<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';

header('Content-Type: application/json; charset=utf-8');

$ctx = api_guard([
    'roles' => ['ADMIN'],
    'rate_key' => 'admin_revenue',
    'rate_max' => 120,
    'rate_window' => 60,
    'skip_csrf' => true,
]);

$database = new Database();
$db = $database->getConnection();
$rid = (int)$ctx['restaurante_id'];

// Revenue trend - 30 days
$stmt_trend = $db->prepare("
    SELECT DATE(criado_em) as dia, SUM(total_final) as total
    FROM vendas 
    WHERE restaurante_id = ? AND status = 'PAGO' AND criado_em >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY dia 
    ORDER BY dia DESC
    LIMIT 30
");
$stmt_trend->execute([$rid]);
$trend = $stmt_trend->fetchAll(PDO::FETCH_ASSOC);

$total_mes = $db->prepare("SELECT SUM(total_final) as total_mes FROM vendas WHERE restaurante_id = ? AND (criado_em >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND criado_em < DATE_FORMAT(CURDATE(), '%Y-%m-01') + INTERVAL 1 MONTH) AND status = 'PAGO'");
$total_mes->execute([$rid]);
$total_mes_val = $total_mes->fetch(PDO::FETCH_ASSOC)['total_mes'] ?? 0;

$pedidos_pendentes = $db->prepare("SELECT COUNT(*) as total FROM pedidos WHERE restaurante_id = ? AND status IN ('NOVO', 'PENDENTE')");
$pedidos_pendentes->execute([$rid]);
$pendentes = $pedidos_pendentes->fetch(PDO::FETCH_ASSOC)['total'];

echo json_encode([
    'trend' => $trend,
    'total_mes' => number_format($total_mes_val, 2),
    'pedidos_pendentes' => (int)$pendentes
]);
