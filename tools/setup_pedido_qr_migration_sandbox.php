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

    $expectedTables = [
        'audit_logs', 'blocked_ips', 'categorias', 'cliente_carrinho_itens', 'cliente_carrinhos',
        'clientes', 'offline_operations', 'offline_sync_queue', 'plano_cobranca_logs',
        'plano_downgrades', 'plano_funcionalidades', 'plano_lifecycle_notificacoes', 'plano_logs',
        'planos_disponiveis', 'reserva_pre_bloqueios', 'reserva_qr_sessoes', 'reservas',
        'restaurante_planos', 'restaurantes', 'schema_migrations', 'security_events', 'usuarios',
    ];
    $tables = $db->query(
        'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
    )->fetchAll(PDO::FETCH_COLUMN);
    sort($tables, SORT_STRING);
    sort($expectedTables, SORT_STRING);
    if ($tables !== $expectedTables) {
        throw new RuntimeException('Estrutura cumulativa inesperada no sandbox; nenhuma tabela foi criada.');
    }
    foreach (['restaurantes', 'usuarios', 'clientes', 'reservas', 'blocked_ips', 'security_events', 'offline_sync_queue'] as $table) {
        if ((int)$db->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() !== 0) {
            throw new RuntimeException('Sandbox contem dados operacionais em ' . $table . '; extensao recusada.');
        }
    }

    $lock = $db->prepare('SELECT GET_LOCK(?, 10)');
    $lock->execute(['nivora_extend_pedido_qr_sandbox']);
    if ((int)$lock->fetchColumn() !== 1) {
        throw new RuntimeException('Nao foi possivel obter o lock do sandbox.');
    }
    try {
        $db->exec(
            'CREATE TABLE itens_pedido ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $created = true;
    } finally {
        try {
            $release = $db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute(['nivora_extend_pedido_qr_sandbox']);
        } catch (Throwable) {
        }
    }

    $respond([
        'ok' => true,
        'target' => $target,
        'table_created' => 'itens_pedido',
        'copied_customer_data' => false,
        'message' => 'Sandbox ampliado com itens_pedido minimo e vazio.',
    ], 0);
} catch (Throwable $e) {
    if ($created && $db instanceof PDO) {
        try {
            $db->exec('DROP TABLE itens_pedido');
        } catch (Throwable) {
        }
    }
    $respond(['ok' => false, 'target' => $target, 'error' => $e->getMessage()], 1);
}
