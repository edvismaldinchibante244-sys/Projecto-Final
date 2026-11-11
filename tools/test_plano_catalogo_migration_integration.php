<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/src/config/database.php';
require_once __DIR__ . '/../app/src/config/migration_state.php';

$options = getopt('', ['target:', 'json']);
$asJson = isset($options['json']);
$target = trim((string)($options['target'] ?? ''));
$checks = [];
$db = null;
$testViewCreated = false;
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
        'audit_logs', 'blocked_ips', 'categorias', 'cliente_carrinho_itens', 'cliente_carrinhos', 'clientes', 'offline_operations', 'offline_sync_queue',
        'itens_pedido', 'pedido_qr_meta', 'plano_cobranca_logs', 'plano_downgrades', 'plano_funcionalidades', 'plano_lifecycle_notificacoes',
        'plano_logs', 'planos_disponiveis', 'reserva_pre_bloqueios', 'reserva_qr_sessoes',
        'reservas', 'restaurante_planos', 'restaurantes', 'schema_migrations', 'security_events', 'usuarios',
    ];
    $tables = $db->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
    sort($tables, SORT_STRING);
    sort($expectedTables, SORT_STRING);
    $check($tables === $expectedTables, 'expected_sandbox_tables');

    $id = '20260814_1330_plano_catalogo';
    $stmt = $db->prepare('SELECT COUNT(*) FROM schema_migrations WHERE id = ?');
    $stmt->execute([$id]);
    $check((int)$stmt->fetchColumn() === 1, 'migration_recorded_once');
    $check(migration_esta_aplicada($db, $id), 'fallback_detects_migration');

    $db->exec('CREATE VIEW v_plano_usage_resumo AS SELECT 1 AS sandbox_marker');
    $testViewCreated = true;
    require_once __DIR__ . '/../app/src/config/plano_check.php';
    $stmt = $db->query(
        "SELECT COUNT(*) FROM information_schema.views "
        . "WHERE table_schema = DATABASE() AND table_name = 'v_plano_usage_resumo'"
    );
    $check((int)$stmt->fetchColumn() === 1, 'migrated_runtime_does_not_drop_legacy_view');
    $db->exec('DROP VIEW v_plano_usage_resumo');
    $testViewCreated = false;

    $planos = require __DIR__ . '/../app/src/config/planos.php';
    $stmt = $db->query("SELECT nome, id FROM planos_disponiveis WHERE nome IN ('BASICO','PROFISSIONAL','EMPRESARIAL')");
    $planIds = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $check(count($planIds) === 3, 'three_default_plans_present');

    $expectedFeatures = 0;
    foreach ($planos as $planName => $config) {
        $features = is_array($config['funcionalidades'] ?? null) ? $config['funcionalidades'] : [];
        $expectedFeatures += count($features);
        $planId = (int)($planIds[$planName] ?? 0);
        $check($planId > 0, strtolower($planName) . '_catalogued');
        $stmt = $db->prepare('SELECT funcionalidade, permitido FROM plano_funcionalidades WHERE plano_id = ?');
        $stmt->execute([$planId]);
        $stored = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($features as $feature => $allowed) {
            $check(array_key_exists($feature, $stored) && (int)$stored[$feature] === ($allowed ? 1 : 0), strtolower($planName) . '_' . $feature);
        }
    }
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM plano_funcionalidades pf JOIN planos_disponiveis pd ON pd.id = pf.plano_id "
        . "WHERE pd.nome IN ('BASICO','PROFISSIONAL','EMPRESARIAL')"
    );
    $stmt->execute();
    $check((int)$stmt->fetchColumn() === $expectedFeatures, 'feature_catalog_has_no_duplicates');

    foreach (['restaurantes', 'usuarios', 'clientes', 'restaurante_planos', 'plano_logs'] as $table) {
        $check((int)$db->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() === 0, $table . '_contains_no_real_data');
    }

    $db->beginTransaction();
    $db->exec("INSERT INTO restaurantes (nome) VALUES ('Restaurante Sandbox Planos')");
    $restaurantId = (int)$db->lastInsertId();
    $basicId = (int)$planIds['BASICO'];
    $stmt = $db->prepare("INSERT INTO restaurante_planos (restaurante_id, plano_id, data_inicio, data_fim, status) VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'ATIVO')");
    $stmt->execute([$restaurantId, $basicId]);
    $db->prepare("INSERT INTO plano_logs (restaurante_id, funcionalidade, recurso, acao) VALUES (?, 'pdv', 'sandbox', 'TESTE')")->execute([$restaurantId]);
    $check((int)$db->query('SELECT COUNT(*) FROM restaurante_planos')->fetchColumn() === 1, 'synthetic_subscription_inserted');
    $check((int)$db->query('SELECT COUNT(*) FROM plano_logs')->fetchColumn() === 1, 'synthetic_usage_log_inserted');
    $db->prepare('DELETE FROM restaurantes WHERE id = ?')->execute([$restaurantId]);
    $check((int)$db->query('SELECT COUNT(*) FROM restaurante_planos')->fetchColumn() === 0, 'subscription_cascade_verified');
    $check((int)$db->query('SELECT COUNT(*) FROM plano_logs')->fetchColumn() === 0, 'usage_log_cascade_verified');
    $db->rollBack();
    $check((int)$db->query('SELECT COUNT(*) FROM restaurantes')->fetchColumn() === 0, 'synthetic_restaurant_rolled_back');

    $payload = ['ok' => true, 'target' => $target, 'expected_features' => $expectedFeatures, 'transaction_rolled_back' => true, 'checks' => $checks];
    echo $asJson ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL : 'OK: catalogo de planos validado no sandbox.' . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    if ($testViewCreated && $db instanceof PDO) {
        try {
            $db->exec('DROP VIEW IF EXISTS v_plano_usage_resumo');
        } catch (Throwable) {
        }
    }
    $payload = ['ok' => false, 'target' => $target, 'transaction_rolled_back' => true, 'error' => $e->getMessage(), 'checks' => $checks];
    echo $asJson ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL : 'FALHA: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
