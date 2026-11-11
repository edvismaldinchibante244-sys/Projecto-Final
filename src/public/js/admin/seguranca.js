// Painel Super Admin - Seguranca: painel, linha temporal, fila offline, bloqueio de IPs e marcacao de saida no logout.
// Parte de admin.php; carregado por ordem com os outros ficheiros de js/admin/ (ver admin.php).

        function renderSecurityTimeline(timeline) {
            const labels = (timeline || []).map(item => String(item.bucket || '').slice(11, 16));
            const values = (timeline || []).map(item => Number(item.total || 0));
            const ctx = document.getElementById('securityTimelineChart');
            if (!ctx || typeof Chart === 'undefined') return;

            if (securityTimelineChart) {
                securityTimelineChart.destroy();
            }

            securityTimelineChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels,
                    datasets: [{
                        label: 'Eventos suspeitos',
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

        function nomeRotinaPt(nome) {
            const n = String(nome || '-');
            const mapa = [[/waf/i, 'Rever a firewall (WAF)'], [/retest/i, 'Repetir o teste de intrusão'], [/pentest/i, 'Teste de intrusão externo'],
                [/dr ?drill|dr-|restore/i, 'Ensaio de recuperação de backup'], [/ir ?table|tabletop/i, 'Simulação de resposta a incidente']];
            for (const [re, pt] of mapa) { if (re.test(n)) return pt; }
            return n;
        }

        function secSet(id, valor) {
            const el = document.getElementById(id);
            if (el) el.textContent = String(valor);
        }

        function renderSecurityPanel(data) {
            const stats = data?.stats || {};
            const total = Number(stats.total_eventos || 0);
            const criticos = Number(stats.criticos || 0);
            secSet('secTotalEventos', total);
            secSet('secCriticos', criticos);
            secSet('secIpsBloqueados', Number(stats.ips_bloqueados_ativos || 0));
            secSet('secSqli', Number(stats.sqli || 0));
            secSet('secXss', Number(stats.xss || 0));
            secSet('secBrute', Number(stats.brute || 0));
            secSet('secBots', Number(stats.bots || 0));
            secSet('secTraversal', Number(stats.traversal || 0));

            // Situacao em linguagem simples (o "score" de risco: 0 = sem ameacas).
            const nivel = String(stats.risk_level || 'BAIXO').toUpperCase();
            const horas = document.getElementById('securityWindowHours')?.value || '24';
            const card = document.getElementById('secRiskCard');
            const ataques = Number(stats.sqli || 0) + Number(stats.xss || 0) + Number(stats.brute || 0) + Number(stats.traversal || 0);
            let rotulo = 'Tudo calmo';
            let nota = ataques === 0 ? `0 ataques nas últimas ${horas}h ✓` : `${ataques} ataque(s) bloqueado(s) nas últimas ${horas}h`;
            let classe = 'ok';
            if (nivel === 'MEDIO' || nivel === 'MÉDIO') { rotulo = 'Atenção'; classe = 'atencao'; }
            if (nivel === 'ALTO' || nivel === 'CRITICO' || nivel === 'CRÍTICO' || criticos > 0) { rotulo = 'Em risco'; classe = 'risco'; nota = criticos + ' evento(s) grave(s) — veja abaixo'; }
            secSet('secRiskLabel', rotulo);
            secSet('secRiskNota', nota);
            secSet('secRiskScore', Number(stats.risk_score || 0));
            if (card) card.className = 'sa-kpi sa-kpi--estado sa-kpi--' + classe;

            const offlineSummary = data?.offline_queue_summary || {};
            secSet('offlineCentralRestaurantes', Number(offlineSummary.restaurantes_com_fila || 0));
            secSet('offlineCentralDispositivos', Number(offlineSummary.dispositivos_com_fila || 0));
            secSet('offlineCentralTotal', Number(offlineSummary.total_count || 0));

            const readiness = data?.readiness_95 || {};
            const governance = data?.governance || {};
            const readyStatus = String(readiness.status || 'PENDENTE');
            secSet('secReadyScore', Number(readiness.score || 0));
            secSet('secGovOwner', governance.owner || 'não definido');
            secSet('secGovUpdatedAt', governance.updated_at || 'nunca');
            const readyStatusEl = document.getElementById('secReadyStatus');
            if (readyStatusEl) {
                const mapa = { 'PRONTO_95+': ['Tudo em dia', 'bg-success'], 'PARCIAL': ['Algumas atrasadas', 'bg-warning text-dark'] };
                const [txt, cls] = mapa[readyStatus] || ['Por fazer', 'bg-danger'];
                readyStatusEl.textContent = txt;
                readyStatusEl.className = 'badge ' + cls;
            }

            const govRows = Array.isArray(governance.items) ? governance.items : [];
            const govBody = document.getElementById('tabelaGovernanceCadencia');
            if (govBody) {
                govBody.innerHTML = govRows.map((row) => {
                    const overdue = Boolean(row?.overdue);
                    const nunca = !row?.last_at;
                    return `
                        <tr>
                            <td class="ps-3">${escapeHtml(nomeRotinaPt(row?.name))}</td>
                            <td>${nunca ? '<span class="text-muted">Nunca</span>' : escapeHtml(String(row.last_at))}</td>
                            <td>${Number(row?.cadence_days || 0)} dias</td>
                            <td>${row?.days_since == null ? '—' : Number(row.days_since) + ' dias'}</td>
                            <td><span class="badge ${overdue || nunca ? 'bg-danger' : 'bg-success'}">${overdue || nunca ? 'Atrasado' : 'Em dia'}</span></td>
                        </tr>
                    `;
                }).join('') || '<tr><td colspan="5" class="security-table-empty">Ainda não há rotinas registadas. Corra <code>php tools/security_ops_runner.php</code> no servidor para registar a primeira.</td></tr>';
            }

            renderSecurityTimeline(data?.timeline || []);

            const topIpsBody = document.getElementById('tabelaTopIps');
            topIpsBody.innerHTML = (data?.top_ips || []).map(row => `
                <tr>
                    <td>${escapeHtml(row.ip || '-')}</td>
                    <td>${Number(row.ataques || 0)}</td>
                    <td class="text-end"><button class="btn btn-sm btn-outline-danger btn-block-ip" data-ip="${escapeHtml(row.ip || '')}">Bloquear</button></td>
                </tr>
            `).join('') || '<tr><td colspan="3" class="security-table-empty">Nenhum IP suspeito ✓</td></tr>';

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
            const sevPt = { CRITICAL: 'Grave', HIGH: 'Alta', MEDIUM: 'Média', LOW: 'Baixa', INFO: 'Info' };
            const eventos = (data?.recent_events || []);
            eventsBody.innerHTML = eventos.slice(0, 10).map(row => `
                <tr>
                    <td>#${Number(row.id || 0)}</td>
                    <td>${escapeHtml(row.created_at || '-')}</td>
                    <td><span class="badge bg-${row.severity === 'CRITICAL' ? 'danger' : (row.severity === 'HIGH' ? 'warning' : 'secondary')}">${escapeHtml(sevPt[String(row.severity || '').toUpperCase()] || row.severity || '-')}</span></td>
                    <td>${escapeHtml(row.attack_type || '-')}</td>
                    <td>${escapeHtml(row.ip || '-')}</td>
                    <td><small>${escapeHtml(row.endpoint || '-')}</small></td>
                </tr>
            `).join('') + (eventos.length > 10 ? `<tr><td colspan="6" class="text-center small py-2">A mostrar os 10 mais recentes de ${eventos.length}. <a href="admin_auditoria.php">Ver todos na Auditoria</a></td></tr>` : '')
                || '<tr><td colspan="6" class="security-table-empty">Sem eventos recentes ✓</td></tr>';

            const checklistBody = document.getElementById('tabelaSecurityChecklist') || { set innerHTML(v) {} };
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

            const hora = new Date().toLocaleTimeString('pt-PT', { hour: '2-digit', minute: '2-digit' });
            secSet('securityLastUpdate', hora);
            secSet('securityCurrentIp', data?.current_client_ip || '');
            secSet('securityEstadoLeitura', 'Atualizado às ' + hora + (data?.current_client_ip ? ' · O seu IP: ' + data.current_client_ip : '') + '.');
            const erroEl = document.getElementById('securityErro');
            if (erroEl) erroEl.classList.add('d-none');
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
                setOfflineQueueStatus('Indisponível nesta página.');
                return;
            }

            OfflineSync.getQueueStats()
                .then((stats) => {
                    preencherOfflineStats(stats || {});
                    const pend = Number(stats?.pending || 0);
                    const falh = Number(stats?.failed || 0);
                    setOfflineQueueStatus(falh > 0 ? (falh + ' falharam') : (pend > 0 ? (pend + ' por enviar') : 'Nada por enviar ✓'));
                })
                .catch((err) => {
                    setOfflineQueueStatus('Não foi possível ler: ' + (err?.message || 'erro desconhecido'));
                });
        }

        function sincronizarFilaOfflineAgora() {
            if (typeof OfflineSync === 'undefined' || !OfflineSync.syncQueue) {
                showAlert('Envio de operações indisponível nesta página.', 'warning');
                return;
            }
            setOfflineQueueStatus('A enviar…');
            OfflineSync.syncQueue()
                .then((res) => {
                    const synced = Number(res?.synced || 0);
                    const pending = Number(res?.pending || 0);
                    showAlert('Enviadas: ' + synced + ' · Por enviar: ' + pending, 'success');
                    carregarPainelFilaOffline();
                })
                .catch((err) => {
                    showAlert('Não foi possível enviar: ' + (err?.message || 'erro desconhecido'), 'danger');
                    carregarPainelFilaOffline();
                });
        }

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
                // Mostra o erro no proprio painel (antes ficava escondido no topo e a pagina parecia vazia).
                const erroEl = document.getElementById('securityErro');
                if (erroEl) {
                    erroEl.innerHTML = '<strong>Não foi possível carregar os dados de segurança.</strong> ' + escapeHtml(err.message || '') +
                        ' <button type="button" class="btn btn-sm btn-light ms-2" id="btnSegurancaTentarDeNovo">Tentar de novo</button>';
                    erroEl.classList.remove('d-none');
                    document.getElementById('btnSegurancaTentarDeNovo')?.addEventListener('click', carregarPainelSeguranca);
                }
                secSet('securityEstadoLeitura', 'Sem dados.');
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
