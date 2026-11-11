                    <?php if ($perfil === 'ADMIN'): ?>
                        <?php if ($plano_atual === 'BASICO'): ?>
                            <div class="dashboard-plan-strip">
                                <div class="dashboard-plan-chip">
                                    <div class="chip-kicker">Incluído</div>
                                    <div class="chip-title">PDV, mesas e caixa</div>
                                    <p class="chip-copy">Operação completa do salão no dia a dia.</p>
                                </div>
                                <div class="dashboard-plan-chip">
                                    <div class="chip-kicker">Incluído</div>
                                    <div class="chip-title">Cardápio e estoque simples</div>
                                    <p class="chip-copy">Produtos, categorias e alertas básicos.</p>
                                </div>
                                <div class="dashboard-plan-chip is-locked">
                                    <div class="chip-kicker">Profissional</div>
                                    <div class="chip-title"><i class="fas fa-lock me-1"></i>Bar e Cozinha (KDS)</div>
                                    <p class="chip-copy">Filas de preparo por sector.</p>
                                </div>
                                <div class="dashboard-plan-chip is-locked">
                                    <div class="chip-kicker">Profissional</div>
                                    <div class="chip-title"><i class="fas fa-lock me-1"></i>QR, clientes e relatórios</div>
                                    <p class="chip-copy">Pedidos online e gestão avançada.</p>
                                </div>
                            </div>
                            <?php if ($mostrarBotaoUpgradePlano): ?>
                                <div class="dashboard-plan-upgrade">
                                    <div>
                                        <div class="fw-bold" style="font-family:'Space Grotesk',sans-serif;">Quer ver o que o Profissional desbloqueia?</div>
                                        <div class="text-muted small mt-1">Receita do mês, fichas técnicas, baixa automática, clientes e relatórios avançados.</div>
                                    </div>
                                    <a href="configuracoes.php?secao=plano&rid=<?php echo (int)$rid; ?>" class="btn btn-primary btn-sm" style="border-radius:10px;font-weight:700;">
                                        Comparar planos
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php elseif ($plano_atual === 'PROFISSIONAL'): ?>
                            <div class="dashboard-plan-strip">
                                <div class="dashboard-plan-chip">
                                    <div class="chip-kicker">Activo</div>
                                    <div class="chip-title">Ficha técnica</div>
                                    <p class="chip-copy">Custo do prato e baixa automática de insumos.</p>
                                </div>
                                <div class="dashboard-plan-chip">
                                    <div class="chip-kicker">Activo</div>
                                    <div class="chip-title">Desempenho da equipe</div>
                                    <p class="chip-copy">Top garçom, caixa e presença online.</p>
                                </div>
                                <div class="dashboard-plan-chip">
                                    <div class="chip-kicker">Activo</div>
                                    <div class="chip-title">Relatórios e clientes</div>
                                    <p class="chip-copy">Exportações e histórico de visitas.</p>
                                </div>
                                <div class="dashboard-plan-chip is-locked">
                                    <div class="chip-kicker">Empresarial</div>
                                    <div class="chip-title"><i class="fas fa-lock me-1"></i>Rede, QR e transferência</div>
                                    <p class="chip-copy">Multi-filial, reservas QR e stock entre filiais.</p>
                                </div>
                            </div>
                            <?php if ($mostrarBotaoUpgradePlano): ?>
                                <div class="dashboard-plan-upgrade">
                                    <div>
                                        <div class="fw-bold" style="font-family:'Space Grotesk',sans-serif;">Pronto para o Empresarial?</div>
                                        <div class="text-muted small mt-1">Multi-filial, QR de reservas, transferência de estoque e visão da rede.</div>
                                    </div>
                                    <a href="configuracoes.php?secao=plano&rid=<?php echo (int)$rid; ?>" class="btn btn-primary btn-sm" style="border-radius:10px;font-weight:700;">
                                        Ver Empresarial
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php elseif ($plano_atual === 'EMPRESARIAL'): ?>
                            <?php
                            $empChipEstCrit = (int)($estoque_resumo['critico'] ?? 0);
                            $empChipEstAlerta = (int)($estoque_resumo['alerta'] ?? 0);
                            $empChipEstAten = $empChipEstCrit + $empChipEstAlerta;
                            $empChipScore = is_array($dashEmpInsights) ? (int)($dashEmpInsights['score_saude'] ?? 0) : null;
                            $empChipPend = (int)($pedidos_resumo['pendentes'] ?? 0);
                            $empChipPrep = (int)($pedidos_resumo['preparando'] ?? 0);
                            $empChipPronto = (int)($pedidos_resumo['prontos'] ?? 0);
                            $empChipRes = (int)($dashboard_reservas['pendentes'] ?? 0);
                            $empChipRedeAtivas = is_array($resumoExecutivoRede) ? (int)$resumoExecutivoRede['ativas'] : 0;
                            $empChipRedeTotal = is_array($resumoExecutivoRede) ? (int)$resumoExecutivoRede['total_filiais'] : 0;
                            $empChipRedeReceita = is_array($resumoExecutivoRede) ? (float)$resumoExecutivoRede['receita_30d'] : 0.0;
                            $empInsightLines = [];
                            if (!$caixa_aberto && $temCaixa) {
                                $empInsightLines[] = ['tom' => 'danger', 'txt' => 'Caixa fechado — abra antes de vender.', 'href' => 'caixa.php', 'cta' => 'Abrir caixa'];
                            }
                            if ($empChipEstCrit > 0) {
                                $empInsightLines[] = ['tom' => 'danger', 'txt' => $empChipEstCrit . ' insumo(s) críticos sem stock.', 'href' => 'estoque_inteligente.php', 'cta' => 'Ver estoque'];
                            } elseif ($empChipEstAlerta > 0) {
                                $empInsightLines[] = ['tom' => 'warn', 'txt' => $empChipEstAlerta . ' insumo(s) abaixo do mínimo.', 'href' => 'estoque_inteligente.php', 'cta' => 'Repor'];
                            }
                            if (!is_null($oldest_pending_minutes) && (int)$oldest_pending_minutes >= 15) {
                                $empInsightLines[] = ['tom' => 'warn', 'txt' => 'Pedido mais antigo há ' . (int)$oldest_pending_minutes . ' min — acelerar cozinha/garçom.', 'href' => 'pedidos.php?filter=pendentes', 'cta' => 'Ver fila'];
                            } elseif ($empChipPend > 0) {
                                $empInsightLines[] = ['tom' => 'info', 'txt' => $empChipPend . ' pedido(s) na fila · ' . $empChipPrep . ' em preparo · ' . $empChipPronto . ' prontos.', 'href' => 'pedidos.php', 'cta' => 'Pedidos'];
                            }
                            if ($empChipRes > 0) {
                                $empInsightLines[] = ['tom' => 'info', 'txt' => $empChipRes . ' reserva(s) pendente(s) no salão.', 'href' => 'mesas.php', 'cta' => 'Reservas'];
                            }
                            if ($empChipScore !== null && $empChipScore < 70) {
                                $empInsightLines[] = ['tom' => 'warn', 'txt' => 'Score de estoque em ' . $empChipScore . '/100 — rever fichas e cobertura.', 'href' => 'estoque_inteligente.php', 'cta' => 'Analisar'];
                            }
                            if ($crescimento_mensal_pct != 0) {
                                $empInsightLines[] = [
                                    'tom' => $crescimento_mensal_pct >= 0 ? 'ok' : 'warn',
                                    'txt' => 'Receita do mês ' . ($crescimento_mensal_pct >= 0 ? '+' : '') . number_format($crescimento_mensal_pct, 1, ',', '.') . '% vs mês anterior.',
                                    'href' => 'relatorios.php',
                                    'cta' => 'Relatórios',
                                ];
                            }
                            if (empty($empInsightLines)) {
                                $empInsightLines[] = ['tom' => 'ok', 'txt' => 'Operação estável: sem alertas urgentes neste momento.', 'href' => 'vendas.php', 'cta' => 'PDV'];
                            }
                            $empInsightLines = array_slice($empInsightLines, 0, 3);
                            ?>
                            <div class="dash-emp-hero mb-3">
                                <div class="d-flex align-items-start gap-3 min-w-0">
                                    <?php if (!empty($foto_usuario_url)): ?>
                                        <img class="dash-emp-hero-avatar" src="<?php echo htmlspecialchars($foto_usuario_url); ?>" alt="">
                                    <?php else: ?>
                                        <div class="dash-emp-hero-avatar is-fallback"><?php echo strtoupper(substr($nome_usuario_completo, 0, 1)); ?></div>
                                    <?php endif; ?>
                                    <div class="min-w-0">
                                        <div class="dash-emp-hero-kicker"><i class="fas fa-crown me-1"></i>Plano Empresarial<?php if ($dias_restantes > 0): ?> · expira em <?php echo (int)$dias_restantes; ?> dias<?php endif; ?></div>
                                        <h2><?php echo htmlspecialchars($dashboardSaudacao); ?></h2>
                                        <p><?php echo htmlspecialchars($dashboardContextoPlano); ?></p>
                                    </div>
                                </div>
                                <div class="dash-emp-hero-actions">
                                    <a href="usuarios.php?edit_me=1" class="btn btn-sm btn-light"><i class="fas fa-user me-1"></i>Perfil</a>
                                    <?php if ($mostrarBotaoUpgradePlano): ?>
                                        <a href="configuracoes.php?secao=plano&rid=<?php echo (int)$rid; ?>" class="btn btn-sm btn-light"><i class="fas fa-crown me-1"></i><?php echo ((int)$dias_restantes <= 0) ? 'Renovar plano' : 'Gerir plano'; ?></a>
                                    <?php endif; ?>
                                    <?php if ($perfil_operacional): ?>
                                        <a href="iniciar_turno.php" class="btn btn-sm btn-light"><i class="fas fa-clock me-1"></i><?php echo $sem_turno_ativo ? 'Turno' : 'Turno'; ?></a>
                                    <?php endif; ?>
                                    <a href="vendas.php" class="btn btn-sm btn-light"><i class="fas fa-cash-register me-1"></i>PDV</a>
                                    <a href="estoque_inteligente.php" class="btn btn-sm btn-light"><i class="fas fa-boxes-stacked me-1"></i>Estoque</a>
                                    <a href="caixa.php" class="btn btn-sm btn-light"><i class="fas fa-money-bill-wave me-1"></i>Caixa</a>
                                </div>
                            </div>
                            <?php if (!empty($empInsightLines)): ?>
                            <div class="dash-emp-insights mb-3">
                                <?php foreach ($empInsightLines as $ins): ?>
                                    <div class="dash-emp-insight tom-<?php echo htmlspecialchars($ins['tom']); ?>">
                                        <div class="insight-txt"><?php echo htmlspecialchars($ins['txt']); ?></div>
                                        <a href="<?php echo htmlspecialchars($ins['href']); ?>"><?php echo htmlspecialchars($ins['cta']); ?> <i class="fas fa-arrow-right"></i></a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>

