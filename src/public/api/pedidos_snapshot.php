<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Snapshot de Pedidos do Dia
 */

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/pedido_schema.php';
require_once APP_BASE . '/src/config/restaurante_context.php';
require_once APP_BASE . '/src/config/input_validation.php';

header('Content-Type: application/json; charset=utf-8');

api_guard([
    'roles' => ['ADMIN', 'GERENTE', 'CAIXA', 'GARCOM', 'COZINHA', 'BAR'],
    'rate_key' => 'pedidos_snapshot',
    'rate_max' => 120,
    'rate_window' => 60,
]);

$filter = input_enum($_GET['filter'] ?? '', ['', 'pendentes'], true);
if ($filter === null) {
    security_json(['success' => false, 'message' => 'Parametro filter invalido.'], 400);
}

function normalizar_status_pedido($status)
{
    $status = strtoupper(trim((string)$status));
    $map = [
        'PENDENTE' => 'NOVO',
        'CONFIRMADO' => 'PREPARANDO',
        'NOVO' => 'NOVO',
        'PREPARANDO' => 'PREPARANDO',
        'PRONTO' => 'PRONTO',
        'ENTREGUE' => 'ENTREGUE',
        'CONTA' => 'CONTA',
        'CONTA_SOLICITADA' => 'CONTA',
        'PEDIR_CONTA' => 'CONTA',
        'PAGO' => 'PAGO',
        'CANCELADO' => 'CANCELADO'
    ];
    return $map[$status] ?? 'NOVO';
}

try {
    $database = new Database();
    $db = $database->getConnection();
    pedido_schema_garantir($db);
    $restauranteId = session_restaurante_contexto_id();
    if ($restauranteId <= 0) {
        security_json(['success' => false, 'message' => 'Contexto do restaurante inválido.'], 403);
    }

    $query = "SELECT p.*, m.numero as mesa_numero,
              (SELECT COUNT(*) FROM itens_pedido WHERE pedido_id = p.id) as total_itens,
              (SELECT GROUP_CONCAT(CONCAT(ip.quantidade, 'x ', COALESCE(pr.nome, CONCAT('Produto #', ip.produto_id))) SEPARATOR ', ')
                 FROM itens_pedido ip
                 LEFT JOIN produtos pr ON pr.id = ip.produto_id
                WHERE ip.pedido_id = p.id) AS itens_resumo
              FROM pedidos p
              LEFT JOIN mesas m ON p.mesa_id = m.id
              WHERE p.restaurante_id = :rid
              AND (p.criado_em >= CURDATE() AND p.criado_em < CURDATE() + INTERVAL 1 DAY)";
    $queryParams = [':rid' => $restauranteId];

    if ($filter === 'pendentes') {
        $query .= " AND p.status IN ('NOVO','PENDENTE','PREPARANDO')";
    }

    $query .= " ORDER BY p.criado_em DESC";

    $stmt = $db->prepare($query);
    foreach ($queryParams as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    }
    $stmt->execute();
    $pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $contadores = ['NOVO' => 0, 'PREPARANDO' => 0, 'PRONTO' => 0, 'ENTREGUE' => 0, 'CONTA' => 0, 'PAGO' => 0, 'CANCELADO' => 0];

    foreach ($pedidos as &$pedido) {
        $pedido['status'] = normalizar_status_pedido($pedido['status'] ?? 'NOVO');
        $pedido['origem'] = pedido_normalizar_origem($pedido['origem'] ?? 'BALCAO');
        if (isset($contadores[$pedido['status']])) {
            $contadores[$pedido['status']]++;
        }
    }
    unset($pedido);

    echo json_encode([
        'success' => true,
        'pedidos' => $pedidos,
        'contadores' => $contadores,
        'updated_at' => date('c')
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('[pedidos_snapshot] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro ao carregar pedidos']);
}
