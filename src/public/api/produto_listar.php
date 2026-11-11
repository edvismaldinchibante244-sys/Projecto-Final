<?php
require_once __DIR__ . '/../app_base.php';

/**
 * API - Listar Produtos por Categoria/Busca
 * Suporta: ?categoria_id=ID&busca=termo&restaurante_id=ID
 */

include_once APP_BASE . '/src/config/api_guard.php';
include_once APP_BASE . '/src/config/restaurante_context.php';

if (!function_exists('produto_listar_debug_log')) {
    function produto_listar_debug_log(string $message): void
    {
        $logDir = sys_get_temp_dir();
        if (!$logDir || !is_dir($logDir) || !is_writable($logDir)) {
            return;
        }

        $logFile = rtrim($logDir, "/\\") . DIRECTORY_SEPARATOR . 'debug_produto_api.log';
        @file_put_contents(
            $logFile,
            date('Y-m-d H:i:s') . ' | ' . $message . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
}

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');

api_guard([
    'roles' => ['ADMIN', 'GERENTE'],
    'rate_key' => 'produto_listar',
    'rate_max' => 120,
    'rate_window' => 60,
]);

try {
    $restaurante_id = session_restaurante_contexto_id();
    $categoria_id = isset($_GET['categoria_id']) && $_GET['categoria_id'] !== '' ? $_GET['categoria_id'] : null;
    $busca = $_GET['busca'] ?? '';
    produto_listar_debug_log("restaurante_id=" . var_export($restaurante_id, true) . " | categoria_id=" . var_export($categoria_id, true) . " | busca=" . var_export($busca, true));
    if (!$restaurante_id) {
        echo json_encode(["success" => false, "message" => "Restaurante não informado"]);
        exit;
    }

    include_once APP_BASE . '/src/config/database.php';
    include_once APP_BASE . '/src/Model/Produto.php';
    include_once APP_BASE . '/src/Model/Categoria.php';

    $database = new Database();
    $db = $database->getConnection();

    $produto = new Produto($db);
    $produto->restaurante_id = $restaurante_id;
    // Listar com filtros
    $produtos = $produto->listarFiltrado($categoria_id, $busca);
    $produtos_array = $produtos->fetchAll(PDO::FETCH_ASSOC);

    $normalizarImagemProduto = static function ($imagem) {
        $img = trim((string)$imagem);
        if ($img === '') {
            return '';
        }

        $img = str_replace('\\', '/', $img);
        $img = preg_replace('#^(\./)+#', '', $img);
        $img = str_replace('src/public/', '', $img);
        $img = ltrim($img, '/');

        // Registros legados podem vir só com o nome do arquivo.
        if (strpos($img, 'images/') !== 0 && preg_match('/\.(png|jpe?g|webp|gif)$/i', $img)) {
            $img = 'images/' . $img;
        }

        $arquivo = APP_BASE . '/../src/public/' . $img;
        if (!is_file($arquivo)) {
            return '';
        }

        return $img;
    };

    foreach ($produtos_array as &$p) {
        $p['imagem'] = $normalizarImagemProduto($p['imagem'] ?? '');
    }
    unset($p);
    produto_listar_debug_log('produtos retornados=' . count($produtos_array));
    echo json_encode([
        "success" => true,
        "data" => $produtos_array,
        "filtros" => [
            "categoria_id" => $categoria_id,
            "busca" => $busca
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    $logMsg = date('Y-m-d H:i:s') . " | ERRO: " . $e->getMessage() . "\n";
    $logMsg .= $e->getTraceAsString() . "\n";
    produto_listar_debug_log(trim($logMsg));
    error_log("produto_listar.php - " . $e->getMessage());
    echo json_encode([
        "success" => false,
        "message" => "Erro interno ao listar produtos"
    ], JSON_UNESCAPED_UNICODE);
}

