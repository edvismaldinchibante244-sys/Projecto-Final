/*
 * Ritmo das atualizacoes automaticas (NvRitmo) — reduz a carga no servidor sem o restaurante notar.
 *
 * Todas as paginas usam setInterval para ir buscar novidades ao servidor. Aqui, cada setInterval
 * de 3 segundos ou mais passa a respeitar o estado do ecra:
 *   - separador escondido (outra aba, janela minimizada): no maximo a cada 30 s
 *     (15 s nos ecras de operacao: pedidos, cozinha, bar, mesas, caixa, terminal);
 *   - ninguem mexe ha 5 minutos (so paginas de gestao): no maximo a cada 30 s;
 *   - ao voltar ao separador ou mexer no rato/teclado: atualiza logo.
 * Relogios e contadores (intervalos < 3 s) nao sao afetados.
 */
(function () {
    'use strict';
    if (window.NvRitmo || typeof window.setInterval !== 'function') { return; }
    var nativoSet = window.setInterval, nativoClear = window.clearInterval;
    var MINIMO = 3000, PARADO_APOS = 5 * 60 * 1000;
    var operacional = /\/(cozinha|bar|pedidos|garcom_mesas|garcom_pedidos|caixa_mesas|caixa|pdv|terminal|kds[a-z_]*)\.php$/i.test(location.pathname || '');
    var OCULTO = operacional ? 15000 : 30000, PARADO = 30000;
    var ultimaAtividade = Date.now();
    var vigiados = [];

    function oculto() { return document.visibilityState === 'hidden'; }
    function parado() { return !operacional && Date.now() - ultimaAtividade > PARADO_APOS; }
    function intervaloAtual(base) {
        if (oculto()) { return Math.max(base, OCULTO); }
        if (parado()) { return Math.max(base, PARADO); }
        return base;
    }
    function correr(r) {
        r.ultimo = Date.now();
        try { r.fn.apply(window, r.args); } catch (e) { if (window.console) { console.error(e); } }
    }
    function acordar() {
        var agora = Date.now();
        vigiados.slice().forEach(function (r) { if (agora - r.ultimo >= r.base) { correr(r); } });
    }

    window.setInterval = function (fn, ms) {
        var base = Number(ms) || 0;
        if (typeof fn !== 'function' || base < MINIMO) { return nativoSet.apply(window, arguments); }
        var r = { fn: fn, args: Array.prototype.slice.call(arguments, 2), base: base, ultimo: Date.now() };
        r.id = nativoSet.call(window, function () {
            if (Date.now() - r.ultimo + 100 < intervaloAtual(r.base)) { return; }
            correr(r);
        }, base);
        vigiados.push(r);
        return r.id;
    };
    window.clearInterval = function (id) {
        vigiados = vigiados.filter(function (r) { return r.id !== id; });
        return nativoClear.call(window, id);
    };

    var aoMexer = function () {
        var estavaParado = parado();
        ultimaAtividade = Date.now();
        if (estavaParado) { acordar(); }
    };
    ['pointerdown', 'keydown', 'touchstart', 'wheel', 'mousemove'].forEach(function (ev) {
        window.addEventListener(ev, aoMexer, { passive: true, capture: true });
    });
    document.addEventListener('visibilitychange', function () { if (!oculto()) { acordar(); } });

    window.NvRitmo = { oculto: oculto, parado: parado, intervaloAtual: intervaloAtual, acordar: acordar, operacional: operacional,
        inativoSeg: function () { return Math.max(0, Math.floor((Date.now() - ultimaAtividade) / 1000)); } };

    // Cada pedido ao servidor diz ha quantos segundos ninguem mexe neste ecra (X-Nv-Inativo).
    // O servidor so conta como uso as acoes reais: um ecra que se atualiza sozinho deixa a sessao
    // expirar aos 45 min sem uso, e o terminal mostra o ecra do PIN.
    function mesmaOrigem(url) {
        try { return new URL(url, location.href).origin === location.origin; } catch (e) { return false; }
    }
    function irParaBloqueio(destino) {
        if (window.__nvSaida) { return; }
        window.__nvSaida = true;
        destino = destino || 'index.php';
        // Sessao em pausa: depois da palavra-passe volta a esta mesma pagina.
        if (/retomar\.php$/.test(destino)) {
            var aqui = location.pathname.replace(/^.*\//, '') + location.search;
            if (/^[a-z0-9_]+\.php/i.test(aqui)) { destino += '?voltar=' + encodeURIComponent(aqui); }
        }
        location.href = destino;
    }
    if (typeof window.fetch === 'function') {
        var fetchNativo = window.fetch;
        window.fetch = function (recurso, opcoes) {
            var url = typeof recurso === 'string' ? recurso : (recurso && recurso.url) || '';
            if (!mesmaOrigem(url)) { return fetchNativo.apply(this, arguments); }
            opcoes = opcoes ? Object.assign({}, opcoes) : {};
            var h = new Headers(opcoes.headers || (recurso && recurso.headers) || {});
            h.set('X-Nv-Inativo', String(window.NvRitmo.inativoSeg()));
            opcoes.headers = h;
            return fetchNativo.call(this, recurso, opcoes).then(function (r) {
                if (r.headers.get('X-Nv-Sessao') === 'expirada') {
                    irParaBloqueio(r.headers.get('X-Nv-Redirect'));
                } else if (r.redirected && /\/(terminal|index|retomar)\.php(\?|$)/.test(r.url) && !/\/(terminal|index|retomar)\.php/.test(location.pathname)) {
                    irParaBloqueio(r.url);
                }
                return r;
            });
        };
    }
    // Vigia da sessao nas paginas internas sem atualizacoes automaticas (ex.: caixa): pergunta a cada
    // minuto se a sessao ainda vale (sem contar como uso). Paginas publicas e do Super Admin ficam de fora.
    var PUBLICAS = /\/(index|terminal|retomar|cardapio|direitos_[a-z_]+|fazer_reserva|reserva_qr|esqueci_senha|nova_senha|reset_senha|redefinir_senha_admin|solicitar_plano|criar_primeiro_admin|privacidade|termos|cookies|login_process|logout|admin[a-z_]*)\.php$/i;
    function vigiarSessao() {
        if (!/(^|;\s*)nv_sessao=1/.test(document.cookie) || PUBLICAS.test(location.pathname) || !/\.php$/i.test(location.pathname)) { return; }
        var base = location.pathname.replace(/[^\/]*$/, '');
        var verificar = function () {
            if (window.__nvSaida) { return; }
            window.fetch(base + 'api/sessao_estado.php', { credentials: 'same-origin', cache: 'no-store', headers: { 'X-Requested-With': 'XMLHttpRequest' } }).catch(function () {});
        };
        window.setInterval(verificar, 60000);
        document.addEventListener('visibilitychange', function () { if (!oculto()) { verificar(); } });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', vigiarSessao); } else { vigiarSessao(); }

    // Gerentes e administradores: pedidos de autorizacao a distancia aparecem em qualquer pagina.
    function carregarAutorizacoes() {
        if (!/(^|;\s*)nv_aprova=1/.test(document.cookie) || PUBLICAS.test(location.pathname) || !/\.php$/i.test(location.pathname)) { return; }
        var sc = document.createElement('script');
        sc.src = location.pathname.replace(/[^\/]*$/, '') + 'js/autorizacoes_pendentes.js?v=20261108a';
        sc.async = true;
        document.head.appendChild(sc);
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', carregarAutorizacoes); } else { carregarAutorizacoes(); }

    if (window.XMLHttpRequest) {
        var abrirNativo = XMLHttpRequest.prototype.open, enviarNativo = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.open = function (metodo, url) {
            this.__nvMesma = mesmaOrigem(url);
            return abrirNativo.apply(this, arguments);
        };
        XMLHttpRequest.prototype.send = function () {
            if (this.__nvMesma) {
                try { this.setRequestHeader('X-Nv-Inativo', String(window.NvRitmo.inativoSeg())); } catch (e) {}
                var xhr = this;
                xhr.addEventListener('load', function () {
                    try { if (xhr.getResponseHeader('X-Nv-Sessao') === 'expirada') { irParaBloqueio(xhr.getResponseHeader('X-Nv-Redirect')); } } catch (e) {}
                });
            }
            return enviarNativo.apply(this, arguments);
        };
    }
})();

/*
 * nv_acoes.js — executa os atributos onclick="…", onchange="…", href="javascript:…" SEM 'unsafe-inline'.
 *
 * A politica de seguranca (CSP) do Nivora ja nao permite JavaScript escrito no HTML. Em vez de
 * reescrever as centenas de botoes do sistema, este ficheiro:
 *   1. tira os atributos on* (e href="javascript:") dos elementos assim que aparecem na pagina
 *      (incluindo os criados depois por JavaScript), e guarda o texto;
 *   2. quando o evento acontece, interpreta esse texto com um mini-interpretador SEGURO:
 *      so chamadas, propriedades, atribuicoes simples, if/else, return e literais. Sem eval,
 *      sem new Function, sem acesso a eval/Function/constructor/__proto__.
 *
 * Tem de ser carregado no <head>, antes do resto, para apanhar tudo.
 */
(function () {
    'use strict';
    if (window.NvAcoes) { return; }

    var EVENTOS = ['click', 'change', 'submit', 'input', 'keyup', 'keydown', 'keypress', 'blur', 'focus',
        'mouseover', 'mouseout', 'mouseenter', 'mouseleave', 'error', 'load', 'dblclick', 'contextmenu', 'reset'];
    var SEM_BOLHA = { blur: 1, focus: 1, error: 1, load: 1, mouseenter: 1, mouseleave: 1 };
    var GLOBAIS_PROIBIDOS = { eval: 1, Function: 1, setTimeout: 1, setInterval: 1, fetch: 1, XMLHttpRequest: 1, importScripts: 1, open: 1, WebSocket: 1 };
    var PROPS_PROIBIDAS = { constructor: 1, __proto__: 1, prototype: 1, call: 1, apply: 1, bind: 1,
        __defineGetter__: 1, __defineSetter__: 1, __lookupGetter__: 1, __lookupSetter__: 1, insertAdjacentHTML: 1, outerHTML: 1, srcdoc: 1 };
    var codigo = new WeakMap(); // elemento -> { click: '…', … }

    // ---------------- Tokenizador ----------------
    function tokens(src) {
        var t = [], i = 0, c;
        var PONT = ['===', '!==', '==', '!=', '&&', '||', '<=', '>=', '(', ')', '{', '}', '[', ']', ',', ';', '.', '=', '!', '+', '-', '*', '/', '<', '>', ':', '?'];
        while (i < src.length) {
            c = src[i];
            if (/\s/.test(c)) { i++; continue; }
            if (c === '"' || c === "'" || c === '`') {
                var q = c, s = '';
                i++;
                while (i < src.length && src[i] !== q) {
                    if (src[i] === '\\') {
                        i++;
                        var e = src[i];
                        s += e === 'n' ? '\n' : e === 't' ? '\t' : e === 'r' ? '\r' : e;
                    } else { s += src[i]; }
                    i++;
                }
                if (q === '`' && s.indexOf('${') !== -1) { throw new Error('template literal'); }
                i++;
                t.push({ k: 'str', v: s });
                continue;
            }
            if (/[0-9]/.test(c) || (c === '.' && /[0-9]/.test(src[i + 1] || ''))) {
                var m = /^[0-9]*\.?[0-9]+(e[+-]?[0-9]+)?/i.exec(src.slice(i));
                t.push({ k: 'num', v: parseFloat(m[0]) });
                i += m[0].length;
                continue;
            }
            if (/[A-Za-z_$]/.test(c)) {
                var w = /^[A-Za-z_$][A-Za-z0-9_$]*/.exec(src.slice(i))[0];
                t.push({ k: 'id', v: w });
                i += w.length;
                continue;
            }
            var p = null;
            for (var j = 0; j < PONT.length; j++) {
                if (src.substr(i, PONT[j].length) === PONT[j]) { p = PONT[j]; break; }
            }
            if (!p) { throw new Error('carácter inesperado: ' + c); }
            t.push({ k: 'p', v: p });
            i += p.length;
        }
        t.push({ k: 'fim' });
        return t;
    }

    // ---------------- Analisador (AST) ----------------
    function analisar(src) {
        var t = tokens(src), pos = 0;
        function ver(v) { return t[pos].k === 'p' && t[pos].v === v; }
        function verId(v) { return t[pos].k === 'id' && t[pos].v === v; }
        function comer(v) {
            if (v !== undefined && !(t[pos].v === v)) { throw new Error('esperado ' + v); }
            return t[pos++];
        }
        function programa() {
            var corpo = [];
            while (t[pos].k !== 'fim' && !ver('}')) {
                if (ver(';')) { pos++; continue; }
                corpo.push(instrucao());
            }
            return corpo;
        }
        function instrucao() {
            if (ver('{')) { pos++; var b = programa(); comer('}'); return { t: 'bloco', corpo: b }; }
            if (verId('if')) {
                pos++; comer('(');
                var cond = expr(); comer(')');
                var sim = instrucao(), nao = null;
                if (verId('else')) { pos++; nao = instrucao(); }
                return { t: 'if', cond: cond, sim: sim, nao: nao };
            }
            if (verId('return')) {
                pos++;
                var v = (ver(';') || ver('}') || t[pos].k === 'fim') ? null : expr();
                return { t: 'return', v: v };
            }
            var e = expr();
            if (ver('=')) { pos++; return { t: 'atrib', alvo: e, v: expr() }; }
            return { t: 'expr', e: e };
        }
        function expr() { return ternario(); }
        function ternario() {
            var c = ou();
            if (ver('?')) { pos++; var a = expr(); comer(':'); var b = expr(); return { t: 'cond', c: c, a: a, b: b }; }
            return c;
        }
        function binario(prox, ops) {
            return function () {
                var e = prox();
                while (t[pos].k === 'p' && ops.indexOf(t[pos].v) !== -1) {
                    var op = t[pos++].v;
                    e = { t: 'bin', op: op, a: e, b: prox() };
                }
                return e;
            };
        }
        var mult = binario(unario, ['*', '/']);
        var soma = binario(mult, ['+', '-']);
        var comp = binario(soma, ['<', '>', '<=', '>=']);
        var igual = binario(comp, ['===', '!==', '==', '!=']);
        var e_ = binario(igual, ['&&']);
        var ou = binario(e_, ['||']);
        function unario() {
            if (ver('!')) { pos++; return { t: 'nao', e: unario() }; }
            if (ver('-')) { pos++; return { t: 'neg', e: unario() }; }
            return posfixo();
        }
        function posfixo() {
            var e = primario();
            for (;;) {
                if (ver('.')) { pos++; e = { t: 'membro', o: e, p: { t: 'lit', v: comer().v } }; }
                else if (ver('[')) { pos++; var p = expr(); comer(']'); e = { t: 'membro', o: e, p: p }; }
                else if (ver('(')) { pos++; e = { t: 'chamada', f: e, args: lista(')') }; }
                else { return e; }
            }
        }
        function lista(fecho) {
            var a = [];
            while (!ver(fecho)) { a.push(expr()); if (ver(',')) { pos++; } }
            comer(fecho);
            return a;
        }
        function primario() {
            var k = t[pos];
            if (k.k === 'num' || k.k === 'str') { pos++; return { t: 'lit', v: k.v }; }
            if (ver('(')) { pos++; var e = expr(); comer(')'); return e; }
            if (ver('[')) { pos++; return { t: 'arr', itens: lista(']') }; }
            if (ver('{')) {
                pos++;
                var props = [];
                while (!ver('}')) {
                    var chave = comer().v;
                    comer(':');
                    props.push([String(chave), expr()]);
                    if (ver(',')) { pos++; }
                }
                comer('}');
                return { t: 'obj', props: props };
            }
            if (k.k === 'id') {
                pos++;
                if (k.v === 'true') { return { t: 'lit', v: true }; }
                if (k.v === 'false') { return { t: 'lit', v: false }; }
                if (k.v === 'null') { return { t: 'lit', v: null }; }
                if (k.v === 'undefined') { return { t: 'lit', v: undefined }; }
                return { t: 'nome', v: k.v };
            }
            throw new Error('expressão inválida');
        }
        var ast = programa();
        if (t[pos].k !== 'fim') { throw new Error('texto a mais'); }
        return ast;
    }

    // ---------------- Avaliacao ----------------
    var RETORNO = {};
    function avaliar(n, ctx) {
        switch (n.t) {
            case 'lit': return n.v;
            case 'nome':
                if (n.v === 'this') { return ctx.este; }
                if (n.v === 'event') { return ctx.evento; }
                if (GLOBAIS_PROIBIDOS[n.v]) { throw new Error(n.v + ' não é permitido'); }
                if (!(n.v in window)) { throw new ReferenceError(n.v + ' is not defined'); }
                return window[n.v];
            case 'membro': {
                var o = avaliar(n.o, ctx), p = avaliar(n.p, ctx);
                if (PROPS_PROIBIDAS[p]) { throw new Error('propriedade não permitida: ' + p); }
                return o == null ? undefined : o[p];
            }
            case 'chamada': {
                var dono = null, fn;
                if (n.f.t === 'membro') {
                    dono = avaliar(n.f.o, ctx);
                    var prop = avaliar(n.f.p, ctx);
                    if (PROPS_PROIBIDAS[prop]) { throw new Error('método não permitido: ' + prop); }
                    fn = dono == null ? undefined : dono[prop];
                } else {
                    fn = avaliar(n.f, ctx);
                }
                if (typeof fn !== 'function') { throw new TypeError('não é uma função'); }
                if (fn === window.eval || fn === window.Function || fn === window.setTimeout || fn === window.setInterval) {
                    throw new Error('função não permitida');
                }
                var args = n.args.map(function (a) { return avaliar(a, ctx); });
                return fn.apply(dono, args);
            }
            case 'arr': return n.itens.map(function (a) { return avaliar(a, ctx); });
            case 'obj': {
                var r = {};
                n.props.forEach(function (kv) {
                    if (PROPS_PROIBIDAS[kv[0]]) { return; }
                    r[kv[0]] = avaliar(kv[1], ctx);
                });
                return r;
            }
            case 'nao': return !avaliar(n.e, ctx);
            case 'neg': return -avaliar(n.e, ctx);
            case 'cond': return avaliar(n.c, ctx) ? avaliar(n.a, ctx) : avaliar(n.b, ctx);
            case 'bin': {
                if (n.op === '&&') { return avaliar(n.a, ctx) && avaliar(n.b, ctx); }
                if (n.op === '||') { return avaliar(n.a, ctx) || avaliar(n.b, ctx); }
                var a = avaliar(n.a, ctx), b = avaliar(n.b, ctx);
                switch (n.op) {
                    case '+': return a + b;
                    case '-': return a - b;
                    case '*': return a * b;
                    case '/': return a / b;
                    case '===': return a === b;
                    case '!==': return a !== b;
                    case '==': return a == b; // eslint-disable-line eqeqeq
                    case '!=': return a != b; // eslint-disable-line eqeqeq
                    case '<': return a < b;
                    case '>': return a > b;
                    case '<=': return a <= b;
                    case '>=': return a >= b;
                }
            }
        }
        throw new Error('nó desconhecido ' + n.t);
    }

    function executar(lista, ctx) {
        for (var i = 0; i < lista.length; i++) {
            var s = lista[i];
            if (s.t === 'expr') { avaliar(s.e, ctx); }
            else if (s.t === 'bloco') { executar(s.corpo, ctx); }
            else if (s.t === 'if') {
                if (avaliar(s.cond, ctx)) { executar([s.sim], ctx); } else if (s.nao) { executar([s.nao], ctx); }
            } else if (s.t === 'return') {
                ctx.retorno = s.v ? avaliar(s.v, ctx) : undefined;
                throw RETORNO;
            } else if (s.t === 'atrib') {
                if (s.alvo.t !== 'membro') { throw new Error('só se pode atribuir a propriedades'); }
                var dono = avaliar(s.alvo.o, ctx), prop = avaliar(s.alvo.p, ctx);
                var valor = avaliar(s.v, ctx);
                // this.onerror = null e permitido (fallback de imagens); dar codigo a um on* nao.
                if (PROPS_PROIBIDAS[prop] || (/^on/i.test(String(prop)) && valor !== null)) { throw new Error('atribuição não permitida: ' + prop); }
                dono[prop] = valor;
            }
        }
    }

    var cache = new Map();
    function correr(texto, este, evento) {
        var ast = cache.get(texto);
        if (!ast) { ast = analisar(texto); cache.set(texto, ast); }
        var ctx = { este: este, evento: evento, retorno: undefined };
        try { executar(ast, ctx); } catch (e) { if (e !== RETORNO) { throw e; } }
        return ctx.retorno;
    }

    // ---------------- Captura dos atributos ----------------
    function recolher(el) {
        if (!el || el.nodeType !== 1) { return; }
        var mapa = null;
        for (var i = el.attributes.length - 1; i >= 0; i--) {
            var a = el.attributes[i];
            var nome = a.name.toLowerCase();
            if (nome.length > 2 && nome.slice(0, 2) === 'on' && EVENTOS.indexOf(nome.slice(2)) !== -1) {
                mapa = mapa || codigo.get(el) || {};
                mapa[nome.slice(2)] = a.value;
                el.removeAttribute(a.name);
            }
        }
        var href = el.getAttribute && el.getAttribute('href');
        if (href && /^\s*javascript:/i.test(href)) {
            mapa = mapa || codigo.get(el) || {};
            var js = href.replace(/^\s*javascript:/i, '');
            if (js.trim() && js.trim() !== 'void(0)' && js.trim() !== 'void 0' && js.trim() !== ';') {
                mapa.click = (mapa.click ? mapa.click + ';' : '') + js;
            }
            el.setAttribute('href', '#');
            el.setAttribute('data-nv-js', '1');
        }
        if (mapa) { codigo.set(el, mapa); }
    }
    function recolherArvore(raiz) {
        if (!raiz || !raiz.querySelectorAll) { return; }
        if (raiz.nodeType === 1) { recolher(raiz); }
        var todos = raiz.querySelectorAll('*');
        for (var i = 0; i < todos.length; i++) { recolher(todos[i]); }
    }

    function tratar(evento) {
        var tipo = evento.type;
        var el = evento.target;
        if (el && el.nodeType !== 1) { el = el.parentElement; }
        while (el && el !== document) {
            var mapa = codigo.get(el);
            if (mapa && mapa[tipo] !== undefined) {
                try {
                    var r = correr(mapa[tipo], el, evento);
                    if (r === false) { evento.preventDefault(); }
                } catch (e) {
                    console.error('[nv_acoes] ' + tipo + ': ' + mapa[tipo], e);
                }
                if (tipo === 'click' && el.getAttribute('data-nv-js') === '1') { evento.preventDefault(); }
                if (evento.cancelBubble) { break; }
            } else if (tipo === 'click' && el.getAttribute && el.getAttribute('data-nv-js') === '1') {
                evento.preventDefault();
            }
            if (SEM_BOLHA[tipo]) { break; }
            el = el.parentElement;
        }
    }

    EVENTOS.forEach(function (tipo) {
        // Eventos que borbulham: depois dos ouvintes normais (como um onclick no proprio elemento).
        // Os que nao borbulham (error, load, focus…): fase de captura.
        document.addEventListener(tipo, tratar, !!SEM_BOLHA[tipo]);
    });

    new MutationObserver(function (lista) {
        for (var i = 0; i < lista.length; i++) {
            var m = lista[i];
            if (m.type === 'attributes') { recolher(m.target); }
            else { for (var j = 0; j < m.addedNodes.length; j++) { recolherArvore(m.addedNodes[j]); } }
        }
    }).observe(document.documentElement, { childList: true, subtree: true, attributes: true,
        attributeFilter: EVENTOS.map(function (e) { return 'on' + e; }).concat(['href']) });
    recolherArvore(document.documentElement);

    window.NvAcoes = { correr: correr, analisar: analisar };
})();
