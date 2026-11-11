<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - DELETAR RESTAURANTE
   Remove um restaurante do sistema
*/

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/super_admin_permissions.php';
require_once APP_BASE . '/src/config/audit.php';

header('Content-Type: application/json; charset=utf-8');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    security_json(['success' => false, 'message' => 'Metodo nao permitido'], 405);
}

api_guard([
    'rate_key' => 'restaurante_deletar',
    'rate_max' => 20,
    'rate_window' => 60,
    'skip_turno' => true,
]);

if (!super_admin_is_authenticated()) {
    security_json(['success' => false, 'message' => 'Acesso negado'], 403);
}

super_admin_require_permission_json('manage_restaurants');

$database = new Database();
$db = $database->getConnection();

$input = json_decode((string)file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$id = (int)($input['id'] ?? 0);
if ($id <= 0) {
    security_json(['success' => false, 'message' => 'ID do restaurante invalido'], 422);
}

$check = $db->prepare("SELECT nome FROM restaurantes WHERE id = ?");
$check->execute([$id]);
if ($check->rowCount() === 0) {
    security_json(['success' => false, 'message' => 'Restaurante nao encontrado'], 404);
}

try {
    $db->beginTransaction();

    $db->prepare("
        DELETE iv
        FROM itens_venda iv
        INNER JOIN vendas v ON v.id = iv.venda_id
        WHERE v.restaurante_id = ?
    ")->execute([$id]);

    $db->prepare("
        DELETE ip
        FROM itens_pedido ip
        INNER JOIN pedidos p ON p.id = ip.pedido_id
        WHERE p.restaurante_id = ?
    ")->execute([$id]);

    $db->prepare("
        DELETE pi
        FROM pedido_itens pi
        INNER JOIN pedidos p ON p.id = pi.pedido_id
        WHERE p.restaurante_id = ?
    ")->execute([$id]);

    $db->prepare("
        DELETE ft
        FROM funcionarios_turnos ft
        INNER JOIN usuarios u ON u.id = ft.usuario_id
        WHERE u.restaurante_id = ?
    ")->execute([$id]);

    $db->prepare("DELETE FROM restaurantes WHERE id = ?")->execute([$id]);
    $db->prepare("DELETE FROM usuarios WHERE restaurante_id = ?")->execute([$id]);

    $db->commit();
    security_audit_event('restaurante_deletado', ['restaurante_id' => $id]);
    audit_log($db, 'super_admin_restaurante_excluido', [
        'restaurante_id' => $id,
        'dados_associados_excluidos' => true,
    ], 'WARN');
    security_json([
        'success' => true,
        'message' => 'Restaurante deletado com sucesso! Dados associados, turnos e usuarios vinculados tambem foram removidos.'
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Erro ao deletar restaurante: ' . $e->getMessage());
    security_json(['success' => false, 'message' => 'Nao foi possivel deletar o restaurante.'], 500);
}
