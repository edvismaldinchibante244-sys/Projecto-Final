<?php
require_once __DIR__ . '/../app_base.php';
/*
 * API da "sessao em pausa" (retomar.php).
 *   acao=entrar      senha=...  -> confirma a palavra-passe e retoma a sessao (sem 2FA: este
 *                                  navegador ja passou pelo login completo nas ultimas 12 h)
 *   acao=pin         pin=...    -> o mesmo com o PIN (so equipa com PIN definido)
 *   acao=outra_conta            -> apaga a pausa deste navegador
 */
require_once APP_BASE . '/src/config/security.php';
require_once APP_BASE . '/src/config/csrf.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/personal_data.php';
require_once APP_BASE . '/src/config/sessao_retomar.php';
require_once APP_BASE . '/src/config/terminal_pin.php';

security_start_session();
security_set_headers();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    security_json(['success' => false, 'message' => 'Metodo nao permitido.'], 405);
}
csrf_validate_or_json();

$db = Database::infra();
$acao = (string)($_POST['acao'] ?? '');
if ($acao === 'outra_conta') {
    sr_apagar_atual($db);
    $_SESSION = [];
    session_regenerate_id(true);
    security_json(['success' => true, 'redirect' => 'index.php']);
}
if ($acao !== 'entrar' && $acao !== 'pin') {
    security_json(['success' => false, 'message' => 'Pedido invalido.'], 400);
}
if (!security_rate_limit('retomar_sessao', 10, 300)) {
    security_json(['success' => false, 'message' => 'Muitas tentativas. Aguarde alguns minutos.'], 429);
}
$pausa = sr_obter($db);
if (!$pausa) {
    security_json(['success' => false, 'message' => 'A pausa expirou. Entre com email e palavra-passe.', 'redirect' => 'index.php']);
}
$terminal = null;
try {
    $terminal = terminal_tem_cookie() ? terminal_atual($db) : null;
} catch (Throwable $e) {
}
$voltar = sr_voltar_seguro($_POST['voltar'] ?? '');
$concluir = static function (array $user) use ($pausa, $terminal, $voltar): void {
    require_once APP_BASE . '/src/config/login_sessao.php';
    if (!empty($pausa['manter'])) {
        $_POST['manter'] = '1';
    }
    // tambem renova a pausa (novo codigo); num terminal fica associada a ele
    $destino = login_api_complete_session($user, (string)$pausa['email'], $terminal ? (int)$terminal['id'] : null);
    security_log_event('retomar_sucesso', ['usuario_id' => (int)$user['id']]);
    if ($voltar !== '' && $destino !== 'iniciar_turno.php' && $destino !== 'criar_primeiro_admin.php') {
        $destino = $voltar;
    }
    security_json(['success' => true, 'redirect' => $destino]);
};

if ($acao === 'pin') {
    $pin = (string)($_POST['pin'] ?? '');
    if (!preg_match('/^\d{4,6}$/', $pin)) {
        security_json(['success' => false, 'message' => 'Introduza o PIN (4 a 6 números).']);
    }
    try {
        $r = pin_verificar((new Database())->getConnection(), (int)$pausa['restaurante_id'], (int)$pausa['usuario_id'], $pin,
            static fn(array $u): bool => terminal_perfil_usa_pin($u['perfil'] ?? ''), ['origem' => 'sessao_em_pausa']);
    } catch (Throwable $e) {
        error_log('api/retomar pin: ' . $e->getMessage());
        security_json(['success' => false, 'message' => 'Não foi possível continuar. Tente de novo.'], 500);
    }
    if (empty($r['ok'])) {
        $restam = sr_falhou($db, $pausa);
        if ($restam <= 0) {
            security_json(['success' => false, 'message' => 'Demasiadas tentativas. Entre de novo.', 'redirect' => $terminal ? 'terminal.php' : 'index.php']);
        }
        $msg = (string)($r['mensagem'] ?? 'PIN incorreto.');
        if ($msg === 'PIN incorreto.') {
            $msg .= ' ' . ($restam === 1 ? 'Resta 1 tentativa.' : "Restam $restam tentativas.");
        }
        security_json(['success' => false, 'message' => $msg]);
    }
    $u = function_exists('personal_data_decrypt_result') ? personal_data_decrypt_result($r['linha']) : $r['linha'];
    $concluir([
        'id' => (int)$u['id'], 'nome' => (string)$u['nome'], 'email' => (string)($u['email'] ?? $pausa['email']),
        'perfil' => terminal_normalizar_perfil($u['perfil']), 'restaurante_id' => (int)$u['restaurante_id'],
        'restaurante_nome' => (string)($u['restaurante_nome'] ?? ''), 'plano' => (string)($u['plano'] ?? 'BASICO'),
        'foto' => (string)($u['foto'] ?? ''), 'super_admin' => 0,
    ]);
}

$senha = (string)($_POST['senha'] ?? '');
if ($senha === '' || strlen($senha) > 512) {
    security_json(['success' => false, 'message' => 'Introduza a palavra-passe.']);
}

$resultado = null;
try {
    require_once APP_BASE . '/src/Controller/AuthController.php';
    $resultado = (new AuthController())->login((string)$pausa['email'], $senha);
} catch (Throwable $e) {
    error_log('api/retomar: ' . $e->getMessage());
    security_json(['success' => false, 'message' => 'Não foi possível continuar. Tente de novo.'], 500);
}
$user = is_array($resultado) && !empty($resultado['success']) ? (array)($resultado['data'] ?? []) : [];
if (!$user || (int)($user['id'] ?? 0) !== (int)$pausa['usuario_id'] || !empty($user['super_admin'])) {
    security_log_event('retomar_falhou', ['usuario_id' => (int)$pausa['usuario_id']]);
    $restam = sr_falhou($db, $pausa);
    if ($restam <= 0) {
        security_json(['success' => false, 'message' => 'Demasiadas tentativas. Entre de novo.', 'redirect' => $terminal ? 'terminal.php' : 'index.php']);
    }
    $msg = is_array($resultado) && !empty($resultado['message']) && stripos((string)$resultado['message'], 'bloque') !== false
        ? (string)$resultado['message']
        : 'Palavra-passe incorreta. ' . ($restam === 1 ? 'Resta 1 tentativa.' : "Restam $restam tentativas.");
    security_json(['success' => false, 'message' => $msg]);
}

$concluir($user);
