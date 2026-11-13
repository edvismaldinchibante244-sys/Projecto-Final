<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Listar Restaurantes
 */

include_once APP_BASE . '/src/config/api_guard.php';
include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/super_admin_permissions.php';
include_once APP_BASE . '/src/config/restaurante_status_helper.php';

header('Content-Type: application/json; charset=utf-8');

function restaurante_listar_json(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($json === false) {
        $json = '{"success":false,"message":"Falha ao serializar resposta JSON."}';
    }
    echo $json;
    exit;
}

api_guard([
    'rate_key' => 'restaurante_listar',
    'rate_max' => 120,
    'rate_window' => 60,
    'skip_turno' => true,
    'skip_csrf' => true,
]);

if (!(isset($_SESSION['logado'], $_SESSION['super_admin'])
    && $_SESSION['logado'] === true
    && intval($_SESSION['super_admin']) === 1)) {
    restaurante_listar_json(['success' => false, 'message' => 'Acesso negado'], 403);
}

super_admin_require_permission_json('manage_restaurants');

$database = new Database();
$db = $database->getConnection();

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
                r.plano,
                r.status,
                {$statusSql['status_exibicao']},
                {$statusSql['possui_compra_pendente']},
                r.data_inicio,
                r.data_fim,
                r.criado_em,
                (SELECT COUNT(*) FROM usuarios WHERE restaurante_id = r.id) as total_usuarios,
                (SELECT COUNT(*) FROM produtos WHERE restaurante_id = r.id) as total_produtos,
                (SELECT COUNT(*) FROM mesas WHERE restaurante_id = r.id) as total_mesas
              FROM restaurantes r
              WHERE r.plataforma = 0
              ORDER BY r.id DESC";

    $stmt = $db->query($query);
    $restaurantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    restaurante_listar_json([
        'success' => true,
        'data' => $restaurantes,
        'total' => count($restaurantes)
    ]);
} catch (Throwable $e) {
    error_log('Erro ao listar restaurantes: ' . $e->getMessage());
    restaurante_listar_json([
        'success' => false,
        'message' => 'Nao foi possivel listar restaurantes.'
    ]);
}
