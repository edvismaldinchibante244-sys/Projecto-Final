<?php
require_once __DIR__ . '/../app_base.php';

include_once APP_BASE . '/src/config/api_guard.php';
include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/filiais_helper.php';

header('Content-Type: application/json; charset=utf-8');

api_guard([
    'roles' => ['ADMIN'],
    'rate_key' => 'filial_criar',
    'rate_max' => 20,
    'rate_window' => 60,
]);

try {
    $db = (new Database())->getConnection();
    $contexto = filiais_obter_contexto(
        $db,
        (int)($_SESSION['restaurante_id'] ?? 0),
        (int)($_SESSION['matriz_id'] ?? 0)
    );
    filiais_exigir_matriz($contexto);

    if (empty($contexto['tem_multi_filial'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Seu plano atual nao possui acesso a multi-filial.',
        ]);
        exit;
    }

    $resultado = filiais_criar($db, (int)$contexto['matriz_id'], $_POST);
    echo json_encode($resultado);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
} catch (RuntimeException $e) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[FILIAIS][CRIAR][ERROR] ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Nao foi possivel criar a filial agora. Tente novamente.',
    ]);
}

