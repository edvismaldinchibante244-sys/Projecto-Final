// Painel Super Admin - Restaurantes: lista, filtros, paginacao, detalhes, edicao, estado e eliminacao.
// Parte de admin.php; carregado por ordem com os outros ficheiros de js/admin/ (ver admin.php).

        function mudarPagina(tipo, delta) {
            const state = tableState[tipo];
            if (!state) {
                return;
            }

            const maxPage = Math.max(1, Math.ceil(state.filtered.length / state.pageSize));
            state.page = Math.min(maxPage, Math.max(1, state.page + delta));
            if (tipo === 'restaurantes') {
                renderRestaurantes();
            } else if (tipo === 'usuarios') {
                renderUsuarios();
            } else if (tipo === 'compras') {
                renderCompras();
            }
            updateUrlState();
        }

        // Carregar estatísticas gerais
        function carregarEstatisticasGerais() {
            if (!hasPermission('view_dashboard')) {
                return;
            }

            fetch('api/super_admin_estatisticas.php', {
                    credentials: 'same-origin'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        (document.getElementById('statTotalRestaurantes') || {}).textContent = data.data.total_restaurantes;
                        (document.getElementById('statRestaurantesAtivos') || {}).textContent = data.data.restaurantes_ativos;
                        (document.getElementById('statAssinaturasExpirando') || {}).textContent = data.data.assinaturas_expirando;
                        // Fonte única de receita recorrente: a mesma usada no painel executivo.
                        window.receitaRecorrenteAtiva = Number(data.data.receita_mensal || 0);
                        window.receitaAnualRecorrente = Number(data.data.receita_anual_projetada || (window.receitaRecorrenteAtiva * 12));
                        if (tableState.compras && tableState.compras.all.length > 0) {
                            atualizarEstatisticasCompras(tableState.compras.filtered);
                        }
                    }
                })
                .catch(err => console.error('Erro ao carregar estatísticas:', err));
        }

        // Função para acessar dashboard do restaurante selecionado
        function acessarDashboard() {
            if (!hasPermission('view_dashboard')) {
                showAlert('Sem permissão para acessar o dashboard.', 'warning');
                return;
            }

            const select = document.getElementById('restauranteSelecionado');
            const restauranteId = select.value;

            if (!restauranteId) {
                showAlert('Selecione um restaurante primeiro!', 'warning');
                return;
            }

            apiFetch('api/selecionar_restaurante.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'restaurante_id=' + restauranteId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        window.location.href = 'dashboard.php';
                    } else {
                        showAlert(data.message, 'danger');
                    }
                })
                .catch(err => {
                    showAlert('Erro: ' + err.message, 'danger');
                });
        }

        // Função para popular seletor de restaurantes
        function popularSeletor(restaurantes) {
            const select = document.getElementById('restauranteSelecionado');
            let html = '<option value="">Escolher um restaurante…</option>';
            restaurantes.forEach(r => {
                html += `<option value="${Number(r.id) || 0}">${escapeHtml(r.nome)}</option>`;
            });
            select.innerHTML = html;
        }

        // Carregar lista de restaurantes
        function carregarRestaurantes() {
            if (!hasPermission('manage_restaurants')) {
                return;
            }

            const basePath = window.location.pathname.replace(/\/[^/]*$/, '/');
            const candidates = [
                'api/restaurante_listar.php',
                basePath + 'api/restaurante_listar.php',
                '/V00/src/public/api/restaurante_listar.php'
            ];

            const tentar = (idx = 0) => {
                if (idx >= candidates.length) {
                    throw new Error('Falha de conexao com API de restaurantes.');
                }
                return apiFetch(candidates[idx])
                    .then(response => parseJsonSafe(response))
                    .catch(err => {
                        if (/Failed to fetch|NetworkError|conexao/i.test(String(err && err.message || ''))) {
                            return tentar(idx + 1);
                        }
                        throw err;
                    });
            };

            Promise.resolve()
                .then(() => tentar(0))
                .then(data => {
                    if (data.success) {
                        tableState.restaurantes.all = Array.isArray(data.data) ? data.data : [];
                        aplicarFiltroRestaurantes(false);
                        atualizarEstatisticas(tableState.restaurantes.filtered);
                        popularSeletor(data.data);
                    } else {
                        showAlert(data.message || 'Falha ao carregar restaurantes.', 'danger');
                    }
                })
                .catch(err => {
                    console.error('Erro:', err);
                    showAlert('Erro ao carregar restaurantes: ' + err.message, 'danger');
                });
        }

        function restauranteVencido(r) {
            if (!r || !r.data_fim) return false;
            const d = new Date(r.data_fim);
            if (Number.isNaN(d.getTime())) return false;
            const hoje = new Date(); hoje.setHours(0, 0, 0, 0);
            return d < hoje;
        }

        function aplicarFiltroRestaurantes(resetPage = true) {
            const query = normalizeText(document.getElementById('filtroRestaurantes').value);
            tableState.restaurantes.filtered = tableState.restaurantes.all.filter(r => {
                if (!query) {
                    return true;
                }

                const hay = [r.nome, r.email, r.telefone, getRestaurantDisplayStatus(r), r.status, getRestaurantPlanCode(r)].map(normalizeText).join(' ');
                return hay.includes(query);
            });
            if (resetPage) {
                tableState.restaurantes.page = 1;
            }
            atualizarEstatisticas(tableState.restaurantes.filtered);
            renderRestaurantes();
            updateUrlState();
        }

        function renderRestaurantes() {
            const state = tableState.restaurantes;
            const ordered = applySorting(state.filtered, state);
            const paged = paginate(ordered, state.page, state.pageSize);
            state.page = paged.page;
            atualizarTabela(paged.items);
            document.getElementById('paginacaoRestaurantesInfo').textContent = `${paged.page}/${paged.totalPages}`;
            document.getElementById('btnRestaurantesPrev').disabled = paged.page <= 1;
            document.getElementById('btnRestaurantesNext').disabled = paged.page >= paged.totalPages;
        }

        // Atualizar tabela
        function atualizarTabela(restaurantes) {
            const tbody = document.getElementById('tabelaRestaurantes');

            if (restaurantes.length === 0) {
                tbody.innerHTML = `<tr><td colspan="7" class="text-center py-4 text-muted"><i class="fas fa-inbox me-2"></i>Nenhum restaurante encontrado</td></tr>`;
                return;
            }

            let html = '';
            restaurantes.forEach(r => {
                const id = Number(r.id) || 0;
                const nome = escapeHtml(r.nome);
                const email = escapeHtml(r.email || '-');
                const telefone = escapeHtml(r.telefone || '-');
                const status = getRestaurantDisplayStatus(r);
                const statusAttr = escapeHtml(status);
                const nomeAttr = escapeHtml(String(r.nome || ''));
                const planoAtual = getRestaurantPlanCode(r);

                let badgePlano = planoAtual === 'EMPRESARIAL' ? '<span class="badge bg-warning badge-plano">Empresarial</span>' :
                    planoAtual === 'PROFISSIONAL' ? '<span class="badge bg-info badge-plano">Profissional</span>' :
                    '<span class="badge bg-secondary badge-plano">Básico</span>';

                let badgeStatus = `<span class="badge bg-${getRestaurantStatusBadgeClass(status)} badge-status">${escapeHtml(getRestaurantStatusLabel(status))}</span>`;
                if (status === 'ATIVO' && restauranteVencido(r)) {
                    // O estado na base so muda quando alguem entra; aqui mostra-se o que e verdade hoje.
                    badgeStatus = '<span class="badge bg-warning text-dark badge-status" title="O plano venceu: o restaurante está em modo limitado ou bloqueado">Plano vencido</span>';
                }

                const dataValidade = r.data_fim ? new Date(r.data_fim) : null;
                const hoje = new Date();
                let validadeTexto = '-';
                let validadeClasse = 'warning';

                if (dataValidade instanceof Date && !Number.isNaN(dataValidade.getTime())) {
                    const diasRestantes = Math.ceil((dataValidade - hoje) / (1000 * 60 * 60 * 24));
                    const dataTxt = dataValidade.toLocaleDateString('pt-PT');
                    if (diasRestantes < 0) {
                        validadeTexto = 'Venceu há ' + Math.abs(diasRestantes) + (Math.abs(diasRestantes) === 1 ? ' dia' : ' dias');
                        validadeClasse = 'danger';
                    } else if (diasRestantes === 0) {
                        validadeTexto = 'Vence hoje';
                        validadeClasse = 'warning';
                    } else if (diasRestantes <= 30) {
                        validadeTexto = 'Faltam ' + diasRestantes + (diasRestantes === 1 ? ' dia' : ' dias');
                        validadeClasse = 'warning';
                    } else {
                        validadeTexto = 'Até ' + dataTxt;
                        validadeClasse = 'success';
                    }
                }

                html += `<tr>
                    <td class="ps-4"><span class="table-id">#${id}</span></td>
                    <td>
                        <div class="table-identity">
                            <strong>${nome}</strong>
                            <span class="table-subtext">${email}</span>
                        </div>
                    </td>
                    <td>${telefone}</td>
                    <td>${badgePlano}</td>
                    <td>${badgeStatus}</td>
                    <td><span class="validity-badge ${validadeClasse}"><i class="fas fa-calendar-alt"></i>${escapeHtml(validadeTexto)}</span></td>
                    <td class="text-center">
                        <div class="action-group">
                            <button class="btn btn-sm btn-outline-info btn-restaurante-action" data-action="ver" data-id="${id}" title="Ver detalhes" aria-label="Ver detalhes de ${nomeAttr}"><i class="fas fa-eye" aria-hidden="true"></i></button>
                            <button class="btn btn-sm btn-outline-primary btn-restaurante-action" data-action="editar" data-id="${id}" title="Editar" aria-label="Editar ${nomeAttr}"><i class="fas fa-pen" aria-hidden="true"></i></button>
                            <button class="btn btn-sm btn-outline-danger btn-restaurante-action" data-action="deletar" data-id="${id}" data-nome="${nomeAttr}" data-status="${statusAttr}" title="Apagar" aria-label="Apagar ${nomeAttr}"><i class="fas fa-trash" aria-hidden="true"></i></button>
                        </div>
                    </td>
                </tr>`;
            });

            tbody.innerHTML = html;
        }

        // Atualizar estatísticas
        function atualizarEstatisticas(restaurantes) {
            const hoje = new Date();
            const expirando = restaurantes.filter(r => {
                if (!r.data_fim) {
                    return false;
                }

                const dataValidade = new Date(r.data_fim);
                if (Number.isNaN(dataValidade.getTime())) {
                    return false;
                }

                const diasRestantes = Math.ceil((dataValidade - hoje) / (1000 * 60 * 60 * 24));
                return diasRestantes >= 0 && diasRestantes <= 30;
            }).length;

            const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = String(v); };
            const bloqueados = restaurantes.filter(r => getRestaurantDisplayStatus(r) === 'BLOQUEADO').length;
            const vencidos = restaurantes.filter(r => getRestaurantDisplayStatus(r) === 'BLOQUEADO' || (getRestaurantDisplayStatus(r) === 'ATIVO' && restauranteVencido(r))).length;
            set('listaTotalRestaurantes', restaurantes.length);
            set('listaAtivos', restaurantes.filter(r => getRestaurantDisplayStatus(r) === 'ATIVO' && !restauranteVencido(r)).length);
            set('listaExpirando', expirando);
            set('listaBloqueados', bloqueados);
            set('listaVencidos', vencidos);
            set('listaEnterprise', restaurantes.filter(r => getRestaurantPlanCode(r) === 'EMPRESARIAL').length);
            document.getElementById('cardListaVencidos')?.classList.toggle('sa-kpi--alerta', vencidos > 0);
        }

        // Cadastrar restaurante
        function cadastrarRestaurante() {
            if (!hasPermission('manage_restaurants')) {
                showAlert('Sem permissão para cadastrar restaurantes.', 'warning');
                return;
            }

            const form = document.getElementById('formCadastrar');
            const formData = new FormData(form);

            apiFetch('api/restaurante_cadastrar.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showAlert(data.message, 'success');
                        bootstrap.Modal.getInstance(document.getElementById('modalCadastrar')).hide();
                        form.reset();
                        carregarRestaurantes();
                    } else {
                        showAlert(data.message, 'danger');
                    }
                })
                .catch(err => {
                    showAlert('Erro ao cadastrar: ' + err.message, 'danger');
                });
        }

        // Ver detalhes
        function verDetalhes(id) {
            if (!hasPermission('manage_restaurants')) {
                showAlert('Sem permissão para visualizar detalhes.', 'warning');
                return;
            }

            const modal = new bootstrap.Modal(document.getElementById('modalVer'));
            const container = document.getElementById('detalhesRestaurante');

            container.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>';
            modal.show();

            fetch('api/restaurante_buscar.php?id=' + id, {
                    credentials: 'same-origin'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const r = data.data;
                        const nome = escapeHtml(r.nome);
                        const email = escapeHtml(r.email || '-');
                        const telefone = escapeHtml(r.telefone || '-');
                        const cidade = escapeHtml(r.cidade || '-');
                        const nuit = escapeHtml(r.nuit || '-');
                        const planoAtual = getRestaurantPlanCode(r);
                        const plano = escapeHtml(planoAtual);
                        const status = getRestaurantDisplayStatus(r);
                        const statusClass = getRestaurantStatusBadgeClass(status);
                        const validade = r.data_fim ? new Date(r.data_fim).toLocaleDateString('pt-BR') : '-';
                        const pendingNotice = Number(r.possui_compra_pendente || 0) === 1 ?
                            '<div class="alert alert-warning py-2 mt-3 mb-0"><i class="fas fa-clock me-2"></i>Este restaurante possui uma solicitacao de plano pendente. O status exibido considera essa pendencia.</div>' :
                            '';

                        container.innerHTML = `
                        <div class="text-center mb-3">
                            <div style="width: 80px; height: 80px; background: linear-gradient(135deg, var(--primary), var(--secondary)); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto; font-size: 32px; color: white;">
                                <i class="fas fa-utensils"></i>
                            </div>
                            <h5 class="mt-3">${nome}</h5>
                            <span class="badge bg-${statusClass}">${escapeHtml(getRestaurantStatusLabel(status))}</span>
                        </div>
                        <div class="row">
                            <div class="col-6 mb-2"><small class="text-muted">Email</small><div>${email}</div></div>
                            <div class="col-6 mb-2"><small class="text-muted">Telefone</small><div>${telefone}</div></div>
                            <div class="col-6 mb-2"><small class="text-muted">Cidade</small><div>${cidade}</div></div>
                            <div class="col-6 mb-2"><small class="text-muted">NUIT</small><div>${nuit}</div></div>
                            <div class="col-6 mb-2"><small class="text-muted">Plano</small><div><span class="badge bg-${planoAtual === 'EMPRESARIAL' ? 'warning' : (planoAtual === 'PROFISSIONAL' ? 'info' : 'secondary')}">${plano}</span></div></div>
                            <div class="col-6 mb-2"><small class="text-muted">Validade</small><div>${escapeHtml(validade)}</div></div>
                        </div>
                        <hr>
                        <div class="row text-center">
                            <div class="col-4"><div class="fs-4 fw-bold text-primary">${r.total_usuarios}</div><small class="text-muted">Usuários</small></div>
                            <div class="col-4"><div class="fs-4 fw-bold text-success">${r.total_produtos}</div><small class="text-muted">Produtos</small></div>
                            <div class="col-4"><div class="fs-4 fw-bold text-info">${r.total_mesas}</div><small class="text-muted">Mesas</small></div>
                        </div>
                        ${pendingNotice}`;
                    } else {
                        container.innerHTML = '<div class="text-danger text-center">Erro ao carregar detalhes</div>';
                    }
                })
                .catch(err => {
                    container.innerHTML = '<div class="text-danger text-center">Erro: ' + err.message + '</div>';
                });
        }

        // Editar restaurante
        function editarRestaurante(id) {
            if (!hasPermission('manage_restaurants')) {
                showAlert('Sem permissão para editar restaurantes.', 'warning');
                return;
            }

            fetch('api/restaurante_buscar.php?id=' + id, {
                    credentials: 'same-origin'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const r = data.data;
                        const rawStatus = getRestaurantRawStatus(r);
                        const displayStatus = getRestaurantDisplayStatus(r);
                        const statusSelect = document.getElementById('edit_status');
                        const statusHelp = document.getElementById('edit_status_help');

                        document.getElementById('edit_id').value = r.id;
                        document.getElementById('edit_nome').value = r.nome;
                        document.getElementById('edit_email').value = r.email;
                        document.getElementById('edit_telefone').value = r.telefone || '';
                        document.getElementById('edit_cidade').value = r.cidade || '';
                        document.getElementById('edit_endereco').value = r.endereco || '';
                        document.getElementById('edit_nuit').value = r.nuit || '';
                        document.getElementById('edit_plano').value = getRestaurantPlanCode(r);
                        statusSelect.value = SUPPORTED_RESTAURANT_STATUSES.includes(rawStatus) ?
                            rawStatus :
                            (SUPPORTED_RESTAURANT_STATUSES[0] || 'ATIVO');
                        statusHelp.textContent = Number(r.possui_compra_pendente || 0) === 1 ?
                            'Este restaurante aparece como "' + getRestaurantStatusLabel(displayStatus) + '" porque possui uma compra de plano pendente. O campo acima edita apenas o status salvo na tabela restaurantes.' :
                            'Apenas os status suportados pelo banco atual aparecem nesta lista.';
                        new bootstrap.Modal(document.getElementById('modalEditar')).show();
                    } else {
                        showAlert(data.message, 'danger');
                    }
                })
                .catch(err => {
                    showAlert('Erro ao carregar dados: ' + err.message, 'danger');
                });
        }

        // Salvar edição
        function salvarEdicao() {
            if (!hasPermission('manage_restaurants')) {
                showAlert('Sem permissão para editar restaurantes.', 'warning');
                return;
            }

            const form = document.getElementById('formEditar');
            const formData = new FormData(form);

            apiFetch('api/restaurante_editar.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showAlert(data.message, 'success');
                        bootstrap.Modal.getInstance(document.getElementById('modalEditar')).hide();
                        carregarRestaurantes();
                    } else {
                        showAlert(data.message, 'danger');
                    }
                })
                .catch(err => {
                    showAlert('Erro ao salvar: ' + err.message, 'danger');
                });
        }

        // Alterar status rapidamente (aprovar/rejeitar)
        function mudarStatus(id, novoStatus) {
            if (!hasPermission('manage_restaurants')) {
                showAlert('Sem permissão para alterar status.', 'warning');
                return;
            }

            const formData = new FormData();
            formData.append('id', id);
            formData.append('status', novoStatus);

            apiFetch('api/restaurante_editar.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showAlert('Status alterado com sucesso', 'success');
                        carregarRestaurantes();
                    } else {
                        showAlert(data.message, 'danger');
                    }
                })
                .catch(err => {
                    showAlert('Erro ao alterar status: ' + err.message, 'danger');
                });
        }

        // Deletar restaurante
        async function deletarRestaurante(id, nome) {
            if (!hasPermission('manage_restaurants')) {
                showAlert('Sem permissão para excluir restaurantes.', 'warning');
                return;
            }

            if (!(await nivoraConfirmar({ titulo: `Excluir o restaurante "${nome}"?`, mensagem: 'Esta ação não pode ser desfeita.', confirmar: 'Excluir', perigo: true }))) {
                return;
            }

            apiFetch('api/restaurante_deletar.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        id: id
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showAlert(data.message, 'success');
                        carregarRestaurantes();
                    } else {
                        showAlert(data.message, 'danger');
                    }
                })
                .catch(err => {
                    showAlert('Erro ao deletar: ' + err.message, 'danger');
                });
        }

        // ==================== USUÁRIOS ====================

        // Carregar restaurantes para seletor de usuários
