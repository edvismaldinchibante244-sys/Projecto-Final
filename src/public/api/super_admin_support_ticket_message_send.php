<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/super_admin_permissions.php';
require_once APP_BASE . '/src/config/support_tickets.php';
require_once APP_BASE . '/src/config/audit.php';
require_once APP_BASE . '/src/config/email_helper.php';

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

$ticketStmt = $db->prepare("
    SELECT t.id, t.restaurante_id, t.status, t.assunto,
           r.nome AS restaurante_nome, r.email AS restaurante_email
    FROM support_tickets t
    LEFT JOIN restaurantes r ON r.id = t.restaurante_id
    WHERE t.id = :id
    LIMIT 1
");
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
// Avisar o cliente por email.
//
// Sem isto, uma resposta escrita aqui ficava so guardada: o cliente nao
// recebia nada e o painel dele nao tem marca de "resposta nova", por isso so
// descobria se fosse la espreitar por iniciativa propria. A mudanca de estado
// (o botao "Guardar estado") ja enviava email; a conversa nao.
$emailCliente = trim((string)($ticket['restaurante_email'] ?? ''));
$clienteAvisado = false;

if ($emailCliente !== '') {
    $assuntoTicket = (string)($ticket['assunto'] ?? '');
    $assuntoEmail = 'Resposta ao seu pedido de ajuda #' . $ticketId
        . ($assuntoTicket !== '' ? ' - ' . $assuntoTicket : '');

    $paragrafos = ['A nossa equipa respondeu ao seu pedido de ajuda.'];
    if ($anexo !== null) {
        $paragrafos[] = 'A equipa juntou o ficheiro "'
            . (string)($anexo['nome'] ?? 'anexo')
            . '". Por segurança ele não segue neste email: abra o pedido no painel para o descarregar.';
    }
    $paragrafos[] = 'Para continuar a conversa, responda no painel — a equipa recebe a sua mensagem ali.';

    $corpo = support_tickets_email_html(
        'Tem uma resposta da equipa',
        (string)($ticket['restaurante_nome'] ?? ''),
        $paragrafos,
        [
            'Pedido' => '#' . $ticketId,
            'Assunto' => $assuntoTicket,
        ],
        $mensagem,
        $mensagem !== '' ? 'Resposta da equipa' : '',
        (string)($ticket['plano_codigo'] ?? '')
    );

    $clienteAvisado = (bool)@saas_enviar_email($emailCliente, $assuntoEmail, $corpo);
}

audit_log($db, 'super_admin_support_mensagem_enviada', [
    'ticket_id' => $ticketId,
    'message_id' => $messageId,
    'restaurante_id' => (int)$ticket['restaurante_id'],
    'tem_anexo' => $anexo !== null,
    'cliente_avisado' => $clienteAvisado,
]);

// O painel mostra esta frase tal e qual, para o suporte saber sempre se o
// cliente chegou a ser avisado -- e nao ficar a supor que sim.
if ($emailCliente === '') {
    $aviso = 'Resposta guardada. O cliente NAO foi avisado: este restaurante nao tem email registado.';
} elseif ($clienteAvisado) {
    $aviso = 'Resposta enviada e cliente avisado por email.';
} else {
    $aviso = 'Resposta guardada, mas o email ao cliente FALHOU. Verifique as definicoes de email.';
}

security_json([
    'success' => true,
    'message' => $aviso,
    'cliente_avisado' => $clienteAvisado,
    'message_id' => $messageId,
]);
