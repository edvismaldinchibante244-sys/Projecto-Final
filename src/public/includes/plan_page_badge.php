<?php
// Este ficheiro e um fragmento: so faz sentido incluido por uma pagina, que e
// quem verifica a sessao. Pedido directamente pelo navegador, nao responde.
// Hoje o reescritor de URLs ja devolve 404, mas isso e um efeito secundario
// das regras de reescrita, nao uma decisao: se mudarem, o fragmento ficava a
// descoberto. Esta linha torna a recusa deliberada.
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Not found');
}

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
