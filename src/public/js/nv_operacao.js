/*
 * NvOperacao.vigiar — atualizacao leve dos ecras de operacao (cozinha, bar, pedidos, mesas).
 *
 * Em vez de recarregar a lista inteira a cada poucos segundos, o ecra pergunta ao servidor
 * so "a versao mudou?" (api/operacao_versao.php: resposta vazia quando nada mudou) e so
 * recarrega quando ha novidade. Se o servidor de tempo real estiver ativo, qualquer alteracao
 * (pedido novo, item pronto, mesa) chega logo por ele e a verificacao passa a ser a cada 30 s;
 * se o tempo real cair, volta sozinha ao intervalo normal. Por seguranca, recarrega sempre ao fim de `forcarCada` ms e, se a
 * verificacao falhar, volta ao comportamento antigo (recarregar), para nunca ficar parado.
 *
 *   var vigia = NvOperacao.vigiar({ intervalo: 4000, aoMudar: function (motivo) { recarregar(); } });
 *   vigia.parar();
 */
(function () {
    'use strict';
    if (window.NvOperacao) { return; }
    var ENDERECO = 'api/operacao_versao.php';
    // Com o tempo real ligado, os avisos chegam logo: a verificacao periodica passa a ser so uma rede de seguranca.
    var COM_TEMPO_REAL = 30000;
    var socketLigado = null; // um so socket por pagina
    var ouvintesTempoReal = [];

    function ligarTempoReal(rt) {
        if (!rt || !rt.url || socketLigado) { return; }
        socketLigado = 'a-ligar';
        var ligar = function () {
            try {
                var s = window.io(rt.url, { transports: ['websocket', 'polling'], reconnectionDelayMax: 30000 });
                var avisar = function (motivo) { ouvintesTempoReal.forEach(function (f) { f(motivo); }); };
                var ligadoAntes = false;
                s.on('connect', function () {
                    s.emit('join-room', { room: rt.room, exp: rt.exp, sig: rt.sig });
                    window.NvOperacao.tempoReal = true;
                    // Ao voltar a ligar, pode ter havido alteracoes enquanto esteve desligado.
                    if (ligadoAntes) { avisar('reconexao'); }
                    ligadoAntes = true;
                });
                // Sem tempo real (servidor em baixo, rede cortada): os ecras voltam sozinhos
                // a verificar de poucos em poucos segundos.
                s.on('disconnect', function () { window.NvOperacao.tempoReal = false; });
                s.on('join-recusado', function () { window.NvOperacao.tempoReal = false; s.close(); socketLigado = null; });
                s.on('novo_pedido', function () { avisar('tempo-real'); });
                s.on('operacao_mudou', function () { avisar('tempo-real'); });
                socketLigado = s;
            } catch (e) { socketLigado = null; }
        };
        if (window.io) { ligar(); return; }
        var sc = document.createElement('script');
        sc.src = rt.url + '/socket.io/socket.io.js';
        sc.async = true;
        sc.onload = ligar;
        sc.onerror = function () { socketLigado = null; };
        document.head.appendChild(sc);
    }

    function vigiar(o) {
        o = o || {};
        var intervalo = Math.max(2000, Number(o.intervalo) || 5000);
        var forcarCada = Math.max(intervalo, Number(o.forcarCada) || 60000);
        var versao = null, ultimo = Date.now(), aPedir = false, parado = false;

        function mudou(motivo) {
            if (parado) { return; }
            ultimo = Date.now();
            try { o.aoMudar(motivo); } catch (e) { if (window.console) { console.error(e); } }
        }
        ouvintesTempoReal.push(function () { mudou('tempo-real'); });

        var ultimaVerificacao = 0;
        function verificar(primeira) {
            if (aPedir || parado) { return; }
            if (primeira !== true && window.NvOperacao.tempoReal && Date.now() - ultimaVerificacao < COM_TEMPO_REAL) { return; }
            ultimaVerificacao = Date.now();
            aPedir = true;
            var url = ENDERECO + (primeira === true ? '?rt=1' : '?v=' + encodeURIComponent(versao || ''));
            fetch(url, { cache: 'no-store', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) {
                    if (r.status === 204) { return null; }
                    if (!r.ok) { throw new Error('HTTP ' + r.status); }
                    return r.json();
                })
                .then(function (d) {
                    if (d && d.v) {
                        var antes = versao;
                        versao = String(d.v);
                        if (primeira === true) {
                            if (d.rt) { ligarTempoReal(d.rt); }
                        } else if (antes !== null && antes !== versao) {
                            mudou('versao');
                            return;
                        }
                    }
                    if (Date.now() - ultimo >= forcarCada) { mudou('periodico'); }
                })
                .catch(function () { mudou('recurso'); })
                .then(function () { aPedir = false; });
        }

        verificar(true);
        var id = setInterval(verificar, intervalo);
        return {
            parar: function () { parado = true; clearInterval(id); },
            agora: function () { mudou('manual'); }
        };
    }

    window.NvOperacao = { vigiar: vigiar, tempoReal: false };
})();
