                    <?php if ($plano_atual === 'PROFISSIONAL'): ?>
                        <div class="row g-3 mb-3 plan-pro-exec">
                            <div class="col-lg-6">
                                <div class="executive-card professional-highlight">
                                    <div class="executive-title"><i class="fas fa-chart-line"></i> Visão Geral de Receita</div>
                                    <div class="executive-main">MZN <?php echo number_format($receita_mes_atual, 2, ',', '.'); ?></div>
                                    <?php $crescimentoPositivo = $crescimento_mensal_pct >= 0; ?>
                                    <div class="kpi-row">
                                        <span>Vs. mês anterior</span>
                                        <span class="trend-chip <?php echo $crescimentoPositivo ? 'trend-positive' : 'trend-negative'; ?>">
                                            <i class="fas fa-<?php echo $crescimentoPositivo ? 'arrow-up' : 'arrow-down'; ?>"></i>
                                            <?php echo number_format(abs($crescimento_mensal_pct), 1, ',', '.'); ?>%
                                        </span>
                                    </div>
                                    <div class="kpi-row">
                                        <span>Mês anterior</span>
                                        <strong>MZN <?php echo number_format($receita_mes_anterior, 2, ',', '.'); ?></strong>
                                    </div>
                                    <div class="kpi-row">
                                        <span>Média por dia (14 dias)</span>
                                        <strong>MZN <?php echo number_format((float)($media_diaria_14d ?? 0), 2, ',', '.'); ?></strong>
                                    </div>
                                    <div class="kpi-row">
                                        <span>Melhor dia (14 dias)</span>
                                        <strong>MZN <?php echo number_format((float)($pico_14d ?? 0), 2, ',', '.'); ?></strong>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="executive-card professional-highlight">
                                    <div class="executive-title">
                                        <i class="fas fa-users text-info"></i>
                                        Desempenho da Equipe
                                        <span class="badge ms-2" style="font-size: 10px; padding: 4px 8px; border-radius: 999px; background:#0e7490;color:#fff;">Profissional</span>
                                    </div>
                                    <ul class="insight-list">
                                        <li>
                                            <span>Top Garçom</span>
                                            <span>
                                                <?php if ($top_garcom): ?>
                                                    <strong><?php echo htmlspecialchars($top_garcom['nome']); ?></strong>
                                                <?php else: ?>
                                                    <span class="text-muted small">Sem pedidos hoje</span>
                                                <?php endif; ?>
                                            </span>
                                        </li>
                                        <li>
                                            <span>Pedidos Garçom</span>
                                            <span class="<?php echo $top_garcom ? '' : 'text-muted'; ?>">
                                                <?php echo $top_garcom ? intval($top_garcom['total_pedidos']) . ' pedido(s)' : '0 pedido(s)'; ?>
                                            </span>
                                        </li>
                                        <li>
                                            <span>Top Caixa</span>
                                            <span>
                                                <?php if ($top_caixa): ?>
                                                    <strong><?php echo htmlspecialchars($top_caixa['nome']); ?></strong>
                                                <?php else: ?>
                                                    <span class="text-muted small">Sem vendas hoje</span>
                                                <?php endif; ?>
                                            </span>
                                        </li>
                                        <li>
                                            <span>Equipe online</span>
                                            <span><strong><?php echo intval($team_online['online']); ?>/<?php echo intval($team_online['total']); ?></strong></span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <div class="plan-pro-pedidos-bar">
                            <div>
                                <div class="fw-bold" style="font-family:'Space Grotesk',sans-serif;">Fila de pedidos</div>
                                <div class="text-muted small">Resumo operacional da unidade</div>
                            </div>
                            <div class="bar-metrics">
                                <span>Pendentes <strong><?php echo (int)$pedidos_resumo['pendentes']; ?></strong></span>
                                <span>Em preparo <strong><?php echo (int)$pedidos_resumo['preparando']; ?></strong></span>
                                <span>Prontos <strong><?php echo (int)$pedidos_resumo['prontos']; ?></strong></span>
                                <span>Mais antigo <strong><?php echo is_null($oldest_pending_minutes) ? '—' : ($oldest_pending_minutes . ' min'); ?></strong></span>
                            </div>
                            <a href="pedidos.php" class="btn btn-sm btn-outline-primary" style="border-radius:10px;font-weight:700;">Abrir pedidos</a>
                        </div>
                    <?php elseif ($plano_atual === 'EMPRESARIAL'): ?>
                        <div class="dash-emp-layout mb-3">
                        <div class="dash-emp-main">
                        <div class="row g-3 mb-3">
                            <div class="col-lg-4">
                                <div class="executive-card enterprise-highlight dash-emp-exec-card">
                                    <div class="executive-title"><i class="fas fa-chart-line"></i> Visão Geral de Receita</div>
                                    <div class="executive-main">MZN <?php echo number_format($receita_mes_atual, 2, ',', '.'); ?></div>
                                    <?php $crescimentoPositivo = $crescimento_mensal_pct >= 0; ?>
                                    <div class="kpi-row">
                                        <span>Vs. mês anterior</span>
                                        <span class="trend-chip <?php echo $crescimentoPositivo ? 'trend-positive' : 'trend-negative'; ?>">
                                            <i class="fas fa-<?php echo $crescimentoPositivo ? 'arrow-up' : 'arrow-down'; ?>"></i>
                                            <?php echo number_format(abs($crescimento_mensal_pct), 1, ',', '.'); ?>%
                                        </span>
                                    </div>
                                    <div class="kpi-row">
                                        <span>Mês anterior</span>
                                        <strong>MZN <?php echo number_format($receita_mes_anterior, 2, ',', '.'); ?></strong>
                                    </div>
                                    <div class="kpi-row">
                                        <span>Vendas hoje</span>
                                        <strong><?php echo $qtd_hoje; ?> venda(s)</strong>
                                    </div>
                                    <div class="kpi-row">
                                        <span>Ticket médio</span>
                                        <strong>MZN <?php echo number_format($qtd_hoje > 0 ? ($total_hoje / max(1, $qtd_hoje)) : 0, 2, ',', '.'); ?></strong>
                                    </div>
                                </div>
                            </div>

                            <div class="col-lg-4">
                                <div class="executive-card enterprise-highlight dash-emp-exec-card">
                                    <div class="executive-title">
                                        <i class="fas fa-users"></i>
                                        Desempenho da Equipe
                                    </div>
                                    <ul class="insight-list">
                                        <li>
                                            <span>Top Garçom</span>
                                            <span>
                                                <?php if ($top_garcom): ?>
                                                    <strong><?php echo htmlspecialchars($top_garcom['nome']); ?></strong>
                                                <?php else: ?>
                                                    <span class="text-muted small">Sem pedidos hoje</span>
                                                <?php endif; ?>
                                            </span>
                                        </li>
                                        <li>
                                            <span>Pedidos Garçom</span>
                                            <span class="<?php echo $top_garcom ? '' : 'text-muted'; ?>">
                                                <?php echo $top_garcom ? intval($top_garcom['total_pedidos']) . ' pedido(s)' : '0 pedido(s)'; ?>
                                            </span>
                                        </li>
                                        <li>
                                            <span>Top Caixa</span>
                                            <span>
                                                <?php if ($top_caixa): ?>
                                                    <strong><?php echo htmlspecialchars($top_caixa['nome']); ?></strong>
                                                <?php else: ?>
                                                    <span class="text-muted small">Sem vendas hoje</span>
                                                <?php endif; ?>
                                            </span>
                                        </li>
                                        <li>
                                            <span>Receita Caixa</span>
                                            <span class="<?php echo $top_caixa ? '' : 'text-muted'; ?>">
                                                <?php echo $top_caixa ? 'MZN ' . number_format(floatval($top_caixa['receita']), 2, ',', '.') : 'MZN 0,00'; ?>
                                            </span>
                                        </li>
                                    </ul>
                                    <div class="mt-3 pt-3 border-top">
                                        <div class="d-flex justify-content-between align-items-start gap-2">
                                            <div>
                                                <div class="small text-muted mb-1">Resumo do dia</div>
                                                <div class="fw-semibold" style="font-size:13px;">
                                                    <?php if ($top_garcom): ?>
                                                        Maior performance hoje: <?php echo htmlspecialchars($top_garcom['nome']); ?>
                                                    <?php else: ?>
                                                        Sem destaque de performance hoje
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="text-end">
                                                <div class="small text-muted mb-1">Equipe online</div>
                                                <div class="fw-bold" style="font-size:13px;">
                                                    <?php echo intval($team_online['online']); ?>/<?php echo intval($team_online['total']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if ($canalSuporteContato !== ''): ?>
                                        <div class="mt-3 pt-3 border-top">
                                            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                                <div>
                                                    <?php if ($temSuporte24h): ?>
                                                        <div class="fw-semibold" style="font-size:13px;">Suporte 24/7 ativo</div>
                                                        <div class="text-muted" style="font-size:12px;">Abra ticket prioritario direto no painel.</div>
                                                    <?php elseif ($temSuporteWhatsapp): ?>
                                                        <div class="fw-semibold" style="font-size:13px;">Suporte via WhatsApp e email</div>
                                                        <div class="text-muted" style="font-size:12px;">Registre o protocolo, envie copia por email e continue no canal oficial.</div>
                                                    <?php else: ?>
                                                        <div class="fw-semibold" style="font-size:13px;">Suporte por email</div>
                                                        <div class="text-muted" style="font-size:12px;">Envie a solicitação e receba o protocolo de atendimento.</div>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($temSuporte24h): ?>
                                                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalSuporte247">
                                                        <i class="fas fa-life-ring me-1"></i>Abrir Ticket
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalSuporteCanal">
                                                        <i class="fas fa-life-ring me-1"></i><?php echo $temSuporteWhatsapp ? 'Abrir WhatsApp' : 'Enviar email'; ?>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="col-lg-4">
                                <div class="executive-card enterprise-highlight dash-emp-exec-card">
                                    <div class="executive-title"><i class="fas fa-diagram-project"></i> Pedidos por Canal</div>
                                        <?php
                                    // Preferência: distribuição de HOJE (alinha com vendas). Fallback: fila pendente.
                                    $canaisFonte = $by_canal_hoje ?? [];
                                    $canalTotalExibido = (int)($pedidos_hoje_total ?? 0);
                                    $canalPeriodoLabel = 'hoje';
                                    if ($canalTotalExibido <= 0) {
                                        $canaisFonte = $by_canal ?? [];
                                        $canalTotalExibido = (int)(($canaisFonte['ONLINE'] ?? 0) + ($canaisFonte['BALCAO'] ?? 0) + ($canaisFonte['GARCOM'] ?? 0) + ($canaisFonte['TELEFONE'] ?? 0) + ($canaisFonte['OUTRO'] ?? 0));
                                        if ($canalTotalExibido <= 0) {
                                            $canalTotalExibido = (int)($pedidos_resumo['pendentes'] ?? 0);
                                        }
                                        $canalPeriodoLabel = 'na fila';
                                    }
                                    $onlineCnt = (int)($canaisFonte['ONLINE'] ?? $canaisFonte['QR'] ?? 0);
                                    $balcaoCnt = (int)($canaisFonte['BALCAO'] ?? $canaisFonte['PRESENCIAL'] ?? 0);
                                    $garcomCnt = (int)($canaisFonte['GARCOM'] ?? 0);
                                    $telefoneCnt = (int)($canaisFonte['TELEFONE'] ?? 0);
                                    $outroCnt = (int)($canaisFonte['OUTRO'] ?? 0);
                                        $canalPrioritario = '';
                                        $canalPrioritarioQty = 0;
                                    foreach (['ONLINE' => $onlineCnt, 'BALCAO' => $balcaoCnt, 'GARCOM' => $garcomCnt, 'TELEFONE' => $telefoneCnt] as $nomeCanal => $qtdCanal) {
                                            if ((int)$qtdCanal > $canalPrioritarioQty) {
                                                $canalPrioritarioQty = (int)$qtdCanal;
                                                $canalPrioritario = $nomeCanal;
                                            }
                                        }
                                        $canalNomes = [
                                        'QR' => 'Online / QR',
                                        'ONLINE' => 'Online / QR',
                                            'BALCAO' => 'Balcão',
                                            'PRESENCIAL' => 'Balcão',
                                            'GARCOM' => 'Garçom',
                                            'TELEFONE' => 'Telefone',
                                            'OUTRO' => 'Outro',
                                        ];
                                    $canalPrincipalLabel = $canalPrioritarioQty > 0
                                        ? (($canalNomes[$canalPrioritario] ?? ucfirst(strtolower($canalPrioritario))) . ' (' . $canalPrioritarioQty . ')')
                                        : 'Sem dados';
                                        ?>
                                    <div class="executive-main"><?php echo $canalTotalExibido; ?></div>
                                    <div class="small text-muted mb-2">Pedidos <?php echo htmlspecialchars($canalPeriodoLabel); ?></div>
                                        <div class="kpi-row"><span>Online / QR</span><strong><?php echo $onlineCnt; ?></strong></div>
                                        <div class="kpi-row"><span>Balcão</span><strong><?php echo $balcaoCnt; ?></strong></div>
                                        <div class="kpi-row"><span>Garçom</span><strong><?php echo $garcomCnt; ?></strong></div>
                                        <?php if ($telefoneCnt > 0): ?>
                                            <div class="kpi-row"><span>Telefone</span><strong><?php echo $telefoneCnt; ?></strong></div>
                                        <?php endif; ?>
                                        <?php if ($outroCnt > 0): ?>
                                            <div class="kpi-row"><span>Outro</span><strong><?php echo $outroCnt; ?></strong></div>
                                        <?php endif; ?>
                                        <div class="kpi-row"><span>Canal principal</span><strong><?php echo htmlspecialchars($canalPrincipalLabel); ?></strong></div>
                                        <?php if ((int)($pedidos_resumo['pendentes'] ?? 0) > 0): ?>
                                            <div class="kpi-row"><span>Ainda na fila</span><strong><?php echo (int)$pedidos_resumo['pendentes']; ?></strong></div>
                                        <?php endif; ?>
                                        <?php if (!empty($oldest_pending_orders)): ?>
                                            <div class="mt-2">
                                                <div class="small text-muted mb-1">Pendentes mais antigos</div>
                                                <ul class="list-unstyled mb-2">
                                                    <?php foreach ($oldest_pending_orders as $op): ?>
                                                        <li class="d-flex justify-content-between align-items-center py-1 border-bottom">
                                                            <div>
                                                                <strong>#<?php echo $op['id']; ?></strong>
                                                                <span class="ms-2"><?php echo htmlspecialchars($op['cliente_nome'] ?: '---'); ?></span>
                                                                <span class="badge bg-light text-dark ms-2" style="font-size:11px;"><?php echo htmlspecialchars($canalNomes[$op['canal'] ?? ''] ?? ucfirst(strtolower($op['canal'] ?? 'Outro'))); ?></span>
                                                            </div>
                                                            <div class="text-muted small"><?php echo is_null($op['age_min']) ? '—' : ($op['age_min'] . 'm'); ?></div>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                        <div class="mt-2 d-flex gap-2">
                                            <a href="pedidos.php" class="btn btn-sm btn-outline-primary">Ver Pedidos</a>
                                            <a href="pedidos.php?filter=pendentes" class="btn btn-sm btn-primary">Ver Pendentes</a>
                                        </div>
                                </div>
                            </div>
                        </div>

                        </div><!-- /.dash-emp-main -->
                        <aside class="dash-emp-side">
                                            <?php
                            $empSideCrit = (int)($estoque_resumo['critico'] ?? 0);
                            $empSideRes = (int)($dashboard_reservas['pendentes'] ?? 0);
                            $empSideEstoqueTop = array_slice(is_array($estoque_baixo) ? $estoque_baixo : [], 0, 3);
                            $empSidePendentes = array_slice(is_array($oldest_pending_orders) ? $oldest_pending_orders : [], 0, 3);
                            $empCanalNomesSide = [
                                'QR' => 'Online / QR', 'ONLINE' => 'Online / QR', 'BALCAO' => 'Balcão',
                                'PRESENCIAL' => 'Balcão', 'GARCOM' => 'Garçom', 'TELEFONE' => 'Telefone', 'OUTRO' => 'Outro',
                            ];
                            $empTeamMembers = $team_online['equipa'] ?? [];
                            $empTeamEnhanced = [];
                            foreach ($empTeamMembers as $m) {
                                                $uid = intval($m['id'] ?? $m['usuario_id'] ?? $m['user_id'] ?? 0);
                                                $perf = $team_performance[$uid] ?? ['pedidos' => 0, 'valor' => 0.0];
                                $empTeamEnhanced[] = [
                                                    'nome' => $m['nome'] ?? ($m['display_name'] ?? ''),
                                                    'perfil' => $m['perfil'] ?? '',
                                                    'foto' => trim((string)($m['foto'] ?? $m['imagem'] ?? $m['avatar'] ?? '')),
                                                    'online' => !empty($m['online']),
                                                    'pedidos' => intval($perf['pedidos'] ?? 0),
                                    'valor' => floatval($perf['valor'] ?? 0.0),
                                ];
                            }
                            usort($empTeamEnhanced, static function ($a, $b) {
                                if ($a['valor'] === $b['valor']) {
                                    return $b['pedidos'] <=> $a['pedidos'];
                                }
                                                return $b['valor'] <=> $a['valor'];
                                            });
                                            ?>
                            <div class="dash-emp-side-card">
                                <h6><i class="fas fa-bolt me-1" style="color:#ea580c;"></i>Acções rápidas</h6>
                                <div class="dash-emp-side-row"><span>Reservas</span><strong><?php echo $empSideRes; ?></strong></div>
                                <div class="dash-emp-side-row"><span>Estoque crítico</span><strong><?php echo $empSideCrit; ?></strong></div>
                                <div class="dash-emp-side-row"><span>Equipe online</span><strong id="teamOnlineCount"><?php echo intval($team_online['online']); ?>/<?php echo intval($team_online['total']); ?></strong></div>

                                <?php if (!$caixa_aberto && $temCaixa): ?>
                                    <div class="dash-emp-prio is-danger">
                                        <div class="prio-title">Caixa fechado</div>
                                        <div class="prio-meta">Abra o caixa para operar vendas.</div>
                                        <a href="caixa.php" class="btn btn-sm btn-warning mt-2">Abrir caixa</a>
                                    </div>
                                                                <?php endif; ?>

                                <?php if ($empSideRes > 0): ?>
                                    <div class="dash-emp-prio is-warn">
                                        <div class="prio-title"><?php echo $empSideRes; ?> reserva(s) pendente(s)</div>
                                        <a href="mesas.php" class="btn btn-sm btn-outline-primary mt-2">Ver reservas</a>
                                                            </div>
                                <?php endif; ?>

                                <?php if (!empty($empSideEstoqueTop)): ?>
                                    <div class="dash-emp-prio-block">
                                        <div class="prio-label">Estoque a repor</div>
                                        <?php foreach ($empSideEstoqueTop as $epSide): ?>
                                            <div class="dash-emp-prio-item">
                                                <strong><?php echo htmlspecialchars((string)($epSide['nome'] ?? '')); ?></strong>
                                                <span><?php echo number_format((float)($epSide['estoque'] ?? 0), 1, ',', '.'); ?> <?php echo htmlspecialchars((string)($epSide['unidade_base'] ?? '')); ?></span>
                                                            </div>
                                        <?php endforeach; ?>
                                        <a href="estoque_inteligente.php" class="dash-emp-side-link">Abrir estoque inteligente</a>
                                                        </div>
                                                            <?php endif; ?>

                                <?php if (!empty($empSidePendentes)): ?>
                                    <div class="dash-emp-prio-block">
                                        <div class="prio-label">Pedidos mais antigos</div>
                                        <?php foreach ($empSidePendentes as $opSide): ?>
                                            <div class="dash-emp-prio-item">
                                                <strong>#<?php echo (int)$opSide['id']; ?></strong>
                                                <span><?php echo htmlspecialchars($empCanalNomesSide[$opSide['canal'] ?? ''] ?? 'Outro'); ?> · <?php echo is_null($opSide['age_min'] ?? null) ? '—' : ((int)$opSide['age_min'] . ' min'); ?></span>
                                                        </div>
                                                <?php endforeach; ?>
                                        <a href="pedidos.php?filter=pendentes" class="dash-emp-side-link">Ver pendentes</a>
                                    </div>
                                            <?php endif; ?>

                                <?php if ($caixa_aberto && $empSideRes <= 0 && empty($empSideEstoqueTop) && empty($empSidePendentes)): ?>
                                    <div class="dash-emp-prio is-ok">
                                        <div class="prio-title">Operação estável</div>
                                        <div class="prio-meta">Sem alertas urgentes neste momento.</div>
                                                        </div>
                                <?php endif; ?>

                                <div class="dash-emp-team mt-3">
                                    <div class="prio-label">Equipe · ranking do dia</div>
                                    <ul class="dash-emp-team-list" id="teamOnlineList" data-server-rendered="1">
                                        <?php if (empty($empTeamEnhanced)): ?>
                                            <li class="text-muted small py-1">Sem presença da equipe.</li>
                                        <?php else: ?>
                                            <?php foreach ($empTeamEnhanced as $idx => $m): ?>
                                                <li class="dash-emp-team-item">
                                                    <div class="dash-emp-team-who">
                                                        <span class="dash-emp-team-avatar"><?php echo htmlspecialchars(mb_strtoupper(mb_substr(trim((string)($m['nome'] ?? 'U')), 0, 1, 'UTF-8'), 'UTF-8')); ?></span>
                                                        <div>
                                                            <strong><?php echo htmlspecialchars($m['nome']); ?></strong>
                                                            <small><?php echo htmlspecialchars($m['perfil']); ?><?php echo !empty($m['online']) ? ' · online' : ''; ?></small>
                                                        </div>
                                                    </div>
                                                    <div class="dash-emp-team-meta">
                                                        <?php if ($idx < 3 && ((float)$m['valor'] > 0 || (int)$m['pedidos'] > 0)): ?>
                                                            <span class="dash-emp-team-rank"><?php echo ($idx + 1); ?>º</span>
                                                        <?php endif; ?>
                                                        <strong><?php echo number_format((float)$m['valor'], 0, ',', '.'); ?></strong>
                                                    </div>
                                                </li>
                                            <?php endforeach; ?>
                                            <?php endif; ?>
                                        </ul>
                                </div>
                            </div>
                        </aside>
                        </div><!-- /.dash-emp-layout -->
                    <?php endif; ?>

