<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/src/config/database.php';
require_once __DIR__ . '/../app/src/config/migration_state.php';

$options = getopt('', ['target:', 'json']);
$asJson = isset($options['json']);
$target = trim((string)($options['target'] ?? ''));
$checks = [];
$db = null;

$check = static function (bool $condition, string $name, string $detail = '') use (&$checks): void {
    $checks[] = [
        'status' => $condition ? 'PASS' : 'FAIL',
        'name' => $name,
        'detail' => $detail,
    ];
    if (!$condition) {
        throw new RuntimeException($name . ($detail !== '' ? ': ' . $detail : ''));
    }
};

try {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $target)) {
        throw new RuntimeException('Informe um --target valido.');
    }
    if (preg_match('/(?:^|_)(test|testing|sandbox|staging)(?:_|$)/i', $target) !== 1) {
        throw new RuntimeException('O teste integrado aceita apenas banco explicitamente isolado.');
    }

    $db = (new Database())->getConnection();
    if (!$db instanceof PDO) {
        throw new RuntimeException('Ligacao PDO indisponivel.');
    }
    $actual = (string)$db->query('SELECT DATABASE()')->fetchColumn();
    $check($actual !== '' && hash_equals($actual, $target), 'exact_test_database', 'Banco conectado corresponde ao alvo isolado.');

    $tables = $db->query(
        'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name'
    )->fetchAll(PDO::FETCH_COLUMN);
    $check($tables === ['categorias', 'schema_migrations'], 'sandbox_contains_only_expected_tables');

    $migrationId = '20260814_1145_categoria_hierarquia';
    $stmt = $db->prepare('SELECT COUNT(*) FROM schema_migrations WHERE id = ?');
    $stmt->execute([$migrationId]);
    $check((int)$stmt->fetchColumn() === 1, 'migration_recorded_once', $migrationId);
    $check(migration_esta_aplicada($db, $migrationId), 'legacy_fallback_detects_applied_migration');

    $columns = $db->query(
        "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT "
        . "FROM information_schema.columns WHERE table_schema = DATABASE() "
        . "AND table_name = 'categorias' AND COLUMN_NAME IN ('parent_id','ativo')"
    )->fetchAll(PDO::FETCH_ASSOC);
    $byName = [];
    foreach ($columns as $column) {
        $byName[(string)$column['COLUMN_NAME']] = $column;
    }
    $check(isset($byName['parent_id']), 'parent_id_created');
    $check(isset($byName['ativo']), 'ativo_created');
    $check(strtoupper((string)$byName['parent_id']['IS_NULLABLE']) === 'YES', 'parent_id_nullable');
    $check(strtolower((string)$byName['ativo']['DATA_TYPE']) === 'tinyint', 'ativo_tinyint');
    $check(strtoupper((string)$byName['ativo']['IS_NULLABLE']) === 'NO', 'ativo_not_null');
    $check((string)$byName['ativo']['COLUMN_DEFAULT'] === '1', 'ativo_default_one');

    $db->beginTransaction();
    $rootInsert = $db->prepare('INSERT INTO categorias (restaurante_id, parent_id, nome, descricao, ativo) VALUES (?, NULL, ?, ?, 1)');
    $rootInsert->execute([999999, 'Categoria Teste', 'Criada apenas dentro da transacao']);
    $rootId = (int)$db->lastInsertId();
    $check($rootId > 0, 'root_category_inserted');

    $childInsert = $db->prepare('INSERT INTO categorias (restaurante_id, parent_id, nome, descricao, ativo) VALUES (?, ?, ?, ?, 1)');
    $childInsert->execute([999999, $rootId, 'Subcategoria Teste', 'Criada apenas dentro da transacao']);
    $childId = (int)$db->lastInsertId();
    $check($childId > 0, 'subcategory_inserted');

    $hierarchy = $db->prepare(
        'SELECT c.id, p.id AS parent_found FROM categorias c '
        . 'LEFT JOIN categorias p ON p.id = c.parent_id AND p.restaurante_id = c.restaurante_id '
        . 'WHERE c.id = ? AND c.restaurante_id = ?'
    );
    $hierarchy->execute([$childId, 999999]);
    $row = $hierarchy->fetch(PDO::FETCH_ASSOC);
    $check(is_array($row) && (int)$row['parent_found'] === $rootId, 'category_hierarchy_readable');

    $disable = $db->prepare('UPDATE categorias SET ativo = 0 WHERE id = ? AND restaurante_id = ?');
    $disable->execute([$childId, 999999]);
    $check($disable->rowCount() === 1, 'category_deactivation');

    $db->rollBack();
    $remaining = $db->query('SELECT COUNT(*) FROM categorias WHERE restaurante_id = 999999')->fetchColumn();
    $check((int)$remaining === 0, 'synthetic_data_rolled_back');

    $payload = ['ok' => true, 'target' => $target, 'transaction_rolled_back' => true, 'checks' => $checks];
    echo $asJson
        ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
        : 'OK: migracao de categorias validada no sandbox; dados sinteticos revertidos.' . PHP_EOL;
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
