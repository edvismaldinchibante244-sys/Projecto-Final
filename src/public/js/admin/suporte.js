// Painel Super Admin - Suporte: tickets, SLA e chat com o restaurante.
// Parte de admin.php; carregado por ordem com os outros ficheiros de js/admin/ (ver admin.php).

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

        let supportTicketsCache = {};

        function planoLabelSupport(plano) {
            const p = String(plano || '').toUpperCase();
            if (p === 'EMPRESARIAL' || p === 'ENTERPRISE') return 'Empresarial';
            if (p === 'PROFISSIONAL') return 'Profissional';
            if (p === 'BASICO') return 'Básico';
            return '';
        }

        function slaTextoSupport(ticket) {
            const st = String(ticket.sla?.state || '').toUpperCase();
            if (['RESOLVIDO', 'FECHADO'].includes(String(ticket.status || '').toUpperCase())) {
                return st === 'CUMPRIDO' ? 'Cumprido' : 'Encerrado';
            }
            if (st === 'VENCIDO') return 'Ultrapassado';
            if (st === 'EM_RISCO') return 'Quase a vencer';
            return 'No prazo';
        }

        function carregarSupportTickets() {
            if (!canAccessSupportSection()) {
                return;
            }

            const tbody = document.getElementById('tabelaSupportTickets');
            const q = document.getElementById('filtroSupportTickets')?.value || '';
            const status = document.getElementById('filtroSupportStatus')?.value ?? 'ABERTOS';
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i>A carregar…</td></tr>';
            }

            const params = new URLSearchParams();
            if (q) params.set('q', q);
            if (status) params.set('status', status);

            apiFetch('api/super_admin_support_tickets.php?' + params.toString())
                .then(parseJsonSafe)
                .then((data) => {
                    if (!data.success) {
                        throw new Error(data.message || 'Falha ao carregar os pedidos');
                    }

                    const stats = data.stats || {};
                    const setTxt = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = String(v || 0); };
                    setTxt('supportStatAbertos', stats.abertos);
                    setTxt('supportStatSlaVencido', stats.sla_vencidos);
                    setTxt('supportStatResolvidosMes', stats.resolvidos_mes);

                    const rows = Array.isArray(data.data) ? data.data : [];
                    supportTicketsCache = {};
                    rows.forEach((t) => { supportTicketsCache[Number(t.id || 0)] = t; });
                    if (!tbody) return;
                    if (!rows.length) {
                        tbody.innerHTML = status === 'ABERTOS'
                            ? '<tr><td colspan="7" class="text-center py-5 text-muted"><i class="fas fa-circle-check text-success me-2"></i>Nada por resolver. Bom trabalho!</td></tr>'
                            : '<tr><td colspan="7" class="text-center py-4 text-muted">Nenhum pedido encontrado.</td></tr>';
                        return;
                    }

                    tbody.innerHTML = rows.map((ticket) => {
                        const id = Number(ticket.id || 0);
                        const plano = planoLabelSupport(ticket.plano_codigo || ticket.restaurante_plano);
                        return `
                        <tr class="sa-linha-clicavel" data-ticket-id="${id}" tabindex="0" aria-label="Abrir pedido ${id}">
                            <td class="ps-3 text-muted">#${id}</td>
                            <td>
                                <div class="fw-semibold">${escapeHtml(ticket.restaurante_nome || '')}</div>
                                ${plano ? `<span class="sa-plano sa-plano--${escapeHtml(plano.toLowerCase().normalize('NFD').replace(/[^a-z]/g, ''))}">${escapeHtml(plano)}</span>` : ''}
                            </td>
                            <td><div class="fw-semibold">${escapeHtml(ticket.assunto || '')}</div><div class="small text-muted sa-1linha">${escapeHtml(ticket.descricao || '')}</div></td>
                            <td><span class="badge bg-${priorityBadgeClass(ticket.prioridade)}">${escapeHtml(ticket.prioridade_label || ticket.prioridade || '')}</span></td>
                            <td><span class="badge bg-${slaBadgeClassSupport(ticket.sla?.state)}">${escapeHtml(slaTextoSupport(ticket))}</span></td>
                            <td><span class="badge bg-${statusBadgeClassSupport(ticket.status)}">${escapeHtml(statusLabelSupport(String(ticket.status || '').toUpperCase()))}</span></td>
                            <td class="text-nowrap small">${escapeHtml(String(ticket.atualizado_em || ticket.criado_em || '').replace('T', ' ').slice(0, 16))}</td>
                        </tr>`;
                    }).join('');
                })
                .catch((err) => {
                    if (tbody) {
                        tbody.innerHTML = `<tr><td colspan="7" class="text-center py-4 text-danger">${escapeHtml(err.message || 'Falha ao carregar os pedidos')} <button type="button" class="btn btn-link btn-sm" id="btnSupportTentarDeNovo">Tentar de novo</button></td></tr>`;
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
                adminSupportChatListEl.innerHTML = '<div class="small text-muted p-2">Ainda sem mensagens.</div>';
                return;
            }
            adminSupportChatListEl.innerHTML = messages.map((m) => {
                const tipo = String(m.author_tipo || '');
                const isSupport = tipo === 'SUPORTE';
                const isSystem = tipo === 'SISTEMA';
                const who = isSystem ? 'Sistema' : (isSupport ? 'Suporte' : 'Cliente');
                const txt = m.mensagem ? `<div class="small mt-1">${escapeHtml(m.mensagem)}</div>` : '';
                const anexo = m.anexo_url ? `<a class="small" href="${escapeHtml(m.anexo_url)}" target="_blank" rel="noopener">Anexo: ${escapeHtml(m.anexo_nome || 'ficheiro')}</a>` : '';
                return `
                    <div class="sa-msg ${isSystem ? 'sa-msg--sistema' : (isSupport ? 'sa-msg--suporte' : 'sa-msg--cliente')}">
                        <div class="d-flex justify-content-between align-items-center gap-2">
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
                        throw new Error(data.message || 'Falha ao carregar a conversa');
                    }
                    renderAdminSupportChat(data.messages || []);
                })
                .catch((err) => {
                    if (adminSupportChatListEl) {
                        adminSupportChatListEl.innerHTML = `<div class="small text-danger p-2">${escapeHtml(err.message || 'Falha ao carregar a conversa')}</div>`;
                    }
                });
        }

        function openAdminSupportChat(ticketId) {
            adminSupportChatCurrentTicketId = Number(ticketId || 0);
            if (!adminSupportChatCurrentTicketId) return;
            const t = supportTicketsCache[adminSupportChatCurrentTicketId] || {};
            document.getElementById('adminSupportChatTicketId').value = String(adminSupportChatCurrentTicketId);
            document.getElementById('adminSupportChatTicketLabel').textContent = '#' + adminSupportChatCurrentTicketId;
            const plano = planoLabelSupport(t.plano_codigo || t.restaurante_plano);
            document.getElementById('painelSupportRestaurante').textContent = (t.restaurante_nome || '') + (plano ? ' · Plano ' + plano : '');
            document.getElementById('painelSupportAssunto').textContent = t.assunto || '';
            document.getElementById('painelSupportDescricao').textContent = t.descricao || '';
            document.getElementById('painelSupportMeta').innerHTML =
                `<span class="badge bg-${priorityBadgeClass(t.prioridade)}">${escapeHtml(t.prioridade_label || t.prioridade || '')}</span>
                 <span class="badge bg-${slaBadgeClassSupport(t.sla?.state)}">Prazo: ${escapeHtml(slaTextoSupport(t))} (${Number(t.sla?.target_hours || 0)}h)</span>
                 <span class="small text-muted">Aberto a ${escapeHtml(String(t.criado_em || '').replace('T', ' ').slice(0, 16))}</span>`;
            document.getElementById('painelSupportEstado').value = String(t.status || 'ABERTO').toUpperCase();
            if (adminSupportChatFeedbackEl) adminSupportChatFeedbackEl.textContent = '';
            if (adminSupportChatListEl) adminSupportChatListEl.innerHTML = '<div class="small text-muted p-2">A carregar…</div>';
            carregarAdminSupportChat(adminSupportChatCurrentTicketId);
            if (adminSupportChatTimer) clearInterval(adminSupportChatTimer);
            adminSupportChatTimer = setInterval(() => carregarAdminSupportChat(adminSupportChatCurrentTicketId), 4000);
            bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('painelSupportTicket')).show();
        }

        const tabelaSupportEl = document.getElementById('tabelaSupportTickets');
        tabelaSupportEl?.addEventListener('click', function(e) {
            if (e.target.closest('#btnSupportTentarDeNovo')) { carregarSupportTickets(); return; }
            const tr = e.target.closest('tr[data-ticket-id]');
            if (tr) openAdminSupportChat(Number(tr.dataset.ticketId || 0));
        });
        tabelaSupportEl?.addEventListener('keydown', function(e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            const tr = e.target.closest('tr[data-ticket-id]');
            if (tr) { e.preventDefault(); openAdminSupportChat(Number(tr.dataset.ticketId || 0)); }
        });

        document.getElementById('painelSupportGuardarEstado')?.addEventListener('click', function() {
            const btn = this;
            const ticketId = adminSupportChatCurrentTicketId;
            const status = document.getElementById('painelSupportEstado')?.value || 'EM_ANDAMENTO';
            if (!ticketId) return;
            btn.disabled = true;
            apiFetch('api/super_admin_support_ticket_update.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ticket_id: ticketId, status: status, resposta: '' })
            })
                .then(parseJsonSafe)
                .then((data) => {
                    if (!data.success) throw new Error(data.message || 'Falha ao atualizar o pedido');
                    showAlert(data.message || 'Estado atualizado.', 'success');
                    if (supportTicketsCache[ticketId]) supportTicketsCache[ticketId].status = status;
                    carregarAdminSupportChat(ticketId);
                    carregarSupportTickets();
                })
                .catch((err) => showAlert(err.message || 'Falha ao atualizar o pedido', 'danger'))
                .finally(() => { btn.disabled = false; });
        });

        document.getElementById('formSupportChatAdmin')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const ticketId = Number(document.getElementById('adminSupportChatTicketId')?.value || 0);
            if (!ticketId) return;
            if (adminSupportChatFeedbackEl) adminSupportChatFeedbackEl.textContent = 'A enviar…';
            const fd = new FormData(e.currentTarget);
            apiFetch('api/super_admin_support_ticket_message_send.php', {
                method: 'POST',
                body: fd
            })
                .then(parseJsonSafe)
                .then((data) => {
                    if (!data.success) {
                        throw new Error(data.message || 'Falha ao enviar a mensagem');
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

        document.getElementById('painelSupportTicket')?.addEventListener('hidden.bs.offcanvas', function() {
            if (adminSupportChatTimer) {
                clearInterval(adminSupportChatTimer);
                adminSupportChatTimer = null;
            }
            adminSupportChatCurrentTicketId = 0;
        });
