<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Buscar Itens do Pedido
 */

try {
    require_once APP_BASE . '/src/config/api_guard.php';
    require_once APP_BASE . '/src/config/database.php';
    require_once APP_BASE . '/src/config/restaurante_context.php';

    header('Content-Type: application/json; charset=utf-8');

    $ctx = api_guard([
        'roles' => ['ADMIN', 'GERENTE', 'CAIXA', 'GARCOM', 'COZINHA', 'BAR'],
        'rate_key' => 'pedido_itens',
        'rate_max' => 120,
        'rate_window' => 60,
        'skip_turno' => true,
    ]);

    $restauranteId = function_exists('session_restaurante_contexto_id')
        ? (int)session_restaurante_contexto_id()
        : (int)$ctx['restaurante_id'];
    if ($restauranteId <= 0) {
        $restauranteId = (int)$ctx['restaurante_id'];
    }
    $restauranteIdSessao = (int)($_SESSION['restaurante_id'] ?? 0);

    // Liberta o lock da sessão para não bloquear outras requests no php -S
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    if (empty($_GET['id'])) {
        security_json(['success' => false, 'message' => 'ID nao fornecido'], 422);
    }

    $database = new Database();
    $db = $database->getConnection();

    $pedidoId = (int)$_GET['id'];
    $pedido = resource_fetch_for_tenant(
        $db,
        'pedidos',
        $pedidoId,
        $restauranteId,
        ['id', 'observacao', 'numero_pedido', 'total', 'status', 'criado_em']
    );

    // Fallback: alguns contextos de filial usam outro restaurante_id na sessão
    if (!$pedido && $restauranteIdSessao > 0 && $restauranteIdSessao !== $restauranteId) {
        $pedido = resource_fetch_for_tenant(
            $db,
            'pedidos',
            $pedidoId,
            $restauranteIdSessao,
            ['id', 'observacao', 'numero_pedido', 'total', 'status', 'criado_em']
        );
    }

    if (!$pedido) {
        resource_deny_not_found(null, [
            'table' => 'pedidos',
            'resource_id' => $pedidoId,
            'restaurante_id' => $restauranteId,
        ]);
    }

    $query = "SELECT ip.id, ip.pedido_id, ip.produto_id, ip.quantidade, ip.preco_unitario, ip.subtotal,
                     COALESCE(p.nome, CONCAT('Produto #', ip.produto_id)) AS produto_nome
              FROM itens_pedido ip
              LEFT JOIN produtos p ON ip.produto_id = p.id
              WHERE ip.pedido_id = :pedido_id
              ORDER BY ip.id ASC";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':pedido_id', (int)$pedido['id'], PDO::PARAM_INT);
    $stmt->execute();
    $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

    security_json([
        'success' => true,
        'itens' => $itens,
        'observacao' => $pedido['observacao'] ?? '',
        'pedido' => [
            'id' => (int)$pedido['id'],
            'numero_pedido' => $pedido['numero_pedido'] ?? null,
            'total' => $pedido['total'] ?? 0,
            'status' => $pedido['status'] ?? '',
            'criado_em' => $pedido['criado_em'] ?? null,
        ],
    ]);
} catch (Throwable $e) {
    error_log('[pedido_itens] ' . $e->getMessage());
    security_json([
        'success' => false,
        'message' => 'Erro interno do servidor',
    ], 500);
}
