<?php /** Dashboard do garcom: as suas mesas, pedidos e vendas do dia. */ ?>
                    <?php include __DIR__ . '/partes/alerta_funcionalidade.php'; ?>
                    <?php include __DIR__ . '/partes/cartao_utilizador.php'; ?>
                                        <div class="dashboard-user-card">
                        <div class="dashboard-user-toolbar">
                            <div class="d-flex align-items-center gap-3">
                                <?php if (!empty($foto_usuario_url)): ?>
                                    <img src="<?php echo htmlspecialchars($foto_usuario_url); ?>" alt="<?php echo htmlspecialchars($nome_usuario_completo); ?>">
                                <?php else: ?>
                                    <div class="user-avatar-fallback d-flex align-items-center justify-content-center text-white">
                                        <?php echo htmlspecialchars(mb_strtoupper(mb_substr(trim((string)$nome_usuario_completo), 0, 1, 'UTF-8'), 'UTF-8')); ?>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <?php if ($plano_atual === 'BASICO'): ?>
                                        <div class="plan-identity-ribbon"><i class="fas fa-store"></i> Operação essencial</div>
                                    <?php elseif ($plano_atual === 'PROFISSIONAL'): ?>
                                        <div class="plan-identity-ribbon"><i class="fas fa-star"></i> Gestão profissional</div>
                                    <?php else: ?>
                                        <div class="plan-identity-ribbon"><i class="fas fa-crown"></i> Rede empresarial</div>
                                    <?php endif; ?>
                                    <div class="user-name-welcome"><?php echo htmlspecialchars($dashboardSaudacao); ?></div>
                                    <div class="small text-muted mt-1"><?php echo htmlspecialchars($dashboardContextoPlano); ?></div>
                                    <div class="user-plan-info">
                                                                                <span>Plano activo</span>
                                        <span class="plan-pill"><?php echo htmlspecialchars($plano_atual === 'BASICO' ? 'Básico' : ($plano_atual === 'PROFISSIONAL' ? 'Profissional' : 'Empresarial')); ?></span>
                                        <?php if ($dias_restantes > 0): ?>
                                            <span class="expiry-pill"><i class="fas fa-calendar-check me-1"></i>Expira em <?php echo $dias_restantes; ?> dias</span>
                                        <?php else: ?>
                                            <span class="expiry-pill"><i class="fas fa-check-circle me-1"></i>Ativo</span>
                                        <?php endif; ?>
                                                                            </div>
                                    <?php if ($plano_atual === 'BASICO'): ?>
                                        <div class="mt-2 text-muted" style="font-size: 0.92rem; max-width: 42rem;">
                                            Fluxo de barraca: PDV + Caixa. Pedidos online, Bar/Cozinha e relatórios avançados ficam no Profissional.
                                        </div>
                                    <?php elseif ($plano_atual === 'PROFISSIONAL'): ?>
                                        <div class="mt-2" style="font-size: 0.92rem; max-width: 42rem; color: #0f766e;">
                                            Unidade única com receita, equipe, clientes, fichas técnicas e baixa automática de insumos. Sem multi-filial nem QR de reservas.
                                        </div>
                                    <?php elseif ($plano_atual === 'EMPRESARIAL'): ?>
                                        <div class="mt-2" style="font-size: 0.92rem; max-width: 42rem; color: rgba(255,255,255,0.78);">
                                            Controlo de rede: filiais, QR de reservas, transferência de estoque e visão executiva multi-unidade.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                                        <?php if ($perfil_operacional): ?>
                                    <a href="iniciar_turno.php" class="btn btn-success btn-sm" style="border-radius: 10px; padding: 9px 16px; font-weight: 600; background: #10b981; border: none;">
                                    <i class="fas fa-clock me-1"></i> <?php echo $sem_turno_ativo ? 'Iniciar turno' : 'Gerir turno'; ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    </div>
                    
                    <?php include __DIR__ . '/partes/aviso_caixa_fechado.php'; ?>
                    <?php if ($plano_atual === 'EMPRESARIAL'): ?>
                        <?php
                        $reservaUltima = $dashboard_reservas['ultima_pendente'];
                        $reservaCount = (int)($dashboard_reservas['pendentes'] ?? 0);
                        $reservaLabel = $reservaCount === 1 ? 'pendente' : 'pendentes';
                        $reservaResumoTexto = $reservaUltima
                            ? trim(
                                ($reservaUltima['nome_cliente'] ?? 'Cliente')
                                    . ' - '
                                    . ($reservaUltima['data_reserva'] ?? '')
                                    . ' às '
                                    . substr((string)($reservaUltima['hora_reserva'] ?? ''), 0, 5)
                                    . (
                                        !empty($reservaUltima['mesa_numero'])
                                        ? ' | Mesa ' . $reservaUltima['mesa_numero']
                                        : ' | Mesa por atribuir'
                                    )
                            )
                            : 'Nenhuma reserva pendente no momento.';
                        ?>
                        <div
                            id="dashboardReservasBanner"
                            class="dashboard-reservas-banner mb-4 <?php echo $reservaCount > 0 ? 'has-pending' : ''; ?>">
                            <div class="dashboard-reservas-left">
                                <div class="dashboard-reservas-icon">
                                    <i class="fas fa-bell"></i>
                                </div>
                                <div class="min-w-0">
                                    <div class="dashboard-reservas-title"><?php echo $perfil === 'GERENTE' ? 'Reservas a coordenar' : 'Reservas do restaurante'; ?></div>
                                    <div class="dashboard-reservas-subtitle" data-dashboard-reservas-ultima>
                                        <?php echo htmlspecialchars($reservaResumoTexto); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="dashboard-reservas-meta">
                                <span
                                    class="badge-custom dashboard-reservas-badge <?php echo $reservaCount > 0 ? 'pending' : 'clear'; ?>"
                                    data-dashboard-reservas-count>
                                    <?php echo $reservaCount; ?> <?php echo $reservaLabel; ?>
                                </span>
                                <a href="mesas.php" class="dashboard-reservas-link">Abrir mesas e reservas</a>
                            </div>
                        </div>
                    <?php endif; ?>

                                            <!-- GARÇOM STATS -->
                        <div class="stats-grid" id="garcom-stats-loading">
                            <div class="stat-card">
                                <div class="stat-icon primary"><i class="fas fa-receipt"></i></div>
                                <div class="stat-value" data-stat="pedidos-total"><?php echo $garcom_dashboard['total_pedidos']; ?></div>
                                <div class="stat-label">Pedidos Hoje</div>
                            </div>
                            <div class="stat-card warning">
                                <div class="stat-icon warning"><i class="fas fa-chair"></i></div>
                                <div class="stat-value" data-stat="mesas-total"><?php echo $garcom_dashboard['mesas_ocupadas']; ?>/<?php echo $garcom_dashboard['mesas_total']; ?></div>
                                <div class="stat-label">Mesas (Ocup./Total)</div>
                            </div>
                            <div class="stat-card success">
                                <div class="stat-icon success"><i class="fas fa-coins"></i></div>
                                <div class="stat-value" data-stat="vendas-total"><?php echo number_format($garcom_dashboard['valor_pedidos'], 2, ',', '.'); ?> MZN</div>
                                <div class="stat-label">Valor em Pedidos</div>
                            </div>
                            <div class="stat-card info">
                                <div class="stat-icon info"><i class="fas fa-list-check"></i></div>
                                <div class="stat-value" data-stat="status-pedidos">
                                    <div class="d-flex justify-content-around gap-2 mt-2">
                                        <span class="badge-custom badge-warning"><?php echo $garcom_dashboard['pendentes']; ?> Pend.</span>
                                        <span class="badge-custom badge-info"><?php echo $garcom_dashboard['preparados']; ?> Prep.</span>
                                        <span class="badge-custom badge-success"><?php echo $garcom_dashboard['entregues']; ?> Ent.</span>
                                    </div>
                                </div>
                                <div class="stat-label">Status dos Pedidos</div>
                            </div>
                        </div>
                    
                    <?php include __DIR__ . '/partes/grafico_e_planos.php'; ?>
                    <?php include __DIR__ . '/partes/analytics_empresarial.php'; ?>
                    <?php include __DIR__ . '/partes/tabelas_operacao.php'; ?>
                    <!-- ATALHOS RÁPIDOS -->
                    <div class="row g-3">
                                                <div class="col-6 col-md-3">
                            <a href="vendas.php" class="quick-action" style="background: linear-gradient(135deg, #FF6B35, #F7931E);">
                                <i class="fas fa-plus-circle fa-2x mb-2"></i><span>Nova Venda</span>
                            </a>
                        </div>
                        <div class="col-6 col-md-3">
                            <a href="produtos.php" class="quick-action" style="background: linear-gradient(135deg, #10b981, #20c997);">
                                <i class="fas fa-box fa-2x mb-2"></i><span>Produtos</span>
                            </a>
                        </div>
                                                <div class="col-6 col-md-3">
                            <a href="mesas.php" class="quick-action" style="background: linear-gradient(135deg, #3b82f6, #6f42c1);">
                                <i class="fas fa-chair fa-2x mb-2"></i><span>Mesas</span>
                            </a>
                        </div>
                        <?php if ($temEstoqueAvancado): ?>
                            <div class="col-6 col-md-3">
                                <a href="estoque_inteligente.php" class="quick-action" style="background: linear-gradient(135deg, #1d4ed8, #0f766e);">
                                    <i class="fas fa-boxes-stacked fa-2x mb-2"></i><span>Estoque IA</span>
                                </a>
                            </div>
                        <?php endif; ?>

                        <!-- CAIXA: Mostrar apenas se permitido no plano -->
                        <?php if ($temCaixa): ?>
                            <div class="col-6 col-md-3">
                                <a href="caixa.php" class="quick-action" style="background: linear-gradient(135deg, #10b981, #059669);">
                                    <i class="fas fa-money-bill-wave fa-2x mb-2"></i><span>Caixa</span>
                                </a>
                            </div>
                        <?php endif; ?>

                        <?php if ($temContasAbertas): ?>
                            <div class="col-6 col-md-3">
                                <a href="caixa_mesas.php" class="quick-action" style="background: linear-gradient(135deg, #0ea5e9, #0369a1);">
                                    <i class="fas fa-receipt fa-2x mb-2"></i><span>Contas Abertas</span>
                                </a>
                            </div>
                        <?php endif; ?>

                        <!-- GARÇOM: Mapa de mesas a partir do Profissional -->
                        <?php if ($temGarcons): ?>
                            <div class="col-6 col-md-3">
                                <a href="garcom_mesas.php" class="quick-action" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                                    <i class="fas fa-table-cells fa-2x mb-2"></i><span>Mapa Mesas</span>
                                </a>
                            </div>
                        <?php endif; ?>

                        <!-- RELATÓRIOS: Mostrar apenas se permitido no plano -->
                        <?php if (($temRelatorios || $temRelatoriosInteligentes)): ?>
                            <div class="col-6 col-md-3">
                                <a href="relatorios.php<?php echo $temRelatoriosInteligentes ? '?tipo=horarios_pico' : ''; ?>" class="quick-action" style="background: linear-gradient(135deg, #64748b, #343a40);">
                                    <i class="fas fa-chart-bar fa-2x mb-2"></i><span><?php echo $temRelatoriosInteligentes ? 'Relatórios IA' : 'Relatórios'; ?></span>
                                </a>
                            </div>
                        <?php endif; ?>

                        <?php if ($temGestaoFuncionarios): ?>
                            <div class="col-6 col-md-3">
                                <a href="funcionarios_turnos.php" class="quick-action" style="background: linear-gradient(135deg, #7c3aed, #4f46e5);">
                                    <i class="fas fa-clock fa-2x mb-2"></i><span>Funcionários</span>
                                </a>
                            </div>
                        <?php endif; ?>

                        <?php if ($temHistoricoClientes): ?>
                            <div class="col-6 col-md-3">
                                <a href="clientes.php" class="quick-action" style="background: linear-gradient(135deg, #0369a1, #0ea5e9);">
                                    <i class="fas fa-address-book fa-2x mb-2"></i><span>Clientes</span>
                                </a>
                            </div>
                        <?php endif; ?>

                                            </div>

