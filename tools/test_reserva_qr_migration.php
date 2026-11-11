<?php
declare(strict_types=1);

use Nivora\Infrastructure\Database\MigrationRunner;

require_once __DIR__ . '/../app/src/Infrastructure/Database/MigrationRunner.php';

$root = realpath(__DIR__ . '/..');
$id = '20260814_1420_reserva_qr';
$failures = [];
$check = static function (bool $condition, string $name) use (&$failures): void {
    if (!$condition) {
        $failures[] = $name;
    }
};
$definitions = MigrationRunner::loadDefinitions($root . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'migrations');
$matches = array_values(array_filter($definitions, static fn(array $migration): bool => $migration['id'] === $id));
$check(count($matches) === 1, 'reserva_qr_migration_registered_once');

$migration = file_get_contents($root . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . $id . '.php');
$check(is_string($migration) && str_contains($migration, 'CREATE TABLE IF NOT EXISTS reserva_qr_sessoes'), 'sessions_represented');
$check(is_string($migration) && str_contains($migration, 'CREATE TABLE IF NOT EXISTS reserva_pre_bloqueios'), 'holds_represented');
$check(is_string($migration) && str_contains($migration, "'chegou','concluido'"), 'reservation_cycle_represented');
$check(is_string($migration) && !str_contains($migration, 'UPDATE reservas'), 'migration_has_no_business_data_update');
$check(is_string($migration) && !str_contains($migration, 'DROP TABLE'), 'no_drop_table');

$legacy = file_get_contents($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'reserva_qr_schema.php');
$check(is_string($legacy) && str_contains($legacy, $id), 'fallback_uses_migration_id');
$check(is_string($legacy) && str_contains($legacy, 'migration_esta_aplicada'), 'fallback_checks_state');
$check(is_string($legacy) && str_contains($legacy, 'CREATE TABLE IF NOT EXISTS reserva_qr_sessoes'), 'fallback_preserved');
$check(is_string($legacy) && str_contains($legacy, 'reserva_sincronizar_reservas_pagas($db)'), 'business_sync_preserved');

if ($failures !== []) {
    fwrite(STDERR, 'FALHA: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}
echo 'OK: migracao QR de reservas validada sem conexao; sincronizacao preservada.' . PHP_EOL;
