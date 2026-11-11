<?php
require_once __DIR__ . '/../app_base.php';

header('Content-Type: application/json');

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/audit.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/restaurante_context.php';

api_guard([
    'roles' => ['ADMIN'],
    'rate_key' => 'usuario_reativar',
    'rate_max' => 30,
    'rate_window' => 60,
    'skip_turno' => true,
]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    security_json(['success' => false, 'message' => 'Metodo invalido.'], 405);
}

$restauranteId = session_restaurante_contexto_id();
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    security_json(['success' => false, 'message' => 'Utilizador invalido.'], 422);
}

try {
    $db = (new Database())->getConnection();
    $usuario = resource_require_usuario($db, $id, $restauranteId, ['id', 'nome', 'email', 'perfil']);

    $stmt = $db->prepare("
        UPDATE usuarios
        SET ativo = 1, tentativas_login = 0, bloqueado_ate = NULL
        WHERE id = :id AND restaurante_id = :rid AND ativo = 0
    ");
    $stmt->execute([':id' => $id, ':rid' => (int)$restauranteId]);

    if ($stmt->rowCount() > 0) {
        audit_log($db, 'usuario_reativado', [
            'usuario_alvo_id' => $id,
            'usuario_alvo_email' => (string)($usuario['email'] ?? ''),
            'perfil_alvo' => (string)($usuario['perfil'] ?? ''),
        ]);
        security_json(['success' => true, 'message' => 'Acesso reactivado com sucesso.']);
    }

    security_json(['success' => false, 'message' => 'Este utilizador ja esta activo.'], 409);
} catch (Throwable $e) {
    error_log('usuario_reativar: ' . $e->getMessage());
    security_json(['success' => false, 'message' => 'Nao foi possivel reactivar o utilizador.'], 500);
}
