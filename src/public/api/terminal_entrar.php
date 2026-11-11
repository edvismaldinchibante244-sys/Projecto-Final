<?php
/** Entrada por PIN num terminal do restaurante. Exige o cookie do terminal + CSRF do ecra de bloqueio. */
require_once __DIR__ . '/../app_base.php';
require_once APP_BASE . '/src/config/security.php';
require_once APP_BASE . '/src/config/csrf.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/personal_data.php';
require_once APP_BASE . '/src/config/login_security.php';
require_once APP_BASE . '/src/config/terminal_pin.php';
require_once APP_BASE . '/src/config/login_sessao.php';

security_start_session();
security_set_headers();
header('Content-Type: application/json; charset=utf-8');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    security_json(['success' => false, 'message' => 'Metodo nao permitido.'], 405);
}
if (!csrf_is_valid()) {
    security_json(['success' => false, 'message' => 'Ecrã expirado. Recarregue a pagina.'], 403);
}
if (!security_rate_limit('terminal_entrar', 20, 60)) {
    security_json(['success' => false, 'message' => 'Demasiadas tentativas. Aguarde um minuto.'], 429);
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

try {
    $db = (new Database())->getConnection();
    $terminal = terminal_atual($db);
    if (!$terminal) {
        security_json(['success' => false, 'message' => 'Este dispositivo já não é um terminal do restaurante. Entre com email e palavra-passe.', 'sem_terminal' => true], 403);
    }
    $r = pin_entrar($db, $terminal, (int)($input['usuario_id'] ?? 0), (string)($input['pin'] ?? ''));
    if (!$r['ok']) {
        security_json(['success' => false, 'message' => $r['mensagem']], 401);
    }
    $destino = login_api_complete_session($r['usuario'], $r['usuario']['email'], (int)$terminal['id']);
    security_json(['success' => true, 'redirect' => $destino]);
} catch (Throwable $e) {
    error_log('api/terminal_entrar: ' . $e->getMessage());
    security_json(['success' => false, 'message' => 'Erro ao entrar. Tente novamente.'], 500);
}
