<?php
require_once __DIR__ . '/../app_base.php';

/*
  API - Criar Primeiro Usuario Admin do Restaurante
  Usado apos login temporario com senha do restaurante
*/

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/audit.php';
require_once APP_BASE . '/src/config/password_policy.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['logado']) || !isset($_SESSION['login_restaurante_temp']) || $_SESSION['login_restaurante_temp'] !== true) {
    security_json(["success" => false, "message" => "Acesso nao autorizado"], 403);
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    security_json(["success" => false, "message" => "Metodo nao permitido"], 405);
}

api_guard([
    'rate_key' => 'criar_primeiro_admin',
    'rate_max' => 10,
    'rate_window' => 300,
    'skip_turno' => true,
]);

$nome = trim((string)($_POST['nome'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$senha = (string)($_POST['senha'] ?? '');

if ($nome === '' || $email === '' || $senha === '') {
    security_json(["success" => false, "message" => "Preencha todos os campos"], 422);
}
$pwdCheck = password_policy_validate($senha, [
    'email' => $email,
    'nome' => $nome,
    'perfil' => 'ADMIN',
    'privileged' => true,
]);
if (!$pwdCheck['ok']) {
    security_json(["success" => false, "message" => $pwdCheck['message']], 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    security_json(["success" => false, "message" => "Email invalido"], 422);
}
if ($email !== (string)($_SESSION['email'] ?? '')) {
    security_json(["success" => false, "message" => "Email nao corresponde ao restaurante"], 403);
}

$database = new Database();
$db = $database->getConnection();
if (!$db) {
    security_json(["success" => false, "message" => "Erro de conexao"], 500);
}

try {
    $db->beginTransaction();

    $stmt = $db->prepare("SELECT id FROM usuarios WHERE restaurante_id = ? AND perfil = 'ADMIN' LIMIT 1");
    $stmt->execute([(int)$_SESSION['restaurante_id']]);
    if ($stmt->rowCount() > 0) {
        security_json(["success" => false, "message" => "Ja existe um usuario admin para este restaurante"], 409);
    }

    $senha_hash = password_hash($senha, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
    $emailLookup = personal_data_email_lookup($email);
    $emailEncrypted = personal_data_encrypt($email);
    $stmt = $db->prepare("INSERT INTO usuarios (restaurante_id, nome, email, email_lookup, senha, perfil, ativo, criado_em, two_factor_email) VALUES (?, ?, ?, ?, ?, 'ADMIN', 1, NOW(), ?)");
    $stmt->execute([(int)$_SESSION['restaurante_id'], $nome, $emailEncrypted, $emailLookup, $senha_hash, $emailEncrypted]);
    $usuario_id = (int)$db->lastInsertId();

    $stmt = $db->prepare("UPDATE restaurantes SET senha_admin = NULL, senha_criada_em = NULL, senha_pode_alterar = 0 WHERE id = ?");
    $stmt->execute([(int)$_SESSION['restaurante_id']]);

    $db->commit();
    audit_log($db, 'primeiro_admin_criado', [
        'usuario_id' => $usuario_id,
        'restaurante_id' => (int)$_SESSION['restaurante_id'],
    ]);

    $_SESSION['usuario_id'] = $usuario_id;
    $_SESSION['nome'] = $nome;
    $_SESSION['perfil'] = 'ADMIN';
    $_SESSION['foto'] = '';
    unset($_SESSION['login_restaurante_temp']);

    security_json([
        "success" => true,
        "message" => "Usuario admin criado com sucesso! Redirecionando..."
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('criar_primeiro_admin: ' . $e->getMessage());
    security_json(["success" => false, "message" => "Erro ao criar usuario admin."], 500);
}
