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
    'rate_key' => 'estoque_inteligente_dashboard',
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

$filialId = isset($_GET['filial_id']) ? (int)$_GET['filial_id'] : 0;
$filialId = $filialId > 0 ? $filialId : null;
$dias = isset($_GET['dias']) ? (int)$_GET['dias'] : 28;

$dadosPlanoEst = function_exists('plano_get_dados') ? plano_get_dados($planoRid) : [];
$planoNomeEst = function_exists('plano_normalizar_nome')
    ? plano_normalizar_nome($dadosPlanoEst['plano_nome'] ?? 'BASICO')
    : 'BASICO';
$isCockpitEmp = ($planoNomeEst === 'EMPRESARIAL');

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
        error_log('estoque_inteligente_dashboard dedup: ' . $dedupErr->getMessage());
    }

    try {
        estoque_backfill_skus($db, $rid);
    } catch (Throwable $skuErr) {
        error_log('estoque_inteligente_dashboard sku: ' . $skuErr->getMessage());
    }

    try {
        estoque_corrigir_custos_inflados($db, $rid);
    } catch (Throwable $custoErr) {
        error_log('estoque_inteligente_dashboard custos: ' . $custoErr->getMessage());
    }

    try {
        estoque_gerar_alertas_automaticos($db, $rid, $filialId);
    } catch (Throwable $alertaErr) {
        error_log('estoque_inteligente_dashboard alertas: ' . $alertaErr->getMessage());
    }

    $sqlSaldos = "
        SELECT i.id AS insumo_id, i.nome, i.sku, i.unidade_base, i.estoque_minimo_base,
               COALESCE(s.saldo_base, 0) AS saldo_base,
               COALESCE(s.custo_medio, 0) AS custo_medio
        FROM estoque_insumos i
        LEFT JOIN estoque_saldos s
          ON s.insumo_id = i.id
         AND s.restaurante_id = i.restaurante_id
    ";
    if ($filialId === null) {
        $sqlSaldos .= " AND ((s.filial_id IS NULL AND i.filial_id IS NULL) OR s.filial_id = i.filial_id)";
    } else {
        $sqlSaldos .= " AND s.filial_id = :filial_id_saldo";
    }
    $sqlSaldos .= "
        WHERE i.restaurante_id = :rid
    ";
    if ($filialId !== null) {
        $sqlSaldos .= " AND i.filial_id = :filial_id_insumo";
    }
    $sqlSaldos .= "
          AND i.ativo = 1
        ORDER BY i.nome ASC
    ";

    $stmtSaldos = $db->prepare($sqlSaldos);
    $stmtSaldos->bindValue(':rid', $rid, PDO::PARAM_INT);
    if ($filialId !== null) {
        $stmtSaldos->bindValue(':filial_id_saldo', $filialId, PDO::PARAM_INT);
        $stmtSaldos->bindValue(':filial_id_insumo', $filialId, PDO::PARAM_INT);
    }
    $stmtSaldos->execute();
    $saldosRaw = $stmtSaldos->fetchAll(PDO::FETCH_ASSOC);

    // Próxima validade por insumo (lote com saldo > 0 e validade registada).
    $sqlVal = "
        SELECT l.insumo_id,
               MIN(l.validade) AS validade_proxima,
               MIN(DATEDIFF(l.validade, CURDATE())) AS dias_validade
        FROM estoque_lotes l
        WHERE l.restaurante_id = :rid
          AND (:filial_id_null IS NULL OR l.filial_id = :filial_id_val)
          AND l.saldo_base > 0.0001
          AND l.validade IS NOT NULL
        GROUP BY l.insumo_id
    ";
    $stmtVal = $db->prepare($sqlVal);
    $stmtVal->execute([
        ':rid' => $rid,
        ':filial_id_null' => $filialId,
        ':filial_id_val' => $filialId,
    ]);
    $valMap = [];
    foreach ($stmtVal->fetchAll(PDO::FETCH_ASSOC) as $vRow) {
        $valMap[(int)$vRow['insumo_id']] = [
            'validade_proxima' => (string)$vRow['validade_proxima'],
            'dias_validade' => (int)$vRow['dias_validade'],
        ];
    }

    $saldosMap = [];
    foreach ($saldosRaw as $row) {
        $insumoId = (int)$row['insumo_id'];
        $v = $valMap[$insumoId] ?? null;
        $row['validade_proxima'] = $v['validade_proxima'] ?? null;
        $row['dias_validade'] = $v['dias_validade'] ?? null;

        $nome = (string)($row['nome'] ?? '');
        $unidade = (string)($row['unidade_base'] ?? 'un');
        $key = estoque_normalizar_nome_chave($nome) . '|' . $unidade;
        if (!isset($saldosMap[$key])) {
            $saldosMap[$key] = $row;
            continue;
        }
        $saldosMap[$key]['saldo_base'] = (float)$saldosMap[$key]['saldo_base'] + (float)$row['saldo_base'];
        $saldosMap[$key]['estoque_minimo_base'] = max((float)$saldosMap[$key]['estoque_minimo_base'], (float)$row['estoque_minimo_base']);
        // Mantém a validade mais próxima entre insumos fundidos (mesmo nome).
        $diasAtual = $saldosMap[$key]['dias_validade'];
        $diasNovo = $row['dias_validade'];
        if ($diasNovo !== null && ($diasAtual === null || (int)$diasNovo < (int)$diasAtual)) {
            $saldosMap[$key]['validade_proxima'] = $row['validade_proxima'];
            $saldosMap[$key]['dias_validade'] = $row['dias_validade'];
        }
    }
    $saldos = array_values($saldosMap);

    $sqlPerdas = "
        SELECT COUNT(*) AS total_registros, COALESCE(SUM(custo_perdido),0) AS custo_total
        FROM estoque_perdas
        WHERE restaurante_id = :rid
          AND criado_em >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ";
    if ($filialId !== null) {
        $sqlPerdas .= " AND filial_id = :filial_id_perda";
    }
    $stmtPerdas = $db->prepare($sqlPerdas);
    $stmtPerdas->bindValue(':rid', $rid, PDO::PARAM_INT);
    if ($filialId !== null) {
        $stmtPerdas->bindValue(':filial_id_perda', $filialId, PDO::PARAM_INT);
    }
    $stmtPerdas->execute();
    $perdas = $stmtPerdas->fetch(PDO::FETCH_ASSOC) ?: ['total_registros' => 0, 'custo_total' => 0];

    $sqlAlertas = "
        SELECT id, insumo_id, tipo, severidade, titulo, detalhe, criado_em
        FROM estoque_alertas
        WHERE restaurante_id = :rid
          AND resolvido = 0
    ";
    // Mesmo contexto que os saldos mostrados: na matriz so os alertas da matriz (filial_id NULL);
    // antes apareciam tambem os das filiais, que nunca se fechavam aqui (contador sem nada na tabela).
    if ($filialId !== null) {
        $sqlAlertas .= " AND filial_id = :filial_id_alerta";
    } else {
        $sqlAlertas .= " AND filial_id IS NULL";
    }
    $sqlAlertas .= "
        ORDER BY
          CASE severidade WHEN 'CRITICA' THEN 1 WHEN 'ALTA' THEN 2 WHEN 'MEDIA' THEN 3 ELSE 4 END,
          criado_em DESC
        LIMIT 40
    ";
    $stmtAlertas = $db->prepare($sqlAlertas);
    $stmtAlertas->bindValue(':rid', $rid, PDO::PARAM_INT);
    if ($filialId !== null) {
        $stmtAlertas->bindValue(':filial_id_alerta', $filialId, PDO::PARAM_INT);
    }
    $stmtAlertas->execute();
    $alertas = $stmtAlertas->fetchAll(PDO::FETCH_ASSOC);

    $previsao = [];
    $insights = [
        'valor_stock' => 0,
        'produtos_sem_ficha' => 0,
        'dias_cobertura_media' => 0,
        'insumos_criticos_previstos' => 0,
        'sugestoes_compra' => [],
        'custo_reposicao_sugerida' => 0,
        'score_saude' => 100,
        'lotes_em_risco' => 0,
        'perdas_indice' => 0,
        'cobertura_distribuicao' => ['critico' => 0, 'baixo' => 0, 'ok' => 0],
        'motivos_top' => [],
        'sugestoes_transferencia' => [],
        'desequilibrio_rede' => 0,
        'modo' => $isCockpitEmp ? 'empresarial' : 'operacional',
    ];
    $lotesValidade = [];
    $redeSugestoes = [];

    if ($isCockpitEmp) {
        try {
            $lotesValidade = estoque_listar_lotes_validade($db, $rid, $filialId, 30);
        } catch (Throwable $loteErr) {
            error_log('estoque_inteligente_dashboard lotes: ' . $loteErr->getMessage());
        }

        try {
            $redeSugestoes = estoque_insights_rede($db, $planoRid);
        } catch (Throwable $redeErr) {
            error_log('estoque_inteligente_dashboard rede: ' . $redeErr->getMessage());
            $redeSugestoes = [];
        }

        try {
            $previsao = estoque_previsao_consumo($db, $rid, $filialId, $dias, ['modo' => 'empresarial']);
            $insights = estoque_insights_empresariais($db, $rid, $filialId, $previsao, [
                'perdas_30d' => [
                    'total_registros' => (int)$perdas['total_registros'],
                    'custo_total' => (float)$perdas['custo_total'],
                ],
                'lotes_validade' => $lotesValidade,
                'sugestoes_transferencia' => $redeSugestoes,
            ]);
        } catch (Throwable $prevErr) {
            error_log('estoque_inteligente_dashboard previsao emp: ' . $prevErr->getMessage());
        }
    } else {
        // Profissional: operação (saldos/alertas/perdas). Sem cockpit de previsão/rede.
        try {
            $stmtSemFichaPro = $db->prepare("
                SELECT COUNT(*)
                FROM produtos p
                WHERE p.restaurante_id = :rid
                  AND COALESCE(p.ativo, 1) = 1
                  AND NOT EXISTS (
                      SELECT 1 FROM estoque_fichas_tecnicas ft
                      WHERE ft.produto_id = p.id
                        AND ft.restaurante_id = p.restaurante_id
                        AND ft.ativo = 1
                  )
            ");
            $stmtSemFichaPro->execute([':rid' => $rid]);
            $insights['produtos_sem_ficha'] = (int)$stmtSemFichaPro->fetchColumn();
        } catch (Throwable $sfErr) {
            error_log('estoque_inteligente_dashboard sem_ficha pro: ' . $sfErr->getMessage());
        }

        try {
            $stmtValorPro = $db->prepare("
                SELECT COALESCE(SUM(COALESCE(s.saldo_base,0) * COALESCE(s.custo_medio,0)), 0)
                FROM estoque_saldos s
                JOIN estoque_insumos i ON i.id = s.insumo_id AND i.ativo = 1
                WHERE s.restaurante_id = :rid
                  AND (:filial_id_null IS NULL OR s.filial_id = :filial_id_val)
            ");
            $stmtValorPro->execute([
                ':rid' => $rid,
                ':filial_id_null' => $filialId,
                ':filial_id_val' => $filialId,
            ]);
            $insights['valor_stock'] = round((float)$stmtValorPro->fetchColumn(), 2);
        } catch (Throwable $valErr) {
            error_log('estoque_inteligente_dashboard valor pro: ' . $valErr->getMessage());
        }
    }

    $prevMap = [];
    foreach ($previsao as $p) {
        $prevMap[(int)$p['insumo_id']] = $p;
    }
    foreach ($saldos as &$sRow) {
        $p = $prevMap[(int)$sRow['insumo_id']] ?? null;
        $sRow['dias_cobertura'] = $p['dias_cobertura'] ?? null;
        $sRow['sugestao_compra_base'] = $p['sugestao_compra_base'] ?? 0;
        $sRow['minimo_sugerido_base'] = $p['minimo_sugerido_base'] ?? (float)$sRow['estoque_minimo_base'];
        $sRow['risco'] = $p['risco'] ?? (
            ((float)$sRow['saldo_base'] <= 0) ? 'CRITICO' : (
                ((float)$sRow['estoque_minimo_base'] > 0 && (float)$sRow['saldo_base'] <= (float)$sRow['estoque_minimo_base']) ? 'BAIXO' : 'OK'
            )
        );
        if ($p) {
            $sRow['motivo_risco'] = $p['motivo_risco'] ?? null;
            $sRow['tendencia'] = $p['tendencia'] ?? null;
        }
    }
    unset($sRow);

    security_json([
        'success' => true,
        'data' => [
            'plano' => $planoNomeEst,
            'cockpit_empresarial' => $isCockpitEmp,
            'saldos' => $saldos,
            'perdas_30d' => [
                'total_registros' => (int)$perdas['total_registros'],
                'custo_total' => (float)$perdas['custo_total'],
            ],
            'alertas_abertos' => $alertas,
            'previsao_consumo' => $isCockpitEmp ? $previsao : [],
            'insights' => $insights,
            'lotes_validade' => $isCockpitEmp ? $lotesValidade : [],
            'sugestoes_transferencia' => $isCockpitEmp ? $redeSugestoes : [],
        ]
    ]);
} catch (Throwable $e) {
    error_log('estoque_inteligente_dashboard: ' . $e->getMessage());
    security_json(['success' => false, 'message' => 'Erro ao carregar dashboard de estoque'], 500);
}
