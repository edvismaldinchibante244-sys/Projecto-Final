<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/src/config/database.php';
require_once __DIR__ . '/../app/src/config/migration_state.php';
require_once __DIR__ . '/../app/src/config/offline_idempotency.php';

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

    $id = '20260814_1400_offline_operations';
    $stmt = $db->prepare('SELECT COUNT(*) FROM schema_migrations WHERE id = ?');
    $stmt->execute([$id]);
    $check((int)$stmt->fetchColumn() === 1, 'migration_recorded_once');
    $check(migration_esta_aplicada($db, $id), 'fallback_detects_migration');

    offline_idempotency_prepare($db);
    foreach (['uq_offline_operation', 'idx_offline_operations_status'] as $index) {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() "
            . "AND table_name = 'offline_operations' AND index_name = ?"
        );
        $stmt->execute([$index]);
        $check((int)$stmt->fetchColumn() > 0, $index . '_exists');
    }
    $check((int)$db->query('SELECT COUNT(*) FROM offline_operations')->fetchColumn() === 0, 'offline_operations_contains_no_real_data');

    $payloadA = ['mesa_id' => 7, 'itens' => [['produto_id' => 10, 'quantidade' => 2]], '_csrf' => 'ignorado'];
    $payloadReordered = ['itens' => [['quantidade' => 2, 'produto_id' => 10]], 'mesa_id' => 7, '_offline_op_id' => 'ignorado'];
    $check(hash_equals(offline_idempotency_hash($payloadA), offline_idempotency_hash($payloadReordered)), 'payload_hash_is_canonical');

    $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] = 'sandbox-offline-operation-0001';
    $_POST = $payloadA;
    $db->beginTransaction();
    $first = offline_idempotency_begin($db, 999999, 999999, 'api/pedido_novo.php', $payloadA);
    $check(!empty($first['active']) && empty($first['replay']) && (int)($first['id'] ?? 0) > 0, 'first_operation_reserved');

    $processingRetry = offline_idempotency_begin($db, 999999, 999999, 'api/pedido_novo.php', $payloadReordered);
    $check(empty($processingRetry['replay']) && (int)$processingRetry['id'] === (int)$first['id'], 'processing_retry_reuses_operation');
    $stmt = $db->prepare('SELECT attempts FROM offline_operations WHERE id = ?');
    $stmt->execute([(int)$first['id']]);
    $check((int)$stmt->fetchColumn() === 2, 'processing_retry_increments_attempts');

    $expectedResponse = ['success' => true, 'pedido_id' => 123456];
    offline_idempotency_complete($db, $first, $expectedResponse, 201);
    $completedRetry = offline_idempotency_begin($db, 999999, 999999, 'api/pedido_novo.php', $payloadA);
    $check(!empty($completedRetry['replay']), 'completed_operation_replayed');
    $check(($completedRetry['response'] ?? null) === $expectedResponse && (int)($completedRetry['http_status'] ?? 0) === 201, 'stored_response_preserved');

    $differentPayloadRejected = false;
    try {
        offline_idempotency_begin($db, 999999, 999999, 'api/pedido_novo.php', ['mesa_id' => 8]);
    } catch (DomainException) {
        $differentPayloadRejected = true;
    }
    $check($differentPayloadRejected, 'same_key_with_different_payload_rejected');
    $check((int)$db->query('SELECT COUNT(*) FROM offline_operations')->fetchColumn() === 1, 'single_operation_preserved');

    $db->rollBack();
    $check((int)$db->query('SELECT COUNT(*) FROM offline_operations')->fetchColumn() === 0, 'synthetic_operation_rolled_back');

    $payload = ['ok' => true, 'target' => $target, 'transaction_rolled_back' => true, 'checks' => $checks];
    echo $asJson ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL : 'OK: idempotencia offline validada no sandbox.' . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $payload = ['ok' => false, 'target' => $target, 'transaction_rolled_back' => true, 'error' => $e->getMessage(), 'checks' => $checks];
    echo $asJson ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL : 'FALHA: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
