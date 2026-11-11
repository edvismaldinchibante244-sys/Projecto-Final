<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Buscar Produto
   Arquitetura N-Tier
*/

include_once APP_BASE . '/src/config/api_guard.php';
include_once APP_BASE . '/src/config/restaurante_context.php';

header('Content-Type: application/json');
ini_set('display_errors', '0');

api_guard([
    'roles' => ['ADMIN'],
    'rate_key' => 'produto_buscar',
    'rate_max' => 120,
    'rate_window' => 60,
]);

try {
    if (empty($_GET['id'])) {
        echo json_encode(array("success" => false, "message" => "ID não fornecido"));
        exit;
    }

    require_once APP_BASE . '/src/Controller/ProdutoController.php';

    $controller = new ProdutoController();
    $data = $controller->buscarPorId($_GET['id'], session_restaurante_contexto_id());

    if ($data) {
        $img = trim((string)($data['imagem'] ?? ''));
        if ($img !== '') {
            $img = str_replace('\\', '/', $img);
            $img = preg_replace('#^(\./)+#', '', $img);
            $img = str_replace('src/public/', '', $img);
            $img = ltrim($img, '/');
            if (strpos($img, 'images/') !== 0 && preg_match('/\.(png|jpe?g|webp|gif)$/i', $img)) {
                $img = 'images/' . $img;
            }
            $full = APP_BASE . '/../src/public/' . $img;
            $data['imagem'] = is_file($full) ? $img : '';
        } else {
            $data['imagem'] = '';
        }
        echo json_encode(array("success" => true, "data" => $data));
    } else {
        echo json_encode(array("success" => false, "message" => "Produto não encontrado"));
    }
} catch (Exception $e) {
    error_log("produto_buscar.php - Exception: " . $e->getMessage());
    echo json_encode(array("success" => false, "message" => "Erro interno ao buscar produto"));
}

