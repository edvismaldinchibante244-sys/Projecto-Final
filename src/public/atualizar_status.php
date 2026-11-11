<?php
require_once __DIR__ . '/app_base.php';
require_once APP_BASE . '/src/config/auth_check.php';
/*
  Endpoint simples para KDS:
  Recebe item_id e marca como pronto.
  Reutiliza a lógica e validações do API oficial.
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

$_POST['status'] = 'pronto';
include __DIR__ . '/api/pedido_item_status.php';

