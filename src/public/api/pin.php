<?php
/**
 * PIN da equipa.
 *  - acao=definir: o proprio funcionario define/troca o PIN, confirmando a palavra-passe;
 *  - acao=limpar: o ADMIN apaga o PIN de um funcionario do seu restaurante;
 *  - acao=desbloquear: o ADMIN tira o bloqueio por tentativas erradas, mantendo o mesmo PIN.
 */
require_once __DIR__ . '/../app_base.php';
require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/audit.php';
require_once APP_BASE . '/src/config/terminal_pin.php';

header('Content-Type: application/json; charset=utf-8');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    security_json(['success' => false, 'message' => 'Metodo nao permitido.'], 405);
}

$ctx = api_guard(['rate_key' => 'pin_definir', 'rate_max' => 10, 'rate_window' => 300, 'skip_turno' => true]);
$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
$acao = (string)($input['acao'] ?? '');
$uid = (int)$ctx['usuario_id'];
$rid = (int)$ctx['restaurante_id'];

try {
    $db = (new Database())->getConnection();
    if ($acao === 'definir') {
        if ($ctx['super_admin'] || !terminal_perfil_usa_pin($ctx['perfil'])) {
            security_json(['success' => false, 'message' => 'O administrador entra sempre com email e palavra-passe; o PIN é para a equipa.'], 403);
        }
        $stmt = $db->prepare('SELECT senha FROM usuarios WHERE id = ? AND restaurante_id = ?');
        $stmt->execute([$uid, $rid]);
        $hash = (string)$stmt->fetchColumn();
        if ($hash === '' || !password_verify((string)($input['senha'] ?? ''), $hash)) {
            security_log_event('pin_definir_senha_errada', ['usuario_id' => $uid]);
            security_json(['success' => false, 'message' => 'Palavra-passe incorreta.'], 422);
        }
        $pin = (string)($input['pin'] ?? '');
        if ($pin !== (string)($input['pin_confirmar'] ?? '')) {
            security_json(['success' => false, 'message' => 'Os dois PIN não coincidem.'], 422);
        }
        pin_definir($db, $uid, $pin);
        audit_log($db, 'pin_definido', ['usuario_id' => $uid, 'restaurante_id' => $rid]);
        security_json(['success' => true, 'message' => 'PIN guardado. Já pode entrar nos terminais do restaurante com ele.']);
    }
    if ($acao === 'limpar') {
        if ($ctx['perfil'] !== 'ADMIN' || $ctx['super_admin']) {
            security_json(['success' => false, 'message' => 'Sem permissao.'], 403);
        }
        $alvo = (int)($input['usuario_id'] ?? 0);
        if (!pin_limpar($db, $rid, $alvo)) {
            security_json(['success' => false, 'message' => 'Funcionário não encontrado.'], 404);
        }
        audit_log($db, 'pin_limpo', ['usuario_id' => $alvo, 'restaurante_id' => $rid, 'por' => $uid]);
        security_json(['success' => true, 'message' => 'PIN apagado. O funcionário tem de definir um novo.']);
    }
    if ($acao === 'desbloquear') {
        if ($ctx['perfil'] !== 'ADMIN' || $ctx['super_admin']) {
            security_json(['success' => false, 'message' => 'Sem permissao.'], 403);
        }
        $alvo = (int)($input['usuario_id'] ?? 0);
        if (!pin_desbloquear($db, $rid, $alvo)) {
            security_json(['success' => false, 'message' => 'Funcionário não encontrado ou sem PIN definido.'], 404);
        }
        audit_log($db, 'pin_desbloqueado', ['usuario_id' => $alvo, 'restaurante_id' => $rid, 'por' => $uid]);
        security_json(['success' => true, 'message' => 'PIN desbloqueado. O funcionário já pode usar o mesmo PIN.']);
    }
    security_json(['success' => false, 'message' => 'Ação inválida.'], 400);
} catch (InvalidArgumentException $e) {
    security_json(['success' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('api/pin: ' . $e->getMessage());
    security_json(['success' => false, 'message' => 'Erro ao guardar o PIN.'], 500);
}
