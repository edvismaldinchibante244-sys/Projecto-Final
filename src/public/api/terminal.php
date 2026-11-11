<?php
/** Gestao de terminais do restaurante (so ADMIN): registar este browser, listar, revogar. */
require_once __DIR__ . '/../app_base.php';
require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/audit.php';
require_once APP_BASE . '/src/config/terminal_pin.php';

header('Content-Type: application/json; charset=utf-8');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    security_json(['success' => false, 'message' => 'Metodo nao permitido.'], 405);
}

$ctx = api_guard(['roles' => ['ADMIN'], 'rate_key' => 'terminal_admin', 'rate_max' => 30, 'rate_window' => 60, 'skip_turno' => true]);
if ($ctx['super_admin']) {
    security_json(['success' => false, 'message' => 'Entre como administrador do restaurante.'], 403);
}
$rid = (int)$ctx['restaurante_id'];

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
$acao = (string)($input['acao'] ?? '');

try {
    $db = (new Database())->getConnection();
    switch ($acao) {
        case 'registar':
            $id = terminal_registar($db, $rid, (string)($input['nome'] ?? ''), (int)$ctx['usuario_id']);
            audit_log($db, 'terminal_registado', ['terminal_id' => $id, 'restaurante_id' => $rid]);
            security_json(['success' => true, 'terminal_id' => $id, 'terminais' => terminal_listar($db, $rid)]);
            break;
        case 'revogar':
            $tid = (int)($input['terminal_id'] ?? 0);
            if (!terminal_revogar($db, $rid, $tid)) {
                security_json(['success' => false, 'message' => 'Terminal não encontrado.'], 404);
            }
            audit_log($db, 'terminal_revogado', ['terminal_id' => $tid, 'restaurante_id' => $rid]);
            $atual = terminal_atual($db);
            security_json(['success' => true, 'este_dispositivo' => $atual ? (int)$atual['id'] : null, 'terminais' => terminal_listar($db, $rid)]);
            break;
        case 'listar':
            $atual = terminal_atual($db);
            security_json(['success' => true, 'este_dispositivo' => $atual && (int)$atual['restaurante_id'] === $rid ? (int)$atual['id'] : null, 'terminais' => terminal_listar($db, $rid)]);
            break;
        default:
            security_json(['success' => false, 'message' => 'Ação inválida.'], 400);
    }
} catch (InvalidArgumentException $e) {
    security_json(['success' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('api/terminal: ' . $e->getMessage());
    security_json(['success' => false, 'message' => 'Erro ao gerir terminais.'], 500);
}
