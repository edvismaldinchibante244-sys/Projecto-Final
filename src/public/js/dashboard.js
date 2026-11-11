        // @ts-nocheck
        /* eslint-disable */
        var _dashDataEl = document.getElementById('dashboard-js-data');
        var dashboardData = {};
        try {
            dashboardData = JSON.parse((_dashDataEl && _dashDataEl.textContent) ? _dashDataEl.textContent : '{}');
        } catch (e) {
            console.error('dashboard-js-data inválido', e);
            dashboardData = {};
        }
        const dashboardMeta = dashboardData.dashboardMeta || {};
        const supportCfg = dashboardData.supportConfig || {};
        const TEM_SUPORTE_24H = Boolean(supportCfg.temSuporte24h);
        const CANAL_SUPORTE_CONTATO = String(supportCfg.canalSuporteContato || '');
        const CSRF_TICKET_TOKEN = String(supportCfg.csrfTicketToken || '');

        function formatDashboardNumber(num, withDecimals = true) {
            return new Intl.NumberFormat('pt-PT', {
                minimumFractionDigits: withDecimals ? 2 : 0,
                maximumFractionDigits: withDecimals ? 2 : 0
            }).format(Number(num || 0));
        }

        const reservasBannerEl = document.getElementById('dashboardReservasBanner');
        const reservasCountEl = document.querySelector('[data-dashboard-reservas-count]');
        const reservasUltimaEl = document.querySelector('[data-dashboard-reservas-ultima]');

        const refreshDashboardReservas = async () => {
            if (!reservasBannerEl || !reservasCountEl || !reservasUltimaEl) {
                return;
            }

            try {
                const res = await fetch('api/reservas_alertas.php', {
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                const json = await res.json();
                if (!json.success) {
                    return;
                }

                const pendentes = Number(json.pendentes || 0);
                const ultima = json.ultima_pendente || null;
                const pendentesLabel = pendentes === 1 ? 'pendente' : 'pendentes';

                reservasBannerEl.classList.toggle('has-pending', pendentes > 0);
                reservasCountEl.textContent = pendentes + ' ' + pendentesLabel;
                reservasCountEl.classList.toggle('pending', pendentes > 0);
                reservasCountEl.classList.toggle('clear', pendentes <= 0);

                if (ultima) {
                    const nomeCliente = ultima.nome_cliente || 'Cliente';
                    const dataReserva = ultima.data_reserva || '';
                    const horaReserva = String(ultima.hora_reserva || '').slice(0, 5);
                    const mesaLabel = ultima.mesa_numero ? ('Mesa ' + String(ultima.mesa_numero)) : 'Mesa por atribuir';
                    reservasUltimaEl.textContent = `${nomeCliente} - ${dataReserva} às ${horaReserva} | ${mesaLabel}`;
                } else {
                    reservasUltimaEl.textContent = 'Nenhuma reserva pendente no momento.';
                }
            } catch (error) {
                console.error('Falha ao atualizar reservas do dashboard:', error);
            }
        };

        refreshDashboardReservas();
        setInterval(refreshDashboardReservas, 25000);

        // Relógio em tempo real no topo do dashboard
        // Garçom Dashboard
        if (document.getElementById('garcom-stats-loading')) {
            const loadGarcomStats = async () => {
                try {
                    const fetchOpts = {
                            credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    };
                    const requests = [
                        fetch('api/garcom_pedidos.php', fetchOpts),
                        fetch('api/garcom_vendas.php', fetchOpts)
                    ];
                    if (dashboardMeta.temGarcons) {
                        requests.splice(1, 0, fetch('api/garcom_mesas.php', fetchOpts));
                    }

                    const responses = await Promise.all(requests);
                    const pedidos = await responses[0].json();
                    const mesas = dashboardMeta.temGarcons
                        ? await responses[1].json()
                        : { success: true, ocupadas: 0, total: 0 };
                    const vendas = await responses[dashboardMeta.temGarcons ? 2 : 1].json();

                    if (pedidos.success && mesas.success && vendas.success) {
                        const pedidosEl = document.querySelector('[data-stat="pedidos-total"]');
                        const mesasEl = document.querySelector('[data-stat="mesas-total"]');
                        const vendasEl = document.querySelector('[data-stat="vendas-total"]');

                        if (pedidosEl) pedidosEl.textContent = String(pedidos.total_pedidos ?? 0);
                        if (mesasEl) mesasEl.textContent = `${mesas.ocupadas ?? 0}/${mesas.total ?? 0}`;
                        if (vendasEl) vendasEl.textContent = formatDashboardNumber(vendas.total_vendas_hoje ?? 0) + ' MZN';

                        // Status breakdown
                        const statusHtml = `
                             <div class="d-flex justify-content-around gap-2 mt-2">
                                 <span class="badge-custom badge-warning">${pedidos.pendentes} Pend.</span>
                                 <span class="badge-custom badge-info">${pedidos.preparados} Prep.</span>
                                 <span class="badge-custom badge-success">${pedidos.entregues} Ent.</span>
                             </div>
                         `;
                        const statusPedidosEl = document.querySelector('[data-stat="status-pedidos"]');
                        if (statusPedidosEl) {
                            statusPedidosEl.innerHTML = statusHtml;
                        }

                        document.getElementById('garcom-stats-loading').id = 'garcom-stats-loaded';
                    }
                } catch (e) {
                    console.error('Erro loading garcom stats:', e);
                }
            };

            loadGarcomStats();
            setInterval(loadGarcomStats, 30000); // Refresh every 30s
        }

        if (document.getElementById('caixa-stats-loading')) {
            const loadCaixaStats = async () => {
                try {
                    const fetchOpts = {
                            credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    };
                    const requests = [
                        fetch('api/caixa_vendas.php', fetchOpts),
                        fetch('api/caixa_turno.php', fetchOpts)
                    ];
                    if (dashboardMeta.temContasAbertas) {
                        requests.push(fetch('api/caixa_mesas_abertas.php', fetchOpts));
                    }

                    const responses = await Promise.all(requests);
                    const vendas = await responses[0].json();
                    const turno = await responses[1].json();
                    const abertas = dashboardMeta.temContasAbertas
                        ? await responses[2].json()
                        : { success: true, qtd_abertas: 0, total_aberto: 0 };

                    if ((vendas.success ?? true) && (turno.success ?? true) && (abertas.success ?? true)) {
                        const contasAbertasEl = document.querySelector('[data-caixa="contas-abertas"]');
                        const totalAbertoEl = document.querySelector('[data-caixa="total-aberto"]');
                        const totalTurnoEl = document.querySelector('[data-caixa="total-turno"]');
                        const caixaStatusEl = document.querySelector('.caixa-status');

                        if (contasAbertasEl) contasAbertasEl.textContent = String(abertas.qtd_abertas ?? 0);
                        if (totalAbertoEl) totalAbertoEl.textContent = formatDashboardNumber(abertas.total_aberto ?? 0);
                        if (totalTurnoEl) totalTurnoEl.textContent = formatDashboardNumber(turno.total_turno ?? 0);

                        if (caixaStatusEl) {
                            caixaStatusEl.textContent = turno.aberto ? 'ABERTO' : 'FECHADO';
                            caixaStatusEl.className = `caixa-status badge ${turno.aberto ? 'badge-success' : 'badge-warning'}`;
                        }

                        document.getElementById('caixa-stats-loading').id = 'caixa-stats-loaded';
                    }
                } catch (e) {
                    console.error('Erro loading caixa stats:', e);
                }
            };

            loadCaixaStats();
            setInterval(loadCaixaStats, 30000);
        }

        const teamOnlineCountEl = document.getElementById('teamOnlineCount');
        const teamOnlineListEl = document.getElementById('teamOnlineList');

        const resolveDashboardImageUrl = (path) => {
            if (!path) return '';
            if (/^(https?:)?\/\//.test(path) || path.startsWith('/')) {
                return path;
            }
            const base = window.location.pathname.replace(/\/[^/]*$/, '/');
            return `${window.location.origin}${base}${path.replace(/^\/+/, '')}`;
        };

        const refreshTeamOnline = async () => {
                    if (!teamOnlineCountEl || !teamOnlineListEl) return;
                    try {
                        const res = await fetch('api/team_online_status.php', {
                            credentials: 'same-origin',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        });
                        const json = await res.json();
                        if (!json.success) return;

                        teamOnlineCountEl.textContent = `${json.online}/${json.total}`;
                        // Preserve server-rendered EMPRESARIAL widget if present
                        if (teamOnlineListEl && teamOnlineListEl.dataset && teamOnlineListEl.dataset.serverRendered === '1') {
                            // do not overwrite server HTML; count already updated
                            return;
                        }
                        if (!Array.isArray(json.equipa) || json.equipa.length === 0) {
                            teamOnlineListEl.innerHTML = '<li class="text-muted small">Sem dados de presença da equipe no momento.</li>';
                            return;
                        }

                        teamOnlineListEl.innerHTML = json.equipa.map((membro) => {
                            const isOnline = Boolean(membro.online);
                            const nome = membro.nome || '';
                            const perfil = membro.perfil || '';
                            const fotoUrl = resolveDashboardImageUrl(membro.foto || '');
                            const avatarHtml = fotoUrl ?
                                `<img src="${fotoUrl}" alt="${nome}" style="width:100%;height:100%;object-fit:cover;" loading="lazy" onerror="this.onerror=null;this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(nome)}&background=EEF2F7&color=334155&size=64';">` :
                                `<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#64748b;font-weight:700;">${nome.charAt(0).toUpperCase() || 'U'}</div>`;
                            return `
                         <li class="team-online-item">
                             <div style="display:flex;align-items:center;gap:10px;">
                                 <div class="avatar" style="width:36px;height:36px;border-radius:50%;overflow:hidden;display:flex;align-items:center;justify-content:center;background:#eef2f7;">
                                     ${avatarHtml}
                                 </div>
                                 <div>
                                     <div class="team-member-name">${nome}</div>
                                     <div class="team-member-role">${perfil}</div>
                                 </div>
                             </div>
                             <div class="small fw-semibold" style="color: #475569;display:flex;align-items:center;gap:8px;">
                                 <span class="team-status-dot ${isOnline ? 'team-status-online' : 'team-status-offline'}"></span>
                                 ${isOnline ? 'Online' : 'Offline'}
                             </div>
                         </li>
                    `;
                        }).join('');
                    } catch (error) {
                        console.error('Falha ao atualizar a equipa online:', error);
                    }
                };

                        const heartbeatPresence = async () => {
                            try {
                                await fetch('api/online_ping.php', {
                                    method: 'POST',
                                    credentials: 'same-origin',
                                    headers: {
                                        'X-Requested-With': 'XMLHttpRequest'
                                    }
                                });
                            } catch (error) {
                                console.error('Falha no heartbeat de presença:', error);
                            }
                        };

                        if (teamOnlineCountEl) {
                            heartbeatPresence();
                            refreshTeamOnline();
                            // Menos pedidos: presenca de minuto a minuto e lista de 30 em 30 s.
                            // Com o separador escondido, nada e pedido (poupa rede e bateria).
                            const ecraVisivel = () => document.visibilityState !== 'hidden';
                            setInterval(() => { if (ecraVisivel()) { heartbeatPresence(); } }, 60000);
                            setInterval(() => { if (ecraVisivel()) { refreshTeamOnline(); } }, 30000);
                            document.addEventListener('visibilitychange', () => {
                                if (ecraVisivel()) {
                                    heartbeatPresence();
                                    refreshTeamOnline();
                                }
                            });
                        }

                        function enableRealtimeDashboardRefresh() {
                            let refreshTimer = null;
                            const triggerRefresh = () => {
                                if (refreshTimer) clearTimeout(refreshTimer);
                                refreshTimer = setTimeout(() => window.location.reload(), 700);
                            };

                            if (typeof io !== 'function') {
                                return;
                            }

                            try {
                                const socketBaseUrl = realtimeCfg.url;
                                const socket = io(socketBaseUrl, {
                                    transports: ['websocket', 'polling'],
                                    reconnection: true,
                                    reconnectionAttempts: 5
                                });

                                socket.on('connect', () => {
                                    // Codigo assinado pelo servidor: so entra na sala do proprio restaurante.
                                    socket.emit('join-room', { room: realtimeCfg.room, exp: realtimeCfg.exp, sig: realtimeCfg.sig });
                                });

                                socket.on('novo_pedido', (payload) => {
                                    const pedido = payload && payload.pedido ? payload.pedido : payload;
                                    if (!pedido) return;
                                    if (Number(pedido.restaurante_id || 0) !== Number(dashboardMeta.restauranteId || 0)) return;

                                    if (dashboardMeta.perfil === 'GARCOM') {
                                        if (Number(pedido.garcom_id || 0) !== Number(dashboardMeta.usuarioId || 0)) return;
                                    }

                                    triggerRefresh();
                                });
                            } catch (error) {
                                console.error('Falha ao conectar no realtime dashboard:', error);
                            }
                        }

                        const realtimeCfg = dashboardData.realtime || null;
                        (function loadRealtimeDashboardScript() {
                            // Tempo real so quando configurado (REALTIME_URL no .env); senao nem tenta ligar.
                            if (!realtimeCfg || !realtimeCfg.url) return;
                            const script = document.createElement('script');
                            script.src = `${realtimeCfg.url}/socket.io/socket.io.js`;
                            script.async = true;
                            script.onload = enableRealtimeDashboardRefresh;
                            script.onerror = () => {
                                // Realtime é opcional; sem Socket.IO o dashboard continua funcionando via refresh/intervalos.
                            };
                            document.head.appendChild(script);
                        })();

                        (function startTopBarClock() {
                            const el = document.getElementById('topBarDateTime');
                            if (!el) return;

                            const format = (n) => String(n).padStart(2, '0');
                            const updateClock = () => {
                                const now = new Date();
                                const text = `${format(now.getDate())}/${format(now.getMonth() + 1)}/${now.getFullYear()} ${format(now.getHours())}:${format(now.getMinutes())}`;
                                el.textContent = text;
                            };

                            updateClock();
                            setInterval(updateClock, 1000);
                        })();

                        const escapeHtml = (value) => String(value ?? '')
                            .replace(/&/g, '&amp;')
                            .replace(/</g, '&lt;')
                            .replace(/>/g, '&gt;')
                            .replace(/"/g, '&quot;')
                            .replace(/'/g, '&#039;');

                        const supportBasePath = window.location.pathname.replace(/\/[^/]*$/, '/');
                        const supportApiUrl = (rel) => `${window.location.origin}${supportBasePath}${String(rel || '').replace(/^\/+/, '')}`;

                        const supportXhrFallback = (url, options = {}) => new Promise((resolve, reject) => {
                            try {
                                const xhr = new XMLHttpRequest();
                                xhr.open(options.method || 'GET', url, true);
                                xhr.withCredentials = true;
                                const headers = options.headers || {};
                                Object.keys(headers).forEach((key) => xhr.setRequestHeader(key, headers[key]));
                                xhr.onload = () => resolve({
                                    ok: xhr.status >= 200 && xhr.status < 300,
                                    status: xhr.status,
                                    text: async () => xhr.responseText || ''
                                });
                                xhr.onerror = () => reject(new Error('XHR network error'));
                                xhr.send(options.body || null);
                            } catch (err) {
                                reject(err);
                            }
                        });

                        const supportRequest = async (relUrl, options = {}) => {
                            const rel = String(relUrl || '').replace(/^\/+/, '');
                            const candidates = [
                                rel,
                                supportApiUrl(rel),
                                `${supportBasePath}${rel}`,
                                `/V00/src/public/${rel}`
                            ];

                            const baseHeaders = {
                                'X-Requested-With': 'XMLHttpRequest',
                                ...(options.headers || {})
                            };
                            const finalOptions = {
                                ...options,
                                headers: baseHeaders
                            };

                            let lastError = null;
                            for (const url of candidates) {
                                try {
                                    const res = await fetch(url, finalOptions);
                                    if (res.status !== 404) return res;
                                    lastError = new Error(`HTTP 404 em ${url}`);
                                } catch (e) {
                                    try {
                                        const resXhr = await supportXhrFallback(url, finalOptions);
                                        if (resXhr.status !== 404) return resXhr;
                                        lastError = new Error(`XHR 404 em ${url}`);
                                    } catch (xhrErr) {
                                        lastError = xhrErr;
                                    }
                                }
                            }
                            throw lastError || new Error('Falha de conexao com API de suporte');
                        };

                        const parseSupportApiResponse = async (res) => {
                            const raw = await res.text();
                            let json = null;
                            try {
                                json = JSON.parse(raw);
                            } catch (e) {
                                const preview = String(raw || '').trim().slice(0, 160);
                                return {
                                    ok: false,
                                    message: `HTTP ${res.status}: resposta invalida${preview ? ' - ' + preview : ''}`
                                };
                            }
                            if (!res.ok || !json.success) {
                                return {
                                    ok: false,
                                    message: json.message || `HTTP ${res.status}: erro na API`
                                };
                            }
                            return {
                                ok: true,
                                json
                            };
                        };

                        const supportChannelFormEl = document.getElementById('formSuporteCanal');
                        const supportChannelFeedbackEl = document.getElementById('supportChannelFeedback');
                        const supportChannelSendBtnEl = document.getElementById('btnEnviarSuporteCanal');

                        if (!TEM_SUPORTE_24H && CANAL_SUPORTE_CONTATO !== '') {
                            supportChannelFormEl?.addEventListener('submit', async (e) => {
                                e.preventDefault();
                                const formData = new FormData(supportChannelFormEl);
                                const canal = String(formData.get('canal') || '').toUpperCase();
                                if (supportChannelSendBtnEl) supportChannelSendBtnEl.disabled = true;
                                if (supportChannelFeedbackEl) supportChannelFeedbackEl.textContent = 'Registrando solicitacao...';
                                try {
                                    const res = await supportRequest('api/support_contact_request.php', {
                                        method: 'POST',
                                        credentials: 'same-origin',
                                        headers: {
                                            'X-CSRF-Token': CSRF_TICKET_TOKEN
                                        },
                                        body: formData
                                    });
                                    const parsed = await parseSupportApiResponse(res);
                                    if (!parsed.ok) {
                                        if (supportChannelFeedbackEl) {
                                            supportChannelFeedbackEl.textContent = parsed.message || 'Falha ao registrar solicitacao.';
                                        }
                                        return;
                                    }

                                    const json = parsed.json || {};
                                    let feedback = json.message || 'Solicitacao registrada com sucesso.';
                                    if (json.ticket_id) {
                                        feedback += ` Protocolo #${Number(json.ticket_id)}.`;
                                    }
                                    if (supportChannelFeedbackEl) supportChannelFeedbackEl.textContent = feedback;
                                    supportChannelFormEl.reset();
                                    const prioridadeEl = supportChannelFormEl.querySelector('select[name="prioridade"]');
                                    if (prioridadeEl) prioridadeEl.value = 'ALTA';
                                    const canalEl = supportChannelFormEl.querySelector('input[name="canal"]');
                                    if (canalEl) canalEl.value = canal;

                                    if (canal === 'WHATSAPP' && json.whatsapp_url) {
                                        const popup = window.open(json.whatsapp_url, '_blank', 'noopener');
                                        if (!popup && supportChannelFeedbackEl) {
                                            supportChannelFeedbackEl.innerHTML = `${escapeHtml(feedback)} <a href="${escapeHtml(json.whatsapp_url)}" target="_blank" rel="noopener">Abrir WhatsApp</a>`;
                                        }
                                    }
                                } catch (error) {
                                    if (supportChannelFeedbackEl) {
                                        supportChannelFeedbackEl.textContent = (error && error.message) ?
                                            `Falha ao registrar solicitacao: ${error.message}` :
                                            'Falha ao registrar solicitacao.';
                                    }
                                } finally {
                                    if (supportChannelSendBtnEl) supportChannelSendBtnEl.disabled = false;
                                }
                            });
                        }

                        const supportFormEl = document.getElementById('formSuporte247');
                        const supportBodyEl = document.getElementById('support247TicketsBody');
                        const supportFeedbackEl = document.getElementById('support247Feedback');
                        const supportSendBtnEl = document.getElementById('btnEnviarSuporte247');
                        const supportChatListEl = document.getElementById('support247ChatList');
                        const supportChatFeedbackEl = document.getElementById('support247ChatFeedback');
                        const supportChatTicketIdEl = document.getElementById('supportChatTicketId');
                        const supportChatTicketLabelEl = document.getElementById('supportChatTicketLabel');
                        const supportChatModalEl = document.getElementById('modalSuporte247Chat');
                        const supportToastContainerEl = document.getElementById('support247ToastContainer');
                        const supportCountAbertosEl = document.getElementById('support247CountAbertos');
                        const supportCountAndamentoEl = document.getElementById('support247CountAndamento');
                        const supportCountResolvidosEl = document.getElementById('support247CountResolvidos');
                        let supportChatPollTimer = null;
                        let supportChatCurrentTicketId = 0;
                        let supportTicketStatusSnapshot = new Map();
                        let supportTicketInitialLoadDone = false;
                        const normalizeSupportStatus = (status) => {
                            const raw = String(status || '').trim().toUpperCase();
                            if (raw === 'EM_ANDAMENTO' || raw === 'EM ANDAMENTO') return 'EM_ANDAMENTO';
                            if (raw === 'RESOLVIDO') return 'RESOLVIDO';
                            if (raw === 'FECHADO') return 'FECHADO';
                            return 'ABERTO';
                        };
                        const supportStatusBadgeClass = (status) => ({
                            ABERTO: 'support247-status-badge support247-status-aberto',
                            EM_ANDAMENTO: 'support247-status-badge support247-status-andamento',
                            RESOLVIDO: 'support247-status-badge support247-status-resolvido',
                            FECHADO: 'support247-status-badge support247-status-fechado'
                        })[normalizeSupportStatus(status)] || 'support247-status-badge support247-status-aberto';
                        const supportStatusBadgeIcon = (status) => ({
                            ABERTO: 'fa-regular fa-clock',
                            EM_ANDAMENTO: 'fa-solid fa-spinner',
                            RESOLVIDO: 'fa-solid fa-circle-check',
                            FECHADO: 'fa-solid fa-lock'
                        })[normalizeSupportStatus(status)] || 'fa-regular fa-clock';
                        const supportStatusToastConfig = (status, ticketId) => {
                            const normalized = normalizeSupportStatus(status);
                            if (normalized === 'EM_ANDAMENTO') {
                                return {
                                    tone: 'info',
                                    message: `Seu ticket #${ticketId} entrou em atendimento.`
                                };
                            }
                            if (normalized === 'RESOLVIDO') {
                                return {
                                    tone: 'success',
                                    message: `Seu ticket #${ticketId} foi resolvido pela equipa.`
                                };
                            }
                            if (normalized === 'FECHADO') {
                                return {
                                    tone: 'neutral',
                                    message: `Seu ticket #${ticketId} foi encerrado.`
                                };
                            }
                            return {
                                tone: 'info',
                                message: `O status do ticket #${ticketId} foi atualizado.`
                            };
                        };
                        const showSupportToast = (message, tone = 'info') => {
                            if (!supportToastContainerEl || !message) return;
                            const toast = document.createElement('div');
                            toast.className = `support247-toast ${tone}`;
                            toast.textContent = message;
                            supportToastContainerEl.appendChild(toast);
                            requestAnimationFrame(() => toast.classList.add('is-visible'));
                            setTimeout(() => {
                                toast.classList.remove('is-visible');
                                setTimeout(() => toast.remove(), 220);
                            }, 4200);
                        };
                        const renderSupportTicketSummary = (tickets) => {
                            const counters = {
                                ABERTO: 0,
                                EM_ANDAMENTO: 0,
                                RESOLVIDO: 0
                            };
                            (Array.isArray(tickets) ? tickets : []).forEach((ticket) => {
                                const normalized = normalizeSupportStatus(ticket.status || ticket.status_label || '');
                                if (normalized === 'EM_ANDAMENTO') {
                                    counters.EM_ANDAMENTO += 1;
                                } else if (normalized === 'RESOLVIDO') {
                                    counters.RESOLVIDO += 1;
                                } else if (normalized === 'ABERTO') {
                                    counters.ABERTO += 1;
                                }
                            });
                            if (supportCountAbertosEl) supportCountAbertosEl.textContent = String(counters.ABERTO);
                            if (supportCountAndamentoEl) supportCountAndamentoEl.textContent = String(counters.EM_ANDAMENTO);
                            if (supportCountResolvidosEl) supportCountResolvidosEl.textContent = String(counters.RESOLVIDO);
                        };
                        const renderSupportTickets247 = (tickets) => {
                            if (!supportBodyEl) return;
                            if (!Array.isArray(tickets) || tickets.length === 0) {
                                renderSupportTicketSummary([]);
                                supportBodyEl.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">Nenhum ticket aberto ainda.</td></tr>';
                                return;
                            }
                            renderSupportTicketSummary(tickets);
                            supportBodyEl.innerHTML = tickets.map((ticket) => {
                                const normalizedStatus = normalizeSupportStatus(ticket.status || ticket.status_label || '');
                                const resposta = ticket.resposta_admin ? `<div class="small text-muted mt-1">${escapeHtml(ticket.resposta_admin)}</div>` : '';
                                return `
                    <tr>
                        <td>#${Number(ticket.id || 0)}</td>
                        <td><div class="fw-semibold">${escapeHtml(ticket.assunto || '')}</div>${resposta}</td>
                        <td>${escapeHtml(ticket.prioridade_label || ticket.prioridade || '')}</td>
                        <td><span class="${supportStatusBadgeClass(normalizedStatus)}"><i class="${supportStatusBadgeIcon(normalizedStatus)} me-1"></i>${escapeHtml(ticket.status_label || ticket.status || '')}</span></td>
                        <td>${escapeHtml(String(ticket.atualizado_em || ticket.criado_em || '').replace('T', ' ').slice(0, 16))}</td>
                        <td class="text-end"><button type="button" class="btn btn-outline-primary btn-sm btn-support-open-chat" data-id="${Number(ticket.id || 0)}">Chat</button></td>
                    </tr>
                `;
                            }).join('');
                        };

                        const carregarTickets247 = async () => {
                            if (!supportBodyEl) return;
                            try {
                                const res = await supportRequest('api/support_ticket_list.php', {
                                    credentials: 'same-origin'
                                });
                                const parsed = await parseSupportApiResponse(res);
                                if (!parsed.ok) {
                                    renderSupportTicketSummary([]);
                                    supportBodyEl.innerHTML = `<tr><td colspan="6" class="text-center text-danger py-3">${escapeHtml(parsed.message || 'Falha ao carregar tickets.')}</td></tr>`;
                                    return;
                                }
                                const tickets = Array.isArray(parsed.json.data) ? parsed.json.data : [];
                                if (supportTicketInitialLoadDone) {
                                    tickets.forEach((ticket) => {
                                        const ticketId = Number(ticket.id || 0);
                                        if (!ticketId) return;
                                        const currentStatus = normalizeSupportStatus(ticket.status || ticket.status_label || '');
                                        const previousStatus = supportTicketStatusSnapshot.get(ticketId);
                                        if (previousStatus && previousStatus !== currentStatus) {
                                            const toastConfig = supportStatusToastConfig(currentStatus, ticketId);
                                            showSupportToast(toastConfig.message, toastConfig.tone);
                                        }
                                    });
                                }
                                supportTicketStatusSnapshot = new Map(tickets.map((ticket) => [
                                    Number(ticket.id || 0),
                                    normalizeSupportStatus(ticket.status || ticket.status_label || '')
                                ]));
                                supportTicketInitialLoadDone = true;
                                renderSupportTickets247(tickets);
                            } catch (error) {
                                renderSupportTicketSummary([]);
                                const msg = (error && error.message) ? error.message : 'Falha ao carregar tickets.';
                                supportBodyEl.innerHTML = `<tr><td colspan="6" class="text-center text-danger py-3">${escapeHtml(msg)}</td></tr>`;
                            }
                        };

                        const renderSupportChat247 = (messages) => {
                            if (!supportChatListEl) return;
                            if (!Array.isArray(messages) || messages.length === 0) {
                                supportChatListEl.innerHTML = '<div class="text-muted small p-2">Sem mensagens ainda.</div>';
                                return;
                            }
                            const timelineHtml = messages.map((m) => {
                                const tipo = String(m.author_tipo || '').toUpperCase();
                                const isSupport = tipo === 'SUPORTE';
                                const isSystem = tipo === 'SISTEMA';
                                const who = isSystem ? 'Sistema' : (isSupport ? 'Suporte' : 'Voce');
                                const rowClass = isSystem ?
                                    'support247-chat-message support247-chat-message-system' :
                                    (isSupport ? 'support247-chat-message support247-chat-message-support' : 'support247-chat-message support247-chat-message-client');
                                const txt = m.mensagem ? `<div class="small mt-1">${escapeHtml(m.mensagem)}</div>` : '';
                                const anexo = m.anexo_url ? `<a class="small" href="${escapeHtml(m.anexo_url)}" target="_blank" rel="noopener">Anexo: ${escapeHtml(m.anexo_nome || 'arquivo')}</a>` : '';
                                return `
                    <div class="border rounded p-2 mb-2 ${rowClass} ${isSystem ? '' : (isSupport ? 'bg-light' : 'bg-white')}">
                        <div class="d-flex justify-content-between align-items-center">
                            <strong class="small">${who}</strong>
                            <span class="text-muted small">${escapeHtml(String(m.criado_em || '').replace('T', ' ').slice(0, 16))}</span>
                        </div>
                        ${txt}
                        ${anexo}
                    </div>
                `;
                            }).join('');
                            supportChatListEl.innerHTML = `<div class="support247-chat-timeline">${timelineHtml}</div>`;
                            supportChatListEl.scrollTop = supportChatListEl.scrollHeight;
                        };

                        const carregarSupportChat247 = async (ticketId) => {
                            if (!ticketId || !supportChatListEl) return;
                            try {
                                const res = await supportRequest(`api/support_ticket_thread.php?ticket_id=${encodeURIComponent(ticketId)}`, {
                                    credentials: 'same-origin'
                                });
                                const parsed = await parseSupportApiResponse(res);
                                if (!parsed.ok) {
                                    supportChatListEl.innerHTML = `<div class="text-danger small p-2">${escapeHtml(parsed.message || 'Falha ao carregar chat')}</div>`;
                                    return;
                                }
                                renderSupportChat247(parsed.json.messages || []);
                            } catch (error) {
                                const msg = (error && error.message) ? error.message : 'Falha ao carregar chat.';
                                supportChatListEl.innerHTML = `<div class="text-danger small p-2">${escapeHtml(msg)}</div>`;
                            }
                        };

                        const openSupportChat247 = (ticketId) => {
                            supportChatCurrentTicketId = Number(ticketId || 0);
                            if (!supportChatCurrentTicketId) return;
                            if (supportChatTicketIdEl) supportChatTicketIdEl.value = String(supportChatCurrentTicketId);
                            if (supportChatTicketLabelEl) supportChatTicketLabelEl.textContent = '#' + supportChatCurrentTicketId;
                            if (supportChatFeedbackEl) supportChatFeedbackEl.textContent = '';
                            carregarSupportChat247(supportChatCurrentTicketId);
                            if (supportChatPollTimer) clearInterval(supportChatPollTimer);
                            supportChatPollTimer = setInterval(() => carregarSupportChat247(supportChatCurrentTicketId), 4000);
                            if (supportChatModalEl && typeof bootstrap !== 'undefined' && bootstrap?.Modal) {
                                const modal = bootstrap.Modal.getOrCreateInstance(supportChatModalEl);
                                modal.show();
                            }
                        };

                        supportFormEl?.addEventListener('submit', async (e) => {
                            e.preventDefault();
                            const formData = new FormData(supportFormEl);
                            if (supportSendBtnEl) supportSendBtnEl.disabled = true;
                            supportFeedbackEl.textContent = 'Enviando ticket...';
                            try {
                                const res = await supportRequest('api/support_ticket_create.php', {
                                    method: 'POST',
                                    credentials: 'same-origin',
                                    headers: {
                                        'X-CSRF-Token': CSRF_TICKET_TOKEN
                                    },
                                    body: formData
                                });
                                const raw = await res.text();
                                let json = null;
                                try {
                                    json = JSON.parse(raw);
                                } catch (parseErr) {
                                    supportFeedbackEl.textContent = 'Falha ao enviar ticket (resposta invalida do servidor).';
                                    return;
                                }
                                if (!json.success) {
                                    supportFeedbackEl.textContent = json.message || 'Falha ao abrir ticket.';
                                    return;
                                }
                                supportFeedbackEl.textContent = json.message || 'Ticket aberto.';
                                supportFormEl.reset();
                                const prioridadeEl = supportFormEl.querySelector('select[name="prioridade"]');
                                if (prioridadeEl) prioridadeEl.value = 'ALTA';
                                await carregarTickets247();
                                if (json.ticket_id) {
                                    try {
                                        openSupportChat247(json.ticket_id);
                                    } catch (chatOpenError) {
                                        console.error('Falha ao abrir chat apos criar ticket:', chatOpenError);
                                    }
                                }
                            } catch (error) {
                                supportFeedbackEl.textContent = (error && error.message) ?
                                    `Falha ao enviar ticket: ${error.message}` :
                                    'Falha ao enviar ticket.';
                            } finally {
                                if (supportSendBtnEl) supportSendBtnEl.disabled = false;
                            }
                        });

                        supportBodyEl?.addEventListener('click', (e) => {
                            const btn = e.target.closest('.btn-support-open-chat');
                            if (!btn) return;
                            openSupportChat247(Number(btn.dataset.id || 0));
                        });

                        document.getElementById('formSuporte247Msg')?.addEventListener('submit', async (e) => {
                            e.preventDefault();
                            const ticketId = Number(supportChatTicketIdEl?.value || 0);
                            if (!ticketId) return;
                            supportChatFeedbackEl.textContent = 'Enviando...';
                            const fd = new FormData(e.currentTarget);
                            try {
                                const res = await supportRequest('api/support_ticket_message_send.php', {
                                    method: 'POST',
                                    credentials: 'same-origin',
                                    headers: {
                                        'X-CSRF-Token': CSRF_TICKET_TOKEN
                                    },
                                    body: fd
                                });
                                const parsed = await parseSupportApiResponse(res);
                                if (!parsed.ok) {
                                    supportChatFeedbackEl.textContent = parsed.message || 'Falha ao enviar.';
                                    return;
                                }
                                supportChatFeedbackEl.textContent = 'Mensagem enviada.';
                                document.getElementById('supportChatMensagem').value = '';
                                document.getElementById('supportChatAnexo').value = '';
                                await carregarSupportChat247(ticketId);
                                await carregarTickets247();
                            } catch (error) {
                                supportChatFeedbackEl.textContent = 'Falha ao enviar.';
                            }
                        });

                        supportChatModalEl?.addEventListener('hidden.bs.modal', () => {
                            if (supportChatPollTimer) {
                                clearInterval(supportChatPollTimer);
                                supportChatPollTimer = null;
                            }
                        });

                        document.getElementById('btnAtualizarTickets247')?.addEventListener('click', carregarTickets247);
                        document.getElementById('modalSuporte247')?.addEventListener('shown.bs.modal', carregarTickets247);
                        if (supportBodyEl) {
                            carregarTickets247();
                            setInterval(carregarTickets247, 15000);
                        }
    
