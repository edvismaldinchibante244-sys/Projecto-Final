<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/src/config/database.php';

$options = getopt('', ['create', 'target:', 'json']);
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
    if (!isset($options['create'])) {
        throw new RuntimeException('Use --create para autorizar a criacao do sandbox.');
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $target)
        || preg_match('/(?:^|_)(test|testing|sandbox|staging)(?:_|$)/i', $target) !== 1) {
        throw new RuntimeException('Nome invalido ou nao identificado como sandbox de teste.');
    }
    $db = (new Database())->getConnection();
    $source = (string)$db->query('SELECT DATABASE()')->fetchColumn();
    if ($source === '' || hash_equals($source, $target)) {
        throw new RuntimeException('O sandbox deve ser diferente do banco conectado.');
    }
    $exists = $db->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?');
    $exists->execute([$target]);
    if ((int)$exists->fetchColumn() !== 0) {
        throw new RuntimeException('O sandbox ja existe; nenhuma estrutura foi sobrescrita.');
    }

    $lock = $db->prepare('SELECT GET_LOCK(?, 10)');
    $lock->execute(['nivora_create_plan_catalog_sandbox']);
    if ((int)$lock->fetchColumn() !== 1) {
        throw new RuntimeException('Nao foi possivel obter o lock do sandbox.');
    }
    try {
        $quoted = '`' . $target . '`';
        $db->exec('CREATE DATABASE ' . $quoted . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $created = true;
        $db->exec('CREATE TABLE ' . $quoted . '.categorias (id INT AUTO_INCREMENT PRIMARY KEY, restaurante_id INT NOT NULL, nome VARCHAR(150) NOT NULL, descricao TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $db->exec('CREATE TABLE ' . $quoted . '.restaurantes (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(180) NOT NULL, logo VARCHAR(255) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $db->exec('CREATE TABLE ' . $quoted . '.usuarios (id INT AUTO_INCREMENT PRIMARY KEY, restaurante_id INT NOT NULL, nome VARCHAR(180) NOT NULL, perfil VARCHAR(40) NOT NULL, foto VARCHAR(255) NULL, super_admin TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $db->exec('CREATE TABLE ' . $quoted . '.clientes (id INT AUTO_INCREMENT PRIMARY KEY, restaurante_id INT NOT NULL, nome VARCHAR(180) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    } finally {
        try {
            $release = $db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute(['nivora_create_plan_catalog_sandbox']);
        } catch (Throwable) {
        }
    }
    $respond([
        'ok' => true,
        'target' => $target,
        'sandbox_created' => true,
        'base_tables' => ['categorias', 'restaurantes', 'usuarios', 'clientes'],
        'copied_customer_data' => false,
        'message' => 'Sandbox cumulativo criado sem dados reais.',
    ], 0);
} catch (Throwable $e) {
    if ($created && $db instanceof PDO && preg_match('/^[a-zA-Z0-9_]+$/', $target)) {
        try {
            $db->exec('DROP DATABASE `' . $target . '`');
        } catch (Throwable) {
        }
    }
    $respond(['ok' => false, 'target' => $target, 'sandbox_created' => false, 'error' => $e->getMessage()], 1);
}
