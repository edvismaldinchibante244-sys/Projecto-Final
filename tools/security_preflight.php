<?php
declare(strict_types=1);

/**
 * Security preflight checker.
 *
 * Usage:
 *   php tools/security_preflight.php
 *   php tools/security_preflight.php --json
 */

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Unable to resolve project root.\n");
    exit(2);
}

$opts = getopt('', ['json']);
$asJson = isset($opts['json']);

/**
 * @return array{status:string,name:string,detail:string}
 */
function check_result(string $status, string $name, string $detail): array
{
    return ['status' => $status, 'name' => $name, 'detail' => $detail];
}

/**
 * @return string
 */
function env_value(string $file, string $key): string
{
    if (!is_file($file)) {
        return '';
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return '';
    }
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        if (trim($parts[0]) === $key) {
            return trim($parts[1], " \t\n\r\0\x0B\"'");
        }
    }
    return '';
}

/**
 * @return bool
 */
function contains_text(string $file, string $needle): bool
{
    if (!is_file($file)) {
        return false;
    }
    $content = file_get_contents($file);
    return is_string($content) && strpos($content, $needle) !== false;
}

/**
 * @return array<int, string>
 */
function list_api_files(string $apiDir): array
{
    $files = glob($apiDir . DIRECTORY_SEPARATOR . '*.php');
    if (!is_array($files)) {
        return [];
    }
    sort($files);
    return $files;
}

$results = [];
$criticalFailures = 0;

$envFile = $root . DIRECTORY_SEPARATOR . '.env';
$legacyAppEnvFile = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . '.env';
$publicEnvFile = $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . '.env';
$srcPublicEnvFile = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . '.env';
$envExample = $root . DIRECTORY_SEPARATOR . '.env.example';
$gitignore = $root . DIRECTORY_SEPARATOR . '.gitignore';
$securityPhp = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'security.php';
$auditPhp = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'audit.php';
$auditSchema = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'schema_security_audit.sql';
$apiDir = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api';
$htaccessRoot = $root . DIRECTORY_SEPARATOR . '.htaccess';
$htaccessPublic = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . '.htaccess';

// .env presence
if (is_file($envFile)) {
    $results[] = check_result('PASS', '.env exists', 'Project has a local .env file.');
} else {
    $results[] = check_result('FAIL', '.env exists', 'Missing .env file.');
    $criticalFailures++;
}

// Duplicate env files / public env exposure
if (is_file($legacyAppEnvFile)) {
    $results[] = check_result('WARN', 'Legacy app/.env', 'Found app/.env. Prefer a single root .env to avoid config drift.');
} else {
    $results[] = check_result('PASS', 'Legacy app/.env', 'No app/.env found.');
}

$publicEnvHits = [];
if (is_file($publicEnvFile)) {
    $publicEnvHits[] = 'public/.env';
}
if (is_file($srcPublicEnvFile)) {
    $publicEnvHits[] = 'src/public/.env';
}
if (count($publicEnvHits) === 0) {
    $results[] = check_result('PASS', '.env in public', 'No .env file inside public web directories.');
} else {
    $results[] = check_result('FAIL', '.env in public', 'Sensitive env file exposed in web path: ' . implode(', ', $publicEnvHits));
    $criticalFailures++;
}

// .env example must not contain real credentials (basic heuristics)
$exampleKey = env_value($envExample, 'APP_KEY');
if ($exampleKey === '' || stripos($exampleKey, 'gere_uma_chave') !== false || strlen($exampleKey) < 32) {
    $results[] = check_result('PASS', '.env.example sanitized', 'No obvious real APP_KEY value.');
} else {
    $results[] = check_result('WARN', '.env.example sanitized', 'APP_KEY in .env.example looks non-placeholder; review manually.');
}

// APP_ENV production
$appEnv = strtolower(env_value($envFile, 'APP_ENV'));
if ($appEnv === 'production' || $appEnv === 'prod') {
    $results[] = check_result('PASS', 'APP_ENV production', 'APP_ENV=' . $appEnv);
} else {
    $results[] = check_result('FAIL', 'APP_ENV production', 'APP_ENV should be production/prod, found: ' . ($appEnv !== '' ? $appEnv : '(empty)'));
    $criticalFailures++;
}

// APP_KEY strength
$appKey = env_value($envFile, 'APP_KEY');
if ($appKey !== '' && strlen($appKey) >= 32 && stripos($appKey, 'gere_uma_chave') === false) {
    $results[] = check_result('PASS', 'APP_KEY strong', 'APP_KEY length looks acceptable.');
} else {
    $results[] = check_result('FAIL', 'APP_KEY strong', 'APP_KEY missing/weak/placeholder.');
    $criticalFailures++;
}

// webhook secret presence
$webhookSecret = env_value($envFile, 'PLANO_WEBHOOK_SECRET');
if ($webhookSecret !== '' && stripos($webhookSecret, 'gere_') === false) {
    $results[] = check_result('PASS', 'Webhook secret', 'PLANO_WEBHOOK_SECRET is present.');
} else {
    $results[] = check_result('FAIL', 'Webhook secret', 'PLANO_WEBHOOK_SECRET missing or placeholder.');
    $criticalFailures++;
}

// .gitignore checks
$ignoreOk = contains_text($gitignore, '.env') && contains_text($gitignore, 'storage/');
if ($ignoreOk) {
    $results[] = check_result('PASS', '.gitignore sensitive paths', 'Contains .env and storage ignore rules.');
} else {
    $results[] = check_result('WARN', '.gitignore sensitive paths', 'Missing expected .env/storage patterns.');
}

// security headers and HTTPS enforcement
$hasHsts = contains_text($securityPhp, 'Strict-Transport-Security');
$hasHttpsEnforce = contains_text($securityPhp, 'security_enforce_https');
if ($hasHsts && $hasHttpsEnforce) {
    $results[] = check_result('PASS', 'HTTPS/HSTS logic', 'security.php contains HTTPS enforcement and HSTS header.');
} else {
    $results[] = check_result('FAIL', 'HTTPS/HSTS logic', 'security.php missing HTTPS enforcement or HSTS.');
    $criticalFailures++;
}

// display_errors off in production logic
$hasDisplayErrorsOff = contains_text($securityPhp, "ini_set('display_errors', '0')");
if ($hasDisplayErrorsOff) {
    $results[] = check_result('PASS', 'display_errors control', 'Production branch disables display_errors.');
} else {
    $results[] = check_result('WARN', 'display_errors control', 'display_errors hardening not found in security.php.');
}

// htaccess checks
$hasSensitiveDeny = contains_text($htaccessRoot, '.env') || contains_text($htaccessPublic, '.env');
if ($hasSensitiveDeny) {
    $results[] = check_result('PASS', '.htaccess sensitive protection', 'At least one .htaccess references sensitive file protection.');
} else {
    $results[] = check_result('WARN', '.htaccess sensitive protection', 'Could not confirm sensitive-file deny rules.');
}

// audit persistence checks
$hasAuditLogFn = contains_text($auditPhp, 'function audit_log');
$hasAuditSchema = is_file($auditSchema) && contains_text($auditSchema, 'CREATE TABLE IF NOT EXISTS audit_logs');
if ($hasAuditLogFn && $hasAuditSchema) {
    $results[] = check_result('PASS', 'Audit persistence', 'audit_log() and audit_logs schema are present.');
} else {
    $results[] = check_result('FAIL', 'Audit persistence', 'Missing audit_log() or schema_security_audit.sql for audit_logs.');
    $criticalFailures++;
}

// API coverage check
$apiFiles = list_api_files($apiDir);
$guardCount = 0;
$missingGuard = [];
foreach ($apiFiles as $file) {
    $content = file_get_contents($file);
    if (!is_string($content)) {
        continue;
    }
    if (strpos($content, 'api_guard(') !== false) {
        $guardCount++;
    } else {
        $missingGuard[] = basename($file);
    }
}
$totalApi = count($apiFiles);
$expectedPublic = [
    'login_process.php',
    'login_simples.php',
    'esqueci_senha.php',
    'redefinir_senha.php',
    'reserva_publica.php',
    'gateway_webhook_plano.php',
    'notificar_cliente.php',
];
$unexpectedMissing = array_values(array_diff($missingGuard, $expectedPublic));
$coverageStatus = count($unexpectedMissing) === 0 ? 'PASS' : 'WARN';
$results[] = check_result(
    $coverageStatus,
    'API guard coverage',
    sprintf('%d/%d endpoints with api_guard. Missing public/allowlisted endpoints: %s', $guardCount, $totalApi, implode(', ', $missingGuard))
);

// Public endpoint allowlist (without api_guard)
if (count($unexpectedMissing) === 0) {
    $results[] = check_result('PASS', 'Public endpoint allowlist', 'Only expected public/webhook/helper endpoints are outside api_guard.');
} else {
    $results[] = check_result('FAIL', 'Public endpoint allowlist', 'Unexpected endpoints without api_guard: ' . implode(', ', $unexpectedMissing));
    $criticalFailures++;
}

// Session fixation hardening
$sessionClientRejected = contains_text($securityPhp, 'session_id_client_rejected')
    && !preg_match('/session_id\(\s*\$requestedSessionId\s*\)/', (string)@file_get_contents($securityPhp));
if ($sessionClientRejected) {
    $results[] = check_result('PASS', 'Session ID client rejection', 'Client-supplied session_id / X-Session-Id is not applied.');
} else {
    $results[] = check_result('FAIL', 'Session ID client rejection', 'security.php may still accept client session_id (fixation risk).');
    $criticalFailures++;
}

// Trusted proxy / IP spoofing hardening
$hasTrustedProxies = contains_text($securityPhp, 'security_trusted_proxies')
    && contains_text($securityPhp, 'TRUSTED_PROXIES');
if ($hasTrustedProxies) {
    $results[] = check_result('PASS', 'Trusted proxy IP logic', 'X-Forwarded-For only trusted via TRUSTED_PROXIES / Cloudflare.');
} else {
    $results[] = check_result('FAIL', 'Trusted proxy IP logic', 'Missing TRUSTED_PROXIES hardening in security_get_client_ip.');
    $criticalFailures++;
}

// SameSite cookie
$sameSiteStrictOrLax = contains_text($securityPhp, "'samesite' => \$sameSite")
    || contains_text($securityPhp, "samesite' => 'Strict")
    || contains_text($securityPhp, "samesite' => 'Lax");
$sameSiteNone = contains_text($securityPhp, "samesite' => \$secureCookie ? 'None'");
if ($sameSiteStrictOrLax && !$sameSiteNone) {
    $results[] = check_result('PASS', 'Cookie SameSite', 'Production cookie SameSite is Strict/Lax (not None).');
} else {
    $results[] = check_result('WARN', 'Cookie SameSite', 'Review session cookie SameSite in security.php.');
}

// Critical APIs must declare roles
$rbacMustHave = [
    'clientes.php',
    'historico_cliente.php',
    'marketing.php',
    'relatorio_reservas.php',
    'online_ping.php',
    'venda_criar.php',
    'team_online_status.php',
];
$rbacMissing = [];
foreach ($rbacMustHave as $apiName) {
    $apiPath = $apiDir . DIRECTORY_SEPARATOR . $apiName;
    if (!is_file($apiPath) || !contains_text($apiPath, "'roles'")) {
        $rbacMissing[] = $apiName;
    }
}
if (count($rbacMissing) === 0) {
    $results[] = check_result('PASS', 'Critical API RBAC', 'Key endpoints declare roles in api_guard.');
} else {
    $results[] = check_result('FAIL', 'Critical API RBAC', 'Missing roles on: ' . implode(', ', $rbacMissing));
    $criticalFailures++;
}

// IDOR helper + public pedido tracking must not accept bare pedido_id
$resourceAccessPhp = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'resource_access.php';
$apiGuardPhp = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'api_guard.php';
$reservaPublicaHandler = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'reserva_publica_handler.php';

if (is_file($resourceAccessPhp)
    && contains_text($resourceAccessPhp, 'function resource_require_for_tenant')
    && contains_text($resourceAccessPhp, 'idor_denied')
    && contains_text($apiGuardPhp, 'resource_access.php')
) {
    $results[] = check_result('PASS', 'IDOR resource_access helper', 'resource_access.php exists and is loaded from api_guard.');
} else {
    $results[] = check_result('FAIL', 'IDOR resource_access helper', 'Missing resource_access.php or api_guard include.');
    $criticalFailures++;
}

$handlerSrc = is_file($reservaPublicaHandler) ? (string)@file_get_contents($reservaPublicaHandler) : '';
$hasBarePedidoIdBranch = preg_match(
    '/elseif\s*\(\s*\$pedidoIdFiltro\s*>\s*0\s*\)\s*\{[\s\S]*?FROM\s+pedidos\s+p[\s\S]*?WHERE\s+p\.id\s*=\s*:pid/i',
    $handlerSrc
) === 1;
$tokenRequired = contains_text($reservaPublicaHandler, 'Anti-IDOR')
    || (contains_text($reservaPublicaHandler, "if (\$token === '')")
        && !$hasBarePedidoIdBranch);

if ($tokenRequired && !$hasBarePedidoIdBranch) {
    $results[] = check_result('PASS', 'Public acompanhar_pedido IDOR', 'acompanhar_pedido requires QR token; no bare pedido_id lookup.');
} else {
    $results[] = check_result('FAIL', 'Public acompanhar_pedido IDOR', 'acompanhar_pedido still allows pedido_id without token.');
    $criticalFailures++;
}

// Document root guidance in root .htaccess
$rootBlocksTools = contains_text($htaccessRoot, 'tools') && contains_text($htaccessRoot, 'src/public');
if ($rootBlocksTools) {
    $results[] = check_result('PASS', 'Root .htaccess document-root fallback', 'Root rewrite blocks tools/docs and routes to src/public.');
} else {
    $results[] = check_result('WARN', 'Root .htaccess document-root fallback', 'Ensure DocumentRoot is src/public and root .htaccess blocks sensitive paths.');
}

// WAF ops reminder (non-blocking)
$wafProvider = env_value($envFile, 'APP_WAF_PROVIDER');
if ($wafProvider !== '' && stripos($wafProvider, 'cloudflare_ou_') === false) {
    $results[] = check_result('PASS', 'Edge WAF configured (env)', 'APP_WAF_PROVIDER is set.');
} else {
    $results[] = check_result('WARN', 'Edge WAF configured (env)', 'Set real APP_WAF_PROVIDER and enable Cloudflare/AWS WAF in block mode before go-live.');
}

// output
if ($asJson) {
    $payload = [
        'generated_at' => date('c'),
        'project_root' => $root,
        'critical_failures' => $criticalFailures,
        'results' => $results,
    ];
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($criticalFailures > 0 ? 1 : 0);
}

$statusColor = [
    'PASS' => '[PASS]',
    'WARN' => '[WARN]',
    'FAIL' => '[FAIL]',
];
echo "Security Preflight\n";
echo "Generated: " . date('c') . "\n\n";
foreach ($results as $row) {
    $tag = $statusColor[$row['status']] ?? '[INFO]';
    echo $tag . ' ' . $row['name'] . "\n";
    echo '  - ' . $row['detail'] . "\n";
}
echo "\nCritical failures: " . $criticalFailures . "\n";

exit($criticalFailures > 0 ? 1 : 0);
