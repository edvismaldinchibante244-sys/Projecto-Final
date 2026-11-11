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
    'rate_key' => 'estoque_movimento_registrar',
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

$insumoId = (int)($_POST['insumo_id'] ?? 0);
$tipo = strtoupper(trim((string)($_POST['tipo'] ?? 'ENTRADA')));
$origem = strtoupper(trim((string)($_POST['origem'] ?? 'MANUAL')));
$quantidade = (float)str_replace(',', '.', (string)($_POST['quantidade'] ?? 0));
$unidade = trim((string)($_POST['unidade'] ?? 'un'));
$custoRaw = trim((string)($_POST['custo_unitario'] ?? ''));
$custoUnit = $custoRaw !== '' ? (float)str_replace(',', '.', $custoRaw) : null;
$validade = trim((string)($_POST['validade'] ?? ''));
$referencia = trim((string)($_POST['referencia'] ?? ''));
$motivo = trim((string)($_POST['motivo'] ?? ''));
$filialId = (int)($_POST['filial_id'] ?? 0);
$filialId = $filialId > 0 ? $filialId : null;

if ($validade !== '') {
    $dtVal = DateTime::createFromFormat('Y-m-d', $validade);
    if (!($dtVal && $dtVal->format('Y-m-d') === $validade)) {
        $dtVal = DateTime::createFromFormat('d/m/Y', $validade)
            ?: DateTime::createFromFormat('m/d/Y', $validade);
        if ($dtVal) {
            $validade = $dtVal->format('Y-m-d');
        } else {
            security_json(['success' => false, 'message' => 'Data de validade invalida'], 422);
        }
    }
}

if ($filialId !== null || $origem === 'TRANSFERENCIA' || $tipo === 'TRANSFERENCIA') {
    if (!plano_tem_funcionalidade_db($planoRid, 'transferencia_estoque')) {
        security_json([
            'success' => false,
            'message' => 'Transferencia de estoque disponivel apenas no plano Empresarial.',
            'funcionalidade' => 'transferencia_estoque',
        ], 403);
    }
}

if ($insumoId <= 0 || $quantidade <= 0) {
    security_json(['success' => false, 'message' => 'Dados invalidos'], 422);
}

$permitidos = ['ENTRADA', 'SAIDA', 'AJUSTE'];
if (!in_array($tipo, $permitidos, true)) {
    $tipo = 'ENTRADA';
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

// Unidade vem do cadastro do insumo (ex.: fatia). Peso/volume permitem kg↔g e l↔ml.
$unidade = estoque_resolver_unidade_movimento($unidade, (string)$insumoRow['unidade_base']);
$baseQtd = estoque_converter_para_base($quantidade, $unidade);
$custoUnitBase = estoque_custo_para_base($custoUnit, $unidade);
if ($tipo === 'SAIDA') {
    $baseQtd *= -1;
}

$db->beginTransaction();

try {
    estoque_upsert_saldo($db, $rid, $filialId, $insumoId, $baseQtd, $custoUnitBase);

    if ($tipo === 'SAIDA') {
        estoque_consumir_fifo_lotes($db, $rid, $filialId, $insumoId, abs($baseQtd));
    } elseif ($tipo === 'ENTRADA') {
        $stmtLote = $db->prepare("
            INSERT INTO estoque_lotes
            (restaurante_id, filial_id, insumo_id, quantidade_base, saldo_base, custo_unitario_base, validade, referencia_compra)
            VALUES
            (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtLote->bindValue(1, $rid, PDO::PARAM_INT);
        if ($filialId === null) {
            $stmtLote->bindValue(2, null, PDO::PARAM_NULL);
        } else {
            $stmtLote->bindValue(2, $filialId, PDO::PARAM_INT);
        }
        $stmtLote->bindValue(3, $insumoId, PDO::PARAM_INT);
        $stmtLote->bindValue(4, abs($baseQtd));
        $stmtLote->bindValue(5, abs($baseQtd));
        $stmtLote->bindValue(6, $custoUnitBase ?? 0);
        if ($validade !== '') {
            $stmtLote->bindValue(7, $validade);
        } else {
            $stmtLote->bindValue(7, null, PDO::PARAM_NULL);
        }
        if ($referencia !== '') {
            $stmtLote->bindValue(8, substr($referencia, 0, 120));
        } else {
            $stmtLote->bindValue(8, null, PDO::PARAM_NULL);
        }
        $stmtLote->execute();
    }

    estoque_registrar_movimento($db, [
        'restaurante_id' => $rid,
        'filial_id' => $filialId,
        'insumo_id' => $insumoId,
        'tipo' => $tipo,
        'origem' => in_array($origem, ['COMPRA', 'PEDIDO', 'COZINHA', 'CAIXA', 'MANUAL', 'SISTEMA'], true) ? $origem : 'MANUAL',
        'quantidade_base' => abs($baseQtd),
        'custo_unitario_base' => $custoUnitBase,
        'custo_total' => $custoUnitBase !== null ? abs($baseQtd) * $custoUnitBase : null,
        'unidade_origem' => $unidade,
        'motivo' => $motivo !== '' ? $motivo : ($referencia !== '' ? $referencia : null),
        'responsavel_id' => (int)$ctx['usuario_id'],
    ]);

    $db->commit();

    // Fora da transação: falha de alertas não pode anular a entrada.
    try {
        estoque_gerar_alertas_automaticos($db, $rid, $filialId);
    } catch (Throwable $alertaErr) {
        error_log('estoque_movimento_registrar alertas: ' . $alertaErr->getMessage());
    }

    estoque_inteligente_log('estoque_movimento_manual', [
        'restaurante_id' => $rid,
        'insumo_id' => $insumoId,
        'tipo' => $tipo,
        'quantidade_base' => abs($baseQtd),
    ]);
    security_json(['success' => true, 'message' => 'Movimento registado com sucesso']);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('estoque_movimento_registrar: ' . $e->getMessage());
    security_json([
        'success' => false,
        'message' => 'Erro ao registrar movimento: ' . $e->getMessage(),
    ], 500);
}
