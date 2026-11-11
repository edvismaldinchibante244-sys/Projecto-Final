<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/super_admin_permissions.php';
require_once APP_BASE . '/src/config/support_tickets.php';
require_once APP_BASE . '/src/config/audit.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    security_json(['success' => false, 'message' => 'Metodo nao permitido'], 405);
}

api_guard([
    'rate_key' => 'super_admin_support_ticket_message_send',
    'rate_max' => 60,
    'rate_window' => 60,
    'skip_turno' => true,
]);

if (!super_admin_is_authenticated()) {
    security_json(['success' => false, 'message' => 'Acesso negado'], 403);
}
super_admin_require_permission_json('manage_support');

$ticketId = (int)($_POST['ticket_id'] ?? 0);
$mensagem = trim((string)($_POST['mensagem'] ?? ''));
if ($ticketId <= 0) {
    security_json(['success' => false, 'message' => 'Ticket invalido'], 422);
}

$db = (new Database())->getConnection();
support_tickets_ensure_tables($db);

$ticketStmt = $db->prepare("SELECT id, restaurante_id, status FROM support_tickets WHERE id = :id LIMIT 1");
$ticketStmt->execute([':id' => $ticketId]);
$ticket = $ticketStmt->fetch(PDO::FETCH_ASSOC);
if (!$ticket) {
    security_json(['success' => false, 'message' => 'Ticket nao encontrado'], 404);
}

$anexo = null;
$uploadError = null;
if (isset($_FILES['anexo'])) {
    $anexo = support_tickets_store_attachment($_FILES['anexo'], $ticketId, $uploadError);
    if ($anexo === null && $uploadError !== null) {
        security_json(['success' => false, 'message' => $uploadError], 422);
    }
}

if ($mensagem === '' && $anexo === null) {
    security_json(['success' => false, 'message' => 'Informe uma mensagem ou anexo'], 422);
}

$stmt = $db->prepare("
    INSERT INTO support_ticket_messages
    (ticket_id, restaurante_id, author_tipo, author_usuario_id, mensagem, anexo_nome, anexo_path, anexo_mime, anexo_size)
    VALUES
    (:ticket_id, :rid, 'SUPORTE', :uid, :mensagem, :anexo_nome, :anexo_path, :anexo_mime, :anexo_size)
");
$stmt->execute([
    ':ticket_id' => $ticketId,
    ':rid' => (int)$ticket['restaurante_id'],
    ':uid' => (int)($_SESSION['usuario_id'] ?? 0),
    ':mensagem' => $mensagem !== '' ? $mensagem : null,
    ':anexo_nome' => $anexo['nome'] ?? null,
    ':anexo_path' => $anexo['path'] ?? null,
    ':anexo_mime' => $anexo['mime'] ?? null,
    ':anexo_size' => $anexo['size'] ?? null,
]);

$messageId = (int)$db->lastInsertId();

$update = $db->prepare("
    UPDATE support_tickets
    SET status = CASE WHEN status IN ('ABERTO','EM_ANDAMENTO') THEN 'EM_ANDAMENTO' ELSE status END,
        respondido_por = :uid,
        respondido_em = NOW(),
        atualizado_em = NOW()
    WHERE id = :id
");
$update->execute([
    ':uid' => (int)($_SESSION['usuario_id'] ?? 0),
    ':id' => $ticketId,
]);

security_audit_event('support_ticket_message_support', [
    'ticket_id' => $ticketId,
    'message_id' => $messageId,
    'restaurante_id' => (int)$ticket['restaurante_id'],
    'has_anexo' => $anexo !== null ? 1 : 0,
]);
audit_log($db, 'super_admin_support_mensagem_enviada', [
    'ticket_id' => $ticketId,
    'message_id' => $messageId,
    'restaurante_id' => (int)$ticket['restaurante_id'],
    'tem_anexo' => $anexo !== null,
]);

security_json([
    'success' => true,
    'message' => 'Resposta enviada',
    'message_id' => $messageId,
]);
