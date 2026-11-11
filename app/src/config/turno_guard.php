<?php

require_once __DIR__ . '/turno_helpers.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/restaurante_context.php';
require_once __DIR__ . '/../Service/TurnoService.php';

if (!function_exists('turno_guard_is_api_request')) {
    function turno_guard_is_api_request(): bool
    {
        return strpos((string)($_SERVER['REQUEST_URI'] ?? ''), '/api/') !== false
            || isset($_SERVER['HTTP_X_REQUESTED_WITH']);
    }
}

if (!function_exists('turno_guard_script_key')) {
    function turno_guard_script_key(): string
    {
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $apiPos = strpos($script, '/api/');
        if ($apiPos !== false) {
            return ltrim(substr($script, $apiPos + 1), '/');
        }

        return basename($script);
    }
}

if (!function_exists('turno_guard_exempt_routes')) {
    function turno_guard_exempt_routes(): array
    {
        return [
            'index.php',
            'login_process.php',
            'logout.php',
            'iniciar_turno.php',
            'definir_pin.php',
            'terminais.php',
            'api/terminal.php',
            'api/pin.php',
            'criar_primeiro_admin.php',
            'api/login_process.php',
            'api/login_simples.php',
            'api/turno_operacao.php',
        ];
    }
}

if (!function_exists('turno_guard_usuario_tem_turno')) {
    function turno_guard_usuario_tem_turno(int $usuarioId, int $restauranteId): bool
    {
        if ($usuarioId <= 0 || $restauranteId <= 0) {
            return false;
        }

        $turnoService = new TurnoService(Database::comLigacaoInfra());
        return $turnoService->obterTurnoAtivoUsuario($usuarioId, $restauranteId) !== null;
    }
}

if (!function_exists('turno_guard_require_active')) {
    function turno_guard_require_active(array $options = []): void
    {
        if (!empty($_SESSION['super_admin']) || !empty($_SESSION['login_restaurante_temp'])) {
            return;
        }
        // Conta bloqueada ou cancelada: nao ha operacao, so exportacao de dados; exigir turno
        // criaria um ciclo de redireccionamentos (o turno tambem esta bloqueado).
        if ((($_SESSION['plano_lifecycle']['limite_nivel'] ?? '') === 'FULL_BLOCK')) {
            return;
        }

        $route = turno_guard_script_key();
        $exempt = array_merge(turno_guard_exempt_routes(), (array)($options['except'] ?? []));
        if (in_array($route, $exempt, true)) {
            return;
        }

        $perfil = turno_normalizar_perfil($_SESSION['perfil'] ?? '');
        if (!turno_usuario_exige_turno_ativo($perfil)) {
            return;
        }

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $restauranteId = function_exists('session_restaurante_auth_id')
            ? session_restaurante_auth_id()
            : (int)($_SESSION['restaurante_base_id'] ?? ($_SESSION['restaurante_id'] ?? 0));
        if (turno_guard_usuario_tem_turno($usuarioId, $restauranteId)) {
            return;
        }

        if (turno_guard_is_api_request()) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(403);
            }
            echo json_encode([
                'success' => false,
                'message' => 'É necessário iniciar o turno antes de usar o sistema.',
                'redirect' => 'iniciar_turno.php',
                'requires_turno' => true,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Location: iniciar_turno.php?erro=turno_obrigatorio');
        exit;
    }
}
