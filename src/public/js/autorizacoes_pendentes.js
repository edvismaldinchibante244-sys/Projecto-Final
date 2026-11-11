/*
 * Pedidos de autorizacao a distancia — aparece para gerentes e administradores em qualquer
 * pagina do sistema. Quando um garcom/caixa pede (ex.: cancelar um pedido), surge um cartao
 * no canto do ecra com "Aprovar" / "Recusar". Carregado por js/nv_acoes.js.
 */
(function () {
    'use strict';
    if (window.__nvAutorizacoesPendentes) { return; }
    window.__nvAutorizacoesPendentes = true;
    var base = location.pathname.replace(/[^\/]*$/, '');
    var URL_API = base + 'api/autorizacoes_remotas.php';
    var csrf = '', vistos = {}, caixa = null;

    function estilos() {
        var s = document.createElement('style');
        s.textContent =
            '.nvar{position:fixed;right:16px;bottom:16px;z-index:19990;display:flex;flex-direction:column;gap:10px;max-width:360px;width:calc(100% - 32px);font-family:inherit}' +
            '.nvar__c{background:#fff;color:#1f1a16;border:1px solid #e6ddd3;border-left:4px solid #b8873e;border-radius:14px;box-shadow:0 14px 40px rgba(15,12,10,.25);padding:14px 16px;animation:nvarEntra .25s ease-out}' +
            '@keyframes nvarEntra{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}' +
            '.nvar__t{display:flex;align-items:center;gap:8px;font-weight:700;font-size:.95rem;margin:0 0 4px}' +
            '.nvar__t span{font-size:.7rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;background:#f3e6d1;color:#8a5a14;border-radius:999px;padding:2px 8px}' +
            '.nvar__d{margin:0 0 2px;font-size:.92rem}.nvar__q{margin:0 0 12px;color:#6b6259;font-size:.82rem}' +
            '.nvar__b{display:flex;gap:8px}.nvar__b button{flex:1;border:0;border-radius:10px;padding:9px 10px;font:inherit;font-weight:600;cursor:pointer}' +
            '.nvar__sim{background:#1f7a46;color:#fff}.nvar__nao{background:#f1ece6;color:#1f1a16}.nvar__b button:disabled{opacity:.6;cursor:wait}' +
            '.nvar__ok{margin:0;font-size:.9rem;color:#1f7a46;font-weight:600}.nvar__ok--nao{color:#8a1c12}';
        document.head.appendChild(s);
    }
    function el(tag, cls, txt) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (txt != null) { e.textContent = txt; } return e; }
    function ha(seg) { return seg < 60 ? 'agora mesmo' : 'há ' + Math.floor(seg / 60) + ' min'; }
    function som() {
        try {
            var A = window.AudioContext || window.webkitAudioContext; if (!A) { return; }
            var c = new A(), o = c.createOscillator(), g = c.createGain();
            o.frequency.value = 880; g.gain.value = 0.08; o.connect(g); g.connect(c.destination);
            o.start(); o.stop(c.currentTime + 0.18);
        } catch (e) {}
    }
    function decidir(p, decisao, cartao) {
        cartao.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
        var fd = new FormData(); fd.append('id', p.id); fd.append('decisao', decisao); fd.append('_csrf', csrf);
        fetch(URL_API, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json().catch(function () { return {}; }); })
            .then(function (j) {
                var b = cartao.querySelector('.nvar__b'); if (b) { b.remove(); }
                cartao.appendChild(el('p', 'nvar__ok' + (decisao === 'recusar' || !j.success ? ' nvar__ok--nao' : ''),
                    j.success ? (decisao === 'aprovar' ? '✓ Aprovado. ' + p.quem + ' pode continuar.' : 'Recusado.') : (j.message || 'Não foi possível responder.')));
                setTimeout(function () { cartao.remove(); }, 3500);
            });
    }
    function mostrar(lista) {
        if (!caixa) { caixa = el('div', 'nvar'); caixa.setAttribute('role', 'region'); caixa.setAttribute('aria-label', 'Pedidos de autorização'); document.body.appendChild(caixa); }
        var ativos = {};
        var novo = false;
        lista.forEach(function (p) {
            ativos[p.id] = true;
            var existente = caixa.querySelector('[data-id="' + p.id + '"]');
            if (existente) { var q = existente.querySelector('.nvar__q'); if (q) { q.textContent = p.quem + ' · ' + p.perfil + ' · ' + ha(p.ha_seg); } return; }
            if (!vistos[p.id]) { novo = true; vistos[p.id] = true; }
            var c = el('div', 'nvar__c'); c.setAttribute('data-id', p.id); c.setAttribute('role', 'alert');
            var t = el('p', 'nvar__t'); t.appendChild(el('span', null, 'Autorização')); t.appendChild(document.createTextNode(p.titulo));
            c.appendChild(t);
            c.appendChild(el('p', 'nvar__d', p.descricao));
            c.appendChild(el('p', 'nvar__q', p.quem + ' · ' + p.perfil + ' · ' + ha(p.ha_seg)));
            var b = el('div', 'nvar__b');
            var nao = el('button', 'nvar__nao', 'Recusar'); nao.type = 'button';
            var sim = el('button', 'nvar__sim', 'Aprovar'); sim.type = 'button';
            nao.addEventListener('click', function () { decidir(p, 'recusar', c); });
            sim.addEventListener('click', function () { decidir(p, 'aprovar', c); });
            b.appendChild(nao); b.appendChild(sim); c.appendChild(b);
            caixa.appendChild(c);
        });
        // Respondidos noutro dispositivo ou expirados: sai do ecra.
        caixa.querySelectorAll('.nvar__c').forEach(function (c) {
            if (!ativos[c.getAttribute('data-id')] && c.querySelector('.nvar__b')) { c.remove(); }
        });
        if (novo) { som(); }
    }
    function verificar() {
        fetch(URL_API, { credentials: 'same-origin', cache: 'no-store', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) { if (j && j.success) { csrf = j.csrf || csrf; mostrar(j.pendentes || []); } })
            .catch(function () {});
    }
    estilos();
    verificar();
    window.setInterval(verificar, 8000);
    // Com o servidor de tempo real ligado, um pedido novo aparece logo.
    if (window.NvOperacao && window.NvOperacao.vigiar) {
        try { window.NvOperacao.vigiar({ intervalo: 30000, forcarCada: 60000, aoMudar: verificar }); } catch (e) {}
    }
})();
