// Pedidos - QR do cardapio, avancar/cancelar pedidos, detalhe de itens e pagamento.
// Parte de pedidos.php; carregado por ordem com os outros ficheiros de js/pedidos/.

        function baixarQRCode() {
            if (!cardapioUrl) {
                NivoraFeedback.warning('O link seguro do cardápio não está disponível. Atualize a página e tente novamente.');
                return;
            }
            var dataUrl = gerarQRDataURL(cardapioUrl, 400);
            var link = document.createElement('a');
            link.download = 'qrcode-cardapio.png';
            link.href = dataUrl;
            link.click();
        }

        function imprimirQRCodes() {
            if (todasMesas.length === 0) {
                NivoraFeedback.show('Nenhuma mesa cadastrada.');
                return;
            }
            var h = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>QR Codes</title>';
            h += '<link rel="icon" href="favicon.ico" type="image/x-icon">';
            h += '<style>body{font-family:Arial;padding:20px;}h1{text-align:center;}.grid{display:flex;flex-wrap:wrap;gap:20px;justify-content:center;}.qi{text-align:center;padding:15px;border:2px solid #333;border-radius:10px;width:180px;}.qi img{display:block;margin:0 auto;}</style></head><body>';
            h += '<h1>QR Codes do Restaurante</h1><div class="grid">';
            if (cardapioUrl) {
                h += '<div class="qi"><img src="' + gerarQRDataURL(cardapioUrl, 200) + '" width="150"><h3>Geral</h3></div>';
            }
            for (var i = 0; i < todasMesas.length; i++) {
                var m = todasMesas[i];
                var mu = mesaQrUrls[String(m.id)] || mesaQrUrls[m.id] || '';
                if (!mu) continue;
                h += '<div class="qi"><img src="' + gerarQRDataURL(mu, 200) + '" width="150"><h3>Mesa ' + m.numero + '</h3></div>';
            }
            h += '</div></body></html>';
            var win = window.open('', '_blank');
            win.document.write(h);
            win.document.close();
        }

        async function avancarPedido(btnEl, id, status) {
            var stLabel = statusUiLabel(status);
            var msg = (String(status).toUpperCase() === 'CONTA')
                ? 'Confirmar pedido de conta ao cliente?'
                : ('Avançar para ' + stLabel + '?');
            if (!(await nivoraConfirmar(msg))) return;

            // Show loading
            const btn = btnEl;
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            btn.disabled = true;

            var fd = new FormData();
            fd.append('id', id);
            fd.append('status', status);
            fd.append('_csrf', CSRF_TOKEN);
            OfflineSync.fetchQueued('api/pedido_status.php', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    headers: {
                        'X-CSRF-Token': CSRF_TOKEN,
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(r => {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(data => {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                    if (data.success) {
                        refreshPedidosData();
                    } else {
                        NivoraFeedback.error('Erro: ' + (data.message || 'Falha desconhecida'));
                    }
                })
                .catch(err => {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                    NivoraFeedback.error('Erro de conexão: ' + err.message);
                });
        }

        async function cancelarPedido(btnEl, id) {
            if (!(await nivoraConfirmar({ mensagem: 'Cancelar este pedido?', confirmar: 'Cancelar pedido', cancelar: 'Voltar', perigo: true }))) return;

            // Show loading
            const btn = btnEl;
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            btn.disabled = true;

            var fd = new FormData();
            fd.append('id', id);
            fd.append('status', 'CANCELADO');
            fd.append('_csrf', CSRF_TOKEN);
            OfflineSync.fetchQueued('api/pedido_status.php', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    headers: {
                        'X-CSRF-Token': CSRF_TOKEN,
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(r => {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(data => {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                    if (data.success) {
                        refreshPedidosData();
                    } else {
                        NivoraFeedback.error('Erro: ' + (data.message || 'Falha desconhecida'));
                    }
                })
                .catch(err => {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                    if (err && err.status === 428) return; // gerente nao autorizou (janela cancelada)
                    NivoraFeedback.error('Erro de conexão: ' + err.message);
                });
        }

        function verItensPedido(id) {
            var pedidoId = parseInt(id, 10);
            if (!pedidoId) {
                NivoraFeedback.warning('Pedido inválido.');
                return;
            }

            var lista = document.getElementById('listaItens');
            var modalEl = document.getElementById('modalItens');
            if (!lista || !modalEl) {
                NivoraFeedback.error('Modal de detalhes indisponível.');
                return;
            }

            lista.innerHTML = '<div class="text-center py-3 text-muted"><i class="fas fa-spinner fa-spin me-2"></i>A carregar…</div>';

            var showModal = function() {
                if (window.bootstrap && bootstrap.Modal) {
                    var instance = bootstrap.Modal.getOrCreateInstance
                        ? bootstrap.Modal.getOrCreateInstance(modalEl)
                        : new bootstrap.Modal(modalEl);
                    instance.show();
                } else {
                    modalEl.classList.add('show');
                    modalEl.style.display = 'block';
                }
            };
            showModal();

            // Fallback imediato a partir da lista em memória (enquanto a API responde)
            var pedLocal = (currentPedidos || []).find(function(p) {
                return parseInt(p.id, 10) === pedidoId;
            });
            if (pedLocal && pedLocal.itens_resumo) {
                lista.innerHTML = '<div class="mb-2">' + escapeHtml(pedLocal.itens_resumo) + '</div>' +
                    '<div class="d-flex justify-content-between fw-bold"><span>Total</span><span class="text-primary">' + formatMoney(pedLocal.total) + '</span></div>' +
                    '<p class="text-muted small mt-2 mb-0">A carregar detalhe…</p>';
            }

            withRealtimePaused(function() {
                var controller = new AbortController();
                var timeoutId = setTimeout(function() { controller.abort(); }, 12000);
                return fetch('api/pedido_itens.php?id=' + encodeURIComponent(pedidoId), {
                    credentials: 'same-origin',
                    signal: controller.signal,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(function(r) { return r.text(); })
                    .then(function(raw) {
                        clearTimeout(timeoutId);
                        var data = null;
                        try { data = raw ? JSON.parse(raw) : null; } catch (e) {
                            throw new Error('Resposta inválida do servidor');
                        }
                        if (!data || !data.success) {
                            throw new Error((data && data.message) || 'Não foi possível carregar os itens.');
                        }

                        var html = '';
                        var titulo = document.querySelector('#modalItens .modal-title');
                        if (titulo && data.pedido) {
                            titulo.innerHTML = '<i class="fas fa-list me-2"></i>Pedido #' + escapeHtml(data.pedido.numero_pedido || data.pedido.id);
                        }

                        var stPedido = String((data.pedido && data.pedido.status) || '').toUpperCase();
                        var itensLista = data.itens || [];
                        var podeRemover = canCancelOrder && stPedido !== 'PAGO' && stPedido !== 'CANCELADO' && itensLista.length > 1;
                        itensLista.forEach(function(item) {
                            html += '<div class="d-flex justify-content-between align-items-center py-2 border-bottom">';
                            html += '<span><strong>' + escapeHtml(item.produto_nome) + '</strong><br><small class="text-muted">' + escapeHtml(item.quantidade) + 'x ' + parseFloat(item.preco_unitario || 0).toFixed(2).replace('.', ',') + ' MZN</small></span>';
                            html += '<span class="d-flex align-items-center gap-2"><strong class="text-primary">' + parseFloat(item.subtotal || 0).toFixed(2).replace('.', ',') + ' MZN</strong>';
                            if (podeRemover) {
                                html += '<button type="button" class="btn btn-sm btn-outline-danger" title="Remover item" aria-label="Remover ' + escapeHtml(item.produto_nome) + '" onclick="removerItemPedido(this, ' + parseInt(item.id, 10) + ', ' + pedidoId + ')"><i class="fas fa-trash"></i></button>';
                            }
                            html += '</span></div>';
                        });
                        if (stPedido === 'PAGO' && canReceivePayment) {
                            html += '<div class="mt-3 text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="anularPagamentoPedido(this, ' + pedidoId + ')"><i class="fas fa-rotate-left me-1"></i> Anular pagamento</button></div>';
                        }

                        if (data.observacao) {
                            html += '<div class="mt-3 small"><strong>Observação:</strong> ' + escapeHtml(data.observacao) + '</div>';
                        }
                        if (data.pedido && data.pedido.total != null) {
                            html += '<div class="mt-3 d-flex justify-content-between fw-bold"><span>Total</span><span class="text-primary">' + formatMoney(data.pedido.total) + '</span></div>';
                        }

                        lista.innerHTML = html || '<p class="text-muted mb-0">Sem itens</p>';
                    })
                    .catch(function(err) {
                        clearTimeout(timeoutId);
                        if (pedLocal) {
                            lista.innerHTML = '<div class="mb-2">' + escapeHtml(pedLocal.itens_resumo || 'Itens indisponíveis') + '</div>' +
                                '<div class="d-flex justify-content-between fw-bold"><span>Total</span><span class="text-primary">' + formatMoney(pedLocal.total) + '</span></div>';
                        } else {
                            lista.innerHTML = '<p class="text-danger mb-0">' + escapeHtml((err && err.message) || 'Erro ao carregar detalhes.') + '</p>';
                        }
                        console.error(err);
                    });
            });
        }
        window.verItensPedido = verItensPedido;

        // Acoes sensiveis: garcom/caixa precisam do PIN de um gerente (js/autorizacao_gerente.js).
        function postSensivel(url, campos) {
            var fd = new FormData();
            Object.keys(campos).forEach(function(k) { fd.append(k, campos[k]); });
            fd.append('_csrf', CSRF_TOKEN);
            return fetch(url, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: { 'X-CSRF-Token': CSRF_TOKEN, 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function(r) {
                return r.json().catch(function() { return { success: false, message: 'Erro inesperado (' + r.status + ').' }; });
            });
        }

        async function removerItemPedido(btn, itemId, pedidoId) {
            if (!(await nivoraConfirmar({ titulo: 'Remover este item do pedido?', mensagem: 'Fica registado quem removeu.', confirmar: 'Remover', perigo: true }))) return;
            btn.disabled = true;
            postSensivel('api/pedido_item_remover.php', { item_id: itemId }).then(function(d) {
                btn.disabled = false;
                if (!d.success) { if (!d.requer_autorizacao) NivoraFeedback.error(d.message || 'Não foi possível remover o item.'); return; }
                verItensPedido(pedidoId);
                refreshPedidosData();
            }).catch(function() { btn.disabled = false; NivoraFeedback.error('Sem ligação.'); });
        }
        window.removerItemPedido = removerItemPedido;

        async function anularPagamentoPedido(btn, pedidoId) {
            if (!(await nivoraConfirmar({ titulo: 'Anular o pagamento deste pedido?', mensagem: 'A venda é removida e o pedido volta a "Entregue".', confirmar: 'Anular pagamento', perigo: true }))) return;
            btn.disabled = true;
            postSensivel('api/pedido_cancelar_pagamento.php', { id: pedidoId }).then(function(d) {
                btn.disabled = false;
                if (!d.success) { if (!d.requer_autorizacao) NivoraFeedback.error(d.message || 'Não foi possível anular o pagamento.'); return; }
                verItensPedido(pedidoId);
                refreshPedidosData();
            }).catch(function() { btn.disabled = false; NivoraFeedback.error('Sem ligação.'); });
        }
        window.anularPagamentoPedido = anularPagamentoPedido;

        var _pagModal = null;

        function getPagModal() {
            if (!_pagModal) _pagModal = new bootstrap.Modal(document.getElementById('modalPagamento'));
            return _pagModal;
        }

        function syncPagamentoCampos() {
            var forma = String(document.getElementById('pagForma').value || 'DINHEIRO').toUpperCase();
            var refWrap = document.getElementById('pagRefWrap');
            var valorWrap = document.getElementById('pagValorWrap');
            var refLabel = document.getElementById('pagRefLabel');
            var refHint = document.getElementById('pagRefHint');
            var precisaRef = forma === 'MPESA' || forma === 'EMOLA' || forma === 'TRANSFERENCIA' || forma === 'CARTAO';
            if (refWrap) refWrap.style.display = precisaRef ? '' : 'none';
            if (valorWrap) valorWrap.style.display = forma === 'DINHEIRO' ? '' : 'none';
            if (refLabel) {
                if (forma === 'MPESA') refLabel.textContent = 'Referência M-Pesa';
                else if (forma === 'EMOLA') refLabel.textContent = 'Referência e-Mola';
                else if (forma === 'CARTAO') refLabel.textContent = 'NSU / autorizacao POS';
                else refLabel.textContent = 'Referência da transferência';
            }
            if (refHint) {
                refHint.textContent = (forma === 'MPESA' || forma === 'EMOLA')
                    ? 'Obrigatório — ID do comprovativo no telemóvel.'
                    : 'Opcional, mas recomendado para auditoria.';
            }
        }
        window.syncPagamentoCampos = syncPagamentoCampos;

        function abrirPagamentoPedido(id) {
            if (!canReceivePayment) {
                NivoraFeedback.warning('Apenas o perfil CAIXA, ADMIN ou GERENTE pode receber pagamento.');
                return;
            }

            var pedido = currentPedidos.find(function(p) {
                return parseInt(p.id, 10) === parseInt(id, 10);
            });

            if (!pedido) {
                NivoraFeedback.warning('Pedido não encontrado na lista atual.');
                return;
            }

            pedidoSelecionadoId = parseInt(id, 10);
            document.getElementById('pagForma').value = 'DINHEIRO';
            document.getElementById('pagDesconto').value = '0';
            document.getElementById('pagObs').value = '';
            var refEl = document.getElementById('pagReferencia');
            if (refEl) refEl.value = '';
            var valorEl = document.getElementById('pagValorRecebido');
            if (valorEl) valorEl.value = '';
            syncPagamentoCampos();

            var mesaTxt = getMesaPedidoLabel(pedido, 'Sem mesa');
            document.getElementById('pagPedidoInfo').textContent = 'Pedido #' + (pedido.numero_pedido || pedido.id) + ' • ' + mesaTxt;
            document.getElementById('pagTotalLabel').textContent = formatMoney(pedido.total || 0);

            // Resetar para estado de formulário
            document.getElementById('pagFormBody').style.display = '';
            document.getElementById('pagSuccessBody').style.display = 'none';
            document.getElementById('pagFooter').style.display = '';

            getPagModal().show();
        }

        function confirmarPagamentoPedido() {
            if (!canReceivePayment) {
                NivoraFeedback.warning('Apenas o perfil CAIXA, ADMIN ou GERENTE pode finalizar venda.');
                return;
            }

            if (!pedidoSelecionadoId) {
                NivoraFeedback.warning('Nenhum pedido selecionado.');
                return;
            }

            var forma = String(document.getElementById('pagForma').value || 'DINHEIRO').toUpperCase();
            var referencia = String((document.getElementById('pagReferencia') || {}).value || '').trim();
            if ((forma === 'MPESA' || forma === 'EMOLA' || forma === 'TRANSFERENCIA' || forma === 'CARTAO') && !referencia) {
                var agora = new Date();
                var pad = function(n) { return String(n).padStart(2, '0'); };
                var stamp = agora.getFullYear() + pad(agora.getMonth() + 1) + pad(agora.getDate())
                    + pad(agora.getHours()) + pad(agora.getMinutes()) + pad(agora.getSeconds());
                referencia = forma + '-' + stamp + '-P' + String(pedidoSelecionadoId || 0);
                var refInput = document.getElementById('pagReferencia');
                if (refInput) refInput.value = referencia;
            }

            // O recibo e carregado num iframe oculto pelo modulo central.
            var reciboPopup = null;

            var btn = document.getElementById('btnConfirmarPagamento');
            var original = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Processando…';

            var fd = new FormData();
            fd.append('id', pedidoSelecionadoId);
            fd.append('forma_pagamento', forma);
            fd.append('desconto', document.getElementById('pagDesconto').value || '0');
            fd.append('observacao', document.getElementById('pagObs').value || '');
            if (referencia) fd.append('referencia', referencia);
            var valorRecebido = String((document.getElementById('pagValorRecebido') || {}).value || '').trim();
            if (forma === 'DINHEIRO' && valorRecebido !== '') {
                fd.append('valor_recebido', valorRecebido);
            }
            fd.append('_csrf', CSRF_TOKEN);

            // Em servidor local (php -S), pausar SSE evita travamento de requisição.
            var realtimePaused = pauseRealtime();

            var controller = new AbortController();
            var timeoutId = setTimeout(function() {
                controller.abort();
            }, 15000);

            OfflineSync.fetchQueued('api/pedido_pagar.php', {
                    method: 'POST',
                    body: fd,
                    signal: controller.signal,
                    credentials: 'same-origin',
                    headers: {
                        'X-CSRF-Token': CSRF_TOKEN,
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(r) {
                    return r.text();
                })
                .then(function(raw) {
                    var data;
                    try {
                        data = JSON.parse(raw);
                    } catch (e) {
                        btn.disabled = false;
                        btn.innerHTML = original;
                        NivoraFeedback.error('Resposta inválida do servidor:\n' + raw.substring(0, 300));
                        return;
                    }

                    if (!data.success) {
                        if (reciboPopup && !reciboPopup.closed) {
                            reciboPopup.close();
                        }
                        btn.disabled = false;
                        btn.innerHTML = original;
                        NivoraFeedback.error('Erro: ' + (data.message || 'Falha ao receber pedido'));
                        return;
                    }

                    // Sucesso: mostrar estado de confirmação com link clicável
                    document.getElementById('pagFormBody').style.display = 'none';
                    document.getElementById('pagFooter').style.display = 'none';

                    var infoEl = document.getElementById('pagSuccessInfo');
                    infoEl.textContent = 'Fatura ' + (data.numero_fatura || '') + ' registrada com sucesso.';

                    var linkEl = document.getElementById('btnComprovante');
                    var linkVer = document.getElementById('btnComprovanteVer');
                    var reciboUrl = 'comprovante.php?id=' + data.venda_id + '&auto_print=1';
                    var reciboUrlVer = 'comprovante.php?id=' + data.venda_id;
                    if (linkEl) linkEl.href = reciboUrl;
                    if (linkVer) linkVer.href = reciboUrlVer;

                    if (data.venda_id) {
                        ReceiptPrint.print(data.venda_id).catch(function(error) {
                            console.error('Falha ao imprimir recibo', error);
                        });
                    }

                    document.getElementById('pagSuccessBody').style.display = '';

                    btn.disabled = false;
                    btn.innerHTML = original;

                    pedidoSelecionadoId = null;
                    refreshPedidosData();
                })
                .catch(function(err) {
                    if (reciboPopup && !reciboPopup.closed) {
                        reciboPopup.close();
                    }
                    btn.disabled = false;
                    btn.innerHTML = original;
                    if (err && err.name === 'AbortError') {
                        NivoraFeedback.error('Tempo limite ao finalizar venda. Tente novamente.');
                    } else {
                        NivoraFeedback.error('Erro de conexão: ' + err.message);
                    }
                })
                .finally(function() {
                    clearTimeout(timeoutId);
                    resumeRealtime(realtimePaused);
                });
        }

        // Realtime via SSE + fallback polling substitui reload automático.
