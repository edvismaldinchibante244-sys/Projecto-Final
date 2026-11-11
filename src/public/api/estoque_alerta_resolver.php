<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/plano_check.php';
require_once APP_BASE . '/src/config/restaurante_context.php';
require_once APP_BASE . '/src/config/estoque_inteligente.php';

header('Content-Type: application/json; charset=utf-8');

$ctx = api_guard([
    'roles' => ['ADMIN', 'GERENTE'],
    'rate_key' => 'estoque_alerta_resolver',
    'rate_max' => 60,
    'rate_window' => 60,
    'skip_turno' => true,
]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    security_json(['success' => false, 'message' => 'Metodo nao permitido'], 405);
}

$rid = (int)$ctx['restaurante_id'];
$planoRid = function_exists('session_restaurante_capability_id') ? (int)session_restaurante_capability_id() : $rid;
$planoRid = $planoRid > 0 ? $planoRid : $rid;
if (!plano_tem_funcionalidade_db($planoRid, 'estoque_avancado')) {
    security_json(['success' => false, 'message' => 'Funcionalidade disponivel nos planos Profissional e Empresarial'], 403);
}

$alertaId = (int)($_POST['alerta_id'] ?? 0);
$resolverTodos = (string)($_POST['resolver_todos'] ?? '') === '1';
$filialId = isset($_POST['filial_id']) ? (int)$_POST['filial_id'] : 0;
$filialId = $filialId > 0 ? $filialId : null;

if (!$resolverTodos && $alertaId <= 0) {
    security_json(['success' => false, 'message' => 'Alerta invalido'], 400);
}

$db = (new Database())->getConnection();
estoque_inteligente_schema_garantir($db);

try {
    if ($resolverTodos) {
        $sql = "
            UPDATE estoque_alertas
            SET resolvido = 2, resolvido_em = NOW()
            WHERE restaurante_id = :rid
              AND resolvido = 0
        ";
        if ($filialId !== null) {
            $sql .= " AND filial_id = :filial_id";
        }
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':rid', $rid, PDO::PARAM_INT);
        if ($filialId !== null) {
            $stmt->bindValue(':filial_id', $filialId, PDO::PARAM_INT);
        }
        $stmt->execute();
        $n = $stmt->rowCount();
        estoque_inteligente_log('estoque_alertas_resolvidos_lote', ['restaurante_id' => $rid, 'qtd' => $n]);
        security_json(['success' => true, 'message' => $n . ' alerta(s) resolvido(s). Não voltam a avisar nas próximas 24 horas.', 'resolvidos' => $n]);
    }

    $stmt = $db->prepare("
        UPDATE estoque_alertas
        SET resolvido = 2, resolvido_em = NOW()
        WHERE id = :id AND restaurante_id = :rid AND resolvido = 0
    ");
    $stmt->execute([':id' => $alertaId, ':rid' => $rid]);
    if ($stmt->rowCount() < 1) {
        security_json(['success' => false, 'message' => 'Alerta nao encontrado ou ja resolvido'], 404);
    }
    estoque_inteligente_log('estoque_alerta_resolvido', ['restaurante_id' => $rid, 'alerta_id' => $alertaId]);
    security_json(['success' => true, 'message' => 'Alerta resolvido. Não volta a avisar sobre isto nas próximas 24 horas.']);
} catch (Throwable $e) {
    error_log('estoque_alerta_resolver: ' . $e->getMessage());
    security_json(['success' => false, 'message' => 'Nao foi possivel resolver o alerta.'], 500);
}
