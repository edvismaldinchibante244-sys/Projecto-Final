<?php
require_once __DIR__ . '/../config/internal_api.php';
internal_api_deny_direct_http_execution(__FILE__);

// API publica para clientes fazerem reservas pela plataforma centralizada
// Endpoint: /src/api/reserva_publica.php

require_once __DIR__ . '/reserva_publica_handler.php';

header('Content-Type: application/json; charset=utf-8');

$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$route = trim((string)($_GET['route'] ?? ''), '/');

if ($requestMethod === 'GET' && $route === 'disponibilidade') {
    $resultado = consultar_disponibilidade_publica($_GET);
    $httpCode = (int)($resultado['http_code'] ?? 200);
    unset($resultado['http_code']);

    http_response_code($httpCode);
    echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($requestMethod === 'GET' && $route === 'cardapio') {
    $resultado = consultar_cardapio_publico($_GET);
    $httpCode = (int)($resultado['http_code'] ?? 200);
    unset($resultado['http_code']);

    http_response_code($httpCode);
    echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($requestMethod === 'GET' && $route === 'acompanhar_pedido') {
    $resultado = acompanhar_pedido_qr_publico($_GET);
    $httpCode = (int)($resultado['http_code'] ?? 200);
    unset($resultado['http_code']);
    http_response_code($httpCode);
    echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($requestMethod !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo nao permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data) || empty($data)) {
    $data = $_POST;
}

if (!is_array($data) || empty($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Dados invalidos.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($route === 'pedido_qr') {
    $resultado = criar_pedido_qr_publico($data);
    $httpCode = (int)($resultado['http_code'] ?? reserva_publica_http_code($resultado));
    unset($resultado['http_code']);

    http_response_code($httpCode);
    echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
    exit;
}

$resultado = criar_reserva_publica($data);
$httpCode = (int)($resultado['http_code'] ?? reserva_publica_http_code($resultado));
unset($resultado['http_code']);

http_response_code($httpCode);
echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
