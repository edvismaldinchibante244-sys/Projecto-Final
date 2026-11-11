<?php
require_once __DIR__ . '/app_base.php';
/*
 * O titular ve os seus dados numa pagina legivel (pode imprimir ou guardar em PDF) e, se quiser,
 * descarrega o ficheiro JSON para levar para outro servico. Link temporario enviado para o seu email.
 */
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/security.php';
require_once APP_BASE . '/src/config/legal_documents.php';
require_once APP_BASE . '/src/config/privacy_requests.php';
require_once APP_BASE . '/src/config/audit.php';

security_start_session();
security_set_headers();
header('X-Robots-Tag: noindex');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$ref = (string)($_GET['r'] ?? '');
$tok = (string)($_GET['t'] ?? '');
$db = (new Database())->getConnection();
privacy_request_ensure_schema($db);
$req = privacy_request_find_by_token($db, $ref, $tok, 'delivery');
if (!$req || empty($req['identity_verified_at'])) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="pt"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Link expirado</title></head><body style="font-family:Arial;max-width:520px;margin:60px auto;padding:20px"><h1>Este link já não é válido</h1><p>Os links para ver os seus dados valem 7 dias. Peça um novo respondendo ao email que recebeu da Nivora Restaurant.</p></body></html>';
    exit;
}
foreach (['nome', 'email', 'restaurante'] as $c) {
    $req[$c] = personal_data_decrypt($req[$c] ?? null) ?? '';
}
$dossie = privacy_request_build_export($db, $req);

if (empty($req['delivery_opened_at'])) {
    $db->prepare('UPDATE data_subject_requests SET delivery_opened_at = NOW() WHERE id = ?')->execute([(int)$req['id']]);
    privacy_request_record_event($db, (int)$req['id'], null, 'DATA_DELIVERED', 'O titular abriu o link e viu os seus dados.');
}
audit_log($db, 'data_subject_delivery_opened', ['request_id' => (int)$req['id'], 'formato' => ($_GET['formato'] ?? 'html')]);

if (($_GET['formato'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="os-meus-dados-' . substr((string)$req['public_id'], 0, 8) . '.json"');
    echo json_encode($dossie, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
$nonce = function_exists('security_csp_nonce') ? security_csp_nonce() : '';
$q = 'r=' . rawurlencode($ref) . '&t=' . rawurlencode($tok);
$acoes = '<button type="button" class="principal" id="btnImprimir">Imprimir ou guardar em PDF</button>'
    . '<a href="direitos_meus_dados.php?' . htmlspecialchars($q, ENT_QUOTES) . '&formato=json">Descarregar ficheiro (para outro serviço)</a>';
$html = str_replace('%%ACOES%%', $acoes, privacy_request_render_html($dossie, true));
echo str_replace('</body>', '<script nonce="' . htmlspecialchars($nonce, ENT_QUOTES) . '">document.getElementById("btnImprimir").addEventListener("click",function(){window.print();});</script></body>', $html);
