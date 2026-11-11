<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/security.php';
security_start_session();
security_set_headers();
security_regenerate_session(15);

error_reporting(E_ALL);
ini_set('display_errors', 0);
if (ob_get_level() === 0) {
    ob_start();
}

require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/email_helper.php';
require_once APP_BASE . '/src/config/password_reset_helper.php';
require_once APP_BASE . '/src/config/audit.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > (64 * 1024)) {
    responderJson(['success' => false, 'message' => 'Payload muito grande.'], 413);
}

function responderJson(array $payload, int $statusCode = 200): void
{
    if (ob_get_length()) {
        ob_clean();
    }

    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function esqueceu_senha_is_local(): bool
{
    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    return $host === 'localhost'
        || $host === '127.0.0.1'
        || str_starts_with($host, 'localhost:')
        || str_starts_with($host, '127.0.0.1:');
}

$lastReset = (int)($_SESSION['last_password_reset_request'] ?? 0);
if ($lastReset > 0 && (time() - $lastReset) < 30) {
    responderJson([
        'success' => false,
        'message' => 'Aguarde alguns segundos antes de solicitar novamente.'
    ]);
}

if (!security_rate_limit('password_reset', 4, 600)) {
    security_log_event('password_reset_rate_limited', ['endpoint' => 'api/esqueci_senha']);
    responderJson([
        'success' => false,
        'message' => 'Muitas solicitações. Aguarde alguns minutos e tente novamente.'
    ]);
}

$_SESSION['last_password_reset_request'] = time();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderJson(['success' => false, 'message' => 'Método não permitido.'], 405);
}

$emailRaw = trim((string)($_POST['email'] ?? ''));
$email = security_validate_email($emailRaw) ?? '';
if ($email === '') {
    security_log_event('password_reset_invalid_email');
    responderJson(['success' => false, 'message' => 'Por favor, insira seu email válido.']);
}

try {
    $database = new Database();
    $db = $database->getConnection();

    password_reset_ensure_table($db);

    $emailLookup = personal_data_email_lookup($email);
    $stmt = $db->prepare("
        SELECT u.id, u.nome, u.email, u.perfil, r.nome AS restaurante_nome
        FROM usuarios u
        LEFT JOIN restaurantes r ON r.id = u.restaurante_id
        WHERE u.email_lookup = ?
        LIMIT 1
    ");
    $stmt->execute([$emailLookup]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        audit_log($db, 'password_reset_requested_unknown_email', [
            'email_hash' => $emailLookup,
        ]);
        saas_email_debug_log('RECUPERACAO: email nao cadastrado (lookup=' . substr($emailLookup, 0, 12) . ')');

        $payload = [
            'success' => true,
            'message' => 'Se o email estiver cadastrado, você receberá um link de recuperação.',
        ];
        if (esqueceu_senha_is_local()) {
            $payload['warning'] = 'Ambiente local: este email nao esta cadastrado em usuarios.';
            $payload['email_sent'] = false;
        }
        responderJson($payload);
    }

    $emailCanonico = trim((string)$usuario['email']);
    $resetData = password_reset_create_token($db, $emailCanonico, '+2 hours', (int)$usuario['id']);
    $link = password_reset_build_link($resetData['token']);

    if (str_contains(strtolower($link), 'localhost') && !esqueceu_senha_is_local()) {
        saas_email_debug_log('AVISO: link de recuperacao com localhost — configure APP_URL no .env');
    }

    $emailParts = password_reset_compose_email([
        'nome' => (string)($usuario['nome'] ?? ''),
        'restaurante' => (string)($usuario['restaurante_nome'] ?? ''),
        'email_conta' => $emailCanonico,
        'link' => $link,
        'expires_hours' => 2,
        'modo' => 'recuperacao',
    ]);
    $subject = $emailParts['subject'];
    $body = $emailParts['html'];

    saas_email_debug_log('RECUPERACAO: a enviar para ' . $emailCanonico);
    $emailEnviado = saas_enviar_email($emailCanonico, $subject, $body);
    $emailError = function_exists('saas_email_get_last_error') ? saas_email_get_last_error() : null;
    $isLocal = esqueceu_senha_is_local();
    $isProduction = function_exists('security_is_production') ? security_is_production() : !$isLocal;

    if (!$emailEnviado) {
        $smtpIssue = function_exists('saas_email_classify_error')
            ? saas_email_classify_error(
                $emailError,
                (string)(function_exists('saas_email_env_value') ? (saas_email_env_value('SMTP_HOST') ?? '') : ''),
                function_exists('saas_email_env_value') ? (saas_email_env_value('SMTP_PORT') ?? null) : null,
                (string)(function_exists('saas_email_env_value') ? (saas_email_env_value('SMTP_SECURE') ?? '') : '')
            )
            : [
                'code' => 'SMTP_SEND_FAILED',
                'message' => 'Nao foi possivel enviar o email de recuperacao neste momento.',
                'detail' => $emailError ?: '',
            ];

        error_log('Esqueci senha email falhou para ' . $emailCanonico . ': ' . ($emailError ?: 'erro desconhecido'));
        audit_log($db, 'password_reset_email_failed', [
            'usuario_id' => (int)$usuario['id'],
            'email_hash' => hash('sha256', strtolower($emailCanonico)),
            'error_code' => $smtpIssue['code'],
            'error' => $smtpIssue['detail'] ?: $emailError,
        ], 'ERROR');

        if ($isLocal) {
            responderJson([
                'success' => true,
                'message' => 'O email nao foi enviado no ambiente local. Use o link de recuperacao abaixo para continuar.',
                'debug_link' => $link,
                'warning' => $smtpIssue['message'],
                'error_code' => $smtpIssue['code'],
                'email_sent' => false,
            ]);
        }

        $publicMessage = 'Nao foi possivel enviar o email de recuperacao neste momento. ' . $smtpIssue['message'];

        responderJson([
            'success' => false,
            'message' => $publicMessage,
            'error_code' => $smtpIssue['code'],
            'email_sent' => false,
        ], 500);
    }
    $payload = [
        'success' => true,
        'message' => 'Email de recuperação enviado. Verifique a caixa de entrada e o spam.',
        'email_sent' => true,
    ];

    if ($isLocal) {
        $payload['debug_link'] = $link;
    }

    audit_log($db, 'password_reset_requested', [
        'usuario_id' => (int)$usuario['id'],
        'email_hash' => hash('sha256', strtolower($emailCanonico)),
        'email_sent' => true,
    ]);

    responderJson($payload);
} catch (Throwable $e) {
    error_log('Esqueci senha API Error: ' . $e->getMessage());
    saas_email_debug_log('RECUPERACAO ERRO: ' . $e->getMessage());
    responderJson(['success' => false, 'message' => 'Erro interno no servidor.'], 500);
}
