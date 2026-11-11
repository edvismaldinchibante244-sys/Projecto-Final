// Estoque inteligente - Valores do servidor, pedidos a API, formatacao, unidades, carregamento e alertas.
// Parte de estoque_inteligente.php; carregado por ordem com os outros ficheiros de js/estoque_inteligente/.

// Valores do servidor: <script type="application/json" id="cfg-estoque_inteligente"> na pagina.
var __NIVORA_ESTOQUE_INTELIGENTE = JSON.parse(document.getElementById('cfg-estoque_inteligente').textContent);
const CSRF = document.getElementById('csrf_token')?.value || '';
const H = CSRF ? {'X-CSRF-Token': CSRF} : {};
const ESTOQUE_EMP_COCKPIT = __NIVORA_ESTOQUE_INTELIGENTE.v0;
const ESTOQUE_DEFAULT_TAB = __NIVORA_ESTOQUE_INTELIGENTE.v1;
let cacheInsumos = [];
let cacheProdutos = [];
let cacheSaldos = [];
let cacheInsights = {};
let cachePrevisao = [];
let fichaItens = [];
let editInsumoId = 0;
let fichaDirty = false;
let fichaLoading = false;

async function fetchJson(url, opts = {}) {
    // APIs de stock são dinâmicas; evita que o navegador reutilize KPIs/alertas
    // antigos depois de resolver um alerta ou registrar um movimento.
    const separator = url.includes('?') ? '&' : '?';
    const freshUrl = url + separator + '_ts=' + Date.now();
    const r = await fetch(freshUrl, {
        credentials: 'same-origin',
        ...opts,
        headers: {...(opts.headers || {}), ...H}
    });
    const text = await r.text();
    let data = null;
    try { data = JSON.parse(text); } catch (e) {}
    if (!r.ok) {
        const msg = (data && data.message) ? data.message : (`HTTP ${r.status}`);
        throw new Error(msg);
    }
    if (!data || typeof data !== 'object') {
        throw new Error('Resposta inválida da API.');
    }
    return data;
}

function moeda(v){ return (Number(v)||0).toLocaleString('pt-MZ',{minimumFractionDigits:2,maximumFractionDigits:2}) + ' MZN'; }
function txt(s){ return String(s ?? ''); }
function fmtNumeroHumano(v, maxDecimals = 3){
    const n = Number(v || 0);
    const rounded = Math.round(n * Math.pow(10, maxDecimals)) / Math.pow(10, maxDecimals);
    const isInt = Math.abs(rounded - Math.trunc(rounded)) < 0.000001;
    return rounded.toLocaleString('pt-MZ', {
        minimumFractionDigits: 0,
        maximumFractionDigits: isInt ? 0 : maxDecimals
    });
}
function labelUnidade(unBase) {
    const u = String(unBase || 'un').toLowerCase();
    if (u === 'porcao') return 'porção';
    if (u === 'l') return 'litro';
    if (u === 'un') return 'unidade';
    if (u === 'fatia') return 'fatia';
    return u || 'un';
}
function fmtQtd(v, unBase){
    const n = Number(v || 0);
    const u = String(unBase || 'un').toLowerCase();
    if (u === 'g') {
        if (Math.abs(n) >= 1000) return `${fmtNumeroHumano(n / 1000)} kg`;
        return `${fmtNumeroHumano(n)} g`;
    }
    if (u === 'ml') {
        if (Math.abs(n) >= 1000) return `${fmtNumeroHumano(n / 1000)} l`;
        return `${fmtNumeroHumano(n)} ml`;
    }
    return `${fmtNumeroHumano(n)} ${labelUnidade(u)}`;
}
/** Consumo / previsão: deixa claro quando é grama vs kg (evita 40 kg ao lado de “∞”). */
function fmtQtdConsumo(v, unBase){
    const n = Number(v || 0);
    const u = String(unBase || 'un').toLowerCase();
    if (!(n > 0)) return fmtQtd(0, unBase);
    if (u === 'g') {
        if (n >= 1000) return fmtNumeroHumano(n / 1000) + ' kg';
        // Ex.: 1,8 g/d — e equivalente mensal aproximado se for muito pequeno face a stock em kg
        if (n < 10) return fmtNumeroHumano(n, 2) + ' g';
        return fmtNumeroHumano(n, 1) + ' g';
    }
    if (u === 'ml') {
        if (n >= 1000) return fmtNumeroHumano(n / 1000) + ' l';
        if (n < 10) return fmtNumeroHumano(n, 2) + ' ml';
        return fmtNumeroHumano(n, 1) + ' ml';
    }
    return fmtQtd(n, unBase);
}
function compraRelevante(qtdBase, unBase) {
    const n = Number(qtdBase || 0);
    const u = String(unBase || 'un').toLowerCase();
    if (u === 'g' || u === 'ml') return n >= 100;
    if (u === 'un') return n >= 1;
    return n > 0.0001;
}
function fmtCompraSugerida(v, unBase) {
    return compraRelevante(v, unBase) ? fmtQtd(v, unBase) : 'Sem compra';
}
function resumoSaudeEstoque(score, criticos, semFicha, lotesRisco, coberturaDias) {
    const sc = Number(score);
    const crit = Number(criticos || 0);
    const fichas = Number(semFicha || 0);
    const lotes = Number(lotesRisco || 0);
    const cob = Number(coberturaDias || 0);
    if (crit > 0) return crit + ' insumo(s) precisam de compra agora';
    if (fichas > 0) return 'Complete ficha tecnica de ' + fichas + ' prato(s)';
    if (lotes > 0) return lotes + ' lote(s) vencem em breve';
    if (Number.isFinite(sc) && sc < 80) return 'Rever entradas, validade e cobertura';
    if (cob > 0) return 'Estavel por cerca de ' + Math.round(cob).toLocaleString('pt-MZ') + ' dias';
    return 'Sem risco critico no momento';
}
function baseToDisplay(v, unBase) {
    const n = Number(v || 0);
    const u = String(unBase || 'un').toLowerCase();
    if (u === 'g') return n / 1000;
    if (u === 'ml') return n / 1000;
    return n;
}
function baseToInputUnit(unBase) {
    const u = String(unBase || 'un').toLowerCase();
    if (u === 'g') return 'kg';
    if (u === 'ml') return 'l';
    return u || 'un';
}
function unidadesPermitidas(unBase) {
    const u = String(unBase || 'un').toLowerCase();
    // Só unidades da mesma família — evita stock errado na venda (ex.: kg em fatia).
    // Prefere unidade humana primeiro (kg/l); g/ml ficam só para fichas com porções pequenas.
    if (u === 'g') return ['kg', 'g'];
    if (u === 'ml') return ['l', 'ml'];
    if (u === 'kg') return ['kg'];
    if (u === 'l') return ['l'];
    return [u || 'un'];
}
function syncUnidadeSelect(unidadeEl, unBase) {
    if (!unidadeEl) return;
    const opts = unidadesPermitidas(unBase);
    const preferida = baseToInputUnit(unBase);
    const atual = String(unidadeEl.value || '');
    unidadeEl.disabled = false;
    unidadeEl.innerHTML = opts.map(o => `<option value="${o}">${labelUnidade(o)}</option>`).join('');
    if (atual && opts.includes(atual) && unidadeEl.dataset.userTouched === '1') {
        unidadeEl.value = atual;
    } else {
        unidadeEl.value = opts.includes(preferida) ? preferida : opts[0];
    }
}
/** Garante unidade válida do insumo antes de enviar (nunca vazia). */
function unidadeSeguraParaEnvio(insumoId, unidadeSelectId) {
    const el = document.getElementById(unidadeSelectId);
    const base = findInsumoBase(insumoId);
    const opts = unidadesPermitidas(base);
    let u = String(el?.value || '').trim();
    if (!u || !opts.includes(u)) {
        u = baseToInputUnit(base);
        if (el) {
            syncUnidadeSelect(el, base);
            el.value = u;
        }
    }
    return u;
}

function fillSelect(el, list, mapLabel) {
    if (!el) return '';
    const prev = String(el.value || '');
    const esc = (s) => String(s ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    el.innerHTML = '<option value="">Selecione...</option>' + list.map(i => {
        const id = Number(i.id || 0);
        return `<option value="${id}">${esc(mapLabel(i))}</option>`;
    }).join('');
    if (prev && [...el.options].some(o => String(o.value) === prev)) {
        el.value = prev;
    }
    return String(el.value || '');
}

function parseNumeroMz(v) {
    if (v === null || v === undefined) return NaN;
    let s = String(v).trim();
    if (s === '') return NaN;
    // Aceita 1.234,56 ou 1234.56 ou 1234,56
    if (s.includes(',') && s.includes('.')) {
        s = s.replace(/\./g, '').replace(',', '.');
    } else if (s.includes(',')) {
        s = s.replace(',', '.');
    }
    const n = Number(s);
    return Number.isFinite(n) ? n : NaN;
}

async function carregarBase(opts = {}) {
    const keepFichaLocal = !!opts.keepFichaLocal;
    const btnAtualizar = document.getElementById('btnAtualizar');
    if (btnAtualizar) {
        btnAtualizar.classList.add('btn-loading');
        btnAtualizar.disabled = true;
    }

    try {
        let ins = { success: false, data: [] };
        let pro = { success: false, data: [] };
        let errIns = null;
        let errPro = null;

        // 1) Formuarios primeiro — nao esperar o dashboard (pode demorar/travar)
        try { ins = await fetchJson('api/estoque_insumo_listar.php'); } catch (e) { errIns = e; console.error('insumos:', e); }
        try { pro = await fetchJson('api/produto_listar.php'); } catch (e) { errPro = e; console.error('produtos:', e); }

        if (!ins.success) {
            NivoraFeedback.error('Falha ao carregar insumos: ' + (errIns?.message || 'erro desconhecido'));
            return;
        }
        if (!pro.success) {
            console.warn('Produtos nao carregaram:', errPro?.message || pro.message || 'erro');
            NivoraFeedback.error('Nao foi possivel carregar os produtos para a ficha tecnica: ' + (errPro?.message || pro.message || 'sem permissao ou erro de API'));
        }

        cacheInsumos = Array.isArray(ins.data) ? ins.data : [];
        cacheProdutos = pro && pro.success && Array.isArray(pro.data) ? pro.data : [];

        const labelInsumo = i => `${i.nome} · ${i.sku || '—'} (${labelUnidade(i.unidade_base)})`;
        fillSelect(document.getElementById('movInsumo'), cacheInsumos, labelInsumo);
        fillSelect(document.getElementById('perdaInsumo'), cacheInsumos, labelInsumo);
        fillSelect(document.getElementById('fichaInsumo'), cacheInsumos, labelInsumo);
        const trfInsumo = document.getElementById('trfInsumo');
        if (trfInsumo) {
            fillSelect(trfInsumo, cacheInsumos, labelInsumo);
        }
        const produtoSel = fillSelect(document.getElementById('fichaProduto'), cacheProdutos, i => i.nome);
        syncUnidadeFromInsumoSelect('movInsumo', 'movUnidade');
        syncUnidadeFromInsumoSelect('perdaInsumo', 'perdaUnidade');
        syncUnidadeFromInsumoSelect('fichaInsumo', 'fichaUnidade');
        syncUnidadeFromInsumoSelect('trfInsumo', 'trfUnidade');

        if (produtoSel && !keepFichaLocal && !fichaDirty) {
            await carregarFichaProduto();
        } else if (!produtoSel && !fichaDirty) {
            fichaItens = [];
            renderFichaItens();
        } else {
            renderFichaItens();
        }

        // 2) KPIs / sugestoes — falha aqui nao bloqueia stock/ficha
        try {
            const dash = await fetchJson('api/estoque_inteligente_dashboard.php');
            if (dash.success) {
                try { renderDashboard(dash.data || {}); } catch (rdErr) { console.warn('renderDashboard:', rdErr); }
            }
        } catch (errDash) {
            console.warn('Dashboard estoque falhou:', errDash.message || errDash);
        }
    } catch (e) {
        console.error('carregarBase:', e);
        NivoraFeedback.error(e.message || 'Erro ao carregar estoque.');
    } finally {
        if (btnAtualizar) {
            btnAtualizar.classList.remove('btn-loading');
            btnAtualizar.disabled = false;
        }
    }
}

function findInsumoBase(insumoId) {
    const insumo = cacheInsumos.find(i => Number(i.id) === Number(insumoId));
    return insumo ? String(insumo.unidade_base || 'un') : 'un';
}
function findInsumoUnit(insumoId) {
    return baseToInputUnit(findInsumoBase(insumoId));
}
function syncUnidadeFromInsumoSelect(insumoSelectId, unidadeSelectId) {
    const insumoEl = document.getElementById(insumoSelectId);
    const unidadeEl = document.getElementById(unidadeSelectId);
    if (!insumoEl || !unidadeEl) return;
    syncUnidadeSelect(unidadeEl, findInsumoBase(insumoEl.value));
}

function riscoChip(risco) {
    const r = String(risco || 'OK').toUpperCase();
    if (r === 'CRITICO') return ['critical', 'Crítico'];
    if (r === 'BAIXO') return ['warning', 'Baixo'];
    if (r === 'ATENCAO') return ['warning', 'Atenção'];
    return ['ok', 'OK'];
}

function fmtCobertura(dias) {
    const d = Number(dias);
    if (!Number.isFinite(d)) return '—';
    if (d < 1) return '< 1 dia';
    // Backend limita a 999 quando o consumo é quase zero face ao saldo
    if (d >= 900) return '> 2 anos';
    if (d >= 365) return '~' + Math.round(d / 30) + ' meses';
    if (d >= 60) return '~' + Math.round(d / 30) + ' meses';
    return fmtNumeroHumano(d, 1) + ' d';
}

/** Cobertura de um insumo: sem stock / sem consumo registado em vez de "< 1 dia" enganador. */
function fmtCoberturaInsumo(dias, mediaDiaria, saldo) {
    if (saldo != null && Number(saldo) <= 0) return 'Sem stock';
    if (!(Number(mediaDiaria) > 0)) return 'Sem consumo';
    return fmtCobertura(dias);
}

function fmtValidadeSaldo(validade, dias) {
    if (!validade) {
        return '<span class="text-muted small">Sem validade</span>';
    }
    const d = Number(dias);
    let chip = 'ok';
    let label = 'OK';
    if (Number.isFinite(d)) {
        if (d < 0) {
            chip = 'critical';
            label = 'Vencido há ' + Math.abs(d) + 'd';
        } else if (d === 0) {
            chip = 'critical';
            label = 'Vence hoje';
        } else if (d <= 7) {
            chip = 'critical';
            label = 'Vence em ' + d + 'd';
        } else if (d <= 30) {
            chip = 'warning';
            label = 'Vence em ' + d + 'd';
        } else {
            label = 'Vence em ' + d + 'd';
        }
    }
    return `<div class="mono">${txt(validade)}</div><span class="status-chip ${chip}">${label}</span>`;
}

function aplicarSugestaoCompra(insumoId, qtdBase, unidadeBase) {
    ativarAbaEstoque('movimentos');
    const movInsumo = document.getElementById('movInsumo');
    const movTipo = document.getElementById('movTipo');
    const movQtd = document.getElementById('movQtd');
    const movUnidade = document.getElementById('movUnidade');
    const movCusto = document.getElementById('movCusto');
    if (!movInsumo || !movTipo || !movQtd || !movUnidade) return;
    if (movCusto) movCusto.value = '';
    movInsumo.value = String(insumoId);
    movTipo.value = 'ENTRADA';
    movInsumo.dispatchEvent(new Event('change'));
    syncUnidadeSelect(movUnidade, unidadeBase);
    const displayQtd = baseToDisplay(qtdBase, unidadeBase);
    movQtd.value = String(Math.round(displayQtd * 1000) / 1000);
    sugerirCustoMovimento(insumoId);
    // Força custo mesmo se o campo tiver ficado vazio
    const saldo = cacheSaldos.find(s => Number(s.insumo_id) === Number(insumoId));
    const custoBase = Number(saldo?.custo_medio || 0);
    if (movCusto && custoBase > 0) {
        const u = String(unidadeBase || '').toLowerCase();
        const custoHuman = (u === 'g' || u === 'ml') ? (custoBase * 1000) : custoBase;
        movCusto.value = String(Math.round(custoHuman * 100) / 100);
    }
    syncMovValidadeUi();
    setTimeout(() => {
        movQtd.focus();
        document.getElementById('btnSalvarMov')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, 60);
}
window.aplicarSugestaoCompra = aplicarSugestaoCompra;

async function resolverAlerta(alertaId, todos = false) {
    const fd = new FormData();
    fd.append('_csrf', CSRF);
    const botaoConfirmacao = todos ? document.getElementById('btnConfirmarResolverTodosAlertas') : null;
    if (todos) {
        if (botaoConfirmacao?.disabled) return;
        fd.append('resolver_todos', '1');
        if (botaoConfirmacao) {
            botaoConfirmacao.disabled = true;
            botaoConfirmacao.textContent = 'A resolver...';
        }
    } else {
        fd.append('alerta_id', String(alertaId));
    }
    try {
        const r = await fetchJson('api/estoque_alerta_resolver.php', { method: 'POST', body: fd });
        if (!todos) {
            NivoraFeedback.show(r.message || (r.success ? 'Alerta resolvido.' : 'Não foi possível resolver o alerta.'), r.success ? 'success' : 'error');
        } else {
            NivoraFeedback.show(r.message || (r.success ? 'Alertas resolvidos.' : 'Não foi possível resolver os alertas.'), r.success ? 'success' : 'error');
            if (r.success) {
                const confirmacao = document.getElementById('confirmResolverTodosAlertas');
                const botao = document.getElementById('btnResolverTodosAlertas');
                if (confirmacao) confirmacao.hidden = true;
                if (botao) {
                    botao.setAttribute('aria-expanded', 'false');
                    botao.focus();
                }
            }
        }
        await carregarBase();
    } catch (e) {
        NivoraFeedback.error(e.message || 'Erro ao resolver alerta.');
    } finally {
        if (botaoConfirmacao) {
            botaoConfirmacao.disabled = false;
            botaoConfirmacao.textContent = 'Resolver todos';
        }
    }
}
window.resolverAlerta = resolverAlerta;
