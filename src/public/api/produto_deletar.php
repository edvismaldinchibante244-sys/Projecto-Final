<?php
require_once __DIR__ . '/../app_base.php';

/**
  API - Excluir Produto
  Arquitetura N-Tier
 */

include_once APP_BASE . '/src/config/api_guard.php';
include_once APP_BASE . '/src/config/audit.php';
include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/restaurante_context.php';

header('Content-Type: application/json');

ini_set('display_errors', '0');

api_guard([
    'roles' => ['ADMIN'],
    'rate_key' => 'produto_deletar',
    'rate_max' => 60,
    'rate_window' => 60,
]);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_POST['id'])) {
            echo json_encode(array("success" => false, "message" => "ID não fornecido"));
            exit;
        }

        $produto_id = (int) $_POST['id'];
        $restaurante_id = session_restaurante_contexto_id();

        require_once APP_BASE . '/src/Controller/ProdutoController.php';
        $controller = new ProdutoController();

        $produto = $controller->buscarPorId($produto_id, $restaurante_id);
        if (!$produto) {
            echo json_encode(array("success" => false, "message" => "Produto não encontrado"));
            exit;
        }

        $resultado = $controller->deletar($produto_id, $restaurante_id);
        if (!empty($resultado['success'])) {
            $databaseAudit = new Database();
            $dbAudit = $databaseAudit->getConnection();
            if ($dbAudit instanceof PDO) {
                audit_log($dbAudit, 'produto_excluido', [
                    'produto_id' => $produto_id,
                    'produto_nome' => (string)($produto['nome'] ?? ''),
                ]);
            }
        }
        echo json_encode($resultado);
    } else {
        echo json_encode(array("success" => false, "message" => "Método não permitido"));
    }
} catch (Exception $e) {
    error_log('produto_deletar: ' . $e->getMessage());
    echo json_encode(array("success" => false, "message" => "Erro interno ao excluir produto"));
}

