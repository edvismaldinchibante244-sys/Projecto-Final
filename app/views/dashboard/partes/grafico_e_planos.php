                    <!-- GRÁFICO E PLANOS -->
                    <?php
                    // Analytics executivo EMP só para ADMIN/dono — gerente fica no salão.
                    $empAnalytics = ($plano_atual === 'EMPRESARIAL' && $isAdminDashboard);
                    $proAnalytics = ($plano_atual === 'PROFISSIONAL' && $isAdminDashboard);
                    $empReceitaIntel = ($empAnalytics && is_array($dashEmpReceita));
                    $empDelta7 = $empReceitaIntel ? (float)$dashEmpReceita['delta7pct'] : 0.0;
                    // No Básico o gráfico continua indisponível, mas o ADMIN vê o resumo do próprio plano.
                    $mostrarBlocoGrafico = ($perfil !== 'GERENTE' && $plano_atual !== 'BASICO')
                        || ($perfil === 'ADMIN' && $plano_atual === 'BASICO');
                    // Cartão "Seu Plano" (recursos/expiração): só ADMIN/dono.
                    $mostrarSeuPlanoLateral = ($perfil === 'ADMIN' && $plano_atual !== 'EMPRESARIAL');
                    $chartColClass = ($empAnalytics || $mostrarSeuPlanoLateral) ? 'col-lg-8' : 'col-lg-12';
                    ?>
                    <?php if ($mostrarBlocoGrafico): ?>
                    <div class="row g-3 mb-3 align-items-stretch <?php echo $empAnalytics ? 'dash-emp-analytics' : ''; ?>">
                        <?php if ($plano_atual !== 'BASICO'): ?>
                            <div class="<?php echo $chartColClass; ?>">
                                <div class="card receita-tendencia-card h-100 <?php echo $empAnalytics ? 'dash-emp-card' : ($proAnalytics ? 'dash-pro-card' : ''); ?>">
                                    <div class="card-header">
                                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                            <div>
                                                <h3 class="card-title mb-1">
                                                    <i class="fas fa-<?php echo $empAnalytics ? 'chart-line' : 'chart-line'; ?>"></i>
                                                    <?php
                                                    if ($isAdminDashboard && $empAnalytics) {
                                                        echo 'Receita da sua casa';
                                                    } elseif ($isAdminDashboard) {
                                                        echo 'Receita e Tendência (14 dias)';
                                                    } elseif ($perfil === 'GERENTE') {
                                                        echo 'Movimento da semana';
                                                    } elseif ($perfil === 'GARCOM') {
                                                        echo 'Pedidos da Semana';
                                                    } else {
                                                        echo 'Vendas da Semana';
                                                    }
                                                    ?>
                                                </h3>
                                                <div class="text-muted" style="font-size: 13px;">
                                                    <?php if ($isAdminDashboard && $empReceitaIntel): ?>
                                                        <?php if ($empDelta7 <= -15): ?>
                                                            Esta semana está mais fraca que a anterior. O gráfico mostra em que dias a casa abrandou.
                                                        <?php elseif ($empDelta7 >= 15): ?>
                                                            Boa fase: os últimos 7 dias vieram mais fortes. Use o pico e o dia forte para manter o ritmo.
                                                        <?php else: ?>
                                                            Últimos 14 dias da unidade — para decidir stock, turnos e o que empurrar no salão.
                                                        <?php endif; ?>
                                                    <?php elseif ($isAdminDashboard && $empAnalytics): ?>
                                                        Últimos 14 dias da unidade — para decidir stock, turnos e o que empurrar no salão.
                                                    <?php elseif ($isAdminDashboard): ?>
                                                        Série diária das vendas pagas · total do período e média nos dias com movimento.
                                                    <?php elseif ($perfil === 'GERENTE'): ?>
                                                        Visão rápida do salão nos últimos dias — para antecipar picos e turnos.
                                                    <?php else: ?>
                                                        Visão rápida do desempenho recente e da tendência do período.
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php if ($isAdminDashboard): ?>
                                                <span class="badge" style="font-size: 12px; background: <?php echo $proAnalytics ? 'rgba(37,99,235,0.12)' : 'rgba(194,65,12,0.12)'; ?>; color: <?php echo $proAnalytics ? '#2563eb' : '#c2410c'; ?>;">
                                                    <i class="fas fa-calendar-week me-1"></i><?php echo $empAnalytics ? 'Últimos 14 dias' : 'Últimos 14 dias'; ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="card-body <?php echo $empAnalytics ? 'dash-emp-chart-body' : ''; ?>" style="padding: 16px 18px 12px;">
                                        <?php if ($isAdminDashboard && $empReceitaIntel): ?>
                                            <div class="dash-emp-metrics mb-3">
                                                <div class="border rounded-3 px-3 summary-metric is-accent">
                                                    <div class="small text-muted">Receita 14 dias</div>
                                                    <div class="fw-bold" style="color:#c2410c;">MZN <?php echo number_format($receita_14_dias_total, 2, ',', '.'); ?></div>
                                                </div>
                                                <div class="border rounded-3 px-3 summary-metric">
                                                    <div class="small text-muted">Últimos 7 vs anteriores</div>
                                                    <div class="fw-bold <?php echo $empDelta7 >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                        <?php echo ($empDelta7 >= 0 ? '+' : ''); ?><?php echo number_format($empDelta7, 1, ',', '.'); ?>%
                                                    </div>
                                                </div>
                                                <div class="border rounded-3 px-3 summary-metric">
                                                    <div class="small text-muted">Ticket médio 14d</div>
                                                    <div class="fw-bold">MZN <?php echo number_format((float)$dashEmpReceita['ticket_14'], 2, ',', '.'); ?></div>
                                                </div>
                                                <div class="border rounded-3 px-3 summary-metric">
                                                    <div class="small text-muted">Dia mais forte</div>
                                                    <div class="fw-bold"><?php echo htmlspecialchars((string)$dashEmpReceita['melhor_dia']); ?></div>
                                                </div>
                                                <div class="border rounded-3 px-3 summary-metric">
                                                    <div class="small text-muted">Projecção da semana</div>
                                                    <div class="fw-bold">MZN <?php echo number_format((float)($dashEmpReceita['projecao_semana'] ?? 0), 0, ',', '.'); ?></div>
                                                </div>
                                                <div class="border rounded-3 px-3 summary-metric">
                                                    <div class="small text-muted">Projecção do mês<?php echo !empty($dashEmpReceita['risco_mes_baixo']) ? ' · risco' : ''; ?></div>
                                                    <div class="fw-bold <?php echo !empty($dashEmpReceita['risco_mes_baixo']) ? 'text-danger' : 'text-success'; ?>">MZN <?php echo number_format((float)$dashEmpReceita['projecao_mes'], 0, ',', '.'); ?></div>
                                                </div>
                                            </div>
                                        <?php elseif ($isAdminDashboard): ?>
                                            <div class="d-flex flex-wrap gap-2 mb-3 <?php echo $proAnalytics ? 'dash-pro-metrics' : ''; ?>">
                                                <div class="border rounded-3 px-3 summary-metric" style="background: linear-gradient(135deg, <?php echo $proAnalytics ? 'rgba(37,99,235,0.12), rgba(14,165,233,0.04)' : 'rgba(234,88,12,0.10), rgba(234,88,12,0.03)'; ?>);">
                                                    <div class="small text-muted">Receita 14 dias</div>
                                                    <div class="fw-bold" style="color:<?php echo $proAnalytics ? '#2563eb' : '#c2410c'; ?>;">MZN <?php echo number_format($receita_14_dias_total, 2, ',', '.'); ?></div>
                                                </div>
                                                <div class="border rounded-3 px-3 summary-metric">
                                                    <div class="small text-muted">Receita do mês</div>
                                                    <div class="fw-bold text-success">MZN <?php echo number_format($receita_mes_atual, 2, ',', '.'); ?></div>
                                                </div>
                                                <div class="border rounded-3 px-3 summary-metric">
                                                    <div class="small text-muted">Crescimento mensal</div>
                                                    <div class="fw-bold <?php echo $crescimento_mensal_pct >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                        <?php echo ($crescimento_mensal_pct >= 0 ? '+' : ''); ?><?php echo number_format($crescimento_mensal_pct, 1, ',', '.'); ?>%
                                                    </div>
                                                </div>
                                                <div class="border rounded-3 px-3 summary-metric">
                                                    <div class="small text-muted">Média / dia com venda</div>
                                                    <div class="fw-bold">MZN <?php echo number_format($media_diaria_14d, 2, ',', '.'); ?></div>
                                                </div>
                                                <div class="border rounded-3 px-3 summary-metric">
                                                    <div class="small text-muted">Pico do período<?php echo $pico_14d_data !== '' ? ' (' . date('d/m', strtotime($pico_14d_data)) . ')' : ''; ?></div>
                                                    <div class="fw-bold">MZN <?php echo number_format($pico_14d, 2, ',', '.'); ?></div>
                                                </div>
                                                <div class="border rounded-3 px-3 summary-metric">
                                                    <div class="small text-muted">Tendência (1.º → hoje)</div>
                                                    <div class="fw-bold <?php echo $tendencia_delta_pct >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                        <?php echo ($tendencia_delta_pct >= 0 ? '+' : ''); ?><?php echo number_format($tendencia_delta_pct, 1, ',', '.'); ?>%
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <?php
                                        // Gráfico SVG nativo (não depende de CDN/Chart.js)
                                        $svgVals = [];
                                        $svgLabels = [];
                                        $svgDow = [];
                                        $nomesDiaCurto = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
                                        foreach (($dados_grafico ?: []) as $dg) {
                                            $svgVals[] = floatval($dg['total'] ?? 0);
                                            $dataDg = (string)($dg['data'] ?? 'now');
                                            $svgLabels[] = date('d/m', strtotime($dataDg));
                                            $svgDow[] = $nomesDiaCurto[(int)date('w', strtotime($dataDg))] ?? '';
                                        }
                                        if (count($svgVals) < 2) {
                                            $svgVals = [0, 0];
                                            $svgLabels = ['-', '-'];
                                            $svgDow = ['', ''];
                                        }
                                        $svgMax = max($svgVals);
                                        if ($svgMax <= 0) {
                                            $svgMax = 1;
                                        }
                                        $empChart = ($plano_atual === 'EMPRESARIAL' && $isAdminDashboard);
                                        $proChart = ($plano_atual === 'PROFISSIONAL' && $isAdminDashboard);
                                        $richChart = ($empChart || $proChart);
                                        $svgW = $empChart ? 760 : ($proChart ? 740 : 700);
                                        $svgH = $empChart ? 300 : ($proChart ? 286 : 260);
                                        $padL = $empChart ? 56 : ($proChart ? 56 : 52);
                                        $padR = $empChart ? 22 : ($proChart ? 22 : 16);
                                        $padT = $empChart ? 28 : ($proChart ? 28 : 18);
                                        $padB = $empChart ? 44 : ($proChart ? 44 : 36);
                                        $plotW = $svgW - $padL - $padR;
                                        $plotH = $svgH - $padT - $padB;
                                        $nPts = count($svgVals);
                                        $svgPoints = [];
                                        for ($si = 0; $si < $nPts; $si++) {
                                            $x = $padL + ($nPts === 1 ? $plotW / 2 : ($si / ($nPts - 1)) * $plotW);
                                            $y = $padT + $plotH - (($svgVals[$si] / $svgMax) * $plotH);
                                            $svgPoints[] = [round($x, 1), round($y, 1), $svgVals[$si], $svgLabels[$si], $svgDow[$si] ?? ''];
                                        }
                                        $linePts = implode(' ', array_map(function ($p) {
                                            return $p[0] . ',' . $p[1];
                                        }, $svgPoints));
                                        $areaPts = $padL . ',' . ($padT + $plotH) . ' ' . $linePts . ' ' . ($padL + $plotW) . ',' . ($padT + $plotH);
                                        $trendUp = $empChart && is_array($dashEmpReceita)
                                            ? ((float)$dashEmpReceita['delta7pct'] >= 0)
                                            : ($svgVals[$nPts - 1] >= $svgVals[0]);
                                        $stroke = $empChart ? '#ea580c' : ($proChart ? '#2563eb' : ($trendUp ? '#16a34a' : '#dc2626'));
                                        $fillGrad = $empChart ? '#ea580c' : ($proChart ? '#2563eb' : ($trendUp ? '#16a34a' : '#dc2626'));
                                        $mediaChart = 0.0;
                                        $diasComVendaChart = 0;
                                        foreach ($svgVals as $vv) {
                                            if ($vv > 0) {
                                                $mediaChart += $vv;
                                                $diasComVendaChart++;
                                            }
                                        }
                                        $mediaChart = $diasComVendaChart > 0 ? ($mediaChart / $diasComVendaChart) : 0.0;
                                        $mediaY = $padT + $plotH - (($mediaChart / $svgMax) * $plotH);
                                        $peakIdx = 0;
                                        $peakVal = -1.0;
                                        foreach ($svgVals as $ix => $vv) {
                                            if ($vv >= $peakVal) {
                                                $peakVal = $vv;
                                                $peakIdx = $ix;
                                            }
                                        }
                                        $midX = ($nPts > 1)
                                            ? ($padL + ((7) / ($nPts - 1)) * $plotW)
                                            : ($padL + $plotW / 2);
                                        $hojeIdx = $nPts - 1;
                                        ?>
                                        <div class="chart-wrapper <?php echo $empChart ? 'dash-emp-chart-wrap' : ($proChart ? 'dash-pro-chart-wrap' : ''); ?>" id="salesChartWrap">
                                            <svg id="salesChartSvg" class="<?php echo $empChart ? 'dash-emp-chart-svg' : ''; ?>" viewBox="0 0 <?php echo $svgW; ?> <?php echo $svgH; ?>" width="100%" height="100%" role="img" aria-label="Gráfico inteligente de receita dos últimos 14 dias" preserveAspectRatio="<?php echo $richChart ? 'xMidYMid meet' : 'none'; ?>">
                                                <defs>
                                                    <linearGradient id="salesAreaGrad" x1="0" y1="0" x2="0" y2="1">
                                                        <stop offset="0%" stop-color="<?php echo $fillGrad; ?>" stop-opacity="<?php echo $empChart ? '0.34' : '0.28'; ?>"/>
                                                        <stop offset="100%" stop-color="<?php echo $fillGrad; ?>" stop-opacity="0.03"/>
                                                    </linearGradient>
                                                    <?php if ($empChart): ?>
                                                    <linearGradient id="empWeekPrev" x1="0" y1="0" x2="1" y2="0">
                                                        <stop offset="0%" stop-color="#94a3b8" stop-opacity="0.10"/>
                                                        <stop offset="100%" stop-color="#94a3b8" stop-opacity="0.04"/>
                                                    </linearGradient>
                                                    <linearGradient id="empWeekCurr" x1="0" y1="0" x2="1" y2="0">
                                                        <stop offset="0%" stop-color="#ea580c" stop-opacity="0.08"/>
                                                        <stop offset="100%" stop-color="#ea580c" stop-opacity="0.16"/>
                                                    </linearGradient>
                                                    <?php endif; ?>
                                                    <?php if ($proChart): ?>
                                                    <linearGradient id="proRecentDays" x1="0" y1="0" x2="1" y2="0">
                                                        <stop offset="0%" stop-color="#dbeafe" stop-opacity="0.20"/>
                                                        <stop offset="100%" stop-color="#bfdbfe" stop-opacity="0.52"/>
                                                    </linearGradient>
                                                    <?php endif; ?>
                                                </defs>
                                                <?php if ($empChart && $nPts >= 14): ?>
                                                    <rect x="<?php echo $padL; ?>" y="<?php echo $padT; ?>" width="<?php echo max(1, $midX - $padL); ?>" height="<?php echo $plotH; ?>" fill="url(#empWeekPrev)" rx="6"/>
                                                    <rect x="<?php echo $midX; ?>" y="<?php echo $padT; ?>" width="<?php echo max(1, ($padL + $plotW) - $midX); ?>" height="<?php echo $plotH; ?>" fill="url(#empWeekCurr)" rx="6"/>
                                                    <line x1="<?php echo $midX; ?>" y1="<?php echo $padT; ?>" x2="<?php echo $midX; ?>" y2="<?php echo $padT + $plotH; ?>" stroke="#fdba74" stroke-width="1.5" stroke-dasharray="4 4"/>
                                                    <text x="<?php echo ($padL + $midX) / 2; ?>" y="<?php echo $padT - 10; ?>" text-anchor="middle" fill="#94a3b8" font-size="10" font-weight="700" font-family="Outfit, sans-serif">7 dias anteriores</text>
                                                    <text x="<?php echo ($midX + $padL + $plotW) / 2; ?>" y="<?php echo $padT - 10; ?>" text-anchor="middle" fill="#c2410c" font-size="10" font-weight="700" font-family="Outfit, sans-serif">Últimos 7 dias</text>
                                                <?php endif; ?>
                                                <?php if ($proChart && $nPts >= 14): ?>
                                                    <rect x="<?php echo $midX; ?>" y="<?php echo $padT; ?>" width="<?php echo max(1, ($padL + $plotW) - $midX); ?>" height="<?php echo $plotH; ?>" fill="url(#proRecentDays)" rx="8"/>
                                                    <line x1="<?php echo $midX; ?>" y1="<?php echo $padT; ?>" x2="<?php echo $midX; ?>" y2="<?php echo $padT + $plotH; ?>" stroke="#93c5fd" stroke-width="1.25" stroke-dasharray="4 4"/>
                                                    <text x="<?php echo ($midX + $padL + $plotW) / 2; ?>" y="<?php echo $padT - 10; ?>" text-anchor="middle" fill="#2563eb" font-size="10" font-weight="700" font-family="Outfit, sans-serif">Últimos 7 dias</text>
                                                <?php endif; ?>
                                                <?php for ($gi = 0; $gi <= 4; $gi++):
                                                    $gy = $padT + ($plotH * $gi / 4);
                                                    $gVal = $svgMax * (1 - $gi / 4);
                                                ?>
                                                    <line x1="<?php echo $padL; ?>" y1="<?php echo $gy; ?>" x2="<?php echo $padL + $plotW; ?>" y2="<?php echo $gy; ?>" stroke="rgba(0,0,0,0.06)" stroke-width="1"/>
                                                    <text x="<?php echo $padL - 8; ?>" y="<?php echo $gy + 4; ?>" text-anchor="end" fill="#94a3b8" font-size="11" font-family="Outfit, sans-serif"><?php echo number_format($gVal, 0, ',', '.'); ?></text>
                                                <?php endfor; ?>
                                                <?php if ($empChart && $mediaChart > 0): ?>
                                                    <line x1="<?php echo $padL; ?>" y1="<?php echo round($mediaY, 1); ?>" x2="<?php echo $padL + $plotW; ?>" y2="<?php echo round($mediaY, 1); ?>" stroke="#0ea5e9" stroke-width="1.75" stroke-dasharray="6 4"/>
                                                    <rect x="<?php echo $padL + $plotW - 86; ?>" y="<?php echo max($padT, round($mediaY, 1) - 16); ?>" width="84" height="16" rx="4" fill="#e0f2fe"/>
                                                    <text x="<?php echo $padL + $plotW - 44; ?>" y="<?php echo max($padT + 11, round($mediaY, 1) - 4); ?>" text-anchor="middle" fill="#0369a1" font-size="9" font-weight="700" font-family="Outfit, sans-serif">Média MZN <?php echo number_format($mediaChart, 0, ',', '.'); ?></text>
                                                <?php endif; ?>
                                                <?php if ($proChart && $mediaChart > 0): ?>
                                                    <line x1="<?php echo $padL; ?>" y1="<?php echo round($mediaY, 1); ?>" x2="<?php echo $padL + $plotW; ?>" y2="<?php echo round($mediaY, 1); ?>" stroke="#0ea5e9" stroke-width="1.5" stroke-dasharray="6 4"/>
                                                    <text x="<?php echo $padL + $plotW - 4; ?>" y="<?php echo max($padT + 10, round($mediaY, 1) - 5); ?>" text-anchor="end" fill="#0284c7" font-size="9" font-weight="700" font-family="Outfit, sans-serif">Média</text>
                                                <?php endif; ?>
                                                <polygon points="<?php echo htmlspecialchars($areaPts); ?>" fill="url(#salesAreaGrad)"/>
                                                <polyline points="<?php echo htmlspecialchars($linePts); ?>" fill="none" stroke="<?php echo $stroke; ?>" stroke-width="<?php echo $empChart ? '3.5' : ($proChart ? '3.25' : '3'); ?>" stroke-linejoin="round" stroke-linecap="round"/>
                                                <?php foreach ($svgPoints as $pi => $pt):
                                                    $isPeak = ($pi === $peakIdx && $pt[2] > 0);
                                                    $isToday = ($pi === $hojeIdx);
                                                    $isZero = ($pt[2] <= 0);
                                                    $todayDot = $empChart ? '#c2410c' : '#2563eb';
                                                    $dot = $isPeak ? '#f59e0b' : ($isToday ? $todayDot : ($isZero && $richChart ? '#cbd5e1' : $stroke));
                                                    $r = $isPeak ? 6.2 : ($isToday ? 5.2 : ($isZero && $richChart ? 2.8 : 3.5));
                                                ?>
                                                    <circle cx="<?php echo $pt[0]; ?>" cy="<?php echo $pt[1]; ?>" r="<?php echo $r; ?>" fill="<?php echo $dot; ?>" stroke="#fff" stroke-width="2">
                                                        <title><?php echo htmlspecialchars(($pt[4] ? $pt[4] . ' ' : '') . $pt[3] . ': MZN ' . number_format($pt[2], 2, ',', '.')); ?></title>
                                                    </circle>
                                                    <?php if ($empChart && $isPeak): ?>
                                                        <?php
                                                        $labelX = min(max($pt[0], $padL + 36), $padL + $plotW - 36);
                                                        $labelY = max($padT + 14, $pt[1] - 14);
                                                        ?>
                                                        <rect x="<?php echo $labelX - 34; ?>" y="<?php echo $labelY - 12; ?>" width="68" height="16" rx="4" fill="#fffbeb" stroke="#fbbf24"/>
                                                        <text x="<?php echo $labelX; ?>" y="<?php echo $labelY; ?>" text-anchor="middle" fill="#b45309" font-size="9" font-weight="800" font-family="Outfit, sans-serif">PICO <?php echo htmlspecialchars($pt[3]); ?></text>
                                                    <?php endif; ?>
                                                    <?php if ($empChart && $isToday && !$isPeak): ?>
                                                        <text x="<?php echo $pt[0]; ?>" y="<?php echo max($padT + 12, $pt[1] - 12); ?>" text-anchor="middle" fill="#c2410c" font-size="9" font-weight="800" font-family="Outfit, sans-serif">HOJE</text>
                                                    <?php endif; ?>
                                                    <?php if ($proChart && $isToday && !$isPeak): ?>
                                                        <text x="<?php echo $pt[0]; ?>" y="<?php echo max($padT + 12, $pt[1] - 12); ?>" text-anchor="middle" fill="#2563eb" font-size="9" font-weight="800" font-family="Outfit, sans-serif">HOJE</text>
                                                    <?php endif; ?>
                                                    <?php if ($pi % 2 === 0 || $pi === $nPts - 1 || ($richChart && $isPeak)): ?>
                                                        <text x="<?php echo $pt[0]; ?>" y="<?php echo $svgH - ($richChart ? 18 : 10); ?>" text-anchor="middle" fill="#94a3b8" font-size="10" font-family="Outfit, sans-serif"><?php echo htmlspecialchars($pt[3]); ?></text>
                                                        <?php if ($empChart): ?>
                                                            <text x="<?php echo $pt[0]; ?>" y="<?php echo $svgH - 6; ?>" text-anchor="middle" fill="#cbd5e1" font-size="8" font-family="Outfit, sans-serif"><?php echo htmlspecialchars($pt[4]); ?></text>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </svg>
                                        </div>
                                        <?php if ($empChart): ?>
                                            <div class="dash-emp-chart-legend">
                                                <span><i class="lg-line"></i> Receita diária</span>
                                                <span><i class="lg-avg"></i> Média dos dias com venda</span>
                                                <span><i class="lg-peak"></i> Pico</span>
                                                <span><i class="lg-zone"></i> Últimos 7 dias</span>
                                                <?php if (is_array($dashEmpReceita)): ?>
                                                    <strong class="<?php echo ((float)$dashEmpReceita['delta7pct'] >= 0) ? 'is-up' : 'is-down'; ?>">
                                                        <?php echo ((float)$dashEmpReceita['delta7pct'] >= 0) ? '▲' : '▼'; ?>
                                                        <?php echo (((float)$dashEmpReceita['delta7pct'] >= 0) ? '+' : ''); ?><?php echo number_format((float)$dashEmpReceita['delta7pct'], 1, ',', '.'); ?>% vs semana anterior
                                                    </strong>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($proChart): ?>
                                            <div class="dash-pro-chart-legend">
                                                <span><i class="lg-line"></i> Receita diária</span>
                                                <span><i class="lg-avg"></i> Média dos dias com venda</span>
                                                <span><i class="lg-peak"></i> Pico do período</span>
                                                <span>Área azul: últimos 7 dias</span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($isAdminDashboard && $receita_14_dias_total <= 0): ?>
                                            <div class="text-muted small mt-2">Ainda sem vendas pagas nestes 14 dias. Quando houver pagamentos, a curva aparece aqui.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if ($empAnalytics): ?>
                        <div class="col-lg-4">
                            <div class="card dash-emp-card dash-emp-intel-panel h-100">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="fas fa-comments"></i> Notas para a equipa</h3>
                                </div>
                                <div class="card-body dash-emp-intel-body">
                                    <?php if ($empReceitaIntel): ?>
                                        <div class="dash-emp-intel-kpis">
                                            <div>
                                                <span>Esta semana</span>
                                                <strong>MZN <?php echo number_format((float)$dashEmpReceita['ult7'], 0, ',', '.'); ?></strong>
                                            </div>
                                            <div>
                                                <span>Semana passada</span>
                                                <strong>MZN <?php echo number_format((float)$dashEmpReceita['ant7'], 0, ',', '.'); ?></strong>
                                            </div>
                                            <div>
                                                <span>Dias com venda</span>
                                                <strong><?php echo (int)$dashEmpReceita['dias_activos']; ?> de 14</strong>
                                            </div>
                                            <div>
                                                <span>Como mais pagam</span>
                                                <strong><?php echo htmlspecialchars((string)$dashEmpReceita['pagamento_top']); ?></strong>
                                            </div>
                                        </div>
                                        <ul class="dash-emp-intel-list">
                                            <?php foreach (($dashEmpReceita['insights'] ?? []) as $insR): ?>
                                                <li class="tom-<?php echo htmlspecialchars((string)($insR['tom'] ?? 'info')); ?>">
                                                    <i class="fas fa-<?php echo ($insR['tom'] ?? '') === 'ok' ? 'check-circle' : (($insR['tom'] ?? '') === 'warn' ? 'exclamation-triangle' : 'info-circle'); ?>"></i>
                                                    <div>
                                                        <?php if (!empty($insR['titulo'])): ?>
                                                            <strong><?php echo htmlspecialchars((string)$insR['titulo']); ?></strong>
                                                        <?php endif; ?>
                                                        <span><?php echo htmlspecialchars((string)($insR['txt'] ?? '')); ?></span>
                                                    </div>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        <div class="text-muted small">Quando houver vendas pagas nestes 14 dias, aparecem aqui notas práticas para o salão e o stock.</div>
                                    <?php endif; ?>
                                    <div class="dash-emp-plan-grid">
                                        <a href="relatorios.php"><i class="fas fa-chart-pie"></i> Relatórios</a>
                                        <a href="vendas.php"><i class="fas fa-receipt"></i> Vendas</a>
                                        <a href="estoque_inteligente.php"><i class="fas fa-boxes-stacked"></i> Estoque</a>
                                        <a href="filiais.php"><i class="fas fa-sitemap"></i> Filiais</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php elseif ($mostrarSeuPlanoLateral): ?>
                        <div class="<?php echo ($plano_atual === 'BASICO') ? 'col-lg-12' : 'col-lg-4'; ?>">
                            <div class="card">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="fas fa-crown"></i> Seu Plano</h3>
                                    <?php if ($mostrarBotaoUpgradePlano): ?>
                                        <a href="configuracoes.php?secao=plano&rid=<?php echo intval($rid); ?>" class="btn btn-primary btn-sm"><?php echo ((int)$dias_restantes <= 0) ? 'Renovar' : 'Upgrade'; ?></a>
                                    <?php endif; ?>
                                </div>
                                <div class="card-body">
                                    <div class="plan-card mb-3">
                                        <div class="plan-icon" style="background: linear-gradient(135deg, <?php echo $plano_atual === 'BASICO' ? '#475569, #334155' : '#ea580c, #c2410c'; ?>); color: white;">
                                            <i class="fas fa-<?php echo $plano_atual === 'PROFISSIONAL' ? 'star' : ($plano_atual === 'BASICO' ? 'store' : 'user'); ?>"></i>
                                        </div>
                                        <h5 class="mb-1">Plano <?php echo $plano_atual; ?></h5>
                                        <p class="text-muted mb-1" style="font-size: 13px;">
                                            <span class="text-success fw-bold"><i class="fas fa-check-circle me-1"></i>ATIVO</span>
                                        </p>
                                        <div class="d-flex flex-wrap justify-content-center gap-2 small text-muted">
                                            <span><i class="far fa-calendar-alt me-1"></i>Validade: <?php echo date('d/m/Y', strtotime((string)$data_fim_plano)); ?></span>
                                            <span><i class="fas fa-hourglass-half me-1"></i><?php echo max(0, (int)$dias_restantes); ?> dias restantes</span>
                                        </div>
                                    </div>
                                    <h6 class="mb-3" style="font-size: 13px; color: var(--text-light);">Recursos do Plano</h6>
                                    <ul class="list-unstyled" style="font-size: 13px;">
                                        <?php foreach ($recursos_plano_atual as $recurso): ?>
                                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i><?php echo htmlspecialchars($recurso); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

