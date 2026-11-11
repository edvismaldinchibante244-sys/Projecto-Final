<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/src/config/database.php';
require_once __DIR__ . '/../app/src/config/migration_state.php';

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

    $expectedTables = [
        'categorias', 'cliente_carrinho_itens', 'cliente_carrinhos', 'clientes',
        'restaurantes', 'schema_migrations', 'usuarios',
    ];
    $tables = $db->query(
        'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name'
    )->fetchAll(PDO::FETCH_COLUMN);
    sort($tables, SORT_STRING);
    sort($expectedTables, SORT_STRING);
    $check($tables === $expectedTables, 'expected_sandbox_tables');

    $id = '20260814_1240_cliente_carrinho';
    $stmt = $db->prepare('SELECT COUNT(*) FROM schema_migrations WHERE id = ?');
    $stmt->execute([$id]);
    $check((int)$stmt->fetchColumn() === 1, 'migration_recorded_once');
    $check(migration_esta_aplicada($db, $id), 'fallback_detects_migration');

    foreach (['uq_carrinho_cliente_rest', 'uq_carrinho_produto'] as $index) {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND index_name = ?'
        );
        $stmt->execute([$index]);
        $check((int)$stmt->fetchColumn() > 0, $index . '_exists');
    }
    foreach (['fk_carrinho_cliente', 'fk_carrinho_itens_carrinho'] as $constraint) {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.referential_constraints '
            . 'WHERE constraint_schema = DATABASE() AND constraint_name = ? AND delete_rule = ?'
        );
        $stmt->execute([$constraint, 'CASCADE']);
        $check((int)$stmt->fetchColumn() === 1, $constraint . '_cascade');
    }

    $db->beginTransaction();
    $client = $db->prepare('INSERT INTO clientes (restaurante_id, nome) VALUES (?, ?)');
    $client->execute([999999, 'Cliente Carrinho Teste']);
    $clientId = (int)$db->lastInsertId();
    $check($clientId > 0, 'synthetic_client_inserted');

    $cart = $db->prepare(
        'INSERT INTO cliente_carrinhos (cliente_id, restaurante_id, atualizado_em) VALUES (?, ?, NOW())'
    );
    $cart->execute([$clientId, 999999]);
    $cartId = (int)$db->lastInsertId();
    $check($cartId > 0, 'cart_inserted');

    $item = $db->prepare(
        'INSERT INTO cliente_carrinho_itens (carrinho_id, produto_id, quantidade, preco_unitario) VALUES (?, ?, ?, ?)'
    );
    $item->execute([$cartId, 123456, 2, 150.50]);
    $itemId = (int)$db->lastInsertId();
    $check($itemId > 0, 'cart_item_inserted');

    $total = $db->prepare(
        'SELECT SUM(quantidade * preco_unitario) FROM cliente_carrinho_itens WHERE carrinho_id = ?'
    );
    $total->execute([$cartId]);
    $check(abs((float)$total->fetchColumn() - 301.0) < 0.001, 'cart_total_calculated');

    $deleteClient = $db->prepare('DELETE FROM clientes WHERE id = ?');
    $deleteClient->execute([$clientId]);
    $cartCount = $db->prepare('SELECT COUNT(*) FROM cliente_carrinhos WHERE id = ?');
    $cartCount->execute([$cartId]);
    $itemCount = $db->prepare('SELECT COUNT(*) FROM cliente_carrinho_itens WHERE id = ?');
    $itemCount->execute([$itemId]);
    $check((int)$cartCount->fetchColumn() === 0, 'client_delete_cascades_cart');
    $check((int)$itemCount->fetchColumn() === 0, 'cart_delete_cascades_items');

    $db->rollBack();
    $remaining = $db->query("SELECT COUNT(*) FROM clientes WHERE nome = 'Cliente Carrinho Teste'")->fetchColumn();
    $check((int)$remaining === 0, 'synthetic_data_rolled_back');

    $payload = ['ok' => true, 'target' => $target, 'transaction_rolled_back' => true, 'checks' => $checks];
    echo $asJson
        ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
        : 'OK: carrinho validado no sandbox; dados sinteticos revertidos.' . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $payload = ['ok' => false, 'target' => $target, 'transaction_rolled_back' => true, 'error' => $e->getMessage(), 'checks' => $checks];
    if ($asJson) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        fwrite(STDERR, 'FALHA: ' . $e->getMessage() . PHP_EOL);
    }
    exit(1);
}
