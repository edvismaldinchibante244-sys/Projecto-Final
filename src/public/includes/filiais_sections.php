<?php
require_once __DIR__ . '/../app_base.php'; if ($secao === 'inicio'): ?>
    <section class="content-card">
        <div class="content-head">
            <div>
                <h2 class="content-title"><i class="fas fa-crown text-warning"></i>Matriz da rede</h2>
                <p class="content-subtitle">Plano, capacidade e ponto central de acesso às unidades.</p>
            </div>
            <span class="pill <?php echo $podeCriarFilial ? 'pill-success' : 'pill-warning'; ?>">
                <?php echo $podeCriarFilial ? 'Pode criar nova filial' : 'Limite do plano atingido'; ?>
            </span>
        </div>

        <div class="overview-grid">
            <div class="overview-box"><span>Nome da matriz</span><strong><?php echo filiais_h($matriz['nome'] ?? '-'); ?></strong></div>
            <div class="overview-box"><span>Email principal</span><strong><?php echo filiais_h($matriz['email'] ?? '-'); ?></strong></div>
            <div class="overview-box"><span>Plano / capacidade</span><strong><?php echo filiais_h($nomePlano . ' • ' . $limiteTexto); ?></strong></div>
        </div>

        <div class="content-head">
            <div>
                <h3 class="content-title"><i class="fas fa-network-wired text-primary"></i>Rede de Filiais</h3>
                <p class="content-subtitle">Ordenada por vendas pagas dos últimos <?php echo (int)$periodoDias; ?> dias — entre, edite ou priorize unidades com alerta.</p>
            </div>
        </div>

        <?php if (empty($filiais)): ?>
            <div class="empty-state">
                <i class="fas fa-building-circle-xmark"></i>
                <h4>Nenhuma filial cadastrada</h4>
                <p>Clique em <strong>Nova Filial</strong> para abrir a primeira unidade da sua rede.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table-filiais">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Filial</th>
                            <th>Contato</th>
                            <th>Vendas / Equipa</th>
                            <th>Status</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($filiais as $filial):
                            $rank30 = (int)($filial['rank_30d'] ?? 0);
                            $alertasF = (int)($filial['estoque_critico_total'] ?? 0);
                            $semVendasF = ($filial['status_normalizado'] ?? '') === 'ATIVO' && (float)($filial['vendas_30d'] ?? 0) <= 0.0001;
                            $semEquipaF = (int)($filial['funcionarios_total'] ?? 0) <= 0;
                            ?>
                            <tr class="<?php echo ($alertasF > 0 || $semVendasF) ? 'row-attention' : ''; ?>">
                                <td>
                                    <span class="filiais-rank <?php echo $rank30 === 1 ? 'is-top' : ''; ?>">#<?php echo $rank30 > 0 ? $rank30 : '—'; ?></span>
                                </td>
                                <td>
                                    <div class="branch-name"><?php echo filiais_h($filial['nome']); ?></div>
                                    <div class="branch-meta">
                                        <span><i class="fas fa-location-dot me-1"></i><?php echo filiais_h($filial['cidade'] ?: 'Sem cidade'); ?></span>
                                        <?php if ($alertasF > 0): ?>
                                            <span class="meta-warn"><i class="fas fa-triangle-exclamation me-1"></i><?php echo $alertasF; ?> stock</span>
                                        <?php else: ?>
                                            <span><i class="fas fa-box-open me-1"></i>Stock OK</span>
                                        <?php endif; ?>
                                        <?php if ($semEquipaF): ?>
                                            <span class="meta-warn"><i class="fas fa-user-slash me-1"></i>Sem equipa</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div><?php echo filiais_h($filial['email'] ?: '-'); ?></div>
                                    <div class="content-subtitle mb-0"><?php echo filiais_h($filial['telefone'] ?: 'Sem telefone'); ?></div>
                                </td>
                                <td>
                                    <div><strong><?php echo filiais_h(filiais_moeda($filial['vendas_30d'])); ?></strong></div>
                                    <div class="content-subtitle mb-0">
                                        <?php echo (int)$filial['num_vendas_30d']; ?> vendas · <?php echo (int)$filial['funcionarios_total']; ?> na equipa
                                        <?php if ($semVendasF): ?> · <span class="meta-warn-inline">sem vendas em <?php echo (int)$periodoDias; ?> dias</span><?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="status-chip <?php echo $filial['status_normalizado'] === 'ATIVO' ? 'active' : 'inactive'; ?>">
                                        <i class="fas fa-circle"></i><?php echo filiais_h($filial['status_normalizado']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-stack">
                                        <form method="post" action="api/filial_selecionar.php">
                                            <input type="hidden" name="_csrf" value="<?php echo filiais_h($csrfToken); ?>">
                                            <input type="hidden" name="filial_id" value="<?php echo (int)$filial['id']; ?>">
                                            <button type="submit" class="btn-table btn-table-primary" <?php echo $filial['status_normalizado'] === 'ATIVO' ? '' : 'disabled'; ?>>
                                                <i class="fas fa-right-to-bracket"></i> Acessar
                                            </button>
                                        </form>
                                        <button
                                            type="button"
                                            class="btn-table"
                                            data-edit-filial
                                            data-filial-id="<?php echo (int)$filial['id']; ?>"
                                            data-filial-nome="<?php echo filiais_h($filial['nome']); ?>"
                                            data-filial-email="<?php echo filiais_h($filial['email'] ?? ''); ?>"
                                            data-filial-telefone="<?php echo filiais_h($filial['telefone'] ?? ''); ?>"
                                            data-filial-endereco="<?php echo filiais_h($filial['endereco'] ?? ''); ?>"
                                            data-filial-cidade="<?php echo filiais_h($filial['cidade'] ?? ''); ?>"
                                            data-filial-status="<?php echo filiais_h($filial['status_normalizado']); ?>"
                                        >
                                            <i class="fas fa-pen"></i> Editar
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($secao === 'dashboard'): ?>
    <section class="content-card mb-4">
        <div class="content-head">
            <div>
                <h2 class="content-title"><i class="fas fa-chart-line text-primary"></i>Comparativo da rede</h2>
                <p class="content-subtitle">Vendas pagas de todas as unidades, incluindo a matriz, nos últimos <?php echo (int)$periodoDias; ?> dias, comparadas com os <?php echo (int)$periodoDias; ?> dias anteriores.</p>
            </div>
            <div class="filiais-periodo">
                <?php foreach ([7, 30, 90] as $pd): ?>
                    <a class="filiais-chip<?php echo $pd === $periodoDias ? ' is-ativo' : ''; ?>" href="filiais.php?secao=dashboard&amp;periodo=<?php echo $pd; ?>"><?php echo $pd; ?> dias</a>
                <?php endforeach; ?>
                <a class="filiais-chip filiais-chip-export" href="filiais.php?secao=dashboard&amp;periodo=<?php echo (int)$periodoDias; ?>&amp;exportar=csv"><i class="fas fa-file-csv me-1"></i>Exportar CSV</a>
            </div>
        </div>
        <div class="table-wrap">
            <table class="table-filiais filiais-comparativo">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Unidade</th>
                        <th class="text-end">Vendas pagas</th>
                        <th class="text-end">N.º vendas</th>
                        <th class="text-end">Ticket médio</th>
                        <th class="text-end">Vs. período anterior</th>
                        <th>Parte da rede</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $posCmp = 0; foreach ($unidadesRede as $un): $posCmp++; ?>
                        <tr>
                            <td><span class="filiais-rank <?php echo $posCmp === 1 && $un['vendas_30d'] > 0 ? 'is-top' : ''; ?>">#<?php echo $posCmp; ?></span></td>
                            <td class="branch-name">
                                <?php if (!empty($un['is_matriz'])): ?><i class="fas fa-crown text-warning me-1"></i><?php endif; ?>
                                <?php echo filiais_h($un['nome']); ?>
                                <small class="d-block text-muted"><?php echo !empty($un['is_matriz']) ? 'Matriz' : 'Filial'; ?><?php echo !empty($un['cidade']) ? ' · ' . filiais_h($un['cidade']) : ''; ?><?php echo ($un['status_normalizado'] ?? 'ATIVO') !== 'ATIVO' ? ' · inactiva' : ''; ?></small>
                            </td>
                            <td class="text-end"><strong><?php echo filiais_h(filiais_moeda($un['vendas_30d'])); ?></strong></td>
                            <td class="text-end"><?php echo (int)$un['num_vendas_30d']; ?></td>
                            <td class="text-end"><?php echo $un['num_vendas_30d'] > 0 ? filiais_h(filiais_moeda($un['ticket_medio'])) : '—'; ?></td>
                            <td class="text-end">
                                <?php if ($un['variacao_pct'] === null): ?>
                                    <span class="text-muted" title="Sem vendas no período anterior">—</span>
                                <?php else: $vp = (float)$un['variacao_pct']; ?>
                                    <span class="filiais-var <?php echo $vp >= 0 ? 'is-up' : 'is-down'; ?>"><i class="fas fa-arrow-<?php echo $vp >= 0 ? 'up' : 'down'; ?>"></i> <?php echo number_format(abs($vp), 1, ',', '.'); ?>%</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="filiais-parte"><span style="width: <?php echo number_format(min(100, max(0, (float)$un['parte_pct'])), 1, '.', ''); ?>%"></span></div>
                                <small><?php echo number_format((float)$un['parte_pct'], 1, ',', '.'); ?>%</small>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th></th>
                        <th>Total da rede</th>
                        <th class="text-end"><?php echo filiais_h(filiais_moeda($totalVendas30Dias)); ?></th>
                        <th class="text-end"><?php $nvRede = array_sum(array_column($unidadesRede, 'num_vendas_30d')); echo (int)$nvRede; ?></th>
                        <th class="text-end"><?php echo $nvRede > 0 ? filiais_h(filiais_moeda($totalVendas30Dias / $nvRede)) : '—'; ?></th>
                        <th class="text-end"><?php $antRede = array_sum(array_column($unidadesRede, 'vendas_anterior')); if ($antRede > 0): $vr = ($totalVendas30Dias - $antRede) / $antRede * 100; ?><span class="filiais-var <?php echo $vr >= 0 ? 'is-up' : 'is-down'; ?>"><i class="fas fa-arrow-<?php echo $vr >= 0 ? 'up' : 'down'; ?>"></i> <?php echo number_format(abs($vr), 1, ',', '.'); ?>%</span><?php else: ?>—<?php endif; ?></th>
                        <th>100%</th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>

    <section class="content-card">
        <div class="content-head">
            <div>
                <h2 class="content-title"><i class="fas fa-store text-primary"></i>Estado das unidades hoje</h2>
                <p class="content-subtitle">Caixa, equipa em turno, contas abertas e última venda de hoje em cada unidade.</p>
            </div>
            <span class="pill pill-success"><?php echo date('d/m/Y H:i'); ?></span>
        </div>
        <div class="table-wrap">
            <table class="table-filiais">
                <thead>
                    <tr><th>Unidade</th><th>Caixa</th><th class="text-end">Em turno</th><th class="text-end">Contas abertas</th><th class="text-end">Última venda hoje</th><th>Leitura</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($unidadesRede as $un): $hj = $un['hoje'] ?? ['caixa_aberto' => false, 'em_turno' => 0, 'contas_abertas' => 0, 'ultima_venda' => null];
                        if (($un['status_normalizado'] ?? 'ATIVO') !== 'ATIVO') { $leitura = ['Inactiva', 'pill-warning']; }
                        elseif (!$hj['caixa_aberto'] && (int)$hj['em_turno'] === 0) { $leitura = ['Parada', 'pill-danger']; }
                        elseif (!$hj['caixa_aberto']) { $leitura = ['Caixa fechado', 'pill-warning']; }
                        elseif ($hj['ultima_venda'] === null) { $leitura = ['Sem vendas hoje', 'pill-warning']; }
                        else { $leitura = ['A operar', 'pill-success']; }
                    ?>
                        <tr>
                            <td class="branch-name"><?php if (!empty($un['is_matriz'])): ?><i class="fas fa-crown text-warning me-1"></i><?php endif; ?><?php echo filiais_h($un['nome']); ?></td>
                            <td><span class="status-chip <?php echo $hj['caixa_aberto'] ? 'active' : 'inactive'; ?>"><i class="fas fa-circle"></i><?php echo $hj['caixa_aberto'] ? 'Aberto' : 'Fechado'; ?></span></td>
                            <td class="text-end"><?php echo (int)$hj['em_turno']; ?></td>
                            <td class="text-end"><?php echo (int)$hj['contas_abertas']; ?></td>
                            <td class="text-end"><?php echo $hj['ultima_venda'] ? filiais_h($hj['ultima_venda']) : '—'; ?></td>
                            <td><span class="pill <?php echo $leitura[1]; ?>"><?php echo $leitura[0]; ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php if ($secao === 'estoque'): ?>
    <?php if (!empty($temTransferenciaEstoqueFiliais)): ?>
    <section class="content-card mb-4">
        <div class="content-head">
            <div>
                <h2 class="content-title"><i class="fas fa-right-left text-primary"></i>Transferência de estoque</h2>
                <p class="content-subtitle">Move insumos entre o stock central e as filiais da rede.</p>
            </div>
            <span class="pill pill-success">Empresarial</span>
        </div>

        <div id="alertTransferenciaEstoque" class="alert d-none mb-3"></div>

        <form id="formTransferenciaEstoque" class="row g-3">
            <input type="hidden" name="_csrf" value="<?php echo filiais_h($csrfToken); ?>">
            <div class="col-lg-4">
                <label class="form-label" for="trfInsumo">Insumo *</label>
                <select id="trfInsumo" name="insumo_id" class="form-select" required>
                    <option value="">A carregar insumos...</option>
                </select>
            </div>
            <div class="col-md-4 col-lg-2">
                <label class="form-label" for="trfOrigem">Origem *</label>
                <select id="trfOrigem" name="filial_origem_id" class="form-select" required>
                    <?php foreach ($opcoesFiliaisTransferencia as $opt): ?>
                        <option value="<?php echo (int)$opt['id']; ?>"><?php echo filiais_h($opt['nome']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 col-lg-2">
                <label class="form-label" for="trfDestino">Destino *</label>
                <select id="trfDestino" name="filial_destino_id" class="form-select" required>
                    <?php foreach ($opcoesFiliaisTransferencia as $i => $opt): ?>
                        <option value="<?php echo (int)$opt['id']; ?>"<?php echo $i === 1 ? ' selected' : ''; ?>><?php echo filiais_h($opt['nome']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 col-lg-2">
                <label class="form-label" for="trfQtd">Quantidade *</label>
                <input type="number" step="0.001" min="0.001" id="trfQtd" name="quantidade" class="form-control" required placeholder="Quantidade">
            </div>
            <div class="col-md-4 col-lg-2">
                <label class="form-label" for="trfUnidade">Unidade</label>
                <select id="trfUnidade" name="unidade" class="form-select">
                    <option value="kg">kg</option>
                    <option value="g">g</option>
                    <option value="l">l</option>
                    <option value="ml">ml</option>
                    <option value="un" selected>un</option>
                </select>
            </div>
            <div class="col-lg-8">
                <label class="form-label" for="trfMotivo">Motivo</label>
                <input type="text" id="trfMotivo" name="motivo" class="form-control" placeholder="Motivo da transferência">
            </div>
            <div class="col-lg-4 d-flex align-items-end">
                <button type="submit" id="btnTransferirEstoque" class="btn btn-primary w-100">
                    <i class="fas fa-right-left me-1"></i> Transferir agora
                </button>
            </div>
        </form>
        <p class="content-subtitle mt-3 mb-0">Os insumos vêm do Estoque Inteligente da matriz. Cadastre-os lá se a lista estiver vazia.</p>
    </section>

    <section class="content-card mb-4">
        <div class="content-head">
            <div>
                <h2 class="content-title"><i class="fas fa-clock-rotate-left text-primary"></i>Últimas transferências</h2>
                <p class="content-subtitle">As 20 transferências de stock mais recentes entre unidades da rede.</p>
            </div>
            <span class="pill pill-success"><?php echo count($transferenciasRede); ?></span>
        </div>
        <?php if (empty($transferenciasRede)): ?>
            <p class="content-subtitle mb-0">Ainda não há transferências registadas.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table-filiais">
                    <thead><tr><th>Data</th><th>Insumo</th><th class="text-end">Quantidade</th><th>Origem</th><th>Destino</th><th>Responsável</th></tr></thead>
                    <tbody>
                        <?php foreach ($transferenciasRede as $trf): ?>
                            <tr>
                                <td><?php echo filiais_h($trf['quando']); ?></td>
                                <td class="branch-name"><?php echo filiais_h($trf['insumo']); ?></td>
                                <td class="text-end"><?php echo filiais_h(rtrim(rtrim(number_format($trf['quantidade'], 3, ',', '.'), '0'), ',') . ' ' . $trf['unidade']); ?></td>
                                <td><?php echo filiais_h($trf['origem']); ?></td>
                                <td><?php echo filiais_h($trf['destino']); ?></td>
                                <td><?php echo filiais_h($trf['responsavel']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="content-card">
        <div class="content-head">
            <div>
                <h2 class="content-title"><i class="fas fa-boxes-stacked text-primary"></i>Stock crítico por unidade</h2>
                <p class="content-subtitle">Insumos do Estoque Inteligente abaixo do mínimo definido, em cada unidade da rede.</p>
            </div>
            <span class="pill <?php echo $totalAlertasEstoque > 0 ? 'pill-danger' : 'pill-success'; ?>"><?php echo (int)$totalAlertasEstoque; ?> itens críticos</span>
        </div>

        <?php if (empty($filiais)): ?>
            <div class="empty-state"><i class="fas fa-boxes-stacked"></i><h4>Nenhuma filial cadastrada</h4><p>Cadastre uma filial para centralizar os alertas de estoque.</p></div>
        <?php else: ?>
            <?php foreach ($unidadesRede as $filial): ?>
                <div class="branch-block">
                    <div class="branch-block-header">
                        <div>
                            <h3 class="content-title mb-0"><i class="fas <?php echo !empty($filial['is_matriz']) ? 'fa-crown text-warning' : 'fa-building text-primary'; ?>"></i><?php echo filiais_h($filial['nome']); ?></h3>
                            <p class="content-subtitle mb-0"><?php echo (int)$filial['estoque_critico_total']; ?> <?php echo (int)$filial['estoque_critico_total'] === 1 ? 'item' : 'itens'; ?> abaixo do mínimo</p>
                        </div>
                        <span class="pill <?php echo $filial['estoque_critico_total'] > 0 ? 'pill-danger' : 'pill-success'; ?>">
                            <?php echo $filial['estoque_critico_total'] > 0 ? 'Atenção imediata' : 'Stock saudável'; ?>
                        </span>
                    </div>

                    <?php if (!empty($filial['estoque_critico_itens'])): ?>
                        <div class="table-wrap">
                            <table class="table-filiais">
                                <thead><tr><th>Insumo</th><th>Stock actual</th><th>Mínimo</th><th>Estado</th></tr></thead>
                                <tbody>
                                    <?php foreach ($filial['estoque_critico_itens'] as $item): ?>
                                        <tr>
                                            <td class="branch-name"><?php echo filiais_h($item['nome']); ?></td>
                                            <td><?php echo filiais_h(rtrim(rtrim(number_format((float)$item['estoque'], 3, ',', '.'), '0'), ',') . ' ' . ($item['unidade'] ?? '')); ?></td>
                                            <td><?php echo filiais_h(rtrim(rtrim(number_format((float)$item['estoque_minimo'], 3, ',', '.'), '0'), ',') . ' ' . ($item['unidade'] ?? '')); ?></td>
                                            <td><span class="pill pill-danger"><?php echo !empty($item['zerado']) ? 'Esgotado' : 'Abaixo do mínimo'; ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="content-subtitle mb-0">Nenhum insumo em nível crítico nesta unidade.</p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($secao === 'funcionarios'): ?>
    <section class="content-card">
        <div class="content-head">
            <div>
                <h2 class="content-title"><i class="fas fa-users text-primary"></i>Funcionários por unidade</h2>
                <p class="content-subtitle">Perfil da equipa em cada unidade com sinalização de colaboradores ativos e vendas pagas dos últimos <?php echo (int)$periodoDias; ?> dias.</p>
            </div>
            <span class="pill pill-success"><?php echo (int)$totalFuncionarios; ?> colaboradores</span>
        </div>

        <?php if (empty($filiais)): ?>
            <div class="empty-state"><i class="fas fa-users"></i><h4>Nenhuma filial cadastrada</h4><p>Cadastre uma filial para acompanhar a distribuição da equipa.</p></div>
        <?php else: ?>
            <?php foreach ($unidadesRede as $filial): ?>
                <div class="branch-block">
                    <div class="branch-block-header">
                        <div>
                            <h3 class="content-title mb-0"><i class="fas <?php echo !empty($filial['is_matriz']) ? 'fa-crown text-warning' : 'fa-building text-primary'; ?>"></i><?php echo filiais_h($filial['nome']); ?></h3>
                            <p class="content-subtitle mb-0"><?php echo (int)$filial['funcionarios_total']; ?> funcionários registados</p>
                        </div>
                        <span class="pill <?php echo $filial['funcionarios_total'] > 0 ? 'pill-success' : 'pill-warning'; ?>">
                            <?php echo $filial['funcionarios_total'] > 0 ? 'Equipa registada' : 'Sem equipa'; ?>
                        </span>
                    </div>

                    <?php if (!empty($filial['funcionarios_lista'])): ?>
                        <div class="table-wrap">
                            <table class="table-filiais">
                                <thead><tr><th>Nome</th><th>Email</th><th>Perfil</th><th>Vendas pagas</th><th>Valor</th><th>Estado</th></tr></thead>
                                <tbody>
                                    <?php foreach ($filial['funcionarios_lista'] as $funcionario): ?>
                                        <tr>
                                            <td class="branch-name"><?php echo filiais_h($funcionario['nome']); ?></td>
                                            <td><?php echo filiais_h($funcionario['email'] ?: '-'); ?></td>
                                            <td><?php echo filiais_h($funcionario['perfil']); ?></td>
                                            <td><?php echo (int)$funcionario['vendas_total']; ?></td>
                                            <td><?php echo filiais_h(filiais_moeda($funcionario['vendas_valor'] ?? 0)); ?></td>
                                            <td><span class="pill <?php echo $funcionario['ativo'] ? 'pill-success' : 'pill-warning'; ?>"><?php echo $funcionario['ativo'] ? 'Ativo' : 'Inativo'; ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="content-subtitle mb-0">Nenhum funcionário encontrado nesta unidade.</p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>

