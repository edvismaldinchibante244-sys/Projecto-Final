<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Itens por setor (cozinha/bar)
 */

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';

header('Content-Type: application/json; charset=utf-8');

api_guard([
    'roles' => ['ADMIN', 'GERENTE', 'COZINHA', 'BAR'],
    'rate_key' => 'setor_itens',
    'rate_max' => 120,
    'rate_window' => 60,
]);

$perfil = strtoupper(trim((string)($_SESSION['perfil'] ?? '')));
if ($perfil === 'GARCOM' || $perfil === 'GARÇOM' || $perfil === 'GARÃ‡OM') {
    $perfil = 'GARCOM';
}

$restaurante_id = (int)($_SESSION['restaurante_id'] ?? 0);
$destino = strtolower(trim((string)($_GET['destino'] ?? 'cozinha')));
if (!in_array($destino, ['cozinha', 'bar'], true)) {
    $destino = 'cozinha';
}

if ($perfil === 'COZINHA') {
    $destino = 'cozinha';
}
if ($perfil === 'BAR') {
    $destino = 'bar';
}

if ($restaurante_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Nao autenticado']);
    exit;
}

$db = (new Database())->getConnection();

$query = "SELECT
            ip.id,
            ip.pedido_id,
            ip.produto_id,
            COALESCE(p.nome, CONCAT('Produto #', ip.produto_id)) AS produto_nome,
            ip.quantidade,
            COALESCE(ip.destino, 'cozinha') AS destino,
            COALESCE(ip.status, 'pendente') AS status,
            ip.iniciado_preparo_em,
            ip.pronto_em,
            ip.entregue_em,
            ped.numero_pedido,
            ped.observacao,
            ped.criado_em,
            m.numero AS mesa_numero
          FROM itens_pedido ip
          INNER JOIN pedidos ped ON ped.id = ip.pedido_id
          LEFT JOIN produtos p ON p.id = ip.produto_id
          LEFT JOIN mesas m ON m.id = ped.mesa_id
          WHERE ped.restaurante_id = :rid
            AND ped.status NOT IN ('PAGO','CANCELADO')
            AND COALESCE(ip.destino, 'cozinha') = :destino
            AND COALESCE(ip.status, 'pendente') IN ('pendente','em_preparo','pronto')
            AND (ped.criado_em >= CURDATE() AND ped.criado_em < CURDATE() + INTERVAL 1 DAY)
          ORDER BY
            FIELD(COALESCE(ip.status, 'pendente'), 'pendente','em_preparo','pronto'),
            ped.criado_em ASC,
            ip.id ASC";

$stmt = $db->prepare($query);
$stmt->execute([
    'rid' => $restaurante_id,
    'destino' => $destino,
]);

$itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($itens as &$it) {
    $it['id'] = (int)$it['id'];
    $it['pedido_id'] = (int)$it['pedido_id'];
    $it['produto_id'] = (int)$it['produto_id'];
    $it['quantidade'] = (int)$it['quantidade'];
}
unset($it);

echo json_encode([
    'success' => true,
    'destino' => $destino,
    'itens' => $itens,
    'updated_at' => date('c'),
], JSON_UNESCAPED_UNICODE);
