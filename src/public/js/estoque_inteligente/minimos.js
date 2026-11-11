// Estoque Inteligente - aplicar minimos sugeridos (pre-visualizar, escolher, desfazer).
// Depende de comum.js (CSRF, H, fetchJson, onEl, carregarBase) e de js/nivora_dialogo.js.

// Aplicar minimos sugeridos: mostra o que muda, deixa escolher, e permite desfazer.
function fmtQtdMin(v, unidade) {
    const n = Number(v || 0).toLocaleString('pt-PT', { maximumFractionDigits: 3 });
    return unidade ? n + ' ' + unidade : n;
}

function tabelaMinimosSugeridos(itens) {
    const wrap = document.createElement('div');
    const topo = document.createElement('label');
    topo.className = 'd-flex align-items-center gap-2 mb-2 small fw-semibold';
    const todos = document.createElement('input');
    todos.type = 'checkbox';
    todos.checked = true;
    topo.append(todos, document.createTextNode('Seleccionar todos'));
    wrap.appendChild(topo);

    const tabela = document.createElement('table');
    tabela.className = 'table table-sm align-middle mb-0';
    tabela.innerHTML = '<thead><tr><th></th><th>Insumo</th><th class="text-end">Mínimo actual</th><th class="text-end">Sugerido</th><th>Porquê</th></tr></thead>';
    const tbody = document.createElement('tbody');
    const caixas = [];
    itens.forEach((it) => {
        const tr = document.createElement('tr');
        const c = document.createElement('input');
        c.type = 'checkbox';
        c.checked = true;
        c.value = String(it.insumo_id);
        c.setAttribute('aria-label', 'Aplicar a ' + it.nome);
        caixas.push(c);
        const td0 = document.createElement('td'); td0.appendChild(c);
        const td1 = document.createElement('td'); td1.textContent = it.nome;
        const td2 = document.createElement('td'); td2.className = 'text-end text-muted'; td2.textContent = fmtQtdMin(it.minimo_anterior, it.unidade);
        const td3 = document.createElement('td'); td3.className = 'text-end fw-semibold';
        td3.textContent = fmtQtdMin(it.minimo_novo, it.unidade);
        const sobe = document.createElement('small');
        sobe.className = 'text-success ms-1';
        sobe.textContent = '+' + fmtQtdMin(it.minimo_novo - it.minimo_anterior, '');
        td3.appendChild(sobe);
        const td4 = document.createElement('td'); td4.className = 'small text-muted';
        td4.textContent = 'consumo médio ~' + fmtQtdMin(Math.round(it.media_diaria), it.unidade) + '/dia × ' + Number(it.safety_dias || 0) + ' dias de segurança';
        tr.append(td0, td1, td2, td3, td4);
        tbody.appendChild(tr);
    });
    tabela.appendChild(tbody);
    const rolar = document.createElement('div');
    rolar.style.maxHeight = '46vh';
    rolar.style.overflow = 'auto';
    rolar.appendChild(tabela);
    wrap.appendChild(rolar);
    return { wrap, todos, caixas };
}

async function desfazerMinimos(itens) {
    try {
        const fd = new FormData();
        fd.append('_csrf', CSRF);
        fd.append('modo', 'desfazer');
        fd.append('itens', JSON.stringify(itens.map((i) => ({ insumo_id: i.insumo_id, minimo_anterior: i.minimo_anterior, minimo_novo: i.minimo_novo }))));
        const r = await fetchJson('api/estoque_aplicar_minimos.php', { method: 'POST', body: fd, headers: H });
        nivoraAviso(r.message || 'Alteração desfeita.', { tipo: 'sucesso' });
        await carregarBase();
    } catch (e) {
        nivoraAviso(e.message || 'Não foi possível desfazer.', { tipo: 'erro' });
    }
}

onEl('btnAplicarMinimos', 'click', async () => {
    if (!ESTOQUE_EMP_COCKPIT) return;
    const btn = document.getElementById('btnAplicarMinimos');
    if (btn) { btn.disabled = true; }
    try {
        const fdPrev = new FormData();
        fdPrev.append('_csrf', CSRF);
        fdPrev.append('modo', 'previsualizar');
        const prev = await fetchJson('api/estoque_aplicar_minimos.php', { method: 'POST', body: fdPrev, headers: H });
        const itens = (prev.data && prev.data.itens) || [];
        if (!itens.length) {
            await nivoraConfirmar({
                titulo: 'Os mínimos já estão em dia',
                mensagem: 'Nenhum insumo precisa de um mínimo maior com base no consumo dos últimos 28 dias.',
                confirmar: 'OK',
                somenteOk: true,
            });
            return;
        }

        const t = tabelaMinimosSugeridos(itens);
        const ok = await nivoraConfirmar({
            titulo: 'Aplicar mínimos sugeridos',
            mensagem: 'O motor calculou mínimos novos com base no consumo real. Escolha os que quer aplicar.',
            conteudo: t.wrap,
            larga: true,
            confirmar: 'Aplicar ' + itens.length + ' alteração(ões)',
            aoAbrir: ({ botaoConfirmar }) => {
                const contar = () => {
                    const n = t.caixas.filter((c) => c.checked).length;
                    botaoConfirmar.textContent = n ? ('Aplicar ' + n + (n === 1 ? ' alteração' : ' alterações')) : 'Escolha pelo menos um';
                    botaoConfirmar.disabled = n === 0;
                    t.todos.checked = n === t.caixas.length;
                    t.todos.indeterminate = n > 0 && n < t.caixas.length;
                };
                t.todos.addEventListener('change', () => { t.caixas.forEach((c) => { c.checked = t.todos.checked; }); contar(); });
                t.caixas.forEach((c) => c.addEventListener('change', contar));
                contar();
            },
        });
        if (!ok) return;

        const ids = t.caixas.filter((c) => c.checked).map((c) => Number(c.value));
        const fd = new FormData();
        fd.append('_csrf', CSRF);
        fd.append('modo', 'aplicar');
        fd.append('insumo_ids', JSON.stringify(ids));
        const r = await fetchJson('api/estoque_aplicar_minimos.php', { method: 'POST', body: fd, headers: H });
        const aplicados = (r.data && r.data.itens) || [];
        await carregarBase();
        nivoraAviso(r.message || 'Mínimos actualizados.', aplicados.length
            ? { tipo: 'sucesso', acao: { texto: 'Desfazer', fn: () => desfazerMinimos(aplicados) } }
            : { tipo: 'sucesso' });
    } catch (e) {
        nivoraAviso(e.message || 'Não foi possível aplicar os mínimos.', { tipo: 'erro' });
    } finally {
        if (btn) { btn.disabled = false; }
    }
});
