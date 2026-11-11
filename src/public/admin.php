<?php
require_once __DIR__ . '/app_base.php';

/*
 
   PÁGINA DE ADMINISTRAÇÃO - SUPER ADMIN
 
 */

include_once APP_BASE . '/src/config/super_admin_check.php';
include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/csrf.php';
include_once APP_BASE . '/src/config/restaurante_status_helper.php';
include_once APP_BASE . '/src/config/support_tickets.php';
include_once APP_BASE . '/src/config/restaurante_context.php';
include_once APP_BASE . '/src/config/legal_documents.php';

$database = new Database();
$db = $database->getConnection();

// Buscar dados do super admin
$super_admin_nome = $_SESSION['nome'] ?? 'Super Admin';
$csrf_token = csrf_get_token();
$super_admin_permissions = super_admin_get_permissions();
$restaurante_status_suportados = restaurante_status_suportados($db);
if (empty($restaurante_status_suportados)) {
    $restaurante_status_suportados = ['ATIVO', 'BLOQUEADO', 'CANCELADO'];
}

$restaurante_status_labels = [
    'PENDENTE' => 'Aguardando Aprovação',
    'AGUARDANDO_APROVACAO' => 'Aguardando Aprovação',
    'ATIVO' => 'Ativo',
    'BLOQUEADO' => 'Bloqueado',
    'CANCELADO' => 'Cancelado',
    'SEM_STATUS' => 'Sem status',
];

$can_manage_restaurants = super_admin_has_permission('manage_restaurants');
$can_manage_users = super_admin_has_permission('manage_users');
$can_view_finance = super_admin_has_permission('view_finance');
$can_approve_plans = super_admin_has_permission('approve_plans');
$can_view_dashboard = super_admin_has_permission('view_dashboard');
$can_manage_security = super_admin_has_permission('manage_security');
$can_manage_support = super_admin_has_permission('manage_support');
$can_manage_privacy = super_admin_has_permission('manage_privacy');
$can_access_plans = $can_view_finance || $can_approve_plans;
$privacy_pending_count = 0;
if ($can_manage_privacy) {
    legal_ensure_schema($db);
    $privacy_pending_count = (int)$db->query("SELECT COUNT(*) FROM data_subject_requests WHERE status IN ('PENDENTE_VERIFICACAO','EM_VERIFICACAO','EM_PROCESSAMENTO')")->fetchColumn();
}
$contextoFilialAtivo = function_exists('session_contexto_filial_ativo') ? session_contexto_filial_ativo() : false;

$default_secao = 'restaurantes';
if (!$can_manage_restaurants && $can_manage_users) {
    $default_secao = 'usuarios';
} elseif (!$can_manage_restaurants && !$can_manage_users && $can_access_plans) {
    $default_secao = 'planos';
} elseif (!$can_manage_restaurants && !$can_manage_users && !$can_access_plans && $can_manage_support) {
    $default_secao = 'suporte';
} elseif (!$can_manage_restaurants && !$can_manage_users && !$can_access_plans && !$can_manage_support && $can_manage_security) {
    $default_secao = 'seguranca';
}

$secaoParam = strtolower(trim((string)($_GET['secao'] ?? '')));
if ($secaoParam === 'restaurantes' && $can_manage_restaurants) {
    $default_secao = 'restaurantes';
} elseif ($secaoParam === 'usuarios' && $can_manage_users) {
    $default_secao = 'usuarios';
} elseif ($secaoParam === 'planos' && $can_access_plans) {
    $default_secao = 'planos';
} elseif ($secaoParam === 'suporte' && $can_manage_support) {
    $default_secao = 'suporte';
} elseif ($secaoParam === 'seguranca' && $can_manage_security) {
    $default_secao = 'seguranca';
}
?>
<!DOCTYPE html>
<html lang="pt">

<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <script src="js/botao_atualizar.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/botao_atualizar.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administração - Sistema de Restaurantes</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/adminpro-theme.css">
    <link rel="stylesheet" href="css/premium.css">

    <link rel="stylesheet" href="css/admin.css?v=<?php echo (int)@filemtime(__DIR__ . '/css/admin.css'); ?>">
    <link rel="stylesheet" href="css/super-admin-shell.css">
    <link rel="stylesheet" href="css/super-admin-secoes.css?v=<?php echo (int)@filemtime(__DIR__ . '/css/super-admin-secoes.css'); ?>">
</head>

<body class="super-admin-ui">
    <button type="button" class="menu-toggle-btn" id="btnMenuToggle" aria-label="Abrir menu">
        <i class="fas fa-bars"></i>
    </button>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <div class="container-fluid">
        <div class="row">
            <?php
            $superAdminActiveSection = $default_secao;
            $superAdminSectionLinks = true;
            $superAdminPrivacyPendingCount = $privacy_pending_count;
            $superAdminOwnerName = $super_admin_nome;
            $superAdminBranchContext = $contextoFilialAtivo;
            include __DIR__ . '/includes/super_admin_sidebar.php';
            ?>

            <main class="main-content col-md-9 ms-sm-auto col-lg-10">
                <!-- Alertas -->
                <div id="alertContainer"></div>
                <?php if (!$can_manage_restaurants && !$can_manage_users && !$can_access_plans && !$can_manage_security): ?>
                    <div class="alert alert-warning" role="alert">
                        Este usuário não possui permissões de módulo no AdminPro. Contacte o fundador para ajustar os acessos.
                    </div>
                <?php endif; ?>

                <!-- SEÇÃO: RESTAURANTES -->
                <?php if ($can_manage_restaurants): ?>
                    <div id="secao-restaurantes" class="secao <?php echo $default_secao === 'restaurantes' ? 'ativa' : ''; ?>">
                        <div class="sa-sec-header">
                            <div>
                                <h2 class="sa-sec-title"><i class="fas fa-building" aria-hidden="true"></i> Restaurantes</h2>
                                <p class="sa-sec-sub">Todos os clientes da plataforma: plano, validade e estado.</p>
                            </div>
                            <div class="sa-sec-tools">
                                <form id="formSelecionarRestaurante" class="d-flex flex-wrap gap-2">
                                    <label class="visually-hidden" for="restauranteSelecionado">Restaurante</label>
                                    <select class="form-select" id="restauranteSelecionado" name="restaurante_id">
                                        <option value="">Escolher um restaurante…</option>
                                    </select>
                                    <?php if ($can_view_dashboard): ?>
                                        <button type="button" class="btn btn-outline-dark" id="btnAcessarDashboard" title="Abrir o painel do restaurante escolhido">
                                            <i class="fas fa-arrow-right-to-bracket me-1" aria-hidden="true"></i> Abrir painel
                                        </button>
                                    <?php endif; ?>
                                </form>
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCadastrar">
                                    <i class="fas fa-plus me-1" aria-hidden="true"></i> Novo restaurante
                                </button>
                            </div>
                        </div>

                        <div class="sa-kpis sa-kpis--4">
                            <div class="sa-kpi"><span class="sa-kpi__rotulo">Restaurantes</span><strong class="sa-kpi__valor" id="listaTotalRestaurantes">0</strong><span class="sa-kpi__nota">Na lista (respeita a pesquisa)</span></div>
                            <div class="sa-kpi"><span class="sa-kpi__rotulo">Ativos e em dia</span><strong class="sa-kpi__valor text-success" id="listaAtivos">0</strong><span class="sa-kpi__nota"><span id="listaEnterprise">0</span> no plano Empresarial</span></div>
                            <div class="sa-kpi"><span class="sa-kpi__rotulo">A vencer em 30 dias</span><strong class="sa-kpi__valor text-warning" id="listaExpirando">0</strong><span class="sa-kpi__nota">Lembrar a renovação</span></div>
                            <div class="sa-kpi" id="cardListaVencidos"><span class="sa-kpi__rotulo">Plano vencido ou bloqueados</span><strong class="sa-kpi__valor" id="listaVencidos">0</strong><span class="sa-kpi__nota"><span id="listaBloqueados">0</span> bloqueados</span></div>
                        </div>

                        <!-- Tabela de Restaurantes -->
                        <div class="table-card">
                            <div class="card-header bg-white py-3">
                                <div class="d-flex flex-column flex-md-row gap-2 justify-content-between align-items-md-center">
                                    <h3 class="sa-card-titulo mb-0">Lista de restaurantes</h3>
                                    <div class="table-toolbar-controls">
                                        <input type="text" id="filtroRestaurantes" class="form-control form-control-sm toolbar-search" placeholder="Pesquisar nome, email, plano ou estado…">
                                        <select id="pageSizeRestaurantes" class="form-select form-select-sm" style="width: 90px;">
                                            <option value="10">10</option>
                                            <option value="25">25</option>
                                            <option value="50">50</option>
                                        </select>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnRestaurantesPrev">Anterior</button>
                                        <span class="text-muted small" id="paginacaoRestaurantesInfo">1/1</span>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnRestaurantesNext">Próxima</button>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th class="ps-4"><button class="btn btn-link btn-sm p-0 text-decoration-none text-dark fw-semibold js-sort" data-table="restaurantes" data-sort="id">ID</button></th>
                                                <th><button class="btn btn-link btn-sm p-0 text-decoration-none text-dark fw-semibold js-sort" data-table="restaurantes" data-sort="nome">Nome</button></th>
                                                                                                <th><button class="btn btn-link btn-sm p-0 text-decoration-none text-dark fw-semibold js-sort" data-table="restaurantes" data-sort="telefone">Telefone</button></th>
                                                <th><button class="btn btn-link btn-sm p-0 text-decoration-none text-dark fw-semibold js-sort" data-table="restaurantes" data-sort="plano">Plano</button></th>
                                                <th><button class="btn btn-link btn-sm p-0 text-decoration-none text-dark fw-semibold js-sort" data-table="restaurantes" data-sort="status_exibicao">Estado</button></th>
                                                <th><button class="btn btn-link btn-sm p-0 text-decoration-none text-dark fw-semibold js-sort" data-table="restaurantes" data-sort="data_fim">Validade</button></th>
                                                <th class="text-center">Ações</th>
                                            </tr>
                                        </thead>
                                        <tbody id="tabelaRestaurantes">
                                            <tr>
                                                <td colspan="7" class="text-center py-4 text-muted">
                                                    <i class="fas fa-spinner fa-spin me-2"></i>A carregar…
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- SEÇÃO: USUÁRIOS -->
                <?php if ($can_manage_users): ?>
                    <div id="secao-usuarios" class="secao <?php echo $default_secao === 'usuarios' ? 'ativa' : ''; ?>">
                        <div class="sa-sec-header">
                            <div>
                                <h2 class="sa-sec-title"><i class="fas fa-users" aria-hidden="true"></i> Utilizadores</h2>
                                <p class="sa-sec-sub">As pessoas com acesso ao sistema em cada restaurante. Mostra todos; escolha um restaurante para filtrar ou criar.</p>
                            </div>
                            <div class="sa-sec-tools">
                                <label class="visually-hidden" for="restauranteSelecionadoUsuarios">Restaurante</label>
                                <select class="form-select" id="restauranteSelecionadoUsuarios">
                                    <option value="">Todos os restaurantes</option>
                                </select>
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCadastrarUsuario">
                                    <i class="fas fa-plus me-1" aria-hidden="true"></i> Novo utilizador
                                </button>
                            </div>
                        </div>

                        <div class="table-card">
                            <div class="card-header bg-white py-3">
                                <div class="d-flex flex-column flex-md-row gap-2 justify-content-between align-items-md-center">
                                    <h3 class="sa-card-titulo mb-0">Lista de utilizadores</h3>
                                    <div class="table-toolbar-controls">
                                        <input type="text" id="filtroUsuarios" class="form-control form-control-sm toolbar-search" placeholder="Pesquisar nome, email ou perfil…">
                                        <select id="pageSizeUsuarios" class="form-select form-select-sm" style="width: 90px;">
                                            <option value="10">10</option>
                                            <option value="25">25</option>
                                            <option value="50">50</option>
                                        </select>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnUsuariosPrev">Anterior</button>
                                        <span class="text-muted small" id="paginacaoUsuariosInfo">1/1</span>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnUsuariosNext">Próxima</button>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th class="ps-4"><button class="btn btn-link btn-sm p-0 text-decoration-none text-dark fw-semibold js-sort" data-table="usuarios" data-sort="id">ID</button></th>
                                                <th><button class="btn btn-link btn-sm p-0 text-decoration-none text-dark fw-semibold js-sort" data-table="usuarios" data-sort="nome">Nome</button></th>
                                                <th><button class="btn btn-link btn-sm p-0 text-decoration-none text-dark fw-semibold js-sort" data-table="usuarios" data-sort="restaurante_nome">Restaurante</button></th>
                                                <th><button class="btn btn-link btn-sm p-0 text-decoration-none text-dark fw-semibold js-sort" data-table="usuarios" data-sort="perfil">Perfil</button></th>
                                                <th><button class="btn btn-link btn-sm p-0 text-decoration-none text-dark fw-semibold js-sort" data-table="usuarios" data-sort="ativo">Estado</button></th>
                                                <th>Turno</th>
                                                <th class="text-center">Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tabelaUsuarios">
                                        <tr>
                                            <td colspan="7" class="text-center py-4 text-muted">
                                                A carregar…
                                            </td>
                                        </tr>
                                    </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- SEÇÃO: PLANOS -->
                <?php if ($can_access_plans): ?>
                    <div id="secao-planos" class="secao <?php echo $default_secao === 'planos' ? 'ativa' : ''; ?>">
                        <div class="sa-sec-header">
                            <div>
                                <h2 class="sa-sec-title"><i class="fas fa-credit-card" aria-hidden="true"></i> Planos e pagamentos</h2>
                                <p class="sa-sec-sub">Aprove os pagamentos enviados pelos restaurantes. Os que esperam por si aparecem primeiro.</p>
                            </div>
                            <div class="sa-sec-tools">
                                <button class="btn btn-dark" id="btnAtualizarCompras" data-atualizar><i class="fas fa-sync me-1" aria-hidden="true"></i> Atualizar</button>
                            </div>
                        </div>

                        <div class="sa-kpis sa-kpis--4">
                            <div class="sa-kpi" id="cardPendentes"><span class="sa-kpi__rotulo">À espera da sua aprovação</span><strong class="sa-kpi__valor" id="totalPendentes">0</strong><span class="sa-kpi__nota">Pagamentos com comprovativo</span></div>
                            <div class="sa-kpi"><span class="sa-kpi__rotulo">Receita mensal</span><strong class="sa-kpi__valor" id="totalMRR">0 MZN</strong><span class="sa-kpi__nota">≈ <span id="totalARR">0 MZN</span> por ano</span></div>
                            <div class="sa-kpi"><span class="sa-kpi__rotulo">Aprovados este mês</span><strong class="sa-kpi__valor text-success" id="totalAprovadosMes">0</strong><span class="sa-kpi__nota"><span id="totalAprovados">0</span> desde o início · <span id="totalCompras">0</span> pedidos</span></div>
                            <div class="sa-kpi"><span class="sa-kpi__rotulo">Rejeitados</span><strong class="sa-kpi__valor" id="totalRejeitados">0</strong><span class="sa-kpi__nota">Comprovativos recusados</span><span class="d-none" id="totalConversao"></span><span class="d-none" id="totalInadimplencia"></span></div>
                        </div>

                        <!-- Tabela de Compras -->
                        <div class="table-card">
                            <div class="card-header bg-white py-3">
                                <div class="d-flex flex-column flex-md-row gap-2 justify-content-between align-items-md-center">
                                    <h3 class="sa-card-titulo mb-0">Pagamentos recebidos</h3>
                                    <div class="table-toolbar-controls">
                                        <input type="text" id="filtroCompras" class="form-control form-control-sm toolbar-search" placeholder="Pesquisar restaurante, plano ou estado…">
                                        <select id="filtroPlanoCompras" class="form-select form-select-sm" style="width: 160px;">
                                            <option value="">Todos os planos</option>
                                            <option value="BASICO">Básico</option>
                                            <option value="PROFISSIONAL">Profissional</option>
                                            <option value="EMPRESARIAL">Empresarial</option>
                                        </select>
                                        <select id="filtroCicloCompras" class="form-select form-select-sm" style="width: 130px;">
                                            <option value="">Todos os ciclos</option>
                                            <option value="MENSAL">Mensal</option>
                                            <option value="TRIMESTRAL">Trimestral</option>
                                            <option value="ANUAL">Anual</option>
                                        </select>
                                        <select id="filtroStatusCompras" class="form-select form-select-sm" style="width: 140px;">
                                            <option value="">Todos os estados</option>
                                            <option value="AGUARDANDO_APROVACAO">À espera de aprovação</option>
                                            <option value="APROVADO">Aprovado</option>
                                            <option value="REJEITADO">Rejeitado</option>
                                        </select>
                                        <input type="date" id="filtroDataIniCompras" class="form-control form-control-sm" style="width: 155px;" title="Data inicial">
                                        <input type="date" id="filtroDataFimCompras" class="form-control form-control-sm" style="width: 155px;" title="Data final">
                                        <select id="pageSizeCompras" class="form-select form-select-sm" style="width: 90px;">
                                            <option value="10">10</option>
                                            <option value="25">25</option>
                                            <option value="50">50</option>
                                        </select>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnComprasPrev">Anterior</button>
                                        <span class="text-muted small" id="paginacaoComprasInfo">1/1</span>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnComprasNext">Próxima</button>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th class="ps-4"><button class="btn btn-link btn-sm p-0 text-decoration-none text-dark fw-semibold js-sort" data-table="compras" data-sort="id">N.º</button></th>
                                                <th><button class="btn btn-link btn-sm p-0 text-decoration-none text-dark fw-semibold js-sort" data-table="compras" data-sort="restaurante_nome">Restaurante</button></th>
                                                <th><button class="btn btn-link btn-sm p-0 text-decoration-none text-dark fw-semibold js-sort" data-table="compras" data-sort="plano_novo">Plano</button></th>
                                                <th><button class="btn btn-link btn-sm p-0 text-decoration-none text-dark fw-semibold js-sort" data-table="compras" data-sort="valor">Valor</button></th>
                                                <th><button class="btn btn-link btn-sm p-0 text-decoration-none text-dark fw-semibold js-sort" data-table="compras" data-sort="metodo_pagamento">Pagamento</button></th>
                                                <th>Comprovativo</th>
                                                <th><button class="btn btn-link btn-sm p-0 text-decoration-none text-dark fw-semibold js-sort" data-table="compras" data-sort="status">Estado</button></th>
                                                <th><button class="btn btn-link btn-sm p-0 text-decoration-none text-dark fw-semibold js-sort" data-table="compras" data-sort="criado_em">Data</button></th>
                                                <th class="text-center">Ações</th>
                                            </tr>
                                        </thead>
                                        <tbody id="tabelaCompras">
                                            <tr>
                                                <td colspan="9" class="text-center py-4 text-muted">
                                                    <i class="fas fa-spinner fa-spin me-2"></i>A carregar…
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($can_manage_support): ?>
                    <div id="secao-suporte" class="secao <?php echo $default_secao === 'suporte' ? 'ativa' : ''; ?>">
                        <div class="sa-sec-header">
                            <div>
                                <h2 class="sa-sec-title"><i class="fas fa-headset" aria-hidden="true"></i> Suporte</h2>
                                <p class="sa-sec-sub">Pedidos de ajuda dos restaurantes. Os mais urgentes aparecem primeiro.</p>
                            </div>
                            <div class="sa-sec-tools">
                                <label class="visually-hidden" for="filtroSupportTickets">Pesquisar</label>
                                <input type="search" id="filtroSupportTickets" class="form-control" placeholder="Pesquisar restaurante ou assunto…">
                                <label class="visually-hidden" for="filtroSupportStatus">Estado</label>
                                <select id="filtroSupportStatus" class="form-select">
                                    <option value="ABERTOS" selected>Por resolver</option>
                                    <option value="">Todos</option>
                                    <option value="ABERTO">Abertos (sem resposta)</option>
                                    <option value="EM_ANDAMENTO">Em andamento</option>
                                    <option value="RESOLVIDO">Resolvidos</option>
                                    <option value="FECHADO">Fechados</option>
                                </select>
                                <button class="btn btn-dark" id="btnAtualizarSupportTickets" data-atualizar><i class="fas fa-sync me-1" aria-hidden="true"></i> Atualizar</button>
                            </div>
                        </div>

                        <div class="sa-kpis">
                            <div class="sa-kpi"><span class="sa-kpi__rotulo">Por resolver</span><strong class="sa-kpi__valor" id="supportStatAbertos">0</strong></div>
                            <div class="sa-kpi sa-kpi--alerta"><span class="sa-kpi__rotulo">Prazo de resposta ultrapassado</span><strong class="sa-kpi__valor" id="supportStatSlaVencido">0</strong></div>
                            <div class="sa-kpi"><span class="sa-kpi__rotulo">Resolvidos este mês</span><strong class="sa-kpi__valor text-success" id="supportStatResolvidosMes">0</strong></div>
                        </div>

                        <div class="table-card">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0 sa-tabela">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="ps-3">N.º</th>
                                            <th>Restaurante</th>
                                            <th>Assunto</th>
                                            <th>Prioridade</th>
                                            <th>Prazo</th>
                                            <th>Estado</th>
                                            <th>Atualizado</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tabelaSupportTickets">
                                        <tr><td colspan="7" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i>A carregar…</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="offcanvas offcanvas-end sa-ticket-painel" tabindex="-1" id="painelSupportTicket" aria-labelledby="painelSupportTitulo">
                            <div class="offcanvas-header">
                                <div>
                                    <h2 class="offcanvas-title h5 mb-1" id="painelSupportTitulo">Pedido <span id="adminSupportChatTicketLabel">#0</span></h2>
                                    <div class="small text-muted" id="painelSupportRestaurante"></div>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fechar"></button>
                            </div>
                            <div class="offcanvas-body">
                                <div class="sa-ticket-meta" id="painelSupportMeta"></div>
                                <h3 class="sa-ticket-assunto" id="painelSupportAssunto"></h3>
                                <p class="sa-ticket-descricao" id="painelSupportDescricao"></p>
                                <div class="d-flex gap-2 align-items-end mb-3">
                                    <div class="flex-grow-1">
                                        <label class="form-label small fw-semibold mb-1" for="painelSupportEstado">Estado</label>
                                        <select class="form-select" id="painelSupportEstado">
                                            <option value="ABERTO">Aberto</option>
                                            <option value="EM_ANDAMENTO">Em andamento</option>
                                            <option value="RESOLVIDO">Resolvido</option>
                                            <option value="FECHADO">Fechado</option>
                                        </select>
                                    </div>
                                    <button type="button" class="btn btn-outline-dark" id="painelSupportGuardarEstado">Guardar estado</button>
                                </div>
                                <h3 class="small fw-bold text-uppercase text-muted mb-2">Conversa</h3>
                                <div id="adminSupportChatList" class="sa-ticket-chat" aria-live="polite"></div>
                                <form id="formSupportChatAdmin" enctype="multipart/form-data">
                                    <input type="hidden" name="ticket_id" id="adminSupportChatTicketId" value="0">
                                    <label class="visually-hidden" for="adminSupportChatMensagem">Resposta</label>
                                    <textarea class="form-control mb-2" name="mensagem" id="adminSupportChatMensagem" rows="3" placeholder="Escreva a resposta ao cliente…"></textarea>
                                    <div class="d-flex gap-2 align-items-center">
                                        <label class="visually-hidden" for="adminSupportChatAnexo">Anexo</label>
                                        <input type="file" class="form-control form-control-sm" name="anexo" id="adminSupportChatAnexo" accept=".jpg,.jpeg,.png,.webp,.pdf,image/*,application/pdf">
                                        <button type="submit" class="btn btn-primary" id="btnAdminSupportSend">Enviar</button>
                                    </div>
                                    <div id="adminSupportChatFeedback" class="small text-muted mt-2" role="status"></div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($can_manage_security): ?>
                    <div id="secao-seguranca" class="secao <?php echo $default_secao === 'seguranca' ? 'ativa' : ''; ?>">
                        <div class="sa-sec-header">
                            <div>
                                <h2 class="sa-sec-title"><i class="fas fa-shield-halved" aria-hidden="true"></i> Segurança</h2>
                                <p class="sa-sec-sub">Tentativas de ataque, IPs suspeitos e rotinas de proteção. <span class="text-muted" id="securityEstadoLeitura">A carregar…</span></p>
                            </div>
                            <div class="sa-sec-tools">
                                <label class="visually-hidden" for="securityWindowHours">Período</label>
                                <select id="securityWindowHours" class="form-select">
                                    <option value="6">Últimas 6 horas</option>
                                    <option value="24" selected>Últimas 24 horas</option>
                                    <option value="72">Últimos 3 dias</option>
                                </select>
                                <button class="btn btn-dark" id="btnAtualizarSeguranca" data-atualizar><i class="fas fa-sync me-1" aria-hidden="true"></i> Atualizar</button>
                            </div>
                        </div>
                        <div class="alert alert-danger d-none" id="securityErro" role="alert"></div>
                        <span class="d-none" id="securityLastUpdate"></span><span class="d-none" id="securityCurrentIp"></span>

                        <div class="sa-kpis sa-kpis--4">
                            <div class="sa-kpi sa-kpi--estado" id="secRiskCard"><span class="sa-kpi__rotulo">Situação</span><strong class="sa-kpi__valor" id="secRiskLabel">—</strong><span class="sa-kpi__nota" id="secRiskNota"></span><span class="d-none" id="secRiskScore"></span></div>
                            <div class="sa-kpi"><span class="sa-kpi__rotulo">Eventos suspeitos</span><strong class="sa-kpi__valor" id="secTotalEventos">0</strong><span class="sa-kpi__nota">Acessos estranhos registados (a maioria inofensivos)</span></div>
                            <div class="sa-kpi sa-kpi--alerta"><span class="sa-kpi__rotulo">Graves</span><strong class="sa-kpi__valor" id="secCriticos">0</strong></div>
                            <div class="sa-kpi"><span class="sa-kpi__rotulo">IPs bloqueados</span><strong class="sa-kpi__valor" id="secIpsBloqueados">0</strong></div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-lg-7">
                                <div class="security-card p-3 h-100">
                                    <h3 class="sa-card-titulo">Eventos suspeitos por hora</h3>
                                    <canvas id="securityTimelineChart" height="110" aria-label="Gráfico de eventos suspeitos por hora" role="img"></canvas>
                                </div>
                            </div>
                            <div class="col-lg-5">
                                <div class="security-card p-3 h-100">
                                    <h3 class="sa-card-titulo">Ataques identificados</h3>
                                    <div class="security-mini-item"><span>Tentativas de mexer na base de dados (SQL)</span><strong id="secSqli">0</strong></div>
                                    <div class="security-mini-item"><span>Código malicioso em páginas (XSS)</span><strong id="secXss">0</strong></div>
                                    <div class="security-mini-item"><span>Tentativas de adivinhar senhas</span><strong id="secBrute">0</strong></div>
                                    <div class="security-mini-item"><span>Robôs automáticos</span><strong id="secBots">0</strong></div>
                                    <div class="security-mini-item"><span>Acesso a pastas proibidas</span><strong id="secTraversal">0</strong></div>
                                    <div class="small text-muted mt-2">Histórico completo em <a href="admin_auditoria.php">Auditoria</a>.</div>
                                </div>
                            </div>
                        </div>

                        <div class="security-card mb-3">
                            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <h3 class="sa-card-titulo mb-0">Rotinas de segurança</h3>
                                <span class="badge bg-secondary" id="secReadyStatus">—</span><span class="d-none" id="secReadyScore"></span>
                            </div>
                            <div class="card-body p-0">
                                <p class="small text-muted px-3 pt-2 mb-2">Testes de segurança, cópias de segurança e ensaios de recuperação devem ser feitos com regularidade. Aqui vê quando foi a última vez.</p>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0 sa-tabela">
                                        <thead class="table-light">
                                            <tr><th class="ps-3">Rotina</th><th>Última vez</th><th>Fazer a cada</th><th>Há</th><th>Estado</th></tr>
                                        </thead>
                                        <tbody id="tabelaGovernanceCadencia"><tr><td colspan="5" class="security-table-empty">A carregar…</td></tr></tbody>
                                    </table>
                                </div>
                                <div class="small text-muted px-3 py-2">Responsável: <strong id="secGovOwner">—</strong> · Atualizado: <strong id="secGovUpdatedAt">—</strong></div>
                            </div>
                        </div>

                        <a class="sa-atalho mb-3" href="admin_verificacao_producao.php">
                            <i class="fas fa-clipboard-check" aria-hidden="true"></i>
                            <span><strong>Verificação antes de publicar</strong><br><span class="small text-muted">HTTPS, segredos, base de dados e ficheiros sensíveis — tudo deve estar verde.</span></span>
                            <i class="fas fa-arrow-right ms-auto" aria-hidden="true"></i>
                        </a>

                        <div class="row g-3">
                            <div class="col-lg-6">
                                <div class="security-card h-100">
                                    <div class="card-header bg-white py-3"><h3 class="sa-card-titulo mb-0">IPs suspeitos</h3></div>
                                    <div class="card-body">
                                        <div class="input-group mb-3 flex-wrap">
                                            <label class="visually-hidden" for="securityIpManual">Endereço IP</label>
                                            <input id="securityIpManual" type="text" class="form-control" placeholder="Bloquear um endereço IP">
                                            <label class="visually-hidden" for="securityTipoBloqueio">Tipo de bloqueio</label>
                                            <select id="securityTipoBloqueio" class="form-select" style="max-width:170px;">
                                                <option value="TEMPORARIO">Durante 1 hora</option>
                                                <option value="PERMANENTE">Permanente</option>
                                            </select>
                                            <button class="btn btn-danger" id="btnBloquearIpManual">Bloquear</button>
                                        </div>
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead><tr><th>IP</th><th>Tentativas</th><th></th></tr></thead>
                                                <tbody id="tabelaTopIps"></tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="security-card h-100">
                                    <div class="card-header bg-white py-3"><h3 class="sa-card-titulo mb-0">IPs bloqueados</h3></div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead><tr><th>IP</th><th>Tipo</th><th>Origem</th><th></th></tr></thead>
                                                <tbody id="tabelaBlockedIps"></tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="security-card mt-3">
                            <div class="card-header bg-white py-3"><h3 class="sa-card-titulo mb-0">Eventos recentes</h3></div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0 sa-tabela">
                                        <thead class="table-light"><tr><th class="ps-3">N.º</th><th>Quando</th><th>Gravidade</th><th>Tipo</th><th>IP</th><th>Página</th></tr></thead>
                                        <tbody id="tabelaSecurityEvents"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <details class="security-card mt-3 sa-detalhes">
                            <summary class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                                <span class="sa-card-titulo">Operações por enviar neste navegador</span>
                                <span class="small text-muted" id="offlineQueueStatus">Sem leitura.</span>
                            </summary>
                            <div class="card-body">
                                <p class="small text-muted">Vendas ou pedidos feitos neste navegador sem internet que ainda não chegaram ao servidor.</p>
                                <div class="sa-kpis sa-kpis--4">
                                    <div class="sa-kpi"><span class="sa-kpi__rotulo">Por enviar</span><strong class="sa-kpi__valor" id="offlinePendingCount">0</strong></div>
                                    <div class="sa-kpi"><span class="sa-kpi__rotulo">Enviadas</span><strong class="sa-kpi__valor" id="offlineDoneCount">0</strong></div>
                                    <div class="sa-kpi sa-kpi--alerta"><span class="sa-kpi__rotulo">Falharam</span><strong class="sa-kpi__valor" id="offlineFailedCount">0</strong></div>
                                    <div class="sa-kpi"><span class="sa-kpi__rotulo">Tentativas</span><strong class="sa-kpi__valor" id="offlineAttemptsCount">0</strong></div>
                                </div>
                                <div class="small text-muted mb-2">Em todo o sistema: <strong id="offlineCentralRestaurantes">0</strong> restaurante(s) e <strong id="offlineCentralDispositivos">0</strong> dispositivo(s) com operações por enviar (<strong id="offlineCentralTotal">0</strong> no total).</div>
                                <button class="btn btn-sm btn-outline-primary" id="btnSincronizarFilaOffline" data-atualizar><i class="fas fa-rotate me-1" aria-hidden="true"></i>Enviar agora</button>
                            </div>
                        </details>
                    </div>
                <?php endif; ?>

            </main>
        </div>
    </div>

    <!-- Modal Aprovar Compra -->
    <div class="modal fade" id="modalAprovarCompra" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-check-circle me-2"></i>Aprovar Pagamento</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="compra_id_aprovar">
                    <div id="alertAprovarModal" class="alert" style="display:none;"></div>
                    <div class="alert alert-info" role="alert">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Você está aprovando</strong> um pagamento de plano. O plano será ativado automaticamente.
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Observação (opcional)</label>
                        <textarea class="form-control" id="obs_aprovar" rows="3" placeholder="Notas da validação"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success" id="btnConfirmarAprovacao" onclick="confirmarAprovacao()">
                        <i class="fas fa-check me-1"></i> Confirmar Aprovação
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Rejeitar Compra -->
    <div class="modal fade" id="modalRejeitarCompra" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-times-circle me-2"></i>Rejeitar Pagamento</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="compra_id_rejeitar">
                    <div id="alertRejeitarModal" class="alert" style="display:none;"></div>
                    <div class="alert alert-warning" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Você está rejeitando</strong> uma compra de plano. O restaurante será notificado.
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Motivo da rejeição *</label>
                        <textarea class="form-control is-invalid" id="obs_rejeitar" rows="3" placeholder="Motivo da rejeição"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-danger" id="btnConfirmarRejeicao" onclick="confirmarRejeicao()">
                        <i class="fas fa-ban me-1"></i> Confirmar Rejeição
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Preview Comprovativo -->
    <div class="modal fade" id="modalComprovativo" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-file-invoice me-2"></i>Comprovativo de Pagamento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="modalComprovativoBody" style="min-height: 520px;">
                    <div class="text-center text-muted py-5"><i class="fas fa-spinner fa-spin me-2"></i>Carregando comprovativo...</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Cadastrar Restaurante -->
    <div class="modal fade" id="modalCadastrar" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Novo Restaurante</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="formCadastrar">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Nome do Restaurante *</label>
                                <input type="text" class="form-control" name="nome" required placeholder="Nome do restaurante">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email *</label>
                                <input type="email" class="form-control" name="email" required placeholder="Email do restaurante">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Telefone</label>
                                <input type="text" class="form-control" name="telefone" placeholder="Telefone">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Cidade</label>
                                <input type="text" class="form-control" name="cidade" placeholder="Cidade">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Endereço</label>
                                <input type="text" class="form-control" name="endereco" placeholder="Endereço">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">NUIT</label>
                                <input type="text" class="form-control" name="nuit" placeholder="NUIT">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Plano *</label>
                                <select class="form-select" name="plano" required>
                                    <option value="BASICO">Básico (Grátis)</option>
                                    <option value="PROFISSIONAL">Profissional (1.999 MZN/mês)</option>
                                    <option value="EMPRESARIAL">Empresarial (3.999 MZN/mês)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Acesso do Admin</label>
                                <div class="alert alert-info mb-0">
                                    O administrador sera criado inativo. A senha sera definida depois por link seguro enviado no momento da ativacao.
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Nome do Administrador</label>
                                <input type="text" class="form-control" name="nome_admin" value="Administrador" placeholder="Nome do administrador">
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="btnCadastrarRestaurante">
                        <i class="fas fa-save me-1"></i> Cadastrar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Editar Restaurante -->
    <div class="modal fade" id="modalEditar" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Editar Restaurante</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="formEditar">
                        <input type="hidden" name="id" id="edit_id">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Nome do Restaurante *</label>
                                <input type="text" class="form-control" name="nome" id="edit_nome" required placeholder="Nome do restaurante">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email *</label>
                                <input type="email" class="form-control" name="email" id="edit_email" required placeholder="Email do restaurante">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Telefone</label>
                                <input type="text" class="form-control" name="telefone" id="edit_telefone" placeholder="Telefone">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Cidade</label>
                                <input type="text" class="form-control" name="cidade" id="edit_cidade" placeholder="Cidade">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Endereço</label>
                                <input type="text" class="form-control" name="endereco" id="edit_endereco" placeholder="Endereço">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">NUIT</label>
                                <input type="text" class="form-control" name="nuit" id="edit_nuit" placeholder="NUIT">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Plano *</label>
                                <select class="form-select" name="plano" id="edit_plano" required>
                                    <option value="BASICO">Básico</option>
                                    <option value="PROFISSIONAL">Profissional</option>
                                    <option value="EMPRESARIAL">Empresarial</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Status *</label>
                                <select class="form-select" name="status" id="edit_status" required>
                                    <?php foreach ($restaurante_status_suportados as $statusOption): ?>
                                        <option value="<?php echo htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($restaurante_status_labels[$statusOption] ?? ucfirst(strtolower($statusOption)), ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text" id="edit_status_help"></div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="btnSalvarEdicao">
                        <i class="fas fa-save me-1"></i> Salvar Alterações
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Cadastrar Usuário -->
    <div class="modal fade" id="modalCadastrarUsuario" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Novo Usuário</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="formCadastrarUsuario">
                        <input type="hidden" id="usuario_restaurante_id" name="restaurante_id">
                        <div class="mb-3">
                            <label class="form-label">Nome *</label>
                            <input type="text" class="form-control" name="nome" required placeholder="Nome completo">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email *</label>
                            <input type="email" class="form-control" name="email" required placeholder="Email do utilizador">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Senha</label>
                            <input type="text" class="form-control" name="senha" value="usuario123" placeholder="Senha inicial (o utilizador deve alterar)">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Perfil *</label>
                            <select class="form-select" name="perfil" required>
                                <option value="ADMIN">Administrador</option>
                                <option value="OPERADOR">Operador</option>
                                <option value="COZINHA">Cozinha</option>
                            </select>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="btnCadastrarUsuario">
                        <i class="fas fa-save me-1"></i> Cadastrar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Ver Detalhes -->
    <div class="modal fade" id="modalVer" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i>Detalhes do Restaurante</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detalhesRestaurante">
                    <div class="text-center py-4">
                        <i class="fas fa-spinner fa-spin"></i> Carregando...
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="js/receipt_print.js?v=20260821a"></script>
    <script src="js/offline_sync.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/offline_sync.js'); ?>"></script>
    <script type="application/json" id="cfg-admin">{"v0": <?php echo json_encode($csrf_token, JSON_UNESCAPED_UNICODE); ?>, "v1": <?php echo json_encode($super_admin_permissions, JSON_UNESCAPED_UNICODE); ?>, "v2": <?php echo json_encode(array_values($restaurante_status_suportados), JSON_UNESCAPED_UNICODE); ?>, "v3": <?php echo json_encode($restaurante_status_labels, JSON_UNESCAPED_UNICODE); ?>, "v4": <?php echo json_encode($default_secao, JSON_UNESCAPED_UNICODE); ?>, "v5": <?php echo json_encode($default_secao, JSON_UNESCAPED_UNICODE); ?>}</script>
    <!-- Painel Super Admin: um ficheiro por area, carregados por esta ordem (partilham variaveis globais). -->
    <script src="js/admin/comum.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/admin/comum.js'); ?>"></script>
    <script src="js/admin/arranque.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/admin/arranque.js'); ?>"></script>
    <script src="js/admin/restaurantes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/admin/restaurantes.js'); ?>"></script>
    <script src="js/admin/utilizadores.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/admin/utilizadores.js'); ?>"></script>
    <script src="js/admin/compras.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/admin/compras.js'); ?>"></script>
    <script src="js/admin/suporte.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/admin/suporte.js'); ?>"></script>
    <script src="js/admin/seguranca.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/admin/seguranca.js'); ?>"></script>
</body>

</html>
