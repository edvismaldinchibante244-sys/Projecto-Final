<?php
require_once __DIR__ . '/app_base.php';
// Proteção da página
include_once APP_BASE . '/src/config/auth_check.php';
include_once APP_BASE . '/src/config/csrf.php';
include_once APP_BASE . '/src/config/plano_check.php';
include_once APP_BASE . '/src/config/restaurante_context.php';
include_once APP_BASE . '/src/config/pedido_schema.php';
include_once APP_BASE . '/src/config/reserva_qr_schema.php';
include_once APP_BASE . '/src/config/mesa_display_helper.php';
include_once APP_BASE . '/src/config/input_validation.php';

header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');

$restaurante_id = session_restaurante_contexto_id();
if ($restaurante_id <= 0) {
    header("Location: index.php?erro=sem_restaurante");
    exit;
}

requirePermissionOrRedirect(['ADMIN', 'GERENTE', 'CAIXA', 'GARCOM', 'COZINHA']);

$planRidPedidos = session_restaurante_capability_id();
if ($planRidPedidos <= 0) {
    $planRidPedidos = $restaurante_id;
}
plano_verificar_funcionalidade($planRidPedidos, 'pedidos_online');
include_once APP_BASE . '/src/config/database.php';

include_once APP_BASE . '/src/Model/Mesa.php';
$csrfToken = csrf_get_token();
$dadosPlanoPedidos = plano_get_dados($planRidPedidos);
$plano_atual = plano_normalizar_nome($dadosPlanoPedidos['plano_nome'] ?? 'BASICO');
require_once __DIR__ . '/includes/empresarial_ui.php';

function normalizar_status_pedido($status)
{
    $status = strtoupper(trim((string)$status));
    $map = [
        'PENDENTE' => 'NOVO',
        'CONFIRMADO' => 'PREPARANDO',
        'NOVO' => 'NOVO',
        'PREPARANDO' => 'PREPARANDO',
        'PRONTO' => 'PRONTO',
        'ENTREGUE' => 'ENTREGUE',
        'CONTA' => 'CONTA',
        'CONTA_SOLICITADA' => 'CONTA',
        'PEDIR_CONTA' => 'CONTA',
        'PAGO' => 'PAGO',
        'CANCELADO' => 'CANCELADO'
    ];
    return $map[$status] ?? 'NOVO';
}

// Conectar ao banco
$database = new Database();
$db = $database->getConnection();
pedido_schema_garantir($db);

// URL base para QR Code
$protocol     = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host         = $_SERVER['HTTP_HOST'];
$base_url     = $protocol . '://' . $host;
$script_dir   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$cardapio_url = reserva_qr_get_or_create_cardapio_geral_url($db, $restaurante_id) ?? '';

// Buscar mesas
$mesa_obj    = new Mesa($db);
$stmt_mesas  = $mesa_obj->listar($restaurante_id);
$todas_mesas = $stmt_mesas->fetchAll(PDO::FETCH_ASSOC);
$mesaQrUrls = [];
$mesasQrImpressao = [];

foreach ($todas_mesas as $mesaRow) {
    if (mesa_eh_balcao($mesaRow['numero'] ?? '')) {
        continue;
    }
    $mesaIdQr = (int)($mesaRow['id'] ?? 0);
    if ($mesaIdQr <= 0) {
        continue;
    }
    // QR fisico da mesa abre o cardapio/pedido publico por token secreto.
    $urlQrMesa = reserva_qr_get_or_create_cardapio_mesa_url($db, $restaurante_id, $mesaIdQr);
    if ($urlQrMesa !== null) {
        $mesaQrUrls[$mesaIdQr] = $urlQrMesa;
        $mesasQrImpressao[] = [
            'id' => $mesaIdQr,
            'numero' => (string)($mesaRow['numero'] ?? ''),
        ];
    }
}

// Buscar pedidos do dia
$filtroRaw = $_GET['filter'] ?? '';
$filtro = input_enum($filtroRaw, ['', 'pendentes'], true);
if ($filtro === null) {
    header('Location: pedidos.php');
    exit;
}
$filtroSql = '';
if ($filtro === 'pendentes') {
    $filtroSql = " AND p.status IN ('NOVO','PENDENTE','PREPARANDO')";
}
$query = "SELECT p.*, m.numero as mesa_numero,
          (SELECT COUNT(*) FROM itens_pedido WHERE pedido_id = p.id) as total_itens,
          (SELECT GROUP_CONCAT(CONCAT(ip.quantidade, 'x ', COALESCE(pr.nome, CONCAT('Produto #', ip.produto_id))) SEPARATOR ', ')
             FROM itens_pedido ip
             LEFT JOIN produtos pr ON pr.id = ip.produto_id
            WHERE ip.pedido_id = p.id) AS itens_resumo
          FROM pedidos p
          LEFT JOIN mesas m ON p.mesa_id = m.id
          WHERE p.restaurante_id = :rid
          AND (p.criado_em >= CURDATE() AND p.criado_em < CURDATE() + INTERVAL 1 DAY)"
          . $filtroSql .
          " ORDER BY p.criado_em DESC";
$stmt = $db->prepare($query);
$stmt->bindParam(':rid', $restaurante_id, PDO::PARAM_INT);
$stmt->execute();
$pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pedidos_ui = [];
foreach ($pedidos as $pedido) {
    $pedido['status'] = normalizar_status_pedido($pedido['status'] ?? 'NOVO');
    $pedido['origem'] = pedido_normalizar_origem($pedido['origem'] ?? 'BALCAO');
    $pedidos_ui[] = $pedido;
}
$pedidos = $pedidos_ui;

// Contadores por status
$contadores = ['NOVO' => 0, 'PREPARANDO' => 0, 'PRONTO' => 0, 'ENTREGUE' => 0, 'CONTA' => 0, 'PAGO' => 0, 'CANCELADO' => 0];
foreach ($pedidos as $p) {
    if (isset($contadores[$p['status']])) {
        $contadores[$p['status']]++;
    }
}

// Métricas só da fila (sem duplicar Mesas/Reservas/QR)
$filaSlaMinutos = 15;
$filaActivaPedidos = (int)$contadores['NOVO'] + (int)$contadores['PREPARANDO'] + (int)$contadores['PRONTO'];
$filaAtrasados = 0;
$filaEsperaMaxMin = 0;
$agoraTs = time();
foreach ($pedidos as $pFila) {
    $stFila = (string)($pFila['status'] ?? '');
    if (!in_array($stFila, ['NOVO', 'PREPARANDO', 'PRONTO'], true)) {
        continue;
    }
    $criadoTs = strtotime((string)($pFila['criado_em'] ?? ''));
    if ($criadoTs <= 0) {
        continue;
    }
    $minsFila = (int)max(0, floor(($agoraTs - $criadoTs) / 60));
    if ($minsFila > $filaEsperaMaxMin) {
        $filaEsperaMaxMin = $minsFila;
    }
    if ($minsFila >= $filaSlaMinutos) {
        $filaAtrasados++;
    }
}

// Pedidos do dia sem os cancelados (um pedido cancelado nao e movimento do dia).
$pedidosDiaSemCancelados = (int)array_sum($contadores) - (int)$contadores['CANCELADO'];
$pedidosHojeTotal = $pedidosDiaSemCancelados;
$pedidosOntemTotal = 0;
$pedidosHojeDeltaPct = 0.0;
try {
    $stmtOntem = $db->prepare("SELECT COUNT(*) FROM pedidos WHERE restaurante_id = :rid AND (criado_em >= CURDATE() - INTERVAL 1 DAY AND criado_em < CURDATE()) AND UPPER(TRIM(status)) <> 'CANCELADO'");
    $stmtOntem->execute([':rid' => $restaurante_id]);
    $pedidosOntemTotal = (int)$stmtOntem->fetchColumn();
    if ($pedidosOntemTotal > 0) {
        $pedidosHojeDeltaPct = (($pedidosHojeTotal - $pedidosOntemTotal) / $pedidosOntemTotal) * 100;
    } elseif ($pedidosHojeTotal > 0) {
        $pedidosHojeDeltaPct = 100;
    }
} catch (Throwable $eOntem) {
    $pedidosOntemTotal = 0;
}

$pode_receber_pagamento = checkPermission(['ADMIN', 'GERENTE', 'CAIXA']);
$pode_gerir_fluxo_pedido = checkPermission(['ADMIN', 'GERENTE', 'COZINHA', 'BAR', 'GARCOM']);
$pode_pedir_conta = checkPermission(['ADMIN', 'GERENTE', 'GARCOM', 'CAIXA', 'COZINHA', 'BAR']);
// Garcom/caixa veem o botao; o servidor pede o PIN do gerente antes de cancelar.
$pode_cancelar_pedido = checkPermission(['ADMIN', 'GERENTE', 'GARCOM', 'CAIXA']);
$userNomePedidos = (string)($_SESSION['nome'] ?? 'Utilizador');
$userPerfilPedidos = (string)($_SESSION['perfil'] ?? '');
if (!function_exists('pedidos_img_iniciais')) {
    /** Avatar desenhado aqui mesmo (sem sites externos): iniciais do nome, com acentos. */
    function pedidos_img_iniciais(string $nome, string $cor = '#ea580c', int $tam = 50): string
    {
        $partes = preg_split('/\s+/u', trim($nome)) ?: [];
        $ini = '';
        foreach ($partes as $parte) {
            if ($parte === '') {
                continue;
            }
            $ini .= mb_substr($parte, 0, 1, 'UTF-8');
            if (mb_strlen($ini, 'UTF-8') >= 2) {
                break;
            }
        }
        $ini = htmlspecialchars(mb_strtoupper($ini !== '' ? $ini : '?', 'UTF-8'), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $tam . '" height="' . $tam . '" viewBox="0 0 ' . $tam . ' ' . $tam . '">'
            . '<rect width="100%" height="100%" rx="' . (int)($tam / 2) . '" fill="' . htmlspecialchars($cor, ENT_QUOTES) . '"/>'
            . '<text x="50%" y="50%" dy=".35em" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="' . (int)round($tam * 0.4) . '" font-weight="700" fill="#fff">' . $ini . '</text></svg>';
        return 'data:image/svg+xml;charset=UTF-8,' . rawurlencode($svg);
    }
}

$isProfissionalPedidos = (($plano_atual ?? '') === 'PROFISSIONAL');

// Tempo medio de preparo de hoje (Profissional; o Empresarial ja tem no painel lateral).
$peProPrepMin = null;
if ($isProfissionalPedidos && $db instanceof PDO) {
    try {
        $stPrepPro = $db->prepare("
            SELECT AVG(TIMESTAMPDIFF(MINUTE, criado_em, pronto_em))
            FROM pedidos
            WHERE restaurante_id = :rid
              AND criado_em >= CURDATE() AND criado_em < CURDATE() + INTERVAL 1 DAY
              AND pronto_em IS NOT NULL AND pronto_em >= criado_em
              AND TIMESTAMPDIFF(MINUTE, criado_em, pronto_em) <= 240
        ");
        $stPrepPro->execute([':rid' => $restaurante_id]);
        $vPrep = $stPrepPro->fetchColumn();
        $peProPrepMin = ($vPrep !== null && $vPrep !== false) ? (int)round((float)$vPrep) : null;
    } catch (Throwable $e) {
        $peProPrepMin = null;
    }
}

$peHoraTrava = '—';
$peHoraTravaQtd = 0;
if (($plano_atual ?? '') === 'EMPRESARIAL' && $db instanceof PDO) {
    try {
        $stmtPeHora = $db->prepare("
            SELECT HOUR(criado_em) AS h, COUNT(*) AS qtd
            FROM pedidos
            WHERE restaurante_id = :rid
              AND (criado_em >= CURDATE() AND criado_em < CURDATE() + INTERVAL 1 DAY)
              AND UPPER(TRIM(status)) <> 'CANCELADO'
            GROUP BY HOUR(criado_em)
            ORDER BY qtd DESC
            LIMIT 1
        ");
        $stmtPeHora->execute([':rid' => $restaurante_id]);
        $rowPeH = $stmtPeHora->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($rowPeH) {
            $peHoraTrava = sprintf('%02d:00', (int)$rowPeH['h']);
            $peHoraTravaQtd = (int)$rowPeH['qtd'];
        }
    } catch (Throwable $ePeH) {
        // ignore
    }
}
// Resumo do dia para o painel lateral (Empresarial)
$peResumo = ['faturado' => 0.0, 'pagos' => 0, 'ticket' => 0.0, 'prep_min' => null, 'cancelados' => 0, 'ultimos' => []];
if (($plano_atual ?? '') === 'EMPRESARIAL' && $db instanceof PDO) {
    try {
        $stR = $db->prepare("
            SELECT
              SUM(CASE WHEN UPPER(TRIM(status)) = 'PAGO' THEN total ELSE 0 END) AS faturado,
              SUM(CASE WHEN UPPER(TRIM(status)) = 'PAGO' THEN 1 ELSE 0 END) AS pagos,
              SUM(CASE WHEN UPPER(TRIM(status)) = 'CANCELADO' THEN 1 ELSE 0 END) AS cancelados,
              AVG(CASE WHEN pronto_em IS NOT NULL AND pronto_em >= criado_em
                       AND TIMESTAMPDIFF(MINUTE, criado_em, pronto_em) <= 240
                       THEN TIMESTAMPDIFF(MINUTE, criado_em, pronto_em) END) AS prep_min
            FROM pedidos
            WHERE restaurante_id = :rid
              AND criado_em >= CURDATE() AND criado_em < CURDATE() + INTERVAL 1 DAY
        ");
        $stR->execute([':rid' => $restaurante_id]);
        $rR = $stR->fetch(PDO::FETCH_ASSOC) ?: [];
        $peResumo['faturado'] = (float)($rR['faturado'] ?? 0);
        $peResumo['pagos'] = (int)($rR['pagos'] ?? 0);
        $peResumo['cancelados'] = (int)($rR['cancelados'] ?? 0);
        $peResumo['ticket'] = $peResumo['pagos'] > 0 ? $peResumo['faturado'] / $peResumo['pagos'] : 0.0;
        $peResumo['prep_min'] = isset($rR['prep_min']) && $rR['prep_min'] !== null ? (int)round((float)$rR['prep_min']) : null;
        $stU = $db->prepare("
            SELECT numero_pedido, id, total, COALESCE(entregue_em, pago_em, pronto_em) AS quando
            FROM pedidos
            WHERE restaurante_id = :rid
              AND criado_em >= CURDATE() AND criado_em < CURDATE() + INTERVAL 1 DAY
              AND UPPER(TRIM(status)) IN ('ENTREGUE','CONTA','PAGO')
            ORDER BY quando DESC, id DESC
            LIMIT 4
        ");
        $stU->execute([':rid' => $restaurante_id]);
        $peResumo['ultimos'] = $stU->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $ePeR) {
        // ignore
    }
}
?>
<!DOCTYPE html>
<html lang="pt">

<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <script src="js/botao_atualizar.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/botao_atualizar.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pedidos Online - Sistema de Restaurante</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">

    <!-- Bootstrap 5 CSS -->
    <link href="lib/bootstrap-5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="lib/fontawesome-6.5.1/css/all.min.css">

    <link rel="stylesheet" href="css/pedidos.css?v=<?php echo (int)@filemtime(__DIR__ . '/css/pedidos.css'); ?>">
    <link rel="stylesheet" href="css/premium.css">
    <?php empresarial_head_assets(); ?>
    <?php if (!empty($isEmpresarialUi)): ?>
        <link rel="stylesheet" href="css/pedidos-empresarial.css?v=<?php echo (int)@filemtime(__DIR__ . "/css/pedidos-empresarial.css"); ?>">
    <?php endif; ?>
</head>

<body class="<?php echo htmlspecialchars(empresarial_body_class('premium-ui' . (!empty($isEmpresarialUi) ? ' pedidos-emp-shell' : ''))); ?>">

    <div class="container-fluid">
        <div class="row">
            <!-- SIDEBAR -->
            <?php include_once __DIR__ . '/includes/sidebar.php'; ?>

            <!-- CONTEÚDO PRINCIPAL -->
            <main class="main-content col-md-9 ms-sm-auto col-lg-10">
                <div id="sound-banner" class="sound-banner">
                    <span><i class="fas fa-volume-up me-2"></i>Toque para ativar alertas sonoros dos pedidos</span>
                    <button type="button" id="sound-enable-btn">Ativar som</button>
                </div>

<?php if (!empty($isEmpresarialUi)): ?>
                <?php
                $peDeltaSinal = $pedidosHojeDeltaPct >= 0 ? '+' : '';
                $peDeltaTxt = $peDeltaSinal . number_format($pedidosHojeDeltaPct, 0, ',', '.') . '%';
                ?>
                <!-- ===== LAYOUT EMPRESARIAL (mockup) ===== -->
                <div class="pe-topbar">
                    <div class="pe-topbar-spacer"></div>
                    <div class="pe-topbar-right">
                        <div class="pe-user">
                            <div>
                                <strong><?php echo htmlspecialchars($userNomePedidos, ENT_QUOTES, 'UTF-8'); ?></strong>
                                <small><?php echo htmlspecialchars($userPerfilPedidos !== '' ? $userPerfilPedidos : 'Equipa', ENT_QUOTES, 'UTF-8'); ?></small>
                            </div>
                            <?php
                            $peIniciais = '';
                            foreach (preg_split('/\s+/u', trim($userNomePedidos)) ?: [] as $peParte) {
                                if ($peParte !== '' && mb_strlen($peIniciais) < 2) { $peIniciais .= mb_strtoupper(mb_substr($peParte, 0, 1)); }
                            }
                            ?>
                            <span class="pe-avatar" aria-hidden="true"><?php echo htmlspecialchars($peIniciais !== '' ? $peIniciais : 'U', ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                    </div>
                </div>

                <div class="pe-header">
                    <div>
                        <h1>
                            <i class="fas fa-clipboard-list pe-title-icon"></i>
                            Pedidos
                            <span class="pe-live" id="rtBadge">Em tempo real</span>
                        </h1>
                    </div>
                    <div class="pe-actions">
                        <button id="btnAtualizarPedidos" type="button" class="pe-btn pe-btn-ghost" onclick="forcarAtualizacaoPedidos(this)">
                            <i class="fas fa-sync-alt"></i> Atualizar
                        </button>
                        <a href="novo_pedido.php" class="pe-btn pe-btn-primary"><i class="fas fa-plus"></i> Novo Pedido</a>
                        <button type="button" class="pe-btn pe-btn-ghost" onclick="document.getElementById('peBoard')?.scrollIntoView({behavior:'smooth',block:'start'})">
                            <i class="fas fa-filter"></i> Filtros
                        </button>
                    </div>
                </div>

                <div class="pe-kpis" aria-label="Resumo de pedidos">
                    <div class="pe-kpi is-hoje">
                        <div class="pe-kpi-top">
                            <div class="label">Pedidos Hoje</div>
                            <span class="pe-kpi-lock" aria-hidden="true"><i class="fas fa-lock"></i></span>
                        </div>
                        <div class="pe-kpi-main">
                            <div class="value" id="countHoje"><?php echo (int)$pedidosHojeTotal; ?></div>
                            <span class="pe-delta" id="hintHoje"><?php echo htmlspecialchars($peDeltaTxt, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                    </div>
                    <div class="pe-kpi is-novo">
                        <div class="pe-kpi-top">
                            <div class="label">Novos</div>
                            <span class="pe-kpi-ico"><i class="fas fa-clipboard-list"></i></span>
                        </div>
                        <div class="value" id="countNovo"><?php echo (int)$contadores['NOVO']; ?></div>
                        <div class="hint">Aguardando confirmação</div>
                    </div>
                    <div class="pe-kpi is-prep">
                        <div class="pe-kpi-top">
                            <div class="label">Em Preparo</div>
                            <span class="pe-kpi-ico"><i class="fas fa-utensils"></i></span>
                        </div>
                        <div class="value" id="countPreparando"><?php echo (int)$contadores['PREPARANDO']; ?></div>
                        <div class="hint">Na cozinha</div>
                    </div>
                    <div class="pe-kpi is-pronto">
                        <div class="pe-kpi-top">
                            <div class="label">Prontos</div>
                            <span class="pe-kpi-ico"><i class="fas fa-check-circle"></i></span>
                        </div>
                        <div class="value" id="countPronto"><?php echo (int)$contadores['PRONTO']; ?></div>
                        <div class="hint">Prontos para entrega</div>
                    </div>
                    <div class="pe-kpi is-atrasado">
                        <div class="pe-kpi-top">
                            <div class="label">Atrasados</div>
                            <span class="pe-kpi-ico"><i class="fas fa-bell"></i></span>
                        </div>
                        <div class="value" id="countAtrasados"><?php echo (int)$filaAtrasados; ?></div>
                        <div class="hint">Requer atenção</div>
                    </div>
                </div>

                <div class="pe-cozinha-card">
                    <div class="pe-cozinha-head">
                        <strong><i class="fas fa-fire-burner me-2"></i>Pedidos / Cozinha</strong>
                        <span>Fila e ritmo de hoje</span>
                    </div>
                    <div class="pe-cozinha-kpis">
                        <div><span>Na fila</span><strong><?php echo (int)$filaActivaPedidos; ?></strong></div>
                        <div><span>Atrasados (+<?php echo (int)$filaSlaMinutos; ?>m)</span><strong><?php echo (int)$filaAtrasados; ?></strong></div>
                        <div><span>Hora que trava</span><strong><?php echo htmlspecialchars($peHoraTrava); ?></strong></div>
                    </div>
                    <ul class="pe-cozinha-notes">
                        <?php if ($filaAtrasados > 0): ?>
                            <li class="tom-warn">
                                <strong><?php echo (int)$filaAtrasados; ?> pedido(s) acima de <?php echo (int)$filaSlaMinutos; ?> min</strong>
                                <span>A cozinha ou o salão está a atrasar<?php if ($filaEsperaMaxMin > 0): ?> · o mais antigo leva <?php echo (int)$filaEsperaMaxMin; ?> min<?php endif; ?>. Reforce o turno no pico.</span>
                            </li>
                        <?php else: ?>
                            <li class="tom-ok">
                                <strong>Fila dentro do tempo</strong>
                                <span>Nenhum pedido activo passou dos <?php echo (int)$filaSlaMinutos; ?> minutos neste momento.</span>
                            </li>
                        <?php endif; ?>
                        <?php if ($peHoraTrava !== '—' && $peHoraTravaQtd > 0): ?>
                            <li class="tom-info">
                                <strong>Hoje a cozinha aperta às <?php echo htmlspecialchars($peHoraTrava); ?></strong>
                                <span><?php echo (int)$peHoraTravaQtd; ?> pedido(s) nesse horário — prepare stock e gente antes da fila crescer.</span>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>

                <div class="pe-workspace">
                    <section class="pe-board" id="peBoard">
                        <div class="pe-board-head">
                            <h2>Pedidos em Tempo Real</h2>
                        </div>
                        <div class="pe-tabs" role="tablist">
                            <button type="button" class="pe-tab is-active" data-pe-tab="todos">Todos <span class="pe-tab-count" id="tabCountTodos"><?php echo (int)$pedidosHojeTotal; ?></span></button>
                            <button type="button" class="pe-tab" data-pe-tab="NOVO">Novos <span class="pe-tab-count" id="tabCountNovo"><?php echo (int)$contadores['NOVO']; ?></span></button>
                            <button type="button" class="pe-tab" data-pe-tab="PREPARANDO">Em preparo <span class="pe-tab-count" id="tabCountPrep"><?php echo (int)$contadores['PREPARANDO']; ?></span></button>
                            <button type="button" class="pe-tab" data-pe-tab="PRONTO">Prontos <span class="pe-tab-count" id="tabCountPronto"><?php echo (int)$contadores['PRONTO']; ?></span></button>
                            <button type="button" class="pe-tab" data-pe-tab="atrasados">Atrasados <span class="pe-tab-count" id="tabCountAtrasados"><?php echo (int)$filaAtrasados; ?></span></button>
                        </div>
                        <div class="pe-table-wrap">
                            <div id="pedidosArea"></div>
                        </div>
                        <div class="pe-board-foot">
                            <button type="button" id="btnPeVerTodos" class="pe-link-btn" onclick="peResetFiltroTodos()">Ver todos os pedidos <i class="fas fa-arrow-right"></i></button>
                        </div>
                    </section>
                    <aside class="pe-detail" id="peDetailPanel">
                        <div class="pe-detail-empty">Selecione um pedido na lista para ver o detalhe.</div>
                    </aside>
                    <template id="peResumoDia">
                        <div class="pe-resumo">
                            <div class="pe-resumo-head">
                                <strong><i class="fas fa-chart-simple me-2"></i>Resumo de hoje</strong>
                                <span>Selecione um pedido para ver o detalhe</span>
                            </div>
                            <div class="pe-resumo-grid">
                                <div><span>Faturado</span><strong><?php echo number_format($peResumo['faturado'], 2, ',', '.'); ?> MZN</strong></div>
                                <div><span>Pedidos pagos</span><strong><?php echo (int)$peResumo['pagos']; ?></strong></div>
                                <div><span>Ticket médio</span><strong><?php echo $peResumo['pagos'] > 0 ? number_format($peResumo['ticket'], 2, ',', '.') . ' MZN' : '—'; ?></strong></div>
                                <div><span>Preparo médio</span><strong><?php echo $peResumo['prep_min'] !== null ? (int)$peResumo['prep_min'] . ' min' : '—'; ?></strong></div>
                            </div>
                            <?php if ($peResumo['cancelados'] > 0): ?>
                                <div class="pe-resumo-aviso"><i class="fas fa-ban me-1"></i><?php echo (int)$peResumo['cancelados']; ?> pedido(s) cancelado(s) hoje</div>
                            <?php endif; ?>
                            <div class="pe-resumo-sub">Últimos entregues</div>
                            <?php if (!empty($peResumo['ultimos'])): ?>
                                <ul class="pe-resumo-lista">
                                    <?php foreach ($peResumo['ultimos'] as $peU): ?>
                                        <li>
                                            <span>#<?php echo htmlspecialchars((string)($peU['numero_pedido'] ?: $peU['id']), ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span><?php echo !empty($peU['quando']) ? htmlspecialchars(substr((string)$peU['quando'], 11, 5), ENT_QUOTES, 'UTF-8') : '—'; ?></span>
                                            <strong><?php echo number_format((float)$peU['total'], 2, ',', '.'); ?> MZN</strong>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <div class="pe-resumo-vazio">Ainda nenhum pedido entregue hoje.</div>
                            <?php endif; ?>
                        </div>
                    </template>
                </div>

<?php else: ?>
                <!-- ===== LAYOUT PADRÃO ===== -->
                <div class="pedidos-header">
                    <div>
                        <h4 class="mb-0"><i class="fas fa-clipboard-list text-primary me-1"></i>Pedidos <?php include __DIR__ . '/includes/plan_page_badge.php'; ?></h4>
                        <?php if (($plano_atual ?? '') === 'PROFISSIONAL'): ?>
                            <p class="text-muted mb-0" style="font-size: 13px;">Pedidos online · Cozinha → Preparo → Pronto → Entregue → Conta → Pago</p>
                        <?php else: ?>
                            <p class="text-muted mb-0" style="font-size: 13px;">Fluxo: Cozinha → Preparo → Pronto → Entregue → Conta → Pago</p>
                        <?php endif; ?>
                    </div>
                    <div class="pedidos-header-actions">
                        <span id="rtBadge" class="pedidos-live-chip">Tempo real</span>
                        <?php if ($isProfissionalPedidos): ?>
                            <button id="btnSomPedidos" type="button" class="btn btn-outline-secondary btn-sm" onclick="alternarSomPedidos()" title="Toca um aviso quando entra um pedido novo">
                                <i class="fas fa-bell-slash me-1"></i><span>Som desligado</span>
                            </button>
                        <?php endif; ?>
                        <button id="btnAtualizarPedidos" class="btn btn-warning btn-sm text-dark" onclick="forcarAtualizacaoPedidos(this)"><i class="fas fa-sync-alt me-1"></i> Atualizar</button>
                        <img src="<?php echo htmlspecialchars(pedidos_img_iniciais($userNomePedidos)); ?>" class="avatar-lg" alt="">
                    </div>
                </div>

                <div class="card mb-3 pedidos-qr-card">
                    <div class="card-body">
                        <div class="text-center" style="width: 96px; flex-shrink: 0;">
                            <canvas id="qrCodeCanvas" class="img-fluid rounded"></canvas>
                        </div>
                        <div class="flex-grow-1">
                            <h5 class="mb-1"><i class="fas fa-qrcode me-2"></i>QR Code do Cardápio</h5>
                            <p class="text-muted mb-2" style="font-size: 13px;">
                                Clientes escaneiam para fazer pedidos pelo celular.
                            </p>
                            <p class="text-muted mb-2" style="font-size: 12px;">
                                <i class="fas fa-shield-halved me-1"></i>Link protegido por token e oculto no painel.
                            </p>
                            <div class="pedidos-qr-actions">
                                <button class="btn btn-success btn-sm" onclick="baixarQRCode()"><i class="fas fa-download me-1"></i> Baixar</button>
                                <button class="btn btn-outline-secondary btn-sm" onclick="imprimirQRCodes()"><i class="fas fa-print me-1"></i> Imprimir</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pedidos-kpi-strip" aria-label="Resumo da fila de pedidos">
                    <div class="pedidos-kpi pedidos-kpi--fila">
                        <div class="pedidos-kpi-top">
                            <div>
                                <div class="pedidos-kpi-label">Fluxo do dia</div>
                                <div id="kpiFilaValue" class="pedidos-kpi-value"><?php echo (int)$pedidosDiaSemCancelados; ?> pedidos</div>
                            </div>
                            <div class="pedidos-kpi-icon"><i class="fas fa-fire"></i></div>
                        </div>
                        <p id="kpiFilaHint" class="pedidos-kpi-hint"><?php
                            echo $pedidosOntemTotal > 0
                                ? htmlspecialchars(($pedidosHojeDeltaPct >= 0 ? '+' : '') . number_format($pedidosHojeDeltaPct, 0, ',', '.') . '% vs ontem', ENT_QUOTES, 'UTF-8')
                                : 'Sem pedidos ontem para comparar';
                        ?></p>
                    </div>
                    <?php if ($isProfissionalPedidos): ?>
                    <div class="pedidos-kpi pedidos-kpi--atraso<?php echo $filaAtrasados > 0 ? ' is-alerta' : ''; ?>">
                        <div class="pedidos-kpi-label">Atrasados</div>
                        <div id="countAtrasados" class="pedidos-kpi-value"><?php echo (int)$filaAtrasados; ?></div>
                        <p class="pedidos-kpi-hint" id="hintAtrasados">
                            <?php echo $filaEsperaMaxMin > 0 ? 'mais antigo: ' . (int)$filaEsperaMaxMin . ' min' : 'acima de ' . (int)$filaSlaMinutos . ' min'; ?>
                        </p>
                    </div>
                    <?php endif; ?>
                    <div class="pedidos-kpi pedidos-kpi--novo">
                        <div class="pedidos-kpi-label">Novos</div>
                        <div id="countNovo" class="pedidos-kpi-value"><?php echo (int)$contadores['NOVO']; ?></div>
                        <p class="pedidos-kpi-hint">Aguardando</p>
                    </div>
                    <div class="pedidos-kpi pedidos-kpi--prep">
                        <div class="pedidos-kpi-label">Preparando</div>
                        <div id="countPreparando" class="pedidos-kpi-value"><?php echo (int)$contadores['PREPARANDO']; ?></div>
                        <p class="pedidos-kpi-hint">Em execução</p>
                    </div>
                    <div class="pedidos-kpi pedidos-kpi--pronto">
                        <div class="pedidos-kpi-label">Prontos</div>
                        <div id="countPronto" class="pedidos-kpi-value"><?php echo (int)$contadores['PRONTO']; ?></div>
                        <p class="pedidos-kpi-hint">Para servir</p>
                    </div>
                    <div class="pedidos-kpi pedidos-kpi--pago">
                        <div class="pedidos-kpi-label">Pagos</div>
                        <div id="countPago" class="pedidos-kpi-value"><?php echo (int)$contadores['PAGO']; ?></div>
                        <p class="pedidos-kpi-hint">Fechados</p>
                    </div>
                    <div class="pedidos-kpi pedidos-kpi--cancel">
                        <div class="pedidos-kpi-label">Cancelados</div>
                        <div id="countCancelado" class="pedidos-kpi-value"><?php echo (int)$contadores['CANCELADO']; ?></div>
                        <p class="pedidos-kpi-hint">Fora do movimento</p>
                    </div>
                    <?php if ($isProfissionalPedidos): ?>
                    <div class="pedidos-kpi pedidos-kpi--prepmed">
                        <div class="pedidos-kpi-label">Preparo médio</div>
                        <div class="pedidos-kpi-value"><?php echo $peProPrepMin !== null ? (int)$peProPrepMin . ' min' : '—'; ?></div>
                        <p class="pedidos-kpi-hint"><?php echo $peProPrepMin !== null ? 'do pedido até ficar pronto' : 'ainda sem pedidos prontos hoje'; ?></p>
                    </div>
                    <?php endif; ?>
                </div>

                <div id="pedidosArea"></div>
<?php endif; ?>

            </main>
        </div>
    </div>

    <!-- MODAL ITENS -->
    <div class="modal fade" id="modalItens" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-list me-2"></i>Itens do Pedido</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="listaItens"></div>
            </div>
        </div>
    </div>

    <!-- MODAL PAGAMENTO -->
    <div class="modal fade" id="modalPagamento" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-money-bill-wave me-2"></i>Receber Pedido</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Estado: formulário -->
                    <div id="pagFormBody">
                        <div class="mb-2 text-muted small" id="pagPedidoInfo">Pedido</div>
                        <div class="mb-3">
                            <label class="form-label">Forma de pagamento</label>
                            <select id="pagForma" class="form-select" onchange="syncPagamentoCampos()">
                                <option value="DINHEIRO">Dinheiro</option>
                                <option value="MPESA">M-Pesa</option>
                                <option value="EMOLA">e-Mola</option>
                                <option value="CARTAO">Cartão / POS</option>
                                <option value="TRANSFERENCIA">Transferência</option>
                            </select>
                        </div>
                        <div class="mb-3" id="pagRefWrap" style="display:none;">
                            <label class="form-label" id="pagRefLabel">Referência</label>
                            <input type="text" id="pagReferencia" class="form-control" maxlength="80" placeholder="Referência do pagamento">
                            <div class="form-text" id="pagRefHint">Obrigatório para M-Pesa e e-Mola.</div>
                        </div>
                        <div class="mb-3" id="pagValorWrap">
                            <label class="form-label">Valor recebido (MZN)</label>
                            <input type="number" id="pagValorRecebido" class="form-control" min="0" step="0.01" value="" placeholder="Valor recebido">
                            <div class="form-text">Opcional — calcula troco no recibo.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Desconto (%)</label>
                            <input type="number" id="pagDesconto" class="form-control" min="0" max="100" step="0.01" value="0" placeholder="Desconto (%)">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Observação</label>
                            <textarea id="pagObs" class="form-control" rows="2" maxlength="300" placeholder="Notas do pagamento"></textarea>
                        </div>
                        <div class="alert alert-light border mb-0">
                            Total do pedido: <strong id="pagTotalLabel">0,00 MZN</strong>
                        </div>
                    </div>
                    <!-- Estado: sucesso -->
                    <div id="pagSuccessBody" style="display:none;">
                        <div class="text-center py-3">
                            <i class="fas fa-check-circle text-success" style="font-size:56px;"></i>
                            <h5 class="mt-3 mb-1 text-success">Pagamento Registrado!</h5>
                            <p class="text-muted small" id="pagSuccessInfo"></p>
                            <div class="d-flex flex-column gap-2 align-items-center mt-3">
                                <a id="btnComprovante" href="#" target="_blank" class="btn btn-primary">
                                    <i class="fas fa-print me-1"></i> Imprimir recibo
                                </a>
                                <a id="btnComprovanteVer" href="#" target="_blank" class="btn btn-outline-secondary btn-sm">
                                    <i class="fas fa-receipt me-1"></i> Abrir comprovante
                                </a>
                                <button type="button" class="btn btn-link btn-sm" data-bs-dismiss="modal">Fechar</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" id="pagFooter">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success" id="btnConfirmarPagamento" onclick="confirmarPagamentoPedido()">
                        <i class="fas fa-check me-1"></i>Confirmar Pagamento
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="lib/bootstrap-5.3.2/js/bootstrap.bundle.min.js"></script>
    <!-- QR Code -->
    <script src="lib/qrcode-generator-1.4.4/qrcode.js"></script>
    <script src="js/receipt_print.js?v=20260821a"></script>
    <script src="js/offline_sync.js?v=20261101b"></script>
    <script src="js/pedido_tempo.js?v=20260814a"></script>

    <script type="application/json" id="cfg-pedidos">{"v0": <?php echo json_encode($cardapio_url, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); ?>, "v1": <?php echo json_encode($mesasQrImpressao, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); ?>, "v2": <?php echo json_encode($mesaQrUrls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); ?>, "v3": <?php echo json_encode($pedidos, JSON_UNESCAPED_UNICODE); ?>, "v4": <?php echo json_encode($contadores, JSON_UNESCAPED_UNICODE); ?>, "v5": <?php echo json_encode($filtro, JSON_UNESCAPED_UNICODE); ?>, "v6": <?php echo $pode_receber_pagamento ? 'true' : 'false'; ?>, "v7": <?php echo $pode_gerir_fluxo_pedido ? 'true' : 'false'; ?>, "v8": <?php echo $pode_pedir_conta ? 'true' : 'false'; ?>, "v9": <?php echo $pode_cancelar_pedido ? 'true' : 'false'; ?>, "v10": <?php echo json_encode(strtoupper((string)$userPerfilPedidos), JSON_UNESCAPED_UNICODE); ?>, "v11": <?php echo json_encode($csrfToken, JSON_UNESCAPED_UNICODE); ?>, "v12": <?php echo !empty($isEmpresarialUi) ? 'true' : 'false'; ?>, "v13": <?php echo (int)$filaSlaMinutos; ?>, "v14": <?php echo (int)$pedidosOntemTotal; ?>, "v15": <?php echo $isProfissionalPedidos ? 'true' : 'false'; ?>}</script>
    <!-- Pedidos: um ficheiro por area, carregados por esta ordem (partilham variaveis globais). -->
    <script src="js/nv_operacao.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_operacao.js'); ?>"></script>
    <script src="js/pedidos/comum.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/pedidos/comum.js'); ?>"></script>
    <script src="js/pedidos/lista.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/pedidos/lista.js'); ?>"></script>
    <script src="js/pedidos/tempo_real.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/pedidos/tempo_real.js'); ?>"></script>
    <script src="js/pedidos/acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/pedidos/acoes.js'); ?>"></script>
</body>

</html>
