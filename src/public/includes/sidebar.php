<?php

/**
 * Sidebar Unificado por Perfil
 * Roles: ADMIN (full), CAIXA (caixa+vendas+pedidos+dashboard), GARCOM (pedidos+novo_pedido+mesas+dashboard), COZINHA (cozinha+pedidos+dashboard), BAR (bar+pedidos+dashboard)
 */
require_once __DIR__ . '/../app_base.php';
include_once APP_BASE . '/src/config/restaurante_context.php';
include_once APP_BASE . '/src/config/csrf.php';
include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/plano_check.php';
include_once APP_BASE . '/src/config/turno_helpers.php';

$perfil = strtoupper(trim($_SESSION['perfil'] ?? 'USER'));
$perfil = str_replace('GARÇOM', 'GARCOM', $perfil);
if ($perfil === 'COZINHEIRO' || $perfil === 'CHEF') {
    $perfil = 'COZINHA';
}
if ($perfil === 'BARMAN' || $perfil === 'BARTENDER') {
    $perfil = 'BAR';
}
$perfilExibicao = intval($_SESSION['super_admin'] ?? 0) === 1 ? 'SUPER ADMIN' : $perfil;
$perfilLabelsSidebar = [
    'ADMIN' => 'Administrador',
    'SUPER ADMIN' => 'Super Admin',
    'CAIXA' => 'Caixa',
    'GARCOM' => 'Garçom',
    'COZINHA' => 'Cozinha',
    'BAR' => 'Bar',
    'USER' => 'Utilizador',
];
$perfilExibicaoHumano = $perfilLabelsSidebar[$perfilExibicao] ?? ucwords(strtolower(str_replace('_', ' ', $perfilExibicao)));
$currentPage = basename((string)($_SERVER['PHP_SELF'] ?? ''));
$restauranteId = session_restaurante_contexto_id();
$restaurantePlanId = session_restaurante_capability_id();
$restaurantePlanId = $restaurantePlanId > 0 ? $restaurantePlanId : $restauranteId;
$temEstoqueAvancadoSidebar = $restaurantePlanId > 0 && plano_tem_funcionalidade_db($restaurantePlanId, 'estoque_avancado');
$temMultiFilialSidebar = $restaurantePlanId > 0 && plano_tem_funcionalidade_db($restaurantePlanId, 'multi_filial');
$temGestaoFuncionariosSidebar = $restaurantePlanId > 0 && plano_tem_funcionalidade_db($restaurantePlanId, 'gestao_funcionarios');
$temRelatoriosSidebar = $restaurantePlanId > 0 && (
    plano_tem_funcionalidade_db($restaurantePlanId, 'relatorio_diario')
    || plano_tem_funcionalidade_db($restaurantePlanId, 'relatorios_avancados')
);
$temBackupSidebar = $restaurantePlanId > 0 && (
    plano_tem_funcionalidade_db($restaurantePlanId, 'backup_manual')
    || plano_tem_funcionalidade_db($restaurantePlanId, 'backup_diario')
    || plano_tem_funcionalidade_db($restaurantePlanId, 'backup_automatico')
    || plano_tem_funcionalidade_db($restaurantePlanId, 'download_banco')
);
$temHistoricoClientesSidebar = $restaurantePlanId > 0 && (
    plano_tem_funcionalidade_db($restaurantePlanId, 'historico_clientes')
    || plano_tem_funcionalidade_db($restaurantePlanId, 'clientes')
);
$temPedidosOnlineSidebar = $restaurantePlanId > 0 && plano_tem_funcionalidade_db($restaurantePlanId, 'pedidos_online');
$temGarconsSidebar = $restaurantePlanId > 0 && plano_tem_funcionalidade_db($restaurantePlanId, 'garcons');
$temContasAbertasSidebar = $restaurantePlanId > 0 && plano_tem_funcionalidade_db($restaurantePlanId, 'contas_abertas');
$temTelasBarCozinhaSidebar = $restaurantePlanId > 0 && plano_tem_funcionalidade_db($restaurantePlanId, 'telas_bar_cozinha');
$contextoFilialAtivo = session_contexto_filial_ativo();
$filialNomeSessao = trim((string)($_SESSION['filial_nome'] ?? ''));
$csrfTokenSidebar = $contextoFilialAtivo ? csrf_get_token() : '';
$restauranteBrandNome = 'Restaurante';
$restauranteBrandLogo = '';
$restauranteBrandCidade = '';
$restauranteBrandPlano = 'Gestao integrada';
$restauranteBrandPlanoIcone = 'fa-gem';

if ($restauranteId > 0) {
    try {
        $databaseSidebar = new Database();
        $dbSidebar = $databaseSidebar->getConnection();
        if ($dbSidebar instanceof PDO) {
            $stmtBrand = $dbSidebar->prepare('SELECT nome, logo, cidade FROM restaurantes WHERE id = :id LIMIT 1');
            $stmtBrand->bindValue(':id', $restauranteId, PDO::PARAM_INT);
            $stmtBrand->execute();
            $brandRow = $stmtBrand->fetch(PDO::FETCH_ASSOC) ?: [];
            $restauranteBrandNome = trim((string)($brandRow['nome'] ?? '')) ?: $restauranteBrandNome;
            $restauranteBrandLogo = trim((string)($brandRow['logo'] ?? ''));
            $restauranteBrandCidade = trim((string)($brandRow['cidade'] ?? ''));

            if ($restauranteBrandLogo !== '') {
                $restauranteBrandLogo = str_replace('\\', '/', $restauranteBrandLogo);
                $restauranteBrandLogo = preg_replace('#^src/public/#i', '', $restauranteBrandLogo);
                $restauranteBrandLogo = ltrim($restauranteBrandLogo, '/');

                $publicRoot = realpath(dirname(__DIR__));
                $localLogo = $publicRoot
                    ? $publicRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $restauranteBrandLogo)
                    : '';
                if ($localLogo === '' || !is_file($localLogo)) {
                    $restauranteBrandLogo = '';
                } else {
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $scriptDir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
                    $restauranteBrandLogo = $scheme . '://' . $host . ($scriptDir !== '' ? $scriptDir . '/' : '/') . $restauranteBrandLogo;
                }
            }
        }

        $planoBrand = plano_get_dados($restaurantePlanId);
        if (is_array($planoBrand)) {
            $restauranteBrandPlano = trim((string)($planoBrand['nome_display'] ?? $planoBrand['plano_nome'] ?? '')) ?: $restauranteBrandPlano;
            $restauranteBrandPlanoIcone = trim((string)($planoBrand['icone'] ?? 'fa-gem'));
            if ($restauranteBrandPlanoIcone !== '' && strpos($restauranteBrandPlanoIcone, 'fa-') !== 0) {
                $restauranteBrandPlanoIcone = 'fa-' . $restauranteBrandPlanoIcone;
            }
        }
    } catch (Throwable $e) {
        // Mantém o fallback visual.
    }
}

$restauranteBrandInicial = strtoupper(substr(preg_replace('/\s+/', '', $restauranteBrandNome), 0, 2) ?: 'R');
$restauranteBrandKicker = $restauranteBrandCidade !== '' ? $restauranteBrandCidade : 'Unidade ativa';
$restauranteBrandHasLogo = $restauranteBrandLogo !== '';

$allItems = [
    'dashboard.php' => ['icon' => 'fa-chart-line', 'label' => 'Dashboard'],
    'pedidos.php' => ['icon' => 'fa-clipboard-check', 'label' => 'Pedidos'],
    'novo_pedido.php' => ['icon' => 'fa-clipboard-list', 'label' => 'Novo Pedido'],
    'caixa_mesas.php' => ['icon' => 'fa-receipt', 'label' => 'Contas Abertas'],
    'vendas.php' => ['icon' => 'fa-cash-register', 'label' => 'Vendas'],
    'caixa.php' => ['icon' => 'fa-money-bill-wave', 'label' => 'Caixa'],
    'mesas.php' => ['icon' => 'fa-chair', 'label' => 'Mesas'],
    'garcom_mesas.php' => ['icon' => 'fa-table-cells', 'label' => 'Mapa de Mesas'],
    'produtos.php' => ['icon' => 'fa-pizza-slice', 'label' => 'Produtos'],
    'promocoes.php' => ['icon' => 'fa-tags', 'label' => 'Promocoes'],
    'clientes.php' => ['icon' => 'fa-address-book', 'label' => 'Clientes'],
    'estoque_inteligente.php' => ['icon' => 'fa-boxes-stacked', 'label' => 'Estoque Inteligente'],
    'funcionarios_turnos.php' => ['icon' => 'fa-clock', 'label' => 'Funcionários e Turnos'],
    'relatorios.php' => ['icon' => 'fa-chart-bar', 'label' => 'Relatórios'],
    'cozinha.php' => ['icon' => 'fa-fire-burner', 'label' => 'Cozinha'],
    'bar.php' => ['icon' => 'fa-martini-glass-citrus', 'label' => 'Bar'],
    'usuarios.php' => ['icon' => 'fa-users', 'label' => 'Usuários'],
    'filiais.php' => ['icon' => 'fa-building', 'label' => 'Filiais'],
    'configuracoes.php' => ['icon' => 'fa-cog', 'label' => 'Configurações'],
    'backup.php' => ['icon' => 'fa-database', 'label' => 'Backup'],
    'meus_dados.php' => ['icon' => 'fa-file-zipper', 'label' => 'Meus dados e plano'],
];

// Botão "Principal" por perfil (atalho destacado no menu).
$primaryActionByPerfil = [
    'ADMIN' => 'dashboard.php',
    'GERENTE' => 'dashboard.php',
    'GARCOM' => 'novo_pedido.php',
    'CAIXA' => $temContasAbertasSidebar ? 'caixa_mesas.php' : 'caixa.php',
    'COZINHA' => 'cozinha.php',
    'BAR' => 'bar.php',
];
if (!$temTelasBarCozinhaSidebar) {
    $primaryActionByPerfil['COZINHA'] = 'novo_pedido.php';
    $primaryActionByPerfil['BAR'] = 'novo_pedido.php';
}
$primaryAction = $primaryActionByPerfil[$perfil] ?? 'dashboard.php';
// Só colapsar menus longos de garçom/caixa — cozinha/bar ficam limpos e curtos
$collapseOperationalMenus = in_array($perfil, ['GARCOM', 'CAIXA'], true);

// Rótulos do posto (KDS) — mais claros para a linha
if ($perfil === 'COZINHA') {
    $allItems['cozinha.php']['label'] = 'Fila da Cozinha';
    $allItems['cozinha.php']['icon'] = 'fa-fire-burner';
}
if ($perfil === 'BAR') {
    $allItems['bar.php']['label'] = 'Fila do Bar';
    $allItems['bar.php']['icon'] = 'fa-martini-glass-citrus';
}

$menuSections = [];

if ($perfil === 'ADMIN') {
    // Menu fixo por perfil: restrições de plano aplicam-se ao aceder às páginas, não aqui.
    // Principal do dono/admin: Dashboard
    $menuOperacao = [
        'dashboard.php',
        'pedidos.php',
        'novo_pedido.php',
        'caixa_mesas.php',
        'vendas.php',
        'mesas.php',
        'cozinha.php',
        'bar.php',
        'caixa.php',
        'garcom_mesas.php',
    ];
    $menuGestao = [
        'produtos.php',
        'promocoes.php',
        'clientes.php',
        'estoque_inteligente.php',
        'relatorios.php',
        'funcionarios_turnos.php',
        'filiais.php',
    ];
    $menuSistema = [
        'usuarios.php',
        'configuracoes.php',
        'backup.php',
        'meus_dados.php',
    ];
    $menuSections = [
        'Operação' => $menuOperacao,
        'Gestão' => $menuGestao,
        'Sistema' => $menuSistema,
    ];
} elseif ($perfil === 'GERENTE') {
    // Principal: Dashboard
    $menuSections = [
        'Operação' => [
            'dashboard.php',
            'pedidos.php',
            'novo_pedido.php',
            'caixa_mesas.php',
            'vendas.php',
            'mesas.php',
            'cozinha.php',
            'bar.php',
            'caixa.php',
            'garcom_mesas.php',
        ],
        'Gestão' => [
            'clientes.php',
            'estoque_inteligente.php',
            'relatorios.php',
            'funcionarios_turnos.php',
        ],
    ];
} elseif ($perfil === 'CAIXA') {
    // Principal: Contas Abertas (ou Caixa)
    $menuCaixaPrincipal = $primaryActionByPerfil['CAIXA'];
    $menuCaixaOperacao = array_values(array_unique(array_filter([
        $menuCaixaPrincipal,
        'dashboard.php',
        'caixa_mesas.php',
        'vendas.php',
        'caixa.php',
    ])));
    $menuSections = [
        'Operação' => $menuCaixaOperacao,
        'Gestão' => [
            'relatorios.php',
        ],
    ];
} elseif ($perfil === 'GARCOM') {
    // Principal: Novo Pedido
    $menuSections = [
        'Operação' => [
            'novo_pedido.php',
            'dashboard.php',
            'pedidos.php',
            'mesas.php',
            'garcom_mesas.php',
        ],
    ];
} elseif ($perfil === 'COZINHA') {
    // Menu enxuto de posto: só a fila (sem Dashboard — padrão de restaurantes)
    $menuSections = [
        'Posto' => $temTelasBarCozinhaSidebar
            ? ['cozinha.php']
            : ['novo_pedido.php', 'vendas.php'],
    ];
} elseif ($perfil === 'BAR') {
    // Menu enxuto de posto: só a fila (sem Dashboard — padrão de restaurantes)
    $menuSections = [
        'Posto' => $temTelasBarCozinhaSidebar
            ? ['bar.php']
            : ['novo_pedido.php', 'vendas.php'],
    ];
} else {
    // Fallback: qualquer outro perfil tem Dashboard como principal
    $menuSections = [
        'Operação' => [
            'dashboard.php',
        ],
    ];
    $primaryAction = 'dashboard.php';
}

if ($perfil === 'GERENTE') {
    foreach ($menuSections as $sectionName => $sectionItems) {
        if (in_array('clientes.php', $sectionItems, true) && !in_array('promocoes.php', $sectionItems, true)) {
            array_unshift($menuSections[$sectionName], 'promocoes.php');
        }
    }
}

// Regra: filial nao pode gerir filiais.
if ($contextoFilialAtivo) {
    foreach ($menuSections as $nomeSecao => $itensSecao) {
        $menuSections[$nomeSecao] = array_values(array_filter(
            $itensSecao,
            static fn($item) => $item !== 'filiais.php'
        ));
    }
}

// Plano actual (tema visual + filtros de menu)
$plano_atual = 'BASICO';
try {
    $planoBrandDados = plano_get_dados($restaurantePlanId);
    $plano_atual = plano_normalizar_nome($planoBrandDados['plano_nome'] ?? 'BASICO');
} catch (Throwable $e) {
    // Silencioso: se algo falhar, mantemos o menu padrão.
}
$planThemeSlug = strtolower(preg_replace('/[^A-Z]/', '', (string)$plano_atual) ?: 'BASICO');
if ($planThemeSlug === '') {
    $planThemeSlug = 'basico';
}
$isEmpresarialSidebar = ($plano_atual === 'EMPRESARIAL');
if (!in_array($plano_atual, ['PROFISSIONAL', 'EMPRESARIAL'], true)) {
    foreach ($menuSections as $sectionName => $sectionItems) {
        $menuSections[$sectionName] = array_values(array_filter(
            $sectionItems,
            static fn($item) => $item !== 'promocoes.php'
        ));
    }
}
// Basico: no lugar do Estoque Inteligente aparece "Ingredientes" (versao simples).
if (!$temEstoqueAvancadoSidebar && $restaurantePlanId > 0 && plano_tem_funcionalidade_db($restaurantePlanId, 'estoque_ingredientes')) {
    $allItems['ingredientes.php'] = ['icon' => 'fa-carrot', 'label' => 'Ingredientes'];
    foreach ($menuSections as $sectionName => $sectionItems) {
        $menuSections[$sectionName] = array_map(
            static fn($item) => $item === 'estoque_inteligente.php' ? 'ingredientes.php' : $item,
            $sectionItems
        );
    }
}
$sidebarCounterPedidos = $isEmpresarialSidebar
    ? ['ADMIN', 'GERENTE', 'GARCOM']
    : ['GARCOM'];
$sidebarCounterContas = $isEmpresarialSidebar
    ? ['ADMIN', 'GERENTE', 'CAIXA']
    : ['CAIXA'];
$sidebarCounterReservas = ['ADMIN', 'GERENTE', 'GARCOM'];
$sidebarCounterEstoque = ($isEmpresarialSidebar && $temEstoqueAvancadoSidebar)
    ? ['ADMIN', 'GERENTE']
    : [];
?>
<script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
(function () {
    var cls = <?php echo json_encode('plan-theme-' . $planThemeSlug, JSON_UNESCAPED_UNICODE); ?>;
    if (document.body) {
        document.body.classList.add(cls);
        <?php if ($isEmpresarialSidebar): ?>
        document.body.classList.add('plano-empresarial');
        <?php endif; ?>
    } else {
        document.addEventListener('DOMContentLoaded', function () {
            document.body.classList.add(cls);
            <?php if ($isEmpresarialSidebar): ?>
            document.body.classList.add('plano-empresarial');
            <?php endif; ?>
        });
    }
})();
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/sidebar.css?v=<?php echo (int)@filemtime(__DIR__ . '/../css/sidebar.css'); ?>">
<link rel="stylesheet" href="css/tokens.css?v=<?php echo (int)@filemtime(__DIR__ . '/../css/tokens.css'); ?>">
<link rel="stylesheet" href="css/feedback.css?v=<?php echo (int)@filemtime(__DIR__ . '/../css/feedback.css'); ?>">
<nav class="sidebar sidebar-unified col-md-3 col-lg-2 d-md-block" id="appSidebar" aria-label="Menu principal">
    <?php
    $planoLabelSidebar = $plano_atual === 'BASICO' ? 'Básico'
        : ($plano_atual === 'PROFISSIONAL' ? 'Profissional'
        : ($plano_atual === 'EMPRESARIAL' ? 'Empresarial' : $restauranteBrandPlano));
    ?>
    <div class="sidebar-brand<?php echo $isEmpresarialSidebar ? ' is-empresarial' : ''; ?>">
        <button type="button" class="sidebar-collapse-btn" id="sidebarCollapseBtn" title="Esconder menu" aria-label="Esconder menu">
            <i class="fas fa-angles-left" aria-hidden="true"></i>
        </button>
        <div class="sidebar-brand-inner">
            <?php if ($isEmpresarialSidebar): ?>
                <div class="sidebar-emp-topline">
                    <p class="sidebar-emp-mark"><span class="sidebar-emp-mark-dot" aria-hidden="true"></span> NIVORA RESTAURANT</p>
                    <div class="sidebar-emp-pill"><i class="fas fa-crown" aria-hidden="true"></i> <span>Rede</span></div>
                </div>
                <div class="sidebar-emp-hero">
                    <div class="sidebar-brand-media-wrap">
                        <div class="sidebar-brand-media<?php echo $restauranteBrandHasLogo ? ' has-logo' : ''; ?>">
                            <?php if ($restauranteBrandHasLogo): ?>
                                <img src="<?php echo htmlspecialchars($restauranteBrandLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($restauranteBrandNome, ENT_QUOTES, 'UTF-8'); ?>" loading="lazy" decoding="async">
                            <?php else: ?>
                                <span aria-hidden="true"><?php echo htmlspecialchars($restauranteBrandInicial, ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="sidebar-brand-copy">
                        <h2 class="sidebar-brand-title"><?php echo htmlspecialchars($restauranteBrandNome, ENT_QUOTES, 'UTF-8'); ?></h2>
                        <div class="sidebar-brand-rule" aria-hidden="true"></div>
                        <div class="sidebar-brand-info">
                            <?php if ($restauranteBrandCidade !== ''): ?>
                                <span class="sidebar-brand-chip is-loc" title="Localização">
                                    <i class="fas fa-location-dot" aria-hidden="true"></i>
                                    <span><?php echo htmlspecialchars($restauranteBrandCidade, ENT_QUOTES, 'UTF-8'); ?></span>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($contextoFilialAtivo)): ?>
                                <span class="sidebar-brand-chip is-unit" title="Unidade / filial">
                                    <i class="fas fa-building" aria-hidden="true"></i>
                                    <span><?php echo htmlspecialchars($filialNomeSessao !== '' ? $filialNomeSessao : 'Filial activa', ENT_QUOTES, 'UTF-8'); ?></span>
                                </span>
                            <?php endif; ?>
                            <span class="sidebar-brand-chip is-plan" title="Plano activo">
                                <i class="fas fa-gem" aria-hidden="true"></i>
                                <span>Plano <?php echo htmlspecialchars($planoLabelSidebar, ENT_QUOTES, 'UTF-8'); ?></span>
                            </span>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="sidebar-brand-product">NIVORA RESTAURANT</div>
                <div class="sidebar-brand-media-wrap">
                    <div class="sidebar-brand-media<?php echo $restauranteBrandHasLogo ? ' has-logo' : ''; ?>">
                        <?php if ($restauranteBrandHasLogo): ?>
                            <img src="<?php echo htmlspecialchars($restauranteBrandLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($restauranteBrandNome, ENT_QUOTES, 'UTF-8'); ?>" loading="lazy" decoding="async">
                        <?php else: ?>
                            <span aria-hidden="true"><?php echo htmlspecialchars($restauranteBrandInicial, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="sidebar-brand-copy">
                    <h2 class="sidebar-brand-title"><?php echo htmlspecialchars($restauranteBrandNome, ENT_QUOTES, 'UTF-8'); ?></h2>
                    <div class="sidebar-brand-meta">
                        <?php if ($restauranteBrandCidade !== ''): ?>
                            <span class="sidebar-brand-kicker"><?php echo htmlspecialchars($restauranteBrandCidade, ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="sidebar-brand-meta-sep" aria-hidden="true"></span>
                        <?php endif; ?>
                        <span class="sidebar-brand-plan">Plano <?php echo htmlspecialchars($planoLabelSidebar, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="sidebar-menu">
        <?php if ($contextoFilialAtivo): ?>
            <div class="sidebar-context">
                <strong>Filial ativa</strong>
                <small><?php echo htmlspecialchars($filialNomeSessao !== '' ? $filialNomeSessao : ('Filial #' . $restauranteId)); ?></small>
                <small>Você está operando em uma unidade. O plano continua vinculado à matriz.</small>
                <form method="post" action="api/filial_voltar_matriz.php">
                    <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrfTokenSidebar, ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" class="btn-context">Voltar para matriz</button>
                </form>
            </div>
        <?php endif; ?>
        <?php $menuItemIndex = 0; ?>
        <?php foreach ($menuSections as $sectionTitle => $sectionItems): ?>
            <div class="menu-section">
                <div class="menu-title"><?php echo htmlspecialchars($sectionTitle); ?></div>

                <?php
                $directItems = $sectionItems;
                $extraItems = [];
                if ($collapseOperationalMenus && $sectionTitle === 'Operação' && count($sectionItems) > 4) {
                    $directItems = array_slice($sectionItems, 0, 4);
                    $extraItems = array_slice($sectionItems, 4);
                }
                ?>

                <?php foreach ($directItems as $url):
                    if (!isset($allItems[$url])) {
                        continue;
                    }
                    if ($url === 'estoque_inteligente.php' && !$temEstoqueAvancadoSidebar) {
                        continue;
                    }
                    if ($url === 'filiais.php' && !$temMultiFilialSidebar) {
                        continue;
                    }
                    if ($url === 'funcionarios_turnos.php' && !$temGestaoFuncionariosSidebar) {
                        continue;
                    }
                    if ($url === 'relatorios.php' && !$temRelatoriosSidebar) {
                        continue;
                    }
                    if ($url === 'backup.php' && !$temBackupSidebar) {
                        continue;
                    }
                    if ($url === 'clientes.php' && !$temHistoricoClientesSidebar) {
                        continue;
                    }
                    if ($url === 'pedidos.php' && !$temPedidosOnlineSidebar) {
                        continue;
                    }
                    if ($url === 'garcom_mesas.php' && !$temGarconsSidebar) {
                        continue;
                    }
                    if ($url === 'caixa_mesas.php' && !$temContasAbertasSidebar) {
                        continue;
                    }
                    if (($url === 'bar.php' || $url === 'cozinha.php') && !$temTelasBarCozinhaSidebar) {
                        continue;
                    }
                    $item = $allItems[$url];
                    $isActive = $currentPage === $url;
                    $isPrimary = $primaryAction === $url;
                    $menuItemIndex++;
                    $counterType = '';
                    if ($url === 'pedidos.php' && in_array($perfil, $sidebarCounterPedidos, true)) {
                        $counterType = 'pedidos';
                    }
                    if ($url === 'caixa_mesas.php' && in_array($perfil, $sidebarCounterContas, true)) {
                        $counterType = 'contas-abertas';
                    }
                    if ($url === 'mesas.php' && in_array($perfil, $sidebarCounterReservas, true)) {
                        $counterType = 'reservas';
                    }
                    if ($url === 'estoque_inteligente.php' && in_array($perfil, $sidebarCounterEstoque, true)) {
                        $counterType = 'estoque';
                    }
                ?>
                    <a class="menu-item <?php echo $isActive ? 'active' : ''; ?> <?php echo $isPrimary ? 'menu-item-primary' : ''; ?>" href="<?php echo htmlspecialchars($url); ?>" style="--item-index: <?php echo $menuItemIndex; ?>;" <?php echo $counterType !== '' ? 'data-counter-type="' . htmlspecialchars($counterType) . '"' : ''; ?>>
                        <i class="fas <?php echo $item['icon']; ?>"></i> <?php echo htmlspecialchars($item['label']); ?>
                        <?php if ($isPrimary): ?><span class="menu-item-badge">Principal</span><?php endif; ?>
                        <?php if ($counterType !== ''): ?>
                            <span class="menu-counter-badge is-empty<?php echo in_array($counterType, ['reservas', 'estoque'], true) ? ' menu-counter-alert' : ''; ?>" data-counter-badge="<?php echo htmlspecialchars($counterType); ?>">0</span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>

                <?php if (!empty($extraItems)): ?>
                    <?php $detailsId = 'menu-more-' . strtolower($perfil) . '-' . substr(md5($sectionTitle), 0, 8); ?>
                    <details class="menu-more" id="<?php echo htmlspecialchars($detailsId); ?>" data-menu-more>
                        <summary>
                            <span>Mais opções</span>
                            <i class="fas fa-chevron-down menu-more-icon"></i>
                        </summary>
                        <?php foreach ($extraItems as $url):
                            if (!isset($allItems[$url])) {
                                continue;
                            }
                            if ($url === 'estoque_inteligente.php' && !$temEstoqueAvancadoSidebar) {
                                continue;
                            }
                            if ($url === 'filiais.php' && !$temMultiFilialSidebar) {
                                continue;
                            }
                            if ($url === 'funcionarios_turnos.php' && !$temGestaoFuncionariosSidebar) {
                                continue;
                            }
                            if ($url === 'relatorios.php' && !$temRelatoriosSidebar) {
                                continue;
                            }
                            if ($url === 'backup.php' && !$temBackupSidebar) {
                                continue;
                            }
                            if ($url === 'clientes.php' && !$temHistoricoClientesSidebar) {
                                continue;
                            }
                            if ($url === 'pedidos.php' && !$temPedidosOnlineSidebar) {
                                continue;
                            }
                            if ($url === 'garcom_mesas.php' && !$temGarconsSidebar) {
                                continue;
                            }
                            if ($url === 'caixa_mesas.php' && !$temContasAbertasSidebar) {
                                continue;
                            }
                            if (($url === 'bar.php' || $url === 'cozinha.php') && !$temTelasBarCozinhaSidebar) {
                                continue;
                            }
                            $item = $allItems[$url];
                            $isActive = $currentPage === $url;
                            $isPrimary = $primaryAction === $url;
                            $menuItemIndex++;
                            $counterType = '';
                            if ($url === 'pedidos.php' && in_array($perfil, $sidebarCounterPedidos, true)) {
                                $counterType = 'pedidos';
                            }
                            if ($url === 'caixa_mesas.php' && in_array($perfil, $sidebarCounterContas, true)) {
                                $counterType = 'contas-abertas';
                            }
                            if ($url === 'mesas.php' && in_array($perfil, $sidebarCounterReservas, true)) {
                                $counterType = 'reservas';
                            }
                            if ($url === 'estoque_inteligente.php' && in_array($perfil, $sidebarCounterEstoque, true)) {
                                $counterType = 'estoque';
                            }
                        ?>
                            <a class="menu-item <?php echo $isActive ? 'active' : ''; ?> <?php echo $isPrimary ? 'menu-item-primary' : ''; ?>" href="<?php echo htmlspecialchars($url); ?>" style="--item-index: <?php echo $menuItemIndex; ?>; margin-top: 6px;" <?php echo $counterType !== '' ? 'data-counter-type="' . htmlspecialchars($counterType) . '"' : ''; ?>>
                                <i class="fas <?php echo $item['icon']; ?>"></i> <?php echo htmlspecialchars($item['label']); ?>
                                <?php if ($isPrimary): ?><span class="menu-item-badge">Principal</span><?php endif; ?>
                                <?php if ($counterType !== ''): ?>
                                    <span class="menu-counter-badge is-empty<?php echo in_array($counterType, ['reservas', 'estoque'], true) ? ' menu-counter-alert' : ''; ?>" data-counter-badge="<?php echo htmlspecialchars($counterType); ?>">0</span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </details>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="sidebar-footer">
        <div class="user-info">
            <div class="user-avatar"><?php echo substr($_SESSION['nome'] ?? 'U', 0, 2); ?></div>
            <div class="user-details">
                <div class="user-name"><?php echo htmlspecialchars($_SESSION['nome'] ?? 'Usuário'); ?></div>
                <?php if (strcasecmp(trim((string)($_SESSION['nome'] ?? '')), $perfilExibicaoHumano) !== 0): ?>
                    <div class="user-role"><?php echo htmlspecialchars($perfilExibicaoHumano); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php if (intval($_SESSION['super_admin'] ?? 0) !== 1 && turno_usuario_exige_turno_ativo($perfil)): ?>
            <a class="menu-item <?php echo $currentPage === 'iniciar_turno.php' ? 'active' : ''; ?>" href="iniciar_turno.php" style="margin-top: 12px;" <?php echo $currentPage === 'iniciar_turno.php' ? 'aria-current="page"' : ''; ?>><i class="fas fa-user-clock"></i> Meu Turno</a>
        <?php endif; ?>
        <?php
        require_once APP_BASE . '/src/config/terminal_pin.php';
        $sidebarEhSuper = intval($_SESSION['super_admin'] ?? 0) === 1;
        ?>
        <?php if (!$sidebarEhSuper && strtoupper((string)($_SESSION['perfil'] ?? '')) === 'ADMIN'): ?>
            <a class="menu-item <?php echo $currentPage === 'terminais.php' ? 'active' : ''; ?>" href="terminais.php" style="margin-top: 12px;" <?php echo $currentPage === 'terminais.php' ? 'aria-current="page"' : ''; ?>><i class="fas fa-desktop"></i> Terminais</a>
        <?php endif; ?>
        <?php if (!$sidebarEhSuper && terminal_perfil_usa_pin($perfil)): ?>
            <a class="menu-item <?php echo $currentPage === 'definir_pin.php' ? 'active' : ''; ?>" href="definir_pin.php" style="margin-top: 12px;" <?php echo $currentPage === 'definir_pin.php' ? 'aria-current="page"' : ''; ?>><i class="fas fa-key"></i> Definir PIN</a>
        <?php endif; ?>
        <?php if (terminal_tem_cookie()): ?>
            <a class="menu-item" href="terminal.php" style="margin-top: 12px;" title="Bloqueia o terminal para o próximo funcionário"><i class="fas fa-lock"></i> Bloquear</a>
        <?php endif; ?>
        <a class="menu-item" href="logout.php" style="margin-top: 12px;"><i class="fas fa-sign-out-alt"></i> Sair</a>
    </div>
</nav>
<?php
// Sem turno aberto as APIs do menu respondem 403: nesse caso nao vale a pena chamar.
$sidebarPrecisaTurno = intval($_SESSION['super_admin'] ?? 0) !== 1 && turno_usuario_exige_turno_ativo($perfil);
$sidebarTurnoAberto = true;
if ($sidebarPrecisaTurno && function_exists('turno_guard_usuario_tem_turno')) {
    $sidebarTurnoAberto = turno_guard_usuario_tem_turno((int)($_SESSION['usuario_id'] ?? 0), (int)$restauranteId);
}
$sidebarPodeConsultarApis = !$sidebarPrecisaTurno || $sidebarTurnoAberto;
?>
<script type="application/json" id="cfg-sidebar">{"v0": <?php echo (int)$restauranteId; ?>, "v1": <?php echo json_encode($perfil, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>, "v2": <?php echo $isEmpresarialSidebar ? 'true' : 'false'; ?>, "v3": <?php echo $sidebarPodeConsultarApis ? 'true' : 'false'; ?>}</script>
    <script src="js/nivora_dialogo.js?v=<?php echo (int)@filemtime(__DIR__ . '/../js/nivora_dialogo.js'); ?>"></script>
    <script src="js/feedback.js?v=<?php echo (int)@filemtime(__DIR__ . '/../js/feedback.js'); ?>"></script>
    <script src="js/autorizacao_gerente.js?v=20261108d" data-csrf="<?php echo htmlspecialchars((string)csrf_get_token(), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <script src="js/sidebar.js?v=<?php echo (int)@filemtime(__DIR__ . '/../js/sidebar.js'); ?>"></script>
<script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
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
            } catch (e) {
                // Fallback para fetch keepalive abaixo.
            }

            try {
                fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    keepalive: true,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
            } catch (e) {
                // Logout segue normalmente mesmo sem confirmar presenca offline.
            }
        };

        logoutLinks.forEach((link) => {
            link.addEventListener('click', markOffline, {
                passive: true
            });
        });
    })();
</script>
<script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
    // Garantia extra: fecha o menu mobile ao clicar em Backup ou Funcionários e Turnos
    document.addEventListener('DOMContentLoaded', function() {
        const body = document.body;
        const mobileQuery = window.matchMedia('(max-width: 1200px)');

        function closeMobileSidebar() {
            body.classList.remove('sidebar-mobile-open');
        }
        // Seleciona links pelo href
        const links = [
            document.querySelector('a.menu-item[href*="backup.php"]'),
            document.querySelector('a.menu-item[href*="funcionarios_turnos.php"]')
        ];
        links.forEach(function(link) {
            if (link) {
                link.addEventListener('click', function() {
                    if (mobileQuery.matches) {
                        closeMobileSidebar();
                        // Oculta o toggle imediatamente ao clicar
                        body.classList.add('funcionarios-turnos-navegando');
                        setTimeout(function() {
                            body.classList.remove('funcionarios-turnos-navegando');
                        }, 1200); // tempo suficiente para navegação
                    }
                });
            }
        });
    });
</script>
