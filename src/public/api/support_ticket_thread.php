<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/plano_check.php';
require_once APP_BASE . '/src/config/support_tickets.php';

header('Content-Type: application/json; charset=utf-8');

$ctx = api_guard([
    'roles' => ['ADMIN', 'SUPER_ADMIN'],
    'rate_key' => 'support_ticket_thread',
    'rate_max' => 80,
    'rate_window' => 60,
    'skip_turno' => true,
    'skip_csrf' => true,
]);

try {
    $ticketId = (int)($_GET['ticket_id'] ?? 0);
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

    $stmt = $db->prepare("
    SELECT id, ticket_hash, prioridade, status, assunto, descricao, criado_em, atualizado_em
    FROM support_tickets
    WHERE id = :id
      AND restaurante_id = :rid
    LIMIT 1
");
    $stmt->execute([':id' => $ticketId, ':rid' => $rid]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) {
        security_json(['success' => false, 'message' => 'Ticket nao encontrado'], 404);
    }

    $msgStmt = $db->prepare("
    SELECT id, author_tipo, author_usuario_id, mensagem, anexo_nome, anexo_mime, anexo_size, criado_em
    FROM support_ticket_messages
    WHERE ticket_id = :ticket_id
      AND restaurante_id = :rid
    ORDER BY id ASC
    LIMIT 300
");
    $msgStmt->execute([':ticket_id' => $ticketId, ':rid' => $rid]);
    $messages = $msgStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($messages as &$m) {
        $m['anexo_url'] = null;
        if (!empty($m['anexo_nome'])) {
            $m['anexo_url'] = 'api/support_ticket_attachment.php?ticket_id=' . $ticketId . '&message_id=' . (int)$m['id'];
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
} catch (Throwable $e) {
    @file_put_contents(
        APP_BASE . '/storage/logs/support_ticket_error.log',
        '[' . date('Y-m-d H:i:s') . '] support_ticket_thread: ' . $e->getMessage() . PHP_EOL,
        FILE_APPEND
    );
    security_json(['success' => false, 'message' => 'Falha ao carregar chat'], 500);
}
