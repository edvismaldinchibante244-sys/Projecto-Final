<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/src/config/database.php';
require_once __DIR__ . '/../app/src/config/migration_state.php';
require_once __DIR__ . '/../app/src/config/password_reset_helper.php';

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

    $id = PASSWORD_RESET_SCHEMA_MIGRATION_ID;
    $stmt = $db->prepare('SELECT COUNT(*) FROM schema_migrations WHERE id = ?');
    $stmt->execute([$id]);
    $check((int)$stmt->fetchColumn() === 1, 'migration_recorded_once');
    $check(migration_esta_aplicada($db, $id), 'fallback_detects_migration');
    password_reset_ensure_table($db);

    foreach (['idx_token', 'idx_email_lookup', 'idx_user_id'] as $index) {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics '
            . "WHERE table_schema = DATABASE() AND table_name = 'password_resets' AND index_name = ?"
        );
        $stmt->execute([$index]);
        $check((int)$stmt->fetchColumn() === 1, $index . '_exists_once');
    }
    $lookup = $db->query(
        "SELECT data_type, character_maximum_length FROM information_schema.columns "
        . "WHERE table_schema = DATABASE() AND table_name = 'password_resets' "
        . "AND column_name = 'email_lookup' LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    $check(is_array($lookup), 'email_lookup_created');
    $check(strtolower((string)$lookup['data_type']) === 'char', 'email_lookup_char');
    $check((int)$lookup['character_maximum_length'] === 64, 'email_lookup_length_64');
    $check((int)$db->query('SELECT COUNT(*) FROM password_resets')->fetchColumn() === 0, 'contains_no_real_data');

    $db->beginTransaction();
    $email = 'reset.sandbox@example.test';
    $created = password_reset_create_token($db, $email, '30m', 999001);
    $check(isset($created['token']) && strlen((string)$created['token']) === 64, 'raw_token_returned_once');
    $row = password_reset_find_valid_token($db, (string)$created['token']);
    $check(is_array($row), 'synthetic_token_found');
    $check((int)$row['user_id'] === 999001, 'synthetic_user_link_preserved');
    $check(hash_equals($email, (string)$row['email']), 'encrypted_email_decrypts');

    $stored = $db->query(
        'SELECT HEX(email) AS email_hex, email_lookup, token FROM password_resets ORDER BY id DESC LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
    $check(is_array($stored), 'synthetic_row_stored');
    $storedCipher = hex2bin((string)$stored['email_hex']);
    $check(is_string($storedCipher) && personal_data_is_encrypted($storedCipher), 'encrypted_email_stored');
    $check(!hash_equals($email, $storedCipher), 'plain_email_not_stored');
    $check(hash_equals(personal_data_email_lookup($email), (string)$stored['email_lookup']), 'email_lookup_preserved');
    $check((string)$stored['token'] !== (string)$created['token'], 'raw_token_not_stored');
    $check(password_reset_mark_used($db, (int)$row['id']), 'synthetic_token_marked_used');
    $check(password_reset_find_valid_token($db, (string)$created['token']) === null, 'used_token_rejected');

    $db->rollBack();
    $check((int)$db->query('SELECT COUNT(*) FROM password_resets')->fetchColumn() === 0, 'synthetic_reset_rolled_back');

    $payload = ['ok' => true, 'target' => $target, 'transaction_rolled_back' => true, 'checks' => $checks];
    echo $asJson
        ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
        : 'OK: redefinicao de senha validada no sandbox; dados sinteticos revertidos.' . PHP_EOL;
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
