<?php

require_once __DIR__ . '/../config/internal_api.php';
internal_api_deny_direct_http_execution(__FILE__);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../Model/Reserva.php';
require_once __DIR__ . '/../Service/ReservaService.php';

header('Content-Type: application/json; charset=utf-8');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo nao permitido.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data) || empty($data)) {
    $data = $_POST;
}

if (!is_array($data) || empty($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Dados invalidos.']);
    exit;
}

if (!isset($data['mesa_atribuida']) && !empty($data['mesa_id'])) {
    $data['mesa_atribuida'] = $data['mesa_id'];
}

try {
    $db = (new Database())->getConnection();
    $modelReserva = new \App\Model\Reserva($db);
    $serviceReserva = new \App\Service\ReservaService($db, $modelReserva);

    $resultado = $serviceReserva->criarReserva($data);
    if (!$resultado['success']) {
        http_response_code(($resultado['code'] ?? '') === 'SEM_DISPONIBILIDADE' ? 409 : 422);
    }

    echo json_encode($resultado);
} catch (\InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (\Throwable $e) {
    http_response_code(500);
    error_log('[RESERVAS][CLIENTE][ERROR] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno ao criar a reserva.']);
}
