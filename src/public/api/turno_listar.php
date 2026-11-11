<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/Service/TurnoService.php';

header('Content-Type: application/json; charset=utf-8');

$ctx = api_guard([
    'roles' => ['ADMIN', 'GERENTE'],
    'rate_key' => 'turno_listar',
    'rate_max' => 120,
    'rate_window' => 60,
    'skip_turno' => true,
    'skip_csrf' => true,
]);

try {
    $turnoService = new TurnoService(new Database());
    $filtroData = trim((string)($_GET['data'] ?? ''));
    $data = $filtroData === '' ? null : $filtroData;

    if ($data === 'hoje') {
        $data = date('Y-m-d');
    } elseif ($data === 'amanha') {
        $data = date('Y-m-d', strtotime('+1 day'));
    } elseif ($data === 'semana') {
        $data = null;
    }

    $restauranteId = (int)$ctx['restaurante_id'];
    security_json([
        'success' => true,
        'turnos' => $turnoService->listarArray($restauranteId, $data),
        'ativos_hoje' => $turnoService->ativosHojeArray($restauranteId),
        'metricas' => $turnoService->obterMetricasDashboard($restauranteId),
        'auditoria' => $turnoService->listarAuditoria($restauranteId, 20),
    ]);
} catch (Throwable $e) {
    security_json([
        'success' => false,
        'message' => 'Erro ao carregar turnos.',
    ], 500);
}
