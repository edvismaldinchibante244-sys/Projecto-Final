<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - EDITAR RESTAURANTE
   Atualiza os dados de um restaurante
*/

require_once APP_BASE . '/src/config/api_guard.php';
include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/restaurante_status_helper.php';
include_once APP_BASE . '/src/config/super_admin_permissions.php';
include_once APP_BASE . '/src/config/audit.php';

header('Content-Type: application/json; charset=utf-8');

api_guard([
    'rate_key' => 'restaurante_editar',
    'rate_max' => 30,
    'rate_window' => 60,
    'skip_turno' => true,
]);

if (!super_admin_is_authenticated()) {
    security_json([
        'success' => false,
        'message' => 'Acesso negado'
    ], 403);
}

super_admin_require_permission_json('manage_restaurants');

$database = new Database();
$db = $database->getConnection();

$input = json_decode((string)file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$id = (int)($input['id'] ?? 0);
$nome = trim((string)($input['nome'] ?? ''));
$email = trim((string)($input['email'] ?? ''));
$telefone = trim((string)($input['telefone'] ?? ''));
$endereco = trim((string)($input['endereco'] ?? ''));
$cidade = trim((string)($input['cidade'] ?? ''));
$nuit = trim((string)($input['nuit'] ?? ''));
$plano = strtoupper(trim((string)($input['plano'] ?? 'BASICO')));
if ($plano === 'ENTERPRISE') {
    $plano = 'EMPRESARIAL';
}
$status = restaurante_status_normalizar($db, $input['status'] ?? '', 'ATIVO');

if ($id <= 0) {
    security_json([
        'success' => false,
        'message' => 'ID do restaurante invalido'
    ], 422);
}

if ($nome === '') {
    security_json([
        'success' => false,
        'message' => 'Nome do restaurante e obrigatorio'
    ], 422);
}

if ($email === '') {
    security_json([
        'success' => false,
        'message' => 'Email do restaurante e obrigatorio'
    ], 422);
}

$emailLookup = personal_data_email_lookup($email);
$check = $db->prepare("SELECT id FROM restaurantes WHERE email_lookup = ? AND id != ?");
$check->execute([$emailLookup, $id]);
if ($check->rowCount() > 0) {
    security_json([
        'success' => false,
        'message' => 'Email ja esta em uso por outro restaurante'
    ], 409);
}

$planos_validos = ['BASICO', 'PROFISSIONAL', 'EMPRESARIAL'];
if (!in_array($plano, $planos_validos, true)) {
    $plano = 'BASICO';
}

try {
    $query = "UPDATE restaurantes SET 
                nome = :nome, 
                email = :email, 
                email_lookup = :email_lookup,
                telefone = :telefone, 
                endereco = :endereco, 
                cidade = :cidade, 
                nuit = :nuit,
                nuit_lookup = :nuit_lookup,
                plano = :plano, 
                status = :status 
              WHERE id = :id";

    $stmt = $db->prepare($query);
    $stmt->execute([
        ':nome' => $nome,
        ':email' => personal_data_encrypt($email),
        ':email_lookup' => $emailLookup,
        ':telefone' => $telefone === '' ? null : personal_data_encrypt($telefone),
        ':endereco' => $endereco === '' ? null : personal_data_encrypt($endereco),
        ':cidade' => $cidade,
        ':nuit' => $nuit === '' ? null : personal_data_encrypt($nuit),
        ':nuit_lookup' => personal_data_nuit_lookup($nuit),
        ':plano' => $plano,
        ':status' => $status,
        ':id' => $id
    ]);

    audit_log($db, 'super_admin_restaurante_atualizado', [
        'restaurante_id' => $id,
        'plano' => $plano,
        'status' => $status,
    ]);

    security_json([
        'success' => true,
        'message' => 'Restaurante atualizado com sucesso!',
        'data' => [
            'id' => $id,
            'nome' => $nome,
            'email' => $email,
            'plano' => $plano,
            'status' => $status
        ]
    ]);
} catch (PDOException $e) {
    error_log('restaurante_editar: ' . $e->getMessage());
    security_json([
        'success' => false,
        'message' => 'Erro ao atualizar restaurante.'
    ], 500);
}
