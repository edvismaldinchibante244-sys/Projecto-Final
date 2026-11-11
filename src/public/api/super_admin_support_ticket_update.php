<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/super_admin_permissions.php';
require_once APP_BASE . '/src/config/plano_notificacoes.php';
require_once APP_BASE . '/src/config/support_tickets.php';
require_once APP_BASE . '/src/config/audit.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    security_json(['success' => false, 'message' => 'Metodo nao permitido'], 405);
}

api_guard([
    'rate_key' => 'super_admin_support_ticket_update',
    'rate_max' => 40,
    'rate_window' => 60,
    'skip_turno' => true,
]);

if (!super_admin_is_authenticated()) {
    security_json(['success' => false, 'message' => 'Acesso negado'], 403);
}

super_admin_require_permission_json('manage_support');

try {
    $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $ticketId = (int)($payload['ticket_id'] ?? 0);
    $status = support_tickets_normalize_status((string)($payload['status'] ?? 'ABERTO'));
    $resposta = trim((string)($payload['resposta'] ?? ''));

    if ($ticketId <= 0) {
        security_json(['success' => false, 'message' => 'Ticket invalido'], 422);
    }

    $db = (new Database())->getConnection();
    support_tickets_ensure_tables($db);

    $stmt = $db->prepare("
        SELECT t.*, r.nome AS restaurante_nome, r.email AS restaurante_email, r.telefone AS restaurante_telefone
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
    $statusAnterior = support_tickets_normalize_status((string)($ticket['status'] ?? 'ABERTO'));

    try {
        $update = $db->prepare("
            UPDATE support_tickets
            SET status = :status,
                resposta_admin = CASE WHEN :resposta_check = '' THEN resposta_admin ELSE :resposta_value END,
                respondido_por = :admin_id,
                respondido_em = NOW()
            WHERE id = :id
        ");
        $update->execute([
            ':status' => $status,
            ':resposta_check' => $resposta,
            ':resposta_value' => $resposta,
            ':admin_id' => (int)($_SESSION['usuario_id'] ?? 0),
            ':id' => $ticketId,
        ]);
    } catch (Throwable $legacySchemaError) {
        // Fallback para schemas antigos sem colunas respondido_por/respondido_em.
        $updateLegacy = $db->prepare("
            UPDATE support_tickets
            SET status = :status,
                resposta_admin = CASE WHEN :resposta_check = '' THEN resposta_admin ELSE :resposta_value END
            WHERE id = :id
        ");
        $updateLegacy->execute([
            ':status' => $status,
            ':resposta_check' => $resposta,
            ':resposta_value' => $resposta,
            ':id' => $ticketId,
        ]);
    }

    if ($statusAnterior !== $status) {
        $mensagemSistema = [
            'EM_ANDAMENTO' => 'Seu ticket entrou em atendimento.',
            'RESOLVIDO' => 'Seu ticket foi resolvido pela equipa.',
            'FECHADO' => 'Seu ticket foi encerrado.',
        ][$status] ?? '';

        if ($mensagemSistema !== '') {
            $msgStmt = $db->prepare("
                INSERT INTO support_ticket_messages (
                    ticket_id,
                    restaurante_id,
                    author_tipo,
                    author_usuario_id,
                    mensagem
                ) VALUES (
                    :ticket_id,
                    :restaurante_id,
                    'SISTEMA',
                    :author_usuario_id,
                    :mensagem
                )
            ");
            $msgStmt->execute([
                ':ticket_id' => $ticketId,
                ':restaurante_id' => (int)($ticket['restaurante_id'] ?? 0),
                ':author_usuario_id' => (int)($_SESSION['usuario_id'] ?? 0),
                ':mensagem' => $mensagemSistema,
            ]);
        }
    }

    $email = trim((string)($ticket['restaurante_email'] ?? ''));
    if ($email !== '') {
        $subject = 'Atualizacao do ticket #' . $ticketId . ' - ' . support_tickets_status_label($status);
        $body = '<p><strong>Ticket:</strong> #' . $ticketId . '</p>'
            . '<p><strong>Status:</strong> ' . htmlspecialchars(support_tickets_status_label($status), ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>Assunto:</strong> ' . htmlspecialchars((string)$ticket['assunto'], ENT_QUOTES, 'UTF-8') . '</p>'
            . ($resposta !== '' ? '<p><strong>Resposta:</strong><br>' . nl2br(htmlspecialchars($resposta, ENT_QUOTES, 'UTF-8')) . '</p>' : '');
        @saas_enviar_email($email, $subject, $body);
    }

    security_audit_event('support_ticket_updated', [
        'ticket_id' => $ticketId,
        'status' => $status,
        'respondido_por' => (int)($_SESSION['usuario_id'] ?? 0),
    ]);
    audit_log($db, 'super_admin_support_ticket_atualizado', [
        'ticket_id' => $ticketId,
        'restaurante_id' => (int)($ticket['restaurante_id'] ?? 0),
        'status_anterior' => $statusAnterior,
        'status_novo' => $status,
        'tem_resposta' => $resposta !== '',
    ]);

    security_json(['success' => true, 'message' => 'Ticket atualizado com sucesso']);
} catch (Throwable $e) {
    @file_put_contents(
        APP_BASE . '/storage/logs/support_ticket_error.log',
        '[' . date('Y-m-d H:i:s') . '] super_admin_support_ticket_update: ' . $e->getMessage() . PHP_EOL,
        FILE_APPEND
    );
    security_json(['success' => false, 'message' => 'Falha ao atualizar ticket'], 500);
}
