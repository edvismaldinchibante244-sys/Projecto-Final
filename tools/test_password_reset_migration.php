<?php
declare(strict_types=1);

use Nivora\Infrastructure\Database\MigrationRunner;

require_once __DIR__ . '/../app/src/Infrastructure/Database/MigrationRunner.php';

$root = realpath(__DIR__ . '/..');
$id = '20260821_1745_password_resets';
$failures = [];
$check = static function (bool $condition, string $name) use (&$failures): void {
    if (!$condition) {
        $failures[] = $name;
    }
};

$definitions = MigrationRunner::loadDefinitions($root . '/db/migrations');
$matches = array_values(array_filter($definitions, static fn(array $migration): bool => $migration['id'] === $id));
$check(count($matches) === 1, 'password_reset_migration_registered_once');

$migration = file_get_contents($root . '/db/migrations/' . $id . '.php');
$check(is_string($migration) && str_contains($migration, 'CREATE TABLE password_resets'), 'table_represented');
$check(is_string($migration) && str_contains($migration, 'ADD COLUMN user_id INT NULL'), 'user_id_represented');
$check(is_string($migration) && str_contains($migration, 'ADD COLUMN email_lookup CHAR(64) NULL'), 'email_lookup_represented');
$check(is_string($migration) && str_contains($migration, 'expires_at DATETIME NOT NULL'), 'portable_expiration_datetime');
$check(is_string($migration) && str_contains($migration, 'idx_token'), 'token_index_preserved');
$check(is_string($migration) && str_contains($migration, 'idx_email_lookup'), 'lookup_index_preserved');
$check(is_string($migration) && str_contains($migration, 'idx_user_id'), 'user_index_preserved');
$check(is_string($migration) && !str_contains($migration, 'DROP TABLE'), 'no_drop_table');
$check(is_string($migration) && !str_contains($migration, 'DELETE FROM'), 'no_delete_data');
$check(is_string($migration) && !str_contains($migration, 'UPDATE password_resets'), 'no_token_data_update');

$legacy = file_get_contents($root . '/app/src/config/password_reset_helper.php');
$check(is_string($legacy) && str_contains($legacy, $id), 'fallback_uses_migration_id');
$check(is_string($legacy) && str_contains($legacy, 'migration_esta_aplicada'), 'fallback_checks_state');
$check(is_string($legacy) && str_contains($legacy, 'CREATE TABLE IF NOT EXISTS password_resets'), 'table_fallback_preserved');
$check(is_string($legacy) && str_contains($legacy, 'ALTER TABLE password_resets ADD COLUMN user_id'), 'column_fallback_preserved');
$check(is_string($legacy) && str_contains($legacy, 'personal_data_encrypt($email)'), 'email_encryption_preserved');
$check(is_string($legacy) && str_contains($legacy, 'security_hash_secret($token)'), 'token_hashing_preserved');

if ($failures !== []) {
    fwrite(STDERR, 'FALHA: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}
echo 'OK: migracao de redefinicao de senha validada sem banco; fallback e protecoes preservados.' . PHP_EOL;
