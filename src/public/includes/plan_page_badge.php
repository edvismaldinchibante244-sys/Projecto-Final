<?php
/**
 * Badge de plano para títulos de página.
 * Depende de $plano_atual / $planThemeSlug (sidebar define ambos).
 */
if (!isset($plano_atual) || $plano_atual === '') {
    return;
}
$__planBadgeLabel = $plano_atual === 'BASICO' ? 'Básico'
    : ($plano_atual === 'PROFISSIONAL' ? 'Profissional'
    : ($plano_atual === 'EMPRESARIAL' ? 'Empresarial' : (string)$plano_atual));
?>
<span class="plan-page-badge"><?php echo htmlspecialchars($__planBadgeLabel, ENT_QUOTES, 'UTF-8'); ?></span>
