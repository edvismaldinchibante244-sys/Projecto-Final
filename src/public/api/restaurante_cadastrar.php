<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Cadastrar Restaurante
*/

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/plano_lancamento.php';
include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/restaurante_status_helper.php';
include_once APP_BASE . '/src/config/super_admin_permissions.php';
include_once APP_BASE . '/src/config/audit.php';

header('Content-Type: application/json; charset=utf-8');

api_guard([
    'rate_key' => 'restaurante_cadastrar',
    'rate_max' => 30,
    'rate_window' => 60,
    'skip_turno' => true,
]);

if (!super_admin_is_authenticated()) {
    security_json(['success' => false, 'message' => 'Acesso negado'], 403);
}

super_admin_require_permission_json('manage_restaurants');

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    security_json(['success' => false, 'message' => 'Erro de conexao com banco de dados'], 500);
}

$data = json_decode((string)file_get_contents("php://input"), true);
if (!is_array($data) || empty($data)) {
    $data = $_POST;
}

$nome = isset($data['nome']) ? trim((string)$data['nome']) : '';
$email = isset($data['email']) ? trim((string)$data['email']) : '';
$telefone = isset($data['telefone']) ? trim((string)$data['telefone']) : '';
$endereco = isset($data['endereco']) ? trim((string)$data['endereco']) : '';

if ($nome === '' || $email === '') {
    security_json(['success' => false, 'message' => 'Nome e email sao obrigatorios'], 422);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    security_json(['success' => false, 'message' => 'Email invalido'], 422);
}

try {
    $emailLookup = personal_data_email_lookup($email);
    $emailEncrypted = personal_data_encrypt($email);
    $stmt = $db->prepare("SELECT id FROM restaurantes WHERE email_lookup = ? LIMIT 1");
    $stmt->execute([$emailLookup]);
    if ($stmt->fetch()) {
        security_json(['success' => false, 'message' => 'Este email ja esta cadastrado para um restaurante'], 409);
    }

    $stmt = $db->prepare("SELECT id FROM usuarios WHERE email_lookup = ? LIMIT 1");
    $stmt->execute([$emailLookup]);
    if ($stmt->fetch()) {
        security_json(['success' => false, 'message' => 'Este email ja esta cadastrado'], 409);
    }

    $senha_hash = password_hash(bin2hex(random_bytes(32)), defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);

    $db->beginTransaction();

    $statusInicial = restaurante_status_resolver_inicial($db);
    $restauranteCols = ['nome', 'email', 'email_lookup', 'telefone', 'endereco', 'created_at'];
    $restauranteVals = ['?', '?', '?', '?', '?', 'NOW()'];
    $restauranteParams = [
        $nome,
        $emailEncrypted,
        $emailLookup,
        $telefone === '' ? null : personal_data_encrypt($telefone),
        $endereco === '' ? null : personal_data_encrypt($endereco),
    ];

    if ($statusInicial !== null) {
        $restauranteCols[] = 'status';
        $restauranteVals[] = '?';
        $restauranteParams[] = $statusInicial;
    }

    $stmt = $db->prepare(
        'INSERT INTO restaurantes (' . implode(', ', $restauranteCols) . ') VALUES (' . implode(', ', $restauranteVals) . ')'
    );
    $stmt->execute($restauranteParams);
    $restaurante_id = (int)$db->lastInsertId();

    // Preco de lancamento: fica preso a este restaurante, para as renovacoes
    // seguirem o preco a que ele aderiu.
    lancamento_registar($db, $restaurante_id);

    $stmt = $db->prepare("INSERT INTO usuarios (restaurante_id, nome, email, email_lookup, senha, perfil, ativo, created_at, two_factor_email) VALUES (?, ?, ?, ?, ?, 'ADMIN', 0, NOW(), ?)");
    $stmt->execute([$restaurante_id, $nome, $emailEncrypted, $emailLookup, $senha_hash, $emailEncrypted]);
    $adminUsuarioId = (int)$db->lastInsertId();

    $db->commit();
    audit_log($db, 'restaurante_cadastrado', [
        'restaurante_id' => $restaurante_id,
        'admin_usuario_id' => $adminUsuarioId,
        'status' => $statusInicial,
    ]);

    security_json([
        'success' => true,
        'message' => 'Restaurante cadastrado com sucesso! Aguarde aprovacao.',
        'restaurante_id' => $restaurante_id,
        'status' => $statusInicial
    ]);
} catch (Throwable $e) {
    if ($db && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('restaurante_cadastrar: ' . $e->getMessage());
    security_json(['success' => false, 'message' => 'Erro ao cadastrar restaurante.'], 500);
}
