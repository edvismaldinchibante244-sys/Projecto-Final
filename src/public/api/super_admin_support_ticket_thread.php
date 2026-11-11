<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/super_admin_permissions.php';
require_once APP_BASE . '/src/config/support_tickets.php';

header('Content-Type: application/json; charset=utf-8');

api_guard([
    'rate_key' => 'super_admin_support_ticket_thread',
    'rate_max' => 90,
    'rate_window' => 60,
    'skip_turno' => true,
    'skip_csrf' => true,
]);

if (!super_admin_is_authenticated()) {
    security_json(['success' => false, 'message' => 'Acesso negado'], 403);
}
super_admin_require_permission_json('manage_support');

$ticketId = (int)($_GET['ticket_id'] ?? 0);
if ($ticketId <= 0) {
    security_json(['success' => false, 'message' => 'Ticket invalido'], 422);
}

$db = (new Database())->getConnection();
support_tickets_ensure_tables($db);

$stmt = $db->prepare("
    SELECT t.*, r.nome AS restaurante_nome
    FROM support_tickets t
    INNER JOIN restaurantes r ON r.id = t.restaurante_id
    WHERE t.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $ticketId]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$ticket) {
    security_json(['success' => false, 'message' => 'Ticket nao encontrado'], 404);
}

$msgStmt = $db->prepare("
    SELECT id, author_tipo, author_usuario_id, mensagem, anexo_nome, anexo_mime, anexo_size, criado_em
    FROM support_ticket_messages
    WHERE ticket_id = :ticket_id
    ORDER BY id ASC
    LIMIT 500
");
$msgStmt->execute([':ticket_id' => $ticketId]);
$messages = $msgStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($messages as &$m) {
    $m['anexo_url'] = null;
    if (!empty($m['anexo_nome'])) {
        $m['anexo_url'] = 'api/super_admin_support_ticket_attachment.php?ticket_id=' . $ticketId . '&message_id=' . (int)$m['id'];
    }
}
unset($m);

$ticket['prioridade_label'] = support_tickets_priority_label((string)$ticket['prioridade']);
$ticket['status_label'] = support_tickets_status_label((string)$ticket['status']);

security_json([
    'success' => true,
    'ticket' => $ticket,
    'messages' => $messages,
]);

