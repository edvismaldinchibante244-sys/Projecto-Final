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
    'rate_key' => 'estoque_insumo_listar',
    'rate_max' => 120,
    'rate_window' => 60,
    'skip_turno' => true,
]);

$rid = (int)$ctx['restaurante_id'];
$planoRid = function_exists('session_restaurante_capability_id') ? (int)session_restaurante_capability_id() : $rid;
$planoRid = $planoRid > 0 ? $planoRid : $rid;
if (!plano_tem_funcionalidade_db($planoRid, 'estoque_avancado')) {
    security_json(['success' => false, 'message' => 'Funcionalidade disponivel nos planos Profissional e Empresarial'], 403);
}

$busca = trim((string)($_GET['busca'] ?? ''));
$filialId = isset($_GET['filial_id']) ? (int)$_GET['filial_id'] : 0;
$filialId = $filialId > 0 ? $filialId : null;
$incluirInativos = isset($_GET['incluir_inativos']) && (int)$_GET['incluir_inativos'] === 1;

try {
    $db = (new Database())->getConnection();
    estoque_inteligente_schema_garantir($db);
    try {
        $db->beginTransaction();
        estoque_deduplicar_insumos($db, $rid, $filialId);
        $db->commit();
    } catch (Throwable $dedupErr) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('estoque_insumo_listar dedup: ' . $dedupErr->getMessage());
    }

    $sql = "
        SELECT i.id, i.nome, i.sku, i.unidade_base, i.estoque_minimo_base, i.ativo,
               COALESCE(s.saldo_base,0) AS saldo_base,
               (
                   SELECT COUNT(*)
                   FROM estoque_ficha_itens fi
                   JOIN estoque_fichas_tecnicas ft ON ft.id = fi.ficha_id
                   WHERE ft.restaurante_id = i.restaurante_id
                     AND fi.insumo_id = i.id
               ) AS em_ficha
        FROM estoque_insumos i
        LEFT JOIN estoque_saldos s
          ON s.insumo_id = i.id
         AND s.restaurante_id = i.restaurante_id
    ";
    if ($filialId === null) {
        $sql .= " AND ((s.filial_id IS NULL AND i.filial_id IS NULL) OR s.filial_id = i.filial_id)";
    } else {
        $sql .= " AND s.filial_id = :filial_id_saldo";
    }
    $sql .= " WHERE i.restaurante_id = :rid";
    if (!$incluirInativos) {
        $sql .= " AND i.ativo = 1";
    }
    if ($filialId !== null) {
        $sql .= " AND i.filial_id = :filial_id_insumo";
    }
    if ($busca !== '') {
        $sql .= " AND (i.nome LIKE :busca OR i.sku LIKE :busca)";
    }
    $sql .= " ORDER BY i.nome ASC";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':rid', $rid, PDO::PARAM_INT);
    if ($filialId !== null) {
        $stmt->bindValue(':filial_id_saldo', $filialId, PDO::PARAM_INT);
        $stmt->bindValue(':filial_id_insumo', $filialId, PDO::PARAM_INT);
    }
    if ($busca !== '') {
        $stmt->bindValue(':busca', '%' . $busca . '%', PDO::PARAM_STR);
    }
    $stmt->execute();

    security_json(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Throwable $e) {
    error_log('estoque_insumo_listar: ' . $e->getMessage());
    security_json(['success' => false, 'message' => 'Erro ao listar insumos'], 500);
}
