// Valores do servidor: <script type="application/json" id="cfg-admin"> na pagina.
var __NIVORA_ADMIN = JSON.parse(document.getElementById('cfg-admin').textContent);
        const CSRF_TOKEN = __NIVORA_ADMIN.v0;
        const SUPER_ADMIN_PERMISSIONS = __NIVORA_ADMIN.v1;
        const SUPPORTED_RESTAURANT_STATUSES = __NIVORA_ADMIN.v2;
        const RESTAURANT_STATUS_LABELS = __NIVORA_ADMIN.v3;
        let secaoAtual = __NIVORA_ADMIN.v4;
        let securityAutoRefreshHandle = null;
        const PAGE_SIZE = 10;
        const tableState = {
            restaurantes: {
                all: [],
                filtered: [],
                page: 1,
                pageSize: PAGE_SIZE,
                sortKey: 'id',
                sortDir: 'desc'
            },
            usuarios: {
                all: [],
                filtered: [],
                page: 1,
                pageSize: PAGE_SIZE,
                sortKey: 'id',
                sortDir: 'desc'
            },
            compras: {
                all: [],
                filtered: [],
                page: 1,
                pageSize: PAGE_SIZE,
                sortKey: 'id',
                sortDir: 'desc'
            }
        };
        let securityTimelineChart = null;

        function getRestaurantDisplayStatus(restaurante) {
            const rawDisplay = restaurante && restaurante.status_exibicao !== undefined ?
                restaurante.status_exibicao :
                (restaurante && Number(restaurante.possui_compra_pendente) === 1 ? 'PENDENTE' : (restaurante ? restaurante.status : ''));
            const normalized = String(rawDisplay || '').toUpperCase().trim();
            if (normalized) {
                return normalized;
            }

            const rawStatus = String(restaurante?.status || '').toUpperCase().trim();
            return rawStatus || 'SEM_STATUS';
        }

        function normalizePurchaseStatus(status) {
            const normalized = String(status || '').toUpperCase().trim();
            if (!normalized) {
                return 'AGUARDANDO_APROVACAO';
            }
            if (normalized === 'CANCELADO') {
                return 'REJEITADO';
            }
            return normalized === 'PENDENTE' ? 'AGUARDANDO_APROVACAO' : normalized;
        }

        function getPurchaseStatusLabel(status) {
            const key = normalizePurchaseStatus(status);
            if (key === 'AGUARDANDO_APROVACAO') return 'Aguardando Aprovação';
            if (key === 'APROVADO') return 'Aprovado';
            if (key === 'REJEITADO') return 'Rejeitado';
            return key || 'Sem status';
        }

        function getRestaurantRawStatus(restaurante) {
            return String(restaurante?.status || '').toUpperCase().trim();
        }

        function getRestaurantStatusLabel(status) {
            const key = String(status || '').toUpperCase();
            return RESTAURANT_STATUS_LABELS[key] || (key.charAt(0) + key.slice(1).toLowerCase());
        }

        function getRestaurantStatusBadgeClass(status) {
            const key = String(status || '').toUpperCase();
            if (key === 'ATIVO') return 'success';
            if (key === 'PENDENTE') return 'warning';
            if (key === 'BLOQUEADO') return 'warning';
            if (key === 'CANCELADO') return 'danger';
            return 'secondary';
        }

        function getRestaurantPlanCode(restaurante) {
            const plan = String(restaurante?.plano || 'BASICO').toUpperCase();
            return plan === 'ENTERPRISE' ? 'EMPRESARIAL' : plan;
        }

        function paginate(items, page, pageSize = PAGE_SIZE) {
            const safeTotal = Math.max(1, Math.ceil(items.length / pageSize));
            const safePage = Math.min(Math.max(1, page), safeTotal);
            const start = (safePage - 1) * pageSize;
            return {
                page: safePage,
                totalPages: safeTotal,
                items: items.slice(start, start + pageSize)
            };
        }

        function compareValues(a, b) {
            const aNum = Number(a);
            const bNum = Number(b);
            const aIsNum = Number.isFinite(aNum);
            const bIsNum = Number.isFinite(bNum);
            if (aIsNum && bIsNum) {
                return aNum - bNum;
            }

            const aDate = Date.parse(a);
            const bDate = Date.parse(b);
            const aIsDate = Number.isFinite(aDate);
            const bIsDate = Number.isFinite(bDate);
            if (aIsDate && bIsDate) {
                return aDate - bDate;
            }

            return String(a || '').localeCompare(String(b || ''), 'pt', {
                sensitivity: 'base'
            });
        }

        function applySorting(items, state) {
            const list = [...items];
            const dir = state.sortDir === 'asc' ? 1 : -1;
            const key = state.sortKey;
            list.sort((x, y) => compareValues(x?.[key], y?.[key]) * dir);
            return list;
        }

        function updateUrlState() {
            const params = new URLSearchParams();
            params.set('secao', secaoAtual);

            const map = {
                restaurantes: 'r',
                usuarios: 'u',
                compras: 'c',
                seguranca: 's'
            };

            const activeTable = secaoAtual === 'planos' ? 'compras' : secaoAtual;
            const prefix = map[activeTable];
            const state = tableState[activeTable];
            const filterEl = document.getElementById(
                activeTable === 'restaurantes' ? 'filtroRestaurantes' : (activeTable === 'usuarios' ? 'filtroUsuarios' : 'filtroCompras')
            );

            if (activeTable === 'seguranca') {
                params.set('sw', String(document.getElementById('securityWindowHours')?.value || '24'));
            } else if (prefix && state) {
                params.set(prefix + 'p', String(state.page));
                params.set(prefix + 's', String(state.pageSize));
                params.set(prefix + 'k', String(state.sortKey));
                params.set(prefix + 'd', String(state.sortDir));

                const filtroAtual = (filterEl ? filterEl.value : '').trim();
                if (filtroAtual !== '') {
                    params.set(prefix + 'q', filtroAtual);
                }

                if (activeTable === 'usuarios') {
                    const selectedRestaurant = document.getElementById('restauranteSelecionadoUsuarios')?.value
                        || new URLSearchParams(window.location.search).get('rid')
                        || '';
                    if (selectedRestaurant) {
                        params.set('rid', selectedRestaurant);
                    }
                }
            }

            const url = `${window.location.pathname}?${params.toString()}`;
            window.history.replaceState(null, '', url);
        }

        function restoreStateFromUrl() {
            const params = new URLSearchParams(window.location.search);
            const secao = params.get('secao');
            if (secao && ['restaurantes', 'usuarios', 'planos', 'seguranca'].includes(secao)) {
                secaoAtual = secao;
            }

            const map = {
                restaurantes: 'r',
                usuarios: 'u',
                compras: 'c'
            };

            Object.keys(map).forEach(key => {
                const prefix = map[key];
                const state = tableState[key];
                state.page = Math.max(1, parseInt(params.get(prefix + 'p') || '1', 10) || 1);
                const restoredPageSize = parseInt(params.get(prefix + 's') || '10', 10);
                state.pageSize = [10, 25, 50].includes(restoredPageSize) ? restoredPageSize : 10;
                state.sortKey = params.get(prefix + 'k') || state.sortKey;
                if (key === 'restaurantes' && state.sortKey === 'status') {
                    state.sortKey = 'status_exibicao';
                }
                state.sortDir = params.get(prefix + 'd') === 'asc' ? 'asc' : 'desc';

                const filterValue = params.get(prefix + 'q') || '';
                const filterEl = document.getElementById(
                    key === 'restaurantes' ? 'filtroRestaurantes' : (key === 'usuarios' ? 'filtroUsuarios' : 'filtroCompras')
                );
                if (filterEl) {
                    filterEl.value = filterValue;
                }

                const sizeEl = document.getElementById(
                    key === 'restaurantes' ? 'pageSizeRestaurantes' : (key === 'usuarios' ? 'pageSizeUsuarios' : 'pageSizeCompras')
                );
                if (sizeEl) {
                    sizeEl.value = String(state.pageSize);
                }
            });

            const sw = params.get('sw');
            if (sw && document.getElementById('securityWindowHours')) {
                document.getElementById('securityWindowHours').value = sw;
            }
        }

        function setSort(table, sortKey) {
            const state = tableState[table];
            if (!state) {
                return;
            }

            if (state.sortKey === sortKey) {
                state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                state.sortKey = sortKey;
                state.sortDir = 'asc';
            }
            state.page = 1;

            if (table === 'restaurantes') {
                renderRestaurantes();
            } else if (table === 'usuarios') {
                renderUsuarios();
            } else if (table === 'compras') {
                renderCompras();
            }
            updateUrlState();
        }

        function normalizeText(value) {
            return String(value || '').toLowerCase();
        }

        function hasPermission(permission) {
            return Boolean(SUPER_ADMIN_PERMISSIONS && SUPER_ADMIN_PERMISSIONS[permission]);
        }

        function canAccessPlansSection() {
            return hasPermission('view_finance') || hasPermission('approve_plans');
        }

        function canAccessSecuritySection() {
            return hasPermission('manage_security');
        }

        function canAccessSupportSection() {
            return hasPermission('manage_support');
        }

        function escapeHtml(value) {
            const str = String(value ?? '');
            return str
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function apiFetch(url, options = {}) {
            const method = String(options.method || 'GET').toUpperCase();
            const headers = new Headers(options.headers || {});
            if (method !== 'GET') {
                headers.set('X-CSRF-Token', CSRF_TOKEN);
            }

            return fetch(url, {
                ...options,
                headers,
                credentials: 'same-origin'
            });
        }

        async function parseJsonSafe(response) {
            const text = await response.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                const compact = String(text || '').replace(/\s+/g, ' ').trim();
                throw new Error(compact ? compact.slice(0, 220) : ('Resposta invalida do servidor (HTTP ' + response.status + ')'));
            }
        }

        function buildCsrfFormBody(data) {
            const params = new URLSearchParams();
            Object.entries(data || {}).forEach(([key, value]) => {
                params.append(key, value == null ? '' : String(value));
            });
            params.append('_csrf', CSRF_TOKEN);
            return params.toString();
        }

        // Função para mostrar seção
        function mostrarSecao(secao, triggerElement = null) {
            const secaoPermitida = (secao === 'restaurantes' && hasPermission('manage_restaurants')) ||
                (secao === 'usuarios' && hasPermission('manage_users')) ||
                (secao === 'planos' && canAccessPlansSection()) ||
                (secao === 'suporte' && canAccessSupportSection()) ||
                (secao === 'seguranca' && canAccessSecuritySection());

            if (!secaoPermitida) {
                showAlert('Sem permissão para acessar esta área.', 'warning');
                return;
            }

            secaoAtual = secao;

            // Atualizar menu
            document.querySelectorAll('.sidebar .nav-link').forEach(link => {
                link.classList.remove('active');
            });
            if (triggerElement) {
                triggerElement.classList.add('active');
            }

            // Mostrar seção correta
            document.querySelectorAll('.secao').forEach(s => s.classList.remove('ativa'));
            document.getElementById('secao-' + secao).classList.add('ativa');

            // Carregar dados conforme seção
            if (secao === 'restaurantes') {
                carregarRestaurantes();
            } else if (secao === 'usuarios') {
                carregarRestaurantesParaUsuario();
            } else if (secao === 'planos') {
                carregarCompras();
            } else if (secao === 'suporte') {
                carregarSupportTickets();
            } else if (secao === 'seguranca') {
                carregarPainelSeguranca();
            }

            controlarAutoRefreshSeguranca();
            updateUrlState();
        }

        // Função para mostrar alerta
        function showAlert(message, type = 'success') {
            const alertContainer = document.getElementById('alertContainer');
            const safeMessage = escapeHtml(message);
            alertContainer.innerHTML = `
                <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                    ${safeMessage}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            setTimeout(() => {
                alertContainer.innerHTML = '';
            }, 5000);
        }

        function showAlertHtml(html, type = 'success', timeoutMs = 5000) {
            const alertContainer = document.getElementById('alertContainer');
            alertContainer.innerHTML = `
                <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                    ${html}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;

            if (timeoutMs > 0) {
                setTimeout(() => {
                    alertContainer.innerHTML = '';
                }, timeoutMs);
            }
        }

        function isLocalEnvironment() {
            const host = String(window.location.hostname || '').toLowerCase();
            return host === 'localhost' || host === '127.0.0.1';
        }

        function isMobileViewport() {
            return window.matchMedia('(max-width: 991px)').matches;
        }

        function setSidebarOpen(open) {
            const sidebar = document.querySelector('.sidebar');
            const backdrop = document.getElementById('sidebarBackdrop');
            const btn = document.getElementById('btnMenuToggle');
            const icon = btn ? btn.querySelector('i') : null;
            if (!sidebar) return;

            if (isMobileViewport()) {
                sidebar.classList.toggle('sidebar-hidden', !open);
                if (backdrop) backdrop.classList.toggle('show', !!open);
                document.body.classList.remove('layout-sidebar-collapsed');
                if (btn) btn.classList.remove('is-docked');
                if (icon) {
                    icon.classList.remove('fa-bars');
                    icon.classList.remove('fa-times');
                    icon.classList.add(open ? 'fa-times' : 'fa-bars');
                }
            } else {
                sidebar.classList.toggle('sidebar-hidden', !open);
                document.body.classList.toggle('layout-sidebar-collapsed', !open);
                if (backdrop) backdrop.classList.remove('show');
                if (btn) btn.classList.toggle('is-docked', !!open);
                if (icon) {
                    icon.classList.remove('fa-bars');
                    icon.classList.remove('fa-times');
                    icon.classList.add(open ? 'fa-times' : 'fa-bars');
                }
            }
        }

        // ==================== RESTAURANTES ====================

        // Carregar restaurantes ao iniciar
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

            document.getElementById('tabelaUsuarios')?.addEventListener('click', function(e) {
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
                    const motivo = prompt('Motivo da intervenção no turno de ' + nome + ':');
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
                        document.getElementById('statTotalRestaurantes').textContent = data.data.total_restaurantes;
                        document.getElementById('statRestaurantesAtivos').textContent = data.data.restaurantes_ativos;
                        document.getElementById('statAssinaturasExpirando').textContent = data.data.assinaturas_expirando;
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
            let html = '<option value="">Selecione um restaurante...</option>';
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
                tbody.innerHTML = `<tr><td colspan="8" class="text-center py-4 text-muted"><i class="fas fa-inbox me-2"></i>Nenhum restaurante cadastrado</td></tr>`;
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

                const dataValidade = r.data_fim ? new Date(r.data_fim) : null;
                const hoje = new Date();
                let validadeTexto = '-';
                let validadeClasse = 'warning';

                if (dataValidade instanceof Date && !Number.isNaN(dataValidade.getTime())) {
                    const diasRestantes = Math.ceil((dataValidade - hoje) / (1000 * 60 * 60 * 24));
                    if (diasRestantes < 0) {
                        validadeTexto = 'Expirado';
                        validadeClasse = 'danger';
                    } else if (diasRestantes <= 30) {
                        validadeTexto = diasRestantes + ' dias';
                        validadeClasse = 'warning';
                    } else {
                        validadeTexto = diasRestantes + ' dias';
                        validadeClasse = 'success';
                    }
                }

                html += `<tr>
                    <td class="ps-4"><span class="table-id">#${id}</span></td>
                    <td>
                        <div class="table-identity">
                            <strong>${nome}</strong>
                            <span class="table-subtext">${planoAtual === 'EMPRESARIAL' ? 'Conta premium' : (planoAtual === 'PROFISSIONAL' ? 'Plano profissional' : 'Plano básico')}</span>
                        </div>
                    </td>
                    <td>${email}</td>
                    <td>${telefone}</td>
                    <td>${badgePlano}</td>
                    <td>${badgeStatus}</td>
                    <td><span class="validity-badge ${validadeClasse}"><i class="fas fa-calendar-alt"></i>${escapeHtml(validadeTexto)}</span></td>
                    <td class="text-center">
                        <div class="action-group">
                            <button class="btn btn-sm btn-outline-info btn-restaurante-action" data-action="ver" data-id="${id}" title="Ver detalhes"><i class="fas fa-eye"></i></button>
                            <button class="btn btn-sm btn-outline-primary btn-restaurante-action" data-action="editar" data-id="${id}" title="Editar"><i class="fas fa-edit"></i></button>
                            <button class="btn btn-sm btn-outline-danger btn-restaurante-action" data-action="deletar" data-id="${id}" data-nome="${nomeAttr}" data-status="${statusAttr}" title="Excluir"><i class="fas fa-trash"></i></button>
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

            document.getElementById('listaTotalRestaurantes').textContent = restaurantes.length;
            document.getElementById('listaAtivos').textContent = restaurantes.filter(r => getRestaurantDisplayStatus(r) === 'ATIVO').length;
            document.getElementById('listaExpirando').textContent = expirando;
            document.getElementById('listaBloqueados').textContent = restaurantes.filter(r => getRestaurantDisplayStatus(r) === 'BLOQUEADO').length;
            document.getElementById('listaEnterprise').textContent = restaurantes.filter(r => getRestaurantPlanCode(r) === 'EMPRESARIAL').length;
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
        function deletarRestaurante(id, nome) {
            if (!hasPermission('manage_restaurants')) {
                showAlert('Sem permissão para excluir restaurantes.', 'warning');
                return;
            }

            if (!confirm(`Tem certeza que deseja excluir o restaurante "${nome}"? Esta ação não pode ser desfeita.`)) {
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
                        let html = '<option value="">Selecione um restaurante...</option>';
                        data.data.forEach(r => {
                            html += `<option value="${Number(r.id) || 0}">${escapeHtml(r.nome)}</option>`;
                        });
                        select.innerHTML = html;

                        const requestedRestaurantExists = requestedRestaurantId
                            && Array.from(select.options).some(option => option.value === requestedRestaurantId);
                        if (requestedRestaurantExists) {
                            select.value = requestedRestaurantId;
                            carregarUsuarios();
                        }
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

            if (!restauranteId) {
                tableState.usuarios.all = [];
                tableState.usuarios.filtered = [];
                tableState.usuarios.page = 1;
                document.getElementById('paginacaoUsuariosInfo').textContent = '1/1';
                document.getElementById('btnUsuariosPrev').disabled = true;
                document.getElementById('btnUsuariosNext').disabled = true;
                tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">Selecione um restaurante para ver os usuários</td></tr>';
                return;
            }

            // Definir ID no form oculto
            document.getElementById('usuario_restaurante_id').value = restauranteId;

            fetch('api/super_admin_usuarios_listar.php?restaurante_id=' + restauranteId, {
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
                    showAlert('Erro ao carregar usuários', 'danger');
                });
        }

        function aplicarFiltroUsuarios(resetPage = true) {
            const query = normalizeText(document.getElementById('filtroUsuarios').value);
            tableState.usuarios.filtered = tableState.usuarios.all.filter(u => {
                if (!query) {
                    return true;
                }

                const hay = [u.nome, u.email, u.perfil, u.ativo ? 'ativo' : 'inativo'].map(normalizeText).join(' ');
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
                tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-muted">Nenhum usuário encontrado</td></tr>';
            } else {
                let html = '';
                paged.items.forEach(u => {
                    const userId = Number(u.id) || 0;
                    const nome = escapeHtml(u.nome);
                    const email = escapeHtml(u.email);
                    const nomeAttr = escapeHtml(String(u.nome || ''));
                    const perfilBadge = u.perfil === 'ADMIN' ? '<span class="badge bg-primary">Admin</span>' :
                        u.perfil === 'COZINHA' ? '<span class="badge bg-warning">Cozinha</span>' :
                        '<span class="badge bg-info">Operador</span>';
                    const statusBadge = u.ativo ? '<span class="badge bg-success">Ativo</span>' : '<span class="badge bg-danger">Inativo</span>';
                    const turnoAtivo = Number(u.turno_ativo || 0) === 1;
                    const turnoTipo = escapeHtml((u.turno_tipo || ''));
                    const horaEntrada = escapeHtml((u.turno_hora_entrada || ''));
                    const turnoLabel = turnoAtivo
                        ? `<span class="badge bg-success">Ativo</span>${turnoTipo ? ' <small class="text-muted">(' + turnoTipo + ')</small>' : ''}${horaEntrada ? '<div class="text-muted small">Entrada ' + horaEntrada + '</div>' : ''}`
                        : '<span class="badge bg-secondary">Encerrado</span>';
                    const btnTurno = turnoAtivo
                        ? `<button class="btn btn-sm btn-outline-danger btn-turno-action" data-action="fechar_turno" data-id="${userId}" data-nome="${nomeAttr}" title="Encerrar turno"><i class="fas fa-stop"></i></button>`
                        : `<button class="btn btn-sm btn-outline-success btn-turno-action" data-action="abrir_turno" data-id="${userId}" data-nome="${nomeAttr}" title="Iniciar turno"><i class="fas fa-play"></i></button>`;

                    html += `<tr>
                        <td class="ps-4"><strong>#${userId}</strong></td>
                        <td>${nome}</td>
                        <td>${email}</td>
                        <td>${perfilBadge}</td>
                        <td>${statusBadge}</td>
                        <td>${turnoLabel}</td>
                        <td class="text-center">
                            <div class="action-group">
                                ${btnTurno}
                                <button class="btn btn-sm btn-outline-danger btn-usuario-action" data-action="deletar" data-id="${userId}" data-nome="${nomeAttr}" title="Inativar"><i class="fas fa-trash"></i></button>
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
                showAlert('Selecione um restaurante primeiro!', 'warning');
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
        function deletarUsuario(id, nome) {
            if (!hasPermission('manage_users')) {
                showAlert('Sem permissão para inativar usuários.', 'warning');
                return;
            }

            if (!confirm(`Tem certeza que deseja inativar o usuário "${nome}"?`)) {
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
            const ordered = applySorting(state.filtered, state);
            const paged = paginate(ordered, state.page, state.pageSize);
            state.page = paged.page;
            atualizarTabelaCompras(paged.items);
            document.getElementById('paginacaoComprasInfo').textContent = `${paged.page}/${paged.totalPages}`;
            document.getElementById('btnComprasPrev').disabled = paged.page <= 1;
            document.getElementById('btnComprasNext').disabled = paged.page >= paged.totalPages;
        }

        // Atualizar tabela de compras
        function atualizarTabelaCompras(compras) {
            const tbody = document.getElementById('tabelaCompras');

            if (compras.length === 0) {
                tbody.innerHTML = '<tr><td colspan="12" class="text-center py-4 text-muted">Nenhuma compra encontrada</td></tr>';
                return;
            }

            let html = '';
            compras.forEach(c => {
                const statusNorm = normalizePurchaseStatus(c.status);
                let statusBadge = statusNorm === 'APROVADO' ? '<span class="badge bg-success">Aprovado</span>' :
                    statusNorm === 'AGUARDANDO_APROVACAO' ? '<span class="badge bg-warning">Aguardando Aprovação</span>' :
                    '<span class="badge bg-danger">Rejeitado</span>';

                const dataRef = c.data_compra || c.criado_em || c.created_at;
                const dataFormatada = dataRef ? new Date(dataRef).toLocaleDateString('pt-BR') : '-';
                const ciclo = c.ciclo ? String(c.ciclo) : (String(c.metodo_pagamento || '').includes('-') ? String(c.metodo_pagamento).split('-').pop().trim() : 'MENSAL');
                const hashRaw = String(c.hash_arquivo || '').trim();
                const hashResumo = hashRaw ? (hashRaw.slice(0, 10) + '...' + hashRaw.slice(-6)) : '-';
                const comprovativoBtn = c.comprovativo_path ?
                    `<button type="button" class="btn btn-sm btn-outline-primary btn-compra-comprovativo" data-path="${encodeURIComponent(String(c.comprovativo_path))}" title="Ver comprovativo"><i class="fas fa-file-invoice"></i></button>` :
                    '<span class="text-muted">-</span>';

                let botoes = '';
                if (statusNorm === 'AGUARDANDO_APROVACAO' && hasPermission('approve_plans')) {
                    const compraId = Number(c.id) || 0;
                    botoes = `<div class="action-group">
                                <button class="btn btn-sm btn-success btn-compra-action" data-action="aprovar" data-id="${compraId}" title="Aprovar"><i class="fas fa-check"></i></button>
                                <button class="btn btn-sm btn-danger btn-compra-action" data-action="rejeitar" data-id="${compraId}" title="Rejeitar"><i class="fas fa-times"></i></button>
                              </div>`;
                } else {
                    botoes = '-';
                }

                html += `<tr>
                    <td class="ps-4"><strong>#${Number(c.id) || 0}</strong></td>
                    <td>${escapeHtml(c.restaurante_nome)}</td>
                    <td>${escapeHtml(c.plano_atual)}</td>
                    <td>${escapeHtml(c.plano_novo)}</td>
                    <td>${parseFloat(c.valor).toFixed(2)} MZN</td>
                    <td>${escapeHtml(c.metodo_pagamento)}</td>
                    <td>${escapeHtml(ciclo)}</td>
                    <td><code title="${escapeHtml(hashRaw || 'Hash indisponivel')}">${escapeHtml(hashResumo)}</code></td>
                    <td class="text-center">${comprovativoBtn}</td>
                    <td>${statusBadge}</td>
                    <td>${escapeHtml(dataFormatada)}</td>
                    <td class="text-center">${botoes}</td>
                </tr>`;
            });

            tbody.innerHTML = html;
        }

        // Atualizar estatísticas de compras
        function atualizarEstatisticasCompras(compras) {
            const total = compras.length;
            const pendentes = compras.filter(c => normalizePurchaseStatus(c.status) === 'AGUARDANDO_APROVACAO').length;
            const aprovados = compras.filter(c => normalizePurchaseStatus(c.status) === 'APROVADO').length;

            document.getElementById('totalCompras').textContent = total;
            document.getElementById('totalPendentes').textContent = pendentes;
            document.getElementById('totalAprovados').textContent = aprovados;

            // MRR: apenas compras aprovadas — normaliza cada valor para equivalente mensal
            // MRR/ARR representam assinaturas ativas; compras aprovadas antigas são histórico de faturação.
            const divisoresCiclo = {
                MENSAL: 1,
                TRIMESTRAL: 3,
                ANUAL: 12
            };
            const mrrHistorico = compras
                .filter(c => normalizePurchaseStatus(c.status) === 'APROVADO')
                .reduce((sum, c) => {
                    const valor = parseFloat(c.valor) || 0;
                    const divisor = divisoresCiclo[(c.ciclo || 'MENSAL').toUpperCase()] || 1;
                    return sum + (valor / divisor);
                }, 0);
            const mrrAtivo = Number(window.receitaRecorrenteAtiva);
            const usarReceitaAtiva = Number.isFinite(mrrAtivo);
            const mrr = usarReceitaAtiva ? mrrAtivo : mrrHistorico;
            const arrAtivo = Number(window.receitaAnualRecorrente);
            const arr = usarReceitaAtiva && Number.isFinite(arrAtivo) ? arrAtivo : (mrr * 12);
            document.getElementById('totalMRR').textContent = mrr.toLocaleString('pt-BR', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            }) + ' MZN';
            document.getElementById('totalARR').textContent = arr.toLocaleString('pt-BR', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            }) + ' MZN';

            const conversao = total > 0 ? (aprovados / total) * 100 : 0;
            const inadimplencia = total > 0 ? (pendentes / total) * 100 : 0;
            document.getElementById('totalConversao').textContent = conversao.toLocaleString('pt-BR', {
                minimumFractionDigits: 1,
                maximumFractionDigits: 1
            }) + '%';
            document.getElementById('totalInadimplencia').textContent = inadimplencia.toLocaleString('pt-BR', {
                minimumFractionDigits: 1,
                maximumFractionDigits: 1
            }) + '%';
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

        function renderSecurityTimeline(timeline) {
            const labels = (timeline || []).map(item => String(item.bucket || '').slice(11, 16));
            const values = (timeline || []).map(item => Number(item.total || 0));
            const ctx = document.getElementById('securityTimelineChart');
            if (!ctx) return;

            if (securityTimelineChart) {
                securityTimelineChart.destroy();
            }

            securityTimelineChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels,
                    datasets: [{
                        label: 'Ataques/eventos',
                        data: values,
                        borderColor: '#dc3545',
                        backgroundColor: 'rgba(220,53,69,0.12)',
                        fill: true,
                        tension: 0.25
                    }]
                },
                options: {
                    responsive: true,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true, ticks: { precision: 0, stepSize: 1 } } }
                }
            });
        }

        function renderSecurityPanel(data) {
            const stats = data?.stats || {};
            document.getElementById('secTotalEventos').textContent = Number(stats.total_eventos || 0);
            document.getElementById('secCriticos').textContent = Number(stats.criticos || 0);
            document.getElementById('secIpsBloqueados').textContent = Number(stats.ips_bloqueados_ativos || 0);
            document.getElementById('secRiskLabel').textContent = String(stats.risk_level || 'BAIXO');
            document.getElementById('secRiskScore').textContent = Number(stats.risk_score || 0);
            document.getElementById('secSqli').textContent = Number(stats.sqli || 0);
            document.getElementById('secXss').textContent = Number(stats.xss || 0);
            document.getElementById('secBrute').textContent = Number(stats.brute || 0);
            document.getElementById('secBots').textContent = Number(stats.bots || 0);
            document.getElementById('secTraversal').textContent = Number(stats.traversal || 0);
            const checklistSummary = data?.production_checklist_summary || {};
            document.getElementById('secCheckOk').textContent = Number(checklistSummary.ok || 0);
            document.getElementById('secCheckPendente').textContent = Number(checklistSummary.pendente || 0);
            document.getElementById('secCheckCritico').textContent = Number(checklistSummary.critico || 0);
            const offlineSummary = data?.offline_queue_summary || {};
            const centralRestEl = document.getElementById('offlineCentralRestaurantes');
            const centralDevEl = document.getElementById('offlineCentralDispositivos');
            const centralTotalEl = document.getElementById('offlineCentralTotal');
            if (centralRestEl) centralRestEl.textContent = String(Number(offlineSummary.restaurantes_com_fila || 0));
            if (centralDevEl) centralDevEl.textContent = String(Number(offlineSummary.dispositivos_com_fila || 0));
            if (centralTotalEl) centralTotalEl.textContent = String(Number(offlineSummary.total_count || 0));

            const readiness = data?.readiness_95 || {};
            const governance = data?.governance || {};
            const readyScore = Number(readiness.score || 0);
            const readyStatus = String(readiness.status || 'PENDENTE');
            const readyStatusEl = document.getElementById('secReadyStatus');
            const readyScoreEl = document.getElementById('secReadyScore');
            const govOwnerEl = document.getElementById('secGovOwner');
            const govUpdatedEl = document.getElementById('secGovUpdatedAt');
            if (readyScoreEl) readyScoreEl.textContent = String(readyScore);
            if (govOwnerEl) govOwnerEl.textContent = String(governance.owner || '--');
            if (govUpdatedEl) govUpdatedEl.textContent = String(governance.updated_at || '--');
            if (readyStatusEl) {
                readyStatusEl.textContent = readyStatus;
                readyStatusEl.className = 'badge ' + (readyStatus === 'PRONTO_95+' ? 'bg-success' : (readyStatus === 'PARCIAL' ? 'bg-warning text-dark' : 'bg-danger'));
            }

            const govRows = Array.isArray(governance.items) ? governance.items : [];
            const govBody = document.getElementById('tabelaGovernanceCadencia');
            if (govBody) {
                govBody.innerHTML = govRows.map((row) => {
                    const overdue = Boolean(row?.overdue);
                    return `
                        <tr>
                            <td>${escapeHtml(String(row?.name || '-'))}</td>
                            <td>${escapeHtml(String(row?.last_at || '-'))}</td>
                            <td>${Number(row?.cadence_days || 0)}d</td>
                            <td>${row?.days_since == null ? '-' : Number(row.days_since)}</td>
                            <td><span class="badge ${overdue ? 'bg-danger' : 'bg-success'}">${overdue ? 'OVERDUE' : 'OK'}</span></td>
                        </tr>
                    `;
                }).join('') || '<tr><td colspan="5" class="security-table-empty">Sem dados de governanca</td></tr>';
            }

            renderSecurityTimeline(data?.timeline || []);

            const topIpsBody = document.getElementById('tabelaTopIps');
            topIpsBody.innerHTML = (data?.top_ips || []).map(row => `
                <tr>
                    <td>${escapeHtml(row.ip || '-')}</td>
                    <td>${Number(row.ataques || 0)}</td>
                    <td class="text-end"><button class="btn btn-sm btn-outline-danger btn-block-ip" data-ip="${escapeHtml(row.ip || '')}">Bloquear</button></td>
                </tr>
            `).join('') || '<tr><td colspan="3" class="security-table-empty">Sem atividade</td></tr>';

            const blockedBody = document.getElementById('tabelaBlockedIps');
            blockedBody.innerHTML = (data?.blocked_ips || []).map(row => `
                <tr>
                    <td>${escapeHtml(row.ip || '-')}</td>
                    <td>${escapeHtml(row.bloqueio_tipo || '-')}</td>
                    <td>${escapeHtml(row.origem || '-')}</td>
                    <td class="text-end"><button class="btn btn-sm btn-outline-success btn-unblock-ip" data-ip="${escapeHtml(row.ip || '')}">Desbloquear</button></td>
                </tr>
            `).join('') || '<tr><td colspan="4" class="security-table-empty">Nenhum IP bloqueado</td></tr>';

            const eventsBody = document.getElementById('tabelaSecurityEvents');
            eventsBody.innerHTML = (data?.recent_events || []).map(row => `
                <tr>
                    <td>#${Number(row.id || 0)}</td>
                    <td>${escapeHtml(row.created_at || '-')}</td>
                    <td><span class="badge bg-${row.severity === 'CRITICAL' ? 'danger' : (row.severity === 'HIGH' ? 'warning' : 'secondary')}">${escapeHtml(row.severity || '-')}</span></td>
                    <td>${escapeHtml(row.attack_type || '-')}</td>
                    <td>${escapeHtml(row.ip || '-')}</td>
                    <td><small>${escapeHtml(row.endpoint || '-')}</small></td>
                </tr>
            `).join('') || '<tr><td colspan="6" class="security-table-empty">Sem eventos recentes</td></tr>';

            const checklistBody = document.getElementById('tabelaSecurityChecklist');
            const statusBadgeMap = {
                ok: 'bg-success',
                pendente: 'bg-warning text-dark',
                critico: 'bg-danger'
            };
            const statusLabelMap = {
                ok: 'OK',
                pendente: 'PENDENTE',
                critico: 'CRITICO'
            };
            checklistBody.innerHTML = (data?.production_checklist || []).map((row) => {
                const statusKey = String(row.status || 'pendente').toLowerCase();
                const badgeClass = statusBadgeMap[statusKey] || 'bg-secondary';
                const label = statusLabelMap[statusKey] || statusKey.toUpperCase();
                return `
                <tr class="security-checklist-row">
                    <td>${escapeHtml(row.label || '-')}</td>
                    <td><span class="badge ${badgeClass}">${label}</span></td>
                    <td><small>${escapeHtml(row.detail || '-')}</small></td>
                </tr>`;
            }).join('') || '<tr><td colspan="3" class="security-table-empty">Checklist indisponivel</td></tr>';

            const stampEl = document.getElementById('securityLastUpdate');
            if (stampEl) {
                const now = new Date();
                stampEl.textContent = now.toLocaleTimeString('pt-PT', { hour12: false });
            }
            const ipEl = document.getElementById('securityCurrentIp');
            if (ipEl) {
                ipEl.textContent = String(data?.current_client_ip || '--');
            }
        }

        function controlarAutoRefreshSeguranca() {
            if (securityAutoRefreshHandle) {
                clearInterval(securityAutoRefreshHandle);
                securityAutoRefreshHandle = null;
            }

            if (secaoAtual !== 'seguranca' || !canAccessSecuritySection()) {
                return;
            }

            securityAutoRefreshHandle = setInterval(() => {
                if (secaoAtual === 'seguranca') {
                    carregarPainelSeguranca();
                    carregarPainelFilaOffline();
                }
            }, 10000);
        }

        function setOfflineQueueStatus(message) {
            const el = document.getElementById('offlineQueueStatus');
            if (el) el.textContent = String(message || '');
        }

        function preencherOfflineStats(stats = {}) {
            const pending = Number(stats.pending || 0);
            const done = Number(stats.done || 0);
            const failed = Number(stats.failed || 0);
            const attempts = Number(stats.attempts || 0);
            const pendingEl = document.getElementById('offlinePendingCount');
            const doneEl = document.getElementById('offlineDoneCount');
            const failedEl = document.getElementById('offlineFailedCount');
            const attemptsEl = document.getElementById('offlineAttemptsCount');
            if (pendingEl) pendingEl.textContent = String(pending);
            if (doneEl) doneEl.textContent = String(done);
            if (failedEl) failedEl.textContent = String(failed);
            if (attemptsEl) attemptsEl.textContent = String(attempts);
        }

        function carregarPainelFilaOffline() {
            if (!canAccessSecuritySection() || secaoAtual !== 'seguranca') return;
            if (typeof OfflineSync === 'undefined' || !OfflineSync.getQueueStats) {
                setOfflineQueueStatus('Modulo offline indisponivel nesta pagina.');
                return;
            }

            OfflineSync.getQueueStats()
                .then((stats) => {
                    preencherOfflineStats(stats || {});
                    const pend = Number(stats?.pending || 0);
                    setOfflineQueueStatus(pend > 0
                        ? ('Fila offline com ' + pend + ' operacao(oes) pendente(s).')
                        : 'Fila offline vazia.');
                })
                .catch((err) => {
                    setOfflineQueueStatus('Erro ao ler fila offline: ' + (err?.message || 'desconhecido'));
                });
        }

        function sincronizarFilaOfflineAgora() {
            if (typeof OfflineSync === 'undefined' || !OfflineSync.syncQueue) {
                showAlert('Modulo offline nao disponivel.', 'warning');
                return;
            }
            setOfflineQueueStatus('Sincronizando fila offline...');
            OfflineSync.syncQueue()
                .then((res) => {
                    const synced = Number(res?.synced || 0);
                    const pending = Number(res?.pending || 0);
                    showAlert('Sincronizacao concluida. Sincronizadas: ' + synced + ' | Pendentes: ' + pending, 'success');
                    carregarPainelFilaOffline();
                })
                .catch((err) => {
                    showAlert('Erro ao sincronizar fila offline: ' + (err?.message || 'desconhecido'), 'danger');
                    carregarPainelFilaOffline();
                });
        }

        function priorityBadgeClass(priority) {
            if (priority === 'URGENTE') return 'danger';
            if (priority === 'ALTA') return 'warning';
            if (priority === 'MEDIA') return 'info';
            return 'secondary';
        }

        function statusBadgeClassSupport(status) {
            if (status === 'ABERTO') return 'warning';
            if (status === 'EM_ANDAMENTO') return 'primary';
            if (status === 'RESOLVIDO') return 'success';
            return 'secondary';
        }

        function statusLabelSupport(status) {
            if (status === 'EM_ANDAMENTO') return 'Em andamento';
            if (status === 'RESOLVIDO') return 'Resolvido';
            if (status === 'FECHADO') return 'Fechado';
            return 'Aberto';
        }

        function slaBadgeClassSupport(state) {
            const normalized = String(state || '').toUpperCase();
            if (normalized === 'VENCIDO') return 'danger';
            if (normalized === 'EM_RISCO') return 'warning text-dark';
            if (normalized === 'CUMPRIDO') return 'success';
            return 'info text-dark';
        }

        function formatSupportNow() {
            const now = new Date();
            const yyyy = now.getFullYear();
            const mm = String(now.getMonth() + 1).padStart(2, '0');
            const dd = String(now.getDate()).padStart(2, '0');
            const hh = String(now.getHours()).padStart(2, '0');
            const mi = String(now.getMinutes()).padStart(2, '0');
            return `${yyyy}-${mm}-${dd} ${hh}:${mi}`;
        }

        function applySupportTicketRowStatus(row, status) {
            if (!row) return;
            const normalized = String(status || 'ABERTO').toUpperCase();
            row.dataset.status = normalized;

            const badge = row.querySelector('.support-ticket-status-badge');
            if (badge) {
                badge.className = `badge support-ticket-status-badge bg-${statusBadgeClassSupport(normalized)}`;
                badge.textContent = statusLabelSupport(normalized);
            }

            const updatedAt = row.querySelector('.support-ticket-updated-at');
            if (updatedAt) {
                updatedAt.textContent = formatSupportNow();
            }

            row.querySelectorAll('.btn-support-action').forEach((button) => {
                const buttonStatus = String(button.dataset.status || '').toUpperCase();
                button.disabled = buttonStatus === normalized;
                button.classList.toggle('active', buttonStatus === normalized);
            });
        }

        function carregarSupportTickets() {
            if (!canAccessSupportSection()) {
                return;
            }

            const tbody = document.getElementById('tabelaSupportTickets');
            const adminChatModalEl = document.getElementById('modalSupportChatAdmin');
            const q = document.getElementById('filtroSupportTickets')?.value || '';
            const status = document.getElementById('filtroSupportStatus')?.value || '';
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i>Carregando...</td></tr>';
            }

            const params = new URLSearchParams();
            if (q) params.set('q', q);
            if (status) params.set('status', status);

            apiFetch('api/super_admin_support_tickets.php?' + params.toString())
                .then(parseJsonSafe)
                .then((data) => {
                    if (!data.success) {
                        throw new Error(data.message || 'Falha ao carregar tickets');
                    }

                    const stats = data.stats || {};
                    document.getElementById('supportStatTotal').textContent = String(stats.total || 0);
                    document.getElementById('supportStatAbertos').textContent = String(stats.abertos || 0);
                    document.getElementById('supportStatUrgentes').textContent = String(stats.urgentes || 0);
                    document.getElementById('supportStatResolvidos').textContent = String(stats.resolvidos || 0);
                    document.getElementById('supportStatSlaOk').textContent = String(stats.sla_no_prazo || 0);
                    document.getElementById('supportStatSlaRisco').textContent = String(stats.sla_em_risco || 0);
                    document.getElementById('supportStatSlaVencido').textContent = String(stats.sla_vencidos || 0);

                    const rows = Array.isArray(data.data) ? data.data : [];
                    if (!tbody) return;
                    if (!rows.length) {
                        tbody.innerHTML = '<tr><td colspan="10" class="text-center py-4 text-muted">Nenhum ticket encontrado.</td></tr>';
                        return;
                    }

                    tbody.innerHTML = rows.map((ticket) => `
                        <tr data-ticket-id="${Number(ticket.id || 0)}" data-status="${escapeHtml(ticket.status || '')}">
                            <td class="ps-4">#${Number(ticket.id || 0)}</td>
                            <td>
                                <div class="fw-semibold">${escapeHtml(ticket.restaurante_nome || '')}</div>
                                <div class="small text-muted">${escapeHtml(ticket.restaurante_email || '')}</div>
                            </td>
                            <td>
                                <div class="fw-semibold">${escapeHtml(ticket.assunto || '')}</div>
                                <div class="small text-muted">${escapeHtml((ticket.descricao || '').slice(0, 120))}</div>
                            </td>
                            <td><span class="badge bg-${priorityBadgeClass(ticket.prioridade)}">${escapeHtml(ticket.prioridade_label || ticket.prioridade || '')}</span></td>
                            <td><span class="badge bg-${slaBadgeClassSupport(ticket.sla?.state)}">${escapeHtml(ticket.sla?.label || 'Dentro do SLA')}</span><div class="small text-muted mt-1">Meta: ${Number(ticket.sla?.target_hours || 0)}h</div></td>
                            <td><span class="badge support-ticket-status-badge bg-${statusBadgeClassSupport(ticket.status)}">${escapeHtml(ticket.status_label || ticket.status || '')}</span></td>
                            <td><span class="support-ticket-updated-at">${escapeHtml(String(ticket.atualizado_em || ticket.criado_em || '').replace('T', ' ').slice(0, 16))}</span></td>
                            <td>
                                <textarea class="form-control form-control-sm support-response-input" rows="2" data-id="${Number(ticket.id || 0)}" placeholder="Resposta breve ao cliente">${escapeHtml(ticket.resposta_admin || '')}</textarea>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-primary btn-support-open-chat-admin" data-id="${Number(ticket.id || 0)}">Abrir chat</button>
                            </td>
                            <td class="text-center">
                                <div class="d-flex flex-column gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-primary btn-support-action ${ticket.status === 'EM_ANDAMENTO' ? 'active' : ''}" data-id="${Number(ticket.id || 0)}" data-status="EM_ANDAMENTO" ${ticket.status === 'EM_ANDAMENTO' ? 'disabled' : ''}>Em andamento</button>
                                    <button type="button" class="btn btn-sm btn-outline-success btn-support-action ${ticket.status === 'RESOLVIDO' ? 'active' : ''}" data-id="${Number(ticket.id || 0)}" data-status="RESOLVIDO" ${ticket.status === 'RESOLVIDO' ? 'disabled' : ''}>Resolver</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary btn-support-action ${ticket.status === 'FECHADO' ? 'active' : ''}" data-id="${Number(ticket.id || 0)}" data-status="FECHADO" ${ticket.status === 'FECHADO' ? 'disabled' : ''}>Fechar</button>
                                </div>
                            </td>
                        </tr>
                    `).join('');
                })
                .catch((err) => {
                    if (tbody) {
                        tbody.innerHTML = `<tr><td colspan="10" class="text-center py-4 text-danger">${escapeHtml(err.message || 'Falha ao carregar tickets')}</td></tr>`;
                    }
                });
        }

        let adminSupportChatCurrentTicketId = 0;
        let adminSupportChatTimer = null;
        const adminSupportChatListEl = document.getElementById('adminSupportChatList');
        const adminSupportChatFeedbackEl = document.getElementById('adminSupportChatFeedback');

        function renderAdminSupportChat(messages) {
            if (!adminSupportChatListEl) return;
            if (!Array.isArray(messages) || !messages.length) {
                adminSupportChatListEl.innerHTML = '<div class="small text-muted p-2">Sem mensagens.</div>';
                return;
            }
            adminSupportChatListEl.innerHTML = messages.map((m) => {
                const tipo = String(m.author_tipo || '');
                const isSupport = tipo === 'SUPORTE';
                const isSystem = tipo === 'SISTEMA';
                const who = isSystem ? 'Sistema' : (isSupport ? 'Suporte' : 'Cliente');
                const txt = m.mensagem ? `<div class="small mt-1">${escapeHtml(m.mensagem)}</div>` : '';
                const anexo = m.anexo_url ? `<a class="small" href="${escapeHtml(m.anexo_url)}" target="_blank" rel="noopener">Anexo: ${escapeHtml(m.anexo_nome || 'arquivo')}</a>` : '';
                return `
                    <div class="border rounded p-2 mb-2 ${isSystem ? 'bg-light border-info' : (isSupport ? 'bg-light' : 'bg-white')}">
                        <div class="d-flex justify-content-between align-items-center">
                            <strong class="small">${who}</strong>
                            <span class="small text-muted">${escapeHtml(String(m.criado_em || '').replace('T', ' ').slice(0, 16))}</span>
                        </div>
                        ${txt}
                        ${anexo}
                    </div>
                `;
            }).join('');
            adminSupportChatListEl.scrollTop = adminSupportChatListEl.scrollHeight;
        }

        function carregarAdminSupportChat(ticketId) {
            return apiFetch('api/super_admin_support_ticket_thread.php?ticket_id=' + encodeURIComponent(ticketId))
                .then(parseJsonSafe)
                .then((data) => {
                    if (!data.success) {
                        throw new Error(data.message || 'Falha ao carregar chat');
                    }
                    renderAdminSupportChat(data.messages || []);
                })
                .catch((err) => {
                    if (adminSupportChatListEl) {
                        adminSupportChatListEl.innerHTML = `<div class="small text-danger p-2">${escapeHtml(err.message || 'Falha ao carregar chat')}</div>`;
                    }
                });
        }

        function openAdminSupportChat(ticketId) {
            adminSupportChatCurrentTicketId = Number(ticketId || 0);
            if (!adminSupportChatCurrentTicketId) return;
            document.getElementById('adminSupportChatTicketId').value = String(adminSupportChatCurrentTicketId);
            document.getElementById('adminSupportChatTicketLabel').textContent = '#' + adminSupportChatCurrentTicketId;
            if (adminSupportChatFeedbackEl) adminSupportChatFeedbackEl.textContent = '';
            carregarAdminSupportChat(adminSupportChatCurrentTicketId);
            if (adminSupportChatTimer) clearInterval(adminSupportChatTimer);
            adminSupportChatTimer = setInterval(() => carregarAdminSupportChat(adminSupportChatCurrentTicketId), 4000);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalSupportChatAdmin')).show();
        }

        document.getElementById('tabelaSupportTickets')?.addEventListener('click', function(e) {
            const chatBtn = e.target.closest('.btn-support-open-chat-admin');
            if (chatBtn) {
                openAdminSupportChat(Number(chatBtn.dataset.id || 0));
                return;
            }

            const btn = e.target.closest('.btn-support-action');
            if (!btn) return;

            const ticketId = Number(btn.dataset.id || 0);
            const status = btn.dataset.status || 'EM_ANDAMENTO';
            const responseInput = document.querySelector(`.support-response-input[data-id="${ticketId}"]`);
            const resposta = responseInput ? responseInput.value : '';
            const row = btn.closest('tr');

            btn.disabled = true;

            apiFetch('api/super_admin_support_ticket_update.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    ticket_id: ticketId,
                    status: status,
                    resposta: resposta
                })
            })
                .then(parseJsonSafe)
                .then((data) => {
                    if (!data.success) {
                        throw new Error(data.message || 'Falha ao atualizar ticket');
                    }
                    applySupportTicketRowStatus(row, status);
                    showAlert(data.message || 'Ticket atualizado.', 'success');
                    if (adminSupportChatCurrentTicketId === ticketId) {
                        carregarAdminSupportChat(ticketId);
                    }
                    setTimeout(() => carregarSupportTickets(), 250);
                })
                .catch((err) => {
                    btn.disabled = false;
                    showAlert(err.message || 'Falha ao atualizar ticket', 'danger');
                });
        });

        document.getElementById('formSupportChatAdmin')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const ticketId = Number(document.getElementById('adminSupportChatTicketId')?.value || 0);
            if (!ticketId) return;
            if (adminSupportChatFeedbackEl) adminSupportChatFeedbackEl.textContent = 'Enviando...';
            const fd = new FormData(e.currentTarget);
            apiFetch('api/super_admin_support_ticket_message_send.php', {
                method: 'POST',
                body: fd
            })
                .then(parseJsonSafe)
                .then((data) => {
                    if (!data.success) {
                        throw new Error(data.message || 'Falha ao enviar mensagem');
                    }
                    if (adminSupportChatFeedbackEl) adminSupportChatFeedbackEl.textContent = 'Mensagem enviada.';
                    document.getElementById('adminSupportChatMensagem').value = '';
                    document.getElementById('adminSupportChatAnexo').value = '';
                    return Promise.all([carregarAdminSupportChat(ticketId), Promise.resolve().then(() => carregarSupportTickets())]);
                })
                .catch((err) => {
                    if (adminSupportChatFeedbackEl) adminSupportChatFeedbackEl.textContent = err.message || 'Falha ao enviar.';
                });
        });

        document.getElementById('modalSupportChatAdmin')?.addEventListener('hidden.bs.modal', function() {
            if (adminSupportChatTimer) {
                clearInterval(adminSupportChatTimer);
                adminSupportChatTimer = null;
            }
        });

        function carregarPainelSeguranca() {
            if (!canAccessSecuritySection()) return;
            const hours = document.getElementById('securityWindowHours')?.value || '24';

            const basePath = window.location.pathname.replace(/\/[^/]*$/, '/');
            const candidates = [
                'api/super_admin_security_dashboard.php?hours=' + encodeURIComponent(hours),
                basePath + 'api/super_admin_security_dashboard.php?hours=' + encodeURIComponent(hours),
                '/V00/src/public/api/super_admin_security_dashboard.php?hours=' + encodeURIComponent(hours)
            ];

            const tentar = (idx = 0) => {
                if (idx >= candidates.length) {
                    throw new Error('Falha de conexao com API de seguranca.');
                }
                return apiFetch(candidates[idx], { method: 'GET' })
                    .then(parseJsonSafe)
                    .catch((err) => {
                        if (/Failed to fetch|NetworkError|conexao/i.test(String(err && err.message || ''))) {
                            return tentar(idx + 1);
                        }
                        throw err;
                    });
            };

            Promise.resolve()
            .then(() => tentar(0))
            .then((data) => {
                if (!data.success) {
                    throw new Error(data.error ? (data.message + ' (' + data.error + ')') : (data.message || 'Falha ao carregar painel de segurança.'));
                }
                renderSecurityPanel(data.data || {});
                carregarPainelFilaOffline();
                updateUrlState();
            })
            .catch((err) => {
                showAlert('Erro no painel de segurança: ' + err.message, 'danger');
            });
        }

        function executarAcaoIp(action, ip, tipo = 'TEMPORARIO') {
            if (!ip) return;
            const payload = JSON.stringify({
                action,
                ip,
                tipo,
                duracao_min: 60,
                motivo: action === 'BLOCK' ? 'Bloqueio manual via painel Super Admin' : 'Desbloqueio manual via painel Super Admin'
            });

            const basePath = window.location.pathname.replace(/\/[^/]*$/, '/');
            const candidates = [
                'api/super_admin_security_ip_action.php',
                basePath + 'api/super_admin_security_ip_action.php',
                '/V00/src/public/api/super_admin_security_ip_action.php'
            ];

            const tentar = (idx = 0) => {
                if (idx >= candidates.length) {
                    throw new Error('Falha de conexao com API de acao de IP.');
                }
                return apiFetch(candidates[idx], {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: payload
                })
                .then(parseJsonSafe)
                .catch((err) => {
                    if (/Failed to fetch|NetworkError|conexao/i.test(String(err && err.message || ''))) {
                        return tentar(idx + 1);
                    }
                    throw err;
                });
            };

            Promise.resolve()
            .then(() => tentar(0))
            .then((data) => {
                if (!data.success) throw new Error(data.message || 'Ação não executada.');
                showAlert(data.message || 'Ação aplicada com sucesso.', 'success');
                carregarPainelSeguranca();
            })
            .catch((err) => showAlert('Erro ao aplicar ação de IP: ' + err.message, 'danger'));
        }

        function bloquearIpManual() {
            const ip = (document.getElementById('securityIpManual')?.value || '').trim();
            const tipo = document.getElementById('securityTipoBloqueio')?.value || 'TEMPORARIO';
            if (!ip) {
                showAlert('Informe um IP para bloquear.', 'warning');
                return;
            }
            executarAcaoIp('BLOCK', ip, tipo);
        }

        (() => {
            const logoutLinks = document.querySelectorAll('a[href="logout.php"]');
            if (!logoutLinks.length) {
                return;
            }

            const markOffline = () => {
                const url = 'api/online_offline.php';

                try {
                    if (navigator.sendBeacon) {
                        const data = new FormData();
                        data.append('source', 'logout');
                        navigator.sendBeacon(url, data);
                        return;
                    }
                } catch (e) {}

                try {
                    fetch(url, {
                        method: 'POST',
                        credentials: 'same-origin',
                        keepalive: true,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                } catch (e) {}
            };

            logoutLinks.forEach((link) => {
                link.addEventListener('click', markOffline, {
                    passive: true
                });
            });
        })();
    
