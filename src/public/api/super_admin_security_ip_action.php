<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/super_admin_permissions.php';
require_once APP_BASE . '/src/config/security_monitor.php';

header('Content-Type: application/json; charset=utf-8');

api_guard([
    'rate_key' => 'super_admin_security_ip_action',
    'rate_max' => 50,
    'rate_window' => 60,
    'skip_turno' => true,
]);

if (!isset($_SESSION['super_admin']) || (int)$_SESSION['super_admin'] !== 1) {
    security_json(['success' => false, 'message' => 'Acesso negado.'], 403);
}

super_admin_require_permission_json('manage_security');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    security_json(['success' => false, 'message' => 'Metodo nao permitido.'], 405);
}

$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$action = strtoupper(trim((string)($payload['action'] ?? '')));
$ip = trim((string)($payload['ip'] ?? ''));
$motivo = trim((string)($payload['motivo'] ?? 'Bloqueio manual pelo Super Admin'));
$tipo = strtoupper(trim((string)($payload['tipo'] ?? 'TEMPORARIO')));
$duracaoMin = (int)($payload['duracao_min'] ?? 60);

if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
    security_json(['success' => false, 'message' => 'IP invalido.'], 422);
}

try {
    $db = (new Database())->getConnection();
    security_monitor_ensure_tables($db);

    if ($action === 'BLOCK') {
        $isPermanent = ($tipo === 'PERMANENTE');
        security_monitor_block_ip($db, $ip, $motivo, 'manual_super_admin', $isPermanent, $duracaoMin);
        security_json(['success' => true, 'message' => 'IP bloqueado com sucesso.']);
    }

    if ($action === 'UNBLOCK') {
        security_monitor_unblock_ip($db, $ip);
        security_json(['success' => true, 'message' => 'IP desbloqueado com sucesso.']);
    }

    security_json(['success' => false, 'message' => 'Acao invalida.'], 422);
} catch (Throwable $e) {
    security_json(['success' => false, 'message' => 'Falha ao executar acao de seguranca.', 'error' => $e->getMessage()], 500);
}

