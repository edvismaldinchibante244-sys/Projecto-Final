<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/filiais_helper.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Location: ../filiais.php?tipo=error&msg=' . urlencode('Sessao expirada. Recarregue a pagina e tente novamente.'));
    exit;
}

api_guard([
    'roles' => ['ADMIN'],
    'rate_key' => 'filial_voltar_matriz',
    'rate_max' => 20,
    'rate_window' => 60,
    'skip_turno' => true,
]);

try {
    $db = (new Database())->getConnection();
    $contexto = filiais_obter_contexto(
        $db,
        (int)($_SESSION['restaurante_id'] ?? 0),
        (int)($_SESSION['matriz_id'] ?? 0)
    );

    $resultado = filiais_retornar_para_matriz((int)$contexto['matriz_id']);
    if (!$resultado['success']) {
        security_log_event('filial_return_denied', [
            'matriz_id' => (int)$contexto['matriz_id'],
            'message' => $resultado['message'],
        ]);
        header('Location: ../filiais.php?tipo=error&msg=' . urlencode($resultado['message']));
        exit;
    }

    security_audit_event('filial_contexto_restaurado', [
        'matriz_id' => (int)$contexto['matriz_id'],
    ]);
    header('Location: ../filiais.php?tipo=success&msg=' . urlencode($resultado['message']));
    exit;
} catch (Throwable $e) {
    error_log('[FILIAIS][RETORNAR_MATRIZ][ERROR] ' . $e->getMessage());
    header('Location: ../filiais.php?tipo=error&msg=' . urlencode('Nao foi possivel restaurar o contexto da matriz.'));
    exit;
}
