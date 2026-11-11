/* Ingredientes (plano Basico). Usa js/nivora_dialogo.js para os formularios e avisos. */
(function () {
    'use strict';
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var estado = { ingredientes: [], limite: -1, pratos: [] };
    var UNIDADES = { g: ['kg', 'g'], ml: ['L', 'ml'], un: ['un'] };
    var NOMES_UN = { kg: 'kg', g: 'g', L: 'litros', ml: 'ml', un: 'unidades' };

    function api(dados) {
        return fetch('api/ingredientes.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(dados)
        }).then(function (r) {
            return r.json().catch(function () { return { success: false, message: 'Erro inesperado (' + r.status + ').' }; });
        }).catch(function () {
            return { success: false, message: 'Sem ligação. Tente novamente.' };
        });
    }

    function mostrarCarregando(container, mensagem) {
        if (!container) { return; }
        container.setAttribute('aria-busy', 'true');
        container.textContent = '';
        var estadoCarga = el('p', { class: 'ing__carregando', role: 'status' });
        estadoCarga.append(el('span', { class: 'ing__spinner', 'aria-hidden': 'true' }), document.createTextNode(mensagem));
        container.appendChild(estadoCarga);
    }

    function mostrarErro(container, titulo, mensagem, tentar) {
        if (!container) { return; }
        container.setAttribute('aria-busy', 'false');
        container.textContent = '';
        var erro = el('div', { class: 'ing__erro', role: 'alert' });
        var texto = el('div');
        texto.append(el('strong', {}, titulo), el('p', {}, mensagem || 'Tente novamente em alguns instantes.'));
        var botao = el('button', { type: 'button', class: 'ing__btn' }, 'Tentar novamente');
        botao.addEventListener('click', tentar);
        erro.append(texto, botao);
        container.appendChild(erro);
    }

    function el(tag, attrs, texto) {
        var e = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'class') { e.className = attrs[k]; } else { e.setAttribute(k, attrs[k]); }
        });
        if (texto != null) { e.textContent = texto; }
        return e;
    }

    function avisar(d, okMsg) {
        nivoraAviso(d.message || okMsg, { tipo: d.success ? 'sucesso' : 'erro' });
    }

    function campo(rotulo, input) {
        var w = el('div', { class: 'ing-campo' });
        var l = el('label', {}, rotulo);
        input.id = input.id || ('f' + Math.random().toString(36).slice(2));
        l.setAttribute('for', input.id);
        w.append(l, input);
        return w;
    }

    function inputNumero(valor) {
        return el('input', { type: 'text', inputmode: 'decimal', value: valor == null ? '' : String(valor), autocomplete: 'off', placeholder: '0' });
    }

    function seletorUnidade(base, escolhida) {
        var s = el('select');
        (UNIDADES[base] || ['un']).forEach(function (u) {
            var o = el('option', { value: u }, NOMES_UN[u]);
            if (u === escolhida) { o.selected = true; }
            s.appendChild(o);
        });
        return s;
    }

    function par(rotulo, input, sel) {
        var w = el('div', { class: 'ing-campo' });
        var l = el('label', {}, rotulo);
        input.id = 'f' + Math.random().toString(36).slice(2);
        l.setAttribute('for', input.id);
        sel.setAttribute('aria-label', 'Unidade de ' + rotulo.toLowerCase());
        var p = el('div', { class: 'ing-par' });
        p.append(input, sel);
        w.append(l, p);
        return w;
    }

    // ---------- Lista ----------
    var filtro = { texto: '', estado: 'todos' };
    var ICONE_MEDIDA = { g: 'fa-weight-hanging', ml: 'fa-droplet', un: 'fa-hashtag' };
    var ROTULO = { ok: 'OK', a_acabar: 'A acabar', acabou: 'Acabou' };

    function cartaoResumo(valor, rotulo, tipo, estadoFiltro) {
        var nomeFiltro = estadoFiltro === 'todos' ? 'Mostrar todos os ingredientes' : 'Filtrar ingredientes: ' + rotulo;
        var c = el('button', { type: 'button', class: 'ing-kpi ing-kpi--' + tipo + (filtro.estado === estadoFiltro ? ' is-ativo' : ''), 'aria-label': nomeFiltro, 'aria-pressed': filtro.estado === estadoFiltro ? 'true' : 'false' });
        c.appendChild(el('strong', {}, String(valor)));
        c.appendChild(el('span', {}, rotulo));
        c.addEventListener('click', function () {
            filtro.estado = filtro.estado === estadoFiltro ? 'todos' : estadoFiltro;
            desenharLista();
            var ativo = document.querySelector('#ingResumo .ing-kpi.is-ativo');
            if (ativo) { ativo.focus(); }
        });
        return c;
    }

    function nivel(i) {
        // Barra: cheia = 3x o minimo (ou cheia se nao ha minimo e ainda ha stock).
        if (i.saldo_base <= 0) { return 0; }
        if (!(i.minimo_base > 0)) { return 100; }
        return Math.max(4, Math.min(100, Math.round(i.saldo_base / (i.minimo_base * 3) * 100)));
    }

    function desenharLista() {
        var lista = document.getElementById('ingLista');
        var resumo = document.getElementById('ingResumo');
        var cont = document.getElementById('ingContagem');
        var status = document.getElementById('ingStatus');
        var ing = estado.ingredientes;
        lista.setAttribute('aria-busy', 'false');
        cont.textContent = ing.length + (estado.limite >= 0 ? ' de ' + estado.limite : '');
        lista.textContent = '';
        resumo.textContent = '';

        if (!ing.length) {
            if (status) { status.textContent = 'Ainda não há ingredientes cadastrados.'; }
            var v = el('div', { class: 'ing__vazio' });
            v.appendChild(el('div', { class: 'ing__vazio-icone', 'aria-hidden': 'true' })).innerHTML = '<i class="fas fa-carrot"></i>';
            v.appendChild(el('h2', {}, 'Comece pelos ingredientes que mais usa'));
            v.appendChild(el('p', {}, 'Arroz, carne, óleo, pão… Depois diga o que leva cada prato e o sistema desconta ao vender.'));
            var b = el('button', { type: 'button', class: 'ing__btn ing__btn--principal' }, 'Adicionar o primeiro');
            b.addEventListener('click', novoIngrediente);
            v.appendChild(b);
            lista.appendChild(v);
            return;
        }

        var nAcabou = ing.filter(function (i) { return i.estado === 'acabou'; }).length;
        var nAcabar = ing.filter(function (i) { return i.estado === 'a_acabar'; }).length;
        var nSemReceita = ing.filter(function (i) { return !i.usado_em; }).length;
        var kpis = el('div', { class: 'ing-kpis' });
        kpis.append(
            cartaoResumo(ing.length, 'ingredientes', 'total', 'todos'),
            cartaoResumo(nAcabar, 'a acabar', 'aviso', 'a_acabar'),
            cartaoResumo(nAcabou, 'acabaram', 'perigo', 'acabou'),
            cartaoResumo(nSemReceita, 'sem receita', 'neutro', 'sem_receita')
        );
        var busca = el('input', { type: 'search', class: 'ing-busca', placeholder: 'Procurar ingrediente…', 'aria-label': 'Procurar ingrediente', value: filtro.texto });
        busca.addEventListener('input', function () { filtro.texto = busca.value; pintarLinhas(); });
        resumo.append(kpis, busca);

        var linhasBox = el('div', { class: 'ing__lista' });
        lista.appendChild(linhasBox);

        function pintarLinhas() {
            linhasBox.textContent = '';
            var t = filtro.texto.trim().toLowerCase();
            var visiveis = ing.filter(function (i) {
                if (t && i.nome.toLowerCase().indexOf(t) === -1) { return false; }
                if (filtro.estado === 'sem_receita') { return !i.usado_em; }
                return filtro.estado === 'todos' || i.estado === filtro.estado;
            });
            if (!visiveis.length) {
                var vazio = el('div', { class: 'ing__vazio ing__vazio--compacto' });
                vazio.appendChild(el('h2', {}, filtro.texto.trim() ? 'Nenhum ingrediente encontrado' : 'Nenhum ingrediente neste estado'));
                vazio.appendChild(el('p', {}, 'Ajuste a pesquisa ou limpe os filtros para voltar a ver a lista.'));
                var limpar = el('button', { type: 'button', class: 'ing__btn' }, 'Limpar pesquisa e filtros');
                limpar.addEventListener('click', function () {
                    filtro.texto = '';
                    filtro.estado = 'todos';
                    desenharLista();
                    var buscaAtual = document.querySelector('#ingResumo .ing-busca');
                    if (buscaAtual) { buscaAtual.focus(); }
                });
                vazio.appendChild(limpar);
                linhasBox.appendChild(vazio);
                if (status) { status.textContent = 'Nenhum ingrediente corresponde à pesquisa ou ao filtro.'; }
                return;
            }
            visiveis.forEach(function (i) { linhasBox.appendChild(linha(i)); });
            if (status) {
                status.textContent = visiveis.length + (visiveis.length === 1 ? ' ingrediente visível.' : ' ingredientes visíveis.');
            }
        }
        pintarLinhas();
    }

    function linha(i) {
        var l = el('article', { class: 'ing__linha ing__linha--' + i.estado });

        var icone = el('div', { class: 'ing__icone', 'aria-hidden': 'true' });
        icone.innerHTML = '<i class="fas ' + (ICONE_MEDIDA[i.unidade_base] || 'fa-box') + '"></i>';

        var info = el('div', { class: 'ing__info' });
        var topo = el('div', { class: 'ing__titulo' });
        topo.append(el('span', { class: 'ing__nome' }, i.nome), el('span', { class: 'ing__estado ing__estado--' + i.estado }, ROTULO[i.estado]));
        info.appendChild(topo);
        var barra = el('div', { class: 'ing__barra', role: 'img', 'aria-label': 'Nível: ' + i.saldo_txt });
        var fill = el('span');
        fill.style.width = nivel(i) + '%';
        barra.appendChild(fill);
        info.appendChild(barra);
        var sub = [];
        if (i.previsao && i.previsao.texto) { sub.push(i.previsao.texto); }
        if (i.minimo_txt) { sub.push('avisa abaixo de ' + i.minimo_txt); }
        sub.push(i.usado_em ? 'em ' + i.usado_em + (i.usado_em === 1 ? ' prato' : ' pratos') : 'sem receita');
        info.appendChild(el('div', { class: 'ing__sub' }, sub.join(' · ')));

        var qtd = el('div', { class: 'ing__qtd' });
        qtd.appendChild(el('strong', {}, i.saldo_txt));
        qtd.appendChild(el('span', {}, 'em stock'));

        var acoes = el('div', { class: 'ing__acoes' });
        var bEnt = el('button', { type: 'button', class: 'ing__btn ing__btn--entrada', title: 'Comprei mais' });
        bEnt.innerHTML = '<i class="fas fa-plus" aria-hidden="true"></i> Entrada';
        bEnt.addEventListener('click', function () { entrada(i); });
        var bCont = el('button', { type: 'button', class: 'ing__btn', title: 'Contei e tenho…' });
        bCont.innerHTML = '<i class="fas fa-clipboard-check" aria-hidden="true"></i> Contar';
        bCont.addEventListener('click', function () { contar(i); });
        var bArq = el('button', { type: 'button', class: 'ing__btn ing__btn--fantasma', title: 'Arquivar ' + i.nome, 'aria-label': 'Arquivar ' + i.nome });
        bArq.innerHTML = '<i class="fas fa-box-archive" aria-hidden="true"></i>';
        bArq.addEventListener('click', function () { arquivar(i); });
        acoes.append(bEnt, bCont, bArq);

        l.append(icone, info, qtd, acoes);
        return l;
    }

    function carregar() {
        var lista = document.getElementById('ingLista');
        var status = document.getElementById('ingStatus');
        if (status) { status.textContent = ''; }
        mostrarCarregando(lista, 'A carregar ingredientes…');
        return api({ acao: 'listar' }).then(function (d) {
            if (!d.success) {
                mostrarErro(lista, 'Não foi possível carregar os ingredientes.', d.message, carregar);
                return;
            }
            estado.ingredientes = d.ingredientes || [];
            estado.limite = d.limite;
            desenharLista();
        });
    }

    function aplicarLista(d) {
        if (d.ingredientes) {
            estado.ingredientes = d.ingredientes;
            estado.limite = d.limite;
            desenharLista();
        }
    }

    // Sugestoes rapidas: nome + como se mede (o dono so clica).
    var SUGESTOES = [
        ['Arroz', 'kg'], ['Farinha', 'kg'], ['Açúcar', 'kg'], ['Carne', 'kg'], ['Frango', 'kg'], ['Batata', 'kg'],
        ['Óleo', 'L'], ['Leite', 'L'], ['Pão', 'un'], ['Ovos', 'un'], ['Queijo (fatias)', 'un'], ['Refrigerante', 'un']
    ];
    var MEDIDAS = [
        { valor: 'kg', icone: 'fa-weight-hanging', titulo: 'Peso', dica: 'kg / g' },
        { valor: 'L', icone: 'fa-droplet', titulo: 'Líquido', dica: 'litros / ml' },
        { valor: 'un', icone: 'fa-hashtag', titulo: 'Unidades', dica: 'pão, ovos, latas' }
    ];
    var SUFIXO = { kg: 'kg', L: 'L', un: 'un' };

    function campoComSufixo(rotulo, ajuda) {
        var w = el('div', { class: 'ing-campo' });
        var id = 'f' + Math.random().toString(36).slice(2);
        var idAjuda = 'h' + Math.random().toString(36).slice(2);
        var l = el('label', { for: id }, rotulo);
        var caixa = el('div', { class: 'ing-sufixo' });
        var input = el('input', { id: id, type: 'text', inputmode: 'decimal', autocomplete: 'off', placeholder: '0' });
        var suf = el('span', { 'aria-hidden': 'true' }, 'kg');
        caixa.append(input, suf);
        w.append(l, caixa);
        if (ajuda) {
            var textoAjuda = el('small', { id: idAjuda }, ajuda);
            input.setAttribute('aria-describedby', idAjuda);
            w.appendChild(textoAjuda);
        }
        return { w: w, input: input, suf: suf };
    }

    async function novoIngrediente() {
        var restam = estado.limite >= 0 ? estado.limite - estado.ingredientes.length : -1;
        if (restam === 0) {
            await nivoraConfirmar({
                titulo: 'Limite de ingredientes atingido',
                mensagem: 'O seu plano permite até ' + estado.limite + ' ingredientes ativos. Arquive um que já não usa ou consulte as opções do plano Profissional.',
                confirmar: 'Entendi',
                somenteOk: true
            });
            return;
        }
        var form = el('div', { class: 'ing-form ing-form--novo' });
        var medida = 'kg';

        // 1) Nome + sugestoes
        var idNome = 'f' + Math.random().toString(36).slice(2);
        var nome = el('input', { id: idNome, type: 'text', maxlength: '140', placeholder: 'Escreva ou escolha abaixo', autocomplete: 'off', required: 'required' });
        var blocoNome = el('div', { class: 'ing-campo' });
        blocoNome.append(el('label', { for: idNome }, 'Nome do ingrediente'), nome);
        var chips = el('div', { class: 'ing-chips', role: 'group', 'aria-label': 'Sugestões' });
        SUGESTOES.forEach(function (sug) {
            if (estado.ingredientes.some(function (i) { return i.nome.toLowerCase() === sug[0].toLowerCase(); })) { return; }
            var c = el('button', { type: 'button', class: 'ing-chip' }, sug[0]);
            c.addEventListener('click', function () { nome.value = sug[0]; escolherMedida(sug[1]); atualizar(); qtd.input.focus(); });
            chips.appendChild(c);
        });
        if (chips.children.length) {
            blocoNome.append(el('small', { class: 'ing-campo__dica' }, 'Sugestões rápidas'), chips);
        }

        // 2) Como se mede: tres cartoes
        var blocoMedida = el('div', { class: 'ing-campo' });
        blocoMedida.appendChild(el('span', { class: 'ing-rotulo' }, 'Como se mede?'));
        var cartoes = el('div', { class: 'ing-medidas', role: 'radiogroup', 'aria-label': 'Como se mede?' });
        var botoesMedida = {};
        MEDIDAS.forEach(function (m) {
            var b = el('button', { type: 'button', class: 'ing-medida', role: 'radio', 'aria-checked': 'false', tabindex: '-1' });
            b.innerHTML = '<i class="fas ' + m.icone + '" aria-hidden="true"></i><strong></strong><small></small>';
            b.querySelector('strong').textContent = m.titulo;
            b.querySelector('small').textContent = m.dica;
            b.addEventListener('click', function () { escolherMedida(m.valor); atualizar(); });
            b.addEventListener('keydown', function (evento) {
                var indice = MEDIDAS.findIndex(function (opcao) { return opcao.valor === m.valor; });
                if (evento.key === 'ArrowRight' || evento.key === 'ArrowDown') { indice = (indice + 1) % MEDIDAS.length; }
                else if (evento.key === 'ArrowLeft' || evento.key === 'ArrowUp') { indice = (indice + MEDIDAS.length - 1) % MEDIDAS.length; }
                else { return; }
                evento.preventDefault();
                escolherMedida(MEDIDAS[indice].valor);
                atualizar();
                botoesMedida[MEDIDAS[indice].valor].focus();
            });
            botoesMedida[m.valor] = b;
            cartoes.appendChild(b);
        });
        blocoMedida.appendChild(cartoes);

        // 3) Quantidades lado a lado, com a unidade dentro do campo
        var qtd = campoComSufixo('Quanto tem agora?', 'Pode deixar 0 e dar entrada depois.');
        var min = campoComSufixo('Avisar quando ficar abaixo de', 'Ex.: o que gasta em 2 dias. Opcional.');
        var linhaQtd = el('div', { class: 'ing-duas' });
        linhaQtd.append(qtd.w, min.w);

        // 4) Pre-visualizacao do que vai ficar
        var previa = el('div', { class: 'ing-previa', 'aria-live': 'polite' });

        function escolherMedida(v) {
            medida = v;
            Object.keys(botoesMedida).forEach(function (k) {
                botoesMedida[k].setAttribute('aria-checked', k === v ? 'true' : 'false');
                botoesMedida[k].setAttribute('tabindex', k === v ? '0' : '-1');
                botoesMedida[k].classList.toggle('is-ativa', k === v);
            });
            qtd.suf.textContent = SUFIXO[v];
            min.suf.textContent = SUFIXO[v];
        }
        var botaoOk = null;
        function atualizar() {
            var n = nome.value.trim();
            var q = qtd.input.value.trim() || '0';
            var m = min.input.value.trim();
            previa.textContent = '';
            if (!n) {
                previa.appendChild(el('span', { class: 'ing-previa__vazia' }, 'Escreva o nome para continuar.'));
            } else {
                previa.appendChild(el('strong', {}, n));
                previa.appendChild(document.createTextNode(' — começa com ' + q + ' ' + SUFIXO[medida] + (m ? ' · avisa abaixo de ' + m + ' ' + SUFIXO[medida] : '')));
            }
            if (botaoOk) { botaoOk.disabled = !n; }
        }
        [nome, qtd.input, min.input].forEach(function (i) { i.addEventListener('input', atualizar); });
        escolherMedida('kg');

        form.append(blocoNome, blocoMedida, linhaQtd, previa);
        var mensagemLimite = restam >= 0
            ? (restam === 1 ? 'Resta 1 ingrediente de ' : 'Restam ' + restam + ' ingredientes de ') + estado.limite + ' no seu plano.'
            : '';
        var ok = await nivoraConfirmar({
            titulo: 'Novo ingrediente',
            mensagem: mensagemLimite,
            icone: 'fas fa-carrot',
            conteudo: form,
            larga: true,
            confirmar: 'Adicionar ingrediente',
            aoAbrir: function (d) { botaoOk = d.botaoConfirmar; atualizar(); setTimeout(function () { nome.focus(); }, 0); }
        });
        if (!ok) { return; }
        var d = await api({ acao: 'criar', nome: nome.value, unidade: medida, quantidade: qtd.input.value, minimo: min.input.value });
        avisar(d, 'Ingrediente adicionado.');
        aplicarLista(d);
    }

    async function entrada(i) {
        var form = el('div', { class: 'ing-form' });
        var q = inputNumero('');
        var u = seletorUnidade(i.unidade_base, i.unidade_base === 'g' ? 'kg' : (i.unidade_base === 'ml' ? 'L' : 'un'));
        form.append(
            resumoStock('Stock atual', i.saldo_txt, 'A quantidade indicada será somada ao saldo.'),
            par('Quanto entrou?', q, u)
        );
        var ok = await nivoraConfirmar({
            titulo: 'Entrada de ' + i.nome,
            conteudo: form,
            confirmar: 'Somar',
            aoAbrir: function () { setTimeout(function () { q.focus(); }, 0); }
        });
        if (!ok) { return; }
        var d = await api({ acao: 'entrada', id: i.id, quantidade: q.value, unidade: u.value });
        avisar(d, 'Entrada registada.');
        aplicarLista(d);
    }

    function paraUnidade(base, unidadeBase, unidade) {
        if ((unidade === 'kg' && unidadeBase === 'g') || (unidade === 'L' && unidadeBase === 'ml')) { return base / 1000; }
        return base;
    }

    function resumoStock(rotulo, valor, detalhe) {
        var resumo = el('div', { class: 'ing-form__contexto' });
        resumo.append(el('span', {}, rotulo), el('strong', {}, valor), el('small', {}, detalhe));
        return resumo;
    }

    async function contar(i) {
        var form = el('div', { class: 'ing-form' });
        var un = i.unidade_base === 'g' ? 'kg' : (i.unidade_base === 'ml' ? 'L' : 'un');
        var fmt = function (v) { return String(Math.round(v * 1000) / 1000).replace('.', ','); };
        var q = inputNumero(fmt(paraUnidade(Math.max(0, i.saldo_base), i.unidade_base, un)));
        var u = seletorUnidade(i.unidade_base, un);
        var m = inputNumero(i.minimo_base > 0 ? fmt(paraUnidade(i.minimo_base, i.unidade_base, un)) : '');
        form.append(
            resumoStock('Stock registado', i.saldo_txt, 'A contagem indicada substituirá o saldo actual.'),
            par('Quanto tem realmente?', q, u),
            campo('Avisar quando ficar abaixo de', m)
        );
        var ok = await nivoraConfirmar({
            titulo: 'Contar ' + i.nome,
            conteudo: form,
            confirmar: 'Guardar',
            aoAbrir: function () { setTimeout(function () { q.select(); }, 0); }
        });
        if (!ok) { return; }
        var d = await api({ acao: 'acertar', id: i.id, quantidade: q.value, unidade: u.value, minimo: m.value });
        avisar(d, 'Quantidade actualizada.');
        aplicarLista(d);
    }

    async function arquivar(i) {
        var ok = await nivoraConfirmar({
            titulo: 'Arquivar ' + i.nome + '?',
            mensagem: 'Deixa de aparecer na lista e sai das receitas' + (i.usado_em ? ' (' + i.usado_em + ' pratos)' : '') + '.',
            confirmar: 'Arquivar',
            perigo: true
        });
        if (!ok) { return; }
        var d = await api({ acao: 'arquivar', id: i.id });
        avisar(d, 'Ingrediente arquivado.');
        aplicarLista(d);
    }

    // ---------- Receitas ----------
    var filtroPratos = { texto: '', so: 'todos' };

    function desenharPratos() {
        var box = document.getElementById('ingPratos');
        var status = document.getElementById('ingStatusReceitas');
        box.setAttribute('aria-busy', 'false');
        box.textContent = '';
        if (!estado.pratos.length) {
            if (status) { status.textContent = 'Ainda não há pratos cadastrados.'; }
            var v = el('div', { class: 'ing__vazio' });
            v.appendChild(el('h2', {}, 'Ainda não há pratos'));
            v.appendChild(el('p', {}, 'Crie os pratos em Produtos e volte aqui para dizer o que leva cada um.'));
            var a = el('a', { href: 'produtos.php', class: 'ing__btn ing__btn--principal' }, 'Ir para Produtos');
            v.appendChild(a);
            box.appendChild(v);
            return;
        }
        var com = estado.pratos.filter(function (p) { return p.n_ingredientes > 0; }).length;
        var sem = estado.pratos.length - com;

        var topo = el('div', { class: 'ing__resumo' });
        var kpis = el('div', { class: 'ing-kpis ing-kpis--3' });
        function kpi(valor, rotulo, tipo, chave) {
            var nomeFiltro = chave === 'todos' ? 'Mostrar todos os pratos' : 'Filtrar pratos: ' + rotulo;
            var c = el('button', { type: 'button', class: 'ing-kpi ing-kpi--' + tipo + (filtroPratos.so === chave ? ' is-ativo' : ''), 'aria-label': nomeFiltro, 'aria-pressed': filtroPratos.so === chave ? 'true' : 'false' });
            c.append(el('strong', {}, String(valor)), el('span', {}, rotulo));
            c.addEventListener('click', function () {
                filtroPratos.so = filtroPratos.so === chave ? 'todos' : chave;
                desenharPratos();
                var ativo = document.querySelector('#ingPratos .ing-kpi.is-ativo');
                if (ativo) { ativo.focus(); }
            });
            return c;
        }
        kpis.append(kpi(estado.pratos.length, 'pratos', 'total', 'todos'), kpi(com, 'com receita', 'ok', 'com'), kpi(sem, 'sem receita', 'aviso', 'sem'));
        var busca = el('input', { type: 'search', class: 'ing-busca', placeholder: 'Procurar prato…', 'aria-label': 'Procurar prato', value: filtroPratos.texto });
        topo.append(kpis, busca);
        box.appendChild(topo);

        var lista = el('div', { class: 'ing__lista' });
        box.appendChild(lista);

        function pintar() {
            lista.textContent = '';
            var t = filtroPratos.texto.trim().toLowerCase();
            var vis = estado.pratos.filter(function (p) {
                if (t && p.nome.toLowerCase().indexOf(t) === -1) { return false; }
                if (filtroPratos.so === 'com') { return p.n_ingredientes > 0; }
                if (filtroPratos.so === 'sem') { return !p.n_ingredientes; }
                return true;
            }).sort(function (a, b) {
                // Sem receita primeiro: e o que falta fazer.
                return (a.n_ingredientes > 0) - (b.n_ingredientes > 0) || a.nome.localeCompare(b.nome, 'pt');
            });
            if (!vis.length) {
                var vazio = el('div', { class: 'ing__vazio ing__vazio--compacto' });
                vazio.appendChild(el('h2', {}, 'Nenhum prato encontrado'));
                vazio.appendChild(el('p', {}, 'Ajuste a pesquisa ou limpe os filtros para voltar a ver a lista.'));
                var limpar = el('button', { type: 'button', class: 'ing__btn' }, 'Limpar pesquisa e filtros');
                limpar.addEventListener('click', function () {
                    filtroPratos.texto = '';
                    filtroPratos.so = 'todos';
                    desenharPratos();
                    var buscaAtual = document.querySelector('#ingPratos .ing-busca');
                    if (buscaAtual) { buscaAtual.focus(); }
                });
                vazio.appendChild(limpar);
                lista.appendChild(vazio);
                if (status) { status.textContent = 'Nenhum prato corresponde à pesquisa ou ao filtro.'; }
                return;
            }
            vis.forEach(function (p) {
                var tem = p.n_ingredientes > 0;
                var l = el('article', { class: 'ing-prato' + (tem ? '' : ' ing-prato--sem') });
                var ic = el('div', { class: 'ing__icone', 'aria-hidden': 'true' });
                ic.innerHTML = '<i class="fas ' + (tem ? 'fa-utensils' : 'fa-circle-exclamation') + '"></i>';
                var info = el('div', { class: 'ing__info' });
                var tit = el('div', { class: 'ing__titulo' });
                tit.append(el('span', { class: 'ing__nome' }, p.nome),
                    el('span', { class: 'ing__estado ' + (tem ? 'ing__estado--ok' : 'ing__estado--a_acabar') }, tem ? p.n_ingredientes + (p.n_ingredientes === 1 ? ' ingrediente' : ' ingredientes') : 'Sem receita'));
                info.appendChild(tit);
                if (tem) {
                    var chips = el('div', { class: 'ing-prato__itens' });
                    (p.resumo || []).forEach(function (r) { chips.appendChild(el('span', { class: 'ing-prato__item' }, r)); });
                    info.appendChild(chips);
                } else {
                    info.appendChild(el('div', { class: 'ing__sub' }, 'Ao vender não desconta ingredientes. Diga o que leva para o sistema controlar.'));
                }
                var b = el('button', { type: 'button', class: 'ing__btn' + (tem ? '' : ' ing__btn--principal') });
                b.innerHTML = tem ? '<i class="fas fa-pen" aria-hidden="true"></i> Editar' : '<i class="fas fa-plus" aria-hidden="true"></i> Criar receita';
                b.addEventListener('click', function () { editarReceita(p); });
                l.append(ic, info, b);
                lista.appendChild(l);
            });
            if (status) { status.textContent = vis.length + (vis.length === 1 ? ' prato visível.' : ' pratos visíveis.'); }
        }
        busca.addEventListener('input', function () { filtroPratos.texto = busca.value; pintar(); });
        pintar();
    }

    function carregarPratos() {
        var box = document.getElementById('ingPratos');
        var status = document.getElementById('ingStatusReceitas');
        if (status) { status.textContent = ''; }
        mostrarCarregando(box, 'A carregar pratos…');
        return api({ acao: 'pratos' }).then(function (d) {
            if (!d.success) {
                mostrarErro(box, 'Não foi possível carregar os pratos.', d.message, carregarPratos);
                return;
            }
            estado.pratos = d.pratos || [];
            desenharPratos();
        });
    }

    async function editarReceita(prato) {
        if (!estado.ingredientes.length) {
            await nivoraConfirmar({ titulo: 'Primeiro, os ingredientes', mensagem: 'Adicione os ingredientes na aba Ingredientes e volte aqui.', confirmar: 'OK', somenteOk: true });
            return;
        }
        var atual = await api({ acao: 'receita', produto_id: prato.id });
        var itens = (atual && atual.itens) || [];
        var box = el('div', { class: 'ing-receita' });
        var linhas = el('div', { class: 'ing-receita' });

        function campoReceita(rotulo, controle, modificador) {
            var id = 'f' + Math.random().toString(36).slice(2);
            controle.id = id;
            var campo = el('div', { class: 'ing-receita__campo' + (modificador ? ' ing-receita__campo--' + modificador : '') });
            campo.append(el('label', { for: id }, rotulo), controle);
            return campo;
        }

        function novaLinha(item) {
            var l = el('div', { class: 'ing-receita__linha' });
            var s = el('select', { 'aria-label': 'Ingrediente' });
            s.appendChild(el('option', { value: '' }, 'Escolha…'));
            estado.ingredientes.forEach(function (i) {
                var o = el('option', { value: String(i.id) }, i.nome);
                o.dataset.base = i.unidade_base;
                if (item && item.insumo_id === i.id) { o.selected = true; }
                s.appendChild(o);
            });
            var q = inputNumero(item ? String(item.quantidade).replace('.', ',') : '');
            q.setAttribute('placeholder', '0');
            var uWrap = el('div', { class: 'ing-receita__campo ing-receita__campo--unidade' });
            var idUnidade = 'f' + Math.random().toString(36).slice(2);
            var rotuloUnidade = el('label', { for: idUnidade }, 'Unidade');
            var tirar = el('button', { type: 'button', class: 'ing-receita__remover', title: 'Remover linha da receita' });
            tirar.innerHTML = '<i class="fas fa-trash-can" aria-hidden="true"></i><span class="visually-hidden">Remover linha da receita</span>';
            function unidades() {
                var o = s.options[s.selectedIndex];
                var base = (o && o.dataset.base) || 'un';
                uWrap.textContent = '';
                var u = seletorUnidade(base, item && item.unidade_base === base ? item.unidade : (base === 'un' ? 'un' : base));
                u.id = idUnidade;
                u.setAttribute('aria-label', 'Unidade');
                uWrap.append(rotuloUnidade, u);
            }
            s.addEventListener('change', function () {
                item = null;
                unidades();
                tirar.setAttribute('aria-label', s.value ? 'Remover ' + s.options[s.selectedIndex].text + ' da receita' : 'Remover linha da receita');
                tirar.querySelector('span').textContent = tirar.getAttribute('aria-label');
            });
            unidades();
            s.setAttribute('aria-label', 'Ingrediente');
            q.setAttribute('aria-label', 'Quantidade para um prato');
            tirar.setAttribute('aria-label', item ? 'Remover ' + s.options[s.selectedIndex].text + ' da receita' : 'Remover linha da receita');
            tirar.querySelector('span').textContent = tirar.getAttribute('aria-label');
            tirar.addEventListener('click', function () { l.remove(); });
            l.append(campoReceita('Ingrediente', s, 'ingrediente'), campoReceita('Quantidade', q, 'quantidade'), uWrap, tirar);
            linhas.appendChild(l);
        }

        (itens.length ? itens : [null]).forEach(novaLinha);
        var mais = el('button', { type: 'button', class: 'ing__btn ing-receita__mais' });
        mais.innerHTML = '<i class="fas fa-plus" aria-hidden="true"></i> Mais um ingrediente';
        mais.addEventListener('click', function () { novaLinha(null); });
        box.append(el('p', { class: 'ing-receita__ajuda' }, 'Indique a quantidade usada em 1 prato. Ex.: 150 g de carne ou 1 pão.'), linhas, mais);

        var ok = await nivoraConfirmar({
            titulo: 'Receita: ' + prato.nome,
            conteudo: box,
            confirmar: 'Guardar receita',
            larga: true,
            aoAbrir: function () {
                setTimeout(function () {
                    var primeiro = linhas.querySelector('select');
                    if (primeiro) { primeiro.focus(); }
                }, 0);
            }
        });
        if (!ok) { return; }
        var dados = Array.prototype.map.call(linhas.querySelectorAll('.ing-receita__linha'), function (l) {
            var sel = l.querySelectorAll('select');
            return { insumo_id: Number(sel[0].value || 0), quantidade: l.querySelector('input').value, unidade: sel[1] ? sel[1].value : 'un' };
        }).filter(function (x) { return x.insumo_id > 0; });
        var d = await api({ acao: 'receita_salvar', produto_id: prato.id, itens: dados });
        avisar(d, 'Receita guardada.');
        if (d.success) {
            estado.pratos = d.pratos || estado.pratos;
            desenharPratos();
            carregar();
        }
    }

    // ---------- Abas ----------
    var abas = Array.prototype.slice.call(document.querySelectorAll('.ing__aba'));
    function ativarAba(b, focar) {
        var receitas = b.dataset.aba === 'receitas';
        abas.forEach(function (x) {
            var ativa = x === b;
            x.classList.toggle('is-ativa', ativa);
            x.setAttribute('aria-selected', ativa ? 'true' : 'false');
            x.setAttribute('tabindex', ativa ? '0' : '-1');
        });
        document.getElementById('abaLista').hidden = receitas;
        document.getElementById('abaReceitas').hidden = !receitas;
        if (focar) { b.focus(); }
        if (receitas) { carregarPratos(); }
    }
    abas.forEach(function (b) {
        b.addEventListener('click', function () { ativarAba(b, false); });
        b.addEventListener('keydown', function (evento) {
            var indice = abas.indexOf(b);
            if (evento.key === 'ArrowRight') { indice = (indice + 1) % abas.length; }
            else if (evento.key === 'ArrowLeft') { indice = (indice + abas.length - 1) % abas.length; }
            else if (evento.key === 'Home') { indice = 0; }
            else if (evento.key === 'End') { indice = abas.length - 1; }
            else { return; }
            evento.preventDefault();
            ativarAba(abas[indice], true);
        });
    });
    document.getElementById('ingNovo').addEventListener('click', novoIngrediente);
    carregar();
})();
