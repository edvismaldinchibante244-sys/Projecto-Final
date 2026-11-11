<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/plano_check.php';
require_once APP_BASE . '/src/config/restaurante_context.php';
require_once APP_BASE . '/src/config/estoque_inteligente.php';

header('Content-Type: application/json; charset=utf-8');

$ctx = api_guard([
    'roles' => ['ADMIN', 'GERENTE'],
    'rate_key' => 'estoque_ficha_buscar',
    'rate_max' => 120,
    'rate_window' => 60,
    'skip_turno' => true,
]);

$rid = (int)$ctx['restaurante_id'];
$planoRid = function_exists('session_restaurante_capability_id') ? (int)session_restaurante_capability_id() : $rid;
$planoRid = $planoRid > 0 ? $planoRid : $rid;
if (!plano_tem_funcionalidade_db($planoRid, 'estoque_avancado')) {
    security_json(['success' => false, 'message' => 'Funcionalidade disponivel nos planos Profissional e Empresarial'], 403);
}

$produtoId = (int)($_GET['produto_id'] ?? 0);
$filialId = isset($_GET['filial_id']) ? (int)$_GET['filial_id'] : 0;
$filialId = $filialId > 0 ? $filialId : null;
if ($produtoId <= 0) {
    security_json(['success' => false, 'message' => 'produto_id obrigatorio'], 422);
}

$db = (new Database())->getConnection();
estoque_inteligente_schema_garantir($db);

if ($filialId === null) {
    $stmtFicha = $db->prepare("
        SELECT id, rendimento_por_produto
        FROM estoque_fichas_tecnicas
        WHERE restaurante_id = :rid
          AND produto_id = :produto_id
          AND filial_id IS NULL
          AND ativo = 1
        ORDER BY id ASC
        LIMIT 1
    ");
    $stmtFicha->execute([
        ':rid' => $rid,
        ':produto_id' => $produtoId,
    ]);
} else {
    $stmtFicha = $db->prepare("
        SELECT id, rendimento_por_produto
        FROM estoque_fichas_tecnicas
        WHERE restaurante_id = :rid
          AND produto_id = :produto_id
          AND filial_id = :filial_id
          AND ativo = 1
        ORDER BY id ASC
        LIMIT 1
    ");
    $stmtFicha->execute([
        ':rid' => $rid,
        ':produto_id' => $produtoId,
        ':filial_id' => $filialId,
    ]);
}
$ficha = $stmtFicha->fetch(PDO::FETCH_ASSOC);
if (!$ficha) {
    security_json(['success' => true, 'data' => ['rendimento' => 1, 'itens' => []]]);
}

$stmtItens = $db->prepare("
    SELECT fi.insumo_id, fi.quantidade_base, i.nome AS insumo_nome, i.unidade_base
    FROM estoque_ficha_itens fi
    JOIN estoque_insumos i ON i.id = fi.insumo_id
    WHERE fi.ficha_id = :ficha_id
    ORDER BY i.nome ASC
");
$stmtItens->execute([':ficha_id' => (int)$ficha['id']]);

security_json([
    'success' => true,
    'data' => [
        'rendimento' => (float)$ficha['rendimento_por_produto'],
        'itens' => $stmtItens->fetchAll(PDO::FETCH_ASSOC),
    ]
]);
