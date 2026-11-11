<?php

/*
   Configuração dos Planos do Sistema
   Define limites e funcionalidades de cada plano
   Versão 2.0 - Com mais granularidade
 */

$planos = [
    'BASICO' => [
        'nome' => 'Básico',
        'descricao' => 'Operação completa para lanchonetes e restaurantes pequenos a médios.',
        'cor' => '#6c757d',
        'icone' => 'fa-user',
        'precos' => [
            'mensal' => 799,
            'trimestral' => 2157,
            'anual' => 7670
        ],
        'trial_dias' => 30,
        'limites' => [
            'produtos' => 150,
            'insumos' => 30,
            'usuarios' => 6,
            'mesas' => 30,
            'balcoes' => 1,
            'categorias' => 10,
            'subcategorias' => 0,
            'filiais' => 0,
        ],
        'funcionalidades' => [
            // PDV e operação diária (lanchonete pequena/média)
            'pdv' => true,
            'caixa' => true, // abrir/fechar caixa no PDV
            // Contas abertas + mapa de mesas: a partir do Profissional
            'contas_abertas' => false,
            'garcons' => false,

            // Produtos e Estoque
            'estoque_simples' => true,
            'produtos_basico' => true,
            'estoque_avancado' => false,
            // Ingredientes + receita + baixa automatica (Basico: versao simples, ate limites['insumos'])
            'estoque_ingredientes' => true,
            'transferencia_estoque' => false,

            // Mesas e Pedidos
            'mesas' => true,
            'pedidos_online' => false,
            'reservas_qr' => false,
            'cardapio_digital' => true,
            'fluxo_garcom' => true,
            'fluxo_cozinha' => true,
            // Telas KDS Bar/Cozinha: no Básico o preparo é no PDV (barraca)
            'telas_bar_cozinha' => false,

            // Categorias (NOVO)
            'subcategorias' => false,

            // Relatórios
            'relatorio_diario' => true,
            'relatorios_avancados' => false,
            'estatisticas_produtos' => false,
            'relatorios_inteligentes' => false,
            'relatorio_personalizado' => false,
            'relatorio_financeiro' => false,
            'export_pdf' => false,
            'export_excel' => false,
            'export_csv' => false,
            'exportacao_relatorios' => false,

            // Dashboard / IA (somente EMP ou PRO V2 futuro)
            'dashboard_executivo' => false,
            'comparativo_filiais' => false,
            'dashboard_inteligente' => false,
            'analise_abc_vendas' => false,
            'recomendacoes_automaticas' => false,
            'previsao_demanda' => false,

            // Clientes
            'clientes' => true,
            'historico_clientes' => false,
            'gestao_funcionarios' => false,

            // Impressão
            'impressao_recibos' => true,
            'logo_recibo' => true,

            // Backup
            'backup_manual' => true,
            'backup_automatico' => false,
            'backup_diario' => false,
            'backup_hora' => false,
            'download_banco' => false,
            'recuperacao_automatica' => false,

            // Multi-filial
            'multi_filial' => false,

            // Integrações
            'api_integracao' => false,
            'notificacoes' => false,

            // Suporte
            'suporte_email' => true,
            'suporte_whatsapp' => false,
            'suporte_24h' => false,
            'suporte_prioritario' => false,
        ]
    ],
    'PROFISSIONAL' => [
        'nome' => 'Profissional',
        'descricao' => 'Ideal para restaurantes médios.',
        'cor' => '#17a2b8',
        'icone' => 'fa-star',
        'precos' => [
            'mensal' => 1999,
            'trimestral' => 5397,
            'anual' => 19190
        ],
        'limites' => [
            'produtos' => 500,
            'insumos' => -1,
            'usuarios' => 10,
            'mesas' => 50,
            'balcoes' => 1,
            'categorias' => 25,
            'subcategorias' => 50,
            'filiais' => 0,
        ],
        'funcionalidades' => [
            // PDV e Vendas
            'pdv' => true,
            'caixa' => true,
            'contas_abertas' => true,
            'garcons' => true,

            // Produtos e Estoque
            'estoque_simples' => true,
            'produtos_basico' => true,
            'estoque_avancado' => true,
            // Ingredientes + receita + baixa automatica (Basico: versao simples, ate limites['insumos'])
            'estoque_ingredientes' => true,
            'transferencia_estoque' => false,

            // Mesas e Pedidos
            'mesas' => true,
            'pedidos_online' => true,
            'reservas_qr' => false,
            'cardapio_digital' => true,
            'fluxo_garcom' => true,
            'fluxo_cozinha' => true,
            'telas_bar_cozinha' => true,

            // Categorias (NOVO)
            'subcategorias' => true,

            // Relatórios
            'relatorio_diario' => true,
            'relatorios_avancados' => true,
            'estatisticas_produtos' => true,
            'relatorios_inteligentes' => false,
            'relatorio_personalizado' => true,
            'relatorio_financeiro' => true,
            'export_pdf' => true,
            'export_excel' => true,
            'export_csv' => true,
            'exportacao_relatorios' => true,

            // Dashboard / IA (PRO sem pacote EMP; IA full fica no EMP)
            'dashboard_executivo' => false,
            'comparativo_filiais' => false,
            'dashboard_inteligente' => false,
            'analise_abc_vendas' => false,
            'recomendacoes_automaticas' => false,
            'previsao_demanda' => false,

            // Clientes
            'clientes' => true,
            'historico_clientes' => true,
            'gestao_funcionarios' => true,

            // Impressão
            'impressao_recibos' => true,
            'logo_recibo' => true,

            // Backup
            'backup_manual' => true,
            'backup_automatico' => false,
            'backup_diario' => true,
            'backup_hora' => false,
            'download_banco' => false,
            'recuperacao_automatica' => false,

            // Multi-filial
            'multi_filial' => false,

            // Integrações
            'api_integracao' => false,
            'notificacoes' => false,
            // Email ao confirmar/cancelar reserva (sem WhatsApp automático)
            'reservas_notificacao_email' => true,

            // Suporte
            'suporte_email' => true,
            'suporte_whatsapp' => true,
            'suporte_24h' => false,
            'suporte_prioritario' => false,
        ]
    ],
    'EMPRESARIAL' => [
        'nome' => 'Empresarial',
        'descricao' => 'Para grandes restaurantes e redes.',
        'cor' => '#6f42c1',
        'icone' => 'fa-crown',
        'precos' => [
            'mensal' => 3999,
            'trimestral' => 10797,
            'anual' => 38390
        ],
        'limites' => [
            'produtos' => -1,
            'insumos' => -1,
            'usuarios' => -1,
            'mesas' => -1,
            'balcoes' => 2, // Empresarial pode activar um segundo canal de balcao (ex.: Take-away)
            'filiais' => 5,
            'categorias' => -1,
            'subcategorias' => -1,
        ],
        'funcionalidades' => [
            // PDV e Vendas
            'pdv' => true,
            'caixa' => true,
            'contas_abertas' => true,
            'garcons' => true,

            // Produtos e Estoque
            'estoque_simples' => true,
            'produtos_basico' => true,
            'estoque_avancado' => true,
            // Ingredientes + receita + baixa automatica (Basico: versao simples, ate limites['insumos'])
            'estoque_ingredientes' => true,
            'transferencia_estoque' => true,

            // Mesas e Pedidos
            'mesas' => true,
            'pedidos_online' => true,
            'reservas_qr' => true,
            'cardapio_digital' => true,
            'fluxo_garcom' => true,
            'fluxo_cozinha' => true,
            'telas_bar_cozinha' => true,

            // Categorias (NOVO)
            'subcategorias' => true,

            // Relatórios
            'relatorio_diario' => true,
            'relatorios_avancados' => true,
            'estatisticas_produtos' => true,
            'relatorios_inteligentes' => true,
            'relatorio_personalizado' => true,
            'relatorio_financeiro' => true,
            'export_pdf' => true,
            'export_excel' => true,
            'export_csv' => true,
            'exportacao_relatorios' => true,

            // Dashboard / IA (EMP ⊇ PRO: inclui diferenciais inteligentes)
            'dashboard_executivo' => true,
            'comparativo_filiais' => true,
            'dashboard_inteligente' => true,
            'analise_abc_vendas' => true,
            'recomendacoes_automaticas' => true,
            'previsao_demanda' => true,

            // Clientes
            'clientes' => true,
            'historico_clientes' => true,
            'gestao_funcionarios' => true,

            // Impressão
            'impressao_recibos' => true,
            'logo_recibo' => true,

            // Backup
            'backup_manual' => true,
            'backup_automatico' => true,
            'backup_diario' => true,
            'backup_hora' => true,
            'download_banco' => true,
            'recuperacao_automatica' => true,

            // Multi-filial
            'multi_filial' => true,

            // Integrações (API permanece desligada por política actual)
            'api_integracao' => false,
            'notificacoes' => true,
            'reservas_notificacao_email' => true,

            // Suporte
            'suporte_email' => true,
            'suporte_whatsapp' => true,
            'suporte_24h' => true,
            'suporte_prioritario' => true,
        ]
    ]
];

/**
 * Função para verificar se uma funcionalidade está disponível no plano
 */
if (!function_exists('plano_tem_funcionalidade')) {
    function plano_tem_funcionalidade($plano, $funcionalidade)
    {
        $planos = require __DIR__ . '/planos.php';

        $plano = strtoupper($plano);

        if (!isset($planos[$plano])) {
            return false;
        }

        return $planos[$plano]['funcionalidades'][$funcionalidade] ?? false;
    }
}

/**
 * Função para obter os limites do plano
 */
if (!function_exists('plano_get_limites')) {
    function plano_get_limites($plano)
    {
        $planos = require __DIR__ . '/planos.php';

        $plano = strtoupper($plano);

        if (!isset($planos[$plano])) {
            return $planos['BASICO']['limites'];
        }

        return $planos[$plano]['limites'];
    }
}

/**
 * Função para verificar limite de produtos
 */
if (!function_exists('plano_pode_cadastrar_produto')) {
    function plano_pode_cadastrar_produto($plano, $qtd_atual = 0)
    {
        $limites = plano_get_limites($plano);

        if ($limites['produtos'] == -1) {
            return ['permitido' => true, 'remaining' => -1];
        }

        $remaining = $limites['produtos'] - $qtd_atual;

        return [
            'permitido' => $remaining > 0,
            'remaining' => $remaining,
            'limite' => $limites['produtos']
        ];
    }
}

/**
 * Função para verificar limite de usuários
 */
if (!function_exists('plano_pode_cadastrar_usuario')) {
    function plano_pode_cadastrar_usuario($plano, $qtd_atual = 0)
    {
        $limites = plano_get_limites($plano);

        if ($limites['usuarios'] == -1) {
            return ['permitido' => true, 'remaining' => -1];
        }

        $remaining = $limites['usuarios'] - $qtd_atual;

        return [
            'permitido' => $remaining > 0,
            'remaining' => $remaining,
            'limite' => $limites['usuarios']
        ];
    }
}

/**
 * Função para obter a lista resumida de recursos exibida no catálogo e dashboard
 */
if (!function_exists('plano_get_recursos_catalogo')) {
    function plano_get_recursos_catalogo($plano)
    {
        $planos = require __DIR__ . '/planos.php';
        $plano = strtoupper((string)$plano);
        $config = $planos[$plano] ?? $planos['BASICO'];
        $limites = $config['limites'] ?? [];
        $funcionalidades = $config['funcionalidades'] ?? [];

        $recursos = [];
        $recursos[] = (($limites['produtos'] ?? 0) == -1) ? 'Produtos ilimitados' : 'Até ' . ($limites['produtos'] ?? 0) . ' produtos';
        $recursos[] = (($limites['usuarios'] ?? 0) == -1) ? 'Usuários ilimitados' : 'Até ' . ($limites['usuarios'] ?? 0) . ' usuários';
        $recursos[] = (($limites['mesas'] ?? 0) == -1) ? 'Mesas ilimitadas' : 'Até ' . ($limites['mesas'] ?? 0) . ' mesas';
        $recursos[] = 'Balcão / Take-away automático';

        if (!empty($funcionalidades['caixa'])) {
            $recursos[] = 'Caixa (abrir/fechar)';
        }

        if (!empty($funcionalidades['contas_abertas'])) {
            $recursos[] = 'Contas abertas';
        }

        if (!empty($funcionalidades['garcons'])) {
            $recursos[] = 'Mapa de mesas e garçons';
        }

        if (!empty($funcionalidades['pedidos_online'])) {
            $recursos[] = 'Pedidos online (QR Code)';
        }

        if (!empty($funcionalidades['telas_bar_cozinha'])) {
            $recursos[] = 'Telas Bar e Cozinha (KDS)';
        }

        if (!empty($funcionalidades['gestao_funcionarios'])) {
            $recursos[] = 'Gestão de funcionários e turnos';
        }

        if (!empty($funcionalidades['historico_clientes'])) {
            $recursos[] = 'Histórico de clientes';
        }

        if (!empty($funcionalidades['relatorios_inteligentes'])) {
            $recursos[] = 'Relatórios inteligentes';
        } elseif (!empty($funcionalidades['relatorios_avancados'])) {
            $recursos[] = 'Relatórios avançados e exportação';
        } elseif (!empty($funcionalidades['relatorio_diario'])) {
            $recursos[] = 'Relatório diário';
        }

        if (!empty($funcionalidades['estoque_avancado'])) {
            $recursos[] = 'Ficha técnica e estoque inteligente (baixa automática)';
        }

        if (!empty($funcionalidades['transferencia_estoque'])) {
            $recursos[] = 'Transferência de estoque entre filiais';
        }

        if (!empty($funcionalidades['reservas_qr'])) {
            $recursos[] = 'Reservas com QR Code';
        }

        if (!empty($funcionalidades['dashboard_executivo'])) {
            $recursos[] = 'Dashboard executivo';
        }

        if (!empty($funcionalidades['comparativo_filiais'])) {
            $recursos[] = 'Comparativo entre filiais';
        }

        if (!empty($funcionalidades['multi_filial'])) {
            $limiteFiliais = (int)($limites['filiais'] ?? 0);
            $recursos[] = $limiteFiliais > 0
                ? 'Multi-filial (até ' . $limiteFiliais . ')'
                : 'Multi-filial';
        }

        if (!empty($funcionalidades['backup_automatico'])) {
            $recursos[] = 'Backup automático';
        } elseif (!empty($funcionalidades['backup_diario'])) {
            $recursos[] = 'Backup diário';
        }

        if (!empty($funcionalidades['dashboard_inteligente'])
            || !empty($funcionalidades['analise_abc_vendas'])
            || !empty($funcionalidades['previsao_demanda'])) {
            $recursos[] = 'Análises inteligentes (IA)';
        }

        if (!empty($funcionalidades['suporte_24h'])) {
            $recursos[] = 'Suporte prioritário 24/7';
        } elseif (!empty($funcionalidades['suporte_whatsapp'])) {
            $recursos[] = 'Suporte via WhatsApp';
        } elseif (!empty($funcionalidades['suporte_email'])) {
            $recursos[] = 'Suporte por email';
        }

        return array_values(array_unique($recursos));
    }
}

return $planos;
