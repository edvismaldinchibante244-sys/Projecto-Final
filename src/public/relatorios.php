<?php
require_once __DIR__ . '/app_base.php';
require_once APP_BASE . '/src/config/auth_check.php';

/*
   RELATÓRIOS DO SISTEMA - Estrutura Premium Unificada
*/
include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/restaurante_context.php';
include_once APP_BASE . '/src/config/plano_check.php';
include_once APP_BASE . '/src/config/mesa_display_helper.php';

$database = new Database();
$db = $database->getConnection();
include_once APP_BASE . '/src/Service/RelatorioService.php';
require_once APP_BASE . '/src/config/caixa_movimentos.php';
$relatorioService = new RelatorioService($db);

$restaurante_id = (int)($_SESSION['restaurante_id'] ?? 0);

if ($restaurante_id <= 0) {
    header("Location: index.php?erro=sem_restaurante");
    exit;
}

$restaurante_plan_id = session_restaurante_capability_id();
if ($restaurante_plan_id <= 0) {
    $restaurante_plan_id = $restaurante_id;
}

$tem_relatorio_diario_acesso = $restaurante_plan_id > 0 && plano_tem_funcionalidade_db($restaurante_plan_id, 'relatorio_diario');
$tem_relatorios_avancados_acesso = $restaurante_plan_id > 0 && plano_tem_funcionalidade_db($restaurante_plan_id, 'relatorios_avancados');
if (!$tem_relatorio_diario_acesso && !$tem_relatorios_avancados_acesso) {
    header('Location: dashboard.php?erro=funcionalidade_bloqueada&funcionalidade=relatorios');
    exit;
}

$restaurante_nome_atual = 'Restaurante';

try {
    $stmt_restaurante_nome = $db->prepare("SELECT nome FROM restaurantes WHERE id = ? LIMIT 1");
    $stmt_restaurante_nome->execute([$restaurante_id]);
    $restaurante_nome_atual = trim((string)($stmt_restaurante_nome->fetchColumn() ?: 'Restaurante'));
} catch (Throwable $e) {
    $restaurante_nome_atual = 'Restaurante';
}

$tipos = [
    'vendas'        => 'Vendas',
    'produtos'      => 'Produtos Mais Vendidos',
    'caixa'         => 'Movimentação de Caixa',
    'financeiro'    => 'Resumo Financeiro',
    'pedidos_qr'    => 'Pedidos QR (Mesa/Hora)',
    'horarios_pico' => 'Horários de Pico',
    'tempo_preparo' => 'Tempo de Preparo',
];

$relatorios_validar_data = static function ($valor): ?string {
    $valor = trim((string)$valor);
    if ($valor === '') {
        return null;
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $valor);
    if (!$dt || $dt->format('Y-m-d') !== $valor) {
        return null;
    }

    return $valor;
};

$relatorios_escape = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

$relatorios_mesa_label = static function ($mesaNumero): string {
    $valor = trim((string)$mesaNumero);
    if ($valor === '' || strcasecmp($valor, 'Sem mesa') === 0) {
        return 'Balcão';
    }

    return mesa_rotulo($valor);
};

$relatorios_formatar_duracao = static function ($segundos): string {
    $segundos = max(0, (int)round((float)$segundos));

    if ($segundos < 60) {
        return $segundos . ' s';
    }

    $horas = intdiv($segundos, 3600);
    $minutos = intdiv($segundos % 3600, 60);

    if ($horas > 0) {
        if ($minutos === 0) {
            return $horas . ' h';
        }

        return $horas . 'h ' . $minutos . 'min';
    }

    return $minutos . ' min';
};

$tipo = isset($_GET['tipo']) ? $_GET['tipo'] : 'vendas';
$periodo = isset($_GET['periodo']) ? $_GET['periodo'] : '30';
$data_inicio_custom = $relatorios_validar_data($_GET['data_inicio'] ?? '');
$data_fim_custom = $relatorios_validar_data($_GET['data_fim'] ?? '');
$export = strtolower(trim((string)($_GET['export'] ?? '')));

$tem_relatorio_diario = plano_tem_funcionalidade_db($restaurante_plan_id, 'relatorio_diario');
$tem_relatorios_avancados = plano_tem_funcionalidade_db($restaurante_plan_id, 'relatorios_avancados');
$tem_relatorios_inteligentes = plano_tem_funcionalidade_db($restaurante_plan_id, 'relatorios_inteligentes');
$tem_estatisticas_produtos = plano_tem_funcionalidade_db($restaurante_plan_id, 'estatisticas_produtos');
$tem_relatorio_financeiro = plano_tem_funcionalidade_db($restaurante_plan_id, 'relatorio_financeiro');
$tem_relatorio_personalizado = plano_tem_funcionalidade_db($restaurante_plan_id, 'relatorio_personalizado');
$tem_analise_abc_vendas = plano_tem_funcionalidade_db($restaurante_plan_id, 'analise_abc_vendas');
$tem_recomendacoes_automaticas = plano_tem_funcionalidade_db($restaurante_plan_id, 'recomendacoes_automaticas');
$tem_previsao_demanda = plano_tem_funcionalidade_db($restaurante_plan_id, 'previsao_demanda');
$dados_plano = plano_get_dados($restaurante_plan_id);
$plano_atual = plano_normalizar_nome($dados_plano['plano_nome'] ?? 'BASICO');
// Blocos de vendas (formas de pagamento, cancelados, lucro) tambem no Profissional, em versao simples
$relExtrasPlano = in_array($plano_atual, ['EMPRESARIAL', 'PROFISSIONAL'], true);
$relExtrasDetalhe = ($plano_atual === 'EMPRESARIAL');
require_once __DIR__ . '/includes/empresarial_ui.php';
// Segundo canal de balcão (Empresarial): totais por canal no relatório de vendas.
require_once APP_BASE . '/src/config/mesa_display_helper.php';
require_once APP_BASE . '/src/config/balcao_canal.php';
$relBalcao2 = ['ativo' => false, 'nome' => '', 'numero' => ''];
try {
    $relBalcao2 = balcao2_config($db, (int)$restaurante_id, $restaurante_plan_id);
} catch (Throwable $e) {
    error_log('[relatorios][balcao2] ' . $e->getMessage());
}
// Sem relatório personalizado: forçar janela curta (7 dias)
if (!$tem_relatorio_personalizado && !in_array($periodo, ['7'], true)) {
    $periodo = '7';
}
if (!$tem_relatorio_personalizado && !isset($_GET['periodo'])) {
    $periodo = '7';
}
$tem_exportacao_relatorios = plano_tem_funcionalidade_db($restaurante_plan_id, 'exportacao_relatorios')
    || plano_tem_funcionalidade_db($restaurante_plan_id, 'export_excel')
    || plano_tem_funcionalidade_db($restaurante_plan_id, 'export_csv')
    || plano_tem_funcionalidade_db($restaurante_plan_id, 'export_pdf');
$pode_exportar = $tem_relatorios_avancados && $tem_exportacao_relatorios;
// Permitir impressão também para plano Básico quando houver relatorio_diario
$pode_imprimir = $tem_relatorios_avancados || $tem_relatorio_diario;
$pode_exportar_pdf = plano_tem_funcionalidade_db($restaurante_plan_id, 'export_pdf');
$table_class = $tem_relatorios_avancados ? 'table table-hover mb-0 table-advanced' : 'table table-hover mb-0';
$tipos_visiveis = [
    'vendas' => 'Relatório Diário',
];
if ($tem_relatorios_avancados) {
    $tipos_visiveis = $tipos;
    if (!$tem_estatisticas_produtos) {
        unset($tipos_visiveis['produtos']);
    }
    if (!$tem_relatorio_financeiro) {
        unset($tipos_visiveis['financeiro']);
    }
    if (!$tem_relatorios_inteligentes) {
        unset($tipos_visiveis['horarios_pico'], $tipos_visiveis['tempo_preparo'], $tipos_visiveis['pedidos_qr']);
    }
}

$tipo = array_key_exists($tipo, $tipos_visiveis) ? $tipo : 'vendas';

switch ($periodo) {
    case '7':
        $data_inicio = date('Y-m-d', strtotime('-6 days')); // 7 dias, contando hoje
        $data_fim = date('Y-m-d');
        break;
    case '30':
        $data_inicio = date('Y-m-d', strtotime('-29 days')); // 30 dias, contando hoje
        $data_fim = date('Y-m-d');
        break;
    case '90':
        $data_inicio = date('Y-m-d', strtotime('-89 days')); // 90 dias, contando hoje
        $data_fim = date('Y-m-d');
        break;
    case 'all':
        $data_inicio = '2020-01-01';
        $data_fim = date('Y-m-d');
        break;
    case 'custom':
        $data_inicio = $data_inicio_custom ?: date('Y-m-d', strtotime('-29 days'));
        $data_fim = $data_fim_custom ?: date('Y-m-d');
        break;
    default:
        $data_inicio = date('Y-m-d', strtotime('-29 days'));
        $data_fim = date('Y-m-d');
}

if (strtotime($data_inicio) > strtotime($data_fim)) {
    [$data_inicio, $data_fim] = [$data_fim, $data_inicio];
}

$pedidos_qr_mesa = [];
$pedidos_qr_hora = [];
$resumo_qr = [
    'total_pedidos' => 0,
    'total_receita' => 0,
    'mesas_ativas' => 0,
    'hora_pico' => '--'
];
$por_pagamento = [];
$grand_total = 0;

// Verificação de acesso ao relatório diário/avançado
if ($tipo === 'vendas') {
    if (!$tem_relatorio_diario && !$tem_relatorios_avancados) {
        header("Location: dashboard.php?aviso=plano_sem_relatorio_diario");
        exit;
    }
} elseif (!$tem_relatorios_avancados) {
    $tipo = 'vendas';
}

// Carregar dados
extract($relatorioService->carregarDados(array_intersect_key(get_defined_vars(), array_flip(RelatorioService::ENTRADAS_CARREGAR_DADOS))));

// Inteligência EMP — análises heurísticas (IA do produto: regras + séries, sem LLM).
$relEmpIntel = null;
if (($plano_atual ?? '') === 'EMPRESARIAL' && !isset($erro_dados)) {
    extract($relatorioService->inteligenciaEmpresarial(array_intersect_key(get_defined_vars(), array_flip(RelatorioService::ENTRADAS_INTELIGENCIA_EMPRESARIAL))));
}

if ($export === 'excel' && !isset($erro_dados)) {
    if (!$pode_exportar) {
        $erro_dados = 'Seu plano atual não permite exportação de relatórios.';
    } else {
    if (ob_get_length()) {
        ob_clean();
    }

    $export_titulo = $tipos[$tipo] ?? ucfirst($tipo);
    $export_periodo = date('d/m/Y', strtotime($data_inicio)) . ' - ' . date('d/m/Y', strtotime($data_fim));
    $export_gerado_em = date('d/m/Y H:i:s');
    $export_resumo = [];
    $export_secoes = [];

    if ($tipo === 'pedidos_qr') {
        $export_resumo = [
            ['label' => 'Total de Pedidos QR', 'valor' => (string)((int)($resumo_qr['total_pedidos'] ?? 0))],
            ['label' => 'Receita QR', 'valor' => 'MZN ' . number_format((float)($resumo_qr['total_receita'] ?? 0), 2, ',', '.')],
            ['label' => 'Mesas Ativas', 'valor' => (string)((int)($resumo_qr['mesas_ativas'] ?? 0))],
            ['label' => 'Hora Pico', 'valor' => (string)($resumo_qr['hora_pico'] ?? '--')],
        ];
    } else {
        $export_resumo = [
            ['label' => 'Total de Registos', 'valor' => (string)($total_vendas ?? 0)],
            ['label' => 'Faturamento', 'valor' => 'MZN ' . number_format((float)($valor_total ?? 0), 2, ',', '.')],
            ['label' => 'Ticket Medio', 'valor' => 'MZN ' . number_format((float)($ticket_medio ?? 0), 2, ',', '.')],
            ['label' => 'Periodo', 'valor' => $export_periodo],
        ];
    }

    if ($tipo === 'vendas') {
        $linhas = [];
        foreach ($vendas ?? [] as $v) {
            $linhas[] = [
                '#' . str_pad((string)$v['id'], 4, '0', STR_PAD_LEFT),
                !empty($v['criado_em']) ? date('d/m/Y H:i', strtotime($v['criado_em'])) : '-',
                $v['cliente_nome'] ?? 'Consumidor',
                $relatorios_mesa_label($v['mesa_numero'] ?? ''),
                number_format((float)($v['valor_total'] ?? $v['total'] ?? $v['total_final'] ?? 0), 2, ',', '.'),
                caixa_forma_label((string)($v['forma_pagamento'] ?? 'DINHEIRO')),
                $v['usuario_nome'] ?? '-',
                ucfirst((string)($v['status'] ?? 'Pendente')),
            ];
        }
        $export_secoes[] = [
            'titulo' => 'Detalhe de Vendas',
            'cabecalhos' => ['ID', 'Data/Hora', 'Cliente', 'Mesa', 'Valor (MZN)', 'Forma Pagamento', 'Atendido Por', 'Status'],
            'linhas' => $linhas,
        ];

        $linhas_pagamento = [];
        foreach ($por_pagamento as $forma => $dados) {
            $linhas_pagamento[] = [
                $forma,
                (int)$dados['qtd'],
                number_format((float)$dados['total'], 2, ',', '.'),
                $grand_total > 0 ? number_format(((float)$dados['total'] / $grand_total) * 100, 1, ',', '.') . '%' : '0,0%',
            ];
        }
        $export_secoes[] = [
            'titulo' => 'Resumo por Forma de Pagamento',
            'cabecalhos' => ['Forma', 'Qtd. Vendas', 'Total (MZN)', '% do Faturamento'],
            'linhas' => $linhas_pagamento,
        ];
    } elseif ($tipo === 'produtos') {
        $linhas = [];
        foreach ($produtos ?? [] as $p) {
            $linhas[] = [
                $p['nome'] ?? '',
                $p['categoria'] ?? '-',
                number_format((float)($p['preco'] ?? 0), 2, ',', '.'),
                (int)($p['total_vendido'] ?? 0),
                number_format((float)($p['receita'] ?? 0), 2, ',', '.'),
            ];
        }
        $export_secoes[] = [
            'titulo' => 'Produtos Mais Vendidos',
            'cabecalhos' => ['Produto', 'Categoria', 'Preco (MZN)', 'Quant. Vendida', 'Receita (MZN)'],
            'linhas' => $linhas,
        ];
    } elseif ($tipo === 'caixa') {
        $linhas = [];
        foreach ($movimentos ?? [] as $m) {
            $linhas[] = [
                !empty($m['data_abertura']) ? date('d/m/Y H:i', strtotime($m['data_abertura'])) : '-',
                !empty($m['data_fechamento']) ? date('d/m/Y H:i', strtotime($m['data_fechamento'])) : '-',
                number_format((float)($m['valor_abertura'] ?? 0), 2, ',', '.'),
                number_format((float)($m['valor_fechamento'] ?? 0), 2, ',', '.'),
                ucfirst((string)($m['status'] ?? 'Aberto')),
            ];
        }
        $export_secoes[] = [
            'titulo' => 'Movimentacao de Caixa',
            'cabecalhos' => ['Data Abertura', 'Data Fechamento', 'Valor Abertura (MZN)', 'Valor Fechamento (MZN)', 'Status'],
            'linhas' => $linhas,
        ];
    } elseif ($tipo === 'financeiro') {
        $linhas = [];
        foreach ($por_pagamento as $forma => $dados) {
            $linhas[] = [
                $forma,
                (int)$dados['qtd'],
                number_format((float)$dados['total'], 2, ',', '.'),
                $grand_total > 0 ? number_format(((float)$dados['total'] / $grand_total) * 100, 1, ',', '.') . '%' : '0,0%',
            ];
        }
        $export_secoes[] = [
            'titulo' => 'Resumo Financeiro',
            'cabecalhos' => ['Forma de Pagamento', 'Qtd. Vendas', 'Total (MZN)', '% do Faturamento'],
            'linhas' => $linhas,
        ];
    } elseif ($tipo === 'pedidos_qr') {
        $linhas_mesa = [];
        foreach ($pedidos_qr_mesa as $qm) {
            $linhas_mesa[] = [
                (string)($qm['mesa_numero'] ?? ''),
                (int)($qm['total_pedidos'] ?? 0),
                number_format((float)($qm['ticket_medio'] ?? 0), 2, ',', '.'),
                number_format((float)($qm['valor_total'] ?? 0), 2, ',', '.'),
            ];
        }
        $export_secoes[] = [
            'titulo' => 'Pedidos por Mesa',
            'cabecalhos' => ['Mesa', 'Qtd. Pedidos', 'Ticket Medio (MZN)', 'Receita (MZN)'],
            'linhas' => $linhas_mesa,
        ];

        $linhas_hora = [];
        foreach ($pedidos_qr_hora as $qh) {
            $linhas_hora[] = [
                (string)($qh['hora'] ?? ''),
                (int)($qh['total_pedidos'] ?? 0),
                number_format((float)($qh['valor_total'] ?? 0), 2, ',', '.'),
            ];
        }
        $export_secoes[] = [
            'titulo' => 'Pedidos por Hora',
            'cabecalhos' => ['Hora', 'Qtd. Pedidos', 'Receita (MZN)'],
            'linhas' => $linhas_hora,
        ];
    } elseif ($tipo === 'horarios_pico') {
        $linhas = [];
        foreach ($pico_horas as $hd) {
            if ((int)($hd['qtd_pedidos'] ?? 0) <= 0) {
                continue;
            }
            $linhas[] = [
                sprintf('%02d:00 - %02d:59', (int)$hd['hora'], (int)$hd['hora']),
                (int)$hd['qtd_pedidos'],
                number_format((float)$hd['receita'], 2, ',', '.'),
            ];
        }
        $export_secoes[] = [
            'titulo' => 'Horarios de Pico',
            'cabecalhos' => ['Hora', 'Pedidos', 'Receita (MZN)'],
            'linhas' => $linhas,
        ];
    } elseif ($tipo === 'tempo_preparo') {
        $linhas = [];
        foreach ($prep_por_hora as $ph) {
            $linhas[] = [
                sprintf('%02d:00 - %02d:59', (int)$ph['hora'], (int)$ph['hora']),
                (int)$ph['qtd'],
                $relatorios_formatar_duracao($ph['avg_seg'] ?? 0),
            ];
        }
        $export_secoes[] = [
            'titulo' => 'Tempo de Preparo por Hora',
            'cabecalhos' => ['Hora', 'Pedidos', 'Tempo Medio'],
            'linhas' => $linhas,
        ];
    }

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="relatorio_' . $tipo . '_' . date('Ymd_His') . '.xls"');

    echo "<html><head><meta charset=\"UTF-8\">";
    echo "<style>
        body{font-family:Segoe UI,Arial,sans-serif;color:#0f172a;}
        .header{margin-bottom:24px;}
        .title{font-size:24px;font-weight:700;color:#1e293b;margin-bottom:6px;}
        .subtitle{font-size:14px;color:#475569;margin-bottom:2px;}
        .summary{border-collapse:collapse;margin:16px 0 24px 0;width:100%;}
        .summary td{border:1px solid #dbe2ea;padding:10px 12px;}
        .summary .label{background:#f8fafc;font-weight:700;width:220px;}
        .section-title{font-size:18px;font-weight:700;color:#1e293b;margin:24px 0 10px 0;}
        table.report{border-collapse:collapse;width:100%;margin-bottom:24px;}
        table.report th{background:#1d4ed8;color:#fff;font-weight:700;text-align:left;padding:10px;border:1px solid #cbd5e1;}
        table.report td{padding:10px;border:1px solid #dbe2ea;}
        .muted{color:#64748b;}
    </style>
</head><body>";
    echo '<div class="header">';
    echo '<div class="title">' . $relatorios_escape($restaurante_nome_atual) . '</div>';
    echo '<div class="subtitle">Relatório: ' . $relatorios_escape($export_titulo) . '</div>';
    echo '<div class="subtitle">Período: ' . $relatorios_escape($export_periodo) . '</div>';
    echo '<div class="subtitle">Gerado em: ' . $relatorios_escape($export_gerado_em) . '</div>';
    echo '</div>';

    echo '<table class="summary">';
    foreach ($export_resumo as $item_resumo) {
        echo '<tr>';
        echo '<td class="label">' . $relatorios_escape($item_resumo['label']) . '</td>';
        echo '<td>' . $relatorios_escape($item_resumo['valor']) . '</td>';
        echo '</tr>';
    }
    echo '</table>';

    foreach ($export_secoes as $secao_export) {
        echo '<div class="section-title">' . $relatorios_escape($secao_export['titulo']) . '</div>';
        echo '<table class="report"><thead><tr>';
        foreach ($secao_export['cabecalhos'] as $cabecalho_export) {
            echo '<th>' . $relatorios_escape($cabecalho_export) . '</th>';
        }
        echo '</tr></thead><tbody>';

        if (empty($secao_export['linhas'])) {
            echo '<tr><td colspan="' . count($secao_export['cabecalhos']) . '" class="muted">Nenhum dado encontrado para o período selecionado.</td></tr>';
        } else {
            foreach ($secao_export['linhas'] as $linha_export) {
                echo '<tr>';
                foreach ($linha_export as $celula_export) {
                    echo '<td>' . $relatorios_escape((string)$celula_export) . '</td>';
                }
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
    }

    echo '</body></html>';
    exit;
    }
}

if ($export === 'csv' && !isset($erro_dados)) {
    if (!$pode_exportar) {
        $erro_dados = 'Seu plano atual não permite exportação de relatórios.';
    } else {
    if (ob_get_length()) {
        ob_clean();
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="relatorio_' . $tipo . '_' . date('Ymd_His') . '.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Restaurante', $restaurante_nome_atual], ';');
    fputcsv($out, ['Relatorio', $tipos[$tipo] ?? ucfirst($tipo)], ';');
    fputcsv($out, ['Periodo', date('d/m/Y', strtotime($data_inicio)) . ' - ' . date('d/m/Y', strtotime($data_fim))], ';');
    fputcsv($out, ['Gerado em', date('d/m/Y H:i:s')], ';');
    fputcsv($out, ['Total de Registos', (string)($total_vendas ?? 0)], ';');
    fputcsv($out, ['Faturamento', 'MZN ' . number_format((float)($valor_total ?? 0), 2, ',', '.')], ';');
    fputcsv($out, ['Ticket Medio', 'MZN ' . number_format((float)($ticket_medio ?? 0), 2, ',', '.')], ';');
    fputcsv($out, [], ';');

    if ($tipo === 'vendas') {
        fputcsv($out, ['ID', 'Data/Hora', 'Cliente', 'Mesa', 'Valor (MZN)', 'Forma Pagamento', 'Atendido Por', 'Status'], ';');
        foreach ($vendas ?? [] as $v) {
            fputcsv($out, [
                '#' . str_pad((string)$v['id'], 4, '0', STR_PAD_LEFT),
                !empty($v['criado_em']) ? date('d/m/Y H:i', strtotime($v['criado_em'])) : '-',
                $v['cliente_nome'] ?? 'Consumidor',
                $relatorios_mesa_label($v['mesa_numero'] ?? ''),
                number_format((float)($v['valor_total'] ?? $v['total'] ?? $v['total_final'] ?? 0), 2, ',', '.'),
                caixa_forma_label((string)($v['forma_pagamento'] ?? 'DINHEIRO')),
                $v['usuario_nome'] ?? '-',
                ucfirst((string)($v['status'] ?? 'Pendente')),
            ], ';');
        }
        fputcsv($out, [], ';');
        fputcsv($out, ['Resumo por Forma de Pagamento'], ';');
        fputcsv($out, ['Forma', 'Qtd. Vendas', 'Total (MZN)', '% do Faturamento'], ';');
        foreach ($por_pagamento as $forma => $dados) {
            fputcsv($out, [
                $forma,
                (int)$dados['qtd'],
                number_format((float)$dados['total'], 2, ',', '.'),
                $grand_total > 0 ? number_format(((float)$dados['total'] / $grand_total) * 100, 1, ',', '.') . '%' : '0,0%',
            ], ';');
        }
    } elseif ($tipo === 'produtos') {
        fputcsv($out, ['Produto', 'Categoria', 'Preço (MZN)', 'Quant. Vendida', 'Receita (MZN)'], ';');
        foreach ($produtos ?? [] as $p) {
            fputcsv($out, [
                $p['nome'] ?? '',
                $p['categoria'] ?? '-',
                number_format((float)($p['preco'] ?? 0), 2, ',', '.'),
                (int)($p['total_vendido'] ?? 0),
                number_format((float)($p['receita'] ?? 0), 2, ',', '.'),
            ], ';');
        }
    } elseif ($tipo === 'caixa') {
        fputcsv($out, ['Data Abertura', 'Data Fechamento', 'Valor Abertura (MZN)', 'Valor Fechamento (MZN)', 'Status'], ';');
        foreach ($movimentos ?? [] as $m) {
            fputcsv($out, [
                !empty($m['data_abertura']) ? date('d/m/Y H:i', strtotime($m['data_abertura'])) : '-',
                !empty($m['data_fechamento']) ? date('d/m/Y H:i', strtotime($m['data_fechamento'])) : '-',
                number_format((float)($m['valor_abertura'] ?? 0), 2, ',', '.'),
                number_format((float)($m['valor_fechamento'] ?? 0), 2, ',', '.'),
                ucfirst((string)($m['status'] ?? 'aberto')),
            ], ';');
        }
    } elseif ($tipo === 'financeiro') {
        fputcsv($out, ['Forma de Pagamento', 'Qtd. Vendas', 'Total (MZN)', '% do Faturamento'], ';');
        foreach ($por_pagamento as $forma => $dados) {
            fputcsv($out, [
                $forma,
                (int)$dados['qtd'],
                number_format((float)$dados['total'], 2, ',', '.'),
                $grand_total > 0 ? number_format(((float)$dados['total'] / $grand_total) * 100, 1, ',', '.') . '%' : '0,0%',
            ], ';');
        }
    } elseif ($tipo === 'pedidos_qr') {
        fputcsv($out, ['Pedidos por Mesa'], ';');
        fputcsv($out, ['Mesa', 'Qtd. Pedidos', 'Ticket Médio', 'Receita'], ';');
        foreach ($pedidos_qr_mesa as $qm) {
            fputcsv($out, [
                (string)($qm['mesa_numero'] ?? ''),
                (int)($qm['total_pedidos'] ?? 0),
                number_format((float)($qm['ticket_medio'] ?? 0), 2, ',', '.'),
                number_format((float)($qm['valor_total'] ?? 0), 2, ',', '.'),
            ], ';');
        }

        fputcsv($out, [], ';');
        fputcsv($out, ['Pedidos por Hora'], ';');
        fputcsv($out, ['Hora', 'Qtd. Pedidos', 'Receita'], ';');
        foreach ($pedidos_qr_hora as $qh) {
            fputcsv($out, [
                (string)($qh['hora'] ?? ''),
                (int)($qh['total_pedidos'] ?? 0),
                number_format((float)($qh['valor_total'] ?? 0), 2, ',', '.'),
            ], ';');
        }
    } elseif ($tipo === 'horarios_pico') {
        fputcsv($out, ['Hora', 'Pedidos', 'Receita'], ';');
        foreach ($pico_horas as $hd) {
            if ((int)($hd['qtd_pedidos'] ?? 0) <= 0) {
                continue;
            }
            fputcsv($out, [
                sprintf('%02d:00 - %02d:59', (int)$hd['hora'], (int)$hd['hora']),
                (int)$hd['qtd_pedidos'],
                number_format((float)$hd['receita'], 2, ',', '.'),
            ], ';');
        }
    } elseif ($tipo === 'tempo_preparo') {
        fputcsv($out, ['Hora', 'Pedidos', 'Tempo Medio'], ';');
        foreach ($prep_por_hora as $ph) {
            fputcsv($out, [
                sprintf('%02d:00 - %02d:59', (int)$ph['hora'], (int)$ph['hora']),
                (int)$ph['qtd'],
                $relatorios_formatar_duracao($ph['avg_seg'] ?? 0),
            ], ';');
        }
    }

    fclose($out);
    exit;
    }
}

// Preparar dados do usuário para exibição
$public_base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$resolve_foto_url = function ($path) use ($public_base) {
    if (empty($path)) return '';
    if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0 || strpos($path, '/') === 0) return $path;
    return $public_base . '/' . ltrim($path, '/');
};
$top_foto_url = $resolve_foto_url($_SESSION['foto'] ?? '');
$top_nome_usuario = $_SESSION['nome'] ?? 'Usuário';
$top_nome_partes = preg_split('/\s+/', trim($top_nome_usuario));
$top_iniciais = mb_strtoupper(
    mb_substr((string)($top_nome_partes[0] ?? 'U'), 0, 1, 'UTF-8') . mb_substr((string)($top_nome_partes[1] ?? ''), 0, 1, 'UTF-8'),
    'UTF-8'
);
?>
<!DOCTYPE html>
<html lang="pt">

<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatórios - RestauranteSaaS</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link href="lib/bootstrap-5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="lib/fontawesome-6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/premium.css">
    <link rel="stylesheet" href="css/relatorios.css?v=<?php echo (int)@filemtime(__DIR__ . '/css/relatorios.css'); ?>">
    <?php empresarial_head_assets(); ?>
    <link rel="stylesheet" href="css/bonus_faixa.css?v=<?php echo (int)@filemtime(__DIR__ . '/css/bonus_faixa.css'); ?>">
</head>

<body class="<?php echo htmlspecialchars(empresarial_body_class('premium-ui ' . ($tem_relatorios_avancados ? 'plan-advanced' : 'plan-basic'))); ?>">
<?php
// Faixa da oferta de boas-vindas: tem de aparecer onde o dono trabalha.
include __DIR__ . '/includes/bonus_faixa.php';
?>
    <div class="container-fluid">
        <div class="row">
            <!-- Botão toggle do menu para mobile -->
            <style>
                @media (max-width: 991px) {
                    .sidebar-toggle-btn {
                        display: block !important;
                    }

                    .sidebar {
                        position: fixed !important;
                        top: 0;
                        left: 0;
                        height: 100vh;
                        z-index: 2000;
                        transition: left 0.3s;
                    }

                    .sidebar.sidebar-hidden {
                        left: -100vw !important;
                    }

                    .main-content-blur {
                        filter: blur(2px) grayscale(0.1);
                        pointer-events: none;
                    }
                }
            </style>
            <!-- SIDEBAR -->
            <?php include_once __DIR__ . '/includes/sidebar.php'; ?>

            <!-- MAIN CONTENT -->
            <main class="main-content">
                <div class="top-bar">
                    <div>
                        <h1 class="page-title mb-0"><i class="fas fa-chart-bar"></i> Relatórios <?php include __DIR__ . '/includes/plan_page_badge.php'; ?></h1>
                        <?php if (($plano_atual ?? '') === 'BASICO'): ?>
                            <div class="small text-muted mt-1">Relatório diário da operação.</div>
                        <?php elseif (($plano_atual ?? '') === 'PROFISSIONAL'): ?>
                            <div class="small text-muted mt-1">Relatórios avançados e exportação da unidade.</div>
                        <?php else: ?>
                            <div class="small text-muted mt-1">Leitura da casa para decidir stock, turnos e o que empurrar no salão.</div>
                        <?php endif; ?>
                    </div>
                    <div class="top-bar-right">
                        <span class="top-bar-date"><i class="far fa-clock"></i> <?php echo date('d/m/Y H:i'); ?></span>
                        <div class="top-bar-user-chip">
                            <?php if (!empty($top_foto_url)): ?>
                                <img src="<?php echo htmlspecialchars($top_foto_url); ?>" alt="<?php echo htmlspecialchars($top_nome_usuario); ?>">
                            <?php else: ?>
                                <span class="chip-avatar"><?php echo htmlspecialchars($top_iniciais); ?></span>
                            <?php endif; ?>
                            <span class="chip-info">
                                <span class="chip-name"><?php echo htmlspecialchars($top_nome_usuario); ?></span>
                                <span class="chip-role"><?php echo $relatorios_escape($_SESSION['perfil'] ?? 'USER'); ?></span>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="content-area">
                    <?php if (isset($erro_dados)): ?>
                        <div class="alert alert-danger" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i> Erro ao carregar dados: <?php echo htmlspecialchars($erro_dados); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($plano_atual === 'BASICO'): ?>
                        <div class="alert alert-light border mb-3" style="border-radius:14px;">
                            <div class="fw-bold mb-1"><i class="fas fa-file-alt me-1"></i> Plano Básico — relatório diário</div>
                            <div class="small text-muted mb-0">Só vendas do dia a dia. Relatórios avançados, exportação e análises inteligentes ficam nos planos superiores.</div>
                        </div>
                    <?php elseif ($plano_atual === 'PROFISSIONAL'): ?>
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <span class="badge rounded-pill" style="background:#0e7490;color:#fff;padding:8px 12px;">Avançados activos</span>
                            <span class="badge rounded-pill bg-light text-dark border" style="padding:8px 12px;">Exportação CSV/Excel</span>
                            <span class="badge rounded-pill bg-light text-muted border" style="padding:8px 12px;"><i class="fas fa-lock me-1"></i>Picos / QR / tempos (Empresarial)</span>
                            <span class="badge rounded-pill bg-light text-muted border" style="padding:8px 12px;"><i class="fas fa-lock me-1"></i>PDF (Empresarial)</span>
                        </div>
                    <?php else: ?>
                        <div class="rel-emp-hero mb-3">
                            <div class="rel-emp-hero-main">
                                <div class="rel-emp-hero-icon"><i class="fas fa-crown"></i></div>
                                <div>
                                    <div class="rel-emp-hero-title">Relatórios da sua casa</div>
                                    <div class="rel-emp-hero-meta">
                                        <?php echo htmlspecialchars($restaurante_nome_atual); ?>
                                        · <?php echo date('d/m/Y', strtotime($data_inicio)); ?> a <?php echo date('d/m/Y', strtotime($data_fim)); ?>
                                        · <?php echo htmlspecialchars($tipos_visiveis[$tipo] ?? $tipo); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="rel-emp-hero-links">
                                <a href="?tipo=vendas&periodo=<?php echo urlencode((string)$periodo); ?>&data_inicio=<?php echo urlencode($data_inicio); ?>&data_fim=<?php echo urlencode($data_fim); ?>" class="<?php echo $tipo === 'vendas' ? 'is-active' : ''; ?>">Vendas</a>
                                <?php if (!empty($tipos_visiveis['horarios_pico'])): ?>
                                    <a href="?tipo=horarios_pico&periodo=<?php echo urlencode((string)$periodo); ?>&data_inicio=<?php echo urlencode($data_inicio); ?>&data_fim=<?php echo urlencode($data_fim); ?>" class="<?php echo $tipo === 'horarios_pico' ? 'is-active' : ''; ?>">Picos</a>
                                <?php endif; ?>
                                <?php if (!empty($tipos_visiveis['tempo_preparo'])): ?>
                                    <a href="?tipo=tempo_preparo&periodo=<?php echo urlencode((string)$periodo); ?>&data_inicio=<?php echo urlencode($data_inicio); ?>&data_fim=<?php echo urlencode($data_fim); ?>" class="<?php echo $tipo === 'tempo_preparo' ? 'is-active' : ''; ?>">Tempos</a>
                                <?php endif; ?>
                                <?php if (!empty($tipos_visiveis['pedidos_qr'])): ?>
                                    <a href="?tipo=pedidos_qr&periodo=<?php echo urlencode((string)$periodo); ?>&data_inicio=<?php echo urlencode($data_inicio); ?>&data_fim=<?php echo urlencode($data_fim); ?>" class="<?php echo $tipo === 'pedidos_qr' ? 'is-active' : ''; ?>">QR</a>
                                <?php endif; ?>
                                <?php if (!empty($tipos_visiveis['produtos'])): ?>
                                    <a href="?tipo=produtos&periodo=<?php echo urlencode((string)$periodo); ?>&data_inicio=<?php echo urlencode($data_inicio); ?>&data_fim=<?php echo urlencode($data_fim); ?>" class="<?php echo $tipo === 'produtos' ? 'is-active' : ''; ?>">Produtos</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="card mb-4 <?php echo $plano_atual === 'EMPRESARIAL' ? 'rel-emp-filter-card' : ''; ?>">
                        <div class="card-body">
                            <form method="GET" class="row g-3" id="filtroRelatorio">
                                <div class="col-md-3">
                                    <label class="form-label">Tipo de Relatório</label>
                                    <select name="tipo" class="form-select">
                                        <?php foreach ($tipos_visiveis as $key => $value): ?>
                                            <option value="<?php echo $key; ?>" <?php echo $tipo === $key ? 'selected' : ''; ?>><?php echo $value; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if ($plano_atual === 'PROFISSIONAL'): ?>
                                        <small class="text-muted">Tipos inteligentes (picos, QR, tempos) no Empresarial.</small>
                                    <?php elseif ($plano_atual === 'BASICO'): ?>
                                        <small class="text-muted">Apenas relatório diário de vendas.</small>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Período</label>
                                    <select name="periodo" class="form-select">
                                        <option value="7" <?php echo $periodo === '7' ? 'selected' : ''; ?>>Últimos 7 dias</option>
                                        <?php if ($tem_relatorio_personalizado): ?>
                                            <option value="30" <?php echo $periodo === '30' ? 'selected' : ''; ?>>Últimos 30 dias</option>
                                            <option value="90" <?php echo $periodo === '90' ? 'selected' : ''; ?>>Últimos 90 dias</option>
                                            <option value="all" <?php echo $periodo === 'all' ? 'selected' : ''; ?>>Todo o período</option>
                                            <option value="custom" <?php echo $periodo === 'custom' ? 'selected' : ''; ?>>Personalizado</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <?php if ($tem_relatorio_personalizado): ?>
                                <div class="col-md-3">
                                    <label class="form-label">Data inicial</label>
                                    <input type="date" name="data_inicio" class="form-control" value="<?php echo htmlspecialchars($data_inicio); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Data final</label>
                                    <input type="date" name="data_fim" class="form-control" value="<?php echo htmlspecialchars($data_fim); ?>">
                                </div>
                                <?php else: ?>
                                <div class="col-md-6">
                                    <label class="form-label">Intervalo</label>
                                    <div class="form-control-plaintext fw-semibold">
                                        <?php echo date('d/m/Y', strtotime($data_inicio)); ?> — <?php echo date('d/m/Y', strtotime($data_fim)); ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <div class="col-12 d-flex gap-3 flex-wrap relatorios-filter-actions">
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filtrar</button>
                                    <button type="button" class="btn btn-success" onclick="imprimirRelatorio()" <?php echo $pode_imprimir ? '' : 'disabled title="Disponível no plano com Relatório Diário"'; ?>>
                                        <i class="fas fa-print"></i> Imprimir
                                    </button>
                                    <?php if ($tem_relatorio_personalizado): ?>
                                    <button type="button" class="btn btn-info" onclick="exportarRelatorio()" <?php echo $pode_exportar ? '' : 'disabled title="Disponível nos planos Profissional/Empresarial"'; ?>>
                                        <i class="fas fa-file-export"></i> Exportar
                                    </button>
                                    <?php if ($pode_exportar_pdf): ?>
                                        <button type="button" class="btn btn-dark" onclick="exportarPDF()">
                                            <i class="fas fa-file-pdf"></i> PDF
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-outline-secondary" disabled title="PDF disponível no plano Empresarial">
                                            <i class="fas fa-lock me-1"></i> PDF
                                        </button>
                                    <?php endif; ?>
                                    <?php else: ?>
                                        <span class="small text-muted align-self-center">Exportação CSV/Excel/PDF no Profissional e Empresarial.</span>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- RESUMO UNIFICADO: Aparece para todos os tipos de relatório -->
                    <?php if ($tipo !== 'pedidos_qr'): ?>
                        <div class="row mb-4" id="relatorioResumoCards">
                            <div class="<?php echo $plano_atual === 'BASICO' ? 'col-md-4' : 'col-md-3'; ?>">
                                <div class="report-stat-card">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <p class="report-stat-label">Total de Venda</p>
                                            <h3 class="report-stat-value mb-0"><?php echo $total_vendas ?? 0; ?></h3>
                                        </div>
                                        <div class="report-stat-icon tone-success"><i class="fas fa-shopping-cart"></i></div>
                                    </div>
                                </div>
                            </div>
                            <div class="<?php echo $plano_atual === 'BASICO' ? 'col-md-4' : 'col-md-3'; ?>">
                                <div class="report-stat-card">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <p class="report-stat-label">Faturamento</p>
                                            <h3 class="report-stat-value mb-0">MZN <?php echo number_format($valor_total ?? 0, 2, ',', '.'); ?></h3>
                                        </div>
                                        <div class="report-stat-icon tone-primary"><i class="fas fa-money-bill-wave"></i></div>
                                    </div>
                                </div>
                            </div>
                            <?php if ($tem_relatorio_personalizado): ?>
                            <div class="col-md-3">
                                <div class="report-stat-card">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <p class="report-stat-label">Ticket Médio</p>
                                            <h3 class="report-stat-value mb-0">MZN <?php echo number_format($ticket_medio ?? 0, 2, ',', '.'); ?></h3>
                                        </div>
                                        <div class="report-stat-icon tone-purple"><i class="fas fa-chart-line"></i></div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            <div class="<?php echo $plano_atual === 'BASICO' ? 'col-md-4' : 'col-md-3'; ?>">
                                <div class="report-stat-card">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <p class="report-stat-label">Período</p>
                                            <h6 class="mb-0 report-stat-label"><?php echo date('d/m/Y', strtotime($data_inicio)); ?> - <?php echo date('d/m/Y', strtotime($data_fim)); ?></h6>
                                        </div>
                                        <div class="report-stat-icon tone-teal"><i class="fas fa-calendar"></i></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($tipo === 'pedidos_qr'): ?>
                        <div class="row mb-4">
                            <div class="col-md-3">
                                <div class="report-stat-card">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <p class="report-stat-label">Pedidos QR</p>
                                            <h3 class="report-stat-value mb-0"><?php echo (int)$resumo_qr['total_pedidos']; ?></h3>
                                        </div>
                                        <div class="report-stat-icon tone-success"><i class="fas fa-qrcode"></i></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="report-stat-card">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <p class="report-stat-label">Receita QR</p>
                                            <h3 class="report-stat-value mb-0">MZN <?php echo number_format($resumo_qr['total_receita'], 2, ',', '.'); ?></h3>
                                        </div>
                                        <div class="report-stat-icon tone-primary"><i class="fas fa-money-bill-wave"></i></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="report-stat-card">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <p class="report-stat-label">Mesas Ativas</p>
                                            <h3 class="report-stat-value mb-0"><?php echo (int)$resumo_qr['mesas_ativas']; ?></h3>
                                        </div>
                                        <div class="report-stat-icon tone-teal"><i class="fas fa-chair"></i></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="report-stat-card">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <p class="report-stat-label">Hora Pico</p>
                                            <h3 class="report-stat-value mb-0"><?php echo htmlspecialchars($resumo_qr['hora_pico']); ?></h3>
                                        </div>
                                        <div class="report-stat-icon tone-purple"><i class="fas fa-clock"></i></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($plano_atual === 'EMPRESARIAL' && is_array($relEmpIntel)): ?>
                        <?php
                        $temPainelIa = !empty($relEmpIntel['alertas_ia'])
                            || !empty($relEmpIntel['projecao'])
                            || !empty($relEmpIntel['ia_produtos'])
                            || !empty($relEmpIntel['ia_picos'])
                            || !empty($relEmpIntel['ia_tempos']);
                        ?>
                        <?php if ($temPainelIa): ?>
                        <div class="card rel-emp-ia-panel mb-4" id="relEmpIaAssistente">
                            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-brain me-2"></i>Assistente IA do período
                                    <span class="badge ms-2" style="font-size:11px;background:rgba(14,165,233,.12);color:#0369a1;">IA Empresarial</span>
                                </h5>
                                <small class="text-muted">Com base nas vendas e pedidos do filtro (regras + séries)</small>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($relEmpIntel['projecao'])): $pj = $relEmpIntel['projecao']; ?>
                                    <div class="rel-emp-ia-proj mb-3">
                                        <div>
                                            <span>Média / dia (todos os dias)</span>
                                            <strong>MZN <?php echo number_format((float)($pj['media_dia'] ?? 0), 0, ',', '.'); ?></strong>
                                        </div>
                                        <div>
                                            <span>Dias restantes no mês</span>
                                            <strong><?php echo (int)($pj['dias_restantes'] ?? 0); ?></strong>
                                        </div>
                                        <div>
                                            <span>Projecção até fim do mês</span>
                                            <strong>MZN <?php echo number_format((float)($pj['projecao_mes'] ?? 0), 0, ',', '.'); ?></strong>
                                        </div>
                                        <?php if (isset($pj['delta_vs_anterior'])): ?>
                                        <div>
                                            <span>Vs período anterior</span>
                                            <strong class="<?php echo ((float)$pj['delta_vs_anterior'] >= 0) ? 'is-up' : 'is-down'; ?>">
                                                <?php echo (((float)$pj['delta_vs_anterior'] >= 0) ? '+' : '') . number_format((float)$pj['delta_vs_anterior'], 0, ',', '.'); ?>%
                                            </strong>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($relEmpIntel['alertas_ia'])): ?>
                                    <div class="rel-emp-ia-alerts mb-3">
                                        <?php foreach ($relEmpIntel['alertas_ia'] as $al): ?>
                                            <div class="rel-emp-ia-alert tom-<?php echo htmlspecialchars((string)($al['tom'] ?? 'info')); ?>">
                                                <span class="rel-emp-ia-area"><?php echo htmlspecialchars((string)($al['area'] ?? 'ops')); ?></span>
                                                <div>
                                                    <strong><?php echo htmlspecialchars((string)($al['titulo'] ?? '')); ?></strong>
                                                    <span><?php echo htmlspecialchars((string)($al['txt'] ?? '')); ?></span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($relEmpIntel['ia_produtos'])): $iap = $relEmpIntel['ia_produtos']; ?>
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <div class="rel-emp-ia-mini">
                                                <h6>ABC receita</h6>
                                                <p class="mb-0">A: <strong><?php echo (int)$iap['classe_a']; ?></strong> · B: <strong><?php echo (int)$iap['classe_b']; ?></strong> · C: <strong><?php echo (int)$iap['classe_c']; ?></strong></p>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="rel-emp-ia-mini">
                                                <h6>Empurrar no salão</h6>
                                                <?php if (!empty($iap['empurrar'])): ?>
                                                    <ul class="mb-0 ps-3">
                                                        <?php foreach (array_slice($iap['empurrar'], 0, 3) as $ep): ?>
                                                            <li><strong><?php echo htmlspecialchars((string)$ep['nome']); ?></strong> — <?php echo htmlspecialchars((string)$ep['acao']); ?></li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php else: ?>
                                                    <p class="text-muted small mb-0">Sem classe A neste filtro.</p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="rel-emp-ia-mini">
                                                <h6>Em queda (14d)</h6>
                                                <?php if (!empty($iap['queda'])): ?>
                                                    <ul class="mb-0 ps-3">
                                                        <?php foreach (array_slice($iap['queda'], 0, 3) as $qd): ?>
                                                            <li><strong><?php echo htmlspecialchars((string)$qd['nome']); ?></strong> (<?php echo number_format((float)$qd['delta_pct'], 0, ',', '.'); ?>%)</li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php else: ?>
                                                    <p class="text-muted small mb-0">Sem quedas relevantes.</p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($relEmpIntel['ia_picos'])): $ipk = $relEmpIntel['ia_picos']; ?>
                                    <div class="rel-emp-ia-mini mt-2">
                                        <h6>Previsão de procura</h6>
                                        <p class="mb-1">
                                            Próximo <strong><?php echo htmlspecialchars((string)$ipk['proximo_dia']); ?></strong>:
                                            pico esperado ~<strong><?php echo (int)$ipk['pico_esperado_qtd']; ?></strong> pedidos às
                                            <strong><?php echo sprintf('%02d:00', (int)$ipk['pico_esperado_hora']); ?></strong>.
                                        </p>
                                        <?php if (!empty($ipk['horas_reforco'])): ?>
                                            <p class="mb-0 small text-muted">
                                                Reforçar turnos:
                                                <?php
                                                echo htmlspecialchars(implode(', ', array_map(
                                                    static fn($h) => sprintf('%02d:00', (int)$h['hora']),
                                                    array_slice($ipk['horas_reforco'], 0, 4)
                                                )));
                                                ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($relEmpIntel['ia_tempos'])): $itm = $relEmpIntel['ia_tempos']; ?>
                                    <div class="rel-emp-ia-mini mt-2">
                                        <h6>Carga × tempo de preparo</h6>
                                        <?php if (!empty($itm['gargalos'])): ?>
                                            <ul class="mb-0 ps-3">
                                                <?php foreach (array_slice($itm['gargalos'], 0, 4) as $gb): ?>
                                                    <li>
                                                        <strong><?php echo sprintf('%02d:00', (int)$gb['hora']); ?></strong>
                                                        — <?php echo htmlspecialchars((string)$gb['avg_min']); ?> min médios com <?php echo (int)$gb['qtd']; ?> pedidos
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php else: ?>
                                            <p class="text-muted small mb-0">Sem gargalo forte: volume e preparo alinhados neste filtro.</p>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($relEmpIntel['chart']) || !empty($relEmpIntel['vs_anterior'])): ?>
                        <div class="card rel-emp-intel-card mb-4" id="relEmpChartCard">
                            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <h5 class="card-title mb-0"><i class="fas fa-chart-area me-2"></i>Receita inteligente do período</h5>
                                <?php if (!empty($relEmpIntel['vs_anterior'])): ?>
                                    <?php $vsA = $relEmpIntel['vs_anterior']; $dlt = (float)($vsA['delta_pct'] ?? 0); ?>
                                    <?php if (($vsA['delta_pct'] ?? null) === null): ?>
                                    <span class="badge" style="font-size:12px;background:#f1f5f9;color:#475569;">Sem vendas no período anterior para comparar</span>
                                    <?php else: ?>
                                    <span class="badge" style="font-size:12px;background:<?php echo $dlt >= 0 ? 'rgba(22,163,74,.12)' : 'rgba(220,38,38,.12)'; ?>;color:<?php echo $dlt >= 0 ? '#15803d' : '#dc2626'; ?>;">
                                        Vs anterior: <?php echo ($dlt >= 0 ? '+' : '') . number_format($dlt, 1, ',', '.'); ?>%
                                        · <?php echo date('d/m', strtotime((string)$vsA['prev_inicio'])); ?>–<?php echo date('d/m', strtotime((string)$vsA['prev_fim'])); ?>
                                    </span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <?php
                                $chVals = array_map(static fn($p) => (float)($p['total'] ?? 0), $relEmpIntel['chart'] ?? []);
                                $chLabs = array_map(static fn($p) => (string)($p['label'] ?? ''), $relEmpIntel['chart'] ?? []);
                                $chDatas = array_map(static fn($p) => (string)($p['data'] ?? ''), $relEmpIntel['chart'] ?? []);
                                $nomesDiaCurtoRel = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
                                $chDow = [];
                                foreach ($chDatas as $cd) {
                                    $chDow[] = $cd !== '' ? ($nomesDiaCurtoRel[(int)date('w', strtotime($cd))] ?? '') : '';
                                }
                                if (count($chVals) < 2) {
                                    $chVals = [0, 0];
                                    $chLabs = ['-', '-'];
                                    $chDow = ['', ''];
                                    $chDatas = ['', ''];
                                }
                                $chMax = max($chVals);
                                if ($chMax <= 0) {
                                    $chMax = 1;
                                }
                                $chTotal = array_sum($chVals);
                                $chDiasComVenda = 0;
                                $chMedia = 0.0;
                                foreach ($chVals as $cv) {
                                    if ($cv > 0) {
                                        $chMedia += $cv;
                                        $chDiasComVenda++;
                                    }
                                }
                                $chMedia = $chDiasComVenda > 0 ? ($chMedia / $chDiasComVenda) : 0.0;
                                $chPeakIdx = 0;
                                $chPeakVal = -1.0;
                                foreach ($chVals as $ix => $cv) {
                                    if ($cv >= $chPeakVal) {
                                        $chPeakVal = $cv;
                                        $chPeakIdx = $ix;
                                    }
                                }
                                $chLowIdx = 0;
                                $chLowVal = PHP_FLOAT_MAX;
                                foreach ($chVals as $ix => $cv) {
                                    if ($cv < $chLowVal) {
                                        $chLowVal = $cv;
                                        $chLowIdx = $ix;
                                    }
                                }
                                $chHoje = date('Y-m-d');
                                $chUltimoIdx = count($chVals) - 1;
                                $chW = 760;
                                $chH = 280;
                                $chL = 56;
                                $chR = 22;
                                $chT = 28;
                                $chB = 44;
                                $chPW = $chW - $chL - $chR;
                                $chPH = $chH - $chT - $chB;
                                $chN = count($chVals);
                                $chPts = [];
                                for ($ci = 0; $ci < $chN; $ci++) {
                                    $cx = $chL + ($chN === 1 ? $chPW / 2 : ($ci / ($chN - 1)) * $chPW);
                                    $cy = $chT + $chPH - (($chVals[$ci] / $chMax) * $chPH);
                                    $chPts[] = [round($cx, 1), round($cy, 1), $chVals[$ci], $chLabs[$ci], $chDow[$ci] ?? ''];
                                }
                                $chLine = implode(' ', array_map(static fn($p) => $p[0] . ',' . $p[1], $chPts));
                                $chArea = $chL . ',' . ($chT + $chPH) . ' ' . $chLine . ' ' . ($chL + $chPW) . ',' . ($chT + $chPH);
                                $chMediaY = $chT + $chPH - (($chMedia / $chMax) * $chPH);
                                $chMid = (int)floor($chN / 2);
                                $chMidX = ($chN > 1)
                                    ? ($chL + ($chMid / ($chN - 1)) * $chPW)
                                    : ($chL + $chPW / 2);
                                $chHalf1 = 0.0;
                                $chHalf2 = 0.0;
                                for ($ci = 0; $ci < $chN; $ci++) {
                                    if ($ci < $chMid) {
                                        $chHalf1 += $chVals[$ci];
                                    } else {
                                        $chHalf2 += $chVals[$ci];
                                    }
                                }
                                $chHalfDelta = 0.0;
                                if ($chHalf1 > 0) {
                                    $chHalfDelta = (($chHalf2 - $chHalf1) / $chHalf1) * 100;
                                } elseif ($chHalf2 > 0) {
                                    $chHalfDelta = null; // 1.ª metade sem vendas: não há base de comparação
                                }
                                $vsA = $relEmpIntel['vs_anterior'] ?? null;
                                $dltChart = is_array($vsA) ? (float)($vsA['delta_pct'] ?? 0) : 0.0;
                                ?>
                                <div class="rel-emp-chart-metrics">
                                    <div>
                                        <span>Total do filtro</span>
                                        <strong>MZN <?php echo number_format($chTotal, 0, ',', '.'); ?></strong>
                                    </div>
                                    <div>
                                        <span>Média / dia com venda</span>
                                        <strong>MZN <?php echo number_format($chMedia, 0, ',', '.'); ?></strong>
                                    </div>
                                    <div>
                                        <span>Pico<?php echo $chPeakVal > 0 ? ' · ' . htmlspecialchars((string)($chLabs[$chPeakIdx] ?? '')) : ''; ?></span>
                                        <strong>MZN <?php echo number_format(max(0, $chPeakVal), 0, ',', '.'); ?></strong>
                                    </div>
                                    <div>
                                        <span>2.ª metade vs 1.ª</span>
                                        <?php if ($chHalfDelta === null): ?>
                                            <strong>—</strong>
                                        <?php else: ?>
                                        <strong class="<?php echo $chHalfDelta >= 0 ? 'is-up' : 'is-down'; ?>">
                                            <?php echo ($chHalfDelta >= 0 ? '+' : '') . number_format($chHalfDelta, 0, ',', '.'); ?>%
                                        </strong>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="rel-emp-chart-wrap">
                                    <svg class="rel-emp-chart-svg" viewBox="0 0 <?php echo $chW; ?> <?php echo $chH; ?>" width="100%" height="100%" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Gráfico inteligente de receita do período">
                                        <defs>
                                            <linearGradient id="relEmpArea" x1="0" y1="0" x2="0" y2="1">
                                                <stop offset="0%" stop-color="#ea580c" stop-opacity="0.34"/>
                                                <stop offset="100%" stop-color="#ea580c" stop-opacity="0.03"/>
                                            </linearGradient>
                                            <linearGradient id="relEmpHalfPrev" x1="0" y1="0" x2="1" y2="0">
                                                <stop offset="0%" stop-color="#94a3b8" stop-opacity="0.10"/>
                                                <stop offset="100%" stop-color="#94a3b8" stop-opacity="0.04"/>
                                            </linearGradient>
                                            <linearGradient id="relEmpHalfCurr" x1="0" y1="0" x2="1" y2="0">
                                                <stop offset="0%" stop-color="#ea580c" stop-opacity="0.08"/>
                                                <stop offset="100%" stop-color="#ea580c" stop-opacity="0.16"/>
                                            </linearGradient>
                                        </defs>
                                        <?php if ($chN >= 4): ?>
                                            <rect x="<?php echo $chL; ?>" y="<?php echo $chT; ?>" width="<?php echo max(1, $chMidX - $chL); ?>" height="<?php echo $chPH; ?>" fill="url(#relEmpHalfPrev)" rx="6"/>
                                            <rect x="<?php echo $chMidX; ?>" y="<?php echo $chT; ?>" width="<?php echo max(1, ($chL + $chPW) - $chMidX); ?>" height="<?php echo $chPH; ?>" fill="url(#relEmpHalfCurr)" rx="6"/>
                                            <line x1="<?php echo $chMidX; ?>" y1="<?php echo $chT; ?>" x2="<?php echo $chMidX; ?>" y2="<?php echo $chT + $chPH; ?>" stroke="#fdba74" stroke-width="1.5" stroke-dasharray="4 4"/>
                                            <text x="<?php echo ($chL + $chMidX) / 2; ?>" y="<?php echo $chT - 10; ?>" text-anchor="middle" fill="#94a3b8" font-size="10" font-weight="700" font-family="Outfit, Segoe UI, Arial, sans-serif">1.ª metade</text>
                                            <text x="<?php echo ($chMidX + $chL + $chPW) / 2; ?>" y="<?php echo $chT - 10; ?>" text-anchor="middle" fill="#c2410c" font-size="10" font-weight="700" font-family="Outfit, Segoe UI, Arial, sans-serif">2.ª metade</text>
                                        <?php endif; ?>
                                        <?php for ($gi = 0; $gi <= 4; $gi++):
                                            $gy = $chT + ($chPH * $gi / 4);
                                            $gVal = $chMax * (1 - $gi / 4);
                                        ?>
                                            <line x1="<?php echo $chL; ?>" y1="<?php echo $gy; ?>" x2="<?php echo $chL + $chPW; ?>" y2="<?php echo $gy; ?>" stroke="rgba(0,0,0,.06)"/>
                                            <text x="<?php echo $chL - 8; ?>" y="<?php echo $gy + 4; ?>" text-anchor="end" fill="#94a3b8" font-size="11" font-family="Outfit, Segoe UI, Arial, sans-serif"><?php echo number_format($gVal, 0, ',', '.'); ?></text>
                                        <?php endfor; ?>
                                        <?php if ($chMedia > 0): ?>
                                            <line x1="<?php echo $chL; ?>" y1="<?php echo round($chMediaY, 1); ?>" x2="<?php echo $chL + $chPW; ?>" y2="<?php echo round($chMediaY, 1); ?>" stroke="#0ea5e9" stroke-width="1.75" stroke-dasharray="6 4"/>
                                            <rect x="<?php echo $chL + 4; ?>" y="<?php echo max($chT, round($chMediaY, 1) - 16); ?>" width="84" height="16" rx="4" fill="#e0f2fe"/>
                                            <text x="<?php echo $chL + 46; ?>" y="<?php echo max($chT + 11, round($chMediaY, 1) - 4); ?>" text-anchor="middle" fill="#0369a1" font-size="9" font-weight="700" font-family="Outfit, Segoe UI, Arial, sans-serif">Média MZN <?php echo number_format($chMedia, 0, ',', '.'); ?></text>
                                        <?php endif; ?>
                                        <polygon points="<?php echo htmlspecialchars($chArea); ?>" fill="url(#relEmpArea)"/>
                                        <polyline points="<?php echo htmlspecialchars($chLine); ?>" fill="none" stroke="#ea580c" stroke-width="3.5" stroke-linejoin="round" stroke-linecap="round"/>
                                        <?php foreach ($chPts as $pi => $pt):
                                            $isPeak = ($pi === $chPeakIdx && $pt[2] > 0);
                                            $isLow = ($pi === $chLowIdx && $chN >= 3 && $pt[2] < $chMedia && $chMedia > 0 && !$isPeak);
                                            $isEnd = ($pi === $chUltimoIdx);
                                            $isHojePt = (($chDatas[$pi] ?? '') === $chHoje);
                                            $isZero = ($pt[2] <= 0);
                                            $dot = $isPeak ? '#f59e0b' : ($isHojePt || $isEnd ? '#c2410c' : ($isZero ? '#cbd5e1' : '#ea580c'));
                                            $r = $isPeak ? 6.2 : (($isHojePt || $isEnd) ? 5.2 : ($isZero ? 2.8 : 3.8));
                                        ?>
                                            <circle cx="<?php echo $pt[0]; ?>" cy="<?php echo $pt[1]; ?>" r="<?php echo $r; ?>" fill="<?php echo $dot; ?>" stroke="#fff" stroke-width="2">
                                                <title><?php echo htmlspecialchars(($pt[4] ? $pt[4] . ' ' : '') . $pt[3] . ': MZN ' . number_format($pt[2], 2, ',', '.')); ?></title>
                                            </circle>
                                            <?php if ($isPeak): ?>
                                                <?php
                                                $labelX = min(max($pt[0], $chL + 36), $chL + $chPW - 36);
                                                $labelY = max($chT + 14, $pt[1] - 14);
                                                ?>
                                                <rect x="<?php echo $labelX - 34; ?>" y="<?php echo $labelY - 12; ?>" width="68" height="16" rx="4" fill="#fffbeb" stroke="#fbbf24"/>
                                                <text x="<?php echo $labelX; ?>" y="<?php echo $labelY; ?>" text-anchor="middle" fill="#b45309" font-size="9" font-weight="800" font-family="Outfit, Segoe UI, Arial, sans-serif">PICO <?php echo htmlspecialchars($pt[3]); ?></text>
                                            <?php elseif ($isLow && $pt[2] >= 0): ?>
                                                <?php $lowY = min($chT + $chPH - 6, $pt[1] + 16); ?>
                                                <text x="<?php echo $pt[0]; ?>" y="<?php echo $lowY; ?>" text-anchor="middle" fill="#94a3b8" font-size="8" font-weight="700" font-family="Outfit, Segoe UI, Arial, sans-serif">baixo</text>
                                            <?php endif; ?>
                                            <?php if ($isHojePt && !$isPeak): ?>
                                                <text x="<?php echo $pt[0] > ($chL + $chPW - 20) ? $pt[0] - 4 : $pt[0]; ?>" y="<?php echo max($chT + 12, $pt[1] - 12); ?>" text-anchor="<?php echo $pt[0] > ($chL + $chPW - 20) ? 'end' : 'middle'; ?>" fill="#c2410c" font-size="9" font-weight="800" font-family="Outfit, Segoe UI, Arial, sans-serif">HOJE</text>
                                            <?php elseif ($isEnd && !$isPeak && !$isHojePt): ?>
                                                <text x="<?php echo $pt[0]; ?>" y="<?php echo max($chT + 12, $pt[1] - 12); ?>" text-anchor="middle" fill="#c2410c" font-size="9" font-weight="800" font-family="Outfit, Segoe UI, Arial, sans-serif">FIM</text>
                                            <?php endif; ?>
                                            <?php if ($pi % max(1, (int)floor($chN / 7)) === 0 || $pi === $chN - 1 || $isPeak): ?>
                                                <text x="<?php echo $pt[0]; ?>" y="<?php echo $chH - 18; ?>" text-anchor="middle" fill="#94a3b8" font-size="10" font-family="Outfit, Segoe UI, Arial, sans-serif"><?php echo htmlspecialchars($pt[3]); ?></text>
                                                <text x="<?php echo $pt[0]; ?>" y="<?php echo $chH - 6; ?>" text-anchor="middle" fill="#cbd5e1" font-size="8" font-family="Outfit, Segoe UI, Arial, sans-serif"><?php echo htmlspecialchars($pt[4]); ?></text>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </svg>
                                </div>
                                <div class="rel-emp-chart-legend">
                                    <span><i class="lg-line"></i> Receita diária</span>
                                    <span><i class="lg-avg"></i> Média dos dias com venda</span>
                                    <span><i class="lg-peak"></i> Pico</span>
                                    <span><i class="lg-zone"></i> 2.ª metade do período</span>
                                    <?php if (is_array($vsA) && ($vsA['delta_pct'] ?? null) !== null): ?>
                                        <strong class="<?php echo $dltChart >= 0 ? 'is-up' : 'is-down'; ?>">
                                            <?php echo $dltChart >= 0 ? '▲' : '▼'; ?>
                                            <?php echo ($dltChart >= 0 ? '+' : '') . number_format($dltChart, 1, ',', '.'); ?>% vs período anterior
                                        </strong>
                                    <?php endif; ?>
                                </div>
                                <?php if ($chTotal <= 0): ?>
                                    <div class="text-muted small mt-2 mb-0">Sem vendas pagas neste filtro. Quando houver pagamentos, a curva e o pico aparecem aqui.</div>
                                <?php elseif ($chPeakVal > 0 && $chMedia > 0 && $chPeakVal >= ($chMedia * 1.4)): ?>
                                    <div class="rel-emp-chart-hint">
                                        <strong>Leitura:</strong>
                                        O pico em <?php echo htmlspecialchars((string)($chLabs[$chPeakIdx] ?? '')); ?>
                                        (<?php echo htmlspecialchars((string)($chDow[$chPeakIdx] ?? '')); ?>)
                                        ficou <?php echo number_format((($chPeakVal / $chMedia) - 1) * 100, 0, ',', '.'); ?>% acima da média —
                                        prepare stock e equipa nesse dia da semana no próximo ciclo.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="row g-3 mb-4 align-items-stretch rel-emp-intel" id="relEmpNotasPrint">
                            <?php if (!empty($relEmpIntel['kpis'])): ?>
                                <div class="col-lg-5">
                                    <div class="card<?php echo $relExtrasDetalhe ? ' rel-emp-intel-card' : ''; ?> h-100">
                                        <div class="card-header">
                                            <h5 class="card-title mb-0"><i class="fas fa-gauge-high me-2"></i>Leitura rápida</h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="rel-emp-kpi-grid">
                                                <?php foreach ($relEmpIntel['kpis'] as $kpiE): ?>
                                                    <div>
                                                        <span><?php echo htmlspecialchars((string)($kpiE['label'] ?? '')); ?></span>
                                                        <strong><?php echo htmlspecialchars((string)($kpiE['valor'] ?? '')); ?></strong>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div class="<?php echo !empty($relEmpIntel['kpis']) ? 'col-lg-7' : 'col-12'; ?>">
                                <div class="card<?php echo $relExtrasDetalhe ? ' rel-emp-intel-card' : ''; ?> h-100">
                                    <div class="card-header">
                                        <h5 class="card-title mb-0"><i class="fas fa-comments me-2"></i>Notas para a equipa</h5>
                                    </div>
                                    <div class="card-body">
                                        <?php if (!empty($relEmpIntel['notas'])): ?>
                                            <ul class="rel-emp-notes">
                                                <?php foreach ($relEmpIntel['notas'] as $nota): ?>
                                                    <li class="tom-<?php echo htmlspecialchars((string)($nota['tom'] ?? 'info')); ?>">
                                                        <i class="fas fa-<?php echo ($nota['tom'] ?? '') === 'ok' ? 'check-circle' : (($nota['tom'] ?? '') === 'warn' ? 'exclamation-triangle' : 'info-circle'); ?>"></i>
                                                        <div>
                                                            <?php if (!empty($nota['titulo'])): ?>
                                                                <strong><?php echo htmlspecialchars((string)$nota['titulo']); ?></strong>
                                                            <?php endif; ?>
                                                            <?php if (!empty($nota['acao'])): ?>
                                                                <span class="rel-emp-acao-chip"><?php echo htmlspecialchars((string)$nota['acao']); ?></span>
                                                            <?php endif; ?>
                                                            <span><?php echo htmlspecialchars((string)($nota['txt'] ?? '')); ?></span>
                                                        </div>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php else: ?>
                                            <div class="text-muted small mb-0">Neste filtro ainda não há notas extras. Mude o tipo ou o período para ver recomendações práticas.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php
                    // Totais por canal de balcão, só com o segundo canal activo.
                    $relCanais = [];
                    if (!empty($relBalcao2['ativo']) && $tipo === 'vendas') {
                        $relCanais = [
                            'mesa' => ['rotulo' => 'Salão', 'qtd' => 0, 'total' => 0.0],
                            'balcao' => ['rotulo' => 'Balcão', 'qtd' => 0, 'total' => 0.0],
                            'balcao2' => ['rotulo' => (string)$relBalcao2['nome'], 'qtd' => 0, 'total' => 0.0],
                        ];
                        foreach ($vendas ?? [] as $vCanal) {
                            $stCanalV = strtoupper(trim((string)($vCanal['status'] ?? '')));
                            if ($stCanalV !== '' && $stCanalV !== 'PAGO') { continue; }
                            $numCanal = trim((string)($vCanal['mesa_numero'] ?? ''));
                            if ($numCanal === '') {
                                $kCanal = 'balcao';
                            } elseif (mesa_eh_balcao($numCanal)) {
                                $kCanal = ($numCanal === (string)$relBalcao2['numero']) ? 'balcao2' : 'balcao';
                            } else {
                                $kCanal = 'mesa';
                            }
                            $relCanais[$kCanal]['qtd']++;
                            $relCanais[$kCanal]['total'] += (float)($vCanal['total_final'] ?? $vCanal['total'] ?? 0);
                        }
                    }
                    ?>
                    <?php if (!empty($relCanais)): ?>
                    <div class="card mb-4">
                        <div class="card-header"><h5 class="card-title mb-0"><i class="fas fa-shuffle me-2"></i>Por canal de atendimento</h5></div>
                        <div class="card-body">
                            <table class="table table-sm mb-0 rel-mini-table">
                                <thead><tr><th>Canal</th><th class="text-end">Vendas</th><th class="text-end">Total</th><?php if ($relExtrasDetalhe): ?><th class="text-end">%</th><?php endif; ?></tr></thead>
                                <tbody>
                                <?php $pctsCanal = caixa_percentagens(array_values(array_map(static fn($x) => (float)$x['total'], $relCanais))); $iCanal = 0; ?>
                                <?php foreach ($relCanais as $linhaCanalRel): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)$linhaCanalRel['rotulo'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="text-end"><?php echo (int)$linhaCanalRel['qtd']; ?></td>
                                        <td class="text-end">MZN <?php echo number_format((float)$linhaCanalRel['total'], 2, ',', '.'); ?></td>
                                        <?php if ($relExtrasDetalhe): ?><td class="text-end"><?php echo (int)($pctsCanal[$iCanal++] ?? 0); ?>%</td><?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($relExtrasPlano && $tipo === 'vendas' && (!empty($pagamentos_resumo) || !empty($cancelados_resumo['qtd']))): ?>
                    <div class="row g-3 mb-4 rel-extra-row">
                        <div class="col-lg-6">
                            <div class="card<?php echo $relExtrasDetalhe ? ' rel-emp-intel-card' : ''; ?> h-100">
                                <div class="card-header"><h5 class="card-title mb-0"><i class="fas fa-wallet me-2"></i>Por forma de pagamento</h5></div>
                                <div class="card-body">
                                    <?php if (empty($pagamentos_resumo)): ?>
                                        <div class="text-muted small">Sem vendas pagas neste período.</div>
                                    <?php else: ?>
                                    <table class="table table-sm mb-0 rel-mini-table">
                                        <thead><tr><th>Forma</th><th class="text-end">Vendas</th><th class="text-end">Total</th><?php if ($relExtrasDetalhe): ?><th class="text-end">%</th><?php endif; ?></tr></thead>
                                        <tbody>
                                        <?php $pctsPag = caixa_percentagens(array_values(array_map(static fn($x) => (float)$x['total'], $pagamentos_resumo))); $iPag = 0; ?>
                                        <?php foreach ($pagamentos_resumo as $fpNome => $fpRow): ?>
                                            <tr>
                                                <td><?php echo $relatorios_escape(caixa_forma_label((string)$fpNome)); ?></td>
                                                <td class="text-end"><?php echo (int)$fpRow['qtd']; ?></td>
                                                <td class="text-end">MZN <?php echo number_format((float)$fpRow['total'], 2, ',', '.'); ?></td>
                                                <?php if ($relExtrasDetalhe): ?><td class="text-end"><?php echo (int)($pctsPag[$iPag++] ?? 0); ?>%</td><?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                        <tfoot><tr><th>Total</th><th class="text-end"><?php echo (int)$total_vendas; ?></th><th class="text-end">MZN <?php echo number_format((float)$valor_total, 2, ',', '.'); ?></th><?php if ($relExtrasDetalhe): ?><th class="text-end">100%</th><?php endif; ?></tr></tfoot>
                                    </table>
                                    <p class="small text-muted mt-2 mb-0 rel-mini-resumo">Use para conferir com o banco e as carteiras móveis.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="card<?php echo $relExtrasDetalhe ? ' rel-emp-intel-card' : ''; ?> h-100">
                                <div class="card-header"><h5 class="card-title mb-0"><i class="fas fa-ban me-2"></i>Vendas canceladas</h5></div>
                                <div class="card-body">
                                    <?php if (empty($cancelados_resumo['qtd'])): ?>
                                        <div class="text-muted small rel-mini-resumo">Nenhuma venda cancelada neste período.</div>
                                    <?php else: ?>
                                    <div class="rel-cancel-total rel-mini-resumo"><strong><?php echo (int)$cancelados_resumo['qtd']; ?></strong> venda(s) cancelada(s) · <strong>MZN <?php echo number_format((float)$cancelados_resumo['valor'], 2, ',', '.'); ?></strong>
                                        <small>(não entram no faturamento)</small></div>
                                    <table class="table table-sm mb-0 rel-mini-table">
                                        <thead><tr><th>Operador</th><th class="text-end">Cancelamentos</th><th class="text-end">Valor</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($cancelados_resumo['por_operador'] as $opNome => $opRow): ?>
                                            <tr><td><?php echo $relatorios_escape($opNome); ?></td><td class="text-end"><?php echo (int)$opRow['qtd']; ?></td><td class="text-end">MZN <?php echo number_format((float)$opRow['valor'], 2, ',', '.'); ?></td></tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    <p class="small text-muted mt-2 mb-0 rel-mini-resumo">Muitos cancelamentos no mesmo operador merecem atenção.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="card <?php echo $plano_atual === 'EMPRESARIAL' ? 'rel-emp-table-card' : ''; ?>" id="relatorioConteudoCard">
                        <div class="card-header">
                            <h5 class="card-title">
                                <i class="fas fa-list me-2"></i><?php echo $relatorios_escape($tipos_visiveis[$tipo] ?? $tipos[$tipo] ?? ucfirst($tipo)); ?>
                                <?php if ($plano_atual === 'EMPRESARIAL'): ?>
                                    <span class="pro-badge" style="background:linear-gradient(135deg,#ea580c,#c2410c);">EMP</span>
                                <?php elseif ($tem_relatorios_avancados): ?>
                                    <span class="pro-badge">PRO</span>
                                <?php endif; ?>
                            </h5>
                        </div>
                        <div class="card-body p-0">
                            <?php if ($tipo === 'vendas'): ?>
                                <div class="table-responsive">
                                    <table class="<?php echo $table_class; ?>" id="tabelaRelatorio">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Data/Hora</th>
                                                <th>Cliente</th>
                                                <th>Mesa</th>
                                                <th>Valor</th>
                                                <th>Forma Pagamento</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($vendas ?? [] as $v): ?>
                                                <tr>
                                                    <td>#<?php echo str_pad($v['id'], 4, '0', STR_PAD_LEFT); ?></td>
                                                    <td><?php echo date('d/m/Y H:i', strtotime($v['criado_em'])); ?></td>
                                                    <td><?php echo $relatorios_escape($v['cliente_nome'] ?? 'Consumidor'); ?></td>
                                                    <td><?php echo $relatorios_escape($relatorios_mesa_label($v['mesa_numero'] ?? '')); ?></td>
                                                    <td>MZN <?php echo number_format($v['valor_total'] ?? $v['total'] ?? $v['total_final'] ?? 0, 2, ',', '.'); ?></td>
                                                    <td><?php echo $relatorios_escape(caixa_forma_label((string)($v['forma_pagamento'] ?? 'DINHEIRO'))); ?></td>
                                                    <td><span class="badge bg-<?php echo ($v['status'] ?? 'PENDENTE') === 'PAGO' ? 'success' : 'warning'; ?>"><?php echo $relatorios_escape(ucfirst((string)($v['status'] ?? 'Pendente'))); ?></span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <?php if (empty($vendas ?? [])): ?>
                                                <tr>
                                                    <td colspan="7" class="text-center py-4">Nenhuma venda encontrada neste período</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php elseif ($tipo === 'produtos'): ?>
                                <?php
                                // Empresarial: lucro estimado com o custo das fichas técnicas
                                $relCustos = [];
                                $relPlanoComCusto = in_array($plano_atual, ['EMPRESARIAL', 'PROFISSIONAL'], true);
                                if ($relPlanoComCusto && $db instanceof PDO) {
                                    require_once APP_BASE . '/src/config/produto_custos.php';
                                    $relCustos = produto_custos_ficha($db, (int)$restaurante_id);
                                }
                                $relMostraLucro = $relPlanoComCusto;
                                $relMostraMargem = $plano_atual === 'EMPRESARIAL';
                                $relLucroTotal = 0.0;
                                $relLucroIncompleto = false;
                                ?>
                                <div class="table-responsive">
                                    <table class="<?php echo $table_class; ?>" id="tabelaRelatorio">
                                        <thead>
                                            <tr>
                                                <th>Produto</th>
                                                <th>Categoria</th>
                                                <?php if ($plano_atual === 'EMPRESARIAL' && $tem_analise_abc_vendas): ?>
                                                <th>ABC</th>
                                                <?php endif; ?>
                                                <th>Preço</th>
                                                <th>Quant. Vendida</th>
                                                <th>Receita</th>
                                                <?php if ($relMostraLucro): ?><th title="Receita menos o custo dos insumos (fichas técnicas)">Lucro estimado</th><?php endif; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($produtos ?? [] as $p): ?>
                                                <tr>
                                                    <td><?php echo $relatorios_escape($p['nome'] ?? ''); ?></td>
                                                    <td><?php echo $relatorios_escape($p['categoria'] ?? '-'); ?></td>
                                                    <?php if ($plano_atual === 'EMPRESARIAL' && $tem_analise_abc_vendas): ?>
                                                    <td>
                                                        <?php $abc = (string)($p['categoria_abc'] ?? ''); ?>
                                                        <?php if ($abc !== ''): ?>
                                                            <span class="badge <?php echo $abc === 'A' ? 'bg-success' : ($abc === 'B' ? 'bg-warning text-dark' : 'bg-secondary'); ?>">
                                                                <?php echo htmlspecialchars($abc); ?>
                                                                <?php if (isset($p['pct_receita'])): ?>
                                                                    · <?php echo number_format((float)$p['pct_receita'], 0, ',', '.'); ?>%
                                                                <?php endif; ?>
                                                            </span>
                                                        <?php else: ?>
                                                            —
                                                        <?php endif; ?>
                                                    </td>
                                                    <?php endif; ?>
                                                    <td>MZN <?php echo number_format($p['preco'], 2, ',', '.'); ?></td>
                                                    <td><span class="badge bg-primary"><?php echo $p['total_vendido']; ?></span></td>
                                                    <td>MZN <?php echo number_format($p['receita'], 2, ',', '.'); ?></td>
                                                    <?php if ($relMostraLucro): ?>
                                                    <td>
                                                        <?php
                                                        $cU = $relCustos[(int)($p['id'] ?? 0)] ?? null;
                                                        $qV = (float)($p['total_vendido'] ?? 0);
                                                        if ($cU !== null) {
                                                            $lucroP = (float)$p['receita'] - $cU * $qV;
                                                            $relLucroTotal += $lucroP;
                                                            $margP = (float)$p['receita'] > 0 ? round($lucroP / (float)$p['receita'] * 100) : null;
                                                            echo 'MZN ' . number_format($lucroP, 2, ',', '.') . (($relMostraMargem && $margP !== null) ? ' <small class="' . ($margP < 50 ? 'text-danger' : 'text-success') . '">(' . $margP . '%)</small>' : '');
                                                        } else {
                                                            if ($qV > 0) { $relLucroIncompleto = true; }
                                                            echo '<span class="text-muted small" title="Sem ficha técnica ou sem custo dos insumos">—</span>';
                                                        }
                                                        ?>
                                                    </td>
                                                    <?php endif; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                            <?php if ($relMostraLucro && !empty($produtos ?? [])): ?>
                                                <tr class="rel-lucro-total">
                                                    <td colspan="<?php echo ($plano_atual === 'EMPRESARIAL' && $tem_analise_abc_vendas) ? '6' : '5'; ?>" class="text-end"><strong>Lucro estimado total</strong><?php echo $relLucroIncompleto ? ' <small class="text-muted">(sem os produtos sem custo)</small>' : ''; ?></td>
                                                    <td><strong>MZN <?php echo number_format($relLucroTotal, 2, ',', '.'); ?></strong></td>
                                                </tr>
                                            <?php endif; ?>
                                            <?php if (empty($produtos ?? [])): ?>
                                                <tr>
                                                    <td colspan="<?php echo ($plano_atual === 'EMPRESARIAL' && $tem_analise_abc_vendas) ? '7' : '6'; ?>" class="text-center py-4">Nenhum produto encontrado neste período</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php elseif ($tipo === 'caixa'): ?>
                                <div class="table-responsive">
                                    <table class="<?php echo $table_class; ?>" id="tabelaRelatorio">
                                        <thead>
                                            <tr>
                                                <th>Data Abertura</th>
                                                <th>Data Fechamento</th>
                                                <th>Valor Abertura</th>
                                                <th>Valor Fechamento</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($movimentos ?? [] as $m): ?>
                                                <tr>
                                                    <td><?php echo !empty($m['data_abertura']) ? date('d/m/Y H:i', strtotime($m['data_abertura'])) : '-'; ?></td>
                                                    <td><?php echo !empty($m['data_fechamento']) ? date('d/m/Y H:i', strtotime($m['data_fechamento'])) : '-'; ?></td>
                                                    <td>MZN <?php echo number_format($m['valor_abertura'] ?? 0, 2, ',', '.'); ?></td>
                                                    <td>MZN <?php echo number_format($m['valor_fechamento'] ?? 0, 2, ',', '.'); ?></td>
                                                    <td><span class="badge bg-<?php echo ($m['status'] ?? 'aberto') === 'fechado' ? 'secondary' : 'success'; ?>"><?php echo $relatorios_escape(ucfirst((string)($m['status'] ?? 'aberto'))); ?></span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <?php if (empty($movimentos ?? [])): ?>
                                                <tr>
                                                    <td colspan="5" class="text-center py-4">Nenhum caixa encontrado</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php elseif ($tipo === 'financeiro'): ?>
                                <?php
                                $por_pagamento = [];
                                foreach ($vendas ?? [] as $v) {
                                    $forma = strtoupper($v['forma_pagamento'] ?? 'DINHEIRO');
                                    if (!isset($por_pagamento[$forma])) $por_pagamento[$forma] = ['qtd' => 0, 'total' => 0];
                                    $por_pagamento[$forma]['qtd']++;
                                    $por_pagamento[$forma]['total'] += floatval($v['valor_total'] ?? $v['total'] ?? $v['total_final'] ?? 0);
                                }
                                arsort($por_pagamento);
                                $grand_total = array_sum(array_column($por_pagamento, 'total'));
                                ?>
                                <div class="row g-3 p-3 mb-2">
                                    <?php foreach ($por_pagamento as $forma => $dados): ?>
                                        <div class="col-md-3">
                                            <div class="report-stat-card">
                                                <div class="report-stat-label"><?php echo htmlspecialchars($forma); ?></div>
                                                <div class="report-stat-value">MZN <?php echo number_format($dados['total'], 2, ',', '.'); ?></div>
                                                <small class="text-muted"><?php echo (int)$dados['qtd']; ?> venda(s) &mdash; <?php echo $grand_total > 0 ? number_format($dados['total'] / $grand_total * 100, 1, ',', '.') : '0,0'; ?>%</small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="table-responsive">
                                    <table class="<?php echo $table_class; ?>" id="tabelaRelatorio">
                                        <thead>
                                            <tr>
                                                <th>Forma de Pagamento</th>
                                                <th>Qtd. Vendas</th>
                                                <th>Total</th>
                                                <th>% do Faturamento</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($por_pagamento as $forma => $dados): ?>
                                                <tr>
                                                    <td><span class="badge bg-primary"><?php echo $relatorios_escape($forma); ?></span></td>
                                                    <td><?php echo (int)$dados['qtd']; ?></td>
                                                    <td>MZN <?php echo number_format($dados['total'], 2, ',', '.'); ?></td>
                                                    <td><?php echo $grand_total > 0 ? number_format($dados['total'] / $grand_total * 100, 1, ',', '.') : '0,0'; ?>%</td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <?php if (empty($por_pagamento)): ?>
                                                <tr>
                                                    <td colspan="4" class="text-center py-4">Nenhuma venda encontrada neste período</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php elseif ($tipo === 'pedidos_qr'): ?>
                                <div class="p-3">
                                    <h6 class="mb-3"><i class="fas fa-chair me-2"></i>Pedidos por Mesa</h6>
                                    <div class="table-responsive mb-4">
                                        <table class="<?php echo $table_class; ?>" id="tabelaRelatorio">
                                            <thead>
                                                <tr>
                                                    <th>Mesa</th>
                                                    <th>Qtd. Pedidos</th>
                                                    <th>Ticket Médio</th>
                                                    <th>Receita</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($pedidos_qr_mesa as $qm): ?>
                                                    <tr>
                                                        <td><?php echo $relatorios_escape((string)$qm['mesa_numero']); ?></td>
                                                        <td><span class="badge bg-primary"><?php echo (int)$qm['total_pedidos']; ?></span></td>
                                                        <td>MZN <?php echo number_format((float)$qm['ticket_medio'], 2, ',', '.'); ?></td>
                                                        <td>MZN <?php echo number_format((float)$qm['valor_total'], 2, ',', '.'); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <?php if (empty($pedidos_qr_mesa)): ?>
                                                    <tr>
                                                        <td colspan="4" class="text-center py-4">Nenhum pedido QR encontrado neste período</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <h6 class="mb-3"><i class="fas fa-clock me-2"></i>Pedidos por Hora</h6>
                                    <div class="table-responsive">
                                        <table class="<?php echo $table_class; ?>">
                                            <thead>
                                                <tr>
                                                    <th>Hora</th>
                                                    <th>Qtd. Pedidos</th>
                                                    <th>Receita</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($pedidos_qr_hora as $qh): ?>
                                                    <tr>
                                                        <td><?php echo $relatorios_escape((string)$qh['hora']); ?></td>
                                                        <td><span class="badge bg-info"><?php echo (int)$qh['total_pedidos']; ?></span></td>
                                                        <td>MZN <?php echo number_format((float)$qh['valor_total'], 2, ',', '.'); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <?php if (empty($pedidos_qr_hora)): ?>
                                                    <tr>
                                                        <td colspan="3" class="text-center py-4">Nenhum dado por hora no período selecionado</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                            <?php elseif ($tipo === 'horarios_pico'): ?>
                                <div class="p-4">
                                    <h6 class="mb-4"><i class="fas fa-clock me-2 text-warning"></i>Volume de Pedidos por Hora do Dia</h6>
                                    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;height:180px;padding-bottom:8px;">
                                        <?php foreach ($pico_horas as $hd): ?>
                                            <?php
                                            $pct = $pico_max > 0 ? round($hd['qtd_pedidos'] / $pico_max * 100) : 0;
                                            $barColor = $pct >= 80 ? '#ef4444' : ($pct >= 50 ? '#f59e0b' : '#3b82f6');
                                            ?>
                                            <div style="flex:1;min-width:22px;display:flex;flex-direction:column;align-items:center;gap:4px" title="<?php echo $relatorios_escape($hd['hora']); ?>h – <?php echo (int)$hd['qtd_pedidos']; ?> pedidos">
                                                <?php if ($hd['qtd_pedidos'] > 0): ?>
                                                    <span style="font-size:10px;font-weight:700;color:#475569"><?php echo (int)$hd['qtd_pedidos']; ?></span>
                                                <?php else: ?>
                                                    <span style="font-size:10px">&nbsp;</span>
                                                <?php endif; ?>
                                                <div style="width:100%;background:<?php echo $barColor; ?>;border-radius:6px 6px 0 0;height:<?php echo max(4, $pct); ?>%;transition:height 0.3s"></div>
                                                <span style="font-size:9px;color:#94a3b8;white-space:nowrap"><?php echo $relatorios_escape(sprintf('%02d', $hd['hora'])); ?>h</span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="mt-3" style="font-size:13px;color:#475569">
                                        <i class="fas fa-fire text-danger me-1"></i>
                                        Horário de pico: <strong><?php echo $relatorios_escape(sprintf('%02d', $pico_best)); ?>h – <?php echo $relatorios_escape(sprintf('%02d', $pico_best + 1)); ?>h</strong>
                                        com <strong><?php echo (int)$pico_horas[$pico_best]['qtd_pedidos']; ?></strong> pedidos
                                    </p>
                                    <div class="table-responsive mt-4">
                                        <table class="<?php echo $table_class; ?>" id="tabelaRelatorio">
                                            <thead>
                                                <tr>
                                                    <th>Hora</th>
                                                    <th>Pedidos</th>
                                                    <th>Receita</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($pico_horas as $hd): if ($hd['qtd_pedidos'] <= 0) continue; ?>
                                                    <tr>
                                                        <td><?php echo $relatorios_escape(sprintf('%02d:00 – %02d:59', $hd['hora'], $hd['hora'])); ?></td>
                                                        <td><span class="badge bg-primary"><?php echo (int)$hd['qtd_pedidos']; ?></span></td>
                                                        <td>MZN <?php echo number_format($hd['receita'], 2, ',', '.'); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <?php if (array_sum(array_column($pico_horas, 'qtd_pedidos')) === 0): ?>
                                                    <tr>
                                                        <td colspan="3" class="text-center py-4">Nenhum pedido no período</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                            <?php elseif ($tipo === 'tempo_preparo'): ?>
                                <div class="p-4">
                                    <?php if ($total_vendas === 0): ?>
                                        <div class="text-center py-5">
                                            <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                                            <p>Nenhum dado de tempo de preparo disponível.<br>
                                                <small class="text-muted">Execute o SQL em <code>schema_melhorias2.sql</code> para adicionar a coluna e os pedidos passarão a registar o início do preparo.</small>
                                            </p>
                                        </div>
                                    <?php else: ?>
                                        <div class="row g-3 mb-4">
                                            <div class="col-md-3">
                                                <div class="report-stat-card">
                                                    <div class="report-stat-icon tone-primary mb-2"><i class="fas fa-hourglass-start"></i></div>
                                                    <div class="report-stat-label">Espera Média (fila)</div>
                                                    <div class="report-stat-value"><?php echo $relatorios_escape($relatorios_formatar_duracao($prep_resumo['avg_espera_seg'] ?? 0)); ?></div>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="report-stat-card">
                                                    <div class="report-stat-icon tone-success mb-2"><i class="fas fa-fire"></i></div>
                                                    <div class="report-stat-label">Preparo Médio</div>
                                                    <div class="report-stat-value"><?php echo $relatorios_escape($relatorios_formatar_duracao($prep_resumo['avg_preparo_seg'] ?? 0)); ?></div>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="report-stat-card">
                                                    <div class="report-stat-icon tone-teal mb-2"><i class="fas fa-bolt"></i></div>
                                                    <div class="report-stat-label">Preparo Mínimo</div>
                                                    <div class="report-stat-value"><?php echo $relatorios_escape($relatorios_formatar_duracao($prep_resumo['min_preparo_seg'] ?? 0)); ?></div>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="report-stat-card">
                                                    <div class="report-stat-icon tone-purple mb-2"><i class="fas fa-exclamation-circle"></i></div>
                                                    <div class="report-stat-label">Preparo Máximo</div>
                                                    <div class="report-stat-value"><?php echo $relatorios_escape($relatorios_formatar_duracao($prep_resumo['max_preparo_seg'] ?? 0)); ?></div>
                                                </div>
                                            </div>
                                        </div>
                                        <h6 class="mb-3"><i class="fas fa-chart-line me-2 text-info"></i>Tempo de Preparo por Hora do Dia</h6>
                                        <div class="table-responsive">
                                            <table class="<?php echo $table_class; ?>" id="tabelaRelatorio">
                                                <thead>
                                                    <tr>
                                                        <th>Hora</th>
                                                        <th>Pedidos</th>
                                                        <th>Tempo Médio</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($prep_por_hora as $ph): ?>
                                                        <?php $avgSeg = (float)($ph['avg_seg'] ?? 0);
                                                        $avgM = $avgSeg / 60;
                                                        $cls = $avgM >= 20 ? 'danger' : ($avgM >= 12 ? 'warning' : 'success'); ?>
                                                        <tr>
                                                            <td><?php echo $relatorios_escape(sprintf('%02d:00 – %02d:59', (int)$ph['hora'], (int)$ph['hora'])); ?></td>
                                                            <td><?php echo (int)$ph['qtd']; ?></td>
                                                            <td><span class="badge bg-<?php echo $cls; ?>"><?php echo $relatorios_escape($relatorios_formatar_duracao($avgSeg)); ?></span></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>

                            <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-chart-pie fa-4x text-muted mb-3"></i>
                                    <p class="text-muted">Selecione um tipo de relatório acima</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="lib/bootstrap-5.3.2/js/bootstrap.bundle.min.js"></script>
    <script type="application/json" id="cfg-relatorios">{"v0": <?php echo $pode_exportar ? 'true' : 'false'; ?>, "v1": <?php echo $pode_imprimir ? 'true' : 'false'; ?>, "v2": <?php echo $pode_exportar_pdf ? 'true' : 'false'; ?>, "v3": <?php echo json_encode($tipos_visiveis[$tipo] ?? $tipos[$tipo] ?? ucfirst($tipo), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>, "v4": <?php echo json_encode($restaurante_nome_atual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>, "v5": <?php echo json_encode(date('d/m/Y', strtotime($data_inicio)) . ' - ' . date('d/m/Y', strtotime($data_fim)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>}</script>
    <script src="js/relatorios.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/relatorios.js'); ?>"></script>
</body>

</html>
