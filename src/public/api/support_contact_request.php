<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/plano_check.php';
require_once APP_BASE . '/src/config/plano_notificacoes.php';
require_once APP_BASE . '/src/config/support_tickets.php';
require_once APP_BASE . '/src/config/restaurante_context.php';
require_once APP_BASE . '/src/api/notificar_cliente_whatsapp.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    security_json(['success' => false, 'message' => 'Metodo nao permitido'], 405);
}

$ctx = api_guard([
    'roles' => ['ADMIN', 'SUPER_ADMIN'],
    'rate_key' => 'support_contact_request',
    'rate_max' => 10,
    'rate_window' => 300,
    'skip_turno' => true,
]);

$rid = (int)($ctx['restaurante_id'] ?? 0);
$planoRid = function_exists('session_restaurante_capability_id') ? (int)session_restaurante_capability_id() : $rid;
$planoRid = $planoRid > 0 ? $planoRid : $rid;

try {
    $canal = strtoupper(trim((string)($_POST['canal'] ?? 'EMAIL')));
    if (!in_array($canal, ['EMAIL', 'WHATSAPP'], true)) {
        security_json(['success' => false, 'message' => 'Canal de suporte invalido'], 422);
    }

    if ($canal === 'EMAIL' && !plano_tem_funcionalidade_db($planoRid, 'suporte_email')) {
        security_json(['success' => false, 'message' => 'Suporte por email indisponivel para o plano atual'], 403);
    }
    if ($canal === 'WHATSAPP' && !plano_tem_funcionalidade_db($planoRid, 'suporte_whatsapp')) {
        security_json(['success' => false, 'message' => 'Suporte via WhatsApp indisponivel para o plano atual'], 403);
    }
    if (plano_tem_funcionalidade_db($planoRid, 'suporte_24h')) {
        security_json(['success' => false, 'message' => 'Use o ticket interno do plano Empresarial'], 403);
    }

    $assunto = trim((string)($_POST['assunto'] ?? ''));
    $descricao = trim((string)($_POST['descricao'] ?? ''));
    $prioridade = support_tickets_normalize_priority((string)($_POST['prioridade'] ?? 'ALTA'));
    if (in_array($prioridade, ['URGENTE', 'CRITICA'], true)
        && !plano_tem_funcionalidade_db($planoRid, 'suporte_prioritario')
    ) {
        security_json([
            'success' => false,
            'message' => 'Prioridade urgente disponível apenas com suporte prioritário (Empresarial).'
        ], 403);
    }
    $lenAssunto = function_exists('mb_strlen') ? mb_strlen($assunto) : strlen($assunto);
    $lenDescricao = function_exists('mb_strlen') ? mb_strlen($descricao) : strlen($descricao);

    if ($assunto === '' || $lenAssunto < 5) {
        security_json(['success' => false, 'message' => 'Informe um assunto com pelo menos 5 caracteres'], 422);
    }
    if ($descricao === '' || $lenDescricao < 12) {
        security_json(['success' => false, 'message' => 'Descreva o problema com mais detalhe'], 422);
    }

    $db = (new Database())->getConnection();
    support_tickets_ensure_tables($db);

    $abertos = support_tickets_open_count($db, $rid);
    if ($abertos >= 10) {
        security_json(['success' => false, 'message' => 'Ja existem muitos tickets abertos. Resolva ou feche os anteriores primeiro.'], 429);
    }

    $restStmt = $db->prepare("
        SELECT id, nome, email, telefone
        FROM restaurantes
        WHERE id = :rid
        LIMIT 1
    ");
    $restStmt->execute([':rid' => $rid]);
    $rest = $restStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $usuarioStmt = $db->prepare("
        SELECT nome, email
        FROM usuarios
        WHERE id = :uid
        LIMIT 1
    ");
    $usuarioStmt->execute([':uid' => (int)$ctx['usuario_id']]);
    $usuario = $usuarioStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $dadosPlanoAtual = function_exists('plano_get_dados') ? plano_get_dados($planoRid) : [];
    $planoCodigo = plano_normalizar_nome((string)($dadosPlanoAtual['plano_nome'] ?? ($_SESSION['plano'] ?? 'BASICO')));
    if ($planoCodigo === '') {
        $planoCodigo = 'BASICO';
    }

    $ticketHash = bin2hex(random_bytes(16));

    $db->beginTransaction();

    $stmt = $db->prepare("
        INSERT INTO support_tickets
        (restaurante_id, usuario_id, plano_codigo, ticket_hash, canal, prioridade, status, assunto, descricao)
        VALUES
        (:rid, :uid, :plano, :hash, :canal, :prioridade, 'ABERTO', :assunto, :descricao)
    ");
    $stmt->execute([
        ':rid' => $rid,
        ':uid' => (int)$ctx['usuario_id'],
        ':plano' => $planoCodigo,
        ':hash' => $ticketHash,
        ':canal' => $canal,
        ':prioridade' => $prioridade,
        ':assunto' => $assunto,
        ':descricao' => $descricao,
    ]);

    $ticketId = (int)$db->lastInsertId();

    $msgStmt = $db->prepare("
        INSERT INTO support_ticket_messages
        (ticket_id, restaurante_id, author_tipo, author_usuario_id, mensagem)
        VALUES
        (:ticket_id, :rid, 'CLIENTE', :uid, :mensagem)
    ");
    $msgStmt->execute([
        ':ticket_id' => $ticketId,
        ':rid' => $rid,
        ':uid' => (int)$ctx['usuario_id'],
        ':mensagem' => $descricao,
    ]);

    $db->commit();

    $restNome = (string)($rest['nome'] ?? ('Restaurante #' . $rid));
    $restEmail = (string)($rest['email'] ?? '');
    $restTelefone = (string)($rest['telefone'] ?? '');
    $usuarioNome = (string)($usuario['nome'] ?? ($_SESSION['nome'] ?? 'Administrador'));
    $usuarioEmail = (string)($usuario['email'] ?? ($_SESSION['email'] ?? ''));
    $prioridadeLabel = support_tickets_priority_label($prioridade);
    $assuntoFinal = 'Solicitacao de suporte - #' . $ticketId . ' - ' . $restNome;
    $body = '<p><strong>Ticket:</strong> #' . $ticketId . '</p>'
        . '<p><strong>Canal:</strong> ' . htmlspecialchars($canal, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p><strong>Restaurante:</strong> ' . htmlspecialchars($restNome, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p><strong>Plano:</strong> ' . htmlspecialchars($planoCodigo, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p><strong>Contato do restaurante:</strong> ' . htmlspecialchars($restEmail, ENT_QUOTES, 'UTF-8') . ' / ' . htmlspecialchars($restTelefone, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p><strong>Solicitante:</strong> ' . htmlspecialchars($usuarioNome, ENT_QUOTES, 'UTF-8') . ' (' . htmlspecialchars($usuarioEmail, ENT_QUOTES, 'UTF-8') . ')</p>'
        . '<p><strong>Prioridade:</strong> ' . htmlspecialchars($prioridadeLabel, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p><strong>Assunto:</strong> ' . htmlspecialchars($assunto, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p><strong>Descricao:</strong><br>' . nl2br(htmlspecialchars($descricao, ENT_QUOTES, 'UTF-8')) . '</p>';

    try {
        support_tickets_notify_support_copy($assuntoFinal, $body);
    } catch (Throwable $notifyError) {
        @file_put_contents(
            APP_BASE . '/storage/logs/support_ticket_notify_error.log',
            '[' . date('Y-m-d H:i:s') . '] ticket #' . $ticketId . ' - ' . $notifyError->getMessage() . PHP_EOL,
            FILE_APPEND
        );
    }

    security_audit_event('support_contact_requested', [
        'ticket_id' => $ticketId,
        'restaurante_id' => $rid,
        'canal' => $canal,
        'prioridade' => $prioridade,
        'plano' => $planoCodigo,
    ]);

    if ($canal === 'EMAIL') {
        $supportEmail = support_tickets_support_email();
        security_json([
            'success' => true,
            'message' => $supportEmail !== ''
                ? 'Solicitacao enviada por email e registrada com protocolo.'
                : 'Solicitacao registrada com protocolo. Configure SUPPORT_EMAIL para envio automatico.',
            'ticket_id' => $ticketId,
            'ticket_hash' => $ticketHash,
            'support_email' => $supportEmail,
        ]);
    }

    $supportWhatsapp = support_tickets_support_whatsapp();
    $waText = "Suporte {$planoCodigo}\n"
        . "Protocolo: #{$ticketId}\n"
        . "Restaurante: {$restNome}\n"
        . "Solicitante: {$usuarioNome}\n"
        . "Prioridade: {$prioridadeLabel}\n"
        . "Assunto: {$assunto}\n"
        . "Descricao: {$descricao}";
    $waUrl = support_tickets_build_whatsapp_url($supportWhatsapp, $waText);

    security_json([
        'success' => true,
        'message' => $waUrl
            ? 'Solicitacao registrada, enviada por email e pronta para continuar no WhatsApp.'
            : 'Solicitacao registrada, mas o numero oficial de WhatsApp ainda nao foi configurado.',
        'ticket_id' => $ticketId,
        'ticket_hash' => $ticketHash,
        'whatsapp_url' => $waUrl,
        'support_whatsapp' => $supportWhatsapp,
    ]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $e->getMessage()
        . ' | file=' . $e->getFile() . ':' . $e->getLine()
        . ' | rid=' . (string)($rid ?? 0)
        . ' | uid=' . (string)($ctx['usuario_id'] ?? 0)
        . PHP_EOL;
    @file_put_contents(APP_BASE . '/storage/logs/support_ticket_error.log', $line, FILE_APPEND);
    security_json(['success' => false, 'message' => 'Erro interno ao registrar contato de suporte'], 500);
}
