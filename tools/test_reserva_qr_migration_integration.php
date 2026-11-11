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

    $id = '20260814_1420_reserva_qr';
    $stmt = $db->prepare('SELECT COUNT(*) FROM schema_migrations WHERE id = ?');
    $stmt->execute([$id]);
    $check((int)$stmt->fetchColumn() === 1, 'migration_recorded_once');
    $check(migration_esta_aplicada($db, $id), 'fallback_detects_migration');

    $status = $db->query("SHOW COLUMNS FROM reservas LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    $type = strtolower((string)($status['Type'] ?? ''));
    $check(str_contains($type, "'chegou'") && str_contains($type, "'concluido'"), 'reservation_status_cycle_complete');

    $indexes = [
        'reserva_qr_sessoes' => ['idx_restaurante_expires', 'idx_token_expires'],
        'reserva_pre_bloqueios' => ['idx_restaurante_data_hora', 'idx_token_sessao', 'idx_expires_at'],
    ];
    foreach ($indexes as $table => $names) {
        foreach ($names as $index) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
            $stmt->execute([$table, $index]);
            $check((int)$stmt->fetchColumn() > 0, $index . '_exists');
        }
    }
    foreach (['reservas', 'reserva_qr_sessoes', 'reserva_pre_bloqueios'] as $table) {
        $check((int)$db->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() === 0, $table . '_contains_no_real_data');
    }

    $db->beginTransaction();
    $token = 'sandbox-reserva-qr-token-000000000001';
    $stmt = $db->prepare("INSERT INTO reserva_qr_sessoes (restaurante_id, mesa_id, token, nome_atendente, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))");
    $stmt->execute([999999, 77, $token, 'Atendente Sandbox']);
    $check((int)$db->lastInsertId() > 0, 'synthetic_qr_session_inserted');

    $duplicateRejected = false;
    try {
        $stmt->execute([999999, 78, $token, 'Outro Sandbox']);
    } catch (PDOException $e) {
        $duplicateRejected = (string)$e->getCode() === '23000';
    }
    $check($duplicateRejected, 'duplicate_qr_token_rejected');

    $stmt = $db->prepare("INSERT INTO reserva_pre_bloqueios (restaurante_id, mesa_id, token_sessao, data_reserva, hora_reserva, quantidade_pessoas, expires_at) VALUES (?, ?, ?, CURDATE(), '19:00:00', 4, DATE_ADD(NOW(), INTERVAL 15 MINUTE))");
    $stmt->execute([999999, 77, $token]);
    $check((int)$db->lastInsertId() > 0, 'synthetic_hold_inserted');
    $db->prepare('UPDATE reserva_qr_sessoes SET consumed_at = NOW() WHERE token = ?')->execute([$token]);
    $stmt = $db->prepare('SELECT COUNT(*) FROM reserva_qr_sessoes WHERE token = ? AND consumed_at IS NOT NULL');
    $stmt->execute([$token]);
    $check((int)$stmt->fetchColumn() === 1, 'qr_session_consumed');

    $db->rollBack();
    $check((int)$db->query('SELECT COUNT(*) FROM reserva_qr_sessoes')->fetchColumn() === 0, 'synthetic_session_rolled_back');
    $check((int)$db->query('SELECT COUNT(*) FROM reserva_pre_bloqueios')->fetchColumn() === 0, 'synthetic_hold_rolled_back');

    $payload = ['ok' => true, 'target' => $target, 'transaction_rolled_back' => true, 'checks' => $checks];
    echo $asJson ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL : 'OK: esquema QR de reservas validado no sandbox.' . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $payload = ['ok' => false, 'target' => $target, 'transaction_rolled_back' => true, 'error' => $e->getMessage(), 'checks' => $checks];
    echo $asJson ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL : 'FALHA: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
