<?php
// Função utilitária para checar turno ativo do usuário logado
function dashboard_usuario_possui_turno_ativo($db, $usuario_id, $restaurante_id)
{
    require_once APP_BASE . '/src/Service/TurnoService.php';
    require_once APP_BASE . '/src/config/restaurante_context.php';
    // Cria um wrapper Database temporário compatível
    $DatabaseWrapper = new class($db) {
        private $conn;
        public function __construct($pdo)
        {
            $this->conn = $pdo;
        }
        public function getConnection()
        {
            return $this->conn;
        }
    };
    $turnoService = new TurnoService($DatabaseWrapper);
    $turnoRestauranteId = function_exists('session_restaurante_auth_id') ? session_restaurante_auth_id() : (int)$restaurante_id;
    return $turnoService->obterTurnoAtivoUsuario((int)$usuario_id, $turnoRestauranteId) !== null;
}

// DEBUG: Diagnóstico de chamada do helper
if (!function_exists('dashboard_turno_debug')) {
    function dashboard_turno_debug($db, $usuario_id, $restaurante_id) {
        try {
            $DatabaseWrapper = new class($db) {
                private $conn;
                public function __construct($pdo) { $this->conn = $pdo; }
                public function getConnection() { return $this->conn; }
            };
            $turnoService = new TurnoService($DatabaseWrapper);
            $turno = $turnoService->obterTurnoAtivoUsuario((int)$usuario_id, (int)$restaurante_id);
            echo '<div style="background:#222;color:#fff;padding:8px 16px;margin:12px 0;border-radius:8px;font-size:15px;">';
            echo '<b>[DEBUG HELPER]</b> usuario_id: ' . htmlspecialchars($usuario_id) . ' | restaurante_id: ' . htmlspecialchars($restaurante_id) . ' | turno: ' . ($turno ? 'ATIVO' : 'NÃO ENCONTRADO');
            echo '</div>';
        } catch (Throwable $e) {
            echo '<div style="background:#b91c1c;color:#fff;padding:8px 16px;margin:12px 0;border-radius:8px;font-size:15px;">[ERRO HELPER] ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }
}
