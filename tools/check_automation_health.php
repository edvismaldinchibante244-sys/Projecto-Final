<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/src/config/database.php';
require_once __DIR__ . '/../app/src/config/email_helper.php';

$db = (new Database())->getConnection();
if (!$db instanceof PDO) {
    fwrite(STDERR, "FALHA: conexao PDO indisponivel.\n");
    exit(1);
}

$root = dirname(__DIR__);
$checks = [];
$warnings = [];
$errors = [];

$tableExists = static function (PDO $db, string $table): bool {
    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table LIMIT 1');
    $stmt->execute(['table' => $table]);
    return (bool)$stmt->fetchColumn();
};

$envOrConfig = static function (string $envKey, array $config, string $configKey, $default = null) {
    $value = saas_email_env_value($envKey);
    return $value !== null ? $value : ($config[$configKey] ?? $default);
};

$emailConfig = saas_email_carregar_config();
$effectiveEmail = [
    'smtp_host' => $envOrConfig('SMTP_HOST', $emailConfig, 'smtp_host', ''),
    'smtp_username' => $envOrConfig('SMTP_USERNAME', $emailConfig, 'smtp_username', ''),
    'smtp_password' => $envOrConfig('SMTP_PASSWORD', $emailConfig, 'smtp_password', ''),
    'smtp_from' => $envOrConfig('SMTP_FROM', $emailConfig, 'smtp_from', ''),
];
$useSmtpEnv = getenv('USE_SMTP');
$smtpEnabled = $useSmtpEnv !== false
    ? filter_var($useSmtpEnv, FILTER_VALIDATE_BOOLEAN)
    : (bool)($emailConfig['use_smtp'] ?? false);
$emailComplete = $smtpEnabled
    && !saas_email_is_placeholder_config($effectiveEmail)
    && saas_email_carregar_phpmailer() !== null;

$emailLog = $root . '/app/debug_email.log';
$emailLastSuccess = null;
if (is_file($emailLog) && is_readable($emailLog)) {
    $lines = file($emailLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    for ($index = count($lines) - 1; $index >= 0; $index--) {
        if (strpos((string)$lines[$index], 'SUCESSO: Email enviado') !== false) {
            $emailLastSuccess = substr((string)$lines[$index], 0, 19);
            break;
        }
    }
}

$checks['email'] = [
    'smtp_enabled' => $smtpEnabled,
    'config_complete' => $emailComplete,
    'phpmailer_available' => saas_email_carregar_phpmailer() !== null,
    'app_url_set' => saas_email_env_value('APP_URL') !== null,
    'last_success' => $emailLastSuccess,
];
if (!$emailComplete) {
    $errors[] = 'SMTP incompleto ou PHPMailer indisponivel.';
} elseif ($emailLastSuccess === null) {
    $warnings[] = 'SMTP configurado, mas nao existe evidencia local de entrega bem-sucedida.';
}

$checks['backup'] = [
    'tables_ready' => $tableExists($db, 'backup_configuracoes') && $tableExists($db, 'backup_historico'),
    'automatic_configs' => 0,
    'pending' => 0,
    'last_success' => null,
    'last_cron_success' => null,
    'cron_minutes_ago' => null,
];
if ($checks['backup']['tables_ready']) {
    $configStats = $db->query("SELECT
            SUM(automatico = 1) AS automaticos,
            SUM(automatico = 1 AND proximo_backup_em IS NOT NULL AND proximo_backup_em <= NOW()) AS pendentes
        FROM backup_configuracoes")->fetch(PDO::FETCH_ASSOC) ?: [];
    $historyStats = $db->query("SELECT
            MAX(CASE WHEN status = 'SUCESSO' THEN executado_em END) AS ultimo_sucesso,
            MAX(CASE WHEN status = 'SUCESSO' AND origem = 'cron' THEN executado_em END) AS ultimo_cron,
            TIMESTAMPDIFF(MINUTE, MAX(CASE WHEN status = 'SUCESSO' AND origem = 'cron' THEN executado_em END), NOW()) AS minutos_cron
        FROM backup_historico")->fetch(PDO::FETCH_ASSOC) ?: [];
    $checks['backup']['automatic_configs'] = (int)($configStats['automaticos'] ?? 0);
    $checks['backup']['pending'] = (int)($configStats['pendentes'] ?? 0);
    $checks['backup']['last_success'] = $historyStats['ultimo_sucesso'] ?? null;
    $checks['backup']['last_cron_success'] = $historyStats['ultimo_cron'] ?? null;
    $checks['backup']['cron_minutes_ago'] = isset($historyStats['minutos_cron']) ? (int)$historyStats['minutos_cron'] : null;

    if ($checks['backup']['automatic_configs'] > 0 && $checks['backup']['last_cron_success'] === null) {
        $errors[] = 'Existem backups automaticos, mas o cron nunca concluiu uma execucao.';
    } elseif ($checks['backup']['automatic_configs'] > 0 && (int)$checks['backup']['cron_minutes_ago'] > 90) {
        $warnings[] = 'O cron de backup nao conclui uma execucao ha mais de 90 minutos.';
    }
    if ($checks['backup']['pending'] > 0) {
        $warnings[] = 'Existem backups automaticos pendentes.';
    }
} else {
    $errors[] = 'Tabelas de backup ainda nao foram inicializadas.';
}

$lifecycleScript = $root . '/src/public/cron/notificar_vencimento_planos.php';
$lifecycleWrapper = $root . '/scripts/cron/run_plano_lifecycle_cron.ps1';
$lifecycleLog = $root . '/app/storage/logs/plano_lifecycle.log';
$lifecycleLogAgeHours = null;
if (is_file($lifecycleLog)) {
    $lifecycleLogAgeHours = round((time() - (int)filemtime($lifecycleLog)) / 3600, 1);
}
$checks['lifecycle'] = [
    'script_exists' => is_file($lifecycleScript),
    'wrapper_exists' => is_file($lifecycleWrapper),
    'log_exists' => is_file($lifecycleLog),
    'log_age_hours' => $lifecycleLogAgeHours,
    'http_token_set' => trim((string)(getenv('CRON_HTTP_TOKEN') ?: '')) !== '',
];
if (!$checks['lifecycle']['script_exists'] || !$checks['lifecycle']['wrapper_exists']) {
    $errors[] = 'Script ou wrapper do lifecycle esta ausente.';
}
if ($lifecycleLogAgeHours === null) {
    $warnings[] = 'O lifecycle ainda nao possui log de execucao agendada.';
} elseif ($lifecycleLogAgeHours > 36) {
    $warnings[] = 'O cron de lifecycle nao registra execucao ha mais de 36 horas.';
}
if (!$checks['lifecycle']['http_token_set']) {
    $warnings[] = 'CRON_HTTP_TOKEN nao esta configurado; chamadas HTTP aos crons permanecem bloqueadas.';
}

echo json_encode([
    'success' => !$errors,
    'healthy' => !$errors && !$warnings,
    'checked_at' => date('c'),
    'checks' => $checks,
    'warnings' => $warnings,
    'errors' => $errors,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;

exit($errors ? 1 : 0);

