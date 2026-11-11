<?php /** Dashboard do administrador/dono (e de qualquer perfil sem vista propria). */ ?>
                    <?php include __DIR__ . '/partes/alerta_funcionalidade.php'; ?>
                    <?php include __DIR__ . '/partes/cartao_utilizador.php'; ?>
                    <?php if ($plano_atual !== 'EMPRESARIAL' || $perfil !== 'ADMIN'): ?>
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
                            <?php if ($perfil === 'ADMIN'): ?>
                            <a href="usuarios.php?edit_me=1" class="btn btn-primary btn-sm" style="border-radius: 10px; padding: 9px 16px; font-weight: 600;">
                                    <i class="fas fa-camera me-1"></i> Editar Perfil
                            </a>
                            <?php endif; ?>
                            <?php if ($perfil_operacional): ?>
                                    <a href="iniciar_turno.php" class="btn btn-success btn-sm" style="border-radius: 10px; padding: 9px 16px; font-weight: 600; background: #10b981; border: none;">
                                    <i class="fas fa-clock me-1"></i> <?php echo $sem_turno_ativo ? 'Iniciar turno' : 'Gerir turno'; ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    </div>
                    <?php endif; ?>

                    <?php include __DIR__ . '/partes/admin_faixa_plano.php'; ?>
                    <?php include __DIR__ . '/partes/aviso_caixa_fechado.php'; ?>
                    <?php if ($plano_atual === 'EMPRESARIAL'): ?>
                        <?php
                        $empTicket = $qtd_hoje > 0 ? ($total_hoje / max(1, $qtd_hoje)) : 0;
                        $empFilaPend = (int)($pedidos_resumo['pendentes'] ?? 0);
                        $empFilaPrep = (int)($pedidos_resumo['preparando'] ?? 0);
                        $empFilaPronto = (int)($pedidos_resumo['prontos'] ?? 0);
                        $empFilaTotal = $empFilaPend + $empFilaPrep + $empFilaPronto;
                        $empScore = is_array($dashEmpInsights) ? (int)($dashEmpInsights['score_saude'] ?? 0) : null;
                        $empCrit = (int)($estoque_resumo['critico'] ?? 0);
                        $empCobMedia = is_array($dashEmpInsights) ? (float)($dashEmpInsights['dias_cobertura_media'] ?? 0) : null;
                        if ($empCrit > 0) {
                            $empEstoqueResumo = $empCrit . ' insumo(s) precisam de reposição';
                        } elseif ($empScore !== null && $empScore < 80) {
                            $empEstoqueResumo = 'Rever fichas, validade e cobertura';
                        } elseif ($empCobMedia !== null && $empCobMedia > 0) {
                            $empEstoqueResumo = 'Estável por cerca de ' . number_format($empCobMedia, 0, ',', '.') . ' dias';
                        } else {
                            $empEstoqueResumo = 'Sem risco crítico no momento';
                        }
                        $empOcupPct = $total_mesas > 0 ? (int)round(($mesas_ocupadas / $total_mesas) * 100) : 0;
                        ?>
                        <?php if (!empty($dashEmpAvisos)): ?>
                            <section class="dash-avisos mb-3">
                                <div class="dash-avisos-head">
                                    <strong><i class="fas fa-bell"></i> Precisa de si hoje</strong>
                                    <span><?php echo count($dashEmpAvisos); ?> aviso(s)</span>
                                </div>
                                <ul class="dash-avisos-lista">
                                    <?php foreach ($dashEmpAvisos as $aviso): ?>
                                        <li class="tom-<?php echo htmlspecialchars($aviso['tom']); ?>">
                                            <i class="fas <?php echo htmlspecialchars($aviso['icone']); ?>"></i>
                                            <div>
                                                <strong><?php echo htmlspecialchars($aviso['titulo']); ?></strong>
                                                <span><?php echo htmlspecialchars($aviso['texto']); ?></span>
                                            </div>
                                            <a class="dash-avisos-btn" href="<?php echo htmlspecialchars($aviso['url']); ?>"><?php echo htmlspecialchars($aviso['botao']); ?></a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </section>
                        <?php endif; ?>

                        <?php if (is_array($dashEmpMeta)): ?>
                            <section class="dash-meta mb-3" id="dashMeta">
                                <div class="dash-meta-topo">
                                    <div>
                                        <strong><i class="fas fa-bullseye"></i> Meta do dia</strong>
                                        <?php if ($dashEmpMeta['valor'] > 0): ?>
                                            <span><?php echo number_format($dashEmpMeta['feito'], 2, ',', '.'); ?> de <?php echo number_format($dashEmpMeta['valor'], 2, ',', '.'); ?> MZN</span>
                                        <?php else: ?>
                                            <span>Ainda sem meta definida para este restaurante.</span>
                                        <?php endif; ?>
                                    </div>
                                    <form class="dash-meta-form" id="formMetaDia" autocomplete="off" data-csrf="<?php echo htmlspecialchars((string)($csrfTicketToken ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="number" step="1" min="0" id="dashMetaValor" class="form-control form-control-sm"
                                               value="<?php echo $dashEmpMeta['valor'] > 0 ? (int)$dashEmpMeta['valor'] : ''; ?>"
                                               placeholder="<?php echo $dashEmpMeta['sugerida'] > 0 ? 'Sugestão: ' . number_format($dashEmpMeta['sugerida'], 0, ',', '.') : 'Valor em MZN'; ?>"
                                               aria-label="Meta de vendas do dia em MZN">
                                        <button type="submit" class="btn btn-sm btn-outline-primary">Guardar</button>
                                    </form>
                                </div>
                                <?php if ($dashEmpMeta['valor'] > 0): $pctMeta = (float)$dashEmpMeta['pct']; ?>
                                    <div class="dash-meta-barra"><span class="<?php echo $pctMeta >= 100 ? 'is-feito' : ''; ?>" style="width: <?php echo number_format(min(100, $pctMeta), 1, '.', ''); ?>%"></span></div>
                                    <div class="dash-meta-rodape">
                                        <strong><?php echo number_format($pctMeta, 0, ',', '.'); ?>%</strong>
                                        <?php if ($dashEmpMeta['falta'] > 0): ?>
                                            faltam <?php echo number_format($dashEmpMeta['falta'], 2, ',', '.'); ?> MZN
                                        <?php else: ?>
                                            meta atingida
                                        <?php endif; ?>
                                    </div>
                                <?php elseif ($dashEmpMeta['sugerida'] > 0): ?>
                                    <div class="dash-meta-rodape">Nas últimas 4 semanas, neste dia da semana vendeu em média <?php echo number_format($dashEmpMeta['sugerida'], 2, ',', '.'); ?> MZN.</div>
                                <?php endif; ?>
                            </section>
                        <?php endif; ?>

                        <div class="dash-emp-kpis mb-3" id="dashEmpKpis" data-auto="1">
                            <div class="dash-emp-kpi kpi-orange">
                                <span class="label">Vendas hoje</span>
                                <strong data-dash-campo="total_hoje"><?php echo number_format($total_hoje, 2, ',', '.'); ?> MZN</strong>
                                <small data-dash-campo="resumo_hoje"><?php echo (int)$qtd_hoje; ?> venda(s) · ticket <?php echo number_format($empTicket, 2, ',', '.'); ?></small>
                                <?php if (is_array($dashEmpComparar)): ?>
                                    <div class="dash-kpi-comparar">
                                        <?php foreach ([['delta_ontem', 'ontem'], ['delta_semana', 'semana passada']] as [$chaveCmp, $rotuloCmp]):
                                            $deltaCmp = $dashEmpComparar[$chaveCmp]; ?>
                                            <span class="<?php echo $deltaCmp === null ? 'is-neutro' : ($deltaCmp >= 0 ? 'is-sobe' : 'is-desce'); ?>">
                                                <?php if ($deltaCmp === null): ?>
                                                    sem dados de <?php echo $rotuloCmp; ?>
                                                <?php else: ?>
                                                    <i class="fas fa-arrow-<?php echo $deltaCmp >= 0 ? 'up' : 'down'; ?>"></i>
                                                    <?php echo number_format(abs($deltaCmp), 0, ',', '.'); ?>% vs <?php echo $rotuloCmp; ?>
                                                <?php endif; ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                    <small class="dash-kpi-nota">comparação até às <?php echo htmlspecialchars($dashEmpComparar['hora']); ?> · <span id="dashAutoHora">actualiza sozinho</span></small>
                                <?php endif; ?>
                            </div>
                            <div class="dash-emp-kpi kpi-amber">
                                <span class="label">Pedidos na fila</span>
                                <strong data-dash-campo="fila_total"><?php echo $empFilaTotal; ?></strong>
                                <small><?php echo $empFilaPend; ?> pend. · <?php echo $empFilaPrep; ?> prep. · <?php echo $empFilaPronto; ?> prontos</small>
                            </div>
                            <div class="dash-emp-kpi <?php echo $empCrit > 0 ? 'kpi-amber' : 'kpi-green'; ?>">
                                <span class="label">Saúde do estoque</span>
                                <strong><?php echo $empScore !== null ? $empScore : '—'; ?><?php echo $empScore !== null ? '/100' : ''; ?></strong>
                                <small><?php echo htmlspecialchars($empEstoqueResumo, ENT_QUOTES, 'UTF-8'); ?></small>
                            </div>
                            <div class="dash-emp-kpi kpi-blue">
                                <span class="label">Salão e caixa</span>
                                <strong data-dash-campo="salao" data-total="<?php echo (int)$total_mesas; ?>"><?php echo (int)$mesas_ocupadas; ?>/<?php echo (int)$total_mesas; ?> · <?php echo $caixa_aberto ? 'Aberto' : 'Fechado'; ?></strong>
                                <small><?php echo $empOcupPct; ?>% ocupação · <?php echo $caixa_aberto ? 'operacional' : 'abrir caixa'; ?></small>
                            </div>
                        </div>
                        <?php if (is_array($dashEmpPeriodo)):
                            $perW = 760; $perH = 190; $perPL = 10; $perPR = 10; $perPT = 14; $perPB = 24;
                            $perN = count($dashEmpPeriodo['atual']);
                            $perMax = max(1, max(array_column($dashEmpPeriodo['atual'], 'v')), max(array_column($dashEmpPeriodo['anterior'], 'v')));
                            $perPontos = static function (array $serie) use ($perW, $perH, $perPL, $perPR, $perPT, $perPB, $perN, $perMax): string {
                                $pts = [];
                                foreach ($serie as $iP => $pt) {
                                    $x = $perPL + ($perN > 1 ? ($iP / ($perN - 1)) : 0.5) * ($perW - $perPL - $perPR);
                                    $y = $perPT + ($perH - $perPT - $perPB) * (1 - ($pt['v'] / $perMax));
                                    $pts[] = round($x, 1) . ',' . round($y, 1);
                                }
                                return implode(' ', $pts);
                            };
                        ?>
                            <section class="dash-periodo mb-3" id="dashPeriodo">
                                <div class="dash-periodo-head">
                                    <div>
                                        <strong><i class="fas fa-chart-area"></i> Receita: últimos <?php echo (int)$dashEmpPeriodo['dias']; ?> dias contra os <?php echo (int)$dashEmpPeriodo['dias']; ?> anteriores</strong>
                                        <span>
                                            <?php echo number_format($dashEmpPeriodo['total_atual'], 2, ',', '.'); ?> MZN agora ·
                                            <?php echo number_format($dashEmpPeriodo['total_anterior'], 2, ',', '.'); ?> MZN antes
                                            <?php if ($dashEmpPeriodo['delta'] !== null): $dPer = (float)$dashEmpPeriodo['delta']; ?>
                                                · <b class="<?php echo $dPer >= 0 ? 'is-sobe' : 'is-desce'; ?>"><i class="fas fa-arrow-<?php echo $dPer >= 0 ? 'up' : 'down'; ?>"></i> <?php echo number_format(abs($dPer), 1, ',', '.'); ?>%</b>
                                            <?php else: ?>
                                                · sem vendas no período anterior
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <div class="dash-periodo-chips">
                                        <?php foreach ([7, 30, 90] as $perOp): ?>
                                            <a class="<?php echo $perOp === (int)$dashEmpPeriodo['dias'] ? 'is-ativo' : ''; ?>" href="?periodo=<?php echo $perOp; ?>#dashPeriodo"><?php echo $perOp; ?> dias</a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <svg viewBox="0 0 <?php echo $perW; ?> <?php echo $perH; ?>" width="100%" height="190" preserveAspectRatio="none" role="img" aria-label="Receita diária do período actual e do período anterior">
                                    <line x1="<?php echo $perPL; ?>" y1="<?php echo $perH - $perPB; ?>" x2="<?php echo $perW - $perPR; ?>" y2="<?php echo $perH - $perPB; ?>" stroke="#e2e8f0" stroke-width="1"/>
                                    <polyline points="<?php echo $perPontos($dashEmpPeriodo['anterior']); ?>" fill="none" stroke="#94a3b8" stroke-width="2" stroke-dasharray="5 5" vector-effect="non-scaling-stroke"/>
                                    <polyline points="<?php echo $perPontos($dashEmpPeriodo['atual']); ?>" fill="none" stroke="#ea580c" stroke-width="2.5" vector-effect="non-scaling-stroke"/>
                                </svg>
                                <div class="dash-periodo-legenda">
                                    <span><i class="leg-atual"></i> Período actual (<?php echo date('d/m', strtotime($dashEmpPeriodo['atual'][0]['d'])); ?> a <?php echo date('d/m'); ?>)</span>
                                    <span><i class="leg-anterior"></i> Período anterior (<?php echo date('d/m', strtotime($dashEmpPeriodo['anterior'][0]['d'])); ?> a <?php echo date('d/m', strtotime($dashEmpPeriodo['anterior'][$perN - 1]['d'])); ?>)</span>
                                </div>
                            </section>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php $proDash = ($plano_atual === 'PROFISSIONAL'); ?>
                        <?php if ($proDash && !empty($dashProAvisos)): ?>
                            <section class="dash-avisos dash-avisos--simples mb-3">
                                <div class="dash-avisos-head">
                                    <strong><i class="fas fa-bell"></i> A tratar hoje</strong>
                                    <span><?php echo count($dashProAvisos); ?> aviso(s)</span>
                                </div>
                                <ul class="dash-avisos-lista">
                                    <?php foreach ($dashProAvisos as $avisoPro): ?>
                                        <li class="tom-warn">
                                            <i class="fas <?php echo htmlspecialchars($avisoPro['icone']); ?>"></i>
                                            <div>
                                                <strong><?php echo htmlspecialchars($avisoPro['titulo']); ?></strong>
                                                <span><?php echo htmlspecialchars($avisoPro['texto']); ?></span>
                                            </div>
                                            <a class="dash-avisos-btn" href="<?php echo htmlspecialchars($avisoPro['url']); ?>"><?php echo htmlspecialchars($avisoPro['botao']); ?></a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </section>
                        <?php endif; ?>
                        <!-- GENERIC ADMIN STATS -->
                        <div class="stats-grid"<?php echo $proDash ? ' data-dash-auto="1"' : ''; ?>>
                            <div class="stat-card success">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div class="stat-icon success"><i class="fas fa-coins"></i></div>
                                </div>
                                <div class="stat-value" style="color: var(--success);" data-dash-campo="total_hoje_num"><?php echo number_format($total_hoje, 2, ',', '.'); ?></div>
                                <div class="stat-label">Vendas Hoje (MZN)</div>
                                <div class="mt-2 text-muted" style="font-size: 13px;"><i class="fas fa-shopping-cart me-1"></i> <span data-dash-campo="qtd_hoje_txt"><?php echo $qtd_hoje; ?> vendas</span></div>
                                <div class="mt-1 text-muted" style="font-size: 12px;"><i class="fas fa-chart-line me-1"></i> <span data-dash-campo="ticket_txt">Ticket médio: MZN <?php echo number_format($qtd_hoje > 0 ? ($total_hoje / max(1, $qtd_hoje)) : 0, 2, ',', '.'); ?></span></div>
                                <?php if ($proDash): ?>
                                    <div class="dash-kpi-comparar">
                                        <?php if ($dashProTemOntem): ?>
                                            <span class="<?php echo $dashProDeltaOntem >= 0 ? 'is-sobe' : 'is-desce'; ?>"><i class="fas fa-arrow-<?php echo $dashProDeltaOntem >= 0 ? 'up' : 'down'; ?>"></i> <?php echo number_format(abs((float)$dashProDeltaOntem), 0, ',', '.'); ?>% vs ontem à mesma hora</span>
                                        <?php else: ?>
                                            <span class="is-neutro">sem vendas ontem até esta hora</span>
                                        <?php endif; ?>
                                    </div>
                                    <small class="dash-kpi-nota" id="dashAutoHora">actualiza sozinho</small>
                                <?php endif; ?>
                            </div>
                            <?php if ($proDash && $dashProStockBaixo !== null): ?>
                            <a class="stat-card dash-stat-link" href="estoque_inteligente.php">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div class="stat-icon primary"><i class="fas fa-boxes-stacked"></i></div>
                                </div>
                                <div class="stat-value" style="color: <?php echo $dashProStockBaixo > 0 ? 'var(--danger)' : 'var(--success)'; ?>;"><?php echo (int)$dashProStockBaixo; ?></div>
                                <div class="stat-label">Insumos abaixo do mínimo</div>
                                <div class="mt-2 text-muted" style="font-size: 13px;"><i class="fas fa-<?php echo $dashProStockBaixo > 0 ? 'triangle-exclamation' : 'check-circle'; ?> me-1"></i> <?php echo $dashProStockBaixo > 0 ? 'Repor antes do serviço' : 'Stock dentro do mínimo'; ?></div>
                                <div class="mt-1 text-muted" style="font-size: 12px;"><i class="fas fa-arrow-right me-1"></i> Abrir o stock</div>
                            </a>
                            <?php else: ?>
                            <div class="stat-card">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div class="stat-icon primary"><i class="fas fa-pizza-slice"></i></div>
                                </div>
                                <div class="stat-value" style="color: var(--primary);"><?php echo $total_produtos; ?></div>
                                <div class="stat-label">Produtos Ativos</div>
                                <div class="mt-2 text-muted" style="font-size: 13px;"><i class="fas fa-box me-1"></i> No cardápio</div>
                                <div class="mt-1 text-muted" style="font-size: 12px;"><i class="fas fa-box-open me-1"></i> <?php echo $total_produtos > 0 ? 'Disponíveis para venda' : 'Sem produtos ativos'; ?></div>
                            </div>
                            <?php endif; ?>
                            <div class="stat-card warning">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div class="stat-icon warning"><i class="fas fa-chair"></i></div>
                                </div>
                                <div class="stat-value" style="color: var(--warning);" data-dash-campo="salao_simples" data-total="<?php echo (int)$total_mesas; ?>"><?php echo $mesas_ocupadas; ?>/<?php echo $total_mesas; ?></div>
                                <div class="stat-label">Mesas Ocupadas</div>
                                <div class="mt-2 text-muted" style="font-size: 13px;"><i class="fas fa-check-circle me-1"></i> <?php echo $total_mesas - $mesas_ocupadas; ?> livres</div>
                                <div class="mt-1 text-muted" style="font-size: 12px;"><i class="fas fa-layer-group me-1"></i> <?php echo $total_mesas > 0 ? round(($mesas_ocupadas / $total_mesas) * 100) . '% de ocupação' : 'Sem dados'; ?></div>
                            </div>
                            <div class="stat-card info">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div class="stat-icon info"><i class="fas fa-money-bill-wave"></i></div>
                                </div>
                                <div class="stat-value" style="color: <?php echo $caixa_aberto ? 'var(--success)' : 'var(--danger)'; ?>;"><?php echo $caixa_aberto ? 'ABERTO' : 'FECHADO'; ?></div>
                                <div class="stat-label">Status do Caixa</div>
                                <div class="mt-2 text-muted" style="font-size: 13px;"><i class="fas fa-<?php echo $caixa_aberto ? 'lock-open' : 'lock'; ?> me-1"></i> <?php echo $caixa_aberto ? 'Operacional' : 'Bloqueado'; ?></div>
                                <div class="mt-1 text-muted" style="font-size: 12px;"><i class="fas fa-bolt me-1"></i> <?php echo $caixa_aberto ? 'Pronto para atender' : 'Aguardar abertura'; ?></div>
                            </div>
                        </div>
                        <?php if ($proDash): ?>
                            <section class="dash-top-hoje mb-3">
                                <div class="dash-avisos-head">
                                    <strong><i class="fas fa-fire"></i> Mais vendidos de hoje</strong>
                                    <span>só vendas pagas</span>
                                </div>
                                <?php if (empty($dashProTopHoje)): ?>
                                    <p class="text-muted small mb-0">Ainda não há vendas pagas hoje.</p>
                                <?php else: $topMaxQtd = max(1, (float)$dashProTopHoje[0]['qtd']); ?>
                                    <ol class="dash-top-lista">
                                        <?php foreach ($dashProTopHoje as $topPro): ?>
                                            <li>
                                                <span class="nome"><?php echo htmlspecialchars((string)$topPro['nome']); ?></span>
                                                <span class="barra"><i style="width: <?php echo number_format(((float)$topPro['qtd'] / $topMaxQtd) * 100, 1, '.', ''); ?>%"></i></span>
                                                <span class="qtd"><?php echo rtrim(rtrim(number_format((float)$topPro['qtd'], 2, ',', '.'), '0'), ','); ?> un.</span>
                                                <span class="valor">MZN <?php echo number_format((float)$topPro['valor'], 2, ',', '.'); ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ol>
                                <?php endif; ?>
                            </section>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php include __DIR__ . '/partes/admin_painel_plano.php'; ?>
                    <?php include __DIR__ . '/partes/grafico_e_planos.php'; ?>
                    <?php include __DIR__ . '/partes/analytics_empresarial.php'; ?>
                    <?php include __DIR__ . '/partes/tabelas_operacao.php'; ?>
                    <?php if ($perfil === 'ADMIN' && is_array($resumoExecutivoRede)): ?>
                    <div class="dash-emp-rede card border-0 shadow-sm mb-4">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                                <div>
                                    <h5 class="mb-1"><i class="fas fa-chart-pie me-2" style="color:#ea580c;"></i>Dashboard executivo da rede</h5>
                                    <div class="text-muted small">Resumo das filiais nos últimos 30 dias</div>
                                </div>
                                <a href="filiais.php?secao=dashboard" class="btn btn-sm btn-outline-primary">Ver comparativo completo</a>
                            </div>
                            <div class="row g-3 mb-3">
                                <div class="col-md-3"><div class="dash-emp-rede-kpi"><div class="small text-muted">Filiais</div><strong><?php echo (int)$resumoExecutivoRede['ativas']; ?>/<?php echo (int)$resumoExecutivoRede['total_filiais']; ?></strong></div></div>
                                <div class="col-md-3"><div class="dash-emp-rede-kpi"><div class="small text-muted">Vendas 30d</div><strong><?php echo (int)$resumoExecutivoRede['vendas_30d']; ?></strong></div></div>
                                <div class="col-md-6"><div class="dash-emp-rede-kpi is-accent"><div class="small text-muted">Receita 30d</div><strong><?php echo number_format((float)$resumoExecutivoRede['receita_30d'], 2, ',', '.'); ?> MZN</strong></div></div>
                            </div>
                            <?php if (!empty($dashEmpFiliaisHoje)): ?>
                                <div class="dash-rede-hoje">
                                    <div class="dash-rede-hoje-head"><i class="fas fa-sun"></i> Como está cada unidade hoje</div>
                                    <div class="table-responsive">
                                        <table class="table table-sm mb-0 dash-emp-rede-table">
                                            <thead><tr><th>Unidade</th><th>Vendas hoje</th><th>Caixa</th><th>Última venda</th></tr></thead>
                                            <tbody>
                                            <?php foreach ($dashEmpFiliaisHoje as $uHojeLinha): ?>
                                                <tr>
                                                    <td>
                                                        <?php if (!empty($uHojeLinha['matriz'])): ?><i class="fas fa-crown text-warning me-1"></i><?php endif; ?>
                                                        <?php echo htmlspecialchars($uHojeLinha['nome'], ENT_QUOTES, 'UTF-8'); ?>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo number_format((float)$uHojeLinha['total'], 2, ',', '.'); ?> MZN</strong>
                                                        <small class="d-block text-muted"><?php echo (int)$uHojeLinha['vendas']; ?> venda(s)</small>
                                                    </td>
                                                    <td>
                                                        <span class="badge <?php echo $uHojeLinha['caixa_aberto'] ? 'bg-success' : 'bg-secondary'; ?>">
                                                            <?php echo $uHojeLinha['caixa_aberto'] ? 'aberto' : 'fechado'; ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo $uHojeLinha['ultima'] ? htmlspecialchars($uHojeLinha['ultima']) : '<span class="text-muted">sem vendas hoje</span>'; ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($resumoExecutivoRede['top'])): ?>
                            <div class="table-responsive">
                                <table class="table table-sm mb-0 dash-emp-rede-table">
                                    <thead><tr><th>Ranking da rede</th><th>Receita 30d</th><th>Share</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($resumoExecutivoRede['top'] as $fTop): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars((string)$fTop['nome'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo number_format((float)$fTop['receita_30d'], 2, ',', '.'); ?> MZN</td>
                                            <td>
                                                <div class="dash-emp-share">
                                                    <i style="width:<?php echo max(4, min(100, (float)$fTop['share'])); ?>%"></i>
                                                    <span><?php echo number_format((float)$fTop['share'], 1, ',', '.'); ?>%</span>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                                <p class="text-muted small mb-0">Ainda não há unidades com vendas pagas para comparar.</p>
                            <?php endif; ?>
                            <?php if (!empty($resumoExecutivoRede['nota'])): ?>
                                <div class="dash-emp-rede-note tom-<?php echo htmlspecialchars((string)($resumoExecutivoRede['nota']['tom'] ?? 'info')); ?> mt-3">
                                    <strong><?php echo htmlspecialchars((string)($resumoExecutivoRede['nota']['titulo'] ?? '')); ?></strong>
                                    <span><?php echo htmlspecialchars((string)($resumoExecutivoRede['nota']['txt'] ?? '')); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

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

                        <?php if ($temMultiFilial && $perfil === 'ADMIN'): ?>
                            <div class="col-6 col-md-3">
                                <a href="filiais.php<?php echo $temDashboardExecutivo ? '?secao=dashboard' : ''; ?>" class="quick-action" style="background: linear-gradient(135deg, #0f766e, #115e59);">
                                    <i class="fas fa-building fa-2x mb-2"></i><span><?php echo $temDashboardExecutivo ? 'Filiais Executivo' : 'Filiais'; ?></span>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

