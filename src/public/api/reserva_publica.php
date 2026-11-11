<?php
require_once __DIR__ . '/../app_base.php';
require_once APP_BASE . '/src/config/security.php';
require_once APP_BASE . '/src/api/reserva_publica_handler.php';

header('Content-Type: application/json; charset=utf-8');
security_set_headers();

$dados  = $_REQUEST;

$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
if (strpos($contentType, 'application/json') !== false) {
    $rawBody = function_exists('pentest_harness_read_raw_input')
        ? pentest_harness_read_raw_input()
        : (string)file_get_contents('php://input');
    $raw = json_decode($rawBody, true);
    if (is_array($raw)) {
        $dados = array_merge($dados, $raw);
    }
}

$rawAction = strtolower(trim((string)($dados['action'] ?? $dados['route'] ?? '')));
$action = preg_replace('/[^a-z0-9_]/', '', $rawAction) ?? '';
$allowedActions = ['contexto_qr', 'prebloqueio', 'disponibilidade', 'cardapio', 'pedido_qr', 'acompanhar_pedido'];
if ($action !== '' && !in_array($action, $allowedActions, true)) {
    security_json(['success' => false, 'message' => 'Ação inválida.'], 400);
}

if (!security_rate_limit('reserva_publica_' . ($action !== '' ? $action : 'criar'), 90, 60)) {
    security_json(['success' => false, 'message' => 'Muitas requisicoes. Tente novamente em instantes.'], 429);
}

if (!empty($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > (256 * 1024)) {
    security_json(['success' => false, 'message' => 'Payload muito grande.'], 413);
}

switch ($action) {
    case 'contexto_qr':
        $resultado = consultar_contexto_qr_publico($dados);
        http_response_code((int)($resultado['http_code'] ?? 200));
        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
        break;

    case 'prebloqueio':
        $resultado = prebloquear_reserva_publica($dados);
        http_response_code((int)($resultado['http_code'] ?? 200));
        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
        break;

    case 'disponibilidade':
        $resultado = consultar_disponibilidade_publica($dados);
        http_response_code(reserva_publica_http_code($resultado));
        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
        break;

    case 'cardapio':
        $resultado = consultar_cardapio_publico($dados);
        http_response_code(!empty($resultado['success']) ? 200 : 422);
        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
        break;

    case 'pedido_qr':
        $resultado = criar_pedido_qr_publico($dados);
        http_response_code((int)($resultado['http_code'] ?? reserva_publica_http_code($resultado)));
        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
        break;

    case 'acompanhar_pedido':
        $resultado = acompanhar_pedido_qr_publico($dados);
        http_response_code((int)($resultado['http_code'] ?? 200));
        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
        break;

    default: // criar reserva
        $resultado = criar_reserva_publica($dados);
        http_response_code(reserva_publica_http_code($resultado));
        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
        break;
}
exit;
