<?php
/**
 * Remove um item de um pedido ja enviado (cozinha/bar).
 * ADMIN/GERENTE removem diretamente; garcom/caixa precisam da autorizacao de um gerente.
 * O total do pedido e recalculado; a remocao fica na auditoria (quem pediu e quem autorizou).
 */
require_once __DIR__ . '/../app_base.php';
require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/audit.php';
require_once APP_BASE . '/src/config/autorizacao_gerente.php';

header('Content-Type: application/json; charset=utf-8');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    security_json(['success' => false, 'message' => 'Método não permitido.'], 405);
}
$ctx = api_guard([
    'roles' => ['ADMIN', 'GERENTE', 'GARCOM', 'CAIXA'],
    'rate_key' => 'pedido_item_remover',
    'rate_max' => 30,
    'rate_window' => 60,
]);
$rid = (int)$ctx['restaurante_id'];
$itemId = (int)($_POST['item_id'] ?? 0);
if ($itemId <= 0) {
    $json = json_decode((string)file_get_contents('php://input'), true);
    $itemId = (int)($json['item_id'] ?? 0);
}
if ($itemId <= 0) {
    security_json(['success' => false, 'message' => 'Item inválido.'], 422);
}

try {
    $db = (new Database())->getConnection();
    $stmt = $db->prepare("
        SELECT ip.id, ip.pedido_id, ip.produto_nome, ip.quantidade, ip.subtotal, p.status, p.numero_pedido
        FROM itens_pedido ip
        INNER JOIN pedidos p ON p.id = ip.pedido_id
        WHERE ip.id = ? AND p.restaurante_id = ?
    ");
    $stmt->execute([$itemId, $rid]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        security_json(['success' => false, 'message' => 'Item não encontrado.'], 404);
    }
    if (in_array(strtoupper((string)$item['status']), ['PAGO', 'CANCELADO'], true)) {
        security_json(['success' => false, 'message' => 'Pedido já encerrado: não é possível remover itens.'], 409);
    }
    $restantes = (int)$db->query('SELECT COUNT(*) FROM itens_pedido WHERE pedido_id = ' . (int)$item['pedido_id'])->fetchColumn();
    if ($restantes <= 1) {
        security_json(['success' => false, 'message' => 'É o único item do pedido: cancele o pedido em vez de remover o item.'], 409);
    }

    $aut = autorizacao_gerente_exigir(
        'remover_item',
        $itemId,
        sprintf('%dx %s (%s MZN) do pedido #%s', (int)$item['quantidade'], (string)$item['produto_nome'],
            number_format((float)$item['subtotal'], 2, ',', '.'), (string)($item['numero_pedido'] ?: $item['pedido_id']))
    );

    $db->beginTransaction();
    $lock = $db->prepare('SELECT status FROM pedidos WHERE id = ? AND restaurante_id = ? FOR UPDATE');
    $lock->execute([(int)$item['pedido_id'], $rid]);
    if (in_array(strtoupper((string)$lock->fetchColumn()), ['PAGO', 'CANCELADO'], true)) {
        $db->rollBack();
        security_json(['success' => false, 'message' => 'Pedido já encerrado.'], 409);
    }
    $db->prepare('DELETE FROM itens_pedido WHERE id = ?')->execute([$itemId]);
    $db->prepare('UPDATE pedidos SET total = (SELECT COALESCE(SUM(subtotal), 0) FROM itens_pedido WHERE pedido_id = ?), atualizado_em = NOW() WHERE id = ?')
        ->execute([(int)$item['pedido_id'], (int)$item['pedido_id']]);
    $novoTotal = (float)$db->query('SELECT total FROM pedidos WHERE id = ' . (int)$item['pedido_id'])->fetchColumn();
    $db->commit();

    audit_log($db, 'pedido_item_removido', [
        'pedido_id' => (int)$item['pedido_id'],
        'item_id' => $itemId,
        'produto' => (string)$item['produto_nome'],
        'quantidade' => (int)$item['quantidade'],
        'subtotal' => (float)$item['subtotal'],
        'usuario_id' => (int)$ctx['usuario_id'],
        'autorizado_por' => $aut ? (int)$aut['autorizador_id'] : null,
    ]);
    security_json(['success' => true, 'pedido_id' => (int)$item['pedido_id'], 'total' => $novoTotal]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('api/pedido_item_remover: ' . $e->getMessage());
    security_json(['success' => false, 'message' => 'Não foi possível remover o item.'], 500);
}
