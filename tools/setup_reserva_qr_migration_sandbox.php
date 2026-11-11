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
    $exists = $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'reservas'");
    if ((int)$exists->fetchColumn() !== 0) {
        throw new RuntimeException('A tabela reservas ja existe; nenhuma estrutura foi sobrescrita.');
    }
    foreach (['schema_migrations', 'restaurantes', 'offline_operations', 'plano_lifecycle_notificacoes'] as $required) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([$required]);
        if ((int)$stmt->fetchColumn() !== 1) {
            throw new RuntimeException('Sandbox cumulativo incompleto: ' . $required);
        }
    }
    foreach (['restaurantes', 'usuarios', 'clientes', 'offline_operations'] as $table) {
        if ((int)$db->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() !== 0) {
            throw new RuntimeException('Sandbox contem dados operacionais em ' . $table . '; extensao recusada.');
        }
    }

    $lock = $db->prepare('SELECT GET_LOCK(?, 10)');
    $lock->execute(['nivora_extend_reserva_qr_sandbox']);
    if ((int)$lock->fetchColumn() !== 1) {
        throw new RuntimeException('Nao foi possivel obter o lock do sandbox.');
    }
    try {
        $db->exec(
            "CREATE TABLE reservas (id INT AUTO_INCREMENT PRIMARY KEY, restaurante_id INT NOT NULL, "
            . "status ENUM('pendente','confirmado','no-show','cancelado') NULL DEFAULT 'pendente') "
            . "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $created = true;
    } finally {
        try {
            $release = $db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute(['nivora_extend_reserva_qr_sandbox']);
        } catch (Throwable) {
        }
    }
    $respond(['ok' => true, 'target' => $target, 'table_created' => 'reservas', 'copied_customer_data' => false, 'message' => 'Sandbox ampliado com reservas minima e vazia.'], 0);
} catch (Throwable $e) {
    if ($created && $db instanceof PDO) {
        try {
            $db->exec('DROP TABLE reservas');
        } catch (Throwable) {
        }
    }
    $respond(['ok' => false, 'target' => $target, 'error' => $e->getMessage()], 1);
}
