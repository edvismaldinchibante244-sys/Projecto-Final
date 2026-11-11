<?php
/**
 * Faixa de inteligência operacional exclusiva do plano Empresarial.
 * Apresenta risco de SLA e recomendação preditiva baseada somente na fila atual.
 * Requer: $grupos, $kdsOldestPrep, $kdsSlaWarn, $kdsSlaCrit, $plano_atual, $kdsAreaLabel.
 */
if (($plano_atual ?? '') !== 'EMPRESARIAL') {
    return;
}

$kdsPrep = count($grupos['em_preparo'] ?? []);
$kdsReady = count($grupos['pronto'] ?? []);
$kdsWarn = 0;
$kdsCrit = 0;
$kdsTotalWait = 0;
$kdsTotalUnits = 0;
$kdsPriorityItem = null;
$kdsPriorityMinutes = -1;

foreach ($grupos['em_preparo'] ?? [] as $item) {
    $timestamp = strtotime((string)($item['data_status'] ?? ''));
    $minutes = $timestamp ? max(0, (int)floor((time() - $timestamp) / 60)) : 0;
    $kdsTotalWait += $minutes;
    $kdsTotalUnits += max(1, (int)($item['quantidade'] ?? 1));

    if ($minutes > $kdsPriorityMinutes) {
        $kdsPriorityMinutes = $minutes;
        $kdsPriorityItem = $item;
    }
    if ($minutes > (int)$kdsSlaCrit) {
        $kdsCrit++;
    } elseif ($minutes > (int)$kdsSlaWarn) {
        $kdsWarn++;
    }
}

$kdsAvgWait = $kdsPrep > 0 ? (int)round($kdsTotalWait / $kdsPrep) : 0;
$kdsOldest = $kdsOldestPrep === null ? 0 : max(0, (int)$kdsOldestPrep);
$kdsArea = htmlspecialchars((string)($kdsAreaLabel ?? 'Posto'), ENT_QUOTES, 'UTF-8');
$kdsAreaIcon = strcasecmp((string)($kdsAreaLabel ?? ''), 'Bar') === 0
    ? 'fa-martini-glass-citrus'
    : 'fa-kitchen-set';

if ($kdsCrit > 0) {
    $kdsTone = 'crit';
    $kdsTitle = 'Intervenção imediata';
    $kdsText = $kdsCrit . ' item(ns) ultrapassaram o SLA crítico de ' . (int)$kdsSlaCrit . ' min.';
} elseif ($kdsWarn > 0) {
    $kdsTone = 'warn';
    $kdsTitle = 'Risco de atraso';
    $kdsText = $kdsWarn . ' item(ns) aproximam-se do limite crítico da operação.';
} elseif ($kdsReady > 3) {
    $kdsTone = 'info';
    $kdsTitle = 'Prontos a acumular';
    $kdsText = $kdsReady . ' pedidos aguardam entrega ou levantamento.';
} elseif ($kdsPrep === 0 && $kdsReady === 0) {
    $kdsTone = 'ok';
    $kdsTitle = 'Fila limpa';
    $kdsText = 'Posto disponível para receber o próximo pedido.';
} else {
    $kdsTone = 'ok';
    $kdsTitle = 'Operação estável';
    $kdsText = $kdsPrep . ' em preparo · média ' . $kdsAvgWait . ' min · SLA ' . (int)$kdsSlaWarn . '/' . (int)$kdsSlaCrit . ' min.';
}

$kdsRiskScore = min(100, (int)round(
    ($kdsCrit * 34)
    + ($kdsWarn * 16)
    + (max(0, $kdsReady - 3) * 7)
    + (min(1, $kdsOldest / max(1, (int)$kdsSlaCrit)) * 30)
));

$kdsActiveLoad = $kdsTotalUnits + $kdsReady;
if ($kdsRiskScore >= 70 || $kdsCrit > 0) {
    $kdsPressure = 'Crítica';
    $kdsCapacity = 'Capacidade pressionada';
} elseif ($kdsRiskScore >= 40 || $kdsActiveLoad >= 8 || $kdsWarn > 0) {
    $kdsPressure = 'Elevada';
    $kdsCapacity = 'Capacidade em atenção';
} elseif ($kdsActiveLoad >= 4) {
    $kdsPressure = 'Moderada';
    $kdsCapacity = 'Capacidade controlada';
} else {
    $kdsPressure = 'Baixa';
    $kdsCapacity = 'Capacidade disponível';
}

if ($kdsCrit > 0 || ($kdsWarn > 0 && $kdsActiveLoad >= 6)) {
    $kdsTrend = 'Tendência de agravamento';
} elseif ($kdsReady > 3) {
    $kdsTrend = 'Tendência de acumulação';
} elseif ($kdsPrep === 0 && $kdsReady === 0) {
    $kdsTrend = 'Sem pressão observada';
} else {
    $kdsTrend = 'Tendência estável';
}

$kdsRecommendation = '';
if (!empty($kdsPredictiveEnabled)) {
    $priorityLocation = !empty($kdsPriorityItem['mesa_numero'])
        ? 'Mesa ' . (int)$kdsPriorityItem['mesa_numero']
        : 'Balcão';
    $priorityProduct = trim((string)($kdsPriorityItem['produto_nome'] ?? ''));

    if ($kdsCrit > 0 && $kdsPriorityItem) {
        $kdsRecommendation = 'Priorizar ' . $priorityLocation . ($priorityProduct !== '' ? ' — ' . $priorityProduct : '') . '; é o item com maior espera.';
    } elseif ($kdsWarn > 0 && $kdsPriorityItem) {
        $kdsRecommendation = 'Antecipar ' . $priorityLocation . ' para evitar ultrapassar o SLA crítico.';
    } elseif ($kdsReady > 3) {
        $kdsRecommendation = 'Acionar o salão para retirar os pedidos prontos e libertar a expedição.';
    } elseif ($kdsTotalUnits >= 8) {
        $kdsRecommendation = 'Distribuir os próximos itens entre postos; a carga atual é de ' . $kdsTotalUnits . ' unidades.';
    } elseif ($kdsPrep > 0) {
        $kdsRecommendation = 'Manter a sequência atual; não há risco relevante de quebra de SLA.';
    } else {
        $kdsRecommendation = 'Operação sem pressão. Preparar o posto para o próximo pico.';
    }
}

$kdsDecision = $kdsRecommendation !== '' ? $kdsRecommendation : $kdsText;
$kdsPriorityId = $kdsPriorityItem === null ? 0 : (int)($kdsPriorityItem['id'] ?? 0);
$kdsNaFila = $kdsPrep;
$metricData = [
    ['label' => 'Em preparo', 'value' => (string)$kdsPrep, 'icon' => $kdsAreaIcon, 'tone' => 'prep'],
    ['label' => 'Prontos', 'value' => (string)$kdsReady, 'icon' => 'fa-check', 'tone' => 'ready'],
    ['label' => 'Críticos', 'value' => (string)$kdsCrit, 'icon' => 'fa-triangle-exclamation', 'tone' => 'critical'],
    ['label' => 'Mais antigo', 'value' => $kdsOldestPrep === null ? '—' : ($kdsOldest . ' min'), 'icon' => 'fa-clock', 'tone' => 'oldest'],
    ['label' => 'Média espera', 'value' => $kdsAvgWait . ' min', 'icon' => 'fa-stopwatch', 'tone' => 'average'],
];
?>
<section class="kds-emp-intel kds-emp-intel-slim" data-kds-intel data-kds-risk="<?php echo (int)$kdsRiskScore; ?>">
    <div class="kds-strip tom-<?php echo htmlspecialchars($kdsTone, ENT_QUOTES, 'UTF-8'); ?>" aria-live="polite">
        <span class="kds-strip-dot" aria-hidden="true"></span>
        <div class="kds-strip-copy">
            <strong><?php echo htmlspecialchars($kdsTitle, ENT_QUOTES, 'UTF-8'); ?></strong>
            <span><?php echo htmlspecialchars($kdsDecision !== '' && $kdsRecommendation !== '' ? $kdsRecommendation : $kdsText, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <?php if ($kdsPriorityId > 0 && $kdsCrit + $kdsWarn > 0): ?>
            <button type="button" class="kds-locate-priority" data-kds-locate-priority="<?php echo (int)$kdsPriorityId; ?>">
                <i class="fas fa-crosshairs" aria-hidden="true"></i>Localizar
            </button>
        <?php endif; ?>
        <div class="kds-strip-fila" title="Itens à espera de preparo">
            <span>Na fila</span>
            <strong><?php echo (int)$kdsNaFila; ?></strong>
        </div>
    </div>
    <div class="kds-emp-intel-metrics" aria-label="Indicadores inteligentes da operação">
        <?php foreach ($metricData as $metric): ?>
            <div class="kds-emp-metric tone-<?php echo htmlspecialchars($metric['tone'], ENT_QUOTES, 'UTF-8'); ?>">
                <span class="kds-metric-icon" aria-hidden="true"><i class="fas <?php echo htmlspecialchars($metric['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i></span>
                <span class="kds-metric-copy">
                    <span class="lbl"><?php echo htmlspecialchars($metric['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="val"><?php echo htmlspecialchars($metric['value'], ENT_QUOTES, 'UTF-8'); ?></span>
                </span>
                <span class="kds-metric-line" aria-hidden="true"></span>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
(() => {
    if (window.kdsIntelLocateBound) return;
    window.kdsIntelLocateBound = true;
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-kds-intel] [data-kds-locate-priority]');
        if (!button) return;
        const itemId = String(button.dataset.kdsLocatePriority || '');
        if (!/^\d+$/.test(itemId)) return;
        const card = document.querySelector('.kds-refresh-root .card-item[data-id="' + itemId + '"]');
        if (!card) return;
        card.scrollIntoView({ behavior: 'smooth', block: 'center' });
        card.setAttribute('tabindex', '-1');
        card.focus({ preventScroll: true });
        card.classList.remove('kds-priority-focus');
        void card.offsetWidth;
        card.classList.add('kds-priority-focus');
        window.setTimeout(() => card.classList.remove('kds-priority-focus'), 2200);
    });
})();
</script>
