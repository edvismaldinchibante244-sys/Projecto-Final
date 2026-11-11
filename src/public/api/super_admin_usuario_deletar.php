<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Super Admin Inativar Usuario
   Inativa um usuario de um restaurante
 */

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/super_admin_permissions.php';
require_once APP_BASE . '/src/config/audit.php';

header('Content-Type: application/json; charset=utf-8');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    security_json(["success" => false, "message" => "Metodo nao permitido"], 405);
}

api_guard([
    'rate_key' => 'super_admin_usuario_deletar',
    'rate_max' => 30,
    'rate_window' => 60,
    'skip_turno' => true,
]);

if (!isset($_SESSION['super_admin']) || (int)$_SESSION['super_admin'] !== 1) {
    security_json(["success" => false, "message" => "Acesso negado"], 403);
}

super_admin_require_permission_json('manage_users');

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$usuario_id = (int)($input['usuario_id'] ?? 0);
if ($usuario_id <= 0) {
    security_json(["success" => false, "message" => "ID do usuario invalido"], 422);
}

$database = new Database();
$db = $database->getConnection();
if (!$db) {
    security_json(["success" => false, "message" => "Erro de conexao"], 500);
}

$stmt = $db->prepare("SELECT id, nome, perfil FROM usuarios WHERE id = ?");
$stmt->execute([$usuario_id]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$usuario) {
    security_json(["success" => false, "message" => "Usuario nao encontrado"], 404);
}

if (($usuario['perfil'] ?? '') === 'ADMIN' && $usuario_id === (int)($_SESSION['usuario_id'] ?? 0)) {
    security_json(["success" => false, "message" => "Nao e possivel inativar seu proprio usuario"], 409);
}

try {
    $stmt = $db->prepare("
        UPDATE usuarios
        SET ativo = 0,
            tentativas_login = 0,
            bloqueado_ate = NULL
        WHERE id = ?
    ");
    $stmt->execute([$usuario_id]);
    audit_log($db, 'super_admin_usuario_inativado', [
        'usuario_alvo_id' => $usuario_id,
        'perfil_alvo' => (string)($usuario['perfil'] ?? ''),
    ]);

    security_json([
        "success" => true,
        "message" => "Usuario inativado com sucesso!"
    ]);
} catch (Throwable $e) {
    error_log('super_admin_usuario_deletar: ' . $e->getMessage());
    security_json(["success" => false, "message" => "Erro ao inativar usuario."], 500);
}
