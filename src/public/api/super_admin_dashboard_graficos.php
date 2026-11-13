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
        WHERE plataforma = 0
        GROUP BY plano
    ");
    $restaurantesPorPlano = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->query("
        SELECT status, COUNT(*) as total
        FROM restaurantes
        WHERE plataforma = 0
        GROUP BY status
    ");
    $restaurantesPorStatus = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->query("
        SELECT
            DATE_FORMAT(criado_em, '%Y-%m') as mes,
            COUNT(*) as total
        FROM restaurantes
        WHERE plataforma = 0 AND criado_em >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(criado_em, '%Y-%m')
        ORDER BY mes
    ");
    $crescimentoRestaurantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Receita por plano, pelos mesmos criterios do cartao de receita: precos
    // do catalogo, desconto de lancamento de cada cliente, e ciclos
    // trimestrais/anuais reduzidos ao equivalente mensal.
    require_once APP_BASE . '/src/config/plano_lancamento.php';

    $stmt = $db->query("
        SELECT plano,
               COALESCE(plano_duracao, 'mensal') AS ciclo,
               COALESCE(desconto_lancamento, 0) AS desconto,
               COUNT(*) AS quantos
        FROM restaurantes
        WHERE plataforma = 0 AND status = 'ATIVO'
        GROUP BY plano, ciclo, desconto
    ");

    $receitaAcumulada = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $grupo) {
        $plano = (string)$grupo['plano'];
        $ciclo = strtoupper((string)$grupo['ciclo']);
        $cobrado = lancamento_preco(lancamento_preco_tabela($plano, $ciclo), (float)$grupo['desconto']);
        $mensal = ($cobrado / lancamento_meses_ciclo($ciclo)) * (int)$grupo['quantos'];
        $receitaAcumulada[$plano] = ($receitaAcumulada[$plano] ?? 0.0) + $mensal;
    }

    $receitaPorPlano = [];
    foreach ($receitaAcumulada as $plano => $receita) {
        $receitaPorPlano[] = ['plano' => $plano, 'receita' => round($receita, 2)];
    }

    $stmt = $db->query("
        SELECT r.nome, COUNT(u.id) as total_usuarios
        FROM restaurantes r
        LEFT JOIN usuarios u ON r.id = u.restaurante_id
        WHERE r.plataforma = 0
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
