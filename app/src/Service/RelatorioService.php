<?php

/**
 * Dados dos relatorios (src/public/relatorios.php), separados da apresentacao e da exportacao.
 *
 * Fase B do plano de divisao dos ficheiros gigantes: cada metodo e um bloco de consultas
 * que estava no topo da pagina, movido sem alteracoes. As constantes ENTRADAS_/SAIDAS_
 * documentam o que cada metodo usa e devolve.
 */
final class RelatorioService
{
    /** Variaveis da pagina lidas por carregarDados(). */
    public const ENTRADAS_CARREGAR_DADOS = ['data_fim', 'data_inicio', 'e', 'grand_total', 'pedidos_qr_hora', 'pedidos_qr_mesa', 'por_pagamento', 'restaurante_id', 'resumo_qr', 'tipo'];
    /** Variaveis que carregarDados() devolve a pagina. */
    public const SAIDAS_CARREGAR_DADOS = ['erro_dados', 'forma', 'grand_total', 'h', 'm', 'movimentos', 'p', 'pedidos_qr_hora', 'pedidos_qr_mesa', 'pico_best', 'pico_horas', 'pico_max', 'por_pagamento', 'prep_por_hora', 'prep_resumo', 'produtos', 'resumo_qr', 'ticket_medio', 'total_vendas', 'v', 'valor_total', 'vendas', 'pagamentos_resumo', 'cancelados_resumo'];

    /** Variaveis da pagina lidas por inteligenciaEmpresarial(). */
    public const ENTRADAS_INTELIGENCIA_EMPRESARIAL = ['data_fim', 'data_inicio', 'forma', 'h', 'movimentos', 'p', 'pedidos_qr_mesa', 'pico_best', 'pico_horas', 'prep_por_hora', 'prep_resumo', 'produtos', 'relEmpIntel', 'relatorios_formatar_duracao', 'restaurante_id', 'resumo_qr', 'tem_analise_abc_vendas', 'tem_previsao_demanda', 'tem_recomendacoes_automaticas', 'ticket_medio', 'tipo', 'v', 'valor_total', 'vendas'];
    /** Variaveis que inteligenciaEmpresarial() devolve a pagina. */
    public const SAIDAS_INTELIGENCIA_EMPRESARIAL = ['forma', 'h', 'p', 'pedidos_qr_mesa', 'ph', 'pico_horas', 'prep_resumo', 'produtos', 'relEmpIntel', 'resumo_qr'];

    /** Pasta de relatorios.php: o codigo movido usava __DIR__ relativo a ela. */
    private const PASTA_PAGINA = __DIR__ . '/../../../src/public';

    private ?PDO $db;

    public function __construct(?PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Dados do relatorio pedido (vendas/financeiro, produtos, movimentos, QR, preparacao) no periodo.
     * Recebe as variaveis ENTRADAS_CARREGAR_DADOS e devolve as SAIDAS_CARREGAR_DADOS que ficaram definidas.
     */
    public function carregarDados(array $entrada): array
    {
        extract($entrada);
        $db = $this->db;

        try {
            if ($tipo === 'vendas' || $tipo === 'financeiro'):
                $stmt = $db->prepare("SELECT v.*, u.nome as usuario_nome, m.numero as mesa_numero FROM vendas v LEFT JOIN usuarios u ON v.usuario_id = u.id LEFT JOIN mesas m ON v.mesa_id = m.id WHERE v.restaurante_id = ? AND (v.criado_em >= ? AND v.criado_em < ? + INTERVAL 1 DAY) ORDER BY v.criado_em DESC");
                $stmt->execute([$restaurante_id, $data_inicio, $data_fim]);
                $vendas = $stmt->fetchAll(PDO::FETCH_ASSOC);
                // Só as vendas PAGAS contam para o faturamento (as canceladas ficam à parte).
                $total_vendas = 0;
                $valor_total = 0;
                $pagamentos_resumo = [];
                $cancelados_resumo = ['qtd' => 0, 'valor' => 0.0, 'por_operador' => []];
                foreach ($vendas as $v) {
                    $stV = strtoupper(trim((string)($v['status'] ?? '')));
                    $valV = (float)($v['total_final'] ?? $v['valor_total'] ?? $v['total'] ?? 0);
                    if ($stV === 'CANCELADO' || $stV === 'CANCELADA') {
                        $cancelados_resumo['qtd']++;
                        $cancelados_resumo['valor'] += $valV;
                        $op = trim((string)($v['usuario_nome'] ?? '')) ?: '—';
                        if (!isset($cancelados_resumo['por_operador'][$op])) {
                            $cancelados_resumo['por_operador'][$op] = ['qtd' => 0, 'valor' => 0.0];
                        }
                        $cancelados_resumo['por_operador'][$op]['qtd']++;
                        $cancelados_resumo['por_operador'][$op]['valor'] += $valV;
                        continue;
                    }
                    if ($stV !== '' && $stV !== 'PAGO') {
                        continue;
                    }
                    $total_vendas++;
                    $valor_total += $valV;
                    $fp = strtoupper(trim((string)($v['forma_pagamento'] ?? ''))) ?: 'OUTRO';
                    if (!isset($pagamentos_resumo[$fp])) {
                        $pagamentos_resumo[$fp] = ['qtd' => 0, 'total' => 0.0];
                    }
                    $pagamentos_resumo[$fp]['qtd']++;
                    $pagamentos_resumo[$fp]['total'] += $valV;
                }
                uasort($pagamentos_resumo, static fn($a, $b) => $b['total'] <=> $a['total']);
                uasort($cancelados_resumo['por_operador'], static fn($a, $b) => $b['valor'] <=> $a['valor']);
                $ticket_medio = $total_vendas > 0 ? $valor_total / $total_vendas : 0;
            endif;

            if ($tipo === 'produtos'):
                $stmt = $db->prepare("SELECT
                                        p.id,
                                        p.nome,
                                        p.preco,
                                        COALESCE(NULLIF(TRIM(c.nome), ''), NULLIF(TRIM(p.categoria), ''), '-') AS categoria,
                                        COALESCE(SUM(pv.quantidade), 0) AS total_vendido,
                                        COALESCE(SUM(pv.quantidade * COALESCE(pv.preco_unitario, p.preco)), 0) AS receita
                                      FROM produtos p
                                      LEFT JOIN categorias c ON c.id = p.categoria_id
                                      -- So itens de pedidos validos do periodo: o filtro tem de estar
                                      -- dentro da juncao, senao a soma conta todos os pedidos de sempre.
                                      LEFT JOIN (itens_pedido pv
                                           INNER JOIN pedidos ped ON pv.pedido_id = ped.id
                                                AND (ped.criado_em >= ? AND ped.criado_em < ? + INTERVAL 1 DAY)
                                                AND UPPER(TRIM(COALESCE(ped.status,''))) NOT IN ('CANCELADO'))
                                        ON pv.produto_id = p.id AND ped.restaurante_id = p.restaurante_id
                                      WHERE p.restaurante_id = ?
                                      GROUP BY p.id, p.nome, p.preco, c.nome, p.categoria
                                      HAVING total_vendido > 0 OR receita > 0
                                      ORDER BY receita DESC, total_vendido DESC
                                      LIMIT 40");
                $stmt->execute([$data_inicio, $data_fim, $restaurante_id]);
                $produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Calcular totais para resumo
                $total_vendas = 0;
                $valor_total = 0;
                foreach ($produtos as $p) {
                    $total_vendas += floatval($p['total_vendido'] ?? 0);
                    $valor_total += floatval($p['receita'] ?? 0);
                }
                $ticket_medio = $total_vendas > 0 ? $valor_total / $total_vendas : 0;
            endif;

            if ($tipo === 'caixa'):
                $stmt = $db->prepare("SELECT * FROM caixas WHERE restaurante_id = ? AND DATE(data_abertura) BETWEEN ? AND ? ORDER BY data_abertura DESC");
                $stmt->execute([$restaurante_id, $data_inicio, $data_fim]);
                $movimentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Calcular totais para resumo (com base em entradas/saídas)
                $total_vendas = count($movimentos);
                $valor_total = 0;
                foreach ($movimentos as $m) {
                    $valor_total += floatval($m['entrada'] ?? 0) - floatval($m['saida'] ?? 0);
                }
                $ticket_medio = $total_vendas > 0 ? $valor_total / $total_vendas : 0;
            endif;

            if ($tipo === 'pedidos_qr'):
                $stmt = $db->prepare("SELECT
                                        COALESCE(m.numero, 'Sem mesa') AS mesa_numero,
                                        COUNT(*) AS total_pedidos,
                                        COALESCE(SUM(p.total), 0) AS valor_total,
                                        COALESCE(AVG(p.total), 0) AS ticket_medio
                                      FROM pedidos p
                                      LEFT JOIN mesas m ON p.mesa_id = m.id
                                      WHERE p.restaurante_id = ?
                                      AND (p.criado_em >= ? AND p.criado_em < ? + INTERVAL 1 DAY)
                                      GROUP BY COALESCE(m.numero, 'Sem mesa')
                                      ORDER BY total_pedidos DESC");
                $stmt->execute([$restaurante_id, $data_inicio, $data_fim]);
                $pedidos_qr_mesa = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $stmt = $db->prepare("SELECT
                                        DATE_FORMAT(p.criado_em, '%H:00') AS hora,
                                        COUNT(*) AS total_pedidos,
                                        COALESCE(SUM(p.total), 0) AS valor_total
                                      FROM pedidos p
                                      WHERE p.restaurante_id = ?
                                      AND (p.criado_em >= ? AND p.criado_em < ? + INTERVAL 1 DAY)
                                      GROUP BY DATE_FORMAT(p.criado_em, '%H')
                                      ORDER BY DATE_FORMAT(p.criado_em, '%H') ASC");
                $stmt->execute([$restaurante_id, $data_inicio, $data_fim]);
                $pedidos_qr_hora = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $stmt = $db->prepare("SELECT COUNT(*) AS total_pedidos,
                                             COALESCE(SUM(total), 0) AS total_receita,
                                             COUNT(DISTINCT mesa_id) AS mesas_ativas
                                      FROM pedidos
                                      WHERE restaurante_id = ?
                                      AND (criado_em >= ? AND criado_em < ? + INTERVAL 1 DAY)");
                $stmt->execute([$restaurante_id, $data_inicio, $data_fim]);
                $resumo = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $resumo_qr['total_pedidos'] = (int)($resumo['total_pedidos'] ?? 0);
                $resumo_qr['total_receita'] = (float)($resumo['total_receita'] ?? 0);
                $resumo_qr['mesas_ativas'] = (int)($resumo['mesas_ativas'] ?? 0);

                $hora_pico = '--';
                $max_pedidos_hora = -1;
                foreach ($pedidos_qr_hora as $item_hora) {
                    $count_hora = (int)($item_hora['total_pedidos'] ?? 0);
                    if ($count_hora > $max_pedidos_hora) {
                        $max_pedidos_hora = $count_hora;
                        $hora_pico = $item_hora['hora'] ?? '--';
                    }
                }
                $resumo_qr['hora_pico'] = $hora_pico;
            endif;

            if ($tipo === 'horarios_pico'):
                $stmt = $db->prepare("
                    SELECT HOUR(criado_em) AS hora,
                           COUNT(*) AS qtd_pedidos,
                           COALESCE(SUM(total), 0) AS receita
                    FROM pedidos
                    WHERE restaurante_id = ?
                      AND (criado_em >= ? AND criado_em < ? + INTERVAL 1 DAY)
                      AND status NOT IN ('CANCELADO')
                    GROUP BY HOUR(criado_em)
                    ORDER BY HOUR(criado_em) ASC");
                $stmt->execute([$restaurante_id, $data_inicio, $data_fim]);
                $pico_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Build full 0-23 hour array
                $pico_horas = array_fill(0, 24, ['hora' => 0, 'qtd_pedidos' => 0, 'receita' => 0]);
                foreach ($pico_data as $row) {
                    $h = (int)$row['hora'];
                    $pico_horas[$h] = ['hora' => $h, 'qtd_pedidos' => (int)$row['qtd_pedidos'], 'receita' => (float)$row['receita']];
                }
                $pico_max = max(array_column($pico_horas, 'qtd_pedidos')) ?: 1;
                $pico_best = array_search(max(array_column($pico_horas, 'qtd_pedidos')), array_column($pico_horas, 'qtd_pedidos'));
                $total_vendas = array_sum(array_column($pico_horas, 'qtd_pedidos'));
                $valor_total  = array_sum(array_column($pico_horas, 'receita'));
                $ticket_medio = $total_vendas > 0 ? $valor_total / $total_vendas : 0;
            endif;

            if ($tipo === 'tempo_preparo'):
                // Average wait time (order placed → started prep) and prep time (started → done)
                $stmt = $db->prepare("
                    SELECT
                        AVG(TIMESTAMPDIFF(SECOND, criado_em, iniciado_preparo_em))       AS avg_espera_seg,
                        AVG(TIMESTAMPDIFF(SECOND, iniciado_preparo_em, atualizado_em))   AS avg_preparo_seg,
                        MIN(TIMESTAMPDIFF(SECOND, iniciado_preparo_em, atualizado_em))   AS min_preparo_seg,
                        MAX(TIMESTAMPDIFF(SECOND, iniciado_preparo_em, atualizado_em))   AS max_preparo_seg,
                        COUNT(*)                                                         AS total_pedidos
                    FROM pedidos
                    WHERE restaurante_id = ?
                      AND (criado_em >= ? AND criado_em < ? + INTERVAL 1 DAY)
                      AND status IN ('PRONTO','ENTREGUE','PAGO')
                      AND iniciado_preparo_em IS NOT NULL
                      AND atualizado_em > iniciado_preparo_em");
                $stmt->execute([$restaurante_id, $data_inicio, $data_fim]);
                $prep_resumo = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

                // Breakdown by hour of day
                $stmt = $db->prepare("
                    SELECT HOUR(criado_em) AS hora,
                           COUNT(*) AS qtd,
                           AVG(TIMESTAMPDIFF(SECOND, iniciado_preparo_em, atualizado_em)) AS avg_seg
                    FROM pedidos
                    WHERE restaurante_id = ?
                      AND (criado_em >= ? AND criado_em < ? + INTERVAL 1 DAY)
                      AND status IN ('PRONTO','ENTREGUE','PAGO')
                      AND iniciado_preparo_em IS NOT NULL
                      AND atualizado_em > iniciado_preparo_em
                    GROUP BY HOUR(criado_em)
                    ORDER BY HOUR(criado_em) ASC");
                $stmt->execute([$restaurante_id, $data_inicio, $data_fim]);
                $prep_por_hora = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $total_vendas  = (int)($prep_resumo['total_pedidos'] ?? 0);
                $valor_total   = 0;
                $ticket_medio  = round(((float)($prep_resumo['avg_preparo_seg'] ?? 0) / 60), 1);
            endif;

            if ($tipo === 'financeiro') {
                $por_pagamento = [];
                foreach ($vendas ?? [] as $v) {
                    $forma = strtoupper((string)($v['forma_pagamento'] ?? 'DINHEIRO'));
                    if (!isset($por_pagamento[$forma])) {
                        $por_pagamento[$forma] = ['qtd' => 0, 'total' => 0];
                    }
                    $por_pagamento[$forma]['qtd']++;
                    $por_pagamento[$forma]['total'] += floatval($v['valor_total'] ?? $v['total'] ?? $v['total_final'] ?? 0);
                }
                arsort($por_pagamento);
                $grand_total = array_sum(array_column($por_pagamento, 'total'));
            }
        } catch (Exception $e) {
            $erro_dados = $e->getMessage();
        }

        return array_intersect_key(get_defined_vars(), array_flip(self::SAIDAS_CARREGAR_DADOS));
    }

    /**
     * Inteligencia do plano Empresarial: notas, KPIs extra, alertas, projecao e graficos.
     * Recebe as variaveis ENTRADAS_INTELIGENCIA_EMPRESARIAL e devolve as SAIDAS_INTELIGENCIA_EMPRESARIAL que ficaram definidas.
     */
    public function inteligenciaEmpresarial(array $entrada): array
    {
        extract($entrada);
        $db = $this->db;

        $fmtMznRel = static function ($v): string {
            return 'MZN ' . number_format((float)$v, 0, ',', '.');
        };
        $notas = [];
        $kpisExtra = [];
        $alertasIa = [];
        $projecaoPeriodo = null;
        $iaProdutos = null;
        $iaPicos = null;
        $iaTempos = null;
        $nomesDia = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];

        if (in_array($tipo, ['vendas', 'financeiro'], true) && !empty($vendas)) {
            $dias = [];
            $pagamentos = [];
            $totalPago = 0.0;
            $qtdPago = 0;
            $porDiaValor = [];
            foreach ($vendas as $vRow) {
                $statusV = strtoupper(trim((string)($vRow['status'] ?? '')));
                if ($statusV !== '' && $statusV !== 'PAGO') {
                    continue;
                }
                $valorV = (float)($vRow['total_final'] ?? $vRow['valor_total'] ?? $vRow['total'] ?? 0);
                $dataV = date('Y-m-d', strtotime((string)($vRow['criado_em'] ?? 'now')));
                $dow = (int)date('w', strtotime($dataV));
                if (!isset($dias[$dow])) {
                    $dias[$dow] = ['soma' => 0.0, 'qtd' => 0];
                }
                $dias[$dow]['soma'] += $valorV;
                $dias[$dow]['qtd']++;
                if (!isset($porDiaValor[$dataV])) {
                    $porDiaValor[$dataV] = 0.0;
                }
                $porDiaValor[$dataV] += $valorV;
                $forma = strtoupper(trim((string)($vRow['forma_pagamento'] ?? 'N/A')));
                if ($forma === '') {
                    $forma = 'N/A';
                }
                if (!isset($pagamentos[$forma])) {
                    $pagamentos[$forma] = ['soma' => 0.0, 'qtd' => 0];
                }
                $pagamentos[$forma]['soma'] += $valorV;
                $pagamentos[$forma]['qtd']++;
                $totalPago += $valorV;
                $qtdPago++;
            }

            $melhorDia = '—';
            $melhorMedia = 0.0;
            foreach ($dias as $dow => $agg) {
                if ($agg['soma'] > $melhorMedia) {
                    $melhorMedia = $agg['soma'];
                    $melhorDia = $nomesDia[$dow] ?? '—';
                }
            }

            $pagTop = '—';
            $pagTopSoma = -1.0;
            $pagTopPct = 0.0;
            foreach ($pagamentos as $forma => $agg) {
                if ((float)$agg['soma'] > $pagTopSoma) {
                    $pagTopSoma = (float)$agg['soma'];
                    $pagTop = $forma;
                }
            }
            if ($pagTop !== '—' && $totalPago > 0) {
                $pagTopPct = ($pagTopSoma / $totalPago) * 100;
            }

            $ticketPago = $qtdPago > 0 ? ($totalPago / $qtdPago) : 0.0;
            $nDiasActivos = count($porDiaValor);
            $spanDias = max(1, (int)round((strtotime($data_fim) - strtotime($data_inicio)) / 86400) + 1);
            $mediaDiaActivo = $nDiasActivos > 0 ? ($totalPago / $nDiasActivos) : 0.0;

            // Projecção: se o filtro cobre o mês corrente, estimar restante.
            $hojeYmd = date('Y-m-d');
            $mesIni = date('Y-m-01');
            $mesFim = date('Y-m-t');
            $diasRestantesMes = max(0, (int)round((strtotime($mesFim) - strtotime($hojeYmd)) / 86400));
            $cobreMesCorrente = (strtotime($data_inicio) <= strtotime($hojeYmd) && strtotime($data_fim) >= strtotime($hojeYmd));
            // Média realista: o total a dividir por TODOS os dias decorridos do período (com e sem venda).
            $fimDecorrido = min(strtotime($data_fim), strtotime($hojeYmd));
            $diasDecorridos = max(1, (int)round(($fimDecorrido - strtotime($data_inicio)) / 86400) + 1);
            $mediaDiaCalendario = $totalPago / $diasDecorridos;
            if ($cobreMesCorrente && $mediaDiaCalendario > 0 && $diasRestantesMes > 0) {
                $mediaDiaActivo = $mediaDiaCalendario;
                $projecaoRestante = $mediaDiaActivo * $diasRestantesMes;
                $projecaoPeriodo = [
                    'media_dia' => round($mediaDiaActivo, 2),
                    'dias_restantes' => $diasRestantesMes,
                    'projecao_restante' => round($projecaoRestante, 2),
                    'projecao_mes' => round($totalPago + $projecaoRestante, 2),
                    'label' => 'Ritmo do filtro',
                ];
                $kpisExtra[] = ['label' => 'Média / dia', 'valor' => $fmtMznRel($mediaDiaActivo)];
                $kpisExtra[] = ['label' => 'Projecção mês', 'valor' => $fmtMznRel($totalPago + $projecaoRestante)];
                $alertasIa[] = [
                    'tom' => 'ok',
                    'area' => 'salão',
                    'titulo' => 'Ritmo do período',
                    'txt' => 'Com uma média de ' . $fmtMznRel($mediaDiaActivo) . ' por dia (contando todos os ' . $diasDecorridos . ' dias do período), o fim do mês deve chegar a ~' . $fmtMznRel($totalPago + $projecaoRestante) . '. Use isto para decidir compras e turnos.',
                ];
            }

            // Anomalia: dia > 2× média
            $picoDia = null;
            $picoVal = 0.0;
            foreach ($porDiaValor as $d => $v) {
                if ($v > $picoVal) {
                    $picoVal = $v;
                    $picoDia = $d;
                }
            }
            if ($picoDia && $mediaDiaActivo > 0 && $picoVal >= ($mediaDiaActivo * 2)) {
                $alertasIa[] = [
                    'tom' => 'warn',
                    'area' => 'turno',
                    'titulo' => 'Anomalia de receita',
                    'txt' => date('d/m', strtotime($picoDia)) . ' fez ' . $fmtMznRel($picoVal) . ' (≥2× a média). Reforce stock e equipa nesse dia da semana no próximo ciclo.',
                ];
                $notas[] = [
                    'tom' => 'warn',
                    'titulo' => 'Dia anómalo: ' . date('d/m', strtotime($picoDia)),
                    'txt' => 'Receita ' . $fmtMznRel($picoVal) . ' vs média ' . $fmtMznRel($mediaDiaActivo) . '. Acção: escalar turno e pré-preparar insumos desse dia da semana.',
                    'acao' => 'turno',
                ];
            }

            $kpisExtra = array_merge([
                ['label' => 'Dia mais forte', 'valor' => $melhorDia],
                ['label' => 'Como mais pagam', 'valor' => function_exists('caixa_forma_label') && $pagTop !== '—' ? caixa_forma_label($pagTop) : $pagTop],
                ['label' => 'Dias com venda', 'valor' => $nDiasActivos . ' de ' . $spanDias],
                ['label' => 'Ticket (pagas)', 'valor' => $fmtMznRel($ticketPago)],
            ], $kpisExtra);

            if ($qtdPago <= 0) {
                $notas[] = [
                    'tom' => 'warn',
                    'titulo' => 'Sem vendas pagas neste período',
                    'txt' => 'Há registos, mas nenhum marcado como pago. Confirme o fecho das contas no caixa antes de decidir stock ou turnos.',
                    'acao' => 'caixa',
                ];
            } else {
                if ($melhorDia !== '—') {
                    $notas[] = [
                        'tom' => 'info',
                        'titulo' => $melhorDia . ' puxa mais receita',
                        'txt' => 'Nesse dia da semana entra cerca de ' . $fmtMznRel($melhorMedia) . '. Acção: prepare stock e turnos com 1 dia de antecedência.',
                        'acao' => 'stock',
                    ];
                }
                if ($nDiasActivos < max(3, (int)floor($spanDias * 0.55))) {
                    $notas[] = [
                        'tom' => 'warn',
                        'titulo' => 'Há buracos no calendário',
                        'txt' => 'Só houve venda em ' . $nDiasActivos . ' de ' . $spanDias . ' dias. Acção: auditar dias sem registo no PDV.',
                        'acao' => 'caixa',
                    ];
                }
                if ($pagTop !== '—' && $pagTopPct >= 55) {
                    $payTxt = stripos($pagTop, 'DINHEIRO') !== false
                        ? 'Muitos pagam em dinheiro (' . number_format($pagTopPct, 0, ',', '.') . '%). Acção: garantir troco e oferecer M-Pesa no fecho.'
                        : number_format($pagTopPct, 0, ',', '.') . '% entra por ' . $pagTop . '. Acção: testar esse meio nas horas de pico.';
                    $notas[] = [
                        'tom' => 'info',
                        'titulo' => 'Pagamento dominante: ' . $pagTop,
                        'txt' => $payTxt,
                        'acao' => 'salão',
                    ];
                }
                if ($ticketPago > 0) {
                    $notas[] = [
                        'tom' => 'ok',
                        'titulo' => 'Subir ticket no salão',
                        'txt' => 'Ticket médio ' . $fmtMznRel($ticketPago) . '. Acção: sugerir bebida/extra nos pratos classe A do cardápio.',
                        'acao' => 'cardapio',
                    ];
                }
            }
        } elseif ($tipo === 'produtos' && !empty($produtos)) {
            $abcRows = $produtos;
            if ($tem_analise_abc_vendas) {
                $analyticsPath = self::PASTA_PAGINA . '/include/produtos_ia_analytics.php';
                if (is_file($analyticsPath)) {
                    require_once $analyticsPath;
                }
                if (function_exists('produtos_ia_abc_por_receita')) {
                    $abcRows = produtos_ia_abc_por_receita($produtos);
                    $produtos = $abcRows;
                }
            }

            $classeA = array_values(array_filter($abcRows, static fn($p) => ($p['categoria_abc'] ?? '') === 'A'));
            $classeC = array_values(array_filter($abcRows, static fn($p) => ($p['categoria_abc'] ?? '') === 'C'));
            $top = $abcRows[0] ?? $produtos[0];
            $topNome = (string)($top['nome'] ?? 'Produto');
            $topQtd = (float)($top['total_vendido'] ?? 0);
            $topRec = (float)($top['receita'] ?? 0);

            // SKUs em queda: 14d vs 14d anteriores
            $skusQueda = [];
            $skusSubida = [];
            if ($tem_recomendacoes_automaticas && $db instanceof PDO) {
                try {
                    // Envolvido numa subconsulta: MySQL/MariaDB nao aceitam aliases de SUM()
                    // dentro de expressoes no ORDER BY (erro 1247 "reference to group function").
                    $stmtTrend = $db->prepare("
                      SELECT * FROM (
                        SELECT p.id, p.nome,
                               COALESCE(SUM(CASE WHEN ped.criado_em >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                                                 THEN pv.quantidade ELSE 0 END), 0) AS qtd_rec,
                               COALESCE(SUM(CASE WHEN ped.criado_em >= DATE_SUB(CURDATE(), INTERVAL 28 DAY)
                                                  AND ped.criado_em < DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                                                 THEN pv.quantidade ELSE 0 END), 0) AS qtd_ant
                        FROM produtos p
                        LEFT JOIN itens_pedido pv ON pv.produto_id = p.id
                        LEFT JOIN pedidos ped ON ped.id = pv.pedido_id
                             AND ped.restaurante_id = p.restaurante_id
                             AND UPPER(TRIM(COALESCE(ped.status,''))) NOT IN ('CANCELADO')
                             AND ped.criado_em >= DATE_SUB(CURDATE(), INTERVAL 28 DAY)
                        WHERE p.restaurante_id = ?
                          AND COALESCE(p.ativo, 1) = 1
                        GROUP BY p.id, p.nome
                      ) tendencia
                      WHERE (qtd_rec + qtd_ant) > 0
                      ORDER BY (qtd_rec - qtd_ant) ASC
                      LIMIT 30
                    ");
                    $stmtTrend->execute([$restaurante_id]);
                    foreach ($stmtTrend->fetchAll(PDO::FETCH_ASSOC) as $tr) {
                        $rec = (float)$tr['qtd_rec'];
                        $ant = (float)$tr['qtd_ant'];
                        $delta = $ant > 0 ? (($rec - $ant) / $ant) * 100 : ($rec > 0 ? 100.0 : 0.0);
                        $itemT = [
                            'id' => (int)$tr['id'],
                            'nome' => (string)$tr['nome'],
                            'qtd_rec' => $rec,
                            'qtd_ant' => $ant,
                            'delta_pct' => round($delta, 1),
                        ];
                        if ($ant >= 2 && $delta <= -25) {
                            $skusQueda[] = $itemT;
                        } elseif ($rec >= 2 && $delta >= 25) {
                            $skusSubida[] = $itemT;
                        }
                    }
                    usort($skusSubida, static fn($a, $b) => ((float)$b['delta_pct']) <=> ((float)$a['delta_pct']));
                    $skusQueda = array_slice($skusQueda, 0, 5);
                    $skusSubida = array_slice($skusSubida, 0, 5);
                } catch (Throwable $eTrend) {
                    error_log('[RELATORIOS][IA_PROD_TREND] ' . $eTrend->getMessage());
                }
            }

            $empurrar = [];
            foreach ($classeA as $aItem) {
                $nomeA = (string)($aItem['nome'] ?? '');
                $subindo = false;
                foreach ($skusSubida as $s) {
                    if (strcasecmp($s['nome'], $nomeA) === 0) {
                        $subindo = true;
                        break;
                    }
                }
                $empurrar[] = [
                    'nome' => $nomeA,
                    'abc' => 'A',
                    'receita' => (float)($aItem['receita'] ?? 0),
                    'acao' => $subindo ? 'Empurrar no salão (em alta)' : 'Garantir stock / destacar no cardápio',
                ];
                if (count($empurrar) >= 4) {
                    break;
                }
            }

            $iaProdutos = [
                'abc' => array_slice($abcRows, 0, 15),
                'classe_a' => count($classeA),
                'classe_b' => count(array_filter($abcRows, static fn($p) => ($p['categoria_abc'] ?? '') === 'B')),
                'classe_c' => count($classeC),
                'empurrar' => $empurrar,
                'queda' => $skusQueda,
                'subida' => $skusSubida,
            ];

            $kpisExtra = [
                ['label' => 'Mais receita', 'valor' => $topNome],
                ['label' => 'Quantidade', 'valor' => number_format($topQtd, 0, ',', '.')],
                ['label' => 'Receita top', 'valor' => $fmtMznRel($topRec)],
                ['label' => 'ABC A / C', 'valor' => count($classeA) . ' / ' . count($classeC)],
            ];
            $notas[] = [
                'tom' => 'ok',
                'titulo' => $topNome . ' lidera a receita (classe A)',
                'txt' => 'Gerou ' . $fmtMznRel($topRec) . '. Acção: confirmar ficha técnica e stock — não pode faltar no salão.',
                'acao' => 'stock',
            ];
            if (!empty($skusQueda)) {
                $q0 = $skusQueda[0];
                $notas[] = [
                    'tom' => 'warn',
                    'titulo' => 'Em queda: ' . $q0['nome'],
                    'txt' => number_format((float)$q0['delta_pct'], 0, ',', '.') . '% vs 14 dias anteriores. Acção: promo curta ou rever preço/visibilidade.',
                    'acao' => 'cardapio',
                ];
                $alertasIa[] = [
                    'tom' => 'warn',
                    'area' => 'cardapio',
                    'titulo' => 'SKU em queda',
                    'txt' => $q0['nome'] . ' caiu ' . number_format((float)$q0['delta_pct'], 0, ',', '.') . '%. Considere promoção ou retirada temporária.',
                ];
            }
            if (!empty($classeC)) {
                $c0 = $classeC[0];
                $notas[] = [
                    'tom' => 'info',
                    'titulo' => 'Classe C: ' . (string)($c0['nome'] ?? 'item'),
                    'txt' => 'Pouca contribuição na receita do período. Acção: reduzir stock parado ou empurrar com combo.',
                    'acao' => 'stock',
                ];
            }
        } elseif ($tipo === 'horarios_pico' && !empty($pico_horas)) {
            $bestH = (int)($pico_best ?? 0);
            $bestQ = (int)($pico_horas[$bestH]['qtd_pedidos'] ?? 0);
            $bestR = (float)($pico_horas[$bestH]['receita'] ?? 0);
            $mediaHora = 0.0;
            $horasComMov = 0;
            foreach ($pico_horas as $ph) {
                $q = (int)($ph['qtd_pedidos'] ?? 0);
                if ($q > 0) {
                    $mediaHora += $q;
                    $horasComMov++;
                }
            }
            $mediaHora = $horasComMov > 0 ? ($mediaHora / $horasComMov) : 0.0;
            $horasReforco = [];
            foreach ($pico_horas as $ph) {
                $q = (int)($ph['qtd_pedidos'] ?? 0);
                if ($mediaHora > 0 && $q >= ($mediaHora * 1.3)) {
                    $horasReforco[] = [
                        'hora' => (int)$ph['hora'],
                        'qtd' => $q,
                        'receita' => (float)($ph['receita'] ?? 0),
                    ];
                }
            }

            $proxDow = (int)date('w', strtotime('+1 day'));
            $proxNome = $nomesDia[$proxDow] ?? 'amanhã';
            $picoEsperadoHora = $bestH;
            $picoEsperadoQtd = $bestQ;
            if ($tem_previsao_demanda && $db instanceof PDO) {
                try {
                    $stmtMat = $db->prepare("
                        SELECT HOUR(criado_em) AS hora, DAYOFWEEK(criado_em) AS dow, COUNT(*) AS qtd
                        FROM pedidos
                        WHERE restaurante_id = ?
                          AND (criado_em >= ? AND criado_em < ? + INTERVAL 1 DAY)
                          AND status NOT IN ('CANCELADO')
                        GROUP BY HOUR(criado_em), DAYOFWEEK(criado_em)
                    ");
                    $stmtMat->execute([$restaurante_id, $data_inicio, $data_fim]);
                    // MySQL DAYOFWEEK: 1=Dom … PHP w: 0=Dom → mysql = php+1
                    $alvoDowMysql = $proxDow + 1;
                    $bestProx = null;
                    $bestProxQ = -1.0;
                    $spanDiasPico = max(1, (int)round((strtotime($data_fim) - strtotime($data_inicio)) / 86400) + 1);
                    $cntSemanas = max(1, (int)ceil($spanDiasPico / 7));
                    foreach ($stmtMat->fetchAll(PDO::FETCH_ASSOC) as $mr) {
                        if ((int)$mr['dow'] !== $alvoDowMysql) {
                            continue;
                        }
                        $media = (float)$mr['qtd'] / $cntSemanas;
                        if ($media > $bestProxQ) {
                            $bestProxQ = $media;
                            $bestProx = (int)$mr['hora'];
                        }
                    }
                    if ($bestProx !== null) {
                        $picoEsperadoHora = $bestProx;
                        $picoEsperadoQtd = (int)round($bestProxQ);
                    }
                } catch (Throwable $eMat) {
                    error_log('[RELATORIOS][IA_PICOS] ' . $eMat->getMessage());
                }
            }

            $iaPicos = [
                'hora_pico' => $bestH,
                'horas_reforco' => $horasReforco,
                'proximo_dia' => $proxNome,
                'pico_esperado_hora' => $picoEsperadoHora,
                'pico_esperado_qtd' => $picoEsperadoQtd,
                'media_hora' => round($mediaHora, 1),
            ];

            $kpisExtra = [
                ['label' => 'Hora de pico', 'valor' => sprintf('%02d:00', $bestH)],
                ['label' => 'Pedidos nessa hora', 'valor' => (string)$bestQ],
                ['label' => 'Receita nessa hora', 'valor' => $fmtMznRel($bestR)],
                ['label' => 'Próx. ' . $proxNome, 'valor' => sprintf('%02d:00 · ~%d', $picoEsperadoHora, $picoEsperadoQtd)],
            ];
            $notas[] = [
                'tom' => 'warn',
                'titulo' => 'Previsão: ' . $proxNome . ' ~' . sprintf('%02d:00', $picoEsperadoHora),
                'txt' => 'Espera-se cerca de ' . $picoEsperadoQtd . ' pedido(s) nessa hora. Acção: reforçar salão/cozinha 30 min antes.',
                'acao' => 'turno',
            ];
            if (!empty($horasReforco)) {
                $labs = array_map(static fn($h) => sprintf('%02d:00', $h['hora']), array_slice($horasReforco, 0, 3));
                $alertasIa[] = [
                    'tom' => 'warn',
                    'area' => 'turno',
                    'titulo' => 'Horas a reforçar',
                    'txt' => implode(', ', $labs) . ' estão ≥1,3× a média. Escalone turnos nestes blocos.',
                ];
                $notas[] = [
                    'tom' => 'info',
                    'titulo' => 'Escala sugerida',
                    'txt' => 'Reforce em ' . implode(', ', $labs) . '. Horas fracas: limpeza, formação ou promo leve.',
                    'acao' => 'turno',
                ];
            }
        } elseif ($tipo === 'tempo_preparo' && !empty($prep_resumo)) {
            $avgPrep = (float)($prep_resumo['avg_preparo_seg'] ?? 0);
            $avgEsp = (float)($prep_resumo['avg_espera_seg'] ?? 0);
            $gargalos = [];
            $prepVals = [];
            foreach ($prep_por_hora ?? [] as $ph) {
                $prepVals[] = (float)($ph['avg_seg'] ?? 0);
            }
            sort($prepVals);
            $p95 = 0.0;
            if (!empty($prepVals)) {
                $p95 = (float)$prepVals[(int)min(count($prepVals) - 1, floor(count($prepVals) * 0.95))];
            }
            $limiar = max($p95, $avgPrep * 1.25, 900);
            $volMax = 0;
            foreach ($prep_por_hora ?? [] as $ph) {
                $volMax = max($volMax, (int)($ph['qtd'] ?? 0));
            }
            foreach ($prep_por_hora ?? [] as $ph) {
                $h = (int)($ph['hora'] ?? 0);
                $q = (int)($ph['qtd'] ?? 0);
                $avg = (float)($ph['avg_seg'] ?? 0);
                $altoVol = $volMax > 0 && $q >= max(2, (int)ceil($volMax * 0.6));
                if ($altoVol && $avg >= $limiar) {
                    $gargalos[] = [
                        'hora' => $h,
                        'qtd' => $q,
                        'avg_seg' => $avg,
                        'avg_min' => round($avg / 60, 1),
                    ];
                }
            }
            $iaTempos = [
                'avg_preparo_seg' => $avgPrep,
                'avg_espera_seg' => $avgEsp,
                'limiar_seg' => $limiar,
                'gargalos' => $gargalos,
            ];
            $kpisExtra = [
                ['label' => 'Espera média', 'valor' => $relatorios_formatar_duracao($avgEsp)],
                ['label' => 'Preparo médio', 'valor' => $relatorios_formatar_duracao($avgPrep)],
                ['label' => 'Mais rápido', 'valor' => $relatorios_formatar_duracao($prep_resumo['min_preparo_seg'] ?? 0)],
                ['label' => 'Gargalos', 'valor' => (string)count($gargalos)],
            ];
            if (!empty($gargalos)) {
                $g0 = $gargalos[0];
                $notas[] = [
                    'tom' => 'warn',
                    'titulo' => 'Gargalo às ' . sprintf('%02d:00', $g0['hora']),
                    'txt' => $g0['avg_min'] . ' min médios com ' . $g0['qtd'] . ' pedidos. Acção: mais mãos na cozinha ou simplificar pratos lentos nesse bloco.',
                    'acao' => 'turno',
                ];
                $alertasIa[] = [
                    'tom' => 'warn',
                    'area' => 'cozinha',
                    'titulo' => 'Carga × preparo',
                    'txt' => 'Às ' . sprintf('%02d:00', $g0['hora']) . ' o preparo sobe com o volume. Antecipe mise en place.',
                ];
            } elseif ($avgPrep > 1200) {
                $notas[] = [
                    'tom' => 'warn',
                    'titulo' => 'Preparo a demorar demais',
                    'txt' => 'Média ' . $relatorios_formatar_duracao($avgPrep) . '. Acção: rever fila KDS e pratos travão.',
                    'acao' => 'cozinha',
                ];
            } else {
                $notas[] = [
                    'tom' => 'ok',
                    'titulo' => 'Ritmo de cozinha controlado',
                    'txt' => 'Preparo médio de ' . $relatorios_formatar_duracao($avgPrep) . '. Mantenha o fluxo e vigie o pico horário.',
                    'acao' => 'cozinha',
                ];
            }
            if ($avgEsp > 600) {
                $notas[] = [
                    'tom' => 'warn',
                    'titulo' => 'Cliente espera antes de começar',
                    'txt' => 'Espera ' . $relatorios_formatar_duracao($avgEsp) . '. Acção: aceitar pedidos mais rápido no tablet/cozinha.',
                    'acao' => 'cozinha',
                ];
            }
        } elseif ($tipo === 'pedidos_qr') {
            $kpisExtra = [
                ['label' => 'Pedidos QR', 'valor' => (string)(int)$resumo_qr['total_pedidos']],
                ['label' => 'Receita QR', 'valor' => $fmtMznRel($resumo_qr['total_receita'])],
                ['label' => 'Mesas activas', 'valor' => (string)(int)$resumo_qr['mesas_ativas']],
                ['label' => 'Hora pico', 'valor' => (string)$resumo_qr['hora_pico']],
            ];
            if ((int)$resumo_qr['total_pedidos'] <= 0) {
                $notas[] = [
                    'tom' => 'warn',
                    'titulo' => 'Ainda sem pedidos por QR',
                    'txt' => 'Acção: validar QR das mesas e abertura do cardápio no telemóvel.',
                    'acao' => 'salão',
                ];
            } else {
                $notas[] = [
                    'tom' => 'ok',
                    'titulo' => 'QR a gerar movimento',
                    'txt' => (int)$resumo_qr['total_pedidos'] . ' pedido(s) · pico ' . (string)$resumo_qr['hora_pico'] . '. Acção: equipa pronta nessa hora.',
                    'acao' => 'turno',
                ];
                if (!empty($pedidos_qr_mesa)) {
                    $mesaTop = $pedidos_qr_mesa[0];
                    $notas[] = [
                        'tom' => 'info',
                        'titulo' => 'Mesa ' . (string)($mesaTop['mesa_numero'] ?? '?') . ' pede mais',
                        'txt' => 'Acção: QR legível e Wi‑Fi estável nessa zona.',
                        'acao' => 'salão',
                    ];
                }
            }
        } elseif ($tipo === 'caixa' && !empty($movimentos)) {
            $kpisExtra = [
                ['label' => 'Movimentos', 'valor' => (string)count($movimentos)],
                ['label' => 'Saldo período', 'valor' => $fmtMznRel($valor_total ?? 0)],
                ['label' => 'Ticket médio', 'valor' => $fmtMznRel($ticket_medio ?? 0)],
                ['label' => 'Período', 'valor' => date('d/m', strtotime($data_inicio)) . '–' . date('d/m', strtotime($data_fim))],
            ];
            $notas[] = [
                'tom' => 'info',
                'titulo' => 'Leitura do caixa',
                'txt' => count($movimentos) . ' movimento(s), saldo ~' . $fmtMznRel($valor_total ?? 0) . '. Acção: cruzar abertura/fecho nos dias fracos.',
                'acao' => 'caixa',
            ];
        }

        usort($notas, static function ($a, $b) {
            $ordem = ['warn' => 0, 'ok' => 1, 'info' => 2];
            return ($ordem[$a['tom'] ?? 'info'] ?? 9) <=> ($ordem[$b['tom'] ?? 'info'] ?? 9);
        });
        $notas = array_slice($notas, 0, 5);
        $alertasIa = array_slice($alertasIa, 0, 5);

        if (!empty($notas) || !empty($kpisExtra) || !empty($alertasIa) || $iaProdutos || $iaPicos || $iaTempos) {
            $relEmpIntel = [
                'notas' => $notas,
                'kpis' => $kpisExtra,
                'tipo' => $tipo,
                'chart' => null,
                'vs_anterior' => null,
                'alertas_ia' => $alertasIa,
                'projecao' => $projecaoPeriodo,
                'ia_produtos' => $iaProdutos,
                'ia_picos' => $iaPicos,
                'ia_tempos' => $iaTempos,
            ];
        }

        // Mini-gráfico + comparação com período anterior (vendas/financeiro)
        if (in_array($tipo, ['vendas', 'financeiro'], true) && $db instanceof PDO) {
            try {
                $spanDiasChart = max(1, (int)round((strtotime($data_fim) - strtotime($data_inicio)) / 86400) + 1);
                $prevFim = date('Y-m-d', strtotime($data_inicio . ' -1 day'));
                $prevIni = date('Y-m-d', strtotime($prevFim . ' -' . ($spanDiasChart - 1) . ' day'));

                $stmtChart = $db->prepare("
                    SELECT DATE(criado_em) AS d, COALESCE(SUM(COALESCE(total_final, 0)), 0) AS total
                    FROM vendas
                    WHERE restaurante_id = ?
                      AND UPPER(TRIM(COALESCE(status,''))) = 'PAGO'
                      AND (criado_em >= ? AND criado_em < ? + INTERVAL 1 DAY)
                    GROUP BY DATE(criado_em)
                    ORDER BY d ASC
                ");
                $stmtChart->execute([$restaurante_id, $data_inicio, $data_fim]);
                $mapCur = [];
                foreach ($stmtChart->fetchAll(PDO::FETCH_ASSOC) as $rc) {
                    $mapCur[(string)$rc['d']] = (float)$rc['total'];
                }
                $stmtChart->execute([$restaurante_id, $prevIni, $prevFim]);
                $mapPrev = [];
                $totalPrev = 0.0;
                foreach ($stmtChart->fetchAll(PDO::FETCH_ASSOC) as $rp) {
                    $mapPrev[(string)$rp['d']] = (float)$rp['total'];
                    $totalPrev += (float)$rp['total'];
                }
                $serie = [];
                for ($i = 0; $i < $spanDiasChart; $i++) {
                    $dia = date('Y-m-d', strtotime($data_inicio . " +{$i} day"));
                    $serie[] = [
                        'data' => $dia,
                        'label' => date('d/m', strtotime($dia)),
                        'total' => (float)($mapCur[$dia] ?? 0),
                    ];
                }
                $totalCur = array_sum(array_column($serie, 'total'));
                $deltaPrevPct = null; // null = sem vendas no período anterior para comparar
                if ($totalPrev > 0) {
                    $deltaPrevPct = (($totalCur - $totalPrev) / $totalPrev) * 100;
                }
                if (!is_array($relEmpIntel)) {
                    $relEmpIntel = ['notas' => [], 'kpis' => [], 'tipo' => $tipo, 'alertas_ia' => []];
                }
                $relEmpIntel['chart'] = $serie;
                $relEmpIntel['vs_anterior'] = [
                    'prev_inicio' => $prevIni,
                    'prev_fim' => $prevFim,
                    'total_atual' => $totalCur,
                    'total_anterior' => $totalPrev,
                    'delta_pct' => $deltaPrevPct,
                ];
                if ($totalPrev > 0) {
                    $fmt = static function ($v) {
                        return 'MZN ' . number_format((float)$v, 0, ',', '.');
                    };
                    array_unshift($relEmpIntel['notas'], [
                        'tom' => $deltaPrevPct < -10 ? 'warn' : ($deltaPrevPct > 10 ? 'ok' : 'info'),
                        'titulo' => 'Vs período anterior: ' . ($deltaPrevPct >= 0 ? '+' : '') . number_format($deltaPrevPct, 0, ',', '.') . '%',
                        'txt' => 'Agora ' . $fmt($totalCur) . ' · antes (' . date('d/m', strtotime($prevIni)) . '–' . date('d/m', strtotime($prevFim)) . ') ' . $fmt($totalPrev) . '. Acção: ' . ($deltaPrevPct < -10 ? 'rever cardápio e turnos fracos.' : ($deltaPrevPct > 10 ? 'repetir o que funcionou no stock/salão.' : 'manter o ritmo e vigiar o pico.')),
                        'acao' => $deltaPrevPct < -10 ? 'cardapio' : 'salão',
                    ]);
                    $relEmpIntel['notas'] = array_slice($relEmpIntel['notas'], 0, 5);
                    if (is_array($relEmpIntel['projecao'] ?? null) && empty($relEmpIntel['projecao']['delta_vs_anterior'])) {
                        $relEmpIntel['projecao']['delta_vs_anterior'] = round($deltaPrevPct, 1);
                    }
                }
            } catch (Throwable $eChart) {
                error_log('[RELATORIOS][EMP_CHART] ' . $eChart->getMessage());
            }
        }

        return array_intersect_key(get_defined_vars(), array_flip(self::SAIDAS_INTELIGENCIA_EMPRESARIAL));
    }
}
