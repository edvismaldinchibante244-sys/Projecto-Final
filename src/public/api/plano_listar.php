<?php
require_once __DIR__ . '/../app_base.php';

/**
   API - Listar Compras de Planos
   Lista as compras de planos do restaurante
 */

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/plano_upgrade_guard.php';

header('Content-Type: application/json; charset=utf-8');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    security_json(['success' => false, 'message' => 'Metodo nao permitido'], 405);
}

$ctx = api_guard([
    'rate_key' => 'plano_listar',
    'rate_max' => 120,
    'rate_window' => 60,
    'skip_turno' => true,
    'skip_csrf' => true,
]);

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    security_json(['success' => false, 'message' => 'Erro de conexao'], 500);
}

$restaurantePlanoId = plano_upgrade_restaurante_alvo_id($db);
if (!plano_upgrade_usuario_pode_gerir($db, (int)$ctx['usuario_id'], $restaurantePlanoId)) {
    security_json(['success' => false, 'message' => 'Apenas o dono/administrador principal do restaurante pode ver compras de planos.'], 403);
}

$colunasCompra = [];
$stmtCols = $db->query('SHOW COLUMNS FROM compras_planos');
while ($col = $stmtCols->fetch(PDO::FETCH_ASSOC)) {
    $colunasCompra[] = $col['Field'];
}

$colData = in_array('criado_em', $colunasCompra, true) ? 'criado_em' : 'created_at';
$stmt = $db->prepare("SELECT *, " . $colData . " AS data_compra FROM compras_planos WHERE restaurante_id = ? ORDER BY " . $colData . " DESC");
$stmt->execute([$restaurantePlanoId]);

security_json([
    'success' => true,
    'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
]);
