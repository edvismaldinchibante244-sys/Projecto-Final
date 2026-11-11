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
    'rate_key' => 'filial_selecionar',
    'rate_max' => 20,
    'rate_window' => 60,
    'skip_turno' => true,
]);

$filialId = (int)($_POST['filial_id'] ?? 0);

try {
    $db = (new Database())->getConnection();
    $contexto = filiais_obter_contexto(
        $db,
        (int)($_SESSION['restaurante_id'] ?? 0),
        (int)($_SESSION['matriz_id'] ?? 0)
    );
    filiais_exigir_matriz($contexto);

    if (empty($contexto['tem_multi_filial'])) {
        header('Location: ../configuracoes.php?erro=plano_sem_multi_filial');
        exit;
    }

    $resultado = filiais_assumir_contexto($db, (int)$contexto['matriz_id'], $filialId);
    if (!$resultado['success']) {
        security_log_event('filial_select_denied', [
            'matriz_id' => (int)$contexto['matriz_id'],
            'filial_id' => $filialId,
            'message' => $resultado['message'],
        ]);
        header('Location: ../filiais.php?tipo=error&msg=' . urlencode($resultado['message']));
        exit;
    }

    security_audit_event('filial_contexto_ativado', [
        'matriz_id' => (int)$contexto['matriz_id'],
        'filial_id' => $filialId,
    ]);
    header('Location: ../dashboard.php?msg=filial_selecionada');
    exit;
} catch (Throwable $e) {
    error_log('[FILIAIS][SELECIONAR][ERROR] ' . $e->getMessage());
    header('Location: ../filiais.php?tipo=error&msg=' . urlencode('Nao foi possivel acessar a filial agora.'));
    exit;
}
