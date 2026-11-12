<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/support_tickets.php';

$ctx = api_guard([
    'roles' => ['ADMIN'],
    'rate_key' => 'support_ticket_attachment',
    'rate_max' => 120,
    'rate_window' => 60,
    'skip_turno' => true,
    'skip_csrf' => true,
]);

$ticketId = (int)($_GET['ticket_id'] ?? 0);
$messageId = (int)($_GET['message_id'] ?? 0);
if ($ticketId <= 0 || $messageId <= 0) {
    http_response_code(404);
    exit;
}

$db = (new Database())->getConnection();
support_tickets_ensure_tables($db);

$stmt = $db->prepare("
    SELECT anexo_nome, anexo_path, anexo_mime, anexo_size
    FROM support_ticket_messages
    WHERE id = :mid
      AND ticket_id = :tid
      AND restaurante_id = :rid
      AND anexo_path IS NOT NULL
    LIMIT 1
");
$stmt->execute([
    ':mid' => $messageId,
    ':tid' => $ticketId,
    ':rid' => (int)$ctx['restaurante_id'],
]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    exit;
}

$relative = ltrim((string)$row['anexo_path'], '/\\');
$baseStorage = realpath(nv_storage());
$absolute = realpath(APP_BASE . '/' . $relative);
if (!$baseStorage || !$absolute || strpos($absolute, $baseStorage) !== 0 || !is_file($absolute)) {
    http_response_code(404);
    exit;
}

$mime = trim((string)($row['anexo_mime'] ?? ''));
if ($mime === '') {
    $mime = 'application/octet-stream';
}
$name = trim((string)($row['anexo_nome'] ?? 'anexo.bin'));
$size = (int)($row['anexo_size'] ?? 0);

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . str_replace('"', '', $name) . '"');
if ($size > 0) {
    header('Content-Length: ' . $size);
}
readfile($absolute);
exit;

