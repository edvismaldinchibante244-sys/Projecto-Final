<?php
require_once __DIR__ . '/../app_base.php';

/*
   API LEGADA - Aprovar Compra de Plano
   A aprovacao de restaurantes/planos e exclusiva do fundador SaaS (Super Admin).
   Use: /api/super_admin_plano_aprovar.php
 */

require_once APP_BASE . '/src/config/api_guard.php';

header('Content-Type: application/json; charset=utf-8');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    api_guard([
        'rate_key' => 'plano_aprovar_legado',
        'rate_max' => 20,
        'rate_window' => 60,
        'skip_turno' => true,
    ]);
}

http_response_code(403);
echo json_encode([
    "success" => false,
    "message" => "Aprovacao restrita ao Super Admin fundador. Use o painel AdminPro.",
    "code" => "SUPER_ADMIN_REQUIRED",
    "endpoint" => "api/super_admin_plano_aprovar.php"
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
