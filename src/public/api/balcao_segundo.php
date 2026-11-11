<?php
require_once __DIR__ . '/../app_base.php';

/*
  API - Segundo canal de balcão (Empresarial)
  Liga, desliga ou renomeia o canal extra (ex.: "Take-away").
*/

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/restaurante_context.php';
require_once APP_BASE . '/src/config/mesa_display_helper.php';
require_once APP_BASE . '/src/config/balcao_canal.php';

header('Content-Type: application/json');

api_guard([
    'roles' => ['ADMIN'],
    'rate_key' => 'balcao_segundo',
    'rate_max' => 20,
    'rate_window' => 60,
]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

$rid = session_restaurante_contexto_id();
$capabilityId = session_restaurante_capability_id();
if ($rid <= 0) {
    echo json_encode(['success' => false, 'message' => 'Não autenticado']);
    exit;
}

if (balcao2_max_permitido($capabilityId > 0 ? $capabilityId : $rid) < 2) {
    echo json_encode(['success' => false, 'message' => 'O segundo canal de balcão faz parte do plano Empresarial.']);
    exit;
}

$database = new Database();
$db = $database->getConnection();

$acao = strtolower(trim((string)($_POST['acao'] ?? '')));
$nome = trim((string)($_POST['nome'] ?? ''));

try {
    if ($acao === 'ativar' || $acao === 'renomear') {
        $resultado = balcao2_ativar($db, $rid, $nome);
        $resultado['message'] = $acao === 'renomear'
            ? 'Nome do canal actualizado.'
            : 'Segundo canal de balcão activado.';
        echo json_encode($resultado);
        exit;
    }

    if ($acao === 'desativar') {
        echo json_encode(balcao2_desativar($db, $rid) + ['message' => 'Canal desligado. Nada foi apagado.']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Acção inválida']);
} catch (Throwable $e) {
    error_log('[balcao_segundo] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Não foi possível alterar o canal de balcão.']);
}
