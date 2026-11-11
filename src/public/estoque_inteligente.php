<?php
require_once __DIR__ . '/app_base.php';
include_once APP_BASE . '/src/config/auth_check.php';
include_once APP_BASE . '/src/config/plano_check.php';
include_once APP_BASE . '/src/config/restaurante_context.php';
include_once APP_BASE . '/src/config/csrf.php';

// Estoque inteligente: ADMIN e GERENTE veem e operam tudo (insumos, ficha, movimentos, perdas, alertas, transferência).
requirePermissionOrRedirect(['ADMIN', 'GERENTE']);

$restauranteId = session_restaurante_contexto_id();
if ($restauranteId <= 0) {
    header('Location: index.php?erro=sem_restaurante');
    exit;
}

$planoRid = session_restaurante_capability_id();
$planoRid = $planoRid > 0 ? $planoRid : $restauranteId;
if (!plano_tem_funcionalidade_db($planoRid, 'estoque_avancado')) {
    header('Location: dashboard.php?erro=funcionalidade_bloqueada&funcionalidade=estoque_avancado');
    exit;
}

$temTransferenciaEstoque = plano_tem_funcionalidade_db($planoRid, 'transferencia_estoque');
$dadosPlanoEstoque = function_exists('plano_get_dados') ? plano_get_dados($planoRid) : [];
$planoEstoqueNome = function_exists('plano_normalizar_nome')
    ? plano_normalizar_nome($dadosPlanoEstoque['plano_nome'] ?? 'BASICO')
    : 'BASICO';
// Cockpit completo (previsão 28d / reposição IA) = Empresarial. Profissional = fichas + baixa + operação.
$isEstoqueEmpCockpit = ($planoEstoqueNome === 'EMPRESARIAL');
$estoqueDefaultTab = $isEstoqueEmpCockpit ? 'insights' : 'fichas';
$csrfToken = csrf_get_token();

$opcoesFiliaisTransferencia = [
    ['id' => 0, 'nome' => 'Stock central (matriz)'],
];
if ($temTransferenciaEstoque) {
    try {
        include_once APP_BASE . '/src/config/database.php';
        $dbFiliais = (new Database())->getConnection();
        if ($dbFiliais instanceof PDO) {
            $stmtFil = $dbFiliais->prepare("
                SELECT id, nome
                FROM restaurantes
                WHERE filial_id = :matriz AND COALESCE(is_matriz, 0) = 0
                ORDER BY nome ASC
            ");
            $stmtFil->execute([':matriz' => $planoRid]);
            foreach ($stmtFil->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $opcoesFiliaisTransferencia[] = [
                    'id' => (int)$f['id'],
                    'nome' => (string)$f['nome'],
                ];
            }
        }
    } catch (Throwable $e) {
        // Mantém só stock central.
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <script src="js/botao_atualizar.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/botao_atualizar.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estoque Inteligente - RestauranteSaaS</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link href="lib/bootstrap-5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="lib/fontawesome-6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/premium.css">
    <link rel="stylesheet" href="css/estoque_inteligente.css?v=<?php echo (int)@filemtime(__DIR__ . '/css/estoque_inteligente.css'); ?>">
</head>
<body class="premium-ui<?php echo $isEstoqueEmpCockpit ? ' estoque-emp' : ''; ?>">
<input type="hidden" id="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
<div class="container-fluid">
    <div class="row">
        <?php include_once __DIR__ . '/includes/sidebar.php'; ?>
        <main class="main-content col-md-9 ms-sm-auto col-lg-10">
            <div class="topbar">
                <div>
                    <h1><i class="fas fa-boxes-stacked text-primary me-2"></i>Estoque Inteligente <?php include __DIR__ . '/includes/plan_page_badge.php'; ?></h1>
                    <div class="topbar-meta"><?php
                        echo $isEstoqueEmpCockpit
                            ? 'Cockpit Empresarial — previsão 28d, FIFO por lote, reposição e alertas automáticos.'
                            : 'Fichas técnicas, insumos e baixa automática nas vendas — gestão do dia a dia.';
                    ?></div>
                </div>
                <div class="topbar-actions">
                    <button type="button" class="btn btn-dark btn-sm" id="btnAtualizar" data-atualizar><i class="fas fa-rotate me-1"></i>Recalcular</button>
                </div>
            </div>

            <div class="content-area">
                <div class="ei-aviso-custo" id="avisoSemCusto" hidden>
                    <i class="fas fa-coins"></i>
                    <div><strong id="avisoSemCustoTitulo"></strong><span id="avisoSemCustoLista"></span></div>
                    <button type="button" class="btn btn-sm btn-warning" id="avisoSemCustoBtn">Registar entrada com custo</button>
                </div>
                <div class="row g-3 mb-2" id="kpiRow">
                    <div class="col-6 <?php echo $isEstoqueEmpCockpit ? 'col-xl-2' : 'col-xl-3'; ?>">
                        <div class="panel">
                            <div class="panel-body card-metric">
                                <div>
                                    <div class="kpi mono" id="kpiInsumos">0</div>
                                    <div class="kpi-label">Insumos activos</div>
                                    <div class="kpi-sub" id="kpiInsumosSub">—</div>
                                </div>
                                <span class="card-metric-icon"><i class="fas fa-layer-group"></i></span>
                            </div>
                        </div>
                    </div>
                    <?php if ($isEstoqueEmpCockpit): ?>
                    <div class="col-6 col-xl-2">
                        <div class="panel">
                            <div class="panel-body card-metric">
                                <div>
                                    <div class="kpi mono" id="kpiScore">—</div>
                                    <div class="kpi-label">Estado do estoque</div>
                                    <div class="kpi-sub" id="kpiScoreSub">A calcular situacao</div>
                                </div>
                                <span class="card-metric-icon"><i class="fas fa-heart-pulse"></i></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-xl-2">
                        <div class="panel">
                            <div class="panel-body card-metric">
                                <div>
                                    <div class="kpi mono" id="kpiCobertura">0 d</div>
                                    <div class="kpi-label">Cobertura média</div>
                                    <div class="kpi-sub">com base no consumo real</div>
                                </div>
                                <span class="card-metric-icon"><i class="fas fa-calendar-day"></i></span>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="col-6 col-xl-3">
                        <div class="panel">
                            <div class="panel-body card-metric">
                                <div>
                                    <div class="kpi mono" id="kpiSemFichaPro">0</div>
                                    <div class="kpi-label">Pratos sem ficha</div>
                                    <div class="kpi-sub">complete para baixa automática</div>
                                </div>
                                <span class="card-metric-icon"><i class="fas fa-clipboard-list"></i></span>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="col-6 <?php echo $isEstoqueEmpCockpit ? 'col-xl-2' : 'col-xl-3'; ?>">
                        <div class="panel">
                            <div class="panel-body card-metric">
                                <div>
                                    <div class="kpi mono" id="kpiValorStock">0 MZN</div>
                                    <div class="kpi-label">Valor em stock</div>
                                    <div class="kpi-sub">lotes / custo médio</div>
                                </div>
                                <span class="card-metric-icon"><i class="fas fa-coins"></i></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 <?php echo $isEstoqueEmpCockpit ? 'col-xl-2' : 'col-xl-3'; ?>">
                        <div class="panel" id="kpiAlertasCard" role="button" tabindex="0" title="Ver os alertas" style="cursor:pointer">
                            <div class="panel-body card-metric">
                                <div>
                                    <div class="kpi mono" id="kpiAlertas">0</div>
                                    <div class="kpi-label">Alertas abertos</div>
                                    <div class="kpi-sub" id="kpiAlertasSub"><?php echo $isEstoqueEmpCockpit ? 'reposição · validade · anomalia' : 'validade · stock · anomalia'; ?></div>
                                </div>
                                <span class="card-metric-icon"><i class="fas fa-exclamation-triangle"></i></span>
                            </div>
                        </div>
                    </div>
                    <?php if ($isEstoqueEmpCockpit): ?>
                    <div class="col-6 col-xl-2">
                        <div class="panel">
                            <div class="panel-body card-metric">
                                <div>
                                    <div class="kpi mono" id="kpiPerdas">0 MZN</div>
                                    <div class="kpi-label">Perdas 30d</div>
                                    <div class="kpi-sub" id="kpiPerdasSub">—</div>
                                </div>
                                <span class="card-metric-icon"><i class="fas fa-chart-line"></i></span>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="col-6 col-xl-3" style="display:none;">
                        <div class="kpi mono" id="kpiScore">—</div>
                        <div class="kpi mono" id="kpiCobertura">0 d</div>
                        <div class="kpi mono" id="kpiPerdas">0 MZN</div>
                                    <div class="kpi-sub" id="kpiScoreSub">A calcular situacao</div>
                        <div class="kpi-sub" id="kpiPerdasSub"></div>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (!$isEstoqueEmpCockpit): ?>
                <div class="ei-estado-chips" id="eiEstadoPro" aria-label="Estado do estoque">
                    <span class="ei-chip" id="chipCriticosPro"><i class="fas fa-circle"></i> Insumos cr&iacute;ticos <b>0</b></span>
                    <span class="ei-chip" id="chipValidadePro"><i class="fas fa-circle"></i> Validade &le; 7 dias <b>0</b></span>
                    <span class="ei-chip" id="chipSemCustoPro"><i class="fas fa-circle"></i> Sem custo registado <b>0</b></span>
                    <span class="ei-chip" id="chipAlertasPro"><i class="fas fa-circle"></i> Alertas abertos <b>0</b></span>
                </div>
                <?php endif; ?>

                <nav class="estoque-tabs" id="estoqueTabs" role="tablist" aria-label="Tarefas de estoque">
                    <?php if ($isEstoqueEmpCockpit): ?>
                    <button type="button" class="estoque-tab is-active" data-estoque-tab="insights" role="tab" aria-selected="true">
                        <i class="fas fa-chart-line"></i> Visão &amp; compra
                    </button>
                    <?php endif; ?>
                    <button type="button" class="estoque-tab<?php echo !$isEstoqueEmpCockpit ? ' is-active' : ''; ?>" data-estoque-tab="fichas" role="tab" aria-selected="<?php echo !$isEstoqueEmpCockpit ? 'true' : 'false'; ?>">
                        <i class="fas fa-utensils"></i> Fichas
                    </button>
                    <button type="button" class="estoque-tab" data-estoque-tab="cadastro" role="tab" aria-selected="false">
                        <i class="fas fa-plus-circle"></i> Cadastro
                    </button>
                    <button type="button" class="estoque-tab" data-estoque-tab="movimentos" role="tab" aria-selected="false">
                        <i class="fas fa-right-left"></i> Movimentos
                    </button>
                    <button type="button" class="estoque-tab" data-estoque-tab="perdas" role="tab" aria-selected="false">
                        <i class="fas fa-trash-can"></i> Perdas
                    </button>
                </nav>

                <!-- ABA: Visão & compra (só Empresarial) -->
                <div class="estoque-tab-pane<?php echo $isEstoqueEmpCockpit ? ' is-active' : ''; ?>" data-estoque-pane="insights" role="tabpanel"<?php echo $isEstoqueEmpCockpit ? '' : ' hidden'; ?>>
                    <div class="estoque-tab-intro">
                        <strong>Cockpit de compra</strong> — previsão por dia da semana + tendência 7d, lead time 5d, validade FIFO e reequilíbrio entre filiais.
                        <span id="empScoreBreakdown" class="ms-1"></span>
                    </div>
                    <div class="estoque-intel-strip" id="intelStripVisao">
                        <div class="estoque-intel-card" id="intelCriticos">
                            <div class="ei-label">Críticos previstos</div>
                            <div class="ei-value" id="intelCriticosVal">0</div>
                            <div class="ei-hint">cobertura &lt; 1,5 d ou saldo zero</div>
                        </div>
                        <div class="estoque-intel-card" id="intelReposicao">
                            <div class="ei-label">Reposição sugerida</div>
                            <div class="ei-value" id="intelReposicaoVal">0 MZN</div>
                            <div class="ei-hint" id="intelReposicaoHint">0 linhas de compra</div>
                        </div>
                        <div class="estoque-intel-card" id="intelSemFicha">
                            <div class="ei-label">Pratos sem ficha</div>
                            <div class="ei-value" id="intelSemFichaVal">0</div>
                            <div class="ei-hint">baixa automática incompleta</div>
                        </div>
                        <div class="estoque-intel-card" id="intelLotesRisco">
                            <div class="ei-label">Lotes em risco</div>
                            <div class="ei-value" id="intelLotesRiscoVal">0</div>
                            <div class="ei-hint">vencidos / ≤7 dias</div>
                        </div>
                        <div class="estoque-intel-card" id="intelLead">
                            <div class="ei-label">Lead time</div>
                            <div class="ei-value" id="intelLeadVal">5 d</div>
                            <div class="ei-hint" id="intelLeadHint">+ 3d segurança</div>
                        </div>
                    </div>
                    <div class="panel mb-3" id="panelInsights" style="display:none;">
                        <div class="panel-head d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <span><i class="fas fa-brain text-primary me-1"></i>Ordem de reposição inteligente</span>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="badge-soft" id="insightCustoReposicao">Reposição estimada: 0 MZN</span>
                                <button type="button" class="btn btn-outline-primary btn-sm" id="btnAplicarMinimos">
                                    <i class="fas fa-sliders me-1"></i>Aplicar mínimos sugeridos
                                </button>
                            </div>
                        </div>
                        <div class="panel-body">
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Insumo</th>
                                            <th>Cobertura</th>
                                            <th>Média/dia</th>
                                            <th>Prev. 7d</th>
                                            <th>Comprar</th>
                                            <th>Custo est.</th>
                                            <th>Risco</th>
                                            <th>Motivo</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody id="tblSugestoes"></tbody>
                                </table>
                            </div>
                            <div class="panel-note" id="insightSemFichaNote"></div>
                        </div>
                    </div>

                    <div class="panel mb-3" id="panelRedeTransferencias" style="display:none;">
                        <div class="panel-head d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <span><i class="fas fa-shuffle text-primary me-1"></i>Reequilíbrio entre filiais</span>
                            <a href="#movimentos" class="btn btn-outline-secondary btn-sm" data-goto-tab="movimentos">Ir a transferências</a>
                        </div>
                        <div class="panel-body">
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Insumo</th>
                                            <th>Origem</th>
                                            <th>Destino</th>
                                            <th>Qtd</th>
                                            <th>Motivo</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tblRedeTransferencias"></tbody>
                                </table>
                            </div>
                            <div class="panel-note">Sugestões por SKU partilhado: excesso numa unidade e falta noutra. Confirme a transferência na aba Movimentos.</div>
                        </div>
                    </div>

                    <div class="panel mb-3" id="panelLotesValidade" style="display:none;">
                        <div class="panel-head"><i class="fas fa-calendar-xmark text-danger me-1"></i>Lotes por validade</div>
                        <div class="panel-body">
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead><tr><th>Insumo</th><th>Saldo lote</th><th>Validade</th><th>Estado</th><th>Ação</th></tr></thead>
                                    <tbody id="tblLotesValidade"></tbody>
                                </table>
                            </div>
                            <div class="panel-note">Cada entrada cria um lote novo. Lotes antigos vencidos continuam aqui até o stock ser consumido (FIFO) ou descartado.</div>
                        </div>
                    </div>

                    <div class="panel mb-3">
                        <div class="panel-head">Saldos e Consumo Previsto</div>
                        <div class="panel-body">
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead><tr><th>Insumo</th><th>Saldo</th><th>Mínimo</th><th>Validade</th><th>Cobertura</th><th>Prev. 7 dias</th><th>Status</th><th>Ação</th></tr></thead>
                                    <tbody id="tblSaldos"></tbody>
                                </table>
                            </div>
                            <div class="panel-note">Validade = lote mais próximo a vencer (com saldo). Cobertura e compra usam lead time + perfil semanal (Empresarial).</div>
                        </div>
                    </div>

                    <div class="panel" id="painelAlertas">
                        <div class="panel-head d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <span><i class="fas fa-triangle-exclamation text-warning me-1"></i>Alertas Operacionais <span class="badge text-bg-warning ms-1" id="painelAlertasConta" hidden></span></span>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnResolverTodosAlertas" aria-expanded="false" aria-controls="confirmResolverTodosAlertas">
                                <i class="fas fa-check-double me-1"></i>Resolver todos
                            </button>
                        </div>
                        <div class="panel-body">
                            <div id="confirmResolverTodosAlertas" class="alert alert-warning d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3" role="group" aria-labelledby="tituloConfirmarTodosAlertas" aria-describedby="descricaoConfirmarTodosAlertas" hidden>
                                <div>
                                    <p class="fw-semibold mb-1" id="tituloConfirmarTodosAlertas">Resolver todos os alertas abertos?</p>
                                    <p class="mb-0" id="descricaoConfirmarTodosAlertas">Esta ação marcará todos os alertas operacionais abertos como resolvidos.</p>
                                </div>
                                <div class="d-flex flex-wrap gap-2">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btnCancelarResolverTodosAlertas">Cancelar</button>
                                    <button type="button" class="btn btn-warning btn-sm" id="btnConfirmarResolverTodosAlertas">Resolver todos</button>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead><tr><th>Sev.</th><th>Tipo</th><th>Título</th><th>Detalhe</th><th></th></tr></thead>
                                    <tbody id="tblAlertas"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ABA: Cadastro -->
                <div class="estoque-tab-pane" data-estoque-pane="cadastro" role="tabpanel">
                    <div class="estoque-tab-intro">
                        <?php if ($isEstoqueEmpCockpit): ?>
                        <strong>Master de insumos</strong> — cadastre em kg / litro / unidade. O motor guarda em base (g/ml/un) e alimenta previsão, FIFO e fichas.
                        <?php else: ?>
                        <strong>Cadastro de insumos</strong> — kg / litro / unidade. Serve as fichas técnicas e a baixa automática nas vendas.
                        <?php endif; ?>
                    </div>
                    <div class="row g-3">
                        <div class="col-lg-6">
                            <div class="panel mb-3" id="panelCadastroInsumo">
                                <div class="panel-head d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                    <span id="wizardPanelTitle">Novo insumo</span>
                                    <button type="button" class="btn btn-outline-secondary btn-sm py-1 px-2" id="btnWizardReiniciar" style="display:none;">Começar de novo</button>
                                </div>
                                <div class="panel-body">
                                    <div id="wizardEditMode" style="display:none;">
                                        <div class="row g-2">
                                            <div class="col-12">
                                                <label class="form-label small mb-1" for="insumoNome">Nome</label>
                                                <input class="form-control form-control-sm" id="insumoNome" placeholder="Nome do insumo">
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label small mb-1" for="insumoUnidade">Unidade</label>
                                                <select class="form-select form-select-sm" id="insumoUnidade">
                                                    <option value="kg">kg</option>
                                                    <option value="l">litro</option>
                                                    <option value="un">unidade</option>
                                                    <option value="fatia">fatia</option>
                                                </select>
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label small mb-1" for="insumoMinimo">Mínimo</label>
                                                <div class="wizard-input-with-unit">
                                                    <input class="form-control form-control-sm" id="insumoMinimo" type="number" step="0.001" min="0" placeholder="Stock mínimo">
                                                    <span class="wizard-unit-suffix" id="editMinimoSuffix">kg</span>
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <input class="form-control form-control-sm" id="insumoSku" placeholder="Gerado automaticamente" readonly disabled>
                                            </div>
                                            <div class="col-6"><button type="button" class="btn btn-outline-secondary btn-sm w-100" id="btnCancelarEdicaoInsumo">Cancelar</button></div>
                                            <div class="col-6"><button type="button" class="btn btn-primary btn-sm w-100" id="btnSalvarInsumo">Actualizar</button></div>
                                        </div>
                                        <div class="panel-note" id="insumoFormNote"></div>
                                    </div>

                                    <div id="wizardNovoMode">
                                        <div class="wizard-steps" aria-label="Passos do cadastro">
                                            <div class="wizard-step-pill is-active" data-step-pill="1">1. O que é</div>
                                            <div class="wizard-step-pill" data-step-pill="2">2. Quantidades</div>
                                            <div class="wizard-step-pill" data-step-pill="3">3. Confirmar</div>
                                        </div>

                                        <div class="wizard-pane is-active" data-wizard-pane="1">
                                            <label class="form-label small mb-1" for="wizNome">Nome do insumo</label>
                                            <input class="form-control form-control-sm mb-3" id="wizNome" placeholder="Nome do insumo">
                                            <div class="small text-muted mb-2">Como mede este ingrediente?</div>
                                            <div class="wizard-type-grid" role="group" aria-label="Tipo de medida">
                                                <button type="button" class="wizard-type-btn is-selected" data-wiz-tipo="peso">
                                                    <strong>Peso (kg)</strong>
                                                    <span>Tomate, carne, alface, queijo…</span>
                                                </button>
                                                <button type="button" class="wizard-type-btn" data-wiz-tipo="liquido">
                                                    <strong>Líquido (litro)</strong>
                                                    <span>Óleo, molhos, leite…</span>
                                                </button>
                                                <button type="button" class="wizard-type-btn" data-wiz-tipo="contagem">
                                                    <strong>Contagem (un)</strong>
                                                    <span>Pão, ovo, latas…</span>
                                                </button>
                                            </div>
                                            <div class="form-check mt-3" id="wizFatiaWrap" style="display:none;">
                                                <input class="form-check-input" type="checkbox" id="wizUsarFatia">
                                                <label class="form-check-label small" for="wizUsarFatia">Usar “fatia” em vez de unidade (ex.: queijo fatiado)</label>
                                            </div>
                                            <div class="panel-note">Escolha kg / litro / unidades. O sistema converte gramas e ml automaticamente.</div>
                                            <div class="wizard-actions">
                                                <button type="button" class="btn btn-primary btn-sm" id="btnWizNext1">Continuar</button>
                                            </div>
                                        </div>

                                        <div class="wizard-pane" data-wizard-pane="2">
                                            <label class="form-label small mb-1" for="wizMinimo">Mínimo em stock (quando repor)</label>
                                            <div class="wizard-input-with-unit mb-3">
                                                <input class="form-control form-control-sm" id="wizMinimo" type="number" step="0.001" min="0" placeholder="Stock mínimo">
                                                <span class="wizard-unit-suffix" id="wizMinimoSuffix">kg</span>
                                            </div>
                                            <div class="small fw-semibold mb-2">Primeira entrada (opcional, recomendado)</div>
                                            <div class="row g-2">
                                                <div class="col-6">
                                                    <label class="form-label small mb-1" for="wizQtdInicial">Quantidade</label>
                                                    <div class="wizard-input-with-unit">
                                                        <input class="form-control form-control-sm" id="wizQtdInicial" type="number" step="0.001" min="0" placeholder="Stock inicial">
                                                        <span class="wizard-unit-suffix" id="wizQtdSuffix">kg</span>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <label class="form-label small mb-1" for="wizCusto">Custo / <span id="wizCustoUnidadeLabel">kg</span></label>
                                                    <input class="form-control form-control-sm" id="wizCusto" type="number" step="0.01" min="0" placeholder="Custo unitário">
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label small mb-1" for="wizValidade">Validade do lote</label>
                                                    <input class="form-control form-control-sm" id="wizValidade" type="date">
                                                </div>
                                            </div>
                                            <div class="wizard-preview" id="wizPreview2">Preencha o mínimo para ver o resumo.</div>
                                            <div class="wizard-actions">
                                                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnWizBack2">Voltar</button>
                                                <button type="button" class="btn btn-primary btn-sm" id="btnWizNext2">Continuar</button>
                                            </div>
                                        </div>

                                        <div class="wizard-pane" data-wizard-pane="3">
                                            <div class="wizard-preview" id="wizResumo" style="background:#eff6ff;border-color:#bfdbfe;color:#1e3a8a;"></div>
                                            <div class="wizard-actions">
                                                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnWizBack3">Voltar</button>
                                                <button type="button" class="btn btn-success btn-sm" id="btnWizCriar">Criar insumo</button>
                                            </div>
                                            <div class="panel-note mt-2" id="wizSucessoNote" style="display:none;"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="panel mb-3">
                                <div class="panel-head panel-head-intel">
                                    <span><?php echo $isEstoqueEmpCockpit ? 'Prontidão operacional' : 'Resumo do estoque'; ?></span>
                                    <?php if ($isEstoqueEmpCockpit): ?>
                                    <span class="badge-soft-intel" id="readinessScoreBadge">Score —</span>
                                    <?php else: ?>
                                    <span class="badge-soft-intel" id="readinessScoreBadge" style="display:none;">Score —</span>
                                    <?php endif; ?>
                                </div>
                                <div class="panel-body">
                                    <ul class="readiness-list" id="readinessList">
                                        <li>
                                            <span class="rk">Insumos activos</span>
                                            <span class="rv" id="readyInsumos">—</span>
                                        </li>
                                        <li>
                                            <span class="rk">Pratos sem ficha técnica</span>
                                            <span class="rv" id="readySemFicha">—</span>
                                        </li>
                                        <?php if ($isEstoqueEmpCockpit): ?>
                                        <li>
                                            <span class="rk">Insumos críticos (previsão)</span>
                                            <span class="rv" id="readyCriticos">—</span>
                                        </li>
                                        <li>
                                            <span class="rk">Linhas de reposição abertas</span>
                                            <span class="rv" id="readySugestoes">—</span>
                                        </li>
                                        <?php endif; ?>
                                        <li>
                                            <span class="rk">Valor imobilizado</span>
                                            <span class="rv" id="readyValor">—</span>
                                        </li>
                                    </ul>
                                    <div class="estoque-action-row">
                                        <button type="button" class="btn btn-outline-primary btn-sm" data-goto-tab="fichas">
                                            <i class="fas fa-utensils me-1"></i>Completar fichas
                                        </button>
                                        <?php if ($isEstoqueEmpCockpit): ?>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" data-goto-tab="insights">
                                            <i class="fas fa-chart-line me-1"></i>Ver reposição
                                        </button>
                                        <?php else: ?>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" data-goto-tab="movimentos">
                                            <i class="fas fa-cart-plus me-1"></i>Registar entrada
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="panel-note mt-3 mb-0" id="readinessNote">
                                        <?php echo $isEstoqueEmpCockpit
                                            ? 'Números em tempo real do motor Empresarial (previsão 28 dias, score de saúde, valor por lotes).'
                                            : 'Resumo operacional: insumos, fichas em falta e valor em stock. Complete as fichas para a baixa automática nas vendas.'; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ABA: Movimentos -->
                <div class="estoque-tab-pane" data-estoque-pane="movimentos" role="tabpanel">
                    <div class="estoque-tab-intro">
                        <strong>Receção &amp; ajuste</strong> — entradas criam lotes (FIFO + validade). A saída por venda vem das fichas; use saída manual só para correções.
                    </div>
                    <div class="row g-3">
                        <div class="col-lg-6">
                            <div class="panel mb-3">
                                <div class="panel-head">Adicionar ou Baixar Estoque</div>
                                <div class="panel-body">
                                    <div class="row g-2">
                                        <div class="col-7"><label class="form-label small mb-1" for="movInsumo">Insumo</label><select class="form-select form-select-sm" id="movInsumo"></select></div>
                                        <div class="col-5"><label class="form-label small mb-1" for="movTipo">Operação</label><select class="form-select form-select-sm" id="movTipo"><option value="ENTRADA">Adicionar estoque</option><option value="SAIDA">Baixar estoque</option></select></div>
                                        <div class="col-4"><label class="form-label small mb-1" for="movQtd">Quantidade</label><input class="form-control form-control-sm" id="movQtd" type="number" step="0.001" placeholder="0"></div>
                                        <div class="col-4"><label class="form-label small mb-1" for="movUnidade">Unidade</label><select class="form-select form-select-sm" id="movUnidade" title="Sugestão automática do cadastro — pode alterar"></select></div>
                                        <div class="col-4"><label class="form-label small mb-1" for="movCusto">Custo unitário (MZN)</label><input class="form-control form-control-sm" id="movCusto" type="number" step="0.0001" placeholder="0,00" title="Custo na unidade do insumo"></div>
                                        <div class="col-8"><label class="form-label small mb-1" for="movRef">Referência <span class="text-muted">(opcional)</span></label><input class="form-control form-control-sm" id="movRef" placeholder="N.º da fatura do fornecedor ou nota"></div>
                                        <div class="col-4" id="movValidadeWrap">
                                            <label class="form-label small mb-1" for="movValidade">Validade do lote</label>
                                            <input class="form-control form-control-sm" id="movValidade" type="date">
                                        </div>
                                        <div class="col-12"><button type="button" class="btn btn-success btn-sm w-100" id="btnSalvarMov">Salvar Movimentação</button></div>
                                    </div>
                                    <div id="movFeedback" class="stock-feedback" role="status" aria-live="polite" aria-atomic="true" hidden></div>
                                    <div class="panel-note" id="movNote">A unidade vem sugerida do cadastro do insumo (podes alterar). Em <strong>Adicionar estoque</strong>, indique a validade do lote.</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
<?php if ($temTransferenciaEstoque): ?>
                        <div class="panel mb-3">
                            <div class="panel-head">Transferir entre filiais</div>
                            <div class="panel-body">
                                <div class="row g-2">
                                    <div class="col-12"><label class="form-label small mb-1" for="trfInsumo">Insumo</label><select class="form-select form-select-sm" id="trfInsumo"></select></div>
                                    <div class="col-6">
                                        <label class="form-label small mb-1">Origem</label>
                                        <select class="form-select form-select-sm" id="trfOrigem">
                                            <?php foreach ($opcoesFiliaisTransferencia as $opt): ?>
                                                <option value="<?php echo (int)$opt['id']; ?>"><?php echo htmlspecialchars($opt['nome'], ENT_QUOTES, 'UTF-8'); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label small mb-1">Destino</label>
                                        <select class="form-select form-select-sm" id="trfDestino">
                                            <?php foreach ($opcoesFiliaisTransferencia as $opt): ?>
                                                <option value="<?php echo (int)$opt['id']; ?>"><?php echo htmlspecialchars($opt['nome'], ENT_QUOTES, 'UTF-8'); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-4"><label class="form-label small mb-1" for="trfQtd">Quantidade</label><input class="form-control form-control-sm" id="trfQtd" type="number" step="0.001" placeholder="0"></div>
                                    <div class="col-4"><label class="form-label small mb-1" for="trfUnidade">Unidade</label><select class="form-select form-select-sm" id="trfUnidade" title="Sugestão automática do cadastro — pode alterar"></select></div>
                                    <div class="col-4"><label class="form-label small mb-1" for="trfMotivo">Motivo</label><input class="form-control form-control-sm" id="trfMotivo" placeholder="Motivo da transferência"></div>
                                    <div class="col-12"><button type="button" class="btn btn-primary btn-sm w-100" id="btnTransferirEstoque">Transferir agora</button></div>
                                </div>
                                <div class="panel-note">Disponível no plano Empresarial. Move saldo do stock central ou entre filiais da mesma matriz.</div>
                            </div>
                        </div>
<?php else: ?>
                            <div class="panel">
                                <div class="panel-head">Dica de operação</div>
                                <div class="panel-body" style="font-size:13px;line-height:1.5;color:#334155;">
                                    <strong>Entrada</strong> = mercadoria que chegou (compra).<br>
                                    <strong>Saída manual</strong> = ajuste raro (contagem, correção).<br>
                                    Consumo dos pratos não se regista aqui — sai pela <em>ficha técnica</em> nas vendas.
                                </div>
                            </div>
<?php endif; ?>
                        </div>
                    </div>

                <div class="row g-3 mt-1" data-ei-extra-movimentos>
                    <div class="col-lg-7">
                        <div class="panel">
                            <div class="panel-head d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-clock-rotate-left me-1"></i>Últimos movimentos</span>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnRecarregarMovs"><i class="fas fa-rotate"></i></button>
                            </div>
                            <div class="panel-body" id="eiMovsRecentes"><div class="text-muted small">A carregar…</div></div>
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <div class="panel">
                            <div class="panel-head"><i class="fas fa-clipboard-check me-1"></i>Contagem de inventário</div>
                            <div class="panel-body">
                                <p class="small text-muted mb-2">Conte o stock físico (por exemplo no fim do mês), escreva as quantidades e o sistema acerta as diferenças, com registo de quem fez.</p>
                                <button type="button" class="btn btn-primary btn-sm w-100" id="btnAbrirContagem"><i class="fas fa-list-check me-1"></i>Iniciar contagem</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
                <!-- (fim aba movimentos) -->
                <!-- ABA: Perdas -->
                <div class="estoque-tab-pane" data-estoque-pane="perdas" role="tabpanel">
                    <div class="estoque-tab-intro">
                        <?php if ($isEstoqueEmpCockpit): ?>
                        <strong>Perdas controladas</strong> — registe estrago/desperdício à parte da venda. Alimenta o KPI de 30 dias e o score de saúde.
                        <?php else: ?>
                        <strong>Perdas controladas</strong> — registe estrago ou desperdício à parte da venda, para não misturar com a baixa automática das fichas.
                        <?php endif; ?>
                    </div>
                    <div class="row g-3">
                        <div class="col-lg-6">
                            <div class="panel">
                                <div class="panel-head">Registrar Perda ou Desperdício</div>
                                <div class="panel-body">
                                    <div class="row g-2">
                                        <div class="col-7"><select class="form-select form-select-sm" id="perdaInsumo"></select></div>
                                        <div class="col-5"><input class="form-control form-control-sm" id="perdaQtd" type="number" step="0.001" placeholder="Quantidade perdida"></div>
                                        <div class="col-6"><select class="form-select form-select-sm" id="perdaUnidade" title="Sugestão automática do cadastro — pode alterar"></select></div>
                                        <div class="col-6"><input class="form-control form-control-sm" id="perdaCusto" type="number" step="0.0001" placeholder="Custo unitário"></div>
                                        <div class="col-12"><input class="form-control form-control-sm" id="perdaMotivo" placeholder="Motivo da perda"></div>
                                        <div class="col-12"><button type="button" class="btn btn-danger btn-sm w-100" id="btnSalvarPerda">Registrar Perda</button></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ABA: Fichas -->
                <div class="estoque-tab-pane<?php echo !$isEstoqueEmpCockpit ? ' is-active' : ''; ?>" data-estoque-pane="fichas" role="tabpanel">
                    <div class="estoque-tab-intro">
                        <?php if ($isEstoqueEmpCockpit): ?>
                        <strong>Engenharia de cardápio</strong> — define o consumo por prato. Sem ficha, não há baixa automática nem previsão completa.
                        <?php else: ?>
                        <strong>Ficha técnica por prato</strong> — define o consumo de insumos. Sem ficha, não há baixa automática nas vendas.
                        <?php endif; ?>
                        <span id="fichaIntroStats" class="ms-1"></span>
                    </div>
                    <?php if (!$isEstoqueEmpCockpit): ?>
                    <div class="panel mb-3" id="eiHojePanel">
                        <div class="panel-head">Movimentos de hoje</div>
                        <div class="panel-body">
                            <div id="eiMovsHoje" class="ei-movs-hoje"><div class="text-muted small">A carregar os movimentos de hoje&hellip;</div></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="row g-3">
                        <div class="col-lg-8">
                            <div class="panel mb-3">
                                <div class="panel-head">Ficha Técnica por Prato</div>
                                <div class="panel-body">
                                    <div class="row g-2 mb-2">
                                        <div class="col-8"><select class="form-select form-select-sm" id="fichaProduto"></select></div>
                                        <div class="col-4"><input class="form-control form-control-sm" id="fichaRendimento" type="number" step="0.001" value="1" placeholder="Rendimento em porções"></div>
                                    </div>
                                    <div class="row g-2 align-items-end mb-2">
                                        <div class="col-5"><select class="form-select form-select-sm" id="fichaInsumo"></select></div>
                                        <div class="col-3"><input class="form-control form-control-sm" id="fichaQtd" type="number" step="0.001" min="0.001" placeholder="Quantidade necessária"></div>
                                        <div class="col-2"><select class="form-select form-select-sm" id="fichaUnidade" title="Pode usar g ou kg — o sistema converte"></select></div>
                                        <div class="col-2"><button type="button" class="btn btn-outline-primary btn-sm w-100" id="btnAddItemFicha">Adicionar</button></div>
                                    </div>
                                    <div class="wizard-preview mb-2" id="fichaConversaoPreview" style="display:none;margin-top:0;"></div>
                                    <div class="panel-note mb-2" id="fichaHint">1) Escolha o prato · 2) Insumo + quantidade (g ou kg) · 3) Adicionar · 4) Salvar. A conversão e o custo são automáticos.</div>
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle mb-2">
                                            <thead><tr><th>Insumo</th><th>Qtd</th><th>Unid.</th><th>Custo no prato</th><th></th></tr></thead>
                                            <tbody id="tblFichaItens"></tbody>
                                            <tfoot>
                                                <tr>
                                                    <td colspan="3" class="text-end fw-semibold">Custo estimado do prato</td>
                                                    <td class="mono fw-bold" id="fichaCustoTotal">0,00 MZN</td>
                                                    <td></td>
                                                </tr>
                                                <tr id="fichaMargemRow" hidden>
                                                    <td colspan="3" class="text-end small text-muted">Preço de venda · margem</td>
                                                    <td class="mono small" id="fichaMargem" colspan="2"></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                    <button type="button" class="btn btn-primary btn-sm" id="btnSalvarFicha">Salvar Ficha Técnica</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script type="application/json" id="cfg-estoque_inteligente">{"v0": <?php echo $isEstoqueEmpCockpit ? 'true' : 'false'; ?>, "v1": <?php echo json_encode($estoqueDefaultTab, JSON_UNESCAPED_UNICODE); ?>}</script>
    <!-- Estoque inteligente: um ficheiro por area, carregados por esta ordem (partilham variaveis globais). -->
    <script src="js/estoque_inteligente/comum.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/estoque_inteligente/comum.js'); ?>"></script>
    <script src="js/estoque_inteligente/painel.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/estoque_inteligente/painel.js'); ?>"></script>
    <script src="js/estoque_inteligente/insumos_fichas.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/estoque_inteligente/insumos_fichas.js'); ?>"></script>
    <script src="js/estoque_inteligente/acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/estoque_inteligente/acoes.js'); ?>"></script>
    <script src="js/estoque_inteligente/minimos.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/estoque_inteligente/minimos.js'); ?>"></script>
    <script src="js/estoque_inteligente/extras.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/estoque_inteligente/extras.js'); ?>"></script>
</body>
</html>
