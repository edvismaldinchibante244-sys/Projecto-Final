<?php
declare(strict_types=1);

use Nivora\Infrastructure\Database\MigrationRunner;

require_once __DIR__ . '/../app/src/Infrastructure/Database/MigrationRunner.php';

$root = realpath(__DIR__ . '/..');
$id = '20260814_1400_offline_operations';
$failures = [];
$check = static function (bool $condition, string $name) use (&$failures): void {
    if (!$condition) {
        $failures[] = $name;
    }
};

$definitions = MigrationRunner::loadDefinitions($root . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'migrations');
$matches = array_values(array_filter($definitions, static fn(array $migration): bool => $migration['id'] === $id));
$check(count($matches) === 1, 'offline_migration_registered_once');

$migration = file_get_contents($root . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . $id . '.php');
$check(is_string($migration) && str_contains($migration, 'CREATE TABLE IF NOT EXISTS offline_operations'), 'table_represented');
$check(is_string($migration) && str_contains($migration, 'uq_offline_operation (restaurante_id, op_id)'), 'operation_uniqueness_preserved');
$check(is_string($migration) && str_contains($migration, "DEFAULT 'PROCESSING'"), 'processing_state_preserved');
$check(is_string($migration) && str_contains($migration, 'request_hash CHAR(64)'), 'request_hash_preserved');
$check(is_string($migration) && !str_contains($migration, 'DROP TABLE'), 'no_drop_table');
$check(is_string($migration) && !str_contains($migration, 'DELETE FROM'), 'no_delete_data');

$legacy = file_get_contents($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'offline_idempotency.php');
$check(is_string($legacy) && str_contains($legacy, $id), 'fallback_uses_migration_id');
$check(is_string($legacy) && str_contains($legacy, 'migration_esta_aplicada'), 'fallback_checks_state');
$check(is_string($legacy) && str_contains($legacy, 'CREATE TABLE IF NOT EXISTS offline_operations'), 'fallback_preserved');

if ($failures !== []) {
    fwrite(STDERR, 'FALHA: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}
echo 'OK: migracao de idempotencia offline validada sem conexao; fallback preservado.' . PHP_EOL;
