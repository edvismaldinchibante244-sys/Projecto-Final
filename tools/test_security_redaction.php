<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/src/config/security.php';

$failures = [];
$check = static function (bool $condition, string $name) use (&$failures): void {
    if (!$condition) {
        $failures[] = $name;
    }
};

$redacted = security_redact_log_data([
    'token' => 'secret-token',
    'nested' => [
        'password' => 'secret-password',
        'safe' => 'visible',
        'url' => 'https://example.test/reset?token=abc&email=user%40example.test#fragment',
    ],
    'Authorization' => 'Bearer secret',
    'cookie' => ['session_id' => 'secret-session'],
]);

$check($redacted['token'] === '[REDACTED]', 'token_redacted');
$check($redacted['nested']['password'] === '[REDACTED]', 'nested_password_redacted');
$check($redacted['nested']['safe'] === 'visible', 'safe_value_preserved');
$check(
    $redacted['nested']['url'] === '/reset?token=[REDACTED]&email=[REDACTED]',
    'url_host_fragment_removed_and_query_masked'
);
$check($redacted['Authorization'] === '[REDACTED]', 'authorization_redacted');
$check($redacted['cookie'] === '[REDACTED]', 'cookie_tree_redacted');

if ($failures !== []) {
    fwrite(STDERR, 'FALHA: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo 'OK: redacao recursiva e sanitizacao de URLs validadas.' . PHP_EOL;
