<?php
/**
 * Movimento do dia — peça própria do plano Básico.
 *
 * Porque existe: uma lanchonete não vive de médias semanais. Vive de dois
 * picos — o pequeno-almoço e o almoço — e a pergunta do dono às 15h é sempre a
 * mesma: "a que horas é que isto encheu hoje?". É dessa resposta que sai
 * quantos salgados fazer amanhã de manhã e quem escalar ao meio-dia.
 *
 * Nem o Profissional nem o Empresarial têm isto neste formato: eles mostram a
 * semana. Esta peça mostra o dia, hora a hora, e é por isso que é do Básico.
 *
 * Variáveis: $dashBasHoras, $dashBasPicoHora, $dashBasPicoValor,
 *            $dashBasTemOntem, $dashBasOntemTotal, $dashBasDeltaOntem
 */

$bmTemDados = !empty($dashBasHoras) && $dashBasPicoValor > 0;
$bmMax = max(0.01, (float)$dashBasPicoValor);
$bmTotalDia = 0.0;
foreach (($dashBasHoras ?? []) as $bmH) {
    $bmTotalDia += (float)$bmH['total'];
}
?>
<section class="bas-movimento" aria-labelledby="bas-movimento-titulo">
    <header class="bas-movimento-head">
        <div>
            <h2 id="bas-movimento-titulo" class="bas-movimento-titulo">Movimento de hoje</h2>
            <p class="bas-movimento-sub">
                <?php if ($bmTemDados): ?>
                    Hora a hora, só vendas pagas.
                <?php else: ?>
                    Ainda sem vendas pagas hoje. As barras enchem-se à medida que o dia corre.
                <?php endif; ?>
            </p>
        </div>
        <?php if ($bmTemDados && $dashBasPicoHora !== null): ?>
            <div class="bas-movimento-pico">
                <span class="bas-movimento-pico-rotulo">Hora mais forte</span>
                <strong><?php echo str_pad((string)(int)$dashBasPicoHora, 2, '0', STR_PAD_LEFT); ?>h</strong>
                <span class="bas-movimento-pico-valor"><?php echo number_format($dashBasPicoValor, 0, ',', '.'); ?> MZN</span>
            </div>
        <?php endif; ?>
    </header>

    <div class="bas-movimento-barras" role="img"
         aria-label="<?php echo $bmTemDados
            ? 'Vendas por hora. Hora mais forte: ' . (int)$dashBasPicoHora . ' horas, ' . number_format($dashBasPicoValor, 0, ',', '.') . ' meticais.'
            : 'Ainda sem vendas pagas hoje.'; ?>">
        <?php foreach (($dashBasHoras ?? []) as $bmH):
            $bmAltura = $bmTemDados ? max(2, (int)round(((float)$bmH['total'] / $bmMax) * 100)) : 2;
            $bmPico = ($dashBasPicoHora !== null && (int)$bmH['hora'] === (int)$dashBasPicoHora && $bmTemDados);
            $bmClasses = 'bas-barra' . ($bmPico ? ' is-pico' : '') . (!empty($bmH['agora']) ? ' is-agora' : '');
            $bmTitulo = str_pad((string)(int)$bmH['hora'], 2, '0', STR_PAD_LEFT) . 'h — '
                . number_format((float)$bmH['total'], 0, ',', '.') . ' MZN em ' . (int)$bmH['vendas'] . ' venda(s)';
        ?>
            <div class="<?php echo $bmClasses; ?>" title="<?php echo htmlspecialchars($bmTitulo, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="bas-barra-haste">
                    <div class="bas-barra-cheio" style="height: <?php echo $bmAltura; ?>%;"></div>
                </div>
                <span class="bas-barra-hora"><?php echo str_pad((string)(int)$bmH['hora'], 2, '0', STR_PAD_LEFT); ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <footer class="bas-movimento-pe">
        <span class="bas-movimento-total">
            <strong><?php echo number_format($bmTotalDia, 2, ',', '.'); ?> MZN</strong> até agora
        </span>
        <?php if ($dashBasTemOntem): ?>
            <?php $bmSobe = ((float)$dashBasDeltaOntem) >= 0; ?>
            <span class="bas-movimento-ontem <?php echo $bmSobe ? 'is-sobe' : 'is-desce'; ?>">
                <i class="fas fa-arrow-<?php echo $bmSobe ? 'up' : 'down'; ?>" aria-hidden="true"></i>
                <?php echo ($bmSobe ? '+' : '') . number_format((float)$dashBasDeltaOntem, 0, ',', '.'); ?>%
                face a ontem a esta hora (<?php echo number_format($dashBasOntemTotal, 0, ',', '.'); ?> MZN)
            </span>
        <?php else: ?>
            <span class="bas-movimento-ontem is-neutro">Ontem a esta hora não havia vendas para comparar</span>
        <?php endif; ?>
    </footer>
</section>
