<?php
require_once __DIR__ . '/../app_base.php';

include_once APP_BASE . '/src/config/security.php';
include_once APP_BASE . '/src/config/login_security.php';
include_once APP_BASE . '/src/config/audit.php';
include_once APP_BASE . '/src/config/email_helper.php';
include_once APP_BASE . '/src/config/email_otp_template.php';
include_once APP_BASE . '/src/config/login_2fa_store.php';

security_start_session();
security_set_headers();
security_regenerate_session(15);
ob_start();

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > (128 * 1024)) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Payload muito grande.']);
    exit;
}

function login_api_get_request_value(string $key, int $maxLength = 2048): string
{
    $value = $_POST[$key] ?? '';
    return security_clean_string($value, $maxLength);
}

function login_api_send_otp(array $user, string $code): array
{
    $email = trim((string)($user['two_factor_email'] ?? $user['email'] ?? ''));
    $nome = trim((string)($user['nome'] ?? ''));
    $emailParts = nivora_email_compose_login_otp([
        'nome' => $nome,
        'code' => $code,
        'expires_minutes' => 5,
    ]);

    $enviado = false;
    $erroEmail = null;
    if ($email !== '' && function_exists('saas_enviar_email')) {
        $enviado = saas_enviar_email($email, $emailParts['subject'], $emailParts['html']);
        if (!$enviado && function_exists('saas_email_get_last_error')) {
            $erroEmail = saas_email_get_last_error();
        }
    } else {
        $erroEmail = $email === '' ? 'Email 2FA vazio.' : 'Funcao de email indisponivel.';
    }

    security_log_event('login_2fa_code_issued', [
        'usuario_id' => $user['id'] ?? null,
        'email' => $email,
        'email_sent' => $enviado,
        'email_error' => $erroEmail,
    ]);

    if (!$enviado) {
        error_log('[2FA_EMAIL] Falha ao enviar codigo para ' . $email . ': ' . ($erroEmail ?: 'erro desconhecido'));
    }

    return [
        'sent' => $enviado,
        'email' => $email,
        'error' => $erroEmail,
    ];
}

function login_api_email_failure_message(?string $error): string
{
    $normalizedError = strtolower(trim((string)$error));

    if (
        str_contains($normalizedError, 'only send testing emails to your own email')
        || str_contains($normalizedError, 'verify a domain at resend.com/domains')
        || str_contains($normalizedError, 'domain is not verified')
    ) {
        return 'A Resend esta em modo de teste e nao pode enviar para este email. Verifique um dominio na Resend e configure RESEND_FROM com esse dominio.';
    }
    if (
        str_contains($normalizedError, 'resend sdk falhou')
        || str_contains($normalizedError, 'resend api falhou')
    ) {
        if (str_contains($normalizedError, 'api key') || str_contains($normalizedError, 'unauthorized')) {
            return 'A Resend recusou a chave de acesso. Confirme RESEND_API_KEY no arquivo .env.';
        }
        return 'Nao foi possivel enviar o codigo pela Resend. Confirme RESEND_API_KEY, RESEND_FROM e o dominio verificado.';
    }

    if (str_contains($normalizedError, 'authenticate') || str_contains($normalizedError, 'rejeitou a autenticacao')) {
        return 'O Gmail recusou a credencial SMTP. Gere uma nova senha de app e actualize SMTP_PASSWORD no arquivo .env.';
    }
    if (str_contains($normalizedError, 'phpmailer nao encontrado')) {
        return 'O componente de envio de email nao esta instalado. Execute composer install na pasta app.';
    }
    if (str_contains($normalizedError, 'placeholder')) {
        return 'A configuracao SMTP esta incompleta. Preencha as variaveis SMTP no arquivo .env.';
    }
    if (str_contains($normalizedError, 'connect') || str_contains($normalizedError, 'conectar')) {
        return 'Nao foi possivel conectar ao servidor SMTP. Verifique a rede, o host e a porta configurados.';
    }

    return 'Nao foi possivel enviar o codigo por email. Verifique a configuracao do provedor de email.';
}

function login_api_is_local_request(): bool
{
    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    return $host === ''
        || $host === 'localhost'
        || $host === '127.0.0.1'
        || str_starts_with($host, 'localhost:')
        || str_starts_with($host, '127.0.0.1:')
        || str_contains($host, '[::1]');
}

function login_api_store_pending_2fa(array $user): array
{
    $userId = (int)($user['id'] ?? 0);
    if ($userId > 0) {
        login_api_delete_all_pending_for_user($userId);
    }

    $code = login_security_generate_otp();
    $token = bin2hex(random_bytes(24));
    $ttl = (int)(getenv('LOGIN_2FA_TTL_SECONDS') ?: 300);
    if ($ttl < 120) {
        $ttl = 300;
    }
    $pending = [
        'token' => $token,
        'code_hash' => login_security_hash_otp($code),
        'expires_at' => time() + $ttl,
        'attempts' => 0,
        'user' => $user,
    ];
    $_SESSION['pending_2fa'] = $pending;
    $persisted = login_api_write_pending_2fa($pending);
    $emailResult = login_api_send_otp($user, $code);

    return [
        'token' => $token,
        'persisted' => $persisted,
        // O codigo so e devolvido em desenvolvimento real: APP_ENV local/dev E
        // ligacao vinda do proprio computador. Antes bastava enviar "Host: localhost"
        // para receber o OTP na resposta e contornar o 2FA.
        'debug_code' => (!security_is_production() && security_is_local_request()) ? $code : null,
        'email_sent' => $emailResult['sent'],
        'email' => $emailResult['email'],
        'email_error' => $emailResult['error'],
    ];
}

require_once APP_BASE . '/src/config/login_sessao.php';

function login_api_requires_2fa(array $user): bool
{
    if (!empty($user['two_factor_enabled'])) {
        return true;
    }

    $profile = strtoupper(trim((string)($user['perfil'] ?? '')));
    $profile = str_replace('GARÃ‡OM', 'GARCOM', $profile);

    return !empty($user['super_admin']) || in_array($profile, ['ADMIN', 'GERENTE', 'CAIXA', 'BAR'], true);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Metodo nao permitido.']);
        exit;
    }

    $isTwoFactorVerification = trim((string)($_POST['two_factor_token'] ?? '')) !== '';
    if ($isTwoFactorVerification) {
        if (!security_rate_limit('login_2fa', 8, 300)) {
            ob_clean();
            security_log_event('login_2fa_rate_limited');
            echo json_encode(['success' => false, 'message' => 'Muitas tentativas de verificacao. Aguarde alguns minutos.']);
            exit;
        }

        $pending = $_SESSION['pending_2fa'] ?? null;
        $token = login_api_get_request_value('two_factor_token', 128);
        $code = preg_replace('/\D+/', '', login_api_get_request_value('otp_code', 8)) ?? '';
        if (login_api_2fa_db() instanceof PDO) {
            // A base de dados e a fonte oficial (tentativas atomicas, desafio
            // apagado apos bloqueio); a copia em sessao pode estar desactualizada.
            $pending = login_api_read_pending_2fa($token);
        } elseif (!is_array($pending) || !hash_equals((string)($pending['token'] ?? ''), $token)) {
            $pending = login_api_read_pending_2fa($token);
        }

        if (!is_array($pending) || !hash_equals((string)($pending['token'] ?? ''), $token)) {
            security_log_event('login_2fa_missing_context');
            ob_clean();
            echo json_encode([
                'success' => false,
                'message' => 'Sessao de verificacao expirada. Faca login novamente para gerar um novo codigo.',
                'requires_2fa_restart' => true,
            ]);
            exit;
        }

        $expired = time() > (int)($pending['expires_at'] ?? 0);
        $tentativaJaContada = false;
        if (!$expired && login_api_2fa_db() instanceof PDO) {
            // Reservar a tentativa ANTES de verificar o codigo: com pedidos em
            // paralelo, cada palpite recebe um numero unico e so os 5 primeiros
            // chegam a ser verificados.
            $pending['attempts'] = login_api_increment_pending_2fa_attempts($pending);
            $tentativaJaContada = true;
            $tooManyAttempts = (int)$pending['attempts'] > 5;
        } else {
            $tooManyAttempts = (int)($pending['attempts'] ?? 0) >= 5;
        }
        $otpValid = !$tooManyAttempts && login_security_verify_otp($code, (string)($pending['code_hash'] ?? ''));

        if ($expired || $tooManyAttempts || !$otpValid) {
            // Fallback seguro para conflito de token/codigo:
            // exige email+senha validos e OTP correspondente a um desafio pendente do mesmo usuario.
            if (!$expired && !$tooManyAttempts && !$otpValid) {
                $emailFallback = security_validate_email(login_api_get_request_value('email', 254)) ?? '';
                $senhaFallback = login_api_get_request_value('senha', 1024);
                if ($emailFallback !== '' && $senhaFallback !== '') {
                    try {
                        require_once APP_BASE . '/src/Controller/AuthController.php';
                        $controllerFallback = new AuthController();
                        $resultadoFallback = $controllerFallback->login($emailFallback, $senhaFallback);
                        if (!empty($resultadoFallback['success']) && is_array($resultadoFallback['data'] ?? null)) {
                            $userFallback = $resultadoFallback['data'];
                            $pendingFallback = login_api_find_pending_for_user_code((int)($userFallback['id'] ?? 0), $code);
                            if (is_array($pendingFallback)) {
                                $_SESSION['pending_2fa'] = $pendingFallback;
                                $pending = $pendingFallback;
                                $otpValid = true;
                            }
                        }
                    } catch (Throwable $fallbackError) {
                        security_log_event('login_2fa_fallback_error', ['error' => $fallbackError->getMessage()]);
                    }
                }
            }

            if ($otpValid) {
                $user = is_array($pending['user'] ?? null) ? $pending['user'] : [];
                login_api_delete_pending_2fa($pending);
                $redirect = login_api_complete_session($user, (string)($user['email'] ?? ''));
                ob_clean();
                echo json_encode(['success' => true, 'message' => 'Login confirmado com sucesso!', 'redirect' => $redirect]);
                exit;
            }

            if ($tooManyAttempts) {
                login_api_delete_pending_2fa($pending);
                unset($_SESSION['pending_2fa']);
                security_log_event('login_2fa_locked_attempts', [
                    'token' => substr((string)($pending['token'] ?? ''), 0, 8),
                ]);
                ob_clean();
                echo json_encode([
                    'success' => false,
                    'message' => 'Muitas tentativas para este codigo. Faca login novamente para gerar um novo codigo.',
                    'requires_2fa_restart' => true,
                ]);
                exit;
            }

            $expired = time() > (int)($pending['expires_at'] ?? 0);
            if (!$expired && !$tentativaJaContada) {
                $pending['attempts'] = login_api_increment_pending_2fa_attempts($pending);
            }
            if (!$expired) {
                $_SESSION['pending_2fa'] = $pending;
            }
            security_log_event('login_2fa_failed', [
                'expired' => $expired,
                'attempts' => (int)($pending['attempts'] ?? 0),
                'token' => substr((string)($pending['token'] ?? ''), 0, 8),
            ]);
            ob_clean();
            echo json_encode([
                'success' => false,
                'message' => $expired
                    ? 'Codigo expirado. Faca login novamente para receber um novo codigo.'
                    : 'Codigo de verificacao invalido.',
                'requires_2fa_restart' => $expired,
            ]);
            exit;
        }

        $user = is_array($pending['user'] ?? null) ? $pending['user'] : [];
        login_api_delete_pending_2fa($token);
        $redirect = login_api_complete_session($user, (string)($user['email'] ?? ''));
        ob_clean();
        echo json_encode(['success' => true, 'message' => 'Login confirmado com sucesso!', 'redirect' => $redirect]);
        exit;
    }

    if (!security_rate_limit('login_api', 8, 300)) {
        ob_clean();
        security_log_event('login_rate_limited', ['endpoint' => 'api/login_process']);
        echo json_encode([
            'success' => false,
            'message' => 'Muitas tentativas. Aguarde alguns minutos e tente novamente.',
            'reason' => 'rate_limited',
        ]);
        exit;
    }

    $email = security_validate_email(login_api_get_request_value('email', 254)) ?? '';
    $senha = login_api_get_request_value('senha', 1024);

    if ($email === '' || $senha === '' || strlen($senha) > 1024) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Preencha email e senha validos.']);
        exit;
    }

    require_once APP_BASE . '/src/Controller/AuthController.php';
    if (!class_exists('AuthController')) {
        throw new RuntimeException('Classe AuthController nao encontrada.');
    }

    $controller = new AuthController();
    $resultado = $controller->login($email, $senha);
    if (!is_array($resultado) || !array_key_exists('success', $resultado)) {
        throw new RuntimeException('Resposta invalida do AuthController.');
    }

    ob_clean();

    if (!empty($resultado['success'])) {
        $user = $resultado['data'] ?? $resultado['usuario'] ?? null;
        if (!is_array($user)) {
            throw new RuntimeException('Dados do usuario ausentes na resposta de login.');
        }

        if (login_api_requires_2fa($user)) {
            $challenge2fa = login_api_store_pending_2fa($user);
            if (empty($challenge2fa['persisted']) && security_is_production()) {
                security_log_event('login_2fa_storage_failed_production', [
                    'usuario_id' => $user['id'] ?? null,
                ]);
                echo json_encode([
                    'success' => false,
                    'message' => 'Nao foi possivel inicializar a verificacao em duas etapas. Contacte o suporte.',
                ]);
                exit;
            }
            $emailSent = !empty($challenge2fa['email_sent']);
            if (!$emailSent && security_is_production()) {
                security_log_event('login_2fa_delivery_failed_production', [
                    'usuario_id' => $user['id'] ?? null,
                    'email' => $challenge2fa['email'] ?? '',
                ]);
                audit_log(null, 'login_2fa_delivery_failed', [
                    'usuario_id' => $user['id'] ?? null,
                    'email' => $challenge2fa['email'] ?? '',
                ], 'ERROR');
                echo json_encode([
                    'success' => false,
                    'message' => login_api_email_failure_message($challenge2fa['email_error'] ?? null),
                    'error_code' => 'LOGIN_2FA_EMAIL_DELIVERY_FAILED',
                    'email_sent' => false,
                ]);
                exit;
            }
            $response2fa = [
                'success' => false,
                'requires_2fa' => true,
                'two_factor_token' => $challenge2fa['token'],
                'email_sent' => $emailSent,
                'message' => $emailSent
                    ? 'Informe o codigo de verificacao enviado para o email da conta.'
                    : login_api_email_failure_message($challenge2fa['email_error'] ?? null),
            ];
            if (!$emailSent && !empty($challenge2fa['email_error']) && !security_is_production()) {
                $response2fa['email_error'] = $challenge2fa['email_error'];
            }
            if (!empty($challenge2fa['debug_code'])) {
                $response2fa['debug_otp'] = $challenge2fa['debug_code'];
            }
            echo json_encode($response2fa);
            exit;
        }

        $redirect = login_api_complete_session($user, $email);
        echo json_encode(['success' => true, 'message' => 'Login realizado com sucesso!', 'redirect' => $redirect]);
        exit;
    }

    $reason = (string)($resultado['reason'] ?? 'invalid_credentials');
    security_log_event('login_failed', ['endpoint' => 'api/login_process', 'email' => $email, 'reason' => $reason]);
    $payload = [
        'success' => false,
        'message' => (string)($resultado['message'] ?? 'Email ou senha invalidos'),
        'reason' => $reason,
    ];
    if (!empty($resultado['blocked_until'])) {
        $payload['blocked_until'] = $resultado['blocked_until'];
    }
    echo json_encode($payload);
} catch (Throwable $e) {
    error_log('Login API Error: ' . $e->getMessage());
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Erro interno no servidor.']);
}

exit;
