<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Alterar Senha do Usuário
 */

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/audit.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/restaurante_context.php';
require_once APP_BASE . '/src/config/password_policy.php';
require_once APP_BASE . '/src/Model/Auth.php';

header('Content-Type: application/json');

api_guard([
    'roles' => ['ADMIN'],
    'rate_key' => 'usuario_senha',
    'rate_max' => 20,
    'rate_window' => 60,
    'skip_turno' => true,
]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $restauranteId = session_restaurante_contexto_id();

    if ($restauranteId <= 0 || $_SESSION['perfil'] !== 'ADMIN') {
        echo json_encode(array("success" => false, "message" => "Sem permissão"));
        exit;
    }

    if (empty($_POST['usuario_id']) || empty($_POST['nova_senha'])) {
        echo json_encode(array("success" => false, "message" => "Dados incompletos"));
        exit;
    }

    $pwdCheck = password_policy_validate((string)$_POST['nova_senha'], [
        'privileged' => true,
    ]);
    if (!$pwdCheck['ok']) {
        echo json_encode(array("success" => false, "message" => $pwdCheck['message']));
        exit;
    }

    $database = new Database();
    $db = $database->getConnection();

    $id = intval($_POST['usuario_id']);
    resource_require_usuario($db, $id, $restauranteId, ['id']);

    $auth = new Auth($db);

    if ($auth->atualizarSenha($id, $_POST['nova_senha'])) {
        audit_log($db, 'usuario_senha_alterada', ['usuario_alvo_id' => $id]);
        echo json_encode(array("success" => true, "message" => "Senha alterada com sucesso!"));
    } else {
        echo json_encode(array("success" => false, "message" => "Erro ao alterar senha"));
    }
} else {
    echo json_encode(array("success" => false, "message" => "Método não permitido"));
}
