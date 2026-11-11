<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/plano_check.php';
require_once APP_BASE . '/src/config/support_tickets.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    security_json(['success' => false, 'message' => 'Metodo nao permitido'], 405);
}

$ctx = api_guard([
    'roles' => ['ADMIN', 'SUPER_ADMIN'],
    'rate_key' => 'support_ticket_message_send',
    'rate_max' => 45,
    'rate_window' => 60,
    'skip_turno' => true,
]);

try {
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    $mensagem = trim((string)($_POST['mensagem'] ?? ''));
    if ($ticketId <= 0) {
        security_json(['success' => false, 'message' => 'Ticket invalido'], 422);
    }

    $rid = (int)$ctx['restaurante_id'];
    $planoRid = function_exists('session_restaurante_capability_id') ? (int)session_restaurante_capability_id() : $rid;
    $planoRid = $planoRid > 0 ? $planoRid : $rid;
    if (!plano_tem_funcionalidade_db($planoRid, 'suporte_24h')) {
        security_json(['success' => false, 'message' => 'Suporte 24/7 disponivel apenas no plano Empresarial'], 403);
    }

    $db = (new Database())->getConnection();
    support_tickets_ensure_tables($db);

    $ticket = resource_require_support_ticket($db, $ticketId, $rid, ['id', 'status']);

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
    (:ticket_id, :rid, 'CLIENTE', :uid, :mensagem, :anexo_nome, :anexo_path, :anexo_mime, :anexo_size)
");
    $stmt->execute([
    ':ticket_id' => $ticketId,
    ':rid' => $rid,
    ':uid' => (int)$ctx['usuario_id'],
    ':mensagem' => $mensagem !== '' ? $mensagem : null,
    ':anexo_nome' => $anexo['nome'] ?? null,
    ':anexo_path' => $anexo['path'] ?? null,
    ':anexo_mime' => $anexo['mime'] ?? null,
    ':anexo_size' => $anexo['size'] ?? null,
    ]);

    $messageId = (int)$db->lastInsertId();

    $update = $db->prepare("
    UPDATE support_tickets
    SET status = CASE WHEN status IN ('RESOLVIDO','FECHADO') THEN 'EM_ANDAMENTO' ELSE status END,
        atualizado_em = NOW()
    WHERE id = :id AND restaurante_id = :rid
");
    $update->execute([':id' => $ticketId, ':rid' => $rid]);

    security_audit_event('support_ticket_message_created', [
        'ticket_id' => $ticketId,
        'message_id' => $messageId,
        'restaurante_id' => $rid,
        'has_anexo' => $anexo !== null ? 1 : 0,
    ]);

    security_json([
        'success' => true,
        'message' => 'Mensagem enviada',
        'message_id' => $messageId,
    ]);
} catch (Throwable $e) {
    @file_put_contents(
        APP_BASE . '/storage/logs/support_ticket_error.log',
        '[' . date('Y-m-d H:i:s') . '] support_ticket_message_send: ' . $e->getMessage() . PHP_EOL,
        FILE_APPEND
    );
    security_json(['success' => false, 'message' => 'Falha ao enviar mensagem'], 500);
}
