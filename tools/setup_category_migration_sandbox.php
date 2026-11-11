<?php
declare(strict_types=1);

/**
 * Cria um schema MySQL isolado e sem dados reais para testar a primeira migracao.
 * Nunca reutiliza, limpa ou sobrescreve um schema existente.
 */

require_once __DIR__ . '/../app/src/config/database.php';

$options = getopt('', ['create', 'target:', 'json']);
$asJson = isset($options['json']);
$target = trim((string)($options['target'] ?? ''));
$databaseConnected = false;
$created = false;
$db = null;

$respond = static function (array $payload, int $code) use ($asJson): never {
    if ($asJson) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        $stream = $code === 0 ? STDOUT : STDERR;
        fwrite($stream, ($payload['message'] ?? $payload['error'] ?? 'Operacao concluida.') . PHP_EOL);
    }
    exit($code);
};

try {
    if (!isset($options['create'])) {
        throw new RuntimeException('Use --create para autorizar a criacao do sandbox.');
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $target)) {
        throw new RuntimeException('Nome de sandbox invalido. Use apenas letras, numeros e underscore.');
    }
    if (preg_match('/(?:^|_)(test|testing|sandbox|staging)(?:_|$)/i', $target) !== 1) {
        throw new RuntimeException('O nome do banco deve identificar claramente test, sandbox ou staging.');
    }

    $db = (new Database())->getConnection();
    if (!$db instanceof PDO) {
        throw new RuntimeException('Ligacao PDO indisponivel.');
    }
    $databaseConnected = true;
    if ((string)$db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('O sandbox requer MySQL.');
    }

    $source = (string)$db->query('SELECT DATABASE()')->fetchColumn();
    if ($source === '' || hash_equals($source, $target)) {
        throw new RuntimeException('O sandbox deve ser diferente do banco actualmente conectado.');
    }

    $exists = $db->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?');
    $exists->execute([$target]);
    if ((int)$exists->fetchColumn() !== 0) {
        throw new RuntimeException('O sandbox ja existe; operacao recusada para nao sobrescrever dados.');
    }

    $lock = $db->prepare('SELECT GET_LOCK(?, 10)');
    $lock->execute(['nivora_create_category_migration_sandbox']);
    if ((int)$lock->fetchColumn() !== 1) {
        throw new RuntimeException('Nao foi possivel obter o lock de criacao do sandbox.');
    }

    try {
        $quotedTarget = '`' . $target . '`';
        $db->exec('CREATE DATABASE ' . $quotedTarget . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $created = true;
        $db->exec(
            'CREATE TABLE ' . $quotedTarget . '.categorias ('
            . 'id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'restaurante_id INT NOT NULL,'
            . 'nome VARCHAR(150) NOT NULL,'
            . 'descricao TEXT NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } finally {
        try {
            $release = $db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute(['nivora_create_category_migration_sandbox']);
        } catch (Throwable) {
            // A ligacao tambem liberta o lock ao terminar.
        }
    }

    $respond([
        'ok' => true,
        'database_connected' => $databaseConnected,
        'sandbox_created' => true,
        'target' => $target,
        'copied_customer_data' => false,
        'message' => 'Sandbox criado com tabela categorias minima e sem dados reais.',
    ], 0);
} catch (Throwable $e) {
    // Remove apenas o schema novo desta execucao se a criacao ficou incompleta.
    if ($created && $db instanceof PDO && preg_match('/^[a-zA-Z0-9_]+$/', $target)) {
        try {
            $db->exec('DROP DATABASE `' . $target . '`');
        } catch (Throwable) {
            // Reporta a falha original; uma eventual limpeza manual fica explicita.
        }
    }
    $respond([
        'ok' => false,
        'database_connected' => $databaseConnected,
        'sandbox_created' => false,
        'error' => $e->getMessage(),
    ], 1);
}
