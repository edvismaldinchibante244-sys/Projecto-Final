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
    'rate_key' => 'estoque_insumo_salvar',
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

$nome = trim((string)($_POST['nome'] ?? ''));
$unidade = trim((string)($_POST['unidade'] ?? 'un'));
$estoqueMinimo = (float)($_POST['estoque_minimo'] ?? 0);
// SKU é gerado automaticamente — ignora input manual.
$insumoId = (int)($_POST['id'] ?? 0);
$filialId = (int)($_POST['filial_id'] ?? 0);
$filialId = $filialId > 0 ? $filialId : null;

if ($nome === '') {
    security_json(['success' => false, 'message' => 'Nome do insumo obrigatorio'], 422);
}

$base = estoque_unidade_base($unidade);
$estoqueMinimoBase = estoque_converter_para_base(max(0, $estoqueMinimo), $unidade);

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
    error_log('estoque_insumo_salvar dedup: ' . $dedupErr->getMessage());
}

if ($insumoId > 0) {
    $stmtCur = $db->prepare("
        SELECT unidade_base FROM estoque_insumos
        WHERE id = :id AND restaurante_id = :rid
        LIMIT 1
    ");
    $stmtCur->execute([':id' => $insumoId, ':rid' => $rid]);
    $curBase = estoque_unidade_base((string)($stmtCur->fetchColumn() ?: ''));
    $unidadeFinal = $base;
    $msgExtra = '';
    $unidadeBloqueada = false;

    // Mudar unidade com stock/ficha já gravados corrompe a baixa na venda.
    if ($curBase !== '' && $curBase !== $base) {
        $stmtSaldo = $db->prepare("
            SELECT COALESCE(SUM(saldo_base), 0)
            FROM estoque_saldos
            WHERE restaurante_id = :rid AND insumo_id = :id
        ");
        $stmtSaldo->execute([':rid' => $rid, ':id' => $insumoId]);
        $saldoAtual = (float)$stmtSaldo->fetchColumn();
        $stmtFicha = $db->prepare("
            SELECT COUNT(*)
            FROM estoque_ficha_itens fi
            JOIN estoque_fichas_tecnicas ft ON ft.id = fi.ficha_id
            WHERE ft.restaurante_id = :rid AND fi.insumo_id = :id
        ");
        $stmtFicha->execute([':rid' => $rid, ':id' => $insumoId]);
        $emFicha = (int)$stmtFicha->fetchColumn() > 0;
        if ($saldoAtual > 0.0001 || $emFicha) {
            $unidadeFinal = $curBase;
            $unidadeBloqueada = true;
            $motivoBloq = $saldoAtual > 0.0001 && $emFicha
                ? 'já há stock e ficha técnica'
                : ($saldoAtual > 0.0001 ? 'já há stock' : 'já está numa ficha técnica');
            $msgExtra = '. Nome/mínimo gravados; unidade mantida em «' . $unidadeFinal . '» porque ' . $motivoBloq . '. Para mudar a unidade, zere o stock e remova o insumo das fichas.';
        }
    }

    // Se a unidade ficou bloqueada, o mínimo deve usar a unidade real (não a tentada no form).
    if ($unidadeBloqueada) {
        if (estoque_unidade_base($unidade) === $unidadeFinal) {
            $unidadeConv = estoque_resolver_unidade_movimento($unidade, $unidadeFinal);
        } else {
            $unidadeConv = estoque_unidade_preferida_entrada($unidadeFinal);
        }
        $estoqueMinimoBase = estoque_converter_para_base(max(0, $estoqueMinimo), $unidadeConv);
    }

    $stmt = $db->prepare("
        UPDATE estoque_insumos
        SET nome = :nome,
            unidade_base = :unidade,
            estoque_minimo_base = :estoque_min
        WHERE id = :id
          AND restaurante_id = :rid
    ");
    $stmt->execute([
        ':nome' => $nome,
        ':unidade' => $unidadeFinal,
        ':estoque_min' => $estoqueMinimoBase,
        ':id' => $insumoId,
        ':rid' => $rid,
    ]);
    $skuAuto = estoque_garantir_sku_insumo($db, $rid, $insumoId);
    estoque_inteligente_log('estoque_insumo_atualizado', ['insumo_id' => $insumoId, 'restaurante_id' => $rid, 'sku' => $skuAuto]);
    security_json([
        'success' => true,
        'message' => 'Insumo atualizado com sucesso' . $msgExtra,
        'sku' => $skuAuto,
        'unidade_base' => $unidadeFinal,
        'unidade_bloqueada' => $unidadeBloqueada,
    ]);
}

$nomeNorm = estoque_normalizar_nome_chave($nome);
if ($filialId === null) {
    $stmtExists = $db->prepare("
        SELECT id
        FROM estoque_insumos
        WHERE restaurante_id = :rid
          AND ativo = 1
          AND unidade_base = :unidade
          AND filial_id IS NULL
          AND LOWER(TRIM(nome)) = :nome_norm
        LIMIT 1
    ");
    $stmtExists->execute([
        ':rid' => $rid,
        ':unidade' => $base,
        ':nome_norm' => $nomeNorm,
    ]);
} else {
    $stmtExists = $db->prepare("
        SELECT id
        FROM estoque_insumos
        WHERE restaurante_id = :rid
          AND ativo = 1
          AND unidade_base = :unidade
          AND filial_id = :filial_id
          AND LOWER(TRIM(nome)) = :nome_norm
        LIMIT 1
    ");
    $stmtExists->execute([
        ':rid' => $rid,
        ':unidade' => $base,
        ':filial_id' => $filialId,
        ':nome_norm' => $nomeNorm,
    ]);
}
$existingId = (int)($stmtExists->fetchColumn() ?: 0);
if ($existingId > 0) {
    $stmtMin = $db->prepare("
        UPDATE estoque_insumos
        SET estoque_minimo_base = :estoque_min,
            atualizado_em = NOW()
        WHERE id = :id
          AND restaurante_id = :rid
    ");
    $stmtMin->execute([
        ':estoque_min' => $estoqueMinimoBase,
        ':id' => $existingId,
        ':rid' => $rid,
    ]);
    $skuAuto = estoque_garantir_sku_insumo($db, $rid, $existingId);
    security_json(['success' => true, 'message' => 'Insumo ja existia e foi atualizado', 'id' => $existingId, 'sku' => $skuAuto]);
}

$stmt = $db->prepare("
    INSERT INTO estoque_insumos
    (restaurante_id, filial_id, nome, sku, unidade_base, estoque_minimo_base, ativo)
    VALUES
    (:rid, :filial_id, :nome, NULL, :unidade, :estoque_min, 1)
");
$stmt->execute([
    ':rid' => $rid,
    ':filial_id' => $filialId,
    ':nome' => $nome,
    ':unidade' => $base,
    ':estoque_min' => $estoqueMinimoBase,
]);

$novoId = (int)$db->lastInsertId();
$skuAuto = estoque_garantir_sku_insumo($db, $rid, $novoId);
estoque_inteligente_log('estoque_insumo_criado', ['insumo_id' => $novoId, 'restaurante_id' => $rid, 'sku' => $skuAuto]);
security_json(['success' => true, 'message' => 'Insumo cadastrado', 'id' => $novoId, 'sku' => $skuAuto]);
