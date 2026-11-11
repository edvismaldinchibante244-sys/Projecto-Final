<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/src/config/database.php';
require_once __DIR__ . '/../app/src/config/migration_state.php';
require_once __DIR__ . '/../app/src/config/plan_security.php';

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

    $id = PLAN_PURCHASE_HARDENING_MIGRATION_ID;
    $stmt = $db->prepare('SELECT COUNT(*) FROM schema_migrations WHERE id = ?');
    $stmt->execute([$id]);
    $check((int)$stmt->fetchColumn() === 1, 'migration_recorded_once');
    $check(migration_esta_aplicada($db, $id), 'fallback_detects_migration');
    plan_security_ensure_purchase_hardening_columns($db);

    $hash = $db->query(
        "SELECT data_type, character_maximum_length, is_nullable FROM information_schema.columns "
        . "WHERE table_schema = DATABASE() AND table_name = 'compras_planos' "
        . "AND column_name = 'hash_arquivo' LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    $check(is_array($hash), 'hash_column_created');
    $check(strtolower((string)$hash['data_type']) === 'char', 'hash_column_char');
    $check((int)$hash['character_maximum_length'] === 64, 'hash_column_length_64');
    $check(strtoupper((string)$hash['is_nullable']) === 'YES', 'hash_column_nullable');

    $stmt = $db->query(
        "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() "
        . "AND table_name = 'compras_planos' AND index_name = 'idx_compras_hash_arquivo'"
    );
    $check((int)$stmt->fetchColumn() === 1, 'hash_index_exists_once');
    $type = (string)$db->query(
        "SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() "
        . "AND table_name = 'compras_planos' AND column_name = 'metodo_pagamento' LIMIT 1"
    )->fetchColumn();
    $check(stripos($type, "'trial'") !== false, 'trial_method_available');
    $check((int)$db->query('SELECT COUNT(*) FROM compras_planos')->fetchColumn() === 0, 'contains_no_real_data');

    $db->beginTransaction();
    $hashValue = hash('sha256', 'comprovativo-sintetico-sandbox');
    $stmt = $db->prepare(
        'INSERT INTO compras_planos (comprovativo_path, hash_arquivo, metodo_pagamento) VALUES (?, ?, ?)'
    );
    $stmt->execute(['sandbox/comprovativo.pdf', $hashValue, 'TRIAL']);
    $idInserted = (int)$db->lastInsertId();
    $check($idInserted > 0, 'synthetic_trial_purchase_inserted');
    $stmt = $db->prepare('SELECT hash_arquivo FROM compras_planos WHERE id = ?');
    $stmt->execute([$idInserted]);
    $check(hash_equals($hashValue, (string)$stmt->fetchColumn()), 'synthetic_hash_preserved');
    $db->rollBack();
    $check((int)$db->query('SELECT COUNT(*) FROM compras_planos')->fetchColumn() === 0, 'synthetic_purchase_rolled_back');

    $payload = ['ok' => true, 'target' => $target, 'transaction_rolled_back' => true, 'checks' => $checks];
    echo $asJson
        ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
        : 'OK: seguranca das compras validada no sandbox; dados sinteticos revertidos.' . PHP_EOL;
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
