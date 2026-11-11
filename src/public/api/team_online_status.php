<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/presenca_online.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $ctx = api_guard([
        'roles' => ['ADMIN', 'GERENTE'],
        'rate_key' => 'team_online_status',
        'rate_max' => 120,
        'rate_window' => 60,
        'skip_turno' => true,
        'skip_csrf' => true,
    ]);

    $db = (new Database())->getConnection();
    $teamOnline = presenca_buscar_equipa_online($db, (int)$ctx['restaurante_id'], 12);

    echo json_encode([
        'success' => true,
        'online' => $teamOnline['online'],
        'total' => $teamOnline['total'],
        'equipa' => $teamOnline['equipa'],
        'updated_at' => date('Y-m-d H:i:s')
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Falha ao carregar equipe online.']);
}

