<?php
require_once __DIR__ . '/../app_base.php';
require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/plano_check.php';
require_once APP_BASE . '/src/config/restaurante_context.php';
require_once APP_BASE . '/src/config/promocao_schema.php';

header('Content-Type: application/json; charset=utf-8');
api_guard(['roles' => ['ADMIN', 'GERENTE'], 'rate_key' => 'promocao_status', 'rate_max' => 30, 'rate_window' => 60]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo nao permitido.']);
    exit;
}

$rid = session_restaurante_contexto_id();
$planRid = session_restaurante_capability_id() ?: $rid;
$plano = plano_normalizar_nome((plano_get_dados($planRid)['plano_nome'] ?? 'BASICO'));
if (!promocao_plano_permitido($plano)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Promocoes disponiveis nos planos Profissional e Empresarial.']);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$ativo = (int)($_POST['ativo'] ?? -1);
if ($id <= 0 || !in_array($ativo, [0, 1], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Estado da campanha invalido.']);
    exit;
}

try {
    $db = (new Database())->getConnection();
    promocao_schema_garantir($db);
    $stmt = $db->prepare('UPDATE promocoes SET ativo = :ativo WHERE id = :id AND restaurante_id = :rid');
    $stmt->execute(['ativo' => $ativo, 'id' => $id, 'rid' => $rid]);

    if ($stmt->rowCount() === 0) {
        $exists = $db->prepare('SELECT id FROM promocoes WHERE id = :id AND restaurante_id = :rid');
        $exists->execute(['id' => $id, 'rid' => $rid]);
        if (!$exists->fetchColumn()) {
            throw new DomainException('Campanha nao encontrada.');
        }
    }

    echo json_encode([
        'success' => true,
        'message' => $ativo === 1 ? 'Campanha reativada.' : 'Campanha pausada.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[promocao_status] ' . $e->getMessage());
    http_response_code($e instanceof DomainException ? 404 : 500);
    echo json_encode([
        'success' => false,
        'message' => $e instanceof DomainException ? $e->getMessage() : 'Nao foi possivel atualizar a campanha.',
    ], JSON_UNESCAPED_UNICODE);
}
