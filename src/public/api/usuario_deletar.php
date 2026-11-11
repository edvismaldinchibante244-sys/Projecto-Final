<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Inativar Usuario
 */

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/audit.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/restaurante_context.php';

header('Content-Type: application/json');

api_guard([
    'roles' => ['ADMIN'],
    'rate_key' => 'usuario_deletar',
    'rate_max' => 30,
    'rate_window' => 60,
    'skip_turno' => true,
]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $restauranteId = session_restaurante_contexto_id();
    $perfil = strtoupper(trim((string)($_SESSION['perfil'] ?? '')));

    if ($restauranteId <= 0) {
        error_log('usuario_deletar: restaurante_id invalido. restaurante_id=' . $restauranteId . ', session=' . json_encode(['restaurante_id' => $_SESSION['restaurante_id'] ?? null, 'usuario_id' => $_SESSION['usuario_id'] ?? null]));
        echo json_encode(array("success" => false, "message" => "Contexto de restaurante invalido"));
        exit;
    }

    if ($perfil !== 'ADMIN') {
        error_log('usuario_deletar: perfil invalido. perfil=' . $perfil . ', requerido=ADMIN');
        echo json_encode(array("success" => false, "message" => "Sem permissao"));
        exit;
    }

    if (empty($_POST['id'])) {
        echo json_encode(array("success" => false, "message" => "ID nao fornecido"));
        exit;
    }

    $id = intval($_POST['id']);

    if ($id === intval($_SESSION['usuario_id'])) {
        echo json_encode(array("success" => false, "message" => "Voce nao pode excluir sua propria conta"));
        exit;
    }

    $database = new Database();
    $db = $database->getConnection();

    $temColunaSuperAdmin = false;
    try {
        $colStmt = $db->query("SHOW COLUMNS FROM usuarios LIKE 'super_admin'");
        $temColunaSuperAdmin = $colStmt && $colStmt->fetch(PDO::FETCH_ASSOC) ? true : false;
    } catch (Throwable $e) {
        $temColunaSuperAdmin = false;
    }

    $usuario = resource_require_usuario(
        $db,
        $id,
        $restauranteId,
        $temColunaSuperAdmin
            ? ['id', 'nome', 'email', 'perfil', 'super_admin']
            : ['id', 'nome', 'email', 'perfil']
    );

    if ($temColunaSuperAdmin && (int)($usuario['super_admin'] ?? 0) === 1) {
        echo json_encode(array("success" => false, "message" => "Super Admin/Fundador nao pode ser removido por este modulo"));
        exit;
    }

    // Utilizadores nao sao apagados fisicamente: turnos, vendas e auditoria
    // precisam de continuar ligados ao autor original.
    $query = "UPDATE usuarios
              SET ativo = 0,
                  tentativas_login = 0,
                  bloqueado_ate = NULL
              WHERE id = :id AND restaurante_id = :rid AND ativo = 1";
    if ($temColunaSuperAdmin) {
        $query .= " AND COALESCE(super_admin, 0) = 0";
    }
    
    // Deletar dados associados ao usuario (cascade delete reverso)
    try {
    } catch (Throwable $e) {
        error_log('usuario_deletar: erro ao preparar a inativacao: ' . $e->getMessage());
    }

    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->bindValue(':rid', $restauranteId, PDO::PARAM_INT);

    if ($stmt->execute()) {
        $rows_deleted = $stmt->rowCount();
        if ($rows_deleted > 0) {
            audit_log($db, 'usuario_excluido', [
                'usuario_alvo_id' => $id,
                'usuario_alvo_email' => (string)($usuario['email'] ?? ''),
                'perfil_alvo' => (string)($usuario['perfil'] ?? ''),
            ]);
            echo json_encode(array("success" => true, "message" => "Usuario inativado com sucesso!"));
        } else {
            echo json_encode(array("success" => false, "message" => "Usuario nao encontrado ou ja foi deletado"));
        }
    } else {
        error_log('usuario_deletar: erro ao executar DELETE. ' . json_encode($stmt->errorInfo()));
        echo json_encode(array("success" => false, "message" => "Erro ao excluir usuario: " . $stmt->errorInfo()[2]));
    }
} else {
    echo json_encode(array("success" => false, "message" => "Metodo nao permitido"));
}
