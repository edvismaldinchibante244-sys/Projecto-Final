<?php
require_once __DIR__ . '/../app_base.php';

/* API - Últimos movimentos de stock (para ver quem fez o quê e quando). */

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/plano_check.php';
require_once APP_BASE . '/src/config/restaurante_context.php';
require_once APP_BASE . '/src/config/estoque_inteligente.php';

header('Content-Type: application/json; charset=utf-8');

$ctx = api_guard([
    'roles' => ['ADMIN', 'GERENTE'],
    'rate_key' => 'estoque_movimentos_recentes',
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

$limite = max(10, min(100, (int)($_GET['limite'] ?? 40)));
try {
    $db = (new Database())->getConnection();
    estoque_inteligente_schema_garantir($db);
    $st = $db->prepare("
        SELECT m.id, m.tipo, m.origem, m.quantidade_base, m.custo_total, m.motivo, m.criado_em,
               i.nome AS insumo_nome, i.unidade_base, u.nome AS usuario_nome
        FROM estoque_movimentos m
        LEFT JOIN estoque_insumos i ON i.id = m.insumo_id
        LEFT JOIN usuarios u ON u.id = m.responsavel_id
        WHERE m.restaurante_id = ?
        ORDER BY m.criado_em DESC, m.id DESC
        LIMIT $limite
    ");
    $st->execute([$rid]);
    security_json(['success' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC) ?: []]);
} catch (Throwable $e) {
    error_log('estoque_movimentos_recentes: ' . $e->getMessage());
    security_json(['success' => false, 'message' => 'Erro ao carregar movimentos'], 500);
}
