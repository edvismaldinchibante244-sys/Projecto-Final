/* Ecra de bloqueio do terminal: escolher pessoa -> PIN -> entrar. */
(function () {
    'use strict';
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var equipa = document.getElementById('termEquipa');
    var painel = document.getElementById('termPin');
    var pontos = document.getElementById('termPontos');
    var erro = document.getElementById('termErro');
    var nomeEl = document.getElementById('termPinNome');
    var relogio = document.getElementById('termRelogio');
    var usuarioId = 0;
    var pin = '';
    var ocupado = false;

    function hora() {
        if (relogio) { relogio.textContent = new Date().toLocaleTimeString('pt-PT', { hour: '2-digit', minute: '2-digit' }); }
    }
    hora();
    setInterval(hora, 15000);

    function desenhar() {
        pontos.innerHTML = '';
        var n = Math.max(4, pin.length);
        for (var i = 0; i < n; i++) {
            var s = document.createElement('span');
            if (i < pin.length) { s.className = 'cheio'; }
            pontos.appendChild(s);
        }
    }

    function abrir(id, nome) {
        usuarioId = id;
        pin = '';
        erro.textContent = '';
        nomeEl.textContent = 'Olá, ' + nome;
        equipa.hidden = true;
        painel.hidden = false;
        desenhar();
    }

    function voltar() {
        usuarioId = 0;
        pin = '';
        painel.hidden = true;
        equipa.hidden = false;
    }

    function entrar() {
        if (ocupado || pin.length < 4) {
            if (pin.length < 4) { erro.textContent = 'O PIN tem pelo menos 4 dígitos.'; }
            return;
        }
        ocupado = true;
        painel.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
        fetch('api/terminal_entrar.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify({ usuario_id: usuarioId, pin: pin })
        }).then(function (r) {
            return r.json().catch(function () { return { success: false, message: 'Erro inesperado.' }; });
        }).then(function (d) {
            if (d && d.success && d.redirect) {
                window.location.href = d.redirect;
                return;
            }
            if (d && d.sem_terminal) {
                window.location.href = 'index.php';
                return;
            }
            pin = '';
            desenhar();
            erro.textContent = (d && d.message) || 'Não foi possível entrar.';
        }).catch(function () {
            erro.textContent = 'Sem ligação. Tente novamente.';
        }).then(function () {
            ocupado = false;
            painel.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
        });
    }

    function tecla(t) {
        if (ocupado) { return; }
        if (t === 'apagar') { pin = pin.slice(0, -1); }
        else if (t === 'ok') { entrar(); return; }
        else if (/^\d$/.test(t) && pin.length < 6) { pin += t; erro.textContent = ''; }
        desenhar();
    }

    document.querySelectorAll('.term__pessoa').forEach(function (b) {
        b.addEventListener('click', function () { abrir(parseInt(b.dataset.id, 10), b.dataset.nome); });
    });
    painel.querySelectorAll('[data-tecla]').forEach(function (b) {
        b.addEventListener('click', function () { tecla(b.dataset.tecla); });
    });
    document.getElementById('termVoltar').addEventListener('click', voltar);
    document.addEventListener('keydown', function (e) {
        if (painel.hidden) { return; }
        if (/^\d$/.test(e.key)) { tecla(e.key); }
        else if (e.key === 'Backspace') { tecla('apagar'); }
        else if (e.key === 'Enter') { tecla('ok'); }
        else if (e.key === 'Escape') { voltar(); }
    });
})();
