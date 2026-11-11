<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Atualizar Estoque
  Arquitetura N-Tier
 */

include_once APP_BASE . '/src/config/api_guard.php';
include_once APP_BASE . '/src/config/restaurante_context.php';
include_once APP_BASE . '/src/config/plano_check.php';

header('Content-Type: application/json');

ini_set('display_errors', '0');

api_guard([
    'roles' => ['ADMIN'],
    'rate_key' => 'produto_estoque',
    'rate_max' => 90,
    'rate_window' => 60,
]);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_POST['id']) || !isset($_POST['quantidade'])) {
            echo json_encode(array("success" => false, "message" => "Dados incompletos"));
            exit;
        }

        require_once APP_BASE . '/src/Controller/ProdutoController.php';
        $ridCapability = function_exists('session_restaurante_capability_id')
            ? (int)session_restaurante_capability_id()
            : (int)session_restaurante_contexto_id();
        $ridCapability = $ridCapability > 0 ? $ridCapability : (int)session_restaurante_contexto_id();
        if (plano_tem_funcionalidade_db($ridCapability, 'estoque_avancado')) {
            echo json_encode([
                "success" => false,
                "message" => "Estoque manual desativado neste plano. Use Estoque Inteligente."
            ]);
            exit;
        }

        $controller = new ProdutoController();

        // Determinar o tipo de operação (entrada ou saída)
        $quantidade = $_POST['quantidade'];
        $tipo = ($quantidade < 0) ? 'SAIDA' : 'ENTRADA';

        $resultado = $controller->atualizarEstoque($_POST['id'], session_restaurante_contexto_id(), abs($quantidade), $tipo);

        echo json_encode($resultado);
    } else {
        echo json_encode(array("success" => false, "message" => "Método não permitido"));
    }
} catch (Exception $e) {
    error_log('produto_estoque: ' . $e->getMessage());
    echo json_encode(array("success" => false, "message" => "Erro interno ao atualizar estoque"));
}

