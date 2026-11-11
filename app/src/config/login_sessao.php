<?php
/**
 * Cria a sessao autenticada. Partilhado entre o login normal (email + palavra-passe)
 * e a entrada por PIN num terminal do restaurante, para as duas sessoes serem iguais.
 */
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/audit.php';

if (!function_exists('login_api_complete_session')) {
    function login_api_complete_session(array $user, string $email, ?int $terminalId = null): string
    {
        require_once APP_BASE . '/src/config/super_admin_permissions.php';
        require_once APP_BASE . '/src/config/restaurante_context.php';
        require_once APP_BASE . '/src/config/turno_helpers.php';
        require_once APP_BASE . '/src/config/database.php';

        $ehSuperAdmin = !empty($user['super_admin']);
        if (empty($user['id']) || (!$ehSuperAdmin && empty($user['restaurante_id']))) {
            throw new RuntimeException('Dados do usuario ausentes na resposta de login.');
        }

        $restauranteIdSessao = !empty($user['restaurante_id']) ? (int)$user['restaurante_id'] : 0;
        $perfilSessao = turno_normalizar_perfil($user['perfil'] ?? 'USER');
        // "Manter sessao iniciada neste computador" (caixa no login): 12 h sem uso em vez de 45 min.
        $manterSessao = !$ehSuperAdmin && !$terminalId && (string)($_POST['manter'] ?? '') === '1';

        session_regenerate_id(true);
        unset(
            $_SESSION['pending_2fa'],
            $_SESSION['login_restaurante_temp'],
            $_SESSION['last_presence_ping'],
            $_SESSION['matriz_id'],
            $_SESSION['filial_id'],
            $_SESSION['filial_nome'],
            $_SESSION['restaurante_selecionado']
        );

        $_SESSION['logado'] = true;
        $_SESSION['usuario_id'] = (int)$user['id'];
        $_SESSION['restaurante_id'] = $restauranteIdSessao;
        $_SESSION['restaurante_base_id'] = $restauranteIdSessao;
        $_SESSION['nome'] = (string)($user['nome'] ?? 'Usuario');
        $_SESSION['email'] = (string)($user['email'] ?? $email);
        $_SESSION['perfil'] = $perfilSessao;
        $_SESSION['plano'] = (string)($user['plano'] ?? 'BASICO');
        $_SESSION['foto'] = (string)($user['foto'] ?? '');
        $_SESSION['super_admin'] = $ehSuperAdmin ? 1 : 0;
        $_SESSION['super_admin_permissions'] = $ehSuperAdmin ? super_admin_default_permissions() : [];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
        $_SESSION['terminal_id'] = $terminalId ?: null;
        if (!$terminalId) {
            unset($_SESSION['terminal_id']);
        }
        if ($manterSessao) {
            $_SESSION['manter_sessao'] = 1;
        } else {
            unset($_SESSION['manter_sessao']);
        }
        $_SESSION['last_activity'] = time();
        // Sessao em pausa: este navegador podera continuar com o PIN ou a palavra-passe durante 12 h
        // (tambem nos terminais: o ecra mostra quem estava e pede o PIN dessa pessoa).
        if (!$ehSuperAdmin && $restauranteIdSessao > 0) {
            try {
                require_once __DIR__ . '/sessao_retomar.php';
                sr_criar(Database::infra(), (int)$user['id'], $restauranteIdSessao, $manterSessao);
            } catch (Throwable $e) {
                error_log('sessao_retomar: ' . $e->getMessage());
            }
        }

        security_log_event($terminalId ? 'login_pin_success' : 'login_success', [
            'usuario_id' => (int)$user['id'],
            'terminal_id' => $terminalId,
            'restaurante_id' => $restauranteIdSessao,
            'perfil' => $perfilSessao,
        ]);
        audit_log(null, 'login_success', [
            'usuario_id' => (int)$user['id'],
            'restaurante_id' => $restauranteIdSessao,
            'perfil' => $perfilSessao,
            'super_admin' => $ehSuperAdmin,
        ]);

        if (function_exists('login_security_alert_if_new_context')) {
            @login_security_alert_if_new_context($user, $ehSuperAdmin);
        }

        if ($_SESSION['super_admin'] === 1) {
            return 'admin.php';
        }
        if (!empty($user['login_restaurante'])) {
            $_SESSION['login_restaurante_temp'] = true;
            return 'criar_primeiro_admin.php';
        }

        // Operacionais: se já têm turno activo → tela de trabalho; senão → iniciar turno.
        if (turno_usuario_exige_turno_ativo($perfilSessao)) {
            try {
                require_once APP_BASE . '/src/Service/TurnoService.php';
                $turnoService = new TurnoService(new Database());
                $turnoAtivo = $turnoService->obterTurnoAtivoUsuario(
                    (int)$user['id'],
                    $restauranteIdSessao
                );
                if (!empty($turnoAtivo)) {
                    return turno_home_por_perfil($perfilSessao);
                }
            } catch (Throwable $e) {
                error_log('login_api turno check: ' . $e->getMessage());
            }
            $_SESSION['login_goto_home'] = 1;
            return 'iniciar_turno.php';
        }

        return turno_home_por_perfil($perfilSessao);
    }
}
