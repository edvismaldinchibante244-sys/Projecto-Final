<?php
require_once __DIR__ . '/app_base.php';

/*
  Tela Principal do Garçom – Grid de Mesas
  UI focada em eficiência: 1 clique para abrir/criar pedido.
  Auto-atualiza a cada 8s via AJAX.
 */
include_once APP_BASE . '/src/config/auth_check.php';
include_once APP_BASE . '/src/config/csrf.php';
include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/plano_check.php';
include_once APP_BASE . '/src/config/restaurante_context.php';
$csrfToken = csrf_get_token();


$perfil_raw = strtoupper(trim($_SESSION['perfil'] ?? ''));
if ($perfil_raw === 'GARÇOM') $perfil_raw = 'GARCOM';

// Bloco de diagnóstico temporário para depuração
if (isset($_GET['debug_turno'])) {
    echo '<pre style="background:#222;color:#fff;padding:16px;font-size:16px;">';
    echo "<b>Diagnóstico de Sessão:</b>\n";
    echo 'usuario_id: ' . var_export($_SESSION['usuario_id'] ?? null, true) . "\n";
    echo 'restaurante_id: ' . var_export($_SESSION['restaurante_id'] ?? null, true) . "\n";
    echo 'perfil: ' . var_export($_SESSION['perfil'] ?? null, true) . "\n";
    echo 'perfil_raw: ' . var_export($perfil_raw, true) . "\n";
    require_once APP_BASE . '/src/config/turno_helpers.php';
    echo 'turno_usuario_exige_turno_ativo: ' . (turno_usuario_exige_turno_ativo($perfil_raw) ? 'SIM' : 'NÃO') . "\n";
    echo '</pre>';
}
require_once APP_BASE . '/src/Service/TurnoService.php';
require_once APP_BASE . '/src/config/turno_helpers.php';
$usuarioId = $_SESSION['usuario_id'] ?? 0;
$restauranteId = function_exists('session_restaurante_auth_id') ? session_restaurante_auth_id() : (int)($_SESSION['restaurante_id'] ?? 0);
if (turno_usuario_exige_turno_ativo($perfil_raw)) {
    $database = new Database();
    $turnoService = new TurnoService($database);
    $turnoAtivo = $turnoService->obterTurnoAtivoUsuario((int)$usuarioId, (int)$restauranteId);
    if (!$turnoAtivo) {
        header('Location: iniciar_turno.php?erro=turno_obrigatorio');
        exit;
    }
}
if (!in_array($perfil_raw, ['GARCOM', 'ADMIN', 'GERENTE'])) {
    header('Location: dashboard.php');
    exit;
}

// Verificar se plano permite funcionalidade GARÇONS (plano da matriz)
$restaurante_id = session_restaurante_contexto_id();
$restauranteFeatureId = session_restaurante_capability_id();
$restauranteFeatureId = $restauranteFeatureId > 0 ? $restauranteFeatureId : $restaurante_id;
plano_verificar_funcionalidade($restauranteFeatureId, 'garcons');
$temPedidosOnline = $restauranteFeatureId > 0 && plano_tem_funcionalidade_db($restauranteFeatureId, 'pedidos_online');
$dadosPlanoGarcom = plano_get_dados($restauranteFeatureId);
$plano_atual = plano_normalizar_nome($dadosPlanoGarcom['plano_nome'] ?? 'BASICO');
require_once __DIR__ . '/includes/empresarial_ui.php';
$usuario_nome   = $_SESSION['nome'] ?? 'Garçom';
?>
<!DOCTYPE html>
<html lang="pt">

<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>Mesas – Garçom</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">

    <link href="lib/bootstrap-5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="lib/fontawesome-6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --bg: #0f1117;
            --card: #1c1e27;
            --border: #2a2d3a;
            --livre: #10b981;
            --ocupada: #f59e0b;
            --atrasada: #ef4444;
            --pronto: #3b82f6;
            --destaque: #fbbf24;
            --text: #f1f5f9;
            --muted: #94a3b8;
            --primary: #FF6B35;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: 'Outfit', 'Segoe UI', 'Noto Sans', system-ui, -apple-system, 'Helvetica Neue', Arial, sans-serif;
            min-height: 100vh;
        }

        /* ── TOPBAR ── */
        .topbar {
            background: #111318;
            border-bottom: 1px solid var(--border);
            padding: 14px 20px;
            position: sticky;
            top: 0;
            z-index: 200;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .topbar-left h1 {
            font-family: 'Space Grotesk', 'Segoe UI Semibold', 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
            font-size: 20px;
            font-weight: 700;
            color: var(--text);
            margin: 0;
        }

        .topbar-left h1 i {
            color: var(--primary);
        }

        #clock {
            font-size: 15px;
            color: var(--muted);
            font-variant-numeric: tabular-nums;
        }

        .topbar-right {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        /* ── HERO / STATS ── */
        .hero-shell {
            margin: 18px 20px 14px;
            padding: 18px 20px;
            border-radius: 22px;
            border: 1px solid rgba(255, 107, 53, 0.2);
            background: linear-gradient(135deg, rgba(255, 107, 53, 0.16), rgba(15, 23, 42, 0.35));
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.16);
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 16px;
            align-items: center;
        }

        .hero-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.12);
            color: #ffe1d1;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.6px;
            text-transform: uppercase;
            margin-bottom: 10px;
        }

        .hero-copy h2 {
            font-family: 'Space Grotesk', 'Segoe UI Semibold', 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
            font-size: 22px;
            margin-bottom: 6px;
            color: var(--text);
        }

        .hero-copy p {
            color: var(--muted);
            margin: 0;
            max-width: 640px;
        }

        .hero-kpis {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
        }

        .hero-kpi {
            background: rgba(17, 19, 24, 0.72);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 12px 12px;
            min-height: 84px;
        }

        .hero-kpi .label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 6px;
        }

        .hero-kpi .value {
            display: block;
            font-size: 20px;
            font-weight: 700;
            color: var(--text);
        }

        .stats-bar {
            display: flex;
            gap: 10px;
            padding: 0 20px 14px;
            flex-wrap: wrap;
            border-bottom: 1px solid var(--border);
        }

        .stat-chip {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 50px;
            padding: 6px 16px;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .stat-chip .dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            display: inline-block;
        }

        .dot-livre {
            background: var(--livre);
        }

        .dot-ocupada {
            background: var(--ocupada);
        }

        .dot-atrasada {
            background: var(--atrasada);
        }

        /* ── GRID DE MESAS ── */
        .gm-som-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--card);
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 6px 12px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }

        .gm-som-btn.is-on { background: #16a34a; border-color: #16a34a; color: #fff; }

        @media (max-width: 560px) { .gm-som-btn span { display: none; } }

        .mesas-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 16px;
            padding: 20px;
        }

        /* ── CARD DE MESA ── */
        .mesa-card {
            background: var(--card);
            border: 2px solid var(--border);
            border-radius: 18px;
            padding: 20px 16px 16px;
            text-align: center;
            cursor: pointer;
            transition: transform 0.18s, box-shadow 0.18s, border-color 0.2s;
            position: relative;
            user-select: none;
            -webkit-tap-highlight-color: transparent;
            overflow: hidden;
        }

        .mesa-card::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: inherit;
            background: linear-gradient(135deg, rgba(255, 107, 53, 0.08), transparent 48%);
            pointer-events: none;
        }

        .mesa-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 32px rgba(0, 0, 0, 0.18);
            border-color: rgba(255, 107, 53, 0.32);
        }

        .mesa-card:active {
            transform: scale(0.95);
        }

        .mesa-card.livre {
            border-color: var(--livre);
        }

        .mesa-card.ocupada {
            border-color: var(--ocupada);
        }

        .mesa-card.atrasada {
            border-color: var(--atrasada);
            animation: pulse-red 1.4s ease-in-out infinite;
        }

        .mesa-card.pronto {
            border-color: var(--pronto);
            animation: pulse-blue 1.4s ease-in-out infinite;
        }

        .mesa-card.minha-mesa {
            border-color: var(--destaque);
            box-shadow: 0 0 0 2px rgba(251, 191, 36, 0.2);
        }

        @keyframes pulse-red {

            0%,
            100% {
                box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4);
            }

            50% {
                box-shadow: 0 0 0 8px rgba(239, 68, 68, 0);
            }
        }

        @keyframes pulse-blue {

            0%,
            100% {
                box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.4);
            }

            50% {
                box-shadow: 0 0 0 8px rgba(59, 130, 246, 0);
            }
        }

        .mesa-numero {
            font-family: 'Space Grotesk', 'Segoe UI Semibold', 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
            font-size: 32px;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 4px;
        }

        .mesa-status-badge {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 10px;
        }

        .badge-livre {
            background: rgba(16, 185, 129, 0.15);
            color: var(--livre);
        }

        .badge-ocupada {
            background: rgba(245, 158, 11, 0.15);
            color: var(--ocupada);
        }

        .badge-reservada {
            background: rgba(99, 102, 241, 0.15);
            color: #818cf8;
        }

        .badge-pronto {
            background: rgba(59, 130, 246, 0.15);
            color: var(--pronto);
        }

        .mesa-info {
            font-size: 12px;
            color: var(--muted);
            line-height: 1.6;
        }

        .badge-minha {
            display: inline-block;
            margin-top: 8px;
            padding: 2px 10px;
            border-radius: 999px;
            font-size: 10px;
            letter-spacing: 0.4px;
            text-transform: uppercase;
            background: rgba(251, 191, 36, 0.15);
            color: var(--destaque);
            font-weight: 700;
        }

        .mesa-info .total {
            font-size: 16px;
            font-weight: 700;
            color: var(--text);
            margin-top: 4px;
        }

        .mesa-info .timer {
            font-size: 11px;
            margin-top: 2px;
        }

        .mesa-info .timer.warn {
            color: var(--ocupada);
            font-weight: 700;
        }

        .mesa-info .timer.urgent {
            color: var(--atrasada);
            font-weight: 700;
        }

        .mesa-cap {
            position: absolute;
            top: 10px;
            right: 12px;
            font-size: 11px;
            color: var(--muted);
        }

        /* ── FAB ── */
        .fab {
            position: fixed;
            bottom: 24px;
            right: 24px;
            width: 62px;
            height: 62px;
            border-radius: 50%;
            background: var(--primary);
            border: none;
            color: white;
            font-size: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 6px 24px rgba(255, 107, 53, 0.5);
            cursor: pointer;
            z-index: 300;
            transition: transform 0.2s;
        }

        .fab:active {
            transform: scale(0.9);
        }

        /* ── STATUS FOOTER ── */
        #footer-status {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #111318;
            border-top: 1px solid var(--border);
            padding: 7px 20px;
            font-size: 11px;
            color: var(--muted);
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 100;
        }

        #refresh-spinner {
            display: none;
        }

        /* ── MODAL MESA OCUPADA ── */
        .modal-mesa {
            border-radius: 20px;
        }

        .modal-header-ocupada {
            background: linear-gradient(135deg, #1e2130, #252836);
            border-bottom: 1px solid var(--border);
        }

        .item-pedido-row {
            display: flex;
            justify-content: space-between;
            padding: 7px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            font-size: 14px;
        }

        .item-pedido-row:last-child {
            border-bottom: none;
        }

        .item-pedido-row .nome {
            flex: 1;
        }

        .item-pedido-row .qtd {
            color: var(--muted);
            margin: 0 12px;
        }

        .item-pedido-row .preco {
            font-weight: 600;
        }

        .item-meta {
            display: flex;
            gap: 6px;
            margin-top: 4px;
        }

        .item-badge {
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 999px;
            font-weight: 700;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }

        .badge-dest-cozinha {
            background: rgba(245, 158, 11, 0.2);
            color: #fbbf24;
        }

        .badge-dest-bar {
            background: rgba(56, 189, 248, 0.2);
            color: #38bdf8;
        }

        .badge-st-pendente {
            background: rgba(148, 163, 184, 0.2);
            color: #cbd5e1;
        }

        .badge-st-preparo {
            background: rgba(249, 115, 22, 0.2);
            color: #fb923c;
        }

        .badge-st-pronto {
            background: rgba(59, 130, 246, 0.2);
            color: #60a5fa;
        }

        .badge-st-entregue {
            background: rgba(16, 185, 129, 0.2);
            color: #34d399;
        }

        /* ── NOTIFICAÇÃO TOAST ── */
        #toast-container {
            position: fixed;
            top: 80px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .toast-item {
            background: #1c1e27;
            border-left: 4px solid var(--primary);
            border-radius: 12px;
            padding: 12px 18px;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4);
            animation: slide-in 0.3s ease;
            max-width: 320px;
        }

        .toast-item.pronto {
            border-color: var(--pronto);
        }

        .sound-banner {
            position: fixed;
            left: 50%;
            bottom: 68px;
            transform: translateX(-50%);
            z-index: 5000;
            display: none;
            align-items: center;
            gap: 12px;
            background: rgba(15, 23, 42, 0.96);
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 12px 16px;
            box-shadow: 0 18px 38px rgba(0, 0, 0, 0.28);
            max-width: calc(100vw - 24px);
        }

        .sound-banner.show {
            display: flex;
        }

        .sound-banner button {
            border: none;
            border-radius: 10px;
            padding: 8px 12px;
            font-weight: 700;
            background: var(--primary);
            color: #fff;
        }

        .toast-item.urgente {
            border-color: var(--atrasada);
        }

        @keyframes slide-in {
            from {
                opacity: 0;
                transform: translateX(40px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @media (max-width: 500px) {
            .mesas-grid {
                grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
                gap: 12px;
                padding: 14px;
            }

            .mesa-numero {
                font-size: 28px;
            }
        }

        @media (min-width: 1200px) {
            .topbar {
                padding: 12px 18px;
            }

            .stats-bar {
                padding: 12px 18px;
                gap: 8px;
            }

            .mesas-grid {
                gap: 12px;
                padding: 16px 18px;
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            }

            .mesa-card {
                border-radius: 14px;
                padding: 16px 12px 14px;
            }

            .mesa-numero {
                font-size: 28px;
            }
        }
    </style>
    <?php empresarial_head_assets(); ?>
    <?php if (!empty($isEmpresarialUi)): ?>
        <link rel="stylesheet" href="css/empresarial.css?v=20260725r">
    <?php endif; ?>
    <style>
        /* ---- Garçom Empresarial (nov 2026) ---- */
        html, body { max-width: 100%; overflow-x: hidden; }
        .gm-btn-entregue { margin-top: 8px; width: 100%; border: 0; border-radius: 10px; padding: 9px 8px; font-weight: 800; font-size: 13px; background: #22c55e; color: #052e16; }
        .gm-btn-entregue:active { transform: scale(.97); }
        .gm-prio-mobile { margin: 0 14px 4px; padding: 10px 12px; border-radius: 14px; background: #1c1e27; border: 1px solid #f59e0b55; }
        .gm-prio-mobile-title { font-weight: 800; font-size: 13px; color: #fbbf24; margin-bottom: 6px; }
        .gm-prio-mobile .garcom-emp-prio-group { margin: 0; }
        .gm-prio-mobile .garcom-emp-prio-label { font-size: 10.5px; margin: 4px 0; }
        .gm-prio-mobile .garcom-emp-prio-item { display: flex; justify-content: space-between; width: 100%; padding: 8px 10px; margin-bottom: 6px; }
        @media (min-width: 992px) {
            .gm-prio-mobile { display: none !important; }
            .fab { display: none !important; }
        }
        @media (max-width: 991.98px) {
            .garcom-emp-side .garcom-emp-side-card:first-child { display: none; }
            .gm-top-novo { display: none !important; }
            body.plano-empresarial .hero-shell { display: none !important; }
            .topbar { flex-wrap: nowrap; gap: 8px; }
            .topbar-right { gap: 6px !important; flex-shrink: 0; }
            .topbar-right .btn { padding: 6px 10px !important; }
            #footer-status { padding-right: 96px; }
        }
        @media (max-width: 560px) {
            /* A barra nao podia quebrar e o ultimo botao ficava cortado
               fora do ecra. Com quebra, passa para a linha de baixo quando
               nao cabe, em vez de desaparecer. */
            .topbar { flex-wrap: wrap; row-gap: 8px; padding: 12px 14px; }
            .topbar-left { min-width: 0; flex: 1 1 auto; }
            .topbar-right { flex: 0 0 auto; margin-left: auto; }
            .topbar-right .gm-lbl { display: none; }
            .topbar-right .btn i { margin-right: 0 !important; }
            #toast-container { top: 64px; right: 10px; left: 10px; align-items: stretch; }
            .toast-item { max-width: none; }
            .sound-banner { left: 10px; right: 90px; transform: none; bottom: 40px; padding: 8px 10px; font-size: 13px; gap: 8px; }
            .sound-banner button { padding: 6px 10px; white-space: nowrap; }
            .fab { bottom: 40px; right: 16px; width: 58px; height: 58px; }
        }
    </style>
</head>

<body class="<?php echo htmlspecialchars(empresarial_body_class(!empty($isEmpresarialUi) ? 'garcom-mesas-page' : '')); ?>">
    <?php if (!empty($isEmpresarialUi)): ?>
        <script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">document.body.classList.add('plano-empresarial', 'garcom-mesas-page');</script>
    <?php endif; ?>

    <!-- TOPBAR -->
    <div class="topbar">
        <div class="topbar-left">
            <a href="dashboard.php" style="color:var(--muted);font-size:20px;text-decoration:none">
                <i class="fas fa-arrow-left"></i>
            </a>
            <h1><i class="fas fa-chair me-2"></i>Mesas</h1>
            <span id="clock"></span>
            <?php if (!empty($isEmpresarialUi)): ?>
                <span class="garcom-emp-plan-pill">Empresarial</span>
            <?php endif; ?>
        </div>
        <div class="topbar-right">
            <?php if ($temPedidosOnline): ?>
                <a href="pedidos.php" class="btn btn-sm" style="background:var(--card);color:var(--text);border:1px solid var(--border);border-radius:10px">
                    <i class="fas fa-list me-1"></i><span class="gm-lbl">Pedidos</span>
                </a>
            <?php endif; ?>
            <?php if (!empty($isEmpresarialUi)): ?>
                <a href="caixa_mesas.php" class="btn btn-sm" style="background:var(--card);color:var(--text);border:1px solid var(--border);border-radius:10px">
                    <i class="fas fa-receipt me-1"></i><span class="gm-lbl">Contas</span>
                </a>
            <?php endif; ?>
            <?php if (($plano_atual ?? '') === 'PROFISSIONAL'): ?>
                <button type="button" id="btnSomGarcom" class="gm-som-btn" onclick="gmAlternarSom()" title="Toca um aviso quando um pedido fica pronto">
                    <i class="fas fa-bell-slash"></i><span>Aviso desligado</span>
                </button>
            <?php endif; ?>
            <a href="novo_pedido.php" class="btn btn-sm gm-top-novo" style="background:var(--primary);color:white;border:none;border-radius:10px">
                <i class="fas fa-plus me-1"></i>Novo
            </a>
        </div>
    </div>

    <div class="hero-shell">
        <div class="hero-copy">
            <?php if (!empty($isEmpresarialUi)): ?>
                <div class="hero-pill"><i class="fas fa-crown me-1"></i>Operação em tempo real</div>
                <h2>Central do salão</h2>
                <p>Mesas prontas e atrasadas aparecem primeiro. Toque numa mesa para ver o pedido.</p>
            <?php else: ?>
                <div class="hero-pill"><i class="fas fa-bolt me-1"></i>Operação em tempo real</div>
                <h2>Central do salão</h2>
                <p>Monitore rapidamente mesas livres, ocupadas e atrasadas para manter o atendimento mais ágil e organizado.</p>
            <?php endif; ?>
        </div>
        <?php if (empty($isEmpresarialUi)): ?>
        <div class="hero-kpis">
            <div class="hero-kpi">
                <span class="label">Livres</span>
                <span class="value" id="hero-livres">–</span>
            </div>
            <div class="hero-kpi">
                <span class="label">Ocupadas</span>
                <span class="value" id="hero-ocupadas">–</span>
            </div>
            <div class="hero-kpi">
                <span class="label">Atrasadas</span>
                <span class="value" id="hero-atrasadas">–</span>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if (empty($isEmpresarialUi)): ?>
    <!-- STATS BAR (não Empresarial — no Empresarial os KPIs ficam só no hero) -->
    <div class="stats-bar" id="stats-bar">
        <div class="stat-chip"><span class="dot dot-livre"></span><span id="s-livres">–</span> Livres</div>
        <div class="stat-chip"><span class="dot dot-ocupada"></span><span id="s-ocupadas">–</span> Ocupadas</div>
        <div class="stat-chip"><span class="dot dot-atrasada"></span><span id="s-atrasadas">–</span> Atrasadas</div>
    </div>
    <?php else: ?>
    <!-- Contadores ocultos para o JS atualizar sem duplicar cards no ecrã -->
    <span id="s-livres" class="d-none">–</span>
    <span id="s-ocupadas" class="d-none">–</span>
    <span class="d-none" id="s-atrasadas">–</span>
    <span class="d-none" id="s-prontos">–</span>
    <span class="d-none" id="s-conta">–</span>
    <?php endif; ?>

    <?php if (!empty($isEmpresarialUi)): ?>
    <div class="garcom-emp-layout">
        <div class="garcom-emp-main">
            <div class="gm-prio-mobile" id="garcom-emp-prio-mobile" hidden></div>
            <div class="garcom-emp-toolbar">
                <div class="garcom-emp-toolbar-title">
                    Mapa do salão
                    <span class="garcom-emp-badge" id="garcom-emp-count">0 mesas</span>
                </div>
                <div class="garcom-emp-toolbar-actions">
                    <input type="search" id="garcom-emp-busca" class="garcom-emp-input" placeholder="Pesquisar mesa…">
                    <div class="garcom-emp-filters" id="garcom-emp-filters" role="group" aria-label="Filtros">
                        <button type="button" class="is-active" data-filter="todos">Todos</button>
                        <button type="button" data-filter="livres">Livres</button>
                        <button type="button" data-filter="ocupadas">Ocupadas</button>
                        <button type="button" data-filter="prontos">Prontos</button>
                        <button type="button" data-filter="atrasadas">Atrasadas</button>
                        <button type="button" data-filter="conta">Conta</button>
                        <button type="button" data-filter="minhas">Minhas</button>
                    </div>
                </div>
            </div>
            <div class="mesas-grid" id="mesas-grid">
                <div class="text-center" style="grid-column:1/-1;padding:60px 0;color:var(--muted)">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                    <p class="mt-3">Carregando mesas…</p>
                </div>
            </div>
        </div>
        <aside class="garcom-emp-side">
            <div class="garcom-emp-side-card">
                <h6><i class="fas fa-bolt me-1"></i>Prioridades</h6>
                <div id="garcom-emp-prioridades">
                    <p class="garcom-emp-empty">Sem alertas no momento.</p>
                </div>
            </div>
            <div class="garcom-emp-side-card mt-3">
                <h6><i class="fas fa-chart-line me-1"></i>Resumo</h6>
                <div class="garcom-emp-side-row"><span>Em aberto</span><strong id="garcom-emp-aberto">MZN 0,00</strong></div>
                <div class="garcom-emp-side-row"><span>Ticket médio</span><strong id="garcom-emp-ticket">MZN 0,00</strong></div>
                <div class="garcom-emp-side-row"><span>Tempo médio</span><strong id="garcom-emp-tempo">—</strong></div>
                <div class="garcom-emp-side-row"><span>Minhas mesas</span><strong id="garcom-emp-minhas">0</strong></div>
                <div class="garcom-emp-side-row"><span>Itens prontos</span><strong id="garcom-emp-itens-prontos">0</strong></div>
                <div class="garcom-emp-side-note">
                    <i class="fas fa-lightbulb me-1"></i>
                    Atenda primeiro <strong>Prontos</strong> e <strong>Atrasadas</strong>. Conta pedida → caixa.
                </div>
                <div class="d-grid gap-2 mt-3">
                    <a href="caixa_mesas.php" class="btn btn-sm" style="background:var(--card);color:var(--text);border:1px solid var(--border);border-radius:10px">
                        <i class="fas fa-cash-register me-1"></i>Contas abertas
                    </a>
                </div>
            </div>
        </aside>
    </div>
    <?php else: ?>
    <!-- GRID DE MESAS -->
    <div class="mesas-grid" id="mesas-grid">
        <div class="text-center" style="grid-column:1/-1;padding:60px 0;color:var(--muted)">
            <i class="fas fa-spinner fa-spin fa-2x"></i>
            <p class="mt-3">Carregando mesas…</p>
        </div>
    </div>
    <?php endif; ?>

    <!-- FAB – novo pedido rápido -->
    <button class="fab" onclick="location.href='novo_pedido.php'" title="Novo pedido">
        <i class="fas fa-plus"></i>
    </button>

    <!-- TOAST CONTAINER -->
    <div id="toast-container"></div>
    <div id="sound-banner" class="sound-banner">
        <span><i class="fas fa-volume-up me-2"></i>Ativar alertas sonoros</span>
        <button type="button" id="sound-enable-btn">Ativar som</button>
    </div>
    <audio id="audio-pronto-garcom" src="data:audio/wav;base64,UklGRoQJAABXQVZFZm10IBAAAAABAAEAQB8AAIA+AAACABAAZGF0YWAJAAAAAHkYtyWmISIOI/SV3+jZt+WX/ZEWMSW+IlkQdPbw4K7ZA+Qw+5MUhCSzI38Sz/hq4prZauLP+H8SsyOEJJMUMPsD5K7Z8OB09lkQviIxJZEWl/235ejZld8j9CIOpiG3JXkYAACH50naWt7e8d0LayAYJkkaaQJv6c/aQt2n74wJEB9SJv0b0ARt63zbTdyB7TEHlh1mJpYdMQeB7U3cfNtt69AE/RtSJhAfjAmn70Ldz9pv6WkCSRoYJmsg3Qve8VreSdqH5wAAeRi3JaYhIg4j9JXf6Nm35Zf9kRYxJb4iWRB09vDgrtkD5DD7kxSEJLMjfxLP+Grimtlq4s/4fxKzI4QkkxQw+wPkrtnw4HT2WRC+IjElkRaX/bfl6NmV3yP0Ig6mIbcleRgAAIfnSdpa3t7x3QtrIBgmSRppAm/pz9pC3afvjAkQH1Im/RvQBG3rfNtN3IHtMQeWHWYmlh0xB4HtTdx8223r0AT9G1ImEB+MCafvQt3P2m/paQJJGhgmayDdC97xWt5J2ofnAAB5GLclpiEiDiP0ld/o2bfll/2RFjElviJZEHT28OCu2QPkMPuTFIQksyN/Es/4auKa2Wriz/h/ErMjhCSTFDD7A+Su2fDgdPZZEL4iMSWRFpf9t+Xo2ZXfI/QiDqYhtyV5GAAAh+dJ2lre3vHdC2sgGCZJGmkCb+nP2kLdp++MCRAfUib9G9AEbet8203cge0xB5YdZiaWHTEHge1N3HzbbevQBP0bUiYQH4wJp+9C3c/ab+lpAkkaGCZrIN0L3vFa3knah+cAAHkYtyWmISIOI/SV3+jZt+WX/ZEWMSW+IlkQdPbw4K7ZA+Qw+5MUhCSzI38Sz/hq4prZauLP+H8SsyOEJJMUMPsD5K7Z8OB09lkQviIxJZEWl/235ejZld8j9CIOpiG3JXkYAACH50naWt7e8d0LayAYJkkaaQJv6c/aQt2n74wJEB9SJv0b0ARt63zbTdyB7TEHlh1mJpYdMQeB7U3cfNtt69AE/RtSJhAfjAmn70Ldz9pv6WkCSRoYJmsg3Qve8VreSdqH5wAAeRi3JaYhIg4j9JXf6Nm35Zf9kRYxJb4iWRB09vDgrtkD5DD7kxSEJLMjfxLP+Grimtlq4s/4fxKzI4QkkxQw+wPkrtnw4HT2WRC+IjElkRaX/bfl6NmV3yP0Ig6mIbcleRgAAIfnSdpa3t7x3QtrIBgmSRppAm/pz9pC3afvjAkQH1Im/RvQBG3rfNtN3IHtMQeWHWYmlh0xB4HtTdx8223r0AT9G1ImEB+MCafvQt3P2m/paQJJGhgmayDdC97xWt5J2ofnAAB5GLclpiEiDiP0ld/o2bfll/2RFjElviJZEHT28OCu2QPkMPuTFIQksyN/Es/4auKa2Wriz/h/ErMjhCSTFDD7A+Su2fDgdPZZEL4iMSWRFpf9t+Xo2ZXfI/QiDqYhtyV5GAAAh+dJ2lre3vHdC2sgGCZJGmkCb+nP2kLdp++MCRAfUib9G9AEbet8203cge0xB5YdZiaWHTEHge1N3HzbbevQBP0bUiYQH4wJp+9C3c/ab+lpAkkaGCZrIN0L3vFa3knah+cAAHkYtyWmISIOI/SV3+jZt+WX/ZEWMSW+IlkQdPbw4K7ZA+Qw+5MUhCSzI38Sz/hq4prZauLP+H8SsyOEJJMUMPsD5K7Z8OB09lkQviIxJZEWl/235ejZld8j9CIOpiG3JXkYAACH50naWt7e8d0LayAYJkkaaQJv6c/aQt2n74wJEB9SJv0b0ARt63zbTdyB7TEHlh1mJpYdMQeB7U3cfNtt69AE/RtSJhAfjAmn70Ldz9pv6WkCSRoYJmsg3Qve8VreSdqH5wAAeRi3JaYhIg4j9JXf6Nm35Zf9kRYxJb4iWRB09vDgrtkD5DD7kxSEJLMjfxLP+Grimtlq4s/4fxKzI4QkkxQw+wPkrtnw4HT2WRC+IjElkRaX/bfl6NmV3yP0Ig6mIbcleRgAAIfnSdpa3t7x3QtrIBgmSRppAm/pz9pC3afvjAkQH1Im/RvQBG3rfNtN3IHtMQeWHWYmlh0xB4HtTdx8223r0AT9G1ImEB+MCafvQt3P2m/paQJJGhgmayDdC97xWt5J2ofnAAB5GLclpiEiDiP0ld/o2bfll/2RFjElviJZEHT28OCu2QPkMPuTFIQksyN/Es/4auKa2Wriz/h/ErMjhCSTFDD7A+Su2fDgdPZZEL4iMSWRFpf9t+Xo2ZXfI/QiDqYhtyV5GAAAh+dJ2lre3vHdC2sgGCZJGmkCb+nP2kLdp++MCRAfUib9G9AEbet8203cge0xB5YdZiaWHTEHge1N3HzbbevQBP0bUiYQH4wJp+9C3c/ab+lpAkkaGCZrIN0L3vFa3knah+c=" preload="auto"></audio>

    <!-- STATUS FOOTER -->
    <div id="footer-status">
        <span id="last-update">–</span>
        <span><i class="fas fa-sync-alt me-1" id="refresh-spinner"></i>Atualiza a cada 8s</span>
    </div>

    <!-- MODAL: Mesa Ocupada -->
    <div class="modal fade" id="modalMesaOcupada" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="background:#1c1e27;color:var(--text);border:1px solid var(--border);border-radius:20px">
                <div class="modal-header modal-header-ocupada">
                    <h5 class="modal-title" id="modal-titulo">
                        <i class="fas fa-chair me-2" style="color:var(--primary)"></i>
                        <span id="modal-mesa-label">Mesa –</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="modal-body" style="padding:22px">
                    <!-- Preenchido via JS -->
                </div>
                <div class="modal-footer" style="border-top:1px solid var(--border);gap:10px;flex-wrap:wrap">
                    <button class="btn btn-sm" style="background:var(--card);color:var(--text);border:1px solid var(--border);border-radius:10px"
                        data-bs-dismiss="modal">Fechar</button>
                    <button class="btn btn-sm" id="btn-modal-entregar" style="display:none;background:#2563eb;color:white;border:none;border-radius:10px">
                        <i class="fas fa-hand-holding me-1"></i>Entregar
                    </button>
                    <button class="btn btn-sm" id="btn-modal-pedir-conta" style="display:none;background:#f59e0b;color:#111;border:none;border-radius:10px;font-weight:700">
                        <i class="fas fa-file-invoice me-1"></i>Pedir Conta
                    </button>
                    <button class="btn btn-sm" id="btn-modal-add"
                        style="background:var(--primary);color:white;border:none;border-radius:10px">
                        <i class="fas fa-plus me-1"></i>Adicionar Itens
                    </button>
                    <button class="btn btn-sm" id="btn-modal-pagar"
                        style="background:var(--livre);color:black;border:none;border-radius:10px;font-weight:700">
                        <i class="fas fa-check me-1"></i>Ir ao Caixa
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="lib/bootstrap-5.3.2/js/bootstrap.bundle.min.js"></script>
    <script src="js/receipt_print.js?v=20260821a"></script>
    <script src="js/nv_operacao.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_operacao.js'); ?>"></script>
    <script src="js/offline_sync.js?v=20261101b"></script>
    <script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
        OfflineSync.init({
            csrfToken: <?php echo json_encode($csrfToken, JSON_UNESCAPED_UNICODE); ?>,
            syncIntervalMs: 10000
        });
        const currentUserId = <?php echo (int)($_SESSION['usuario_id'] ?? 0); ?>;
        const currentPerfil = "<?php echo htmlspecialchars($perfil_raw, ENT_QUOTES); ?>";
        const CSRF_TOKEN = <?php echo json_encode($csrfToken, JSON_UNESCAPED_UNICODE); ?>;
        const IS_EMPRESARIAL = <?php echo !empty($isEmpresarialUi) ? 'true' : 'false'; ?>;
        const IS_PROFISSIONAL = <?php echo (($plano_atual ?? '') === 'PROFISSIONAL') ? 'true' : 'false'; ?>;
        let garcomEmpFilter = 'todos';
        let garcomEmpBusca = '';
        let garcomMesasCache = [];
        const isMesaDoGarcom = (m) => {
            if (currentPerfil !== 'GARCOM') return true;
            return Number(m.garcom_id || 0) === currentUserId;
        };
        const isContaStatus = (st) => {
            const s = String(st || '').toUpperCase();
            return s === 'CONTA' || s === 'CONTA_SOLICITADA';
        };
        // ─── Utilitários ──────────────────────────────────────────────────────────────
        function esc(str) {
            return String(str ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        function fmt(v) {
            return parseFloat(v || 0).toLocaleString('pt-MZ', {
                minimumFractionDigits: 2
            });
        }

        function timerText(mins) {
            if (mins === null || mins === undefined) return '';
            if (mins < 1) return 'Agora';
            if (mins < 60) return mins + ' min';
            return Math.floor(mins / 60) + 'h ' + (mins % 60) + 'min';
        }

        // ─── Relógio ──────────────────────────────────────────────────────────────────
        function updateClock() {
            document.getElementById('clock').textContent =
                new Date().toLocaleTimeString('pt-BR', {
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                });
        }
        setInterval(updateClock, 1000);
        updateClock();

        // ─── Notificações ─────────────────────────────────────────────────────────────
        let audioCtx = null;
        let soundEnabled = false;
        let audioUnlocked = false;

        function beep(freq, dur, volume = 0.35) {
            if (!soundEnabled || !audioUnlocked || !audioCtx) return;
            try {
                const o = audioCtx.createOscillator();
                const g = audioCtx.createGain();
                o.connect(g);
                g.connect(audioCtx.destination);
                o.frequency.value = freq;
                g.gain.setValueAtTime(volume, audioCtx.currentTime);
                g.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + dur);
                o.start();
                o.stop(audioCtx.currentTime + dur);
            } catch (e) {}
        }

        function playBeepPronto() {
            beep(880, 0.16, 0.85);
            setTimeout(() => beep(1046, 0.16, 0.9), 150);
            setTimeout(() => beep(1318, 0.2, 0.95), 300);
            setTimeout(() => beep(1568, 0.24, 1.0), 520);
            if (navigator.vibrate) {
                navigator.vibrate([180, 100, 220, 100, 260]);
            }
        }

        function playPronto() {
            if (!soundEnabled || !audioUnlocked) return;
            const audio = document.getElementById('audio-pronto-garcom');
            if (audio) {
                const playOnce = (delayMs) => {
                    setTimeout(() => {
                        audio.currentTime = 0;
                        audio.volume = 1.0;
                        audio.play().catch(() => playBeepPronto());
                    }, delayMs);
                };
                playOnce(0);
                playOnce(900);
                playOnce(1800);
                return;
            }
            playBeepPronto();
        }

        function updateSoundBanner() {
            const banner = document.getElementById('sound-banner');
            if (banner) banner.classList.toggle('show', !soundEnabled);
        }

        async function enableSoundAlerts() {
            try {
                if (!audioCtx) audioCtx = new(window.AudioContext || window.webkitAudioContext)();
                if (audioCtx.state === 'suspended') await audioCtx.resume();
                soundEnabled = true;
                audioUnlocked = true;
                localStorage.setItem('edvis_sound_alerts', '1');
                updateSoundBanner();
                beep(880, 0.12, 0.45);
            } catch (e) {}
        }

        async function atualizarStatusPedidoGarcom(pedidoId, status, btnEl) {
            if (!pedidoId || !status) return;
            const label = String(status).toUpperCase() === 'CONTA'
                ? 'Confirmar pedido de conta?'
                : 'Marcar pedido como entregue?';
            if (!(await nivoraConfirmar(label))) return;
            const original = btnEl ? btnEl.innerHTML : '';
            if (btnEl) {
                btnEl.disabled = true;
                btnEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            }
            try {
                const result = await OfflineSync.executeOrQueue({
                    label: 'Atualizar pedido ' + pedidoId + ' para ' + status,
                    endpoint: 'api/pedido_status.php',
                    method: 'POST',
                    body: { id: String(pedidoId), status: String(status) },
                    headers: {
                        'X-CSRF-Token': CSRF_TOKEN,
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                if (result.queued) {
                    showToast('Acao guardada e pendente de sincronizacao.', '');
                } else if (!result.data?.success) {
                    throw new Error(result.data?.message || 'Falha ao atualizar');
                } else {
                    showToast(String(status).toUpperCase() === 'CONTA' ? 'Conta pedida' : 'Pedido entregue', 'pronto');
                }
                const modalEl = document.getElementById('modalMesaOcupada');
                const modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
                carregarMesas();
            } catch (err) {
                showToast(err.message || 'Erro de ligação', '');
            } finally {
                if (btnEl) {
                    btnEl.disabled = false;
                    btnEl.innerHTML = original;
                }
            }
        }

        function showToast(msg, type = '') {
            const div = document.createElement('div');
            div.className = 'toast-item ' + type;
            div.innerHTML = '<i class="fas fa-bell"></i>' + esc(msg);
            const tc = document.getElementById('toast-container');
            tc.appendChild(div);
            setTimeout(() => div.remove(), 5000);
        }

        // ─── Renderizar mesa ──────────────────────────────────────────────────────────
        function classifyCard(m) {
            if (m.status === 'LIVRE') return 'livre';
            if (m.status === 'RESERVADA') return 'reservada';
            if (m.pedido_status === 'PRONTO') return 'pronto';
            if (m.pedido_status === 'CONTA' || m.pedido_status === 'CONTA_SOLICITADA') return 'ocupada';
            if (m.pedido_minutos !== null && m.pedido_minutos >= 15) return 'atrasada';
            return 'ocupada';
        }

        function isMesaSala(m) {
            return Number(m && m.numero) > 0;
        }

        function renderMesa(m) {
            const cls = classifyCard(m);
            let badgeCls = 'badge-ocupada',
                badgeTxt = 'Ocupada';
            if (cls === 'livre') {
                badgeCls = 'badge-livre';
                badgeTxt = 'Livre';
            }
            if (cls === 'reservada') {
                badgeCls = 'badge-reservada';
                badgeTxt = 'Reservada';
            }
            if (cls === 'pronto') {
                badgeCls = 'badge-pronto';
                badgeTxt = 'Pronto ✓';
            }
            if (cls === 'atrasada') {
                badgeTxt = 'Atrasada ⚠';
            }

            let infoHtml = '';
            if (m.pedido_id) {
                const mins = m.pedido_minutos;
                const tClass = mins >= 15 ? 'urgent' : mins >= 10 ? 'warn' : '';
                const tTxt = timerText(mins);
                const minhaMesa = isMesaDoGarcom(m) && m.pedido_status === 'PRONTO';
                const prontosBar = Number(m.itens_prontos_bar || 0);
                const prontosCoz = Number(m.itens_prontos_cozinha || 0);
                const chipsEmp = IS_EMPRESARIAL ? `
                    <div class="garcom-emp-card-chips">
                        ${prontosCoz > 0 ? '<span class="chip chip-cozinha">' + prontosCoz + ' cozinha</span>' : ''}
                        ${prontosBar > 0 ? '<span class="chip chip-bar">' + prontosBar + ' bar</span>' : ''}
                        ${isContaStatus(m.pedido_status) ? '<span class="chip chip-conta">conta</span>' : ''}
                        ${m.garcom_nome ? '<span class="chip chip-garcom">' + esc(String(m.garcom_nome).split(' ')[0]) + '</span>' : ''}
                    </div>` : '';

                infoHtml = `
            <div class="mesa-info">
                <div>${esc(m.pedido_itens)} ${m.pedido_itens === 1 ? 'item' : 'itens'}</div>
                <div class="total">MZN ${fmt(m.pedido_total)}</div>
                <div class="timer ${tClass}"><i class="fas fa-clock fa-xs me-1"></i>${tTxt}</div>
                ${minhaMesa ? '<div class="badge-minha">minha mesa</div>' : ''}
                ${chipsEmp}
                ${IS_EMPRESARIAL && String(m.pedido_status || '').toUpperCase() === 'PRONTO' ? '<button type="button" class="gm-btn-entregue" data-entregue="' + Number(m.pedido_id) + '"><i class="fas fa-check me-1"></i>Entregue</button>' : ''}
            </div>`;
            } else {
                infoHtml = `<div class="mesa-info" style="color:var(--muted)">
            <i class="fas fa-check-circle" style="font-size:20px;color:var(--livre)"></i>
            <div class="mt-1">Disponível</div>
        </div>`;
            }

            const minhaMesa = m.pedido_id ? (isMesaDoGarcom(m) && m.pedido_status === 'PRONTO') : false;
            const urgenteEmp = IS_EMPRESARIAL && (cls === 'pronto' || cls === 'atrasada' || isContaStatus(m.pedido_status));
            return `
    <div class="mesa-card ${cls} ${minhaMesa ? 'minha-mesa' : ''} ${urgenteEmp ? 'garcom-emp-urgente' : ''}"
         onclick="onMesaClick(${m.id}, ${JSON.stringify(m).replace(/"/g,'&quot;')})"
         data-mesa-id="${m.id}"
         data-cls="${cls}">
        <span class="mesa-cap"><i class="fas fa-user-friends"></i> ${m.capacidade}</span>
        <div class="mesa-numero">${m.numero}</div>
        <div class="mesa-status-badge ${badgeCls}">${badgeTxt}</div>
        ${infoHtml}
    </div>`;
        }

        function prioridadeMesa(m) {
            const cls = classifyCard(m);
            if (cls === 'pronto') return 0;
            if (isContaStatus(m.pedido_status)) return 1;
            if (cls === 'atrasada') return 2;
            if (cls === 'ocupada') return 3;
            if (cls === 'reservada') return 4;
            return 5;
        }

        function filtrarMesasEmp(mesas) {
            const q = garcomEmpBusca.trim().toLowerCase();
            return (mesas || []).filter((m) => {
                const cls = classifyCard(m);
                const pst = String(m.pedido_status || '').toUpperCase();
                if (q && !String(m.numero).includes(q) && !(m.garcom_nome || '').toLowerCase().includes(q)) {
                    return false;
                }
                if (garcomEmpFilter === 'livres') return cls === 'livre';
                if (garcomEmpFilter === 'ocupadas') return cls === 'ocupada' || cls === 'atrasada' || cls === 'pronto' || isContaStatus(pst);
                if (garcomEmpFilter === 'prontos') return cls === 'pronto';
                if (garcomEmpFilter === 'atrasadas') return cls === 'atrasada';
                if (garcomEmpFilter === 'conta') return isContaStatus(pst);
                if (garcomEmpFilter === 'minhas') return Number(m.garcom_id || 0) === currentUserId && !!m.pedido_id;
                return true;
            }).sort((a, b) => {
                const pa = prioridadeMesa(a) - prioridadeMesa(b);
                if (pa !== 0) return pa;
                return (Number(b.pedido_minutos) || 0) - (Number(a.pedido_minutos) || 0);
            });
        }

        function renderPrioridadesEmp(mesas) {
            const box = document.getElementById('garcom-emp-prioridades');
            if (!box) return;
            const prontos = mesas.filter((m) => classifyCard(m) === 'pronto').slice(0, 5);
            const atrasadas = mesas.filter((m) => classifyCard(m) === 'atrasada').slice(0, 5);
            const contas = mesas.filter((m) => isContaStatus(m.pedido_status)).slice(0, 5);
            let html = '';
            const addGroup = (titulo, lista, tom) => {
                if (!lista.length) return;
                html += `<div class="garcom-emp-prio-group"><div class="garcom-emp-prio-label ${tom}">${titulo}</div>`;
                lista.forEach((m) => {
                    html += `<button type="button" class="garcom-emp-prio-item" data-open-mesa="${m.id}">
                        <strong>Mesa ${esc(m.numero)}</strong>
                        <span>${timerText(m.pedido_minutos)} · MZN ${fmt(m.pedido_total)}</span>
                    </button>`;
                });
                html += '</div>';
            };
            addGroup('Prontos para entregar', prontos, 'tom-pronto');
            addGroup('Atrasadas ≥15 min', atrasadas, 'tom-atrasada');
            addGroup('Conta pedida', contas, 'tom-conta');
            box.innerHTML = html || '<p class="garcom-emp-empty">Sem alertas no momento.</p>';
            const mob = document.getElementById('garcom-emp-prio-mobile');
            if (mob) {
                mob.innerHTML = html ? '<div class="gm-prio-mobile-title"><i class="fas fa-bolt me-1"></i>Prioridades</div>' + html : '';
                mob.hidden = !html;
            }
        }

        document.addEventListener('click', function (ev) {
            const b = ev.target.closest('.gm-btn-entregue');
            if (!b) return;
            ev.stopPropagation();
            ev.preventDefault();
            atualizarStatusPedidoGarcom(Number(b.getAttribute('data-entregue')), 'ENTREGUE', b);
        }, true);

        function abrirMesaPorId(id) {
            const m = garcomMesasCache.find((x) => Number(x.id) === Number(id));
            if (m) onMesaClick(Number(id), m);
        }

        function atualizarResumoEmp(mesas, stats) {
            const ocupadasComPedido = mesas.filter((m) => m.pedido_id);
            const aberto = ocupadasComPedido.reduce((acc, m) => acc + (Number(m.pedido_total) || 0), 0);
            const ticket = ocupadasComPedido.length ? aberto / ocupadasComPedido.length : 0;
            const prontos = mesas.filter((m) => classifyCard(m) === 'pronto').length;
            const contas = mesas.filter((m) => isContaStatus(m.pedido_status)).length;
            const minhas = mesas.filter((m) => Number(m.garcom_id || 0) === currentUserId && m.pedido_id).length;
            const itensProntos = mesas.reduce((acc, m) => acc + Number(m.itens_prontos_bar || 0) + Number(m.itens_prontos_cozinha || 0), 0);
            const minsList = ocupadasComPedido.map((m) => Number(m.pedido_minutos)).filter((n) => !Number.isNaN(n));
            const tempoMedio = minsList.length ? Math.round(minsList.reduce((a, b) => a + b, 0) / minsList.length) : null;

            const setStatValue = (id, value) => {
                const el = document.getElementById(id);
                if (el) el.textContent = value ?? '–';
            };
            setStatValue('s-prontos', prontos);
            setStatValue('s-conta', contas);
            setStatValue('garcom-emp-count', (stats.total || mesas.length) + ' mesas');
            setStatValue('garcom-emp-minhas', minhas);
            setStatValue('garcom-emp-itens-prontos', itensProntos);
            setStatValue('garcom-emp-tempo', tempoMedio === null ? '—' : (tempoMedio + ' min'));
            // Em aberto / ticket ficam no painel lateral (evita cards repetidos no hero).
            const ladoAberto = document.getElementById('garcom-emp-aberto');
            const ladoTicket = document.getElementById('garcom-emp-ticket');
            if (ladoAberto) ladoAberto.textContent = 'MZN ' + fmt(aberto);
            if (ladoTicket) ladoTicket.textContent = 'MZN ' + fmt(ticket);
        }

        // ─── Aviso de pedido pronto (Profissional) ────────────────────────────────
        let gmProntosVistos = null;
        let gmSomCtx = null;

        function gmSomLigado() {
            try {
                return localStorage.getItem('nivora_garcom_som') === '1';
            } catch (e) {
                return false;
            }
        }

        function gmPintarBotaoSom() {
            const btn = document.getElementById('btnSomGarcom');
            if (!btn) return;
            const on = gmSomLigado();
            btn.innerHTML = '<i class="fas ' + (on ? 'fa-bell' : 'fa-bell-slash') + '"></i><span>' + (on ? 'Aviso ligado' : 'Aviso desligado') + '</span>';
            btn.classList.toggle('is-on', on);
        }

        function gmAlternarSom() {
            const novo = !gmSomLigado();
            try { localStorage.setItem('nivora_garcom_som', novo ? '1' : '0'); } catch (e) {}
            gmPintarBotaoSom();
            if (novo) gmTocarAviso();
        }

        function gmTocarAviso() {
            if (!gmSomLigado()) return;
            try {
                const Ctx = window.AudioContext || window.webkitAudioContext;
                if (!Ctx) return;
                gmSomCtx = gmSomCtx || new Ctx();
                if (gmSomCtx.state === 'suspended') gmSomCtx.resume();
                [0, 0.17].forEach(function (atraso, i) {
                    const osc = gmSomCtx.createOscillator();
                    const vol = gmSomCtx.createGain();
                    osc.type = 'sine';
                    osc.frequency.value = i === 0 ? 920 : 1240;
                    vol.gain.setValueAtTime(0.0001, gmSomCtx.currentTime + atraso);
                    vol.gain.exponentialRampToValueAtTime(0.28, gmSomCtx.currentTime + atraso + 0.02);
                    vol.gain.exponentialRampToValueAtTime(0.0001, gmSomCtx.currentTime + atraso + 0.15);
                    osc.connect(vol).connect(gmSomCtx.destination);
                    osc.start(gmSomCtx.currentTime + atraso);
                    osc.stop(gmSomCtx.currentTime + atraso + 0.17);
                });
            } catch (e) { /* o navegador pode recusar som antes de um clique */ }
        }

        // Avisa quando uma mesa passa a "pronto" desde a ultima leitura.
        function gmVerificarProntos(mesas) {
            if (!IS_PROFISSIONAL) return;
            const prontos = {};
            (mesas || []).forEach(function (m) {
                if (String(m.pedido_status || '').toUpperCase() === 'PRONTO' && m.pedido_id) {
                    prontos[String(m.pedido_id)] = true;
                }
            });
            if (gmProntosVistos === null) {
                gmProntosVistos = prontos;
                return;
            }
            let novo = false;
            Object.keys(prontos).forEach(function (id) {
                if (!gmProntosVistos[id]) novo = true;
            });
            gmProntosVistos = prontos;
            if (novo) {
                gmTocarAviso();
                if (typeof showToast === 'function') showToast('Pedido pronto para entregar', 'pronto');
            }
        }

        document.addEventListener('DOMContentLoaded', gmPintarBotaoSom);

        function renderGridMesas(mesas) {
            const grid = document.getElementById('mesas-grid');
            if (!grid) return;
            let lista;
            if (IS_EMPRESARIAL) {
                lista = filtrarMesasEmp(mesas);
            } else if (IS_PROFISSIONAL) {
                // Primeiro o que precisa do garcom: prontos, conta pedida e atrasadas.
                lista = [...mesas].sort(function (a, b) {
                    const pa = prioridadeMesa(a) - prioridadeMesa(b);
                    if (pa !== 0) return pa;
                    return (Number(b.pedido_minutos) || 0) - (Number(a.pedido_minutos) || 0);
                });
            } else {
                lista = mesas;
            }
            if (!mesas.length) {
                grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:60px 0;color:var(--muted)"><i class="fas fa-chair fa-2x mb-3"></i><p>Nenhuma mesa cadastrada.<br><a href="mesas.php" style="color:var(--primary)">Cadastrar mesas</a></p></div>';
                return;
            }
            if (!lista.length) {
                grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px 0;color:var(--muted)"><i class="fas fa-filter fa-2x mb-3"></i><p>Nenhuma mesa neste filtro.</p></div>';
                return;
            }
            grid.innerHTML = lista.map(renderMesa).join('');
            gmVerificarProntos(mesas);
        }

        // ─── Click na mesa ────────────────────────────────────────────────────────────
        function onMesaClick(id, m) {
            if (m.status === 'LIVRE' || !m.pedido_id) {
                // Mesa livre → criar pedido direto
                location.href = 'novo_pedido.php?mesa_id=' + id;
                return;
            }
            // Mesa ocupada → abrir modal de detalhes
            abrirModalMesa(m);
        }

        let currentPedidoId = null;
        let currentMesaId = null;

        function abrirModalMesa(m) {
            currentPedidoId = m.pedido_id;
            currentMesaId = m.id;

            document.getElementById('modal-mesa-label').textContent = 'Mesa ' + m.numero;

            const mins = m.pedido_minutos;
            const tClass = mins >= 15 ? 'color:var(--atrasada)' : mins >= 10 ? 'color:var(--ocupada)' : 'color:var(--muted)';
            const tTxt = timerText(mins);

            let statusIcon = '';
            const pst = String(m.pedido_status || '').toUpperCase();
            if (pst === 'PRONTO') statusIcon = '<span style="color:var(--pronto)"><i class="fas fa-check-circle me-1"></i>Pronto para entrega</span>';
            else if (pst === 'PREPARANDO') statusIcon = '<span style="color:var(--ocupada)"><i class="fas fa-fire me-1"></i>Em preparo</span>';
            else if (pst === 'ENTREGUE') statusIcon = '<span style="color:var(--muted)"><i class="fas fa-utensils me-1"></i>Entregue — cliente a consumir</span>';
            else if (pst === 'CONTA' || pst === 'CONTA_SOLICITADA') statusIcon = '<span style="color:#38bdf8"><i class="fas fa-file-invoice me-1"></i>Conta pedida — aguarda caixa</span>';
            else statusIcon = '<span style="color:var(--muted)"><i class="fas fa-clock me-1"></i>Aguardando</span>';

            document.getElementById('modal-body').innerHTML = `
        <div style="display:flex;justify-content:space-between;margin-bottom:14px;font-size:13px">
            <span>${statusIcon}</span>
            <span style="${tClass}"><i class="fas fa-stopwatch me-1"></i>${tTxt}</span>
        </div>
        <div id="modal-itens-lista">
            <div style="text-align:center;padding:20px;color:var(--muted)"><i class="fas fa-spinner fa-spin"></i> Carregando itens…</div>
        </div>
        <hr style="border-color:var(--border);margin:14px 0">
        <div style="display:flex;justify-content:space-between;font-size:15px;font-weight:700">
            <span>Total</span>
            <span>MZN ${fmt(m.pedido_total)}</span>
        </div>
        ${m.garcom_nome ? '<div style="font-size:12px;color:var(--muted);margin-top:6px"><i class="fas fa-user me-1"></i>' + esc(m.garcom_nome) + '</div>' : ''}
    `;

            // Botões de ação
            const btnEntregar = document.getElementById('btn-modal-entregar');
            const btnPedirConta = document.getElementById('btn-modal-pedir-conta');
            if (btnEntregar) {
                btnEntregar.style.display = pst === 'PRONTO' ? '' : 'none';
                btnEntregar.onclick = () => atualizarStatusPedidoGarcom(currentPedidoId, 'ENTREGUE', btnEntregar);
            }
            if (btnPedirConta) {
                // Visível em Pronto ou Entregue (não só Entregue)
                btnPedirConta.style.display = (pst === 'PRONTO' || pst === 'ENTREGUE') ? '' : 'none';
                btnPedirConta.onclick = () => atualizarStatusPedidoGarcom(currentPedidoId, 'CONTA', btnPedirConta);
            }
            document.getElementById('btn-modal-add').onclick = () => {
                location.href = 'novo_pedido.php?mesa_id=' + currentMesaId + '&pedido_id=' + currentPedidoId;
            };
            document.getElementById('btn-modal-pagar').onclick = () => {
                location.href = IS_EMPRESARIAL
                    ? ('caixa_mesas.php?mesa_id=' + currentMesaId)
                    : ('caixa.php?pedido_id=' + currentPedidoId);
            };

            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalMesaOcupada')).show();

            // Carregar itens detalhados
            OfflineSync.fetchJSON('api/pedido_itens.php?id=' + currentPedidoId, {
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    ttlMs: 120000,
                    allowStale: true
                })
                .then((result) => {
                    const data = (result && result.data) ? result.data : {};
                    if (!data.success) return;
                    let html = '';
                    (data.itens || []).forEach(it => {
                        const destino = String(it.destino || 'cozinha').toLowerCase();
                        const status = String(it.status || 'pendente').toLowerCase();
                        const destinoClass = destino === 'bar' ? 'badge-dest-bar' : 'badge-dest-cozinha';
                        const statusMap = {
                            'pendente': ['badge-st-pendente', 'Pendente'],
                            'em_preparo': ['badge-st-preparo', 'Em preparo'],
                            'pronto': ['badge-st-pronto', 'Pronto'],
                            'entregue': ['badge-st-entregue', 'Entregue']
                        };
                        const st = statusMap[status] || ['badge-st-pendente', status];
                        html += `<div class="item-pedido-row">
                    <span class="nome">${esc(it.produto_nome)}
                        <div class="item-meta">
                            <span class="item-badge ${destinoClass}">${esc(destino)}</span>
                            <span class="item-badge ${st[0]}">${esc(st[1])}</span>
                        </div>
                    </span>
                    <span class="qtd">${esc(it.quantidade)}×</span>
                    <span class="preco">MZN ${fmt(parseFloat(it.preco_unitario) * parseFloat(it.quantidade))}</span>
                </div>`;
                    });
                    if (data.observacao) {
                        html += `<div style="font-size:12px;color:#fcd34d;background:rgba(245,158,11,0.1);border-radius:8px;padding:8px;margin-top:10px">
                    <i class="fas fa-comment-dots me-1"></i>${esc(data.observacao)}</div>`;
                    }
                    document.getElementById('modal-itens-lista').innerHTML = html || '<p style="color:var(--muted);text-align:center">Sem itens</p>';
                })
                .catch(() => {});
        }

        // ─── Carregar mesas (polling) ─────────────────────────────────────────────────
        let prevProntos = new Set();
        let prevReadyByPedido = {};

        async function carregarMesas() {
            const spin = document.getElementById('refresh-spinner');
            if (spin) spin.style.display = 'inline-block';
            try {
                const result = await OfflineSync.fetchJSON('api/garcom_mesas_detalhes.php', {
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    ttlMs: 30000,
                    allowStale: true
                });
                const data = (result && result.data) ? result.data : {};
                if (data.redirect) {
                    location.href = data.redirect;
                    return;
                }
                if (!data.success) throw new Error(data.message || 'Erro');
                data.mesas = (data.mesas || []).filter(isMesaSala);
                data.stats = data.stats || {};
                data.stats.total = data.mesas.length;
                data.stats.livres = data.mesas.filter((m) => m.status === 'LIVRE').length;
                data.stats.ocupadas = data.mesas.filter((m) => m.status === 'OCUPADA').length;
                data.stats.atrasadas = data.mesas.filter((m) => m.pedido_minutos !== null && Number(m.pedido_minutos) >= 15).length;

                // Detectar pedidos que ficaram prontos
                const newProntos = new Set(
                    data.mesas.filter(m => m.pedido_status === 'PRONTO').map(m => m.pedido_id)
                );
                newProntos.forEach(id => {
                    if (!prevProntos.has(id)) {
                        const m = data.mesas.find(x => x.pedido_id === id);
                        if (m && isMesaDoGarcom(m)) {
                            playPronto();
                            showToast('Mesa ' + m.numero + ' — pedido pronto! 🍽️', 'pronto');
                        }
                    }
                });
                prevProntos = newProntos;

                // Notificacoes granulares por setor (bar/cozinha)
                (data.mesas || []).forEach(m => {
                    if (!m.pedido_id) return;
                    const pid = String(m.pedido_id);
                    const prev = prevReadyByPedido[pid] || {
                        bar: 0,
                        cozinha: 0
                    };
                    const nowBar = Number(m.itens_prontos_bar || 0);
                    const nowCoz = Number(m.itens_prontos_cozinha || 0);

                    if (nowBar > prev.bar && isMesaDoGarcom(m)) {
                        playPronto();
                        showToast('Mesa ' + m.numero + ' — bebida pronta 🍹', 'pronto');
                    }
                    if (nowCoz > prev.cozinha && isMesaDoGarcom(m)) {
                        playPronto();
                        showToast('Mesa ' + m.numero + ' — comida pronta 🍽️', 'pronto');
                    }

                    prevReadyByPedido[pid] = {
                        bar: nowBar,
                        cozinha: nowCoz
                    };
                });

                garcomMesasCache = data.mesas || [];
                renderGridMesas(garcomMesasCache);

                // Atualizar stats
                const s = data.stats || {};
                const setStatValue = (id, value) => {
                    const el = document.getElementById(id);
                    if (el) el.textContent = value ?? '–';
                };
                setStatValue('s-livres', s.livres);
                setStatValue('s-ocupadas', s.ocupadas);
                setStatValue('s-atrasadas', s.atrasadas);
                if (!IS_EMPRESARIAL) {
                    setStatValue('hero-livres', s.livres);
                    setStatValue('hero-ocupadas', s.ocupadas);
                    setStatValue('hero-atrasadas', s.atrasadas);
                } else {
                    atualizarResumoEmp(garcomMesasCache, s);
                    renderPrioridadesEmp(garcomMesasCache);
                }

                document.getElementById('last-update').textContent =
                    'Atualizado às ' + new Date().toLocaleTimeString('pt-BR', {
                        hour: '2-digit',
                        minute: '2-digit',
                        second: '2-digit'
                    });

            } catch (e) {
                const lu = document.getElementById('last-update');
                if (lu) lu.textContent = 'Erro ao carregar – ' + e.message;
            } finally {
                const spinEnd = document.getElementById('refresh-spinner');
                if (spinEnd) spinEnd.style.display = 'none';
            }
        }

        if (IS_EMPRESARIAL) {
            document.getElementById('garcom-emp-filters')?.addEventListener('click', (ev) => {
                const btn = ev.target.closest('[data-filter]');
                if (!btn) return;
                garcomEmpFilter = btn.getAttribute('data-filter') || 'todos';
                document.querySelectorAll('#garcom-emp-filters [data-filter]').forEach((b) => {
                    b.classList.toggle('is-active', b === btn);
                });
                renderGridMesas(garcomMesasCache);
            });
            document.getElementById('garcom-emp-busca')?.addEventListener('input', (ev) => {
                garcomEmpBusca = String(ev.target.value || '');
                renderGridMesas(garcomMesasCache);
            });
            document.getElementById('garcom-emp-prioridades')?.addEventListener('click', (ev) => {
                const btn = ev.target.closest('[data-open-mesa]');
                if (!btn) return;
                abrirMesaPorId(btn.getAttribute('data-open-mesa'));
            });
            const footerHint = document.querySelector('#footer-status span:last-child');
            if (footerHint) footerHint.innerHTML = '<i class="fas fa-sync-alt me-1" id="refresh-spinner"></i>Atualiza a cada 5s · fila inteligente';
        }

        document.addEventListener('click', () => {
            try {
                if (!audioCtx) audioCtx = new(window.AudioContext || window.webkitAudioContext)();
                if (audioCtx.state === 'suspended') audioCtx.resume();
                audioUnlocked = true;
            } catch (e) {}
        }, {
            once: true
        });

        document.addEventListener('touchstart', () => {
            try {
                if (!audioCtx) audioCtx = new(window.AudioContext || window.webkitAudioContext)();
                if (audioCtx.state === 'suspended') audioCtx.resume();
                audioUnlocked = true;
            } catch (e) {}
        }, {
            once: true,
            passive: true
        });

        soundEnabled = localStorage.getItem('edvis_sound_alerts') === '1';
        updateSoundBanner();
        document.getElementById('sound-enable-btn')?.addEventListener('click', enableSoundAlerts);
        setTimeout(updateSoundBanner, 150);

        // Carregar imediatamente e a cada 5s
        carregarMesas();
        // So recarrega quando ha novidade (ver js/nv_operacao.js).
        NvOperacao.vigiar({ intervalo: 5000, forcarCada: 60000, aoMudar: () => carregarMesas() });
    </script>
<script src="js/autorizacao_gerente.js?v=20261108d" data-csrf="<?php echo htmlspecialchars((string)csrf_get_token(), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="js/nivora_dialogo.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nivora_dialogo.js'); ?>"></script>
</body>

</html>
