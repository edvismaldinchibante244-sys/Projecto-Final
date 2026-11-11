<?php
require_once __DIR__ . '/../app_base.php';
require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';

header('Content-Type: application/json; charset=utf-8');

api_guard([
    'roles' => ['ADMIN', 'GERENTE', 'GARCOM'],
    'rate_key' => 'garcom_vendas',
    'rate_max' => 120,
    'rate_window' => 60,
]);

$restaurante_id = (int)$_SESSION['restaurante_id'];
$garcom_id = (int)$_SESSION['usuario_id'];

$db = (new Database())->getConnection();

$query = "SELECT
    COUNT(*) as total_vendas,
    COALESCE(SUM(total), 0) as total_vendas_hoje
FROM pedidos
WHERE garcom_id = :garcom_id
  AND restaurante_id = :rid
  AND (criado_em >= CURDATE() AND criado_em < CURDATE() + INTERVAL 1 DAY)
  AND status <> 'CANCELADO'";

$stmt = $db->prepare($query);
$stmt->execute(['garcom_id' => $garcom_id, 'rid' => $restaurante_id]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_vendas' => 0, 'total_vendas_hoje' => 0];

echo json_encode([
    'success' => true,
    'total_vendas' => (int)$stats['total_vendas'],
    'total_vendas_hoje' => (float)$stats['total_vendas_hoje'],
]);
