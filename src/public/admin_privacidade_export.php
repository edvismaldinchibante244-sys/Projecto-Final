<?php

require_once __DIR__ . '/app_base.php';
require_once APP_BASE . '/src/config/super_admin_check.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/csrf.php';
require_once APP_BASE . '/src/config/security.php';
require_once APP_BASE . '/src/config/privacy_requests.php';
require_once APP_BASE . '/src/config/audit.php';

requireSuperAdminPermission('manage_privacy');
security_set_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Metodo nao permitido.');
}

$requestId = (int)($_POST['request_id'] ?? 0);
$token = (string)($_POST['_csrf'] ?? '');
if ($requestId <= 0 || !csrf_is_valid($token)) {
    http_response_code(422);
    exit('Pedido ou sessao invalida.');
}

$db = (new Database())->getConnection();
privacy_request_ensure_schema($db);

$stmt = $db->prepare('SELECT * FROM data_subject_requests WHERE id = ? LIMIT 1');
$stmt->execute([$requestId]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$request) {
    http_response_code(404);
    exit('Pedido nao encontrado.');
}
if (empty($request['identity_verified_at'])) {
    http_response_code(409);
    exit('Confirme a identidade antes de gerar o ficheiro.');
}

$payload = privacy_request_build_export($db, $request);
if (($_POST['formato'] ?? '') === 'html') {
    // Versao legivel para o administrador conferir o que vai ser entregue ao titular.
    privacy_request_record_event($db, $requestId, (int)($_SESSION['usuario_id'] ?? 0) ?: null, 'EXPORT_VIEWED', 'Versão legível consultada pelo Super Admin.');
    audit_log($db, 'data_subject_export_viewed', ['request_id' => $requestId], 'INFO');
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $nonce = function_exists('security_csp_nonce') ? security_csp_nonce() : '';
    $html = str_replace('%%ACOES%%', '<button type="button" class="principal" id="btnImprimir">Imprimir ou guardar em PDF</button>', privacy_request_render_html($payload, false));
    echo str_replace('</body>', '<script nonce="' . htmlspecialchars($nonce, ENT_QUOTES) . '">document.getElementById("btnImprimir").addEventListener("click",function(){window.print();});</script></body>', $html);
    exit;
}
$actorId = (int)($_SESSION['usuario_id'] ?? 0) ?: null;
privacy_request_record_event($db, $requestId, $actorId, 'EXPORT_GENERATED', 'Dossie JSON de acesso/portabilidade gerado pelo Super Admin.');
$update = $db->prepare('UPDATE data_subject_requests SET export_generated_at = NOW(), handled_by = COALESCE(handled_by, ?), updated_at = NOW() WHERE id = ?');
$update->execute([$actorId, $requestId]);

audit_log($db, 'data_subject_export_generated', [
    'request_id' => $requestId,
    'public_id' => (string)$request['public_id'],
], 'INFO');

$filename = 'edvistro-dados-' . preg_replace('/[^a-zA-Z0-9-]/', '', (string)$request['public_id']) . '.json';
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
