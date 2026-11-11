// Painel Super Admin - Compras de planos: lista, comprovativos, aprovacao e rejeicao.
// Parte de admin.php; carregado por ordem com os outros ficheiros de js/admin/ (ver admin.php).

        function carregarCompras() {
            if (!canAccessPlansSection()) {
                return;
            }

            fetch('api/super_admin_compras_listar.php', {
                    credentials: 'same-origin'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        tableState.compras.all = Array.isArray(data.data) ? data.data : [];
                        aplicarFiltroCompras(false);
                    } else {
                        showAlert(data.message, 'danger');
                    }
                })
                .catch(err => {
                    console.error('Erro:', err);
                    showAlert('Erro ao carregar compras', 'danger');
                });
        }

        function obterCompraPorId(compraId) {
            return fetch('api/super_admin_compras_listar.php', {
                    credentials: 'same-origin'
                })
                .then(response => response.json())
                .then(data => {
                    if (!data.success || !Array.isArray(data.data)) {
                        throw new Error(data.message || 'Falha ao consultar compras');
                    }
                    return data.data.find(item => Number(item.id) === Number(compraId)) || null;
                });
        }

        function reconciliarCompraProcessada(compraId, acao, modalAlertId, modalElementId) {
            const acaoLabel = acao === 'rejeitar' ? 'rejeitada' : 'aprovada';
            return obterCompraPorId(compraId)
                .then(compra => {
                    if (compra && normalizePurchaseStatus(compra.status) !== 'AGUARDANDO_APROVACAO') {
                        bootstrap.Modal.getInstance(document.getElementById(modalElementId))?.hide();
                        carregarCompras();
                        showAlert(`A compra #${compraId} já foi ${acaoLabel} no servidor.`, 'info');
                        return true;
                    }
                    return false;
                })
                .catch(err => {
                    console.error('Falha ao reconciliar status da compra:', err);
                    return false;
                });
        }

        function aplicarFiltroCompras(resetPage = true) {
            const query = normalizeText(document.getElementById('filtroCompras').value);
            const planoSelecionado = (document.getElementById('filtroPlanoCompras')?.value || '').toUpperCase();
            const cicloSelecionado = (document.getElementById('filtroCicloCompras')?.value || '').toUpperCase();
            const statusSelecionado = (document.getElementById('filtroStatusCompras')?.value || '').toUpperCase();
            const dataIni = document.getElementById('filtroDataIniCompras')?.value || '';
            const dataFim = document.getElementById('filtroDataFimCompras')?.value || '';
            tableState.compras.filtered = tableState.compras.all.filter(c => {
                const cicloCompra = String(c.ciclo || (String(c.metodo_pagamento || '').includes('-') ? String(c.metodo_pagamento).split('-').pop().trim() : 'MENSAL')).toUpperCase();
                const statusCompra = normalizePurchaseStatus(c.status);
                const planoCompra = String(c.plano_novo || '').toUpperCase();
                const dataRef = c.data_compra || c.criado_em || c.created_at;
                const dataCompra = dataRef ? new Date(dataRef) : null;

                if (planoSelecionado && planoCompra !== planoSelecionado) {
                    return false;
                }

                if (cicloSelecionado && cicloCompra !== cicloSelecionado) {
                    return false;
                }

                if (statusSelecionado && statusCompra !== statusSelecionado) {
                    return false;
                }

                if (dataIni && dataCompra) {
                    const ini = new Date(dataIni + 'T00:00:00');
                    if (dataCompra < ini) {
                        return false;
                    }
                }

                if (dataFim && dataCompra) {
                    const fim = new Date(dataFim + 'T23:59:59');
                    if (dataCompra > fim) {
                        return false;
                    }
                }

                if ((dataIni || dataFim) && !dataCompra) {
                    return false;
                }

                if (!query) {
                    return true;
                }

            const hay = [c.restaurante_nome, c.plano_atual, c.plano_novo, getPurchaseStatusLabel(c.status), c.metodo_pagamento, c.ciclo, c.hash_arquivo].map(normalizeText).join(' ');
            return hay.includes(query);
        });
            if (resetPage) {
                tableState.compras.page = 1;
            }
            atualizarEstatisticasCompras(tableState.compras.filtered);
            renderCompras();
            updateUrlState();
        }

        function renderCompras() {
            const state = tableState.compras;
            const ordenados = applySorting(state.filtered, state);
            // Os que esperam aprovacao aparecem sempre primeiro.
            const ordered = ordenados.filter(c => normalizePurchaseStatus(c.status) === 'AGUARDANDO_APROVACAO')
                .concat(ordenados.filter(c => normalizePurchaseStatus(c.status) !== 'AGUARDANDO_APROVACAO'));
            const paged = paginate(ordered, state.page, state.pageSize);
            state.page = paged.page;
            atualizarTabelaCompras(paged.items);
            document.getElementById('paginacaoComprasInfo').textContent = `${paged.page}/${paged.totalPages}`;
            document.getElementById('btnComprasPrev').disabled = paged.page <= 1;
            document.getElementById('btnComprasNext').disabled = paged.page >= paged.totalPages;
        }

        function planoPt(codigo) {
            const c = String(codigo || '').toUpperCase();
            return { BASICO: 'Básico', PROFISSIONAL: 'Profissional', EMPRESARIAL: 'Empresarial', ENTERPRISE: 'Empresarial' }[c] || (codigo || '—');
        }

        function cicloPt(ciclo) {
            return { MENSAL: 'Mensal', TRIMESTRAL: 'Trimestral', ANUAL: 'Anual', SEMESTRAL: 'Semestral' }[String(ciclo || '').toUpperCase()] || (ciclo || '');
        }

        function metodoPt(metodo) {
            const m = String(metodo || '').toUpperCase().split('-')[0].trim();
            return { MPESA: 'M-Pesa', EMOLA: 'e-Mola', TRANSFERENCIA: 'Transferência', TRANSFERENCIA_BANCARIA: 'Transferência', TRIAL: 'Teste grátis', CARTAO: 'Cartão', NUMERARIO: 'Numerário', DINHEIRO: 'Numerário' }[m] || (m ? m.charAt(0) + m.slice(1).toLowerCase() : 'Não indicado');
        }

        // Atualizar tabela de compras
        function atualizarTabelaCompras(compras) {
            const tbody = document.getElementById('tabelaCompras');

            if (compras.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4 text-muted">Nenhum pagamento encontrado</td></tr>';
                return;
            }

            let html = '';
            compras.forEach(c => {
                const statusNorm = normalizePurchaseStatus(c.status);
                const statusBadge = statusNorm === 'APROVADO' ? '<span class="badge bg-success">Aprovado</span>' :
                    statusNorm === 'AGUARDANDO_APROVACAO' ? '<span class="badge bg-warning text-dark">À espera de aprovação</span>' :
                    '<span class="badge bg-danger">Rejeitado</span>';

                const dataRef = c.data_compra || c.criado_em || c.created_at;
                const dataFormatada = dataRef ? new Date(String(dataRef).replace(' ', 'T')).toLocaleDateString('pt-PT') : '-';
                const ciclo = c.ciclo ? String(c.ciclo) : (String(c.metodo_pagamento || '').includes('-') ? String(c.metodo_pagamento).split('-').pop().trim() : 'MENSAL');
                const hashRaw = String(c.hash_arquivo || '').trim();
                const atual = String(c.plano_atual || '').toUpperCase();
                const novo = String(c.plano_novo || '').toUpperCase();
                const plano = atual && novo && atual !== novo
                    ? `${escapeHtml(planoPt(atual))} <i class="fas fa-arrow-right small text-muted" aria-hidden="true"></i> <strong>${escapeHtml(planoPt(novo))}</strong>`
                    : `<strong>${escapeHtml(planoPt(novo || atual))}</strong> <span class="small text-muted">renovação</span>`;
                const valor = (parseFloat(c.valor) || 0).toLocaleString('pt-PT', { minimumFractionDigits: 0, maximumFractionDigits: 2 }) + ' MZN';
                const comprovativoBtn = c.comprovativo_path ?
                    `<button type="button" class="btn btn-sm btn-outline-primary btn-compra-comprovativo" data-path="${encodeURIComponent(String(c.comprovativo_path))}" title="${escapeHtml(hashRaw ? 'Impressão digital: ' + hashRaw : 'Ver comprovativo')}"><i class="fas fa-file-invoice me-1" aria-hidden="true"></i>Ver</button>` :
                    '<span class="text-muted small">Sem comprovativo</span>';

                let botoes = '<span class="text-muted">—</span>';
                if (statusNorm === 'AGUARDANDO_APROVACAO' && hasPermission('approve_plans')) {
                    const compraId = Number(c.id) || 0;
                    botoes = `<div class="sa-acoes">
                                <button class="btn btn-sm btn-success btn-compra-action" data-action="aprovar" data-id="${compraId}"><i class="fas fa-check me-1" aria-hidden="true"></i>Aprovar</button>
                                <button class="btn btn-sm btn-outline-danger btn-compra-action" data-action="rejeitar" data-id="${compraId}"><i class="fas fa-times me-1" aria-hidden="true"></i>Rejeitar</button>
                              </div>`;
                }

                html += `<tr class="${statusNorm === 'AGUARDANDO_APROVACAO' ? 'table-warning' : ''}">
                    <td class="ps-4 text-muted">#${Number(c.id) || 0}</td>
                    <td class="fw-semibold">${escapeHtml(c.restaurante_nome)}</td>
                    <td>${plano}</td>
                    <td class="text-nowrap">${valor}</td>
                    <td>${escapeHtml(metodoPt(c.metodo_pagamento))}<div class="small text-muted">${escapeHtml(cicloPt(ciclo))}</div></td>
                    <td>${comprovativoBtn}</td>
                    <td>${statusBadge}</td>
                    <td class="text-nowrap">${escapeHtml(dataFormatada)}</td>
                    <td class="text-center">${botoes}</td>
                </tr>`;
            });

            tbody.innerHTML = html;
        }

        // Atualizar estatísticas de compras
        function atualizarEstatisticasCompras(compras) {
            const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = String(v); };
            const total = compras.length;
            const pendentes = compras.filter(c => normalizePurchaseStatus(c.status) === 'AGUARDANDO_APROVACAO').length;
            const aprovados = compras.filter(c => normalizePurchaseStatus(c.status) === 'APROVADO');
            const rejeitados = compras.filter(c => normalizePurchaseStatus(c.status) === 'REJEITADO').length;
            const agora = new Date();
            const aprovadosMes = aprovados.filter(c => {
                const d = new Date(String(c.data_aprovacao || c.data_compra || c.criado_em || '').replace(' ', 'T'));
                return !Number.isNaN(d.getTime()) && d.getMonth() === agora.getMonth() && d.getFullYear() === agora.getFullYear();
            }).length;

            set('totalCompras', total);
            set('totalPendentes', pendentes);
            set('totalAprovados', aprovados.length);
            set('totalAprovadosMes', aprovadosMes);
            set('totalRejeitados', rejeitados);
            document.getElementById('cardPendentes')?.classList.toggle('sa-kpi--atencao', pendentes > 0);

            const divisoresCiclo = { MENSAL: 1, TRIMESTRAL: 3, ANUAL: 12 };
            const mrrHistorico = aprovados.reduce((sum, c) => {
                const valor = parseFloat(c.valor) || 0;
                const divisor = divisoresCiclo[(c.ciclo || 'MENSAL').toUpperCase()] || 1;
                return sum + (valor / divisor);
            }, 0);
            const mrrAtivo = Number(window.receitaRecorrenteAtiva);
            const usarReceitaAtiva = Number.isFinite(mrrAtivo);
            const mrr = usarReceitaAtiva ? mrrAtivo : mrrHistorico;
            const arrAtivo = Number(window.receitaAnualRecorrente);
            const arr = usarReceitaAtiva && Number.isFinite(arrAtivo) ? arrAtivo : (mrr * 12);
            const fmt = (v) => v.toLocaleString('pt-PT', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + ' MZN';
            set('totalMRR', fmt(mrr));
            set('totalARR', fmt(arr));
        }

        function abrirComprovativo(pathEncoded) {
            const path = decodeURIComponent(pathEncoded || '').trim();
            if (!path) {
                showAlert('Comprovativo não disponível.', 'warning');
                return;
            }

            const body = document.getElementById('modalComprovativoBody');
            const lower = path.toLowerCase();
            const fileUrl = path.includes('comprovativo_arquivo.php')
                ? path
                : 'comprovativo_arquivo.php?path=' + encodeURIComponent(path);

            if (lower.endsWith('.pdf')) {
                body.innerHTML = `<iframe src="${escapeHtml(fileUrl)}" style="width:100%;height:70vh;border:0;border-radius:8px;"></iframe>`;
            } else {
                body.innerHTML = `<div class="text-center"><img src="${escapeHtml(fileUrl)}" alt="Comprovativo" style="max-width:100%;max-height:70vh;border-radius:10px;"></div>`;
            }

            new bootstrap.Modal(document.getElementById('modalComprovativo')).show();
        }

        // Aprovar compra - abre modal
        function aprovarCompra(id) {
            if (!hasPermission('approve_plans')) {
                showAlert('Sem permissão para aprovar planos.', 'warning');
                return;
            }

            document.getElementById('compra_id_aprovar').value = id;
            document.getElementById('obs_aprovar').value = '';
            new bootstrap.Modal(document.getElementById('modalAprovarCompra')).show();
        }

        function showModalAlert(id, message, type) {
            const el = document.getElementById(id);
            if (!el) {
                showAlert(message, type || 'danger');
                return;
            }
            el.className = 'alert alert-' + (type || 'danger');
            el.textContent = message;
            el.style.display = 'block';
        }

        function clearModalAlert(id) {
            const el = document.getElementById(id);
            if (!el) return;
            el.style.display = 'none';
            el.textContent = '';
        }

        // Confirmar aprovação com observação
        function confirmarAprovacao() {
            if (!hasPermission('approve_plans')) {
                showModalAlert('alertAprovarModal', 'Sem permissão para aprovar planos.', 'warning');
                return;
            }

            const compra_id = document.getElementById('compra_id_aprovar').value;
            const observacao = document.getElementById('obs_aprovar').value;

            if (!compra_id || Number(compra_id) <= 0) {
                showModalAlert('alertAprovarModal', 'ID da compra inválido. Feche o modal e tente novamente.', 'danger');
                return;
            }

            clearModalAlert('alertAprovarModal');

            // Desabilitar botão durante envio
            const btnConfirmar = document.getElementById('btnConfirmarAprovacao');
            if (btnConfirmar.disabled) {
                return;
            }
            const btnOriginalText = btnConfirmar.innerHTML;
            btnConfirmar.disabled = true;
            btnConfirmar.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Processando...';

            const controller = new AbortController();

            // Timeout de 30 segundos para evitar travamento indefinido
            const timeoutId = setTimeout(() => {
                controller.abort();
                btnConfirmar.disabled = false;
                btnConfirmar.innerHTML = btnOriginalText;
                console.error('Timeout ao aprovar compra (30s)');
                showModalAlert('alertAprovarModal', 'Tempo limite atingido. Verificando status real da compra...', 'warning');

                reconciliarCompraProcessada(compra_id, 'aprovar', 'alertAprovarModal', 'modalAprovarCompra')
                    .then(resolvido => {
                        if (!resolvido) {
                            showModalAlert('alertAprovarModal', 'Timeout: A operação demorou muito. Tente novamente.', 'danger');
                            showAlert('Timeout: A operação demorou muito. Tente novamente.', 'danger');
                        }
                    });
            }, 30000);

            apiFetch('api/super_admin_plano_aprovar.php', {
                    method: 'POST',
                    signal: controller.signal,
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: buildCsrfFormBody({
                        compra_id,
                        acao: 'aprovar',
                        observacao
                    })
                })
                .then(response => {
                    clearTimeout(timeoutId);
                    if (!response.ok) {
                        return response.text().then(text => {
                            console.error('Erro HTTP ' + response.status + ':', text);
                            try {
                                const data = JSON.parse(text);
                                throw new Error(data.message || 'Erro HTTP ' + response.status);
                            } catch (e) {
                                throw new Error('Erro HTTP ' + response.status + ': ' + (text || response.statusText));
                            }
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    btnConfirmar.disabled = false;
                    btnConfirmar.innerHTML = btnOriginalText;

                    if (data.success) {
                        const passwordSetupUrl = data.data && data.data.password_setup_url ?
                            String(data.data.password_setup_url) :
                            '';
                        const passwordSetupEmail = data.data && data.data.password_setup_email ?
                            String(data.data.password_setup_email) :
                            '';

                        if (passwordSetupUrl) {
                            const safePasswordSetupUrl = escapeHtml(passwordSetupUrl);
                            const safePasswordSetupEmail = escapeHtml(passwordSetupEmail || 'Nao informado');
                            const supportMessages = [];
                            if (data.warning) {
                                supportMessages.push(String(data.warning));
                            }
                            const supportMessage = supportMessages.length ?
                                supportMessages.join(' ') :
                                'Guarde este link e partilhe com o administrador caso o email nao chegue.';

                            const html = `
                                <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 pe-4">
                                    <div>
                                        <div>${escapeHtml(data.message || 'Plano aprovado com sucesso!')}</div>
                                        <div class="small mt-2"><strong>Email do administrador:</strong> ${safePasswordSetupEmail}</div>
                                        <div class="small mt-2">${escapeHtml(supportMessage)}</div>
                                    </div>
                                    <div class="d-flex flex-wrap gap-2">
                                        <a href="${safePasswordSetupUrl}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-dark">
                                            Abrir definicao de senha
                                        </a>
                                    </div>
                                </div>
                            `;

                            console.info('Link local de definicao de senha:', passwordSetupUrl);
                            showAlertHtml(html, isLocalEnvironment() ? 'info' : (data.warning ? 'warning' : 'success'), 15000);
                        } else {
                            const detalhesExtras = [];
                            if (data.warning) {
                                detalhesExtras.push(data.warning);
                            }

                            const mensagemFinal = [data.message || 'Plano aprovado com sucesso!'].concat(detalhesExtras).join(' ');
                            showAlert(mensagemFinal, data.warning ? 'warning' : 'success');
                        }
                        bootstrap.Modal.getInstance(document.getElementById('modalAprovarCompra')).hide();
                        carregarCompras();
                    } else {
                        const errorMsg = data.message || 'Falha ao aprovar compra. Tente novamente.';
                        if (errorMsg.toLowerCase().includes('já foi processada')) {
                            reconciliarCompraProcessada(compra_id, 'aprovar', 'alertAprovarModal', 'modalAprovarCompra')
                                .then(resolvido => {
                                    if (!resolvido) {
                                        showModalAlert('alertAprovarModal', errorMsg, 'warning');
                                        showAlert(errorMsg, 'warning');
                                    }
                                });
                        } else {
                            showModalAlert('alertAprovarModal', errorMsg, 'danger');
                            showAlert(errorMsg, 'danger');
                            console.error('Erro na resposta:', data);
                        }
                    }
                })
                .catch(err => {
                    clearTimeout(timeoutId);
                    btnConfirmar.disabled = false;
                    btnConfirmar.innerHTML = btnOriginalText;

                    console.error('Erro ao aprovar compra:', err);
                    const errorMsg = err.name === 'AbortError' ?
                        'Timeout: A operação demorou muito. Tente novamente.' :
                        ('Erro ao aprovar: ' + (err.message || 'Erro desconhecido'));
                    showModalAlert('alertAprovarModal', errorMsg, 'danger');
                    showAlert(errorMsg, 'danger');
                });
        }

        // Rejeitar compra - abre modal
        function rejeitarCompra(id) {
            if (!hasPermission('approve_plans')) {
                showAlert('Sem permissão para rejeitar planos.', 'warning');
                return;
            }

            document.getElementById('compra_id_rejeitar').value = id;
            document.getElementById('obs_rejeitar').value = '';
            clearModalAlert('alertRejeitarModal');
            new bootstrap.Modal(document.getElementById('modalRejeitarCompra')).show();
        }

        // Confirmar rejeição com observação
        function confirmarRejeicao() {
            if (!hasPermission('approve_plans')) {
                showModalAlert('alertRejeitarModal', 'Sem permissão para rejeitar planos.', 'warning');
                return;
            }

            const compra_id = document.getElementById('compra_id_rejeitar').value;
            const observacao = document.getElementById('obs_rejeitar').value;

            if (!compra_id || Number(compra_id) <= 0) {
                showModalAlert('alertRejeitarModal', 'ID da compra inválido. Feche o modal e tente novamente.', 'danger');
                return;
            }

            if (!observacao.trim()) {
                showModalAlert('alertRejeitarModal', 'Informe o motivo da rejeição!', 'warning');
                return;
            }

            clearModalAlert('alertRejeitarModal');

            // Desabilitar botão durante envio
            const btnConfirmar = document.getElementById('btnConfirmarRejeicao');
            if (btnConfirmar.disabled) {
                return;
            }
            const btnOriginalText = btnConfirmar.innerHTML;
            btnConfirmar.disabled = true;
            btnConfirmar.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Processando...';

            const controller = new AbortController();

            // Timeout de 30 segundos para evitar travamento indefinido
            const timeoutId = setTimeout(() => {
                controller.abort();
                btnConfirmar.disabled = false;
                btnConfirmar.innerHTML = btnOriginalText;
                console.error('Timeout ao rejeitar compra (30s)');
                showModalAlert('alertRejeitarModal', 'Tempo limite atingido. Verificando status real da compra...', 'warning');

                reconciliarCompraProcessada(compra_id, 'rejeitar', 'alertRejeitarModal', 'modalRejeitarCompra')
                    .then(resolvido => {
                        if (!resolvido) {
                            showModalAlert('alertRejeitarModal', 'Timeout: A operação demorou muito. Tente novamente.', 'danger');
                            showAlert('Timeout: A operação demorou muito. Tente novamente.', 'danger');
                        }
                    });
            }, 30000);

            apiFetch('api/super_admin_plano_aprovar.php', {
                    method: 'POST',
                    signal: controller.signal,
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: buildCsrfFormBody({
                        compra_id,
                        acao: 'rejeitar',
                        observacao
                    })
                })
                .then(response => {
                    clearTimeout(timeoutId);
                    if (!response.ok) {
                        return response.text().then(text => {
                            console.error('Erro HTTP ' + response.status + ':', text);
                            try {
                                const data = JSON.parse(text);
                                throw new Error(data.message || 'Erro HTTP ' + response.status);
                            } catch (e) {
                                throw new Error('Erro HTTP ' + response.status + ': ' + (text || response.statusText));
                            }
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    btnConfirmar.disabled = false;
                    btnConfirmar.innerHTML = btnOriginalText;

                    if (data.success) {
                        showAlert(data.message || 'Compra rejeitada com sucesso!', 'success');
                        bootstrap.Modal.getInstance(document.getElementById('modalRejeitarCompra')).hide();
                        carregarCompras();
                    } else {
                        const errorMsg = data.message || 'Falha ao rejeitar compra. Tente novamente.';
                        if (errorMsg.toLowerCase().includes('já foi processada')) {
                            reconciliarCompraProcessada(compra_id, 'rejeitar', 'alertRejeitarModal', 'modalRejeitarCompra')
                                .then(resolvido => {
                                    if (!resolvido) {
                                        showModalAlert('alertRejeitarModal', errorMsg, 'warning');
                                        showAlert(errorMsg, 'warning');
                                    }
                                });
                        } else {
                            showModalAlert('alertRejeitarModal', errorMsg, 'danger');
                            showAlert(errorMsg, 'danger');
                            console.error('Erro na resposta:', data);
                        }
                    }
                })
                .catch(err => {
                    clearTimeout(timeoutId);
                    btnConfirmar.disabled = false;
                    btnConfirmar.innerHTML = btnOriginalText;

                    console.error('Erro ao rejeitar compra:', err);
                    const errorMsg = err.name === 'AbortError' ?
                        'Timeout: A operação demorou muito. Tente novamente.' :
                        ('Erro ao rejeitar: ' + (err.message || 'Erro desconhecido'));
                    showModalAlert('alertRejeitarModal', errorMsg, 'danger');
                    showAlert(errorMsg, 'danger');
                });
        }
