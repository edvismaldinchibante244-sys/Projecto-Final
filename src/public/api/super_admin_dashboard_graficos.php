<?php
require_once __DIR__ . '/../app_base.php';

/*
  API - Super Admin Dashboard Graficos
 */

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/super_admin_permissions.php';

header('Content-Type: application/json');

api_guard([
    'rate_key' => 'super_admin_dashboard_graficos',
    'rate_max' => 120,
    'rate_window' => 60,
    'skip_turno' => true,
    'skip_csrf' => true,
]);

if (!isset($_SESSION['super_admin']) || $_SESSION['super_admin'] != 1) {
    echo json_encode(["success" => false, "message" => "Acesso negado"]);
    exit;
}

super_admin_require_permission_json('view_dashboard');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $database = new Database();
    $db = $database->getConnection();

    if (!$db) {
        echo json_encode(["success" => false, "message" => "Erro de conexao"]);
        exit;
    }

    $stmt = $db->query("
        SELECT plano, COUNT(*) as total
        FROM restaurantes
        GROUP BY plano
    ");
    $restaurantesPorPlano = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->query("
        SELECT status, COUNT(*) as total
        FROM restaurantes
        GROUP BY status
    ");
    $restaurantesPorStatus = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->query("
        SELECT
            DATE_FORMAT(criado_em, '%Y-%m') as mes,
            COUNT(*) as total
        FROM restaurantes
        WHERE criado_em >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(criado_em, '%Y-%m')
        ORDER BY mes
    ");
    $crescimentoRestaurantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->query("
        SELECT
            plano,
            SUM(CASE
                    WHEN plano IN ('ENTERPRISE', 'EMPRESARIAL') THEN 3999
                    WHEN plano = 'PROFISSIONAL' THEN 1999
                    WHEN plano = 'BASICO' THEN 799
                    ELSE 799
                END) as receita
        FROM restaurantes
        WHERE status = 'ATIVO'
        GROUP BY plano
    ");
    $receitaPorPlano = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->query("
        SELECT r.nome, COUNT(u.id) as total_usuarios
        FROM restaurantes r
        LEFT JOIN usuarios u ON r.id = u.restaurante_id
        GROUP BY r.id, r.nome
        ORDER BY total_usuarios DESC
        LIMIT 5
    ");
    $topRestaurantesUsuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->query("
        SELECT
            DATE_FORMAT(criado_em, '%Y-%m') as mes,
            COUNT(*) as total,
            SUM(CASE WHEN status = 'APROVADO' THEN 1 ELSE 0 END) as aprovados
        FROM compras_planos
        WHERE criado_em >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(criado_em, '%Y-%m')
        ORDER BY mes
    ");
    $comprasPorMes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "data" => [
            "restaurantes_por_plano" => $restaurantesPorPlano,
            "restaurantes_por_status" => $restaurantesPorStatus,
            "crescimento_restaurantes" => $crescimentoRestaurantes,
            "receita_por_plano" => $receitaPorPlano,
            "top_restaurantes_usuarios" => $topRestaurantesUsuarios,
            "compras_por_mes" => $comprasPorMes
        ]
    ]);
} else {
    echo json_encode(["success" => false, "message" => "Metodo nao permitido"]);
}
