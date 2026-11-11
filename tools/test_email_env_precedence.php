<?php
declare(strict_types=1);

$syntheticDatabase = 'nivora_env_precedence_test';
$syntheticSmtpHost = 'process.smtp.invalid';

putenv('DB_NAME=' . $syntheticDatabase);
putenv('SMTP_HOST=' . $syntheticSmtpHost);
$_ENV['DB_NAME'] = $syntheticDatabase;
$_SERVER['DB_NAME'] = $syntheticDatabase;
$_ENV['SMTP_HOST'] = $syntheticSmtpHost;
$_SERVER['SMTP_HOST'] = $syntheticSmtpHost;

require_once __DIR__ . '/../app/src/config/email_helper.php';

$failures = [];
$check = static function (bool $condition, string $name) use (&$failures): void {
    if (!$condition) {
        $failures[] = $name;
    }
};

$loaded = saas_email_load_local_env();
$check(is_array($loaded), 'env_files_parsed');
$check(getenv('DB_NAME') === $syntheticDatabase, 'process_database_preserved');
$check(($_ENV['DB_NAME'] ?? null) === $syntheticDatabase, 'env_database_preserved');
$check(($_SERVER['DB_NAME'] ?? null) === $syntheticDatabase, 'server_database_preserved');
$check(getenv('SMTP_HOST') === $syntheticSmtpHost, 'process_smtp_preserved');

putenv('APP_ENV=production');
$_ENV['APP_ENV'] = 'production';
$_SERVER['APP_ENV'] = 'production';
$check(saas_email_env_value('SMTP_HOST') === $syntheticSmtpHost, 'production_email_reader_uses_process_value');

putenv('APP_ENV=local');
$_ENV['APP_ENV'] = 'local';
$_SERVER['APP_ENV'] = 'local';
$localSmtpHost = $loaded['SMTP_HOST'] ?? null;
if (is_string($localSmtpHost) && trim($localSmtpHost) !== '') {
    $check(
        saas_email_env_value('SMTP_HOST') === trim($localSmtpHost),
        'local_email_reader_uses_project_smtp_value'
    );
}

$source = file_get_contents(__DIR__ . '/../app/src/config/email_helper.php');
$check(is_string($source) && str_contains($source, '$alreadyDefined'), 'precedence_guard_present');
$check(is_string($source) && str_contains($source, 'if ($alreadyDefined)'), 'precedence_guard_enforced');
$check(is_string($source) && str_contains($source, 'saas_email_is_production_environment'), 'smtp_environment_scope_present');

if ($failures !== []) {
    fwrite(STDERR, 'FALHA: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo 'OK: processo preservado; SMTP local usa .env e producao usa variaveis do processo.' . PHP_EOL;
