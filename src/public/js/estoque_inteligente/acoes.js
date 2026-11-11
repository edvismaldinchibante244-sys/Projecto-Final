// Estoque inteligente - Botoes (insumos, movimentos, transferencias, perdas, fichas), abas e arranque.
// Parte de estoque_inteligente.php; carregado por ordem com os outros ficheiros de js/estoque_inteligente/.

function mostrarFeedbackMovimento(mensagem, sucesso) {
    const feedback = document.getElementById('movFeedback');
    if (!feedback) {
        console.error('Elemento movFeedback não encontrado.');
        return;
    }

    feedback.textContent = mensagem;
    feedback.className = 'stock-feedback ' + (sucesso ? 'is-success' : 'is-error');
    feedback.setAttribute('role', sucesso ? 'status' : 'alert');
    feedback.hidden = false;
}

document.getElementById('btnSalvarInsumo')?.addEventListener('click', async () => {
    // Modo edição rápida
    if (!(editInsumoId > 0)) {
        NivoraFeedback.warning('Use o assistente \"Novo insumo\" para criar. Esta acção é só para actualizar.');
        return;
    }
    const nome = document.getElementById('insumoNome').value.trim();
    if (!nome) { NivoraFeedback.warning('Informe o nome do insumo.'); return; }
    const minimo = parseNumeroMz(document.getElementById('insumoMinimo').value || '0');
    if (!Number.isFinite(minimo) || minimo < 0) { NivoraFeedback.warning('Mínimo inválido.'); return; }
    const btn = document.getElementById('btnSalvarInsumo');
    const uniEl = document.getElementById('insumoUnidade');
    const wasDisabled = !!(uniEl && uniEl.disabled);
    if (uniEl) uniEl.disabled = false;
    if (btn) btn.disabled = true;
    const fd = new FormData();
    fd.append('nome', nome);
    fd.append('unidade', uniEl ? uniEl.value : 'kg');
    fd.append('estoque_minimo', String(minimo));
    fd.append('id', String(editInsumoId));
    fd.append('_csrf', CSRF);
    try {
        const r = await fetchJson('api/estoque_insumo_salvar.php', {method:'POST', body:fd});
        const skuMsg = r.sku ? (' SKU: ' + r.sku) : '';
        NivoraFeedback.show((r.message || (r.success ? 'Insumo actualizado.' : 'Não foi possível actualizar o insumo.')) + skuMsg, r.success ? 'success' : 'error');
        if (r.success) {
            setInsumoFormModoNovo();
            await carregarBase({ keepFichaLocal: true });
        } else if (wasDisabled && uniEl) {
            uniEl.disabled = true;
        }
    } catch (e) {
        if (wasDisabled && uniEl) uniEl.disabled = true;
        NivoraFeedback.error(e.message || 'Erro ao actualizar insumo.');
    } finally {
        if (btn) btn.disabled = false;
    }
});

async function wizardCriarInsumo() {
    const nome = String(document.getElementById('wizNome')?.value || '').trim();
    const meta = wizardUnidadeHumana();
    const minimo = parseNumeroMz(document.getElementById('wizMinimo')?.value || '');
    const qtd = parseNumeroMz(document.getElementById('wizQtdInicial')?.value || '');
    const custo = parseNumeroMz(document.getElementById('wizCusto')?.value || '');
    const validade = String(document.getElementById('wizValidade')?.value || '').trim();
    if (!nome) {
        NivoraFeedback.warning('Informe o nome do insumo.');
        wizardGoStep(1);
        document.getElementById('wizNome')?.focus();
        return;
    }
    if (!(minimo > 0)) {
        NivoraFeedback.warning('Indique um mínimo maior que zero (ex.: 5 kg).');
        wizardGoStep(2);
        document.getElementById('wizMinimo')?.focus();
        return;
    }
    if (qtd > 0 && !validade) {
        const ok = await nivoraConfirmar({ mensagem: 'Sem data de validade neste lote.\n\nSem validade, o sistema não consegue alertar vencimentos. Continuar mesmo assim?', confirmar: 'Continuar sem validade', cancelar: 'Voltar' });
        if (!ok) {
            wizardGoStep(2);
            document.getElementById('wizValidade')?.focus();
            return;
        }
    }
    const btn = document.getElementById('btnWizCriar');
    if (btn) {
        btn.disabled = true;
        btn.classList.add('btn-loading');
    }
    try {
        const fd = new FormData();
        fd.append('nome', nome);
        fd.append('unidade', meta.unidade);
        fd.append('estoque_minimo', String(minimo));
        fd.append('_csrf', CSRF);
        const r = await fetchJson('api/estoque_insumo_salvar.php', { method: 'POST', body: fd });
        if (!r.success) {
            throw new Error(r.message || 'Falha ao criar insumo.');
        }
        const novoId = Number(r.id || r.insumo_id || 0);
        let entradaOk = true;
        let entradaMsg = '';
        if (qtd > 0) {
            if (!(novoId > 0)) {
                // Fallback: recarregar e procurar por SKU/nome
                await carregarBase({ keepFichaLocal: true });
            }
            let insumoId = novoId;
            if (!(insumoId > 0)) {
                const found = cacheInsumos.find(i => String(i.sku || '') === String(r.sku || '') || String(i.nome || '').toLowerCase() === nome.toLowerCase());
                insumoId = Number(found?.id || 0);
            }
            if (insumoId > 0) {
                const fdMov = new FormData();
                fdMov.append('insumo_id', String(insumoId));
                fdMov.append('tipo', 'ENTRADA');
                fdMov.append('quantidade', String(qtd));
                fdMov.append('unidade', meta.unidade);
                if (custo > 0) fdMov.append('custo_unitario', String(custo));
                fdMov.append('referencia', 'Entrada inicial (assistente)');
                if (validade) fdMov.append('validade', validade);
                fdMov.append('_csrf', CSRF);
                try {
                    const rMov = await fetchJson('api/estoque_movimento_registrar.php', { method: 'POST', body: fdMov });
                    if (!rMov.success) {
                        throw new Error(rMov.message || 'Não foi possível registar a entrada inicial.');
                    }
                    entradaMsg = rMov.message || 'Entrada registada.';
                } catch (eMov) {
                    entradaOk = false;
                    entradaMsg = eMov.message || 'Insumo criado, mas a entrada falhou.';
                }
            } else {
                entradaOk = false;
                entradaMsg = 'Insumo criado, mas não foi possível registar a entrada automaticamente.';
            }
        }
        const skuMsg = r.sku ? (' SKU ' + r.sku) : '';
        let sucesso = 'Insumo <strong>' + txt(nome) + '</strong> criado' + (skuMsg ? ' (' + txt(skuMsg.trim()) + ')' : '') + '.';
        if (qtd > 0) {
            sucesso += entradaOk
                ? ' Stock inicial adicionado.'
                : ' ' + txt(entradaMsg);
        }
        sucesso += ' Pode incluí-lo agora numa ficha de prato.';
        NivoraFeedback.show(
            (r.message || 'Insumo cadastrado.') + (skuMsg ? ' ' + skuMsg : '') + (entradaMsg && !entradaOk ? '\n' + entradaMsg : ''),
            entradaOk ? 'success' : 'warning'
        );
        await carregarBase({ keepFichaLocal: true });
        if (novoId > 0 || r.sku) {
            const found = cacheInsumos.find(i => Number(i.id) === Number(novoId) || String(i.sku || '') === String(r.sku || ''));
            if (found) {
                const fichaIns = document.getElementById('fichaInsumo');
                if (fichaIns) {
                    fichaIns.value = String(found.id);
                    syncUnidadeFromInsumoSelect('fichaInsumo', 'fichaUnidade');
                }
            }
        }
        wizardReset();
        const note = document.getElementById('wizSucessoNote');
        if (note) {
            note.style.display = '';
            note.innerHTML = sucesso + ' <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-goto-tab="fichas">Abrir Fichas</button>';
        }
    } catch (e) {
        NivoraFeedback.error(e.message || 'Erro ao criar insumo.');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.classList.remove('btn-loading');
        }
    }
}

document.getElementById('btnSalvarMov')?.addEventListener('click', async () => {
    const insumoId = document.getElementById('movInsumo').value;
    const qtd = parseNumeroMz(document.getElementById('movQtd').value || 0);
    const tipo = String(document.getElementById('movTipo').value || 'ENTRADA').toUpperCase();
    const validade = String(document.getElementById('movValidade').value || '').trim();
    const feedback = document.getElementById('movFeedback');
    if (feedback) {
        feedback.hidden = true;
        feedback.textContent = '';
        feedback.className = 'stock-feedback';
    }
    if (!insumoId) {
        mostrarFeedbackMovimento('Selecione o insumo.', false);
        document.getElementById('movInsumo').focus();
        return;
    }
    if (!(qtd > 0)) {
        mostrarFeedbackMovimento('Informe uma quantidade maior que zero.', false);
        document.getElementById('movQtd').focus();
        return;
    }
    if (tipo === 'ENTRADA' && !validade) {
        const ok = await nivoraConfirmar({ mensagem: 'Sem data de validade neste lote.\n\nSem validade, o sistema não consegue alertar vencimentos. Continuar mesmo assim?', confirmar: 'Continuar sem validade', cancelar: 'Voltar' });
        if (!ok) {
            document.getElementById('movValidade')?.focus();
            return;
        }
    }
    const btn = document.getElementById('btnSalvarMov');
    const textoOriginal = btn ? btn.textContent : '';
    if (btn) {
        btn.disabled = true;
        btn.classList.add('btn-loading');
        btn.setAttribute('aria-busy', 'true');
        btn.textContent = 'A guardar movimentação...';
    }
    const fd = new FormData();
    fd.append('insumo_id', insumoId);
    fd.append('tipo', tipo);
    fd.append('quantidade', String(qtd));
    fd.append('unidade', unidadeSeguraParaEnvio(insumoId, 'movUnidade'));
    fd.append('custo_unitario', document.getElementById('movCusto').value || '');
    fd.append('referencia', document.getElementById('movRef').value);
    if (tipo === 'ENTRADA' && validade) fd.append('validade', validade);
    fd.append('_csrf', CSRF);
    try {
        const r = await fetchJson('api/estoque_movimento_registrar.php', {method:'POST', body:fd});
        if (!r.success) {
            throw new Error(r.message || 'Não foi possível registar a movimentação.');
        }
        mostrarFeedbackMovimento(r.message || 'Movimento registado com sucesso.', true);
        document.getElementById('movQtd').value = '';
        document.getElementById('movCusto').value = '';
        document.getElementById('movRef').value = '';
        document.getElementById('movValidade').value = '';
        await carregarBase();
    } catch (e) {
        mostrarFeedbackMovimento(e.message || 'Erro ao registar a movimentação.', false);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.classList.remove('btn-loading');
            btn.removeAttribute('aria-busy');
            btn.textContent = textoOriginal;
        }
    }
});

const btnTransferirEstoque = document.getElementById('btnTransferirEstoque');
if (btnTransferirEstoque) {
    btnTransferirEstoque.addEventListener('click', async () => {
        const insumoId = document.getElementById('trfInsumo').value;
        const qtd = Number(document.getElementById('trfQtd').value || 0);
        const origem = document.getElementById('trfOrigem').value;
        const destino = document.getElementById('trfDestino').value;
        if (!insumoId) { NivoraFeedback.warning('Selecione o insumo.'); return; }
        if (!(qtd > 0)) { NivoraFeedback.warning('Informe uma quantidade maior que zero.'); return; }
        if (String(origem) === String(destino)) { NivoraFeedback.warning('Origem e destino devem ser diferentes.'); return; }
        const fd = new FormData();
        fd.append('insumo_id', insumoId);
        fd.append('quantidade', String(qtd));
        fd.append('unidade', unidadeSeguraParaEnvio(insumoId, 'trfUnidade'));
        fd.append('filial_origem_id', origem);
        fd.append('filial_destino_id', destino);
        fd.append('motivo', document.getElementById('trfMotivo').value || '');
        fd.append('_csrf', CSRF);
        try {
            const r = await fetchJson('api/estoque_transferir.php', {method:'POST', body:fd});
            NivoraFeedback.show(r.message || (r.success ? 'Transferencia registada.' : 'Não foi possível transferir o stock.'), r.success ? 'success' : 'error');
            if (r.success) carregarBase();
        } catch (e) {
            NivoraFeedback.error(e.message || 'Erro ao transferir estoque.');
        }
    });
    document.getElementById('trfInsumo').addEventListener('change', () => {
        const u = document.getElementById('trfUnidade');
        if (u) u.dataset.userTouched = '0';
        syncUnidadeFromInsumoSelect('trfInsumo', 'trfUnidade');
    });
}

document.getElementById('btnSalvarPerda')?.addEventListener('click', async () => {
    const insumoId = document.getElementById('perdaInsumo').value;
    const qtd = parseNumeroMz(document.getElementById('perdaQtd').value || 0);
    const motivo = document.getElementById('perdaMotivo').value.trim();
    if (!insumoId) { NivoraFeedback.warning('Selecione o insumo da perda.'); return; }
    if (!(qtd > 0)) { NivoraFeedback.warning('Informe a quantidade da perda.'); return; }
    if (!motivo) { NivoraFeedback.warning('Informe o motivo da perda.'); return; }
    const btn = document.getElementById('btnSalvarPerda');
    if (btn) btn.disabled = true;
    const fd = new FormData();
    fd.append('insumo_id', insumoId);
    fd.append('quantidade', String(qtd));
    fd.append('unidade', unidadeSeguraParaEnvio(insumoId, 'perdaUnidade'));
    fd.append('custo_unitario', document.getElementById('perdaCusto').value || '');
    fd.append('motivo', motivo);
    fd.append('_csrf', CSRF);
    try {
        const r = await fetchJson('api/estoque_perda_registrar.php', {method:'POST', body:fd});
        NivoraFeedback.show(r.message || (r.success ? 'Perda registrada.' : 'Não foi possível registar a perda.'), r.success ? 'success' : 'error');
        if (r.success) {
            document.getElementById('perdaQtd').value = '';
            document.getElementById('perdaCusto').value = '';
            document.getElementById('perdaMotivo').value = '';
            await carregarBase();
        }
    } catch (e) {
        NivoraFeedback.error(e.message || 'Erro ao registrar perda.');
    } finally {
        if (btn) btn.disabled = false;
    }
});

document.getElementById('btnAddItemFicha')?.addEventListener('click', () => {
    const sid = Number(document.getElementById('fichaInsumo').value || 0);
    const qtdEl = document.getElementById('fichaQtd');
    const qtd = parseNumeroMz(qtdEl.value || 0);
    const produtoId = Number(document.getElementById('fichaProduto').value || 0);
    if (!produtoId) {
        NivoraFeedback.warning('Primeiro selecione o prato/produto da ficha.');
        document.getElementById('fichaProduto')?.focus();
        return;
    }
    if (!sid) {
        NivoraFeedback.warning('Selecione o insumo da ficha.');
        document.getElementById('fichaInsumo')?.focus();
        return;
    }
    if (!(qtd > 0)) {
        NivoraFeedback.warning('Informe a quantidade do insumo (ex.: 1 ou 0,150).');
        qtdEl?.focus();
        return;
    }
    const uni = unidadeSeguraParaEnvio(sid, 'fichaUnidade');
    const ins = cacheInsumos.find(i => Number(i.id) === sid);
    if (!ins) {
        NivoraFeedback.warning('Insumo não encontrado. Clique em Atualizar.');
        return;
    }
    if (fichaItens.some(i => Number(i.insumo_id) === sid)) {
        NivoraFeedback.warning('Este insumo já está na ficha. Remova-o antes de adicionar de novo.');
        return;
    }
    fichaItens.push({insumo_id:sid, insumo_nome:ins.nome, quantidade:qtd, unidade:uni});
    fichaDirty = true;
    qtdEl.value = '';
    renderFichaItens();
    const hint = document.getElementById('fichaHint');
    if (hint) hint.textContent = fichaItens.length + ' item(ns) na ficha — clique em «Salvar Ficha Técnica» para gravar.';
});

document.getElementById('btnSalvarFicha')?.addEventListener('click', async () => {
    const produtoId = document.getElementById('fichaProduto').value;
    if (!produtoId) { NivoraFeedback.warning('Selecione o produto da ficha técnica.'); return; }
    if (!Array.isArray(fichaItens) || fichaItens.length === 0) {
        NivoraFeedback.warning('Adicione pelo menos 1 insumo: escolha o insumo, preencha a Qtd e clique em Adicionar.');
        document.getElementById('fichaQtd')?.focus();
        return;
    }
    const itensSafe = fichaItens.map(i => {
        const base = findInsumoBase(i.insumo_id);
        const opts = unidadesPermitidas(base);
        let u = String(i.unidade || '').trim();
        if (!u || !opts.includes(u)) u = baseToInputUnit(base);
        return {insumo_id: i.insumo_id, quantidade: i.quantidade, unidade: u};
    });
    const btn = document.getElementById('btnSalvarFicha');
    if (btn) btn.disabled = true;
    const fd = new FormData();
    fd.append('produto_id', produtoId);
    fd.append('rendimento', String(parseNumeroMz(document.getElementById('fichaRendimento').value || '1') || 1));
    fd.append('itens', JSON.stringify(itensSafe));
    fd.append('_csrf', CSRF);
    try {
        const r = await fetchJson('api/estoque_ficha_tecnica_salvar.php', {method:'POST', body:fd});
        NivoraFeedback.show(r.message || (r.success ? 'Ficha salva.' : 'Não foi possível salvar a ficha.'), r.success ? 'success' : 'error');
        if (r.success) {
            fichaDirty = false;
            await carregarFichaProduto();
            await carregarBase({ keepFichaLocal: true });
        }
    } catch (e) {
        NivoraFeedback.error(e.message || 'Erro ao salvar ficha.');
    } finally {
        if (btn) btn.disabled = false;
    }
});

function onEl(id, event, handler) {
    const el = document.getElementById(id);
    if (!el) return null;
    el.addEventListener(event, handler);
    return el;
}

function bindUnidadeUserTouch(unidadeSelectId) {
    const el = document.getElementById(unidadeSelectId);
    if (!el) return;
    el.addEventListener('change', () => { el.dataset.userTouched = '1'; });
}
bindUnidadeUserTouch('movUnidade');
bindUnidadeUserTouch('perdaUnidade');
bindUnidadeUserTouch('fichaUnidade');
bindUnidadeUserTouch('trfUnidade');

function sugerirCustoMovimento(insumoId) {
    const movCusto = document.getElementById('movCusto');
    if (!movCusto) return;
    const id = Number(insumoId || 0);
    if (!(id > 0)) return;
    const saldo = cacheSaldos.find(s => Number(s.insumo_id) === id);
    const custoBase = Number(saldo?.custo_medio || 0);
    const base = findInsumoBase(id);
    const human = baseToInputUnit(base);
    movCusto.placeholder = 'Custo MZN / ' + labelUnidade(human);
    if (!(custoBase > 0)) return;
    // Só preenche se o campo estiver vazio (não sobrescreve digitação).
    if (String(movCusto.value || '').trim() !== '') return;
    const custoHuman = (base === 'g' || base === 'ml') ? (custoBase * 1000) : custoBase;
    movCusto.value = String(Math.round(custoHuman * 100) / 100);
}

onEl('movInsumo', 'change', () => {
    const u = document.getElementById('movUnidade');
    if (u) u.dataset.userTouched = '0';
    syncUnidadeFromInsumoSelect('movInsumo', 'movUnidade');
    sugerirCustoMovimento(document.getElementById('movInsumo')?.value);
});
onEl('movTipo', 'change', syncMovValidadeUi);
onEl('perdaInsumo', 'change', () => {
    const u = document.getElementById('perdaUnidade');
    if (u) u.dataset.userTouched = '0';
    syncUnidadeFromInsumoSelect('perdaInsumo', 'perdaUnidade');
});
onEl('fichaInsumo', 'change', () => {
    const u = document.getElementById('fichaUnidade');
    if (u) u.dataset.userTouched = '0';
    syncUnidadeFromInsumoSelect('fichaInsumo', 'fichaUnidade');
    atualizarPreviewConversaoFicha();
});
onEl('fichaQtd', 'input', () => atualizarPreviewConversaoFicha());
onEl('fichaUnidade', 'change', () => atualizarPreviewConversaoFicha());
onEl('fichaProduto', 'change', () => { fichaDirty = false; carregarFichaProduto(); });
onEl('btnAtualizar', 'click', () => carregarBase());
onEl('btnResolverTodosAlertas', 'click', () => {
    const confirmacao = document.getElementById('confirmResolverTodosAlertas');
    const botao = document.getElementById('btnResolverTodosAlertas');
    if (!confirmacao || !botao) return;

    confirmacao.hidden = !confirmacao.hidden;
    const aberta = !confirmacao.hidden;
    botao.setAttribute('aria-expanded', String(aberta));
    if (aberta) {
        document.getElementById('btnConfirmarResolverTodosAlertas')?.focus();
    } else {
        botao.focus();
    }
});
onEl('btnCancelarResolverTodosAlertas', 'click', () => {
    const confirmacao = document.getElementById('confirmResolverTodosAlertas');
    const botao = document.getElementById('btnResolverTodosAlertas');
    if (!confirmacao || !botao) return;

    confirmacao.hidden = true;
    botao.setAttribute('aria-expanded', 'false');
    botao.focus();
});
onEl('btnConfirmarResolverTodosAlertas', 'click', () => resolverAlerta(0, true));
// Aplicar minimos sugeridos: ver js/estoque_inteligente/minimos.js
onEl('fichaQtd', 'keydown', (ev) => {
    if (ev.key === 'Enter') {
        ev.preventDefault();
        document.getElementById('btnAddItemFicha')?.click();
    }
});

// —— Eventos do assistente de cadastro ——
document.querySelectorAll('.wizard-type-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const tipo = String(btn.getAttribute('data-wiz-tipo') || 'peso');
        wizardState.tipo = tipo;
        document.querySelectorAll('.wizard-type-btn').forEach(b => {
            b.classList.toggle('is-selected', b === btn);
        });
        const fatiaWrap = document.getElementById('wizFatiaWrap');
        if (fatiaWrap) fatiaWrap.style.display = tipo === 'contagem' ? '' : 'none';
        if (tipo !== 'contagem') {
            wizardState.usarFatia = false;
            const fatia = document.getElementById('wizUsarFatia');
            if (fatia) fatia.checked = false;
        }
        wizardSyncUnitLabels();
        wizardUpdatePreview();
    });
});
onEl('wizUsarFatia', 'change', (ev) => {
    wizardState.usarFatia = !!(ev.target && ev.target.checked);
    wizardSyncUnitLabels();
    wizardUpdatePreview();
});
['wizMinimo', 'wizQtdInicial', 'wizCusto'].forEach(id => {
    onEl(id, 'input', () => wizardUpdatePreview());
    onEl(id, 'change', () => wizardUpdatePreview());
});
onEl('btnWizNext1', 'click', () => {
    const nome = String(document.getElementById('wizNome')?.value || '').trim();
    if (!nome) {
        NivoraFeedback.warning('Informe o nome do insumo.');
        document.getElementById('wizNome')?.focus();
        return;
    }
    wizardGoStep(2);
    document.getElementById('wizMinimo')?.focus();
});
onEl('btnWizBack2', 'click', () => wizardGoStep(1));
onEl('btnWizNext2', 'click', () => {
    const minimo = parseNumeroMz(document.getElementById('wizMinimo')?.value || '');
    if (!(minimo > 0)) {
        NivoraFeedback.warning('Indique um mínimo maior que zero (ex.: 5 kg). Sem isto as sugestões de compra ficam erradas.');
        document.getElementById('wizMinimo')?.focus();
        return;
    }
    wizardGoStep(3);
});
onEl('btnWizBack3', 'click', () => wizardGoStep(2));
onEl('btnWizCriar', 'click', () => wizardCriarInsumo());
onEl('btnWizardReiniciar', 'click', () => setInsumoFormModoNovo());
onEl('btnCancelarEdicaoInsumo', 'click', () => setInsumoFormModoNovo());
onEl('insumoUnidade', 'change', () => {
    const suffix = document.getElementById('editMinimoSuffix');
    const uni = document.getElementById('insumoUnidade');
    if (suffix && uni) suffix.textContent = labelUnidade(uni.value);
});

const ESTOQUE_TABS = ESTOQUE_EMP_COCKPIT
    ? ['insights', 'cadastro', 'movimentos', 'perdas', 'fichas']
    : ['fichas', 'cadastro', 'movimentos', 'perdas'];
function ativarAbaEstoque(tab, opts = {}) {
    let name = ESTOQUE_TABS.includes(tab) ? tab : ESTOQUE_DEFAULT_TAB;
    if (!ESTOQUE_EMP_COCKPIT && name === 'insights') name = ESTOQUE_DEFAULT_TAB;
    document.querySelectorAll('[data-estoque-tab]').forEach(btn => {
        const on = btn.getAttribute('data-estoque-tab') === name;
        btn.classList.toggle('is-active', on);
        btn.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    document.querySelectorAll('[data-estoque-pane]').forEach(pane => {
        pane.classList.toggle('is-active', pane.getAttribute('data-estoque-pane') === name);
    });
    if (opts.updateUrl !== false) {
        try {
            const u = new URL(window.location.href);
            if (name === ESTOQUE_DEFAULT_TAB) u.searchParams.delete('tab');
            else u.searchParams.set('tab', name);
            history.replaceState({}, '', u.pathname + u.search + u.hash);
        } catch (e) {}
    }
    if (name === 'cadastro') {
        const focusId = editInsumoId > 0 ? 'insumoNome' : 'wizNome';
        setTimeout(() => document.getElementById(focusId)?.focus(), 50);
    }
}
window.ativarAbaEstoque = ativarAbaEstoque;

document.getElementById('estoqueTabs')?.addEventListener('click', (ev) => {
    const btn = ev.target.closest('[data-estoque-tab]');
    if (!btn) return;
    ativarAbaEstoque(btn.getAttribute('data-estoque-tab'));
});
document.addEventListener('click', (ev) => {
    const go = ev.target.closest('[data-goto-tab]');
    if (!go) return;
    ev.preventDefault();
    ativarAbaEstoque(go.getAttribute('data-goto-tab'));
});

try {
    const tabInit = new URLSearchParams(window.location.search).get('tab');
    if (tabInit) ativarAbaEstoque(tabInit, { updateUrl: false });
    else ativarAbaEstoque(ESTOQUE_DEFAULT_TAB, { updateUrl: false });
} catch (e) {
    ativarAbaEstoque(ESTOQUE_DEFAULT_TAB, { updateUrl: false });
}
setInsumoFormModoNovo();
syncMovValidadeUi();
renderFichaItens();
carregarBase();
