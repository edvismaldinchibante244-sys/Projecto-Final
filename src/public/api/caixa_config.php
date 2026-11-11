<?php
require_once __DIR__ . '/../app_base.php';

/* API - Opcoes do caixa (fecho as cegas). Só administrador ou gerente. */

header('Content-Type: application/json');
require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/restaurante_context.php';

api_guard([
    'roles' => ['ADMIN', 'GERENTE'],
    'rate_key' => 'caixa_config',
    'rate_max' => 20,
    'rate_window' => 60,
]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

$rid = (int)($_SESSION['restaurante_id'] ?? 0);
$cego = !empty($_POST['fecho_cego']) && $_POST['fecho_cego'] !== '0' ? 1 : 0;
try {
    $db = (new Database())->getConnection();
    $st = $db->prepare("UPDATE restaurantes SET caixa_fecho_cego = ? WHERE id = ?");
    $st->execute([$cego, $rid]);
    if (function_exists('audit_log')) {
        @audit_log(null, 'caixa_fecho_cego', ['ativo' => $cego]);
    }
    echo json_encode(['success' => true, 'fecho_cego' => $cego,
        'message' => $cego ? 'Fecho às cegas ligado.' : 'Fecho às cegas desligado.']);
} catch (Throwable $e) {
    error_log('Erro caixa_config: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Falta aplicar a migração 20261108_1900_caixa_movimentos.']);
}
