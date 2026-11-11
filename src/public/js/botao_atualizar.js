/*
 * Retorno visual dos botoes "Atualizar" (marcados com data-atualizar).
 * Antes, ao clicar, os dados eram recarregados mas o ecra ficava igual e parecia que nada acontecia.
 * Agora: o icone gira enquanto ha pedidos ao servidor e no fim o botao diz "Atualizado" ou "Falhou".
 * Funciona com qualquer codigo existente: observa os pedidos (fetch) feitos logo apos o clique.
 */
(function () {
    'use strict';
    if (window.__nvBotaoAtualizar) { return; }
    window.__nvBotaoAtualizar = true;

    var estilo = document.createElement('style');
    estilo.textContent = '.nv-atualizar-falhou{border-color:#dc2626!important;color:#b91c1c!important}[data-atualizar][aria-busy="true"]{opacity:.85;cursor:progress}';
    (document.head || document.documentElement).appendChild(estilo);

    var pendentes = 0;
    var falhou = false;
    var fetchOriginal = window.fetch ? window.fetch.bind(window) : null;
    if (fetchOriginal) {
        window.fetch = function () {
            pendentes++;
            return fetchOriginal.apply(window, arguments).then(function (r) {
                if (!r.ok) { falhou = true; }
                return r;
            }, function (e) {
                falhou = true;
                throw e;
            }).finally(function () { pendentes = Math.max(0, pendentes - 1); });
        };
    }

    function rotulo(btn) {
        var span = btn.querySelector('.nv-atualizar-rotulo');
        if (span) { return span; }
        // Embrulha o texto do botao (sem o icone) para o poder trocar.
        span = document.createElement('span');
        span.className = 'nv-atualizar-rotulo';
        Array.prototype.slice.call(btn.childNodes).forEach(function (n) {
            if (n.nodeType === 3 && n.textContent.trim()) { span.appendChild(n); }
        });
        btn.appendChild(span);
        return span;
    }

    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest && ev.target.closest('[data-atualizar]');
        if (!btn || btn.dataset.nvAtualizando === '1') { return; }
        var icone = btn.querySelector('i');
        var span = rotulo(btn);
        var textoOriginal = span.textContent;
        btn.dataset.nvAtualizando = '1';
        btn.setAttribute('aria-busy', 'true');
        falhou = false;
        if (icone) { icone.classList.add('fa-spin'); }
        span.textContent = ' A atualizar…';
        var inicio = Date.now();
        // Espera pelo menos 400 ms (para se ver) e no maximo 12 s pelos pedidos ao servidor.
        var vigia = setInterval(function () {
            var passou = Date.now() - inicio;
            if ((pendentes === 0 && passou > 400) || passou > 12000) {
                clearInterval(vigia);
                if (icone) { icone.classList.remove('fa-spin'); }
                btn.removeAttribute('aria-busy');
                span.textContent = falhou ? ' Falhou — tente de novo' : ' Atualizado ✓';
                btn.classList.toggle('nv-atualizar-falhou', falhou);
                setTimeout(function () {
                    span.textContent = textoOriginal;
                    btn.classList.remove('nv-atualizar-falhou');
                    btn.dataset.nvAtualizando = '';
                }, falhou ? 3500 : 1800);
            }
        }, 150);
    }, true);
})();
