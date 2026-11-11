<?php
require_once __DIR__ . '/app_base.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/reserva_qr_schema.php';
require_once APP_BASE . '/src/api/reserva_publica_handler.php';

$db = (new Database())->getConnection();
$token = trim((string)($_GET['token'] ?? ''));
$contexto = $token !== '' ? consultar_contexto_qr_publico(['token' => $token], $db) : [
    'success' => false,
    'message' => 'Sessao QR nao informada.',
];

$contextoValido = !empty($contexto['success']);
$restaurante = $contexto['restaurante'] ?? [];
$mesas = $contexto['mesas'] ?? [];
$cardapio = $contexto['cardapio'] ?? [];
$sessao = $contexto['sessao'] ?? [];
$preBloqueio = $contexto['pre_bloqueio'] ?? null;
$sessaoConsumida = !empty($sessao['consumed_at']);
$planoAtualNome = 'BASICO';
if ($contextoValido && !empty($restaurante['id']) && function_exists('plano_get_dados')) {
    $dadosPlanoReservaQr = plano_get_dados((int)$restaurante['id']);
    $planoAtualNome = function_exists('plano_normalizar_nome')
        ? plano_normalizar_nome($dadosPlanoReservaQr['plano_nome'] ?? 'BASICO')
        : strtoupper((string)($dadosPlanoReservaQr['plano_nome'] ?? 'BASICO'));
}
$isEmpresarialReservaQr = $planoAtualNome === 'EMPRESARIAL';

function reserva_qr_item_imagem_url($imagem): ?string
{
    $imagem = trim((string)$imagem);
    if ($imagem === '') {
        return null;
    }

    if (preg_match('#^https?://#i', $imagem)) {
        return $imagem;
    }

    return ltrim($imagem, '/');
}

function reserva_qr_logo_url(?string $logo): ?string
{
    $logo = trim((string)$logo);
    if ($logo === '') {
        return null;
    }

    if (preg_match('#^https?://#i', $logo)) {
        return $logo;
    }

    $logo = str_replace('\\', '/', $logo);
    $logo = preg_replace('#^(\./)+#', '', $logo);
    $logo = preg_replace('#^/?src/public/#i', '', $logo);
    $logo = ltrim($logo, '/');
    if ($logo === '') {
        return null;
    }

    $localFile = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $logo);
    if (!is_file($localFile)) {
        return null;
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');

    return $scheme . '://' . $host . ($scriptDir !== '' ? $scriptDir . '/' : '/') . $logo;
}

function reserva_qr_formatar_data_hora($valor): string
{
    $valor = trim((string)$valor);
    if ($valor === '') {
        return '';
    }

    $formatos = ['Y-m-d H:i:s', 'Y-m-d H:i', 'd/m/Y H:i'];
    foreach ($formatos as $formato) {
        $dt = DateTimeImmutable::createFromFormat($formato, $valor);
        if ($dt instanceof DateTimeImmutable) {
            return $dt->format('d/m/Y H:i');
        }
    }

    return $valor;
}

function reserva_qr_mesa_label($numero): string
{
    $numero = trim((string)$numero);
    if ($numero !== '' && is_numeric($numero) && (int)$numero === 0) {
        return 'Balcão';
    }

    return 'Mesa ' . $numero;
}

$restauranteLogoUrl = reserva_qr_logo_url($restaurante['logo'] ?? null);
$restauranteNome = trim((string)($restaurante['nome'] ?? 'Restaurante'));
$restauranteInicial = strtoupper(substr(preg_replace('/\s+/', '', $restauranteNome), 0, 2) ?: 'R');
$restauranteCidade = trim((string)($restaurante['cidade'] ?? ''));
$mesaSessaoNumero = trim((string)($sessao['mesa_numero'] ?? ''));
$mesaSessaoLabel = reserva_qr_mesa_label($mesaSessaoNumero);
$sessaoExpiraEm = reserva_qr_utc_para_local($sessao['expires_at'] ?? null);
$totalMesasVisiveis = is_array($mesas) ? count($mesas) : 0;
$mesasLivres = 0;
foreach ($mesas as $mesaItem) {
    if (strtoupper((string)($mesaItem['status'] ?? '')) === 'LIVRE') {
        $mesasLivres++;
    }
}
?>
<!DOCTYPE html>
<html lang="pt">

<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($contextoValido ? $restauranteNome : 'Reserva via QR Code'); ?></title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --bg: #fff8ef;
            --ink: #1f2937;
            --muted: #6b7280;
            --accent: #d95d39;
            --accent-2: #f0a202;
            --line: rgba(31, 41, 55, 0.08);
            --card: rgba(255, 255, 255, 0.85);
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Outfit', sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at top right, rgba(240, 162, 2, 0.18), transparent 28%),
                radial-gradient(circle at bottom left, rgba(217, 93, 57, 0.12), transparent 30%),
                linear-gradient(180deg, #fffaf4 0%, #fff2df 100%);
        }

        .shell {
            max-width: 1220px;
            margin: 0 auto;
            padding: 28px 16px 40px;
        }

        .hero,
        .panel {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 28px;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.08);
            backdrop-filter: blur(10px);
        }

        .hero {
            padding: 24px;
            margin-bottom: 22px;
        }

        .hero-top {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .hero-brand-logo-shell {
            position: relative;
            flex-shrink: 0;
            padding: 5px;
            border-radius: 30px;
            background: linear-gradient(135deg, #d95d39 0%, #f0a202 48%, #ffd27a 100%);
            box-shadow: 0 16px 34px rgba(217, 93, 57, 0.28);
        }

        .hero-brand-logo {
            position: relative;
            width: 132px;
            height: 132px;
            border-radius: 26px;
            display: flex;
            align-items: stretch;
            justify-content: stretch;
            overflow: hidden;
            background: #fff;
        }

        .hero-brand-logo.has-image {
            padding: 0;
            background: transparent;
        }

        .hero-brand-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
            display: block;
            border-radius: 26px;
            -webkit-user-drag: none;
        }

        .hero-brand-logo-fallback {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 30px;
            font-weight: 800;
            letter-spacing: -0.05em;
            color: #fff;
            background: linear-gradient(145deg, #d95d39, #f0a202);
        }

        .hero-brand-copy {
            min-width: 0;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .hero-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .hero-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 11px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            line-height: 1;
            white-space: nowrap;
        }

        .hero-chip-primary {
            background: rgba(217, 93, 57, 0.12);
            border: 1px solid rgba(217, 93, 57, 0.18);
            color: #9a3412;
        }

        .hero-chip-muted {
            background: rgba(31, 41, 55, 0.05);
            border: 1px solid rgba(31, 41, 55, 0.08);
            color: #4b5563;
        }

        .hero-chip-accent {
            background: rgba(240, 162, 2, 0.14);
            border: 1px solid rgba(240, 162, 2, 0.22);
            color: #92400e;
        }

        .hero-title {
            font-family: 'Space Grotesk', sans-serif;
            font-size: clamp(1.45rem, 3.2vw, 2.15rem);
            font-weight: 700;
            margin: 0;
            line-height: 1.12;
            letter-spacing: -0.03em;
        }

        .hero-lead {
            color: var(--muted);
            margin: 0;
            font-size: 0.98rem;
            line-height: 1.55;
            max-width: 640px;
        }

        .hero-info-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
            margin-top: 18px;
            padding-top: 18px;
            border-top: 1px dashed rgba(31, 41, 55, 0.12);
        }

        .hero-info-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 14px;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.72);
            border: 1px solid rgba(31, 41, 55, 0.08);
        }

        .hero-info-icon {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            background: rgba(217, 93, 57, 0.1);
            color: #c2410c;
            font-size: 15px;
        }

        .hero-info-icon.is-success {
            background: rgba(21, 128, 61, 0.1);
            color: #15803d;
        }

        .hero-info-text strong {
            display: block;
            font-size: 0.92rem;
            color: var(--ink);
            margin-bottom: 2px;
        }

        .hero-info-text span {
            display: block;
            font-size: 0.84rem;
            color: var(--muted);
            line-height: 1.4;
        }

        .hero-brand-kicker {
            display: none;
        }

        .hero-subtitle {
            color: var(--muted);
            margin: 0;
            max-width: 760px;
        }

        .session-chip {
            display: none;
        }

        .grid {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 22px;
        }

        .panel {
            padding: 22px;
        }

        .panel h2 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.15rem;
            margin: 0 0 16px;
        }

        .mesa-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(132px, 1fr));
            gap: 12px;
        }

        .mesa-item {
            border-radius: 18px;
            padding: 14px;
            border: 1px solid var(--line);
            background: #fff;
        }

        .mesa-item strong {
            display: block;
            font-size: 1rem;
        }

        .mesa-status {
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .status-LIVRE {
            color: #15803d;
        }

        .status-OCUPADA {
            color: #b91c1c;
        }

        .status-RESERVADA {
            color: #b45309;
        }

        .menu-category {
            margin-bottom: 16px;
        }

        .preorder-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 6px;
        }

        .preorder-header h2 {
            margin-bottom: 0;
        }

        .preorder-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
            padding: 6px 10px;
            border-radius: 999px;
            color: #08725c;
            background: rgba(24, 168, 134, 0.1);
            border: 1px solid rgba(24, 168, 134, 0.16);
            font-size: 0.72rem;
            font-weight: 800;
        }

        .preorder-toolbar {
            padding: 12px;
            margin-bottom: 16px;
            border: 1px solid var(--line);
            border-radius: 16px;
            background: rgba(246, 249, 251, 0.82);
        }

        .preorder-search {
            position: relative;
        }

        .preorder-search i {
            position: absolute;
            left: 13px;
            top: 50%;
            color: var(--muted);
            transform: translateY(-50%);
            pointer-events: none;
        }

        .preorder-search .form-control {
            padding-left: 38px;
            background: #fff;
        }

        .category-filters {
            display: flex;
            gap: 7px;
            overflow-x: auto;
            padding-top: 10px;
            scrollbar-width: thin;
        }

        .category-filter {
            border: 1px solid rgba(15, 42, 67, 0.12);
            border-radius: 999px;
            padding: 6px 11px;
            color: var(--muted);
            background: #fff;
            font-size: 0.76rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .category-filter.active {
            color: #fff;
            border-color: transparent;
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
        }

        .menu-category.is-filtered,
        .menu-item.is-filtered {
            display: none;
        }

        .menu-category h3 {
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #9a3412;
            margin-bottom: 10px;
        }

        .menu-item {
            display: grid;
            grid-template-columns: 84px minmax(0, 1fr) auto;
            align-items: center;
            gap: 14px;
            padding: 12px 0;
            border-bottom: 1px dashed rgba(31, 41, 55, 0.12);
        }

        .menu-item:last-child {
            border-bottom: 0;
        }

        .menu-item-image {
            width: 84px;
            height: 84px;
            object-fit: cover;
            border-radius: 16px;
            border: 1px solid rgba(31, 41, 55, 0.08);
            background: #fff7ed;
            display: block;
        }

        .menu-item-body {
            min-width: 0;
        }

        .menu-item-title {
            display: block;
            font-size: 1rem;
            margin-bottom: 4px;
        }

        .menu-item-description {
            color: var(--muted);
            font-size: 0.9rem;
            line-height: 1.4;
        }

        .menu-item-price {
            font-size: 1rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .menu-item-actions {
            grid-column: 2 / 4;
            display: flex;
            gap: 8px;
            align-items: center;
            justify-content: flex-end;
        }

        .qty-stepper {
            display: inline-grid;
            grid-template-columns: 32px 42px 32px;
            align-items: center;
            overflow: hidden;
            border: 1px solid rgba(31, 41, 55, 0.16);
            border-radius: 11px;
            background: #fff;
        }

        .qty-stepper button {
            width: 32px;
            height: 34px;
            border: 0;
            color: var(--ink);
            background: transparent;
            font-weight: 800;
        }

        .qty-stepper button:hover {
            background: rgba(11, 95, 113, 0.08);
        }

        .menu-qty {
            width: 42px;
            height: 34px;
            padding: 0;
            border: 0;
            outline: 0;
            text-align: center;
            font-weight: 800;
            appearance: textfield;
            -moz-appearance: textfield;
        }

        .menu-qty::-webkit-inner-spin-button,
        .menu-qty::-webkit-outer-spin-button {
            margin: 0;
            appearance: none;
        }

        .btn-mini {
            border: 1px solid rgba(31, 41, 55, 0.2);
            border-radius: 10px;
            padding: 6px 10px;
            background: #fff;
            font-weight: 700;
        }

        .btn-add-cart {
            color: #fff;
            border-color: transparent;
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            transition: transform 0.18s ease, opacity 0.18s ease;
        }

        .btn-add-cart:hover {
            transform: translateY(-1px);
        }

        .btn-add-cart.is-added {
            background: #15803d;
        }

        .cart-box {
            margin-top: 20px;
            padding: 16px;
            border: 1px solid rgba(11, 95, 113, 0.14);
            border-radius: 18px;
            background: linear-gradient(145deg, rgba(246, 249, 251, 0.95), #fff);
        }

        .cart-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
        }

        .cart-header h3 {
            margin: 0;
            font-size: 1rem;
        }

        .cart-count {
            min-width: 28px;
            padding: 4px 8px;
            border-radius: 999px;
            color: #fff;
            background: #10243e;
            text-align: center;
            font-size: 0.75rem;
            font-weight: 800;
        }

        .cart-row {
            display: grid;
            grid-template-columns: 1fr auto auto;
            gap: 10px;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px dashed rgba(31, 41, 55, 0.12);
        }

        .cart-row-controls {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .cart-qty-btn {
            width: 26px;
            height: 26px;
            padding: 0;
            border: 1px solid rgba(15, 42, 67, 0.12);
            border-radius: 8px;
            color: var(--ink);
            background: #fff;
            font-weight: 800;
        }

        .cart-empty {
            padding: 18px 12px;
            color: var(--muted);
            text-align: center;
        }

        .cart-empty i {
            display: block;
            margin-bottom: 7px;
            color: rgba(11, 95, 113, 0.35);
            font-size: 1.5rem;
        }

        .cart-total-box {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 0 4px;
        }

        .cart-total-box span {
            display: block;
            color: var(--muted);
            font-size: 0.72rem;
        }

        .cart-actions {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            gap: 8px;
            margin-top: 12px;
        }

        .btn-go-reservation {
            border: 0;
            border-radius: 12px;
            padding: 10px 14px;
            color: #fff;
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            font-weight: 800;
        }

        .btn-go-reservation:disabled {
            cursor: not-allowed;
            opacity: 0.45;
        }

        .preorder-persist {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 10px;
            color: var(--muted);
            font-size: 0.76rem;
        }

        .order-success {
            margin-top: 12px;
            border: 1px solid rgba(21, 128, 61, 0.25);
            background: rgba(240, 253, 244, 0.9);
            color: #14532d;
            border-radius: 14px;
            padding: 12px 14px;
        }

        .order-success strong {
            display: block;
            font-size: 1.05rem;
        }

        .form-control,
        .form-select {
            border-radius: 14px;
            border: 1px solid rgba(31, 41, 55, 0.12);
            padding: 12px 14px;
        }

        .btn-accent {
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            border: 0;
            border-radius: 16px;
            color: #fff;
            font-weight: 700;
            padding: 12px 18px;
        }

        .helper {
            color: var(--muted);
            font-size: 0.93rem;
            min-height: 22px;
        }

        .readonly-banner {
            border-radius: 18px;
            border: 1px solid rgba(217, 119, 6, 0.22);
            background: rgba(255, 247, 237, 0.95);
            color: #9a3412;
            padding: 14px 16px;
            margin-bottom: 18px;
            font-weight: 600;
        }

        body.reserva-empresarial {
            --bg: #f3f7fb;
            --ink: #10243e;
            --muted: #607087;
            --accent: #0b5f71;
            --accent-2: #18a886;
            --line: rgba(15, 42, 67, 0.1);
            --card: rgba(255, 255, 255, 0.94);
            background:
                radial-gradient(circle at 90% 4%, rgba(24, 168, 134, 0.13), transparent 25%),
                radial-gradient(circle at 7% 90%, rgba(11, 95, 113, 0.1), transparent 27%),
                linear-gradient(180deg, #f7fafc 0%, #eef4f8 100%);
        }

        .reserva-empresarial .shell {
            max-width: 1280px;
            padding-top: 24px;
        }

        .reserva-empresarial .hero,
        .reserva-empresarial .panel {
            border-radius: 22px;
            box-shadow: 0 18px 50px rgba(15, 42, 67, 0.09);
        }

        .reserva-empresarial .hero {
            position: relative;
            overflow: hidden;
            border-top: 4px solid var(--accent);
        }

        .reserva-empresarial .hero::after {
            content: '';
            position: absolute;
            width: 220px;
            height: 220px;
            right: -92px;
            top: -105px;
            border-radius: 50%;
            border: 32px solid rgba(24, 168, 134, 0.08);
            pointer-events: none;
        }

        .reserva-empresarial .hero-brand-logo-shell {
            border-radius: 24px;
            background: linear-gradient(145deg, #0b5f71, #18a886);
            box-shadow: 0 14px 30px rgba(11, 95, 113, 0.22);
        }

        .reserva-empresarial .hero-brand-logo,
        .reserva-empresarial .hero-brand-logo img {
            border-radius: 19px;
        }

        .reserva-empresarial .hero-chip-primary {
            color: #075263;
            background: rgba(11, 95, 113, 0.09);
            border-color: rgba(11, 95, 113, 0.16);
        }

        .reserva-empresarial .hero-chip-accent {
            color: #08725c;
            background: rgba(24, 168, 134, 0.1);
            border-color: rgba(24, 168, 134, 0.18);
        }

        .enterprise-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 11px;
            border-radius: 999px;
            color: #fff;
            background: linear-gradient(135deg, #10243e, #0b5f71);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .reserva-empresarial .hero-info-icon {
            color: #0b5f71;
            background: rgba(11, 95, 113, 0.09);
        }

        .reserva-empresarial .hero-info-icon.is-success {
            color: #08725c;
            background: rgba(24, 168, 134, 0.1);
        }

        .smart-assistant {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            gap: 13px;
            align-items: start;
            padding: 14px 16px;
            margin-bottom: 18px;
            border: 1px solid rgba(11, 95, 113, 0.13);
            border-radius: 16px;
            background: linear-gradient(135deg, rgba(11, 95, 113, 0.06), rgba(24, 168, 134, 0.08));
        }

        .smart-assistant-icon {
            width: 40px;
            height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 13px;
            color: #fff;
            background: linear-gradient(135deg, #0b5f71, #18a886);
            box-shadow: 0 8px 18px rgba(11, 95, 113, 0.18);
        }

        .smart-assistant strong {
            display: block;
            color: #10243e;
            font-size: 0.94rem;
            margin-bottom: 2px;
        }

        .smart-assistant p {
            margin: 0;
            color: #607087;
            font-size: 0.84rem;
            line-height: 1.45;
        }

        .section-heading {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 16px;
        }

        .section-heading h2 {
            margin-bottom: 3px;
        }

        .section-heading p {
            margin: 0;
            color: var(--muted);
            font-size: 0.82rem;
        }

        .section-step {
            flex-shrink: 0;
            padding: 6px 10px;
            border: 1px solid rgba(11, 95, 113, 0.14);
            border-radius: 999px;
            color: #075263;
            background: rgba(11, 95, 113, 0.07);
            font-size: 0.7rem;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .form-progress {
            height: 5px;
            overflow: hidden;
            margin: -4px 0 18px;
            border-radius: 999px;
            background: rgba(15, 42, 67, 0.07);
        }

        .form-progress-bar {
            width: 0;
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, var(--accent), var(--accent-2));
            transition: width 0.25s ease;
        }

        .form-block-label {
            display: flex;
            align-items: center;
            gap: 7px;
            margin: 4px 0 10px;
            color: #10243e;
            font-size: 0.75rem;
            font-weight: 800;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .form-block-label i {
            color: var(--accent);
        }

        .reservation-panel .form-label {
            margin-bottom: 5px;
            color: #40536b;
            font-size: 0.78rem;
            font-weight: 700;
        }

        .reservation-panel .form-control,
        .reservation-panel .form-select {
            background-color: #fbfdfe;
        }

        .reservation-actions {
            display: grid;
            grid-template-columns: minmax(0, 0.85fr) minmax(0, 1.15fr);
            gap: 9px;
            margin-top: 14px;
        }

        .reservation-actions .btn {
            min-height: 46px;
            border-radius: 13px;
            font-weight: 800;
        }

        .tables-panel-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 13px;
        }

        .tables-panel-header h2 {
            margin-bottom: 3px;
        }

        .tables-panel-header p {
            margin: 0;
            color: var(--muted);
            font-size: 0.8rem;
        }

        .live-indicator {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
            padding: 6px 9px;
            border-radius: 999px;
            color: #08725c;
            background: rgba(24, 168, 134, 0.1);
            font-size: 0.68rem;
            font-weight: 800;
            text-transform: uppercase;
        }

        .live-indicator::before {
            content: '';
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #18a886;
            box-shadow: 0 0 0 4px rgba(24, 168, 134, 0.12);
            animation: livePulse 1.8s ease-in-out infinite;
        }

        @keyframes livePulse {
            50% {
                box-shadow: 0 0 0 7px rgba(24, 168, 134, 0.03);
            }
        }

        .table-filters {
            display: flex;
            gap: 6px;
            overflow-x: auto;
            margin-bottom: 13px;
            padding-bottom: 2px;
        }

        .table-filter {
            border: 1px solid rgba(15, 42, 67, 0.1);
            border-radius: 999px;
            padding: 6px 10px;
            color: var(--muted);
            background: #f8fafc;
            font-size: 0.7rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .table-filter.active {
            color: #fff;
            border-color: transparent;
            background: #10243e;
        }

        .mesa-item.is-filtered {
            display: none;
        }

        .mesa-item-top,
        .mesa-item-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .mesa-item-icon {
            width: 30px;
            height: 30px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 9px;
            color: #0b5f71;
            background: rgba(11, 95, 113, 0.08);
        }

        .mesa-item-meta {
            margin-top: 9px;
            color: var(--muted);
            font-size: 0.75rem;
        }

        .mesa-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 7px;
            border-radius: 999px;
            background: rgba(15, 42, 67, 0.05);
            font-size: 0.65rem;
        }

        .mesa-status::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: currentColor;
        }

        .tables-empty {
            display: none;
            padding: 18px;
            color: var(--muted);
            text-align: center;
        }

        .tables-empty.is-visible {
            display: block;
        }

        .reservation-summary {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 8px;
            padding: 12px;
            margin-top: 16px;
            border-radius: 16px;
            background: #f6f9fb;
            border: 1px solid rgba(15, 42, 67, 0.08);
        }

        .reservation-summary-item {
            min-width: 0;
            padding: 3px 8px;
            border-right: 1px solid rgba(15, 42, 67, 0.08);
        }

        .reservation-summary-item:last-child {
            border-right: 0;
        }

        .reservation-summary-item span,
        .reservation-summary-item strong {
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .reservation-summary-item span {
            color: var(--muted);
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .reservation-summary-item strong {
            margin-top: 2px;
            font-size: 0.85rem;
        }

        .reserva-empresarial .mesa-item {
            position: relative;
            transition: transform 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
        }

        .reserva-empresarial .mesa-item.is-selectable {
            cursor: pointer;
        }

        .reserva-empresarial .mesa-item.is-selectable:hover,
        .reserva-empresarial .mesa-item.is-selected {
            transform: translateY(-2px);
            border-color: rgba(11, 95, 113, 0.42);
            box-shadow: 0 10px 24px rgba(15, 42, 67, 0.1);
        }

        .reserva-empresarial .mesa-item.is-selected {
            background: linear-gradient(145deg, #f3fbfa, #fff);
        }

        .mesa-recommended {
            display: none;
            margin-top: 6px;
            color: #08725c;
            font-size: 0.68rem;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .mesa-item.is-recommended .mesa-recommended {
            display: block;
        }

        .reserva-empresarial .form-control:focus,
        .reserva-empresarial .form-select:focus {
            border-color: rgba(11, 95, 113, 0.5);
            box-shadow: 0 0 0 0.2rem rgba(11, 95, 113, 0.1);
        }

        .reserva-empresarial .panel h2 {
            color: #10243e;
        }

        @media (max-width: 960px) {
            .grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .hero-top {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
            }

            .hero-brand-logo {
                width: 112px;
                height: 112px;
            }

            .hero-info-bar {
                grid-template-columns: 1fr;
            }

            .menu-item {
                grid-template-columns: 72px minmax(0, 1fr);
            }

            .menu-item-image {
                width: 72px;
                height: 72px;
            }

            .menu-item-price {
                grid-column: 2;
            }

            .menu-item-actions {
                grid-column: 1 / 3;
                justify-content: space-between;
            }

            .btn-add-cart {
                flex: 1;
            }

            .cart-row {
                grid-template-columns: minmax(0, 1fr) auto;
            }

            .cart-row .btn-remove-cart {
                grid-column: 1 / 3;
                justify-self: end;
            }

            .cart-actions {
                grid-template-columns: 1fr;
            }

            .reservation-actions {
                grid-template-columns: 1fr;
            }

            .reservation-summary {
                grid-template-columns: 1fr;
            }

            .reservation-summary-item {
                border-right: 0;
                border-bottom: 1px solid rgba(15, 42, 67, 0.08);
                padding: 6px 4px;
            }

            .reservation-summary-item:last-child {
                border-bottom: 0;
            }
        }
    </style>
</head>

<body class="<?php echo $isEmpresarialReservaQr ? 'reserva-empresarial' : ''; ?>">
    <div class="shell">
        <?php if (!$contextoValido): ?>
            <div class="hero">
                <h1 class="hero-title">Sessao QR indisponivel</h1>
                <p class="hero-subtitle"><?php echo htmlspecialchars($contexto['message'] ?? 'O link de reserva nao esta mais ativo.'); ?></p>
                <div class="mt-4 d-flex flex-wrap justify-content-center gap-2">
                    <a class="btn btn-primary" href="mesas.php">
                        <i class="fas fa-qrcode me-2" aria-hidden="true"></i>Gerar novo QR
                    </a>
                    <button class="btn btn-outline-secondary" type="button" onclick="window.location.reload()">
                        <i class="fas fa-rotate-right me-2" aria-hidden="true"></i>Tentar novamente
                    </button>
                </div>
            </div>
        <?php else: ?>
            <section class="hero">
                <div class="hero-top">
                    <div class="hero-brand-logo-shell">
                        <div class="hero-brand-logo<?php echo $restauranteLogoUrl ? ' has-image' : ''; ?>">
                            <?php if ($restauranteLogoUrl): ?>
                                <img
                                    src="<?php echo htmlspecialchars($restauranteLogoUrl); ?>"
                                    alt="Logo <?php echo htmlspecialchars($restauranteNome); ?>"
                                    loading="eager"
                                    decoding="async">
                            <?php else: ?>
                                <span class="hero-brand-logo-fallback"><?php echo htmlspecialchars($restauranteInicial); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="hero-brand-copy">
                        <div class="hero-meta">
                            <span class="hero-chip hero-chip-primary">
                                <i class="fas fa-qrcode" aria-hidden="true"></i>
                                Reserva via QR
                            </span>
                            <?php if ($isEmpresarialReservaQr): ?>
                                <span class="enterprise-badge">
                                    <i class="fas fa-building" aria-hidden="true"></i>
                                    Experiencia empresarial
                                </span>
                            <?php endif; ?>
                            <?php if ($restauranteCidade !== ''): ?>
                                <span class="hero-chip hero-chip-muted">
                                    <i class="fas fa-location-dot" aria-hidden="true"></i>
                                    <?php echo htmlspecialchars($restauranteCidade); ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($mesaSessaoNumero !== ''): ?>
                                <span class="hero-chip hero-chip-accent">
                                    <i class="fas fa-chair" aria-hidden="true"></i>
                                    <?php echo htmlspecialchars($mesaSessaoLabel); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <h1 class="hero-title"><?php echo htmlspecialchars($restauranteNome); ?></h1>
                        <p class="hero-lead">
                            <?php if ($isEmpresarialReservaQr): ?>
                                Uma experiencia de reserva inteligente: escolha sua mesa, confirme os detalhes e antecipe seu pedido com seguranca.
                            <?php else: ?>
                                Reserve sua mesa, acompanhe a disponibilidade em tempo real e envie seu pedido em poucos passos.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                <div class="hero-info-bar">
                    <?php if ($sessaoExpiraEm !== ''): ?>
                        <div class="hero-info-item">
                            <span class="hero-info-icon" aria-hidden="true"><i class="fas fa-hourglass-half"></i></span>
                            <div class="hero-info-text">
                                <strong>Sessao ativa</strong>
                                <span>Valida ate <?php echo htmlspecialchars($sessaoExpiraEm); ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($totalMesasVisiveis > 0): ?>
                        <div class="hero-info-item">
                            <span class="hero-info-icon is-success" aria-hidden="true"><i class="fas fa-table-cells"></i></span>
                            <div class="hero-info-text">
                                <strong><?php echo (int)$mesasLivres; ?> de <?php echo (int)$totalMesasVisiveis; ?> mesas livres</strong>
                                <span>Atualizacao em tempo real no painel abaixo</span>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($sessaoConsumida): ?>
                        <div class="hero-info-item">
                            <span class="hero-info-icon" aria-hidden="true"><i class="fas fa-circle-check"></i></span>
                            <div class="hero-info-text">
                                <strong>Reserva ja enviada</strong>
                                <span>Modo consulta em <?php echo htmlspecialchars(reserva_qr_utc_para_local($sessao['consumed_at'] ?? '')); ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <div class="grid">
                <section class="panel reservation-panel">
                    <div class="section-heading">
                        <div>
                            <h2><i class="fas fa-calendar-check me-2"></i>Solicitar reserva</h2>
                            <p>Preencha os dados para confirmar sua experiencia.</p>
                        </div>
                        <span class="section-step">Etapa principal</span>
                    </div>
                    <?php if ($isEmpresarialReservaQr && !$sessaoConsumida): ?>
                        <div class="smart-assistant" id="smartAssistant">
                            <span class="smart-assistant-icon" aria-hidden="true"><i class="fas fa-wand-magic-sparkles"></i></span>
                            <div>
                                <strong>Assistente inteligente de reserva</strong>
                                <p id="smartAssistantText">Informe o numero de pessoas e indicaremos automaticamente a mesa livre mais adequada.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($sessaoConsumida): ?>
                        <div class="readonly-banner">
                            Esta reserva via QR ja foi enviada em <?php echo htmlspecialchars((string)$sessao['consumed_at']); ?>. O link continua disponivel apenas para consulta.
                        </div>
                    <?php endif; ?>
                    <div id="feedbackReservaQr" class="alert d-none"></div>
                    <form id="formReservaQr">
                        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                        <fieldset <?php echo $sessaoConsumida ? 'disabled' : ''; ?>>
                            <div class="form-progress" aria-hidden="true">
                                <div class="form-progress-bar" id="reservationProgress"></div>
                            </div>
                            <div class="form-block-label"><i class="fas fa-address-card"></i>Seus dados</div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Nome</label>
                                    <input class="form-control" name="nome_cliente" required placeholder="Nome completo" autocomplete="name">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Telefone</label>
                                    <input class="form-control" name="telefone_cliente" value="<?php echo htmlspecialchars((string)($sessao['telefone_cliente'] ?? '')); ?>" placeholder="Telefone" autocomplete="tel" inputmode="tel">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Email</label>
                                    <input class="form-control" type="email" name="email_cliente" placeholder="Email" autocomplete="email">
                                </div>
                                <div class="col-12">
                                    <div class="form-block-label mt-1 mb-0"><i class="fas fa-calendar-days"></i>Detalhes da visita</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Mesa</label>
                                    <select class="form-select" id="mesa_id" name="mesa_atribuida">
                                        <option value="">Melhor mesa disponivel</option>
                                        <?php foreach ($mesas as $mesa): ?>
                                            <option value="<?php echo (int)$mesa['id']; ?>" <?php echo !empty($sessao['mesa_id']) && (int)$sessao['mesa_id'] === (int)$mesa['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars(reserva_qr_mesa_label($mesa['numero'] ?? '')); ?> • <?php echo (int)($mesa['capacidade'] ?? 0); ?> lugares
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Data</label>
                                    <input class="form-control" type="date" id="data_reserva" name="data_reserva" required min="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Hora</label>
                                    <input class="form-control" type="time" id="hora_reserva" name="hora_reserva" required value="19:00">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Pessoas</label>
                                    <input class="form-control" type="number" id="quantidade_pessoas" name="quantidade_pessoas" min="1" value="<?php echo !empty($sessao['mesa_capacidade']) ? min(2, (int)$sessao['mesa_capacidade']) : 2; ?>" required placeholder="N.º de pessoas">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Observacoes</label>
                                    <textarea class="form-control" rows="3" name="observacoes" placeholder="Observações da reserva"></textarea>
                                </div>
                            </div>
                            <?php if ($isEmpresarialReservaQr): ?>
                                <div class="reservation-summary" aria-live="polite">
                                    <div class="reservation-summary-item">
                                        <span>Quando</span>
                                        <strong id="summaryQuando">Defina data e hora</strong>
                                    </div>
                                    <div class="reservation-summary-item">
                                        <span>Grupo</span>
                                        <strong id="summaryPessoas">2 pessoas</strong>
                                    </div>
                                    <div class="reservation-summary-item">
                                        <span>Mesa</span>
                                        <strong id="summaryMesa">Selecao inteligente</strong>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div class="helper mt-3" id="preBloqueioHint">
                                <?php if (isset($preBloqueio['mesa_numero']) && (string)$preBloqueio['mesa_numero'] !== ''): ?>
                                    <?php echo htmlspecialchars(reserva_qr_mesa_label($preBloqueio['mesa_numero'])); ?> ja esta pre-bloqueada ate <?php echo htmlspecialchars(reserva_qr_utc_para_local((string)$preBloqueio['expires_at'], 'H:i')); ?>.
                                <?php else: ?>
                                    Opcional: pre-bloqueie para segurar uma mesa enquanto preenche o formulario.
                                <?php endif; ?>
                            </div>
                            <div class="reservation-actions">
                                <button type="button" class="btn btn-outline-dark" id="btnPreBloquear"><i class="fas fa-lock me-2"></i>Pre-bloquear mesa</button>
                                <button type="submit" class="btn btn-accent" id="btnEnviarReserva"><i class="fas fa-circle-check me-2"></i>Enviar reserva</button>
                            </div>
                        </fieldset>
                    </form>
                </section>

                <div class="d-flex flex-column gap-3">
                    <section class="panel tables-panel">
                        <div class="tables-panel-header">
                            <div>
                                <h2><i class="fas fa-chair me-2"></i>Mesas em tempo real</h2>
                                <p><strong id="liveFreeTables"><?php echo (int)$mesasLivres; ?></strong> disponiveis de <?php echo (int)$totalMesasVisiveis; ?></p>
                            </div>
                            <span class="live-indicator">Ao vivo</span>
                        </div>
                        <div class="table-filters" id="tableFilters" aria-label="Filtrar mesas">
                            <button type="button" class="table-filter active" data-table-filter="">Todas</button>
                            <button type="button" class="table-filter" data-table-filter="LIVRE">Livres</button>
                            <button type="button" class="table-filter" data-table-filter="OCUPADA">Ocupadas</button>
                            <button type="button" class="table-filter" data-table-filter="RESERVADA">Reservadas</button>
                        </div>
                        <div class="mesa-grid">
                            <?php foreach ($mesas as $mesa): ?>
                                <?php $mesaLivre = strtoupper((string)($mesa['status'] ?? '')) === 'LIVRE'; ?>
                                <div
                                    class="mesa-item<?php echo $isEmpresarialReservaQr && $mesaLivre ? ' is-selectable' : ''; ?>"
                                    data-mesa-id="<?php echo (int)$mesa['id']; ?>"
                                    data-mesa-numero="<?php echo htmlspecialchars((string)$mesa['numero']); ?>"
                                    data-mesa-label="<?php echo htmlspecialchars(reserva_qr_mesa_label($mesa['numero'] ?? '')); ?>"
                                    data-mesa-capacidade="<?php echo (int)($mesa['capacidade'] ?? 0); ?>"
                                    data-mesa-status="<?php echo htmlspecialchars(strtoupper((string)($mesa['status'] ?? ''))); ?>"
                                    <?php echo $isEmpresarialReservaQr && $mesaLivre ? 'role="button" tabindex="0" aria-label="Selecionar ' . htmlspecialchars(reserva_qr_mesa_label($mesa['numero'] ?? '')) . '"' : ''; ?>>
                                    <div class="mesa-item-top">
                                        <strong><?php echo htmlspecialchars(reserva_qr_mesa_label($mesa['numero'] ?? '')); ?></strong>
                                        <span class="mesa-item-icon"><i class="fas fa-chair"></i></span>
                                    </div>
                                    <div class="mesa-item-meta">
                                        <span><i class="fas fa-user-group me-1"></i><?php echo (int)($mesa['capacidade'] ?? 0); ?> pessoas</span>
                                        <div class="mesa-status status-<?php echo htmlspecialchars((string)$mesa['status']); ?>">
                                            <?php echo htmlspecialchars((string)$mesa['status']); ?>
                                        </div>
                                    </div>
                                    <?php if ($isEmpresarialReservaQr && $mesaLivre): ?>
                                        <span class="mesa-recommended"><i class="fas fa-sparkles me-1"></i>Melhor opcao</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="tables-empty" id="tablesEmpty">
                            <i class="fas fa-chair d-block mb-2"></i>Nenhuma mesa neste estado.
                        </div>
                    </section>

                    <section class="panel">
                        <div class="preorder-header">
                            <h2><i class="fas fa-bag-shopping me-2"></i>Pre-pedido da reserva</h2>
                            <span class="preorder-status"><i class="fas fa-shield-check"></i>Sem cobranca agora</span>
                        </div>
                        <p class="helper mb-3">Escolha com antecedencia e reduza o tempo de espera. O pre-pedido sera confirmado junto com a reserva.</p>
                        <?php if (!empty($cardapio['success']) && !empty($cardapio['cardapio_preview'])): ?>
                            <div class="preorder-toolbar">
                                <div class="preorder-search">
                                    <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                                    <input type="search" class="form-control" id="preorderSearch" placeholder="Procurar no cardapio..." autocomplete="off">
                                </div>
                                <div class="category-filters" id="preorderCategoryFilters" aria-label="Filtrar categorias">
                                    <button type="button" class="category-filter active" data-category-filter="">Todos</button>
                                    <?php foreach ($cardapio['cardapio_preview'] as $categoria): ?>
                                        <button type="button" class="category-filter" data-category-filter="<?php echo htmlspecialchars((string)$categoria['nome']); ?>">
                                            <?php echo htmlspecialchars((string)$categoria['nome']); ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php foreach ($cardapio['cardapio_preview'] as $categoria): ?>
                                <div class="menu-category" data-category="<?php echo htmlspecialchars((string)$categoria['nome']); ?>">
                                    <h3><?php echo htmlspecialchars((string)$categoria['nome']); ?></h3>
                                    <?php foreach (($categoria['itens'] ?? []) as $item): ?>
                                        <?php $imagemItem = reserva_qr_item_imagem_url($item['imagem'] ?? null); ?>
                                        <div class="menu-item" data-produto-id="<?php echo (int)$item['id']; ?>" data-produto-nome="<?php echo htmlspecialchars((string)$item['nome']); ?>" data-produto-preco="<?php echo (float)($item['preco'] ?? 0); ?>">
                                            <?php if ($imagemItem): ?>
                                                <img
                                                    src="<?php echo htmlspecialchars($imagemItem); ?>"
                                                    alt="<?php echo htmlspecialchars((string)$item['nome']); ?>"
                                                    class="menu-item-image"
                                                    loading="lazy">
                                            <?php else: ?>
                                                <div class="menu-item-image d-flex align-items-center justify-content-center text-muted">
                                                    <i class="fas fa-utensils"></i>
                                                </div>
                                            <?php endif; ?>
                                            <div class="menu-item-body">
                                                <strong class="menu-item-title"><?php echo htmlspecialchars((string)$item['nome']); ?></strong>
                                                <div class="menu-item-description"><?php echo htmlspecialchars((string)($item['descricao'] ?? '')); ?></div>
                                            </div>
                                            <strong class="menu-item-price"><?php echo number_format((float)($item['preco'] ?? 0), 2, ',', '.'); ?> MZN</strong>
                                            <div class="menu-item-actions">
                                                <div class="qty-stepper" aria-label="Quantidade">
                                                    <button type="button" class="qty-step" data-delta="-1" aria-label="Diminuir quantidade">−</button>
                                                    <input type="number" class="menu-qty" min="1" max="50" value="1" aria-label="Quantidade do item">
                                                    <button type="button" class="qty-step" data-delta="1" aria-label="Aumentar quantidade">+</button>
                                                </div>
                                                <button type="button" class="btn-mini btn-add-cart"><i class="fas fa-plus me-1"></i>Adicionar</button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                            <div class="cart-box">
                                <div class="cart-header">
                                    <h3><i class="fas fa-basket-shopping me-2"></i>Seu pre-pedido</h3>
                                    <span class="cart-count" id="qrCartCount" aria-label="Quantidade de itens">0</span>
                                </div>
                                <div id="qrCartItems"></div>
                                <div class="cart-total-box">
                                    <div>
                                        <strong>Total estimado</strong>
                                        <span>Pagamento conforme atendimento do restaurante</span>
                                    </div>
                                    <strong id="qrCartTotal">0,00 MZN</strong>
                                </div>
                                <div class="cart-actions">
                                    <button type="button" class="btn btn-outline-dark" id="btnLimparPedidoQr">Limpar</button>
                                    <button type="button" class="btn-go-reservation" id="btnIrReserva" disabled>
                                        Confirmar com a reserva <i class="fas fa-arrow-up ms-1"></i>
                                    </button>
                                </div>
                                <div class="preorder-persist"><i class="fas fa-cloud-arrow-down"></i><span id="preorderPersistText">O pre-pedido fica guardado neste dispositivo enquanto voce conclui.</span></div>
                                <div id="qrOrderSuccess" class="order-success d-none"></div>
                                <div id="qrOrderTracker" class="order-success d-none mt-2"></div>
                            </div>
                        <?php else: ?>
                            <p class="text-muted mb-0"><?php echo htmlspecialchars((string)($cardapio['message'] ?? 'Cardapio indisponivel no momento.')); ?></p>
                        <?php endif; ?>
                    </section>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($contextoValido): ?>
        <script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
            (function() {
                const token = <?php echo json_encode($token, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
                const api = 'api/reserva_publica.php';
                const isEmpresarial = <?php echo $isEmpresarialReservaQr ? 'true' : 'false'; ?>;
                let mesaPreBloqueadaId = <?php echo json_encode(!empty($preBloqueio['mesa_id']) ? (int)$preBloqueio['mesa_id'] : null); ?>;

                const form = document.getElementById('formReservaQr');
                const feedback = document.getElementById('feedbackReservaQr');
                const hint = document.getElementById('preBloqueioHint');

                const btnPreBloquear = document.getElementById('btnPreBloquear');
                const btnEnviar = document.getElementById('btnEnviarReserva');
                const reservationProgress = document.getElementById('reservationProgress');

                const cartItemsEl = document.getElementById('qrCartItems');
                const cartTotalEl = document.getElementById('qrCartTotal');
                const cartCountEl = document.getElementById('qrCartCount');
                const btnLimparPedidoQr = document.getElementById('btnLimparPedidoQr');
                const btnIrReserva = document.getElementById('btnIrReserva');
                const preorderPersistText = document.getElementById('preorderPersistText');

                const qrOrderSuccess = document.getElementById('qrOrderSuccess');
                const qrOrderTracker = document.getElementById('qrOrderTracker');

                const carrinho = new Map();
                const CART_TTL_MS = 24 * 60 * 60 * 1000;
                const cartStorageKey = 'qr_cart_' + token;

                function showFeedback(message, type) {
                    if (!feedback) return;
                    feedback.className = 'alert alert-' + type;
                    feedback.textContent = message;
                    feedback.classList.remove('d-none');
                }

                function ocultarConfirmacaoPedido() {
                    if (!qrOrderSuccess) return;
                    qrOrderSuccess.classList.add('d-none');
                    qrOrderSuccess.innerHTML = '';
                }

                function formatMzn(valor) {
                    return Number(valor || 0).toLocaleString('pt-MZ', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }) + ' MZN';
                }

                function escapeHtml(valor) {
                    return String(valor ?? '')
                        .replaceAll('&', '&amp;')
                        .replaceAll('<', '&lt;')
                        .replaceAll('>', '&gt;')
                        .replaceAll('"', '&quot;')
                        .replaceAll("'", '&#039;');
                }

                function renderCarrinho() {
                    if (!cartItemsEl || !cartTotalEl) return;

                    let total = 0;
                    let totalItens = 0;
                    carrinho.forEach((item) => {
                        total += Number(item.preco) * Number(item.qtd);
                        totalItens += Number(item.qtd);
                    });

                    cartTotalEl.textContent = formatMzn(total);
                    if (cartCountEl) cartCountEl.textContent = String(totalItens);
                    if (btnIrReserva) btnIrReserva.disabled = totalItens === 0;
                    if (btnLimparPedidoQr) btnLimparPedidoQr.disabled = totalItens === 0;
                    if (btnEnviar && !btnEnviar.disabled) {
                        btnEnviar.innerHTML = totalItens > 0
                            ? '<i class="fas fa-circle-check me-2"></i>Confirmar reserva e pre-pedido (' + totalItens + ')'
                            : '<i class="fas fa-circle-check me-2"></i>Enviar reserva';
                    }

                    if (carrinho.size === 0) {
                        cartItemsEl.innerHTML =
                            '<div class="cart-empty"><i class="fas fa-basket-shopping"></i>' +
                            '<strong>Seu pre-pedido esta vazio</strong><br>' +
                            '<span>Adicione itens do cardapio acima.</span></div>';
                        return;
                    }

                    const rows = [];

                    carrinho.forEach((item, key) => {
                        const subtotal = Number(item.preco) * Number(item.qtd);
                        rows.push(
                            '<div class="cart-row">' +
                            '<div><strong>' + escapeHtml(item.nome) + '</strong><div class="cart-row-controls mt-1">' +
                            '<button type="button" class="cart-qty-btn btn-cart-qty" data-key="' + escapeHtml(key) + '" data-delta="-1" aria-label="Diminuir">−</button>' +
                            '<span class="px-1 small"><strong>' + Number(item.qtd) + '</strong></span>' +
                            '<button type="button" class="cart-qty-btn btn-cart-qty" data-key="' + escapeHtml(key) + '" data-delta="1" aria-label="Aumentar">+</button>' +
                            '</div></div>' +
                            '<div><strong>' + formatMzn(subtotal) + '</strong></div>' +
                            '<button type="button" class="btn-mini btn-remove-cart" data-key="' + escapeHtml(key) + '"><i class="fas fa-trash-can me-1"></i>Remover</button>' +
                            '</div>'
                        );
                    });

                    cartItemsEl.innerHTML = rows.join('');
                }

                function atualizarEstadoPersistencia() {
                    if (!preorderPersistText) return;
                    preorderPersistText.textContent = carrinho.size > 0
                        ? 'Pre-pedido guardado automaticamente neste dispositivo.'
                        : 'O pre-pedido fica guardado neste dispositivo enquanto voce conclui.';
                }

                function normalizarBusca(valor) {
                    return String(valor || '')
                        .normalize('NFD')
                        .replace(/[\u0300-\u036f]/g, '')
                        .toLowerCase()
                        .trim();
                }

                function ativarFiltrosPrePedido() {
                    const search = document.getElementById('preorderSearch');
                    const filters = document.getElementById('preorderCategoryFilters');
                    if (!search || !filters) return;

                    let categoriaAtiva = '';
                    const aplicarFiltros = () => {
                        const termo = normalizarBusca(search.value);
                        document.querySelectorAll('.menu-category[data-category]').forEach((categoria) => {
                            const correspondeCategoria = !categoriaAtiva || categoria.dataset.category === categoriaAtiva;
                            let itensVisiveis = 0;

                            categoria.querySelectorAll('.menu-item').forEach((item) => {
                                const correspondeBusca = !termo || normalizarBusca(item.textContent).includes(termo);
                                const visivel = correspondeCategoria && correspondeBusca;
                                item.classList.toggle('is-filtered', !visivel);
                                if (visivel) itensVisiveis++;
                            });

                            categoria.classList.toggle('is-filtered', itensVisiveis === 0);
                        });
                    };

                    search.addEventListener('input', aplicarFiltros);
                    filters.addEventListener('click', (event) => {
                        const button = event.target.closest('.category-filter');
                        if (!button) return;
                        categoriaAtiva = String(button.dataset.categoryFilter || '');
                        filters.querySelectorAll('.category-filter').forEach((item) => item.classList.toggle('active', item === button));
                        aplicarFiltros();
                    });
                }

                function gerarIdemKey() {
                    const rand = Math.random().toString(36).slice(2, 10);
                    return 'qr_' + Date.now().toString(36) + '_' + rand;
                }

                function salvarCarrinhoLocal(idemKeyAtual) {
                    const itens = [];
                    carrinho.forEach((v, k) => itens.push({
                        key: k,
                        id: v.id,
                        nome: v.nome,
                        preco: v.preco,
                        qtd: v.qtd
                    }));

                    const payload = {
                        createdAt: Date.now(),
                        idemKey: idemKeyAtual || '',
                        itens: itens
                    };

                    localStorage.setItem(cartStorageKey, JSON.stringify(payload));
                    atualizarEstadoPersistencia();
                }

                function carregarCarrinhoLocal() {
                    try {
                        const raw = localStorage.getItem(cartStorageKey);
                        if (!raw) return {
                            idemKey: ''
                        };

                        const payload = JSON.parse(raw);
                        if (!payload || !Array.isArray(payload.itens)) return {
                            idemKey: ''
                        };

                        if (!payload.createdAt || (Date.now() - Number(payload.createdAt)) > CART_TTL_MS) {
                            localStorage.removeItem(cartStorageKey);
                            return {
                                idemKey: ''
                            };
                        }

                        payload.itens.forEach((it) => {
                            if (!it || !it.id || !it.qtd) return;
                            carrinho.set(String(it.id), {
                                id: Number(it.id),
                                nome: String(it.nome || ''),
                                preco: Number(it.preco || 0),
                                qtd: Number(it.qtd || 1)
                            });
                        });

                        return {
                            idemKey: String(payload.idemKey || '')
                        };
                    } catch (e) {
                        return {
                            idemKey: ''
                        };
                    }
                }

                function limparCarrinhoLocal() {
                    localStorage.removeItem(cartStorageKey);
                    atualizarEstadoPersistencia();
                }

                function mostrarConfirmacaoPedido(numeroPedido, totalTexto, itensCount) {
                    if (!qrOrderSuccess) return;

                    const tempoEstimadoMin = Math.max(12, Math.min(45, 10 + (Number(itensCount) * 4)));
                    const numeroLabel = numeroPedido ? ('#' + numeroPedido) : '';

                    qrOrderSuccess.innerHTML =
                        '<strong>Pre-pedido ' + (numeroLabel ? 'confirmado ' + numeroLabel : 'guardado') + '</strong>' +
                        '<span>Total: ' + (totalTexto || '0,00') + ' MZN</span><br>' +
                        '<span>Ligado a esta reserva. Tempo estimado apos check-in: ' + tempoEstimadoMin + ' min</span>';

                    qrOrderSuccess.classList.remove('d-none');
                }

                function atualizarAcompanhamentoPedido() {
                    if (!qrOrderTracker) return;

                    fetch(api + '?route=acompanhar_pedido&token=' + encodeURIComponent(token), {
                            method: 'GET',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        })
                        .then(r => r.json())
                        .then(data => {
                            if (data && data.success && !data.pedido && data.pre_pedido_em_espera) {
                                // Pre-pedido guardado: so vai para a cozinha quando o cliente fizer o check-in.
                                qrOrderTracker.innerHTML =
                                    '<strong>Pre-pedido guardado</strong>' +
                                    '<span>Vai para a cozinha quando chegar ao restaurante e fizer o check-in.</span>';
                                qrOrderTracker.classList.remove('d-none');
                                return;
                            }
                            if (!data || !data.success || !data.pedido) return;

                            const p = data.pedido;
                            const r = data.resumo_itens || {};

                            qrOrderTracker.innerHTML =
                                '<strong>Acompanhar pre-pedido #' + String(p.numero_pedido || '--') + '</strong>' +
                                '<span>Status: <b>' + String(p.status || '-') + '</b></span><br>' +
                                '<span>Itens - Pend: ' + Number(r.pendentes || 0) +
                                ' | Preparo: ' + Number(r.em_preparo || 0) +
                                ' | Prontos: ' + Number(r.prontos || 0) +
                                ' | Entregues: ' + Number(r.entregues || 0) + '</span>';

                            qrOrderTracker.classList.remove('d-none');
                        })
                        .catch(() => {});
                }

                function aplicarMesaSelecionada(mesaId) {
                    const mesaNumero = Number(mesaId || 0);
                    if (mesaNumero <= 0) return;

                    mesaPreBloqueadaId = mesaNumero;
                    const mesaSelect = document.getElementById('mesa_id');
                    if (mesaSelect) {
                        mesaSelect.value = String(mesaNumero);
                    }

                    if (isEmpresarial) {
                        document.querySelectorAll('.mesa-item[data-mesa-id]').forEach((card) => {
                            card.classList.toggle('is-selected', Number(card.dataset.mesaId || 0) === mesaNumero);
                        });
                        atualizarResumoInteligente(false);
                    }
                }

                function formatarDataResumo(dataIso) {
                    if (!dataIso) return '';
                    const partes = String(dataIso).split('-');
                    if (partes.length !== 3) return dataIso;
                    return partes[2] + '/' + partes[1] + '/' + partes[0];
                }

                function mesaLabel(numero) {
                    const valor = String(numero ?? '').trim();
                    return valor !== '' && Number(valor) === 0 ? 'Balcão' : 'Mesa ' + valor;
                }

                function recomendarMesaInteligente(aplicarSelecao) {
                    if (!isEmpresarial) return null;

                    const quantidade = Math.max(1, Number(document.getElementById('quantidade_pessoas')?.value || 1));
                    const disponiveis = Array.from(document.querySelectorAll('.mesa-item[data-mesa-status="LIVRE"]'))
                        .filter((card) => Number(card.dataset.mesaCapacidade || 0) >= quantidade)
                        .sort((a, b) => Number(a.dataset.mesaCapacidade || 0) - Number(b.dataset.mesaCapacidade || 0));

                    document.querySelectorAll('.mesa-item.is-recommended').forEach((card) => card.classList.remove('is-recommended'));
                    const recomendada = disponiveis[0] || null;
                    const assistantText = document.getElementById('smartAssistantText');

                    if (recomendada) {
                        recomendada.classList.add('is-recommended');
                        if (assistantText) {
                            assistantText.textContent = 'A ' + (recomendada.dataset.mesaLabel || mesaLabel(recomendada.dataset.mesaNumero)) +
                                ' e a melhor opcao para ' + quantidade + (quantidade === 1 ? ' pessoa' : ' pessoas') +
                                ', com melhor aproveitamento do espaco.';
                        }
                        if (aplicarSelecao) {
                            aplicarMesaSelecionada(Number(recomendada.dataset.mesaId || 0));
                        }
                    } else if (assistantText) {
                        assistantText.textContent = 'Nao encontramos uma mesa livre com essa capacidade agora. Envie a reserva com selecao automatica para a equipa avaliar.';
                    }

                    return recomendada;
                }

                function atualizarResumoInteligente(recomendar) {
                    if (!isEmpresarial) return;

                    const data = document.getElementById('data_reserva')?.value || '';
                    const hora = document.getElementById('hora_reserva')?.value || '';
                    const quantidade = Math.max(1, Number(document.getElementById('quantidade_pessoas')?.value || 1));
                    const mesaSelect = document.getElementById('mesa_id');
                    const mesaOption = mesaSelect && mesaSelect.value ? mesaSelect.options[mesaSelect.selectedIndex] : null;

                    const quandoEl = document.getElementById('summaryQuando');
                    const pessoasEl = document.getElementById('summaryPessoas');
                    const mesaEl = document.getElementById('summaryMesa');

                    if (quandoEl) quandoEl.textContent = data ? (formatarDataResumo(data) + (hora ? ' as ' + hora : '')) : 'Defina data e hora';
                    if (pessoasEl) pessoasEl.textContent = quantidade + (quantidade === 1 ? ' pessoa' : ' pessoas');
                    if (mesaEl) mesaEl.textContent = mesaOption ? mesaOption.textContent.trim() : 'Selecao inteligente';

                    if (recomendar) recomendarMesaInteligente(false);
                }

                function ativarExperienciaEmpresarial() {
                    if (!isEmpresarial) return;

                    const mesaSelect = document.getElementById('mesa_id');
                    const quantidadeInput = document.getElementById('quantidade_pessoas');
                    const dataInput = document.getElementById('data_reserva');
                    const horaInput = document.getElementById('hora_reserva');

                    document.querySelectorAll('.mesa-item.is-selectable').forEach((card) => {
                        const selecionar = () => aplicarMesaSelecionada(Number(card.dataset.mesaId || 0));
                        card.addEventListener('click', selecionar);
                        card.addEventListener('keydown', (event) => {
                            if (event.key === 'Enter' || event.key === ' ') {
                                event.preventDefault();
                                selecionar();
                            }
                        });
                    });

                    if (mesaSelect) mesaSelect.addEventListener('change', () => {
                        if (mesaSelect.value) aplicarMesaSelecionada(Number(mesaSelect.value));
                        else {
                            document.querySelectorAll('.mesa-item.is-selected').forEach((card) => card.classList.remove('is-selected'));
                            atualizarResumoInteligente(false);
                        }
                    });
                    if (quantidadeInput) quantidadeInput.addEventListener('input', () => atualizarResumoInteligente(true));
                    if (dataInput) dataInput.addEventListener('change', () => atualizarResumoInteligente(false));
                    if (horaInput) horaInput.addEventListener('change', () => atualizarResumoInteligente(false));

                    atualizarResumoInteligente(true);
                }

                function atualizarProgressoReserva() {
                    if (!form || !reservationProgress) return;
                    const obrigatorios = Array.from(form.querySelectorAll('[required]'));
                    const preenchidos = obrigatorios.filter((campo) => String(campo.value || '').trim() !== '').length;
                    const progresso = obrigatorios.length > 0 ? Math.round((preenchidos / obrigatorios.length) * 100) : 0;
                    reservationProgress.style.width = progresso + '%';
                }

                function ativarPainelMesas() {
                    const filters = document.getElementById('tableFilters');
                    const empty = document.getElementById('tablesEmpty');
                    if (!filters) return;

                    filters.addEventListener('click', (event) => {
                        const button = event.target.closest('.table-filter');
                        if (!button) return;
                        const status = String(button.dataset.tableFilter || '');
                        let visiveis = 0;

                        filters.querySelectorAll('.table-filter').forEach((item) => item.classList.toggle('active', item === button));
                        document.querySelectorAll('.mesa-item[data-mesa-status]').forEach((card) => {
                            const mostrar = !status || card.dataset.mesaStatus === status;
                            card.classList.toggle('is-filtered', !mostrar);
                            if (mostrar) visiveis++;
                        });
                        if (empty) empty.classList.toggle('is-visible', visiveis === 0);
                    });
                }

                function enviarPedidoQrComCarrinho(mesaResolvida) {
                    if (carrinho.size === 0) {
                        return Promise.resolve({
                            success: true,
                            skipped: true
                        });
                    }

                    ocultarConfirmacaoPedido();

                    let idemKey = gerarIdemKey();
                    const saved = carregarCarrinhoLocal();
                    if (saved && saved.idemKey) idemKey = saved.idemKey;
                    salvarCarrinhoLocal(idemKey);

                    const itens = [];
                    carrinho.forEach((item) => itens.push({
                        produto_id: item.id,
                        quantidade: item.qtd
                    }));

                    const mesaId = String(
                        mesaResolvida ||
                        mesaPreBloqueadaId ||
                        document.getElementById('mesa_id')?.value ||
                        ''
                    );
                    const observacoes = document.querySelector('[name="observacoes"]')?.value || '';
                    const nomeCliente = document.querySelector('[name="nome_cliente"]')?.value || '';
                    const telefoneCliente = document.querySelector('[name="telefone_cliente"]')?.value || '';

                    return fetch(api + '?route=pedido_qr', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: JSON.stringify({
                                token: token,
                                mesa_id: mesaId,
                                observacao: observacoes,
                                cliente_nome: nomeCliente,
                                cliente_telefone: telefoneCliente,
                                idem_key: idemKey,
                                itens: itens
                            })
                        })
                        .then(r => r.json())
                        .then(data => {
                            if (data && data.success) {
                                mostrarConfirmacaoPedido(data.numero_pedido || '', data.total || '0,00', itens.length);
                                carrinho.clear();
                                limparCarrinhoLocal();
                                renderCarrinho();
                                atualizarAcompanhamentoPedido();
                                return {
                                    success: true,
                                    data: data
                                };
                            }
                            return {
                                success: false,
                                data: data
                            };
                        });
                }

                document.addEventListener('click', function(event) {
                    const qtyStep = event.target.closest('.qty-step');
                    if (qtyStep) {
                        const input = qtyStep.closest('.qty-stepper')?.querySelector('.menu-qty');
                        if (!input) return;
                        const delta = Number(qtyStep.dataset.delta || 0);
                        input.value = String(Math.max(1, Math.min(50, Number(input.value || 1) + delta)));
                        return;
                    }

                    const addBtn = event.target.closest('.btn-add-cart');
                    if (addBtn) {
                        ocultarConfirmacaoPedido();

                        const card = addBtn.closest('.menu-item');
                        if (!card) return;

                        const id = Number(card.getAttribute('data-produto-id') || 0);
                        const nome = String(card.getAttribute('data-produto-nome') || '');
                        const preco = Number(card.getAttribute('data-produto-preco') || 0);

                        const qtdInput = card.querySelector('.menu-qty');
                        const qtd = Math.max(1, Math.min(50, Number(qtdInput ? qtdInput.value : 1) || 1));

                        if (id <= 0 || !nome) return;

                        const current = carrinho.get(String(id));
                        carrinho.set(String(id), {
                            id: id,
                            nome: nome,
                            preco: preco,
                            qtd: (current ? Number(current.qtd) : 0) + qtd
                        });

                        salvarCarrinhoLocal('');
                        renderCarrinho();
                        addBtn.classList.add('is-added');
                        addBtn.innerHTML = '<i class="fas fa-check me-1"></i>Adicionado';
                        window.setTimeout(() => {
                            addBtn.classList.remove('is-added');
                            addBtn.innerHTML = '<i class="fas fa-plus me-1"></i>Adicionar';
                        }, 1100);
                        return;
                    }

                    const qtyCartBtn = event.target.closest('.btn-cart-qty');
                    if (qtyCartBtn) {
                        ocultarConfirmacaoPedido();
                        const key = String(qtyCartBtn.getAttribute('data-key') || '');
                        const item = carrinho.get(key);
                        if (!item) return;
                        const novaQtd = Math.max(0, Math.min(50, Number(item.qtd) + Number(qtyCartBtn.dataset.delta || 0)));
                        if (novaQtd === 0) carrinho.delete(key);
                        else item.qtd = novaQtd;
                        salvarCarrinhoLocal('');
                        renderCarrinho();
                        return;
                    }

                    const removeBtn = event.target.closest('.btn-remove-cart');
                    if (removeBtn) {
                        ocultarConfirmacaoPedido();
                        const key = String(removeBtn.getAttribute('data-key') || '');
                        if (key) {
                            carrinho.delete(key);
                            salvarCarrinhoLocal('');
                            renderCarrinho();
                        }
                    }
                });

                if (btnLimparPedidoQr) {
                    btnLimparPedidoQr.addEventListener('click', function() {
                        ocultarConfirmacaoPedido();
                        carrinho.clear();
                        limparCarrinhoLocal();
                        renderCarrinho();
                    });
                }

                if (btnIrReserva) {
                    btnIrReserva.addEventListener('click', function() {
                        form?.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                        window.setTimeout(() => form?.querySelector('[name="nome_cliente"]')?.focus(), 450);
                    });
                }

                if (btnPreBloquear) {
                    btnPreBloquear.addEventListener('click', function() {
                        const dataReserva = document.getElementById('data_reserva')?.value || '';
                        const horaReserva = document.getElementById('hora_reserva')?.value || '';
                        const qtd = document.getElementById('quantidade_pessoas')?.value || '';
                        const mesaId = document.getElementById('mesa_id')?.value || '';

                        if (!dataReserva || !horaReserva || !qtd) {
                            showFeedback('Preencha data, hora e quantidade para pre-bloquear.', 'warning');
                            return;
                        }

                        btnPreBloquear.disabled = true;
                        btnPreBloquear.textContent = 'Pre-bloqueando...';

                        fetch(api + '?route=prebloqueio', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest'
                                },
                                body: JSON.stringify({
                                    token: token,
                                    data_reserva: dataReserva,
                                    hora_reserva: horaReserva,
                                    quantidade_pessoas: qtd,
                                    mesa_id: mesaId || undefined
                                })
                            })
                            .then(r => r.json())
                            .then(data => {
                                if (data && data.success) {
                                    const mesaId = data.mesa?.id || data.mesa_id || null;
                                    if (mesaId) {
                                        aplicarMesaSelecionada(mesaId);
                                    }
                                    const mesaNumero = data.mesa?.numero ?? '';
                                    const expiresAt = data.expires_at_local || data.expires_at || '';
                                    if (hint) {
                                        hint.textContent = mesaNumero ?
                                            (mesaLabel(mesaNumero) + ' pre-bloqueada ate ' + expiresAt + '.') :
                                            (data.message || 'Mesa pre-bloqueada.');
                                    }
                                    showFeedback(data.message || 'Mesa pre-bloqueada temporariamente.', 'success');
                                } else {
                                    showFeedback((data && data.message) || 'Nao foi possivel pre-bloquear a mesa.', 'danger');
                                }
                            })
                            .catch(function() {
                                showFeedback('Erro de conexao ao pre-bloquear mesa.', 'danger');
                            })
                            .finally(function() {
                                btnPreBloquear.disabled = false;
                                btnPreBloquear.textContent = 'Pre-bloquear mesa';
                            });
                    });
                }

                if (form) {
                    form.addEventListener('submit', function(e) {
                        e.preventDefault();

                        if (btnEnviar) {
                            btnEnviar.disabled = true;
                            btnEnviar.textContent = 'Enviando...';
                        }

                        const formData = new FormData(form);
                        const payload = {};
                        formData.forEach(function(value, key) {
                            payload[key] = value;
                        });

                        fetch(api, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest'
                                },
                                body: JSON.stringify(payload)
                            })
                            .then(r => r.json())
                            .then(function(data) {
                                if (!data || !data.success) {
                                    throw new Error((data && data.message) || 'Erro ao enviar reserva.');
                                }

                                showFeedback(data.message || 'Reserva enviada com sucesso!', 'success');

                                if (data.mesa_atribuida) {
                                    aplicarMesaSelecionada(data.mesa_atribuida);
                                }

                                return enviarPedidoQrComCarrinho(data.mesa_atribuida || mesaPreBloqueadaId).then(function(pedidoResult) {
                                    if (pedidoResult && !pedidoResult.success && !pedidoResult.skipped) {
                                        const pedidoMsg = (pedidoResult.data && pedidoResult.data.message) ?
                                            pedidoResult.data.message :
                                            'Reserva enviada, mas houve falha ao enviar o pre-pedido do carrinho.';
                                        showFeedback(pedidoMsg, 'warning');
                                    }

                                    const fieldset = form.querySelector('fieldset');
                                    if (fieldset) fieldset.disabled = true;
                                    if (btnPreBloquear) btnPreBloquear.disabled = true;
                                    if (btnEnviar) btnEnviar.textContent = 'Reserva enviada';
                                });
                            })
                            .catch(function(err) {
                                showFeedback(err.message || 'Erro ao enviar reserva.', 'danger');
                            })
                            .finally(function() {
                                if (btnEnviar && !form.querySelector('fieldset[disabled]')) {
                                    btnEnviar.disabled = false;
                                    renderCarrinho();
                                }
                            });
                    });
                }

                if (mesaPreBloqueadaId) {
                    aplicarMesaSelecionada(mesaPreBloqueadaId);
                }

                carregarCarrinhoLocal();
                renderCarrinho();
                atualizarEstadoPersistencia();
                ativarFiltrosPrePedido();
                if (form) {
                    form.addEventListener('input', atualizarProgressoReserva);
                    form.addEventListener('change', atualizarProgressoReserva);
                }
                atualizarProgressoReserva();
                ativarPainelMesas();
                atualizarAcompanhamentoPedido();
                ativarExperienciaEmpresarial();
            })();
        </script>
    <?php endif; ?>
</body>

</html>
