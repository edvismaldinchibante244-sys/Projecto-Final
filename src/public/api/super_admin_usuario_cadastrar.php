<?php
require_once __DIR__ . '/../app_base.php';

/**
   API - Super Admin Cadastrar Usuario
   Cadastra um novo usuario para um restaurante especifico
 */

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/super_admin_permissions.php';
require_once APP_BASE . '/src/config/audit.php';
require_once APP_BASE . '/src/config/password_policy.php';

header('Content-Type: application/json; charset=utf-8');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    security_json(["success" => false, "message" => "Metodo nao permitido"], 405);
}

api_guard([
    'rate_key' => 'super_admin_usuario_cadastrar',
    'rate_max' => 30,
    'rate_window' => 60,
    'skip_turno' => true,
]);

if (!isset($_SESSION['super_admin']) || (int)$_SESSION['super_admin'] !== 1) {
    security_json(["success" => false, "message" => "Acesso negado"], 403);
}

super_admin_require_permission_json('manage_users');

$restaurante_id = (int)($_POST['restaurante_id'] ?? 0);
$nome = trim((string)($_POST['nome'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$senha = (string)($_POST['senha'] ?? '');
$perfil = strtoupper(trim((string)($_POST['perfil'] ?? 'GARCOM')));
$perfil_alias = [
    'OPERADOR' => 'GARCOM',
    'COZINHA' => 'GARCOM',
    'GARCOM' => 'GARCOM',
    'FUNCIONARIO' => 'GARCOM'
];
$perfil = $perfil_alias[$perfil] ?? $perfil;

if ($restaurante_id <= 0) {
    security_json(["success" => false, "message" => "ID do restaurante invalido"], 422);
}
if ($nome === '') {
    security_json(["success" => false, "message" => "Nome e obrigatorio"], 422);
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    security_json(["success" => false, "message" => "Email invalido"], 422);
}
if ($senha === '') {
    security_json(["success" => false, "message" => "Senha e obrigatoria"], 422);
}
$pwdCheck = password_policy_validate($senha, [
    'email' => $email,
    'nome' => $nome,
    'perfil' => $perfil,
]);
if (!$pwdCheck['ok']) {
    security_json(["success" => false, "message" => $pwdCheck['message']], 422);
}
if (!in_array($perfil, ['ADMIN', 'CAIXA', 'GARCOM'], true)) {
    security_json(["success" => false, "message" => "Perfil invalido"], 422);
}

$database = new Database();
$db = $database->getConnection();
if (!$db) {
    security_json(["success" => false, "message" => "Erro de conexao"], 500);
}

$emailLookup = personal_data_email_lookup($email);
$emailEncrypted = personal_data_encrypt($email);
$stmt = $db->prepare("SELECT id FROM usuarios WHERE email_lookup = ? AND restaurante_id = ?");
$stmt->execute([$emailLookup, $restaurante_id]);
if ($stmt->fetch()) {
    security_json(["success" => false, "message" => "Email ja esta em uso neste restaurante"], 409);
}

$stmt = $db->prepare("SELECT id, nome FROM restaurantes WHERE id = ?");
$stmt->execute([$restaurante_id]);
$restaurante = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$restaurante) {
    security_json(["success" => false, "message" => "Restaurante nao encontrado"], 404);
}

try {
    $senha_hash = password_hash($senha, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);

    $stmt = $db->prepare("INSERT INTO usuarios (restaurante_id, nome, email, email_lookup, senha, perfil, ativo, created_at, two_factor_email) VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), ?)");
    $stmt->execute([$restaurante_id, $nome, $emailEncrypted, $emailLookup, $senha_hash, $perfil, $emailEncrypted]);
    $novoUsuarioId = (int)$db->lastInsertId();

    audit_log($db, 'super_admin_usuario_cadastrado', [
        'usuario_alvo_id' => $novoUsuarioId,
        'restaurante_id' => $restaurante_id,
        'perfil' => $perfil,
    ]);

    security_json([
        "success" => true,
        "message" => "Usuario cadastrado com sucesso!"
    ]);
} catch (Throwable $e) {
    error_log('super_admin_usuario_cadastrar: ' . $e->getMessage());
    security_json(["success" => false, "message" => "Erro ao cadastrar usuario."], 500);
}
