<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Editar Produto
   Arquitetura N-Tier
 */

include_once APP_BASE . '/src/config/api_guard.php';
include_once APP_BASE . '/src/config/restaurante_context.php';

header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', '0');

api_guard([
    'roles' => ['ADMIN'],
    'rate_key' => 'produto_editar',
    'rate_max' => 60,
    'rate_window' => 60,
]);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(["success" => false, "message" => "Método não permitido"]);
        exit;
    }

    $contentLen = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLen > 0 && empty($_POST) && empty($_FILES)) {
        echo json_encode([
            "success" => false,
            "message" => "A imagem é demasiado grande. Use um ficheiro até 5–8 MB."
        ]);
        exit;
    }

    if (empty($_POST['produto_id']) || empty($_POST['nome']) || !isset($_POST['preco']) || $_POST['preco'] === '') {
        echo json_encode(["success" => false, "message" => "Preencha todos os campos obrigatórios"]);
        exit;
    }

    require_once APP_BASE . '/src/Controller/ProdutoController.php';
    require_once APP_BASE . '/src/config/catalogo_nome_helper.php';

    $nomeNorm = catalogo_nome_normalizar((string)$_POST['nome']);
    $controller = new ProdutoController();

    $imgExisting = trim((string)($_POST['imagem_existing'] ?? ''));
    if ($imgExisting !== '') {
        $imgExisting = str_replace('\\', '/', $imgExisting);
        $imgExisting = preg_replace('#^https?://[^/]+/#i', '', $imgExisting);
        $imgExisting = str_replace('src/public/', '', $imgExisting);
        $imgExisting = ltrim($imgExisting, '/');
    }

    $dados = [
        'id' => (int)$_POST['produto_id'],
        'restaurante_id' => session_restaurante_contexto_id(),
        'categoria_id' => !empty($_POST['categoria_id']) ? $_POST['categoria_id'] : null,
        'nome' => $nomeNorm['nome'],
        'descricao' => $_POST['descricao'] ?? '',
        'preco' => $_POST['preco'],
        'custo' => $_POST['custo'] ?? 0,
        'estoque' => $_POST['estoque'] ?? 0,
        'estoque_minimo' => $_POST['estoque_minimo'] ?? 5,
        'ativo' => ((string)($_POST['ativo'] ?? '0') === '1' || (string)($_POST['ativo'] ?? '') === 'on') ? 1 : 0,
        'destaque' => ((string)($_POST['destaque'] ?? '0') === '1' || (string)($_POST['destaque'] ?? '') === 'on') ? 1 : 0,
        'imagem' => $imgExisting !== '' ? $imgExisting : null,
    ];

    $resultado = $controller->editar($dados);
    if (!empty($resultado['success']) && !empty($nomeNorm['alterado'])) {
        $resultado['nome_corrigido'] = $nomeNorm['nome'];
        $resultado['message'] = trim(($resultado['message'] ?? 'Produto atualizado.') . ' Nome ajustado: ' . $nomeNorm['nome']);
    }
    echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('produto_editar: ' . $e->getMessage());
    echo json_encode(["success" => false, "message" => "Erro interno ao editar produto"], JSON_UNESCAPED_UNICODE);
}
