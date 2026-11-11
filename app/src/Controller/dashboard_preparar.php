<?php
/**
 * Preparacao do dashboard: autenticacao do contexto, plano, permissoes e dados.
 * Corre no ambito da pagina src/public/dashboard.php (as variaveis definidas aqui
 * sao as que as vistas em app/views/dashboard usam). As consultas estao no DashboardService.
 */
// Instanciar classes
$venda   = new Venda($db);
$produto = new Produto($db);
$caixa   = new Caixa($db);
$mesa    = new Mesa($db);

// Verificar se é Super Admin
$isSuperAdmin = isset($_SESSION['super_admin']) && $_SESSION['super_admin'] == 1;

// Se for Super Admin, verificar se selecionou um restaurante
if ($isSuperAdmin) {
    if (isset($_POST['selecionar_restaurante']) && isset($_POST['restaurante_id'])) {
        $_SESSION['restaurante_selecionado'] = intval($_POST['restaurante_id']);
        $_SESSION['restaurante_id'] = $_SESSION['restaurante_selecionado'];
        $_SESSION['restaurante_base_id'] = $_SESSION['restaurante_selecionado'];
        unset($_SESSION['matriz_id'], $_SESSION['filial_id']);
        header("Location: dashboard.php");
        exit;
    }

    if (isset($_SESSION['restaurante_selecionado']) && $_SESSION['restaurante_selecionado'] > 0) {
        $_SESSION['restaurante_id'] = $_SESSION['restaurante_selecionado'];
        $_SESSION['restaurante_base_id'] = $_SESSION['restaurante_selecionado'];
    }
}

// Se for Super Admin, verificar se selecionou um restaurante primeiro
if ($isSuperAdmin) {
    if (!isset($_SESSION['restaurante_selecionado']) || $_SESSION['restaurante_selecionado'] == 0) {
        header("Location: admin.php");
        exit;
    }
    // Usar o restaurante selecionado
    $_SESSION['restaurante_id'] = $_SESSION['restaurante_selecionado'];
    $_SESSION['restaurante_base_id'] = $_SESSION['restaurante_selecionado'];
}

$rid = session_restaurante_contexto_id();
$planoRid = session_restaurante_capability_id();
$authRid = session_restaurante_auth_id();
$featureRid = $planoRid > 0 ? $planoRid : $rid;
$temPedidosOnline = $featureRid > 0 && plano_tem_funcionalidade_db($featureRid, 'pedidos_online');
$temCaixa = $featureRid > 0 && plano_tem_funcionalidade_db($featureRid, 'caixa');
$temContasAbertas = $featureRid > 0 && plano_tem_funcionalidade_db($featureRid, 'contas_abertas');
$temGarcons = $featureRid > 0 && plano_tem_funcionalidade_db($featureRid, 'garcons');
$temRelatorios = $featureRid > 0 && plano_tem_funcionalidade_db($featureRid, 'relatorios_avancados');
$temGestaoFuncionarios = $featureRid > 0 && plano_tem_funcionalidade_db($featureRid, 'gestao_funcionarios');
$temEstoqueAvancado = $featureRid > 0 && plano_tem_funcionalidade_db($featureRid, 'estoque_avancado');
$temDashboardExecutivo = $featureRid > 0 && plano_tem_funcionalidade_db($featureRid, 'dashboard_executivo');
$temMultiFilial = $featureRid > 0 && plano_tem_funcionalidade_db($featureRid, 'multi_filial');
$temRelatoriosInteligentes = $featureRid > 0 && plano_tem_funcionalidade_db($featureRid, 'relatorios_inteligentes');
$temHistoricoClientes = $featureRid > 0 && plano_tem_funcionalidade_db($featureRid, 'historico_clientes');

// Dados do plano atual e flag para mostrar estoque avançado apenas quando o plano NÃO for BASICO
$dados_plano = plano_get_dados($planoRid);
$plano_atual = plano_normalizar_nome($dados_plano['plano_nome'] ?? 'BASICO');
$showEstoqueAvancado = $temEstoqueAvancado && $plano_atual !== 'BASICO';
$temSuporte24h = $featureRid > 0 && plano_tem_funcionalidade_db($featureRid, 'suporte_24h');
$temSuporteWhatsapp = $featureRid > 0 && plano_tem_funcionalidade_db($featureRid, 'suporte_whatsapp');
$temSuporteEmail = $featureRid > 0 && plano_tem_funcionalidade_db($featureRid, 'suporte_email');
$canalSuporteContato = $temSuporte24h ? 'DASHBOARD' : ($temSuporteWhatsapp ? 'WHATSAPP' : ($temSuporteEmail ? 'EMAIL' : ''));
$podeGerirPlanos = plano_upgrade_usuario_pode_gerir($db, (int)($_SESSION['usuario_id'] ?? 0), plano_upgrade_restaurante_alvo_id($db));
$csrfTicketToken = csrf_get_token();

$perfil = strtoupper((string)($_SESSION['perfil'] ?? 'USER'));
if ($perfil === 'GARÇOM' || $perfil === 'GARÃ‡OM') {
    $perfil = 'GARCOM';
}

$resumoExecutivoRede = null;
if ($perfil === 'ADMIN' && $temDashboardExecutivo && $temMultiFilial && $db instanceof PDO) {
    extract($dashboardService->resumoRede(array_intersect_key(get_defined_vars(), array_flip(DashboardService::ENTRADAS_RESUMO_REDE))));
}

// Se não tem restaurante_id válido (para usuários normais), redirecionar
if ($rid <= 0) {
    header("Location: index.php?erro=sem_restaurante");
    exit;
}

$alertaFuncionalidadeBloqueada = isset($_GET['erro']) && (string)$_GET['erro'] === 'funcionalidade_bloqueada';
$funcionalidadeBloqueadaChave = isset($_GET['funcionalidade']) ? trim((string)$_GET['funcionalidade']) : '';
$rotulosFuncionalidadeBloqueada = [
    'caixa' => 'o módulo de Caixa',
    'contas_abertas' => 'Contas Abertas',
    'garcons' => 'o Mapa de Mesas',
    'pedidos_online' => 'a gestão de pedidos (tela de pedidos)',
    'gestao_funcionarios' => 'Funcionários e Turnos',
    'relatorios' => 'os Relatórios',
    'relatorios_avancados' => 'os Relatórios avançados',
    'multi_filial' => 'a gestão de filiais',
    'estoque_avancado' => 'o Estoque Inteligente com ficha técnica (disponível nos planos Profissional e Empresarial)',
];
$rotuloFuncionalidadeBloqueada = $rotulosFuncionalidadeBloqueada[$funcionalidadeBloqueadaChave] ?? 'esta funcionalidade';

$perfil = strtoupper($_SESSION['perfil'] ?? 'USER');
if ($perfil === 'GARÇOM') $perfil = 'GARCOM';

// Sincronizar foto do usuário logado para garantir exibição correta no dashboard
if (!empty($_SESSION['usuario_id'])) {
    $stmt_user_foto = $db->prepare("SELECT foto, nome, perfil FROM usuarios WHERE id = ? AND restaurante_id = ? LIMIT 1");
    $stmt_user_foto->execute([intval($_SESSION['usuario_id']), $authRid]);
    $dados_usuario_sessao = $stmt_user_foto->fetch(PDO::FETCH_ASSOC);
    if ($dados_usuario_sessao) {
        $_SESSION['foto'] = $dados_usuario_sessao['foto'] ?? '';
        $_SESSION['nome'] = $dados_usuario_sessao['nome'] ?? ($_SESSION['nome'] ?? 'Usuário');
        $_SESSION['perfil'] = $dados_usuario_sessao['perfil'] ?? ($_SESSION['perfil'] ?? 'USER');
        $perfil = strtoupper($_SESSION['perfil'] ?? 'USER');
        if ($perfil === 'GARÇOM') $perfil = 'GARCOM';
    }
}

$dashboardRestauranteNome = 'seu restaurante';
try {
    $stmtDashboardRestaurante = $db->prepare("SELECT nome FROM restaurantes WHERE id = :id LIMIT 1");
    $stmtDashboardRestaurante->execute([':id' => (int)$rid]);
    $nomeRestauranteDb = trim((string)($stmtDashboardRestaurante->fetchColumn() ?: ''));
    if ($nomeRestauranteDb !== '') {
        $dashboardRestauranteNome = $nomeRestauranteDb;
    }
} catch (Throwable $e) {
    // Mantém fallback sem impedir o carregamento do dashboard.
}

$dashboardNomeCompleto = trim((string)($_SESSION['nome'] ?? ''));
$dashboardPrimeiroNome = trim((string)(preg_split('/\s+/', $dashboardNomeCompleto)[0] ?? ''));
$dashboardNomeNormalizado = function_exists('mb_strtolower')
    ? mb_strtolower($dashboardPrimeiroNome, 'UTF-8')
    : strtolower($dashboardPrimeiroNome);
$dashboardNomesGenericos = ['admin', 'administrador', 'administradora', 'usuario', 'usuário', 'user'];
$dashboardHoraAtual = (int)date('G');
$dashboardPeriodo = $dashboardHoraAtual < 12
    ? 'Bom dia'
    : ($dashboardHoraAtual < 18 ? 'Boa tarde' : 'Boa noite');
$dashboardDestinatario = in_array($dashboardNomeNormalizado, $dashboardNomesGenericos, true) || $dashboardPrimeiroNome === ''
    ? $dashboardRestauranteNome
    : $dashboardPrimeiroNome;
$dashboardSaudacao = $dashboardPeriodo . ', ' . $dashboardDestinatario;
$dashboardContextoPlano = $plano_atual === 'EMPRESARIAL'
    ? 'Visão executiva de ' . $dashboardRestauranteNome . ': operação, reservas, rede e receita num só lugar.'
    : ($plano_atual === 'PROFISSIONAL'
        ? 'Gestão de ' . $dashboardRestauranteNome . ': pedidos, equipa, reservas e desempenho em tempo real.'
        : 'Operação de ' . $dashboardRestauranteNome . ': acompanhe vendas, caixa, estoque e atendimento.');

$dashboardUsuarioGenerico = in_array($dashboardNomeNormalizado, $dashboardNomesGenericos, true) || $dashboardPrimeiroNome === '';
$dashboardPlanoLabel = ucfirst(strtolower((string)$plano_atual));
if ($plano_atual === 'EMPRESARIAL') {
    $dashboardSaudacao = $dashboardUsuarioGenerico
        ? $dashboardPeriodo . ', direcao do ' . $dashboardRestauranteNome
        : $dashboardPeriodo . ', ' . $dashboardPrimeiroNome;
    $dashboardContextoPlano = 'Painel Empresarial: receita, rede, reservas, stock e decisoes executivas do ' . $dashboardRestauranteNome . '.';
} elseif ($plano_atual === 'PROFISSIONAL') {
    $dashboardSaudacao = $dashboardUsuarioGenerico
        ? $dashboardPeriodo . ', equipa de gestao do ' . $dashboardRestauranteNome
        : $dashboardPeriodo . ', ' . $dashboardPrimeiroNome;
    $dashboardContextoPlano = 'Painel Profissional: pedidos, mesas, equipa, caixa e desempenho operacional do ' . $dashboardRestauranteNome . '.';
} else {
    $dashboardSaudacao = $dashboardUsuarioGenerico
        ? $dashboardPeriodo . ', operacao do ' . $dashboardRestauranteNome
        : $dashboardPeriodo . ', ' . $dashboardPrimeiroNome;
    $dashboardContextoPlano = 'Painel ' . $dashboardPlanoLabel . ': vendas, caixa, mesas e tarefas essenciais do ' . $dashboardRestauranteNome . '.';
}

$perfil_lower = strtolower($perfil);
$usuario_logado_id = intval($_SESSION['usuario_id'] ?? 0);

// O backup NAO corre ao abrir o dashboard: em bases grandes bloqueava a pagina.
// Aqui so se verifica se ha backup em atraso, para mostrar o aviso com o botao "Executar agora".
$backupEmAtrasoDashboard = false;
if ($perfil === 'ADMIN' && $planoRid > 0 && $db instanceof PDO) {
    try {
        $temBackupAutoDash = plano_tem_funcionalidade_db($planoRid, 'backup_automatico')
            || plano_tem_funcionalidade_db($planoRid, 'backup_diario')
            || plano_tem_funcionalidade_db($planoRid, 'backup_hora');
        if ($temBackupAutoDash && function_exists('backup_obter_configuracao') && function_exists('backup_esta_pendente')) {
            $cfgBackupDash = backup_obter_configuracao($db, $planoRid);
            $backupEmAtrasoDashboard = !empty($cfgBackupDash['automatico']) && backup_esta_pendente($cfgBackupDash);
        }
    } catch (Throwable $e) {
        error_log('[BACKUP][DASH_AVISO] ' . $e->getMessage());
    }
}

// --- Dados do dashboard ---
$total_hoje = $venda->vendasHoje($rid);
$qtd_hoje   = $venda->contarVendasHoje($rid);
$caixa_aberto = $caixa->buscarAberto($rid);
$total_produtos = $produto->contarAtivos($rid);

$stmt_mesas = $mesa->listar($rid);
$todas_mesas = $stmt_mesas->fetchAll(PDO::FETCH_ASSOC);
$mesas_ocupadas = 0;
$mesas_dashboard = [];
$mesas_dashboard_ids = [];
foreach ($todas_mesas as $m) {
    $mesaId = (int)($m['id'] ?? 0);
    if ($mesaId <= 0 || isset($mesas_dashboard_ids[$mesaId])) {
        continue;
    }
    // Balcao/take-away nao entra na ocupacao do salao, tal como em mesas.php.
    if (function_exists('mesa_eh_balcao') && mesa_eh_balcao($m['numero'] ?? '')) {
        continue;
    }
    $mesas_dashboard_ids[$mesaId] = true;
    $mesas_dashboard[] = $m;
    if (($m['status'] ?? '') === 'OCUPADA') {
        $mesas_ocupadas++;
    }
}
$total_mesas = count($mesas_dashboard);

$stmt_estoque = $produto->estoqueBaixo($rid);
$estoque_baixo = $stmt_estoque->fetchAll(PDO::FETCH_ASSOC);
$estoqueCardTitulo = 'Estoque Baixo';
$estoqueCardLink = 'produtos.php';
$estoqueCardLinkLabel = 'Ver Produtos';
$estoqueSimplesResumo = [
    'zerados' => 0,
    'baixo' => 0,
    'ok' => 0,
    'total' => 0,
];
if (!$showEstoqueAvancado && $db instanceof PDO && (int)$rid > 0) {
    extract($dashboardService->estoqueSimples(array_intersect_key(get_defined_vars(), array_flip(DashboardService::ENTRADAS_ESTOQUE_SIMPLES))));
}
$estoque_resumo = [
    'total_insumos' => 0,
    'ok' => 0,
    'critico' => 0,
    'alerta' => 0,
    'atencao' => 0,
    'cobertura_critica' => 0,
    'validade_7d' => 0,
    'perdas_30d_regs' => 0,
    'perdas_30d_custo' => 0.0,
    'alertas_abertos' => 0,
    'tem_insumos' => false,
];
$estoque_previsao_map = [];
$estoque_alertas_feed = [];

if ($showEstoqueAvancado) {
    extract($dashboardService->estoqueAvancado(array_intersect_key(get_defined_vars(), array_flip(DashboardService::ENTRADAS_ESTOQUE_AVANCADO))));
}

// Insights EMP (score / cobertura) — só plano Empresarial, reutiliza previsão já carregada.
$dashEmpInsights = null;
if ($plano_atual === 'EMPRESARIAL' && $showEstoqueAvancado && function_exists('estoque_insights_empresariais') && $db instanceof PDO) {
    extract($dashboardService->estoqueInsightsEmpresarial(array_intersect_key(get_defined_vars(), array_flip(DashboardService::ENTRADAS_ESTOQUE_INSIGHTS_EMPRESARIAL))));
}

// Notas humanas de estoque EMP
$dashEmpStockNotas = [];
if ($plano_atual === 'EMPRESARIAL' && $showEstoqueAvancado) {
    extract($dashboardService->estoqueNotasEmpresarial(array_intersect_key(get_defined_vars(), array_flip(DashboardService::ENTRADAS_ESTOQUE_NOTAS_EMPRESARIAL))));
}

$stmt_ultimas = $venda->ultimasVendas($rid, 8);
$ultimas_vendas = $stmt_ultimas->fetchAll(PDO::FETCH_ASSOC);

$stmt_semana = $venda->vendasPorDia($rid, 7);
$vendas_semana = $stmt_semana->fetchAll(PDO::FETCH_ASSOC);

$data_fim_plano = $dados_plano['data_fim'] ?? date('Y-m-d');
// Dias de calendário até à validade (igual a Configurações / plano_lifecycle) — evita floor/ceil a divergir.
try {
    $hojePlano = new DateTimeImmutable('today');
    $fimPlano = new DateTimeImmutable(date('Y-m-d', strtotime((string)$data_fim_plano)));
    $dias_restantes = (int)$hojePlano->diff($fimPlano)->format('%r%a');
} catch (Throwable $e) {
    $dias_restantes = 0;
}
// CTA de upgrade/gerir plano: só nos últimos 7 dias (ou já expirado)
$mostrarBotaoUpgradePlano = $podeGerirPlanos && (int)$dias_restantes <= 7;
$recursos_plano_atual = plano_get_resumo_recursos($plano_atual);
if ($plano_atual === 'BASICO' && empty($recursos_plano_atual)) {
    $recursos_plano_atual = [
        'Ate 150 produtos',
        'Ate 6 usuarios',
        'Ate 30 mesas',
        'Ate 1 balcao',
        'Caixa (abrir/fechar)',
        'Relatorio diario',
        'Suporte por email',
    ];
}

$isGerenteDashboard = ($perfil === 'GERENTE');
$isAdminDashboard = !in_array($perfil, ['GARCOM', 'CAIXA', 'GERENTE'], true);
$grafico_min_pontos = $isAdminDashboard ? 14 : 7;
$dados_grafico = $vendas_semana;
$garcom_dashboard = [
    'total_pedidos' => 0,
    'mesas_total' => 0,
    'mesas_ocupadas' => 0,
    'valor_pedidos' => 0.0,
    'pendentes' => 0,
    'preparados' => 0,
    'entregues' => 0,
];
$caixa_dashboard = [
    'contas_abertas' => 0,
    'total_aberto' => 0.0,
    'caixa_aberto' => (bool)$caixa_aberto,
    'total_turno' => 0.0,
];
$dashboard_reservas = [
    'total_ativas' => 0,
    'pendentes' => 0,
    'confirmadas' => 0,
    'ultima_pendente' => null,
];

extract($dashboardService->reservasHoje(array_intersect_key(get_defined_vars(), array_flip(DashboardService::ENTRADAS_RESERVAS_HOJE))));

if (!$isAdminDashboard && $usuario_logado_id > 0) {
    extract($dashboardService->dadosOperacionais(array_intersect_key(get_defined_vars(), array_flip(DashboardService::ENTRADAS_DADOS_OPERACIONAIS))));
}

$receita_mes_atual = 0.0;
$receita_mes_anterior = 0.0;
$crescimento_mensal_pct = 0.0;
$top_garcom = null;
$top_caixa = null;
$pedidos_resumo = [
    'pendentes' => 0,
    'preparando' => 0,
    'prontos' => 0,
    'entregues' => 0,
];
$estoque_critico = (int)($estoque_resumo['critico'] ?? 0);
$estoque_alerta = (int)($estoque_resumo['alerta'] ?? 0);
if (!$showEstoqueAvancado) {
$estoque_critico = 0;
$estoque_alerta = 0;
    foreach ($estoque_baixo as $itemEstoque) {
        $estoqueAtual = (float)($itemEstoque['estoque'] ?? 0);
        $estoqueMinimo = (float)($itemEstoque['estoque_minimo'] ?? 0);
        if ($estoqueAtual <= 0) {
            $estoque_critico++;
        }
        if ($estoqueAtual <= $estoqueMinimo) {
            $estoque_alerta++;
        }
    }
}
$sugestao_upgrade = [
    'mostrar' => false,
    'plano' => '',
    'mensagem' => 'Seu plano atual atende bem a operação de hoje.',
];
$team_online = [
    'online' => 0,
    'total' => 0,
    'equipa' => [],
];
$by_canal = ['ONLINE' => 0, 'BALCAO' => 0, 'GARCOM' => 0, 'TELEFONE' => 0, 'OUTRO' => 0];
$by_canal_hoje = ['ONLINE' => 0, 'BALCAO' => 0, 'GARCOM' => 0, 'TELEFONE' => 0, 'OUTRO' => 0];
$pedidos_hoje_total = 0;
$oldest_pending_orders = [];
$oldest_pending_minutes = null;
$team_performance = [];

$receita_14_dias_total = 0.0;
$media_diaria_14d = 0.0;
$pico_14d = 0.0;
$pico_14d_data = '';
$tendencia_delta_pct = 0.0;
$dashEmpReceita = null;
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
if (!empty($dados_grafico) && !$isAdminDashboard) {
    foreach ($dados_grafico as $itemGrafico) {
        $receita_14_dias_total += floatval($itemGrafico['total'] ?? 0);
    }
    $media_diaria_14d = $receita_14_dias_total / max(1, count($dados_grafico));
}

if ($isAdminDashboard) {
    extract($dashboardService->dadosAdmin(array_intersect_key(get_defined_vars(), array_flip(DashboardService::ENTRADAS_DADOS_ADMIN))));
}

// Dados operacionais do gerente (sem analytics de dono/admin).
if ($isGerenteDashboard) {
    extract($dashboardService->dadosGerente(array_intersect_key(get_defined_vars(), array_flip(DashboardService::ENTRADAS_DADOS_GERENTE))));
}

include_once DASHBOARD_PUBLIC_DIR . '/includes/empresarial_ui.php';

// ---------------------------------------------------------------------------
// Empresarial (administrador): comparacoes, meta do dia, avisos e filiais hoje.
// ---------------------------------------------------------------------------
$dashEmpComparar = null;
$dashEmpMeta = null;
$dashEmpAvisos = [];
$dashEmpFiliaisHoje = [];
$dashEmpPeriodo = null;

if ($isAdminDashboard && ($plano_atual ?? '') === 'EMPRESARIAL' && $db instanceof PDO && $rid > 0) {
    $horaAgora = date('H:i:s');

    // 7) Mesmo periodo de ontem e do mesmo dia da semana passada (ate esta hora).
    $compararVendas = static function (PDO $db, int $rid, string $dia, string $horaAgora): array {
        $stmt = $db->prepare("
            SELECT COUNT(*) AS qtd, COALESCE(SUM(total_final), 0) AS total
            FROM vendas
            WHERE restaurante_id = :rid
              AND UPPER(TRIM(COALESCE(status, ''))) = 'PAGO'
              AND criado_em >= :ini AND criado_em <= :fim
        ");
        $stmt->execute([
            ':rid' => $rid,
            ':ini' => $dia . ' 00:00:00',
            ':fim' => $dia . ' ' . $horaAgora,
        ]);
        $linha = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['qtd' => (int)($linha['qtd'] ?? 0), 'total' => (float)($linha['total'] ?? 0)];
    };

    try {
        $ontem = $compararVendas($db, (int)$rid, date('Y-m-d', strtotime('-1 day')), $horaAgora);
        $semana = $compararVendas($db, (int)$rid, date('Y-m-d', strtotime('-7 days')), $horaAgora);
        $hojeTotal = (float)($total_hoje ?? 0);
        $variacao = static fn(float $atual, float $antes): ?float => $antes > 0 ? (($atual - $antes) / $antes) * 100 : null;
        $dashEmpComparar = [
            'hora' => date('H:i'),
            'ontem' => $ontem,
            'semana' => $semana,
            'delta_ontem' => $variacao($hojeTotal, $ontem['total']),
            'delta_semana' => $variacao($hojeTotal, $semana['total']),
        ];
    } catch (Throwable $e) {
        error_log('[DASH][COMPARAR] ' . $e->getMessage());
    }

    // 8) Meta do dia: guardada em config (chave meta_vendas_dia). 0 = sem meta.
    try {
        $stMeta = $db->prepare("SELECT valor FROM config WHERE restaurante_id = ? AND chave = 'meta_vendas_dia' LIMIT 1");
        $stMeta->execute([(int)$rid]);
        $metaValor = (float)($stMeta->fetchColumn() ?: 0);
        $metaHoje = (float)($total_hoje ?? 0);
        $sugerida = 0.0;
        if ($metaValor <= 0) {
            // Sugestao: media do mesmo dia da semana nas ultimas 4 semanas.
            $stSug = $db->prepare("
                SELECT COALESCE(AVG(t), 0) FROM (
                    SELECT DATE(criado_em) AS d, SUM(total_final) AS t
                    FROM vendas
                    WHERE restaurante_id = :rid
                      AND UPPER(TRIM(COALESCE(status, ''))) = 'PAGO'
                      AND criado_em >= DATE_SUB(CURDATE(), INTERVAL 28 DAY)
                      AND criado_em < CURDATE()
                      AND DAYOFWEEK(criado_em) = DAYOFWEEK(CURDATE())
                    GROUP BY DATE(criado_em)
                ) x
            ");
            $stSug->execute([':rid' => (int)$rid]);
            $sugerida = (float)$stSug->fetchColumn();
        }
        $dashEmpMeta = [
            'valor' => $metaValor,
            'sugerida' => $sugerida,
            'feito' => $metaHoje,
            'pct' => $metaValor > 0 ? min(999, ($metaHoje / $metaValor) * 100) : 0.0,
            'falta' => max(0, $metaValor - $metaHoje),
        ];
    } catch (Throwable $e) {
        error_log('[DASH][META] ' . $e->getMessage());
    }

    // 11) Periodo escolhido (7/30/90 dias) contra o periodo anterior.
    try {
        $diasPeriodo = (int)($_GET['periodo'] ?? 30);
        if (!in_array($diasPeriodo, [7, 30, 90], true)) {
            $diasPeriodo = 30;
        }
        $stPer = $db->prepare("
            SELECT DATE(criado_em) AS d, COALESCE(SUM(total_final), 0) AS t
            FROM vendas
            WHERE restaurante_id = :rid
              AND UPPER(TRIM(COALESCE(status, ''))) = 'PAGO'
              AND criado_em >= DATE_SUB(CURDATE(), INTERVAL :dias DAY)
            GROUP BY DATE(criado_em)
        ");
        $stPer->bindValue(':rid', (int)$rid, PDO::PARAM_INT);
        $stPer->bindValue(':dias', $diasPeriodo * 2 - 1, PDO::PARAM_INT);
        $stPer->execute();
        $porDiaPer = $stPer->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        $serieAtual = [];
        $serieAnterior = [];
        for ($iPer = $diasPeriodo - 1; $iPer >= 0; $iPer--) {
            $dAtual = date('Y-m-d', strtotime('-' . $iPer . ' days'));
            $dAnt = date('Y-m-d', strtotime('-' . ($iPer + $diasPeriodo) . ' days'));
            $serieAtual[] = ['d' => $dAtual, 'v' => (float)($porDiaPer[$dAtual] ?? 0)];
            $serieAnterior[] = ['d' => $dAnt, 'v' => (float)($porDiaPer[$dAnt] ?? 0)];
        }
        $totAtual = array_sum(array_column($serieAtual, 'v'));
        $totAnterior = array_sum(array_column($serieAnterior, 'v'));
        $dashEmpPeriodo = [
            'dias' => $diasPeriodo,
            'atual' => $serieAtual,
            'anterior' => $serieAnterior,
            'total_atual' => $totAtual,
            'total_anterior' => $totAnterior,
            'delta' => $totAnterior > 0 ? (($totAtual - $totAnterior) / $totAnterior) * 100 : null,
        ];
    } catch (Throwable $e) {
        error_log('[DASH][PERIODO] ' . $e->getMessage());
    }

    // 9) Centro de avisos: o que precisa de decisao hoje.
    if (empty($caixa_aberto)) {
        $dashEmpAvisos[] = [
            'tom' => 'warn',
            'icone' => 'fa-cash-register',
            'titulo' => 'Caixa fechado',
            'texto' => 'Sem caixa aberto não é possível cobrar vendas.',
            'url' => 'caixa.php',
            'botao' => 'Abrir caixa',
        ];
    }
    if (!empty($backupEmAtrasoDashboard)) {
        $dashEmpAvisos[] = [
            'tom' => 'warn',
            'icone' => 'fa-database',
            'titulo' => 'Backup automático em atraso',
            'texto' => 'A tarefa agendada pode não estar a correr neste computador.',
            'url' => 'backup.php',
            'botao' => 'Ver backup',
        ];
    }
    try {
        $stTurnos = $db->prepare("SELECT COUNT(*) FROM funcionarios_turnos
            WHERE restaurante_id = ? AND UPPER(status) = 'ATIVO' AND data < CURDATE()");
        $stTurnos->execute([(int)$rid]);
        $turnosEsquecidos = (int)$stTurnos->fetchColumn();
        if ($turnosEsquecidos > 0) {
            $dashEmpAvisos[] = [
                'tom' => 'warn',
                'icone' => 'fa-user-clock',
                'titulo' => $turnosEsquecidos . ' turno(s) de dias anteriores por encerrar',
                'texto' => 'Distorce a presença, as horas e a auditoria da unidade.',
                'url' => 'funcionarios_turnos.php',
                'botao' => 'Encerrar turnos',
            ];
        }
    } catch (Throwable $e) {
    }
    $criticoAviso = (int)($estoque_resumo['critico'] ?? 0);
    if ($criticoAviso > 0) {
        $dashEmpAvisos[] = [
            'tom' => 'grave',
            'icone' => 'fa-boxes-stacked',
            'titulo' => $criticoAviso . ' insumo(s) em nível crítico',
            'texto' => 'Reponha ou transfira de outra unidade antes de faltar no serviço.',
            'url' => 'estoque_inteligente.php',
            'botao' => 'Ver stock',
        ];
    }
    try {
        $stContas = $db->prepare("SELECT COUNT(*) FROM pedidos
            WHERE restaurante_id = ?
              AND status IN ('CONTA', 'CONTA_SOLICITADA', 'ENTREGUE')
              AND criado_em < DATE_SUB(NOW(), INTERVAL 2 HOUR)");
        $stContas->execute([(int)$rid]);
        $contasVelhas = (int)$stContas->fetchColumn();
        if ($contasVelhas > 0) {
            $dashEmpAvisos[] = [
                'tom' => 'info',
                'icone' => 'fa-receipt',
                'titulo' => $contasVelhas . ' conta(s) aberta(s) há mais de 2 horas',
                'texto' => 'Confirme se já foram pagas ou se ficaram esquecidas no salão.',
                'url' => 'caixa_mesas.php',
                'botao' => 'Ver contas',
            ];
        }
    } catch (Throwable $e) {
    }
    $dashEmpAvisos = array_slice($dashEmpAvisos, 0, 5);

    // 10) Filiais: como está cada unidade hoje.
    try {
        $stFilHoje = $db->prepare("SELECT id, nome, COALESCE(is_matriz, 0) AS is_matriz
            FROM restaurantes
            WHERE (id = :rid1 OR filial_id = :rid2) AND UPPER(COALESCE(status, 'ATIVO')) = 'ATIVO'
            ORDER BY COALESCE(is_matriz, 0) DESC, nome");
        $stFilHoje->execute([':rid1' => (int)$planoRid ?: (int)$rid, ':rid2' => (int)$planoRid ?: (int)$rid]);
        $unidadesHoje = $stFilHoje->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($unidadesHoje) > 1) {
            $idsHoje = array_map(static fn($u) => (int)$u['id'], $unidadesHoje);
            $phHoje = implode(',', array_fill(0, count($idsHoje), '?'));

            $stVendHoje = $db->prepare("
                SELECT restaurante_id, COUNT(*) AS qtd, COALESCE(SUM(total_final), 0) AS total, MAX(criado_em) AS ultima
                FROM vendas
                WHERE restaurante_id IN ($phHoje)
                  AND UPPER(TRIM(COALESCE(status, ''))) = 'PAGO'
                  AND criado_em >= CURDATE()
                GROUP BY restaurante_id
            ");
            $stVendHoje->execute($idsHoje);
            $mapaVendHoje = [];
            foreach ($stVendHoje->fetchAll(PDO::FETCH_ASSOC) as $linhaVH) {
                $mapaVendHoje[(int)$linhaVH['restaurante_id']] = $linhaVH;
            }

            $stCaixaHoje = $db->prepare("SELECT restaurante_id, COUNT(*) AS abertos FROM caixas
                WHERE restaurante_id IN ($phHoje) AND UPPER(status) = 'ABERTO' GROUP BY restaurante_id");
            $stCaixaHoje->execute($idsHoje);
            $mapaCaixaHoje = $stCaixaHoje->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

            foreach ($unidadesHoje as $uHoje) {
                $idU = (int)$uHoje['id'];
                $vH = $mapaVendHoje[$idU] ?? null;
                $dashEmpFiliaisHoje[] = [
                    'id' => $idU,
                    'nome' => (string)$uHoje['nome'],
                    'matriz' => (int)$uHoje['is_matriz'] === 1,
                    'vendas' => (int)($vH['qtd'] ?? 0),
                    'total' => (float)($vH['total'] ?? 0),
                    'ultima' => !empty($vH['ultima']) ? date('H:i', strtotime((string)$vH['ultima'])) : null,
                    'caixa_aberto' => (int)($mapaCaixaHoje[$idU] ?? 0) > 0,
                ];
            }
            usort($dashEmpFiliaisHoje, static fn($a, $b) => $b['total'] <=> $a['total']);
        }
    } catch (Throwable $e) {
        error_log('[DASH][FILIAIS_HOJE] ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Profissional (administrador): versao simples — so ontem, 3 avisos da unidade,
// stock abaixo do minimo e mais vendidos de hoje. Meta, semana passada, rede e
// periodos ficam no Empresarial.
// ---------------------------------------------------------------------------
$dashProDeltaOntem = null;
$dashProTemOntem = false;
$dashProAvisos = [];
$dashProStockBaixo = null;
$dashProTopHoje = [];

if ($isAdminDashboard && ($plano_atual ?? '') === 'PROFISSIONAL' && $db instanceof PDO && $rid > 0) {
    try {
        $stOntemPro = $db->prepare("
            SELECT COALESCE(SUM(total_final), 0) FROM vendas
            WHERE restaurante_id = :rid
              AND UPPER(TRIM(COALESCE(status, ''))) = 'PAGO'
              AND criado_em >= :ini AND criado_em <= :fim
        ");
        $diaOntemPro = date('Y-m-d', strtotime('-1 day'));
        $stOntemPro->execute([':rid' => (int)$rid, ':ini' => $diaOntemPro . ' 00:00:00', ':fim' => $diaOntemPro . ' ' . date('H:i:s')]);
        $totalOntemPro = (float)$stOntemPro->fetchColumn();
        if ($totalOntemPro > 0) {
            $dashProTemOntem = true;
            $dashProDeltaOntem = (((float)($total_hoje ?? 0)) - $totalOntemPro) / $totalOntemPro * 100;
        }
    } catch (Throwable $e) {
        error_log('[DASH][PRO][ONTEM] ' . $e->getMessage());
    }

    if (empty($caixa_aberto)) {
        $dashProAvisos[] = ['icone' => 'fa-cash-register', 'titulo' => 'Caixa fechado', 'texto' => 'Sem caixa aberto não é possível cobrar vendas.', 'url' => 'caixa.php', 'botao' => 'Abrir caixa'];
    }
    try {
        $stTurnosPro = $db->prepare("SELECT COUNT(*) FROM funcionarios_turnos WHERE restaurante_id = ? AND UPPER(status) = 'ATIVO' AND data < CURDATE()");
        $stTurnosPro->execute([(int)$rid]);
        $nTurnosPro = (int)$stTurnosPro->fetchColumn();
        if ($nTurnosPro > 0) {
            $dashProAvisos[] = ['icone' => 'fa-user-clock', 'titulo' => $nTurnosPro . ' turno(s) de dias anteriores por encerrar', 'texto' => 'As horas e a presença ficam erradas enquanto estiverem abertos.', 'url' => 'funcionarios_turnos.php', 'botao' => 'Encerrar turnos'];
        }
        $stContasPro = $db->prepare("SELECT COUNT(*) FROM pedidos WHERE restaurante_id = ? AND status IN ('CONTA', 'CONTA_SOLICITADA', 'ENTREGUE') AND criado_em < DATE_SUB(NOW(), INTERVAL 2 HOUR)");
        $stContasPro->execute([(int)$rid]);
        $nContasPro = (int)$stContasPro->fetchColumn();
        if ($nContasPro > 0) {
            $dashProAvisos[] = ['icone' => 'fa-receipt', 'titulo' => $nContasPro . ' conta(s) aberta(s) há mais de 2 horas', 'texto' => 'Confirme se já foram pagas ou se ficaram esquecidas.', 'url' => 'caixa_mesas.php', 'botao' => 'Ver contas'];
        }
    } catch (Throwable $e) {
        error_log('[DASH][PRO][AVISOS] ' . $e->getMessage());
    }
    $dashProAvisos = array_slice($dashProAvisos, 0, 3);

    try {
        $stStockPro = $db->prepare("
            SELECT COUNT(*) FROM (
                SELECT i.id
                FROM estoque_insumos i
                LEFT JOIN estoque_saldos s ON s.insumo_id = i.id AND s.restaurante_id = i.restaurante_id
                WHERE i.restaurante_id = ? AND i.ativo = 1 AND i.estoque_minimo_base > 0
                GROUP BY i.id, i.estoque_minimo_base
                HAVING COALESCE(SUM(s.saldo_base), 0) <= i.estoque_minimo_base
            ) x
        ");
        $stStockPro->execute([(int)$rid]);
        $dashProStockBaixo = (int)$stStockPro->fetchColumn();
    } catch (Throwable $e) {
        $dashProStockBaixo = null;
    }

    try {
        $stTopPro = $db->prepare("
            SELECT p.nome, SUM(iv.quantidade) AS qtd, SUM(iv.quantidade * iv.preco_unitario) AS valor
            FROM itens_venda iv
            INNER JOIN vendas v ON v.id = iv.venda_id
            INNER JOIN produtos p ON p.id = iv.produto_id
            WHERE v.restaurante_id = ?
              AND UPPER(TRIM(COALESCE(v.status, ''))) = 'PAGO'
              AND v.criado_em >= CURDATE()
            GROUP BY p.id, p.nome
            ORDER BY qtd DESC, valor DESC
            LIMIT 5
        ");
        $stTopPro->execute([(int)$rid]);
        $dashProTopHoje = $stTopPro->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[DASH][PRO][TOP] ' . $e->getMessage());
    }
}
