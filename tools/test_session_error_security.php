<?php
declare(strict_types=1);

putenv('APP_ENV=production');
$_SERVER['HTTP_HOST'] = 'app.example.test';
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);

$sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'v00-security-session-' . bin2hex(random_bytes(4));
if (!mkdir($sessionDir, 0700, true) && !is_dir($sessionDir)) {
    fwrite(STDERR, "Nao foi possivel criar diretorio temporario de sessao." . PHP_EOL);
    exit(1);
}
session_save_path($sessionDir);
putenv('SESSION_SAVE_PATH=' . $sessionDir);

require_once __DIR__ . '/../app/src/config/security.php';
require_once __DIR__ . '/../app/src/config/csrf.php';

$failures = [];
$check = static function (bool $condition, string $name) use (&$failures): void {
    if (!$condition) {
        $failures[] = $name;
    }
};

$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
$_SERVER['HTTP_CF_RAY'] = 'spoofed';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.99';
$check(!security_is_https(), 'untrusted_forwarded_proto_ignored');
$check(security_get_client_ip() === '203.0.113.10', 'untrusted_cloudflare_headers_ignored');
unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTP_CF_RAY'], $_SERVER['HTTP_CF_CONNECTING_IP']);

security_start_session();
$params = session_get_cookie_params();
$check((bool)$params['secure'], 'production_cookie_secure');
$check((bool)$params['httponly'], 'production_cookie_httponly');
$check(strcasecmp((string)($params['samesite'] ?? ''), 'Strict') === 0, 'production_cookie_samesite_strict');
$check((string)ini_get('session.use_strict_mode') === '1', 'session_strict_mode');
$check((string)ini_get('session.use_only_cookies') === '1', 'session_cookies_only');
$check((int)ini_get('session.gc_maxlifetime') >= 2700, 'session_storage_lifetime_covers_idle_timeout');
$check(session_save_path() === $sessionDir, 'session_storage_path_configured');

$token = csrf_get_token();
$_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
$check(strlen($token) === 64, 'csrf_token_entropy');
$check(csrf_is_valid(), 'csrf_valid_token');
$check(!csrf_is_valid('invalid-token'), 'csrf_invalid_token');

$generic = security_exception_message(
    new RuntimeException('SQLSTATE secret database detail'),
    'Erro seguro.',
    'test_safe_exception',
    false
);
$business = security_exception_message(
    new DomainException('Regra de negocio valida.'),
    'Erro seguro.',
    'test_safe_domain_exception'
);
$check($generic === 'Erro seguro.', 'production_exception_is_generic');
$check($business === 'Regra de negocio valida.', 'domain_exception_is_preserved');

$_SESSION['last_activity'] = time() - (45 * 60) + 2;
$check(security_enforce_idle_timeout(45), 'session_stays_active_before_45_minutes');
$_SESSION['last_activity'] = time() - (45 * 60) - 2;
$check(!security_enforce_idle_timeout(45), 'session_expires_after_45_minutes');

$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}
foreach (glob($sessionDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($sessionDir);

if ($failures !== []) {
    fwrite(STDERR, 'FALHA: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo 'OK: sessao, CSRF e mensagens de erro seguras validadas.' . PHP_EOL;
