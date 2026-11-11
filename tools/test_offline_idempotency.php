<?php

require_once __DIR__ . '/../app/src/config/database.php';
require_once __DIR__ . '/../app/src/config/offline_idempotency.php';

$db = (new Database())->getConnection();
offline_idempotency_prepare($db);

$opId = 'op_test_' . bin2hex(random_bytes(8));
$_SERVER['HTTP_X_IDEMPOTENCY_KEY'] = $opId;
$_POST = ['id' => '123', 'status' => 'CONTA', '_offline_op_id' => $opId];

try {
    $db->beginTransaction();
    $first = offline_idempotency_begin($db, 1, 1, 'test/offline', $_POST);
    if (empty($first['active']) || !empty($first['replay'])) {
        throw new RuntimeException('A primeira operacao nao foi reservada corretamente.');
    }
    $expected = ['success' => true, 'test' => true];
    offline_idempotency_complete($db, $first, $expected);
    $db->commit();

    $db->beginTransaction();
    $second = offline_idempotency_begin($db, 1, 1, 'test/offline', $_POST);
    if (empty($second['replay']) || ($second['response'] ?? null) !== $expected) {
        throw new RuntimeException('A repeticao nao devolveu a resposta gravada.');
    }
    $db->rollBack();

    $delete = $db->prepare('DELETE FROM offline_operations WHERE restaurante_id = 1 AND op_id = :op_id');
    $delete->execute(['op_id' => $opId]);
    echo "OK: reserva, conclusao e replay idempotente validados.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $delete = $db->prepare('DELETE FROM offline_operations WHERE restaurante_id = 1 AND op_id = :op_id');
    $delete->execute(['op_id' => $opId]);
    fwrite(STDERR, 'FALHA: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
