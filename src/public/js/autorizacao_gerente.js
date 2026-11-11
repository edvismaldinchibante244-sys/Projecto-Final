/*
 * Autorizacao do gerente para acoes sensiveis.
 * Quando o servidor responde 428 {requer_autorizacao:true}, esta janela pede o PIN de um
 * gerente (ou a palavra-passe do administrador), obtem um codigo de uso unico e repete o
 * mesmo pedido com o cabecalho X-Autorizacao-Gerente. Funciona com qualquer fetch da pagina
 * (incluindo OfflineSync), sem alterar quem faz o pedido.
 */
(function () {
    'use strict';
    if (window.__autorizacaoGerente) { return; }
    window.__autorizacaoGerente = true;

    var script = document.currentScript;
    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return (script && script.getAttribute('data-csrf')) || (m && m.content) || window.CSRF_TOKEN || '';
    }

    var fetchOriginal = window.fetch.bind(window);
    var fila = Promise.resolve();
    var abertas = {}; // acao:alvo -> resposta da janela aberta

    function api(dados) {
        return fetchOriginal('api/gerente_autorizar.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(dados)
        }).then(function (r) {
            return r.json().catch(function () { return { success: false, message: 'Erro inesperado (' + r.status + ').' }; });
        }).catch(function () { return { success: false, message: 'Sem ligação. Tente novamente.' }; });
    }

    function el(tag, attrs, texto) {
        var e = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
        if (texto != null) { e.textContent = texto; }
        return e;
    }

    function estilos() {
        if (document.getElementById('ag-estilos')) { return; }
        var s = el('style', { id: 'ag-estilos' });
        s.textContent =
            '.ag-fundo{position:fixed;inset:0;background:rgba(15,12,10,.6);z-index:20000;display:flex;align-items:center;justify-content:center;padding:16px}' +
            '.ag-caixa{background:#fff;color:#1f1a16;border-radius:16px;max-width:420px;width:100%;padding:22px;box-shadow:0 20px 60px rgba(0,0,0,.35);font-family:inherit}' +
            '.ag-caixa h3{margin:0 0 4px;font-size:1.15rem}.ag-sub{margin:0 0 14px;color:#6b6259;font-size:.92rem}' +
            '.ag-lista{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px}' +
            '.ag-lista button{border:1px solid #d9d0c5;background:#faf7f3;border-radius:10px;padding:8px 12px;cursor:pointer;font:inherit}' +
            '.ag-lista button[aria-pressed="true"]{border-color:#b8873e;background:#f3e6d1;font-weight:600}' +
            '.ag-caixa input{width:100%;padding:10px 12px;border:1px solid #d9d0c5;border-radius:10px;font-size:1.1rem;letter-spacing:.15em}' +
            '.ag-erro{color:#c0392b;min-height:1.3em;margin:8px 0;font-size:.9rem}' +
            '.ag-botoes{display:flex;justify-content:flex-end;gap:8px}' +
            '.ag-botoes button{border:0;border-radius:10px;padding:9px 16px;font:inherit;cursor:pointer}' +
            '.ag-ok{background:#1f1a16;color:#fff}.ag-ok:disabled{opacity:.6}.ag-cancelar{background:#eee7df}' +
            '.ag-ou{display:flex;align-items:center;gap:10px;color:#9a8f84;font-size:.8rem;margin:14px 0 10px}.ag-ou:before,.ag-ou:after{content:"";flex:1;height:1px;background:#e6ddd3}' +
            '.ag-remoto{width:100%;border:1px solid #d9d0c5;background:#fff;border-radius:10px;padding:10px 12px;font:inherit;cursor:pointer;display:flex;gap:10px;align-items:center;justify-content:center}' +
            '.ag-remoto--principal{background:#1f1a16;color:#fff;border-color:#1f1a16;font-weight:600;padding:12px}.ag-remoto--principal:hover{background:#3a3129}' +
            '.ag-local{display:block;margin:12px auto 4px;background:none;border:0;color:#8a5a14;font:inherit;font-size:.88rem;cursor:pointer;text-decoration:underline}' +
            '.ag-localcaixa{margin-top:12px;padding-top:12px;border-top:1px solid #eee5da}.ag-nota{margin:0 0 10px;font-size:.85rem;color:#6b6259}' +
            '.ag-remoto:hover{background:#faf7f3}.ag-espera{text-align:center;padding:10px 0 4px}' +
            '.ag-roda{width:34px;height:34px;border:3px solid #eadfce;border-top-color:#b8873e;border-radius:50%;margin:6px auto 12px;animation:agRoda 1s linear infinite}' +
            '@keyframes agRoda{to{transform:rotate(360deg)}}.ag-espera p{margin:0 0 6px}.ag-espera small{color:#6b6259}';
        document.head.appendChild(s);
    }

    /** Mostra a janela. Resolve com o codigo de autorizacao, ou null se cancelar. */
    function pedirAutorizacao(info) {
        estilos();
        return new Promise(function (resolve) {
            var fundo = el('div', { class: 'ag-fundo', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'ag-titulo' });
            var caixa = el('div', { class: 'ag-caixa' });
            caixa.appendChild(el('h3', { id: 'ag-titulo' }, 'Autorização do gerente'));
            caixa.appendChild(el('p', { class: 'ag-sub' }, (info.titulo || 'Ação sensível') + (info.detalhe ? ' — ' + info.detalhe : '') + ' precisa da aprovação de um gerente ou do administrador.'));
            var lista = el('div', { class: 'ag-lista' }, 'A carregar…');
            var campo = el('input', { type: 'password', autocomplete: 'off', 'aria-label': 'PIN ou palavra-passe', disabled: 'disabled' });
            var erro = el('p', { class: 'ag-erro', role: 'alert' });
            var botoes = el('div', { class: 'ag-botoes' });
            var cancelar = el('button', { type: 'button', class: 'ag-cancelar' }, 'Cancelar');
            var ok = el('button', { type: 'button', class: 'ag-ok', disabled: 'disabled' }, 'Autorizar');
            botoes.appendChild(cancelar);
            botoes.appendChild(ok);
            // Caminho principal: o pedido vai para o telemovel/computador do gerente e do administrador.
            // Alternativa: o gerente esta ao lado e escreve ELE o seu PIN neste ecra.
            var remoto = el('button', { type: 'button', class: 'ag-remoto ag-remoto--principal' }, 'Pedir aprovação ao gerente');
            var ou = el('button', { type: 'button', class: 'ag-local' }, 'O gerente está aqui ao lado? Ele escreve o PIN dele');
            var local = el('div', { class: 'ag-localcaixa', hidden: 'hidden' });
            local.appendChild(el('p', { class: 'ag-nota' }, 'Entregue o aparelho ao gerente: só ele escreve o PIN.'));
            [lista, campo].forEach(function (n) { local.appendChild(n); });
            [remoto, ou, local, erro, botoes].forEach(function (n) { caixa.appendChild(n); });
            ok.hidden = true;
            ou.addEventListener('click', function () {
                local.hidden = false; ou.hidden = true; ok.hidden = false;
                var b1 = lista.querySelector('button'); if (b1 && lista.querySelectorAll('button').length === 1) { b1.click(); }
            });
            fundo.appendChild(caixa);
            // Impede que o 'focus trap' de um modal Bootstrap aberto roube o foco desta janela.
            fundo.addEventListener('focusin', function (e) { e.stopPropagation(); });
            document.body.appendChild(fundo);

            var escolhido = null, remotoId = null, remotoTimer = null;
            function fechar(valor) {
                document.removeEventListener('keydown', teclas, true);
                if (remotoTimer) { clearTimeout(remotoTimer); }
                if (remotoId && !valor) { api({ acao: 'desistir_remoto', id: remotoId }); }
                fundo.remove();
                resolve(valor);
            }
            remoto.addEventListener('click', function () {
                remoto.disabled = true;
                api({ acao: 'pedir_remoto', acao_alvo: info.acao, alvo: info.alvo }).then(function (d) {
                    if (!d || !d.success) { remoto.disabled = false; erro.textContent = (d && d.message) || 'Não foi possível enviar o pedido.'; return; }
                    remotoId = d.id;
                    var codigo = d.codigo;
                    [local, erro, ou, remoto].forEach(function (n) { n.remove(); });
                    ok.remove();
                    cancelar.textContent = 'Cancelar pedido';
                    var espera = el('div', { class: 'ag-espera', role: 'status', 'aria-live': 'polite' });
                    espera.appendChild(el('div', { class: 'ag-roda', 'aria-hidden': 'true' }));
                    var linha = el('p', null, 'Pedido enviado aos gerentes e administradores.');
                    var sub = el('small', null, 'A aguardar resposta… pode deixar esta janela aberta.');
                    espera.appendChild(linha); espera.appendChild(sub);
                    caixa.insertBefore(espera, botoes);
                    var verificar = function () {
                        api({ acao: 'estado_remoto', id: remotoId }).then(function (e) {
                            if (!e || !e.success) { remotoTimer = setTimeout(verificar, 5000); return; }
                            if (e.estado === 'USADA') {
                                // Ja aprovado e usado (ex.: noutro pedido repetido da fila offline): a acao ja foi feita.
                                linha.textContent = 'Aprovado' + (e.decidido_por ? ' por ' + e.decidido_por : '') + '.';
                                remotoId = null;
                                setTimeout(function () { fechar('JA_FEITO'); }, 600);
                                return;
                            }
                            if (e.estado === 'APROVADA') {
                                linha.textContent = 'Aprovado' + (e.decidido_por ? ' por ' + e.decidido_por : '') + '.';
                                remotoId = null;
                                setTimeout(function () { fechar(codigo); }, 600);
                                return;
                            }
                            if (e.estado === 'RECUSADA' || e.estado === 'EXPIRADA' || e.estado === 'CANCELADA') {
                                espera.querySelector('.ag-roda').remove();
                                linha.textContent = e.estado === 'RECUSADA'
                                    ? 'Recusado' + (e.decidido_por ? ' por ' + e.decidido_por : '') + '.'
                                    : 'O pedido expirou sem resposta.';
                                sub.textContent = '';
                                cancelar.textContent = 'Fechar';
                                remotoId = null;
                                return;
                            }
                            remotoTimer = setTimeout(verificar, 3000);
                        });
                    };
                    remotoTimer = setTimeout(verificar, 2500);
                });
            });
            function teclas(e) {
                if (e.key === 'Escape') { e.stopPropagation(); fechar(null); }
                if (e.key === 'Enter' && document.activeElement === campo) { e.preventDefault(); ok.click(); }
            }
            document.addEventListener('keydown', teclas, true);
            cancelar.addEventListener('click', function () { fechar(null); });

            api({ acao: 'listar' }).then(function (d) {
                lista.textContent = '';
                var pessoas = ((d && d.autorizadores) || []).filter(function (p) { return p.credencial === 'pin'; });
                if (!pessoas.length) {
                    lista.textContent = 'Nenhum gerente com PIN definido. Use "Pedir aprovação ao gerente".';
                    return;
                }
                pessoas.forEach(function (p) {
                    var b = el('button', { type: 'button', 'aria-pressed': 'false' }, p.nome);
                    b.addEventListener('click', function () {
                        escolhido = p;
                        lista.querySelectorAll('button').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
                        campo.disabled = false;
                        campo.value = '';
                        campo.placeholder = 'PIN de ' + p.nome;
                        campo.setAttribute('inputmode', 'numeric');
                        ok.disabled = false;
                        erro.textContent = '';
                        campo.focus();
                    });
                    lista.appendChild(b);
                });

            });

            ok.addEventListener('click', function () {
                if (!escolhido || !campo.value) { erro.textContent = 'Escolha o gerente e peça-lhe que escreva o PIN.'; return; }
                ok.disabled = true;
                api({ acao: 'autorizar', acao_alvo: info.acao, alvo: info.alvo, autorizador_id: escolhido.id, credencial: campo.value })
                    .then(function (d) {
                        if (d && d.success && d.codigo) { fechar(d.codigo); return; }
                        erro.textContent = (d && d.message) || 'Não autorizado.';
                        campo.value = '';
                        ok.disabled = false;
                        campo.focus();
                    });
            });
        });
    }

    function comCabecalho(input, init, codigo) {
        var novo = Object.assign({}, init || {});
        var h = new Headers(novo.headers || (input instanceof Request ? input.headers : undefined));
        h.set('X-Autorizacao-Gerente', codigo);
        novo.headers = h;
        return novo;
    }

    window.fetch = function (input, init) {
        return fetchOriginal(input, init).then(function (resp) {
            if (resp.status !== 428) { return resp; }
            return resp.clone().json().catch(function () { return null; }).then(function (info) {
                if (!info || !info.requer_autorizacao) { return resp; }
                // A mesma acao pedida de novo enquanto ja ha uma janela aberta (ex.: a fila offline a repetir):
                // nao abre outra janela; espera pela mesma resposta e, se foi aprovada, repete sem codigo
                // (o servidor responde que ja esta feito).
                var chave = info.acao + ':' + info.alvo;
                if (abertas[chave]) {
                    return abertas[chave].then(function (codigo) { return codigo ? fetchOriginal(input, init) : resp; });
                }
                // Uma janela de cada vez.
                var vez = fila.then(function () { return pedirAutorizacao(info); });
                fila = vez.catch(function () {});
                abertas[chave] = vez;
                vez.then(function () { setTimeout(function () { delete abertas[chave]; }, 5000); });
                return vez.then(function (codigo) {
                    if (!codigo) { return resp; }
                    if (codigo === 'JA_FEITO') { return fetchOriginal(input, init); }
                    return window.fetch(input, comCabecalho(input, init, codigo));
                });
            });
        });
    };
})();
