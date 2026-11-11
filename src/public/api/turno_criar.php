<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/Service/TurnoService.php';

header('Content-Type: application/json; charset=utf-8');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    security_json(['success' => false, 'message' => 'POST apenas.'], 405);
}

$ctx = api_guard([
    'roles' => ['ADMIN', 'GERENTE', 'CAIXA'],
    'rate_key' => 'turno_criar',
    'rate_max' => 30,
    'rate_window' => 60,
    'skip_turno' => true,
]);

$data = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = $_POST;
}

$data['restaurante_id'] = (int)$ctx['restaurante_id'];
$data['responsavel_id'] = (int)$ctx['usuario_id'];
$data['manual'] = 1;

try {
    $turnoService = new TurnoService(new Database());
    security_json($turnoService->criarTurno($data));
} catch (Throwable $e) {
    security_json(['success' => false, 'message' => 'Erro interno ao criar turno.'], 500);
}
