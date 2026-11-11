<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/src/config/database.php';

$options = getopt('', ['extend', 'target:', 'json']);
$asJson = isset($options['json']);
$target = trim((string)($options['target'] ?? ''));
$db = null;
$created = false;
$respond = static function (array $payload, int $code) use ($asJson): never {
    echo $asJson
        ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
        : ($payload['message'] ?? $payload['error'] ?? '') . PHP_EOL;
    exit($code);
};

try {
    if (!isset($options['extend'])) {
        throw new RuntimeException('Use --extend para autorizar a extensao do sandbox.');
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $target)
        || preg_match('/(?:^|_)(test|testing|sandbox|staging)(?:_|$)/i', $target) !== 1) {
        throw new RuntimeException('Alvo invalido ou nao identificado como sandbox.');
    }
    $db = (new Database())->getConnection();
    $actual = (string)$db->query('SELECT DATABASE()')->fetchColumn();
    if ($actual === '' || !hash_equals($actual, $target)) {
        throw new RuntimeException('O banco conectado nao corresponde ao sandbox informado.');
    }

    foreach (['schema_migrations', 'usuarios', 'pedido_qr_meta', 'compras_planos'] as $table) {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([$table]);
        if ((int)$stmt->fetchColumn() !== 1) {
            throw new RuntimeException('Sandbox cumulativo incompleto; tabela ausente: ' . $table);
        }
    }
    foreach (['usuarios', 'pedido_qr_meta', 'compras_planos'] as $table) {
        if ((int)$db->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() !== 0) {
            throw new RuntimeException('Sandbox contem dados operacionais em ' . $table . '; extensao recusada.');
        }
    }

    $exists = $db->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() "
        . "AND table_name = 'password_resets'"
    );
    if ((int)$exists->fetchColumn() !== 0) {
        throw new RuntimeException('Tabela password_resets ja existe; nenhuma alteracao foi feita.');
    }

    $lock = $db->prepare('SELECT GET_LOCK(?, 10)');
    $lock->execute(['nivora_extend_password_reset_sandbox']);
    if ((int)$lock->fetchColumn() !== 1) {
        throw new RuntimeException('Nao foi possivel obter o lock do sandbox.');
    }
    try {
        $db->exec(
            'CREATE TABLE password_resets ('
            . 'id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'email TEXT NOT NULL,'
            . 'token VARCHAR(255) NOT NULL,'
            . 'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,'
            . 'expires_at DATETIME NOT NULL,'
            . 'used TINYINT(1) DEFAULT 0,'
            . 'INDEX idx_token (token)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $created = true;
    } finally {
        try {
            $release = $db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute(['nivora_extend_password_reset_sandbox']);
        } catch (Throwable) {
        }
    }

    $respond([
        'ok' => true,
        'target' => $target,
        'table_created' => 'password_resets',
        'copied_customer_data' => false,
        'message' => 'Sandbox ampliado com password_resets legada, minima e vazia.',
    ], 0);
} catch (Throwable $e) {
    if ($created && $db instanceof PDO) {
        try {
            $db->exec('DROP TABLE password_resets');
        } catch (Throwable) {
        }
    }
    $respond(['ok' => false, 'target' => $target, 'error' => $e->getMessage()], 1);
}
