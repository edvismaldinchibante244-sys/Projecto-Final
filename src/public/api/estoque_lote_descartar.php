<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/plano_check.php';
require_once APP_BASE . '/src/config/restaurante_context.php';
require_once APP_BASE . '/src/config/estoque_inteligente.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    security_json(['success' => false, 'message' => 'Metodo nao permitido'], 405);
}

$ctx = api_guard([
    'roles' => ['ADMIN', 'GERENTE'],
    'rate_key' => 'estoque_lote_descartar',
    'rate_max' => 60,
    'rate_window' => 60,
    'skip_turno' => true,
]);

$rid = (int)$ctx['restaurante_id'];
$planoRid = function_exists('session_restaurante_capability_id') ? (int)session_restaurante_capability_id() : $rid;
$planoRid = $planoRid > 0 ? $planoRid : $rid;
if (!plano_tem_funcionalidade_db($planoRid, 'estoque_avancado')) {
    security_json(['success' => false, 'message' => 'Funcionalidade disponivel nos planos Profissional e Empresarial'], 403);
}

$loteId = (int)($_POST['lote_id'] ?? 0);
$motivo = trim((string)($_POST['motivo'] ?? 'Descarte de lote vencido/proximo do vencimento'));
if ($loteId <= 0) {
    security_json(['success' => false, 'message' => 'Lote invalido'], 422);
}

$db = (new Database())->getConnection();
estoque_inteligente_schema_garantir($db);

$stmt = $db->prepare("
    SELECT l.id, l.insumo_id, l.filial_id, l.saldo_base, l.custo_unitario_base, l.validade,
           i.nome, i.unidade_base
    FROM estoque_lotes l
    JOIN estoque_insumos i ON i.id = l.insumo_id
    WHERE l.id = :id AND l.restaurante_id = :rid
    LIMIT 1
");
$stmt->execute([':id' => $loteId, ':rid' => $rid]);
$lote = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$lote) {
    security_json(['success' => false, 'message' => 'Lote nao encontrado'], 404);
}

$qtdBase = (float)$lote['saldo_base'];
if ($qtdBase <= 0) {
    security_json(['success' => true, 'message' => 'Lote ja estava sem saldo']);
}

$filialId = $lote['filial_id'] !== null ? (int)$lote['filial_id'] : null;
$insumoId = (int)$lote['insumo_id'];
$custoUnit = (float)($lote['custo_unitario_base'] ?? 0);
$custoPerdido = $qtdBase * max(0, $custoUnit);

$db->beginTransaction();
try {
    $upd = $db->prepare("UPDATE estoque_lotes SET saldo_base = 0 WHERE id = :id AND restaurante_id = :rid");
    $upd->execute([':id' => $loteId, ':rid' => $rid]);

    estoque_upsert_saldo($db, $rid, $filialId, $insumoId, -$qtdBase, null);

    $stmtPerda = $db->prepare("
        INSERT INTO estoque_perdas
        (restaurante_id, filial_id, insumo_id, quantidade_base, custo_perdido, motivo, responsavel_id)
        VALUES
        (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtPerda->bindValue(1, $rid, PDO::PARAM_INT);
    if ($filialId === null) {
        $stmtPerda->bindValue(2, null, PDO::PARAM_NULL);
    } else {
        $stmtPerda->bindValue(2, $filialId, PDO::PARAM_INT);
    }
    $stmtPerda->bindValue(3, $insumoId, PDO::PARAM_INT);
    $stmtPerda->bindValue(4, $qtdBase);
    $stmtPerda->bindValue(5, $custoPerdido);
    $stmtPerda->bindValue(6, substr($motivo, 0, 180));
    $stmtPerda->bindValue(7, (int)$ctx['usuario_id'], PDO::PARAM_INT);
    $stmtPerda->execute();

    estoque_registrar_movimento($db, [
        'restaurante_id' => $rid,
        'filial_id' => $filialId,
        'insumo_id' => $insumoId,
        'tipo' => 'PERDA',
        'origem' => 'MANUAL',
        'quantidade_base' => $qtdBase,
        'custo_unitario_base' => $custoUnit,
        'custo_total' => $custoPerdido,
        'unidade_origem' => (string)$lote['unidade_base'],
        'motivo' => $motivo,
        'responsavel_id' => (int)$ctx['usuario_id'],
        'meta_json' => ['lote_id' => $loteId, 'validade' => $lote['validade']],
    ]);

    $db->commit();

    try {
        estoque_gerar_alertas_automaticos($db, $rid, $filialId);
    } catch (Throwable $e) {
        error_log('estoque_lote_descartar alertas: ' . $e->getMessage());
    }

    security_json([
        'success' => true,
        'message' => 'Lote descartado: ' . ($lote['nome'] ?? '') . ' (' . number_format($qtdBase, 3, '.', '') . ' base)',
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    security_json([
        'success' => false,
        'message' => security_exception_message(
            $e,
            'Erro ao descartar lote.',
            'estoque_lote_descartar_failed'
        ),
    ], 500);
}
