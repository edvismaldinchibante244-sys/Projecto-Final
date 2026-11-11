<?php
require_once __DIR__ . '/app_base.php';

/* Talão de fecho de caixa (resumo do turno para o gerente assinar). */

include_once APP_BASE . '/src/config/auth_check.php';
include_once APP_BASE . '/src/config/restaurante_context.php';
requirePermissionOrRedirect(['ADMIN', 'GERENTE', 'CAIXA']);
include_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/caixa_movimentos.php';

$rid = (int)($_SESSION['restaurante_id'] ?? 0);
$caixaId = (int)($_GET['id'] ?? 0);
$db = (new Database())->getConnection();

$st = $db->prepare("SELECT c.*, u.nome AS usuario_nome FROM caixas c LEFT JOIN usuarios u ON u.id = c.usuario_id
    WHERE c.id = ? AND c.restaurante_id = ?");
$st->execute([$caixaId, $rid]);
$cx = $st->fetch(PDO::FETCH_ASSOC);
if (!$cx) {
    http_response_code(404);
    echo 'Caixa não encontrado.';
    exit;
}

$stR = $db->prepare("SELECT nome FROM restaurantes WHERE id = ?");
$stR->execute([$rid]);
$nomeRest = (string)($stR->fetchColumn() ?: 'Restaurante');

$stF = $db->prepare("SELECT UPPER(COALESCE(NULLIF(TRIM(forma_pagamento), ''), 'OUTRO')) AS forma,
        COUNT(*) AS qtd, COALESCE(SUM(total_final), 0) AS total
    FROM vendas WHERE caixa_id = ? AND restaurante_id = ? AND UPPER(TRIM(COALESCE(status, ''))) = 'PAGO'
    GROUP BY forma ORDER BY total DESC");
$stF->execute([$caixaId, $rid]);
$formas = $stF->fetchAll(PDO::FETCH_ASSOC) ?: [];

$stC = $db->prepare("SELECT COUNT(*) FROM vendas WHERE caixa_id = ? AND restaurante_id = ?
    AND UPPER(TRIM(COALESCE(status, ''))) = 'CANCELADO'");
$stC->execute([$caixaId, $rid]);
$canceladas = (int)$stC->fetchColumn();

$totalVendas = 0.0;
$dinheiro = 0.0;
$qtdVendas = 0;
foreach ($formas as $f) {
    $totalVendas += (float)$f['total'];
    $qtdVendas += (int)$f['qtd'];
    if ($f['forma'] === 'DINHEIRO') {
        $dinheiro += (float)$f['total'];
    }
}
$mov = caixa_mov_resumo($db, $caixaId, $rid);
$abertura = (float)($cx['saldo_inicial'] ?? 0);
$esperado = round($abertura + $dinheiro + $mov['reforco'] - $mov['sangria'], 2);
$fechado = strtoupper((string)($cx['status'] ?? '')) === 'FECHADO';
$contado = $fechado ? (float)($cx['saldo_final'] ?? 0) : null;
$diferenca = $contado !== null ? round($contado - $esperado, 2) : null;

$fmt = static fn($v) => number_format((float)$v, 2, ',', '.') . ' MZN';
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fecho de caixa #<?php echo (int)$caixaId; ?></title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; color: #111; margin: 0; background: #f1f5f9; }
        .talao { width: 80mm; max-width: 100%; margin: 16px auto; background: #fff; padding: 14px 14px 18px; font-size: 12.5px; line-height: 1.4; }
        h1 { font-size: 15px; text-align: center; margin: 0 0 2px; }
        .sub { text-align: center; font-size: 11.5px; color: #444; margin-bottom: 10px; }
        h2 { font-size: 12px; text-transform: uppercase; letter-spacing: .05em; margin: 12px 0 4px; border-bottom: 1px dashed #999; padding-bottom: 3px; }
        .row { display: flex; justify-content: space-between; gap: 8px; padding: 2px 0; }
        .row strong { white-space: nowrap; }
        .total { font-weight: 700; border-top: 1px solid #111; margin-top: 4px; padding-top: 4px; }
        .dif-ok { color: #047857; } .dif-mal { color: #b91c1c; }
        .mov { font-size: 11.5px; color: #333; padding: 2px 0 4px; }
        .assin { margin-top: 26px; font-size: 11.5px; }
        .assin div { border-top: 1px solid #111; margin-top: 30px; padding-top: 3px; text-align: center; }
        .acoes { text-align: center; margin: 10px auto 20px; }
        .acoes button { padding: 10px 18px; border-radius: 10px; border: 0; background: #ea580c; color: #fff; font-weight: 700; cursor: pointer; margin: 0 4px; }
        .acoes button.sec { background: #e2e8f0; color: #111; }
        @media print { body { background: #fff; } .talao { margin: 0; width: 80mm; } .acoes { display: none; } }
    </style>
</head>
<body>
<div class="talao">
    <h1><?php echo $h($nomeRest); ?></h1>
    <div class="sub">Fecho de caixa #<?php echo (int)$caixaId; ?><?php echo $fechado ? '' : ' (caixa ainda aberto)'; ?></div>

    <div class="row"><span>Operador</span><strong><?php echo $h($cx['usuario_nome'] ?? '—'); ?></strong></div>
    <div class="row"><span>Abertura</span><strong><?php echo $h(date('d/m/Y H:i', strtotime((string)$cx['data_abertura']))); ?></strong></div>
    <div class="row"><span>Fecho</span><strong><?php echo !empty($cx['data_fechamento']) ? $h(date('d/m/Y H:i', strtotime((string)$cx['data_fechamento']))) : '—'; ?></strong></div>

    <h2>Vendas (<?php echo (int)$qtdVendas; ?>)</h2>
    <?php if (empty($formas)): ?>
        <div class="row"><span>Sem vendas</span><strong><?php echo $fmt(0); ?></strong></div>
    <?php endif; ?>
    <?php foreach ($formas as $f): ?>
        <div class="row"><span><?php echo $h(caixa_forma_label((string)$f['forma'])); ?> (<?php echo (int)$f['qtd']; ?>)</span><strong><?php echo $fmt($f['total']); ?></strong></div>
    <?php endforeach; ?>
    <div class="row total"><span>Total vendido</span><strong><?php echo $fmt($totalVendas); ?></strong></div>
    <?php if ($canceladas > 0): ?>
        <div class="row"><span>Vendas canceladas</span><strong><?php echo (int)$canceladas; ?></strong></div>
    <?php endif; ?>

    <h2>Gaveta (dinheiro)</h2>
    <div class="row"><span>Fundo de abertura</span><strong><?php echo $fmt($abertura); ?></strong></div>
    <div class="row"><span>+ Vendas em dinheiro</span><strong><?php echo $fmt($dinheiro); ?></strong></div>
    <div class="row"><span>+ Reforços</span><strong><?php echo $fmt($mov['reforco']); ?></strong></div>
    <div class="row"><span>− Sangrias</span><strong><?php echo $fmt($mov['sangria']); ?></strong></div>
    <div class="row total"><span>Esperado na gaveta</span><strong><?php echo $fmt($esperado); ?></strong></div>
    <div class="row"><span>Contado</span><strong><?php echo $contado !== null ? $fmt($contado) : '—'; ?></strong></div>
    <?php if ($diferenca !== null): ?>
        <div class="row total <?php echo abs($diferenca) < 0.01 ? 'dif-ok' : 'dif-mal'; ?>">
            <span>Diferença</span>
            <strong><?php echo abs($diferenca) < 0.01 ? 'Sem diferença' : (($diferenca > 0 ? '+' : '') . $fmt($diferenca) . ($diferenca > 0 ? ' (sobra)' : ' (falta)')); ?></strong>
        </div>
    <?php endif; ?>

    <?php if (!empty($mov['lista'])): ?>
        <h2>Sangrias e reforços</h2>
        <?php foreach (array_reverse($mov['lista']) as $m): ?>
            <div class="row"><span><?php echo $h(date('H:i', strtotime((string)$m['criado_em']))); ?> · <?php echo strtoupper((string)$m['tipo']) === 'SANGRIA' ? 'Sangria' : 'Reforço'; ?></span><strong><?php echo (strtoupper((string)$m['tipo']) === 'SANGRIA' ? '−' : '+') . $fmt($m['valor']); ?></strong></div>
            <div class="mov"><?php echo $h($m['motivo'] ?: '—'); ?> · <?php echo $h($m['usuario_nome'] ?? '—'); ?><?php echo !empty($m['autorizado_nome']) && $m['autorizado_nome'] !== $m['usuario_nome'] ? ' · autorizado por ' . $h($m['autorizado_nome']) : ''; ?></div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="assin">
        <div>Operador de caixa</div>
        <div>Gerente</div>
    </div>
    <div class="sub" style="margin-top:12px;">Impresso em <?php echo $h(date('d/m/Y H:i')); ?></div>
</div>
<div class="acoes">
    <button type="button" id="btnImprimir">Imprimir</button>
    <a class="sec-link" href="caixa.php"><button type="button" class="sec">Voltar ao caixa</button></a>
</div>
<script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
document.getElementById('btnImprimir').addEventListener('click', function () { window.print(); });
</script>
</body>
</html>
