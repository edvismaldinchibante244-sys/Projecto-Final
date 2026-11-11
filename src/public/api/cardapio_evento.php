<?php
declare(strict_types=1);
require_once __DIR__ . '/../app_base.php';
require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/reserva_qr_schema.php';

header('Content-Type: application/json; charset=utf-8');
api_guard(['rate_key' => 'cardapio_evento', 'rate_max' => 180, 'rate_window' => 60, 'skip_turno' => true]);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') security_json(['success' => false, 'message' => 'Metodo nao permitido.'], 405);

$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) $payload = $_POST;
$evento = trim((string)($payload['evento'] ?? ''));
$allowed = ['abertura', 'produto_visualizado', 'produto_adicionado', 'pedido_enviado'];
if (!in_array($evento, $allowed, true)) security_json(['success' => false, 'message' => 'Evento invalido.'], 422);
$token = trim((string)($payload['token'] ?? ''));
$rid = (int)($payload['rid'] ?? 0);
$mesaId = max(0, (int)($payload['mesa_id'] ?? 0));
$produtoId = max(0, (int)($payload['produto_id'] ?? 0));
if ($token === '' || $rid <= 0) security_json(['success' => false, 'message' => 'Sessao invalida.'], 422);

try {
    $db = (new Database())->getConnection();
    $sessao = reserva_qr_get_session_by_token($db, $token, true);
    if (!$sessao || (int)($sessao['restaurante_id'] ?? 0) !== $rid) security_json(['success' => false, 'message' => 'Sessao invalida.'], 403);
    $sessionHash = hash('sha256', $token);
    $visitorHash = hash('sha256', substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64) . '|' . substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255));
    $uaHash = hash('sha256', substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255));
    $db->exec("CREATE TABLE IF NOT EXISTS cardapio_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, restaurante_id INT NOT NULL, mesa_id INT NULL,
        sessao_hash CHAR(64) NULL, evento VARCHAR(32) NOT NULL, produto_id INT NULL,
        visitor_hash CHAR(64) NULL, user_agent_hash CHAR(64) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cardapio_events_restaurante_created (restaurante_id, created_at), INDEX idx_cardapio_events_evento (evento), INDEX idx_cardapio_events_produto (produto_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $stmt = $db->prepare('INSERT INTO cardapio_events (restaurante_id, mesa_id, sessao_hash, evento, produto_id, visitor_hash, user_agent_hash) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$rid, $mesaId > 0 ? $mesaId : null, $sessionHash, $evento, $produtoId > 0 ? $produtoId : null, $visitorHash, $uaHash]);
    security_json(['success' => true]);
} catch (Throwable $e) {
    security_json(['success' => false, 'message' => 'Nao foi possivel registar o evento.'], 500);
}
