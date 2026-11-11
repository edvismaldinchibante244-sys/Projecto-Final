<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/src/config/database.php';
require_once __DIR__ . '/../app/src/config/migration_state.php';
require_once __DIR__ . '/../app/src/api/reserva_publica_handler.php';

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

    $id = '20260815_0900_pedido_qr_meta';
    $stmt = $db->prepare('SELECT COUNT(*) FROM schema_migrations WHERE id = ?');
    $stmt->execute([$id]);
    $check((int)$stmt->fetchColumn() === 1, 'migration_recorded_once');
    $check(migration_esta_aplicada($db, $id), 'fallback_detects_migration');
    reserva_publica_qr_pedido_schema($db);

    $column = $db->query(
        "SELECT data_type, character_maximum_length, is_nullable FROM information_schema.columns "
        . "WHERE table_schema = DATABASE() AND table_name = 'itens_pedido' "
        . "AND column_name = 'observacao' LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    $check(is_array($column), 'item_observation_created');
    $check(strtolower((string)$column['data_type']) === 'varchar', 'item_observation_varchar');
    $check((int)$column['character_maximum_length'] === 255, 'item_observation_length_255');
    $check(strtoupper((string)$column['is_nullable']) === 'YES', 'item_observation_nullable');

    foreach (['uq_qr_idem', 'idx_qr_token_created', 'idx_qr_pedido'] as $index) {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics '
            . "WHERE table_schema = DATABASE() AND table_name = 'pedido_qr_meta' AND index_name = ?"
        );
        $stmt->execute([$index]);
        $check((int)$stmt->fetchColumn() > 0, $index . '_exists');
    }
    foreach (['itens_pedido', 'pedido_qr_meta'] as $table) {
        $check((int)$db->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() === 0, $table . '_contains_no_real_data');
    }

    $db->beginTransaction();
    $db->prepare('INSERT INTO itens_pedido (subtotal, observacao) VALUES (?, ?)')
        ->execute([125.50, 'Sem cebola']);
    $itemId = (int)$db->lastInsertId();
    $check($itemId > 0, 'synthetic_item_inserted');
    $stmt = $db->prepare('SELECT observacao FROM itens_pedido WHERE id = ?');
    $stmt->execute([$itemId]);
    $check((string)$stmt->fetchColumn() === 'Sem cebola', 'item_observation_preserved');

    $meta = $db->prepare(
        'INSERT INTO pedido_qr_meta '
        . '(pedido_id, restaurante_id, token, idem_key, cliente_nome, cliente_telefone) '
        . 'VALUES (?, ?, ?, ?, ?, ?)'
    );
    $meta->execute([900001, 999999, 'sandbox-token-qr-001', 'sandbox-idem-001', 'Cliente Sandbox', '+258000000000']);
    $check((int)$db->lastInsertId() > 0, 'synthetic_qr_metadata_inserted');

    $duplicateRejected = false;
    try {
        $meta->execute([900002, 999999, 'sandbox-token-qr-001', 'sandbox-idem-001', 'Outro Cliente', null]);
    } catch (PDOException $e) {
        $duplicateRejected = (string)$e->getCode() === '23000';
    }
    $check($duplicateRejected, 'duplicate_qr_idempotency_rejected');

    $db->rollBack();
    $check((int)$db->query('SELECT COUNT(*) FROM itens_pedido')->fetchColumn() === 0, 'synthetic_item_rolled_back');
    $check((int)$db->query('SELECT COUNT(*) FROM pedido_qr_meta')->fetchColumn() === 0, 'synthetic_metadata_rolled_back');

    $payload = ['ok' => true, 'target' => $target, 'transaction_rolled_back' => true, 'checks' => $checks];
    echo $asJson
        ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
        : 'OK: pedido QR validado no sandbox; dados sinteticos revertidos.' . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $payload = ['ok' => false, 'target' => $target, 'transaction_rolled_back' => true, 'error' => $e->getMessage(), 'checks' => $checks];
    echo $asJson
        ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
        : 'FALHA: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
