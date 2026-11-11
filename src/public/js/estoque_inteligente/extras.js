// Estoque inteligente - Extras (nov 2026): aviso de insumos sem custo, margem na ficha,
// últimos movimentos por dia e contagem de inventário.
// Carregado depois de comum.js, painel.js, insumos_fichas.js e acoes.js.

(function () {
    'use strict';

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // ---------- 1) Aviso: insumos sem custo ----------
    function atualizarAvisoSemCusto() {
        var box = document.getElementById('avisoSemCusto');
        if (!box) return;
        var semCusto = (cacheSaldos || []).filter(function (s) { return !(Number(s.custo_medio) > 0); });
        if (!semCusto.length) { box.hidden = true; return; }
        box.hidden = false;
        document.getElementById('avisoSemCustoTitulo').textContent =
            semCusto.length + (semCusto.length === 1 ? ' insumo sem custo' : ' insumos sem custo') +
            ' — a margem dos pratos não pode ser calculada.';
        var nomes = semCusto.slice(0, 5).map(function (s) { return s.nome; }).join(', ');
        document.getElementById('avisoSemCustoLista').textContent =
            nomes + (semCusto.length > 5 ? ' e mais ' + (semCusto.length - 5) : '') +
            '. Registe uma entrada com o custo unitário.';
        var btn = document.getElementById('avisoSemCustoBtn');
        btn.onclick = function () {
            if (typeof ativarAbaEstoque === 'function') ativarAbaEstoque('movimentos');
            var sel = document.getElementById('movInsumo');
            if (sel) {
                sel.value = String(semCusto[0].insumo_id);
                sel.dispatchEvent(new Event('change', { bubbles: true }));
            }
            var tipo = document.getElementById('movTipo');
            if (tipo) { tipo.value = 'ENTRADA'; tipo.dispatchEvent(new Event('change', { bubbles: true })); }
            var custo = document.getElementById('movCusto');
            if (custo) setTimeout(function () { custo.focus(); }, 150);
        };
    }

    if (typeof renderDashboard === 'function') {
        var renderDashboardOrig = renderDashboard;
        // eslint-disable-next-line no-global-assign
        renderDashboard = function (data) {
            renderDashboardOrig(data);
            try { atualizarAvisoSemCusto(); } catch (e) { console.warn('avisoSemCusto', e); }
        };
    }

    // ---------- 2) Margem na ficha técnica ----------
    function atualizarMargemFicha() {
        var row = document.getElementById('fichaMargemRow');
        var cell = document.getElementById('fichaMargem');
        if (!row || !cell) return;
        var pid = Number(document.getElementById('fichaProduto')?.value || 0);
        var prod = (cacheProdutos || []).find(function (p) { return Number(p.id) === pid; });
        var preco = Number(prod?.preco || 0);
        var itens = Array.isArray(fichaItens) ? fichaItens : [];
        if (!(preco > 0) || !itens.length) { row.hidden = true; return; }
        var total = 0;
        var faltaCusto = false;
        itens.forEach(function (i) {
            var c = custoItemFicha(i.insumo_id, i.quantidade, i.unidade);
            if (!(c > 0)) faltaCusto = true;
            total += c;
        });
        row.hidden = false;
        if (faltaCusto) {
            cell.innerHTML = moeda(preco) + ' · <span class="text-warning">margem por calcular (há insumos sem custo)</span>';
            return;
        }
        var margem = Math.round((preco - total) / preco * 100);
        var cls = margem < 30 ? 'text-danger' : (margem < 50 ? 'text-warning' : 'text-success');
        cell.innerHTML = moeda(preco) + ' · <strong class="' + cls + '">margem ' + margem + '%</strong>' +
            (total >= preco ? ' <span class="text-danger">— o custo é maior que o preço</span>' : '');
    }

    if (typeof renderFichaItens === 'function') {
        var renderFichaItensOrig = renderFichaItens;
        // eslint-disable-next-line no-global-assign
        renderFichaItens = function () {
            var r = renderFichaItensOrig.apply(this, arguments);
            try { atualizarMargemFicha(); } catch (e) { console.warn('margemFicha', e); }
            return r;
        };
    }

    // ---------- 3) Últimos movimentos por dia ----------
    var TIPOS = {
        ENTRADA: { txt: 'Entrada', cls: 'is-in', sinal: '+' },
        SAIDA: { txt: 'Saída', cls: 'is-out', sinal: '−' },
        AJUSTE: { txt: 'Ajuste', cls: 'is-adj', sinal: '±' },
        TRANSFERENCIA: { txt: 'Transferência', cls: 'is-adj', sinal: '⇄' },
        PERDA: { txt: 'Perda', cls: 'is-out', sinal: '−' }
    };
    var ORIGENS = { COMPRA: 'compra', PEDIDO: 'venda (pedido)', CAIXA: 'venda (PDV)', COZINHA: 'cozinha', MANUAL: 'manual', SISTEMA: 'sistema', TRANSFERENCIA: 'transferência' };

    function rotuloDia(d) {
        var hoje = new Date(); hoje.setHours(0, 0, 0, 0);
        var dia = new Date(d.getTime()); dia.setHours(0, 0, 0, 0);
        var diff = Math.round((hoje - dia) / 86400000);
        if (diff === 0) return 'Hoje';
        if (diff === 1) return 'Ontem';
        return dia.toLocaleDateString('pt-PT', { weekday: 'long', day: '2-digit', month: '2-digit' });
    }

    async function carregarMovimentos() {
        var box = document.getElementById('eiMovsRecentes');
        var boxHoje = document.getElementById('eiMovsHoje');
        if (!box && !boxHoje) return;
        try {
            var r = await fetchJson('api/estoque_movimentos_recentes.php?limite=40');
            var lista = Array.isArray(r.data) ? r.data : [];
            if (boxHoje) renderMovimentosHoje(boxHoje, lista);
            if (!box) return;
            if (!lista.length) { box.innerHTML = '<div class="text-muted small">Ainda não há movimentos.</div>'; return; }
            var html = '';
            var diaAtual = '';
            lista.forEach(function (m) {
                var d = new Date(String(m.criado_em).replace(' ', 'T'));
                var dia = rotuloDia(d);
                if (dia !== diaAtual) {
                    diaAtual = dia;
                    html += '<div class="ei-mov-dia">' + esc(dia.charAt(0).toUpperCase() + dia.slice(1)) + '</div>';
                }
                var t = TIPOS[String(m.tipo || '').toUpperCase()] || { txt: m.tipo, cls: 'is-adj', sinal: '' };
                var qtd = fmtNumeroHumano(m.quantidade_base) + ' ' + labelUnidade(m.unidade_base);
                var origem = ORIGENS[String(m.origem || '').toUpperCase()] || String(m.origem || '').toLowerCase();
                html += '<div class="ei-mov">' +
                    '<span class="ei-mov-hora">' + d.toLocaleTimeString('pt-PT', { hour: '2-digit', minute: '2-digit' }) + '</span>' +
                    '<span class="ei-mov-tipo ' + t.cls + '">' + esc(t.txt) + '</span>' +
                    '<div class="ei-mov-info"><strong>' + esc(m.insumo_nome || '—') + '</strong>' +
                    '<small>' + esc(origem) + (m.motivo ? ' · ' + esc(m.motivo) : '') + ' · ' + esc(m.usuario_nome || 'sistema') + '</small></div>' +
                    '<span class="ei-mov-qtd ' + t.cls + '">' + esc(t.sinal + qtd) + '</span>' +
                    '</div>';
            });
            box.innerHTML = html;
        } catch (e) {
            if (box) box.innerHTML = '<div class="text-muted small">Não foi possível carregar os movimentos.</div>';
            if (boxHoje) boxHoje.innerHTML = '<div class="text-muted small">Não foi possível carregar os movimentos de hoje.</div>';
        }
    }

    // Resumo curto dos movimentos de hoje (planos sem o cockpit Empresarial)
    function renderMovimentosHoje(box, lista) {
        var hoje = new Date(); hoje.setHours(0, 0, 0, 0);
        var doDia = lista.filter(function (m) {
            var d = new Date(String(m.criado_em).replace(' ', 'T'));
            d.setHours(0, 0, 0, 0);
            return d.getTime() === hoje.getTime();
        });
        if (!doDia.length) {
            box.innerHTML = '<div class="text-muted small">Ainda não houve movimentos de stock hoje.</div>';
            return;
        }
        var entradas = 0, saidas = 0;
        doDia.forEach(function (m) {
            var tipo = String(m.tipo || '').toUpperCase();
            if (tipo === 'ENTRADA') entradas++;
            else if (tipo === 'SAIDA' || tipo === 'PERDA') saidas++;
        });
        var html = '<div class="ei-hoje-resumo">' + doDia.length + ' movimento(s) hoje · '
            + entradas + ' entrada(s) · ' + saidas + ' saída(s)/perda(s)</div>';
        doDia.slice(0, 6).forEach(function (m) {
            var d = new Date(String(m.criado_em).replace(' ', 'T'));
            var t = TIPOS[String(m.tipo || '').toUpperCase()] || { txt: m.tipo, cls: 'is-adj', sinal: '' };
            var qtd = fmtNumeroHumano(m.quantidade_base) + ' ' + labelUnidade(m.unidade_base);
            html += '<div class="ei-mov">' +
                '<span class="ei-mov-hora">' + d.toLocaleTimeString('pt-PT', { hour: '2-digit', minute: '2-digit' }) + '</span>' +
                '<span class="ei-mov-tipo ' + t.cls + '">' + esc(t.txt) + '</span>' +
                '<div class="ei-mov-info"><strong>' + esc(m.insumo_nome || '—') + '</strong>' +
                '<small>' + esc(m.usuario_nome || 'sistema') + '</small></div>' +
                '<span class="ei-mov-qtd ' + t.cls + '">' + esc(t.sinal + qtd) + '</span>' +
                '</div>';
        });
        if (doDia.length > 6) {
            html += '<div class="ei-hoje-mais">Veja todos no separador Movimentos.</div>';
        }
        box.innerHTML = html;
    }

    document.getElementById('btnRecarregarMovs')?.addEventListener('click', carregarMovimentos);

    // ---------- 4) Contagem de inventário ----------
    function abrirContagem() {
        var saldos = (cacheSaldos || []).slice().sort(function (a, b) { return String(a.nome).localeCompare(String(b.nome)); });
        if (!saldos.length) {
            NivoraFeedback.error('Não há insumos para contar.');
            return;
        }
        var fundo = document.createElement('div');
        fundo.className = 'ei-cont-fundo';
        var linhas = saldos.map(function (s) {
            var un = labelUnidade(s.unidade_base);
            return '<tr data-id="' + Number(s.insumo_id) + '" data-saldo="' + Number(s.saldo_base) + '" data-un="' + esc(s.unidade_base) + '">' +
                '<td><strong>' + esc(s.nome) + '</strong><small>' + esc(s.sku || '') + '</small></td>' +
                '<td class="mono">' + fmtNumeroHumano(s.saldo_base) + ' ' + esc(un) + '</td>' +
                '<td><input type="text" inputmode="decimal" class="form-control form-control-sm ei-cont-input" placeholder="contado"></td>' +
                '<td class="mono ei-cont-dif">—</td></tr>';
        }).join('');
        fundo.innerHTML =
            '<div class="ei-cont-caixa" role="dialog" aria-modal="true" aria-label="Contagem de inventário">' +
            '<div class="ei-cont-topo"><div><h5>Contagem de inventário</h5><p>Escreva só o que contou. Insumos em branco ficam como estão. Quantidades na unidade base de cada insumo (g, ml, unidade…).</p></div>' +
            '<button type="button" class="btn-close" data-cont="fechar" aria-label="Fechar"></button></div>' +
            '<div class="ei-cont-corpo"><table class="table table-sm align-middle mb-0"><thead><tr><th>Insumo</th><th>No sistema</th><th style="width:140px">Contado</th><th>Diferença</th></tr></thead><tbody>' + linhas + '</tbody></table></div>' +
            '<div class="ei-cont-rodape"><span id="eiContResumo">Nenhuma diferença.</span>' +
            '<div><button type="button" class="btn btn-light btn-sm" data-cont="fechar">Cancelar</button> ' +
            '<button type="button" class="btn btn-primary btn-sm" data-cont="aplicar" disabled>Acertar stock</button></div></div></div>';

        function lerDiferencas() {
            var difs = [];
            fundo.querySelectorAll('tbody tr').forEach(function (tr) {
                var inp = tr.querySelector('.ei-cont-input');
                var cel = tr.querySelector('.ei-cont-dif');
                var v = (typeof parseNumeroMz === 'function') ? parseNumeroMz(inp.value) : Number(String(inp.value).replace(',', '.'));
                if (inp.value.trim() === '' || !isFinite(v) || v < 0) {
                    cel.textContent = inp.value.trim() === '' ? '—' : 'inválido';
                    cel.className = 'mono ei-cont-dif';
                    return;
                }
                var dif = Math.round((v - Number(tr.dataset.saldo)) * 1000) / 1000;
                cel.textContent = dif === 0 ? 'certo' : ((dif > 0 ? '+' : '') + fmtNumeroHumano(dif));
                cel.className = 'mono ei-cont-dif ' + (dif === 0 ? 'is-ok' : (dif > 0 ? 'is-in' : 'is-out'));
                if (dif !== 0) difs.push({ id: Number(tr.dataset.id), un: tr.dataset.un, dif: dif, nome: tr.querySelector('strong').textContent });
            });
            var res = fundo.querySelector('#eiContResumo');
            res.textContent = difs.length ? (difs.length + ' insumo(s) com diferença.') : 'Nenhuma diferença.';
            fundo.querySelector('[data-cont="aplicar"]').disabled = difs.length === 0;
            return difs;
        }

        fundo.addEventListener('input', function (e) { if (e.target.classList.contains('ei-cont-input')) lerDiferencas(); });
        fundo.addEventListener('click', async function (e) {
            var b = e.target.closest('[data-cont]');
            if (!b) return;
            if (b.dataset.cont === 'fechar') { fundo.remove(); return; }
            var difs = lerDiferencas();
            if (!difs.length) return;
            var ok = typeof nivoraConfirmar === 'function'
                ? await nivoraConfirmar({ titulo: 'Acertar o stock?', mensagem: difs.length + ' insumo(s) vão ser acertados para o valor contado. Fica registado como "Contagem de inventário".', confirmar: 'Acertar stock' })
                : true;
            if (!ok) return;
            b.disabled = true;
            var falhas = [];
            for (var k = 0; k < difs.length; k++) {
                var d = difs[k];
                b.textContent = 'A acertar ' + (k + 1) + '/' + difs.length + '…';
                var fd = new FormData();
                fd.append('insumo_id', d.id);
                fd.append('tipo', d.dif > 0 ? 'ENTRADA' : 'SAIDA');
                fd.append('quantidade', String(Math.abs(d.dif)));
                fd.append('unidade', d.un || 'un');
                fd.append('referencia', 'Contagem de inventário');
                fd.append('motivo', 'Contagem de inventário');
                fd.append('_csrf', CSRF);
                try {
                    var r = await fetchJson('api/estoque_movimento_registrar.php', { method: 'POST', body: fd });
                    if (!r.success) falhas.push(d.nome);
                } catch (err) { falhas.push(d.nome); }
            }
            fundo.remove();
            if (falhas.length) NivoraFeedback.error('Não foi possível acertar: ' + falhas.join(', '));
            else NivoraFeedback.show('Contagem aplicada: ' + difs.length + ' insumo(s) acertado(s).', 'success');
            if (typeof carregarBase === 'function') await carregarBase({ keepFichaLocal: true });
            carregarMovimentos();
        });
        document.body.appendChild(fundo);
        var primeiro = fundo.querySelector('.ei-cont-input');
        if (primeiro) primeiro.focus();
    }

    document.getElementById('btnAbrirContagem')?.addEventListener('click', abrirContagem);

    // Recarrega os movimentos depois de gravar um movimento manual
    document.getElementById('btnSalvarMov')?.addEventListener('click', function () { setTimeout(carregarMovimentos, 1500); });

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', carregarMovimentos);
    else carregarMovimentos();
})();
