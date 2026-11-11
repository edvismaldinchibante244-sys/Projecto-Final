<?php


/**
 * Dados do dashboard (src/public/dashboard.php), separados da apresentacao.
 *
 * Fase B do plano de divisao dos ficheiros gigantes: cada metodo e um bloco
 * de consultas que estava no topo do dashboard.php, movido sem alteracoes.
 * As constantes ENTRADAS_/SAIDAS_ documentam o que cada metodo usa e devolve.
 */
final class DashboardService
{
    /** Variaveis da pagina lidas por resumoRede(). */
    public const ENTRADAS_RESUMO_REDE = ['planoRid', 'resumoExecutivoRede', 'rid'];
    /** Variaveis que resumoRede() devolve a pagina. */
    public const SAIDAS_RESUMO_REDE = ['a', 'b', 'resumoExecutivoRede', 'row', 'sqlVendas'];

    /** Variaveis da pagina lidas por dadosOperacionais(). */
    public const ENTRADAS_DADOS_OPERACIONAIS = ['caixa_aberto', 'caixa_dashboard', 'dados_grafico', 'garcom_dashboard', 'perfil', 'qtd_hoje', 'rid', 'stmt_semana', 'stmt_ultimas', 'total_hoje', 'ultimas_vendas', 'usuario_logado_id', 'vendas_semana'];
    /** Variaveis que dadosOperacionais() devolve a pagina. */
    public const SAIDAS_DADOS_OPERACIONAIS = ['caixa_aberto', 'caixa_dashboard', 'dados_grafico', 'garcom_dashboard', 'qtd_hoje', 'total_hoje', 'ultimas_vendas'];

    /** Variaveis da pagina lidas por dadosAdmin(). */
    public const ENTRADAS_DADOS_ADMIN = ['a', 'b', 'by_canal', 'by_canal_hoje', 'crescimento_mensal_pct', 'dados_grafico', 'dashEmpOportunidades', 'dashEmpOppMeta', 'dashEmpReceita', 'dashEmpTopMeta', 'dashEmpTopProdutos', 'e', 'itemGrafico', 'm', 'mediaDia', 'media_diaria_14d', 'oldest_pending_minutes', 'oldest_pending_orders', 'pedidos_hoje_total', 'pedidos_resumo', 'pico_14d', 'pico_14d_data', 'plano_atual', 'qtd_hoje', 'receita_14_dias_total', 'receita_mes_anterior', 'receita_mes_atual', 'rid', 'sqlVendas', 'sugestao_upgrade', 'team_online', 'team_performance', 'tendencia_delta_pct', 'top_caixa', 'top_garcom', 'total_hoje', 'total_produtos'];
    /** Variaveis que dadosAdmin() devolve a pagina. */
    public const SAIDAS_DADOS_ADMIN = ['a', 'b', 'by_canal', 'by_canal_hoje', 'crescimento_mensal_pct', 'd', 'dados_grafico', 'dashEmpOportunidades', 'dashEmpOppMeta', 'dashEmpReceita', 'dashEmpTopMeta', 'dashEmpTopProdutos', 'm', 'media_diaria_14d', 'oldest_pending_minutes', 'oldest_pending_orders', 'op', 'pedidos_hoje_total', 'pedidos_resumo', 'pedidos_resumo_db', 'pico_14d', 'pico_14d_data', 'r', 'receita_14_dias_total', 'receita_mes_anterior', 'receita_mes_atual', 'stmt_pedidos_resumo', 'team_online', 'team_performance', 'tendencia_delta_pct', 'top_caixa', 'top_garcom', 'uid'];

    /** Variaveis da pagina lidas por dadosGerente(). */
    public const ENTRADAS_DADOS_GERENTE = ['e', 'pedidos_resumo', 'pedidos_resumo_db', 'rid', 'stmt_pedidos_resumo', 'team_online'];
    /** Variaveis que dadosGerente() devolve a pagina. */
    public const SAIDAS_DADOS_GERENTE = ['pedidos_resumo', 'team_online'];

    /** Variaveis da pagina lidas por estoqueSimples(). */
    public const ENTRADAS_ESTOQUE_SIMPLES = ['estoqueSimplesResumo', 'rid'];
    /** Variaveis que estoqueSimples() devolve a pagina. */
    public const SAIDAS_ESTOQUE_SIMPLES = ['estoqueSimplesResumo'];

    /** Variaveis da pagina lidas por estoqueAvancado(). */
    public const ENTRADAS_ESTOQUE_AVANCADO = ['authRid', 'estoqueCardLink', 'estoqueCardLinkLabel', 'estoqueCardTitulo', 'estoque_alertas_feed', 'estoque_baixo', 'estoque_previsao_map', 'estoque_resumo', 'planoRid', 'plano_atual', 'rid'];
    /** Variaveis que estoqueAvancado() devolve a pagina. */
    public const SAIDAS_ESTOQUE_AVANCADO = ['a', 'b', 'diasCob', 'estoqueCardLink', 'estoqueCardLinkLabel', 'estoqueCardTitulo', 'estoque_alertas_feed', 'estoque_baixo', 'estoque_previsao_map', 'estoque_resumo', 'saldoAl'];

    /** Variaveis da pagina lidas por estoqueInsightsEmpresarial(). */
    public const ENTRADAS_ESTOQUE_INSIGHTS_EMPRESARIAL = ['authRid', 'dashEmpInsights', 'estoque_previsao_map', 'planoRid', 'rid'];
    /** Variaveis que estoqueInsightsEmpresarial() devolve a pagina. */
    public const SAIDAS_ESTOQUE_INSIGHTS_EMPRESARIAL = ['dashEmpInsights'];

    /** Variaveis da pagina lidas por estoqueNotasEmpresarial(). */
    public const ENTRADAS_ESTOQUE_NOTAS_EMPRESARIAL = ['dashEmpInsights', 'dashEmpStockNotas', 'estoque_previsao_map', 'estoque_resumo'];
    /** Variaveis que estoqueNotasEmpresarial() devolve a pagina. */
    public const SAIDAS_ESTOQUE_NOTAS_EMPRESARIAL = ['dashEmpInsights', 'dashEmpStockNotas', 'estoque_resumo'];

    /** Variaveis da pagina lidas por reservasHoje(). */
    public const ENTRADAS_RESERVAS_HOJE = ['dashboard_reservas', 'e', 'rid'];
    /** Variaveis que reservasHoje() devolve a pagina. */
    public const SAIDAS_RESERVAS_HOJE = ['dashboard_reservas'];

    private ?PDO $db;

    public function __construct(?PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Resumo executivo da rede de filiais (admin Empresarial com multi-filial).
     * Recebe as variaveis ENTRADAS_RESUMO_REDE e devolve as SAIDAS_RESUMO_REDE que ficaram definidas.
     */
    public function resumoRede(array $entrada): array
    {
        extract($entrada);
        $db = $this->db;

        try {
            $matrizId = $planoRid > 0 ? $planoRid : $rid;
            $stmtFiliais = $db->prepare("
                SELECT id, nome, status
                FROM restaurantes
                WHERE filial_id = :matriz AND COALESCE(is_matriz, 0) = 0
                ORDER BY nome ASC
            ");
            $stmtFiliais->execute([':matriz' => $matrizId]);
            $filiaisRede = $stmtFiliais->fetchAll(PDO::FETCH_ASSOC);
            $filialIds = array_map(static fn($f) => (int)$f['id'], $filiaisRede);
            $receita30 = 0.0;
            $vendas30 = 0;
            if ($filialIds) {
                $ph = implode(',', array_fill(0, count($filialIds), '?'));
                $sqlVendas = "
                    SELECT restaurante_id,
                           COUNT(*) AS qtd,
                           COALESCE(SUM(total_final), 0) AS receita
                    FROM vendas
                    WHERE restaurante_id IN ($ph)
                      AND UPPER(TRIM(COALESCE(status,''))) = 'PAGO'
                      AND criado_em >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    GROUP BY restaurante_id
                ";
                $stmtV = $db->prepare($sqlVendas);
                $stmtV->execute($filialIds);
                $porFilial = [];
                foreach ($stmtV->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $porFilial[(int)$row['restaurante_id']] = $row;
                    $receita30 += (float)$row['receita'];
                    $vendas30 += (int)$row['qtd'];
                }
                foreach ($filiaisRede as &$f) {
                    $id = (int)$f['id'];
                    $f['receita_30d'] = (float)($porFilial[$id]['receita'] ?? 0);
                    $f['vendas_30d'] = (int)($porFilial[$id]['qtd'] ?? 0);
                    $f['share'] = $receita30 > 0 ? round(($f['receita_30d'] / $receita30) * 100, 1) : 0.0;
                }
                unset($f);
                usort($filiaisRede, static fn($a, $b) => ($b['receita_30d'] <=> $a['receita_30d']));
            }
            $resumoExecutivoRede = [
                'total_filiais' => count($filiaisRede),
                'ativas' => count(array_filter($filiaisRede, static fn($f) => strtoupper((string)($f['status'] ?? '')) === 'ATIVO')),
                'receita_30d' => $receita30,
                'vendas_30d' => $vendas30,
                'top' => array_slice($filiaisRede, 0, 5),
                'a_cair' => null,
                'nota' => null,
            ];

            // Incluir a matriz no ranking se tiver vendas (evita ranking só a zeros).
            try {
                $stmtMatrizV = $db->prepare("
                    SELECT COUNT(*) AS qtd, COALESCE(SUM(total_final), 0) AS receita
                    FROM vendas
                    WHERE restaurante_id = ?
                      AND UPPER(TRIM(COALESCE(status,''))) = 'PAGO'
                      AND criado_em >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                ");
                $stmtMatrizV->execute([$matrizId]);
                $rowMat = $stmtMatrizV->fetch(PDO::FETCH_ASSOC) ?: [];
                $recMat = (float)($rowMat['receita'] ?? 0);
                $qtdMat = (int)($rowMat['qtd'] ?? 0);
                if ($recMat > 0 || $qtdMat > 0) {
                    $stmtMatrizNome = $db->prepare("SELECT nome FROM restaurantes WHERE id = ? LIMIT 1");
                    $stmtMatrizNome->execute([$matrizId]);
                    $nomeMat = (string)($stmtMatrizNome->fetchColumn() ?: 'Matriz');
                    $receita30 += $recMat;
                    $vendas30 += $qtdMat;
                    $filiaisRede[] = [
                        'id' => $matrizId,
                        'nome' => $nomeMat . ' (matriz)',
                        'status' => 'ATIVO',
                        'receita_30d' => $recMat,
                        'vendas_30d' => $qtdMat,
                        'share' => 0,
                        'is_matriz' => true,
                    ];
                    // recalcular shares
                    foreach ($filiaisRede as &$fRecalc) {
                        $fRecalc['share'] = $receita30 > 0 ? round(((float)$fRecalc['receita_30d'] / $receita30) * 100, 1) : 0.0;
                    }
                    unset($fRecalc);
                    usort($filiaisRede, static fn($a, $b) => ((float)$b['receita_30d'] <=> (float)$a['receita_30d']));
                    $resumoExecutivoRede['receita_30d'] = $receita30;
                    $resumoExecutivoRede['vendas_30d'] = $vendas30;
                    $resumoExecutivoRede['top'] = array_slice($filiaisRede, 0, 5);
                }
            } catch (Throwable $eMat) {
                // mantém ranking só de filiais
            }

            // Comparar 30d actuais vs 30d anteriores para achar quem está a cair.
            try {
                $idsCmp = array_values(array_unique(array_map(static fn($f) => (int)$f['id'], $filiaisRede)));
                if ($idsCmp) {
                    $ph2 = implode(',', array_fill(0, count($idsCmp), '?'));
                    $stmtPrev = $db->prepare("
                        SELECT restaurante_id, COALESCE(SUM(total_final), 0) AS receita
                        FROM vendas
                        WHERE restaurante_id IN ($ph2)
                          AND UPPER(TRIM(COALESCE(status,''))) = 'PAGO'
                          AND criado_em >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
                          AND criado_em < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                        GROUP BY restaurante_id
                    ");
                    $stmtPrev->execute($idsCmp);
                    $prevMap = [];
                    foreach ($stmtPrev->fetchAll(PDO::FETCH_ASSOC) as $rp) {
                        $prevMap[(int)$rp['restaurante_id']] = (float)$rp['receita'];
                    }
                    $piorDelta = null;
                    $piorFilial = null;
                    foreach ($filiaisRede as $fCmp) {
                        $idc = (int)$fCmp['id'];
                        $atual = (float)($fCmp['receita_30d'] ?? 0);
                        $ant = (float)($prevMap[$idc] ?? 0);
                        if ($ant <= 0 && $atual <= 0) {
                            continue;
                        }
                        $delta = $ant > 0 ? (($atual - $ant) / $ant) * 100 : ($atual > 0 ? 100.0 : 0.0);
                        if ($piorDelta === null || $delta < $piorDelta) {
                            $piorDelta = $delta;
                            $piorFilial = [
                                'nome' => (string)$fCmp['nome'],
                                'delta_pct' => $delta,
                                'receita_30d' => $atual,
                                'receita_ant' => $ant,
                            ];
                        }
                    }
                    if ($piorFilial && (float)$piorFilial['delta_pct'] <= -10) {
                        $resumoExecutivoRede['a_cair'] = $piorFilial;
                        $resumoExecutivoRede['nota'] = [
                            'tom' => 'warn',
                            'titulo' => $piorFilial['nome'] . ' está a cair',
                            'txt' => 'Nos últimos 30 dias veio ' . number_format(abs((float)$piorFilial['delta_pct']), 0, ',', '.') . '% abaixo do mês anterior (MZN ' . number_format((float)$piorFilial['receita_30d'], 0, ',', '.') . ' vs MZN ' . number_format((float)$piorFilial['receita_ant'], 0, ',', '.') . '). Vale rever stock, turnos e promoções nessa unidade.',
                        ];
                    } elseif ($receita30 <= 0) {
                        $resumoExecutivoRede['nota'] = [
                            'tom' => 'info',
                            'titulo' => 'Rede ainda sem vendas pagas neste filtro',
                            'txt' => 'Quando as filiais registarem vendas pagas, o ranking e a comparação aparecem aqui.',
                        ];
                    } else {
                        $resumoExecutivoRede['nota'] = [
                            'tom' => 'ok',
                            'titulo' => 'Rede estável neste período',
                            'txt' => 'Nenhuma unidade caiu mais de 10% vs os 30 dias anteriores. Continue a acompanhar o top do ranking.',
                        ];
                    }
                }
            } catch (Throwable $eCmp) {
                // sem comparação
            }
        } catch (Throwable $e) {
            $resumoExecutivoRede = null;
        }

        return array_intersect_key(get_defined_vars(), array_flip(self::SAIDAS_RESUMO_REDE));
    }

    /**
     * Dados do dashboard de garcom e caixa (o que cada um vendeu/atendeu hoje).
     * Recebe as variaveis ENTRADAS_DADOS_OPERACIONAIS e devolve as SAIDAS_DADOS_OPERACIONAIS que ficaram definidas.
     */
    public function dadosOperacionais(array $entrada): array
    {
        extract($entrada);
        $db = $this->db;

        if ($perfil === 'GARCOM') {
            $stmt_pedidos_usuario_hoje = $db->prepare("SELECT COUNT(*) AS qtd_pedidos, COALESCE(SUM(total), 0) AS total_pedidos FROM pedidos WHERE restaurante_id = :rid AND garcom_id = :uid AND (criado_em >= CURDATE() AND criado_em < CURDATE() + INTERVAL 1 DAY) AND status <> 'CANCELADO'");
            $stmt_pedidos_usuario_hoje->execute([':rid' => $rid, ':uid' => $usuario_logado_id]);
            $pedidos_usuario_hoje = $stmt_pedidos_usuario_hoje->fetch(PDO::FETCH_ASSOC) ?: [];

            $total_hoje = floatval($pedidos_usuario_hoje['total_pedidos'] ?? 0);
            $qtd_hoje = intval($pedidos_usuario_hoje['qtd_pedidos'] ?? 0);

            $stmt_ultimas = $db->prepare("SELECT p.numero_pedido AS numero_fatura,
                                                     p.criado_em,
                                                     m.numero AS mesa_numero,
                                                     p.total AS total_final,
                                                     'PEDIDO' AS forma_pagamento,
                                                     p.status
                                              FROM pedidos p
                                              LEFT JOIN mesas m ON p.mesa_id = m.id
                                              WHERE p.restaurante_id = :rid AND p.garcom_id = :uid
                                              ORDER BY p.criado_em DESC
                                              LIMIT 8");
            $stmt_ultimas->bindValue(':rid', $rid, PDO::PARAM_INT);
            $stmt_ultimas->bindValue(':uid', $usuario_logado_id, PDO::PARAM_INT);
            $stmt_ultimas->execute();
            $ultimas_vendas = $stmt_ultimas->fetchAll(PDO::FETCH_ASSOC);

            $stmt_semana = $db->prepare("SELECT DATE(criado_em) AS data, COALESCE(SUM(total), 0) AS total
                                             FROM pedidos
                                             WHERE restaurante_id = :rid
                                             AND garcom_id = :uid
                                             AND criado_em >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                                             AND status <> 'CANCELADO'
                                             GROUP BY DATE(criado_em)
                                             ORDER BY data ASC");
            $stmt_semana->bindValue(':rid', $rid, PDO::PARAM_INT);
            $stmt_semana->bindValue(':uid', $usuario_logado_id, PDO::PARAM_INT);
            $stmt_semana->execute();
            $vendas_semana = $stmt_semana->fetchAll(PDO::FETCH_ASSOC);
            $dados_grafico = $vendas_semana;

            $stmt_garcom_resumo = $db->prepare("SELECT COUNT(*) AS total_pedidos,
                                                                                                                 COALESCE(SUM(total), 0) AS valor_pedidos,
                                                                                                                 SUM(CASE WHEN status IN ('NOVO','PENDENTE') THEN 1 ELSE 0 END) AS pendentes,
                                                                                                                 SUM(CASE WHEN status IN ('PREPARANDO','PRONTO') THEN 1 ELSE 0 END) AS preparados,
                                                                                                                 SUM(CASE WHEN status = 'ENTREGUE' THEN 1 ELSE 0 END) AS entregues
                                                                                                    FROM pedidos
                                                                                                    WHERE restaurante_id = :rid
                                                                                                        AND garcom_id = :uid
                                                                                                        AND (criado_em >= CURDATE() AND criado_em < CURDATE() + INTERVAL 1 DAY)
                                                                                                        AND status <> 'CANCELADO'");
            $stmt_garcom_resumo->execute([':rid' => $rid, ':uid' => $usuario_logado_id]);
            $garcom_resumo = $stmt_garcom_resumo->fetch(PDO::FETCH_ASSOC) ?: [];

            $stmt_garcom_mesas = $db->prepare("SELECT COUNT(*) AS total,
                                                                                                                SUM(CASE WHEN status = 'OCUPADA' THEN 1 ELSE 0 END) AS ocupadas
                                                                                                 FROM mesas
                                                                                                 WHERE restaurante_id = :rid
                                                                                                     AND (garcom_id = :uid OR garcom_id IS NULL)");
            $stmt_garcom_mesas->execute([':rid' => $rid, ':uid' => $usuario_logado_id]);
            $garcom_mesas_resumo = $stmt_garcom_mesas->fetch(PDO::FETCH_ASSOC) ?: [];

            $garcom_dashboard = [
                'total_pedidos' => intval($garcom_resumo['total_pedidos'] ?? 0),
                'mesas_total' => intval($garcom_mesas_resumo['total'] ?? 0),
                'mesas_ocupadas' => intval($garcom_mesas_resumo['ocupadas'] ?? 0),
                'valor_pedidos' => floatval($garcom_resumo['valor_pedidos'] ?? 0),
                'pendentes' => intval($garcom_resumo['pendentes'] ?? 0),
                'preparados' => intval($garcom_resumo['preparados'] ?? 0),
                'entregues' => intval($garcom_resumo['entregues'] ?? 0),
            ];
        } else {
            $stmt_vendas_usuario_hoje = $db->prepare("SELECT COUNT(*) AS qtd_vendas, COALESCE(SUM(total_final), 0) AS total_vendas FROM vendas WHERE restaurante_id = :rid AND usuario_id = :uid AND (criado_em >= CURDATE() AND criado_em < CURDATE() + INTERVAL 1 DAY) AND status = 'PAGO'");
            $stmt_vendas_usuario_hoje->execute([':rid' => $rid, ':uid' => $usuario_logado_id]);
            $vendas_usuario_hoje = $stmt_vendas_usuario_hoje->fetch(PDO::FETCH_ASSOC) ?: [];

            $total_hoje = floatval($vendas_usuario_hoje['total_vendas'] ?? 0);
            $qtd_hoje = intval($vendas_usuario_hoje['qtd_vendas'] ?? 0);

            $stmt_ultimas = $db->prepare("SELECT v.*, u.nome as usuario_nome, m.numero as mesa_numero
                                              FROM vendas v
                                              LEFT JOIN usuarios u ON v.usuario_id = u.id
                                              LEFT JOIN mesas m ON v.mesa_id = m.id
                                              WHERE v.restaurante_id = :rid AND v.usuario_id = :uid
                                              ORDER BY v.criado_em DESC
                                              LIMIT 8");
            $stmt_ultimas->bindValue(':rid', $rid, PDO::PARAM_INT);
            $stmt_ultimas->bindValue(':uid', $usuario_logado_id, PDO::PARAM_INT);
            $stmt_ultimas->execute();
            $ultimas_vendas = $stmt_ultimas->fetchAll(PDO::FETCH_ASSOC);

            $stmt_semana = $db->prepare("SELECT DATE(criado_em) AS data, COALESCE(SUM(total_final), 0) AS total
                                             FROM vendas
                                             WHERE restaurante_id = :rid
                                             AND usuario_id = :uid
                                             AND criado_em >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                                             AND status = 'PAGO'
                                             GROUP BY DATE(criado_em)
                                             ORDER BY data ASC");
            $stmt_semana->bindValue(':rid', $rid, PDO::PARAM_INT);
            $stmt_semana->bindValue(':uid', $usuario_logado_id, PDO::PARAM_INT);
            $stmt_semana->execute();
            $vendas_semana = $stmt_semana->fetchAll(PDO::FETCH_ASSOC);
            $dados_grafico = $vendas_semana;

            $stmt_caixa_abertas = $db->prepare("SELECT COUNT(*) AS contas_abertas, COALESCE(SUM(total), 0) AS total_aberto
                                                    FROM pedidos
                                                    WHERE restaurante_id = :rid
                                                      AND status NOT IN ('PAGO','CANCELADO')
                                                      AND (criado_em >= CURDATE() AND criado_em < CURDATE() + INTERVAL 1 DAY)");
            $stmt_caixa_abertas->execute([':rid' => $rid]);
            $caixa_abertas_resumo = $stmt_caixa_abertas->fetch(PDO::FETCH_ASSOC) ?: [];

            $total_turno_dashboard = 0.0;
            if (!empty($caixa_aberto['id'])) {
                $stmt_total_turno = $db->prepare("SELECT COALESCE(SUM(total_final), 0) AS total FROM vendas WHERE caixa_id = :caixa_id AND status = 'PAGO'");
                $stmt_total_turno->execute([':caixa_id' => intval($caixa_aberto['id'])]);
                $total_turno_dashboard = floatval($stmt_total_turno->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
            }

            $caixa_dashboard = [
                'contas_abertas' => intval($caixa_abertas_resumo['contas_abertas'] ?? 0),
                'total_aberto' => floatval($caixa_abertas_resumo['total_aberto'] ?? 0),
                'caixa_aberto' => (bool)$caixa_aberto,
                'total_turno' => $total_turno_dashboard,
            ];
        }

        return array_intersect_key(get_defined_vars(), array_flip(self::SAIDAS_DADOS_OPERACIONAIS));
    }

    /**
     * Dados do dashboard do administrador: receita, canais, equipa, Empresarial.
     * Recebe as variaveis ENTRADAS_DADOS_ADMIN e devolve as SAIDAS_DADOS_ADMIN que ficaram definidas.
     */
    public function dadosAdmin(array $entrada): array
    {
        extract($entrada);
        $db = $this->db;

        // Totais do mes e do mes anterior pelo resumo diario (ver app/src/config/vendas_resumo.php).
        require_once __DIR__ . '/../config/vendas_resumo.php';
        $inicioMes = date('Y-m-01', strtotime((string)$db->query('SELECT CURDATE()')->fetchColumn()));
        $receita_mes_atual = (float)vendas_resumo_periodo($db, (int)$rid, $inicioMes, date('Y-m-d', strtotime($inicioMes . ' +1 month')))['total'];
        $receita_mes_anterior = (float)vendas_resumo_periodo($db, (int)$rid, date('Y-m-d', strtotime($inicioMes . ' -1 month')), $inicioMes)['total'];

        if ($receita_mes_anterior > 0) {
            $crescimento_mensal_pct = (($receita_mes_atual - $receita_mes_anterior) / $receita_mes_anterior) * 100;
        } elseif ($receita_mes_atual > 0) {
            $crescimento_mensal_pct = 100;
        }

        $hojeTend = date('Y-m-d', strtotime((string)$db->query('SELECT CURDATE()')->fetchColumn()));
        $tendenciaRaw = [];
        foreach (vendas_resumo_periodo($db, (int)$rid, date('Y-m-d', strtotime($hojeTend . ' -13 day')), date('Y-m-d', strtotime($hojeTend . ' +1 day')))['por_dia'] as $diaTend => $totalTend) {
            $tendenciaRaw[] = ['data' => $diaTend, 'total' => $totalTend];
        }

        // Série contínua dos últimos 14 dias (preenche dias sem venda com 0)
        $mapTendencia = [];
        foreach ($tendenciaRaw as $rowTend) {
            $diaKey = date('Y-m-d', strtotime((string)($rowTend['data'] ?? '')));
            if ($diaKey) {
                $mapTendencia[$diaKey] = floatval($rowTend['total'] ?? 0);
            }
        }
        $dados_grafico = [];
        for ($i = 13; $i >= 0; $i--) {
            $dia = date('Y-m-d', strtotime("-{$i} day"));
            $dados_grafico[] = [
                'data' => $dia,
                'total' => floatval($mapTendencia[$dia] ?? 0),
            ];
        }

        // Recalcular totais/média DEPOIS da série de 14 dias (antes ficava com dados da semana)
        $receita_14_dias_total = 0.0;
        foreach ($dados_grafico as $itemGrafico) {
            $receita_14_dias_total += floatval($itemGrafico['total'] ?? 0);
        }
        $diasComVenda = 0;
        foreach ($dados_grafico as $itemGrafico) {
            if (floatval($itemGrafico['total'] ?? 0) > 0) {
                $diasComVenda++;
            }
        }
        $media_diaria_14d = $diasComVenda > 0
            ? ($receita_14_dias_total / $diasComVenda)
            : ($receita_14_dias_total / max(1, count($dados_grafico)));
        $pico_14d = 0.0;
        $pico_14d_data = '';
        foreach ($dados_grafico as $itemGrafico) {
            $totDia = floatval($itemGrafico['total'] ?? 0);
            if ($totDia >= $pico_14d) {
                $pico_14d = $totDia;
                $pico_14d_data = (string)($itemGrafico['data'] ?? '');
            }
        }
        $tendencia_delta_pct = 0.0;
        if (count($dados_grafico) >= 2) {
            $primeiro = floatval($dados_grafico[0]['total'] ?? 0);
            $ultimo = floatval($dados_grafico[count($dados_grafico) - 1]['total'] ?? 0);
            if ($primeiro > 0) {
                $tendencia_delta_pct = (($ultimo - $primeiro) / $primeiro) * 100;
            } elseif ($ultimo > 0) {
                $tendencia_delta_pct = 100;
            }
        }

        // Inteligência de receita EMP — comparativos e recomendações (não métricas genéricas).
        $dashEmpReceita = null;
        if ($plano_atual === 'EMPRESARIAL' && count($dados_grafico) >= 14) {
            $vals14 = array_map(static function ($r) {
                return (float)($r['total'] ?? 0);
            }, $dados_grafico);
            $ult7 = array_slice($vals14, 7);
            $ant7 = array_slice($vals14, 0, 7);
            $somaUlt7 = array_sum($ult7);
            $somaAnt7 = array_sum($ant7);
            $delta7pct = 0.0;
            if ($somaAnt7 > 0) {
                $delta7pct = (($somaUlt7 - $somaAnt7) / $somaAnt7) * 100;
            } elseif ($somaUlt7 > 0) {
                $delta7pct = 100.0;
            }
            $diasActivos14 = 0;
            $porSemana = [];
            $nomesDia = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
            foreach ($dados_grafico as $rowG) {
                $totG = (float)($rowG['total'] ?? 0);
                if ($totG > 0) {
                    $diasActivos14++;
                }
                $dow = (int)date('w', strtotime((string)($rowG['data'] ?? 'now')));
                if (!isset($porSemana[$dow])) {
                    $porSemana[$dow] = ['soma' => 0.0, 'dias' => 0];
                }
                $porSemana[$dow]['soma'] += $totG;
                $porSemana[$dow]['dias']++;
            }
            $melhorDiaNome = '—';
            $melhorDiaMedia = 0.0;
            foreach ($porSemana as $dow => $agg) {
                $mediaDow = $agg['dias'] > 0 ? ($agg['soma'] / $agg['dias']) : 0.0;
                if ($mediaDow > $melhorDiaMedia) {
                    $melhorDiaMedia = $mediaDow;
                    $melhorDiaNome = $nomesDia[$dow] ?? '—';
                }
            }
            $hojeReceita = (float)($vals14[count($vals14) - 1] ?? 0);
            $ritmoVsMedia = 0.0;
            if ($media_diaria_14d > 0) {
                $ritmoVsMedia = (($hojeReceita - $media_diaria_14d) / $media_diaria_14d) * 100;
            } elseif ($hojeReceita > 0) {
                $ritmoVsMedia = 100.0;
            }
            $diaMes = (int)date('j');
            $diasNoMes = (int)date('t');
            $projecaoMes = $diaMes > 0
                ? ($receita_mes_atual / $diaMes) * $diasNoMes
                : $receita_mes_atual;
            $projecaoSemana = $media_diaria_14d > 0 ? ($media_diaria_14d * 7) : $somaUlt7;
            $diasRestantesMes = max(0, $diasNoMes - $diaMes);
            $metaMesReferencia = $receita_mes_anterior > 0 ? $receita_mes_anterior : ($projecaoMes > 0 ? $projecaoMes : 0);
            $riscoMesBaixo = false;
            if ($metaMesReferencia > 0 && $projecaoMes > 0 && $projecaoMes < ($metaMesReferencia * 0.90)) {
                $riscoMesBaixo = true;
            }
            if ($ritmoVsMedia <= -25 && $diaMes >= 10 && $media_diaria_14d > 0) {
                $riscoMesBaixo = true;
            }

            $ticket14 = 0.0;
            $qtdVendas14 = 0;
            $pagamentoTop = '—';
            $pagamentoTopPct = 0.0;
            try {
                $stmtEmpTicket = $db->prepare("
                    SELECT COUNT(*) AS qtd, COALESCE(SUM(total_final), 0) AS total
                    FROM vendas
                    WHERE restaurante_id = :rid
                      AND UPPER(TRIM(COALESCE(status, ''))) = 'PAGO'
                      AND criado_em >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
                ");
                $stmtEmpTicket->execute([':rid' => $rid]);
                $rowEmpTicket = $stmtEmpTicket->fetch(PDO::FETCH_ASSOC) ?: [];
                $qtdVendas14 = (int)($rowEmpTicket['qtd'] ?? 0);
                $totVendas14 = (float)($rowEmpTicket['total'] ?? 0);
                $ticket14 = $qtdVendas14 > 0 ? ($totVendas14 / $qtdVendas14) : 0.0;

                $stmtEmpPay = $db->prepare("
                    SELECT COALESCE(NULLIF(TRIM(forma_pagamento), ''), 'N/A') AS forma,
                           COUNT(*) AS qtd,
                           COALESCE(SUM(total_final), 0) AS total
                    FROM vendas
                    WHERE restaurante_id = :rid
                      AND UPPER(TRIM(COALESCE(status, ''))) = 'PAGO'
                      AND criado_em >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
                    GROUP BY COALESCE(NULLIF(TRIM(forma_pagamento), ''), 'N/A')
                    ORDER BY total DESC
                    LIMIT 1
                ");
                $stmtEmpPay->execute([':rid' => $rid]);
                $rowEmpPay = $stmtEmpPay->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($rowEmpPay && $totVendas14 > 0) {
                    $pagamentoTop = (string)($rowEmpPay['forma'] ?? '—');
                    $pagamentoTopPct = ((float)($rowEmpPay['total'] ?? 0) / $totVendas14) * 100;
                }
            } catch (Throwable $empReceitaErr) {
                error_log('[DASHBOARD][EMP_RECEITA] ' . $empReceitaErr->getMessage());
            }

            $insightsReceita = [];
            $fmtMzn = static function ($v) {
                return 'MZN ' . number_format((float)$v, 0, ',', '.');
            };
            if ($receita_14_dias_total <= 0) {
                $insightsReceita[] = [
                    'tom' => 'warn',
                    'titulo' => 'Ainda sem movimento pago',
                    'txt' => 'Nestes 14 dias não há vendas marcadas como pagas. Vale confirmar se o caixa está a abrir e se as contas estão a ser fechadas no sistema.',
                ];
            } else {
                if ($delta7pct <= -15) {
                    $insightsReceita[] = [
                        'tom' => 'warn',
                        'titulo' => 'Esta semana veio mais fraca',
                        'txt' => 'Entrou cerca de ' . number_format(abs($delta7pct), 0, ',', '.') . '% menos do que na semana passada (' . $fmtMzn($somaUlt7) . ' vs ' . $fmtMzn($somaAnt7) . '). Olhe os dias vazios no gráfico e veja se falta staff, stock ou promoção no horário certo.',
                    ];
                } elseif ($delta7pct >= 15) {
                    $insightsReceita[] = [
                        'tom' => 'ok',
                        'titulo' => 'A casa está a subir',
                        'txt' => 'Os últimos 7 dias renderam ' . number_format($delta7pct, 0, ',', '.') . '% a mais que a semana anterior. Mantenha o que está a funcionar: cardápio do pico, atendimento e stock nos produtos que saem mais.',
                    ];
                } else {
                    $insightsReceita[] = [
                        'tom' => 'info',
                        'titulo' => 'Semana parecida com a anterior',
                        'txt' => 'A diferença ficou em ' . ($delta7pct >= 0 ? '+' : '') . number_format($delta7pct, 0, ',', '.') . '% (' . $fmtMzn($somaUlt7) . ' agora vs ' . $fmtMzn($somaAnt7) . '). Está estável — se quiser crescer, o próximo passo é empurrar o dia mais fraco, não só o dia forte.',
                    ];
                }
                if ($melhorDiaMedia > 0 && $melhorDiaNome !== '—') {
                    $insightsReceita[] = [
                        'tom' => 'info',
                        'titulo' => $melhorDiaNome . ' costuma ser o melhor dia',
                        'txt' => 'Nesses dias a média anda à volta de ' . $fmtMzn($melhorDiaMedia) . '. Prepare a cozinha e o salão com antecedência: mais insumos, mais gente no turno e mesa/caixa prontos.',
                    ];
                }
                if ($diasActivos14 < 10) {
                    $vazios = 14 - $diasActivos14;
                    $insightsReceita[] = [
                        'tom' => 'warn',
                        'titulo' => 'Há dias sem venda no período',
                        'txt' => 'Só houve movimento em ' . $diasActivos14 . ' dos últimos 14 dias (' . $vazios . ' dia(s) a zero). Se a casa abriu nesses dias, algo ficou por registar — ou o ritmo está a falhar e convém olhar o calendário.',
                    ];
                }
                if ($pagamentoTop !== '—' && $pagamentoTopPct >= 55) {
                    if (stripos($pagamentoTop, 'DINHEIRO') !== false) {
                        $payHint = 'Muitos clientes ainda pagam em dinheiro. Se fizer sentido no vosso público, pode facilitar M-Pesa/e-Mola no fecho da conta para não perder venda quando falta troco.';
                    } elseif (stripos($pagamentoTop, 'MPESA') !== false || stripos($pagamentoTop, 'EMOLA') !== false) {
                        $payHint = 'A maior parte já passa por ' . $pagamentoTop . '. Mantenha o telemóvel/caixa digital estável nessas horas — é aí que a fila trava.';
                    } else {
                        $payHint = 'A maior parte das vendas entra por ' . $pagamentoTop . '. Vale garantir que o processo nesse meio está rápido e sem falhas no fecho.';
                    }
                    $insightsReceita[] = [
                        'tom' => 'info',
                        'titulo' => number_format($pagamentoTopPct, 0, ',', '.') . '% da receita vem de ' . $pagamentoTop,
                        'txt' => $payHint,
                    ];
                }
                if ($ritmoVsMedia <= -25 && $media_diaria_14d > 0) {
                    $insightsReceita[] = [
                        'tom' => 'warn',
                        'titulo' => 'Hoje ainda está abaixo do habitual',
                        'txt' => 'Até agora a casa fez ' . $fmtMzn($hojeReceita) . ', cerca de ' . number_format(abs($ritmoVsMedia), 0, ',', '.') . '% abaixo da média dos dias com venda (' . $fmtMzn($media_diaria_14d) . '). Ainda dá para recuperar no jantar se empurrar mesa e takeaway.',
                    ];
                } elseif ($ritmoVsMedia >= 25 && $hojeReceita > 0) {
                    $insightsReceita[] = [
                        'tom' => 'ok',
                        'titulo' => 'Hoje está a correr bem',
                        'txt' => 'Já vão ' . $fmtMzn($hojeReceita) . ' — acima da média habitual. Não deixe faltar o que mais vende: confirme stock e ritmo da cozinha até ao fecho.',
                    ];
                }
                if ($riscoMesBaixo) {
                    $insightsReceita[] = [
                        'tom' => 'warn',
                        'titulo' => 'Com este ritmo o mês pode fechar fraco',
                        'txt' => 'A projecção aponta para ' . $fmtMzn($projecaoMes) . ' no mês' . ($receita_mes_anterior > 0 ? ' (mês passado: ' . $fmtMzn($receita_mes_anterior) . ')' : '') . '. Faltam ' . $diasRestantesMes . ' dia(s) — vale empurrar o jantar e o dia fraco da semana.',
                    ];
                } else {
                    $insightsReceita[] = [
                        'tom' => 'info',
                        'titulo' => 'Projecção da semana: ' . $fmtMzn($projecaoSemana),
                        'txt' => 'Se mantiver a média actual, a semana fecha perto desse valor. A projecção do mês está em ' . $fmtMzn($projecaoMes) . '.',
                    ];
                }
                if ($ticket14 > 0 && $qtdVendas14 > 0) {
                    $insightsReceita[] = [
                        'tom' => 'info',
                        'titulo' => 'Ticket médio nestes 14 dias',
                        'txt' => 'Cada venda paga ficou em média ' . $fmtMzn($ticket14) . ' (' . (int)$qtdVendas14 . ' vendas). Sugestão de mesa: sugerir bebida/extra no atendimento pode subir esse valor sem aumentar o fluxo.',
                    ];
                }
            }
            // Prioridade: avisos primeiro, depois ok, depois info — máximo 3 para não parecer relatório de robô
            usort($insightsReceita, static function ($a, $b) {
                $ordem = ['warn' => 0, 'ok' => 1, 'info' => 2];
                return ($ordem[$a['tom'] ?? 'info'] ?? 9) <=> ($ordem[$b['tom'] ?? 'info'] ?? 9);
            });
            $insightsReceita = array_slice($insightsReceita, 0, 3);

            $dashEmpReceita = [
                'ult7' => $somaUlt7,
                'ant7' => $somaAnt7,
                'delta7pct' => $delta7pct,
                'dias_activos' => $diasActivos14,
                'melhor_dia' => $melhorDiaNome,
                'melhor_dia_media' => $melhorDiaMedia,
                'hoje' => $hojeReceita,
                'ritmo_vs_media' => $ritmoVsMedia,
                'projecao_mes' => $projecaoMes,
                'projecao_semana' => $projecaoSemana,
                'risco_mes_baixo' => $riscoMesBaixo,
                'ticket_14' => $ticket14,
                'qtd_14' => $qtdVendas14,
                'pagamento_top' => $pagamentoTop,
                'pagamento_top_pct' => $pagamentoTopPct,
                'insights' => $insightsReceita,
            ];
        }

        // Top produtos + oportunidades (EMP) — inteligência comercial 30 dias.
        $dashEmpTopProdutos = [];
        $dashEmpOportunidades = [];
        $dashEmpTopMeta = [
            'receita_total' => 0.0,
            'receita_top' => 0.0,
            'share_top' => 0.0,
            'share_top3' => 0.0,
            'lider' => '',
            'qtd_top' => 0,
            'insight' => '',
        ];
        $dashEmpOppMeta = [
            'cair' => 0,
            'parado' => 0,
            'fraco' => 0,
            'insight' => '',
        ];
        if ($plano_atual === 'EMPRESARIAL') {
            try {
                $stmtTopProd = $db->prepare("
                    SELECT p.id,
                           p.nome,
                           p.imagem,
                           COALESCE(SUM(iv.quantidade), 0) AS qtd,
                           COALESCE(SUM(COALESCE(iv.subtotal, iv.quantidade * iv.preco_unitario)), 0) AS receita,
                           COALESCE(SUM(CASE
                               WHEN v.criado_em >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                               THEN iv.quantidade ELSE 0 END), 0) AS qtd_rec,
                           COALESCE(SUM(CASE
                               WHEN v.criado_em < DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                               THEN iv.quantidade ELSE 0 END), 0) AS qtd_ant
                    FROM itens_venda iv
                    INNER JOIN vendas v ON v.id = iv.venda_id
                    INNER JOIN produtos p ON p.id = iv.produto_id
                    WHERE v.restaurante_id = :rid
                      AND UPPER(TRIM(COALESCE(v.status, ''))) = 'PAGO'
                      AND v.criado_em >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                    GROUP BY p.id, p.nome, p.imagem
                    ORDER BY qtd DESC, receita DESC
                    LIMIT 5
                ");
                $stmtTopProd->execute([':rid' => $rid]);
                $dashEmpTopProdutos = $stmtTopProd->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $stmtRecTotal = $db->prepare("
                    SELECT COALESCE(SUM(COALESCE(iv.subtotal, iv.quantidade * iv.preco_unitario)), 0) AS receita_total
                    FROM itens_venda iv
                    INNER JOIN vendas v ON v.id = iv.venda_id
                    WHERE v.restaurante_id = :rid
                      AND UPPER(TRIM(COALESCE(v.status, ''))) = 'PAGO'
                      AND v.criado_em >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                ");
                $stmtRecTotal->execute([':rid' => $rid]);
                $receitaTotal30 = (float)($stmtRecTotal->fetchColumn() ?: 0);
                $dashEmpTopMeta['receita_total'] = $receitaTotal30;

                $receitaTop = 0.0;
                $receitaTop3 = 0.0;
                $qtdTop = 0;
                foreach ($dashEmpTopProdutos as $i => &$tpRow) {
                    $rec = (float)($tpRow['receita'] ?? 0);
                    $qtd = (float)($tpRow['qtd'] ?? 0);
                    $qRec = (float)($tpRow['qtd_rec'] ?? 0);
                    $qAnt = (float)($tpRow['qtd_ant'] ?? 0);
                    $share = $receitaTotal30 > 0 ? ($rec / $receitaTotal30) * 100 : 0.0;
                    $deltaPct = null;
                    if ($qAnt > 0) {
                        $deltaPct = (($qRec - $qAnt) / $qAnt) * 100;
                    } elseif ($qRec > 0) {
                        $deltaPct = 100.0;
                    }
                    $tpRow['share_pct'] = $share;
                    $tpRow['delta_pct'] = $deltaPct;
                    $receitaTop += $rec;
                    $qtdTop += (int)round($qtd);
                    if ($i < 3) {
                        $receitaTop3 += $rec;
                    }
                }
                unset($tpRow);

                $dashEmpTopMeta['receita_top'] = $receitaTop;
                $dashEmpTopMeta['qtd_top'] = $qtdTop;
                $dashEmpTopMeta['share_top'] = $receitaTotal30 > 0 ? ($receitaTop / $receitaTotal30) * 100 : 0.0;
                $dashEmpTopMeta['share_top3'] = $receitaTotal30 > 0 ? ($receitaTop3 / $receitaTotal30) * 100 : 0.0;
                $dashEmpTopMeta['lider'] = trim((string)($dashEmpTopProdutos[0]['nome'] ?? ''));
                if ($dashEmpTopMeta['share_top3'] >= 70 && $dashEmpTopMeta['lider'] !== '') {
                    $dashEmpTopMeta['insight'] = 'Os 3 primeiros concentram ' . number_format($dashEmpTopMeta['share_top3'], 0) . '% da receita — proteja stock e ritmo de ' . $dashEmpTopMeta['lider'] . '.';
                } elseif ($dashEmpTopMeta['lider'] !== '') {
                    $dashEmpTopMeta['insight'] = $dashEmpTopMeta['lider'] . ' lidera o período. Use o ranking para priorizar produção e promoção no salão.';
                }
            } catch (Throwable $topProdErr) {
                error_log('[DASHBOARD][TOP_PRODUTOS] ' . $topProdErr->getMessage());
                $dashEmpTopProdutos = [];
            }

            // Oportunidades concretas: números + decisão por produto (não frases genéricas).
            try {
                $topIds = [];
                $liderNome = $dashEmpTopMeta['lider'] !== '' ? $dashEmpTopMeta['lider'] : '';
                $liderQtd = (float)($dashEmpTopProdutos[0]['qtd'] ?? 0);
                foreach ($dashEmpTopProdutos as $tpRow) {
                    $topIds[(int)($tpRow['id'] ?? 0)] = true;
                }
                $stmtOpp = $db->prepare("
                    SELECT p.id,
                           p.nome,
                           p.imagem,
                           COALESCE(p.preco, 0) AS preco,
                           COALESCE(NULLIF(TRIM(c.nome), ''), '') AS categoria,
                           COALESCE(SUM(CASE
                               WHEN v.id IS NOT NULL
                                AND v.criado_em >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                               THEN iv.quantidade ELSE 0 END), 0) AS qtd_rec,
                           COALESCE(SUM(CASE
                               WHEN v.id IS NOT NULL
                                AND v.criado_em < DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                                AND v.criado_em >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                               THEN iv.quantidade ELSE 0 END), 0) AS qtd_ant,
                           COALESCE(SUM(CASE
                               WHEN v.id IS NOT NULL
                                AND v.criado_em >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                               THEN COALESCE(iv.subtotal, iv.quantidade * iv.preco_unitario) ELSE 0 END), 0) AS receita_rec,
                           COALESCE(SUM(CASE
                               WHEN v.id IS NOT NULL
                                AND v.criado_em < DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                                AND v.criado_em >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                               THEN COALESCE(iv.subtotal, iv.quantidade * iv.preco_unitario) ELSE 0 END), 0) AS receita_ant,
                           MAX(v.criado_em) AS ultima_venda_30d
                    FROM produtos p
                    LEFT JOIN categorias c ON c.id = p.categoria_id
                    LEFT JOIN itens_venda iv ON iv.produto_id = p.id
                    LEFT JOIN vendas v
                      ON v.id = iv.venda_id
                     AND v.restaurante_id = p.restaurante_id
                     AND UPPER(TRIM(COALESCE(v.status, ''))) = 'PAGO'
                     AND v.criado_em >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                    WHERE p.restaurante_id = :rid
                      AND COALESCE(p.ativo, 1) = 1
                    GROUP BY p.id, p.nome, p.imagem, p.preco, c.nome
                ");
                $stmtOpp->execute([':rid' => $rid]);
                $oppRaw = $stmtOpp->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $classificarTipo = static function (string $cat, string $nome): string {
                    $s = function_exists('mb_strtolower')
                        ? mb_strtolower($cat . ' ' . $nome, 'UTF-8')
                        : strtolower($cat . ' ' . $nome);
                    if (preg_match('/pizza/u', $s)) {
                        return 'pizza';
                    }
                    if (preg_match('/bebida|coca|fanta|sumo|suco|água|agua|cerveja|refri|refrigerante|chá|cha|café|cafe/u', $s)) {
                        return 'bebida';
                    }
                    if (preg_match('/hamb|burger|sandu|hot.?dog/u', $s)) {
                        return 'lanche';
                    }
                    if (preg_match('/sobremesa|bolo|doce|gelado|mousse/u', $s)) {
                        return 'sobremesa';
                    }
                    return 'prato';
                };

                $cair = [];
                $parado = [];
                $fraco = [];
                foreach ($oppRaw as $op) {
                    $pid = (int)($op['id'] ?? 0);
                    if ($pid <= 0 || isset($topIds[$pid])) {
                        continue;
                    }
                    $qRec = (float)($op['qtd_rec'] ?? 0);
                    $qAnt = (float)($op['qtd_ant'] ?? 0);
                    $recRec = (float)($op['receita_rec'] ?? 0);
                    $recAnt = (float)($op['receita_ant'] ?? 0);
                    $nome = trim((string)($op['nome'] ?? 'Produto'));
                    $preco = (float)($op['preco'] ?? 0);
                    $cat = trim((string)($op['categoria'] ?? ''));
                    $tipoItem = $classificarTipo($cat, $nome);
                    $perdaRec = max(0.0, $recAnt - $recRec);
                    $potencialSemana = $preco > 0 ? ($preco * 4) : 0.0;
                    $base = [
                        'id' => $pid,
                        'nome' => $nome,
                        'imagem' => (string)($op['imagem'] ?? ''),
                        'qtd_rec' => $qRec,
                        'qtd_ant' => $qAnt,
                        'preco' => $preco,
                        'categoria' => $cat,
                        'tipo_item' => $tipoItem,
                        'potencial' => $potencialSemana,
                        'perda' => $perdaRec,
                    ];

                    if ($qAnt >= 3 && $qRec < ($qAnt * 0.7)) {
                        $quedaPct = (int)round((1 - ($qRec / $qAnt)) * 100);
                        $faltam = max(1, (int)round($qAnt - $qRec));
                        $evidencia = (int)round($qAnt) . ' → ' . (int)round($qRec) . ' vendas (15d)';
                        if ($perdaRec > 0) {
                            $evidencia .= ' · −' . number_format($perdaRec, 0, ',', '.') . ' MZN';
                        }
                        if ($tipoItem === 'bebida') {
                            $acao = 'Meta: recuperar ' . $faltam . ' unidades esta semana — oferecer com cada ' . ($liderNome !== '' ? $liderNome : 'prato principal') . ' no salão.';
                        } elseif ($tipoItem === 'pizza' || $tipoItem === 'lanche') {
                            $acao = 'Meta: voltar às ' . (int)round($qAnt) . ' vendas/quinzena — promo “hora de almoço” ou combo com bebida top.';
                        } else {
                            $acao = 'Meta: +' . $faltam . ' vendas esta semana — destaque no cardápio e no briefing do turno.';
                        }
                        $base['tipo'] = 'cair';
                        $base['queda_pct'] = $quedaPct;
                        $base['prioridade'] = 100 + $quedaPct + (int)min(50, round($perdaRec / 100));
                        $base['evidencia'] = $evidencia;
                        $base['acao'] = $acao;
                        $base['label'] = 'Queda ' . $quedaPct . '%';
                        $cair[] = $base;
                    } elseif ($qRec <= 0 && $qAnt <= 0) {
                        $evidencia = ($preco > 0 ? number_format($preco, 0, ',', '.') . ' MZN/un · ' : '') . '0 vendas em 30 dias';
                        if ($cat !== '') {
                            $evidencia = $cat . ' · ' . $evidencia;
                        }
                        if ($tipoItem === 'pizza') {
                            $acao = 'Hoje: “pizza do dia” no salão ou desactivar se não houver procura — 4 vendas ≈ ' . number_format($potencialSemana, 0, ',', '.') . ' MZN.';
                        } elseif ($tipoItem === 'bebida') {
                            $acao = 'Hoje: sugerir no fecho da conta com ' . ($liderNome !== '' ? $liderNome : 'o prato líder') . ' — 4 vendas ≈ ' . number_format($potencialSemana, 0, ',', '.') . ' MZN.';
                        } elseif ($tipoItem === 'sobremesa') {
                            $acao = 'Hoje: oferecer após o prato principal — meta 4 vendas esta semana (' . number_format($potencialSemana, 0, ',', '.') . ' MZN).';
                        } else {
                            $acao = 'Decisão hoje: oferta do dia no briefing OU arquivar no cardápio — potencial 4× = ' . number_format($potencialSemana, 0, ',', '.') . ' MZN.';
                        }
                        $base['tipo'] = 'parado';
                        $base['queda_pct'] = 100;
                        $base['prioridade'] = 35 + min(45, (int)round($preco / 40));
                        $base['evidencia'] = $evidencia;
                        $base['acao'] = $acao;
                        $base['label'] = 'Sem movimento';
                        $parado[] = $base;
                    } elseif ($qRec > 0 && $qRec <= 3) {
                        $mediaDia = $qRec / 15.0;
                        $evidencia = (int)round($qRec) . ' venda' . ((int)round($qRec) === 1 ? '' : 's') . ' em 15 dias'
                            . ' · ~' . number_format($mediaDia, 1, ',', '.') . '/dia';
                        if ($preco > 0) {
                            $evidencia .= ' · ' . number_format($preco, 0, ',', '.') . ' MZN';
                        }
                        if ($tipoItem === 'bebida' && $liderNome !== '') {
                            $acao = 'Pedir ao salão: oferecer ' . $nome . ' em cada ' . $liderNome . ' — sobe ticket em ' . number_format($preco, 0, ',', '.') . ' MZN.';
                        } elseif ($tipoItem === 'sobremesa' && $liderQtd > 0) {
                            $acao = 'Após cada prato principal, sugerir ' . $nome . ' — se 10% do top aceitar ≈ ' . number_format($preco * max(1, round($liderQtd * 0.1)), 0, ',', '.') . ' MZN.';
                        } else {
                            $meta = max(5, (int)round($qRec) * 2);
                            $acao = 'Meta esta semana: ' . $meta . ' vendas — colocar em destaque no PDV e mencionar no turno.';
                        }
                        $base['tipo'] = 'fraco';
                        $base['queda_pct'] = 0;
                        $base['prioridade'] = 55 - (int)round($qRec) + min(20, (int)round($preco / 50));
                        $base['evidencia'] = $evidencia;
                        $base['acao'] = $acao;
                        $base['label'] = 'Ritmo baixo';
                        $fraco[] = $base;
                    }
                }
                usort($cair, static fn($a, $b) => ($b['prioridade'] ?? 0) <=> ($a['prioridade'] ?? 0));
                usort($parado, static fn($a, $b) => ($b['prioridade'] ?? 0) <=> ($a['prioridade'] ?? 0));
                usort($fraco, static fn($a, $b) => ($b['prioridade'] ?? 0) <=> ($a['prioridade'] ?? 0));
                $dashEmpOportunidades = array_slice(array_merge(
                    $cair,
                    $fraco,
                    array_slice($parado, 0, 2)
                ), 0, 5);
                $dashEmpOppMeta['cair'] = count($cair);
                $dashEmpOppMeta['parado'] = count($parado);
                $dashEmpOppMeta['fraco'] = count($fraco);
                if (!empty($dashEmpOportunidades[0]['acao'])) {
                    $primeiro = $dashEmpOportunidades[0];
                    $dashEmpOppMeta['insight'] = ($primeiro['nome'] ?? 'Prioridade') . ': ' . ($primeiro['acao'] ?? '');
                } elseif (count($cair) > 0) {
                    $dashEmpOppMeta['insight'] = $cair[0]['nome'] . ' caiu ' . (int)($cair[0]['queda_pct'] ?? 0) . '% — prioridade nº 1 do salão.';
                } else {
                    $dashEmpOppMeta['insight'] = 'Nenhum alerta fora do top — mantenha o ritmo dos líderes.';
                }
            } catch (Throwable $oppErr) {
                error_log('[DASHBOARD][OPORTUNIDADES] ' . $oppErr->getMessage());
                $dashEmpOportunidades = [];
            }
        }

        $stmt_top_garcom = $db->prepare("SELECT
                                                u.nome,
                                                COUNT(p.id) AS total_pedidos,
                                                COALESCE(SUM(p.total), 0) AS valor_pedidos
                                             FROM pedidos p
                                             INNER JOIN usuarios u ON u.id = p.garcom_id
                                             WHERE p.restaurante_id = :rid
                                               AND p.status <> 'CANCELADO'
                                               AND (p.criado_em >= CURDATE() AND p.criado_em < CURDATE() + INTERVAL 1 DAY)
                                               AND UPPER(REPLACE(u.perfil, 'Ç', 'C')) = 'GARCOM'
                                             GROUP BY u.id, u.nome
                                             ORDER BY valor_pedidos DESC, total_pedidos DESC
                                             LIMIT 1");
        $stmt_top_garcom->execute([':rid' => $rid]);
        $top_garcom = $stmt_top_garcom->fetch(PDO::FETCH_ASSOC) ?: null;

        $stmt_top_caixa = $db->prepare("SELECT u.nome, COUNT(v.id) AS total_vendas, COALESCE(SUM(v.total_final), 0) AS receita FROM vendas v INNER JOIN usuarios u ON u.id = v.usuario_id WHERE v.restaurante_id = :rid AND v.status = 'PAGO' AND (v.criado_em >= CURDATE() AND v.criado_em < CURDATE() + INTERVAL 1 DAY) AND UPPER(REPLACE(u.perfil, 'Ç', 'C')) = 'CAIXA' GROUP BY u.id, u.nome ORDER BY receita DESC, total_vendas DESC LIMIT 1");
        $stmt_top_caixa->execute([':rid' => $rid]);
        $top_caixa = $stmt_top_caixa->fetch(PDO::FETCH_ASSOC) ?: null;

        $stmt_pedidos_resumo = $db->prepare("SELECT SUM(CASE WHEN UPPER(TRIM(status)) IN ('NOVO','PENDENTE','PREPARANDO') THEN 1 ELSE 0 END) AS pendentes, SUM(CASE WHEN UPPER(TRIM(status)) = 'PREPARANDO' THEN 1 ELSE 0 END) AS preparando, SUM(CASE WHEN UPPER(TRIM(status)) = 'PRONTO' THEN 1 ELSE 0 END) AS prontos, SUM(CASE WHEN UPPER(TRIM(status)) = 'ENTREGUE' THEN 1 ELSE 0 END) AS entregues FROM pedidos WHERE restaurante_id = :rid");
        $stmt_pedidos_resumo->execute([':rid' => $rid]);
        $pedidos_resumo_db = $stmt_pedidos_resumo->fetch(PDO::FETCH_ASSOC) ?: [];
        $pedidos_resumo = [
            'pendentes' => intval($pedidos_resumo_db['pendentes'] ?? 0),
            'preparando' => intval($pedidos_resumo_db['preparando'] ?? 0),
            'prontos' => intval($pedidos_resumo_db['prontos'] ?? 0),
            'entregues' => intval($pedidos_resumo_db['entregues'] ?? 0),
        ];

        $by_canal = ['ONLINE' => 0, 'BALCAO' => 0, 'GARCOM' => 0, 'TELEFONE' => 0, 'OUTRO' => 0];
        $by_canal_hoje = ['ONLINE' => 0, 'BALCAO' => 0, 'GARCOM' => 0, 'TELEFONE' => 0, 'OUTRO' => 0];
        $pedidos_hoje_total = 0;
        $oldest_pending_orders = [];
        $oldest_pending_minutes = null;

        // Additional data for improved pending orders / channel display
        try {
            if (function_exists('pedido_schema_garantir')) {
                pedido_schema_garantir($db);
            }

            $stmt_oldest = $db->prepare("SELECT MIN(criado_em) AS oldest_pending FROM pedidos WHERE restaurante_id = :rid AND UPPER(TRIM(status)) IN ('NOVO','PENDENTE','PREPARANDO')");
            $stmt_oldest->execute([':rid' => $rid]);
            $oldest_row = $stmt_oldest->fetch(PDO::FETCH_ASSOC) ?: [];
            $oldest_pending_ts = $oldest_row['oldest_pending'] ?? null;
            if ($oldest_pending_ts) {
                $oldest_dt = new DateTime($oldest_pending_ts);
                $now_dt = new DateTime();
                $interval = $now_dt->getTimestamp() - $oldest_dt->getTimestamp();
                $oldest_pending_minutes = (int)floor($interval / 60);
            }

            // Canal normalizado: origem explícita → senão inferência (garçom/mesa) → BALCAO
            $canalCaseSql = "CASE
                        WHEN UPPER(TRIM(COALESCE(origem, ''))) IN ('QR', 'ONLINE') THEN 'ONLINE'
                    WHEN UPPER(TRIM(COALESCE(origem, ''))) IN ('GARCOM') THEN 'GARCOM'
                    WHEN UPPER(TRIM(COALESCE(origem, ''))) IN ('BALCAO', 'PRESENCIAL') THEN 'BALCAO'
                    WHEN UPPER(TRIM(COALESCE(origem, ''))) IN ('TELEFONE') THEN 'TELEFONE'
                    WHEN COALESCE(garcom_id, 0) > 0 THEN 'GARCOM'
                    WHEN COALESCE(mesa_id, 0) > 0 THEN 'BALCAO'
                    ELSE 'BALCAO'
                END";

            // Fila activa (pendentes) por canal — soma correcta (GROUP BY da expressão)
            $stmt_by_canal = $db->prepare("
                SELECT canal AS origem, COUNT(*) AS cnt
                FROM (
                    SELECT {$canalCaseSql} AS canal
                  FROM pedidos
                  WHERE restaurante_id = :rid
                      AND UPPER(TRIM(COALESCE(status, ''))) IN ('NOVO','PENDENTE','PREPARANDO')
                ) t
                GROUP BY canal
            ");
            $stmt_by_canal->execute([':rid' => $rid]);
            while ($r = $stmt_by_canal->fetch(PDO::FETCH_ASSOC)) {
                $key = strtoupper((string)($r['origem'] ?? 'BALCAO'));
                $by_canal[$key] = intval($r['cnt'] ?? 0);
            }

            // Pedidos de hoje por canal (visão executiva — alinha com vendas do dia)
            $stmt_by_canal_hoje = $db->prepare("
                SELECT canal AS origem, COUNT(*) AS cnt
                FROM (
                    SELECT {$canalCaseSql} AS canal
                    FROM pedidos
                    WHERE restaurante_id = :rid
                      AND (criado_em >= CURDATE() AND criado_em < CURDATE() + INTERVAL 1 DAY)
                      AND UPPER(TRIM(COALESCE(status, ''))) NOT IN ('CANCELADO')
                ) t
                GROUP BY canal
            ");
            $stmt_by_canal_hoje->execute([':rid' => $rid]);
            while ($r = $stmt_by_canal_hoje->fetch(PDO::FETCH_ASSOC)) {
                $key = strtoupper((string)($r['origem'] ?? 'BALCAO'));
                $cnt = intval($r['cnt'] ?? 0);
                $by_canal_hoje[$key] = $cnt;
                $pedidos_hoje_total += $cnt;
            }

            // Top 3 oldest pending orders for EMPRESARIAL view
            $clienteExpr = 'NULL';
            try {
                if (pedido_schema_coluna_existe($db, 'pedidos', 'cliente_nome')) {
                    $clienteExpr = 'cliente_nome';
                } elseif (pedido_schema_coluna_existe($db, 'pedidos', 'cliente')) {
                    $clienteExpr = 'cliente';
                }
            } catch (Throwable $eCol) {
                $clienteExpr = 'NULL';
            }
            $stmt_top_pending = $db->prepare("
                SELECT id,
                       COALESCE({$clienteExpr}, '') AS cliente_nome,
                       {$canalCaseSql} AS origem,
                    criado_em
                  FROM pedidos
                  WHERE restaurante_id = :rid
                  AND UPPER(TRIM(COALESCE(status, ''))) IN ('NOVO','PENDENTE','PREPARANDO')
                  ORDER BY criado_em ASC
                LIMIT 3
            ");
            $stmt_top_pending->execute([':rid' => $rid]);
            while ($r = $stmt_top_pending->fetch(PDO::FETCH_ASSOC)) {
                $age = null;
                if (!empty($r['criado_em'])) {
                    $d = new DateTime($r['criado_em']);
                    $age = (int)floor((time() - $d->getTimestamp()) / 60);
                }
                $oldest_pending_orders[] = [
                    'id' => intval($r['id']),
                    'cliente_nome' => $r['cliente_nome'] ?? '',
                    'canal' => strtoupper((string)$r['origem']),
                    'age_min' => $age
                ];
            }
        } catch (Throwable $e) {
            $oldest_pending_minutes = null;
            $by_canal = ['ONLINE' => 0, 'BALCAO' => 0, 'GARCOM' => 0, 'TELEFONE' => 0, 'OUTRO' => 0];
            $by_canal_hoje = ['ONLINE' => 0, 'BALCAO' => 0, 'GARCOM' => 0, 'TELEFONE' => 0, 'OUTRO' => 0];
            $pedidos_hoje_total = 0;
            $oldest_pending_orders = [];
        }

        if ($plano_atual === 'BASICO' && ($qtd_hoje >= 25 || $total_produtos >= 80 || $pedidos_resumo['pendentes'] >= 10)) {
            $sugestao_upgrade = [
                'mostrar' => true,
                'plano' => 'PROFISSIONAL',
                'mensagem' => 'O volume está a crescer. O Profissional libera fichas técnicas, baixa automática, relatórios avançados e histórico de clientes.',
            ];
        } elseif ($plano_atual === 'PROFISSIONAL' && ($qtd_hoje >= 80 || $total_hoje >= 25000 || $pedidos_resumo['pendentes'] >= 20)) {
            $sugestao_upgrade = [
                'mostrar' => true,
                'plano' => 'EMPRESARIAL',
                'mensagem' => 'Operação em escala. O Empresarial adiciona multi-filial, QR de reservas, transferência de estoque e visão da rede.',
            ];
        }

        try {
            $team_online = presenca_buscar_equipa_online($db, intval($rid), 12);
        } catch (Throwable $e) {
            $team_online = [
                'online' => 0,
                'total' => 0,
                'equipa' => [],
            ];
        }

        // Se for empresarial, buscar métricas de desempenho da equipa (pedidos e receita do dia)
        $team_performance = [];
        if ($plano_atual === 'EMPRESARIAL') {
            $team_user_ids = [];
            foreach ($team_online['equipa'] as $m) {
                if (!empty($m['id'])) {
                    $team_user_ids[] = intval($m['id']);
                } elseif (!empty($m['usuario_id'])) {
                    $team_user_ids[] = intval($m['usuario_id']);
                } elseif (!empty($m['user_id'])) {
                    $team_user_ids[] = intval($m['user_id']);
                }
            }
            $team_user_ids = array_values(array_unique(array_filter($team_user_ids)));

            if (!empty($team_user_ids)) {
                    $in = implode(',', array_fill(0, count($team_user_ids), '?'));
                $paramsBase = array_merge([intval($rid)], $team_user_ids);

                // Pedidos atribuídos ao colaborador via garcom_id (coluna real em pedidos)
                try {
                    $sqlPedidos = "
                        SELECT garcom_id AS usuario_id,
                               COUNT(*) AS pedidos_count,
                               COALESCE(SUM(COALESCE(total, 0)), 0) AS valor_total
                        FROM pedidos
                        WHERE restaurante_id = ?
                          AND (criado_em >= CURDATE() AND criado_em < CURDATE() + INTERVAL 1 DAY)
                          AND UPPER(TRIM(COALESCE(status, ''))) NOT IN ('CANCELADO')
                          AND garcom_id IS NOT NULL
                          AND garcom_id > 0
                          AND garcom_id IN ($in)
                        GROUP BY garcom_id
                    ";
                    $stmt_perf = $db->prepare($sqlPedidos);
                    $stmt_perf->execute($paramsBase);
                    while ($r = $stmt_perf->fetch(PDO::FETCH_ASSOC)) {
                        $uid = intval($r['usuario_id'] ?? 0);
                        if ($uid <= 0) {
                            continue;
                        }
                        $team_performance[$uid] = [
                            'pedidos' => intval($r['pedidos_count'] ?? 0),
                            'valor' => floatval($r['valor_total'] ?? 0),
                            'vendas' => 0,
                        ];
                    }
                } catch (Throwable $e) {
                    // Mantém o que já tiver.
                }

                // Receita de vendas fechadas pelo utilizador (caixa/admin) — tabela vendas.usuario_id
                try {
                    $sqlVendas = "
                        SELECT usuario_id,
                               COUNT(*) AS vendas_count,
                               COALESCE(SUM(COALESCE(total_final, total, 0)), 0) AS valor_total
                        FROM vendas
                        WHERE restaurante_id = ?
                          AND (criado_em >= CURDATE() AND criado_em < CURDATE() + INTERVAL 1 DAY)
                          AND UPPER(TRIM(COALESCE(status, ''))) = 'PAGO'
                          AND usuario_id IS NOT NULL
                          AND usuario_id > 0
                          AND usuario_id IN ($in)
                        GROUP BY usuario_id
                    ";
                    $stmt_vendas = $db->prepare($sqlVendas);
                    $stmt_vendas->execute($paramsBase);
                    while ($r = $stmt_vendas->fetch(PDO::FETCH_ASSOC)) {
                        $uid = intval($r['usuario_id'] ?? 0);
                        if ($uid <= 0) {
                            continue;
                        }
                        if (!isset($team_performance[$uid])) {
                            $team_performance[$uid] = ['pedidos' => 0, 'valor' => 0.0, 'vendas' => 0];
                        }
                        $vendasValor = floatval($r['valor_total'] ?? 0);
                        $vendasCount = intval($r['vendas_count'] ?? 0);
                        $team_performance[$uid]['vendas'] = $vendasCount;
                        // Receita exibida: o maior entre total de pedidos atribuídos e vendas fechadas
                        // (evita subcontar quando o caixa fecha e o garçom abriu o pedido)
                        if ($vendasValor > floatval($team_performance[$uid]['valor'])) {
                            $team_performance[$uid]['valor'] = $vendasValor;
                        }
                        // Se não tem pedidos como garçom, mostra vendas como volume operacional
                        if (intval($team_performance[$uid]['pedidos']) <= 0 && $vendasCount > 0) {
                            $team_performance[$uid]['pedidos'] = $vendasCount;
                        }
                    }
                } catch (Throwable $e) {
                    // Fallback sem coluna total_final
                    try {
                        $sqlVendas2 = "
                            SELECT usuario_id,
                                   COUNT(*) AS vendas_count,
                                   COALESCE(SUM(COALESCE(total, 0)), 0) AS valor_total
                            FROM vendas
                            WHERE restaurante_id = ?
                              AND (criado_em >= CURDATE() AND criado_em < CURDATE() + INTERVAL 1 DAY)
                              AND UPPER(TRIM(COALESCE(status, ''))) = 'PAGO'
                              AND usuario_id IS NOT NULL
                              AND usuario_id > 0
                              AND usuario_id IN ($in)
                            GROUP BY usuario_id
                        ";
                        $stmt_vendas2 = $db->prepare($sqlVendas2);
                        $stmt_vendas2->execute($paramsBase);
                        while ($r = $stmt_vendas2->fetch(PDO::FETCH_ASSOC)) {
                            $uid = intval($r['usuario_id'] ?? 0);
                            if ($uid <= 0) {
                                continue;
                            }
                            if (!isset($team_performance[$uid])) {
                                $team_performance[$uid] = ['pedidos' => 0, 'valor' => 0.0, 'vendas' => 0];
                            }
                            $vendasValor = floatval($r['valor_total'] ?? 0);
                            $vendasCount = intval($r['vendas_count'] ?? 0);
                            $team_performance[$uid]['vendas'] = $vendasCount;
                            if ($vendasValor > floatval($team_performance[$uid]['valor'])) {
                                $team_performance[$uid]['valor'] = $vendasValor;
                            }
                            if (intval($team_performance[$uid]['pedidos']) <= 0 && $vendasCount > 0) {
                                $team_performance[$uid]['pedidos'] = $vendasCount;
                            }
                        }
                    } catch (Throwable $e2) {
                        // Silencioso.
                    }
                }
            }
        }

        return array_intersect_key(get_defined_vars(), array_flip(self::SAIDAS_DADOS_ADMIN));
    }

    /**
     * Dados operacionais do gerente (sem analytics financeiros do dono).
     * Recebe as variaveis ENTRADAS_DADOS_GERENTE e devolve as SAIDAS_DADOS_GERENTE que ficaram definidas.
     */
    public function dadosGerente(array $entrada): array
    {
        extract($entrada);
        $db = $this->db;

        try {
            $stmt_pedidos_resumo = $db->prepare("SELECT SUM(CASE WHEN UPPER(TRIM(status)) IN ('NOVO','PENDENTE','PREPARANDO') THEN 1 ELSE 0 END) AS pendentes, SUM(CASE WHEN UPPER(TRIM(status)) = 'PREPARANDO' THEN 1 ELSE 0 END) AS preparando, SUM(CASE WHEN UPPER(TRIM(status)) = 'PRONTO' THEN 1 ELSE 0 END) AS prontos, SUM(CASE WHEN UPPER(TRIM(status)) = 'ENTREGUE' THEN 1 ELSE 0 END) AS entregues FROM pedidos WHERE restaurante_id = :rid AND (criado_em >= CURDATE() AND criado_em < CURDATE() + INTERVAL 1 DAY)");
            $stmt_pedidos_resumo->execute([':rid' => $rid]);
            $pedidos_resumo_db = $stmt_pedidos_resumo->fetch(PDO::FETCH_ASSOC) ?: [];
            $pedidos_resumo = [
                'pendentes' => intval($pedidos_resumo_db['pendentes'] ?? 0),
                'preparando' => intval($pedidos_resumo_db['preparando'] ?? 0),
                'prontos' => intval($pedidos_resumo_db['prontos'] ?? 0),
                'entregues' => intval($pedidos_resumo_db['entregues'] ?? 0),
            ];
        } catch (Throwable $e) {
            error_log('[DASHBOARD][GERENTE][PEDIDOS] ' . $e->getMessage());
        }
        try {
            if (function_exists('presenca_buscar_equipa_online')) {
                $team_online = presenca_buscar_equipa_online($db, intval($rid), 12);
            }
        } catch (Throwable $e) {
            // mantém defaults
        }

        return array_intersect_key(get_defined_vars(), array_flip(self::SAIDAS_DADOS_GERENTE));
    }

    /**
     * Alertas de stock simples (planos sem estoque avancado).
     * Recebe as variaveis ENTRADAS_ESTOQUE_SIMPLES e devolve as SAIDAS_ESTOQUE_SIMPLES que ficaram definidas.
     */
    public function estoqueSimples(array $entrada): array
    {
        extract($entrada);
        $db = $this->db;

        try {
            $stmtEstoqueSimples = $db->prepare("
                SELECT
                    SUM(CASE WHEN COALESCE(estoque, 0) <= 0 THEN 1 ELSE 0 END) AS zerados,
                    SUM(CASE WHEN COALESCE(estoque, 0) > 0 AND COALESCE(estoque, 0) <= COALESCE(estoque_minimo, 0) THEN 1 ELSE 0 END) AS baixo,
                    SUM(CASE WHEN COALESCE(estoque, 0) > COALESCE(estoque_minimo, 0) THEN 1 ELSE 0 END) AS ok,
                    COUNT(*) AS total
                FROM produtos
                WHERE restaurante_id = :rid
                  AND ativo = 1
            ");
            $stmtEstoqueSimples->execute([':rid' => (int)$rid]);
            $rowEstoqueSimples = $stmtEstoqueSimples->fetch(PDO::FETCH_ASSOC) ?: [];
            $estoqueSimplesResumo = [
                'zerados' => (int)($rowEstoqueSimples['zerados'] ?? 0),
                'baixo' => (int)($rowEstoqueSimples['baixo'] ?? 0),
                'ok' => (int)($rowEstoqueSimples['ok'] ?? 0),
                'total' => (int)($rowEstoqueSimples['total'] ?? 0),
            ];
        } catch (Throwable $eEstoqueSimples) {
            error_log('[DASHBOARD][ESTOQUE_SIMPLES] ' . $eEstoqueSimples->getMessage());
        }

        return array_intersect_key(get_defined_vars(), array_flip(self::SAIDAS_ESTOQUE_SIMPLES));
    }

    /**
     * Estoque inteligente: alertas, previsao e feed (planos com estoque avancado).
     * Recebe as variaveis ENTRADAS_ESTOQUE_AVANCADO e devolve as SAIDAS_ESTOQUE_AVANCADO que ficaram definidas.
     */
    public function estoqueAvancado(array $entrada): array
    {
        extract($entrada);
        $db = $this->db;

        try {
            estoque_inteligente_schema_garantir($db);
            $filialEstoqueDashboard = null;
            if ($planoRid > 0 && $authRid > 0 && $authRid !== $planoRid) {
                $filialEstoqueDashboard = $authRid;
            }

            // Os alertas de stock sao recalculados no maximo uma vez por hora (por sessao):
            // antes corriam em cada abertura do dashboard e escreviam na base sem necessidade.
            $chaveAlertas = 'estoque_alertas_dash_' . (int)$rid . '_' . (int)$filialEstoqueDashboard;
            $ultimoCalculo = (int)($_SESSION[$chaveAlertas] ?? 0);
            if ((time() - $ultimoCalculo) >= 3600) {
                $_SESSION[$chaveAlertas] = time();
                try {
                    estoque_gerar_alertas_automaticos($db, (int)$rid, $filialEstoqueDashboard);
                } catch (Throwable $alertaGenErr) {
                    error_log('[DASHBOARD][ESTOQUE][ALERTAS] ' . $alertaGenErr->getMessage());
                }
            }

            $sqlEstoqueAvancado = "
                SELECT i.id,
                       i.nome,
                       i.unidade_base,
                       i.estoque_minimo_base AS estoque_minimo,
                       COALESCE(s.saldo_base, 0) AS estoque
                FROM estoque_insumos i
                LEFT JOIN estoque_saldos s
                  ON s.insumo_id = i.id
                 AND s.restaurante_id = i.restaurante_id
                 AND ((s.filial_id IS NULL AND i.filial_id IS NULL) OR s.filial_id = i.filial_id)
                WHERE i.restaurante_id = :rid
                  AND i.ativo = 1
            ";
            if ($filialEstoqueDashboard !== null) {
                $sqlEstoqueAvancado .= " AND i.filial_id = :filial_id";
            } else {
                $sqlEstoqueAvancado .= " AND i.filial_id IS NULL";
            }
            $sqlEstoqueAvancado .= " ORDER BY i.nome ASC";

            $stmtEstoqueAvancado = $db->prepare($sqlEstoqueAvancado);
            $stmtEstoqueAvancado->bindValue(':rid', $rid, PDO::PARAM_INT);
            if ($filialEstoqueDashboard !== null) {
                $stmtEstoqueAvancado->bindValue(':filial_id', $filialEstoqueDashboard, PDO::PARAM_INT);
            }
            $stmtEstoqueAvancado->execute();
            $estoqueAvancadoRaw = $stmtEstoqueAvancado->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $estoqueAgrupado = [];
            foreach ($estoqueAvancadoRaw as $row) {
                $nomeEstoque = trim((string)($row['nome'] ?? ''));
                $unidadeEstoque = trim((string)($row['unidade_base'] ?? 'un'));
                $chaveEstoque = estoque_normalizar_nome_chave($nomeEstoque) . '|' . $unidadeEstoque;
                if (!isset($estoqueAgrupado[$chaveEstoque])) {
                    $estoqueAgrupado[$chaveEstoque] = [
                        'id' => (int)($row['id'] ?? 0),
                        'nome' => $nomeEstoque,
                        'unidade_base' => $unidadeEstoque,
                        'estoque_minimo' => (float)($row['estoque_minimo'] ?? 0),
                        'estoque' => 0.0,
                    ];
                }
                $estoqueAgrupado[$chaveEstoque]['estoque'] += (float)($row['estoque'] ?? 0);
                $estoqueAgrupado[$chaveEstoque]['estoque_minimo'] = max(
                    (float)$estoqueAgrupado[$chaveEstoque]['estoque_minimo'],
                    (float)($row['estoque_minimo'] ?? 0)
                );
            }

            $todosInsumos = array_values($estoqueAgrupado);
            $estoque_resumo['total_insumos'] = count($todosInsumos);
            $estoque_resumo['tem_insumos'] = $estoque_resumo['total_insumos'] > 0;

            $estoque_baixo_full = [];
            foreach ($todosInsumos as $item) {
                $saldoItem = (float)($item['estoque'] ?? 0);
                $minimoItem = (float)($item['estoque_minimo'] ?? 0);
                if ($saldoItem <= 0) {
                    $estoque_resumo['critico']++;
                    $estoque_baixo_full[] = $item;
                } elseif ($minimoItem > 0 && $saldoItem <= $minimoItem) {
                    $estoque_resumo['alerta']++;
                    $estoque_baixo_full[] = $item;
                } else {
                    $estoque_resumo['ok']++;
                }
            }
            $estoque_resumo['atencao'] = $estoque_resumo['critico'] + $estoque_resumo['alerta'];

            // Previsão de cobertura (dias até zerar) para itens em atenção
            try {
                $prevOptsDash = ($plano_atual === 'EMPRESARIAL') ? ['modo' => 'empresarial'] : ['modo' => 'operacional'];
                $previsaoRows = estoque_previsao_consumo($db, (int)$rid, $filialEstoqueDashboard, 28, $prevOptsDash);
                foreach ($previsaoRows as $pr) {
                    $estoque_previsao_map[(int)($pr['insumo_id'] ?? 0)] = $pr;
                }
                foreach ($estoque_baixo_full as &$itemCob) {
                    $insumoIdCob = (int)($itemCob['id'] ?? 0);
                    $mediaDia = (float)(($estoque_previsao_map[$insumoIdCob]['media_diaria'] ?? 0));
                    $saldoCob = (float)($itemCob['estoque'] ?? 0);
                    if ($mediaDia > 0 && $saldoCob > 0) {
                        $diasCob = (int)floor($saldoCob / $mediaDia);
                        $itemCob['dias_cobertura'] = $diasCob;
                        if ($diasCob <= 3) {
                            $estoque_resumo['cobertura_critica']++;
                        }
                    } elseif ($saldoCob <= 0) {
                        $itemCob['dias_cobertura'] = 0;
                        $estoque_resumo['cobertura_critica']++;
                    } else {
                        $itemCob['dias_cobertura'] = null;
                    }
                }
                unset($itemCob);
            } catch (Throwable $prevErr) {
                error_log('[DASHBOARD][ESTOQUE][PREVISAO] ' . $prevErr->getMessage());
            }

            // Perdas 30 dias
            $sqlPerdasDash = "
                SELECT COUNT(*) AS total_registros, COALESCE(SUM(custo_perdido), 0) AS custo_total
                FROM estoque_perdas
                WHERE restaurante_id = :rid
                  AND criado_em >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ";
            if ($filialEstoqueDashboard !== null) {
                $sqlPerdasDash .= " AND filial_id = :filial_id";
            } else {
                $sqlPerdasDash .= " AND filial_id IS NULL";
            }
            $stmtPerdasDash = $db->prepare($sqlPerdasDash);
            $stmtPerdasDash->bindValue(':rid', $rid, PDO::PARAM_INT);
            if ($filialEstoqueDashboard !== null) {
                $stmtPerdasDash->bindValue(':filial_id', $filialEstoqueDashboard, PDO::PARAM_INT);
            }
            $stmtPerdasDash->execute();
            $perdasDash = $stmtPerdasDash->fetch(PDO::FETCH_ASSOC) ?: [];
            $estoque_resumo['perdas_30d_regs'] = (int)($perdasDash['total_registros'] ?? 0);
            $estoque_resumo['perdas_30d_custo'] = (float)($perdasDash['custo_total'] ?? 0);

            // Validade próximos 7 dias + alertas abertos
            $sqlValidadeDash = "
                SELECT COUNT(DISTINCT l.insumo_id) AS total
                FROM estoque_lotes l
                WHERE l.restaurante_id = :rid
                  AND l.saldo_base > 0
                  AND l.validade IS NOT NULL
                  AND l.validade BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            ";
            if ($filialEstoqueDashboard !== null) {
                $sqlValidadeDash .= " AND l.filial_id = :filial_id";
            } else {
                $sqlValidadeDash .= " AND l.filial_id IS NULL";
            }
            $stmtValidadeDash = $db->prepare($sqlValidadeDash);
            $stmtValidadeDash->bindValue(':rid', $rid, PDO::PARAM_INT);
            if ($filialEstoqueDashboard !== null) {
                $stmtValidadeDash->bindValue(':filial_id', $filialEstoqueDashboard, PDO::PARAM_INT);
            }
            $stmtValidadeDash->execute();
            $estoque_resumo['validade_7d'] = (int)($stmtValidadeDash->fetchColumn() ?: 0);

            $sqlAlertasDash = "
                SELECT COUNT(*) FROM estoque_alertas
                WHERE restaurante_id = :rid AND resolvido = 0
            ";
            if ($filialEstoqueDashboard !== null) {
                $sqlAlertasDash .= " AND filial_id = :filial_id";
            } else {
                $sqlAlertasDash .= " AND filial_id IS NULL";
            }
            $stmtAlertasDash = $db->prepare($sqlAlertasDash);
            $stmtAlertasDash->bindValue(':rid', $rid, PDO::PARAM_INT);
            if ($filialEstoqueDashboard !== null) {
                $stmtAlertasDash->bindValue(':filial_id', $filialEstoqueDashboard, PDO::PARAM_INT);
            }
            $stmtAlertasDash->execute();
            $estoque_resumo['alertas_abertos'] = (int)($stmtAlertasDash->fetchColumn() ?: 0);

            // Feed de alertas abertos (card do dashboard Empresarial)
            $estoque_alertas_feed = [];
            try {
                $sqlAlertasFeed = "
                    SELECT a.id, a.tipo, a.severidade, a.titulo, a.detalhe, a.criado_em,
                           i.nome AS insumo_nome, i.unidade_base, i.estoque_minimo_base,
                           COALESCE(s.saldo_base, 0) AS saldo_base
                    FROM estoque_alertas a
                    LEFT JOIN estoque_insumos i ON i.id = a.insumo_id
                    LEFT JOIN estoque_saldos s
                      ON s.insumo_id = a.insumo_id
                     AND s.restaurante_id = a.restaurante_id
                     AND (
                            (a.filial_id IS NULL AND s.filial_id IS NULL)
                         OR (a.filial_id IS NOT NULL AND s.filial_id = a.filial_id)
                     )
                    WHERE a.restaurante_id = :rid
                      AND a.resolvido = 0
                ";
                if ($filialEstoqueDashboard !== null) {
                    $sqlAlertasFeed .= " AND a.filial_id = :filial_id";
                } else {
                    $sqlAlertasFeed .= " AND a.filial_id IS NULL";
                }
                $sqlAlertasFeed .= "
                    ORDER BY FIELD(a.severidade, 'CRITICA', 'ALTA', 'MEDIA', 'BAIXA'), a.criado_em DESC
                    LIMIT 12
                ";
                $stmtAlertasFeed = $db->prepare($sqlAlertasFeed);
                $stmtAlertasFeed->bindValue(':rid', $rid, PDO::PARAM_INT);
                if ($filialEstoqueDashboard !== null) {
                    $stmtAlertasFeed->bindValue(':filial_id', $filialEstoqueDashboard, PDO::PARAM_INT);
                }
                $stmtAlertasFeed->execute();
                $feedRaw = $stmtAlertasFeed->fetchAll(PDO::FETCH_ASSOC) ?: [];
                // Não mostrar reposição se o saldo já está acima do mínimo (alerta fantasma).
                // Consumo anómalo só vale para o dia atual.
                $estoque_alertas_feed = [];
                $hojeYmd = date('Y-m-d');
                foreach ($feedRaw as $alRow) {
                    $tipoAl = strtoupper((string)($alRow['tipo'] ?? ''));
                    if ($tipoAl === 'REPOSICAO') {
                        $saldoAl = (float)($alRow['saldo_base'] ?? 0);
                        $minAl = (float)($alRow['estoque_minimo_base'] ?? 0);
                        if ($minAl > 0 && $saldoAl > $minAl) {
                            continue;
                        }
                    }
                    if ($tipoAl === 'CONSUMO_ANOMALO') {
                        $criadoDia = substr((string)($alRow['criado_em'] ?? ''), 0, 10);
                        if ($criadoDia !== '' && $criadoDia < $hojeYmd) {
                            continue;
                        }
                    }
                    $estoque_alertas_feed[] = $alRow;
                    if (count($estoque_alertas_feed) >= 6) {
                        break;
                    }
                }
            } catch (Throwable $feedErr) {
                error_log('[DASHBOARD][ESTOQUE][FEED] ' . $feedErr->getMessage());
                $estoque_alertas_feed = [];
            }

            usort($estoque_baixo_full, static function ($a, $b) {
                $estoqueA = (float)($a['estoque'] ?? 0);
                $estoqueB = (float)($b['estoque'] ?? 0);
                if ($estoqueA === $estoqueB) {
                    return strcmp((string)($a['nome'] ?? ''), (string)($b['nome'] ?? ''));
                }
                return $estoqueA <=> $estoqueB;
            });

            $estoque_baixo = array_slice($estoque_baixo_full, 0, 6);
            $estoqueCardTitulo = 'Estoque Inteligente';
            $estoqueCardLink = 'estoque_inteligente.php';
            $estoqueCardLinkLabel = 'Abrir Estoque';
        } catch (Throwable $estoqueDashboardError) {
            error_log('[DASHBOARD][ESTOQUE] ' . $estoqueDashboardError->getMessage());
            $estoque_baixo = [];
        }

        return array_intersect_key(get_defined_vars(), array_flip(self::SAIDAS_ESTOQUE_AVANCADO));
    }

    /**
     * Insights de stock do plano Empresarial (score e cobertura).
     * Recebe as variaveis ENTRADAS_ESTOQUE_INSIGHTS_EMPRESARIAL e devolve as SAIDAS_ESTOQUE_INSIGHTS_EMPRESARIAL que ficaram definidas.
     */
    public function estoqueInsightsEmpresarial(array $entrada): array
    {
        extract($entrada);
        $db = $this->db;

        try {
            $filialEmpInsights = null;
            if ($planoRid > 0 && $authRid > 0 && $authRid !== $planoRid) {
                $filialEmpInsights = $authRid;
            }
            $dashEmpInsights = estoque_insights_empresariais(
                $db,
                (int)$rid,
                $filialEmpInsights,
                array_values($estoque_previsao_map)
            );
        } catch (Throwable $empInsightsErr) {
            error_log('[DASHBOARD][EMP_INSIGHTS] ' . $empInsightsErr->getMessage());
            $dashEmpInsights = null;
        }

        return array_intersect_key(get_defined_vars(), array_flip(self::SAIDAS_ESTOQUE_INSIGHTS_EMPRESARIAL));
    }

    /**
     * Notas de stock em linguagem simples do plano Empresarial.
     * Recebe as variaveis ENTRADAS_ESTOQUE_NOTAS_EMPRESARIAL e devolve as SAIDAS_ESTOQUE_NOTAS_EMPRESARIAL que ficaram definidas.
     */
    public function estoqueNotasEmpresarial(array $entrada): array
    {
        extract($entrada);
        $db = $this->db;

        $faltamAmanha = [];
        $reporHoje = [];
        foreach ($estoque_previsao_map as $prevItem) {
            $nomePrev = (string)($prevItem['nome'] ?? 'Insumo');
            $diasPrev = (float)($prevItem['dias_cobertura'] ?? 999);
            $riscoPrev = (string)($prevItem['risco'] ?? '');
            $saldoPrev = (float)($prevItem['saldo_base'] ?? 0);
            if ($saldoPrev <= 0 || $diasPrev <= 0 || $riscoPrev === 'CRITICO') {
                $reporHoje[] = $nomePrev;
            } elseif ($diasPrev > 0 && $diasPrev <= 1.5) {
                $faltamAmanha[] = $nomePrev;
            }
        }
        $reporHoje = array_values(array_unique(array_slice($reporHoje, 0, 3)));
        $faltamAmanha = array_values(array_unique(array_slice($faltamAmanha, 0, 3)));
        $validade7 = (int)($estoque_resumo['validade_7d'] ?? 0);
        $semFichaN = is_array($dashEmpInsights) ? (int)($dashEmpInsights['produtos_sem_ficha'] ?? 0) : 0;

        if ($reporHoje) {
            $dashEmpStockNotas[] = [
                'tom' => 'warn',
                'titulo' => 'Repor hoje: ' . implode(', ', $reporHoje),
                'txt' => 'Estes insumos estão sem cobertura ou em risco crítico. Sem reposição, a cozinha pode parar nos pratos que dependem deles.',
            ];
        }
        if ($faltamAmanha) {
            $dashEmpStockNotas[] = [
                'tom' => 'warn',
                'titulo' => 'Amanhã pode faltar: ' . implode(', ', $faltamAmanha),
                'txt' => 'Com o ritmo actual de consumo, a cobertura fica abaixo de 1–2 dias. Organize a compra ainda hoje.',
            ];
        }
        if ($validade7 > 0) {
            $dashEmpStockNotas[] = [
                'tom' => 'info',
                'titulo' => $validade7 . ' lote(s) a vencer em 7 dias',
                'txt' => 'Priorize estes produtos no cardápio ou na produção para evitar perda.',
            ];
        }
        if ($semFichaN > 0) {
            $dashEmpStockNotas[] = [
                'tom' => 'info',
                'titulo' => $semFichaN . ' prato(s) sem ficha',
                'txt' => 'Sem ficha a baixa nas vendas fica incompleta. Complete na aba Fichas.',
                'href' => 'estoque_inteligente.php?tab=fichas',
            ];
        }
        // Manter até 3 notas; prioridade já está na ordem acima.
        $dashEmpStockNotas = array_slice($dashEmpStockNotas, 0, 3);

        return array_intersect_key(get_defined_vars(), array_flip(self::SAIDAS_ESTOQUE_NOTAS_EMPRESARIAL));
    }

    /**
     * Resumo das reservas ativas para o cartao do dashboard.
     * Recebe as variaveis ENTRADAS_RESERVAS_HOJE e devolve as SAIDAS_RESERVAS_HOJE que ficaram definidas.
     */
    public function reservasHoje(array $entrada): array
    {
        extract($entrada);
        $db = $this->db;

        try {
            $stmt_dashboard_reservas = $db->prepare("
                    SELECT
                        COUNT(*) AS total_ativas,
                        SUM(CASE WHEN status = 'pendente' THEN 1 ELSE 0 END) AS pendentes,
                        SUM(CASE WHEN status = 'confirmado' THEN 1 ELSE 0 END) AS confirmadas
                    FROM reservas
                    WHERE restaurante_id = :restaurante_id
                      AND data_reserva >= CURDATE()
                      AND status IN ('pendente', 'confirmado')
                ");
            $stmt_dashboard_reservas->execute([':restaurante_id' => $rid]);
            $resumo_dashboard_reservas = $stmt_dashboard_reservas->fetch(PDO::FETCH_ASSOC) ?: [];

            $stmt_dashboard_reservas_ultima = $db->prepare("
                    SELECT
                        r.id,
                        r.nome_cliente,
                        r.data_reserva,
                        r.hora_reserva,
                        r.mesa_atribuida,
                        m.numero AS mesa_numero,
                        r.criado_em
                    FROM reservas r
                    LEFT JOIN mesas m ON m.id = r.mesa_atribuida
                    WHERE r.restaurante_id = :restaurante_id
                      AND r.data_reserva >= CURDATE()
                      AND r.status = 'pendente'
                    ORDER BY r.id DESC
                    LIMIT 1
                ");
            $stmt_dashboard_reservas_ultima->execute([':restaurante_id' => $rid]);
            $ultimaReservaDashboard = $stmt_dashboard_reservas_ultima->fetch(PDO::FETCH_ASSOC) ?: null;

            $dashboard_reservas = [
                'total_ativas' => (int)($resumo_dashboard_reservas['total_ativas'] ?? 0),
                'pendentes' => (int)($resumo_dashboard_reservas['pendentes'] ?? 0),
                'confirmadas' => (int)($resumo_dashboard_reservas['confirmadas'] ?? 0),
                'ultima_pendente' => $ultimaReservaDashboard ? [
                    'id' => (int)($ultimaReservaDashboard['id'] ?? 0),
                    'nome_cliente' => (string)($ultimaReservaDashboard['nome_cliente'] ?? ''),
                    'data_reserva' => (string)($ultimaReservaDashboard['data_reserva'] ?? ''),
                    'hora_reserva' => (string)($ultimaReservaDashboard['hora_reserva'] ?? ''),
                    'mesa_atribuida' => (int)($ultimaReservaDashboard['mesa_atribuida'] ?? 0),
                    'mesa_numero' => $ultimaReservaDashboard['mesa_numero'] ?? null,
                    'criado_em' => (string)($ultimaReservaDashboard['criado_em'] ?? ''),
                ] : null,
            ];
        } catch (Throwable $e) {
            error_log('[DASHBOARD][RESERVAS] ' . $e->getMessage());
        }

        return array_intersect_key(get_defined_vars(), array_flip(self::SAIDAS_RESERVAS_HOJE));
    }
}
