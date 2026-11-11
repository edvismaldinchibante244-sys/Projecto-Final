<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Super Admin Listar Compras de Planos
 */

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/super_admin_permissions.php';

header('Content-Type: application/json');

api_guard([
    'rate_key' => 'super_admin_compras_listar',
    'rate_max' => 120,
    'rate_window' => 60,
    'skip_turno' => true,
    'skip_csrf' => true,
]);

if (!(isset($_SESSION['logado'], $_SESSION['super_admin'])
    && $_SESSION['logado'] === true
    && intval($_SESSION['super_admin']) === 1)) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Acesso negado"]);
    exit;
}

super_admin_require_any_permission_json(['view_finance', 'approve_plans']);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $database = new Database();
    $db = $database->getConnection();

    if (!$db) {
        echo json_encode(["success" => false, "message" => "Erro de conexao"]);
        exit;
    }

    $colunasCompra = [];
    $stmtCols = $db->query('SHOW COLUMNS FROM compras_planos');
    while ($col = $stmtCols->fetch(PDO::FETCH_ASSOC)) {
        $colunasCompra[] = $col['Field'];
    }

    $colData = in_array('criado_em', $colunasCompra, true) ? 'criado_em' : 'created_at';

    $stmt = $db->query("
        SELECT cp.*, cp." . $colData . " AS data_compra, r.nome as restaurante_nome, r.email as restaurante_email,
               r.documento_titularidade_path
        FROM compras_planos cp
        INNER JOIN restaurantes r ON cp.restaurante_id = r.id
        ORDER BY cp." . $colData . " DESC
    ");
    $compras = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($compras as &$compra) {
        if (!empty($compra['comprovativo_path'])) {
            $compra['comprovativo_path_original'] = $compra['comprovativo_path'];
            $compra['comprovativo_path'] = 'comprovativo_arquivo.php?path=' . rawurlencode((string)$compra['comprovativo_path']);
        }
        if (!empty($compra['documento_titularidade_path'])) {
            $compra['documento_titularidade_path_original'] = $compra['documento_titularidade_path'];
            $compra['documento_titularidade_path'] = 'comprovativo_arquivo.php?path=' . rawurlencode((string)$compra['documento_titularidade_path']);
        }
    }
    unset($compra);

    echo json_encode([
        "success" => true,
        "data" => $compras
    ]);
} else {
    echo json_encode(["success" => false, "message" => "Metodo nao permitido"]);
}
