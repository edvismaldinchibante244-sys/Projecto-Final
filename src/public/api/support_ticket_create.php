<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/plano_check.php';
require_once APP_BASE . '/src/config/plano_notificacoes.php';
require_once APP_BASE . '/src/config/support_tickets.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    security_json(['success' => false, 'message' => 'Metodo nao permitido'], 405);
}

$ctx = api_guard([
    'roles' => ['ADMIN', 'SUPER_ADMIN'],
    'rate_key' => 'support_ticket_create',
    'rate_max' => 12,
    'rate_window' => 300,
    'skip_turno' => true,
]);

$rid = (int)$ctx['restaurante_id'];
$planoRid = function_exists('session_restaurante_capability_id') ? (int)session_restaurante_capability_id() : $rid;
$planoRid = $planoRid > 0 ? $planoRid : $rid;
if (!plano_tem_funcionalidade_db($planoRid, 'suporte_24h')) {
    security_json(['success' => false, 'message' => 'Suporte 24/7 disponivel apenas no plano Empresarial'], 403);
}

try {
    $assunto = trim((string)($_POST['assunto'] ?? ''));
    $descricao = trim((string)($_POST['descricao'] ?? ''));
    $prioridade = support_tickets_normalize_priority((string)($_POST['prioridade'] ?? 'ALTA'));
    // Prioridade URGENTE só com suporte_prioritario (EMP)
    if (in_array($prioridade, ['URGENTE', 'CRITICA'], true)
        && !plano_tem_funcionalidade_db($planoRid, 'suporte_prioritario')
    ) {
        $prioridade = 'ALTA';
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
    $db->beginTransaction();

    $dadosPlanoAtual = function_exists('plano_get_dados') ? plano_get_dados($planoRid) : [];
    $planoCodigo = strtoupper((string)($dadosPlanoAtual['plano_nome'] ?? ($_SESSION['plano'] ?? 'BASICO')));
    if ($planoCodigo === '') {
        $planoCodigo = 'BASICO';
    }
    $abertos = support_tickets_open_count($db, $rid);
    if ($abertos >= 10) {
        security_json(['success' => false, 'message' => 'Ja existem muitos tickets abertos. Feche ou resolva os anteriores primeiro.'], 429);
    }

    $ticketHash = bin2hex(random_bytes(16));
    $stmt = $db->prepare("
    INSERT INTO support_tickets
    (restaurante_id, usuario_id, plano_codigo, ticket_hash, canal, prioridade, status, assunto, descricao)
    VALUES
    (:rid, :uid, :plano, :hash, 'DASHBOARD', :prioridade, 'ABERTO', :assunto, :descricao)
");
    $stmt->execute([
    ':rid' => $rid,
    ':uid' => (int)$ctx['usuario_id'],
    ':plano' => $planoCodigo,
    ':hash' => $ticketHash,
    ':prioridade' => $prioridade,
    ':assunto' => $assunto,
    ':descricao' => $descricao,
    ]);

    $ticketId = (int)$db->lastInsertId();

    $msgInit = $db->prepare("
    INSERT INTO support_ticket_messages
    (ticket_id, restaurante_id, author_tipo, author_usuario_id, mensagem)
    VALUES
    (:ticket_id, :rid, 'CLIENTE', :uid, :mensagem)
");
    $msgInit->execute([
    ':ticket_id' => $ticketId,
    ':rid' => $rid,
    ':uid' => (int)$ctx['usuario_id'],
    ':mensagem' => $descricao,
    ]);
    $db->commit();

    $restStmt = $db->prepare("
    SELECT nome, email, telefone
    FROM restaurantes
    WHERE id = :rid
    LIMIT 1
");
    $restStmt->execute([':rid' => $rid]);
    $rest = $restStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $subject = 'Novo ticket 24/7 - #' . $ticketId . ' - ' . ($rest['nome'] ?? ('Restaurante ' . $rid));
    $body = '<p><strong>Ticket:</strong> #' . $ticketId . '</p>'
    . '<p><strong>Restaurante:</strong> ' . htmlspecialchars((string)($rest['nome'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>'
    . '<p><strong>Plano:</strong> ' . htmlspecialchars($planoCodigo, ENT_QUOTES, 'UTF-8') . '</p>'
    . '<p><strong>Prioridade:</strong> ' . htmlspecialchars(support_tickets_priority_label($prioridade), ENT_QUOTES, 'UTF-8') . '</p>'
    . '<p><strong>Assunto:</strong> ' . htmlspecialchars($assunto, ENT_QUOTES, 'UTF-8') . '</p>'
    . '<p><strong>Descricao:</strong><br>' . nl2br(htmlspecialchars($descricao, ENT_QUOTES, 'UTF-8')) . '</p>';
    try {
        support_tickets_notify_support_copy($subject, $body);
    } catch (Throwable $notifyError) {
        @file_put_contents(
            APP_BASE . '/storage/logs/support_ticket_notify_error.log',
            '[' . date('Y-m-d H:i:s') . '] ticket #' . $ticketId . ' - ' . $notifyError->getMessage() . PHP_EOL,
            FILE_APPEND
        );
    }

    security_audit_event('support_ticket_created', [
    'ticket_id' => $ticketId,
    'restaurante_id' => $rid,
    'prioridade' => $prioridade,
    'plano' => $planoCodigo,
    ]);

    security_json([
    'success' => true,
    'message' => 'Ticket aberto com sucesso. A equipa sera notificada.',
    'ticket_id' => $ticketId,
    'ticket_hash' => $ticketHash,
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
    @error_log('[support_ticket_create] ' . $e->getMessage());
    security_json(['success' => false, 'message' => 'Erro interno ao abrir ticket'], 500);
}
