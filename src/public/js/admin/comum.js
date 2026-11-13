// Painel Super Admin - Configuracao do servidor, estado das tabelas, utilitarios, permissoes, pedidos a API, alertas e menu lateral.
// Parte de admin.php; carregado por ordem com os outros ficheiros de js/admin/ (ver admin.php).

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


        // ==================== RESTAURANTES ====================

        // Carregar restaurantes ao iniciar
