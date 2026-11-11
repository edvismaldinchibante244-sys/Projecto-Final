<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/security_monitor.php';

header('Content-Type: application/json; charset=utf-8');

api_guard([
    'rate_key' => 'offline_sync_event',
    'rate_max' => 240,
    'rate_window' => 60,
    'skip_turno' => true,
]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    security_json(['success' => false, 'message' => 'Metodo nao permitido.'], 405);
}

$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$opId = trim((string)($payload['op_id'] ?? ''));
$status = strtoupper(trim((string)($payload['status'] ?? 'PENDING')));
$endpoint = trim((string)($payload['endpoint'] ?? ''));
$method = strtoupper(trim((string)($payload['method'] ?? 'POST')));
$deviceId = trim((string)($payload['device_id'] ?? ''));
$attempts = max(0, (int)($payload['attempts'] ?? 0));
$lastError = trim((string)($payload['last_error'] ?? ''));

if ($opId === '' || $endpoint === '') {
    security_json(['success' => false, 'message' => 'Dados invalidos.'], 422);
}
if (!in_array($status, ['PENDING', 'DONE', 'FAILED'], true)) {
    $status = 'PENDING';
}

try {
    $db = (new Database())->getConnection();
    security_monitor_ensure_tables($db);

    $restauranteId = (int)($_SESSION['restaurante_id'] ?? 0);
    $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

    $stmt = $db->prepare("
        INSERT INTO offline_sync_queue
            (op_id, restaurante_id, usuario_id, device_id, endpoint, method, status, attempts, last_error, payload_json, created_at, updated_at, last_sync_at)
        VALUES
            (:op_id, :restaurante_id, :usuario_id, :device_id, :endpoint, :method, :status, :attempts, :last_error, :payload_json, NOW(), NOW(), :last_sync_at)
        ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            attempts = GREATEST(attempts, VALUES(attempts)),
            last_error = VALUES(last_error),
            updated_at = NOW(),
            last_sync_at = VALUES(last_sync_at)
    ");
    $stmt->execute([
        ':op_id' => substr($opId, 0, 80),
        ':restaurante_id' => $restauranteId > 0 ? $restauranteId : null,
        ':usuario_id' => $usuarioId > 0 ? $usuarioId : null,
        ':device_id' => $deviceId !== '' ? substr($deviceId, 0, 120) : null,
        ':endpoint' => substr($endpoint, 0, 255),
        ':method' => substr($method !== '' ? $method : 'POST', 0, 12),
        ':status' => $status,
        ':attempts' => $attempts,
        ':last_error' => $lastError !== '' ? substr($lastError, 0, 255) : null,
        ':payload_json' => json_encode([
            'ts' => gmdate('c'),
            'source' => 'offline_sync',
        ], JSON_UNESCAPED_UNICODE),
        ':last_sync_at' => $status === 'DONE' ? date('Y-m-d H:i:s') : null,
    ]);

    security_json(['success' => true]);
} catch (Throwable $e) {
    security_json(['success' => false, 'message' => 'Falha ao registrar telemetria offline.'], 500);
}

