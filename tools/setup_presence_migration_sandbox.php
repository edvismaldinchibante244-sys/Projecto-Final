<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/src/config/database.php';

$options = getopt('', ['extend', 'target:', 'json']);
$asJson = isset($options['json']);
$target = trim((string)($options['target'] ?? ''));
$db = null;
$created = false;

$respond = static function (array $payload, int $code) use ($asJson): never {
    if ($asJson) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        fwrite($code === 0 ? STDOUT : STDERR, ($payload['message'] ?? $payload['error'] ?? '') . PHP_EOL);
    }
    exit($code);
};

try {
    if (!isset($options['extend'])) {
        throw new RuntimeException('Use --extend para autorizar a extensao do sandbox.');
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $target)
        || preg_match('/(?:^|_)(test|testing|sandbox|staging)(?:_|$)/i', $target) !== 1) {
        throw new RuntimeException('Alvo invalido ou nao identificado como sandbox de teste.');
    }

    $db = (new Database())->getConnection();
    $actual = (string)$db->query('SELECT DATABASE()')->fetchColumn();
    if ($actual === '' || !hash_equals($actual, $target)) {
        throw new RuntimeException('O banco conectado nao corresponde ao sandbox informado.');
    }
    $tables = $db->query(
        'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name'
    )->fetchAll(PDO::FETCH_COLUMN);
    if ($tables !== ['categorias', 'restaurantes', 'schema_migrations']) {
        throw new RuntimeException('Estrutura inesperada no sandbox; nenhuma tabela foi criada.');
    }

    $lock = $db->prepare('SELECT GET_LOCK(?, 10)');
    $lock->execute(['nivora_extend_presence_sandbox']);
    if ((int)$lock->fetchColumn() !== 1) {
        throw new RuntimeException('Nao foi possivel obter o lock do sandbox.');
    }
    try {
        $db->exec(
            'CREATE TABLE usuarios ('
            . 'id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'restaurante_id INT NOT NULL,'
            . 'nome VARCHAR(180) NOT NULL,'
            . 'perfil VARCHAR(40) NOT NULL,'
            . 'foto VARCHAR(255) NULL DEFAULT NULL,'
            . 'super_admin TINYINT(1) NOT NULL DEFAULT 0'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $created = true;
    } finally {
        try {
            $release = $db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute(['nivora_extend_presence_sandbox']);
        } catch (Throwable) {
        }
    }

    $respond([
        'ok' => true,
        'target' => $target,
        'table_created' => 'usuarios',
        'copied_customer_data' => false,
        'message' => 'Sandbox ampliado com usuarios minimo e vazio.',
    ], 0);
} catch (Throwable $e) {
    if ($created && $db instanceof PDO) {
        try {
            $db->exec('DROP TABLE usuarios');
        } catch (Throwable) {
        }
    }
    $respond(['ok' => false, 'target' => $target, 'error' => $e->getMessage()], 1);
}
