<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/src/config/database.php';
require_once __DIR__ . '/../app/src/config/migration_state.php';
require_once __DIR__ . '/../app/src/config/security_monitor.php';

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

    $id = '20260814_1440_security_monitor';
    $stmt = $db->prepare('SELECT COUNT(*) FROM schema_migrations WHERE id = ?');
    $stmt->execute([$id]);
    $check((int)$stmt->fetchColumn() === 1, 'migration_recorded_once');
    $check(migration_esta_aplicada($db, $id), 'fallback_detects_migration');
    security_monitor_ensure_tables($db);

    $indexes = [
        'blocked_ips' => ['idx_blocked_ip', 'idx_blocked_ativo', 'idx_blocked_ate'],
        'security_events' => ['idx_security_events_created', 'idx_security_events_type', 'idx_security_events_ip'],
        'offline_sync_queue' => ['uq_offline_op_id', 'idx_offline_status', 'idx_offline_restaurante', 'idx_offline_updated'],
    ];
    foreach ($indexes as $table => $names) {
        foreach ($names as $index) {
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.statistics '
                . 'WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
            );
            $stmt->execute([$table, $index]);
            $check((int)$stmt->fetchColumn() > 0, $table . '_' . $index . '_exists');
        }
    }
    foreach (array_keys($indexes) as $table) {
        $check((int)$db->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() === 0, $table . '_contains_no_real_data');
    }

    $check(security_monitor_detect_attack_type(['event' => 'login_failed']) === 'BRUTE_FORCE', 'brute_force_classification_preserved');
    $check(security_monitor_severity_for_type('SQL_INJECTION') === 'CRITICAL', 'critical_severity_preserved');

    $db->beginTransaction();
    $db->prepare(
        "INSERT INTO blocked_ips (ip, motivo, origem, bloqueio_tipo) VALUES (?, ?, 'sandbox', 'TEMPORARIO')"
    )->execute(['192.0.2.44', 'Teste sintetico']);
    $check((int)$db->lastInsertId() > 0, 'synthetic_block_inserted');

    $db->prepare(
        "INSERT INTO security_events (event_name, attack_type, severity, ip, endpoint, method, context_json) "
        . "VALUES ('sandbox_attack', 'SQL_INJECTION', 'CRITICAL', ?, '/sandbox', 'POST', '{}')"
    )->execute(['192.0.2.44']);
    $check((int)$db->lastInsertId() > 0, 'synthetic_security_event_inserted');

    $queue = $db->prepare(
        "INSERT INTO offline_sync_queue (op_id, restaurante_id, endpoint, payload_json) VALUES (?, ?, ?, '{}')"
    );
    $queue->execute(['sandbox-security-op-0001', 999999, '/api/sandbox']);
    $check((int)$db->lastInsertId() > 0, 'synthetic_offline_queue_inserted');
    $duplicateRejected = false;
    try {
        $queue->execute(['sandbox-security-op-0001', 999999, '/api/sandbox']);
    } catch (PDOException $e) {
        $duplicateRejected = (string)$e->getCode() === '23000';
    }
    $check($duplicateRejected, 'duplicate_offline_operation_rejected');

    $db->rollBack();
    foreach (array_keys($indexes) as $table) {
        $check((int)$db->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() === 0, $table . '_synthetic_data_rolled_back');
    }

    $payload = ['ok' => true, 'target' => $target, 'transaction_rolled_back' => true, 'checks' => $checks];
    echo $asJson
        ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
        : 'OK: monitor de seguranca validado no sandbox; dados sinteticos revertidos.' . PHP_EOL;
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
