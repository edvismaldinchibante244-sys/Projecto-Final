<?php
require_once __DIR__ . '/../app_base.php';
require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/presenca_online.php';

header('Content-Type: application/json; charset=utf-8');

api_guard([
    'roles' => ['ADMIN', 'GERENTE', 'GARCOM', 'CAIXA', 'COZINHA', 'BAR'],
    'rate_key' => 'online_ping',
    'rate_max' => 120,
    'rate_window' => 60,
    'skip_turno' => true,
    'skip_csrf' => true,
]);

try {
    $usuarioId = intval($_SESSION['usuario_id'] ?? 0);
    $restauranteId = intval($_SESSION['restaurante_id'] ?? 0);
    $isSuperAdmin = intval($_SESSION['super_admin'] ?? 0) === 1;

    if ($usuarioId <= 0 || $restauranteId <= 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sessao invalida.']);
        exit;
    }

    if ($isSuperAdmin) {
        echo json_encode(['success' => true, 'timestamp' => date('Y-m-d H:i:s'), 'ignored' => true]);
        exit;
    }

    $db = (new Database())->getConnection();
    if (!$db instanceof PDO || !presenca_ping_usuario($db, $usuarioId, $restauranteId)) {
        throw new RuntimeException('Falha ao registrar presenca.');
    }

    $_SESSION['last_presence_ping'] = time();

    echo json_encode(['success' => true, 'timestamp' => date('Y-m-d H:i:s')]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Falha ao atualizar presenca.']);
}

