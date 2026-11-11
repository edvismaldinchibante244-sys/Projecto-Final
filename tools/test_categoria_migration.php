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

$migrationId = '20260814_1145_categoria_hierarquia';
$definitions = MigrationRunner::loadDefinitions($root . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'migrations');
$categoryMigrations = array_values(array_filter(
    $definitions,
    static fn(array $migration): bool => $migration['id'] === $migrationId
));
$check(count($categoryMigrations) === 1, 'category_migration_registered_once');

$migrationSource = file_get_contents($root . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . $migrationId . '.php');
$check(is_string($migrationSource) && str_contains($migrationSource, "table_name = 'categorias'"), 'base_table_required');
$check(is_string($migrationSource) && str_contains($migrationSource, 'ADD COLUMN parent_id INT NULL DEFAULT NULL'), 'parent_id_equivalent');
$check(is_string($migrationSource) && str_contains($migrationSource, 'ADD COLUMN ativo TINYINT(1) NOT NULL DEFAULT 1'), 'ativo_equivalent');
$check(is_string($migrationSource) && !str_contains($migrationSource, 'DROP TABLE'), 'no_drop_table');
$check(is_string($migrationSource) && !str_contains($migrationSource, 'DELETE FROM'), 'no_delete_data');

$legacySource = file_get_contents($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'categoria_schema.php');
$check(is_string($legacySource) && str_contains($legacySource, $migrationId), 'fallback_uses_same_migration_id');
$check(is_string($legacySource) && str_contains($legacySource, 'migration_esta_aplicada'), 'fallback_checks_migration_state');
$check(is_string($legacySource) && str_contains($legacySource, 'ALTER TABLE categorias'), 'legacy_fallback_preserved');

if ($failures !== []) {
    fwrite(STDERR, 'FALHA: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo 'OK: migracao piloto de categorias validada sem conexao ao banco; fallback legado preservado.' . PHP_EOL;
