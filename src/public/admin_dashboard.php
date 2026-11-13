<?php
require_once __DIR__ . '/app_base.php';

/*
 
   PÁGINA DE DASHBOARD - SUPER ADMIN
   Gráficos e análises visuais
 
 */

include_once __DIR__ . '/../../app/src/config/super_admin_check.php';
include_once __DIR__ . '/../../app/src/config/database.php';
include_once __DIR__ . '/../../app/src/config/privacy_requests.php';
include_once __DIR__ . '/../../app/src/config/restaurante_context.php';

requireSuperAdminPermission('view_dashboard');

$database = new Database();
$db = $database->getConnection();

$super_admin_nome = $_SESSION['nome'] ?? 'Super Admin';
$privacy_pending_count = 0;
if (super_admin_has_permission('manage_privacy') && privacy_request_table_exists($db, 'data_subject_requests')) {
    $privacy_pending_count = (int)$db->query("SELECT COUNT(*) FROM data_subject_requests WHERE status IN ('PENDENTE_VERIFICACAO','EM_VERIFICACAO','EM_PROCESSAMENTO')")->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="pt">

<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Nivora Restaurant Admin</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/adminpro-theme.css">
    <link rel="stylesheet" href="css/premium.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        body {
            font-family: 'Poppins', sans-serif;
            overflow-x: hidden;
        }

        .sidebar {
            width: 260px;
            min-height: 100vh;
            background: linear-gradient(135deg, var(--dark) 0%, #1a1a2e 100%);
            position: fixed;
            left: 0;
            top: 0;
            z-index: 1000;
            transition: transform 0.25s ease;
            border-right: 1px solid rgba(255, 255, 255, 0.08);
        }

        .sidebar.sidebar-hidden {
            transform: translateX(-100%);
        }

        .menu-toggle-btn {
            position: fixed;
            left: 16px;
            top: 16px;
            z-index: 2100;
            width: 56px;
            height: 56px;
            border-radius: 16px;
            border: none;
            background: linear-gradient(180deg, #ff9433 0%, #ff7a1a 100%);
            color: #fff;
            font-size: 22px;
            box-shadow: 0 10px 20px rgba(255, 122, 26, 0.26);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all .2s ease;
        }

        .menu-toggle-btn.is-docked {
            left: 202px;
            top: 14px;
            width: 46px;
            height: 46px;
            border-radius: 12px;
            font-size: 18px;
            box-shadow: 0 6px 14px rgba(255, 122, 26, 0.2);
        }

        .sidebar-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.44);
            z-index: 1800;
            display: none;
        }

        .sidebar-backdrop.show {
            display: block;
        }

        .sidebar .brand {
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar .brand h3 {
            color: white;
            font-size: 18px;
            font-weight: 700;
            margin: 0;
        }

        .sidebar .brand span {
            color: var(--secondary);
            font-size: 12px;
        }

        .sidebar .nav-item a {
            color: rgba(255, 255, 255, 0.7);
            margin: 4px 10px;
            padding: 11px 14px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            transition: all 0.3s;
            cursor: pointer;
        }

        .sidebar .nav-item a:hover,
        .sidebar .nav-item a.active {
            background: rgba(108, 92, 231, 0.3);
            color: white;
            transform: translateX(2px);
        }

        .main-content {
            margin-left: 260px;
            padding: 25px;
            width: calc(100% - 260px);
            max-width: calc(100% - 260px);
            flex: 0 0 calc(100% - 260px);
            transition: margin-left .25s ease;
        }

        body.layout-sidebar-collapsed .main-content {
            margin-left: 0;
            width: 100%;
            max-width: 100%;
            flex-basis: 100%;
            padding-left: 92px;
        }

        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.08);
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .kpi-detail {
            margin-top: 4px;
            color: #64748b;
            font-size: 11px;
            line-height: 1.35;
        }

        .kpi-health-label {
            display: inline-flex;
            margin-top: 5px;
            padding: 3px 8px;
            color: #166534;
            background: #dcfce7;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .chart-card {
            background: white;
            padding: 20px;
        }

        .chart-container {
            position: relative;
            height: 300px;
        }

        @media (max-width: 991px) {
            .sidebar {
                width: 82vw;
                max-width: 340px;
                min-width: 230px;
                position: fixed;
                left: 0;
                top: 0;
                z-index: 2000;
                box-shadow: 2px 0 16px rgba(0, 0, 0, 0.08);
                transform: translateX(-100%);
            }

            .main-content {
                margin-left: 0;
                width: 100%;
                max-width: 100%;
                flex-basis: 100%;
                padding-top: 98px;
            }
        }

        @media (max-width: 576px) {
            .sidebar {
                width: 86vw !important;
                max-width: 320px !important;
                min-height: 100vh !important;
                display: block !important;
            }

            .main-content {
                margin-left: 0 !important;
                padding: 14px !important;
                padding-top: 94px !important;
                padding-left: 14px !important;
            }

            .menu-toggle-btn {
                width: 50px;
                height: 50px;
                border-radius: 14px;
                font-size: 20px;
                left: 12px;
                top: 12px;
            }

            .stat-card {
                padding: 16px !important;
            }

            .stat-icon {
                width: 44px;
                height: 44px;
                font-size: 20px;
            }

            .chart-card {
                padding: 16px !important;
            }

            .chart-container {
                height: 240px;
            }
        }

        /* Corporate polish v1: compact and consistent with admin panel */
        body {
            font-size: 14px;
            color: #0f172a;
        }

        .sidebar {
            background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
            box-shadow: 10px 0 24px rgba(2, 6, 23, 0.22);
        }

        .sidebar .brand h3 {
            font-size: 18px;
            line-height: 1.2;
        }

        .sidebar .brand span {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .03em;
            opacity: 0.9;
        }

        .sidebar .nav-item a {
            font-size: 15px;
            padding: 10px 12px;
            border-radius: 10px;
        }

        .main-content {
            padding-top: 18px;
        }

        .stat-card,
        .chart-card {
            border-radius: 12px;
            border: 1px solid #dbe3ef;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
        }

        .stat-card {
            padding: 16px;
        }

        .stat-card .fs-3 {
            font-size: 1.85rem !important;
        }

        .chart-card {
            padding: 16px;
        }

        .btn {
            border-radius: 10px;
            font-size: 14px;
            padding: 9px 14px;
        }

        .chart-card h5 {
            font-size: 17px;
            font-weight: 700;
        }

        @media (min-width: 1200px) {
            .main-content {
                padding: 16px 18px 18px;
            }

            .row.g-4 {
                --bs-gutter-x: 1rem;
                --bs-gutter-y: 1rem;
            }

            .stat-card {
                padding: 14px;
            }

            .stat-card .fs-3 {
                font-size: 1.65rem !important;
            }

            .chart-card {
                padding: 14px;
            }

            .chart-container {
                height: 270px;
            }

            .sidebar .nav-item a {
                padding: 9px 12px;
            }
        }
    </style>
    <link rel="stylesheet" href="css/super-admin-shell.css">
    <link rel="stylesheet" href="css/super-admin-secoes.css?v=<?php echo (int)@filemtime(__DIR__ . '/css/super-admin-secoes.css'); ?>">
</head>

<body class="super-admin-ui">
    <button type="button" class="menu-toggle-btn" id="btnMenuToggle" aria-label="Abrir menu">
        <i class="fas fa-bars"></i>
    </button>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
    <script src="js/super_admin_sidebar.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/super_admin_sidebar.js'); ?>" defer></script>

    <div class="container-fluid">
        <div class="row">
            <?php
            $superAdminActiveSection = 'dashboard';
            $superAdminSectionLinks = false;
            $superAdminPrivacyPendingCount = $privacy_pending_count;
            $superAdminOwnerName = $super_admin_nome;
            $superAdminBranchContext = session_contexto_filial_ativo();
            include __DIR__ . '/includes/super_admin_sidebar.php';
            ?>

            <main class="main-content col-md-9 ms-sm-auto col-lg-10">
                <!-- Alertas -->
                <div id="alertContainer"></div>

                <div class="sa-sec-header">
                    <div>
                        <h1 class="sa-sec-title"><i class="fas fa-chart-pie" aria-hidden="true"></i> Painel executivo</h1>
                        <p class="sa-sec-sub">O estado do negócio e o que precisa da sua atenção, num só ecrã.</p>
                    </div>
                </div>

                <h2 class="sa-bloco-titulo" id="dashboard-kpis-title">Negócio</h2>
                <div class="sa-kpis sa-kpis--4">
                    <div class="sa-kpi">
                        <span class="sa-kpi__rotulo">Restaurantes ativos</span>
                        <strong class="sa-kpi__valor text-success" id="statRestaurantesAtivos">0</strong>
                        <span class="sa-kpi__nota">de <span id="statTotalRestaurantes">0</span> · <span id="statRestaurantesSuspensos">0</span> suspensos · <span id="statRestaurantesEmAvaliacao">0</span> em avaliação</span>
                        <span class="kpi-trend neutral" id="kpiRestaurantesAtivosTrend"></span>
                    </div>
                    <div class="sa-kpi">
                        <span class="sa-kpi__rotulo">Receita mensal</span>
                        <strong class="sa-kpi__valor" id="statReceitaMensal">0 MZN</strong>
                        <span class="sa-kpi__nota">≈ <span id="statReceitaAnual">0 MZN</span> por ano, se se mantiver</span>
                        <span class="kpi-trend neutral" id="kpiReceitaMensalTrend"></span>
                    </div>
                    <div class="sa-kpi">
                        <span class="sa-kpi__rotulo">Planos a vencer em breve</span>
                        <strong class="sa-kpi__valor text-warning" id="statAssinaturasExpirando">0</strong>
                        <span class="sa-kpi__nota"><a href="admin.php?secao=restaurantes">Ver restaurantes</a> e lembrar a renovação</span>
                        <span class="kpi-trend neutral" id="kpiAssinaturasExpirandoTrend"></span>
                    </div>
                    <div class="sa-kpi">
                        <span class="sa-kpi__rotulo">Utilizadores ativos</span>
                        <strong class="sa-kpi__valor" id="statUtilizadoresAtivos">0</strong>
                        <span class="sa-kpi__nota">Pessoas com acesso ao sistema</span>
                    </div>
                </div>

                <h2 class="sa-bloco-titulo">Precisa de atenção</h2>
                <div class="sa-kpis sa-kpis--4">
                    <a class="sa-kpi sa-kpi--link" id="cardSupportSla" href="admin.php?secao=suporte">
                        <span class="sa-kpi__rotulo">Suporte fora do prazo</span>
                        <strong class="sa-kpi__valor" id="statSupportSlaVencido">0</strong>
                        <span class="sa-kpi__nota" id="statSupportSlaDetail">0 quase a vencer · 0 por resolver</span>
                    </a>
                    <a class="sa-kpi sa-kpi--link" id="cardPrivacyDeadline" href="admin_privacidade.php">
                        <span class="sa-kpi__rotulo">Pedidos de privacidade a vencer</span>
                        <strong class="sa-kpi__valor" id="statPrivacyDueSoon">0</strong>
                        <span class="sa-kpi__nota" id="statPrivacyDueDetail">0 fora do prazo</span>
                    </a>
                    <a class="sa-kpi sa-kpi--link" id="cardSecurityAlerts" href="admin.php?secao=seguranca">
                        <span class="sa-kpi__rotulo">Alertas de segurança (24h)</span>
                        <strong class="sa-kpi__valor" id="statSecurityAlerts">0</strong>
                        <span class="sa-kpi__nota" id="statSecurityDetail">0 IPs bloqueados</span>
                    </a>
                    <div class="sa-kpi" id="cardSaude" title="Combina segurança, suporte, privacidade, vencimentos e suspensões.">
                        <span class="sa-kpi__rotulo">Saúde da plataforma</span>
                        <strong class="sa-kpi__valor"><span id="statOperationalHealth">100</span><small class="fs-6">/100</small></strong>
                        <span class="kpi-health-label" id="statOperationalHealthLabel">SAUDÁVEL</span>
                    </div>
                </div>

                <section class="sa-section-heading" aria-labelledby="dashboard-analysis-title">
                    <div>
                        <span class="sa-section-eyebrow">An&aacute;lise empresarial</span>
                        <h2 id="dashboard-analysis-title">Tend&ecirc;ncias comerciais e utiliza&ccedil;&atilde;o</h2>
                        <p>Distribui&ccedil;&atilde;o dos planos, crescimento da base e concentra&ccedil;&atilde;o de utilizadores.</p>
                    </div>
                </section>

                <!-- Gráficos Linha 1 -->
                <div class="row g-4 mb-4">
                    <div class="col-md-6">
                        <div class="chart-card">
                            <h5 class="mb-3"><i class="fas fa-chart-pie me-2 text-primary"></i>Restaurantes por Plano</h5>
                            <div class="chart-container">
                                <canvas id="chartPlanos"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="chart-card">
                            <h5 class="mb-3"><i class="fas fa-chart-pie me-2 text-success"></i>Restaurantes por Status</h5>
                            <div class="chart-container">
                                <canvas id="chartStatus"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Gráficos Linha 2 -->
                <div class="row g-4 mb-4">
                    <div class="col-md-8">
                        <div class="chart-card">
                            <h5 class="mb-3"><i class="fas fa-chart-line me-2 text-info"></i>Crescimento de Restaurantes</h5>
                            <div class="chart-container">
                                <canvas id="chartCrescimento"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="chart-card">
                            <h5 class="mb-3"><i class="fas fa-chart-bar me-2 text-warning"></i>Receita por Plano</h5>
                            <div class="chart-container">
                                <canvas id="chartReceita"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Gráficos Linha 3 -->
                <div class="row g-4 mb-4">
                    <div class="col-md-6">
                        <div class="chart-card">
                            <h5 class="mb-3"><i class="fas fa-trophy me-2 text-warning"></i>Top 5 Restaurantes (Usuários)</h5>
                            <div class="chart-container">
                                <canvas id="chartTopUsuarios"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="chart-card">
                            <h5 class="mb-3"><i class="fas fa-shopping-cart me-2 text-info"></i>Compras de Planos por Mês</h5>
                            <div class="chart-container">
                                <canvas id="chartCompras"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
        // Cores para os gráficos
        const cores = {
            primary: '#6c5ce7',
            secondary: '#a29bfe',
            success: '#28a745',
            warning: '#ffc107',
            danger: '#dc3545',
            info: '#17a2b8',
            basico: '#6c757d',
            profissional: '#17a2b8',
            enterprise: '#ffc107'
        };

        document.addEventListener('DOMContentLoaded', function() {
            carregarEstatisticas();
            carregarGraficos();
        });

        // Carregar estatísticas
        function carregarEstatisticas() {
            fetch('api/super_admin_estatisticas.php', {
                    credentials: 'same-origin'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('statTotalRestaurantes').textContent = data.data.total_restaurantes;
                        document.getElementById('statRestaurantesAtivos').textContent = data.data.restaurantes_ativos;
                        const formatarMzn = (valor) => Number(valor || 0).toLocaleString('pt-PT', {
                            minimumFractionDigits: 0,
                            maximumFractionDigits: 0
                        }) + ' MZN';
                        document.getElementById('statReceitaMensal').textContent = formatarMzn(data.data.receita_mensal);
                        document.getElementById('statAssinaturasExpirando').textContent = data.data.assinaturas_expirando;
                        document.getElementById('statRestaurantesSuspensos').textContent = data.data.restaurantes_suspensos ?? 0;
                        document.getElementById('statRestaurantesEmAvaliacao').textContent = data.data.restaurantes_em_avaliacao ?? 0;
                        document.getElementById('statReceitaAnual').textContent = formatarMzn(data.data.receita_anual_projetada);
                        document.getElementById('statUtilizadoresAtivos').textContent = data.data.utilizadores_ativos ?? 0;

                        const supportSla = data.data.suporte_sla || {};
                        document.getElementById('cardSupportSla').classList.toggle('d-none', supportSla.authorized === false);
                        document.getElementById('statSupportSlaVencido').textContent = supportSla.vencidos || 0;
                        document.getElementById('cardSupportSla').classList.toggle('sa-kpi--alerta', Number(supportSla.vencidos || 0) > 0);
                        document.getElementById('statSupportSlaDetail').textContent = `${supportSla.em_risco || 0} quase a vencer · ${supportSla.abertos || 0} por resolver`;

                        const privacyTiming = data.data.privacidade_prazos || {};
                        document.getElementById('cardPrivacyDeadline').classList.toggle('d-none', privacyTiming.authorized === false);
                        document.getElementById('statPrivacyDueSoon').textContent = privacyTiming.proximos_prazo || 0;
                        document.getElementById('statPrivacyDueDetail').textContent = `${privacyTiming.vencidos || 0} fora do prazo (${privacyTiming.prazo_dias || 30} dias)`;
                        document.getElementById('cardPrivacyDeadline').classList.toggle('sa-kpi--alerta', Number(privacyTiming.vencidos || 0) > 0);

                        const security = data.data.seguranca || {};
                        document.getElementById('cardSecurityAlerts').classList.toggle('d-none', security.authorized === false);
                        document.getElementById('statSecurityAlerts').textContent = security.alertas_24h || 0;
                        const riscoPt = { BAIXO: 'Risco baixo', MEDIO: 'Risco médio', ALTO: 'Risco alto', CRITICO: 'Risco crítico' };
                        document.getElementById('statSecurityDetail').textContent = `${riscoPt[String(security.risk_level || 'BAIXO').toUpperCase()] || 'Risco baixo'} · ${security.ips_bloqueados || 0} IPs bloqueados`;

                        const health = data.data.saude_operacional || {};
                        const healthScore = Number(health.score ?? 100);
                        const healthElement = document.getElementById('statOperationalHealth');
                        const healthLabel = document.getElementById('statOperationalHealthLabel');
                        healthElement.textContent = String(healthScore);
                        healthElement.parentElement.className = `sa-kpi__valor ${healthScore >= 85 ? 'text-success' : (healthScore >= 70 ? 'text-warning' : 'text-danger')}`;
                        healthLabel.textContent = String(health.label || 'SAUDAVEL').replace('SAUDAVEL', 'SAUDÁVEL').replace('ATENCAO', 'ATENÇÃO');
                        healthLabel.style.color = healthScore >= 85 ? '#166534' : (healthScore >= 70 ? '#92400e' : '#991b1b');
                        healthLabel.style.background = healthScore >= 85 ? '#dcfce7' : (healthScore >= 70 ? '#fef3c7' : '#fee2e2');

                        atualizarKpiComparativo('kpiTotalRestaurantesTrend', data.data.comparativos?.total_restaurantes);
                        atualizarKpiComparativo('kpiRestaurantesAtivosTrend', data.data.comparativos?.restaurantes_ativos);
                        atualizarKpiComparativo('kpiReceitaMensalTrend', data.data.comparativos?.receita_mensal, true);
                        atualizarKpiComparativo('kpiAssinaturasExpirandoTrend', data.data.comparativos?.assinaturas_expirando);
                    }
                })
                .catch(err => console.error('Erro ao carregar estatísticas:', err));
        }

        function atualizarKpiComparativo(elementId, comparativo, monetario = false) {
            const el = document.getElementById(elementId);
            if (!el || !comparativo) {
                return;
            }

            const variacao = Number(comparativo.variacao_percentual || 0);
            const atual = Number(comparativo.atual || 0);
            const anterior = Number(comparativo.anterior || 0);

            const signal = variacao > 0 ? '+' : '';
            const arrow = variacao > 0 ? 'fa-arrow-up' : (variacao < 0 ? 'fa-arrow-down' : 'fa-minus');
            const css = variacao > 0 ? 'positive' : (variacao < 0 ? 'negative' : 'neutral');

            el.className = `kpi-trend ${css}`;
            // Percentagens com 0 no mes anterior enganam ("+100%"): nesse caso diz-se "novo".
            if (atual === anterior) {
                el.className = 'kpi-trend neutral';
                el.innerHTML = '<i class="fas fa-minus" aria-hidden="true"></i> Igual ao mês passado';
            } else if (anterior === 0 && monetario) {
                el.className = 'kpi-trend positive';
                el.innerHTML = '<i class="fas fa-arrow-up" aria-hidden="true"></i> Novo este mês';
            } else if (!monetario) {
                // Em contagens, a diferenca real diz mais do que uma percentagem.
                const dif = atual - anterior;
                el.innerHTML = `<i class="fas ${arrow}" aria-hidden="true"></i> ${dif > 0 ? '+' : ''}${dif.toLocaleString('pt-PT')} face ao mês passado`;
            } else {
                const pct = Math.abs(variacao) >= 10 ? variacao.toFixed(0) : variacao.toFixed(1).replace('.', ',');
                el.innerHTML = `<i class="fas ${arrow}" aria-hidden="true"></i> ${signal}${pct}% face ao mês passado`;
            }
        }

        // Carregar gráficos
        function carregarGraficos() {
            fetch('api/super_admin_dashboard_graficos.php', {
                    credentials: 'same-origin'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        criarGraficoPlanos(data.data.restaurantes_por_plano);
                        criarGraficoStatus(data.data.restaurantes_por_status);
                        criarGraficoCrescimento(data.data.crescimento_restaurantes);
                        criarGraficoReceita(data.data.receita_por_plano);
                        criarGraficoTopUsuarios(data.data.top_restaurantes_usuarios);
                        criarGraficoCompras(data.data.compras_por_mes);
                    }
                })
                .catch(err => console.error('Erro ao carregar gráficos:', err));
        }

        // Gráfico de Pizza - Planos
        function criarGraficoPlanos(dados) {
            const ctx = document.getElementById('chartPlanos').getContext('2d');

            const labels = dados.map(d => {
                if (d.plano === 'EMPRESARIAL') return 'Empresarial';
                if (d.plano === 'PROFISSIONAL') return 'Profissional';
                return 'Básico';
            });
            const values = dados.map(d => d.total);
            const backgroundColors = dados.map(d => {
                if (d.plano === 'EMPRESARIAL') return cores.enterprise;
                if (d.plano === 'PROFISSIONAL') return cores.profissional;
                return cores.basico;
            });

            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: values,
                        backgroundColor: backgroundColors,
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        }

        // Gráfico de Pizza - Status
        function criarGraficoStatus(dados) {
            const ctx = document.getElementById('chartStatus').getContext('2d');

            const labels = dados.map(d => {
                if (d.status === 'ATIVO') return 'Ativo';
                if (d.status === 'BLOQUEADO') return 'Bloqueado';
                return 'Cancelado';
            });
            const values = dados.map(d => d.total);
            const backgroundColors = dados.map(d => {
                if (d.status === 'ATIVO') return cores.success;
                if (d.status === 'BLOQUEADO') return cores.warning;
                return cores.danger;
            });

            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: values,
                        backgroundColor: backgroundColors,
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        }

        // Gráfico de Linha - Crescimento
        function criarGraficoCrescimento(dados) {
            const ctx = document.getElementById('chartCrescimento').getContext('2d');

            const labels = dados.map(d => {
                const [ano, mes] = d.mes.split('-');
                const meses = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
                return meses[parseInt(mes) - 1] + '/' + ano.slice(2);
            });
            const values = dados.map(d => parseInt(d.total));

            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Novos Restaurantes',
                        data: values,
                        borderColor: cores.info,
                        backgroundColor: 'rgba(23, 162, 184, 0.1)',
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
            });
        }

        // Gráfico de Barra - Receita
        function criarGraficoReceita(dados) {
            const ctx = document.getElementById('chartReceita').getContext('2d');

            const labels = dados.map(d => {
                if (d.plano === 'EMPRESARIAL') return 'Empresarial';
                if (d.plano === 'PROFISSIONAL') return 'Profissional';
                return 'Básico';
            });
            const values = dados.map(d => parseFloat(d.receita) || 0);
            const backgroundColors = dados.map(d => {
                if (d.plano === 'EMPRESARIAL') return cores.enterprise;
                if (d.plano === 'PROFISSIONAL') return cores.profissional;
                return cores.basico;
            });

            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Receita (MZN)',
                        data: values,
                        backgroundColor: backgroundColors,
                        borderRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }

        // Gráfico de Barra Horizontal - Top Usuários
        function criarGraficoTopUsuarios(dados) {
            const ctx = document.getElementById('chartTopUsuarios').getContext('2d');

            const labels = dados.map(d => d.nome.substring(0, 15) + (d.nome.length > 15 ? '...' : ''));
            const values = dados.map(d => parseInt(d.total_usuarios));

            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Usuários',
                        data: values,
                        backgroundColor: [
                            cores.primary,
                            cores.secondary,
                            cores.info,
                            cores.warning,
                            cores.success
                        ],
                        borderRadius: 5
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
            });
        }

        // Gráfico de Linha - Compras por Mês
        function criarGraficoCompras(dados) {
            const ctx = document.getElementById('chartCompras').getContext('2d');

            const labels = dados.map(d => {
                const [ano, mes] = d.mes.split('-');
                const meses = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
                return meses[parseInt(mes) - 1] + '/' + ano.slice(2);
            });
            const total = dados.map(d => parseInt(d.total));
            const aprovados = dados.map(d => parseInt(d.aprovados));

            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Total',
                        data: total,
                        borderColor: cores.primary,
                        backgroundColor: 'rgba(108, 92, 231, 0.1)',
                        fill: true,
                        tension: 0.4
                    }, {
                        label: 'Aprovados',
                        data: aprovados,
                        borderColor: cores.success,
                        backgroundColor: 'rgba(40, 167, 69, 0.1)',
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
            });
        }

        (() => {
            const logoutLinks = document.querySelectorAll('a[href="logout.php"]');
            if (!logoutLinks.length) {
                return;
            }

            const markOffline = () => {
                const url = 'api/online_offline.php';

                try {
                    if (navigator.sendBeacon) {
                        const data = new FormData();
                        data.append('source', 'logout');
                        navigator.sendBeacon(url, data);
                        return;
                    }
                } catch (e) {}

                try {
                    fetch(url, {
                        method: 'POST',
                        credentials: 'same-origin',
                        keepalive: true,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                } catch (e) {}
            };

            logoutLinks.forEach((link) => {
                link.addEventListener('click', markOffline, {
                    passive: true
                });
            });
        })();
    </script>
</body>

</html>
