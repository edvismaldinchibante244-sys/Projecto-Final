<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/src/config/database.php';
require_once __DIR__ . '/../app/src/config/migration_state.php';
require_once __DIR__ . '/../app/src/config/presenca_online.php';

$options = getopt('', ['target:', 'json']);
$asJson = isset($options['json']);
$target = trim((string)($options['target'] ?? ''));
$checks = [];
$db = null;
$check = static function (bool $condition, string $name) use (&$checks): void {
    $checks[] = ['status' => $condition ? 'PASS' : 'FAIL', 'name' => $name];
    if (!$condition) {
        throw new RuntimeException($name);
    }
};

try {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $target)
        || preg_match('/(?:^|_)(test|testing|sandbox|staging)(?:_|$)/i', $target) !== 1) {
        throw new RuntimeException('Alvo de teste invalido.');
    }
    $db = (new Database())->getConnection();
    $actual = (string)$db->query('SELECT DATABASE()')->fetchColumn();
    $check($actual !== '' && hash_equals($actual, $target), 'exact_test_database');

    $tables = $db->query(
        'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name'
    )->fetchAll(PDO::FETCH_COLUMN);
    $check($tables === ['categorias', 'restaurantes', 'schema_migrations', 'usuarios'], 'expected_sandbox_tables');

    $id = '20260814_1225_usuario_presenca_online';
    $stmt = $db->prepare('SELECT COUNT(*) FROM schema_migrations WHERE id = ?');
    $stmt->execute([$id]);
    $check((int)$stmt->fetchColumn() === 1, 'migration_recorded_once');
    $check(migration_esta_aplicada($db, $id), 'fallback_detects_migration');

    $column = $db->query(
        "SELECT DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.columns "
        . "WHERE table_schema = DATABASE() AND table_name = 'usuarios' "
        . "AND column_name = 'ultimo_acesso' LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    $check(is_array($column), 'ultimo_acesso_created');
    $check(strtolower((string)$column['DATA_TYPE']) === 'datetime', 'ultimo_acesso_datetime');
    $check(strtoupper((string)$column['IS_NULLABLE']) === 'YES', 'ultimo_acesso_nullable');

    $db->beginTransaction();
    $insert = $db->prepare(
        'INSERT INTO usuarios (restaurante_id, nome, perfil, foto, super_admin) VALUES (?, ?, ?, NULL, 0)'
    );
    $insert->execute([999999, 'Utilizador Presenca Teste', 'GARCOM']);
    $userId = (int)$db->lastInsertId();
    $check($userId > 0, 'synthetic_user_inserted');

    $check(presenca_ping_usuario($db, $userId, 999999), 'presence_ping_updated');
    $lastAccess = $db->prepare('SELECT ultimo_acesso FROM usuarios WHERE id = ? AND restaurante_id = ?');
    $lastAccess->execute([$userId, 999999]);
    $check((string)$lastAccess->fetchColumn() !== '', 'last_access_recorded');

    $team = presenca_buscar_equipa_online($db, 999999, 12);
    $check((int)$team['online'] === 1, 'team_reports_user_online');
    $check((int)$team['total'] === 1, 'team_reports_single_user');

    $check(presenca_marcar_usuario_offline($db, $userId, 999999), 'presence_offline_updated');
    $teamOffline = presenca_buscar_equipa_online($db, 999999, 12);
    $check((int)$teamOffline['online'] === 0, 'team_reports_user_offline');

    $db->rollBack();
    $remaining = $db->query("SELECT COUNT(*) FROM usuarios WHERE nome = 'Utilizador Presenca Teste'")->fetchColumn();
    $check((int)$remaining === 0, 'synthetic_data_rolled_back');

    $payload = ['ok' => true, 'target' => $target, 'transaction_rolled_back' => true, 'checks' => $checks];
    echo $asJson
        ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
        : 'OK: presenca online validada no sandbox; dados sinteticos revertidos.' . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $payload = ['ok' => false, 'target' => $target, 'transaction_rolled_back' => true, 'error' => $e->getMessage(), 'checks' => $checks];
    if ($asJson) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        fwrite(STDERR, 'FALHA: ' . $e->getMessage() . PHP_EOL);
    }
    exit(1);
}
