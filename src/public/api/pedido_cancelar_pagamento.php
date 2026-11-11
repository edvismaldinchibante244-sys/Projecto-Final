<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Cancelar pagamento de pedido
 */

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/autorizacao_gerente.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/pedido_schema.php';
require_once APP_BASE . '/src/Model/Venda.php';

header('Content-Type: application/json; charset=utf-8');

api_guard([
    'roles' => ['ADMIN', 'GERENTE', 'CAIXA'],
    'rate_key' => 'pedido_cancelar_pagamento',
    'rate_max' => 30,
    'rate_window' => 60,
]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo nao permitido']);
    exit;
}

if (!isset($_SESSION['restaurante_id'], $_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'message' => 'Nao autenticado']);
    exit;
}

$pedido_id = (int)($_POST['id'] ?? 0);
if ($pedido_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID do pedido invalido']);
    exit;
}

// Caixa: so com o PIN de um gerente (ou palavra-passe do administrador).
autorizacao_gerente_exigir('cancelar_pagamento', $pedido_id);

$database = new Database();
$db = $database->getConnection();
pedido_schema_garantir($db);

try {
    $db->beginTransaction();

    $stmt_pedido = $db->prepare('SELECT id, restaurante_id, status FROM pedidos WHERE id = :id AND restaurante_id = :rid LIMIT 1');
    $stmt_pedido->execute(['id' => $pedido_id, 'rid' => $_SESSION['restaurante_id']]);
    $pedido = $stmt_pedido->fetch(PDO::FETCH_ASSOC);
    if (!$pedido) {
        throw new Exception('Pedido nao encontrado');
    }
    if ($pedido['status'] !== 'PAGO') {
        throw new Exception('So e possivel cancelar pagamento de pedidos pagos');
    }

    $stmt_venda = $db->prepare('SELECT id FROM vendas WHERE pedido_id = :pedido_id LIMIT 1');
    $stmt_venda->execute(['pedido_id' => $pedido_id]);
    $venda = $stmt_venda->fetch(PDO::FETCH_ASSOC);

    if ($venda) {
        $db->prepare('DELETE FROM itens_venda WHERE venda_id = :venda_id')->execute(['venda_id' => $venda['id']]);
        $db->prepare('DELETE FROM vendas WHERE id = :id')->execute(['id' => $venda['id']]);
    }

    $db->prepare('UPDATE pedidos SET status = "ENTREGUE", atualizado_em = NOW(), entregue_em = COALESCE(entregue_em, NOW()), pago_em = NULL WHERE id = :id')->execute(['id' => $pedido_id]);

    $db->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    if ($db && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('pedido_cancelar_pagamento: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Nao foi possivel cancelar o pagamento.']);
}
