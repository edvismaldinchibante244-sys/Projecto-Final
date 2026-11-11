// Painel Super Admin - Arranque da pagina (DOMContentLoaded): liga botoes, filtros e restaura o estado do URL.
// Parte de admin.php; carregado por ordem com os outros ficheiros de js/admin/ (ver admin.php).

        document.addEventListener('DOMContentLoaded', function() {
            if (typeof OfflineSync !== 'undefined' && OfflineSync.init) {
                OfflineSync.init({
                    csrfToken: CSRF_TOKEN,
                    syncIntervalMs: 15000
                });
            }
            restoreStateFromUrl();

            const secaoPermitida = (secaoAtual === 'restaurantes' && hasPermission('manage_restaurants')) ||
                (secaoAtual === 'usuarios' && hasPermission('manage_users')) ||
                (secaoAtual === 'planos' && canAccessPlansSection()) ||
                (secaoAtual === 'suporte' && canAccessSupportSection()) ||
                (secaoAtual === 'seguranca' && canAccessSecuritySection());
            if (!secaoPermitida) {
                secaoAtual = __NIVORA_ADMIN.v5;
            }

            document.querySelectorAll('.js-secao-link').forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const secao = this.dataset.secao;
                    if (secao) {
                        mostrarSecao(secao, this);
                        if (isMobileViewport()) {
                            setSidebarOpen(false);
                        }
                    }
                });
            });

            const btnMenuToggle = document.getElementById('btnMenuToggle');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');
            const sidebar = document.querySelector('.sidebar');
            const startOpen = !isMobileViewport();
            setSidebarOpen(startOpen);

            btnMenuToggle?.addEventListener('click', () => {
                const isHidden = sidebar?.classList.contains('sidebar-hidden');
                setSidebarOpen(!!isHidden);
            });
            sidebarBackdrop?.addEventListener('click', () => setSidebarOpen(false));
            window.addEventListener('resize', () => {
                if (isMobileViewport()) {
                    setSidebarOpen(false);
                } else {
                    setSidebarOpen(true);
                }
            });

            document.getElementById('restauranteSelecionadoUsuarios')?.addEventListener('change', carregarUsuarios);
            document.getElementById('btnAcessarDashboard')?.addEventListener('click', acessarDashboard);
            document.getElementById('btnAtualizarCompras')?.addEventListener('click', carregarCompras);
            document.getElementById('btnAtualizarSupportTickets')?.addEventListener('click', carregarSupportTickets);
            document.getElementById('btnAtualizarSeguranca')?.addEventListener('click', carregarPainelSeguranca);
            document.getElementById('securityWindowHours')?.addEventListener('change', carregarPainelSeguranca);
            document.getElementById('btnBloquearIpManual')?.addEventListener('click', bloquearIpManual);
            document.getElementById('btnSincronizarFilaOffline')?.addEventListener('click', sincronizarFilaOfflineAgora);
            // Os botoes de aprovar/rejeitar usam onclick inline para evitar bind duplicado.
            document.getElementById('btnCadastrarRestaurante')?.addEventListener('click', cadastrarRestaurante);
            document.getElementById('btnSalvarEdicao')?.addEventListener('click', salvarEdicao);
            document.getElementById('btnCadastrarUsuario')?.addEventListener('click', cadastrarUsuario);
            document.getElementById('filtroRestaurantes')?.addEventListener('input', aplicarFiltroRestaurantes);
            document.getElementById('filtroUsuarios')?.addEventListener('input', aplicarFiltroUsuarios);
            document.getElementById('filtroCompras')?.addEventListener('input', aplicarFiltroCompras);
            document.getElementById('filtroPlanoCompras')?.addEventListener('change', () => aplicarFiltroCompras());
            document.getElementById('filtroCicloCompras')?.addEventListener('change', () => aplicarFiltroCompras());
            document.getElementById('filtroStatusCompras')?.addEventListener('change', () => aplicarFiltroCompras());
            document.getElementById('filtroDataIniCompras')?.addEventListener('change', () => aplicarFiltroCompras());
            document.getElementById('filtroDataFimCompras')?.addEventListener('change', () => aplicarFiltroCompras());
            document.getElementById('filtroSupportTickets')?.addEventListener('input', carregarSupportTickets);
            document.getElementById('filtroSupportStatus')?.addEventListener('change', carregarSupportTickets);
            document.getElementById('btnRestaurantesPrev')?.addEventListener('click', () => mudarPagina('restaurantes', -1));
            document.getElementById('btnRestaurantesNext')?.addEventListener('click', () => mudarPagina('restaurantes', 1));
            document.getElementById('btnUsuariosPrev')?.addEventListener('click', () => mudarPagina('usuarios', -1));
            document.getElementById('btnUsuariosNext')?.addEventListener('click', () => mudarPagina('usuarios', 1));
            document.getElementById('btnComprasPrev')?.addEventListener('click', () => mudarPagina('compras', -1));
            document.getElementById('btnComprasNext')?.addEventListener('click', () => mudarPagina('compras', 1));
            document.getElementById('pageSizeRestaurantes')?.addEventListener('change', (e) => {
                tableState.restaurantes.pageSize = parseInt(e.target.value, 10) || PAGE_SIZE;
                tableState.restaurantes.page = 1;
                renderRestaurantes();
                updateUrlState();
            });
            document.getElementById('pageSizeUsuarios')?.addEventListener('change', (e) => {
                tableState.usuarios.pageSize = parseInt(e.target.value, 10) || PAGE_SIZE;
                tableState.usuarios.page = 1;
                renderUsuarios();
                updateUrlState();
            });
            document.getElementById('pageSizeCompras')?.addEventListener('change', (e) => {
                tableState.compras.pageSize = parseInt(e.target.value, 10) || PAGE_SIZE;
                tableState.compras.page = 1;
                renderCompras();
                updateUrlState();
            });

            document.querySelectorAll('.js-sort').forEach(btn => {
                btn.addEventListener('click', () => {
                    const table = btn.dataset.table;
                    const sort = btn.dataset.sort;
                    if (table && sort) {
                        setSort(table, sort);
                    }
                });
            });

            document.getElementById('tabelaRestaurantes')?.addEventListener('click', function(e) {
                const button = e.target.closest('.btn-restaurante-action');
                if (!button) {
                    return;
                }

                const id = Number(button.dataset.id || 0);
                const action = button.dataset.action || '';

                if (action === 'ver') {
                    verDetalhes(id);
                } else if (action === 'editar') {
                    editarRestaurante(id);
                } else if (action === 'deletar') {
                    deletarRestaurante(id, button.dataset.nome || '');
                }
            });

            document.getElementById('tabelaUsuarios')?.addEventListener('click', async function(e) {
                const turnoBtn = e.target.closest('.btn-turno-action');
                if (turnoBtn) {
                    const id = Number(turnoBtn.dataset.id || 0);
                    const nome = turnoBtn.dataset.nome || '';
                    const action = turnoBtn.dataset.action || '';
                    const restauranteId = document.getElementById('restauranteSelecionadoUsuarios')?.value;
                    if (!restauranteId) {
                        showAlert('Selecione um restaurante primeiro!', 'warning');
                        return;
                    }
                    if (!id) return;
                    const motivo = await nivoraPerguntar({
                        titulo: 'Intervir no turno de ' + nome,
                        campo: { rotulo: 'Motivo da intervenção (obrigatório)', multilinha: true, obrigatorio: true, max: 255 },
                        confirmar: action === 'abrir_turno' ? 'Abrir turno' : 'Fechar turno',
                    });
                    if (!motivo) return;
                    const payload = {
                        acao: action === 'abrir_turno' ? 'abrir_manual' : 'fechar_manual',
                        funcionario_id: id,
                        restaurante_id: Number(restauranteId),
                        motivo: motivo
                    };
                    apiFetch('api/super_admin_turno_operacao.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(payload)
                        })
                        .then(r => r.json())
                        .then(data => {
                            if (data.success) {
                                showAlert(data.message || 'Operação de turno realizada.', 'success');
                                carregarUsuarios();
                            } else {
                                showAlert(data.message || 'Falha ao operar turno.', 'danger');
                            }
                        })
                        .catch(() => showAlert('Falha ao operar turno.', 'danger'));
                    return;
                }

                const button = e.target.closest('.btn-usuario-action');
                if (!button) {
                    return;
                }

                const id = Number(button.dataset.id || 0);
                const nome = button.dataset.nome || '';
                if ((button.dataset.action || '') === 'deletar') {
                    deletarUsuario(id, nome);
                }
            });

            document.getElementById('tabelaCompras')?.addEventListener('click', function(e) {
                const button = e.target.closest('.btn-compra-action');
                const buttonComprovativo = e.target.closest('.btn-compra-comprovativo');

                if (buttonComprovativo) {
                    const path = buttonComprovativo.dataset.path || '';
                    abrirComprovativo(path);
                    return;
                }

                if (!button) {
                    return;
                }

                const id = Number(button.dataset.id || 0);
                const action = button.dataset.action || '';
                if (action === 'aprovar') {
                    aprovarCompra(id);
                } else if (action === 'rejeitar') {
                    rejeitarCompra(id);
                }
            });

            document.getElementById('tabelaTopIps')?.addEventListener('click', function(e) {
                const btn = e.target.closest('.btn-block-ip');
                if (!btn) return;
                executarAcaoIp('BLOCK', btn.dataset.ip || '', 'TEMPORARIO');
            });

            document.getElementById('tabelaBlockedIps')?.addEventListener('click', function(e) {
                const btn = e.target.closest('.btn-unblock-ip');
                if (!btn) return;
                executarAcaoIp('UNBLOCK', btn.dataset.ip || '');
            });

            const secaoLinkInicial = document.querySelector(`.js-secao-link[data-secao="${secaoAtual}"]`);
            if (secaoLinkInicial) {
                mostrarSecao(secaoAtual, secaoLinkInicial);
            }
            carregarPainelFilaOffline();
            carregarEstatisticasGerais();
        });
