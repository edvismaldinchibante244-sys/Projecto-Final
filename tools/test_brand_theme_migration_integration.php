<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/src/config/database.php';
require_once __DIR__ . '/../app/src/config/migration_state.php';
require_once __DIR__ . '/../app/src/config/brand_theme_helper.php';

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
    $check($tables === ['categorias', 'restaurantes', 'schema_migrations'], 'expected_sandbox_tables');

    $id = '20260814_1210_restaurante_brand_theme';
    $stmt = $db->prepare('SELECT COUNT(*) FROM schema_migrations WHERE id = ?');
    $stmt->execute([$id]);
    $check((int)$stmt->fetchColumn() === 1, 'migration_recorded_once');
    $check(migration_esta_aplicada($db, $id), 'fallback_detects_migration');

    $columns = $db->query(
        "SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE "
        . "FROM information_schema.columns WHERE table_schema = DATABASE() "
        . "AND table_name = 'restaurantes' AND COLUMN_NAME IN ('cor_primaria','cor_secundaria')"
    )->fetchAll(PDO::FETCH_ASSOC);
    $byName = [];
    foreach ($columns as $column) {
        $byName[(string)$column['COLUMN_NAME']] = $column;
    }
    foreach (['cor_primaria', 'cor_secundaria'] as $columnName) {
        $check(isset($byName[$columnName]), $columnName . '_created');
        $check(strtolower((string)$byName[$columnName]['DATA_TYPE']) === 'varchar', $columnName . '_varchar');
        $check((int)$byName[$columnName]['CHARACTER_MAXIMUM_LENGTH'] === 7, $columnName . '_length_7');
        $check(strtoupper((string)$byName[$columnName]['IS_NULLABLE']) === 'YES', $columnName . '_nullable');
    }

    $db->beginTransaction();
    $insert = $db->prepare(
        'INSERT INTO restaurantes (nome, logo, cor_primaria, cor_secundaria) VALUES (?, NULL, ?, ?)'
    );
    $insert->execute(['Restaurante Tema Teste', '#123456', '#ABCDEF']);
    $restaurantId = (int)$db->lastInsertId();
    $check($restaurantId > 0, 'restaurant_inserted');

    $theme = brand_theme_for_restaurante_id($db, $restaurantId);
    $check(($theme['primary'] ?? '') === '#123456', 'primary_color_resolved');
    $check(($theme['accent'] ?? '') === '#ABCDEF', 'secondary_color_resolved');
    $check(($theme['source'] ?? '') === 'db', 'theme_source_database');

    $db->rollBack();
    $remaining = $db->query("SELECT COUNT(*) FROM restaurantes WHERE nome = 'Restaurante Tema Teste'")->fetchColumn();
    $check((int)$remaining === 0, 'synthetic_data_rolled_back');

    $payload = ['ok' => true, 'target' => $target, 'transaction_rolled_back' => true, 'checks' => $checks];
    echo $asJson
        ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
        : 'OK: tema/marca validado no sandbox; dados sinteticos revertidos.' . PHP_EOL;
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
