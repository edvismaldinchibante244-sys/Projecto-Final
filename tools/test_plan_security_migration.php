<?php
declare(strict_types=1);

use Nivora\Infrastructure\Database\MigrationRunner;

require_once __DIR__ . '/../app/src/Infrastructure/Database/MigrationRunner.php';

$root = realpath(__DIR__ . '/..');
$id = '20260821_1715_compras_planos_hardening';
$failures = [];
$check = static function (bool $condition, string $name) use (&$failures): void {
    if (!$condition) {
        $failures[] = $name;
    }
};

$definitions = MigrationRunner::loadDefinitions($root . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'migrations');
$matches = array_values(array_filter($definitions, static fn(array $migration): bool => $migration['id'] === $id));
$check(count($matches) === 1, 'plan_security_migration_registered_once');

$migration = file_get_contents($root . '/db/migrations/' . $id . '.php');
$check(is_string($migration) && str_contains($migration, 'ADD COLUMN hash_arquivo CHAR(64) NULL'), 'file_hash_represented');
$check(is_string($migration) && str_contains($migration, 'idx_compras_hash_arquivo'), 'file_hash_index_represented');
$check(is_string($migration) && str_contains($migration, "'TRIAL'"), 'trial_method_represented');
$check(is_string($migration) && str_contains($migration, 'Metodo de pagamento nao suportado'), 'unsafe_enum_conversion_blocked');
$check(is_string($migration) && !str_contains($migration, 'DROP TABLE'), 'no_drop_table');
$check(is_string($migration) && !str_contains($migration, 'DELETE FROM'), 'no_delete_data');

$legacy = file_get_contents($root . '/app/src/config/plan_security.php');
$check(is_string($legacy) && str_contains($legacy, $id), 'fallback_uses_migration_id');
$check(is_string($legacy) && str_contains($legacy, 'migration_esta_aplicada'), 'fallback_checks_state');
$check(is_string($legacy) && str_contains($legacy, 'ADD COLUMN hash_arquivo'), 'column_fallback_preserved');
$check(is_string($legacy) && str_contains($legacy, 'MODIFY COLUMN metodo_pagamento'), 'enum_fallback_preserved');

if ($failures !== []) {
    fwrite(STDERR, 'FALHA: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}
echo 'OK: migracao de seguranca das compras validada sem conexao ao banco; fallback preservado.' . PHP_EOL;
