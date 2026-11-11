                    <?php if (!empty($empAnalytics)): ?>
                    <div class="row g-3 mb-3 dash-emp-top-row align-items-stretch">
                        <div class="col-lg-5 col-xl-4">
                            <div class="card dash-emp-card dash-emp-top-produtos dash-emp-intel-card h-100">
                                <div class="card-header dash-emp-intel-head">
                                    <div>
                                        <div class="dash-emp-intel-kicker">Comando do cardápio</div>
                                        <h3 class="card-title mb-0"><i class="fas fa-trophy"></i> Top Produtos</h3>
                                    </div>
                                    <span class="dash-emp-badge">30 dias</span>
                                </div>
                                <div class="card-body py-2 px-3">
                                    <?php if (!empty($dashEmpTopProdutos)): ?>
                                        <div class="dash-emp-intel-strip">
                                            <div>
                                                <span>Receita top 5</span>
                                                <strong><?php echo number_format((float)$dashEmpTopMeta['receita_top'], 0, ',', '.'); ?> MZN</strong>
                                            </div>
                                            <div>
                                                <span>Peso no total</span>
                                                <strong><?php echo number_format((float)$dashEmpTopMeta['share_top'], 0, ',', '.'); ?>%</strong>
                                            </div>
                                            <div>
                                                <span>Top 3</span>
                                                <strong><?php echo number_format((float)$dashEmpTopMeta['share_top3'], 0, ',', '.'); ?>%</strong>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (empty($dashEmpTopProdutos)): ?>
                                        <div class="dash-emp-top-empty text-muted small py-3">
                                            Ainda sem ranking. Com vendas pagas, os mais vendidos aparecem aqui.
                                        </div>
                                    <?php else: ?>
                                        <?php
                                        $maxShareTop = 0.0;
                                        foreach ($dashEmpTopProdutos as $tpShare) {
                                            $maxShareTop = max($maxShareTop, (float)($tpShare['share_pct'] ?? 0));
                                        }
                                        if ($maxShareTop <= 0) {
                                            $maxShareTop = 1.0;
                                        }
                                        ?>
                                        <ol class="dash-emp-top-list">
                                            <?php foreach ($dashEmpTopProdutos as $idxTop => $tpDash):
                                                $tpNome = trim((string)($tpDash['nome'] ?? 'Produto'));
                                                $tpQtd = (int)round((float)($tpDash['qtd'] ?? 0));
                                                $tpReceita = (float)($tpDash['receita'] ?? 0);
                                                $tpShare = (float)($tpDash['share_pct'] ?? 0);
                                                $tpDelta = $tpDash['delta_pct'] ?? null;
                                                $tpBar = max(8, min(100, (int)round(($tpShare / $maxShareTop) * 100)));
                                                $tpImg = trim((string)($tpDash['imagem'] ?? ''));
                                                $tpImg = str_replace('\\', '/', $tpImg);
                                                $tpImg = preg_replace('#^(\./)+#', '', $tpImg);
                                                $tpImg = str_replace('src/public/', '', $tpImg);
                                                $tpImg = ltrim((string)$tpImg, '/');
                                                if ($tpImg !== '' && strpos($tpImg, 'images/') !== 0 && preg_match('/\.(png|jpe?g|webp|gif)$/i', $tpImg)) {
                                                    $tpImg = 'images/' . $tpImg;
                                                }
                                                $tpImgOk = ($tpImg !== '' && is_file(DASHBOARD_PUBLIC_DIR . '/' . $tpImg));
                                                $tpInicial = function_exists('mb_substr')
                                                    ? mb_strtoupper(mb_substr($tpNome, 0, 1, 'UTF-8'), 'UTF-8')
                                                    : strtoupper(substr($tpNome, 0, 1));
                                            ?>
                                                <li class="dash-emp-top-item">
                                                    <span class="dash-emp-top-rank" aria-hidden="true"><?php echo (int)$idxTop + 1; ?></span>
                                                    <?php if ($tpImgOk): ?>
                                                        <img class="dash-emp-top-thumb" src="<?php echo htmlspecialchars($tpImg, ENT_QUOTES, 'UTF-8'); ?>" alt="" loading="lazy" decoding="async" width="44" height="44">
                                                    <?php else: ?>
                                                        <span class="dash-emp-top-thumb is-fallback" aria-hidden="true"><?php echo htmlspecialchars($tpInicial, ENT_QUOTES, 'UTF-8'); ?></span>
                                                    <?php endif; ?>
                                                    <div class="dash-emp-top-meta">
                                                        <strong class="dash-emp-top-name"><?php echo htmlspecialchars($tpNome, ENT_QUOTES, 'UTF-8'); ?></strong>
                                                        <span class="dash-emp-top-qtd">
                                                            <?php echo number_format($tpQtd, 0, ',', '.'); ?> venda<?php echo $tpQtd === 1 ? '' : 's'; ?>
                                                            · <?php echo number_format($tpShare, 0, ',', '.'); ?>% da receita
                                                            <?php if ($tpDelta !== null): ?>
                                                                <em class="dash-emp-top-delta <?php echo ((float)$tpDelta >= 0) ? 'is-up' : 'is-down'; ?>">
                                                                    <?php echo ((float)$tpDelta >= 0) ? '▲' : '▼'; ?>
                                                                    <?php echo number_format(abs((float)$tpDelta), 0, ',', '.'); ?>%
                                                                </em>
                                                            <?php endif; ?>
                                                        </span>
                                                        <span class="dash-emp-top-bar" aria-hidden="true"><i style="width:<?php echo $tpBar; ?>%"></i></span>
                                                    </div>
                                                    <div class="dash-emp-top-receita"><?php echo number_format($tpReceita, 0, ',', '.'); ?> MZN</div>
                                                </li>
                                            <?php endforeach; ?>
                                        </ol>
                                        <?php if (!empty($dashEmpTopMeta['insight'])): ?>
                                            <div class="dash-emp-intel-note"><?php echo htmlspecialchars((string)$dashEmpTopMeta['insight'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="card-footer dash-emp-top-footer">
                                    <a href="relatorios.php?tipo=produtos">Ver relatório de produtos <i class="fas fa-arrow-right ms-1"></i></a>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-7 col-xl-8">
                            <div class="card dash-emp-card dash-emp-oportunidades dash-emp-intel-card h-100">
                                <div class="card-header dash-emp-intel-head">
                                    <div>
                                        <div class="dash-emp-intel-kicker">O que fazer hoje</div>
                                        <h3 class="card-title mb-0"><i class="fas fa-bullseye"></i> Alertas do cardápio</h3>
                                    </div>
                                    <span class="dash-emp-badge">Prioridade</span>
                                </div>
                                <div class="card-body py-2 px-3">
                                    <div class="dash-emp-intel-strip">
                                        <div>
                                            <span>Quedas</span>
                                            <strong><?php echo (int)$dashEmpOppMeta['cair']; ?></strong>
                                        </div>
                                        <div>
                                            <span>Ritmo baixo</span>
                                            <strong><?php echo (int)$dashEmpOppMeta['fraco']; ?></strong>
                                        </div>
                                        <div>
                                            <span>Sem movimento</span>
                                            <strong><?php echo (int)$dashEmpOppMeta['parado']; ?></strong>
                                        </div>
                                    </div>
                                    <?php if (empty($dashEmpOportunidades)): ?>
                                        <div class="dash-emp-opp-ok">
                                            <i class="fas fa-check-circle" aria-hidden="true"></i>
                                            <div>
                                                <strong>Sem alertas fora do top</strong>
                                                <span><?php echo htmlspecialchars((string)($dashEmpOppMeta['insight'] ?: 'Mantenha o ritmo dos líderes.'), ENT_QUOTES, 'UTF-8'); ?></span>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <ul class="dash-emp-opp-list">
                                            <?php foreach ($dashEmpOportunidades as $opDash):
                                                $opNome = trim((string)($opDash['nome'] ?? 'Produto'));
                                                $opTipo = (string)($opDash['tipo'] ?? 'fraco');
                                                $opEvid = trim((string)($opDash['evidencia'] ?? ''));
                                                $opAcao = trim((string)($opDash['acao'] ?? ''));
                                                $opLabel = trim((string)($opDash['label'] ?? ''));
                                                if ($opLabel === '') {
                                                    $opLabel = $opTipo === 'cair' ? 'Queda' : ($opTipo === 'parado' ? 'Sem movimento' : 'Ritmo baixo');
                                                }
                                                $opTom = $opTipo === 'cair' ? 'warn' : ($opTipo === 'parado' ? 'muted' : 'info');
                                                $opIco = $opTipo === 'cair' ? 'fa-arrow-trend-down' : ($opTipo === 'parado' ? 'fa-ban' : 'fa-bolt');
                                                $opPot = (float)($opDash['potencial'] ?? 0);
                                                $opImg = trim((string)($opDash['imagem'] ?? ''));
                                                $opImg = str_replace('\\', '/', $opImg);
                                                $opImg = preg_replace('#^(\./)+#', '', $opImg);
                                                $opImg = str_replace('src/public/', '', $opImg);
                                                $opImg = ltrim((string)$opImg, '/');
                                                if ($opImg !== '' && strpos($opImg, 'images/') !== 0 && preg_match('/\.(png|jpe?g|webp|gif)$/i', $opImg)) {
                                                    $opImg = 'images/' . $opImg;
                                                }
                                                $opImgOk = ($opImg !== '' && is_file(DASHBOARD_PUBLIC_DIR . '/' . $opImg));
                                                $opInicial = function_exists('mb_substr')
                                                    ? mb_strtoupper(mb_substr($opNome, 0, 1, 'UTF-8'), 'UTF-8')
                                                    : strtoupper(substr($opNome, 0, 1));
                                            ?>
                                                <li class="dash-emp-opp-item tom-<?php echo htmlspecialchars($opTom, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <span class="dash-emp-opp-ico" aria-hidden="true"><i class="fas <?php echo $opIco; ?>"></i></span>
                                                    <?php if ($opImgOk): ?>
                                                        <img class="dash-emp-top-thumb" src="<?php echo htmlspecialchars($opImg, ENT_QUOTES, 'UTF-8'); ?>" alt="" loading="lazy" decoding="async" width="44" height="44">
                                                    <?php else: ?>
                                                        <span class="dash-emp-top-thumb is-fallback" aria-hidden="true"><?php echo htmlspecialchars($opInicial, ENT_QUOTES, 'UTF-8'); ?></span>
                                                    <?php endif; ?>
                                                    <div class="dash-emp-opp-meta">
                                                        <div class="dash-emp-opp-head">
                                                            <strong><?php echo htmlspecialchars($opNome, ENT_QUOTES, 'UTF-8'); ?></strong>
                                                            <em class="dash-emp-opp-tag"><?php echo htmlspecialchars($opLabel, ENT_QUOTES, 'UTF-8'); ?></em>
                                                        </div>
                                                        <?php if ($opEvid !== ''): ?>
                                                            <span class="dash-emp-opp-fact"><?php echo htmlspecialchars($opEvid, ENT_QUOTES, 'UTF-8'); ?></span>
                                                        <?php endif; ?>
                                                        <span class="dash-emp-opp-action"><?php echo htmlspecialchars($opAcao, ENT_QUOTES, 'UTF-8'); ?></span>
                                                        <?php if ($opPot > 0 && $opTipo === 'parado'): ?>
                                                            <span class="dash-emp-opp-pot">Potencial 4 vendas: <?php echo number_format($opPot, 0, ',', '.'); ?> MZN</span>
                                                        <?php elseif ($opTipo === 'cair' && (float)($opDash['perda'] ?? 0) > 0): ?>
                                                            <span class="dash-emp-opp-pot is-loss">Perda recente: <?php echo number_format((float)$opDash['perda'], 0, ',', '.'); ?> MZN</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <a class="dash-emp-opp-cta" href="produtos.php">Agir</a>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                                <div class="card-footer dash-emp-top-footer">
                                    <a href="produtos.php">Abrir cardápio <i class="fas fa-arrow-right ms-1"></i></a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

