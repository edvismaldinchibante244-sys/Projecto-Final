<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Redefinir Senha com Token
*/

error_reporting(E_ALL);
ini_set('display_errors', 0);

if (ob_get_level() === 0) {
    ob_start();
}

include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/password_reset_helper.php';
include_once APP_BASE . '/src/config/password_policy.php';
include_once APP_BASE . '/src/config/security.php';
include_once APP_BASE . '/src/config/audit.php';
include_once APP_BASE . '/src/Model/Auth.php';

security_set_headers();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > (64 * 1024)) {
    responderJsonRedefinicao(["success" => false, "message" => "Payload muito grande."], 413);
}

function responderJsonRedefinicao(array $payload, int $statusCode = 200): void
{
    if (ob_get_length()) {
        ob_clean();
    }

    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderJsonRedefinicao(["success" => false, "message" => "Método não permitido."], 405);
}

if (!security_rate_limit('password_reset_consume', 8, 600)) {
    security_log_event('password_reset_consume_rate_limited');
    responderJsonRedefinicao(["success" => false, "message" => "Muitas tentativas. Solicite um novo link."], 429);
}

$token = security_clean_string($_POST['token'] ?? '', 255);
$nova_senha = security_clean_string($_POST['nova_senha'] ?? '', 1024);
$confirmar_senha = security_clean_string($_POST['confirmar_senha'] ?? '', 1024);

if ($token === '') {
    responderJsonRedefinicao(["success" => false, "message" => "Link de recuperação inválido ou expirado."]);
}

$pwdCheck = password_policy_validate($nova_senha, ['privileged' => true]);
if (!$pwdCheck['ok']) {
    responderJsonRedefinicao(["success" => false, "message" => $pwdCheck['message']]);
}

if ($nova_senha !== $confirmar_senha) {
    responderJsonRedefinicao(["success" => false, "message" => "As senhas não conferem."]);
}

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    responderJsonRedefinicao(["success" => false, "message" => "Erro de conexão ao banco de dados."], 500);
}

try {
    $db->beginTransaction();

    $reset = password_reset_find_valid_token($db, $token, true);
    if (!$reset) {
        $db->rollBack();
        audit_log($db, 'password_reset_invalid_token', [], 'WARN');
        responderJsonRedefinicao(["success" => false, "message" => "Link de recuperação inválido ou expirado."]);
    }


    $userIdToken = isset($reset['user_id']) ? (int)$reset['user_id'] : 0;
    if ($userIdToken > 0) {
        $stmt = $db->prepare("SELECT id, super_admin, restaurante_id FROM usuarios WHERE id = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$userIdToken]);
    } else {
        $stmt = $db->prepare("SELECT id, super_admin, restaurante_id FROM usuarios WHERE email_lookup = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([personal_data_email_lookup((string)$reset['email'])]);
    }
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        $db->rollBack();
        audit_log($db, 'password_reset_user_missing', [
            'reset_id' => (int)$reset['id'],
        ], 'WARN');
        responderJsonRedefinicao(["success" => false, "message" => "Link de recuperação inválido ou expirado."]);
    }

    // Bloquear redefinição de senha para super admin
    if (isset($usuario['super_admin']) && intval($usuario['super_admin']) === 1) {
        $db->rollBack();
        audit_log($db, 'password_reset_super_admin_blocked', [
            'usuario_id' => (int)$usuario['id'],
        ], 'CRITICAL');
        responderJsonRedefinicao(["success" => false, "message" => "Não é permitido redefinir a senha do super admin por este método."]);
    }

    $auth = new Auth($db);
    if (!$auth->atualizarSenha((int)$usuario['id'], $nova_senha)) {
        throw new RuntimeException('Falha ao atualizar senha via token.');
    }

    if ((int)($usuario['super_admin'] ?? 0) !== 1) {
        $restauranteId = (int)($usuario['restaurante_id'] ?? 0);

        $stmtAtivarUsuario = $db->prepare("UPDATE usuarios SET ativo = 1 WHERE id = ?");
        $stmtAtivarUsuario->execute([(int)$usuario['id']]);

        if ($restauranteId > 0) {
            $stmtAtivarRestaurante = $db->prepare("UPDATE restaurantes SET status = 'ATIVO' WHERE id = ?");
            $stmtAtivarRestaurante->execute([$restauranteId]);
        }
    }

    password_reset_mark_used($db, (int)$reset['id']);
    password_reset_invalidate_email_tokens($db, (string)$reset['email']);

    $db->commit();
    audit_log($db, 'password_reset_completed', [
        'usuario_id' => (int)$usuario['id'],
        'restaurante_id' => (int)($usuario['restaurante_id'] ?? 0),
    ]);

    responderJsonRedefinicao(["success" => true, "message" => "Senha redefinida com sucesso! Agora você pode fazer login."]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('Redefinir senha API Error: ' . $e->getMessage());
    responderJsonRedefinicao(["success" => false, "message" => "Não foi possível redefinir a senha. Solicite um novo link."], 500);
}
