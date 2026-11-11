<?php

$checks = [];
$check = static function (string $name, bool $passed) use (&$checks): void {
    $checks[$name] = $passed;
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
};

$permissionMap = [
    'manage_restaurants' => true,
    'manage_users' => true,
    'approve_plans' => true,
    'view_finance' => true,
    'view_dashboard' => true,
    'manage_security' => false,
    'manage_support' => true,
    'manage_privacy' => true,
];

function super_admin_has_permission($permission)
{
    global $permissionMap;
    return !empty($permissionMap[(string)$permission]);
}

function super_admin_has_any_permission(array $permissions)
{
    foreach ($permissions as $permission) {
        if (super_admin_has_permission($permission)) {
            return true;
        }
    }
    return false;
}

$sidebarFile = dirname(__DIR__) . '/src/public/includes/super_admin_sidebar.php';
$cssFile = dirname(__DIR__) . '/src/public/css/super-admin-shell.css';
$check('shared_sidebar_file_exists', is_file($sidebarFile));
$check('shared_sidebar_css_exists', is_file($cssFile));

$superAdminActiveSection = 'privacidade';
$superAdminSectionLinks = true;
$superAdminPrivacyPendingCount = 3;
$superAdminOwnerName = 'Dono da Plataforma';

ob_start();
include $sidebarFile;
$html = (string)ob_get_clean();

$check('renders_enterprise_identity', str_contains($html, 'Nivora Restaurant Admin') && str_contains($html, 'Propriet&aacute;rio da plataforma'));
$check('renders_business_groups', str_contains($html, 'Vis&atilde;o geral') && str_contains($html, 'Opera&ccedil;&atilde;o') && str_contains($html, 'Comercial') && str_contains($html, 'Atendimento'));
$check('renders_active_privacy_state', str_contains($html, 'nav-link active') && str_contains($html, 'aria-current="page"'));
$check('renders_pending_privacy_count', str_contains($html, '3 pedidos em aberto'));
$check('renders_section_navigation_contract', str_contains($html, 'js-secao-link') && str_contains($html, 'data-secao="restaurantes"'));
$check('honors_permission_gates', !str_contains($html, 'Seguran&ccedil;a'));
$check('renders_owner_session_footer', str_contains($html, 'Dono da Plataforma') && str_contains($html, 'logout.php'));
$check('hides_audit_navigation_without_security_permission', !str_contains($html, 'admin_auditoria.php'));

$permissionMap['manage_security'] = true;
$superAdminActiveSection = 'auditoria';
$superAdminSectionLinks = false;
ob_start();
include $sidebarFile;
$auditOwnerHtml = (string)ob_get_clean();
$check('renders_active_audit_navigation_for_security_owner', str_contains($auditOwnerHtml, 'admin_auditoria.php') && str_contains($auditOwnerHtml, 'nav-link active'));

foreach (['admin.php', 'admin_dashboard.php', 'admin_privacidade.php', 'admin_auditoria.php'] as $page) {
    $source = file_get_contents(dirname(__DIR__) . '/src/public/' . $page) ?: '';
    $check($page . '_loads_shared_sidebar', str_contains($source, "includes/super_admin_sidebar.php"));
    $check($page . '_loads_shared_css', str_contains($source, 'css/super-admin-shell.css'));
}

$sharedCss = file_get_contents($cssFile) ?: '';
$dashboardSource = file_get_contents(dirname(__DIR__) . '/src/public/admin_dashboard.php') ?: '';
$privacySource = file_get_contents(dirname(__DIR__) . '/src/public/admin_privacidade.php') ?: '';
$auditSource = file_get_contents(dirname(__DIR__) . '/src/public/admin_auditoria.php') ?: '';
$check('shared_css_has_enterprise_components', str_contains($sharedCss, '.sa-section-heading') && str_contains($sharedCss, '.sa-panel-heading') && str_contains($sharedCss, '.privacy-request'));
$check('pages_render_enterprise_hierarchy', str_contains($dashboardSource, 'dashboard-kpis-title') && str_contains($privacySource, 'privacy-cases-title') && str_contains($auditSource, 'audit-events-title'));

$failed = array_filter($checks, static fn(bool $passed): bool => !$passed);
echo PHP_EOL . (count($checks) - count($failed)) . '/' . count($checks) . ' checks passed.' . PHP_EOL;
exit($failed === [] ? 0 : 1);
