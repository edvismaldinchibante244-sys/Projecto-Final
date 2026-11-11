<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Atualizar Status da Mesa
   Arquitetura N-Tier
 */

try {
    require_once APP_BASE . '/src/config/api_guard.php';
    require_once APP_BASE . '/src/config/restaurante_context.php';

    header('Content-Type: application/json');

    api_guard([
        'roles' => ['ADMIN', 'GERENTE'],
        'rate_key' => 'mesa_atualizar',
        'rate_max' => 120,
        'rate_window' => 60,
    ]);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $restauranteId = session_restaurante_contexto_id();
        if ($restauranteId <= 0) {
            echo json_encode(array("success" => false, "message" => "Não autenticado"));
            exit;
        }

        if (empty($_POST['id']) || empty($_POST['status'])) {
            echo json_encode(array("success" => false, "message" => "Dados incompletos"));
            exit;
        }

        $status_validos = ['LIVRE', 'OCUPADA', 'RESERVADA'];
        if (!in_array($_POST['status'], $status_validos)) {
            echo json_encode(array("success" => false, "message" => "Status inválido"));
            exit;
        }

        require_once APP_BASE . '/src/Controller/MesaController.php';
        require_once APP_BASE . '/src/config/database.php';
        require_once APP_BASE . '/src/config/reserva_qr_schema.php';

        $db = (new Database())->getConnection();
        $mesaId = intval($_POST['id']);
        resource_require_mesa($db, $mesaId, $restauranteId, ['id']);

        $controller = new MesaController();
        $resultado = $controller->atualizarStatus($mesaId, $restauranteId, $_POST['status']);

        if ($resultado['success']) {
            $labels = ['LIVRE' => 'liberada', 'OCUPADA' => 'ocupada', 'RESERVADA' => 'reservada'];
            $resultado['message'] = "Mesa " . ($labels[$_POST['status']] ?? $_POST['status']) . " com sucesso!";
            if (strtoupper((string)$_POST['status']) === 'LIVRE') {
                try {
                    reserva_concluir_por_mesa($db, (int)$restauranteId, $mesaId);
                } catch (Throwable $e) {
                    // Não bloquear liberação da mesa.
                }
            }
        }

        echo json_encode($resultado);
    } else {
        echo json_encode(array("success" => false, "message" => "Método não permitido"));
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erro interno do servidor: ' . $e->getMessage()
    ]);
}

