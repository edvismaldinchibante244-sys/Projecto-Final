<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/plano_check.php';
require_once APP_BASE . '/src/config/restaurante_context.php';
require_once APP_BASE . '/src/config/estoque_inteligente.php';
require_once APP_BASE . '/src/config/audit.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    security_json(['success' => false, 'message' => 'Metodo nao permitido'], 405);
}

$ctx = api_guard([
    'roles' => ['ADMIN', 'GERENTE'],
    'rate_key' => 'estoque_aplicar_minimos',
    'rate_max' => 30,
    'rate_window' => 60,
    'skip_turno' => true,
]);

$rid = (int)$ctx['restaurante_id'];
$planoRid = function_exists('session_restaurante_capability_id') ? (int)session_restaurante_capability_id() : $rid;
$planoRid = $planoRid > 0 ? $planoRid : $rid;

if (!plano_tem_funcionalidade_db($planoRid, 'estoque_avancado')) {
    security_json(['success' => false, 'message' => 'Funcionalidade disponivel nos planos Profissional e Empresarial'], 403);
}

$dadosPlano = function_exists('plano_get_dados') ? plano_get_dados($planoRid) : [];
$planoNome = function_exists('plano_normalizar_nome')
    ? plano_normalizar_nome($dadosPlano['plano_nome'] ?? 'BASICO')
    : 'BASICO';
if ($planoNome !== 'EMPRESARIAL') {
    security_json([
        'success' => false,
        'message' => 'Aplicar mínimos sugeridos está disponível apenas no plano Empresarial.',
    ], 403);
}

$filialId = isset($_POST['filial_id']) ? (int)$_POST['filial_id'] : 0;
$filialId = $filialId > 0 ? $filialId : null;

$insumoIds = [];
$rawIds = $_POST['insumo_ids'] ?? '[]';
if (is_string($rawIds)) {
    $decoded = json_decode($rawIds, true);
    if (is_array($decoded)) {
        $insumoIds = $decoded;
    }
} elseif (is_array($rawIds)) {
    $insumoIds = $rawIds;
}
if (isset($_POST['insumo_id'])) {
    $insumoIds[] = (int)$_POST['insumo_id'];
}

$modo = (string)($_POST['modo'] ?? 'aplicar'); // previsualizar | aplicar | desfazer

try {
    $db = (new Database())->getConnection();
    estoque_inteligente_schema_garantir($db);

    if ($modo === 'desfazer') {
        $itens = json_decode((string)($_POST['itens'] ?? '[]'), true);
        $repostos = estoque_desfazer_minimos($db, $rid, is_array($itens) ? array_slice($itens, 0, 500) : []);
        if (function_exists('audit_log')) {
            @audit_log($db, 'estoque_minimos_desfeitos', ['restaurante_id' => $rid, 'repostos' => $repostos, 'usuario_id' => (int)$ctx['usuario_id']]);
        }
        security_json([
            'success' => true,
            'message' => $repostos > 0 ? ('Mínimos anteriores repostos em ' . $repostos . ' insumo(s).') : 'Nada para desfazer (os mínimos já tinham sido alterados).',
            'data' => ['repostos' => $repostos],
        ]);
    }

    $previsualizar = $modo === 'previsualizar';
    if (!$previsualizar && $insumoIds === []) {
        // Aplicar exige a lista escolhida na janela de confirmacao.
        security_json(['success' => false, 'message' => 'Escolha pelo menos um insumo.'], 422);
    }
    $resultado = estoque_aplicar_minimos_sugeridos($db, $rid, $filialId, $insumoIds, 28, !$previsualizar);
    if (!$previsualizar && function_exists('audit_log')) {
        @audit_log($db, 'estoque_minimos_aplicados', ['restaurante_id' => $rid, 'atualizados' => $resultado['atualizados'], 'usuario_id' => (int)$ctx['usuario_id']]);
    }
    security_json([
        'success' => true,
        'message' => $previsualizar
            ? (count($resultado['itens']) . ' mínimo(s) a actualizar.')
            : ($resultado['atualizados'] > 0
                ? ('Mínimos actualizados em ' . $resultado['atualizados'] . ' insumo(s).')
                : 'Nenhum mínimo precisava de actualização.'),
        'data' => $resultado,
    ]);
} catch (Throwable $e) {
    error_log('estoque_aplicar_minimos: ' . $e->getMessage());
    security_json(['success' => false, 'message' => 'Erro ao aplicar mínimos sugeridos'], 500);
}
