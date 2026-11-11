<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Super Admin Listar Compras Pendentes
 */

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/super_admin_permissions.php';

header('Content-Type: application/json');

api_guard([
    'rate_key' => 'super_admin_compras_pendentes',
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

super_admin_require_permission_json('view_finance');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $database = new Database();
    $db = $database->getConnection();

    if (!$db) {
        echo json_encode(["success" => false, "message" => "Erro de conexao"]);
        exit;
    }

    $stmt = $db->query("
        SELECT cp.*, r.nome as restaurante_nome, r.email as restaurante_email
        FROM compras_planos cp
        INNER JOIN restaurantes r ON cp.restaurante_id = r.id
        WHERE cp.status IN ('PENDENTE', 'AGUARDANDO_APROVACAO')
        ORDER BY cp.criado_em DESC
    ");
    $compras = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($compras as &$compra) {
        if (!empty($compra['comprovativo_path'])) {
            $compra['comprovativo_path_original'] = $compra['comprovativo_path'];
            $compra['comprovativo_path'] = 'comprovativo_arquivo.php?path=' . rawurlencode((string)$compra['comprovativo_path']);
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
