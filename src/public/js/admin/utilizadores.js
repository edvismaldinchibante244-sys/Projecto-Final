// Painel Super Admin - Utilizadores: lista, filtros, criacao e eliminacao.
// Parte de admin.php; carregado por ordem com os outros ficheiros de js/admin/ (ver admin.php).

        function carregarRestaurantesParaUsuario() {
            if (!hasPermission('manage_users')) {
                return;
            }

            const requestedRestaurantId = new URLSearchParams(window.location.search).get('rid');

            fetch('api/restaurante_listar.php', {
                    credentials: 'same-origin'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const select = document.getElementById('restauranteSelecionadoUsuarios');
                        let html = '<option value="">Todos os restaurantes</option>';
                        data.data.forEach(r => {
                            html += `<option value="${Number(r.id) || 0}">${escapeHtml(r.nome)}</option>`;
                        });
                        select.innerHTML = html;

                        const requestedRestaurantExists = requestedRestaurantId
                            && Array.from(select.options).some(option => option.value === requestedRestaurantId);
                        if (requestedRestaurantExists) {
                            select.value = requestedRestaurantId;
                        }
                        carregarUsuarios();
                    }
                })
                .catch(err => console.error('Erro:', err));
        }

        // Carregar usuários
        function carregarUsuarios() {
            if (!hasPermission('manage_users')) {
                return;
            }

            const restauranteId = document.getElementById('restauranteSelecionadoUsuarios').value;
            const tbody = document.getElementById('tabelaUsuarios');

            // Definir ID no form oculto (criar utilizador exige um restaurante escolhido)
            document.getElementById('usuario_restaurante_id').value = restauranteId || '';
            if (tbody) tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i>A carregar…</td></tr>';

            fetch('api/super_admin_usuarios_listar.php?restaurante_id=' + encodeURIComponent(restauranteId || '0'), {
                    credentials: 'same-origin'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        tableState.usuarios.all = Array.isArray(data.data) ? data.data : [];
                        aplicarFiltroUsuarios(false);
                    } else {
                        showAlert(data.message, 'danger');
                    }
                })
                .catch(err => {
                    console.error('Erro:', err);
                    showAlert('Erro ao carregar os utilizadores', 'danger');
                });
        }

        function aplicarFiltroUsuarios(resetPage = true) {
            const query = normalizeText(document.getElementById('filtroUsuarios').value);
            tableState.usuarios.filtered = tableState.usuarios.all.filter(u => {
                if (!query) {
                    return true;
                }

                const hay = [u.nome, u.email, u.perfil, u.restaurante_nome, u.ativo ? 'ativo' : 'inativo'].map(normalizeText).join(' ');
                return hay.includes(query);
            });
            if (resetPage) {
                tableState.usuarios.page = 1;
            }
            renderUsuarios();
            updateUrlState();
        }

        function renderUsuarios() {
            const tbody = document.getElementById('tabelaUsuarios');
            const state = tableState.usuarios;
            const ordered = applySorting(state.filtered, state);
            const paged = paginate(ordered, state.page, state.pageSize);
            state.page = paged.page;

            if (paged.items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-muted">Nenhum utilizador encontrado</td></tr>';
            } else {
                let html = '';
                paged.items.forEach(u => {
                    const userId = Number(u.id) || 0;
                    const nome = escapeHtml(u.nome);
                    const email = escapeHtml(u.email);
                    const nomeAttr = escapeHtml(String(u.nome || ''));
                    const perfis = { ADMIN: ['Administrador', 'primary'], GERENTE: ['Gerente', 'dark'], CAIXA: ['Caixa', 'success'], GARCOM: ['Garçom', 'info'], COZINHA: ['Cozinha', 'warning'], BAR: ['Bar', 'secondary'] };
                    const [perfilTxt, perfilCor] = perfis[String(u.perfil || '').toUpperCase()] || [u.perfil || '—', 'secondary'];
                    const perfilBadge = `<span class="badge bg-${perfilCor}${perfilCor === 'warning' || perfilCor === 'info' ? ' text-dark' : ''}">${escapeHtml(perfilTxt)}</span>`;
                    const statusBadge = u.ativo ? '<span class="badge bg-success">Ativo</span>' : '<span class="badge bg-danger">Inativo</span>';
                    const turnoAtivo = Number(u.turno_ativo || 0) === 1;
                    const turnoTipo = escapeHtml((u.turno_tipo || ''));
                    const horaEntrada = escapeHtml((u.turno_hora_entrada || ''));
                    const turnoLabel = turnoAtivo
                        ? `<span class="badge bg-success">Ativo</span>${turnoTipo ? ' <small class="text-muted">(' + turnoTipo + ')</small>' : ''}${horaEntrada ? '<div class="text-muted small">Entrada ' + horaEntrada + '</div>' : ''}`
                        : '<span class="text-muted small">Fora de turno</span>';
                    const btnTurno = turnoAtivo
                        ? `<button class="btn btn-sm btn-outline-danger btn-turno-action" data-action="fechar_turno" data-id="${userId}" data-nome="${nomeAttr}" title="Encerrar turno" aria-label="Encerrar turno de ${nomeAttr}"><i class="fas fa-stop" aria-hidden="true"></i></button>`
                        : `<button class="btn btn-sm btn-outline-success btn-turno-action" data-action="abrir_turno" data-id="${userId}" data-nome="${nomeAttr}" title="Iniciar turno" aria-label="Iniciar turno de ${nomeAttr}"><i class="fas fa-play" aria-hidden="true"></i></button>`;

                    html += `<tr>
                        <td class="ps-4"><strong>#${userId}</strong></td>
                        <td><div class="fw-semibold">${nome}</div><div class="small text-muted">${email}</div></td>
                        <td>${escapeHtml(u.restaurante_nome || '—')}</td>
                        <td>${perfilBadge}</td>
                        <td>${statusBadge}</td>
                        <td>${turnoLabel}</td>
                        <td class="text-center">
                            <div class="action-group">
                                ${btnTurno}
                                <button class="btn btn-sm btn-outline-danger btn-usuario-action" data-action="deletar" data-id="${userId}" data-nome="${nomeAttr}" title="Desativar acesso" aria-label="Desativar acesso de ${nomeAttr}"><i class="fas fa-user-slash" aria-hidden="true"></i></button>
                            </div>
                        </td>
                    </tr>`;
                });
                tbody.innerHTML = html;
            }

            document.getElementById('paginacaoUsuariosInfo').textContent = `${paged.page}/${paged.totalPages}`;
            document.getElementById('btnUsuariosPrev').disabled = paged.page <= 1;
            document.getElementById('btnUsuariosNext').disabled = paged.page >= paged.totalPages;
        }

        // Cadastrar usuário
        function cadastrarUsuario() {
            if (!hasPermission('manage_users')) {
                showAlert('Sem permissão para cadastrar usuários.', 'warning');
                return;
            }

            const restauranteId = document.getElementById('restauranteSelecionadoUsuarios').value;
            if (!restauranteId) {
                showAlert('Escolha primeiro o restaurante (no topo da página) onde criar o utilizador.', 'warning');
                return;
            }

            const form = document.getElementById('formCadastrarUsuario');
            const formData = new FormData(form);
            formData.set('restaurante_id', restauranteId);

            apiFetch('api/super_admin_usuario_cadastrar.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showAlert(data.message, 'success');
                        bootstrap.Modal.getInstance(document.getElementById('modalCadastrarUsuario')).hide();
                        form.reset();
                        carregarUsuarios();
                    } else {
                        showAlert(data.message, 'danger');
                    }
                })
                .catch(err => {
                    showAlert('Erro ao cadastrar: ' + err.message, 'danger');
                });
        }

        // Deletar usuário
        async function deletarUsuario(id, nome) {
            if (!hasPermission('manage_users')) {
                showAlert('Sem permissão para inativar usuários.', 'warning');
                return;
            }

            if (!(await nivoraConfirmar({ mensagem: `Inativar o utilizador "${nome}"?`, confirmar: 'Inativar', perigo: true }))) {
                return;
            }

            apiFetch('api/super_admin_usuario_deletar.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        usuario_id: id
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showAlert(data.message, 'success');
                        carregarUsuarios();
                    } else {
                        showAlert(data.message, 'danger');
                    }
                })
                .catch(err => {
                    showAlert('Erro ao inativar: ' + err.message, 'danger');
                });
        }

        // ==================== PLANOS ====================

        // Carregar compras de planos
