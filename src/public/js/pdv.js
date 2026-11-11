/**
 * ============================================
 * JAVASCRIPT - PDV (Ponto de Venda)
 * ============================================
 */

let carrinho = [];

function adicionarProdutoCarrinho(el) {
    // Ler dados do atributo data-
    const root = el.closest('.produto-item');
    const produto = {
        id: parseInt(root.dataset.id),
        nome: root.dataset.nome,
        preco: parseFloat(root.dataset.preco),
        estoque: parseInt(root.dataset.estoque),
        sem_ficha: root.dataset.semFicha === '1'
    };
    
    adicionarAoCarrinho(produto);
}

function adicionarAoCarrinho(produto) {
    // Support both 'id' and 'Id' (case insensitive)
    const produtoId = produto.id || produto.Id;
    const produtoNome = produto.nome || produto.Nome;
    const produtoPreco = produto.preco || produto.Preco;
    const produtoEstoque = Number(produto.estoque ?? produto.Estoque);
    const estoqueKnown = Number.isFinite(produtoEstoque);
    const estoqueMax = estoqueKnown ? produtoEstoque : 999;
    const semFicha = !!(produto.sem_ficha || produto.semFicha);
    const exigeFicha = typeof EXIGE_FICHA_CARRINHO !== 'undefined' && EXIGE_FICHA_CARRINHO;
    const stockGuard = (typeof IS_EMPRESARIAL !== 'undefined' && IS_EMPRESARIAL)
        || (typeof ESTOQUE_AVANCADO_PDV !== 'undefined' && ESTOQUE_AVANCADO_PDV);

    if (exigeFicha && semFicha) {
        showAlert('Produto sem ficha técnica / insumos. Complete a ficha antes de vender.', 'warning');
        return;
    }

    if (stockGuard && estoqueKnown && estoqueMax <= 0) {
        showAlert(exigeFicha && semFicha
            ? 'Produto sem ficha técnica / insumos.'
            : 'Produto esgotado — sem porções disponíveis.', 'warning');
        return;
    }

    const index = carrinho.findIndex(item => item.id === produtoId);
    const qtdAtual = index !== -1 ? (Number(carrinho[index].quantidade) || 0) : 0;
    if (stockGuard && estoqueKnown && (qtdAtual + 1) > estoqueMax) {
        showAlert('Só restam ' + estoqueMax + ' porção(ões) deste produto.', 'warning');
        return;
    }

    if(index !== -1) {
        carrinho[index].quantidade++;
        if (estoqueKnown) carrinho[index].estoque = estoqueMax;
    } else {
        carrinho.push({
            id: produtoId,
            nome: produtoNome,
            preco: parseFloat(produtoPreco),
            quantidade: 1,
            estoque: estoqueKnown ? estoqueMax : null
        });
    }
    atualizarCarrinho();
    showAlert('Produto adicionado ao carrinho!', 'success');
}

function atualizarCarrinho() {
    const container = document.getElementById('carrinhoItens');
    const countEl = document.getElementById('pdvCartCount');
    const emp = typeof IS_EMPRESARIAL !== 'undefined' && IS_EMPRESARIAL;
    const itemCount = carrinho.reduce((s, it) => s + (Number(it.quantidade) || 0), 0);
    if (countEl) countEl.textContent = String(itemCount);

    if(carrinho.length === 0) {
        container.innerHTML = emp
            ? '<div class="pdv-emp-cart-empty"><i class="fas fa-basket-shopping"></i><strong>Carrinho vazio</strong><span>Toque num produto do catálogo para começar.</span></div>'
            : '<div style="text-align: center; padding: 40px; color: #999;">🛒<br>Carrinho vazio</div>';
        document.getElementById('subtotal').textContent = '0,00 MZN';
        document.getElementById('total').textContent = '0,00 MZN';
        if (typeof sincronizarModoCarrinhoPdv === 'function') sincronizarModoCarrinhoPdv();
        else if (typeof atualizarPagamentoCarrinhoUI === 'function') atualizarPagamentoCarrinhoUI();
        return;
    }
    
    let html = '';
    carrinho.forEach((item, index) => {
        const subtotal = item.preco * item.quantidade;
        if (emp) {
            const est = item.estoque;
            const stockHint = (est != null && Number.isFinite(Number(est)) && Number(est) <= 5)
                ? `<span class="pdv-emp-cart-stock">Stock: ${Number(est)}</span>`
                : '';
            html += `
            <div class="pdv-emp-cart-item">
                <div class="pdv-emp-cart-item-main">
                    <strong>${escHtmlPdv(item.nome)}</strong>
                    <span>${formatarMoeda(item.preco)} · un</span>
                    ${stockHint}
                </div>
                <div class="pdv-emp-cart-qty">
                    <button type="button" onclick="alterarQuantidade(${index}, -1)" aria-label="Diminuir">−</button>
                    <em>${item.quantidade}</em>
                    <button type="button" onclick="alterarQuantidade(${index}, 1)" aria-label="Aumentar">+</button>
                </div>
                <div class="pdv-emp-cart-sub">${formatarMoeda(subtotal)}</div>
                <button type="button" class="pdv-emp-cart-del" onclick="removerItem(${index})" title="Remover"><i class="fas fa-trash"></i></button>
            </div>`;
        } else {
            html += `
            <div class="carrinho-item">
                <div class="item-info">
                    <div class="item-nome">${item.nome}</div>
                    <div class="item-preco">${formatarMoeda(item.preco)} x ${item.quantidade}</div>
                </div>
                <div class="item-qtd">
                    <button class="btn-qtd" onclick="alterarQuantidade(${index}, -1)">-</button>
                    <span>${item.quantidade}</span>
                    <button class="btn-qtd" onclick="alterarQuantidade(${index}, 1)">+</button>
                </div>
                <div class="item-subtotal">${formatarMoeda(subtotal)}</div>
                <button class="btn-remover" onclick="removerItem(${index})">🗑️</button>
            </div>
        `;
        }
    });
    
    container.innerHTML = html;
    calcularTotal();
}

function escHtmlPdv(str) {
    return String(str == null ? '' : str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function alterarQuantidade(index, delta) {
    if (!carrinho[index]) return;
    const stockGuard = (typeof IS_EMPRESARIAL !== 'undefined' && IS_EMPRESARIAL)
        || (typeof ESTOQUE_AVANCADO_PDV !== 'undefined' && ESTOQUE_AVANCADO_PDV);
    if (stockGuard && delta > 0) {
        const est = carrinho[index].estoque;
        if (est != null && Number.isFinite(Number(est)) && (Number(carrinho[index].quantidade) + 1) > Number(est)) {
            showAlert('Só restam ' + est + ' porção(ões) deste produto.', 'warning');
            return;
        }
    }
    carrinho[index].quantidade += delta;
    if(carrinho[index].quantidade <= 0) {
        carrinho.splice(index, 1);
    }
    atualizarCarrinho();
}

function removerItem(index) {
    carrinho.splice(index, 1);
    atualizarCarrinho();
}

function calcularTotal() {
    let subtotal = 0;
    carrinho.forEach(item => {
        subtotal += item.preco * item.quantidade;
    });
    const descEl = document.getElementById('desconto');
    let desconto = parseFloat(descEl?.value) || 0;
    if (desconto < 0) { desconto = 0; if (descEl) descEl.value = 0; }
    if (desconto > subtotal) { desconto = subtotal; if (descEl && carrinho.length) descEl.value = subtotal.toFixed(2); }
    const total = Math.max(0, subtotal - desconto);
    const subEl = document.getElementById('subtotal');
    const totEl = document.getElementById('total');
    if (subEl) subEl.textContent = formatarMoeda(subtotal);
    if (totEl) totEl.textContent = formatarMoeda(total);
    if (typeof atualizarPagamentoCarrinhoUI === 'function') {
        atualizarPagamentoCarrinhoUI();
    }
    if (typeof sincronizarModoCarrinhoPdv === 'function' && typeof TEM_TELAS_BAR_COZINHA !== 'undefined' && TEM_TELAS_BAR_COZINHA) {
        // Mantém labels/modo após recalcular totais
    }
}

async function limparCarrinho() {
    if(carrinho.length === 0) return;
    if(await nivoraConfirmar({ mensagem: 'Deseja limpar o carrinho?', confirmar: 'Limpar' })) {
        carrinho = [];
        document.getElementById('desconto').value = 0;
        atualizarCarrinho();
    }
}

function calcularTotalCarrinho() {
    let subtotal = 0;
    carrinho.forEach(item => { subtotal += item.preco * item.quantidade; });
    const desconto = parseFloat(document.getElementById('desconto')?.value) || 0;
    return Math.max(0, subtotal - desconto);
}

function pdvTemKds() {
    return typeof TEM_TELAS_BAR_COZINHA !== 'undefined' && !!TEM_TELAS_BAR_COZINHA;
}

function pdvMesaSelecionadaId() {
    const mesaRaw = document.getElementById('mesa_id')?.value || '';
    return mesaRaw ? (parseInt(mesaRaw, 10) || 0) : 0;
}

/** O id seleccionado é um canal de balcão (canónico ou segundo canal)? */
function pdvIdEhBalcao(id) {
    const b1 = (typeof MESA_BALCAO_ID !== 'undefined' ? parseInt(MESA_BALCAO_ID, 10) : 0) || 0;
    const b2 = (typeof MESA_BALCAO2_ID !== 'undefined' ? parseInt(MESA_BALCAO2_ID, 10) : 0) || 0;
    return id > 0 && (id === b1 || (b2 > 0 && id === b2));
}

/** Mesa de balcão a usar no envio: o segundo canal quando seleccionado, senão o canónico. */
function pdvMesaBalcaoEnvio() {
    const sel = pdvMesaSelecionadaId();
    if (pdvIdEhBalcao(sel)) return sel;
    return (typeof MESA_BALCAO_ID !== 'undefined' ? parseInt(MESA_BALCAO_ID, 10) : 0) || 0;
}

function pdvModoMesa() {
    const id = pdvMesaSelecionadaId();
    return pdvTemKds() && id > 0 && !pdvIdEhBalcao(id);
}

/** Alterna UI: mesa = só enviar | balcão = pagar e enviar */
function sincronizarModoCarrinhoPdv() {
    if (!pdvTemKds()) {
        atualizarPagamentoCarrinhoUI();
        return;
    }

    const isMesa = pdvModoMesa();
    const payBlock = document.getElementById('carrinhoPagamentoBlock');
    const mesaActions = document.getElementById('pdvModeMesaActions');
    const balcaoActions = document.getElementById('pdvModeBalcaoActions');
    const totalLabel = document.getElementById('pdvTotalLabel');
    const flowTitle = document.getElementById('pdvFlowTitle');
    const flowText = document.getElementById('pdvFlowText');

    if (payBlock) payBlock.style.display = isMesa ? 'none' : 'block';
    if (mesaActions) mesaActions.style.display = isMesa ? 'block' : 'none';
    if (balcaoActions) balcaoActions.style.display = isMesa ? 'none' : 'block';
    if (totalLabel) totalLabel.textContent = isMesa ? 'TOTAL DA CONTA' : 'TOTAL A PAGAR';

    if (flowTitle && flowText) {
        if (isMesa) {
            flowTitle.textContent = 'Fluxo de salão';
            flowText.textContent = 'Envia à cozinha/bar agora. O cliente paga depois em “A receber” / Contas / Caixa.';
        } else {
            flowTitle.textContent = 'Fluxo de balcão';
            flowText.textContent = 'Paga já e envia à cozinha/bar. Use “Venda directa” só para itens sem preparação.';
        }
    }

    atualizarPagamentoCarrinhoUI();
}

function atualizarPagamentoCarrinhoUI() {
    // Em modo mesa (KDS), o bloco de pagamento fica oculto — não calcula troco.
    if (pdvModoMesa()) {
        return;
    }

    const forma = (document.getElementById('forma_pagamento')?.value || 'DINHEIRO').toUpperCase();
    const valorBox = document.getElementById('carrinhoValorRecebidoBox');
    const trocoBox = document.getElementById('carrinhoTrocoBox');
    const trocoEl = document.getElementById('carrinhoTroco');
    const valorInput = document.getElementById('valor_recebido');
    const payHint = document.getElementById('pdvPayHint');
    const isDinheiro = forma === 'DINHEIRO';

    if (valorBox) valorBox.style.display = isDinheiro ? 'block' : 'none';
    if (trocoBox) trocoBox.style.display = isDinheiro ? 'block' : 'none';

    if (payHint) {
        if (forma === 'DINHEIRO') payHint.textContent = 'Em dinheiro: indique o valor entregue para calcular o troco.';
        else if (forma === 'MPESA' || forma === 'EMOLA') payHint.textContent = 'Confirme o pagamento digital antes de finalizar.';
        else if (forma === 'CARTAO') payHint.textContent = 'Confirme a aprovação do cartão no terminal.';
        else payHint.textContent = 'Confirme a transferência antes de receber o pagamento.';
    }

    if (!isDinheiro || !trocoEl) return;

    const total = calcularTotalCarrinho();
    const recebido = parseFloat(valorInput?.value) || 0;
    const troco = Math.max(0, recebido - total);
    trocoEl.textContent = formatarMoeda(troco);
}

function pdvLimparCarrinhoAposSucesso() {
    carrinho = [];
    const desconto = document.getElementById('desconto');
    if (desconto) desconto.value = 0;
    const mesa = document.getElementById('mesa_id');
    if (mesa) mesa.value = '';
    const valor = document.getElementById('valor_recebido');
    if (valor) valor.value = '';
    atualizarCarrinho();
    sincronizarModoCarrinhoPdv();
}

function pdvValidarPagamentoBalcao() {
    const forma = (document.getElementById('forma_pagamento')?.value || 'DINHEIRO').toUpperCase();
    const total_final = calcularTotalCarrinho();
    let valor_recebido = null;
    let troco = null;

    if (forma === 'DINHEIRO') {
        valor_recebido = parseFloat(document.getElementById('valor_recebido')?.value) || 0;
        if (valor_recebido <= 0) {
            showAlert('Informe o valor recebido em dinheiro.', 'warning');
            return null;
        }
        if (valor_recebido + 0.0001 < total_final) {
            showAlert('Valor recebido inferior ao total a pagar.', 'warning');
            return null;
        }
        troco = Math.round((valor_recebido - total_final) * 100) / 100;
    }

    return { forma, total_final, valor_recebido, troco };
}

function pdvMontarItensPedido() {
    return carrinho.map(item => ({
        id: item.id,
        nome: item.nome,
        preco: item.preco,
        qtd: item.quantidade,
        quantidade: item.quantidade
    }));
}

function pdvSetBotoesBusy(busy, primaryBtn, busyHtml) {
    const ids = ['btnEnviarPedidoCarrinho', 'btnPagarEnviar', 'btnVendaDirecta', 'btnFinalizarVenda'];
    ids.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.disabled = !!busy;
    });
    if (primaryBtn && busyHtml) {
        if (busy) {
            primaryBtn.dataset.originalHtml = primaryBtn.innerHTML;
            primaryBtn.innerHTML = busyHtml;
        } else if (primaryBtn.dataset.originalHtml) {
            primaryBtn.innerHTML = primaryBtn.dataset.originalHtml;
        }
    }
}

/** Imprime o recibo sem abrir outra pagina; em modo kiosk a impressao e silenciosa. */
function pdvAbrirComprovante(vendaId, popupExistente) {
    const id = parseInt(vendaId, 10) || 0;
    if (!id) return null;
    if (popupExistente && !popupExistente.closed) popupExistente.close();
    if (window.ReceiptPrint && typeof window.ReceiptPrint.print === 'function') {
        return window.ReceiptPrint.print(id).catch(function (error) {
            console.error('Falha ao imprimir comprovante', error);
            return null;
        });
    }
    const url = 'comprovante.php?id=' + id + '&auto_print=1';
    window.location.href = url;
    return null;
}

function pdvPrepararJanelaRecibo() {
    return null;
}

/** Venda directa rápida (sem KDS) — só balcão / planos sem cozinha */
function finalizarVenda() {
    if (carrinho.length === 0) {
        showAlert('Adicione produtos ao carrinho!', 'warning');
        return;
    }
    if (pdvModoMesa()) {
        showAlert('Com mesa seleccionada, use “Enviar à cozinha / bar”. O pagamento é depois.', 'warning');
        return;
    }

    const mesa_id = pdvMesaSelecionadaId();
    const desconto = parseFloat(document.getElementById('desconto')?.value) || 0;
    let subtotal = 0;
    carrinho.forEach(item => { subtotal += item.preco * item.quantidade; });
    const pay = pdvValidarPagamentoBalcao();
    if (!pay) return;

    const btn = document.getElementById('btnVendaDirecta') || document.getElementById('btnFinalizarVenda');
    pdvSetBotoesBusy(true, btn, '<span class="spinner-border spinner-border-sm me-1"></span>Processando...');
    const reciboPopup = pdvPrepararJanelaRecibo();

    const dados = {
        mesa_id: mesa_id > 0 ? mesa_id : null,
        itens: carrinho,
        total: subtotal,
        desconto: desconto,
        total_final: pay.total_final,
        forma_pagamento: pay.forma,
        valor_recebido: pay.valor_recebido,
        troco: pay.troco,
        _csrf: (typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : '')
    };

    fetch('api/venda_criar.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': (typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : '')
        },
        body: JSON.stringify(dados)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            let msg = data.message || 'Venda directa registada!';
            if (data.troco != null && pay.forma === 'DINHEIRO') {
                msg += ' Troco: ' + formatarMoeda(parseFloat(data.troco) || 0);
            }
            showAlert(msg, 'success');
            if (data.venda_id) {
                pdvAbrirComprovante(data.venda_id, reciboPopup);
            } else if (reciboPopup && !reciboPopup.closed) {
                reciboPopup.close();
            }
            pdvLimparCarrinhoAposSucesso();
            setTimeout(function () { window.location.reload(); }, 1200);
        } else {
            if (reciboPopup && !reciboPopup.closed) reciboPopup.close();
            showAlert(data.message || 'Falha ao registar a venda.', 'danger');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        if (reciboPopup && !reciboPopup.closed) reciboPopup.close();
        showAlert('Erro ao processar venda: ' + error.message, 'danger');
    })
    .finally(function () {
        pdvSetBotoesBusy(false, btn);
    });
}

/**
 * Mesa (salão): envia pedido à cozinha/bar sem cobrar.
 */
function enviarPedidoCarrinho() {
    if (!pdvTemKds()) {
        showAlert('Este plano não tem ecrãs de cozinha/bar.', 'warning');
        return;
    }
    if (carrinho.length === 0) {
        showAlert('Adicione produtos ao carrinho!', 'warning');
        return;
    }

    const mesaId = pdvMesaSelecionadaId();
    if (!mesaId) {
        showAlert('Seleccione uma mesa de salão para enviar sem pagar agora.', 'warning');
        sincronizarModoCarrinhoPdv();
        return;
    }

    const btn = document.getElementById('btnEnviarPedidoCarrinho');
    pdvSetBotoesBusy(true, btn, '<span class="spinner-border spinner-border-sm me-1"></span>Enviando...');

    const fd = new FormData();
    fd.append('mesa_id', String(mesaId));
    fd.append('itens', JSON.stringify(pdvMontarItensPedido()));
    if (typeof CSRF_TOKEN !== 'undefined') fd.append('_csrf', CSRF_TOKEN);

    fetch('api/pedido_novo.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': (typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : '')
        },
        body: fd
    })
    .then(r => r.text())
    .then(text => {
        let data;
        try { data = JSON.parse(text); } catch (e) { throw new Error('Resposta inválida do servidor'); }
        return data;
    })
    .then(data => {
        if (data.success) {
            let msg = 'Pedido enviado à cozinha/bar. Pague depois em “A receber”.';
            if (data.destino_resumo === 'bar') msg = 'Pedido enviado ao bar. Pague depois em “A receber”.';
            else if (data.destino_resumo === 'cozinha') msg = 'Pedido enviado à cozinha. Pague depois em “A receber”.';
            else if (data.destino_resumo === 'ambos') msg = 'Pedido enviado à cozinha e bar. Pague depois em “A receber”.';
            if (data.numero_pedido) msg += ' Nº ' + data.numero_pedido;
            showAlert(msg, 'success');
            pdvLimparCarrinhoAposSucesso();
            setTimeout(function () { window.location.reload(); }, 900);
        } else {
            showAlert(data.message || 'Não foi possível enviar o pedido.', 'danger');
        }
    })
    .catch(err => {
        console.error(err);
        showAlert('Erro ao enviar pedido: ' + err.message, 'danger');
    })
    .finally(function () {
        pdvSetBotoesBusy(false, btn);
    });
}

/**
 * Balcão / take-away: paga já + cria pedido para KDS.
 */
function pagarEEnviarCarrinho() {
    if (!pdvTemKds()) {
        finalizarVenda();
        return;
    }
    if (carrinho.length === 0) {
        showAlert('Adicione produtos ao carrinho!', 'warning');
        return;
    }
    if (pdvModoMesa()) {
        showAlert('Com mesa seleccionada, use apenas “Enviar à cozinha / bar”.', 'warning');
        return;
    }

    const mesaBalcao = pdvMesaBalcaoEnvio();
    if (!mesaBalcao) {
        showAlert('Crie uma mesa chamada “Balcão” em Mesas para take-away com cozinha.', 'warning');
        return;
    }

    const pay = pdvValidarPagamentoBalcao();
    if (!pay) return;

    const btn = document.getElementById('btnPagarEnviar');
    pdvSetBotoesBusy(true, btn, '<span class="spinner-border spinner-border-sm me-1"></span>A pagar e enviar...');
    const reciboPopup = pdvPrepararJanelaRecibo();

    const fd = new FormData();
    fd.append('mesa_id', String(mesaBalcao));
    fd.append('itens', JSON.stringify(pdvMontarItensPedido()));
    fd.append('observacao', 'TAKEAWAY_PREPAGO');
    if (typeof CSRF_TOKEN !== 'undefined') fd.append('_csrf', CSRF_TOKEN);

    fetch('api/pedido_novo.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': (typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : '')
        },
        body: fd
    })
    .then(r => r.text())
    .then(text => {
        let data;
        try { data = JSON.parse(text); } catch (e) { throw new Error('Resposta inválida ao criar pedido'); }
        if (!data.success) throw new Error(data.message || 'Falha ao criar pedido');
        return data;
    })
    .then(pedidoData => {
        const pedidoId = parseInt(pedidoData.pedido_id || pedidoData.id || 0, 10);
        if (!pedidoId) throw new Error('Pedido criado sem ID');

        const descontoAbs = parseFloat(document.getElementById('desconto')?.value) || 0;
        let subtotal = 0;
        carrinho.forEach(item => { subtotal += item.preco * item.quantidade; });
        const descontoPct = subtotal > 0 ? Math.min(100, (descontoAbs / subtotal) * 100) : 0;

        const payFd = new FormData();
        payFd.append('id', String(pedidoId));
        payFd.append('forma_pagamento', pay.forma);
        payFd.append('desconto', String(descontoPct));
        payFd.append('pagar_ao_enviar', '1');
        if (pay.valor_recebido != null) payFd.append('valor_recebido', String(pay.valor_recebido));
        if (typeof CSRF_TOKEN !== 'undefined') payFd.append('_csrf', CSRF_TOKEN);

        return fetch('api/pedido_pagar.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': (typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : '')
            },
            body: payFd
        })
        .then(r => r.text())
        .then(text => {
            let data;
            try { data = JSON.parse(text); } catch (e) { throw new Error('Pedido criado, mas falhou o pagamento'); }
            if (!data.success) throw new Error(data.message || 'Pedido criado, mas o pagamento falhou. Receba em “A receber”.');
            return { pedidoData, payData: data };
        });
    })
    .then(({ pedidoData, payData }) => {
        let msg = 'Pago e enviado à cozinha/bar!';
        if (pedidoData.numero_pedido) msg += ' Pedido Nº ' + pedidoData.numero_pedido;
        if (payData.numero_fatura) msg += ' · Fatura ' + payData.numero_fatura;
        if (payData.troco != null && pay.forma === 'DINHEIRO') {
            msg += ' · Troco: ' + formatarMoeda(parseFloat(payData.troco) || 0);
        }
        showAlert(msg, 'success');
        if (payData.venda_id) {
            pdvAbrirComprovante(payData.venda_id, reciboPopup);
        } else if (reciboPopup && !reciboPopup.closed) {
            reciboPopup.close();
        }
        pdvLimparCarrinhoAposSucesso();
        setTimeout(function () { window.location.reload(); }, 1200);
    })
    .catch(err => {
        console.error(err);
        if (reciboPopup && !reciboPopup.closed) reciboPopup.close();
        showAlert(err.message || 'Erro no fluxo pagar e enviar', 'danger');
    })
    .finally(function () {
        pdvSetBotoesBusy(false, btn);
    });
}

document.addEventListener('DOMContentLoaded', function() {
    const inputBuscar = document.getElementById('buscarProduto');
    const filtroCategoria = document.getElementById('filtroCategoria');
    const desconto = document.getElementById('desconto');

    if (inputBuscar) {
        inputBuscar.addEventListener('keyup', function() {
            filtrarProdutos();
        });
    }

    if (filtroCategoria) {
        filtroCategoria.addEventListener('change', function() {
            filtrarProdutos();
        });
    }

    if (desconto) {
        desconto.addEventListener('input', function () {
            if (typeof calcularTotal === 'function') calcularTotal();
            sincronizarModoCarrinhoPdv();
        });
    }

    sincronizarModoCarrinhoPdv();
});

function filtrarProdutos() {
    const busca = document.getElementById('buscarProduto')?.value.toLowerCase() || '';
    const categoria = document.getElementById('filtroCategoria')?.value || '';
    const stockFilter = window.PDV_STOCK_FILTER || 'todos';
    const items = document.querySelectorAll('.produto-item');
    let visiveis = 0;

    items.forEach(item => {
        const nome = item.querySelector('.nome')?.textContent.toLowerCase() || '';
        const itemCategoria = item.getAttribute('data-categoria') || '';
        const stockState = item.getAttribute('data-stock-state') || '';
        const vendas = parseInt(item.getAttribute('data-vendas') || '0', 10) || 0;

        let mostrar = true;

        if (busca && !nome.includes(busca)) {
            mostrar = false;
        }

        if (categoria && itemCategoria !== categoria) {
            mostrar = false;
        }

        if (stockFilter === 'estoque' && stockState !== 'estoque' && stockState !== 'baixo') {
            mostrar = false;
        } else if (stockFilter === 'baixo' && stockState !== 'baixo') {
            mostrar = false;
        } else if (stockFilter === 'sem' && stockState !== 'sem') {
            mostrar = false;
        } else if (stockFilter === 'top' && vendas <= 0) {
            mostrar = false;
        }

        item.style.display = mostrar ? 'block' : 'none';
        if (mostrar) visiveis++;
    });

    const countEl = document.getElementById('pdv-emp-prod-count');
    if (countEl) countEl.textContent = String(visiveis);
}

function formatarMoeda(valor) {
    return valor.toFixed(2).replace('.', ',') + ' MZN';
}

function showAlert(message, type) {
    const alertDiv = document.getElementById('alertVenda');
    if (!alertDiv) return;

    alertDiv.textContent = message;
    alertDiv.className = 'alert alert-' + (type === 'warning' ? 'warning' : (type === 'success' ? 'success' : 'danger'));
    alertDiv.style.display = 'block';
    setTimeout(() => {
        alertDiv.style.display = 'none';
    }, 5000);
}

// =============================================
// INTEGRAÇÃO: receber pagamento de pedido
// chamado pelos botões na seção "Pedidos Aguardando"
// =============================================
async function receberPagamentoPedido(pedidoId) {
    const sel = document.getElementById('forma_pag_' + pedidoId);
    const btn = document.getElementById('btn_pagar_' + pedidoId);

    if (!sel || !btn) return;

    const formaPag = sel.value;
    const originalHtml = btn.innerHTML;

    if (!(await nivoraConfirmar({ mensagem: 'Confirmar recebimento do pedido #' + pedidoId + ' via ' + formaPag + '?', confirmar: 'Receber' }))) return;

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Processando...';
    const reciboPopup = pdvPrepararJanelaRecibo();

    const formData = new FormData();
    formData.append('id', pedidoId);
    formData.append('forma_pagamento', formaPag);
    formData.append('desconto', '0');
    if (typeof CSRF_TOKEN !== 'undefined') {
        formData.append('_csrf', CSRF_TOKEN);
    }

    fetch('api/pedido_pagar.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': (typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : '')
        },
        body: formData
    })
    .then(r => r.text())
    .then(text => {
        try {
            return JSON.parse(text);
        } catch (e) {
            throw new Error('Resposta inválida do servidor');
        }
    })
    .then(data => {
        if (data.success) {
            const row = document.getElementById('pedido_row_' + pedidoId);
            if (row) {
                row.style.transition = 'opacity 0.4s';
                row.style.opacity = '0';
                setTimeout(() => row.remove(), 400);
            }
            showAlert(
                '✅ Pedido #' + pedidoId + ' pago! Fatura: ' + (data.numero_fatura || ''),
                'success'
            );
            if (data.venda_id) {
                pdvAbrirComprovante(data.venda_id, reciboPopup);
            } else if (reciboPopup && !reciboPopup.closed) {
                reciboPopup.close();
            }
            if (typeof atualizarMesas === 'function') {
                setTimeout(() => atualizarMesas(), 1200);
            } else {
                setTimeout(() => location.reload(), 2500);
            }
        } else {
            if (reciboPopup && !reciboPopup.closed) reciboPopup.close();
            showAlert(data.message || 'Erro ao receber pagamento', 'danger');
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    })
    .catch(e => {
        if (reciboPopup && !reciboPopup.closed) reciboPopup.close();
        showAlert('Erro de conexão: ' + e.message, 'danger');
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    });
}


// Empresarial: forma de pagamento com um toque + troco rápido
(function () {
    function iniciar() {
        var sel = document.getElementById('forma_pagamento');
        var box = document.getElementById('pdvPayBtns');
        if (sel && box) {
            var ultimo = null;
            var sync = function () {
                if (sel.value === ultimo) return;
                ultimo = sel.value;
                box.querySelectorAll('button[data-forma]').forEach(function (b) {
                    b.classList.toggle('is-active', b.getAttribute('data-forma') === sel.value);
                });
            };
            box.addEventListener('click', function (ev) {
                var b = ev.target.closest('button[data-forma]');
                if (!b) return;
                sel.value = b.getAttribute('data-forma');
                sel.dispatchEvent(new Event('change', { bubbles: true }));
                sync();
            });
            sel.addEventListener('change', sync);
            setInterval(sync, 400);
            sync();
        }
        var rapido = document.getElementById('pdvTrocoRapido');
        var input = document.getElementById('valor_recebido');
        if (rapido && input) {
            rapido.addEventListener('click', function (ev) {
                var b = ev.target.closest('button[data-valor]');
                if (!b) return;
                var v = b.getAttribute('data-valor');
                var total = typeof calcularTotalCarrinho === 'function' ? calcularTotalCarrinho() : 0;
                input.value = v === 'exato' ? total.toFixed(2) : v;
                input.dispatchEvent(new Event('input', { bubbles: true }));
            });
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar);
    else iniciar();
})();
