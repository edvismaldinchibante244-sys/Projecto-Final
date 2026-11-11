<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Super Admin Listar Usuarios
 */

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/super_admin_permissions.php';
require_once APP_BASE . '/src/config/turno_schema.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

api_guard([
    'rate_key' => 'super_admin_usuarios_listar',
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

super_admin_require_permission_json('manage_users');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $restaurante_id = intval($_GET['restaurante_id'] ?? 0);

    // Sem restaurante: lista todos (o painel abre com a lista completa e filtra no navegador).
    $todos = $restaurante_id <= 0;

    $database = new Database();
    $db = $database->getConnection();

    if (!$db) {
        echo json_encode(["success" => false, "message" => "Erro de conexao"]);
        exit;
    }

    turno_schema_garantir($db);

    $stmt = $db->prepare("
        SELECT u.id, u.nome, u.email, u.perfil, u.ativo, u.foto, u.restaurante_id, r.nome AS restaurante_nome
        FROM usuarios u
        LEFT JOIN restaurantes r ON r.id = u.restaurante_id
        WHERE " . ($todos ? "COALESCE(u.super_admin, 0) = 0" : "u.restaurante_id = ?") . "
        ORDER BY " . ($todos ? "u.id DESC LIMIT 2000" : "u.nome") . "
    ");
    $stmt->execute($todos ? [] : [$restaurante_id]);
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $turnosAtivos = [];
    $stmtTurnos = $db->prepare("
        SELECT id, usuario_id, turno, hora_entrada
        FROM funcionarios_turnos
        WHERE " . ($todos ? "1 = 1" : "restaurante_id = ?") . "
          AND status = 'ATIVO'
        ORDER BY id DESC
    ");
    $stmtTurnos->execute($todos ? [] : [$restaurante_id]);
    while ($t = $stmtTurnos->fetch(PDO::FETCH_ASSOC)) {
        $uid = (int)$t['usuario_id'];
        if (!isset($turnosAtivos[$uid])) {
            $turnosAtivos[$uid] = $t;
        }
    }

    foreach ($usuarios as &$u) {
        $uid = (int)($u['id'] ?? 0);
        $turno = $turnosAtivos[$uid] ?? null;
        $u['turno_ativo'] = $turno ? 1 : 0;
        $u['turno_id'] = $turno ? (int)$turno['id'] : null;
        $u['turno_tipo'] = $turno['turno'] ?? null;
        $u['turno_hora_entrada'] = $turno['hora_entrada'] ?? null;
    }
    unset($u);

    echo json_encode([
        "success" => true,
        "data" => $usuarios
    ]);
} else {
    echo json_encode(["success" => false, "message" => "Metodo nao permitido"]);
}
