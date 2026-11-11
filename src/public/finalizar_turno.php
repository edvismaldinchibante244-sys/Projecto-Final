<?php
require_once __DIR__ . '/app_base.php';
require_once APP_BASE . '/src/config/auth_check.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/turno_helpers.php';
require_once APP_BASE . '/src/Service/TurnoService.php';

$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$restauranteId = (int)($_SESSION['restaurante_id'] ?? ($_SESSION['restaurante_base_id'] ?? 0));

if ($usuarioId <= 0 || $restauranteId <= 0) {
    header('Location: index.php');
    exit;
}

try {
    $database = new Database();
    $turnoService = new TurnoService($database);
    
    // Encerra o turno do usuário
    $resultado = $turnoService->encerrarTurno($usuarioId, $restauranteId);
    
    if (!empty($resultado['success'])) {
        // Log da finalização
        error_log('[Turno] Turno finalizado pelo usuário ' . $usuarioId);
        
        // Destroi sessão
        session_destroy();
        
        // Redireciona para login
        header('Location: index.php?turno_finalizado=1');
        exit;
    } else {
        // Se falhar, volta para a página anterior
        $_SESSION['turno_flash_msg'] = $resultado['message'] ?? 'Erro ao finalizar turno.';
        $_SESSION['turno_flash_type'] = 'danger';
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'dashboard.php'));
        exit;
    }
} catch (Throwable $e) {
    error_log('[finalizar_turno] Erro: ' . $e->getMessage());
    $_SESSION['turno_flash_msg'] = 'Erro ao finalizar turno: ' . $e->getMessage();
    $_SESSION['turno_flash_type'] = 'danger';
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'dashboard.php'));
    exit;
}
