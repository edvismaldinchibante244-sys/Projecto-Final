<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/src/config/database.php';
require_once __DIR__ . '/../app/src/config/migration_state.php';
require_once __DIR__ . '/../app/src/config/audit.php';

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

    $expectedTables = [
        'audit_logs', 'blocked_ips', 'categorias', 'cliente_carrinho_itens', 'cliente_carrinhos', 'offline_operations', 'offline_sync_queue',
        'clientes', 'itens_pedido', 'pedido_qr_meta', 'plano_cobranca_logs', 'plano_downgrades', 'plano_funcionalidades',
        'plano_lifecycle_notificacoes', 'plano_logs', 'planos_disponiveis', 'restaurante_planos', 'restaurantes',
        'reserva_pre_bloqueios', 'reserva_qr_sessoes', 'reservas', 'schema_migrations', 'security_events', 'usuarios',
    ];
    $tables = $db->query(
        'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
    )->fetchAll(PDO::FETCH_COLUMN);
    sort($tables, SORT_STRING);
    sort($expectedTables, SORT_STRING);
    $check($tables === $expectedTables, 'expected_sandbox_tables');

    $id = '20260814_1255_audit_logs';
    $stmt = $db->prepare('SELECT COUNT(*) FROM schema_migrations WHERE id = ?');
    $stmt->execute([$id]);
    $check((int)$stmt->fetchColumn() === 1, 'migration_recorded_once');
    $check(migration_esta_aplicada($db, $id), 'fallback_detects_migration');

    foreach (['idx_audit_event', 'idx_audit_usuario', 'idx_audit_restaurante', 'idx_audit_created'] as $index) {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM information_schema.statistics "
            . "WHERE table_schema = DATABASE() AND table_name = 'audit_logs' AND index_name = ?"
        );
        $stmt->execute([$index]);
        $check((int)$stmt->fetchColumn() > 0, $index . '_exists');
    }

    $_SESSION['usuario_id'] = 999999;
    $_SESSION['restaurante_id'] = 999999;
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/sandbox/audit';
    $_SERVER['HTTP_USER_AGENT'] = 'NivoraMigrationTest/1.0';

    $db->beginTransaction();
    $event = 'migration_sandbox_audit_test';
    $check(audit_log($db, $event, ['sandbox' => true, 'secret' => false], 'WARN'), 'audit_event_inserted');
    $stmt = $db->prepare(
        'SELECT severity, usuario_id, restaurante_id, method, context_json FROM audit_logs WHERE event = ? ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$event]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $check(is_array($row), 'audit_event_readable');
    $check(($row['severity'] ?? '') === 'WARN', 'severity_preserved');
    $check((int)($row['usuario_id'] ?? 0) === 999999, 'user_context_preserved');
    $check((int)($row['restaurante_id'] ?? 0) === 999999, 'restaurant_context_preserved');
    $check(($row['method'] ?? '') === 'POST', 'request_method_preserved');
    $context = json_decode((string)($row['context_json'] ?? ''), true);
    $check(is_array($context) && ($context['sandbox'] ?? false) === true, 'json_context_preserved');

    $db->rollBack();
    $stmt = $db->prepare('SELECT COUNT(*) FROM audit_logs WHERE event = ?');
    $stmt->execute([$event]);
    $check((int)$stmt->fetchColumn() === 0, 'synthetic_event_rolled_back');

    $payload = ['ok' => true, 'target' => $target, 'transaction_rolled_back' => true, 'checks' => $checks];
    echo $asJson
        ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
        : 'OK: auditoria validada no sandbox; evento sintetico revertido.' . PHP_EOL;
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
