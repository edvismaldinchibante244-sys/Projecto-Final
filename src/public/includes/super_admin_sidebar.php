<?php

if (!function_exists('super_admin_has_permission')) {
    throw new RuntimeException('As permissoes do Super Admin devem ser carregadas antes da sidebar.');
}

$superAdminActiveSection = (string)($superAdminActiveSection ?? '');
$superAdminSectionLinks = !empty($superAdminSectionLinks);
$superAdminPrivacyPendingCount = max(0, (int)($superAdminPrivacyPendingCount ?? 0));
$superAdminOwnerName = trim((string)($superAdminOwnerName ?? ($_SESSION['nome'] ?? 'Proprietario')));
$superAdminBranchContext = !empty($superAdminBranchContext);
$superAdminOwnerLabel = $superAdminOwnerName !== '' ? $superAdminOwnerName : 'Proprietario';
$superAdminOwnerInitial = function_exists('mb_substr') ? mb_substr($superAdminOwnerLabel, 0, 1, 'UTF-8') : substr($superAdminOwnerLabel, 0, 1);
$superAdminOwnerInitial = function_exists('mb_strtoupper') ? mb_strtoupper($superAdminOwnerInitial, 'UTF-8') : strtoupper($superAdminOwnerInitial);

$superAdminCan = [
    'dashboard' => super_admin_has_permission('view_dashboard'),
    'restaurants' => super_admin_has_permission('manage_restaurants'),
    'users' => super_admin_has_permission('manage_users'),
    'plans' => super_admin_has_any_permission(['view_finance', 'approve_plans']),
    'support' => super_admin_has_permission('manage_support'),
    'privacy' => super_admin_has_permission('manage_privacy'),
    'security' => super_admin_has_permission('manage_security'),
    'audit' => super_admin_has_permission('manage_security'),
];

$superAdminSectionHref = static function (string $section) use ($superAdminSectionLinks): string {
    return $superAdminSectionLinks ? '#' : 'admin.php?secao=' . rawurlencode($section);
};

$superAdminSectionAttributes = static function (string $section) use ($superAdminSectionLinks): string {
    if (!$superAdminSectionLinks) {
        return '';
    }

    return ' data-secao="' . htmlspecialchars($section, ENT_QUOTES, 'UTF-8') . '"';
};

$superAdminActiveClass = static function (string $section) use ($superAdminActiveSection): string {
    return $superAdminActiveSection === $section ? ' active' : '';
};
?>
<nav class="sidebar sa-owner-sidebar col-md-3 col-lg-2 d-md-block" aria-label="Administracao da plataforma">
    <div class="sa-owner-brand">
        <div class="sa-owner-brand-mark" aria-hidden="true"><i class="fas fa-crown"></i></div>
        <div class="sa-owner-brand-copy">
            <strong>Nivora Restaurant Admin</strong>
            <span>Propriet&aacute;rio da plataforma</span>
        </div>
    </div>

    <div class="sa-owner-status">
        <span class="sa-owner-status-dot" aria-hidden="true"></span>
        <span>Central operacional ativa</span>
    </div>

    <div class="sa-owner-nav">
        <?php if ($superAdminCan['dashboard']): ?>
            <div class="sa-nav-group">
                <p class="sa-nav-label">Vis&atilde;o geral</p>
                <ul class="nav flex-column">
                    <li class="nav-item"><a href="admin_dashboard.php" class="nav-link<?= $superAdminActiveClass('dashboard') ?>"<?= $superAdminActiveSection === 'dashboard' ? ' aria-current="page"' : '' ?>><i class="fas fa-chart-pie"></i><span>Painel executivo</span></a></li>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($superAdminCan['restaurants'] || $superAdminCan['users']): ?>
            <div class="sa-nav-group">
                <p class="sa-nav-label">Opera&ccedil;&atilde;o</p>
                <ul class="nav flex-column">
                    <?php if ($superAdminCan['restaurants']): ?>
                        <li class="nav-item"><a href="<?= $superAdminSectionHref('restaurantes') ?>" class="nav-link<?= $superAdminActiveClass('restaurantes') ?><?= $superAdminSectionLinks ? ' js-secao-link' : '' ?>"<?= $superAdminSectionAttributes('restaurantes') ?><?= $superAdminActiveSection === 'restaurantes' ? ' aria-current="page"' : '' ?>><i class="fas fa-building"></i><span>Restaurantes</span></a></li>
                    <?php endif; ?>
                    <?php if ($superAdminCan['users']): ?>
                        <li class="nav-item"><a href="<?= $superAdminSectionHref('usuarios') ?>" class="nav-link<?= $superAdminActiveClass('usuarios') ?><?= $superAdminSectionLinks ? ' js-secao-link' : '' ?>"<?= $superAdminSectionAttributes('usuarios') ?><?= $superAdminActiveSection === 'usuarios' ? ' aria-current="page"' : '' ?>><i class="fas fa-users"></i><span>Utilizadores</span></a></li>
                    <?php endif; ?>
                    <?php if ($superAdminCan['restaurants'] && !$superAdminBranchContext): ?>
                        <li class="nav-item"><a href="filiais.php" class="nav-link<?= $superAdminActiveClass('filiais') ?>"><i class="fas fa-code-branch"></i><span>Filiais</span></a></li>
                    <?php endif; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($superAdminCan['plans']): ?>
            <div class="sa-nav-group">
                <p class="sa-nav-label">Comercial</p>
                <ul class="nav flex-column">
                    <li class="nav-item"><a href="<?= $superAdminSectionHref('planos') ?>" class="nav-link<?= $superAdminActiveClass('planos') ?><?= $superAdminSectionLinks ? ' js-secao-link' : '' ?>"<?= $superAdminSectionAttributes('planos') ?><?= $superAdminActiveSection === 'planos' ? ' aria-current="page"' : '' ?>><i class="fas fa-credit-card"></i><span>Planos e fatura&ccedil;&atilde;o</span></a></li>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($superAdminCan['support'] || $superAdminCan['privacy']): ?>
            <div class="sa-nav-group">
                <p class="sa-nav-label">Atendimento</p>
                <ul class="nav flex-column">
                    <?php if ($superAdminCan['support']): ?>
                        <li class="nav-item"><a href="<?= $superAdminSectionHref('suporte') ?>" class="nav-link<?= $superAdminActiveClass('suporte') ?><?= $superAdminSectionLinks ? ' js-secao-link' : '' ?>"<?= $superAdminSectionAttributes('suporte') ?><?= $superAdminActiveSection === 'suporte' ? ' aria-current="page"' : '' ?>><i class="fas fa-headset"></i><span>Suporte</span></a></li>
                    <?php endif; ?>
                    <?php if ($superAdminCan['privacy']): ?>
                        <li class="nav-item"><a href="admin_privacidade.php" class="nav-link<?= $superAdminActiveClass('privacidade') ?>"<?= $superAdminActiveSection === 'privacidade' ? ' aria-current="page"' : '' ?>><i class="fas fa-user-shield"></i><span>Privacidade</span><?php if ($superAdminPrivacyPendingCount > 0): ?><span class="sa-nav-count" aria-label="<?= $superAdminPrivacyPendingCount ?> pedidos em aberto"><?= $superAdminPrivacyPendingCount ?></span><?php endif; ?></a></li>
                    <?php endif; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($superAdminCan['security']): ?>
            <div class="sa-nav-group">
                <p class="sa-nav-label">Governa&ccedil;&atilde;o</p>
                <ul class="nav flex-column">
                    <li class="nav-item"><a href="<?= $superAdminSectionHref('seguranca') ?>" class="nav-link<?= $superAdminActiveClass('seguranca') ?><?= $superAdminSectionLinks ? ' js-secao-link' : '' ?>"<?= $superAdminSectionAttributes('seguranca') ?><?= $superAdminActiveSection === 'seguranca' ? ' aria-current="page"' : '' ?>><i class="fas fa-shield-halved"></i><span>Seguran&ccedil;a</span></a></li>
                    <li class="nav-item"><a href="admin_reembolsos.php" class="nav-link<?= $superAdminActiveClass('reembolsos') ?>"<?= $superAdminActiveSection === 'reembolsos' ? ' aria-current="page"' : '' ?>><i class="fas fa-rotate-left"></i><span>Reembolsos</span></a></li>
                    <li class="nav-item"><a href="admin_verificacao_producao.php" class="nav-link<?= $superAdminActiveClass('producao') ?>"<?= $superAdminActiveSection === 'producao' ? ' aria-current="page"' : '' ?>><i class="fas fa-clipboard-check"></i><span>Verifica&ccedil;&atilde;o de produ&ccedil;&atilde;o</span></a></li>
                    <li class="nav-item"><a href="admin_auditoria.php" class="nav-link<?= $superAdminActiveClass('auditoria') ?>"<?= $superAdminActiveSection === 'auditoria' ? ' aria-current="page"' : '' ?>><i class="fas fa-clipboard-list"></i><span>Auditoria</span></a></li>
                </ul>
            </div>
        <?php endif; ?>
    </div>

    <div class="sa-owner-footer">
        <div class="sa-owner-profile">
            <span class="sa-owner-avatar" aria-hidden="true"><?= htmlspecialchars($superAdminOwnerInitial, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="sa-owner-profile-copy"><strong><?= htmlspecialchars($superAdminOwnerName, ENT_QUOTES, 'UTF-8') ?></strong><small>Propriet&aacute;rio</small></span>
        </div>
        <a class="sa-owner-logout" href="logout.php" aria-label="Terminar sessao" title="Terminar sessao"><i class="fas fa-arrow-right-from-bracket"></i></a>
    </div>
</nav>
<script src="js/nivora_dialogo.js?v=<?php echo (int)@filemtime(__DIR__ . '/../js/nivora_dialogo.js'); ?>"></script>
