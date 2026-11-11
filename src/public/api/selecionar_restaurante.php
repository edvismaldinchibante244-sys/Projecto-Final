<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - SELECIONAR RESTAURANTE PARA ACESSO
   Define qual restaurante o Super Admin ira acessar
*/

require_once APP_BASE . '/src/config/api_guard.php';

header('Content-Type: application/json; charset=utf-8');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    security_json(['success' => false, 'message' => 'Metodo nao permitido'], 405);
}

$ctx = api_guard([
    'rate_key' => 'selecionar_restaurante',
    'rate_max' => 60,
    'rate_window' => 60,
    'skip_turno' => true,
]);

if (!$ctx['super_admin']) {
    security_json(['success' => false, 'message' => 'Acesso negado'], 403);
}

$restaurante_id = (int)($_POST['restaurante_id'] ?? 0);
if ($restaurante_id > 0) {
    $_SESSION['restaurante_selecionado'] = $restaurante_id;
    $_SESSION['restaurante_id'] = $restaurante_id;
    $_SESSION['restaurante_base_id'] = $restaurante_id;
    unset($_SESSION['matriz_id'], $_SESSION['filial_id'], $_SESSION['filial_nome']);
    security_json(['success' => true, 'message' => 'Restaurante selecionado']);
}

security_json(['success' => false, 'message' => 'ID invalido'], 422);
