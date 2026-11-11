<?php
require_once __DIR__ . '/app_base.php';

/*
   Caixa – Vista de Mesas com Contas Abertas
   Interface simplificada para fechar contas rapidamente.
   Garante uma ação por mesa (máximo 2 cliques para pagar).
 */
include_once APP_BASE . '/src/config/auth_check.php';
include_once APP_BASE . '/src/config/csrf.php';
include_once APP_BASE . '/src/config/plano_check.php';
include_once APP_BASE . '/src/config/restaurante_context.php';
include_once APP_BASE . '/src/config/database.php';

$perfil = strtoupper(trim($_SESSION['perfil'] ?? ''));
if (!in_array($perfil, ['CAIXA', 'ADMIN', 'GERENTE'])) {
    header('Location: dashboard.php');
    exit;
}

// Verificar se plano permite funcionalidade CAIXA (plano da matriz)
$restaurante_id = (int)($_SESSION['restaurante_id'] ?? 0);
$planRidCaixaMesas = session_restaurante_capability_id();
if ($planRidCaixaMesas <= 0) {
    $planRidCaixaMesas = $restaurante_id;
}
plano_verificar_funcionalidade($planRidCaixaMesas, 'contas_abertas');
$csrfToken = csrf_get_token();
$dadosPlanoCaixaMesas = plano_get_dados($planRidCaixaMesas);
$plano_atual = plano_normalizar_nome($dadosPlanoCaixaMesas['plano_nome'] ?? 'BASICO');
$temMultiFilialCaixaMesas = plano_tem_funcionalidade_db($planRidCaixaMesas, 'multi_filial');
require_once __DIR__ . '/includes/empresarial_ui.php';

// Snapshot server-side para funcionar mesmo quando AJAX estiver bloqueado no navegador.
$caixaMesasSnapshot = [
    'success' => true,
    'mesas' => [],
    'qtd_abertas' => 0,
    'total_aberto' => 0.0,
    'updated_at' => date('c'),
];
try {
    $db = (new Database())->getConnection();
    $rid = (int)$restaurante_id;
    $sql = "
        SELECT
            m.id            AS mesa_id,
            m.numero        AS mesa_numero,
            m.capacidade,
            p.id            AS pedido_id,
            p.numero_pedido,
            p.status        AS pedido_status,
            p.total         AS pedido_total,
            0               AS desconto,
            p.total         AS total_bruto,
            p.criado_em     AS pedido_criado_em,
            TIMESTAMPDIFF(MINUTE, p.criado_em, NOW()) AS minutos_aberto,
            (SELECT COUNT(*) FROM itens_pedido ip WHERE ip.pedido_id = p.id) AS qtd_itens,
            u.nome          AS garcom_nome
        FROM mesas m
        INNER JOIN pedidos p
            ON p.mesa_id = m.id
            AND p.restaurante_id = :rid
            AND p.status IN ('NOVO', 'PENDENTE', 'CONFIRMADO', 'PREPARANDO', 'ABERTO', 'EM_ANDAMENTO', 'PRONTO', 'ENTREGUE', 'CONTA', 'CONTA_SOLICITADA')
            AND p.id = (
                SELECT id FROM pedidos
                WHERE mesa_id = m.id
                  AND restaurante_id = :rid2
                  AND status IN ('NOVO', 'PENDENTE', 'CONFIRMADO', 'PREPARANDO', 'ABERTO', 'EM_ANDAMENTO', 'PRONTO', 'ENTREGUE', 'CONTA', 'CONTA_SOLICITADA')
                ORDER BY criado_em DESC
                LIMIT 1
            )
        LEFT JOIN usuarios u ON u.id = p.garcom_id
        WHERE m.restaurante_id = :rid3
        ORDER BY p.criado_em ASC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([':rid' => $rid, ':rid2' => $rid, ':rid3' => $rid]);
    $mesas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmtItens = $db->prepare(
        "SELECT ip.pedido_id, COALESCE(p.nome, CONCAT('Produto #', ip.produto_id)) AS produto, ip.quantidade, ip.preco_unitario
         FROM itens_pedido ip
         LEFT JOIN produtos p ON p.id = ip.produto_id
         WHERE ip.pedido_id = :pid
         ORDER BY COALESCE(p.nome, CONCAT('Produto #', ip.produto_id))"
    );
    foreach ($mesas as &$m) {
        $m['mesa_id'] = (int)$m['mesa_id'];
        $m['pedido_id'] = (int)$m['pedido_id'];
        $m['pedido_total'] = (float)$m['pedido_total'];
        $m['desconto'] = (float)$m['desconto'];
        $m['qtd_itens'] = (int)$m['qtd_itens'];
        $m['minutos_aberto'] = (int)$m['minutos_aberto'];
        $stmtItens->execute([':pid' => $m['pedido_id']]);
        $m['itens'] = $stmtItens->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($m);

    $caixaMesasSnapshot['mesas'] = $mesas;
    $caixaMesasSnapshot['qtd_abertas'] = count($mesas);
    $caixaMesasSnapshot['total_aberto'] = round((float)array_sum(array_column($mesas, 'pedido_total')), 2);
    $caixaMesasSnapshot['updated_at'] = date('c');
} catch (Throwable $e) {
    $caixaMesasSnapshot['success'] = false;
}

// Empresarial: recebido hoje por forma de pagamento
$cxRecebidoHoje = ['total' => 0.0, 'qtd' => 0, 'formas' => []];
if (!empty($isEmpresarialUi) && isset($db) && $db instanceof PDO) {
    try {
        $stF = $db->prepare("
            SELECT UPPER(COALESCE(NULLIF(TRIM(forma_pagamento), ''), 'OUTRO')) AS forma,
                   COALESCE(SUM(total_final), 0) AS total, COUNT(*) AS qtd
            FROM vendas
            WHERE restaurante_id = :rid
              AND criado_em >= CURDATE() AND criado_em < CURDATE() + INTERVAL 1 DAY
              AND UPPER(TRIM(COALESCE(status, ''))) = 'PAGO'
            GROUP BY forma
            ORDER BY total DESC
        ");
        $stF->execute([':rid' => (int)$restaurante_id]);
        foreach ($stF->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rF) {
            $cxRecebidoHoje['formas'][] = $rF;
            $cxRecebidoHoje['total'] += (float)$rF['total'];
            $cxRecebidoHoje['qtd'] += (int)$rF['qtd'];
        }
    } catch (Throwable $eF) {
        // ignore
    }
}
$isProfissionalCx = (($plano_atual ?? '') === 'PROFISSIONAL');

$cxFormaLabel = static function (string $f): string {
    $map = ['DINHEIRO' => 'Dinheiro', 'MPESA' => 'M-Pesa', 'EMOLA' => 'e-Mola', 'CARTAO' => 'Cartão', 'TRANSFERENCIA' => 'Transferência', 'OUTRO' => 'Outro'];
    return $map[$f] ?? ucfirst(strtolower($f));
};
?>
<!DOCTYPE html>
<html lang="pt">

<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>Caixa – Contas Abertas</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">

    <link href="lib/bootstrap-5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="lib/fontawesome-6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/feedback.css?v=<?php echo (int)filemtime(__DIR__ . '/css/feedback.css'); ?>">
    <script src="js/feedback.js?v=<?php echo (int)filemtime(__DIR__ . '/js/feedback.js'); ?>"></script>

    <style>
        :root {
            --bg: #f8fafc;
            --bg-soft: linear-gradient(135deg, #fff7f1 0%, #f8fafc 100%);
            --card: #fff;
            --border: #e2e8f0;
            --text: #1e293b;
            --muted: #64748b;
            --primary: #FF6B35;
            --primary-soft: rgba(255,107,53,0.10);
            --success: #10b981;
            --warn: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background: var(--bg-soft);
            color: var(--text);
            font-family: 'Outfit', 'Segoe UI', 'Noto Sans', system-ui, -apple-system, 'Helvetica Neue', Arial, sans-serif;
        }

        /* TOPBAR */
        .topbar {
            background: rgba(255,255,255,0.96);
            border-bottom: 1px solid rgba(226,232,240,0.9);
            padding: 16px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 200;
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
        }

        .topbar h1 {
            font-family: 'Space Grotesk', 'Segoe UI Semibold', 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
            font-size: 22px;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .topbar h1 i {
            color: var(--primary);
        }

        /* SUMMARY BAR */
        .summary-bar {
            background: rgba(255,255,255,0.88);
            border-bottom: 1px solid rgba(226,232,240,0.9);
            padding: 14px 24px;
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }

        .sum-item {
            display: flex;
            flex-direction: column;
            min-width: 140px;
            padding: 10px 12px;
            border-radius: 14px;
            background: rgba(255,255,255,0.9);
            border: 1px solid rgba(226,232,240,0.95);
        }

        .sum-item.is-alerta { background: #fef2f2; border-color: #fecaca; }

        .sum-item .label {
            font-size: 12px;
            color: var(--muted);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .sum-item .value {
            font-family: 'Space Grotesk', 'Segoe UI Semibold', 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
            font-size: 22px;
            font-weight: 700;
        }

        .sum-item .value.green {
            color: var(--success);
        }

        /* GRID DE MESAS OCUPADAS */
        .mesas-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 18px;
            padding: 24px;
        }

        .mesa-card {
            background: linear-gradient(180deg, #ffffff 0%, #fffaf6 100%);
            border-radius: 20px;
            border: 2px solid var(--border);
            overflow: hidden;
            transition: box-shadow 0.2s, transform 0.2s;
        }

        .mesa-card:hover {
            transform: translateY(-2px);
        }

        .mesa-card:hover {
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .mesa-card.urgente {
            border-color: var(--danger);
        }

        .mesa-card.warn {
            border-color: var(--warn);
        }

        .card-header-mesa {
            padding: 16px 20px 12px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 1px solid var(--border);
            gap: 10px;
        }

        .mesa-num {
            font-family: 'Space Grotesk', 'Segoe UI Semibold', 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
            font-size: 26px;
            font-weight: 700;
        }

        .mesa-num span {
            font-size: 13px;
            font-weight: 500;
            color: var(--muted);
        }

        .time-badge {
            font-size: 12px;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 20px;
        }

        .time-badge.normal {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .time-badge.warn {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warn);
        }

        .time-badge.urgente {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .card-body-mesa {
            padding: 16px 20px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .mesa-priority {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            padding: 5px 8px;
            border-radius: 999px;
            background: var(--primary-soft);
            color: var(--primary);
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-top: 6px;
        }

        .item-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            font-size: 14px;
            border-bottom: 1px solid var(--border);
        }

        .item-row:last-child {
            border-bottom: none;
        }

        .item-row .nome {
            flex: 1;
            color: var(--text);
        }

        .item-row .qtd {
            color: var(--muted);
            margin: 0 12px;
        }

        .item-row .preco {
            font-weight: 600;
        }

        .card-total {
            padding: 14px 20px;
            border-top: 2px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 700;
            font-size: 18px;
        }

        .card-total .garcom {
            font-size: 12px;
            color: var(--muted);
            font-weight: 400;
        }

        .card-actions {
            padding: 0 20px 18px;
            display: flex;
            gap: 10px;
        }

        .btn-pagar {
            flex: 1;
            padding: 12px 0;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--success), #059669);
            color: white;
            border: none;
            font-weight: 700;
            font-size: 15px;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            box-shadow: 0 8px 18px rgba(16, 185, 129, 0.18);
        }

        .btn-pagar:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 22px rgba(16, 185, 129, 0.24);
        }

        .btn-pagar:hover {
            opacity: 0.9;
        }

        .btn-detalhe {
            padding: 12px 16px;
            border-radius: 12px;
            background: var(--bg);
            color: var(--text);
            border: 1px solid var(--border);
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
        }

        /* EMPTY STATE */
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: var(--muted);
            grid-column: 1 / -1;
            background: rgba(255,255,255,0.82);
            border: 1px dashed rgba(226,232,240,0.95);
            border-radius: 24px;
            box-shadow: 0 10px 28px rgba(15,23,42,0.04);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            display: block;
            color: #cbd5e1;
        }

        /* MODAL PAGAMENTO */
        .modal-content {
            border-radius: 20px;
            border: none;
            box-shadow: 0 24px 70px rgba(15, 23, 42, 0.2);
        }

        .modal-header-pay {
            background: linear-gradient(135deg, var(--success), #059669);
            color: white;
            border-radius: 20px 20px 0 0;
            padding: 22px 26px;
        }

        .pay-option-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin: 16px 0;
        }

        .pay-option {
            padding: 14px;
            border: 2px solid var(--border);
            border-radius: 12px;
            text-align: center;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.18s;
            background: #fff;
        }

        .pay-option:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .pay-option.selected {
            border-color: var(--success);
            background: rgba(16, 185, 129, 0.08);
            color: var(--success);
        }

        .pay-option i {
            display: block;
            font-size: 22px;
            margin-bottom: 6px;
        }

        /* STATUS FOOTER */
        #footer-status {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            border-top: 1px solid var(--border);
            padding: 8px 20px;
            font-size: 12px;
            color: var(--muted);
            display: flex;
            justify-content: space-between;
            z-index: 100;
        }

        .loading-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.3);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }

        .loading-overlay.show {
            display: flex;
        }

        @media (max-width: 600px) {
            .mesas-grid {
                grid-template-columns: 1fr;
                padding: 16px;
            }
        }

        @media (min-width: 1200px) {
            .topbar {
                padding: 12px 18px;
            }

            .summary-bar {
                padding: 12px 18px;
                gap: 18px;
            }

            .mesas-grid {
                gap: 14px;
                padding: 18px;
            }

            .mesa-card {
                border-radius: 14px;
                border-width: 1px;
                box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06);
            }
        }

        /* ── Empresarial only ── */
        .emp-layout {
            display: grid;
            grid-template-columns: 1fr 280px;
            gap: 18px;
            padding: 20px 24px 28px;
            align-items: start;
        }
        .emp-main {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
        }
        .emp-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: space-between;
            align-items: center;
            padding: 16px 18px;
            border-bottom: 1px solid var(--border);
            background: #fff;
        }
        .emp-toolbar-title {
            font-family: 'Space Grotesk', 'Segoe UI Semibold', 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
            font-weight: 700;
            font-size: 17px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .emp-badge {
            font-size: 12px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 999px;
            background: #fff7ed;
            color: #c2410c;
            border: 1px solid #fed7aa;
        }
        .emp-toolbar-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .emp-input, .emp-select {
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 8px 12px;
            font-size: 13px;
            background: #fff;
            color: var(--text);
            min-width: 140px;
        }
        .emp-input { min-width: 200px; }
        .emp-table-wrap { overflow-x: auto; }
        .emp-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        .emp-table th {
            text-align: left;
            padding: 12px 14px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: var(--muted);
            background: #f8fafc;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        .emp-table td {
            padding: 14px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        .emp-table tr:hover td { background: #fff7ed; }
        .emp-table tr.atrasada td { background: rgba(239, 68, 68, 0.04); }
        .emp-mesa {
            font-family: 'Space Grotesk', 'Segoe UI Semibold', 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
            font-weight: 700;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            height: 36px;
            padding: 0 10px;
            border-radius: 999px;
            background: #fff7ed;
            color: #c2410c;
            border: 1px solid #fed7aa;
        }
        .emp-itens-pill {
            display: inline-block;
            font-size: 12px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 999px;
            background: #fff7ed;
            color: #9a3412;
            border: 1px solid #ffedd5;
        }
        .emp-sub { font-size: 12px; color: var(--muted); margin-top: 2px; }
        .emp-tempo { font-weight: 700; }
        .emp-tempo.warn { color: var(--warn); }
        .emp-tempo.urgente { color: var(--danger); }
        .emp-tempo.normal { color: var(--success); }
        .emp-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            font-weight: 700;
            padding: 4px 8px;
            border-radius: 999px;
            background: #f1f5f9;
            color: #475569;
        }
        .emp-actions { display: flex; gap: 6px; align-items: center; }
        .emp-btn-pay {
            border: none;
            background: var(--success);
            color: #fff;
            font-weight: 700;
            font-size: 12px;
            border-radius: 10px;
            padding: 8px 12px;
            cursor: pointer;
        }
        .emp-btn-pay:hover { filter: brightness(0.95); }
        .emp-menu { position: relative; }
        .emp-menu-btn {
            border: 1px solid var(--border);
            background: #fff;
            border-radius: 10px;
            width: 34px;
            height: 34px;
            cursor: pointer;
            color: var(--muted);
        }
        .emp-menu-panel {
            display: none;
            position: absolute;
            right: 0;
            top: 38px;
            min-width: 180px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.12);
            z-index: 20;
            overflow: hidden;
        }
        .emp-menu.open .emp-menu-panel { display: block; }
        .emp-menu-panel button {
            display: block;
            width: 100%;
            text-align: left;
            border: none;
            background: transparent;
            padding: 10px 14px;
            font-size: 13px;
            cursor: pointer;
            color: var(--text);
        }
        .emp-menu-panel button:hover { background: #f8fafc; }
        .emp-side-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 18px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
            position: sticky;
            top: 88px;
        }
        .emp-side-card h6 {
            font-family: 'Space Grotesk', 'Segoe UI Semibold', 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
            font-weight: 700;
            margin-bottom: 14px;
        }
        .emp-side-row {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 0;
            border-bottom: 1px solid #f1f5f9;
            font-size: 13px;
        }
        .emp-side-row span { color: var(--muted); }
        .emp-side-note {
            margin-top: 14px;
            padding: 12px;
            border-radius: 12px;
            background: #fff7ed;
            color: #9a3412;
            border: 1px solid #ffedd5;
            font-size: 12px;
            line-height: 1.45;
        }
        .emp-side-row strong.emp-val-blue { color: #1d4ed8; }
        .emp-side-row strong.emp-val-green { color: #047857; }
        @media (max-width: 1100px) {
            .emp-layout { grid-template-columns: 1fr; }
            .emp-side { order: -1; }
            .emp-side-card { position: static; }
        }

        /* ---- Empresarial: menu lateral, cartões e painel ---- */
        body.cx-emp-shell .cx-main { padding: 0 !important; min-height: 100vh; }
        body.cx-emp-shell .summary-bar { display: grid !important; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); }
        body.cx-emp-shell .summary-bar .sum-item { min-width: 0; width: auto; }
        body.cx-emp-shell #footer-status { left: auto; gap: 18px; }
        body.cx-emp-shell .emp-table td:nth-child(1) > *:first-child,
        body.cx-emp-shell .emp-table td:nth-child(5),
        body.cx-emp-shell .emp-table td:nth-child(5) *,
        body.cx-emp-shell .emp-table td:nth-child(6),
        body.cx-emp-shell .emp-table td:nth-child(6) * { white-space: nowrap; }
        body.cx-emp-shell .emp-table th, body.cx-emp-shell .emp-table td { padding-left: 9px; padding-right: 9px; }
        body.cx-emp-shell .emp-layout { grid-template-columns: minmax(0, 1fr) 260px; gap: 16px; padding: 18px 20px 28px; }
        @media (min-width: 992px) {
            body.cx-emp-shell .cx-main { margin-left: 280px !important; width: calc(100% - 280px) !important; max-width: none !important; flex: none !important; }
        }
        body.cx-emp-shell .cx-recebido strong { display: block; font-size: 22px; color: #047857; margin-top: 2px; }
        body.cx-emp-shell .cx-recebido span { display: block; font-size: 12px; color: var(--muted); margin-bottom: 12px; }
        body.cx-emp-shell .cx-forma { padding: 7px 0; }
        body.cx-emp-shell .cx-forma-top { display: flex; justify-content: space-between; gap: 8px; font-size: 13px; }
        body.cx-emp-shell .cx-forma-top span { color: #475569; }
        body.cx-emp-shell .cx-barra { height: 6px; background: #f1f5f9; border-radius: 99px; margin-top: 5px; overflow: hidden; }
        body.cx-emp-shell .cx-barra i { display: block; height: 100%; background: #10b981; border-radius: 99px; }
        body.cx-emp-shell .cx-side-vazio { font-size: 13px; color: #94a3b8; padding: 4px 0; }
        body.cx-emp-shell .cx-antiga { display: grid; grid-template-columns: 1fr auto auto; gap: 10px; padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #475569; }
        body.cx-emp-shell .cx-antiga:last-child { border-bottom: 0; }
        body.cx-emp-shell .cx-antiga strong { color: #0f172a; }
        body.cx-emp-shell .cx-antiga.is-late span:nth-child(2) { color: #b91c1c; font-weight: 700; }
        body.cx-emp-shell .cx-vazio { display: flex; align-items: center; gap: 14px; padding: 16px 18px; margin: 6px 0; border: 1px dashed #e2e8f0; border-radius: 12px; background: #f8fafc; text-align: left; }
        body.cx-emp-shell .cx-vazio > i { font-size: 1.4rem; color: #94a3b8; }
        body.cx-emp-shell .cx-vazio > div { flex: 1; min-width: 0; }
        body.cx-emp-shell .cx-vazio strong { display: block; color: #334155; font-size: 14px; }
        body.cx-emp-shell .cx-vazio span { display: block; color: #94a3b8; font-size: 12.5px; margin-top: 2px; }
        body.cx-emp-shell .cx-vazio-btn { background: #16a34a; color: #fff; text-decoration: none; padding: 9px 14px; border-radius: 10px; font-weight: 700; font-size: 13px; white-space: nowrap; }
        body.cx-emp-shell .cx-vazio-btn:hover { background: #15803d; color: #fff; }
    </style>
    <?php empresarial_head_assets(); ?>
</head>


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

<body class="<?php echo htmlspecialchars(empresarial_body_class(!empty($isEmpresarialUi) ? 'cx-emp-shell' : '')); ?>">
<?php if (!empty($isEmpresarialUi)): ?>
    <div class="container-fluid">
        <div class="row">
            <?php include_once __DIR__ . '/includes/sidebar.php'; ?>
            <main class="main-content col-md-9 ms-sm-auto col-lg-10 cx-main">
<?php endif; ?>
    <!-- TOPBAR -->
    <div class="topbar">
        <h1><i class="fas fa-cash-register"></i> Contas Abertas</h1>
        <div style="display:flex;gap:10px;align-items:center">
            <span id="clock" style="color:var(--muted);font-size:14px"></span>
            <a href="caixa.php" class="btn btn-sm" style="background:var(--bg);border:1px solid var(--border);border-radius:10px;color:var(--text)">
                <i class="fas fa-money-bill-wave me-1"></i>Caixa
            </a>
            <?php if (empty($isEmpresarialUi)): ?>
            <a href="dashboard.php" class="btn btn-sm" style="background:var(--primary);color:white;border:none;border-radius:10px">
                <i class="fas fa-arrow-left me-1"></i>Voltar
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- SUMMARY BAR -->
    <div class="summary-bar">
        <div class="sum-item<?php echo !empty($isEmpresarialUi) ? ' kpi-orange' : ''; ?>">
            <span class="label">Mesas Abertas</span>
            <span class="value" id="s-abertas">–</span>
        </div>
        <div class="sum-item<?php echo !empty($isEmpresarialUi) ? ' kpi-blue' : ''; ?>">
            <span class="label">Total em Aberto</span>
            <span class="value green" id="s-total">–</span>
        </div>
        <?php if (!empty($isEmpresarialUi)): ?>
        <div class="sum-item kpi-green">
            <span class="label">Ticket Médio</span>
            <span class="value" id="s-ticket">–</span>
        </div>
        <div class="sum-item kpi-amber">
            <span class="label">Tempo Médio</span>
            <span class="value" id="s-tempo-medio">–</span>
        </div>
        <div class="sum-item kpi-slate">
            <span class="label">&gt; 30 min</span>
            <span class="value" id="s-atrasadas" style="color:#b91c1c">–</span>
        </div>
        <?php endif; ?>
        <?php if ($isProfissionalCx): ?>
        <div class="sum-item" id="cx-paradas-card">
            <span class="label">Paradas &gt; 30 min</span>
            <span class="value" id="s-paradas">–</span>
        </div>
        <?php endif; ?>
        <div class="sum-item<?php echo !empty($isEmpresarialUi) ? ' kpi-slate' : ''; ?>">
            <span class="label">Atualizado</span>
            <span class="value" style="font-size:16px;margin-top:4px" id="s-hora">–</span>
        </div>
    </div>

    <?php if (!empty($isEmpresarialUi)): ?>
    <!-- EMPRESARIAL: tabela + filtros + resumo -->
    <div class="emp-layout" id="emp-layout">
        <div class="emp-main">
            <div class="emp-toolbar">
                <div class="emp-toolbar-title">
                    Contas Abertas
                    <span class="emp-badge" id="emp-badge-count">0 contas</span>
                </div>
                <div class="emp-toolbar-actions">
                    <input type="search" id="emp-busca" class="emp-input" placeholder="Pesquisar mesa ou garçom…">
                    <select id="emp-filtro-tempo" class="emp-select" title="Filtrar por tempo">
                        <option value="todos">Todos os tempos</option>
                        <option value="15">≥ 15 min</option>
                        <option value="30">≥ 30 min</option>
                    </select>
                    <select id="emp-filtro-status" class="emp-select" title="Filtrar por status">
                        <option value="todos">Todos os status</option>
                        <option value="PRONTO">Pronto</option>
                        <option value="ENTREGUE">Entregue</option>
                        <option value="CONTA">Conta</option>
                        <option value="PREPARANDO">Preparando</option>
                    </select>
                </div>
            </div>
            <div class="emp-table-wrap">
                <table class="emp-table" id="emp-table">
                    <thead>
                        <tr>
                            <th>Mesa</th>
                            <th>Cliente / Responsável</th>
                            <th>Aberta em</th>
                            <th>Total (MZN)</th>
                            <th>Itens</th>
                            <th>Tempo</th>
                            <th>Status</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody id="emp-tbody">
                        <tr><td colspan="8" class="text-center text-muted py-4">Carregando contas…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <aside class="emp-side">
            <div class="emp-side-card">
                <h6>Recebido hoje</h6>
                <div class="cx-recebido">
                    <strong>MZN <?php echo number_format($cxRecebidoHoje['total'], 2, ',', '.'); ?></strong>
                    <span><?php echo (int)$cxRecebidoHoje['qtd']; ?> pagamento(s)</span>
                </div>
                <?php if (!empty($cxRecebidoHoje['formas'])): ?>
                    <?php foreach ($cxRecebidoHoje['formas'] as $cxF):
                        $cxPct = $cxRecebidoHoje['total'] > 0 ? round((float)$cxF['total'] / $cxRecebidoHoje['total'] * 100) : 0; ?>
                        <div class="cx-forma">
                            <div class="cx-forma-top"><span><?php echo htmlspecialchars($cxFormaLabel((string)$cxF['forma']), ENT_QUOTES, 'UTF-8'); ?></span><strong>MZN <?php echo number_format((float)$cxF['total'], 2, ',', '.'); ?></strong></div>
                            <div class="cx-barra"><i style="width:<?php echo (int)$cxPct; ?>%"></i></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="cx-side-vazio">Ainda sem pagamentos hoje.</div>
                <?php endif; ?>
            </div>
            <div class="emp-side-card mt-3">
                <h6>Contas mais antigas</h6>
                <div id="emp-side-antigas"><div class="cx-side-vazio">Nenhuma conta aberta.</div></div>
            </div>
        </aside>
    </div>
    <?php else: ?>
    <!-- PROFESSIONAL: grid de cards -->
    <div class="mesas-grid" id="mesas-grid">
        <div style="grid-column:1/-1;text-align:center;padding:60px 0;color:var(--muted)">
            <i class="fas fa-spinner fa-spin fa-2x"></i>
            <p class="mt-3">Carregando contas…</p>
        </div>
    </div>
    <?php endif; ?>

    <!-- FOOTER -->
    <div id="footer-status">
        <span id="footer-msg">–</span>
        <span><i class="fas fa-sync-alt me-1" id="refresh-spin" style="display:none"></i>Atualiza a cada 10s</span>
    </div>
<?php if (!empty($isEmpresarialUi)): ?>
            </main>
        </div>
    </div>
<?php endif; ?>

    <!-- LOADING OVERLAY -->
    <div class="loading-overlay" id="loading-overlay">
        <div style="background:white;border-radius:16px;padding:32px 40px;text-align:center">
            <i class="fas fa-spinner fa-spin fa-3x" style="color:var(--success)"></i>
            <p class="mt-3" style="font-weight:600">Processando pagamento…</p>
        </div>
    </div>

    <!-- MODAL PAGAMENTO -->
    <div class="modal fade" id="modalPagamento" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-header-pay">
                    <h5 style="margin:0;font-weight:700"><i class="fas fa-check-circle me-2"></i>Fechar Conta</h5>
                    <p style="margin:6px 0 0;opacity:0.85;font-size:14px" id="pay-sub">Mesa – · Total: –</p>
                </div>
                <div class="modal-body" style="padding:22px">
                    <p style="font-weight:600;margin-bottom:12px">Forma de Pagamento</p>
                    <div class="pay-option-grid" id="pay-options">
                        <div class="pay-option" data-pagamento="DINHEIRO" onclick="selectPay(this)">
                            <i class="fas fa-money-bill-wave"></i>Dinheiro
                        </div>
                        <div class="pay-option" data-pagamento="MPESA" onclick="selectPay(this)">
                            <i class="fas fa-mobile-alt"></i>M-Pesa
                        </div>
                        <div class="pay-option" data-pagamento="EMOLA" onclick="selectPay(this)">
                            <i class="fas fa-mobile-alt"></i>e-Mola<br><small>879778409</small>
                        </div>
                        <div class="pay-option" data-pagamento="TRANSFERENCIA" onclick="selectPay(this)">
                            <i class="fas fa-exchange-alt"></i>Transferência
                        </div>
                    </div>
                    <div style="margin-top:12px">
                        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">Desconto (%)</label>
                        <input type="number" id="pay-desconto" min="0" max="100" step="0.01" value="0" placeholder="Desconto (%)"
                            style="width:100%;padding:10px 14px;border-radius:10px;border:2px solid var(--border);font-size:14px"
                            oninput="calcularTroco()">
                    </div>
                    <!-- CAMPO VALOR RECEBIDO (DINHEIRO) -->
                    <div id="pay-valor-recebido-container" style="margin-top:12px;display:none">
                        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">Valor Recebido (MZN)</label>
                        <input type="number" id="pay-valor-recebido" min="0" step="0.01" placeholder="Valor recebido"
                            style="width:100%;padding:10px 14px;border-radius:10px;border:2px solid var(--border);font-size:14px" 
                            oninput="calcularTroco()">
                    </div>
                    <!-- REFERÊNCIA AUTOMÁTICA (M-Pesa / e-Mola / Transferência) -->
                    <div id="pay-referencia-container" style="margin-top:12px;display:none">
                        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px" id="pay-referencia-label">Referência automática</label>
                        <input type="text" id="pay-referencia" maxlength="80" readonly
                            style="width:100%;padding:10px 14px;border-radius:10px;border:2px solid var(--border);font-size:14px;background:#f8fafc;color:#334155"
                            autocomplete="off">
                        <div style="font-size:12px;color:var(--text-muted);margin-top:6px" id="pay-referencia-hint">Gerada automaticamente pelo sistema.</div>
                    </div>
                    <!-- EXIBIÇÃO TROCO -->
                    <div id="pay-troco-container" style="margin-top:12px;display:none;padding:12px;background:rgba(16,185,129,0.1);border-radius:10px;border:2px solid var(--success)">
                        <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px">Troco</div>
                        <div style="font-size:20px;font-weight:700;color:var(--success)" id="pay-troco-valor">MZN 0,00</div>
                    </div>
                </div>
                <div class="modal-footer" style="gap:10px;padding:16px 22px">
                    <button class="btn" style="border:1px solid var(--border);border-radius:10px;flex:1"
                        data-bs-dismiss="modal">Cancelar</button>
                    <button class="btn" id="btn-confirmar-pag"
                        onclick="confirmarPagamento()"
                        style="background:var(--success);color:white;border:none;border-radius:10px;flex:2;font-weight:700">
                        <i class="fas fa-check me-1"></i>Confirmar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="lib/bootstrap-5.3.2/js/bootstrap.bundle.min.js"></script>
    <script src="js/nv_operacao.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_operacao.js'); ?>"></script>
    <script src="js/receipt_print.js?v=20260821a"></script>
    <script src="js/offline_sync.js?v=20261101b"></script>
    <script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
        const CAIXA_MESAS_INICIAL = <?php echo json_encode($caixaMesasSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const IS_EMPRESARIAL = <?php echo !empty($isEmpresarialUi) ? 'true' : 'false'; ?>;
        const IS_PROFISSIONAL = <?php echo $isProfissionalCx ? 'true' : 'false'; ?>;
        let falhasRedeCaixaMesas = 0;
        let refreshCaixaHandle = null;
        OfflineSync.init({
            csrfToken: <?php echo json_encode($csrfToken, JSON_UNESCAPED_UNICODE); ?>,
            syncIntervalMs: 10000
        });
        // ─── Utilitários ──────────────────────────────────────────────────────────────
        function esc(s) {
            return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        function fmt(v) {
            return parseFloat(v || 0).toLocaleString('pt-MZ', {
                minimumFractionDigits: 2
            });
        }

        function timerClass(mins) {
            return mins >= 30 ? 'urgente' : mins >= 15 ? 'warn' : 'normal';
        }

        function timerText(mins) {
            mins = Math.max(0, Number(mins || 0));
            if (mins < 1) return 'Agora';
            if (mins < 60) return mins + ' min';
            const days = Math.floor(mins / 1440);
            const hours = Math.floor((mins % 1440) / 60);
            const rest = mins % 60;
            if (days > 0) return days + 'd ' + hours + 'h';
            return hours + 'h' + (rest > 0 ? ' ' + rest + 'min' : '');
        }

        async function lerJsonSeguro(resp) {
            const raw = await resp.text();
            try {
                return JSON.parse(raw);
            } catch (e) {
                const preview = raw.slice(0, 160).replace(/\s+/g, ' ').trim();
                throw new Error('Resposta inválida da API: ' + (preview || '[vazia]'));
            }
        }

        function apiCandidates(endpoint) {
            const clean = String(endpoint || '').replace(/^\/+/, '');
            const basePath = window.location.pathname.replace(/\/[^/]*$/, '/');
            return [...new Set([clean, basePath + clean, '/V00/src/public/' + clean])];
        }

        async function apiRequest(endpoint, options = {}) {
            const candidates = apiCandidates(endpoint);
            let lastError = null;
            let lastResp = null;

            const xhrFallback = (url, opts = {}) => new Promise((resolve, reject) => {
                try {
                    const xhr = new XMLHttpRequest();
                    xhr.open(opts.method || 'GET', url, true);
                    xhr.withCredentials = true;
                    const headers = opts.headers || {};
                    Object.keys(headers).forEach((k) => xhr.setRequestHeader(k, headers[k]));
                    xhr.onload = () => resolve({
                        ok: xhr.status >= 200 && xhr.status < 300,
                        status: xhr.status,
                        statusText: xhr.statusText || '',
                        text: async () => (xhr.responseText || '')
                    });
                    xhr.onerror = () => reject(new Error('XHR network error'));
                    xhr.send(opts.body || null);
                } catch (e) {
                    reject(e);
                }
            });

            for (let i = 0; i < candidates.length; i++) {
                const url = candidates[i];
                try {
                    const resp = await fetch(url, options);
                    if (resp.ok) return resp;
                    if (resp.status === 404) {
                        lastResp = resp;
                        continue;
                    }
                    return resp;
                } catch (e) {
                    lastError = e;
                    if (!/Failed to fetch|NetworkError|ERR_CONNECTION_CLOSED|conexao/i.test(String(e?.message || ''))) {
                        throw e;
                    }
                    try {
                        const xr = await xhrFallback(url, options);
                        if (xr.ok) return xr;
                        if (xr.status === 404) {
                            lastResp = xr;
                            continue;
                        }
                        return xr;
                    } catch (xe) {
                        lastError = xe;
                    }
                }
            }
            if (lastResp) return lastResp;
            throw lastError || new Error('Falha de conexao com API');
        }

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

        // ─── Render ───────────────────────────────────────────────────────────────────
        let cacheMesasAbertas = [];

        function statusLabel(s) {
            const u = String(s || '').toUpperCase();
            if (u === 'PRONTO') return 'Pronto';
            if (u === 'PREPARANDO' || u === 'CONFIRMADO') return 'Preparando';
            if (u === 'ENTREGUE') return 'Entregue';
            if (u === 'CONTA' || u === 'CONTA_SOLICITADA') return 'Conta';
            if (u === 'NOVO' || u === 'PENDENTE' || u === 'ABERTO') return 'Aberto';
            return u || '—';
        }

        function formatHoraPedido(iso) {
            if (!iso) return '—';
            const d = new Date(String(iso).replace(' ', 'T'));
            if (Number.isNaN(d.getTime())) return String(iso).slice(11, 16) || '—';
            return d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
        }

        function mesaContaLabel(m) {
            const raw = m && m.mesa_numero !== undefined && m.mesa_numero !== null ? String(m.mesa_numero).trim() : '';
            return raw !== '' && raw !== '0' ? 'Mesa ' + raw : 'Balcao';
        }

        function renderMesa(m) {
            const tc = timerClass(m.minutos_aberto);
            const tt = timerText(m.minutos_aberto);
            const itens = Array.isArray(m.itens) ? m.itens : [];
            const mesaLabel = mesaContaLabel(m);

            let itensHtml = itens.map(it => `
        <div class="item-row">
            <span class="nome">${esc(it.produto)}</span>
            <span class="qtd">${esc(it.quantidade)}×</span>
            <span class="preco">MZN ${fmt(parseFloat(it.preco_unitario)*parseFloat(it.quantidade))}</span>
        </div>`).join('');

            const statusBadge = (() => {
                const s = String(m.pedido_status || '').toUpperCase();
                if (s === 'PRONTO') return '<span style="color:#3b82f6"><i class="fas fa-check-circle me-1"></i>Pronto</span>';
                if (s === 'PREPARANDO' || s === 'CONFIRMADO') return '<span style="color:#f59e0b"><i class="fas fa-fire me-1"></i>Preparando</span>';
                if (s === 'ENTREGUE') return '<span style="color:#0f766e"><i class="fas fa-hand-holding me-1"></i>Entregue</span>';
                if (s === 'CONTA' || s === 'CONTA_SOLICITADA') return '<span style="color:#c2410c"><i class="fas fa-receipt me-1"></i>Conta</span>';
                return '<span style="color:#94a3b8"><i class="fas fa-clock me-1"></i>Novo</span>';
            })();
            const priorityLabel = m.minutos_aberto >= 30 ? 'Atenção' : (m.minutos_aberto >= 15 ? 'Em atenção' : 'Em fluxo');
            const priorityIcon = m.minutos_aberto >= 30 ? 'fa-exclamation-triangle' : (m.minutos_aberto >= 15 ? 'fa-hourglass-half' : 'fa-bolt');

            return `
    <div class="mesa-card ${tc}">
        <div class="card-header-mesa">
            <div>
                <div class="mesa-num">${esc(mesaLabel)}</div>
                <div><span>${statusBadge}</span></div>
                <div class="mesa-priority"><i class="fas ${priorityIcon}"></i>${priorityLabel}</div>
            </div>
            <span class="time-badge ${tc}"><i class="fas fa-clock me-1"></i>${tt}</span>
        </div>
        <div class="card-body-mesa">${itensHtml}</div>
        <div class="card-total">
            <div>
                <div>Total</div>
                ${m.garcom_nome ? `<div class="garcom"><i class="fas fa-user me-1"></i>${esc(m.garcom_nome)}</div>` : ''}
            </div>
            <div>MZN ${fmt(m.pedido_total)}</div>
        </div>
        <div class="card-actions">
            <button class="btn-pagar" onclick="abrirPagamento(${m.pedido_id},${m.mesa_numero},${m.pedido_total})">
                <i class="fas fa-check-circle me-2"></i>Receber Pagamento
            </button>
        </div>
    </div>`;
        }

        function filtrarMesasEmp(mesas) {
            const busca = String(document.getElementById('emp-busca')?.value || '').trim().toLowerCase();
            const tempo = String(document.getElementById('emp-filtro-tempo')?.value || 'todos');
            const status = String(document.getElementById('emp-filtro-status')?.value || 'todos').toUpperCase();
            return mesas.filter((m) => {
                const mins = Number(m.minutos_aberto || 0);
                if (tempo === '15' && mins < 15) return false;
                if (tempo === '30' && mins < 30) return false;
                const st = String(m.pedido_status || '').toUpperCase();
                if (status !== 'TODOS') {
                    if (status === 'CONTA') {
                        if (st !== 'CONTA' && st !== 'CONTA_SOLICITADA') return false;
                    } else if (status === 'PREPARANDO') {
                        if (st !== 'PREPARANDO' && st !== 'CONFIRMADO') return false;
                    } else if (st !== status) {
                        return false;
                    }
                }
                if (!busca) return true;
                const hay = [
                    m.mesa_numero,
                    m.garcom_nome,
                    m.numero_pedido,
                    m.pedido_status,
                    ...(Array.isArray(m.itens) ? m.itens.map(i => i.produto) : [])
                ].join(' ').toLowerCase();
                return hay.includes(busca);
            });
        }

        function renderTabelaEmpresarial(mesasAll) {
            const tbody = document.getElementById('emp-tbody');
            if (!tbody) return;
            const mesas = filtrarMesasEmp(mesasAll);
            const badge = document.getElementById('emp-badge-count');
            if (badge) badge.textContent = mesas.length + (mesas.length === 1 ? ' conta' : ' contas');

            if (!mesas.length) {
                const semContas = !(Array.isArray(cacheMesasAbertas) && cacheMesasAbertas.length);
                tbody.innerHTML = `<tr><td colspan="8"><div class="cx-vazio"><i class="fas fa-mug-hot" aria-hidden="true"></i><div><strong>${semContas ? 'Nenhuma conta aberta' : 'Nenhuma conta com estes filtros'}</strong><span>${semContas ? 'Todas as mesas estão livres ou pagas. As novas contas aparecem aqui automaticamente.' : 'Altere a pesquisa ou os filtros para ver outras contas.'}</span></div><a href="novo_pedido.php" class="cx-vazio-btn"><i class="fas fa-plus me-1"></i>Novo pedido</a></div></td></tr>`;
                return;
            }

            tbody.innerHTML = mesas.map((m) => {
                const tc = timerClass(m.minutos_aberto);
                const tt = timerText(m.minutos_aberto);
                const st = String(m.pedido_status || '').toUpperCase();
                const podeConta = st === 'PRONTO' || st === 'ENTREGUE';
                const rowClass = Number(m.minutos_aberto || 0) >= 30 ? 'atrasada' : '';
                const qtdItens = Number(m.qtd_itens || (m.itens || []).length || 0);
                const mesaLabel = mesaContaLabel(m);
                return `<tr class="${rowClass}">
                    <td>
                        <div class="emp-mesa">${esc(mesaLabel)}</div>
                        <div class="emp-sub">${esc(m.numero_pedido || ('#' + m.pedido_id))}</div>
                    </td>
                    <td>
                        <div>${esc(m.garcom_nome || '—')}</div>
                        <div class="emp-sub">${esc(m.capacidade ? (m.capacidade + ' lugares') : '')}</div>
                    </td>
                    <td>${esc(formatHoraPedido(m.pedido_criado_em))}</td>
                    <td><strong style="color:#1d4ed8">${fmt(m.pedido_total)}</strong></td>
                    <td><span class="emp-itens-pill">${qtdItens} ${qtdItens === 1 ? 'item' : 'itens'}</span></td>
                    <td><span class="emp-tempo ${tc}">${tt}</span></td>
                    <td><span class="emp-status">${esc(statusLabel(st))}</span></td>
                    <td>
                        <div class="emp-actions">
                            <button type="button" class="emp-btn-pay" onclick="abrirPagamento(${m.pedido_id},${m.mesa_numero},${m.pedido_total})">
                                Receber
                            </button>
                            <div class="emp-menu" id="emp-menu-${m.pedido_id}">
                                <button type="button" class="emp-menu-btn" onclick="toggleEmpMenu(event, ${m.pedido_id})" title="Mais ações">
                                    <i class="fas fa-ellipsis-v"></i>
                                </button>
                                <div class="emp-menu-panel">
                                    <button type="button" onclick="verPedido(${m.pedido_id})"><i class="fas fa-eye me-2"></i>Ver pedido</button>
                                    ${podeConta ? `<button type="button" onclick="pedirContaCaixa(${m.pedido_id})"><i class="fas fa-receipt me-2"></i>Pedir conta</button>` : ''}
                                    <button type="button" onclick="abrirPagamento(${m.pedido_id},${m.mesa_numero},${m.pedido_total})"><i class="fas fa-check-circle me-2"></i>Receber pagamento</button>
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>`;
            }).join('');
        }

        function updateKpis(mesas, data) {
            const qtd = Number(data?.qtd_abertas ?? mesas.length) || 0;
            const total = Number(data?.total_aberto ?? 0) || 0;
            document.getElementById('s-abertas').textContent = qtd;
            document.getElementById('s-total').textContent = 'MZN ' + fmt(total);
            document.getElementById('s-hora').textContent = new Date().toLocaleTimeString('pt-BR', {
                hour: '2-digit',
                minute: '2-digit'
            });

            const paradasEl = document.getElementById('s-paradas');
            if (paradasEl) {
                const paradas = mesas.filter((m) => Number(m.minutos_aberto || 0) >= 30).length;
                paradasEl.textContent = String(paradas);
                paradasEl.style.color = paradas > 0 ? '#b91c1c' : '';
                const cartao = document.getElementById('cx-paradas-card');
                if (cartao) cartao.classList.toggle('is-alerta', paradas > 0);
            }

            if (!IS_EMPRESARIAL) return;
            const ticket = qtd > 0 ? (total / qtd) : 0;
            const atrasadas = mesas.filter((m) => Number(m.minutos_aberto || 0) >= 30).length;
            const somaMin = mesas.reduce((acc, m) => acc + Number(m.minutos_aberto || 0), 0);
            const mediaMin = mesas.length ? Math.round(somaMin / mesas.length) : 0;
            const setEmp = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
            setEmp('s-ticket', 'MZN ' + fmt(ticket));
            setEmp('s-atrasadas', String(atrasadas));
            setEmp('s-tempo-medio', mediaMin > 0 ? timerText(mediaMin) : '—');
            const boxAntigas = document.getElementById('emp-side-antigas');
            if (boxAntigas) {
                const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
                const top = [...mesas].sort((a, b) => Number(b.minutos_aberto || 0) - Number(a.minutos_aberto || 0)).slice(0, 4);
                boxAntigas.innerHTML = top.length ? top.map((m) => {
                    const min = Number(m.minutos_aberto || 0);
                    const cls = min >= 30 ? ' is-late' : '';
                    return '<div class="cx-antiga' + cls + '"><span>' + esc(mesaContaLabel(m)) + '</span><span>' + esc(timerText(min)) + '</span><strong>MZN ' + esc(fmt(m.pedido_total)) + '</strong></div>';
                }).join('') : '<div class="cx-side-vazio">Nenhuma conta aberta.</div>';
            }
        }

        function renderPainelMesas(data) {
            const mesas = Array.isArray(data?.mesas) ? data.mesas : [];
            cacheMesasAbertas = mesas;
            updateKpis(mesas, data || {});

            if (IS_EMPRESARIAL) {
                renderTabelaEmpresarial(mesas);
                return;
            }

            const grid = document.getElementById('mesas-grid');
            if (!grid) return;
            if (mesas.length === 0) {
                grid.innerHTML = `<div class="empty-state">
                <i class="fas fa-coffee"></i>
                <h5>Nenhuma conta aberta</h5>
                <p>Todas as mesas estão livres ou pagas.</p>
            </div>`;
            } else {
                // Primeiro as contas abertas ha mais tempo: sao as que estao a espera.
                const porAntiguidade = [...mesas].sort(function (a, b) {
                    return Number(b.minutos_aberto || 0) - Number(a.minutos_aberto || 0);
                });
                grid.innerHTML = porAntiguidade.map(renderMesa).join('');
            }
        }

        function toggleEmpMenu(ev, pedidoId) {
            ev.stopPropagation();
            document.querySelectorAll('.emp-menu.open').forEach((el) => {
                if (el.id !== ('emp-menu-' + pedidoId)) el.classList.remove('open');
            });
            const menu = document.getElementById('emp-menu-' + pedidoId);
            if (menu) menu.classList.toggle('open');
        }
        document.addEventListener('click', () => {
            document.querySelectorAll('.emp-menu.open').forEach((el) => el.classList.remove('open'));
        });

        async function pedirContaCaixa(pedidoId) {
            if (!pedidoId) return;
            if (!(await nivoraConfirmar('Marcar este pedido como Conta solicitada?'))) return;
            try {
                const result = await OfflineSync.executeOrQueue({
                    label: 'Pedir conta do pedido ' + pedidoId,
                    endpoint: 'api/pedido_status.php',
                    method: 'POST',
                    body: { id: String(pedidoId), status: 'CONTA' },
                    headers: {
                        'X-CSRF-Token': <?php echo json_encode($csrfToken, JSON_UNESCAPED_UNICODE); ?>,
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                if (result.queued) {
                    NivoraFeedback.info('Pedido de conta guardado e pendente de sincronizacao.');
                    return;
                }
                if (!result.data?.success) throw new Error(result.data?.message || 'Falha ao pedir conta');
                await carregarMesas();
            } catch (e) {
                NivoraFeedback.error(e.message || 'Erro ao pedir conta.');
            }
        }
        window.pedirContaCaixa = pedirContaCaixa;
        window.toggleEmpMenu = toggleEmpMenu;

        async function carregarMesas() {
            const spin = document.getElementById('refresh-spin');
            if (spin) spin.style.display = 'inline-block';
            try {
                const result = await OfflineSync.fetchJSON('api/caixa_mesas_abertas.php', {
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    ttlMs: 120000,
                    allowStale: true
                });
                const data = result.data || {};
                if (!data.success) throw new Error(data.message || 'Erro');

                renderPainelMesas(data);
                falhasRedeCaixaMesas = 0;
                document.getElementById('footer-msg').textContent = 'Última actualização: ' + new Date().toLocaleTimeString('pt-BR', {
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                });
            } catch (e) {
                falhasRedeCaixaMesas += 1;
                document.getElementById('footer-msg').textContent = 'Aviso de rede: mantendo dados locais.';
                if (falhasRedeCaixaMesas >= 3 && refreshCaixaHandle) {
                    refreshCaixaHandle.parar();
                    refreshCaixaHandle = null;
                    document.getElementById('footer-msg').textContent = 'Modo local ativo: atualizacao automatica pausada.';
                }
            } finally {
                if (spin) spin.style.display = 'none';
            }
        }

        if (IS_EMPRESARIAL) {
            ['emp-busca', 'emp-filtro-tempo', 'emp-filtro-status'].forEach((id) => {
                const el = document.getElementById(id);
                if (!el) return;
                el.addEventListener('input', () => renderTabelaEmpresarial(cacheMesasAbertas));
                el.addEventListener('change', () => renderTabelaEmpresarial(cacheMesasAbertas));
            });
        }

        if (CAIXA_MESAS_INICIAL && CAIXA_MESAS_INICIAL.success) {
            renderPainelMesas(CAIXA_MESAS_INICIAL);
            document.getElementById('footer-msg').textContent = 'Atualizado às ' + new Date().toLocaleTimeString('pt-PT', { hour: '2-digit', minute: '2-digit' });
        } else {
            carregarMesas();
        }
        // So recarrega quando ha novidade (ver js/nv_operacao.js).
        refreshCaixaHandle = NvOperacao.vigiar({ intervalo: 6000, forcarCada: 60000, aoMudar: () => carregarMesas() });

        // ─── Pagamento ────────────────────────────────────────────────────────────────
        let payPedidoId = null;
        let payForma = 'DINHEIRO';
        const paymentDraftKey = () => 'pagamento:' + String(payPedidoId || 'novo');

        function guardarRascunhoPagamento() {
            if (!payPedidoId) return;
            OfflineSync.saveDraft(paymentDraftKey(), {
                forma: payForma,
                desconto: document.getElementById('pay-desconto')?.value || '0',
                valor_recebido: document.getElementById('pay-valor-recebido')?.value || '',
                referencia: document.getElementById('pay-referencia')?.value || ''
            }).catch(() => {});
        }

        function gerarReferenciaPagamento(forma, pedidoId) {
            const agora = new Date();
            const pad = (n, len = 2) => String(n).padStart(len, '0');
            const stamp = agora.getFullYear()
                + pad(agora.getMonth() + 1)
                + pad(agora.getDate())
                + pad(agora.getHours())
                + pad(agora.getMinutes())
                + pad(agora.getSeconds());
            const sufixo = Math.random().toString(36).slice(2, 6).toUpperCase();
            return String(forma || 'REF').toUpperCase() + '-' + stamp + '-P' + String(pedidoId || 0) + '-' + sufixo;
        }

        function syncPayCamposUi() {
            const containerValorRecebido = document.getElementById('pay-valor-recebido-container');
            const containerTroco = document.getElementById('pay-troco-container');
            const containerRef = document.getElementById('pay-referencia-container');
            const labelRef = document.getElementById('pay-referencia-label');
            const hintRef = document.getElementById('pay-referencia-hint');
            const inputRef = document.getElementById('pay-referencia');
            const precisaRef = payForma === 'MPESA' || payForma === 'EMOLA' || payForma === 'TRANSFERENCIA';

            if (payForma === 'DINHEIRO') {
                containerValorRecebido.style.display = 'block';
                containerTroco.style.display = 'block';
                containerRef.style.display = 'none';
                if (inputRef) inputRef.value = '';
                calcularTroco();
            } else {
                containerValorRecebido.style.display = 'none';
                containerTroco.style.display = 'none';
                document.getElementById('pay-valor-recebido').value = '';
                containerRef.style.display = precisaRef ? 'block' : 'none';
                if (precisaRef && inputRef) {
                    inputRef.value = gerarReferenciaPagamento(payForma, payPedidoId);
                    labelRef.textContent = 'Referência ' + (payForma === 'EMOLA' ? 'e-Mola' : (payForma === 'MPESA' ? 'M-Pesa' : 'transferência'));
                    hintRef.textContent = 'Gerada automaticamente — não é preciso digitar.';
                }
            }
        }

        async function abrirPagamento(pedidoId, mesaNum, total) {
            payPedidoId = pedidoId;
            payForma = 'DINHEIRO';
            document.getElementById('pay-sub').textContent = (String(mesaNum).trim() === '0' ? 'Balcao' : 'Mesa ' + mesaNum) + ' · Total: MZN ' + fmt(total);
            // Reset seleção
            document.querySelectorAll('.pay-option').forEach(el => el.classList.remove('selected'));
            document.querySelector('[data-pagamento="DINHEIRO"]').classList.add('selected');
            document.getElementById('pay-desconto').value = '0';
            document.getElementById('pay-valor-recebido').value = '';
            document.getElementById('pay-referencia').value = '';
            const draft = await OfflineSync.getDraft(paymentDraftKey()).catch(() => null);
            if (draft?.payload) {
                payForma = draft.payload.forma || payForma;
                document.getElementById('pay-desconto').value = draft.payload.desconto || '0';
                document.getElementById('pay-valor-recebido').value = draft.payload.valor_recebido || '';
                document.getElementById('pay-referencia').value = draft.payload.referencia || '';
            }
            document.getElementById('pay-troco-valor').textContent = 'MZN 0,00';
            document.getElementById('pay-troco-valor').style.color = 'var(--success)';
            syncPayCamposUi();
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPagamento')).show();
            setTimeout(() => document.getElementById('pay-valor-recebido').focus(), 100);
        }

        function selectPay(el) {
            document.querySelectorAll('.pay-option').forEach(x => x.classList.remove('selected'));
            el.classList.add('selected');
            payForma = el.dataset.pagamento;
            guardarRascunhoPagamento();
            syncPayCamposUi();
            if (payForma === 'DINHEIRO') {
                document.getElementById('pay-valor-recebido').focus();
            }
        }

        function calcularTroco() {
            const valorRecebidoInput = document.getElementById('pay-valor-recebido');
            const trocoValorElem = document.getElementById('pay-troco-valor');
            
            if (payForma !== 'DINHEIRO') return;
            
            const valorRecebido = parseFloat(valorRecebidoInput.value || 0);
            const totalElement = document.getElementById('pay-sub');
            const totalMatch = totalElement.textContent.match(/Total:\s*MZN\s*([\d,\.]+)/);
            
            if (!totalMatch) return;
            
            const totalString = totalMatch[1].replace('.', '').replace(',', '.');
            const total = parseFloat(totalString);
            const desconto = parseFloat(document.getElementById('pay-desconto').value || 0);
            const descontoValor = total * (desconto / 100);
            const totalComDesconto = total - descontoValor;
            const troco = valorRecebido - totalComDesconto;
            
            if (troco < 0) {
                trocoValorElem.style.color = 'var(--danger)';
                trocoValorElem.textContent = 'MZN ' + fmt(Math.abs(troco)) + ' (faltam)';
            } else {
                trocoValorElem.style.color = 'var(--success)';
                trocoValorElem.textContent = 'MZN ' + fmt(troco);
            }
        }
        
        async function confirmarPagamento() {
            if (!payPedidoId) return;
            const descontoPercent = parseFloat(document.getElementById('pay-desconto').value || 0);
            if (Number.isNaN(descontoPercent) || descontoPercent < 0 || descontoPercent > 100) {
                NivoraFeedback.warning('Desconto deve estar entre 0% e 100%.');
                return;
            }
            // Validar valor recebido se for DINHEIRO
            if (payForma === 'DINHEIRO') {
                const valorRecebido = parseFloat(document.getElementById('pay-valor-recebido').value || 0);
                if (valorRecebido <= 0) {
                    NivoraFeedback.warning('Por favor, informe o valor recebido em dinheiro.');
                    document.getElementById('pay-valor-recebido').focus();
                    return;
                }
            }
            let referencia = String(document.getElementById('pay-referencia')?.value || '').trim();
            if ((payForma === 'MPESA' || payForma === 'EMOLA' || payForma === 'TRANSFERENCIA') && !referencia) {
                referencia = gerarReferenciaPagamento(payForma, payPedidoId);
                const refEl = document.getElementById('pay-referencia');
                if (refEl) refEl.value = referencia;
            }

            // O recibo e carregado num iframe oculto pelo modulo central.
            const reciboPopup = null;

            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPagamento')).hide();
            document.getElementById('loading-overlay').classList.add('show');

            const paymentBody = {
                id: String(payPedidoId),
                forma_pagamento: String(payForma),
                desconto_percent: String(descontoPercent)
            };
            if (payForma === 'DINHEIRO') {
                const valorRecebido = parseFloat(document.getElementById('pay-valor-recebido').value || 0);
                paymentBody.valor_recebido = String(valorRecebido);
            }
            if (referencia) paymentBody.referencia = referencia;
            await OfflineSync.saveDraft(paymentDraftKey(), paymentBody);

            try {
                const result = await OfflineSync.executeOrQueue({
                    label: 'Pagamento do pedido ' + payPedidoId,
                    endpoint: 'api/pedido_pagar.php',
                    method: 'POST',
                    body: paymentBody,
                    headers: {
                        'X-CSRF-Token': <?php echo json_encode($csrfToken, JSON_UNESCAPED_UNICODE); ?>,
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                if (result.queued) {
                    if (reciboPopup && !reciboPopup.closed) reciboPopup.close();
                    await OfflineSync.removeDraft(paymentDraftKey());
                    NivoraFeedback.info('Pagamento guardado e pendente de sincronizacao.');
                    carregarMesas();
                    return;
                }
                const data = result.data;
                if (data.success) {
                    await OfflineSync.removeDraft(paymentDraftKey());
                    carregarMesas();
                    if (data.venda_id) {
                        await ReceiptPrint.print(data.venda_id).catch(function(error) {
                            console.error('Pagamento concluido, mas a impressao falhou', error);
                        });
                    } else if (reciboPopup && !reciboPopup.closed) {
                        reciboPopup.close();
                    }
                } else {
                    if (reciboPopup && !reciboPopup.closed) {
                        reciboPopup.close();
                    }
                    NivoraFeedback.error('Erro: ' + (data.message || 'Falha ao processar'));
                }
            } catch (e) {
                if (reciboPopup && !reciboPopup.closed) {
                    reciboPopup.close();
                }
                NivoraFeedback.error('Nao foi possivel guardar o pagamento: ' + e.message);
            } finally {
                document.getElementById('loading-overlay').classList.remove('show');
            }
        }

        ['pay-desconto', 'pay-valor-recebido', 'pay-referencia'].forEach((id) => {
            document.getElementById(id)?.addEventListener('input', guardarRascunhoPagamento);
        });

        function verPedido(id) {
            window.location.href = 'pedidos.php?pedido_id=' + id;
        }
    </script>
<script src="js/autorizacao_gerente.js?v=20261108d" data-csrf="<?php echo htmlspecialchars((string)csrf_get_token(), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="js/nivora_dialogo.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nivora_dialogo.js'); ?>"></script>
</body>

</html>
