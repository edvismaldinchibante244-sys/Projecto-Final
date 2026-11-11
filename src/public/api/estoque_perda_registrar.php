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
    'rate_key' => 'estoque_perda_registrar',
    'rate_max' => 90,
    'rate_window' => 60,
    'skip_turno' => true,
]);

$rid = (int)$ctx['restaurante_id'];
$planoRid = function_exists('session_restaurante_capability_id') ? (int)session_restaurante_capability_id() : $rid;
$planoRid = $planoRid > 0 ? $planoRid : $rid;
if (!plano_tem_funcionalidade_db($planoRid, 'estoque_avancado')) {
    security_json(['success' => false, 'message' => 'Funcionalidade disponivel nos planos Profissional e Empresarial'], 403);
}

$insumoId = (int)($_POST['insumo_id'] ?? 0);
$quantidade = (float)str_replace(',', '.', (string)($_POST['quantidade'] ?? 0));
$unidade = trim((string)($_POST['unidade'] ?? 'un'));
$motivo = trim((string)($_POST['motivo'] ?? ''));
$custoRaw = trim((string)($_POST['custo_unitario'] ?? ''));
$custoUnit = $custoRaw !== '' ? (float)str_replace(',', '.', $custoRaw) : null;
$filialId = (int)($_POST['filial_id'] ?? 0);
$filialId = $filialId > 0 ? $filialId : null;

if ($insumoId <= 0 || $quantidade <= 0 || $motivo === '') {
    security_json(['success' => false, 'message' => 'Dados obrigatorios invalidos'], 422);
}

$db = (new Database())->getConnection();
estoque_inteligente_schema_garantir($db);

$stmtInsumo = $db->prepare("
    SELECT id, unidade_base FROM estoque_insumos
    WHERE id = :id AND restaurante_id = :rid AND ativo = 1
    LIMIT 1
");
$stmtInsumo->execute([':id' => $insumoId, ':rid' => $rid]);
$insumoRow = $stmtInsumo->fetch(PDO::FETCH_ASSOC);
if (!$insumoRow) {
    security_json(['success' => false, 'message' => 'Insumo nao encontrado'], 404);
}

$unidade = estoque_resolver_unidade_movimento($unidade, (string)$insumoRow['unidade_base']);
$qtdBase = estoque_converter_para_base($quantidade, $unidade);
$custoUnitBase = estoque_custo_para_base($custoUnit, $unidade);
$custoPerdido = $custoUnitBase !== null ? ($qtdBase * max(0, (float)$custoUnitBase)) : 0;

$db->beginTransaction();

try {
    estoque_upsert_saldo($db, $rid, $filialId, $insumoId, -$qtdBase, $custoUnitBase);
    estoque_consumir_fifo_lotes($db, $rid, $filialId, $insumoId, $qtdBase);

    $stmtPerda = $db->prepare("
        INSERT INTO estoque_perdas
        (restaurante_id, filial_id, insumo_id, quantidade_base, custo_perdido, motivo, responsavel_id)
        VALUES
        (:rid, :filial_id, :insumo_id, :qtd, :custo, :motivo, :resp)
    ");
    $stmtPerda->execute([
        ':rid' => $rid,
        ':filial_id' => $filialId,
        ':insumo_id' => $insumoId,
        ':qtd' => $qtdBase,
        ':custo' => $custoPerdido,
        ':motivo' => $motivo,
        ':resp' => (int)$ctx['usuario_id'],
    ]);

    estoque_registrar_movimento($db, [
        'restaurante_id' => $rid,
        'filial_id' => $filialId,
        'insumo_id' => $insumoId,
        'tipo' => 'PERDA',
        'origem' => 'MANUAL',
        'quantidade_base' => $qtdBase,
        'custo_unitario_base' => $custoUnitBase,
        'custo_total' => $custoPerdido,
        'unidade_origem' => $unidade,
        'motivo' => $motivo,
        'responsavel_id' => (int)$ctx['usuario_id'],
    ]);

    $db->commit();
    estoque_inteligente_log('estoque_perda_registrada', ['restaurante_id' => $rid, 'insumo_id' => $insumoId, 'motivo' => $motivo]);
    security_json(['success' => true, 'message' => 'Perda registada com sucesso']);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('estoque_perda_registrar: ' . $e->getMessage());
    $msg = 'Erro ao registar perda';
    if (function_exists('security_is_production') && !security_is_production()) {
        $msg .= ': ' . $e->getMessage();
    }
    security_json(['success' => false, 'message' => $msg], 500);
}
