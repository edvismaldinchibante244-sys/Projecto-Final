<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/src/config/database.php';
require_once __DIR__ . '/../app/src/config/migration_state.php';

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

    $requiredTables = ['plano_cobranca_logs', 'plano_lifecycle_notificacoes'];
    foreach ($requiredTables as $table) {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_name = ? AND table_type = ?'
        );
        $stmt->execute([$table, 'BASE TABLE']);
        $check((int)$stmt->fetchColumn() === 1, $table . '_exists');
    }

    $id = '20260814_1345_plano_lifecycle';
    $stmt = $db->prepare('SELECT COUNT(*) FROM schema_migrations WHERE id = ?');
    $stmt->execute([$id]);
    $check((int)$stmt->fetchColumn() === 1, 'migration_recorded_once');
    $check(migration_esta_aplicada($db, $id), 'fallback_detects_migration');

    foreach (['idx_pcl_restaurante', 'idx_pcl_evento', 'idx_pcl_created'] as $index) {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() "
            . "AND table_name = 'plano_cobranca_logs' AND index_name = ?"
        );
        $stmt->execute([$index]);
        $check((int)$stmt->fetchColumn() > 0, $index . '_exists');
    }
    foreach (['uniq_lifecycle_notif', 'idx_lifecycle_created'] as $index) {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() "
            . "AND table_name = 'plano_lifecycle_notificacoes' AND index_name = ?"
        );
        $stmt->execute([$index]);
        $check((int)$stmt->fetchColumn() > 0, $index . '_exists');
    }

    $check((int)$db->query('SELECT COUNT(*) FROM plano_cobranca_logs')->fetchColumn() === 0, 'billing_log_contains_no_real_data');
    $check((int)$db->query('SELECT COUNT(*) FROM plano_lifecycle_notificacoes')->fetchColumn() === 0, 'notifications_contain_no_real_data');

    $db->beginTransaction();
    $details = json_encode(['sandbox' => true, 'dias_restantes' => 7], JSON_UNESCAPED_SLASHES);
    $stmt = $db->prepare("INSERT INTO plano_cobranca_logs (restaurante_id, evento, severidade, detalhes_json) VALUES (?, 'notificacao_renovacao', 'INFO', ?)");
    $stmt->execute([999999, $details]);
    $check((int)$db->lastInsertId() > 0, 'synthetic_billing_log_inserted');
    $row = $db->query('SELECT evento, severidade, detalhes_json FROM plano_cobranca_logs ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $decoded = json_decode((string)($row['detalhes_json'] ?? ''), true);
    $check(($row['evento'] ?? '') === 'notificacao_renovacao' && ($row['severidade'] ?? '') === 'INFO', 'billing_log_fields_preserved');
    $check(is_array($decoded) && ($decoded['sandbox'] ?? false) === true, 'billing_log_json_preserved');

    $stmt = $db->prepare("INSERT INTO plano_lifecycle_notificacoes (restaurante_id, data_fim, dias_restantes, canal, destinatario) VALUES (?, DATE_ADD(CURDATE(), INTERVAL 7 DAY), 7, 'email', ?)");
    $stmt->execute([999999, 'sandbox@example.invalid']);
    $check((int)$db->lastInsertId() > 0, 'synthetic_notification_inserted');
    $duplicateRejected = false;
    try {
        $stmt->execute([999999, 'outro@example.invalid']);
    } catch (PDOException $e) {
        $duplicateRejected = (string)$e->getCode() === '23000';
    }
    $check($duplicateRejected, 'duplicate_notification_rejected');
    $check((int)$db->query('SELECT COUNT(*) FROM plano_lifecycle_notificacoes')->fetchColumn() === 1, 'single_notification_preserved');

    $db->rollBack();
    $check((int)$db->query('SELECT COUNT(*) FROM plano_cobranca_logs')->fetchColumn() === 0, 'synthetic_billing_log_rolled_back');
    $check((int)$db->query('SELECT COUNT(*) FROM plano_lifecycle_notificacoes')->fetchColumn() === 0, 'synthetic_notification_rolled_back');

    $payload = ['ok' => true, 'target' => $target, 'transaction_rolled_back' => true, 'checks' => $checks];
    echo $asJson ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL : 'OK: lifecycle validado no sandbox.' . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $payload = ['ok' => false, 'target' => $target, 'transaction_rolled_back' => true, 'error' => $e->getMessage(), 'checks' => $checks];
    echo $asJson ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL : 'FALHA: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
