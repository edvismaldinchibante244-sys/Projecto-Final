<?php
require_once __DIR__ . '/../app_base.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/api/reserva_notificacoes.php';

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Metodo nao permitido.']);
    exit;
}

function notificar_cliente($reserva_id, $novo_status)
{
    $reservaId = (int)$reserva_id;
    $status = trim((string)$novo_status);
    if ($reservaId <= 0 || $status === '') {
        return false;
    }
    $db = (new Database())->getConnection();
    return reserva_notificacoes_enviar_status_cliente($db, $reservaId, $status);
}

