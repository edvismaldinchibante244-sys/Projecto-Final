<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/plano_check.php';
require_once APP_BASE . '/src/config/support_tickets.php';

header('Content-Type: application/json; charset=utf-8');

$ctx = api_guard([
    'roles' => ['ADMIN', 'SUPER_ADMIN'],
    'rate_key' => 'support_ticket_list',
    'rate_max' => 40,
    'rate_window' => 60,
    'skip_turno' => true,
    'skip_csrf' => true,
]);

try {
    $rid = (int)$ctx['restaurante_id'];
    $planoRid = function_exists('session_restaurante_capability_id') ? (int)session_restaurante_capability_id() : $rid;
    $planoRid = $planoRid > 0 ? $planoRid : $rid;
    if (!plano_tem_funcionalidade_db($planoRid, 'suporte_24h')) {
        security_json(['success' => false, 'message' => 'Suporte 24/7 disponivel apenas no plano Empresarial'], 403);
    }

    $db = (new Database())->getConnection();
    support_tickets_ensure_tables($db);

    $stmt = $db->prepare("
    SELECT id, ticket_hash, prioridade, status, assunto, descricao, resposta_admin, criado_em, atualizado_em, respondido_em
    FROM support_tickets
    WHERE restaurante_id = :rid
    ORDER BY id DESC
    LIMIT 30
");
    $stmt->execute([':rid' => $rid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$row) {
        $row['prioridade_label'] = support_tickets_priority_label((string)$row['prioridade']);
        $row['status_label'] = support_tickets_status_label((string)$row['status']);
    }
    unset($row);

    security_json(['success' => true, 'data' => $rows]);
} catch (Throwable $e) {
    @file_put_contents(
        APP_BASE . '/storage/logs/support_ticket_error.log',
        '[' . date('Y-m-d H:i:s') . '] support_ticket_list: ' . $e->getMessage() . PHP_EOL,
        FILE_APPEND
    );
    security_json(['success' => false, 'message' => 'Falha ao carregar tickets'], 500);
}
