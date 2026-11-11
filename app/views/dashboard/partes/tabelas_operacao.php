                    <!-- TABELAS -->
                    <?php
                    $dashOpsEmpStretch = ($plano_atual === 'EMPRESARIAL' && $perfil === 'ADMIN');
                    $dashOpsEmpLayout = ($plano_atual === 'EMPRESARIAL' && $perfil === 'ADMIN');
                    $dashGerenteOps = ($perfil === 'GERENTE');
                    ?>
                    <div class="row g-3 mb-3 <?php echo $dashOpsEmpStretch ? 'dash-emp-ops align-items-stretch' : 'align-items-start'; ?>">
                        <div class="<?php echo $dashGerenteOps ? 'col-12' : ($dashOpsEmpLayout ? 'col-lg-7' : 'col-lg-8'); ?>">
                            <div class="card <?php echo $dashOpsEmpStretch ? 'h-100' : ''; ?> <?php echo $dashOpsEmpLayout ? 'dash-emp-card' : ''; ?>">
                                <div class="card-header">
                                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                        <div>
                                            <h3 class="card-title mb-1"><i class="fas fa-shopping-cart"></i> <?php echo $perfil === 'GARCOM' ? 'Últimos Pedidos' : 'Últimas Vendas'; ?></h3>
                                            <div class="text-muted" style="font-size: 13px;">
                                                <?php
                                                if ($perfil === 'GARCOM') {
                                                    echo 'Fluxo recente dos pedidos em andamento.';
                                                } elseif ($plano_atual === 'EMPRESARIAL') {
                                                    echo 'Últimas vendas pagas · mix de pagamento e ticket médio.';
                                                } else {
                                                    echo 'Resumo financeiro recente com foco em desempenho e controlo.';
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        <?php if ($perfil !== 'GARCOM' || $temPedidosOnline): ?>
                                            <a href="<?php echo $perfil === 'GARCOM' ? 'pedidos.php' : 'vendas.php'; ?>" class="btn btn-outline-primary btn-sm">Ver Todas</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <?php
                                    $ultimasVendasCount = count($ultimas_vendas ?? []);
                                    $valorTotalUltimasVendas = 0.0;
                                    $pagamentoResumo = [];
                                    foreach (($ultimas_vendas ?? []) as $v) {
                                        $valorTotalUltimasVendas += (float)($v['total_final'] ?? 0);
                                        $pagamento = (string)($v['forma_pagamento'] ?? 'N/A');
                                        $pagamentoResumo[$pagamento] = ($pagamentoResumo[$pagamento] ?? 0) + 1;
                                    }
                                    $ticketMedioUltimasVendas = $ultimasVendasCount > 0 ? ($valorTotalUltimasVendas / $ultimasVendasCount) : 0.0;
                                    arsort($pagamentoResumo);
                                    $pagamentoMaisUsado = $pagamentoResumo ? array_key_first($pagamentoResumo) : '—';
                                    ?>
                                    <?php if ($perfil !== 'GARCOM' && $plano_atual === 'EMPRESARIAL'): ?>
                                        <div class="dash-emp-mini-kpis mb-3">
                                            <div class="dash-emp-mini-kpi kpi-orange">
                                                <span>Últimas vendas</span>
                                                <strong><?php echo $ultimasVendasCount; ?></strong>
                                            </div>
                                            <div class="dash-emp-mini-kpi kpi-green">
                                                <span>Valor total</span>
                                                <strong>MZN <?php echo number_format($valorTotalUltimasVendas, 2, ',', '.'); ?></strong>
                                            </div>
                                            <div class="dash-emp-mini-kpi">
                                                <span>Ticket médio</span>
                                                <strong>MZN <?php echo number_format($ticketMedioUltimasVendas, 2, ',', '.'); ?></strong>
                                            </div>
                                            <div class="dash-emp-mini-kpi kpi-amber">
                                                <span>Pagamento top</span>
                                                <strong><?php echo htmlspecialchars($pagamentoMaisUsado); ?></strong>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <div class="table-responsive">
                                        <table class="table align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Fatura</th>
                                                    <th>Horário</th>
                                                    <th>Mesa</th>
                                                    <th>Total</th>
                                                    <th>Pagamento</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($ultimas_vendas as $v): ?>
                                                    <tr>
                                                        <td>
                                                            <div class="fw-semibold"><?php echo $v['numero_fatura']; ?></div>
                                                            <div class="small text-muted">Venda recente</div>
                                                        </td>
                                                        <td>
                                                            <div class="fw-semibold"><?php echo date('H:i', strtotime($v['criado_em'])); ?></div>
                                                            <div class="small text-muted"><?php echo date('d/m', strtotime($v['criado_em'])); ?></div>
                                                        </td>
                                                        <!-- Destaca pedidos do Balcão no dashboard -->
                                                        <td class="<?php echo empty($v['mesa_numero']) ? 'balcao-label' : ''; ?>">
                                                            <div class="fw-semibold"><?php echo $v['mesa_numero'] ? 'Mesa ' . $v['mesa_numero'] : 'Balcão'; ?></div>
                                                            <div class="small text-muted"><?php echo $v['mesa_numero'] ? 'Atendimento local' : 'Consumo direto'; ?></div>
                                                        </td>
                                                        <td>
                                                            <div class="fw-semibold">MZN <?php echo number_format($v['total_final'], 2, ',', '.'); ?></div>
                                                            <div class="small text-muted">Valor final</div>
                                                        </td>
                                                        <td><span class="badge-custom badge-<?php echo $v['forma_pagamento'] == 'DINHEIRO' ? 'success' : ($v['forma_pagamento'] == 'CARTAO' ? 'info' : 'secondary'); ?>"><?php echo $v['forma_pagamento']; ?></span></td>
                                                        <td><span class="badge-custom badge-<?php echo $v['status'] == 'PAGO' ? 'success' : ($v['status'] == 'CANCELADO' ? 'danger' : 'warning'); ?>"><?php echo $v['status']; ?></span></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <?php if (empty($ultimas_vendas)): ?>
                                                    <tr>
                                                        <td colspan="6" class="text-center py-4 text-muted"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>Nenhuma venda hoje</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php if (!$dashGerenteOps): ?>
                        <div class="<?php echo $dashOpsEmpLayout ? 'col-lg-5' : 'col-lg-4'; ?>">
                            <?php
                            $eiTotal = (int)($estoque_resumo['total_insumos'] ?? 0);
                            $eiCritico = (int)($estoque_resumo['critico'] ?? $estoque_critico);
                            $eiAlerta = (int)($estoque_resumo['alerta'] ?? max(0, $estoque_alerta - $estoque_critico));
                            $eiOk = (int)($estoque_resumo['ok'] ?? 0);
                            $eiValidade = (int)($estoque_resumo['validade_7d'] ?? 0);
                            $eiPerdasRegs = (int)($estoque_resumo['perdas_30d_regs'] ?? 0);
                            $eiPerdasCusto = (float)($estoque_resumo['perdas_30d_custo'] ?? 0);
                            $eiAlertas = (int)($estoque_resumo['alertas_abertos'] ?? 0);
                            $eiTemInsumos = !empty($estoque_resumo['tem_insumos']);
                            $eiSaudavel = $eiTemInsumos && $eiCritico === 0 && $eiAlerta === 0;
                            $eiScore = ($plano_atual === 'EMPRESARIAL' && is_array($dashEmpInsights))
                                ? (int)($dashEmpInsights['score_saude'] ?? 0)
                                : null;
                            $eiCobMedia = ($plano_atual === 'EMPRESARIAL' && is_array($dashEmpInsights))
                                ? (float)($dashEmpInsights['dias_cobertura_media'] ?? 0)
                                : null;
                            $eiSemFicha = ($plano_atual === 'EMPRESARIAL' && is_array($dashEmpInsights))
                                ? (int)($dashEmpInsights['produtos_sem_ficha'] ?? 0)
                                : 0;
                            ?>
                            <div class="card <?php echo $dashOpsEmpStretch ? 'h-100' : ''; ?> <?php echo $dashOpsEmpLayout ? 'dash-emp-card dash-emp-stock-card' : ''; ?>">
                                <div class="card-header">
                                    <h3 class="card-title">
                                        <i class="fas fa-<?php echo $showEstoqueAvancado ? 'boxes-stacked' : 'exclamation-triangle'; ?>"></i>
                                        <?php echo htmlspecialchars($estoqueCardTitulo); ?>
                                    </h3>
                                    <a href="<?php echo htmlspecialchars($estoqueCardLink); ?>" class="btn <?php echo $showEstoqueAvancado ? 'btn-outline-primary' : 'btn-outline-warning'; ?> btn-sm"><?php echo htmlspecialchars($estoqueCardLinkLabel); ?></a>
                                </div>
                                <div class="card-body p-0 <?php echo $plano_atual === 'EMPRESARIAL' ? 'dash-emp-stock-body' : ''; ?>">
                                    <?php if (!$showEstoqueAvancado): ?>
                                        <?php
                                        $esZerados = (int)($estoqueSimplesResumo['zerados'] ?? 0);
                                        $esBaixo = (int)($estoqueSimplesResumo['baixo'] ?? 0);
                                        $esOk = (int)($estoqueSimplesResumo['ok'] ?? 0);
                                        $esTotal = (int)($estoqueSimplesResumo['total'] ?? 0);
                                        $esTemAlerta = !empty($estoque_baixo);
                                        ?>
                                        <?php if ($esTemAlerta): ?>
                                            <div class="stock-summary">
                                                <div class="stock-summary-row">
                                                    <div class="stock-summary-item is-critico">
                                                        <span class="stock-kicker">Zerados</span>
                                                        <strong><?php echo $esZerados; ?></strong>
                                                        <span class="stock-hint">sem stock</span>
                                                    </div>
                                                    <div class="stock-summary-item is-alerta">
                                                        <span class="stock-kicker">Baixo</span>
                                                        <strong><?php echo $esBaixo; ?></strong>
                                                        <span class="stock-hint">sob o mínimo</span>
                                                    </div>
                                                    <div class="stock-summary-item is-ok">
                                                        <span class="stock-kicker">OK</span>
                                                        <strong><?php echo $esOk; ?></strong>
                                                        <span class="stock-hint"><?php echo $esTotal; ?> produtos</span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="list-group list-group-flush simple-stock-list">
                                                <?php foreach ($estoque_baixo as $ep):
                                                    $produtoEstoque = (float)($ep['estoque'] ?? 0);
                                                    $produtoMinimo = (float)($ep['estoque_minimo'] ?? 0);
                                                    $statusClass = $produtoEstoque <= 0 ? 'danger' : 'warning';
                                                ?>
                                                    <div class="list-group-item d-flex justify-content-between align-items-center py-2 simple-stock-item">
                                                        <div class="min-w-0">
                                                            <div class="fw-semibold truncate-ellipsis"><?php echo htmlspecialchars((string)($ep['nome'] ?? '')); ?></div>
                                                            <small class="text-muted">Mín: <?php echo number_format($produtoMinimo, 0, ',', '.'); ?></small>
                                                        </div>
                                                        <span class="badge-custom badge-<?php echo $statusClass; ?> flex-shrink-0 ms-2">
                                                            <?php echo number_format($produtoEstoque, 0, ',', '.'); ?>
                                                        </span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="px-3 py-2 border-top d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                                <span class="small text-muted"><?php echo count($estoque_baixo); ?> produto(s) a repor</span>
                                                <a href="produtos.php" class="btn btn-sm btn-warning">
                                                    <i class="fas fa-boxes-stacked me-1"></i>Ver / ajustar em Produtos
                                                </a>
                                            </div>
                                        <?php else: ?>
                                            <div class="stock-summary">
                                                <div class="stock-summary-row">
                                                    <div class="stock-summary-item is-critico">
                                                        <span class="stock-kicker">Zerados</span>
                                                        <strong><?php echo $esZerados; ?></strong>
                                                        <span class="stock-hint">sem stock</span>
                                                    </div>
                                                    <div class="stock-summary-item is-alerta">
                                                        <span class="stock-kicker">Baixo</span>
                                                        <strong><?php echo $esBaixo; ?></strong>
                                                        <span class="stock-hint">sob o mínimo</span>
                                                    </div>
                                                    <div class="stock-summary-item is-ok">
                                                        <span class="stock-kicker">OK</span>
                                                        <strong><?php echo $esOk; ?></strong>
                                                        <span class="stock-hint"><?php echo $esTotal; ?> produtos</span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="p-3 text-center">
                                                <i class="fas fa-check-circle fa-2x text-success mb-2 d-block"></i>
                                                <p class="mb-2 fw-semibold" style="color:#0f172a;">Estoque em dia</p>
                                                <p class="mb-3 text-muted small">Nenhum produto abaixo do mínimo. Gere stock por produto em Produtos.</p>
                                                <a href="produtos.php" class="btn btn-sm btn-outline-warning">
                                                    <i class="fas fa-boxes-stacked me-1"></i>Gerir stock
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                    <?php
                                    // Empresarial: card enxuto — só números com acção; sem repetir score/cobertura.
                                    $eiMetaChips = [];
                                    if ($eiValidade > 0) {
                                        $eiMetaChips[] = ['icon' => 'hourglass-half', 'txt' => $eiValidade . ' lote(s) a vencer (7d)', 'warn' => true];
                                    }
                                    if ($eiPerdasRegs > 0) {
                                        $eiPerdasTxt = $eiPerdasRegs . ' perda(s) · 30d';
                                        if ($eiPerdasCusto > 0) {
                                            $eiPerdasTxt .= ' · MZN ' . number_format($eiPerdasCusto, 0, ',', '.');
                                        }
                                        $eiMetaChips[] = ['icon' => 'trash-alt', 'txt' => $eiPerdasTxt, 'warn' => false];
                                    }
                                    if ($eiAlertas > 0) {
                                        $eiMetaChips[] = ['icon' => 'bell', 'txt' => $eiAlertas . ' alerta(s) aberto(s)', 'warn' => true];
                                    }
                                    if ($eiSemFicha > 0) {
                                        $eiMetaChips[] = ['icon' => 'link-slash', 'txt' => $eiSemFicha . ' prato(s) sem ficha', 'warn' => true];
                                    }
                                    // Notas: só acção (não repetir “estoque sob controlo” genérico)
                                    $eiNotasAccao = array_values(array_filter($dashEmpStockNotas ?? [], static function ($n) {
                                        $tom = (string)($n['tom'] ?? '');
                                        return $tom === 'warn' || $tom === 'info';
                                    }));
                                    $eiNotasAccao = array_slice($eiNotasAccao, 0, 2);
                                    ?>
                                    <div class="stock-summary">
                                        <div class="stock-summary-row">
                                            <div class="stock-summary-item is-critico">
                                                <span class="stock-kicker">Crítico</span>
                                                <strong><?php echo $eiCritico; ?></strong>
                                                <span class="stock-hint">zerado</span>
                                            </div>
                                            <div class="stock-summary-item is-alerta">
                                                <span class="stock-kicker">Alerta</span>
                                                <strong><?php echo $eiAlerta; ?></strong>
                                                <span class="stock-hint">sob o mínimo</span>
                                            </div>
                                            <div class="stock-summary-item <?php echo $eiSaudavel ? 'is-ok' : ''; ?>">
                                                <span class="stock-kicker">OK</span>
                                                <strong><?php echo $eiOk; ?></strong>
                                                <span class="stock-hint"><?php echo $eiTotal; ?> insumos</span>
                                            </div>
                                        </div>
                                        <?php if (!empty($eiMetaChips)): ?>
                                            <div class="stock-meta-row">
                                                <?php foreach ($eiMetaChips as $chip): ?>
                                                    <span class="stock-meta-chip"<?php echo !empty($chip['warn']) ? ' style="border-color: rgba(234,88,12,0.35); color:#c2410c;"' : ''; ?>>
                                                        <i class="fas fa-<?php echo htmlspecialchars($chip['icon']); ?>"></i>
                                                        <?php echo htmlspecialchars($chip['txt']); ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                        <?php if ($plano_atual === 'EMPRESARIAL'): ?>
                                            <div class="dash-emp-stock-fill">
                                                <?php if ($eiSemFicha > 0): ?>
                                                    <a class="dash-emp-ficha-banner" href="estoque_inteligente.php?tab=fichas">
                                                        <span class="ficha-icon"><i class="fas fa-clipboard-list" aria-hidden="true"></i></span>
                                                        <span class="ficha-copy">
                                                            <strong><?php echo (int)$eiSemFicha; ?> prato(s) sem ficha</strong>
                                                            <span>Sem ficha a baixa nas vendas fica incompleta. Complete na aba Fichas.</span>
                                                        </span>
                                                        <i class="fas fa-chevron-right ficha-chevron" aria-hidden="true"></i>
                                                    </a>
                                                <?php endif; ?>

                                                <?php if (!$eiTemInsumos): ?>
                                                    <div class="stock-empty-cta">
                                                        <i class="fas fa-boxes-stacked fa-2x mb-2 d-block" style="color:#ea580c;"></i>
                                                        <div class="cta-title">Ainda sem insumos</div>
                                                        <div class="cta-sub">Cadastre ingredientes e fichas para o estoque alertar e baixar nas vendas.</div>
                                                        <a href="estoque_inteligente.php?tab=cadastro" class="btn btn-sm" style="background:linear-gradient(135deg,#ea580c,#c2410c);color:#fff;border:0;">
                                                            <i class="fas fa-plus me-1"></i>Cadastrar insumo
                                                        </a>
                                                    </div>
                                                <?php elseif (!empty($estoque_alertas_feed)): ?>
                                                    <div class="dash-emp-alert-feed" role="list">
                                                        <?php foreach ($estoque_alertas_feed as $al):
                                                            $sev = strtoupper((string)($al['severidade'] ?? 'MEDIA'));
                                                            $tipo = strtoupper((string)($al['tipo'] ?? ''));
                                                            $saldoAl = (float)($al['saldo_base'] ?? 0);
                                                            // Consumo anómalo = aviso informativo, não perigo de stock.
                                                            if ($tipo === 'CONSUMO_ANOMALO') {
                                                                $tomFeed = 'info';
                                                                $icoFeed = 'fa-chart-line';
                                                            } elseif ($tipo === 'VALIDADE') {
                                                                $tomFeed = in_array($sev, ['CRITICA', 'ALTA'], true) ? 'crit' : 'warn';
                                                                $icoFeed = 'fa-calendar-xmark';
                                                            } elseif ($tipo === 'REPOSICAO' && $saldoAl <= 0) {
                                                                $tomFeed = 'crit';
                                                                $icoFeed = 'fa-exclamation-triangle';
                                                            } elseif ($tipo === 'REPOSICAO') {
                                                                $tomFeed = 'warn';
                                                                $icoFeed = 'fa-exclamation-triangle';
                                                            } else {
                                                                $tomFeed = in_array($sev, ['CRITICA', 'ALTA'], true) ? 'crit' : 'warn';
                                                                $icoFeed = 'fa-exclamation-triangle';
                                                            }
                                                            $nomeInsumo = trim((string)($al['insumo_nome'] ?? ''));
                                                            if ($nomeInsumo === '') {
                                                                $nomeInsumo = preg_replace('/^(Reposicao urgente:\s*|Validade:\s*|Lote vencido:\s*|Validade proxima:\s*|Consumo anomalo:\s*)/iu', '', (string)($al['titulo'] ?? 'Insumo')) ?: 'Insumo';
                                                            }
                                                            $unidadeAl = trim((string)($al['unidade_base'] ?? 'un'));
                                                            $saldoTxt = function_exists('estoque_formatar_quantidade')
                                                                ? estoque_formatar_quantidade($saldoAl, $unidadeAl)
                                                                : (number_format($saldoAl, $unidadeAl === 'un' ? 0 : 1, ',', '.') . ' ' . $unidadeAl);
                                                            if ($tipo === 'VALIDADE') {
                                                                $detalheAl = trim((string)($al['detalhe'] ?? ''));
                                                                $msgFeed = $nomeInsumo . (preg_match('/vence|vencid/i', $detalheAl) ? (' — ' . $detalheAl) : (' vence em breve'));
                                                            } elseif ($tipo === 'CONSUMO_ANOMALO') {
                                                                $msgFeed = $nomeInsumo . ' — consumo acima do habitual hoje';
                                                            } elseif ($tipo === 'REPOSICAO' && $saldoAl <= 0) {
                                                                $msgFeed = $nomeInsumo . ' está sem stock (' . $saldoTxt . ')';
                                                            } elseif ($tipo === 'REPOSICAO') {
                                                                $msgFeed = $nomeInsumo . ' está abaixo do mínimo (' . $saldoTxt . ')';
                                                            } else {
                                                                $msgFeed = trim((string)($al['titulo'] ?? $nomeInsumo));
                                                            }
                                                            $ts = strtotime((string)($al['criado_em'] ?? ''));
                                                            if ($ts) {
                                                                $hoje = strtotime('today');
                                                                $ontem = strtotime('yesterday');
                                                                if ($ts >= $hoje) {
                                                                    $quando = 'Hoje, ' . date('H:i', $ts);
                                                                } elseif ($ts >= $ontem) {
                                                                    $quando = 'Ontem, ' . date('H:i', $ts);
                                                                } else {
                                                                    $quando = date('d/m, H:i', $ts);
                                                                }
                                                            } else {
                                                                $quando = '';
                                                            }
                                                        ?>
                                                            <a class="dash-emp-alert-row tom-<?php echo $tomFeed; ?>" href="estoque_inteligente.php" role="listitem">
                                                                <span class="alert-ico" aria-hidden="true"><i class="fas <?php echo $icoFeed; ?>"></i></span>
                                                                <span class="alert-body">
                                                                    <strong><?php echo htmlspecialchars($msgFeed, ENT_QUOTES, 'UTF-8'); ?></strong>
                                                                    <?php if ($quando !== ''): ?>
                                                                        <small><?php echo htmlspecialchars($quando, ENT_QUOTES, 'UTF-8'); ?></small>
                                                                    <?php endif; ?>
                                                                </span>
                                                                <i class="fas fa-chevron-right alert-go" aria-hidden="true"></i>
                                                            </a>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php elseif (!empty($estoque_baixo)): ?>
                                                    <div class="list-group list-group-flush simple-stock-list">
                                                        <?php foreach ($estoque_baixo as $ep):
                                                            $produtoEstoque = (float)($ep['estoque'] ?? 0);
                                                            $produtoMinimo = (float)($ep['estoque_minimo'] ?? 0);
                                                            $unidadeEp = (string)($ep['unidade_base'] ?? '');
                                                            $statusClass = $produtoEstoque <= 0 ? 'danger' : 'warning';
                                                            $diasCob = array_key_exists('dias_cobertura', $ep) ? $ep['dias_cobertura'] : null;
                                                        ?>
                                                            <div class="list-group-item d-flex justify-content-between align-items-center py-2 simple-stock-item">
                                                                <div class="min-w-0">
                                                                    <div class="fw-semibold truncate-ellipsis"><?php echo htmlspecialchars($ep['nome']); ?></div>
                                                                    <small class="text-muted">Mín: <?php echo number_format($produtoMinimo, $unidadeEp === 'un' ? 0 : 1, ',', '.'); ?> <?php echo htmlspecialchars($unidadeEp); ?></small>
                                                                    <?php if ($diasCob !== null): ?>
                                                                        <div class="stock-dias">
                                                                            <?php if ((int)$diasCob <= 0): ?>
                                                                                Sem cobertura · repor já
                                                                            <?php else: ?>
                                                                                ~<?php echo (int)$diasCob; ?> dia(s) de cobertura
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <div class="text-end flex-shrink-0 ms-2">
                                                                    <span class="badge-custom badge-<?php echo $statusClass; ?>">
                                                                        <?php echo number_format($produtoEstoque, $unidadeEp === 'un' ? 0 : 1, ',', '.'); ?>
                                                                        <?php echo htmlspecialchars($unidadeEp); ?>
                                                                    </span>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php elseif (!empty($eiNotasAccao)): ?>
                                                    <ul class="dash-emp-stock-notes">
                                                        <?php foreach ($eiNotasAccao as $sn): ?>
                                                            <li class="tom-<?php echo htmlspecialchars((string)($sn['tom'] ?? 'info')); ?>">
                                                                <strong><?php echo htmlspecialchars((string)($sn['titulo'] ?? '')); ?></strong>
                                                                <span><?php echo htmlspecialchars((string)($sn['txt'] ?? '')); ?></span>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php else: ?>
                                                    <div class="dash-emp-stock-ok">
                                                        <i class="fas fa-check-circle"></i>
                                                        <div>
                                                            <strong>Nada a repor agora</strong>
                                                            <span><?php echo $eiTotal; ?> insumos · mínimos OK<?php echo ($eiCobMedia !== null && $eiCobMedia > 0) ? ' · cobertura ~' . number_format($eiCobMedia, 0, ',', '.') . ' d' : ''; ?></span>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="dash-emp-stock-actions">
                                                    <?php if ($eiSemFicha > 0): ?>
                                                        <a href="estoque_inteligente.php?tab=fichas"><i class="fas fa-clipboard-list"></i> Completar <?php echo (int)$eiSemFicha; ?> ficha(s)</a>
                                                    <?php elseif ($eiAlertas > 0): ?>
                                                        <a href="estoque_inteligente.php"><i class="fas fa-bell"></i> Ver <?php echo (int)$eiAlertas; ?> alerta(s)</a>
                                                    <?php else: ?>
                                                        <a href="estoque_inteligente.php"><i class="fas fa-boxes-stacked"></i> Abrir estoque</a>
                                                    <?php endif; ?>
                                                    <?php if ($eiCritico > 0 || $eiAlerta > 0): ?>
                                                        <a href="estoque_inteligente.php?tab=movimentos"><i class="fas fa-cart-plus"></i> Registar entrada</a>
                                                    <?php elseif ($eiSemFicha <= 0): ?>
                                                        <a href="estoque_inteligente.php?tab=insights"><i class="fas fa-chart-line"></i> Visão &amp; compra</a>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php elseif (!$eiTemInsumos): ?>
                                            <div class="stock-empty-cta">
                                                <i class="fas fa-boxes-stacked fa-2x mb-2 d-block" style="color:#ea580c;"></i>
                                                <div class="cta-title">Ainda sem insumos cadastrados</div>
                                                <div class="cta-sub">Cadastre ingredientes, defina mínimos e fichas técnicas para o estoque inteligente começar a alertar.</div>
                                                <a href="estoque_inteligente.php" class="btn btn-sm" style="background:linear-gradient(135deg,#ea580c,#c2410c);color:#fff;border:0;">
                                                    <i class="fas fa-plus me-1"></i>Cadastrar primeiro insumo
                                                </a>
                                            </div>
                                        <?php elseif (!empty($estoque_baixo)): ?>
                                            <div class="list-group list-group-flush simple-stock-list">
                                                <?php foreach ($estoque_baixo as $ep):
                                                    $produtoEstoque = (float)($ep['estoque'] ?? 0);
                                                    $produtoMinimo = (float)($ep['estoque_minimo'] ?? 0);
                                                    $unidadeEp = (string)($ep['unidade_base'] ?? '');
                                                    $statusClass = $produtoEstoque <= 0 ? 'danger' : 'warning';
                                                    $diasCob = array_key_exists('dias_cobertura', $ep) ? $ep['dias_cobertura'] : null;
                                                ?>
                                                    <div class="list-group-item d-flex justify-content-between align-items-center py-2 simple-stock-item">
                                                        <div class="min-w-0">
                                                            <div class="fw-semibold truncate-ellipsis"><?php echo htmlspecialchars($ep['nome']); ?></div>
                                                            <small class="text-muted">Mín: <?php echo number_format($produtoMinimo, $unidadeEp === 'un' ? 0 : 1, ',', '.'); ?> <?php echo htmlspecialchars($unidadeEp); ?></small>
                                                            <?php if ($diasCob !== null): ?>
                                                                <div class="stock-dias">
                                                                    <?php if ((int)$diasCob <= 0): ?>
                                                                        Sem cobertura · repor já
                                        <?php else: ?>
                                                                        ~<?php echo (int)$diasCob; ?> dia(s) de cobertura
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="text-end flex-shrink-0 ms-2">
                                                            <span class="badge-custom badge-<?php echo $statusClass; ?>">
                                                                <?php echo number_format($produtoEstoque, $unidadeEp === 'un' ? 0 : 1, ',', '.'); ?>
                                                                <?php echo htmlspecialchars($unidadeEp); ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="px-3 py-2 border-top">
                                                <a href="estoque_inteligente.php" class="small fw-semibold text-decoration-none" style="color:#c2410c;">
                                                    Ver todos os alertas e previsões <i class="fas fa-arrow-right ms-1"></i>
                                                </a>
                                            </div>
                                        <?php else: ?>
                                            <div class="p-3 text-center">
                                                <i class="fas fa-check-circle fa-2x text-success mb-2 d-block"></i>
                                                <p class="mb-1 fw-semibold" style="color:#0f172a;">Estoque sob controlo</p>
                                                <p class="mb-0 text-muted small">
                                                    <?php echo $eiOk; ?> insumo(s) acima do mínimo
                                                    <?php if ($eiValidade > 0): ?>
                                                        · <?php echo $eiValidade; ?> com validade próxima
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

