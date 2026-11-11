<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Cadastrar Categoria / Subcategoria
   Arquitetura N-Tier
*/

include_once APP_BASE . '/src/config/api_guard.php';
include_once APP_BASE . '/src/config/plano_check.php';
include_once APP_BASE . '/src/config/restaurante_context.php';
include_once APP_BASE . '/src/config/database.php';

header('Content-Type: application/json');

ini_set('display_errors', '0');

$ctx = api_guard([
    'roles' => ['ADMIN'],
    'rate_key' => 'categoria_cadastrar',
    'rate_max' => 60,
    'rate_window' => 60,
]);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(["success" => false, "message" => "Método não permitido"]);
        exit;
    }

    $nome = trim((string)($_POST['nome'] ?? ''));
    if ($nome === '') {
        echo json_encode(["success" => false, "message" => "Digite o nome da categoria"]);
        exit;
    }
    if (function_exists('mb_strlen') ? mb_strlen($nome) < 2 : strlen($nome) < 2) {
        echo json_encode(["success" => false, "message" => "O nome deve ter pelo menos 2 caracteres"]);
        exit;
    }

    require_once APP_BASE . '/src/config/catalogo_nome_helper.php';
    $nomeNorm = catalogo_nome_normalizar($nome);
    $nome = $nomeNorm['nome'];

    $restauranteId = (int)($ctx['restaurante_id'] ?? session_restaurante_contexto_id());
    $planoRid = function_exists('session_restaurante_capability_id')
        ? (int)session_restaurante_capability_id()
        : $restauranteId;
    $planoRid = $planoRid > 0 ? $planoRid : $restauranteId;

    $parentId = (int)($_POST['parent_id'] ?? 0);
    $isSubcategoria = $parentId > 0;

    require_once APP_BASE . '/src/Controller/CategoriaController.php';
    require_once APP_BASE . '/src/Service/CategoriaService.php';

    $service = new CategoriaService();
    $parentNome = '';

    if ($isSubcategoria) {
        if (!plano_tem_funcionalidade_db($planoRid, 'subcategorias')) {
            echo json_encode([
                "success" => false,
                "message" => "Subcategorias disponíveis a partir do plano Profissional.",
                "funcionalidade" => "subcategorias"
            ]);
            exit;
        }

        $db = (new Database())->getConnection();
        if (is_file(APP_BASE . '/src/config/categoria_schema.php')) {
            require_once APP_BASE . '/src/config/categoria_schema.php';
            if (function_exists('categoria_schema_garantir')) {
                categoria_schema_garantir($db);
            }
        }

        $stmtParent = $db->prepare("
            SELECT id, nome, parent_id
            FROM categorias
            WHERE id = :id AND restaurante_id = :rid AND COALESCE(ativo, 1) = 1
            LIMIT 1
        ");
        $stmtParent->execute([':id' => $parentId, ':rid' => $restauranteId]);
        $parent = $stmtParent->fetch(PDO::FETCH_ASSOC);
        if (!$parent) {
            echo json_encode(["success" => false, "message" => "Categoria pai inválida."]);
            exit;
        }
        if (!empty($parent['parent_id'])) {
            echo json_encode(["success" => false, "message" => "Só é permitido criar subcategoria sob uma categoria principal."]);
            exit;
        }
        $parentNome = (string)$parent['nome'];

        $qtdSub = $service->contar($restauranteId, true);
        $limiteSub = plano_verificar_limite_subcategorias($planoRid, $qtdSub);
        if (empty($limiteSub['permitido'])) {
            echo json_encode([
                "success" => false,
                "message" => "Limite de subcategorias atingido ({$limiteSub['limite']}) no plano {$limiteSub['plano']}."
            ]);
            exit;
        }
    } else {
        $qtdCat = $service->contar($restauranteId, false);
        $limiteCat = plano_verificar_limite_categorias($planoRid, $qtdCat);
        if (empty($limiteCat['permitido'])) {
            echo json_encode([
                "success" => false,
                "message" => "Limite de categorias atingido ({$limiteCat['limite']}) no plano {$limiteCat['plano']}."
            ]);
            exit;
        }
    }

    $controller = new CategoriaController();
    $dados = [
        'restaurante_id' => $restauranteId,
        'nome' => $nome,
        'descricao' => (string)($_POST['descricao'] ?? ''),
        'ativo' => 1,
        'parent_id' => $isSubcategoria ? $parentId : null,
        'parent_nome' => $parentNome,
    ];

    $resultado = $controller->cadastrar($dados);
    if (!empty($resultado['success']) && !empty($nomeNorm['alterado'])) {
        $resultado['nome_corrigido'] = $nome;
        $resultado['message'] = trim(($resultado['message'] ?? 'Categoria cadastrada.') . ' Nome ajustado: ' . $nome);
    }
    echo json_encode($resultado);
} catch (Exception $e) {
    error_log('categoria_cadastrar: ' . $e->getMessage());
    echo json_encode(["success" => false, "message" => "Erro interno ao cadastrar categoria"]);
}
