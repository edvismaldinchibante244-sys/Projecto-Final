// Valores do servidor: <script type="application/json" id="cfg-sidebar"> na pagina.
var __NIVORA_SIDEBAR = JSON.parse(document.getElementById('cfg-sidebar').textContent);
    (function initSidebarEnhancements() {
        const body = document.body;
        const sidebar = document.querySelector('.sidebar.sidebar-unified');
        const collapseBtn = document.getElementById('sidebarCollapseBtn');
        let backdrop = document.querySelector('.sidebar-mobile-backdrop');
        let sidebarToggleButtons = Array.from(document.querySelectorAll('#sidebarToggleBtn, #menuToggleBtn, .sidebar-mobile-toggle'));
        const mobileQuery = window.matchMedia('(max-width: 1200px)');
        const STORAGE_KEY = 'sidebar.desktopCollapsed';

        if (!backdrop) {
            backdrop = document.createElement('div');
            backdrop.className = 'sidebar-mobile-backdrop';
            backdrop.setAttribute('aria-hidden', 'true');
            body.appendChild(backdrop);
        }

        const readDesktopCollapsed = () => {
            try {
                return localStorage.getItem(STORAGE_KEY) === '1';
            } catch (e) {
                return false;
            }
        };

        const writeDesktopCollapsed = (collapsed) => {
            try {
                localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
            } catch (e) {
                // Ignore storage errors.
            }
        };

        const updateToggleLabels = (expanded) => {
            sidebarToggleButtons.forEach((button) => {
                button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                const label = expanded ? 'Esconder menu' : 'Mostrar menu';
                button.setAttribute('aria-label', label);
                button.setAttribute('title', label);
            });
            if (collapseBtn) {
                collapseBtn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                collapseBtn.setAttribute('title', expanded ? 'Esconder menu' : 'Mostrar menu');
                collapseBtn.setAttribute('aria-label', expanded ? 'Esconder menu' : 'Mostrar menu');
            }
        };

        const setDesktopCollapsed = (collapsed) => {
            body.classList.toggle('sidebar-desktop-collapsed', !!collapsed);
            writeDesktopCollapsed(!!collapsed);
            updateToggleLabels(!collapsed);
        };

        const resetLegacySidebarState = () => {
            if (!sidebar) {
                return;
            }

            sidebar.classList.remove('hide-mobile', 'sidebar-hidden');
            sidebar.style.removeProperty('display');
            sidebar.style.removeProperty('left');
            sidebar.style.removeProperty('top');
            sidebar.style.removeProperty('transform');
        };

        const placeToggleInsideTopBar = () => {
            const topBar = document.querySelector('.top-bar');
            const mainContent = document.querySelector('.main-content');

            if (!sidebarToggleButtons.length) {
                const generatedButton = document.createElement('button');
                generatedButton.type = 'button';
                generatedButton.id = 'sidebarAutoToggleBtn';
                generatedButton.className = 'sidebar-mobile-toggle';
                generatedButton.setAttribute('aria-label', 'Mostrar menu');
                generatedButton.setAttribute('title', 'Mostrar menu');
                generatedButton.innerHTML = '<i class="fas fa-bars"></i>';
                sidebarToggleButtons = [generatedButton];
                (topBar || mainContent || body).prepend(generatedButton);
                generatedButton.setAttribute('aria-expanded', 'false');
                generatedButton.addEventListener('click', toggleSidebarEvent, true);
            }

            if (!topBar) {
                return;
            }

            const primaryButton = sidebarToggleButtons[0];
            if (!primaryButton) {
                return;
            }

            let targetContainer = topBar.querySelector('.top-bar-main, .sidebar-topbar-main');
            if (!targetContainer) {
                targetContainer = document.createElement('div');
                targetContainer.className = 'sidebar-topbar-main';

                const firstNonToggleChild = Array.from(topBar.children).find((child) => !sidebarToggleButtons.includes(child));
                if (firstNonToggleChild) {
                    topBar.insertBefore(targetContainer, firstNonToggleChild);
                    targetContainer.appendChild(firstNonToggleChild);
                } else {
                    topBar.prepend(targetContainer);
                }
            }

            if (primaryButton.parentElement !== targetContainer) {
                targetContainer.prepend(primaryButton);
            }
        };

        const closeMobileSidebar = () => {
            body.classList.remove('sidebar-mobile-open');
            resetLegacySidebarState();
            updateToggleLabels(false);
        };

        const openMobileSidebar = () => {
            if (!mobileQuery.matches) {
                return;
            }

            body.classList.add('sidebar-mobile-open');
            resetLegacySidebarState();
            updateToggleLabels(true);
        };

        const syncSidebarLayout = () => {
            resetLegacySidebarState();
            if (mobileQuery.matches) {
                body.classList.remove('sidebar-desktop-collapsed');
                if (!body.classList.contains('sidebar-mobile-open')) {
                    updateToggleLabels(false);
                }
                return;
            }

            body.classList.remove('sidebar-mobile-open');
            setDesktopCollapsed(readDesktopCollapsed());
        };

        const toggleSidebarEvent = (event) => {
            event.preventDefault();
            event.stopPropagation();
            if (typeof event.stopImmediatePropagation === 'function') {
                event.stopImmediatePropagation();
            }

            if (mobileQuery.matches) {
                if (body.classList.contains('sidebar-mobile-open')) {
                    closeMobileSidebar();
                } else {
                    openMobileSidebar();
                }
                return;
            }

            setDesktopCollapsed(!body.classList.contains('sidebar-desktop-collapsed'));
        };

        sidebarToggleButtons.forEach((button) => {
            button.setAttribute('aria-expanded', 'false');
            button.addEventListener('click', toggleSidebarEvent, true);
        });

        if (collapseBtn) {
            collapseBtn.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                if (mobileQuery.matches) {
                    closeMobileSidebar();
                    return;
                }
                setDesktopCollapsed(true);
            });
        }

        if (backdrop) {
            backdrop.addEventListener('click', closeMobileSidebar);
        }

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                if (mobileQuery.matches) {
                    closeMobileSidebar();
                } else if (body.classList.contains('sidebar-desktop-collapsed')) {
                    setDesktopCollapsed(false);
                }
            }
        });

        document.addEventListener('click', (event) => {
            if (!mobileQuery.matches || !body.classList.contains('sidebar-mobile-open') || !sidebar) {
                return;
            }

            if (sidebar.contains(event.target)) {
                return;
            }

            if (sidebarToggleButtons.some((button) => button.contains(event.target))) {
                return;
            }

            closeMobileSidebar();
        });

        if (sidebar) {
            sidebar.querySelectorAll('a').forEach((link) => {
                link.addEventListener('click', () => {
                    closeMobileSidebar();
                });
            });
        }

        placeToggleInsideTopBar();
        syncSidebarLayout();
        window.addEventListener('resize', syncSidebarLayout);
        window.addEventListener('load', syncSidebarLayout);
        window.addEventListener('load', placeToggleInsideTopBar);
        setTimeout(() => {
            placeToggleInsideTopBar();
            syncSidebarLayout();
        }, 120);
        setTimeout(() => {
            placeToggleInsideTopBar();
            syncSidebarLayout();
        }, 420);
        setTimeout(() => {
            placeToggleInsideTopBar();
            syncSidebarLayout();
        }, 900);

        const restauranteId = __NIVORA_SIDEBAR.v0;
        const perfilAtual = __NIVORA_SIDEBAR.v1;
        const isEmpresarialSidebar = __NIVORA_SIDEBAR.v2;
        const menuMores = document.querySelectorAll('[data-menu-more]');
        menuMores.forEach((detailsEl) => {
            const key = 'sidebar.menuMore.' + (detailsEl.id || 'default');
            try {
                const saved = localStorage.getItem(key);
                if (saved === '1') detailsEl.open = true;
                if (saved === '0') detailsEl.open = false;
            } catch (e) {
                // Ignore storage errors (private mode, blocked storage, etc.)
            }

            detailsEl.addEventListener('toggle', () => {
                try {
                    localStorage.setItem(key, detailsEl.open ? '1' : '0');
                } catch (e) {
                    // Ignore storage errors.
                }
            });
        });

        const updateCounter = (type, value) => {
            const badge = document.querySelector('[data-counter-badge="' + type + '"]');
            if (!badge) return;

            const num = Number(value || 0);
            badge.textContent = String(num);
            badge.classList.toggle('is-empty', num <= 0);
        };

        const reqOpts = {
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        };
        const buildApiCandidates = (endpoint) => {
            const clean = String(endpoint || '').replace(/^\/+/, '');
            const basePath = window.location.pathname.replace(/\/[^/]*$/, '/');
            const list = [
                clean,
                basePath + clean,
                '/V00/src/public/' + clean
            ];
            return [...new Set(list)];
        };
        const fetchApiWithFallback = async (endpoint, options) => {
            const candidates = buildApiCandidates(endpoint);
            let lastError = null;
            let lastResponse = null;
            const xhrFallback = (url, opts = {}) => new Promise((resolve, reject) => {
                try {
                    const xhr = new XMLHttpRequest();
                    xhr.open(opts.method || 'GET', url, true);
                    xhr.withCredentials = true;
                    const headers = opts.headers || {};
                    Object.keys(headers).forEach((k) => xhr.setRequestHeader(k, headers[k]));
                    xhr.onload = () => {
                        const body = xhr.responseText || '';
                        resolve({
                            ok: xhr.status >= 200 && xhr.status < 300,
                            status: xhr.status,
                            statusText: xhr.statusText || '',
                            text: async () => body,
                            json: async () => JSON.parse(body),
                            headers: {
                                entries: () => []
                            }
                        });
                    };
                    xhr.onerror = () => reject(new Error('XHR network error'));
                    xhr.send(opts.body || null);
                } catch (e) {
                    reject(e);
                }
            });
            for (let i = 0; i < candidates.length; i++) {
                try {
                    const response = await fetch(candidates[i], options);
                    if (response.ok) {
                        return response;
                    }
                    if (response.status === 404) {
                        lastResponse = response;
                        continue;
                    }
                    return response;
                } catch (err) {
                    lastError = err;
                    if (!/Failed to fetch|NetworkError|ERR_CONNECTION_CLOSED|conexao/i.test(String(err && err.message || ''))) {
                        throw err;
                    }
                    try {
                        const xhrResp = await xhrFallback(candidates[i], options || {});
                        if (xhrResp.ok) {
                            return xhrResp;
                        }
                        if (xhrResp.status === 404) {
                            lastResponse = xhrResp;
                            continue;
                        }
                        return xhrResp;
                    } catch (xhrErr) {
                        lastError = xhrErr;
                    }
                }
            }
            if (lastResponse) {
                return lastResponse;
            }
            throw lastError || new Error('Falha de conexao');
        };

        const reservasBadge = document.querySelector('[data-counter-badge="reservas"]');
        const reservasEndpoint = 'api/reservas_alertas.php';
        const reservasStorageKey = 'sidebar.reservas.lastId.' + restauranteId + '.' + perfilAtual;
        let reservasUltimoId = 0;
        let reservasInicializado = false;

        try {
            const saved = localStorage.getItem(reservasStorageKey);
            const parsed = Number(saved || 0);
            if (Number.isFinite(parsed) && parsed > 0) {
                reservasUltimoId = parsed;
            }
        } catch (e) {
            // Ignore storage errors.
        }

        const toastEl = document.createElement('div');
        toastEl.className = 'sidebar-toast';
        toastEl.setAttribute('role', 'status');
        toastEl.setAttribute('aria-live', 'polite');
        document.body.appendChild(toastEl);

        const updateReservaBadge = (value) => {
            updateCounter('reservas', value);
        };

        const persistReservaId = (id) => {
            const num = Number(id || 0);
            if (!(num > 0)) {
                return;
            }

            reservasUltimoId = num;
            try {
                localStorage.setItem(reservasStorageKey, String(num));
            } catch (e) {
                // Ignore storage errors.
            }
        };

        window.sidebarReservasMarcarVisto = persistReservaId;

        const showReservaToast = (titulo, mensagem) => {
            toastEl.innerHTML = '<strong>' + titulo + '</strong><small>' + mensagem + '</small>';
            toastEl.style.display = 'block';

            clearTimeout(toastEl._hideTimer);
            toastEl._hideTimer = setTimeout(() => {
                toastEl.style.display = 'none';
            }, 4500);
        };

        const isMesasPage = () => {
            return window.location.pathname.toLowerCase().endsWith('/mesas.php');
        };

        const refreshReservasAlertas = () => {
            if (!reservasBadge) {
                return;
            }

            fetchApiWithFallback(reservasEndpoint, reqOpts)
                .then((r) => r.json())
                .then((data) => {
                    if (!data || !data.success) {
                        return;
                    }

                    updateReservaBadge(data.pendentes || 0);

                    const ultima = data.ultima_pendente || null;
                    const ultimoId = Number(ultima && ultima.id ? ultima.id : 0);

                    if (reservasInicializado && ultimoId > 0 && ultimoId > reservasUltimoId) {
                        const nomeCliente = ultima && ultima.nome_cliente ? ultima.nome_cliente : 'Cliente';
                        const dataReserva = ultima && ultima.data_reserva ? ultima.data_reserva : '';
                        const horaReserva = ultima && ultima.hora_reserva ? String(ultima.hora_reserva).slice(0, 5) : '';
                        const mesaNumero = ultima && ultima.mesa_numero ? ' Mesa ' + ultima.mesa_numero + '.' : '';

                        showReservaToast(
                            'Nova reserva recebida',
                            nomeCliente + ' para ' + dataReserva + ' às ' + horaReserva + '.' + mesaNumero
                        );

                        if (isMesasPage()) {
                            setTimeout(() => {
                                window.location.reload();
                            }, 1800);
                        }
                    }

                    if (ultimoId > 0) {
                        persistReservaId(ultimoId);
                    }

                    reservasInicializado = true;
                })
                .catch(() => {
                    // Silencioso: a badge continua como estava até a próxima tentativa.
                });
        };

        const refreshPedidosCounter = () => {
            if (!document.querySelector('[data-counter-type="pedidos"]')) {
                return;
            }
            if (isEmpresarialSidebar) {
                fetchApiWithFallback('api/operacoes_empresariais.php?action=dashboard', reqOpts)
                    .then((r) => r.json())
                    .then((data) => {
                        if (data && data.success) {
                            updateCounter('pedidos', data.pedidos_abertos || 0);
                        }
                    })
                    .catch(() => {});
                return;
            }
            fetchApiWithFallback('api/garcom_pedidos.php', reqOpts)
                .then((r) => r.json())
                .then((data) => {
                    if (data && data.success) {
                        updateCounter('pedidos', data.total_pedidos || 0);
                    }
                })
                .catch(() => {});
        };

        const refreshContasCounter = () => {
            if (!document.querySelector('[data-counter-type="contas-abertas"]')) {
                return;
            }
            fetchApiWithFallback('api/caixa_mesas_abertas.php', reqOpts)
                .then((r) => r.json())
                .then((data) => {
                    if (data && data.success) {
                        updateCounter('contas-abertas', data.qtd_abertas || 0);
                    }
                })
                .catch(() => {});
        };

        const refreshEstoqueCounter = () => {
            if (!document.querySelector('[data-counter-type="estoque"]')) {
                return;
            }
            // O contador deve refletir o mesmo estado atual da tela, nunca uma
            // resposta HTTP em cache (que podia manter alertas antigos no menu).
            const estoqueUrl = 'api/estoque_inteligente_dashboard.php?_ts=' + Date.now();
            fetchApiWithFallback(estoqueUrl, reqOpts)
                .then((r) => r.json())
                .then((data) => {
                    if (!data || !data.success) {
                        return;
                    }
                    const alertas = (data.data && data.data.alertas_abertos) ? data.data.alertas_abertos : [];
                    const nAlertas = Array.isArray(alertas) ? alertas.length : 0;
                    updateCounter('estoque', nAlertas);
                    // Com alertas, o menu abre a pagina ja na lista de alertas.
                    const badgeEst = document.querySelector('[data-counter-type="estoque"]');
                    const linkEst = badgeEst ? badgeEst.closest('a') : null;
                    if (linkEst) {
                        const baseHref = (linkEst.getAttribute('href') || 'estoque_inteligente.php').split('#')[0];
                        linkEst.setAttribute('href', nAlertas > 0 ? baseHref + '#alertas' : baseHref);
                    }
                })
                .catch(() => {});
        };

        // Sem turno aberto estas APIs respondem sempre 403: nao vale a pena insistir.
        const podeConsultarApis = __NIVORA_SIDEBAR.v3 !== false;
        if (!podeConsultarApis) {
            return;
        }

        refreshPedidosCounter();
        refreshContasCounter();
        refreshEstoqueCounter();

        const isProdutosPage = window.location.pathname.toLowerCase().endsWith('/produtos.php');
        if (!isProdutosPage) {
            refreshReservasAlertas();
            setInterval(refreshReservasAlertas, 25000);
            setInterval(refreshPedidosCounter, 30000);
            setInterval(refreshContasCounter, 30000);
            setInterval(refreshEstoqueCounter, 45000);
        }
    })();
