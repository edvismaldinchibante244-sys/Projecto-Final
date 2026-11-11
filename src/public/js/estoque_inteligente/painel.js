// Estoque inteligente - Painel: saldos, riscos, previsao, lotes e sugestoes de compra.
// Parte de estoque_inteligente.php; carregado por ordem com os outros ficheiros de js/estoque_inteligente/.

function renderDashboard(data) {
    const saldos = Array.isArray(data.saldos) ? data.saldos : [];
    const alertas = Array.isArray(data.alertas_abertos) ? data.alertas_abertos : [];
    const prev = Array.isArray(data.previsao_consumo) ? data.previsao_consumo : [];
    const insights = data.insights || {};
    cacheSaldos = saldos;
    cachePrevisao = prev;
    cacheInsights = insights;
    const prevMap = new Map(prev.map(p => [Number(p.insumo_id), p]));
    const sugestoes = Array.isArray(insights.sugestoes_compra) ? insights.sugestoes_compra : [];
    const criticos = Number(insights.insumos_criticos_previstos || 0);
    const semFicha = Number(insights.produtos_sem_ficha || 0);
    const score = insights.score_saude != null ? Number(insights.score_saude) : null;

    document.getElementById('kpiInsumos').textContent = saldos.length;
    document.getElementById('kpiAlertas').textContent = alertas.length;
    nvMostrarAlertas(alertas);
    document.getElementById('kpiPerdas').textContent = moeda(data.perdas_30d?.custo_total || 0);
    const scoreEl = document.getElementById('kpiScore');
    if (scoreEl) scoreEl.textContent = score != null ? (String(score) + '/100') : '�';
    const cobEl = document.getElementById('kpiCobertura');
    if (cobEl) cobEl.textContent = fmtCobertura(insights.dias_cobertura_media);
    const valorEl = document.getElementById('kpiValorStock');
    if (valorEl) valorEl.textContent = moeda(insights.valor_stock || 0);
    const semFichaProEl = document.getElementById('kpiSemFichaPro');
    if (semFichaProEl) semFichaProEl.textContent = String(semFicha);

    const kpiInsumosSub = document.getElementById('kpiInsumosSub');
    if (kpiInsumosSub) {
        if (!ESTOQUE_EMP_COCKPIT) {
            kpiInsumosSub.textContent = alertas.length ? (alertas.length + ' alerta(s)') : 'sem urgências';
        } else {
            kpiInsumosSub.textContent = criticos > 0
                ? (criticos + ' crítico(s) na previsão')
                : (sugestoes.length ? (sugestoes.length + ' a repor') : 'sem urgências');
        }
    }
    const kpiScoreSub = document.getElementById('kpiScoreSub');
    if (kpiScoreSub) {
        const lotesRisco = Number(insights.lotes_em_risco || 0);
        kpiScoreSub.textContent = resumoSaudeEstoque(score, criticos, semFicha, lotesRisco, insights.dias_cobertura_media);
    }
    const kpiPerdasSub = document.getElementById('kpiPerdasSub');
    if (kpiPerdasSub) {
        const n = Number(data.perdas_30d?.total_registros || 0);
        kpiPerdasSub.textContent = n ? (n + ' registo(s)') : 'sem perdas registadas';
    }

    // Cartões simples de estado (Profissional)
    if (!ESTOQUE_EMP_COCKPIT) {
        var criticosPro = 0, validadePro = 0, semCustoPro = 0;
        saldos.forEach(function (sl) {
            var saldo = Number(sl.saldo_base || 0);
            var minimo = Number(sl.estoque_minimo_base || 0);
            if (minimo > 0 && saldo <= minimo) criticosPro++;
            var dias = sl.dias_validade;
            if (dias !== null && dias !== undefined && Number(dias) <= 7) validadePro++;
            if (Number(sl.custo_medio || 0) <= 0) semCustoPro++;
        });
        var porChip = function (id, valor, tomAviso) {
            var el = document.getElementById(id);
            if (!el) return;
            var b = el.querySelector('b');
            if (b) b.textContent = String(valor);
            el.classList.remove('is-ok', 'is-warn', 'is-crit');
            el.classList.add(valor > 0 ? tomAviso : 'is-ok');
        };
        porChip('chipCriticosPro', criticosPro, 'is-crit');
        porChip('chipValidadePro', validadePro, 'is-warn');
        porChip('chipSemCustoPro', semCustoPro, 'is-warn');
        porChip('chipAlertasPro', alertas.length, 'is-warn');
    }

    // Strip inteligente (Visão)
    const setCardTone = (id, tone) => {
        const el = document.getElementById(id);
        if (!el) return;
        el.classList.remove('is-ok', 'is-warn', 'is-crit');
        if (tone) el.classList.add(tone);
    };
    const elCrit = document.getElementById('intelCriticosVal');
    if (elCrit) elCrit.textContent = String(criticos);
    setCardTone('intelCriticos', criticos > 0 ? 'is-crit' : 'is-ok');
    const elRep = document.getElementById('intelReposicaoVal');
    if (elRep) elRep.textContent = moeda(insights.custo_reposicao_sugerida || 0);
    const elRepHint = document.getElementById('intelReposicaoHint');
    if (elRepHint) elRepHint.textContent = sugestoes.length + ' linha(s) de compra';
    setCardTone('intelReposicao', sugestoes.length ? (criticos > 0 ? 'is-crit' : 'is-warn') : 'is-ok');
    const elSF = document.getElementById('intelSemFichaVal');
    if (elSF) elSF.textContent = String(semFicha);
    setCardTone('intelSemFicha', semFicha > 0 ? 'is-warn' : 'is-ok');
    const elLotesRisco = document.getElementById('intelLotesRiscoVal');
    if (elLotesRisco) elLotesRisco.textContent = String(Number(insights.lotes_em_risco || 0));
    setCardTone('intelLotesRisco', Number(insights.lotes_em_risco || 0) > 0 ? 'is-warn' : 'is-ok');
    const elLead = document.getElementById('intelLeadVal');
    if (elLead) elLead.textContent = String(Number(insights.lead_time_dias || 5)) + ' d';
    const elLeadHint = document.getElementById('intelLeadHint');
    if (elLeadHint) elLeadHint.textContent = '+ segurança · perfil semanal';

    const scoreBreakdown = document.getElementById('empScoreBreakdown');
    if (scoreBreakdown && ESTOQUE_EMP_COCKPIT) {
        const motivos = Array.isArray(insights.motivos_top) ? insights.motivos_top : [];
        if (motivos.length) {
            scoreBreakdown.innerHTML = '· score <strong>' + (score != null ? score : '—') + '</strong>: '
                + motivos.slice(0, 2).map(m => txt(m.motivo || '')).filter(Boolean).join(' · ');
        } else {
            scoreBreakdown.innerHTML = '· score <strong>' + (score != null ? score : '—') + '</strong> estável';
        }
    }

    // Prontidão (Cadastro)
    const badge = document.getElementById('readinessScoreBadge');
    if (badge && ESTOQUE_EMP_COCKPIT) badge.textContent = 'Score ' + (score != null ? score : '—');
    const setReady = (id, html) => { const el = document.getElementById(id); if (el) el.innerHTML = html; };
    setReady('readyInsumos', '<strong>' + saldos.length + '</strong>');
    setReady('readySemFicha', semFicha > 0
        ? ('<strong class="text-warning">' + semFicha + '</strong><span class="mini">completar fichas</span>')
        : ('<strong class="text-success">0</strong><span class="mini">todas cobertas</span>'));
    if (ESTOQUE_EMP_COCKPIT) {
        setReady('readyCriticos', criticos > 0
            ? ('<strong class="text-danger">' + criticos + '</strong><span class="mini">repor já</span>')
            : ('<strong class="text-success">0</strong><span class="mini">estável</span>'));
        setReady('readySugestoes', '<strong>' + sugestoes.length + '</strong><span class="mini">' + moeda(insights.custo_reposicao_sugerida || 0) + '</span>');
    }
    setReady('readyValor', '<strong>' + moeda(insights.valor_stock || 0) + '</strong>');
    const readyNote = document.getElementById('readinessNote');
    if (readyNote) {
        if (!ESTOQUE_EMP_COCKPIT) {
            if (semFicha > 0) {
                readyNote.textContent = semFicha + ' prato(s) sem ficha — complete as fichas para a baixa automática nas vendas.';
            } else if (alertas.length > 0) {
                readyNote.textContent = alertas.length + ' alerta(s) aberto(s). Verifique validade e saldos nos movimentos.';
            } else {
                readyNote.textContent = 'Fichas cobertas. Continue a registar entradas com validade para manter o stock correcto.';
            }
        } else if (semFicha > 0 && criticos > 0) {
            readyNote.textContent = 'Prioridade: fechar ' + semFicha + ' ficha(s) e repor ' + criticos + ' crítico(s). Sem isso a previsão e a baixa automática ficam enviesadas.';
        } else if (semFicha > 0) {
            readyNote.textContent = semFicha + ' prato(s) activo(s) sem ficha — a baixa por venda e o consumo médio ficam incompletos.';
        } else if (criticos > 0) {
            readyNote.textContent = criticos + ' insumo(s) em risco crítico segundo a previsão de 28 dias. Use “Aplicar” na ordem de reposição.';
        } else {
            readyNote.textContent = 'Operação estável: fichas cobertas e sem críticos previstos. Continuar a registar entradas com validade para manter o FIFO.';
        }
    }
    const fichaIntro = document.getElementById('fichaIntroStats');
    if (fichaIntro) {
        fichaIntro.innerHTML = semFicha > 0
            ? ('· <strong>' + semFicha + '</strong> prato(s) ainda sem ficha')
            : '· todas as fichas activas preenchidas';
    }

    const panelInsights = document.getElementById('panelInsights');
    const tblSug = document.getElementById('tblSugestoes');
    const custoRep = document.getElementById('insightCustoReposicao');
    const noteFicha = document.getElementById('insightSemFichaNote');
    if (panelInsights && tblSug) {
        if (sugestoes.length) {
            panelInsights.style.display = '';
            if (custoRep) custoRep.textContent = 'Reposição estimada: ' + moeda(insights.custo_reposicao_sugerida || 0);
            tblSug.innerHTML = sugestoes.map(s => {
                const [stClass, stLabel] = riscoChip(s.risco);
                const saz = Number(s.fator_sazonal || 1);
                const tend = String(s.tendencia || '');
                const sazHint = saz > 1.05 ? (' · saz. ×' + saz.toFixed(2)) : '';
                const tendHint = tend === 'ALTA' ? ' · tendência ↑' : (tend === 'BAIXA' ? ' · tendência ↓' : '');
                const motivo = txt(s.motivo_risco || '—');
                const mediaDisplay = s.media_prevista_diaria != null ? s.media_prevista_diaria : s.media_diaria;
                const podeAplicar = compraRelevante(s.sugestao_compra_base, s.unidade_base);
                const acaoCompra = podeAplicar
                    ? `<button type="button" class="btn btn-sm btn-primary" onclick="aplicarSugestaoCompra(${Number(s.insumo_id)}, ${Number(s.sugestao_compra_base)}, '${txt(s.unidade_base)}')">
                            <i class="fas fa-cart-plus me-1"></i>Aplicar
                        </button>`
                    : '<span class="text-muted small">Stock suficiente</span>';
                return `<tr>
                    <td>${txt(s.nome || ('#' + s.insumo_id))}<div class="text-muted small">mín. sug. ${fmtQtd(s.minimo_sugerido_base, s.unidade_base)}${sazHint}${tendHint}</div></td>
                    <td class="mono">${fmtCoberturaInsumo(s.dias_cobertura, mediaDisplay, s.saldo_base)}</td>
                    <td class="mono">${fmtQtdConsumo(mediaDisplay, s.unidade_base)}</td>
                    <td class="mono">${fmtQtdConsumo(s.previsao_7_dias, s.unidade_base)}</td>
                    <td class="mono fw-bold">${fmtCompraSugerida(s.sugestao_compra_base, s.unidade_base)}</td>
                    <td class="mono">${Number(s.custo_estimado_compra) > 0 ? moeda(s.custo_estimado_compra) : '<span class="text-muted" title="Sem custo registado nas entradas">—</span>'}</td>
                    <td><span class="status-chip ${stClass}">${stLabel}</span></td>
                    <td class="small" style="max-width:180px;">${motivo}</td>
                    <td>${acaoCompra}</td>
                </tr>`;
            }).join('');
        } else {
            panelInsights.style.display = '';
            if (custoRep) custoRep.textContent = 'Sem reposição urgente';
            tblSug.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-3">Nenhuma compra sugerida — cobertura OK face ao lead time e ao perfil semanal.</td></tr>';
        }
    }
    if (noteFicha) {
        noteFicha.innerHTML = semFicha > 0
            ? (`${semFicha} produto(s) sem ficha técnica — <button type="button" class="btn btn-link btn-sm p-0 m-0 align-baseline border-0" style="vertical-align:baseline" data-goto-tab="fichas">abrir Fichas</button>. Sem ficha a baixa automática e a previsão ficam incompletas.`)
            : 'Motor Emp: média por dia da semana + EWMA 7d, horizonte lead+segurança, validade FIFO e custo médio × qtd.';
    }

    const rede = Array.isArray(data.sugestoes_transferencia)
        ? data.sugestoes_transferencia
        : (Array.isArray(insights.sugestoes_transferencia) ? insights.sugestoes_transferencia : []);
    const panelRede = document.getElementById('panelRedeTransferencias');
    const tblRede = document.getElementById('tblRedeTransferencias');
    if (panelRede && tblRede) {
        if (ESTOQUE_EMP_COCKPIT && rede.length) {
            panelRede.style.display = '';
            tblRede.innerHTML = rede.map(r => `<tr>
                <td>${txt(r.insumo_nome)}<div class="text-muted small">SKU ${txt(r.sku || '')}</div></td>
                <td>${txt(r.origem_nome)}</td>
                <td>${txt(r.destino_nome)}</td>
                <td class="mono">${fmtQtd(r.quantidade_base, r.unidade_base)}</td>
                <td class="small">${txt(r.motivo || '')}</td>
            </tr>`).join('');
        } else {
            panelRede.style.display = 'none';
            tblRede.innerHTML = '';
        }
    }

    document.getElementById('tblSaldos').innerHTML = saldos.map(s => {
        const p = prevMap.get(Number(s.insumo_id));
        const risco = s.risco || (p && p.risco) || 'OK';
        const [stClass, stLabel] = riscoChip(risco);
        const diasCob = s.dias_cobertura != null ? s.dias_cobertura : (p ? p.dias_cobertura : null);
        const minSug = s.minimo_sugerido_base != null ? s.minimo_sugerido_base : (p ? p.minimo_sugerido_base : s.estoque_minimo_base);
        const progressValue = Math.min(100, Math.max(0, Math.round(((Number(s.saldo_base) || 0) / Math.max(Number(minSug) || 1, 1)) * 100)));
        const crit = risco === 'CRITICO';
        const baixo = risco === 'BAIXO' || risco === 'ATENCAO';
        const mediaDia = p ? fmtQtdConsumo(p.media_diaria, s.unidade_base) : '—';
        return `<tr>
            <td>${txt(s.nome)}<div class="text-muted small">SKU: ${txt(s.sku || '-')} · mín. sug. ${fmtQtd(minSug, s.unidade_base)} · média/d ${mediaDia}</div></td>
            <td class="mono">${fmtQtd(s.saldo_base, s.unidade_base)}</td>
            <td class="mono">${fmtQtd(s.estoque_minimo_base, s.unidade_base)}</td>
            <td>${fmtValidadeSaldo(s.validade_proxima, s.dias_validade)}</td>
            <td class="mono">${fmtCoberturaInsumo(diasCob, p ? p.media_diaria : null, s.saldo_base)}</td>
            <td class="mono">${p ? fmtQtdConsumo(p.previsao_7_dias, s.unidade_base) : fmtQtd(0, s.unidade_base)}</td>
            <td class="progress-cell">
                <div class="d-flex align-items-center gap-2">
                    <span class="status-chip ${stClass}">${stLabel}</span>
                </div>
                <div class="progress mt-2" style="height: 8px; border-radius: 999px; background:#eef2f5;">
                    <div class="progress-bar progress-bar-custom" role="progressbar" style="width:${progressValue}%; background: ${crit ? '#ef4444' : baixo ? '#f59e0b' : '#10b981'};" aria-valuenow="${progressValue}" aria-valuemax="100"></div>
                </div>
            </td>
            <td class="d-flex gap-1">
                <button class="btn btn-sm btn-outline-primary" onclick="editarInsumo(${Number(s.insumo_id)})" title="Editar"><i class="fas fa-pen"></i></button>
                <button class="btn btn-sm btn-outline-success" onclick="aplicarSugestaoCompra(${Number(s.insumo_id)}, ${Number((p && p.sugestao_compra_base) || Math.max(0, (Number(minSug)||0) - (Number(s.saldo_base)||0)))}, '${txt(s.unidade_base)}')" title="Preparar entrada"><i class="fas fa-cart-plus"></i></button>
                <button class="btn btn-sm btn-outline-danger" onclick="excluirInsumo(${Number(s.insumo_id)})" title="Excluir"><i class="fas fa-trash"></i></button>
            </td>
        </tr>`;
    }).join('') || '<tr><td colspan="8" class="text-center text-muted">Sem dados</td></tr>';

    document.getElementById('tblAlertas').innerHTML = alertas.map(a => `<tr>
        <td><span class="badge ${a.severidade==='CRITICA'?'text-bg-danger':(a.severidade==='ALTA'?'text-bg-warning':'text-bg-secondary')}">${txt(a.severidade)}</span></td>
        <td>${txt(nvTipoAlerta(a.tipo))}</td><td>${txt(a.titulo)}</td><td>${txt(a.detalhe || '')}</td>
        <td><button type="button" class="btn btn-sm btn-outline-success" onclick="resolverAlerta(${Number(a.id)})" title="Resolver"><i class="fas fa-check"></i></button></td>
    </tr>`).join('') || '<tr><td colspan="5" class="text-center text-muted">Sem alertas</td></tr>';

    const lotes = Array.isArray(data.lotes_validade) ? data.lotes_validade : [];
    const panelLotes = document.getElementById('panelLotesValidade');
    const tblLotes = document.getElementById('tblLotesValidade');
    if (panelLotes && tblLotes) {
        if (lotes.length) {
            panelLotes.style.display = '';
            tblLotes.innerHTML = lotes.map(l => {
                const dias = Number(l.dias_restantes);
                const estado = String(l.estado || '');
                const chip = estado === 'VENCIDO' || estado === 'CRITICO' ? 'critical' : (estado === 'PROXIMO' ? 'warning' : 'ok');
                const label = estado === 'VENCIDO'
                    ? ('Vencido há ' + Math.abs(dias) + 'd')
                    : (dias === 0 ? 'Vence hoje' : ('Vence em ' + dias + 'd'));
                const podeDescartar = estado === 'VENCIDO' || estado === 'CRITICO' || estado === 'PROXIMO';
                return `<tr>
                    <td>${txt(l.insumo_nome)}<div class="text-muted small">${txt(l.sku || '')}</div></td>
                    <td class="mono">${fmtQtd(l.saldo_base, l.unidade_base)}</td>
                    <td class="mono">${txt(l.validade || '')}</td>
                    <td><span class="status-chip ${chip}">${label}</span></td>
                    <td>
                        ${podeDescartar
                            ? `<button type="button" class="btn btn-sm btn-outline-danger" onclick="descartarLote(${Number(l.lote_id)})" title="Registar perda e zerar este lote"><i class="fas fa-trash-can me-1"></i>Descartar</button>`
                            : '<span class="text-muted small">—</span>'}
                    </td>
                </tr>`;
            }).join('');
        } else {
            panelLotes.style.display = 'none';
            tblLotes.innerHTML = '';
        }
    }
}

async function descartarLote(loteId) {
    const id = Number(loteId || 0);
    if (!(id > 0)) return;
    if (!(await nivoraConfirmar({ mensagem: 'Descartar este lote?\n\nO saldo deste lote será registado como perda e sai do stock.', confirmar: 'Descartar', perigo: true }))) return;
    const fd = new FormData();
    fd.append('lote_id', String(id));
    fd.append('motivo', 'Descarte de lote vencido/proximo do vencimento');
    fd.append('_csrf', CSRF);
    try {
        const r = await fetchJson('api/estoque_lote_descartar.php', { method: 'POST', body: fd });
        NivoraFeedback.show(r.message || (r.success ? 'Lote descartado.' : 'Não foi possível descartar o lote.'), r.success ? 'success' : 'error');
        if (r.success) await carregarBase();
    } catch (e) {
        NivoraFeedback.error(e.message || 'Erro ao descartar lote.');
    }
}
window.descartarLote = descartarLote;


/* Alertas: o numero no menu e no cartao leva diretamente a lista (antes ficava no fundo da pagina). */
function nvTipoAlerta(t) {
    var m = { REPOSICAO: 'Reposição', VALIDADE: 'Validade', CONSUMO_ANOMALO: 'Consumo anómalo' };
    t = String(t || '');
    return m[t] || (t.charAt(0) + t.slice(1).toLowerCase().replace(/_/g, ' '));
}
function nvMostrarAlertas(alertas) {
    var lista = Array.isArray(alertas) ? alertas : [];
    var sub = document.getElementById('kpiAlertasSub');
    if (sub) {
        var cont = {};
        lista.forEach(function (a) { var k = nvTipoAlerta(a.tipo).toLowerCase(); cont[k] = (cont[k] || 0) + 1; });
        var partes = Object.keys(cont).map(function (k) { return cont[k] + ' ' + k; });
        sub.textContent = partes.length ? partes.join(' · ') + ' — ver lista' : 'nada pendente';
    }
    var conta = document.getElementById('painelAlertasConta');
    if (conta) { conta.hidden = !lista.length; conta.textContent = String(lista.length); }
    var painel = document.getElementById('painelAlertas');
    if (!painel) { return; }
    var pane = painel.closest('.estoque-tab-pane');
    if (!pane) { return; }
    // Com alertas abertos, a lista sobe para o topo do separador; sem alertas fica no fundo.
    if (lista.length) {
        var antes = pane.children[1] || null;
        if (antes !== painel) { pane.insertBefore(painel, antes); }
    } else if (pane.lastElementChild !== painel) {
        pane.appendChild(painel);
    }
    if (location.hash === '#alertas' && !window.__nvAlertasVisto && lista.length) {
        window.__nvAlertasVisto = true;
        setTimeout(nvIrParaAlertas, 150);
    }
}
function nvIrParaAlertas() {
    var tab = document.querySelector('[data-estoque-tab="insights"]');
    if (tab && !tab.classList.contains('is-active')) { tab.click(); }
    var painel = document.getElementById('painelAlertas');
    if (painel) {
        painel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        painel.classList.add('nv-destaque');
        setTimeout(function () { painel.classList.remove('nv-destaque'); }, 1800);
    }
}
(function () {
    var card = document.getElementById('kpiAlertasCard');
    if (card) {
        card.addEventListener('click', nvIrParaAlertas);
        card.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); nvIrParaAlertas(); } });
    }
    window.addEventListener('hashchange', function () { if (location.hash === '#alertas') { nvIrParaAlertas(); } });
    var st = document.createElement('style');
    st.textContent = '#painelAlertas.nv-destaque{box-shadow:0 0 0 3px rgba(245,158,11,.55);transition:box-shadow .3s}' +
        '#confirmResolverTodosAlertas[hidden]{display:none!important}';
    document.head.appendChild(st);
})();
