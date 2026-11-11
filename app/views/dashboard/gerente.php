<?php /** Dashboard do gerente: operacao do salao, sem analytics financeiros do dono. */ ?>
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
                                                                            <div class="plan-identity-ribbon"><i class="fas fa-clipboard-list"></i> Coordenação do salão</div>
                                                                        <div class="user-name-welcome"><?php echo htmlspecialchars($dashboardSaudacao); ?></div>
                                    <div class="small text-muted mt-1"><?php echo htmlspecialchars($dashboardContextoPlano); ?></div>
                                    <div class="user-plan-info">
                                                                                    <span>Perfil</span>
                                            <span class="plan-pill">Gerente</span>
                                            <span class="expiry-pill"><i class="fas fa-store me-1"></i>Salão · reservas · turnos</span>
                                                                            </div>
                                                                            <div class="mt-2 text-muted" style="font-size: 0.92rem; max-width: 42rem;">
                                            Vista operacional: mesas, reservas, fila de pedidos e equipa. Sem configuração de plano nem utilizadores.
                                        </div>
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
                    
                                            <?php
                        $gerOcupPct = $total_mesas > 0 ? (int)round(($mesas_ocupadas / $total_mesas) * 100) : 0;
                        $gerPend = (int)($pedidos_resumo['pendentes'] ?? 0);
                        $gerPrep = (int)($pedidos_resumo['preparando'] ?? 0);
                        $gerPronto = (int)($pedidos_resumo['prontos'] ?? 0);
                        $gerResPend = (int)($dashboard_reservas['pendentes'] ?? 0);
                        $gerResConf = (int)($dashboard_reservas['confirmadas'] ?? 0);
                        $gerEquipaOn = (int)($team_online['online'] ?? 0);
                        $gerEquipaTot = (int)($team_online['total'] ?? 0);
                        ?>
                        <div class="dash-gerente-hero mb-3">
                            <div>
                                <div class="dash-gerente-kicker">Dashboard do gerente</div>
                                <h2 class="dash-gerente-title">Salão em tempo real</h2>
                                <p class="dash-gerente-copy mb-0">Coordene mesas, reservas, fila e turnos — sem a visão financeira do dono.</p>
                            </div>
                        </div>
                        <div class="stats-grid" id="gerente-stats">
                            <div class="stat-card warning">
                                <div class="stat-icon warning"><i class="fas fa-chair"></i></div>
                                <div class="stat-value"><?php echo (int)$mesas_ocupadas; ?>/<?php echo (int)$total_mesas; ?></div>
                                <div class="stat-label">Mesas ocupadas</div>
                                <div class="mt-2 text-muted" style="font-size: 13px;"><?php echo $gerOcupPct; ?>% ocupação · <?php echo max(0, (int)$total_mesas - (int)$mesas_ocupadas); ?> livres</div>
                            </div>
                            <div class="stat-card info">
                                <div class="stat-icon info"><i class="fas fa-calendar-check"></i></div>
                                <div class="stat-value"><?php echo $gerResPend; ?></div>
                                <div class="stat-label">Reservas pendentes</div>
                                <div class="mt-2 text-muted" style="font-size: 13px;"><?php echo $gerResConf; ?> confirmada(s) activas</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-icon primary"><i class="fas fa-fire"></i></div>
                                <div class="stat-value"><?php echo $gerPend + $gerPrep + $gerPronto; ?></div>
                                <div class="stat-label">Fila de pedidos</div>
                                <div class="mt-2 text-muted" style="font-size: 13px;"><?php echo $gerPend; ?> pend. · <?php echo $gerPrep; ?> prep. · <?php echo $gerPronto; ?> prontos</div>
                            </div>
                            <div class="stat-card <?php echo $caixa_aberto ? 'success' : 'warning'; ?>">
                                <div class="stat-icon <?php echo $caixa_aberto ? 'success' : 'warning'; ?>"><i class="fas fa-money-bill-wave"></i></div>
                                <div class="stat-value" style="font-size:1.35rem;"><?php echo $caixa_aberto ? 'ABERTO' : 'FECHADO'; ?></div>
                                <div class="stat-label">Caixa · equipa</div>
                                <div class="mt-2 text-muted" style="font-size: 13px;"><?php echo $gerEquipaOn; ?>/<?php echo $gerEquipaTot; ?> online</div>
                            </div>
                        </div>
                    
                    <?php include __DIR__ . '/partes/grafico_e_planos.php'; ?>
                    <?php include __DIR__ . '/partes/analytics_empresarial.php'; ?>
                    <?php include __DIR__ . '/partes/tabelas_operacao.php'; ?>
                    <!-- ATALHOS RÁPIDOS -->
                    <div class="row g-3">
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
                        
                        <?php if ($temGestaoFuncionarios): ?>
                            <div class="col-6 col-md-3">
                                <a href="funcionarios_turnos.php" class="quick-action" style="background: linear-gradient(135deg, #7c3aed, #4f46e5);">
                                    <i class="fas fa-clock fa-2x mb-2"></i><span>Funcionários</span>
                                </a>
                            </div>
                        <?php endif; ?>

                        
                                            </div>

