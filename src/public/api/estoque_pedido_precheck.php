<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/plano_check.php';
require_once APP_BASE . '/src/config/restaurante_context.php';
require_once APP_BASE . '/src/config/estoque_inteligente.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    security_json(['success' => false, 'message' => 'Metodo nao permitido'], 405);
}

$ctx = api_guard([
    'roles' => ['ADMIN', 'GERENTE', 'GARCOM'],
    'rate_key' => 'estoque_pedido_precheck',
    'rate_max' => 90,
    'rate_window' => 60,
]);

$rid = (int)$ctx['restaurante_id'];
$planRid = function_exists('session_restaurante_capability_id') ? (int)session_restaurante_capability_id() : $rid;
$planRid = $planRid > 0 ? $planRid : $rid;

if (!plano_tem_funcionalidade_db($planRid, 'estoque_avancado')) {
    security_json([
        'success' => true,
        'enabled' => false,
        'ok' => true,
        'message' => 'Estoque inteligente nao ativo para este plano.',
    ]);
}

$dadosPlanoPre = function_exists('plano_get_dados') ? plano_get_dados($planRid) : [];
$planoNomePre = function_exists('plano_normalizar_nome')
    ? plano_normalizar_nome($dadosPlanoPre['plano_nome'] ?? 'BASICO')
    : 'BASICO';
$exigeFichaPre = ($planoNomePre === 'PROFISSIONAL');

$rawItens = $_POST['itens'] ?? '[]';
$itens = json_decode((string)$rawItens, true);
$itens = is_array($itens) ? $itens : [];
if (empty($itens)) {
    security_json(['success' => true, 'enabled' => true, 'ok' => true, 'faltas' => []]);
}

$db = (new Database())->getConnection();
estoque_inteligente_schema_garantir($db);

$filialId = null;
if (function_exists('session_restaurante_auth_id')) {
    $authRid = (int)session_restaurante_auth_id();
    if ($authRid > 0 && $authRid !== $planRid) {
        $filialId = $authRid;
    }
}

$produtoIds = [];
foreach ($itens as $item) {
    $pid = (int)($item['id'] ?? $item['Id'] ?? $item['produto_id'] ?? 0);
    if ($pid > 0) {
        $produtoIds[] = $pid;
    }
}
$produtoIds = array_values(array_unique($produtoIds));
if (empty($produtoIds)) {
    security_json(['success' => true, 'enabled' => true, 'ok' => true, 'faltas' => []]);
}

if ($exigeFichaPre) {
    try {
        estoque_validar_itens_com_ficha_obrigatoria($db, $rid, $filialId, $itens);
    } catch (RuntimeException $e) {
        $msg = (string)$e->getMessage();
        if (strpos($msg, 'SEM_FICHA:') === 0) {
            $nomes = trim(substr($msg, strlen('SEM_FICHA:')));
            security_json([
                'success' => true,
                'enabled' => true,
                'ok' => false,
                'faltas' => [],
                'sem_ficha' => array_map('trim', explode(',', $nomes)),
                'message' => 'Produtos sem ficha técnica / insumos: ' . $nomes,
            ]);
        }
        throw $e;
    }
}

$in = implode(',', array_fill(0, count($produtoIds), '?'));
$sql = "
    SELECT ft.produto_id, ft.rendimento_por_produto, fi.insumo_id, fi.quantidade_base, i.nome AS insumo_nome, i.unidade_base
    FROM estoque_fichas_tecnicas ft
    JOIN estoque_ficha_itens fi ON fi.ficha_id = ft.id
    JOIN estoque_insumos i ON i.id = fi.insumo_id
    WHERE ft.restaurante_id = ?
      AND (? IS NULL OR ft.filial_id = ?)
      AND ft.ativo = 1
      AND i.ativo = 1
      AND ft.produto_id IN ($in)
";
$stmt = $db->prepare($sql);
$stmt->execute(array_merge([$rid, $filialId, $filialId], $produtoIds));
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) {
    security_json(['success' => true, 'enabled' => true, 'ok' => true, 'faltas' => []]);
}

$fichas = [];
foreach ($rows as $r) {
    $pid = (int)$r['produto_id'];
    if (!isset($fichas[$pid])) {
        $fichas[$pid] = [];
    }
    $fichas[$pid][] = $r;
}

$consumo = [];
foreach ($itens as $item) {
    $pid = (int)($item['id'] ?? $item['Id'] ?? $item['produto_id'] ?? 0);
    $qtd = (float)($item['qtd'] ?? $item['qty'] ?? $item['quantidade'] ?? 0);
    if ($pid <= 0 || $qtd <= 0 || !isset($fichas[$pid])) {
        continue;
    }
    foreach ($fichas[$pid] as $f) {
        $rendimento = max(0.001, (float)$f['rendimento_por_produto']);
        $insumoId = (int)$f['insumo_id'];
        $qtdInsumo = ($qtd / $rendimento) * (float)$f['quantidade_base'];
        if (!isset($consumo[$insumoId])) {
            $consumo[$insumoId] = [
                'insumo_id' => $insumoId,
                'nome' => (string)$f['insumo_nome'],
                'unidade_base' => (string)$f['unidade_base'],
                'necessario_base' => 0.0,
                'saldo_base' => 0.0,
            ];
        }
        $consumo[$insumoId]['necessario_base'] += $qtdInsumo;
    }
}

$faltas = [];
foreach ($consumo as $insumoId => &$c) {
    $saldo = estoque_obter_saldo_insumo_base($db, $rid, $filialId, (int)$insumoId);
    $c['saldo_base'] = $saldo;
    if ($saldo + 0.0001 < (float)$c['necessario_base']) {
        $faltas[] = [
            'insumo_id' => (int)$insumoId,
            'nome' => (string)$c['nome'],
            'unidade_base' => (string)$c['unidade_base'],
            'saldo_base' => round((float)$saldo, 3),
            'necessario_base' => round((float)$c['necessario_base'], 3),
            'faltando_base' => round((float)$c['necessario_base'] - (float)$saldo, 3),
        ];
    }
}
unset($c);

security_json([
    'success' => true,
    'enabled' => true,
    'ok' => empty($faltas),
    'faltas' => $faltas,
]);

