<?php
$legalFooterCompact = isset($legalFooterCompact) ? (bool)$legalFooterCompact : false;
?>
<footer class="legal-footer<?= $legalFooterCompact ? ' legal-footer--compact' : '' ?>">
    <div class="legal-footer__inner">
        <span>&copy; <?= date('Y') ?> Nivora Restaurant</span>
        <nav aria-label="Informacao legal">
            <a href="privacidade.php">Privacidade</a>
            <a href="termos.php">Termos de Uso</a>
            <a href="cookies.php">Cookies</a>
            <a href="direitos_dados.php">Os seus dados</a>
        </nav>
    </div>
</footer>

<aside class="legal-cookie-notice" id="legalCookieNotice" aria-labelledby="legalCookieTitle" hidden>
    <div>
        <strong id="legalCookieTitle">Cookies essenciais</strong>
        <p>Usamos apenas o necessário para manter a sessão, a segurança e as suas preferências. Não usamos cookies de publicidade.</p>
    </div>
    <div class="legal-cookie-notice__actions">
        <a href="cookies.php">Saber mais</a>
        <button type="button" id="legalCookieAccept">Compreendi</button>
    </div>
</aside>
<script src="js/legal-consent.js?v=20260808a" defer></script>
