<?php
require_once __DIR__ . '/app_base.php';
// Pasta publica: as vistas (app/views/dashboard) usam-na para css/js, imagens e includes.
defined('DASHBOARD_PUBLIC_DIR') || define('DASHBOARD_PUBLIC_DIR', __DIR__);
// Proteção da página
include_once APP_BASE . '/src/config/auth_check.php';
include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/csrf.php';
include_once APP_BASE . '/src/config/plano_check.php';
include_once APP_BASE . '/src/config/presenca_online.php';
include_once APP_BASE . '/src/config/restaurante_context.php';
include_once APP_BASE . '/src/config/plano_upgrade_guard.php';
include_once APP_BASE . '/src/config/estoque_inteligente.php';
include_once APP_BASE . '/src/config/support_tickets.php';
include_once APP_BASE . '/src/config/backup_helper.php';
include_once APP_BASE . '/src/config/pedido_schema.php';
include_once APP_BASE . '/src/config/mesa_display_helper.php';
include_once APP_BASE . '/src/Model/Produto.php';
include_once APP_BASE . '/src/Model/Venda.php';
include_once APP_BASE . '/src/Model/Caixa.php';
include_once APP_BASE . '/src/Model/Mesa.php';

// Conectar ao banco
$database = new Database();
$db = $database->getConnection();
include_once APP_BASE . '/src/Service/DashboardService.php';
$dashboardService = new DashboardService($db);

// Preparacao (contexto, plano, permissoes e dados) no mesmo ambito desta pagina.
require APP_BASE . '/src/Controller/dashboard_preparar.php';

// Uma vista por perfil; qualquer outro perfil ve o dashboard do administrador.
if ($isGerenteDashboard) {
    $dashboardVista = 'gerente';
} elseif ($perfil === 'GARCOM') {
    $dashboardVista = 'garcom';
} elseif ($perfil === 'CAIXA') {
    $dashboardVista = 'caixa';
} else {
    $dashboardVista = 'admin';
}
require APP_BASE . '/views/dashboard/layout.php';
