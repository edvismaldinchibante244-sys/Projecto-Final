/*
 * Caixa (Empresarial): sangria / reforço da gaveta e opção de fecho às cegas.
 * Usa window.fetch: se o servidor pedir autorização do gerente (428), o
 * autorizacao_gerente.js abre a janela do PIN / pedido à distância.
 */
(function () {
    'use strict';
    var script = document.currentScript;
    var csrf = (script && script.getAttribute('data-csrf')) || '';

    function moeda(v) {
        return Number(v || 0).toLocaleString('pt-MZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function lerValor(txt) {
        var s = String(txt || '').trim().replace(/\s/g, '');
        if (s.indexOf(',') >= 0) { s = s.replace(/\./g, '').replace(',', '.'); }
        var n = parseFloat(s);
        return isFinite(n) ? n : NaN;
    }
    function post(url, dados) {
        var fd = new FormData();
        Object.keys(dados).forEach(function (k) { fd.append(k, dados[k]); });
        fd.append('_csrf', csrf);
        return fetch(url, {
            method: 'POST', body: fd, credentials: 'same-origin',
            headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.json().catch(function () { return { success: false, message: 'Resposta inválida do servidor.' }; }); });
    }

    function estilos() {
        if (document.getElementById('cxg-estilos')) return;
        var st = document.createElement('style');
        st.id = 'cxg-estilos';
        st.textContent = [
            '.cxg-fundo{position:fixed;inset:0;z-index:3000;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:16px}',
            '.cxg-caixa{background:#fff;border-radius:18px;width:min(420px,100%);box-shadow:0 30px 60px rgba(0,0,0,.25);overflow:hidden;font-family:inherit}',
            '.cxg-topo{padding:18px 22px 6px}.cxg-topo h5{margin:0;font-weight:800;font-size:1.1rem}.cxg-topo p{margin:4px 0 0;color:#64748b;font-size:.88rem}',
            '.cxg-corpo{padding:10px 22px 4px}.cxg-corpo label{display:block;font-weight:700;font-size:.85rem;margin:10px 0 5px;color:#334155}',
            '.cxg-corpo input{width:100%;padding:11px 13px;border:1.5px solid #e2e8f0;border-radius:12px;font-size:1rem}',
            '.cxg-corpo input:focus{outline:none;border-color:#ea580c;box-shadow:0 0 0 3px rgba(234,88,12,.15)}',
            '.cxg-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}.cxg-chips button{border:1px solid #e2e8f0;background:#f8fafc;border-radius:999px;padding:5px 11px;font-size:.8rem;color:#334155}',
            '.cxg-erro{margin:10px 0 0;color:#b91c1c;font-size:.85rem;font-weight:600;min-height:1em}',
            '.cxg-botoes{display:flex;justify-content:flex-end;gap:10px;padding:14px 22px;background:#f8fafc;border-top:1px solid #e2e8f0;margin-top:12px}',
            '.cxg-botoes button{padding:10px 16px;border-radius:10px;font-weight:700;border:1px solid #e2e8f0;background:#fff}',
            '.cxg-botoes .cxg-ok{background:#ea580c;border-color:#ea580c;color:#fff}.cxg-botoes .cxg-ok.is-in{background:#059669;border-color:#059669}',
            '.cxg-botoes button:disabled{opacity:.6}'
        ].join('');
        document.head.appendChild(st);
    }

    function abrirMovimento(tipo) {
        estilos();
        var sangria = tipo === 'SANGRIA';
        var fundo = document.createElement('div');
        fundo.className = 'cxg-fundo';
        fundo.innerHTML =
            '<div class="cxg-caixa" role="dialog" aria-modal="true">' +
            '<div class="cxg-topo"><h5></h5><p></p></div>' +
            '<div class="cxg-corpo">' +
            '<label for="cxgValor">Valor (MZN)</label><input id="cxgValor" type="text" inputmode="decimal" autocomplete="off" placeholder="0,00">' +
            '<label for="cxgMotivo">Motivo</label><input id="cxgMotivo" type="text" maxlength="160" autocomplete="off">' +
            '<div class="cxg-chips"></div><p class="cxg-erro" aria-live="polite"></p></div>' +
            '<div class="cxg-botoes"><button type="button" class="cxg-nao">Cancelar</button><button type="button" class="cxg-ok"></button></div></div>';
        fundo.querySelector('h5').textContent = sangria ? 'Sangria — retirar dinheiro da gaveta' : 'Reforço — pôr dinheiro na gaveta';
        fundo.querySelector('.cxg-topo p').textContent = sangria
            ? 'Registe o dinheiro que sai da gaveta durante o turno. Um operador de caixa precisa da autorização do gerente.'
            : 'Registe o dinheiro que entra na gaveta durante o turno (ex.: troco extra).';
        var motivo = fundo.querySelector('#cxgMotivo');
        motivo.placeholder = sangria ? 'Ex.: levado para o cofre' : 'Ex.: troco trazido do banco (opcional)';
        var chips = fundo.querySelector('.cxg-chips');
        (sangria ? ['Levado para o cofre', 'Depósito no banco', 'Pagamento a fornecedor'] : ['Troco extra', 'Reforço do cofre']).forEach(function (t) {
            var b = document.createElement('button');
            b.type = 'button';
            b.textContent = t;
            b.addEventListener('click', function () { motivo.value = t; });
            chips.appendChild(b);
        });
        var ok = fundo.querySelector('.cxg-ok');
        ok.textContent = sangria ? 'Registar sangria' : 'Registar reforço';
        if (!sangria) ok.classList.add('is-in');
        var erro = fundo.querySelector('.cxg-erro');
        var valor = fundo.querySelector('#cxgValor');

        function fechar() { fundo.remove(); document.removeEventListener('keydown', teclas); }
        function teclas(e) { if (e.key === 'Escape') fechar(); if (e.key === 'Enter' && document.activeElement !== ok) ok.click(); }
        document.addEventListener('keydown', teclas);
        fundo.querySelector('.cxg-nao').addEventListener('click', fechar);
        fundo.addEventListener('mousedown', function (e) { if (e.target === fundo) fechar(); });

        ok.addEventListener('click', function () {
            var v = lerValor(valor.value);
            erro.textContent = '';
            if (!(v > 0)) { erro.textContent = 'Indique um valor maior que zero.'; valor.focus(); return; }
            if (sangria && !motivo.value.trim()) { erro.textContent = 'Indique o motivo da sangria.'; motivo.focus(); return; }
            ok.disabled = true;
            post('api/caixa_movimento.php', { tipo: tipo, valor: String(v), motivo: motivo.value.trim() })
                .then(function (d) {
                    if (d && d.success) {
                        fechar();
                        if (window.NivoraFeedback && typeof NivoraFeedback.show === 'function') {
                            NivoraFeedback.show(d.message, 'success');
                        }
                        setTimeout(function () { location.reload(); }, 700);
                    } else {
                        ok.disabled = false;
                        erro.textContent = (d && d.message) || 'Não foi possível registar.';
                    }
                })
                .catch(function () { ok.disabled = false; erro.textContent = 'Sem ligação ao servidor. Tente de novo.'; });
        });
        document.body.appendChild(fundo);
        valor.focus();
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-caixa-mov]');
        if (b) { abrirMovimento(b.getAttribute('data-caixa-mov')); }
    });

    var cego = document.getElementById('caixaFechoCego');
    if (cego) {
        cego.addEventListener('change', function () {
            var ligado = cego.checked;
            cego.disabled = true;
            post('api/caixa_config.php', { fecho_cego: ligado ? '1' : '0' }).then(function (d) {
                cego.disabled = false;
                if (!d || !d.success) {
                    cego.checked = !ligado;
                    if (window.NivoraFeedback) NivoraFeedback.show((d && d.message) || 'Não foi possível guardar.', 'danger');
                } else if (window.NivoraFeedback && typeof NivoraFeedback.show === 'function') {
                    NivoraFeedback.show(d.message, 'success');
                }
            }).catch(function () { cego.disabled = false; cego.checked = !ligado; });
        });
    }
})();
