<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/src/config/database.php';
require_once __DIR__ . '/../app/src/Model/Auth.php';

$db = (new Database())->getConnection();
$checks = [];

$checks['usuarios_email_encrypted'] = (int)$db->query(
    "SELECT COUNT(id) FROM usuarios WHERE email NOT LIKE 'v1sodium:%' AND email NOT LIKE 'v1gcm:%'"
)->fetchColumn() === 0;
$checks['usuarios_lookup_complete'] = (int)$db->query(
    "SELECT COUNT(id) FROM usuarios WHERE email_lookup IS NULL OR email_lookup = ''"
)->fetchColumn() === 0;
$checks['usuarios_2fa_encrypted'] = (int)$db->query(
    "SELECT COUNT(id) FROM usuarios WHERE two_factor_email IS NOT NULL AND two_factor_email <> '' AND two_factor_email NOT LIKE 'v1sodium:%' AND two_factor_email NOT LIKE 'v1gcm:%'"
)->fetchColumn() === 0;
$checks['restaurantes_email_encrypted'] = (int)$db->query(
    "SELECT COUNT(id) FROM restaurantes WHERE email NOT LIKE 'v1sodium:%' AND email NOT LIKE 'v1gcm:%'"
)->fetchColumn() === 0;

$passwordHashes = $db->query('SELECT senha FROM usuarios')->fetchAll(PDO::FETCH_COLUMN);
$checks['passwords_remain_argon2id'] = $passwordHashes !== [];
foreach ($passwordHashes as $passwordHashValue) {
    if ((password_get_info((string)$passwordHashValue)['algoName'] ?? 'unknown') !== 'argon2id') {
        $checks['passwords_remain_argon2id'] = false;
        break;
    }
}

$userIndexes = $db->query('SHOW INDEX FROM usuarios')->fetchAll(PDO::FETCH_ASSOC);
$restaurantIndexes = $db->query('SHOW INDEX FROM restaurantes')->fetchAll(PDO::FETCH_ASSOC);
$checks['usuarios_unique_email_lookup'] = false;
$checks['restaurantes_unique_email_lookup'] = false;
foreach ($userIndexes as $index) {
    if (($index['Key_name'] ?? '') === 'uq_usuarios_email_lookup' && (int)($index['Non_unique'] ?? 1) === 0) {
        $checks['usuarios_unique_email_lookup'] = true;
    }
}
foreach ($restaurantIndexes as $index) {
    if (($index['Key_name'] ?? '') === 'uq_restaurantes_email_lookup' && (int)($index['Non_unique'] ?? 1) === 0) {
        $checks['restaurantes_unique_email_lookup'] = true;
    }
}

foreach (['telefone', 'endereco', 'nuit'] as $column) {
    $checks['restaurantes_' . $column . '_encrypted'] = (int)$db->query(
        "SELECT COUNT(id) FROM restaurantes WHERE `{$column}` IS NOT NULL AND `{$column}` <> '' AND `{$column}` NOT LIKE 'v1sodium:%' AND `{$column}` NOT LIKE 'v1gcm:%'"
    )->fetchColumn() === 0;
}

$hasPasswordResets = (bool)$db->query(
    "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' LIMIT 1"
)->fetchColumn();
if ($hasPasswordResets) {
    $checks['password_resets_email_encrypted'] = (int)$db->query(
        "SELECT COUNT(id) FROM password_resets WHERE email NOT LIKE 'v1sodium:%' AND email NOT LIKE 'v1gcm:%'"
    )->fetchColumn() === 0;
    $checks['password_resets_lookup_complete'] = (int)$db->query(
        "SELECT COUNT(id) FROM password_resets WHERE email_lookup IS NULL OR email_lookup = ''"
    )->fetchColumn() === 0;
}

$sample = $db->query('SELECT email, email_lookup FROM usuarios ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$checks['transparent_decryption'] = is_array($sample)
    && filter_var((string)($sample['email'] ?? ''), FILTER_VALIDATE_EMAIL) !== false;
$checks['lookup_matches_plaintext'] = is_array($sample)
    && hash_equals((string)$sample['email_lookup'], personal_data_email_lookup((string)$sample['email']));

$restaurantId = (int)($db->query("SELECT id FROM restaurantes WHERE status = 'ATIVO' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if ($restaurantId <= 0) {
    throw new RuntimeException('Nenhum restaurante ativo para o teste transacional de login.');
}

$email = 'crypto-regression-' . bin2hex(random_bytes(5)) . '@example.invalid';
$password = 'Teste-Cripto-2026!';
$passwordHash = password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);

$db->beginTransaction();
try {
    $stmt = $db->prepare(
        "INSERT INTO usuarios (restaurante_id, nome, email, email_lookup, senha, perfil, ativo, two_factor_email) VALUES (?, 'Teste Criptografia', ?, ?, ?, 'ADMIN', 1, ?)"
    );
    $encryptedEmail = personal_data_encrypt($email);
    $stmt->execute([
        $restaurantId,
        $encryptedEmail,
        personal_data_email_lookup($email),
        $passwordHash,
        $encryptedEmail,
    ]);

    $auth = new Auth($db);
    $hashLogin = $auth->login($email, $passwordHash);
    $normalLogin = $auth->login($email, $password);
    $checks['hash_cannot_authenticate'] = empty($hashLogin['success']);
    $checks['normal_password_authenticates'] = !empty($normalLogin['success']);
    $checks['login_returns_plain_email'] = (string)($normalLogin['data']['email'] ?? '') === $email;
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo json_encode([
    'success' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === [] ? 0 : 1);
