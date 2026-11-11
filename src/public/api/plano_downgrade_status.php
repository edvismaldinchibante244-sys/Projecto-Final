<?php
require_once __DIR__ . '/../app_base.php';
require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/plano_upgrade_guard.php';
require_once APP_BASE . '/src/config/plano_downgrade.php';

$ctx = api_guard(['rate_key' => 'plano_downgrade_status', 'rate_max' => 30, 'rate_window' => 60, 'skip_turno' => true]);
try {
    $db = (new Database())->getConnection();
    $rid = plano_upgrade_restaurante_alvo_id($db);
    if (!plano_upgrade_usuario_pode_gerir($db, (int)$ctx['usuario_id'], $rid)) security_json(['success' => false, 'message' => 'Sem permissao.'], 403);
    plano_downgrade_schema_garantir($db);
    $stmt = $db->prepare("SELECT id, plano_destino, aplicar_em, status, limites_json FROM plano_downgrades WHERE restaurante_id = ? AND status IN ('AGENDADO','BLOQUEADO_LIMITE') ORDER BY id DESC LIMIT 1");
    $stmt->execute([$rid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    security_json(['success' => true, 'data' => $row]);
} catch (Throwable $e) {
    error_log('[plano_downgrade_status] ' . $e->getMessage());
    security_json(['success' => false, 'message' => 'Nao foi possivel consultar o downgrade.'], 500);
}
