<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Descarregar o ficheiro de um trabalho pronto (ex.: exportacao de dados).
   GET ?id=N. So o administrador do restaurante dono do trabalho. O ficheiro fica 24 h.
*/
include_once APP_BASE . '/src/config/api_guard.php';
include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/plano_upgrade_guard.php';
include_once APP_BASE . '/src/config/trabalhos_fila.php';

$ctx = api_guard(['rate_key' => 'trabalho_descarregar', 'rate_max' => 30, 'rate_window' => 600, 'skip_turno' => true]);
$db = (new Database())->getConnection();
$rid = plano_upgrade_restaurante_alvo_id($db);
$id = (int)($_GET['id'] ?? 0);
if ($rid <= 0 || $id <= 0 || !plano_upgrade_usuario_pode_gerir($db, (int)$ctx['usuario_id'], $rid)) {
    security_json(['success' => false, 'message' => 'Sem acesso.'], 403);
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
$t = fila_obter($db, $id);
if (!$t || (int)$t['restaurante_id'] !== $rid || $t['estado'] !== 'PRONTO' || empty($t['ficheiro'])
    || strtotime((string)$t['expira_em']) < time()) {
    security_json(['success' => false, 'message' => 'O ficheiro já não está disponível. Peça uma nova exportação.'], 404);
}
$f = fila_pasta() . DIRECTORY_SEPARATOR . basename((string)$t['ficheiro']);
if (!is_file($f)) {
    security_json(['success' => false, 'message' => 'O ficheiro já não está disponível. Peça uma nova exportação.'], 404);
}
$db->prepare('UPDATE trabalhos_fila SET descarregado_em = COALESCE(descarregado_em, NOW()) WHERE id = ?')->execute([$id]);
if (function_exists('audit_log')) {
    audit_log($db, 'dados_exportados_descarregados', ['restaurante_id' => $rid, 'trabalho_id' => $id]);
}
$nome = preg_replace('/[^A-Za-z0-9._-]/', '', (string)$t['nome_download']) ?: 'dados.zip';
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $nome . '"');
header('Content-Length: ' . filesize($f));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
readfile($f);
exit;
