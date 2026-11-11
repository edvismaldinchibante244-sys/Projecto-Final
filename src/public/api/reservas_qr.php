<?php

/**
 * API para gerar sessoes QR de reserva
 * POST JSON: {mesa_id, expira_em_minutos, nome_atendente, telefone_cliente, observacoes}
 */

require_once __DIR__ . '/../app_base.php';
require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/reserva_qr_schema.php';

header('Content-Type: application/json; charset=utf-8');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    security_json(['success' => false, 'message' => 'Metodo nao permitido.'], 405);
}

$ctx = api_guard([
    'roles' => ['ADMIN', 'GERENTE', 'GARCOM'],
    'rate_key' => 'reservas_qr',
    'rate_max' => 30,
    'rate_window' => 60,
]);

$restauranteId = (int)$ctx['restaurante_id'];

try {
    $db = (new \Database())->getConnection();
    reserva_qr_ensure_tables($db);
    reserva_qr_cleanup($db);

    $rawInput = function_exists('pentest_harness_read_raw_input')
        ? pentest_harness_read_raw_input()
        : (string)file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: [];
    $tipoQr = strtolower(trim((string)($input['tipo_qr'] ?? 'reserva')));
    $observacoes = security_clean_string($input['observacoes'] ?? '', 255);
    if ($tipoQr === 'reserva' || $tipoQr === 'checkin') {
        $observacoes = trim('reserva_qr_checkin ' . $observacoes);
    }

    $dados = [
        'mesa_id' => !empty($input['mesa_id']) ? (int)$input['mesa_id'] : null,
        'expira_em_minutos' => (int)($input['expira_em_minutos'] ?? 15),
        'nome_atendente' => security_clean_string($input['nome_atendente'] ?? '', 120),
        'telefone_cliente' => security_clean_string($input['telefone_cliente'] ?? '', 30),
        'observacoes' => $observacoes,
    ];

    $resultado = reserva_qr_create_session($db, $restauranteId, $dados);
    if (!empty($resultado['success'])) {
        security_audit_event('reserva_qr_sessao_criada', [
            'mesa_id' => (int)($dados['mesa_id'] ?? 0),
        ]);
    }

    security_json($resultado, !empty($resultado['success']) ? 201 : 400);
} catch (\Throwable $e) {
    error_log('[RESERVAS_QR][ERROR] ' . $e->getMessage());
    security_json([
        'success' => false,
        'message' => 'Falha interna ao gerar a sessao QR.',
    ], 500);
}
