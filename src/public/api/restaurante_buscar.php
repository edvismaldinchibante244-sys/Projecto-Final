<?php
require_once __DIR__ . '/../app_base.php';

/*
  API - Buscar Restaurante por ID
 */

include_once APP_BASE . '/src/config/api_guard.php';
include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/super_admin_permissions.php';
include_once APP_BASE . '/src/config/restaurante_status_helper.php';

header('Content-Type: application/json; charset=utf-8');

api_guard([
    'rate_key' => 'restaurante_buscar',
    'rate_max' => 120,
    'rate_window' => 60,
    'skip_turno' => true,
    'skip_csrf' => true,
]);

if (!(isset($_SESSION['logado'], $_SESSION['super_admin'])
    && $_SESSION['logado'] === true
    && intval($_SESSION['super_admin']) === 1)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acesso negado']);
    exit;
}

super_admin_require_permission_json('manage_restaurants');

$database = new Database();
$db = $database->getConnection();

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'ID do restaurante invalido'
    ]);
    exit;
}

try {
    $statusSql = restaurante_status_sql_campos($db, 'r');

    $query = "SELECT
                r.id,
                r.nome,
                r.email,
                r.telefone,
                r.endereco,
                r.cidade,
                r.nuit,
                r.logo,
                r.plano,
                r.status,
                {$statusSql['status_exibicao']},
                {$statusSql['possui_compra_pendente']},
                r.data_inicio,
                r.data_fim,
                r.criado_em,
                r.atualizado_em,
                (SELECT COUNT(*) FROM usuarios WHERE restaurante_id = r.id) as total_usuarios,
                (SELECT COUNT(*) FROM produtos WHERE restaurante_id = r.id) as total_produtos,
                (SELECT COUNT(*) FROM mesas WHERE restaurante_id = r.id) as total_mesas,
                (SELECT COUNT(*) FROM vendas WHERE restaurante_id = r.id) as total_vendas
              FROM restaurantes r
              WHERE r.id = :id";

    $stmt = $db->prepare($query);
    $stmt->execute([':id' => $id]);
    $restaurante = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($restaurante) {
        echo json_encode([
            'success' => true,
            'data' => $restaurante
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Restaurante nao encontrado'
        ]);
    }
} catch (PDOException $e) {
    error_log('Erro ao buscar restaurante: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Nao foi possivel buscar o restaurante.'
    ]);
}
