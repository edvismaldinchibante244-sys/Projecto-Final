// Estoque inteligente - Fichas tecnicas, custos, assistente de novo insumo, edicao e exclusao.
// Parte de estoque_inteligente.php; carregado por ordem com os outros ficheiros de js/estoque_inteligente/.

function qtdParaBase(quantidade, unidade) {
    const q = Number(quantidade || 0);
    const u = String(unidade || 'un').toLowerCase();
    if (u === 'kg') return q * 1000;
    if (u === 'g' || u === 'grama' || u === 'gramas') return q;
    if (u === 'l' || u === 'lt' || u === 'litro' || u === 'litros') return q * 1000;
    if (u === 'ml') return q;
    return q;
}

function custoMedioBaseInsumo(insumoId) {
    const id = Number(insumoId || 0);
    const saldo = cacheSaldos.find(s => Number(s.insumo_id) === id);
    if (saldo && Number(saldo.custo_medio) > 0) return Number(saldo.custo_medio);
    const ins = cacheInsumos.find(i => Number(i.id) === id);
    return Number(ins?.custo_medio || 0);
}

/** Custo do item na ficha = quantidade na unidade base × custo médio por base (g/ml/un). */
function custoItemFicha(insumoId, quantidade, unidade) {
    const custoBase = custoMedioBaseInsumo(insumoId);
    if (!(custoBase > 0)) return 0;
    return qtdParaBase(quantidade, unidade) * custoBase;
}

function fmtCustoUnitarioHumano(insumoId) {
    const base = findInsumoBase(insumoId);
    const custoBase = custoMedioBaseInsumo(insumoId);
    if (!(custoBase > 0)) return 'sem custo médio';
    if (base === 'g') return moeda(custoBase * 1000) + '/kg';
    if (base === 'ml') return moeda(custoBase * 1000) + '/litro';
    return moeda(custoBase) + '/' + labelUnidade(base);
}

function atualizarPreviewConversaoFicha() {
    const box = document.getElementById('fichaConversaoPreview');
    if (!box) return;
    const sid = Number(document.getElementById('fichaInsumo')?.value || 0);
    const qtd = parseNumeroMz(document.getElementById('fichaQtd')?.value || '');
    const uni = String(document.getElementById('fichaUnidade')?.value || '').toLowerCase();
    if (!(sid > 0) || !(qtd > 0) || !uni) {
        box.style.display = 'none';
        box.innerHTML = '';
        return;
    }
    const base = findInsumoBase(sid);
    const qtdBase = qtdParaBase(qtd, uni);
    const custo = custoItemFicha(sid, qtd, uni);
    let conv = '';
    if ((uni === 'g' || uni === 'kg') && (base === 'g' || base === 'kg')) {
        const kg = qtdBase / 1000;
        conv = fmtNumeroHumano(qtd) + ' ' + labelUnidade(uni)
            + ' = <strong>' + fmtNumeroHumano(qtdBase) + ' g</strong>'
            + ' (= ' + fmtNumeroHumano(kg) + ' kg)';
    } else if ((uni === 'ml' || uni === 'l') && (base === 'ml' || base === 'l')) {
        const litros = qtdBase / 1000;
        conv = fmtNumeroHumano(qtd) + ' ' + labelUnidade(uni)
            + ' = <strong>' + fmtNumeroHumano(qtdBase) + ' ml</strong>'
            + ' (= ' + fmtNumeroHumano(litros) + ' l)';
    } else {
        conv = fmtNumeroHumano(qtd) + ' ' + labelUnidade(uni);
    }
    let custoHtml = '';
    if (custo > 0) {
        custoHtml = ' · custo no prato <strong>' + moeda(custo) + '</strong>'
            + ' <span class="text-muted">(' + fmtCustoUnitarioHumano(sid) + ')</span>';
    } else {
        custoHtml = ' · <em>sem custo médio neste insumo — registe uma entrada com custo</em>';
    }
    box.style.display = '';
    box.innerHTML = 'Conversão automática: ' + conv + custoHtml;
}

function renderFichaItens() {
    let total = 0;
    const rows = fichaItens.map((i, idx) => {
        const custo = custoItemFicha(i.insumo_id, i.quantidade, i.unidade);
        total += custo;
        const custoCell = custo > 0
            ? ('<span class="mono">' + moeda(custo) + '</span><div class="text-muted small">' + fmtCustoUnitarioHumano(i.insumo_id) + '</div>')
            : '<span class="text-muted small">Sem custo</span>';
        const qtdBase = qtdParaBase(i.quantidade, i.unidade);
        const base = findInsumoBase(i.insumo_id);
        let convHint = '';
        if (base === 'g' && String(i.unidade).toLowerCase() === 'kg') {
            convHint = '<div class="text-muted small">= ' + fmtNumeroHumano(qtdBase) + ' g</div>';
        } else if (base === 'g' && String(i.unidade).toLowerCase() === 'g') {
            convHint = '<div class="text-muted small">= ' + fmtNumeroHumano(qtdBase / 1000) + ' kg</div>';
        } else if (base === 'ml' && String(i.unidade).toLowerCase() === 'l') {
            convHint = '<div class="text-muted small">= ' + fmtNumeroHumano(qtdBase) + ' ml</div>';
        } else if (base === 'ml' && String(i.unidade).toLowerCase() === 'ml') {
            convHint = '<div class="text-muted small">= ' + fmtNumeroHumano(qtdBase / 1000) + ' l</div>';
        }
        return `<tr>
            <td>${txt(i.insumo_nome)}</td>
            <td class="mono">${fmtNumeroHumano(i.quantidade)}${convHint}</td>
            <td>${txt(labelUnidade(i.unidade))}</td>
            <td>${custoCell}</td>
            <td><button class="btn btn-sm btn-outline-danger" onclick="remFicha(${idx})"><i class="fas fa-trash"></i></button></td>
        </tr>`;
    });
    document.getElementById('tblFichaItens').innerHTML = rows.join('')
        || '<tr><td colspan="5" class="text-center text-muted">Sem itens</td></tr>';
    const tot = document.getElementById('fichaCustoTotal');
    if (tot) tot.textContent = moeda(total);
}
function remFicha(i){
    fichaItens.splice(i,1);
    fichaDirty = true;
    renderFichaItens();
}
window.remFicha = remFicha;

function syncMovValidadeUi() {
    const tipo = String(document.getElementById('movTipo')?.value || 'ENTRADA').toUpperCase();
    const wrap = document.getElementById('movValidadeWrap');
    const note = document.getElementById('movNote');
    const isEntrada = tipo === 'ENTRADA';
    if (wrap) wrap.style.display = isEntrada ? '' : 'none';
    if (!isEntrada) {
        const v = document.getElementById('movValidade');
        if (v) v.value = '';
    }
    if (note) {
        note.innerHTML = isEntrada
            ? 'Em <strong>Adicionar estoque</strong>, indique a validade do lote para alertas automáticos de vencimento.'
            : 'Baixa manual de stock. A validade controla-se nos lotes da entrada (FIFO).';
    }
}

function setInsumoFormModoNovo() {
    editInsumoId = 0;
    wizardReset();
    showWizardNovoMode();
}

function showWizardNovoMode() {
    const edit = document.getElementById('wizardEditMode');
    const novo = document.getElementById('wizardNovoMode');
    const title = document.getElementById('wizardPanelTitle');
    const reiniciar = document.getElementById('btnWizardReiniciar');
    if (edit) edit.style.display = 'none';
    if (novo) novo.style.display = '';
    if (title) title.textContent = 'Novo insumo';
    if (reiniciar) reiniciar.style.display = 'none';
}

function showWizardEditMode() {
    const edit = document.getElementById('wizardEditMode');
    const novo = document.getElementById('wizardNovoMode');
    const title = document.getElementById('wizardPanelTitle');
    const reiniciar = document.getElementById('btnWizardReiniciar');
    if (edit) edit.style.display = '';
    if (novo) novo.style.display = 'none';
    if (title) title.textContent = 'Editar insumo';
    if (reiniciar) reiniciar.style.display = '';
}

const WIZ_TIPO_META = {
    peso: { unidade: 'kg', label: 'kg', custoLabel: 'kg' },
    liquido: { unidade: 'l', label: 'litro', custoLabel: 'litro' },
    contagem: { unidade: 'un', label: 'un', custoLabel: 'unidade' }
};

let wizardState = {
    step: 1,
    tipo: 'peso',
    usarFatia: false
};

function wizardUnidadeHumana() {
    if (wizardState.tipo === 'contagem' && wizardState.usarFatia) {
        return { unidade: 'fatia', label: 'fatia', custoLabel: 'fatia' };
    }
    return WIZ_TIPO_META[wizardState.tipo] || WIZ_TIPO_META.peso;
}

function wizardReset() {
    wizardState = { step: 1, tipo: 'peso', usarFatia: false };
    const nome = document.getElementById('wizNome');
    const min = document.getElementById('wizMinimo');
    const qtd = document.getElementById('wizQtdInicial');
    const custo = document.getElementById('wizCusto');
    const validade = document.getElementById('wizValidade');
    const fatia = document.getElementById('wizUsarFatia');
    const sucesso = document.getElementById('wizSucessoNote');
    if (nome) nome.value = '';
    if (min) min.value = '';
    if (qtd) qtd.value = '';
    if (custo) custo.value = '';
    if (validade) validade.value = '';
    if (fatia) fatia.checked = false;
    if (sucesso) {
        sucesso.style.display = 'none';
        sucesso.innerHTML = '';
    }
    document.querySelectorAll('.wizard-type-btn').forEach(btn => {
        btn.classList.toggle('is-selected', btn.getAttribute('data-wiz-tipo') === 'peso');
    });
    const fatiaWrap = document.getElementById('wizFatiaWrap');
    if (fatiaWrap) fatiaWrap.style.display = 'none';
    wizardGoStep(1);
    wizardSyncUnitLabels();
    wizardUpdatePreview();
}

function wizardGoStep(step) {
    wizardState.step = Math.max(1, Math.min(3, Number(step) || 1));
    document.querySelectorAll('[data-wizard-pane]').forEach(pane => {
        const n = Number(pane.getAttribute('data-wizard-pane'));
        pane.classList.toggle('is-active', n === wizardState.step);
    });
    document.querySelectorAll('[data-step-pill]').forEach(pill => {
        const n = Number(pill.getAttribute('data-step-pill'));
        pill.classList.toggle('is-active', n === wizardState.step);
        pill.classList.toggle('is-done', n < wizardState.step);
    });
    if (wizardState.step === 3) {
        wizardRenderResumo();
    }
}

function wizardSyncUnitLabels() {
    const meta = wizardUnidadeHumana();
    ['wizMinimoSuffix', 'wizQtdSuffix'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.textContent = meta.label;
    });
    const custoLabel = document.getElementById('wizCustoUnidadeLabel');
    if (custoLabel) custoLabel.textContent = meta.custoLabel;
}

function wizardUpdatePreview() {
    const prev = document.getElementById('wizPreview2');
    if (!prev) return;
    const meta = wizardUnidadeHumana();
    const minimo = parseNumeroMz(document.getElementById('wizMinimo')?.value || '');
    const qtd = parseNumeroMz(document.getElementById('wizQtdInicial')?.value || '');
    const custo = parseNumeroMz(document.getElementById('wizCusto')?.value || '');
    if (!(minimo > 0)) {
        prev.textContent = 'Indique o mínimo (ex.: 5 ' + meta.label + '). Sem isto as sugestões de compra ficam erradas.';
        return;
    }
    let msg = 'Vai gravar mínimo <strong>' + fmtNumeroHumano(minimo) + ' ' + meta.label + '</strong>';
    if (qtd > 0) {
        msg += ' · entrada inicial <strong>' + fmtNumeroHumano(qtd) + ' ' + meta.label + '</strong>';
        if (custo > 0) {
            msg += ' a <strong>' + moeda(custo) + '/' + meta.custoLabel + '</strong>';
        }
    } else {
        msg += ' · sem stock inicial (pode adicionar depois)';
    }
    prev.innerHTML = msg;
}

function wizardRenderResumo() {
    const box = document.getElementById('wizResumo');
    if (!box) return;
    const nome = String(document.getElementById('wizNome')?.value || '').trim();
    const meta = wizardUnidadeHumana();
    const minimo = parseNumeroMz(document.getElementById('wizMinimo')?.value || '');
    const qtd = parseNumeroMz(document.getElementById('wizQtdInicial')?.value || '');
    const custo = parseNumeroMz(document.getElementById('wizCusto')?.value || '');
    const validade = String(document.getElementById('wizValidade')?.value || '').trim();
    let html = '<strong>' + txt(nome || '—') + '</strong><br>';
    html += 'Medida: <strong>' + txt(meta.label) + '</strong><br>';
    html += 'Mínimo: <strong>' + (minimo > 0 ? fmtNumeroHumano(minimo) + ' ' + meta.label : '—') + '</strong><br>';
    if (qtd > 0) {
        html += 'Primeira entrada: <strong>' + fmtNumeroHumano(qtd) + ' ' + meta.label + '</strong>';
        if (custo > 0) html += ' · custo <strong>' + moeda(custo) + '/' + meta.custoLabel + '</strong>';
        if (validade) html += '<br>Validade: <strong>' + txt(validade) + '</strong>';
    } else {
        html += 'Primeira entrada: <em>nenhuma</em> (só cria o cadastro)';
    }
    box.innerHTML = html;
}

function editarInsumo(insumoId) {
    const id = Number(insumoId || 0);
    const ins = cacheInsumos.find(i => Number(i.id) === id);
    if (!ins) {
        NivoraFeedback.warning('Insumo não encontrado para edição. Clique em Atualizar e tente de novo.');
        return;
    }
    ativarAbaEstoque('cadastro');
    editInsumoId = id;
    showWizardEditMode();
    document.getElementById('insumoNome').value = txt(ins.nome);
    document.getElementById('insumoSku').value = txt(ins.sku || 'Automático');
    const uniEl = document.getElementById('insumoUnidade');
    const humanUnit = baseToInputUnit(ins.unidade_base);
    const allowed = ['kg', 'l', 'un', 'fatia'];
    uniEl.value = allowed.includes(humanUnit) ? humanUnit : (humanUnit === 'g' ? 'kg' : (humanUnit === 'ml' ? 'l' : 'un'));
    const minDisp = baseToDisplay(ins.estoque_minimo_base, ins.unidade_base);
    document.getElementById('insumoMinimo').value = String(Math.round(minDisp * 1000) / 1000);
    const suffix = document.getElementById('editMinimoSuffix');
    if (suffix) suffix.textContent = labelUnidade(uniEl.value);
    const temStock = Number(ins.saldo_base || 0) > 0.0001;
    const emFicha = Number(ins.em_ficha || 0) > 0;
    const unidadeTravada = temStock || emFicha;
    uniEl.disabled = unidadeTravada;
    const note = document.getElementById('insumoFormNote');
    if (note) {
        if (unidadeTravada) {
            note.innerHTML = '<strong>Pode alterar nome e mínimo.</strong> Unidade bloqueada em <code>' +
                txt(labelUnidade(uniEl.value)) +
                '</code> porque ' +
                (temStock && emFicha ? 'há stock e ficha técnica' : (temStock ? 'há stock' : 'está numa ficha técnica')) +
                '. Para mudar a unidade: zere o stock e remova o insumo das fichas.';
        } else {
            note.textContent = 'A editar insumo. Use kg / litro / unidade (ou fatia).';
        }
    }
    const panel = document.getElementById('panelCadastroInsumo');
    if (panel) panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
    document.getElementById('insumoNome').focus();
}
window.editarInsumo = editarInsumo;

async function excluirInsumo(insumoId) {
    if (!(Number(insumoId) > 0)) return;
    if (!(await nivoraConfirmar({ mensagem: 'Remover este insumo?', confirmar: 'Remover', perigo: true }))) return;
    const fd = new FormData();
    fd.append('insumo_id', String(insumoId));
    fd.append('_csrf', CSRF);
    try {
        const r = await fetchJson('api/estoque_insumo_excluir.php', { method: 'POST', body: fd });
        NivoraFeedback.show(r.message || (r.success ? 'Insumo removido.' : 'Não foi possível remover o insumo.'), r.success ? 'success' : 'error');
        if (r.success) {
            await carregarBase();
        }
    } catch (e) {
        const msg = String(e?.message || '');
        if (msg.includes('Nao e possivel apagar')) {
            const confirmarArquivar = await nivoraConfirmar({ titulo: 'Arquivar este insumo?', mensagem: msg + '\nArquivado, deixa de aparecer na operação.', confirmar: 'Arquivar' });
            if (!confirmarArquivar) return;
            const fd2 = new FormData();
            fd2.append('insumo_id', String(insumoId));
            fd2.append('modo', 'arquivar');
            fd2.append('_csrf', CSRF);
            try {
                const r2 = await fetchJson('api/estoque_insumo_excluir.php', { method: 'POST', body: fd2 });
                NivoraFeedback.show(r2.message || (r2.success ? 'Insumo arquivado.' : 'Não foi possível arquivar o insumo.'), r2.success ? 'success' : 'error');
                if (r2.success) await carregarBase();
                return;
            } catch (e2) {
                NivoraFeedback.error(e2.message || 'Erro ao arquivar insumo.');
                return;
            }
        }
        NivoraFeedback.error(msg || 'Erro ao excluir insumo.');
    }
}
window.excluirInsumo = excluirInsumo;

async function carregarFichaProduto() {
    const pid = Number(document.getElementById('fichaProduto').value || 0);
    if (!pid) {
        fichaItens = [];
        fichaDirty = false;
        document.getElementById('fichaRendimento').value = '1';
        renderFichaItens();
        return;
    }
    if (fichaLoading) return;
    fichaLoading = true;
    const btnSalvar = document.getElementById('btnSalvarFicha');
    if (btnSalvar) btnSalvar.disabled = true;
    try {
        const r = await fetchJson('api/estoque_ficha_buscar.php?produto_id=' + encodeURIComponent(pid));
        if (!r.success) {
            NivoraFeedback.error(r.message || 'Não foi possível carregar a ficha deste produto.');
            return;
        }
        document.getElementById('fichaRendimento').value = Number(r.data?.rendimento || 1);
        // API devolve quantidade em unidade base (g/ml/un); o formulário usa kg/l/un.
        fichaItens = (r.data?.itens || []).map(i => {
            const base = String(i.unidade_base || 'un').toLowerCase() || 'un';
            return {
                insumo_id: Number(i.insumo_id),
                insumo_nome: i.insumo_nome,
                quantidade: baseToDisplay(Number(i.quantidade_base), base),
                unidade: baseToInputUnit(base)
            };
        });
        fichaDirty = false;
        renderFichaItens();
    } catch (e) {
        console.error('ficha produto:', e);
        fichaItens = [];
        fichaDirty = false;
        renderFichaItens();
        NivoraFeedback.error(e.message || 'Erro ao carregar ficha técnica do produto.');
    } finally {
        fichaLoading = false;
        if (btnSalvar) btnSalvar.disabled = false;
    }
}
