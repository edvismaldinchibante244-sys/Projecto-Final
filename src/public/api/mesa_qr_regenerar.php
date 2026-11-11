<?php
require_once __DIR__ . '/../app_base.php';
require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/reserva_qr_schema.php';

header('Content-Type: application/json; charset=utf-8');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    security_json(['success' => false, 'message' => 'Metodo nao permitido.'], 405);
}

$ctx = api_guard([
    'roles' => ['ADMIN', 'GERENTE'],
    'rate_key' => 'mesa_qr_regenerar',
    'rate_max' => 20,
    'rate_window' => 60,
]);

$restauranteId = (int)($ctx['restaurante_id'] ?? 0);

try {
    $rawInput = function_exists('pentest_harness_read_raw_input')
        ? pentest_harness_read_raw_input()
        : (string)file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $mesaId = (int)($input['mesa_id'] ?? $input['id'] ?? 0);
    if ($restauranteId <= 0 || $mesaId <= 0) {
        security_json(['success' => false, 'message' => 'Mesa invalida.'], 422);
    }

    $db = (new Database())->getConnection();
    reserva_qr_ensure_tables($db);
    resource_require_mesa($db, $mesaId, $restauranteId, ['id']);

    reserva_qr_revoke_cardapio_mesa($db, $restauranteId, $mesaId);
    $url = reserva_qr_get_or_create_cardapio_mesa_url($db, $restauranteId, $mesaId);

    if (!$url) {
        security_json(['success' => false, 'message' => 'Nao foi possivel gerar novo QR.'], 500);
    }

    security_audit_event('mesa_qr_regenerado', [
        'mesa_id' => $mesaId,
        'restaurante_id' => $restauranteId,
    ]);

    security_json([
        'success' => true,
        'message' => 'QR regenerado com sucesso.',
        'url' => $url,
    ]);
} catch (Throwable $e) {
    error_log('[MESA_QR_REGENERAR] ' . $e->getMessage());
    security_json(['success' => false, 'message' => 'Erro interno ao regenerar QR.'], 500);
}
