<?php
require_once __DIR__ . '/../app_base.php';
require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/restaurante_context.php';
require_once APP_BASE . '/src/config/plano_upgrade_guard.php';
require_once APP_BASE . '/src/config/plano_downgrade.php';
require_once APP_BASE . '/src/config/audit.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') security_json(['success' => false, 'message' => 'Metodo nao permitido.'], 405);
$ctx = api_guard(['roles' => ['ADMIN', 'GERENTE'], 'rate_key' => 'plano_downgrade', 'rate_max' => 10, 'rate_window' => 300, 'skip_turno' => true]);
try {
    $db = (new Database())->getConnection();
    $rid = plano_upgrade_restaurante_alvo_id($db);
    if (!plano_upgrade_usuario_pode_gerir($db, (int)$ctx['usuario_id'], $rid)) security_json(['success' => false, 'message' => 'Sem permissao para gerir o plano.'], 403);
    plano_downgrade_schema_garantir($db);
    $destino = plano_normalizar_nome($_POST['plano'] ?? '');
    $atual = plano_normalizar_nome(plano_get_dados($rid)['plano_nome'] ?? 'BASICO');
    if (plano_downgrade_nivel($destino) < 1 || plano_downgrade_nivel($destino) >= plano_downgrade_nivel($atual)) security_json(['success' => false, 'message' => 'Selecione um plano inferior ao atual.'], 422);
    $aplicarEm = plano_downgrade_data_aplicacao($db, $rid);
    if (!$aplicarEm) security_json(['success' => false, 'message' => 'Nao foi possivel identificar o fim do ciclo atual.'], 422);
    $pendente = $db->prepare("SELECT id FROM plano_downgrades WHERE restaurante_id = ? AND status IN ('AGENDADO','BLOQUEADO_LIMITE') LIMIT 1");
    $pendente->execute([$rid]);
    if ($pendente->fetchColumn()) security_json(['success' => false, 'message' => 'Ja existe um downgrade agendado.'], 409);
    $avaliacao = plano_downgrade_avaliar_limites($db, $rid, $destino);
    $stmt = $db->prepare("INSERT INTO plano_downgrades (restaurante_id, plano_origem, plano_destino, aplicar_em, status, limites_json, criado_por) VALUES (?, ?, ?, ?, 'AGENDADO', ?, ?)");
    $stmt->execute([$rid, $atual, $destino, $aplicarEm, json_encode($avaliacao, JSON_UNESCAPED_UNICODE), (int)$ctx['usuario_id']]);
    audit_log($db, 'plano_downgrade_agendado', ['restaurante_id' => $rid, 'origem' => $atual, 'destino' => $destino, 'aplicar_em' => $aplicarEm, 'compativel_hoje' => $avaliacao['compativel']]);
    security_json(['success' => true, 'message' => 'Downgrade agendado para ' . date('d/m/Y', strtotime($aplicarEm)) . '. O plano atual permanece ativo ate ao fim do ciclo.', 'data' => ['aplicar_em' => $aplicarEm, 'avaliacao' => $avaliacao]]);
} catch (Throwable $e) {
    error_log('[plano_downgrade] ' . $e->getMessage());
    security_json(['success' => false, 'message' => 'Nao foi possivel agendar o downgrade.'], 500);
}
