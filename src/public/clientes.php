<?php
require_once __DIR__ . '/app_base.php';

include_once APP_BASE . '/src/config/auth_check.php';
include_once APP_BASE . '/src/config/plano_check.php';
include_once APP_BASE . '/src/config/restaurante_context.php';
include_once APP_BASE . '/src/config/csrf.php';

$restauranteId = session_restaurante_contexto_id();
if ($restauranteId <= 0) {
    header('Location: index.php?erro=sem_restaurante');
    exit;
}

$planoRid = session_restaurante_capability_id();
$planoRid = $planoRid > 0 ? $planoRid : $restauranteId;
$temClientes = plano_tem_funcionalidade_db($planoRid, 'clientes');
$temHistoricoClientes = plano_tem_funcionalidade_db($planoRid, 'historico_clientes');
if (!$temClientes && !$temHistoricoClientes) {
    header('Location: dashboard.php?erro=funcionalidade_bloqueada&funcionalidade=clientes');
    exit;
}

$csrfToken = csrf_get_token();
$dadosPlanoClientes = plano_get_dados($planoRid);
$plano_atual = function_exists('plano_normalizar_nome')
    ? plano_normalizar_nome($dadosPlanoClientes['plano_nome'] ?? 'BASICO')
    : strtoupper((string)($dadosPlanoClientes['plano_nome'] ?? 'BASICO'));
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <script src="js/botao_atualizar.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/botao_atualizar.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $temHistoricoClientes ? 'Clientes e Histórico' : 'Clientes'; ?> - RestauranteSaaS</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/premium.css">
    <style>
        body { font-family: 'Outfit', sans-serif; background: #f4f7fc; color: #0f172a; }
        .main-content { margin-left: 280px; min-height: 100vh; }
        .topbar { background: #fff; border-bottom: 1px solid #dbe3ef; padding: 16px 22px; display: flex; align-items: center; justify-content: space-between; gap: 12px; position: sticky; top: 0; z-index: 5; }
        .topbar h1 { margin: 0; font-size: 22px; font-family: 'Space Grotesk', sans-serif; font-weight: 700; }
        .content-area { padding: 18px; }
        .panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; box-shadow: 0 18px 30px rgba(15, 23, 42, 0.06); }
        .panel-head { padding: 14px 18px; border-bottom: 1px solid #eef2f5; font-weight: 700; }
        .panel-body { padding: 16px 18px; }
        .table th { font-size: 12px; text-transform: uppercase; color: #64748b; letter-spacing: .06em; }
        .badge-soft { background: #eef2ff; color: #334155; border-radius: 999px; padding: 4px 10px; font-size: 12px; }
        @media (max-width: 991px) { .main-content { margin-left: 0; } }
    </style>
</head>
<body class="premium-ui">
<input type="hidden" id="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
<div class="container-fluid">
    <div class="row">
        <?php include_once __DIR__ . '/includes/sidebar.php'; ?>
        <main class="main-content col-md-9 ms-sm-auto col-lg-10">
            <div class="topbar">
                <div>
                    <h1><i class="fas fa-address-book text-primary me-2"></i><?php echo $temHistoricoClientes ? 'Clientes e Histórico' : 'Clientes'; ?> <?php include __DIR__ . '/includes/plan_page_badge.php'; ?></h1>
                    <?php if (($plano_atual ?? '') === 'EMPRESARIAL'): ?>
                        <div class="text-muted small">Histórico de clientes da unidade — útil para rede e campanhas locais.</div>
                    <?php elseif ($temHistoricoClientes): ?>
                        <div class="text-muted small">Visitas, compras e frequência na unidade (Profissional).</div>
                    <?php else: ?>
                        <div class="text-muted small">Lista de clientes. Histórico completo disponível no plano Profissional.</div>
                    <?php endif; ?>
                </div>
                <button class="btn btn-dark btn-sm" id="btnAtualizar" data-atualizar><i class="fas fa-rotate me-1"></i>Atualizar</button>
            </div>
            <div class="content-area">
                <div class="panel mb-3">
                    <div class="panel-body">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label small mb-1">Filtrar por tipo</label>
                                <select class="form-select form-select-sm" id="filtroTipo">
                                    <option value="todos">Todos</option>
                                    <option value="comum">Comum</option>
                                    <option value="vip">VIP</option>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small mb-1">Buscar</label>
                                <input type="search" class="form-control form-control-sm" id="filtroBusca" placeholder="Nome, telefone ou email">
                            </div>
                            <div class="col-md-3">
                                <button class="btn btn-outline-primary btn-sm w-100" id="btnFiltrar">Aplicar</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-head d-flex justify-content-between align-items-center">
                        <span>Lista de clientes</span>
                        <span class="badge-soft" id="totalClientes">0</span>
                    </div>
                    <div class="panel-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th>Nome</th>
                                        <th>Contacto</th>
                                        <th>Tipo</th>
                                        <th>Última visita</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="tbodyClientes">
                                    <tr><td colspan="5" class="text-center text-muted py-4">A carregar...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<div class="modal fade" id="modalHistorico" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalHistoricoTitulo">Histórico do cliente</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body" id="modalHistoricoBody">
                <div class="text-muted">A carregar...</div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
const CSRF = document.getElementById('csrf_token').value;
const TEM_HISTORICO_CLIENTES = <?php echo !empty($temHistoricoClientes) ? 'true' : 'false'; ?>;
let cacheClientes = [];
const modalEl = document.getElementById('modalHistorico');
const modal = TEM_HISTORICO_CLIENTES && modalEl ? new bootstrap.Modal(modalEl) : null;

async function fetchJson(url, opts = {}) {
    const res = await fetch(url, opts);
    const text = await res.text();
    let data = {};
    try { data = text ? JSON.parse(text) : {}; } catch (_) {
        throw new Error(res.ok ? 'Resposta invalida do servidor.' : `Erro HTTP ${res.status}`);
    }
    if (!res.ok || data.success === false) {
        throw new Error(data.message || `Pedido falhou (HTTP ${res.status})`);
    }
    return data;
}

function esc(v) {
    return String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function fmtData(v) {
    if (v == null || v === '' || v === '-' || String(v).startsWith('1000-')) return '-';
    const s = String(v).trim();
    const m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (m) return `${m[3]}/${m[2]}/${m[1]}`;
    return s;
}

function fmtHora(v) {
    if (v == null || v === '') return '';
    const s = String(v);
    return s.length >= 5 ? s.slice(0, 5) : s;
}

function fmtMoney(v) {
    const n = Number(v);
    if (!Number.isFinite(n)) return '-';
    return n.toLocaleString('pt-MZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' MT';
}

function renderLista(lista) {
    const tbody = document.getElementById('tbodyClientes');
    document.getElementById('totalClientes').textContent = String(lista.length);
    if (!lista.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">Nenhum cliente encontrado.</td></tr>';
        return;
    }
    tbody.innerHTML = lista.map(c => `
        <tr>
            <td><strong>${esc(c.nome)}</strong></td>
            <td>
                <div>${esc(c.telefone || '-')}</div>
                <div class="small text-muted">${esc(c.email || '')}</div>
            </td>
            <td><span class="badge-soft">${esc((c.tipo_cliente || 'comum').toUpperCase())}</span></td>
            <td>${esc(fmtData(c.data_ultima_visita))}</td>
            <td class="text-end">
                ${TEM_HISTORICO_CLIENTES
                    ? `<button class="btn btn-sm btn-outline-primary" data-id="${esc(c.id)}">Histórico</button>`
                    : `<span class="text-muted small">Histórico no Profissional</span>`}
            </td>
        </tr>
    `).join('');
    if (TEM_HISTORICO_CLIENTES) {
        tbody.querySelectorAll('button[data-id]').forEach(btn => {
            btn.addEventListener('click', () => abrirHistorico(Number(btn.dataset.id)));
        });
    }
}

async function carregarClientes() {
    const tipo = document.getElementById('filtroTipo').value;
    const data = await fetchJson(`api/clientes.php?tipo=${encodeURIComponent(tipo)}&limite=100`);
    cacheClientes = Array.isArray(data.data) ? data.data : [];
    aplicarFiltro();
}

function aplicarFiltro() {
    const q = document.getElementById('filtroBusca').value.trim().toLowerCase();
    const filtrados = !q ? cacheClientes : cacheClientes.filter(c => {
        const blob = `${c.nome || ''} ${c.telefone || ''} ${c.email || ''}`.toLowerCase();
        return blob.includes(q);
    });
    renderLista(filtrados);
}

async function abrirHistorico(clienteId) {
    if (!TEM_HISTORICO_CLIENTES || !modal) {
        NivoraFeedback.warning('Histórico de clientes disponível a partir do plano Profissional.');
        return;
    }
    document.getElementById('modalHistoricoTitulo').textContent = 'Histórico do cliente';
    document.getElementById('modalHistoricoBody').innerHTML = '<div class="text-muted">A carregar...</div>';
    modal.show();
    try {
        const [detalhe, reservas] = await Promise.all([
            fetchJson(`api/clientes.php?route=${clienteId}`),
            fetchJson(`api/historico_cliente.php?cliente_id=${clienteId}`).catch(() => null)
        ]);
        const c = detalhe.cliente || {};
        const stats = detalhe.estatisticas || {};
        const comprasApi = Array.isArray(reservas?.compras) ? reservas.compras : [];
        const comprasDetalhe = Array.isArray(detalhe.historico_compras) ? detalhe.historico_compras : [];
        const compras = comprasApi.length ? comprasApi : comprasDetalhe;
        const freq = Array.isArray(reservas?.frequencia) ? reservas.frequencia : [];
        const reservasRecentes = Array.isArray(reservas?.reservas_recentes) ? reservas.reservas_recentes : [];
        const ultima = reservas?.data_ultima_visita || c.data_ultima_visita || stats.data_ultima_visita;
        document.getElementById('modalHistoricoTitulo').textContent = c.nome || 'Cliente';
        document.getElementById('modalHistoricoBody').innerHTML = `
            <div class="row g-3 mb-3">
                <div class="col-md-3"><div class="border rounded-3 p-3"><div class="small text-muted">Score</div><strong>${esc(detalhe.score_cliente ?? '-')}</strong></div></div>
                <div class="col-md-3"><div class="border rounded-3 p-3"><div class="small text-muted">Reservas</div><strong>${esc(reservas?.total_reservas ?? stats.total_reservas ?? 0)}</strong></div></div>
                <div class="col-md-3"><div class="border rounded-3 p-3"><div class="small text-muted">Última visita</div><strong>${esc(fmtData(ultima))}</strong></div></div>
                <div class="col-md-3"><div class="border rounded-3 p-3"><div class="small text-muted">Contacto</div><strong>${esc(c.telefone || c.email || '-')}</strong></div></div>
            </div>
            <h6 class="fw-bold">Reservas recentes</h6>
            ${reservasRecentes.length ? `<div class="table-responsive mb-3"><table class="table table-sm"><thead><tr><th>Data</th><th>Hora</th><th>Pessoas</th><th>Mesa</th><th>Estado</th></tr></thead><tbody>
                ${reservasRecentes.map(r => `<tr>
                    <td>${esc(fmtData(r.data_reserva))}</td>
                    <td>${esc(fmtHora(r.hora_reserva))}</td>
                    <td>${esc(r.quantidade_pessoas ?? '-')}</td>
                    <td>${esc(r.mesa_numero ?? r.mesa_atribuida ?? '-')}</td>
                    <td>${esc(r.status || '-')}</td>
                </tr>`).join('')}
            </tbody></table></div>` : '<p class="text-muted small">Sem reservas registadas para este contacto.</p>'}
            <h6 class="fw-bold">Últimas compras</h6>
            ${compras.length ? `<div class="table-responsive mb-3"><table class="table table-sm"><thead><tr><th>Data</th><th>Valor</th><th>Pontos</th></tr></thead><tbody>
                ${compras.map(h => `<tr>
                    <td>${esc(fmtData(h.data_compra || h.created_at))}</td>
                    <td>${esc(fmtMoney(h.valor_total ?? h.total))}</td>
                    <td>${esc(h.pontos_ganhos ?? '-')}</td>
                </tr>`).join('')}
            </tbody></table></div>` : '<p class="text-muted small">Sem compras ligadas ao CRM (as vendas do PDV ainda não associam cliente automaticamente).</p>'}
            <h6 class="fw-bold">Frequência de reservas</h6>
            ${freq.length ? `<ul class="mb-0">${freq.map(f => `<li>${esc(f.mes)}: <strong>${esc(f.qtd)}</strong></li>`).join('')}</ul>` : '<p class="text-muted small mb-0">Sem frequência mensal.</p>'}
        `;
    } catch (e) {
        document.getElementById('modalHistoricoBody').innerHTML = `<div class="alert alert-danger mb-0">${esc(e.message || 'Erro')}</div>`;
    }
}

document.getElementById('btnAtualizar').addEventListener('click', () => carregarClientes().catch(e => NivoraFeedback.error(e.message)));
document.getElementById('btnFiltrar').addEventListener('click', aplicarFiltro);
document.getElementById('filtroBusca').addEventListener('keyup', (e) => { if (e.key === 'Enter') aplicarFiltro(); });
carregarClientes().catch(e => {
    document.getElementById('tbodyClientes').innerHTML = `<tr><td colspan="5" class="text-center text-danger py-4">${esc(e.message)}</td></tr>`;
});
</script>
</body>
</html>
