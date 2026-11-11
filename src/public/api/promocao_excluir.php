<?php
require_once __DIR__ . '/../app_base.php';
require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/restaurante_context.php';
require_once APP_BASE . '/src/config/promocao_schema.php';

header('Content-Type: application/json; charset=utf-8');
api_guard(['roles' => ['ADMIN', 'GERENTE'], 'rate_key' => 'promocao_excluir', 'rate_max' => 30, 'rate_window' => 60]);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo nao permitido']);
    exit;
}
$id = (int)($_POST['id'] ?? 0);
$rid = session_restaurante_contexto_id();
$db = (new Database())->getConnection();
promocao_schema_garantir($db);
$stmt = $db->prepare('UPDATE promocoes SET ativo = 0 WHERE id = :id AND restaurante_id = :rid');
$stmt->execute(['id' => $id, 'rid' => $rid]);
echo json_encode(['success' => $stmt->rowCount() > 0, 'message' => $stmt->rowCount() > 0 ? 'Promocao desativada.' : 'Promocao nao encontrada.']);
