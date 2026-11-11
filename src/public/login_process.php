<?php
require_once __DIR__ . '/app_base.php';

/*
   Processamento de Login
   Arquitetura N-Tier
 */

include_once APP_BASE . '/src/config/security.php';
security_start_session();
security_set_headers();
security_regenerate_session(15);

// iniciar buffer para evitar qualquer saída antes do JSON
ob_start();

// garantir que sempre retorna JSON
header('Content-Type: application/json; charset=utf-8');

// impedir warnings de quebrar JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

try {

    // método POST obrigatório
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ob_clean();
        echo json_encode([
            "success" => false,
            "message" => "Método não permitido."
        ]);
        exit;
    }

    if (!security_rate_limit('login_public', 10, 300)) {
        ob_clean();
        security_log_event('login_rate_limited', ['endpoint' => 'public/login_process']);
        echo json_encode([
            "success" => false,
            "message" => "Muitas tentativas. Aguarde alguns minutos e tente novamente."
        ]);
        exit;
    }

    // capturar dados do formulário
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $senha = isset($_POST['senha']) ? $_POST['senha'] : '';

    if (empty($email) || empty($senha)) {
        ob_clean();
        echo json_encode([
            "success" => false,
            "message" => "Preencha todos os campos."
        ]);
        exit;
    }

    // caminho do controller (verifique a pasta real)
    $controllerPath = __DIR__ . '/../Controller/AuthController.php';

    if (!file_exists($controllerPath)) {
        throw new Exception("AuthController não encontrado.");
    }

    require_once $controllerPath;

    if (!class_exists('AuthController')) {
        throw new Exception("Classe AuthController não encontrada.");
    }

    // instanciar controller
    $controller = new AuthController();
    $resultado = $controller->login($email, $senha);

    if (!isset($resultado['success'])) {
        throw new Exception("Resposta inválida do AuthController.");
    }

    ob_clean(); // limpar qualquer saída antes de enviar JSON final

    if ($resultado['success']) {

        $user = $resultado['data'];
        session_regenerate_id(true);
        unset($_SESSION['login_restaurante_temp'], $_SESSION['last_presence_ping']);

        $_SESSION['logado'] = true;
        $_SESSION['usuario_id'] = $user['id'];
        $_SESSION['restaurante_id'] = $user['restaurante_id'];
        $_SESSION['nome'] = $user['nome'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['perfil'] = $user['perfil'];
        $_SESSION['plano'] = $user['plano'];
        $_SESSION['foto'] = $user['foto'] ?? '';
        try {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            unset($_SESSION['csrf_token']);
        }

        // verificar tipo de login
        if (!empty($user['super_admin']) && $user['super_admin'] == 1) {
            $_SESSION['super_admin'] = 1;
            $redirect = "admin.php";
        } elseif (!empty($user['login_restaurante']) && $user['login_restaurante'] == true) {
            $_SESSION['login_restaurante_temp'] = true;
            $redirect = "criar_primeiro_admin.php";
        } else {
            $_SESSION['super_admin'] = 0;
            require_once APP_BASE . '/src/config/turno_helpers.php';
            $perfilLogin = turno_normalizar_perfil($_SESSION['perfil'] ?? '');
            $_SESSION['perfil'] = $perfilLogin;
            $redirect = turno_home_por_perfil($perfilLogin);
        }

        try {
            require_once APP_BASE . '/src/config/turno_helpers.php';
            require_once APP_BASE . '/src/config/database.php';
            if (!($_SESSION['super_admin'] ?? 0) && turno_usuario_exige_turno_ativo($_SESSION['perfil'] ?? '')) {
                $temTurno = false;
                try {
                    require_once APP_BASE . '/src/Service/TurnoService.php';
                    $turnoService = new TurnoService(new Database());
                    $temTurno = !empty($turnoService->obterTurnoAtivoUsuario(
                        (int)($_SESSION['usuario_id'] ?? 0),
                        (int)($_SESSION['restaurante_id'] ?? 0)
                    ));
                } catch (Throwable $e) {
                    error_log('login_public turno check: ' . $e->getMessage());
                }
                if ($temTurno) {
                    $redirect = turno_home_por_perfil($_SESSION['perfil'] ?? '');
                } else {
                    $_SESSION['login_goto_home'] = 1;
                    $redirect = 'iniciar_turno.php';
                }
            }
        } catch (Throwable $e) {
            error_log('Falha ao avaliar exigencia de turno no login: ' . $e->getMessage());
        }

        echo json_encode([
            "success" => true,
            "message" => "Login realizado com sucesso!",
            "redirect" => $redirect
        ]);
    } else {
        security_log_event('login_failed', ['endpoint' => 'public/login_process', 'email' => $email]);
        echo json_encode([
            "success" => false,
            "message" => "Email ou senha inválidos"
        ]);
    }
} catch (Throwable $e) {

    // salvar erro no log
    error_log("Login Error: " . $e->getMessage());

    ob_clean(); // limpar qualquer saída antes de enviar JSON
    echo json_encode([
        "success" => false,
        "message" => "Erro interno no servidor."
    ]);
}

exit;


