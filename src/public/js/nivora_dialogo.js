/*
 * Janelas do Nivora: substitui confirm()/alert() do browser ("localhost diz...").
 *
 *   if (!(await nivoraConfirmar('Cancelar este pedido?'))) return;
 *   await nivoraConfirmar({ titulo, mensagem, conteudo: elementoOuHtml, confirmar: 'Aplicar', perigo: true });
 *   nivoraAviso('7 mínimos actualizados', { tipo: 'sucesso', acao: { texto: 'Desfazer', fn: () => ... } });
 *
 * Usa as cores do plano: laranja Empresarial (body.plano-empresarial) ou a cor
 * principal dos planos Basico/Profissional (--primary).
 */
(function () {
    'use strict';
    if (window.NivoraDialogo) { return; }

    function estilos() {
        if (document.getElementById('nd-estilos')) { return; }
        var s = document.createElement('style');
        s.id = 'nd-estilos';
        s.textContent = [
            // Cor do plano (css/premium.css: Basico cinza-azulado, Profissional azul-petroleo, Empresarial laranja).
            'body{--nd-cor:var(--nv-plano,var(--plan-accent,var(--primary,#1f2937)));--nd-cor-texto:#fff}',
            'body.plano-empresarial{--nd-cor:var(--nv-plano,var(--plan-accent,var(--emp-accent,#c2410c)))}',
            '.nd-fundo{position:fixed;inset:0;z-index:20050;background:rgba(15,23,42,.55);-webkit-backdrop-filter:blur(2px);backdrop-filter:blur(2px);display:flex;align-items:center;justify-content:center;padding:16px;animation:nd-in .12s ease-out}',
            '.nd-caixa{position:relative;background:#fff;color:#0f172a;border:1px solid #e2e8f0;border-radius:12px;width:100%;max-width:460px;max-height:calc(100vh - 32px);display:flex;flex-direction:column;box-shadow:0 20px 50px rgba(15,23,42,.22),0 2px 6px rgba(15,23,42,.06);font-family:inherit;overflow:hidden}',
            '.nd-perigo{border-top:3px solid #b42318}',
            '.nd-fechar{position:absolute;top:12px;right:12px;width:32px;height:32px;border:0;border-radius:8px;background:transparent;color:#64748b;font-size:1.25rem;line-height:1;cursor:pointer}.nd-fechar:hover{background:#f1f5f9;color:#0f172a}',
            '.nd-caixa.nd-larga{max-width:720px}',
            '.nd-cabeca{padding:22px 52px 14px 24px;display:flex;gap:14px;align-items:flex-start}',
            '.nd-icone{flex:0 0 40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.05rem;background:color-mix(in srgb,var(--nd-cor) 10%,#fff);color:var(--nd-cor);border:1px solid color-mix(in srgb,var(--nd-cor) 22%,#fff)}',
            '.nd-perigo .nd-icone{background:#fef3f2;color:#b42318;border-color:#fecdca}',
            '.nd-titulo{margin:2px 0 0;font-size:1.05rem;font-weight:650;line-height:1.35;letter-spacing:-.005em;color:#0f172a}',
            '.nd-msg{margin:6px 0 0;color:#475569;font-size:.92rem;line-height:1.55;white-space:pre-line}',
            '.nd-corpo{padding:4px 24px 14px;overflow:auto}',
            '.nd-botoes{display:flex;justify-content:flex-end;gap:10px;padding:14px 24px;background:#f8fafc;border-top:1px solid #e2e8f0}',
            '.nd-botoes button{border:1px solid transparent;border-radius:8px;padding:8px 18px;min-width:96px;font:inherit;font-size:.92rem;font-weight:600;cursor:pointer;transition:background .12s,border-color .12s}',
            '.nd-nao{background:#fff;color:#334155;border-color:#cbd5e1!important;box-shadow:0 1px 2px rgba(15,23,42,.05)}.nd-nao:hover{background:#f1f5f9}',
            '.nd-sim{background:var(--nd-cor);color:var(--nd-cor-texto)}.nd-sim:hover{filter:brightness(.94)}',
            '.nd-perigo .nd-sim{background:#b42318}.nd-perigo .nd-sim:hover{background:#912018;filter:none}',
            '.nd-sim:disabled{opacity:.55;cursor:not-allowed}',
            '.nd-perigo .nd-botoes button:focus-visible,.nd-fechar:focus-visible{outline:3px solid rgba(100,116,139,.35);outline-offset:2px}',
            '.nd-botoes button:focus-visible{outline:3px solid color-mix(in srgb,var(--nd-cor) 45%,transparent);outline-offset:2px}',
            '.nd-rotulo{display:block;font-size:.88rem;font-weight:600;margin-bottom:6px;color:#374151}',
            '.nd-campo{width:100%;border:1px solid #d1d5db;border-radius:10px;padding:10px 12px;font:inherit;resize:vertical}',
            '.nd-campo:focus{outline:none;border-color:var(--nd-cor);box-shadow:0 0 0 3px color-mix(in srgb,var(--nd-cor) 20%,transparent)}',
            '.nd-avisos{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:20060;display:flex;flex-direction:column;gap:8px;align-items:center;pointer-events:none}',
            '.nd-aviso{pointer-events:auto;display:flex;align-items:center;gap:14px;background:#111827;color:#fff;border-radius:12px;padding:11px 16px;box-shadow:0 12px 32px rgba(0,0,0,.25);font-size:.93rem;max-width:min(560px,calc(100vw - 32px));animation:nd-in .15s ease-out}',
            '.nd-aviso.nd-erro{background:#7f1d1d}',
            '.nd-aviso button{background:none;border:0;color:#fdba74;font:inherit;font-weight:700;cursor:pointer;padding:2px 4px}',
            '@keyframes nd-in{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}',
            '@media (prefers-reduced-motion:reduce){.nd-fundo,.nd-aviso{animation:none}}'
        ].join('\n');
        document.head.appendChild(s);
    }

    function el(tag, cls, texto) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (texto != null) { e.textContent = texto; }
        return e;
    }

    var fila = Promise.resolve();

    /** Mostra a confirmacao. Resolve true (confirmou) ou false. */
    function confirmar(opcoes) {
        var o = typeof opcoes === 'string' ? { mensagem: opcoes } : (opcoes || {});
        var vez = fila.then(function () { return abrir(o); });
        fila = vez.catch(function () {});
        return vez;
    }

    function abrir(o) {
        estilos();
        return new Promise(function (resolve) {
            var anterior = document.activeElement;
            var fundo = el('div', 'nd-fundo');
            var caixa = el('div', 'nd-caixa' + (o.perigo ? ' nd-perigo' : '') + (o.larga ? ' nd-larga' : ''));
            caixa.setAttribute('role', 'alertdialog');
            caixa.setAttribute('aria-modal', 'true');

            // Sem titulo: a primeira linha da mensagem passa a titulo ("Descartar este lote?\n\nO saldo...").
            var titulo = o.titulo;
            var mensagem = o.mensagem || '';
            if (!titulo) {
                var partes = String(mensagem).split(/\n+/);
                titulo = partes.shift() || 'Confirmar';
                mensagem = partes.join('\n');
            }
            var cabeca = el('div', 'nd-cabeca');
            // Icone discreto (Font Awesome). Sem "!" nem "?": aspeto mais sobrio.
            var icone = el('div', 'nd-icone');
            var classeIcone = o.icone || (o.perigo ? 'fas fa-shield-halved' : 'fas fa-circle-info');
            icone.innerHTML = '<i class="' + String(classeIcone).replace(/[^a-z0-9 -]/gi, '') + '" aria-hidden="true"></i>';
            cabeca.appendChild(icone);
            var textos = el('div');
            var h = el('h2', 'nd-titulo', titulo);
            h.id = 'nd-t-' + Date.now();
            caixa.setAttribute('aria-labelledby', h.id);
            textos.appendChild(h);
            if (mensagem) { textos.appendChild(el('p', 'nd-msg', mensagem)); }
            cabeca.appendChild(textos);
            caixa.appendChild(cabeca);

            if (o.conteudo) {
                var corpo = el('div', 'nd-corpo');
                if (typeof o.conteudo === 'string') { corpo.innerHTML = o.conteudo; } else { corpo.appendChild(o.conteudo); }
                caixa.appendChild(corpo);
            }

            var campo = null;
            if (o.campo) {
                var zona = el('div', 'nd-corpo');
                var rot = el('label', 'nd-rotulo', o.campo.rotulo || '');
                campo = o.campo.multilinha ? el('textarea', 'nd-campo') : el('input', 'nd-campo');
                if (!o.campo.multilinha) { campo.type = 'text'; }
                campo.id = 'nd-c-' + Date.now();
                rot.setAttribute('for', campo.id);
                campo.value = o.campo.valor || '';
                if (o.campo.max) { campo.maxLength = o.campo.max; }
                if (o.campo.placeholder) { campo.placeholder = o.campo.placeholder; }
                if (o.campo.rotulo) { zona.appendChild(rot); }
                zona.appendChild(campo);
                caixa.appendChild(zona);
            }

            var botoes = el('div', 'nd-botoes');
            var nao = el('button', 'nd-nao', o.cancelar || 'Cancelar');
            var sim = el('button', 'nd-sim', o.confirmar || (o.perigo ? 'Sim, continuar' : 'Confirmar'));
            nao.type = 'button';
            sim.type = 'button';
            if (o.somenteOk) { nao.hidden = true; }
            botoes.appendChild(nao);
            botoes.appendChild(sim);
            caixa.appendChild(botoes);
            var fecharX = el('button', 'nd-fechar', '\u00d7');
            fecharX.type = 'button';
            fecharX.setAttribute('aria-label', 'Fechar');
            if (o.somenteOk) { fecharX.hidden = true; }
            caixa.appendChild(fecharX);
            fundo.appendChild(caixa);
            document.body.appendChild(fundo);
            // Um modal Bootstrap aberto por baixo nao rouba o foco desta janela.
            fundo.addEventListener('focusin', function (e) { e.stopPropagation(); });

            if (typeof o.aoAbrir === 'function') { o.aoAbrir({ caixa: caixa, botaoConfirmar: sim }); }

            function fechar(valor) {
                document.removeEventListener('keydown', teclas, true);
                fundo.remove();
                if (anterior && typeof anterior.focus === 'function') { try { anterior.focus(); } catch (e) {} }
                // Com campo de texto: devolve o texto escrito, ou null se cancelou.
                resolve(campo ? (valor ? campo.value.trim() : null) : valor);
            }
            if (campo && o.campo.obrigatorio) {
                var validar = function () { sim.disabled = campo.value.trim() === ''; };
                campo.addEventListener('input', validar);
                validar();
            }
            if (campo && !o.campo.multilinha) {
                campo.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); sim.click(); } });
            }
            function teclas(e) {
                if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); fechar(false); return; }
                if (e.key === 'Tab') {
                    var foco = Array.prototype.filter.call(caixa.querySelectorAll('button,input,select,textarea,a[href]'), function (x) { return !x.disabled && !x.hidden && x.offsetParent !== null; });
                    if (!foco.length) { return; }
                    var i = foco.indexOf(document.activeElement);
                    if (e.shiftKey && i <= 0) { e.preventDefault(); foco[foco.length - 1].focus(); }
                    else if (!e.shiftKey && i === foco.length - 1) { e.preventDefault(); foco[0].focus(); }
                }
            }
            document.addEventListener('keydown', teclas, true);
            nao.addEventListener('click', function () { fechar(false); });
            fecharX.addEventListener('click', function () { fechar(false); });
            sim.addEventListener('click', function () { if (!sim.disabled) { fechar(true); } });
            fundo.addEventListener('mousedown', function (e) { if (e.target === fundo && !o.perigo) { fechar(false); } });
            // Acao perigosa: o foco comeca em Cancelar, para um Enter distraido nao confirmar.
            (campo || (o.perigo ? nao : sim)).focus();
        });
    }

    /** Mensagem curta no fundo do ecra, com acao opcional (ex.: Desfazer). */
    function aviso(texto, opcoes) {
        var o = opcoes || {};
        // Se a pagina tem as notificacoes do Nivora (js/feedback.js), usa-as: um so estilo de aviso.
        if (window.NivoraFeedback) {
            var tipos = { sucesso: 'success', erro: 'error', aviso: 'warning', info: 'info' };
            var extra = {};
            if (o.duracao) { extra.duration = o.duracao; }
            if (o.acao && typeof o.acao.fn === 'function') { extra.action = { label: o.acao.texto || 'Desfazer', onClick: o.acao.fn }; }
            var n = window.NivoraFeedback.show(texto, tipos[o.tipo] || o.tipo || 'success', extra);
            return function () { if (n) { n.remove(); } };
        }
        estilos();
        var zona = document.querySelector('.nd-avisos');
        if (!zona) {
            zona = el('div', 'nd-avisos');
            zona.setAttribute('aria-live', 'polite');
            document.body.appendChild(zona);
        }
        var a = el('div', 'nd-aviso' + (o.tipo === 'erro' ? ' nd-erro' : ''));
        a.setAttribute('role', o.tipo === 'erro' ? 'alert' : 'status');
        a.appendChild(el('span', '', texto));
        var timer = null;
        function tirar() { clearTimeout(timer); a.remove(); }
        if (o.acao && typeof o.acao.fn === 'function') {
            var b = el('button', '', o.acao.texto || 'Desfazer');
            b.type = 'button';
            b.addEventListener('click', function () { tirar(); o.acao.fn(); });
            a.appendChild(b);
        }
        zona.appendChild(a);
        timer = setTimeout(tirar, o.duracao || (o.acao ? 10000 : 4500));
        return tirar;
    }

    /** Pede um texto (substitui prompt()). Resolve com o texto, ou null se cancelar. */
    function perguntar(opcoes) {
        var o = Object.assign({}, opcoes || {});
        o.campo = Object.assign({ rotulo: '', valor: '' }, o.campo || {});
        return confirmar(o);
    }

    window.NivoraDialogo = { confirmar: confirmar, perguntar: perguntar, aviso: aviso };
    window.nivoraPerguntar = perguntar;
    window.nivoraConfirmar = confirmar;
    window.nivoraAviso = aviso;
})();
