<?php
require_once __DIR__ . '/../app_base.php';

/*
  API - Atualizar status de item do pedido
  Fluxo: pendente -> em_preparo -> pronto -> entregue
 */

require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/offline_idempotency.php';
require_once APP_BASE . '/src/config/pedido_schema.php';

function log_debug($msg)
{
    $logDir = sys_get_temp_dir();
    if (!$logDir || !is_dir($logDir) || !is_writable($logDir)) {
        $logDir = __DIR__ . '/../../../';
    }
    $log_file = rtrim($logDir, "/\\") . DIRECTORY_SEPARATOR . 'pedido_status_debug.log';
    $timestamp = date('Y-m-d H:i:s');
    $data = "[{$timestamp}] {$msg}\n";
    @file_put_contents($log_file, $data, FILE_APPEND | LOCK_EX);
}

header('Content-Type: application/json; charset=utf-8');

log_debug("REQUEST HIT: method=" . $_SERVER['REQUEST_METHOD'] . ", POST=" . json_encode($_POST));

api_guard([
    'roles' => ['ADMIN', 'GERENTE', 'COZINHA', 'BAR'],
    'rate_key' => 'pedido_item_status',
    'rate_max' => 180,
    'rate_window' => 60,
]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo nao permitido']);
    exit;
}

$perfil = strtoupper(trim((string)($_SESSION['perfil'] ?? '')));
if ($perfil === 'GARCOM' || $perfil === 'GARÇOM' || $perfil === 'GARÃ‡OM') {
    $perfil = 'GARCOM';
}

$restaurante_id = (int)($_SESSION['restaurante_id'] ?? 0);
$item_id = (int)($_POST['item_id'] ?? 0);
$novoStatus = strtolower(trim((string)($_POST['status'] ?? '')));

log_debug("PARAMS: restaurante_id={$restaurante_id}, item_id={$item_id}, status='{$novoStatus}', POST=" . json_encode($_POST) . ", SESSION perfil='{$perfil}' rid=" . ($_SESSION['restaurante_id'] ?? 'MISSING'));

$permitidos = ['pendente', 'em_preparo', 'pronto', 'entregue'];
if ($restaurante_id <= 0 || $item_id <= 0 || !in_array($novoStatus, $permitidos, true)) {
    log_debug("VALIDATION FAILED: restaurante_id={$restaurante_id}, item_id={$item_id}, status='{$novoStatus}'");
    echo json_encode(['success' => false, 'message' => 'Dados invalidos (ver log)']);
    exit;
}

$db = (new Database())->getConnection();
pedido_schema_garantir($db);
offline_idempotency_prepare($db);

try {
    $item = resource_require_item_pedido($db, $item_id, $restaurante_id);
    $db->beginTransaction();
    $idempotency = offline_idempotency_begin(
        $db,
        $restaurante_id,
        (int)($_SESSION['usuario_id'] ?? 0),
        'api/pedido_item_status.php',
        $_POST
    );
    offline_idempotency_replay($db, $idempotency);

    $destinoItem = strtolower((string)($item['destino'] ?? 'cozinha'));
    if ($perfil === 'COZINHA' && $destinoItem !== 'cozinha') {
        throw new Exception('Sem permissao para atualizar item do bar');
    }
    if ($perfil === 'BAR' && $destinoItem !== 'bar') {
        throw new Exception('Sem permissao para atualizar item da cozinha');
    }

    $statusAtual = strtolower((string)($item['status'] ?? $item['status_atual'] ?? 'pendente'));
    $transicoes = [
        'pendente' => ['em_preparo'],
        'em_preparo' => ['pronto'],
        'pronto' => ['entregue'],
        'entregue' => [],
    ];

    if ($perfil !== 'ADMIN') {
        $next = $transicoes[$statusAtual] ?? [];
        if (!in_array($novoStatus, $next, true)) {
            throw new Exception('Transicao de status invalida');
        }
    }

    $sets = ["status = :status"];
    if ($novoStatus === 'em_preparo') {
        $sets[] = "iniciado_preparo_em = COALESCE(iniciado_preparo_em, NOW())";
    }
    if ($novoStatus === 'pronto') {
        $sets[] = "pronto_em = COALESCE(pronto_em, NOW())";
    }
    if ($novoStatus === 'entregue') {
        $sets[] = "entregue_em = COALESCE(entregue_em, NOW())";
    }

    $sqlUpdate = "UPDATE itens_pedido SET " . implode(', ', $sets) . "
                  WHERE id = :item_id
                    AND pedido_id = :pedido_id";
    $stmtUpdate = $db->prepare($sqlUpdate);
    $stmtUpdate->execute([
        'status' => $novoStatus,
        'item_id' => $item_id,
        'pedido_id' => (int)$item['pedido_id'],
    ]);

    $pedidoId = (int)$item['pedido_id'];

    $stmtResumo = $db->prepare(
        "SELECT
            SUM(CASE WHEN COALESCE(status,'pendente') = 'pendente' THEN 1 ELSE 0 END) AS pendentes,
            SUM(CASE WHEN COALESCE(status,'pendente') = 'em_preparo' THEN 1 ELSE 0 END) AS preparo,
            SUM(CASE WHEN COALESCE(status,'pendente') = 'pronto' THEN 1 ELSE 0 END) AS prontos,
            SUM(CASE WHEN COALESCE(status,'pendente') = 'entregue' THEN 1 ELSE 0 END) AS entregues,
            COUNT(*) AS total
         FROM itens_pedido
         WHERE pedido_id = :pedido_id"
    );
    $stmtResumo->execute(['pedido_id' => $pedidoId]);
    $r = $stmtResumo->fetch(PDO::FETCH_ASSOC) ?: ['pendentes' => 0, 'preparo' => 0, 'prontos' => 0, 'entregues' => 0, 'total' => 0];

    $pedidoStatus = 'NOVO';
    if ((int)$r['entregues'] === (int)$r['total'] && (int)$r['total'] > 0) {
        $pedidoStatus = 'ENTREGUE';
    } elseif ((int)$r['preparo'] > 0 || (int)$r['prontos'] > 0) {
        $pedidoStatus = 'PREPARANDO';
        if ((int)$r['pendentes'] === 0 && (int)$r['preparo'] === 0 && (int)$r['prontos'] > 0) {
            $pedidoStatus = 'PRONTO';
        }
    }

    $stmtPedido = $db->prepare(
        "UPDATE pedidos
         SET status = :status,
             atualizado_em = NOW(),
             iniciado_preparo_em = CASE WHEN :marcar_preparo = 1 THEN COALESCE(iniciado_preparo_em, NOW()) ELSE iniciado_preparo_em END,
             pronto_em = CASE WHEN :marcar_pronto = 1 THEN COALESCE(pronto_em, NOW()) ELSE pronto_em END,
             entregue_em = CASE WHEN :marcar_entregue = 1 THEN COALESCE(entregue_em, NOW()) ELSE entregue_em END
         WHERE id = :pedido_id AND restaurante_id = :rid"
    );
    $stmtPedido->execute([
        'status' => $pedidoStatus,
        'marcar_preparo' => $pedidoStatus === 'PREPARANDO' ? 1 : 0,
        'marcar_pronto' => $pedidoStatus === 'PRONTO' ? 1 : 0,
        'marcar_entregue' => $pedidoStatus === 'ENTREGUE' ? 1 : 0,
        'pedido_id' => $pedidoId,
        'rid' => $restaurante_id,
    ]);

    $response = [
        'success' => true,
        'message' => 'Status do item atualizado',
        'pedido_status' => $pedidoStatus,
    ];
    offline_idempotency_complete($db, $idempotency, $response);
    $db->commit();

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('pedido_item_status: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Nao foi possivel atualizar o item.'], JSON_UNESCAPED_UNICODE);
}
