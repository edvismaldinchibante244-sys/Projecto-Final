<?php
require_once __DIR__ . '/../app_base.php';
require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
header('Content-Type: application/json');

api_guard([
    'roles' => ['ADMIN', 'GERENTE', 'CAIXA'],
    'rate_key' => 'caixa_vendas',
    'rate_max' => 120,
    'rate_window' => 60,
]);

$database = new Database();
$db = $database->getConnection();
$rid = $_SESSION['restaurante_id'] ?? 0;
$uid = $_SESSION['usuario_id'] ?? 0;
$perfil = strtoupper(trim($_SESSION['perfil'] ?? ''));

if ($rid <= 0 || $uid <= 0) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

$vendas_hoje = $db->prepare("
    SELECT 
        COUNT(*) as qtd_vendas,
        SUM(total_final) as total_vendas,
        AVG(total_final) as ticket_medio
    FROM vendas 
    WHERE restaurante_id = ? AND usuario_id = ? AND (criado_em >= CURDATE() AND criado_em < CURDATE() + INTERVAL 1 DAY) AND status = 'PAGO'
");
$vendas_hoje->execute([$rid, $uid]);
$vendas = $vendas_hoje->fetch(PDO::FETCH_ASSOC) ?: ['qtd_vendas' => 0, 'total_vendas' => 0, 'ticket_medio' => 0];

$pedidos_pagos = $db->prepare("
    SELECT COUNT(*) as qtd_pedidos 
    FROM vendas 
    WHERE restaurante_id = ? AND (criado_em >= CURDATE() AND criado_em < CURDATE() + INTERVAL 1 DAY) AND status = 'PAGO'
");
$pedidos_pagos->execute([$rid]);
$pedidos = $pedidos_pagos->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'vendas_hoje' => (float)($vendas['total_vendas'] ?? 0),
    'qtd_vendas' => (int)($vendas['qtd_vendas'] ?? 0),
    'ticket_medio' => (float)($vendas['ticket_medio'] ?? 0),
    'qtd_pedidos' => (int)($pedidos['qtd_pedidos'] ?? 0)
]);
?>


