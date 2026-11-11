<?php
require_once __DIR__ . '/../app_base.php';
require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';

header('Content-Type: application/json; charset=utf-8');

api_guard([
    'roles' => ['ADMIN', 'GERENTE', 'GARCOM'],
    'rate_key' => 'garcom_pedidos',
    'rate_max' => 120,
    'rate_window' => 60,
]);

$restaurante_id = (int)$_SESSION['restaurante_id'];
$garcom_id = (int)$_SESSION['usuario_id'];

$db = (new Database())->getConnection();

$query = "SELECT
    COUNT(*) as total_pedidos,
    SUM(CASE WHEN status = 'NOVO' OR status = 'PENDENTE' THEN 1 ELSE 0 END) as pendentes,
    SUM(CASE WHEN status = 'PREPARANDO' OR status = 'PRONTO' THEN 1 ELSE 0 END) as preparados,
    SUM(CASE WHEN status = 'ENTREGUE' THEN 1 ELSE 0 END) as entregues
FROM pedidos
WHERE garcom_id = :garcom_id
  AND restaurante_id = :rid
  AND (criado_em >= CURDATE() AND criado_em < CURDATE() + INTERVAL 1 DAY)";

$stmt = $db->prepare($query);
$stmt->execute(['garcom_id' => $garcom_id, 'rid' => $restaurante_id]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_pedidos' => 0, 'pendentes' => 0, 'preparados' => 0, 'entregues' => 0];

echo json_encode([
    'success' => true,
    'total_pedidos' => (int)$stats['total_pedidos'],
    'pendentes' => (int)$stats['pendentes'],
    'preparados' => (int)$stats['preparados'],
    'entregues' => (int)$stats['entregues'],
]);
