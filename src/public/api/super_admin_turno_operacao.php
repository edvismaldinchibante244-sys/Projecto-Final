<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/super_admin_permissions.php';
require_once APP_BASE . '/src/config/turno_helpers.php';
require_once APP_BASE . '/src/Service/TurnoService.php';

header('Content-Type: application/json; charset=utf-8');

api_guard([
    'rate_key' => 'super_admin_turno_operacao',
    'rate_max' => 30,
    'rate_window' => 60,
    'skip_turno' => true,
]);

$isSuperAdmin = isset($_SESSION['logado'], $_SESSION['super_admin'])
    && $_SESSION['logado'] === true
    && intval($_SESSION['super_admin']) === 1;

if (!$isSuperAdmin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acesso negado']);
    exit;
}

super_admin_require_permission_json('manage_users');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo nao permitido']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = $_POST;
}

$acao = strtolower(trim((string)($data['acao'] ?? '')));
$restauranteId = (int)($data['restaurante_id'] ?? 0);
$funcionarioId = (int)($data['funcionario_id'] ?? 0);
$motivo = trim((string)($data['motivo'] ?? ''));

if ($restauranteId <= 0 || $funcionarioId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Parametros invalidos.']);
    exit;
}

$contexto = [
    'usuario_id' => (int)($_SESSION['usuario_id'] ?? 0),
    'restaurante_id' => $restauranteId,
    'perfil' => 'ADMIN',
];

try {
    $turnoService = new TurnoService(new Database());
    switch ($acao) {
        case 'abrir_manual':
            $resultado = $turnoService->abrirTurnoManual($contexto, $funcionarioId, $motivo, $data['turno'] ?? null);
            break;
        case 'fechar_manual':
            $resultado = $turnoService->fecharTurnoManual($contexto, $funcionarioId, $motivo);
            break;
        default:
            http_response_code(400);
            $resultado = ['success' => false, 'message' => 'Acao invalida.'];
            break;
    }

    echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro ao processar operacao de turno.'], JSON_UNESCAPED_UNICODE);
}
