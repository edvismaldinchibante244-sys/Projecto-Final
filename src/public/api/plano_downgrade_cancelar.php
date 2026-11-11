<?php
require_once __DIR__ . '/../app_base.php';
require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/plano_upgrade_guard.php';
require_once APP_BASE . '/src/config/plano_downgrade.php';
require_once APP_BASE . '/src/config/audit.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') security_json(['success' => false, 'message' => 'Metodo nao permitido.'], 405);
$ctx = api_guard(['roles' => ['ADMIN', 'GERENTE'], 'rate_key' => 'plano_downgrade_cancelar', 'rate_max' => 10, 'rate_window' => 300, 'skip_turno' => true]);
try {
    $db = (new Database())->getConnection();
    $rid = plano_upgrade_restaurante_alvo_id($db);
    if (!plano_upgrade_usuario_pode_gerir($db, (int)$ctx['usuario_id'], $rid)) security_json(['success' => false, 'message' => 'Sem permissao.'], 403);
    plano_downgrade_schema_garantir($db);
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $db->prepare("UPDATE plano_downgrades SET status = 'CANCELADO', cancelado_em = NOW() WHERE id = ? AND restaurante_id = ? AND status IN ('AGENDADO','BLOQUEADO_LIMITE')");
    $stmt->execute([$id, $rid]);
    if ($stmt->rowCount() !== 1) security_json(['success' => false, 'message' => 'Downgrade nao encontrado ou ja processado.'], 404);
    audit_log($db, 'plano_downgrade_cancelado', ['restaurante_id' => $rid, 'downgrade_id' => $id]);
    security_json(['success' => true, 'message' => 'Downgrade agendado cancelado. O plano atual permanece inalterado.']);
} catch (Throwable $e) {
    error_log('[plano_downgrade_cancelar] ' . $e->getMessage());
    security_json(['success' => false, 'message' => 'Nao foi possivel cancelar o downgrade.'], 500);
}
