<?php
require_once __DIR__ . '/app_base.php';
// Proteção da página
include_once APP_BASE . '/src/config/auth_check.php';
include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/csrf.php';
include_once APP_BASE . '/src/config/plano_check.php';
include_once APP_BASE . '/src/config/restaurante_context.php';
include_once APP_BASE . '/src/config/mesa_display_helper.php';
include_once APP_BASE . '/src/Model/Mesa.php';
include_once APP_BASE . '/src/config/reserva_qr_schema.php';

// Conectar ao banco
$database = new Database();
$db = $database->getConnection();

$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');

$restauranteId = session_restaurante_contexto_id();
$restauranteFeatureId = session_restaurante_capability_id();
$restauranteFeatureId = $restauranteFeatureId > 0 ? $restauranteFeatureId : $restauranteId;
$temPedidosOnline = $restauranteFeatureId > 0 && plano_tem_funcionalidade_db($restauranteFeatureId, 'pedidos_online');
$dados_plano = plano_get_dados($restauranteFeatureId);
$plano_atual = plano_normalizar_nome($dados_plano['plano_nome'] ?? 'BASICO');
$temReservasQrMesas = $restauranteFeatureId > 0 && plano_tem_funcionalidade_db($restauranteFeatureId, 'reservas_qr');
$temMultiFilialMesas = $restauranteFeatureId > 0 && plano_tem_funcionalidade_db($restauranteFeatureId, 'multi_filial');
require_once __DIR__ . '/includes/empresarial_ui.php';
$mesa = new Mesa($db);
$balcaoCanonico = mesa_garantir_balcao_canonico($db, (int)$restauranteId);
require_once APP_BASE . '/src/config/balcao_canal.php';
$balcao2Permitido = balcao2_max_permitido($restauranteFeatureId > 0 ? $restauranteFeatureId : $restauranteId) >= 2;
$balcao2 = balcao2_config($db, (int)$restauranteId, $restauranteFeatureId > 0 ? $restauranteFeatureId : null);
$mesas = $mesa->listar($restauranteId);
$csrfToken = csrf_get_token();
$dataHoje = date('Y-m-d');
$mesaQrUrls = [];
$restauranteNomeQr = '';
try {
    reserva_qr_ensure_tables($db);
} catch (Throwable $e) {
    // schema opcional
}
try {
    $stmtNomeRest = $db->prepare('SELECT nome FROM restaurantes WHERE id = :id LIMIT 1');
    $stmtNomeRest->execute([':id' => (int)$restauranteId]);
    $restauranteNomeQr = (string)($stmtNomeRest->fetchColumn() ?: '');
} catch (Throwable $e) {
    $restauranteNomeQr = '';
}

$stmtReservasHoje = $db->prepare("
    SELECT
        r.id,
        r.nome_cliente,
        r.telefone_cliente,
        r.data_reserva,
        r.hora_reserva,
        r.quantidade_pessoas,
        r.status,
        r.mesa_atribuida,
        r.observacoes,
        m.numero AS mesa_numero
    FROM reservas r
    LEFT JOIN mesas m ON m.id = r.mesa_atribuida
    WHERE r.restaurante_id = :restaurante_id
      AND r.data_reserva >= :data_hoje
      AND r.status IN ('pendente', 'confirmado', 'chegou')
    ORDER BY
        FIELD(r.status, 'pendente', 'confirmado', 'chegou'),
        r.data_reserva ASC,
        r.hora_reserva ASC
    LIMIT 12
");
$stmtReservasHoje->execute([
    ':restaurante_id' => $restauranteId,
    ':data_hoje' => $dataHoje,
]);
$proximasReservas = $stmtReservasHoje->fetchAll(PDO::FETCH_ASSOC);

// Mapa mesa_id → reserva activa do dia (pendente/confirmado), preferindo a mais próxima no tempo.
$reservaPorMesaId = [];
foreach ($proximasReservas as $reservaMapRow) {
    $mesaMapId = (int)($reservaMapRow['mesa_atribuida'] ?? 0);
    $statusMap = strtolower(trim((string)($reservaMapRow['status'] ?? '')));
    $dataMap = (string)($reservaMapRow['data_reserva'] ?? '');
    if ($mesaMapId <= 0 || $dataMap !== $dataHoje || !in_array($statusMap, ['pendente', 'confirmado'], true)) {
        continue;
    }
    if (!isset($reservaPorMesaId[$mesaMapId])) {
        $reservaPorMesaId[$mesaMapId] = $reservaMapRow;
        continue;
    }
    $atualMap = $reservaPorMesaId[$mesaMapId];
    $chaveNova = (string)($reservaMapRow['hora_reserva'] ?? '');
    $chaveAtual = (string)($atualMap['hora_reserva'] ?? '');
    if ($chaveNova < $chaveAtual) {
        $reservaPorMesaId[$mesaMapId] = $reservaMapRow;
    }
}

$stmtResumoReservas = $db->prepare("
    SELECT
        COUNT(*) AS total_hoje,
        SUM(CASE WHEN status = 'pendente' THEN 1 ELSE 0 END) AS pendentes_hoje,
        SUM(CASE WHEN status = 'confirmado' THEN 1 ELSE 0 END) AS confirmadas_hoje,
        SUM(CASE WHEN status = 'chegou' THEN 1 ELSE 0 END) AS chegaram_hoje
    FROM reservas
    WHERE restaurante_id = :restaurante_id
      AND data_reserva >= :data_hoje
      AND status IN ('pendente', 'confirmado', 'chegou')
");
$stmtResumoReservas->execute([
    ':restaurante_id' => $restauranteId,
    ':data_hoje' => $dataHoje,
]);
$resumoReservasHoje = $stmtResumoReservas->fetch(PDO::FETCH_ASSOC) ?: [
    'total_hoje' => 0,
    'pendentes_hoje' => 0,
    'confirmadas_hoje' => 0,
    'chegaram_hoje' => 0,
];

$mesasEmpReservas = null;
if ($plano_atual === 'EMPRESARIAL' && $temReservasQrMesas && $db instanceof PDO) {
    try {
        $stmtNoShow = $db->prepare("
            SELECT COUNT(*) FROM reservas
            WHERE restaurante_id = :rid
              AND LOWER(TRIM(status)) IN ('no-show','noshow','no_show')
              AND data_reserva >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ");
        $stmtNoShow->execute([':rid' => (int)$restauranteId]);
        $noShow30 = (int)$stmtNoShow->fetchColumn();

        $stmtHoraRes = $db->prepare("
            SELECT DATE_FORMAT(hora_reserva, '%H:00') AS hora, COUNT(*) AS qtd
            FROM reservas
            WHERE restaurante_id = :rid
              AND data_reserva >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
              AND status NOT IN ('cancelado','CANCELADO')
              AND hora_reserva IS NOT NULL
            GROUP BY DATE_FORMAT(hora_reserva, '%H:00')
            ORDER BY qtd DESC
            LIMIT 1
        ");
        $stmtHoraRes->execute([':rid' => (int)$restauranteId]);
        $horaResRow = $stmtHoraRes->fetch(PDO::FETCH_ASSOC) ?: null;
        $horaPicoRes = (string)($horaResRow['hora'] ?? '—');
        $horaPicoResQtd = (int)($horaResRow['qtd'] ?? 0);

        $stmtMesaQuente = $db->prepare("
            SELECT COALESCE(m.numero, r.mesa_atribuida) AS mesa, COUNT(*) AS qtd
            FROM reservas r
            LEFT JOIN mesas m ON m.id = r.mesa_atribuida
            WHERE r.restaurante_id = :rid
              AND r.data_reserva >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
              AND r.status NOT IN ('cancelado','CANCELADO')
              AND r.mesa_atribuida IS NOT NULL
            GROUP BY COALESCE(m.numero, r.mesa_atribuida)
            ORDER BY qtd DESC
            LIMIT 1
        ");
        $stmtMesaQuente->execute([':rid' => (int)$restauranteId]);
        $mesaQuenteRow = $stmtMesaQuente->fetch(PDO::FETCH_ASSOC) ?: null;

        $notasRes = [];
        if ($noShow30 > 0) {
            $notasRes[] = [
                'tom' => 'warn',
                'titulo' => $noShow30 . ' no-show nos últimos 30 dias',
                'txt' => 'Clientes que não apareceram. Confirme reservas no dia e ligue/WhatsApp 1–2h antes nos horários cheios.',
            ];
        }
        if ($horaPicoRes !== '—' && $horaPicoResQtd > 0) {
            $notasRes[] = [
                'tom' => 'info',
                'titulo' => 'Mais reservas à volta das ' . $horaPicoRes,
                'txt' => 'Nesse horário concentraram-se ' . $horaPicoResQtd . ' reserva(s). Tenha mesas e salão prontos 20 minutos antes.',
            ];
        }
        if ($mesaQuenteRow) {
            $notasRes[] = [
                'tom' => 'ok',
                'titulo' => 'Mesa ' . (string)($mesaQuenteRow['mesa'] ?? '?') . ' é das mais pedidas',
                'txt' => (int)($mesaQuenteRow['qtd'] ?? 0) . ' reserva(s) nessa mesa. Garanta QR legível e Wi‑Fi estável nessa zona.',
            ];
        }
        if (empty($notasRes)) {
            $notasRes[] = [
                'tom' => 'ok',
                'titulo' => 'Reservas sob controlo',
                'txt' => 'Sem alertas fortes neste momento. Continue a confirmar pendentes antes do pico.',
            ];
        }
        $mesasEmpReservas = [
            'no_show_30' => $noShow30,
            'hora_pico' => $horaPicoRes,
            'mesa_quente' => $mesaQuenteRow ? (string)($mesaQuenteRow['mesa'] ?? '') : '—',
            'notas' => array_slice($notasRes, 0, 3),
        ];
    } catch (Throwable $eMesasEmp) {
        error_log('[MESAS][EMP_RESERVAS] ' . $eMesasEmp->getMessage());
        $mesasEmpReservas = null;
    }
}

if (!function_exists('mesas_reserva_status_ui')) {
    function mesas_reserva_status_ui(string $status): array
    {
        return match (strtolower($status)) {
            'confirmado' => ['class' => 'badge-success', 'label' => 'Confirmado'],
            'chegou' => ['class' => 'badge-info', 'label' => 'No salão'],
            'pendente' => ['class' => 'badge-warning', 'label' => 'Pendente'],
            default => ['class' => 'badge-secondary', 'label' => ucfirst($status)],
        };
    }
}

if (!function_exists('mesas_tempo_conta_label')) {
    function mesas_tempo_conta_label(?int $minutos): string
    {
        if ($minutos === null) {
            return '—';
        }

        $minutos = max(0, $minutos);
        if ($minutos < 1) {
            return 'Agora';
        }
        if ($minutos < 60) {
            return $minutos . ' min';
        }

        $dias = intdiv($minutos, 1440);
        $horas = intdiv($minutos % 1440, 60);
        $resto = $minutos % 60;

        if ($dias > 0) {
            return $dias . 'd ' . $horas . 'h';
        }

        return $horas . 'h' . ($resto > 0 ? ' ' . $resto . 'min' : '');
    }
}
?>
<!DOCTYPE html>
<html lang="pt">

<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mesas - RestauranteSaaS</title>

    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link href="lib/bootstrap-5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="lib/fontawesome-6.5.1/css/all.min.css">
    <style>
        :root {
            --primary: #FF6B35;
            --primary-dark: #e55a2b;
            --secondary: #F7931E;
            --dark: #0f0f23;
            --dark-2: #1a1a2e;
            --dark-3: #16213e;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --text: #1e293b;
            --text-light: #64748b;
            --text-muted: #94a3b8;
            --bg: #f8fafc;
            --border: #e2e8f0;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Outfit', 'Segoe UI', 'Noto Sans', system-ui, -apple-system, 'Helvetica Neue', Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
        }

        .sidebar {
            width: 280px;
            min-height: 100vh;
            background: linear-gradient(180deg, var(--dark) 0%, var(--dark-2) 50%, var(--dark-3) 100%);
            position: fixed;
            left: 0;
            top: 0;
            z-index: 1000;
            padding: 0;
            display: flex;
            flex-direction: column;
        }

        .sidebar-brand {
            padding: 28px 24px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            background: linear-gradient(135deg, rgba(255, 107, 53, 0.15), rgba(247, 147, 30, 0.05));
        }

        .sidebar-brand h2 {
            color: white;
            font-family: 'Space Grotesk', 'Segoe UI Semibold', 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
            font-size: 20px;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-brand h2 i {
            color: var(--primary);
            font-size: 24px;
        }

        .sidebar-brand span {
            display: block;
            color: var(--text-muted);
            font-size: 11px;
            margin-top: 4px;
            margin-left: 36px;
            letter-spacing: 0.5px;
        }

        .sidebar-menu {
            flex: 1;
            padding: 20px 12px;
            overflow-y: auto;
        }

        .menu-title {
            color: var(--text-muted);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            padding: 12px 16px 8px;
            font-weight: 600;
        }

        .menu-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 18px;
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            border-radius: 12px;
            margin-bottom: 4px;
            transition: all 0.3s;
            cursor: pointer;
            font-weight: 500;
            font-size: 14px;
        }

        .menu-item:hover {
            background: rgba(255, 255, 255, 0.08);
            color: white;
            transform: translateX(4px);
        }

        .menu-item.active {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            box-shadow: 0 4px 15px rgba(255, 107, 53, 0.4);
        }

        .menu-item i {
            width: 20px;
            text-align: center;
            font-size: 16px;
        }

        .sidebar-footer {
            padding: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
        }

        .user-avatar {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 16px;
        }

        .user-details {
            flex: 1;
        }

        .user-name {
            color: white;
            font-weight: 600;
            font-size: 14px;
        }

        .user-role {
            color: var(--text-muted);
            font-size: 12px;
        }

        .main-content {
            margin-left: 280px;
            padding: 0;
            min-height: 100vh;
            background: var(--bg);
        }

        .top-bar {
            background: white;
            padding: 20px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .page-title {
            font-family: 'Space Grotesk', 'Segoe UI Semibold', 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
            font-size: 24px;
            font-weight: 700;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-title i {
            color: var(--primary);
        }

        .content-area {
            padding: 32px;
        }

        .hero-panel {
            display: grid;
            grid-template-columns: 1.3fr 1fr;
            gap: 20px;
            padding: 24px;
            border-radius: 24px;
            margin-bottom: 24px;
            background: linear-gradient(135deg, rgba(255, 107, 53, 0.12), rgba(247, 147, 30, 0.06));
            border: 1px solid rgba(255, 107, 53, 0.16);
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.06);
        }

        .hero-copy h3 {
            font-family: 'Space Grotesk', 'Segoe UI Semibold', 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--dark);
        }

        .hero-copy p {
            color: var(--text-light);
            margin-bottom: 0;
            max-width: 620px;
        }

        .hero-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 11px;
            border-radius: 999px;
            background: rgba(255,255,255,0.82);
            color: var(--primary-dark);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
        }

        .hero-metrics {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .hero-metric {
            background: rgba(255,255,255,0.9);
            border: 1px solid rgba(226,232,240,0.7);
            border-radius: 16px;
            padding: 14px 16px;
            min-height: 88px;
        }

        .hero-metric .label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-light);
            margin-bottom: 6px;
        }

        .hero-metric .value {
            font-size: 20px;
            font-weight: 700;
            color: var(--dark);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            box-shadow: var(--shadow);
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--primary);
            opacity: 0;
            transition: opacity 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .stat-card:hover::before {
            opacity: 1;
        }

        .stat-card.success::before {
            background: var(--success);
        }

        .stat-card.warning::before {
            background: var(--warning);
        }

        .stat-card.danger::before {
            background: var(--danger);
        }

        .stat-value {
            font-family: 'Space Grotesk', 'Segoe UI Semibold', 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
            font-size: 36px;
            font-weight: 700;
            color: var(--text);
        }

        .stat-label {
            color: var(--text-light);
            font-size: 14px;
            font-weight: 500;
        }

        .mesa-card {
            background: white;
            border-radius: 20px;
            padding: 20px 16px 16px;
            text-align: center;
            box-shadow: var(--shadow);
            transition: all 0.3s;
            border: 3px solid transparent;
            display: flex;
            flex-direction: column;
            min-height: 100%;
        }

        .mesa-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }

        .mesa-card.livre {
            border-color: var(--success);
            background: rgba(16, 185, 129, 0.05);
        }

        .mesa-card.ocupada {
            border-color: var(--danger);
            background: rgba(239, 68, 68, 0.05);
        }

        .mesa-card.reservada {
            border-color: var(--warning);
            background: rgba(245, 158, 11, 0.05);
        }

        .mesa-card-icon {
            font-size: 40px;
            line-height: 1;
            margin-bottom: 8px;
        }

        .mesa-card-icon .fa-bell-concierge {
            font-size: 34px;
            color: #0f766e;
        }

        .mesa-card-icon .fa-chair,
        .mesa-card-icon .fa-users,
        .mesa-card-icon .fa-clipboard-list {
            font-size: 34px;
            color: #64748b;
        }

        .mesa-card.ocupada .mesa-card-icon .fa-users {
            color: #dc2626;
        }

        .mesa-card.reservada .mesa-card-icon .fa-clipboard-list {
            color: #d97706;
        }

        .mesa-card.livre .mesa-card-icon .fa-chair {
            color: #16a34a;
        }

        .mesa-card-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 2px;
        }

        .mesa-card-tempo {
            margin-top: 6px;
            font-size: 11.5px;
            font-weight: 600;
            color: #0f766e;
            background: #ecfeff;
            border-radius: 999px;
            padding: 2px 10px;
            display: inline-block;
        }

        .mesa-card-tempo.is-demorada { color: #b91c1c; background: #fee2e2; }

        .mesas-estado-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            margin-top: 10px;
            font-size: 13px;
            color: rgba(255, 255, 255, 0.85);
        }

        .mesas-estado-chips span { display: inline-flex; align-items: center; gap: 6px; }

        .mesas-estado-chips span::before {
            content: '';
            width: 10px;
            height: 10px;
            border-radius: 3px;
            display: inline-block;
            background: #cbd5e1;
        }

        .mesas-estado-chips .is-livre::before { background: #22c55e; }
        .mesas-estado-chips .is-ocupada::before { background: #f59e0b; }
        .mesas-estado-chips .is-reservada::before { background: #818cf8; }
        .mesas-estado-chips strong { color: #fff; }

        .mesa-card-meta {
            color: var(--text-light);
            font-size: 12px;
            margin-bottom: 10px;
        }

        .mesa-card-reserva-cliente {
            margin: 0 0 10px;
            padding: 8px 10px;
            border-radius: 10px;
            background: rgba(245, 158, 11, 0.12);
            border: 1px solid rgba(217, 119, 6, 0.25);
            text-align: left;
            font-size: 12px;
            line-height: 1.35;
        }

        .mesa-card-reserva-cliente strong {
            display: block;
            color: #92400e;
            font-size: 13px;
            margin-bottom: 2px;
        }

        .mesa-card-reserva-cliente span {
            display: block;
            color: #78716c;
        }

        .mesa-qr-wrap {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px;
            margin: 0 auto 12px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.06);
        }

        .mesa-qr-wrap img {
            width: 72px;
            height: 72px;
            display: block;
            border-radius: 6px;
        }

        .mesa-qr-locked {
            margin: 0 auto 12px;
            color: var(--text-light);
            font-size: 12px;
        }

        .mesa-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-top: auto;
            padding-top: 12px;
            border-top: 1px solid rgba(15, 23, 42, 0.06);
        }

        .mesa-action {
            border: 1px solid transparent;
            border-radius: 12px;
            min-height: 42px;
            padding: 8px 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.02em;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease, border-color 0.2s ease;
            line-height: 1;
        }

        .mesa-action i {
            font-size: 12px;
        }

        .mesa-action:hover {
            transform: translateY(-1px);
        }

        .mesa-action:disabled,
        .mesa-action.is-current {
            opacity: 0.55;
            cursor: default;
            transform: none;
            box-shadow: none;
        }

        .mesa-action.livre {
            background: rgba(16, 185, 129, 0.12);
            color: #059669;
            border-color: rgba(16, 185, 129, 0.22);
        }

        .mesa-action.livre:hover:not(:disabled):not(.is-current) {
            background: #10b981;
            color: #fff;
            box-shadow: 0 8px 18px rgba(16, 185, 129, 0.28);
        }

        .mesa-action.ocupar {
            background: rgba(239, 68, 68, 0.12);
            color: #dc2626;
            border-color: rgba(239, 68, 68, 0.22);
        }

        .mesa-action.ocupar:hover:not(:disabled):not(.is-current) {
            background: #ef4444;
            color: #fff;
            box-shadow: 0 8px 18px rgba(239, 68, 68, 0.28);
        }

        .mesa-action.reservar {
            background: rgba(245, 158, 11, 0.14);
            color: #d97706;
            border-color: rgba(245, 158, 11, 0.28);
        }

        .mesa-action.reservar:hover:not(:disabled):not(.is-current) {
            background: #f59e0b;
            color: #fff;
            box-shadow: 0 8px 18px rgba(245, 158, 11, 0.28);
        }

        .mesa-action.qr {
            background: rgba(15, 23, 42, 0.06);
            color: #1f2937;
            border-color: rgba(15, 23, 42, 0.12);
            grid-column: 1 / -1;
        }

        .mesa-action.qr:hover {
            background: #111827;
            color: #fff;
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.22);
        }

        .badge-custom {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .badge-warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .badge-danger {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .badge-info {
            background: rgba(14, 165, 233, 0.12);
            color: #0284c7;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(255, 107, 53, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 107, 53, 0.4);
        }

        .btn-sm {
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 12px;
        }

        .modal-content {
            border: none;
            border-radius: 24px;
            box-shadow: var(--shadow-lg);
        }

        .modal-header {
            padding: 24px 28px;
            border-bottom: 1px solid var(--border);
            border-radius: 24px 24px 0 0;
        }

        .modal-title {
            font-family: 'Space Grotesk', 'Segoe UI Semibold', 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
            font-weight: 700;
            font-size: 20px;
        }

        .modal-body {
            padding: 28px;
        }

        .modal-footer {
            padding: 20px 28px;
            border-top: 1px solid var(--border);
        }

        .form-label {
            font-weight: 600;
            font-size: 13px;
            color: var(--text);
            margin-bottom: 8px;
        }

        .form-control {
            padding: 12px 16px;
            border-radius: 12px;
            border: 2px solid var(--border);
            font-size: 14px;
            transition: all 0.3s;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(255, 107, 53, 0.1);
        }

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 991px) {
            .main-content {
                margin-left: 0 !important;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .content-area {
                padding: 20px;
            }

            .mesa-action {
                min-height: 40px;
                font-size: 11px;
                padding: 8px 6px;
                gap: 5px;
            }

            .mesa-qr-wrap img {
                width: 64px;
                height: 64px;
            }
        }

        @media (min-width: 1200px) {
            .main-content {
                padding: 18px;
            }

            .top-bar {
                padding: 14px 18px;
                border-radius: 12px;
            }

            .stats-grid {
                gap: 12px;
            }

            .card,
            .stat-card,
            .mesa-card,
            .reserva-card {
                border-radius: 12px;
                box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
            }
        }
    </style>
    <link rel="stylesheet" href="css/premium.css">
    <?php empresarial_head_assets(); ?>
    <style>
        /* ---- Mesas Empresarial (nov 2026) ---- */
        @media (min-width: 992px) {
            body.plano-empresarial:not(.sidebar-desktop-collapsed) main.main-content { margin-left: 280px !important; width: calc(100% - 280px) !important; max-width: none !important; flex: none !important; }
        }
        body.plano-empresarial main.main-content { padding-left: 28px !important; padding-right: 28px !important; }
        body.plano-empresarial .mesas-emp-map-hero:not(:has(*)) { display: none !important; }
        /* Botões das mesas: 2 colunas iguais; QR numa linha própria, discreto */
        body.plano-empresarial .mesas-emp-acoes { display: grid !important; grid-template-columns: 1fr 1fr; gap: 8px; }
        body.plano-empresarial .mesas-emp-acoes .mesa-action {
            width: 100%; min-width: 0; min-height: 42px; padding: 8px 6px !important;
            display: inline-flex; align-items: center; justify-content: center; gap: 6px !important;
            font-size: 13px !important; text-decoration: none; white-space: nowrap;
        }
        body.plano-empresarial .mesas-emp-acoes .mesa-action i { margin: 0 !important; flex: 0 0 auto; }
        body.plano-empresarial .mesas-emp-acoes .mesa-action:only-child,
        body.plano-empresarial .mesas-emp-acoes .mesa-action:first-child:nth-last-child(2):not(.qr),
        body.plano-empresarial .mesas-emp-acoes .mesa-action.qr { grid-column: 1 / -1; }
        body.plano-empresarial .mesas-emp-acoes .mesa-action.qr { min-height: 34px; background: #f8fafc !important; border: 1px dashed #cbd5e1 !important; color: #475569 !important; font-weight: 600; }
        body.plano-empresarial .mesas-emp-acoes .mesa-action.conta { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; border-radius: 12px; font-weight: 700; }
        body.plano-empresarial .mesas-emp-aviso { margin: 8px 0; padding: 7px 10px; border-radius: 10px; background: #fef3c7; color: #92400e; font-size: 12.5px; font-weight: 600; }
        body.plano-empresarial .mesas-emp-aviso.is-info { background: #f1f5f9; color: #475569; }
        .nv-qr-modal { position: fixed; inset: 0; z-index: 3000; background: rgba(15,23,42,.55); display: flex; align-items: center; justify-content: center; padding: 16px; }
        .nv-qr-modal-box { background: #fff; border-radius: 18px; padding: 22px; width: min(360px, 100%); text-align: center; box-shadow: 0 30px 60px rgba(0,0,0,.25); }
        .nv-qr-modal-box h5 { margin: 0 0 4px; font-weight: 800; }
        .nv-qr-modal-box p { margin: 0 0 14px; color: #64748b; font-size: 13px; }
        .nv-qr-modal-box img { width: 260px; height: 260px; image-rendering: pixelated; border: 1px solid #e2e8f0; border-radius: 12px; padding: 8px; }
        .nv-qr-modal-acts { display: flex; gap: 8px; margin-top: 14px; }
        .nv-qr-modal-acts button { flex: 1; padding: 10px; border-radius: 10px; font-weight: 700; border: 1px solid #e2e8f0; background: #fff; }
        .nv-qr-modal-acts button.primary { background: #ea580c; color: #fff; border-color: #ea580c; }
    </style>
</head>

<body class="<?php echo htmlspecialchars(empresarial_body_class('premium-ui')); ?>">
    <div class="container-fluid">
        <div class="row">
            <!-- Botão toggle do menu para mobile -->
            <style>
                @media (max-width: 991px) {
                    .sidebar-toggle-btn {
                        display: block !important;
                    }

                    .sidebar {
                        position: fixed !important;
                        top: 0;
                        left: 0;
                        height: 100vh;
                        z-index: 2000;
                        transition: left 0.3s;
                    }

                    .sidebar.sidebar-hidden {
                        left: -100vw !important;
                    }

                    .main-content-blur {
                        filter: blur(2px) grayscale(0.1);
                        pointer-events: none;
                    }
                }
            </style>
            <!-- SIDEBAR -->
            <?php include_once __DIR__ . '/includes/sidebar.php'; ?>

            <!-- MAIN CONTENT -->
            <main class="main-content col-md-9 ms-sm-auto col-lg-10">
                <div class="content-area">
                    <?php
                    $total = 0;
                    $livres = 0;
                    $ocupadas = 0;
                    $reservadas = 0;
                    $todas_mesas = [];
                    $uniqueMesas = [];
                    $balcaoCanonicoId = (int)($balcaoCanonico['id'] ?? 0);
                    while ($m = $mesas->fetch(PDO::FETCH_ASSOC)) {
                        $mesaId = (int)($m['id'] ?? 0);
                        if ($mesaId <= 0 || isset($uniqueMesas[$mesaId])) {
                            continue;
                        }
                        $ehBalcao = mesa_eh_balcao($m['numero'] ?? '');
                        $balcao2IdGrelha = !empty($balcao2['ativo']) ? (int)$balcao2['mesa_id'] : 0;
                        if ($ehBalcao && $mesaId !== $balcaoCanonicoId && $mesaId !== $balcao2IdGrelha) {
                            continue;
                        }
                        $uniqueMesas[$mesaId] = $m;
                        $todas_mesas[] = $m;
                        if ($ehBalcao) {
                            continue;
                        }
                        $total++;
                        if ($m['status'] == 'LIVRE') $livres++;
                        if ($m['status'] == 'OCUPADA') $ocupadas++;
                        if ($m['status'] == 'RESERVADA') $reservadas++;
                    }

                    if ($temPedidosOnline) {
                        foreach ($todas_mesas as $mesaRow) {
                            if (mesa_eh_balcao($mesaRow['numero'] ?? '')) {
                                continue;
                            }
                            $mesaIdQr = (int)($mesaRow['id'] ?? 0);
                            if ($mesaIdQr <= 0) {
                                continue;
                            }

                            // QR fisico da mesa deve usar token secreto, nao rid/mesa_id previsivel.
                            $urlQrMesa = reserva_qr_get_or_create_cardapio_mesa_url($db, $restauranteId, $mesaIdQr);
                            if ($urlQrMesa) {
                                $mesaQrUrls[$mesaIdQr] = $urlQrMesa;
                            }
                        }
                    }

                    // Dados operacionais (pedido ativo) — só plano Empresarial
                    $mesaOps = [];
                    $empKpis = [
                        'ocupacao_pct' => $total > 0 ? (int)round(($ocupadas / $total) * 100) : 0,
                        'valor_aberto' => 0.0,
                        'tempo_medio' => null,
                        'atrasadas' => 0,
                        'lugares_total' => 0,
                        'lugares_livres' => 0,
                        'proxima_reserva_label' => '—',
                    ];
                    // Profissional: ha quanto tempo cada mesa esta ocupada e quantas estao em cada estado.
                    $isProfissionalMesas = ($plano_atual === 'PROFISSIONAL');
                    $mesaMinutosPro = [];
                    $mesasEstadoPro = ['livres' => 0, 'ocupadas' => 0, 'reservadas' => 0];
                    if ($isProfissionalMesas && !empty($todas_mesas)) {
                        foreach ($todas_mesas as $mPro) {
                            if (mesa_eh_balcao($mPro['numero'] ?? '')) {
                                continue;
                            }
                            $stPro = strtoupper(trim((string)($mPro['status'] ?? '')));
                            if ($stPro === 'OCUPADA' || $stPro === 'COM_PEDIDO') {
                                $mesasEstadoPro['ocupadas']++;
                            } elseif ($stPro === 'RESERVADA') {
                                $mesasEstadoPro['reservadas']++;
                            } else {
                                $mesasEstadoPro['livres']++;
                            }
                        }
                        try {
                            $stmtMinPro = $db->prepare("
                                SELECT mesa_id, MIN(TIMESTAMPDIFF(MINUTE, criado_em, NOW())) AS mins
                                FROM pedidos
                                WHERE restaurante_id = :rid
                                  AND status NOT IN ('PAGO', 'CANCELADO')
                                  AND mesa_id IS NOT NULL
                                GROUP BY mesa_id
                            ");
                            $stmtMinPro->execute([':rid' => (int)$restauranteId]);
                            foreach ($stmtMinPro->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rowMin) {
                                $mesaMinutosPro[(int)$rowMin['mesa_id']] = (int)$rowMin['mins'];
                            }
                        } catch (Throwable $e) {
                            $mesaMinutosPro = [];
                        }
                    }

                    if (!empty($isEmpresarialUi) && !empty($todas_mesas)) {
                        foreach ($todas_mesas as $mr) {
                            if (mesa_eh_balcao($mr['numero'] ?? '')) {
                                continue;
                            }
                            $cap = (int)($mr['capacidade'] ?? 4);
                            $empKpis['lugares_total'] += $cap;
                            if (($mr['status'] ?? '') === 'LIVRE') {
                                $empKpis['lugares_livres'] += $cap;
                            }
                        }
                        try {
                            $stmtOps = $db->prepare("
                                SELECT
                                    m.id AS mesa_id,
                                    p.id AS pedido_id,
                                    p.numero_pedido,
                                    p.status AS pedido_status,
                                    p.total AS pedido_total,
                                    p.criado_em AS pedido_criado_em,
                                    u.nome AS garcom_nome,
                                    (SELECT COUNT(*) FROM itens_pedido ip WHERE ip.pedido_id = p.id) AS pedido_itens,
                                    TIMESTAMPDIFF(MINUTE, p.criado_em, NOW()) AS pedido_minutos
                                FROM mesas m
                                INNER JOIN pedidos p
                                    ON p.mesa_id = m.id
                                    AND p.restaurante_id = :rid
                                    AND p.status NOT IN ('PAGO', 'CANCELADO')
                                    AND p.id = (
                                        SELECT id FROM pedidos
                                        WHERE mesa_id = m.id
                                          AND restaurante_id = :rid2
                                          AND status NOT IN ('PAGO', 'CANCELADO')
                                        ORDER BY criado_em DESC
                                        LIMIT 1
                                    )
                                LEFT JOIN usuarios u ON u.id = p.garcom_id
                                WHERE m.restaurante_id = :rid3
                            ");
                            $stmtOps->execute([
                                ':rid' => (int)$restauranteId,
                                ':rid2' => (int)$restauranteId,
                                ':rid3' => (int)$restauranteId,
                            ]);
                            $minsList = [];
                            while ($op = $stmtOps->fetch(PDO::FETCH_ASSOC)) {
                                $mid = (int)$op['mesa_id'];
                                $mins = $op['pedido_minutos'] !== null ? (int)$op['pedido_minutos'] : null;
                                $mesaOps[$mid] = [
                                    'pedido_id' => (int)$op['pedido_id'],
                                    'numero_pedido' => (string)($op['numero_pedido'] ?? ''),
                                    'pedido_status' => (string)($op['pedido_status'] ?? ''),
                                    'pedido_total' => (float)($op['pedido_total'] ?? 0),
                                    'pedido_itens' => (int)($op['pedido_itens'] ?? 0),
                                    'pedido_minutos' => $mins,
                                    'garcom_nome' => (string)($op['garcom_nome'] ?? ''),
                                ];
                                $empKpis['valor_aberto'] += (float)($op['pedido_total'] ?? 0);
                                if ($mins !== null) {
                                    $minsList[] = $mins;
                                    if ($mins >= 30) {
                                        $empKpis['atrasadas']++;
                                    }
                                }
                            }
                            if (!empty($minsList)) {
                                $empKpis['tempo_medio'] = (int)round(array_sum($minsList) / count($minsList));
                            }
                        } catch (Throwable $e) {
                            $mesaOps = [];
                        }

                        foreach ($proximasReservas as $prCand) {
                            if (in_array((string)($prCand['status'] ?? ''), ['pendente', 'confirmado'], true)) {
                                $quando = trim(($prCand['data_reserva'] ?? '') . ' ' . substr((string)($prCand['hora_reserva'] ?? ''), 0, 5));
                                $cli = (string)($prCand['nome_cliente'] ?? 'Cliente');
                                $empKpis['proxima_reserva_label'] = $cli . ' · ' . $quando;
                                break;
                            }
                        }
                        if ($empKpis['proxima_reserva_label'] === '—' && !empty($proximasReservas[0])) {
                            $pr = $proximasReservas[0];
                            $quando = trim(($pr['data_reserva'] ?? '') . ' ' . substr((string)($pr['hora_reserva'] ?? ''), 0, 5));
                            $cli = (string)($pr['nome_cliente'] ?? 'Cliente');
                            $empKpis['proxima_reserva_label'] = $cli . ' · ' . $quando . ' (no salão)';
                        }
                    }

                    $mesasSalao = [];
                    foreach ($todas_mesas as $mRow) {
                        if (!mesa_eh_balcao($mRow['numero'] ?? '')) {
                            $mesasSalao[] = $mRow;
                        }
                    }

                    $mesaDisplayLabel = [];
                    foreach ($todas_mesas as $mRow) {
                        $mid = (int)($mRow['id'] ?? 0);
                        $numRaw = (string)($mRow['numero'] ?? '');
                        $mesaDisplayLabel[$mid] = mesa_rotulo($numRaw);
                    }

                    // Intel do mapa (só Empresarial)
                    $mapaIntel = [
                        'com_pedido' => 0,
                        'livres_salão' => 0,
                        'ocupadas_salão' => 0,
                        'reservadas_salão' => 0,
                        'grandes_livres' => 0,
                        'ticket_medio' => 0.0,
                        'atrasadas_lista' => [],
                        'notas' => [],
                    ];
                    if (!empty($isEmpresarialUi)) {
                        $totaisPedido = [];
                        foreach ($mesasSalao as $ms) {
                            $st = (string)($ms['status'] ?? '');
                            $mid = (int)($ms['id'] ?? 0);
                            $cap = (int)($ms['capacidade'] ?? 4);
                            if ($st === 'LIVRE') {
                                $mapaIntel['livres_salão']++;
                                if ($cap >= 6) {
                                    $mapaIntel['grandes_livres']++;
                                }
                            } elseif ($st === 'OCUPADA') {
                                $mapaIntel['ocupadas_salão']++;
                            } elseif ($st === 'RESERVADA') {
                                $mapaIntel['reservadas_salão']++;
                            }
                            if (!empty($mesaOps[$mid])) {
                                $mapaIntel['com_pedido']++;
                                $totaisPedido[] = (float)$mesaOps[$mid]['pedido_total'];
                                $minsOp = $mesaOps[$mid]['pedido_minutos'];
                                if ($minsOp !== null && (int)$minsOp >= 30) {
                                    $mapaIntel['atrasadas_lista'][] = [
                                        'numero' => (string)($ms['numero'] ?? ''),
                                        'mins' => (int)$minsOp,
                                        'total' => (float)$mesaOps[$mid]['pedido_total'],
                                        'status' => (string)$mesaOps[$mid]['pedido_status'],
                                    ];
                                }
                            }
                        }
                        if (!empty($totaisPedido)) {
                            $mapaIntel['ticket_medio'] = array_sum($totaisPedido) / count($totaisPedido);
                        }
                        usort($mapaIntel['atrasadas_lista'], static function ($a, $b) {
                            return ((int)$b['mins']) <=> ((int)$a['mins']);
                        });
                        $mapaIntel['atrasadas_lista'] = array_slice($mapaIntel['atrasadas_lista'], 0, 5);

                        if ((int)$empKpis['atrasadas'] > 0) {
                            $mapaIntel['notas'][] = [
                                'tom' => 'warn',
                                'dup' => true, 'titulo' => (int)$empKpis['atrasadas'] . ' mesa(s) ≥ 30 min',
                                'txt' => 'Priorize fecho de conta ou apoio ao salão nestas mesas.',
                            ];
                        }
                        if ((int)$empKpis['ocupacao_pct'] >= 80) {
                            $mapaIntel['notas'][] = [
                                'tom' => 'warn',
                                'titulo' => 'Salão quase cheio (' . (int)$empKpis['ocupacao_pct'] . '%)',
                                'txt' => $mapaIntel['grandes_livres'] > 0
                                    ? ($mapaIntel['grandes_livres'] . ' mesa(s) grande(s) ainda livre(s).')
                                    : 'Prepare lista de espera ou incentive take-away.',
                            ];
                        } elseif ((int)$mapaIntel['livres_salão'] > 0) {
                            $mapaIntel['notas'][] = [
                                'tom' => 'ok',
                                'dup' => true, 'titulo' => (int)$mapaIntel['livres_salão'] . ' mesa(s) livre(s)',
                                'txt' => (int)$empKpis['lugares_livres'] . ' lugares disponíveis no salão.',
                            ];
                        }
                        if ((float)$empKpis['valor_aberto'] > 0) {
                            $mapaIntel['notas'][] = [
                                'tom' => 'info',
                                'dup' => true, 'titulo' => 'MZN ' . number_format((float)$empKpis['valor_aberto'], 0, ',', '.') . ' em aberto',
                                'txt' => $mapaIntel['com_pedido'] . ' conta(s) activa(s)'
                                    . ($mapaIntel['ticket_medio'] > 0
                                        ? ' · ticket médio MZN ' . number_format((float)$mapaIntel['ticket_medio'], 0, ',', '.')
                                        : ''),
                            ];
                        }
                        if ($empKpis['proxima_reserva_label'] !== '—') {
                            $mapaIntel['notas'][] = [
                                'tom' => 'info',
                                'titulo' => 'Próxima reserva',
                                'txt' => (string)$empKpis['proxima_reserva_label'],
                            ];
                        }
                        $mapaIntel['notas'] = array_slice($mapaIntel['notas'], 0, 4);
                    }
                    ?>

                    <div class="hero-panel">
                        <div class="hero-copy">
                            <?php if ($plano_atual === 'BASICO'): ?>
                                <div class="hero-pill"><i class="fas fa-chair"></i> Mesas do salão <?php include __DIR__ . '/includes/plan_page_badge.php'; ?></div>
                                <h3>Estado das mesas</h3>
                                <p>Veja quais mesas estão livres, ocupadas ou reservadas.</p>
                            <?php elseif ($plano_atual === 'PROFISSIONAL'): ?>
                                <div class="hero-pill"><i class="fas fa-store"></i> Operação de mesas <?php include __DIR__ . '/includes/plan_page_badge.php'; ?></div>
                                <h3>Fluxo do salão</h3>
                                <p>Monitore disponibilidade e QR das mesas para pedidos online.</p>
                                <div class="mesas-estado-chips">
                                    <span class="is-livre"><strong><?php echo (int)$mesasEstadoPro['livres']; ?></strong> livres</span>
                                    <span class="is-ocupada"><strong><?php echo (int)$mesasEstadoPro['ocupadas']; ?></strong> ocupadas</span>
                                    <span class="is-reservada"><strong><?php echo (int)$mesasEstadoPro['reservadas']; ?></strong> reservadas</span>
                                </div>
                            <?php else: ?>
                                <div class="hero-pill"><i class="fas fa-crown"></i> Operação de mesas <?php include __DIR__ . '/includes/plan_page_badge.php'; ?></div>
                                <h3>Salão, reservas e QR</h3>
                                <p>Mesas ilimitadas, reservas programadas e QR para reserva e pedido pelo telemóvel.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- STATS -->
                    <div class="stats-grid<?php echo !empty($isEmpresarialUi) ? ' mesas-emp-stats' : ''; ?>">
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $total; ?></div>
                            <div class="stat-label">Total de Mesas</div>
                        </div>
                        <div class="stat-card success">
                            <div class="stat-value" style="color: var(--success);"><?php echo $livres; ?></div>
                            <div class="stat-label"><i class="fas fa-circle me-1" style="font-size: 10px; color: var(--success);"></i>Mesas Livres</div>
                        </div>
                        <div class="stat-card danger">
                            <div class="stat-value" style="color: var(--danger);"><?php echo $ocupadas; ?></div>
                            <div class="stat-label"><i class="fas fa-circle me-1" style="font-size: 10px; color: var(--danger);"></i>Mesas Ocupadas</div>
                        </div>
                        <?php if (!empty($isEmpresarialUi)): ?>
                        <div class="stat-card">
                            <div class="stat-value" style="color: #c2410c;"><?php echo (int)$empKpis['ocupacao_pct']; ?>%</div>
                            <div class="stat-label"><i class="fas fa-chart-pie me-1" style="font-size: 10px; color: #c2410c;"></i>Taxa de Ocupação</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value" style="color: #1d4ed8; font-size: 22px;">
                                <?php echo number_format((float)$empKpis['valor_aberto'], 2, ',', ' '); ?>
                            </div>
                            <div class="stat-label"><i class="fas fa-coins me-1" style="font-size: 10px; color: #1d4ed8;"></i>Em Aberto (MZN)</div>
                        </div>
                        <div class="stat-card warning">
                            <div class="stat-value" style="color: var(--warning);">
                                <?php echo $empKpis['tempo_medio'] !== null ? ((int)$empKpis['tempo_medio'] . 'm') : '—'; ?>
                            </div>
                            <div class="stat-label"><i class="fas fa-clock me-1" style="font-size: 10px; color: var(--warning);"></i>Tempo Médio · <?php echo (int)$empKpis['atrasadas']; ?> &gt;30min</div>
                        </div>
                        <?php else: ?>
                        <div class="stat-card warning">
                            <div class="stat-value" style="color: var(--warning);"><?php echo $reservadas; ?></div>
                            <div class="stat-label"><i class="fas fa-circle me-1" style="font-size: 10px; color: var(--warning);"></i>Mesas Reservadas</div>
                        </div>
                        <?php endif; ?>
                        <?php if ($plano_atual !== 'BASICO'): ?>
                        <div class="stat-card">
                            <div class="stat-value" style="color: var(--info);"><?php echo (int)($resumoReservasHoje['total_hoje'] ?? 0); ?></div>
                            <div class="stat-label"><i class="fas fa-calendar-check me-1" style="font-size: 10px; color: var(--info);"></i>Reservas de Hoje</div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php
                    $mesasPlanRid = function_exists('session_restaurante_capability_id')
                        ? (int)session_restaurante_capability_id()
                        : (int)$restauranteId;
                    $mesasPlanRid = $mesasPlanRid > 0 ? $mesasPlanRid : (int)$restauranteId;
                    $planUsage = plano_verificar_limite_db($mesasPlanRid, 'mesas', (int)$total);
                    $planUsageLabel = 'Mesas do salão';
                    include __DIR__ . '/includes/plan_usage_banner.php';
                    $bloquearNovaMesa = !empty($planUsageBloqueado);
                    ?>

                    <!-- LEGENDA E BOTÃO -->
                    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
                        <div class="d-flex gap-3">
                            <span class="badge-custom badge-success"><i class="fas fa-circle me-1"></i>Livre</span>
                            <span class="badge-custom badge-danger"><i class="fas fa-circle me-1"></i>Ocupada</span>
                            <span class="badge-custom badge-warning"><i class="fas fa-circle me-1"></i>Reservada</span>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <?php if ($temPedidosOnline): ?>
                                <button type="button" class="btn btn-outline-secondary" onclick="baixarTodosQrMesas()">
                                    <i class="fas fa-download me-2"></i>Baixar QR Cardapio
                                </button>
                            <?php endif; ?>
                            <?php if ($restauranteFeatureId > 0 && plano_tem_funcionalidade_db($restauranteFeatureId, 'reservas_qr')): ?>
                                <?php if (!empty($isEmpresarialUi)): ?>
                                    <button type="button" class="btn mesas-emp-qr-reserva-btn" onclick="abrirModalReservaQr()" title="Gerar QR temporário para reserva ou check-in">
                                        <span class="mesas-emp-qr-reserva-icon"><i class="fas fa-qrcode"></i></span>
                                        <span class="mesas-emp-qr-reserva-copy">
                                            <strong>QR Reserva / Check-in</strong>
                                            <small>Enviar acesso da mesa ao cliente</small>
                                        </span>
                                        <i class="fas fa-arrow-right mesas-emp-qr-reserva-arrow" aria-hidden="true"></i>
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-outline-dark" onclick="abrirModalReservaQr()"><i class="fas fa-user-check me-2"></i>QR Reserva/Check-in</button>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if (in_array($_SESSION['perfil'] ?? '', ['ADMIN', 'GERENTE'], true)): ?>
                                <?php if (!empty($bloquearNovaMesa)): ?>
                                    <button class="btn btn-secondary" type="button" disabled title="Limite do plano atingido">
                                        <i class="fas fa-lock me-2"></i>Limite de mesas
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-primary" onclick="abrirModalNovaMesa('mesa')"><i class="fas fa-plus me-2"></i>Adicionar Mesa</button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body d-flex align-items-center justify-content-between gap-3 flex-wrap">
                            <div class="d-flex align-items-center gap-3">
                                <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-success-subtle text-success" style="width:44px;height:44px;">
                                    <i class="fas fa-bell-concierge"></i>
                                </span>
                                <div>
                                    <strong class="d-block">Balcão / Take-away</strong>
                                    <span class="text-muted small">Canal automático para pedidos ao balcão e para levar. Não ocupa lugar no salão.</span>
                                </div>
                            </div>
                            <span class="badge-custom badge-success"><i class="fas fa-circle me-1"></i>Sempre disponível</span>
                        </div>
                        <?php if (!empty($balcao2Permitido) && ($_SESSION['perfil'] ?? '') === 'ADMIN'): ?>
                        <div class="card-body border-top d-flex align-items-center justify-content-between gap-3 flex-wrap" id="balcao2Box">
                            <div class="d-flex align-items-center gap-3">
                                <span class="d-inline-flex align-items-center justify-content-center rounded-circle <?php echo !empty($balcao2['ativo']) ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'; ?>" style="width:44px;height:44px;">
                                    <i class="fas fa-bag-shopping"></i>
                                </span>
                                <div>
                                    <strong class="d-block">Segundo canal: <span id="balcao2Nome"><?php echo htmlspecialchars($balcao2['nome']); ?></span></strong>
                                    <span class="text-muted small">Canal extra do plano Empresarial, com fila própria (ex.: take-away ou entregas). Desligar não apaga nada.</span>
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <?php if (!empty($balcao2['ativo'])): ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnBalcao2Renomear"><i class="fas fa-pen me-1"></i>Mudar nome</button>
                                    <button type="button" class="btn btn-sm btn-outline-danger" id="btnBalcao2Desligar"><i class="fas fa-power-off me-1"></i>Desligar</button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-sm btn-success" id="btnBalcao2Ativar"><i class="fas fa-plus me-1"></i>Activar segundo canal</button>
                                <?php endif; ?>
                            </div>
                            <div class="w-100 d-none" id="balcao2Form">
                                <div class="d-flex align-items-center gap-2 flex-wrap mt-2">
                                    <input type="text" class="form-control form-control-sm" id="balcao2NomeInput" maxlength="40" style="max-width:260px" placeholder="Nome do canal, como aparece no PDV e na cozinha" value="<?php echo htmlspecialchars($balcao2['nome']); ?>">
                                    <button type="button" class="btn btn-sm btn-primary" id="btnBalcao2Confirmar">Confirmar</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnBalcao2Cancelar">Cancelar</button>
                                </div>
                                <small class="text-muted d-none" id="balcao2Aviso"></small>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($isEmpresarialUi)): ?>
                    <!-- EMPRESARIAL: salão inteligente -->
                    <?php if ($restauranteFeatureId > 0 && plano_tem_funcionalidade_db($restauranteFeatureId, 'reservas_qr')): ?>
                        <div class="mesas-emp-reservas mb-3">
                            <div class="mesas-emp-reservas-bar">
                                <div>
                                    <strong><i class="fas fa-calendar-check me-2"></i>Reservas</strong>
                                    <span class="text-muted ms-2 small">Próxima: <?php echo htmlspecialchars((string)$empKpis['proxima_reserva_label']); ?></span>
                                    <div class="small text-muted mt-1">Check-in ocupa a mesa e abre o pedido. Sai da lista só após pagar.</div>
                                </div>
                                <div class="d-flex gap-2 align-items-center flex-wrap">
                                    <span class="badge-custom badge-warning"><?php echo (int)($resumoReservasHoje['pendentes_hoje'] ?? 0); ?> pendentes</span>
                                    <span class="badge-custom badge-success"><?php echo (int)($resumoReservasHoje['confirmadas_hoje'] ?? 0); ?> confirmadas</span>
                                    <span class="badge-custom badge-info"><?php echo (int)($resumoReservasHoje['chegaram_hoje'] ?? 0); ?> no salão</span>
                                    <?php if (!empty($proximasReservas)): ?>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('mesas-emp-reservas-body').classList.toggle('is-open')">
                                            Ver lista
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if (!empty($proximasReservas)): ?>
                            <div class="mesas-emp-reservas-body" id="mesas-emp-reservas-body">
                                <div class="table-responsive">
                                    <table class="table mb-0 align-middle">
                                        <thead>
                                            <tr>
                                                <th class="ps-3">Data / Hora</th>
                                                <th>Cliente</th>
                                                <th>Mesa</th>
                                                <th>Pessoas</th>
                                                <th>Status</th>
                                                <th class="text-center">Ações</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($proximasReservas as $reserva): ?>
                                                <?php
                                                $stUi = mesas_reserva_status_ui((string)$reserva['status']);
                                                $mesaReservaId = (int)($reserva['mesa_atribuida'] ?? 0);
                                                ?>
                                                <tr>
                                                    <td class="ps-3">
                                                        <strong><?php echo htmlspecialchars($reserva['data_reserva']); ?></strong>
                                                        <span class="text-muted ms-1"><?php echo htmlspecialchars(substr((string)$reserva['hora_reserva'], 0, 5)); ?></span>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($reserva['nome_cliente']); ?></strong>
                                                        <div class="small text-muted"><?php echo htmlspecialchars($reserva['telefone_cliente'] ?: 'Sem telefone'); ?></div>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($reserva['mesa_numero'] ? mesa_rotulo($reserva['mesa_numero']) : 'Por atribuir'); ?></td>
                                                    <td><?php echo (int)$reserva['quantidade_pessoas']; ?></td>
                                                    <td>
                                                        <span class="badge-custom <?php echo htmlspecialchars($stUi['class']); ?>">
                                                            <?php echo htmlspecialchars($stUi['label']); ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-center">
                                                        <div class="d-flex gap-2 justify-content-center flex-wrap">
                                                            <?php if ($reserva['status'] === 'pendente'): ?>
                                                                <button class="btn btn-success btn-action btn-sm" onclick="confirmarReserva(<?php echo (int)$reserva['id']; ?>)" title="Confirmar reserva"><i class="fas fa-check"></i></button>
                                                            <?php endif; ?>
                                                            <?php if (in_array($reserva['status'], ['pendente', 'confirmado'], true)): ?>
                                                                <button class="btn btn-info btn-action btn-sm" onclick="fazerCheckinReserva(<?php echo (int)$reserva['id']; ?>, <?php echo $mesaReservaId; ?>)" title="Fazer check-in"><i class="fas fa-right-to-bracket"></i></button>
                                                            <?php endif; ?>
                                                            <?php if ($reserva['status'] === 'chegou' && $mesaReservaId > 0): ?>
                                                                <?php $pedidoAbertoReserva = (int)($mesaOps[$mesaReservaId]['pedido_id'] ?? 0); ?>
                                                                <?php if ($pedidoAbertoReserva > 0): ?>
                                                                <a class="btn btn-primary btn-action btn-sm" href="pedidos.php?pedido=<?php echo $pedidoAbertoReserva; ?>" title="Ver o pedido aberto da mesa (inclui o pre-pedido do cliente)"><i class="fas fa-receipt"></i></a>
                                                                <a class="btn btn-outline-primary btn-action btn-sm" href="novo_pedido.php?mesa_id=<?php echo $mesaReservaId; ?>" title="Acrescentar itens (novo pedido na mesma mesa)"><i class="fas fa-plus"></i></a>
                                                                <?php else: ?>
                                                                <a class="btn btn-primary btn-action btn-sm" href="novo_pedido.php?mesa_id=<?php echo $mesaReservaId; ?>&origem=reserva_checkin" title="Abrir pedido da mesa"><i class="fas fa-utensils"></i></a>
                                                                <?php endif; ?>
                                                                <a class="btn btn-success btn-action btn-sm" href="caixa_mesas.php" title="Ir a Contas Abertas para pagar"><i class="fas fa-cash-register"></i></a>
                                                                <button class="btn btn-outline-secondary btn-action btn-sm" onclick="atualizarMesa(<?php echo $mesaReservaId; ?>, 'LIVRE')" title="Cliente saiu / liberar mesa (reserva sai da lista)"><i class="fas fa-door-open"></i></button>
                                                            <?php endif; ?>
                                                            <?php if ($reserva['status'] !== 'chegou'): ?>
                                                                <button class="btn btn-danger btn-action btn-sm" onclick="cancelarReserva(<?php echo (int)$reserva['id']; ?>)" title="Cancelar reserva"><i class="fas fa-ban"></i></button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php if (is_array($mesasEmpReservas)): ?>
                        <div class="mesas-emp-intel-card mt-3">
                            <div class="mesas-emp-intel-head">
                                <strong><i class="fas fa-qrcode me-2"></i>Reservas + QR</strong>
                                <span class="small text-muted">Leitura dos últimos 30 dias</span>
                            </div>
                            <div class="mesas-emp-intel-kpis">
                                <div><span>No-show 30d</span><strong><?php echo (int)$mesasEmpReservas['no_show_30']; ?></strong></div>
                                <div><span>Hora pico</span><strong><?php echo htmlspecialchars((string)$mesasEmpReservas['hora_pico']); ?></strong></div>
                                <div><span>Mesa quente</span><strong><?php echo htmlspecialchars((string)$mesasEmpReservas['mesa_quente']); ?></strong></div>
                            </div>
                            <?php if (!empty($mesasEmpReservas['notas'])): ?>
                                <ul class="mesas-emp-intel-notes">
                                    <?php foreach ($mesasEmpReservas['notas'] as $rn): ?>
                                        <li class="tom-<?php echo htmlspecialchars((string)($rn['tom'] ?? 'info')); ?>">
                                            <strong><?php echo htmlspecialchars((string)($rn['titulo'] ?? '')); ?></strong>
                                            <span><?php echo htmlspecialchars((string)($rn['txt'] ?? '')); ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <div class="mesas-emp-layout" id="mesas-emp-layout">
                        <div class="mesas-emp-main">
                            <div class="mesas-emp-toolbar">
                                <div class="mesas-emp-toolbar-title">
                                    <span class="mesas-emp-map-kicker"><i class="fas fa-map-location-dot"></i> Operação</span>
                                    Mapa do salão
                                    <span class="mesas-emp-badge" id="mesas-emp-count"><?php echo count($mesasSalao); ?> mesas</span>
                                </div>
                                <div class="mesas-emp-toolbar-actions">
                                    <input type="search" id="mesas-emp-busca" class="mesas-emp-input" placeholder="Pesquisar mesa, garçom ou pedido…">
                                    <select id="mesas-emp-filtro-status" class="mesas-emp-select">
                                        <option value="todos">Todos os status</option>
                                        <option value="LIVRE">Livre</option>
                                        <option value="OCUPADA">Ocupada</option>
                                        <option value="RESERVADA">Reservada</option>
                                        <option value="COM_PEDIDO">Com pedido ativo</option>
                                        <option value="ATRASADA">≥ 30 min</option>
                                        <option value="GRANDE_LIVRE">Livre · ≥6 lugares</option>
                                    </select>
                                    <div class="mesas-emp-view-toggle" role="group" aria-label="Vista">
                                        <button type="button" class="is-active" data-view="floor" title="Vista mapa"><i class="fas fa-th-large"></i></button>
                                        <button type="button" data-view="table" title="Vista tabela"><i class="fas fa-list"></i></button>
                                    </div>
                                </div>
                            </div>

                            <div class="mesas-emp-map-hero">
                                <?php if (array_filter((array)$mapaIntel['notas'], static fn($n) => empty($n['dup']))): ?>
                                <div class="mesas-emp-map-leitura">
                                    <strong><i class="fas fa-brain me-1"></i>Leitura do salão</strong>
                                    <ul>
                                        <?php foreach ($mapaIntel['notas'] as $mn): if (!empty($mn['dup'])) { continue; } ?>
                                            <li class="tom-<?php echo htmlspecialchars((string)($mn['tom'] ?? 'info')); ?>">
                                                <strong><?php echo htmlspecialchars((string)($mn['titulo'] ?? '')); ?></strong>
                                                <span><?php echo htmlspecialchars((string)($mn['txt'] ?? '')); ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="mesas-emp-chips" id="mesas-emp-chips">
                                <button type="button" class="mesas-emp-chip is-active" data-chip="todos">Todas <em><?php echo count($mesasSalao); ?></em></button>
                                <button type="button" class="mesas-emp-chip chip-ok" data-chip="LIVRE">Livres <em><?php echo (int)$mapaIntel['livres_salão']; ?></em></button>
                                <button type="button" class="mesas-emp-chip chip-busy" data-chip="OCUPADA">Ocupadas <em><?php echo (int)$mapaIntel['ocupadas_salão']; ?></em></button>
                                <button type="button" class="mesas-emp-chip chip-warn" data-chip="RESERVADA">Reservadas <em><?php echo (int)$mapaIntel['reservadas_salão']; ?></em></button>
                                <button type="button" class="mesas-emp-chip chip-info" data-chip="COM_PEDIDO">Com conta <em><?php echo (int)$mapaIntel['com_pedido']; ?></em></button>
                                <button type="button" class="mesas-emp-chip chip-danger<?php echo (int)$empKpis['atrasadas'] > 0 ? ' has-alert' : ''; ?>" data-chip="ATRASADA">≥30 min <em><?php echo (int)$empKpis['atrasadas']; ?></em></button>
                                <button type="button" class="mesas-emp-chip" data-chip="GRANDE_LIVRE">Grandes livres <em><?php echo (int)$mapaIntel['grandes_livres']; ?></em></button>
                            </div>

                            <div class="mesas-emp-floor" id="mesas-emp-floor">
                                <div class="mesas-emp-floor-grid">
                                    <?php foreach ($mesasSalao as $m): ?>
                                        <?php
                                        $mid = (int)$m['id'];
                                        $status_lower = strtolower((string)$m['status']);
                                        $op = $mesaOps[$mid] ?? null;
                                        $mins = $op['pedido_minutos'] ?? null;
                                        $atrasada = $mins !== null && $mins >= 30;
                                        $alertaTempo = $mins !== null && $mins >= 15 && !$atrasada;
                                        $cap = (int)($m['capacidade'] ?? 4);
                                        $sizeClass = $cap >= 8 ? 'size-xl' : ($cap >= 6 ? 'size-lg' : ($cap <= 2 ? 'size-sm' : 'size-md'));
                                        $heatClass = $atrasada ? 'heat-crit' : ($alertaTempo ? 'heat-warn' : ($op ? 'heat-active' : ''));
                                        $link = $mesaQrUrls[$mid] ?? '';
                                        $searchBlob = strtolower('mesa ' . $m['numero'] . ' ' . ($op['garcom_nome'] ?? '') . ' ' . ($op['numero_pedido'] ?? ''));
                                        ?>
                                        <div class="mesas-emp-item <?php echo $sizeClass; ?>"
                                             data-status="<?php echo htmlspecialchars((string)$m['status']); ?>"
                                             data-pedido="<?php echo $op ? '1' : '0'; ?>"
                                             data-atrasada="<?php echo $atrasada ? '1' : '0'; ?>"
                                             data-cap="<?php echo $cap; ?>"
                                             data-search="<?php echo htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8'); ?>">
                                            <div class="mesa-card mesas-emp-tile <?php echo $status_lower; ?> <?php echo $heatClass; ?><?php echo $atrasada ? ' urgente' : ''; ?>">
                                                <div class="mesas-emp-tile-top">
                                                    <div class="mesa-card-title">
                                                        <?php if (mesa_eh_balcao($m['numero'] ?? '')): ?>
                                                            <i class="fas fa-bell-concierge me-1" style="color:#0f766e;font-size:0.85em;"></i>
                                                        <?php endif; ?>
                                                        <?php $mid = (int)($m['id'] ?? 0); echo htmlspecialchars($mesaDisplayLabel[$mid] ?? mesa_rotulo($m['numero'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                                    </div>
                                                    <span class="mesas-emp-status-dot status-<?php echo htmlspecialchars($status_lower); ?>" title="<?php echo htmlspecialchars((string)$m['status']); ?>"></span>
                                                </div>
                                                <div class="mesa-card-meta">
                                                    <i class="fas fa-user-group me-1"></i><?php echo $cap; ?> lugares
                                                    <span class="badge-custom badge-<?php echo $status_lower === 'livre' ? 'success' : ($status_lower === 'ocupada' ? 'danger' : 'warning'); ?>"><?php echo htmlspecialchars((string)$m['status']); ?></span>
                                                </div>
                                                <?php if ($op): ?>
                                                    <div class="mesas-emp-intel<?php echo $atrasada ? ' is-urgente' : ''; ?>">
                                                        <?php if (!empty($op['numero_pedido'])): ?>
                                                            <div class="mesas-emp-ticket">#<?php echo htmlspecialchars((string)$op['numero_pedido']); ?></div>
                                                        <?php endif; ?>
                                                        <div class="mesas-emp-intel-total">MZN <?php echo number_format((float)$op['pedido_total'], 2, ',', ' '); ?></div>
                                                        <div class="mesas-emp-intel-meta">
                                                            <?php echo (int)$op['pedido_itens']; ?> itens ·
                                                            <strong class="<?php echo $atrasada ? 'text-danger' : ($alertaTempo ? 'text-warning' : ''); ?>">
                                                                <?php echo $mins !== null ? ((int)$mins . ' min') : '—'; ?>
                                                            </strong>
                                                            · <?php echo htmlspecialchars((string)$op['pedido_status']); ?>
                                                        </div>
                                                        <?php if (!empty($op['garcom_nome'])): ?>
                                                            <div class="mesas-emp-intel-garcom"><i class="fas fa-user-tie me-1"></i><?php echo htmlspecialchars($op['garcom_nome']); ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="mesas-emp-tile-idle"><?php $stIdle = strtoupper((string)$m['status']); echo $stIdle === 'RESERVADA' ? 'Reservada' : ($stIdle === 'OCUPADA' ? 'Clientes sentados' : 'Pronta para receber'); ?></div>
                                                <?php endif; ?>
                                                <?php
                                                $stMesa = strtoupper((string)$m['status']);
                                                $numEsc = htmlspecialchars((string)$m['numero'], ENT_QUOTES, 'UTF-8');
                                                ?>
                                                <?php if ($op && $stMesa === 'LIVRE'): ?>
                                                    <div class="mesas-emp-aviso"><i class="fas fa-triangle-exclamation me-1"></i>Tem conta aberta mas está marcada como livre.</div>
                                                <?php elseif (!$op && $stMesa === 'OCUPADA'): ?>
                                                    <div class="mesas-emp-aviso is-info"><i class="fas fa-circle-info me-1"></i>Ocupada sem conta aberta.</div>
                                                <?php endif; ?>
                                                <div class="mesa-actions mesas-emp-acoes">
                                                    <?php if ($stMesa === 'LIVRE'): ?>
                                                        <button type="button" class="mesa-action ocupar" onclick="atualizarMesa(<?php echo $mid; ?>, 'OCUPADA')"><i class="fas fa-user"></i><span>Ocupar</span></button>
                                                        <button type="button" class="mesa-action reservar" onclick="abrirModalReserva(<?php echo $mid; ?>, '<?php echo $numEsc; ?>', <?php echo $cap; ?>)"><i class="fas fa-calendar"></i><span>Reservar</span></button>
                                                    <?php elseif ($stMesa === 'RESERVADA'): ?>
                                                        <button type="button" class="mesa-action ocupar" onclick="atualizarMesa(<?php echo $mid; ?>, 'OCUPADA')"><i class="fas fa-user-check"></i><span>Sentar</span></button>
                                                        <button type="button" class="mesa-action livre" onclick="atualizarMesa(<?php echo $mid; ?>, 'LIVRE')"><i class="fas fa-check"></i><span>Libertar</span></button>
                                                    <?php else: ?>
                                                        <?php if ($op): ?>
                                                            <a class="mesa-action conta" href="caixa_mesas.php"><i class="fas fa-receipt"></i><span>Ver conta</span></a>
                                                        <?php endif; ?>
                                                        <button type="button" class="mesa-action livre" onclick="atualizarMesa(<?php echo $mid; ?>, 'LIVRE')"><i class="fas fa-check"></i><span>Libertar</span></button>
                                                    <?php endif; ?>
                                                    <?php if ($temPedidosOnline && $link !== ''): ?>
                                                        <button type="button" class="mesa-action qr mesas-emp-qr-btn" data-mesa-link="<?php echo htmlspecialchars($link, ENT_QUOTES, 'UTF-8'); ?>" data-mesa-numero="<?php echo $numEsc; ?>" title="Mostrar QR do cardápio"><i class="fas fa-qrcode"></i><span>QR do cardápio</span></button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (empty($mesasSalao)): ?>
                                        <div class="mesas-emp-empty">
                                            <i class="fas fa-chair"></i>
                                            <strong>Nenhuma mesa no salão</strong>
                                            <span>Adicione mesas para ver o mapa inteligente.</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="mesas-emp-table-wrap" id="mesas-emp-table-wrap" hidden>
                                <table class="mesas-emp-table">
                                    <thead>
                                        <tr>
                                            <th>Mesa</th>
                                            <th>Status</th>
                                            <th>Cap.</th>
                                            <th>Pedido</th>
                                            <th>Total</th>
                                            <th>Tempo</th>
                                            <th>Garçom</th>
                                            <th>Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($mesasSalao as $m): ?>
                                            <?php
                                            $mid = (int)$m['id'];
                                            $op = $mesaOps[$mid] ?? null;
                                            $mins = $op['pedido_minutos'] ?? null;
                                            $atrasada = $mins !== null && $mins >= 30;
                                            $cap = (int)($m['capacidade'] ?? 4);
                                            $searchBlob = strtolower('mesa ' . $m['numero'] . ' ' . ($op['garcom_nome'] ?? '') . ' ' . ($op['numero_pedido'] ?? ''));
                                            ?>
                                            <tr class="mesas-emp-item<?php echo $atrasada ? ' is-atrasada' : ''; ?>"
                                                data-status="<?php echo htmlspecialchars((string)$m['status']); ?>"
                                                data-pedido="<?php echo $op ? '1' : '0'; ?>"
                                                data-atrasada="<?php echo $atrasada ? '1' : '0'; ?>"
                                                data-cap="<?php echo $cap; ?>"
                                                data-search="<?php echo htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8'); ?>">
                                                <td><span class="mesas-emp-mesa-pill"><?php $mid = (int)($m['id'] ?? 0); echo htmlspecialchars($mesaDisplayLabel[$mid] ?? mesa_rotulo($m['numero'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                                <td><?php echo htmlspecialchars((string)$m['status']); ?></td>
                                                <td><?php echo $cap; ?></td>
                                                <td><?php echo $op ? htmlspecialchars((string)($op['pedido_status'] ?: $op['numero_pedido'])) : '—'; ?></td>
                                                <td><?php echo $op ? ('MZN ' . number_format((float)$op['pedido_total'], 2, ',', ' ')) : '—'; ?></td>
                                                <td class="<?php echo $atrasada ? 'text-danger fw-bold' : ''; ?>"><?php echo $mins !== null ? ((int)$mins . ' min') : '—'; ?></td>
                                                <td><?php echo $op && $op['garcom_nome'] !== '' ? htmlspecialchars($op['garcom_nome']) : '—'; ?></td>
                                                <td>
                                                    <div class="d-flex gap-1 flex-wrap">
                                                        <button type="button" class="btn btn-sm btn-outline-success" <?php echo $m['status'] === 'LIVRE' ? 'disabled' : ''; ?> onclick="atualizarMesa(<?php echo $mid; ?>, 'LIVRE')">Livre</button>
                                                        <button type="button" class="btn btn-sm btn-outline-danger" <?php echo $m['status'] === 'OCUPADA' ? 'disabled' : ''; ?> onclick="atualizarMesa(<?php echo $mid; ?>, 'OCUPADA')">Ocupar</button>
                                                        <button type="button" class="btn btn-sm btn-outline-warning" onclick="abrirModalReserva(<?php echo $mid; ?>, '<?php echo htmlspecialchars((string)$m['numero'], ENT_QUOTES, 'UTF-8'); ?>', <?php echo $cap; ?>)">Reservar</button>
                                                        <?php if ($op): ?><a class="btn btn-sm btn-success" href="caixa_mesas.php">Conta</a><?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <aside class="mesas-emp-side">
                            <div class="mesas-emp-side-card">
                                <h6>Resumo do salão</h6>
                                <div class="mesas-emp-side-row"><span>Lugares livres</span><strong><?php echo (int)$empKpis['lugares_livres']; ?> / <?php echo (int)$empKpis['lugares_total']; ?></strong></div>
                                <div class="mesas-emp-side-row"><span>Ticket médio</span><strong>MZN <?php echo number_format((float)$mapaIntel['ticket_medio'], 0, ',', ' '); ?></strong></div>
                                <div class="mesas-emp-side-row"><span>Tempo médio</span><strong><?php echo $empKpis['tempo_medio'] !== null ? ((int)$empKpis['tempo_medio'] . ' min') : '—'; ?></strong></div>
                                <div class="mesas-emp-side-row"><span>Reservadas</span><strong><?php echo (int)$reservadas; ?></strong></div>

                                <?php if (!empty($mapaIntel['atrasadas_lista'])): ?>
                                <div class="mesas-emp-side-priority">
                                    <strong><i class="fas fa-bolt me-1"></i>Prioridade agora</strong>
                                    <?php foreach ($mapaIntel['atrasadas_lista'] as $at): ?>
                                        <div class="mesas-emp-side-priority-row">
                                            <span><?php echo htmlspecialchars(mesa_rotulo($at['numero'] ?? '')); ?></span>
                                            <em><?php echo (int)$at['mins']; ?> min</em>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>

                                <div class="mesas-emp-side-note">
                                    <i class="fas fa-lightbulb me-1"></i>
                                    <?php if ((int)$empKpis['atrasadas'] > 0): ?>
                                        Foque nas mesas ≥30 min antes de abrir novas contas.
                                    <?php elseif ((int)$empKpis['ocupacao_pct'] >= 80): ?>
                                        Salão cheio — confirme reservas e prepare rotação de mesas.
                                    <?php else: ?>
                                        Priorize mesas com mais de 30 minutos e confirme reservas pendentes antes do pico.
                                    <?php endif; ?>
                                </div>
                            </div>
                        </aside>
                    </div>

                    <?php else: ?>
                    <!-- PROFISSIONAL / BÁSICO: layout original -->
                    <?php if ($plano_atual === 'PROFISSIONAL' || $plano_atual === 'BASICO'): ?>
                        <?php $reservasSimples = $plano_atual === 'BASICO'; ?>
                        <div class="card mb-4 reservation-panel">
                            <div class="card-header d-flex justify-content-between align-items-center px-4 py-3" style="background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(255, 107, 53, 0.04)); border-bottom: 1px solid var(--border);">
                                <div>
                                    <div class="fw-bold text-dark"><i class="fas fa-calendar-check me-2"></i><?php echo $reservasSimples ? 'Reservas' : 'Reservas programadas'; ?></div>
                                    <div class="small text-muted"><?php echo $reservasSimples
                                        ? 'Quem vem hoje e nos próximos dias. Quando o cliente chegar, clique em «Cliente chegou».'
                                        : 'Confirme o cliente pelo nome/telefone antes de ocupar a mesa.'; ?></div>
                                </div>
                                <?php if ($reservasSimples): ?>
                                <div class="d-flex gap-2 align-items-center flex-wrap">
                                    <?php
                                    $reservasParaHoje = count(array_filter($proximasReservas, static fn($r) => $r['data_reserva'] === $dataHoje && $r['status'] !== 'chegou'));
                                    ?>
                                    <span class="badge-custom badge-warning"><?php echo (int)$reservasParaHoje; ?> por chegar hoje</span>
                                    <button type="button" class="btn btn-sm btn-outline-warning" onclick="abrirModalReserva()"><i class="fas fa-plus me-1"></i>Nova reserva</button>
                                </div>
                                <?php else: ?>
                                <div class="d-flex gap-2 align-items-center flex-wrap">
                                    <span class="badge-custom badge-warning"><?php echo (int)($resumoReservasHoje['pendentes_hoje'] ?? 0); ?> pendentes hoje</span>
                                    <span class="badge-custom badge-success"><?php echo (int)($resumoReservasHoje['confirmadas_hoje'] ?? 0); ?> confirmadas hoje</span>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="card-body p-0">
                                <?php if (empty($proximasReservas)): ?>
                                    <div class="text-center py-5 text-muted">
                                        <i class="fas fa-calendar-xmark fa-2x mb-3 d-block"></i>
                                        Nenhuma reserva programada a partir de hoje.
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table mb-0 align-middle">
                                            <thead style="background: #fffaf3;">
                                                <tr>
                                                    <th class="ps-4">Data / Hora</th>
                                                    <th>Cliente</th>
                                                    <th>Mesa</th>
                                                    <th>Pessoas</th>
                                                    <th>Status</th>
                                                    <th class="text-center">Ações</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($proximasReservas as $reserva): ?>
                                                    <?php
                                                    $stUi = mesas_reserva_status_ui((string)$reserva['status']);
                                                    if ($reservasSimples && in_array($reserva['status'], ['pendente', 'confirmado'], true)) {
                                                        $stUi = ['class' => 'badge-warning', 'label' => 'Por chegar'];
                                                    }
                                                    ?>
                                                    <tr>
                                                        <td class="ps-4">
                                                            <?php
                                                            $diaReserva = (string)$reserva['data_reserva'];
                                                            $diaRotulo = $diaReserva === $dataHoje ? 'Hoje'
                                                                : ($diaReserva === date('Y-m-d', strtotime($dataHoje . ' +1 day')) ? 'Amanhã'
                                                                : date('d/m/Y', strtotime($diaReserva)));
                                                            ?>
                                                            <strong><?php echo htmlspecialchars($diaRotulo); ?></strong><br>
                                                            <span class="text-muted"><?php echo htmlspecialchars(substr((string)$reserva['hora_reserva'], 0, 5)); ?></span>
                                                        </td>
                                                        <td>
                                                            <strong><?php echo htmlspecialchars($reserva['nome_cliente']); ?></strong><br>
                                                            <span class="text-muted"><?php echo htmlspecialchars($reserva['telefone_cliente'] ?: 'Sem telefone'); ?></span>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($reserva['mesa_numero'] ? mesa_rotulo($reserva['mesa_numero']) : 'Por atribuir'); ?></td>
                                                        <td><?php echo (int)$reserva['quantidade_pessoas']; ?></td>
                                                        <td>
                                                            <span class="badge-custom <?php echo htmlspecialchars($stUi['class']); ?>">
                                                                <?php echo htmlspecialchars($stUi['label']); ?>
                                                            </span>
                                                        </td>
                                                        <td class="text-center">
                                                            <div class="d-flex gap-2 justify-content-center flex-wrap">
                                                                <?php if ($reserva['status'] === 'pendente' && !$reservasSimples): ?>
                                                                    <button class="btn btn-success btn-action btn-sm" onclick="confirmarReserva(<?php echo (int)$reserva['id']; ?>)" title="Confirmar reserva">
                                                                        <i class="fas fa-check"></i>
                                                                    </button>
                                                                <?php endif; ?>
                                                                <?php if (in_array($reserva['status'], ['pendente', 'confirmado'], true) && $reserva['data_reserva'] === $dataHoje): ?>
                                                                    <button class="btn btn-primary btn-sm" onclick="fazerCheckinReserva(<?php echo (int)$reserva['id']; ?>, <?php echo (int)($reserva['mesa_atribuida'] ?? 0); ?>)" title="O cliente chegou: ocupa a mesa e abre o pedido">
                                                                        <i class="fas fa-user-check me-1"></i>Cliente chegou
                                                                    </button>
                                                                <?php endif; ?>
                                                                <?php if ($reserva['status'] !== 'chegou'): ?>
                                                                    <button class="btn btn-danger btn-action btn-sm" onclick="cancelarReserva(<?php echo (int)$reserva['id']; ?>)" title="Cancelar reserva">
                                                                        <i class="fas fa-ban"></i>
                                                                    </button>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                        <!-- GRID DE MESAS -->
                        <div class="row g-4">
                            <?php foreach ($mesasSalao as $m): ?>
                                <?php
                                $status_lower = strtolower($m['status']);
                                ?>
                                <div class="col-6 col-md-4 col-lg-3">
                                    <div class="mesa-card <?php echo $status_lower; ?>">
                                        <div class="mesa-card-icon">
                                            <?php if (mesa_eh_balcao($m['numero'] ?? '')): ?>
                                                <i class="fas fa-bell-concierge" title="Balcão"></i>
                                            <?php elseif ($m['status'] == 'LIVRE'): ?>
                                                <i class="fas fa-chair" title="Mesa livre"></i>
                                            <?php elseif ($m['status'] == 'OCUPADA'): ?>
                                                <i class="fas fa-users" title="Mesa ocupada"></i>
                                            <?php else: ?>
                                                <i class="fas fa-clipboard-list" title="Mesa reservada"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mesa-card-title"><?php echo htmlspecialchars($mesaDisplayLabel[(int)($m['id'] ?? 0)] ?? mesa_rotulo($m['numero'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="mesa-card-meta"><i class="fas fa-user-group me-1"></i><?php echo (int)($m['capacidade'] ?? 4); ?> pessoas</div>

                                        <?php
                                        $minsCartaoPro = !empty($isProfissionalMesas) ? ($mesaMinutosPro[(int)($m['id'] ?? 0)] ?? null) : null;
                                        if ($minsCartaoPro !== null):
                                        ?>
                                            <div class="mesa-card-tempo<?php echo $minsCartaoPro >= 90 ? ' is-demorada' : ''; ?>" title="Tempo desde que o pedido foi aberto">
                                                <i class="fas fa-clock me-1"></i>há <?php echo htmlspecialchars(mesas_tempo_conta_label($minsCartaoPro), ENT_QUOTES, 'UTF-8'); ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php
                                        $reservaCartao = null;
                                        if ($plano_atual === 'PROFISSIONAL' && strtoupper((string)($m['status'] ?? '')) === 'RESERVADA') {
                                            $reservaCartao = $reservaPorMesaId[(int)($m['id'] ?? 0)] ?? null;
                                        }
                                        if (!empty($reservaCartao)):
                                            $horaCartao = substr((string)($reservaCartao['hora_reserva'] ?? ''), 0, 5);
                                            $telCartao = trim((string)($reservaCartao['telefone_cliente'] ?? ''));
                                        ?>
                                            <div class="mesa-card-reserva-cliente">
                                                <strong><i class="fas fa-user me-1"></i><?php echo htmlspecialchars((string)($reservaCartao['nome_cliente'] ?? 'Cliente')); ?></strong>
                                                <span><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($telCartao !== '' ? $telCartao : 'Sem telefone'); ?></span>
                                                <?php if ($horaCartao !== ''): ?>
                                                    <span><i class="fas fa-clock me-1"></i><?php echo htmlspecialchars($horaCartao); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($temPedidosOnline && $plano_atual !== 'BASICO'): ?>
                                            <?php $link = $mesaQrUrls[(int)$m['id']] ?? ''; ?>
                                            <a class="mesa-qr-wrap" href="<?php echo htmlspecialchars($link); ?>" target="_blank" title="Abrir cardápio" rel="noopener">
                                                <img data-qr-local="<?php echo htmlspecialchars($link, ENT_QUOTES, 'UTF-8'); ?>" width="80" height="80" alt="QR Code Mesa <?php echo htmlspecialchars((string)$m['numero']); ?>">
                                            </a>
                                        <?php else: ?>
                                            <div class="mesa-qr-locked">
                                                <i class="fas fa-lock me-1"></i>QR indisponível neste plano
                                            </div>
                                        <?php endif; ?>

                                        <span class="badge-custom badge-<?php echo $status_lower == 'livre' ? 'success' : ($status_lower == 'ocupada' ? 'danger' : 'warning'); ?>">
                                            <?php echo $m['status']; ?>
                                        </span>

                                        <div class="mesa-actions">
                                            <button
                                                type="button"
                                                class="mesa-action livre<?php echo $m['status'] === 'LIVRE' ? ' is-current' : ''; ?>"
                                                <?php echo $m['status'] === 'LIVRE' ? 'disabled' : ''; ?>
                                                onclick="atualizarMesa(<?php echo (int)$m['id']; ?>, 'LIVRE')"
                                                title="Marcar como livre">
                                                <i class="fas fa-check"></i><span>Livre</span>
                                            </button>
                                            <button
                                                type="button"
                                                class="mesa-action ocupar<?php echo $m['status'] === 'OCUPADA' ? ' is-current' : ''; ?>"
                                                <?php echo $m['status'] === 'OCUPADA' ? 'disabled' : ''; ?>
                                                onclick="atualizarMesa(<?php echo (int)$m['id']; ?>, 'OCUPADA')"
                                                title="Marcar como ocupada">
                                                <i class="fas fa-user"></i><span>Ocupar</span>
                                            </button>
                                            <button
                                                type="button"
                                                class="mesa-action reservar<?php echo $m['status'] === 'RESERVADA' ? ' is-current' : ''; ?>"
                                                <?php echo $m['status'] === 'RESERVADA' ? 'disabled' : ''; ?>
                                                onclick="abrirModalReserva(<?php echo (int)$m['id']; ?>, '<?php echo htmlspecialchars((string)$m['numero'], ENT_QUOTES, 'UTF-8'); ?>', <?php echo (int)($m['capacidade'] ?? 4); ?>)"
                                                title="Reservar mesa">
                                                <i class="fas fa-calendar"></i><span>Reservar</span>
                                            </button>
                                            <?php if ($temPedidosOnline && $plano_atual !== 'BASICO'): ?>
                                                <button
                                                    type="button"
                                                    class="mesa-action qr js-baixar-qr-mesa"
                                                    data-mesa-link="<?php echo htmlspecialchars($link, ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-mesa-numero="<?php echo htmlspecialchars((string)$m['numero'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    onclick="baixarQrMesa(this)"
                                                    title="Baixar QR fixo do cardapio/pedidos">
                                                    <i class="fas fa-download"></i><span>QR Cardapio</span>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <?php if (empty($mesasSalao)): ?>
                                <div class="col-12 text-center py-5">
                                    <i class="fas fa-chair fa-3x text-muted mb-3 d-block"></i>
                                    <p class="text-muted">Nenhuma mesa cadastrada</p>
                                    <button class="btn btn-primary" onclick="abrirModalNovaMesa('mesa')"><i class="fas fa-plus me-2"></i>Adicionar Mesa</button>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- MODAL NOVA MESA -->
    <div class="modal fade<?php echo !empty($isEmpresarialUi) ? ' mesa-create-modal' : ''; ?>" id="modalNovaMesa" tabindex="-1" aria-labelledby="modalNovaMesaTitulo" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered<?php echo !empty($isEmpresarialUi) ? ' modal-lg mesa-create-dialog' : ''; ?>">
            <div class="modal-content<?php echo !empty($isEmpresarialUi) ? ' mesa-create-content' : ''; ?>">
                <div class="modal-header">
                    <?php if (!empty($isEmpresarialUi)): ?>
                        <div class="mesa-create-heading">
                            <span class="mesa-create-icon"><i class="fas fa-chair"></i></span>
                            <div>
                                <span class="mesa-create-kicker"><i class="fas fa-crown"></i> Gestão do salão</span>
                                <h5 class="modal-title" id="modalNovaMesaTitulo">Adicionar nova mesa</h5>
                                <p>Configure a identificação e a lotação para incluir a mesa no mapa do salão.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <h5 class="modal-title" id="modalNovaMesaTitulo"><i class="fas fa-plus me-2"></i>Nova Mesa</h5>
                    <?php endif; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <div class="alert" id="alertMesa" style="display: none;"></div>
                    <form id="formNovaMesa"<?php echo !empty($isEmpresarialUi) ? ' class="mesa-create-form"' : ''; ?>>
                        <input type="hidden" id="mesa_tipo" name="tipo" value="mesa">
                        <div class="mb-3">
                            <label class="form-label" id="mesa_numero_label" for="mesa_numero">Identificação no salão *</label>
                            <input type="text" id="mesa_numero" name="numero" class="form-control" required maxlength="30" autocomplete="off" placeholder="Número ou zona no mapa do salão">
                            <?php if (!empty($isEmpresarialUi)): ?>
                                <div class="form-text">Use uma identificação curta e única, igual à utilizada pela equipa no salão.</div>
                            <?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" id="mesa_capacidade_label" for="mesa_capacidade">Capacidade (pessoas) *</label>
                            <input type="number" id="mesa_capacidade" name="capacidade" class="form-control" min="1" max="50" value="4" required inputmode="numeric" placeholder="Lugares desta mesa">
                            <?php if (!empty($isEmpresarialUi)): ?>
                                <div class="mesa-create-capacity" role="group" aria-label="Capacidades rápidas">
                                    <span>Seleção rápida</span>
                                    <?php foreach ([2, 4, 6, 8, 10] as $capacidadeRapida): ?>
                                        <button type="button" class="mesa-capacity-chip<?php echo $capacidadeRapida === 4 ? ' is-active' : ''; ?>" data-mesa-capacidade="<?php echo $capacidadeRapida; ?>"><?php echo $capacidadeRapida; ?></button>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </form>
                    <?php if (!empty($isEmpresarialUi)): ?>
                        <aside class="mesa-create-preview" aria-live="polite">
                            <div class="mesa-create-preview-top">
                                <span>Pré-visualização no salão</span>
                                <em><i class="fas fa-circle"></i> Livre</em>
                            </div>
                            <div class="mesa-create-preview-card">
                                <span class="mesa-create-preview-chair"><i class="fas fa-chair"></i></span>
                                <div>
                                    <strong id="mesaCreatePreviewNumero">Identificação no salão</strong>
                                    <small><i class="fas fa-users"></i> <span id="mesaCreatePreviewCapacidade">4</span> lugares</small>
                                </div>
                            </div>
                            <p><i class="fas fa-circle-info"></i> A nova mesa será criada como livre e ficará imediatamente disponível no mapa.</p>
                        </aside>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" form="formNovaMesa" id="btnSalvarNovaMesa" class="btn btn-primary"><i class="fas fa-plus me-2"></i><?php echo !empty($isEmpresarialUi) ? 'Adicionar mesa' : 'Salvar'; ?></button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalReservaMesa" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-calendar-plus me-2"></i>Nova Reserva</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert" id="alertReserva" style="display: none;"></div>
                    <form id="formReservaMesa">
                        <input type="hidden" id="reserva_mesa_id" name="mesa_atribuida">
                        <div class="mb-3">
                            <label class="form-label">Mesa selecionada</label>
                            <input type="text" id="reserva_mesa_label" class="form-control" readonly placeholder="Atribuição automática">
                            <div class="form-text text-muted" id="reserva_disponibilidade">Selecione data, hora e quantidade para ver a disponibilidade.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Cliente *</label>
                            <input type="text" id="reserva_nome_cliente" name="nome_cliente" class="form-control" required placeholder="Nome completo">
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Telefone</label>
                                <input type="text" id="reserva_telefone_cliente" name="telefone_cliente" class="form-control" placeholder="Telefone">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email <?php echo $plano_atual === 'PROFISSIONAL' ? '*' : ''; ?></label>
                                <input type="email" id="reserva_email_cliente" name="email_cliente" class="form-control" placeholder="Email" <?php echo $plano_atual === 'PROFISSIONAL' ? 'required' : ''; ?>>
                                <?php if ($plano_atual === 'PROFISSIONAL'): ?>
                                    <div class="form-text">Usado para enviar confirmação ou cancelamento da reserva.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="row g-3 mt-1">
                            <div class="col-md-4">
                                <label class="form-label">Data *</label>
                                <input type="date" id="reserva_data" name="data_reserva" class="form-control" value="<?php echo htmlspecialchars($dataHoje); ?>" min="<?php echo htmlspecialchars($dataHoje); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Hora *</label>
                                <input type="time" id="reserva_hora" name="hora_reserva" class="form-control" value="19:00" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Pessoas *</label>
                                <input type="number" id="reserva_quantidade" name="quantidade_pessoas" class="form-control" min="1" value="2" required placeholder="N.º de pessoas">
                            </div>
                        </div>
                        <div class="mt-3">
                            <label class="form-label">Observações</label>
                            <textarea id="reserva_observacoes" name="observacoes" class="form-control" rows="3" placeholder="Preferências ou restrições"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" id="btnSalvarReserva" form="formReservaMesa" class="btn btn-warning"><i class="fas fa-save me-2"></i>Salvar Reserva</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade<?php echo !empty($isEmpresarialUi) ? ' qr-reserva-emp-modal' : ''; ?>" id="modalReservaQr" tabindex="-1" aria-labelledby="modalReservaQrTitulo" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered<?php echo !empty($isEmpresarialUi) ? ' qr-reserva-emp-dialog' : ''; ?>">
            <div class="modal-content<?php echo !empty($isEmpresarialUi) ? ' qr-reserva-emp-content' : ''; ?>">
                <div class="modal-header">
                    <?php if (!empty($isEmpresarialUi)): ?>
                        <div class="qr-reserva-emp-heading">
                            <span class="qr-reserva-emp-heading-icon"><i class="fas fa-qrcode"></i></span>
                            <div>
                                <span class="qr-reserva-emp-kicker"><i class="fas fa-crown"></i> Experiência do cliente</span>
                                <h5 class="modal-title" id="modalReservaQrTitulo">QR temporário de Reserva / Check-in</h5>
                                <p>Crie um acesso seguro para o cliente reservar, confirmar dados ou realizar o check-in.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <h5 class="modal-title" id="modalReservaQrTitulo"><i class="fas fa-user-check me-2"></i>QR temporario de Reserva/Check-in</h5>
                    <?php endif; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <div class="alert" id="alertReservaQr" style="display:none;"></div>
                    <?php if (!empty($isEmpresarialUi)): ?>
                        <div class="qr-reserva-emp-flow" aria-label="Fluxo do QR temporário">
                            <span><em>1</em>Configurar acesso</span>
                            <i class="fas fa-chevron-right"></i>
                            <span><em>2</em>Gerar QR seguro</span>
                            <i class="fas fa-chevron-right"></i>
                            <span><em>3</em>Enviar ao cliente</span>
                        </div>
                    <?php endif; ?>
                    <form id="formReservaQrInterna"<?php echo !empty($isEmpresarialUi) ? ' class="qr-reserva-emp-form"' : ''; ?>>
                        <div class="row g-3">
                            <?php if (!empty($isEmpresarialUi)): ?>
                                <div class="col-12 qr-reserva-emp-section-title">
                                    <span><i class="fas fa-sliders"></i></span>
                                    <div><strong>Configuração do acesso</strong><small>Defina a mesa e por quanto tempo o QR ficará disponível.</small></div>
                                </div>
                            <?php endif; ?>
                            <div class="col-md-6">
                                <label class="form-label" for="qr_mesa_id">Mesa do check-in</label>
                                <select class="form-control" id="qr_mesa_id" name="mesa_id">
                                    <option value="">Atribuir a melhor mesa livre</option>
                                    <?php foreach ($mesasSalao as $mesaOption): ?>
                                        <?php $optId = (int)$mesaOption['id']; $optLabel = $mesaDisplayLabel[$optId] ?? mesa_rotulo($mesaOption['numero'] ?? ''); ?>
                                        <option value="<?php echo $optId; ?>"><?php echo htmlspecialchars($optLabel, ENT_QUOTES, 'UTF-8'); ?> • <?php echo (int)($mesaOption['capacidade'] ?? 0); ?> lugares<?php echo strtoupper((string)($mesaOption['status'] ?? '')) === 'OCUPADA' ? ' • ocupada agora' : ''; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="qr_expira_em_minutos">Expira em</label>
                                <select class="form-control" id="qr_expira_em_minutos" name="expira_em_minutos">
                                    <option value="30">30 minutos</option>
                                    <option value="60">1 hora</option>
                                    <option value="120" selected>2 horas</option>
                                    <option value="240">4 horas</option>
                                    <option value="1440">24 horas</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="qr_nome_atendente">Atendente da recepção</label>
                                <input class="form-control" id="qr_nome_atendente" name="nome_atendente" value="<?php echo htmlspecialchars((string)($_SESSION['nome'] ?? '')); ?>" placeholder="Quem gera o acesso na recepção" autocomplete="name">
                            </div>
                            <?php if (!empty($isEmpresarialUi)): ?>
                                <div class="col-12 qr-reserva-emp-section-title">
                                    <span><i class="fas fa-user"></i></span>
                                    <div><strong>Dados do cliente</strong><small>O nome é obrigatório; telefone e e-mail habilitam os canais de envio.</small></div>
                                </div>
                            <?php endif; ?>
                            <div class="col-md-6">
                                <label class="form-label" for="qr_nome_cliente">Nome do hóspede / cliente *</label>
                                <input class="form-control" id="qr_nome_cliente" name="nome_cliente" placeholder="Nome que aparece no cartão QR" required autocomplete="name">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="qr_telefone_cliente">WhatsApp do cliente</label>
                                <input type="tel" class="form-control" id="qr_telefone_cliente" name="telefone_cliente" placeholder="WhatsApp para enviar o QR" autocomplete="tel" inputmode="tel">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="qr_email_cliente">E-mail para enviar o QR</label>
                                <input type="email" class="form-control" id="qr_email_cliente" name="email_cliente" placeholder="E-mail para enviar o cartão QR" autocomplete="email">
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="qr_observacoes">Notas da reserva</label>
                                <textarea class="form-control" id="qr_observacoes" name="observacoes" rows="2" placeholder="Pedido especial a mostrar no check-in"></textarea>
                            </div>
                        </div>
                    </form>
                    <div id="qrReservaResultado" class="mt-4<?php echo !empty($isEmpresarialUi) ? ' qr-reserva-emp-result' : ''; ?>" style="display:none;">
                        <?php if (!empty($isEmpresarialUi)): ?>
                            <div class="qr-reserva-emp-result-head">
                                <div><span><i class="fas fa-shield-halved"></i></span><strong>QR pronto para compartilhar</strong></div>
                                <em><i class="fas fa-circle"></i> Acesso protegido</em>
                            </div>
                        <?php endif; ?>
                        <div class="row g-3 align-items-center">
                            <div class="col-md-4 text-center">
                                <img id="qrReservaImagem" alt="QR Code da reserva" class="img-fluid rounded border p-2 bg-white">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label">Referencia do QR</label>
                                <input class="form-control" id="qrReservaLink" readonly>
                                <div class="d-flex gap-2 mt-3 flex-wrap">
                                    <button type="button" class="btn btn-outline-secondary" id="btnEnviarReservaQrEmail" style="display:none;" onclick="enviarReservaQrEmail()"><i class="fas fa-envelope me-2"></i>Enviar por E-mail</button>
                                    <button type="button" class="btn btn-success" id="btnEnviarReservaQrWhatsApp" style="display:none;" onclick="enviarReservaQrWhatsAppManual()"><i class="fab fa-whatsapp me-2"></i>Enviar pelo WhatsApp</button>
                                    <a href="#" class="btn btn-dark" id="btnDownloadReservaQr" style="display:none;" download onclick="registrarDownloadReservaQr()"><i class="fas fa-download me-2"></i>Baixar QR Code</a>
                                </div>
                                <div class="mt-3">
                                    <label for="qrReservaAnexo" class="form-label">Imagem do QR para WhatsApp</label>
                                    <input type="file" class="form-control" id="qrReservaAnexo" accept=".png,.jpg,.jpeg,image/png,image/jpeg">
                                    <div class="form-text mt-2" id="qrReservaAnexoInfo">Baixe o QR Code, selecione a imagem baixada e depois clique em "Enviar pelo WhatsApp".</div>
                                </div>
                                <div class="form-text mt-2" id="qrReservaCanais"></div>
                                <div class="form-text mt-2" id="qrReservaExpiracao"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <button type="submit" id="btnGerarReservaQr" form="formReservaQrInterna" class="btn<?php echo !empty($isEmpresarialUi) ? ' qr-reserva-emp-submit' : ' btn-dark'; ?>"><i class="fas fa-shield-halved me-2"></i>Gerar QR seguro</button>
                </div>
            </div>
        </div>
    </div>

    <script src="lib/bootstrap-5.3.2/js/bootstrap.bundle.min.js"></script>
    <script src="lib/qrcode-generator-1.4.4/qrcode.js"></script>
    <script src="js/mesas.js?v=20260829u"></script>
    <script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
        // Segundo canal de balcão (Empresarial): activar, renomear e desligar.
        (function initBalcao2() {
            var box = document.getElementById('balcao2Box');
            if (!box) return;
            var token = <?php echo json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            var form = document.getElementById('balcao2Form');
            var input = document.getElementById('balcao2NomeInput');
            var aviso = document.getElementById('balcao2Aviso');
            var acaoPendente = '';

            function mostrarAviso(msg) {
                if (!aviso) return;
                aviso.textContent = msg;
                aviso.classList.remove('d-none');
                aviso.classList.add('text-danger');
            }

            function abrirForm(acao, placeholderBotao) {
                acaoPendente = acao;
                if (form) form.classList.remove('d-none');
                var btn = document.getElementById('btnBalcao2Confirmar');
                if (btn) btn.textContent = placeholderBotao;
                if (input && acao !== 'desativar') { input.classList.remove('d-none'); input.focus(); input.select(); }
                if (input && acao === 'desativar') input.classList.add('d-none');
                if (aviso && acao === 'desativar') {
                    aviso.textContent = 'Os pedidos antigos ficam no histórico e pode voltar a ligar o canal quando quiser.';
                    aviso.classList.remove('d-none', 'text-danger');
                }
            }

            function chamar() {
                var fd = new FormData();
                fd.append('acao', acaoPendente);
                if (acaoPendente !== 'desativar' && input) fd.append('nome', input.value);
                fd.append('_csrf', token);
                fetch('api/balcao_segundo.php', { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        if (j && j.success) { window.location.reload(); return; }
                        mostrarAviso((j && j.message) || 'Não foi possível alterar o canal.');
                    })
                    .catch(function () { mostrarAviso('Não foi possível alterar o canal.'); });
            }

            var btnAtivar = document.getElementById('btnBalcao2Ativar');
            if (btnAtivar) btnAtivar.addEventListener('click', function () { abrirForm('ativar', 'Activar canal'); });
            var btnRen = document.getElementById('btnBalcao2Renomear');
            if (btnRen) btnRen.addEventListener('click', function () { abrirForm('renomear', 'Guardar nome'); });
            var btnDes = document.getElementById('btnBalcao2Desligar');
            if (btnDes) btnDes.addEventListener('click', function () { abrirForm('desativar', 'Desligar mesmo'); });
            var btnConf = document.getElementById('btnBalcao2Confirmar');
            if (btnConf) btnConf.addEventListener('click', chamar);
            var btnCanc = document.getElementById('btnBalcao2Cancelar');
            if (btnCanc) btnCanc.addEventListener('click', function () {
                if (form) form.classList.add('d-none');
                if (aviso) aviso.classList.add('d-none');
                acaoPendente = '';
            });
        })();

        window.RESERVAS_CONFIG = {
            token: <?php echo json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            endpoint: 'api/reservas.php',
            hoje: <?php echo json_encode($dataHoje, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            qrEndpoint: 'api/reservas_qr.php',
            restauranteNome: <?php echo json_encode($restauranteNomeQr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
        };

        <?php if (!empty($isEmpresarialUi)): ?>
        (function initMesasEmpresarial() {
            const busca = document.getElementById('mesas-emp-busca');
            const filtro = document.getElementById('mesas-emp-filtro-status');
            const countEl = document.getElementById('mesas-emp-count');
            const floor = document.getElementById('mesas-emp-floor');
            const tableWrap = document.getElementById('mesas-emp-table-wrap');
            const toggles = document.querySelectorAll('.mesas-emp-view-toggle button');
            const chips = document.querySelectorAll('#mesas-emp-chips .mesas-emp-chip');
            if (!busca || !filtro) return;

            function setChipActive(value) {
                chips.forEach(function (c) {
                    c.classList.toggle('is-active', (c.getAttribute('data-chip') || '') === value);
                });
            }

            function aplicarFiltros() {
                const q = String(busca.value || '').trim().toLowerCase();
                const st = String(filtro.value || 'todos');
                document.querySelectorAll('#mesas-emp-layout .mesas-emp-item').forEach(function (el) {
                    const status = el.getAttribute('data-status') || '';
                    const pedido = el.getAttribute('data-pedido') === '1';
                    const atrasada = el.getAttribute('data-atrasada') === '1';
                    const cap = parseInt(el.getAttribute('data-cap') || '0', 10) || 0;
                    const search = el.getAttribute('data-search') || '';
                    let ok = true;
                    if (q && search.indexOf(q) === -1) ok = false;
                    if (st === 'COM_PEDIDO' && !pedido) ok = false;
                    else if (st === 'ATRASADA' && !atrasada) ok = false;
                    else if (st === 'GRANDE_LIVRE' && !(status === 'LIVRE' && cap >= 6)) ok = false;
                    else if (st !== 'todos' && st !== 'COM_PEDIDO' && st !== 'ATRASADA' && st !== 'GRANDE_LIVRE' && status !== st) ok = false;
                    el.classList.toggle('is-hidden', !ok);
                });
                const floorVisible = document.querySelectorAll('#mesas-emp-floor .mesas-emp-item:not(.is-hidden)').length;
                if (countEl) countEl.textContent = floorVisible + ' mesas';
                setChipActive(st);
            }

            busca.addEventListener('input', aplicarFiltros);
            filtro.addEventListener('change', aplicarFiltros);

            chips.forEach(function (chip) {
                chip.addEventListener('click', function () {
                    filtro.value = chip.getAttribute('data-chip') || 'todos';
                    aplicarFiltros();
                });
            });

            toggles.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    toggles.forEach(function (b) { b.classList.remove('is-active'); });
                    btn.classList.add('is-active');
                    const view = btn.getAttribute('data-view');
                    if (view === 'table') {
                        if (floor) floor.hidden = true;
                        if (tableWrap) tableWrap.hidden = false;
                    } else {
                        if (floor) floor.hidden = false;
                        if (tableWrap) tableWrap.hidden = true;
                    }
                    aplicarFiltros();
                });
            });

            aplicarFiltros();
        })();
        <?php endif; ?>

        setInterval(function() {
            fetch('api/online_ping.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
        }, 60000);
    </script>
    <script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
    (function () {
        function qrDataUrl(texto, cel) {
            if (typeof qrcode !== 'function') return '';
            var q = qrcode(0, 'M'); q.addData(String(texto)); q.make();
            return q.createDataURL(cel || 4, 2);
        }
        // QR gerados localmente (sem enviar o link a sites externos)
        document.querySelectorAll('img[data-qr-local]').forEach(function (img) {
            var url = qrDataUrl(img.getAttribute('data-qr-local'), 3);
            if (url) img.src = url;
        });
        document.addEventListener('click', function (ev) {
            var b = ev.target.closest('.mesas-emp-qr-btn');
            if (!b) return;
            var link = b.getAttribute('data-mesa-link') || '';
            var num = b.getAttribute('data-mesa-numero') || '';
            var url = qrDataUrl(link, 8);
            if (!url) return;
            var m = document.createElement('div');
            m.className = 'nv-qr-modal';
            m.innerHTML = '<div class="nv-qr-modal-box" role="dialog" aria-modal="true"><h5></h5><p>O cliente lê este código para ver o cardápio e pedir.</p><img alt=""><div class="nv-qr-modal-acts"><button type="button" data-a="fechar">Fechar</button><button type="button" class="primary" data-a="baixar"><i class="fas fa-download me-1"></i>Baixar</button></div></div>';
            m.querySelector('h5').textContent = 'QR da Mesa ' + num;
            m.querySelector('img').src = url;
            m.querySelector('img').alt = 'QR da Mesa ' + num;
            m.addEventListener('click', function (e) {
                var a = e.target.closest('[data-a]');
                if (e.target === m || (a && a.getAttribute('data-a') === 'fechar')) { m.remove(); return; }
                if (a && a.getAttribute('data-a') === 'baixar' && typeof baixarQrMesa === 'function') { baixarQrMesa(b); }
            });
            document.body.appendChild(m);
        });
    })();
    </script>
</body>

</html>
