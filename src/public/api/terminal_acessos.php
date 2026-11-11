<?php
require_once __DIR__ . '/../app_base.php';

header('Content-Type: application/json; charset=utf-8');

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/personal_data.php';
require_once APP_BASE . '/src/config/terminal_pin.php';

api_guard([
    'roles' => ['ADMIN'],
    'rate_key' => 'terminal_acessos',
    'rate_max' => 60,
    'rate_window' => 60,
    'skip_turno' => true,
]);

$ctx = ['restaurante_id' => (int)($_SESSION['restaurante_id'] ?? 0)];
$rid = (int)$ctx['restaurante_id'];
$terminalId = (int)($_GET['terminal_id'] ?? 0);
if ($rid <= 0 || $terminalId <= 0) {
    security_json(['success' => false, 'message' => 'Terminal invalido.'], 422);
}

try {
    $db = (new Database())->getConnection();

    $stmt = $db->prepare('SELECT nome FROM terminais WHERE id = ? AND restaurante_id = ? LIMIT 1');
    $stmt->execute([$terminalId, $rid]);
    $nomeTerminal = $stmt->fetchColumn();
    if ($nomeTerminal === false) {
        security_json(['success' => false, 'message' => 'Terminal nao encontrado.'], 404);
    }

    $itens = [];
    foreach (terminal_acessos_recentes($db, $rid, $terminalId, 30) as $linha) {
        $itens[] = [
            'quando' => date('d/m/Y H:i', strtotime((string)$linha['criado_em'])),
            'usuario' => $linha['usuario_nome']
                ? (string)personal_data_decrypt_result($linha['usuario_nome'], 'nome')
                : '—',
            'resultado' => strtoupper((string)$linha['resultado']) === 'OK' ? 'entrou' : 'PIN errado',
            'ok' => strtoupper((string)$linha['resultado']) === 'OK',
        ];
    }

    security_json(['success' => true, 'nome' => (string)$nomeTerminal, 'itens' => $itens]);
} catch (Throwable $e) {
    error_log('terminal_acessos: ' . $e->getMessage());
    security_json(['success' => false, 'message' => 'Nao foi possivel carregar os acessos.'], 500);
}
