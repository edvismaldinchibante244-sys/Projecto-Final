// Pedidos - Contadores, tabela Empresarial, detalhe, kanban e desenho da lista.
// Parte de pedidos.php; carregado por ordem com os outros ficheiros de js/pedidos/.

        function getStatusColumns() {
            return {
                NOVO: {
                    titulo: '📋 Na Cozinha',
                    subtitulo: 'Enviar para preparo',
                    proximo: 'PREPARANDO',
                    cor: 'NOVO'
                },
                PREPARANDO: {
                    titulo: '🔥 Em Preparo',
                    subtitulo: 'Cozinha preparando',
                    proximo: 'PRONTO',
                    cor: 'PREPARANDO'
                },
                PRONTO: {
                    titulo: '✅ Pronto',
                    subtitulo: 'Entregar ao cliente',
                    proximo: 'ENTREGUE',
                    cor: 'PRONTO'
                },
                ENTREGUE: {
                    titulo: '🛎 Entregue',
                    subtitulo: 'Cliente a consumir',
                    proximo: 'CONTA',
                    cor: 'ENTREGUE'
                },
                CONTA: {
                    titulo: '🧾 Conta',
                    subtitulo: 'Aguardando caixa',
                    proximo: 'PAGO',
                    cor: 'CONTA'
                },
                PAGO: {
                    titulo: '💰 Pago',
                    subtitulo: 'Mesa fechada',
                    proximo: null,
                    cor: 'PAGO'
                }
            };
        }

        function updateCounters(contadores) {
            var map = {
                countNovo: contadores.NOVO || 0,
                countPreparando: contadores.PREPARANDO || 0,
                countPronto: contadores.PRONTO || 0,
                countEntregue: contadores.ENTREGUE || 0,
                countConta: contadores.CONTA || 0,
                countPago: contadores.PAGO || 0
            };
            Object.keys(map).forEach(function(id) {
                var el = document.getElementById(id);
                if (el) el.textContent = map[id];
            });
            if (isEmpresarialUi) {
                var tNovo = document.getElementById('tabCountNovo');
                var tPrep = document.getElementById('tabCountPrep');
                var tPronto = document.getElementById('tabCountPronto');
                if (tNovo) tNovo.textContent = String(contadores.NOVO || 0);
                if (tPrep) tPrep.textContent = String(contadores.PREPARANDO || 0);
                if (tPronto) tPronto.textContent = String(contadores.PRONTO || 0);
            }
        }

        function contarPedidosSemCancelados(pedidos) {
            return (Array.isArray(pedidos) ? pedidos : []).filter(function (p) {
                return normalizarStatus(p && p.status) !== 'CANCELADO';
            }).length;
        }

        function updateHojeKpi(pedidos) {
            var el = document.getElementById('countHoje');
            var hint = document.getElementById('hintHoje');
            if (!el) return;
            var total = contarPedidosSemCancelados(pedidos);
            el.textContent = String(total);
            var tabTodos = document.getElementById('tabCountTodos');
            if (tabTodos) tabTodos.textContent = String(total);
            if (hint) {
                if (PEDIDOS_ONTEM > 0) {
                    var pct = ((total - PEDIDOS_ONTEM) / PEDIDOS_ONTEM) * 100;
                    var sinal = pct >= 0 ? '+' : '';
                    hint.textContent = isEmpresarialUi
                        ? (sinal + Math.round(pct) + '%')
                        : (sinal + Math.round(pct) + '% vs ontem');
                } else {
                    hint.textContent = isEmpresarialUi ? '—' : 'Sem pedidos ontem para comparar';
                }
            }
        }

        // Ecra padrao: fluxo do dia sem os cancelados, atrasados e pedido mais antigo.
        function updateKpisPadrao(pedidos, contadores) {
            var lista = Array.isArray(pedidos) ? pedidos : [];
            var validos = contarPedidosSemCancelados(lista);
            var fluxo = document.getElementById('kpiFilaValue');
            if (fluxo) {
                fluxo.textContent = validos + ' pedidos';
            }
            var hintFluxo = document.getElementById('kpiFilaHint');
            if (hintFluxo) {
                if (PEDIDOS_ONTEM > 0) {
                    var p2 = ((validos - PEDIDOS_ONTEM) / PEDIDOS_ONTEM) * 100;
                    hintFluxo.textContent = (p2 >= 0 ? '+' : '') + Math.round(p2) + '% vs ontem';
                } else {
                    hintFluxo.textContent = 'Sem pedidos ontem para comparar';
                }
            }
            var cancelEl = document.getElementById('countCancelado');
            if (cancelEl && contadores) {
                cancelEl.textContent = String(contadores.CANCELADO || 0);
            }
            var atrasEl = document.getElementById('countAtrasados');
            if (atrasEl) {
                var ops = calcFilaOps(lista);
                atrasEl.textContent = String(ops.atrasados);
                var cartao = atrasEl.closest('.pedidos-kpi');
                if (cartao) cartao.classList.toggle('is-alerta', ops.atrasados > 0);
                var hintAtras = document.getElementById('hintAtrasados');
                if (hintAtras) {
                    hintAtras.textContent = ops.esperaMax > 0
                        ? 'mais antigo: ' + ops.esperaMax + ' min'
                        : 'acima de ' + FILA_SLA_MINUTOS + ' min';
                }
            }
        }

        function updateEmpTabAtrasados(pedidos) {
            if (!isEmpresarialUi) return;
            var el = document.getElementById('tabCountAtrasados');
            if (!el) return;
            var n = (Array.isArray(pedidos) ? pedidos : []).filter(isPedidoAtrasado).length;
            el.textContent = String(n);
        }

        function peResetFiltroTodos() {
            peTabFilter = 'todos';
            document.querySelectorAll('.pe-tab').forEach(function(t) {
                t.classList.toggle('is-active', (t.getAttribute('data-pe-tab') || '') === 'todos');
            });
            var area = document.getElementById('pedidosArea');
            if (area) {
                area.innerHTML = buildEmpTableHtml(currentPedidos || []);
            }
            var filtered = filterEmpPedidos(currentPedidos || []);
            if (filtered.length) {
                var keep = selectedEmpPedidoId && filtered.some(function(p) {
                    return parseInt(p.id, 10) === parseInt(selectedEmpPedidoId, 10);
                });
                selectEmpPedido(keep ? selectedEmpPedidoId : filtered[0].id);
            } else {
                selectedEmpPedidoId = null;
                renderEmpDetail(null);
            }
            var board = document.getElementById('peBoard');
            if (board && typeof board.scrollIntoView === 'function') {
                board.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }

            // Feedback visual claro
            if (board) {
                board.classList.add('pe-flash');
                setTimeout(function() { board.classList.remove('pe-flash'); }, 900);
            }
        }
        window.peResetFiltroTodos = peResetFiltroTodos;

        function statusUiLabel(status) {
            var s = normalizarStatus(status);
            if (s === 'NOVO') return 'Novo';
            if (s === 'PREPARANDO') return 'Em preparo';
            if (s === 'PRONTO') return 'Pronto';
            if (s === 'ENTREGUE') return 'Entregue';
            if (s === 'CONTA') return 'Conta pedida';
            if (s === 'PAGO') return 'Pago';
            if (s === 'CANCELADO') return 'Cancelado';
            return s;
        }

        function statusCssClass(status) {
            return String(normalizarStatus(status) || '').toLowerCase();
        }

        function isPedidoAtrasado(ped) {
            var st = normalizarStatus(ped && ped.status);
            if (st !== 'NOVO' && st !== 'PREPARANDO' && st !== 'PRONTO') return false;
            return getPedidoAgeMinutes(ped.criado_em) >= FILA_SLA_MINUTOS;
        }

        function filterEmpPedidos(pedidos) {
            var lista = Array.isArray(pedidos) ? pedidos.slice() : [];
            if (peTabFilter === 'todos') return lista;
            if (peTabFilter === 'atrasados') {
                return lista.filter(isPedidoAtrasado);
            }
            return lista.filter(function(p) {
                return normalizarStatus(p.status) === peTabFilter;
            });
        }

        function buildEmpTableHtml(pedidos) {
            var lista = filterEmpPedidos(pedidos);
            lista.sort(function(a, b) {
                var aLate = isPedidoAtrasado(a) ? 1 : 0;
                var bLate = isPedidoAtrasado(b) ? 1 : 0;
                if (aLate !== bLate) return bLate - aLate;
                return getPedidoAgeMinutes(b.criado_em) - getPedidoAgeMinutes(a.criado_em);
            });

            if (!lista.length) {
                return '<div class="pe-empty pe-empty-compacta"><i class="fas fa-inbox" aria-hidden="true"></i>'
                    + '<div><strong>Nenhum pedido neste filtro</strong><span>Os novos pedidos aparecem aqui automaticamente.</span></div>'
                    + '<a href="novo_pedido.php" class="pe-btn pe-btn-primary"><i class="fas fa-plus"></i> Novo pedido</a></div>';
            }

            var html = '<table class="pe-table"><thead><tr>';
            html += '<th>Pedido</th><th>Cliente / Mesa</th><th>Itens</th><th>Total</th><th>Status</th><th>Atendimento</th><th>Ação</th>';
            html += '</tr></thead><tbody>';

            lista.forEach(function(ped) {
                var id = parseInt(ped.id, 10);
                var st = normalizarStatus(ped.status);
                var late = isPedidoAtrasado(ped);
                var origemMeta = getOrigemPedidoMeta(ped.origem);
                var cliente = ped.cliente_nome || ped.nome_cliente || ped.nome || ped.cliente || '';
                var mesaLabel = getMesaPedidoLabel(ped, origemMeta.className === 'balcao' ? 'Take Away' : origemMeta.label);
                var itensTxt = ped.itens_resumo || ((ped.total_itens || 0) + ' item(s)');
                var ageLabel = getPedidoTempoAtendimentoLabel(ped);
                var selected = selectedEmpPedidoId && parseInt(selectedEmpPedidoId, 10) === id;
                var statusClass = late ? 'atrasado' : statusCssClass(st);
                var statusTxt = late && (st === 'NOVO' || st === 'PREPARANDO' || st === 'PRONTO') ? 'Atrasado' : statusUiLabel(st);

                html += '<tr class="' + (selected ? 'is-selected' : '') + '" onclick="selectEmpPedido(' + id + ')">';
                html += '<td><div class="pe-id">#' + escapeHtml(ped.numero_pedido || id) + '</div><div class="pe-sub">' + escapeHtml(origemMeta.label) + '</div></td>';
                html += '<td><div class="fw-semibold">' + escapeHtml(mesaLabel) + '</div>';
                if (cliente) html += '<div class="pe-sub">' + escapeHtml(cliente) + '</div>';
                html += '</td>';
                html += '<td><div class="text-truncate" style="max-width:220px;" title="' + escapeHtml(itensTxt) + '">' + escapeHtml(itensTxt) + '</div></td>';
                html += '<td class="fw-bold">' + formatMoney(ped.total) + '</td>';
                html += '<td><span class="pe-status ' + statusClass + '">' + escapeHtml(statusTxt) + '</span></td>';
                html += '<td><span class="pe-tempo' + (late ? ' is-late' : '') + '">' + escapeHtml(ageLabel) + '</span></td>';
                html += '<td onclick="event.stopPropagation()">';
                if (canRequestBill && (st === 'PRONTO' || st === 'ENTREGUE')) {
                    html += '<button type="button" class="pe-btn pe-btn-primary pe-btn-xs" onclick="avancarPedido(this, ' + id + ', \'CONTA\')"><i class="fas fa-file-invoice"></i> Pedir Conta</button>';
                } else if (canReceivePayment && st === 'CONTA') {
                    html += '<button type="button" class="pe-btn pe-btn-primary pe-btn-xs" onclick="abrirPagamentoPedido(' + id + ')"><i class="fas fa-money-bill-wave"></i> Receber</button>';
                } else {
                    html += '<span class="pe-sub">—</span>';
                }
                html += '</td>';
                html += '</tr>';
            });

            html += '</tbody></table>';
            return html;
        }

        function timelineHtml(status) {
            var steps = [
                { key: 'NOVO', label: 'Novo' },
                { key: 'PREPARANDO', label: 'Em preparação' },
                { key: 'PRONTO', label: 'Pronto' },
                { key: 'ENTREGUE', label: 'Entregue' },
                { key: 'CONTA', label: 'Conta' }
            ];
            var order = { NOVO: 0, PREPARANDO: 1, PRONTO: 2, ENTREGUE: 3, CONTA: 4, PAGO: 5 };
            var cur = order[normalizarStatus(status)] != null ? order[normalizarStatus(status)] : 0;
            var html = '<div class="pe-timeline">';
            steps.forEach(function(step, idx) {
                var cls = '';
                if (idx < cur) cls = 'is-done';
                else if (idx === cur) cls = 'is-current';
                html += '<div class="pe-step ' + cls + '"><span>' + step.label + '</span></div>';
            });
            html += '</div>';
            return html;
        }

        function renderEmpDetail(ped) {
            var box = document.getElementById('peDetailPanel');
            if (!box) return;
            if (!ped) {
                var tplResumo = document.getElementById('peResumoDia');
                box.innerHTML = tplResumo ? tplResumo.innerHTML : '<div class="pe-detail-empty">Selecione um pedido na lista para ver o detalhe.</div>';
                return;
            }
            var st = normalizarStatus(ped.status);
            var origemMeta = getOrigemPedidoMeta(ped.origem);
            var mesaLabel = getMesaPedidoLabel(ped, origemMeta.className === 'balcao' ? 'Take Away' : origemMeta.label);
            var hora = '';
            if (ped.criado_em && String(ped.criado_em).length >= 16) {
                hora = String(ped.criado_em).substring(11, 16);
            }
            var cols = getStatusColumns();
            var info = cols[st];
            var html = '<div class="pe-detail-head">';
            html += '<div class="pe-detail-kicker">Último Pedido</div>';
            html += '<h3>Pedido #' + escapeHtml(ped.numero_pedido || ped.id) + '</h3>';
            html += '<div class="meta"><span>' + escapeHtml(mesaLabel) + '</span>';
            if (hora) html += '<span class="dot"></span><span>' + escapeHtml(hora) + '</span>';
            html += '</div></div><div class="pe-detail-body">';
            html += '<div class="pe-section-label">Itens</div>';
            html += '<div id="peDetailItens"><div class="text-muted small">A carregar…</div></div>';
            html += '<div class="pe-total"><span>Total</span><span>' + formatMoney(ped.total) + '</span></div>';
            html += timelineHtml(st);
            html += '<div class="pe-detail-actions">';
            if (st === 'PRONTO' || st === 'ENTREGUE') {
                if (st === 'PRONTO' && podeAvancarStatus(st, 'ENTREGUE')) {
                    html += '<button type="button" class="pe-btn pe-btn-ghost" onclick="avancarPedido(this, ' + parseInt(ped.id, 10) + ', \'ENTREGUE\')"><i class="fas fa-hand-holding"></i> Entregar</button>';
                }
                if (canRequestBill) {
                    html += '<button type="button" class="pe-btn pe-btn-primary" onclick="avancarPedido(this, ' + parseInt(ped.id, 10) + ', \'CONTA\')"><i class="fas fa-file-invoice"></i> Pedir Conta</button>';
                }
                if (canReceivePayment) {
                    html += '<button type="button" class="pe-btn pe-btn-ghost" onclick="abrirPagamentoPedido(' + parseInt(ped.id, 10) + ')"><i class="fas fa-money-bill-wave"></i> Receber pagamento</button>';
                }
            } else if (st === 'CONTA') {
                if (canReceivePayment) {
                    html += '<button type="button" class="pe-btn pe-btn-primary" onclick="abrirPagamentoPedido(' + parseInt(ped.id, 10) + ')"><i class="fas fa-money-bill-wave"></i> Receber pagamento</button>';
                } else {
                    html += '<button type="button" class="pe-btn pe-btn-ghost" disabled><i class="fas fa-lock"></i> Aguardando caixa</button>';
                }
            } else if (info && info.proximo && info.proximo !== 'PAGO' && info.proximo !== 'CONTA' && podeAvancarStatus(st, info.proximo)) {
                var btnLabel = 'Avançar';
                if (st === 'NOVO') btnLabel = 'Enviar p/ preparo';
                else if (st === 'PREPARANDO') btnLabel = 'Marcar pronto';
                html += '<button type="button" class="pe-btn pe-btn-ghost" onclick="avancarPedido(this, ' + parseInt(ped.id, 10) + ', \'' + info.proximo + '\')"><i class="fas fa-arrow-right"></i> ' + btnLabel + '</button>';
            }
            if (canCancelOrder && st !== 'ENTREGUE' && st !== 'CONTA' && st !== 'PAGO' && st !== 'CANCELADO') {
                html += '<button type="button" class="pe-btn pe-btn-ghost" style="color:#dc2626;border-color:#fecaca;" onclick="cancelarPedido(this, ' + parseInt(ped.id, 10) + ')"><i class="fas fa-times"></i> Cancelar</button>';
            }
            html += '<button type="button" class="pe-btn pe-btn-ghost" onclick="verItensPedido(' + parseInt(ped.id, 10) + ')">Ver Detalhes do Pedido <i class="fas fa-arrow-right"></i></button>';
            html += '</div></div>';
            box.innerHTML = html;

            // Mostra resumo imediatamente; detalhe completo sob demanda no botão
            var elItens = document.getElementById('peDetailItens');
            if (elItens) {
                if (ped.itens_resumo) {
                    elItens.innerHTML = '<div class="text-muted small">' + escapeHtml(ped.itens_resumo) + '</div>';
                }
                withRealtimePaused(function() {
                    return fetch('api/pedido_itens.php?id=' + encodeURIComponent(ped.id), { credentials: 'same-origin' })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            var el = document.getElementById('peDetailItens');
                            if (!el) return;
                            if (!data || !data.success || !Array.isArray(data.itens) || !data.itens.length) {
                                el.innerHTML = '<div class="text-muted small">' + escapeHtml(ped.itens_resumo || 'Sem itens') + '</div>';
                                return;
                            }
                            var rows = data.itens.map(function(item) {
                                return '<div class="pe-item-row"><span>' + escapeHtml(item.produto_nome) + ' <small class="text-muted">' + escapeHtml(item.quantidade) + 'x</small></span><strong>' + formatMoney(item.subtotal) + '</strong></div>';
                            }).join('');
                            el.innerHTML = rows;
                        })
                        .catch(function() {
                            var el = document.getElementById('peDetailItens');
                            if (el) el.innerHTML = '<div class="text-muted small">' + escapeHtml(ped.itens_resumo || 'Sem itens') + '</div>';
                        });
                });
            }
        }

        function selectEmpPedido(id) {
            selectedEmpPedidoId = parseInt(id, 10);
            var ped = (currentPedidos || []).find(function(p) {
                return parseInt(p.id, 10) === selectedEmpPedidoId;
            });
            var area = document.getElementById('pedidosArea');
            if (area && isEmpresarialUi) {
                area.innerHTML = buildEmpTableHtml(currentPedidos);
            }
            renderEmpDetail(ped || null);
        }

        function detectReadyAlerts(pedidos) {
            var readyIds = new Set((pedidos || []).filter(function(p) {
                return normalizarStatus(p.status) === 'PRONTO';
            }).map(function(p) {
                return String(p.id);
            }));

            if (previousReadyIds.size > 0) {
                readyIds.forEach(function(id) {
                    if (!previousReadyIds.has(id)) {
                        playReadySound();
                    }
                });
            }

            previousReadyIds = readyIds;
        }

        function getPedidoTempoAtendimentoLabel(ped) {
            return window.NivoraPedidoTempo.serviceLabel(ped);
        }

        function buildKanbanHtml(pedidos) {
            if (!pedidos || pedidos.length === 0) {
                return '<div class="card text-center py-5" style="border: 1px dashed #d7dde5; background: linear-gradient(135deg, rgba(255,107,53,0.05), rgba(255,255,255,0.95));">' +
                    '<i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>' +
                    '<h5 class="fw-bold">Nenhum pedido hoje</h5>' +
                    '<p class="text-muted mb-0">' + (isEmpresarialUi
                        ? 'A fila aparece aqui em tempo real assim que houver pedidos.'
                        : 'Pedidos via QR Code aparecerão aqui assim que chegarem.') + '</p>' +
                    '</div>';
            }

            var columns = getStatusColumns();
            var html = '<div class="kanban">';

            Object.keys(columns).forEach(function(status) {
                var info = columns[status];
                var pedidosColuna = pedidos.filter(function(p) {
                    return normalizarStatus(p.status) === status;
                });

                html += '<div class="kanban-col">';
                html += '<div class="kanban-header bg-' + info.cor + '">';
                html += '<div>';
                html += '<div>' + info.titulo + '</div>';
                if (info.subtitulo) html += '<div style="font-size:11px;opacity:0.75;font-weight:400;margin-top:2px;">' + info.subtitulo + '</div>';
                html += '</div>';
                html += '</div>';

                if (pedidosColuna.length === 0) {
                    html += '<p class="text-muted text-center small py-4">Nenhum</p>';
                }

                pedidosColuna.forEach(function(ped) {
                    var statusAtual = normalizarStatus(ped.status);
                    var origemMeta = getOrigemPedidoMeta(ped.origem);
                    var mesaLabel = getMesaPedidoLabel(ped, origemMeta.className === 'balcao' ? 'Take Away' : 'Sem mesa');
                    var clienteNome = ped.cliente_nome || ped.nome_cliente || ped.nome || ped.cliente || 'Cliente';
                    var hora = '';
                    if (ped.criado_em && ped.criado_em.length >= 16) {
                        hora = ped.criado_em.substring(11, 16);
                    }
                    var ageMins = getPedidoAgeMinutes(ped.criado_em);
                    var ageLabel = getPedidoTempoAtendimentoLabel(ped);
                    var naFila = statusAtual === 'NOVO' || statusAtual === 'PREPARANDO' || statusAtual === 'PRONTO';
                    var isAtrasado = marcarAtrasos && naFila && ageMins >= FILA_SLA_MINUTOS;
                    var ageClass = isAtrasado ? 'pedido-age-pill' : ((ageMins > 0 && ageMins < 10) ? 'text-success' : 'text-muted');

                    html += '<div class="pedido-card status-' + statusAtual + (isAtrasado ? ' is-atrasado' : '') + '">';
                    html += '<div class="pedido-meta-row">';
                    html += '<div><div class="fw-bold">' + escapeHtml(ped.numero_pedido) + '</div><div class="pedido-client">' + escapeHtml(clienteNome) + '</div></div>';
                    html += '<div class="text-end"><div class="pedido-time-pill">' + escapeHtml(hora) + '</div><div class="small ' + ageClass + ' mt-1">' + escapeHtml(ageLabel) + (isAtrasado ? ' · atrasado' : '') + '</div></div>';
                    html += '</div>';
                    html += '<div class="d-flex justify-content-between align-items-center mb-2 gap-2 flex-wrap">';
                    html += '<span class="pedido-origin-badge ' + origemMeta.className + '">';
                    html += '<i class="fas ' + origemMeta.icon + '"></i> ' + escapeHtml(origemMeta.label) + '</span>';
                    html += '<span class="status-pill">' + escapeHtml(statusAtual) + '</span>';
                    html += '</div>';
                    html += '<div class="small text-muted mb-2">' + escapeHtml(mesaLabel) + ' &bull; ' + parseInt(ped.total_itens || 0, 10) + ' item(s)</div>';
                    html += '<div class="fw-bold text-primary mb-3">' + formatMoney(ped.total) + '</div>';
                    html += '<div class="d-flex gap-2 flex-wrap">';

                    if (statusAtual === 'PRONTO' || statusAtual === 'ENTREGUE') {
                        if (statusAtual === 'PRONTO' && podeAvancarStatus(statusAtual, 'ENTREGUE')) {
                            html += '<button class="btn btn-sm btn-primary" onclick="avancarPedido(this, ' + parseInt(ped.id, 10) + ', \'ENTREGUE\')">';
                            html += '<i class="fas fa-hand-holding me-1"></i> Entregar</button>';
                        }
                        if (canRequestBill) {
                            html += '<button class="btn btn-sm btn-warning text-dark" onclick="avancarPedido(this, ' + parseInt(ped.id, 10) + ', \'CONTA\')">';
                            html += '<i class="fas fa-file-invoice me-1"></i> Pedir Conta</button>';
                        }
                        if (canReceivePayment) {
                            html += '<button class="btn btn-sm btn-success" onclick="abrirPagamentoPedido(' + parseInt(ped.id, 10) + ')">';
                            html += '<i class="fas fa-money-bill-wave me-1"></i> Receber Pagamento</button>';
                        } else if (!canRequestBill) {
                            html += '<button class="btn btn-sm btn-secondary" disabled title="Apenas perfil CAIXA, ADMIN ou GERENTE">';
                            html += '<i class="fas fa-lock me-1"></i> Aguardando Caixa</button>';
                        }
                    } else if (statusAtual === 'CONTA') {
                        if (canReceivePayment) {
                            html += '<button class="btn btn-sm btn-success" onclick="abrirPagamentoPedido(' + parseInt(ped.id, 10) + ')">';
                            html += '<i class="fas fa-money-bill-wave me-1"></i> Receber Pagamento</button>';
                        } else {
                            html += '<button class="btn btn-sm btn-secondary" disabled title="Apenas perfil CAIXA, ADMIN ou GERENTE">';
                            html += '<i class="fas fa-lock me-1"></i> Aguardando Caixa</button>';
                        }
                    } else if (info.proximo && info.proximo !== 'PAGO' && info.proximo !== 'CONTA') {
                        if (podeAvancarStatus(statusAtual, info.proximo)) {
                            if (statusAtual === 'NOVO') {
                                html += '<button class="btn btn-sm btn-warning text-dark" onclick="avancarPedido(this, ' + parseInt(ped.id, 10) + ', \'' + info.proximo + '\')">';
                                html += '<i class="fas fa-fire me-1"></i> Enviar p/ Preparo</button>';
                            } else if (statusAtual === 'PREPARANDO') {
                                html += '<button class="btn btn-sm btn-success" onclick="avancarPedido(this, ' + parseInt(ped.id, 10) + ', \'' + info.proximo + '\')">';
                                html += '<i class="fas fa-check me-1"></i> Marcar Pronto</button>';
                            } else {
                                html += '<button class="btn btn-sm btn-success" onclick="avancarPedido(this, ' + parseInt(ped.id, 10) + ', \'' + info.proximo + '\')">';
                                html += '<i class="fas fa-arrow-right me-1"></i> Avançar</button>';
                            }
                        } else {
                            html += '<button class="btn btn-sm btn-secondary" disabled title="Acompanhamento apenas">';
                            html += '<i class="fas fa-eye me-1"></i> Acompanhando</button>';
                        }
                    }

                    html += '<button class="btn btn-sm btn-info" onclick="verItensPedido(' + parseInt(ped.id, 10) + ')"><i class="fas fa-eye me-1"></i> Itens</button>';

                    if (canCancelOrder && statusAtual !== 'ENTREGUE' && statusAtual !== 'CONTA' && statusAtual !== 'PAGO') {
                        html += '<button class="btn btn-sm btn-danger" onclick="cancelarPedido(this, ' + parseInt(ped.id, 10) + ')"><i class="fas fa-times me-1"></i> Cancelar</button>';
                    }

                    html += '</div></div>';
                });

                html += '</div>';
            });

            html += '</div>';
            return html;
        }

        function renderPedidos(payload) {
            if (!payload) return;
            var pedidos = Array.isArray(payload.pedidos) ? payload.pedidos : [];
            detectReadyAlerts(pedidos);
            currentPedidos = pedidos;
            var contadores = payload.contadores || {
                NOVO: 0,
                PREPARANDO: 0,
                PRONTO: 0,
                ENTREGUE: 0,
                CONTA: 0,
                PAGO: 0,
                CANCELADO: 0
            };
            updateCounters(contadores);
            updateFilaOpsUi(pedidos, contadores);
            updateHojeKpi(pedidos);
            updateEmpTabAtrasados(pedidos);
            updateKpisPadrao(pedidos, contadores);
            verificarPedidosNovosParaSom(pedidos);
            var area = document.getElementById('pedidosArea');
            if (!area) return;
            if (isEmpresarialUi) {
                area.innerHTML = buildEmpTableHtml(pedidos);
                var stillThere = selectedEmpPedidoId && pedidos.some(function(p) {
                    return parseInt(p.id, 10) === parseInt(selectedEmpPedidoId, 10);
                });
                if (!stillThere) {
                    selectedEmpPedidoId = pedidos.length ? parseInt(pedidos[0].id, 10) : null;
                }
                if (selectedEmpPedidoId) {
                    selectEmpPedido(selectedEmpPedidoId);
                } else {
                    renderEmpDetail(null);
                }
            } else {
                area.innerHTML = buildKanbanHtml(pedidos);
            }
        }
