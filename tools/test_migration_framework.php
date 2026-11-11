<?php
declare(strict_types=1);

use Nivora\Infrastructure\Database\MigrationRunner;

require_once __DIR__ . '/../app/src/Infrastructure/Database/MigrationRunner.php';

$root = realpath(__DIR__ . '/..');
$failures = [];
$check = static function (bool $condition, string $name) use (&$failures): void {
    if (!$condition) {
        $failures[] = $name;
    }
};

$directory = $root . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'migrations';
$definitions = MigrationRunner::loadDefinitions($directory);
$check(is_array($definitions), 'definitions_are_array');
$check(MigrationRunner::APPLY_CONFIRMATION === 'APPLY_MIGRATIONS', 'explicit_apply_confirmation');

$cli = file_get_contents(__DIR__ . '/db_migrate.php');
$check(is_string($cli) && str_contains($cli, "\$mode = \$modes[0] ?? 'validate'"), 'safe_default_validate');
$check(is_string($cli) && str_contains($cli, "'confirm:'"), 'apply_requires_confirmation');
$check(is_string($cli) && str_contains($cli, "'target:'"), 'apply_requires_exact_target');
$check(is_string($cli) && str_contains($cli, "'allow-non-test'"), 'non_test_requires_extra_flag');
$confirmationPosition = is_string($cli) ? strpos($cli, 'Confirmacao invalida. Banco nao conectado') : false;
$connectionPosition = is_string($cli) ? strpos($cli, '(new Database())->getConnection()') : false;
$check(
    is_int($confirmationPosition) && is_int($connectionPosition) && $confirmationPosition < $connectionPosition,
    'confirmation_checked_before_database_connection'
);

$runnerSource = file_get_contents($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Infrastructure' . DIRECTORY_SEPARATOR . 'Database' . DIRECTORY_SEPARATOR . 'MigrationRunner.php');
$check(is_string($runnerSource) && str_contains($runnerSource, "SELECT DATABASE()"), 'runner_checks_connected_database');
$check(is_string($runnerSource) && str_contains($runnerSource, 'hash_equals($actualDatabase, $targetDatabase)'), 'runner_matches_exact_target');
$check(is_string($runnerSource) && str_contains($runnerSource, '(test|testing|sandbox|staging)'), 'runner_recognizes_isolated_targets');

$readme = file_get_contents($directory . DIRECTORY_SEPARATOR . 'README.md');
$check(is_string($readme) && str_contains($readme, 'rollback') && str_contains($readme, 'DDL MySQL'), 'ddl_rollback_risk_documented');

if ($failures !== []) {
    fwrite(STDERR, 'FALHA: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo 'OK: infraestrutura de migracoes validada (' . count($definitions) . ' migracao(oes) activa(s), banco nao conectado).' . PHP_EOL;
