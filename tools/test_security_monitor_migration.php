<?php
declare(strict_types=1);

use Nivora\Infrastructure\Database\MigrationRunner;

require_once __DIR__ . '/../app/src/Infrastructure/Database/MigrationRunner.php';

$root = realpath(__DIR__ . '/..');
$id = '20260814_1440_security_monitor';
$failures = [];
$check = static function (bool $condition, string $name) use (&$failures): void {
    if (!$condition) {
        $failures[] = $name;
    }
};

$definitions = MigrationRunner::loadDefinitions($root . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'migrations');
$matches = array_values(array_filter($definitions, static fn(array $migration): bool => $migration['id'] === $id));
$check(count($matches) === 1, 'security_monitor_migration_registered_once');

$migration = file_get_contents($root . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . $id . '.php');
foreach (['blocked_ips', 'security_events', 'offline_sync_queue'] as $table) {
    $check(is_string($migration) && str_contains($migration, 'CREATE TABLE IF NOT EXISTS ' . $table), $table . '_represented');
}
$check(is_string($migration) && str_contains($migration, 'uq_offline_op_id'), 'offline_operation_unique_index');
$check(is_string($migration) && !str_contains($migration, 'DROP TABLE'), 'no_drop_table');
$check(is_string($migration) && !str_contains($migration, 'DELETE FROM'), 'no_delete_data');
$check(is_string($migration) && !str_contains($migration, 'UPDATE blocked_ips'), 'no_business_data_update');

$legacy = file_get_contents($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'security_monitor.php');
$check(is_string($legacy) && str_contains($legacy, $id), 'fallback_uses_migration_id');
$check(is_string($legacy) && str_contains($legacy, 'migration_esta_aplicada'), 'fallback_checks_state');
foreach (['blocked_ips', 'security_events', 'offline_sync_queue'] as $table) {
    $check(is_string($legacy) && str_contains($legacy, 'CREATE TABLE IF NOT EXISTS ' . $table), $table . '_fallback_preserved');
}
$check(is_string($legacy) && str_contains($legacy, 'security_monitor_detect_attack_type'), 'attack_detection_preserved');
$check(is_string($legacy) && str_contains($legacy, 'security_monitor_block_ip'), 'ip_blocking_preserved');

if ($failures !== []) {
    fwrite(STDERR, 'FALHA: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}
echo 'OK: migracao do monitor de seguranca validada sem conexao ao banco; fallback preservado.' . PHP_EOL;
