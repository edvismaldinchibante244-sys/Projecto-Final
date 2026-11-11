<?php
require_once __DIR__ . '/../app_base.php';

/*
  API - Pedidos para a Cozinha
 */

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';

header('Content-Type: application/json; charset=utf-8');

api_guard([
    'roles' => ['ADMIN', 'GERENTE', 'COZINHA'],
    'rate_key' => 'cozinha_pedidos',
    'rate_max' => 120,
    'rate_window' => 60,
]);

$restaurante_id = (int)$_SESSION['restaurante_id'];

$database = new Database();
$db = $database->getConnection();

$query = "SELECT p.id, p.numero_pedido, p.status, p.total, p.observacao,
                 p.criado_em, p.atualizado_em,
                 m.numero AS mesa_numero
          FROM pedidos p
          LEFT JOIN mesas m ON p.mesa_id = m.id
          WHERE p.restaurante_id = :rid
            AND p.status IN ('NOVO','PENDENTE','PREPARANDO','PRONTO','PAGO')
            AND (p.criado_em >= CURDATE() AND p.criado_em < CURDATE() + INTERVAL 1 DAY)
          ORDER BY
            FIELD(p.status,'NOVO','PENDENTE','PREPARANDO','PRONTO'),
            p.criado_em ASC";

$stmt = $db->prepare($query);
$stmt->bindParam(':rid', $restaurante_id, PDO::PARAM_INT);
$stmt->execute();
$pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt_itens = $db->prepare(
    "SELECT ip.pedido_id, ip.quantidade, ip.preco_unitario, COALESCE(p.nome, CONCAT('Produto #', ip.produto_id)) AS produto_nome
     FROM itens_pedido ip
     LEFT JOIN produtos p ON ip.produto_id = p.id
     WHERE ip.pedido_id = :pid
     ORDER BY COALESCE(p.nome, CONCAT('Produto #', ip.produto_id))"
);

foreach ($pedidos as &$pedido) {
    $stmt_itens->execute(['pid' => $pedido['id']]);
    $pedido['itens'] = $stmt_itens->fetchAll(PDO::FETCH_ASSOC);
}
unset($pedido);

echo json_encode([
    'success' => true,
    'pedidos' => $pedidos,
    'updated_at' => date('c')
]);
