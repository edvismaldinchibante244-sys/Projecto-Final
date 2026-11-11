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

    $id = '20260814_1310_plano_downgrades';
    $stmt = $db->prepare('SELECT COUNT(*) FROM schema_migrations WHERE id = ?');
    $stmt->execute([$id]);
    $check((int)$stmt->fetchColumn() === 1, 'migration_recorded_once');
    $check(migration_esta_aplicada($db, $id), 'fallback_detects_migration');

    foreach (['idx_downgrade_fila', 'idx_downgrade_restaurante'] as $index) {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM information_schema.statistics "
            . "WHERE table_schema = DATABASE() AND table_name = 'plano_downgrades' AND index_name = ?"
        );
        $stmt->execute([$index]);
        $check((int)$stmt->fetchColumn() > 0, $index . '_exists');
    }

    $db->beginTransaction();
    $marker = 'migration-sandbox-downgrade';
    $stmt = $db->prepare(
        "INSERT INTO plano_downgrades "
        . "(restaurante_id, plano_origem, plano_destino, aplicar_em, status, limites_json, criado_por) "
        . "VALUES (?, 'EMPRESARIAL', 'PROFISSIONAL', CURDATE(), 'AGENDADO', ?, ?)"
    );
    $stmt->execute([999999, json_encode(['sandbox' => true, 'marker' => $marker]), 999999]);
    $syntheticId = (int)$db->lastInsertId();
    $check($syntheticId > 0, 'synthetic_downgrade_inserted');

    $stmt = $db->prepare('SELECT status, plano_origem, plano_destino, limites_json FROM plano_downgrades WHERE id = ?');
    $stmt->execute([$syntheticId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $check(is_array($row) && ($row['status'] ?? '') === 'AGENDADO', 'scheduled_status_preserved');
    $check(($row['plano_origem'] ?? '') === 'EMPRESARIAL' && ($row['plano_destino'] ?? '') === 'PROFISSIONAL', 'plan_transition_preserved');
    $limits = json_decode((string)($row['limites_json'] ?? ''), true);
    $check(is_array($limits) && ($limits['marker'] ?? '') === $marker, 'limits_payload_preserved');

    $stmt = $db->prepare("UPDATE plano_downgrades SET status = 'CANCELADO', cancelado_em = NOW() WHERE id = ? AND status = 'AGENDADO'");
    $stmt->execute([$syntheticId]);
    $check($stmt->rowCount() === 1, 'scheduled_downgrade_cancelled_once');
    $stmt = $db->prepare('SELECT status, cancelado_em FROM plano_downgrades WHERE id = ?');
    $stmt->execute([$syntheticId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $check(is_array($row) && ($row['status'] ?? '') === 'CANCELADO' && !empty($row['cancelado_em']), 'cancellation_persisted_in_transaction');

    $db->rollBack();
    $stmt = $db->prepare('SELECT COUNT(*) FROM plano_downgrades WHERE id = ?');
    $stmt->execute([$syntheticId]);
    $check((int)$stmt->fetchColumn() === 0, 'synthetic_downgrade_rolled_back');

    $payload = ['ok' => true, 'target' => $target, 'transaction_rolled_back' => true, 'checks' => $checks];
    echo $asJson
        ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
        : 'OK: downgrade validado no sandbox; registo sintetico revertido.' . PHP_EOL;
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
