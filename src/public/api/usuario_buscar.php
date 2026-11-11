<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Buscar Usuario por ID
 */

ob_start();
error_reporting(0);
ini_set('display_errors', '0');

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/restaurante_context.php';

header('Content-Type: application/json; charset=utf-8');

api_guard([
    'roles' => ['ADMIN'],
    'rate_key' => 'usuario_buscar',
    'rate_max' => 120,
    'rate_window' => 60,
    'skip_turno' => true,
]);

$response = ['success' => false, 'message' => ''];

if (session_restaurante_contexto_id() <= 0) {
    $response['message'] = 'Restaurante nao identificado';
    echo json_encode($response);
    exit;
}

if (empty($_GET['id'])) {
    $response['message'] = 'ID nao fornecido';
    echo json_encode($response);
    exit;
}

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    $response['message'] = 'Erro de conexao';
    echo json_encode($response);
    exit;
}

$id = intval($_GET['id']);
$rid = session_restaurante_contexto_id();

$usuario = resource_require_usuario($db, $id, $rid, ['id', 'nome', 'email', 'perfil', 'ativo', 'foto']);

$temColunaSuperAdmin = false;
try {
    $colStmt = $db->query("SHOW COLUMNS FROM usuarios LIKE 'super_admin'");
    $temColunaSuperAdmin = $colStmt && $colStmt->fetch(PDO::FETCH_ASSOC) ? true : false;
} catch (Throwable $e) {
    $temColunaSuperAdmin = false;
}

if ($temColunaSuperAdmin) {
    $chk = $db->prepare("SELECT COALESCE(super_admin, 0) FROM usuarios WHERE id = ? AND restaurante_id = ? LIMIT 1");
    $chk->execute([$id, $rid]);
    if ((int)$chk->fetchColumn() === 1) {
        resource_deny_not_found(null, ['table' => 'usuarios', 'resource_id' => $id, 'restaurante_id' => $rid]);
    }
}

echo json_encode(['success' => true, 'data' => $usuario]);
