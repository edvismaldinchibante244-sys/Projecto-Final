<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Abrir Caixa
   Arquitetura N-Tier
 */

header('Content-Type: application/json');
require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/turno_helpers.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/restaurante_context.php';
require_once APP_BASE . '/src/config/offline_idempotency.php';
require_once APP_BASE . '/src/Service/TurnoService.php';

api_guard([
    'roles' => ['ADMIN', 'CAIXA'],
    'rate_key' => 'caixa_abrir',
    'rate_max' => 20,
    'rate_window' => 60,
]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['restaurante_id']) || !isset($_SESSION['logado']) || $_SESSION['logado'] !== true) {
        http_response_code(401);
        echo json_encode(array("success" => false, "message" => "Não autenticado"));
        exit;
    }

    $perfil = turno_normalizar_perfil($_SESSION['perfil'] ?? '');
    if (!in_array($perfil, ['ADMIN', 'CAIXA'], true)) {
        http_response_code(403);
        echo json_encode(array("success" => false, "message" => "Sem permissão para abrir o caixa"));
        exit;
    }

    if (empty($_POST['abertura'])) {
        echo json_encode(array("success" => false, "message" => "Valor de abertura não informado"));
        exit;
    }

    $database = new Database();
    $turnoService = new TurnoService($database);
    $turnoRestauranteId = function_exists('session_restaurante_auth_id') ? session_restaurante_auth_id() : (int)$_SESSION['restaurante_id'];
    $turnoAtivo = $turnoService->obterTurnoAtivoUsuario((int)$_SESSION['usuario_id'], $turnoRestauranteId);
    if (!$turnoAtivo) {
        echo json_encode(array("success" => false, "message" => "É necessário iniciar o turno antes de abrir o caixa"));
        exit;
    }

    require_once APP_BASE . '/src/Controller/CaixaController.php';

    $dados = array(
        'restaurante_id' => $_SESSION['restaurante_id'],
        'usuario_id' => $_SESSION['usuario_id'],
        'abertura' => $_POST['abertura']
    );

    $db = $database->getConnection();
    offline_idempotency_prepare($db);
    try {
        $db->beginTransaction();
        $idempotency = offline_idempotency_begin($db, (int)$_SESSION['restaurante_id'], (int)$_SESSION['usuario_id'], 'api/caixa_abrir.php', $_POST);
        offline_idempotency_replay($db, $idempotency);
        $controller = new CaixaController($db);
        $resultado = $controller->abrir($dados);
        if (!empty($resultado['success']) && !empty($resultado['id'])) {
            $turnoService->vincularCaixaAoTurno(
                (int)$_SESSION['restaurante_id'],
                (int)$resultado['id'],
                (int)$turnoAtivo['id'],
                (int)$_SESSION['usuario_id']
            );
        }
        offline_idempotency_complete($db, $idempotency, $resultado);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[caixa_abrir] ' . $e->getMessage());
        $resultado = ['success' => false, 'message' => 'Nao foi possivel abrir o caixa'];
    }
    echo json_encode($resultado);
} else {
    echo json_encode(array("success" => false, "message" => "Método não permitido"));
}

