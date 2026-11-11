<?php
declare(strict_types=1);

use Nivora\Infrastructure\Database\MigrationRunner;

require_once __DIR__ . '/../app/src/Infrastructure/Database/MigrationRunner.php';

$root = realpath(__DIR__ . '/..');
$id = '20260814_1330_plano_catalogo';
$failures = [];
$check = static function (bool $condition, string $name) use (&$failures): void {
    if (!$condition) {
        $failures[] = $name;
    }
};

$definitions = MigrationRunner::loadDefinitions($root . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'migrations');
$matches = array_values(array_filter($definitions, static fn(array $migration): bool => $migration['id'] === $id));
$check(count($matches) === 1, 'plan_catalog_migration_registered_once');

$migration = file_get_contents($root . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . $id . '.php');
foreach (['planos_disponiveis', 'restaurante_planos', 'plano_funcionalidades', 'plano_logs'] as $table) {
    $check(is_string($migration) && str_contains($migration, 'CREATE TABLE IF NOT EXISTS ' . $table), $table . '_represented');
}
$check(is_string($migration) && str_contains($migration, "['BASICO', 'Básico'"), 'default_plans_seeded');
$check(is_string($migration) && str_contains($migration, 'unique_plano_func'), 'feature_uniqueness_preserved');
$check(is_string($migration) && !str_contains($migration, 'DROP VIEW'), 'migration_does_not_drop_view');
$check(is_string($migration) && !str_contains($migration, 'DROP TABLE'), 'no_drop_table');
$check(is_string($migration) && !str_contains($migration, 'DELETE FROM'), 'no_delete_data');

$legacy = file_get_contents($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'plano_check.php');
$check(is_string($legacy) && str_contains($legacy, $id), 'fallback_uses_migration_id');
$check(is_string($legacy) && str_contains($legacy, 'migration_esta_aplicada'), 'fallback_checks_state');
$check(is_string($legacy) && str_contains($legacy, 'CREATE TABLE IF NOT EXISTS planos_disponiveis'), 'fallback_preserved');

if ($failures !== []) {
    fwrite(STDERR, 'FALHA: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo 'OK: migracao do catalogo de planos validada sem conexao; fallback preservado.' . PHP_EOL;
