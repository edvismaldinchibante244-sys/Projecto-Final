<?php
require_once __DIR__ . '/app_base.php';

/*
   PROCESSAMENTO DE LOGOUT

*/

require_once APP_BASE . '/src/config/security.php';
require_once APP_BASE . '/src/config/terminal_pin.php';
security_start_session();

include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/presenca_online.php';
include_once APP_BASE . '/src/config/turno_helpers.php';
include_once APP_BASE . '/src/Service/TurnoService.php';

$usuarioId = intval($_SESSION['usuario_id'] ?? 0);
$restauranteId = intval($_SESSION['restaurante_id'] ?? 0);
$perfilSessao = strtoupper(trim((string)($_SESSION['perfil'] ?? '')));
if ($perfilSessao === 'GARÇOM') {
    $perfilSessao = 'GARCOM';
}

if ($usuarioId > 0 && $restauranteId > 0) {
    try {
        $db = (new Database())->getConnection();
        if ($db instanceof PDO) {
            presenca_marcar_usuario_offline($db, $usuarioId, $restauranteId);
        }
    } catch (Throwable $e) {
        // Logout nao deve falhar se a atualizacao de presenca falhar.
    }
}

// Quem sai de proposito tem de voltar a entrar com email e palavra-passe neste navegador.
try {
    require_once APP_BASE . '/src/config/sessao_retomar.php';
    sr_apagar_atual(isset($db) && $db instanceof PDO ? $db : (new Database())->getConnection());
} catch (Throwable $e) {
}

// Destruir todas as variáveis de sessão
$_SESSION = array();

// Destruir a sessão
session_destroy();

// Redirecionar para login
// Num terminal do restaurante volta ao ecra de bloqueio; nos outros dispositivos, ao login.
header('Location: ' . terminal_destino_sem_sessao());
exit;
