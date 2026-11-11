<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Server Sent Events para pedidos
 */

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/pedido_schema.php';
require_once APP_BASE . '/src/config/restaurante_context.php';
require_once APP_BASE . '/src/config/input_validation.php';

api_guard([
    'roles' => ['ADMIN', 'GERENTE', 'CAIXA', 'GARCOM', 'COZINHA', 'BAR'],
    'rate_key' => 'pedido_sse',
    'rate_max' => 30,
    'rate_window' => 60,
]);

$filter = input_enum($_GET['filter'] ?? '', ['', 'pendentes'], true);
if ($filter === null) {
    http_response_code(400);
    exit;
}

$restauranteId = session_restaurante_contexto_id();
if ($restauranteId <= 0) {
    http_response_code(403);
    exit;
}
session_write_close();

function normalizar_status_pedido($status)
{
    $status = strtoupper(trim((string)$status));
    $map = [
        'PENDENTE' => 'NOVO',
        'CONFIRMADO' => 'PREPARANDO',
        'NOVO' => 'NOVO',
        'PREPARANDO' => 'PREPARANDO',
        'PRONTO' => 'PRONTO',
        'ENTREGUE' => 'ENTREGUE',
        'CONTA' => 'CONTA',
        'CONTA_SOLICITADA' => 'CONTA',
        'PEDIR_CONTA' => 'CONTA',
        'PAGO' => 'PAGO',
        'CANCELADO' => 'CANCELADO'
    ];
    return $map[$status] ?? 'NOVO';
}

function carregar_payload_pedidos($db, $restauranteId, string $filter)
{
    $query = "SELECT p.*, m.numero as mesa_numero,
              (SELECT COUNT(*) FROM itens_pedido WHERE pedido_id = p.id) as total_itens
              FROM pedidos p
              LEFT JOIN mesas m ON p.mesa_id = m.id
              WHERE p.restaurante_id = :rid
              AND (p.criado_em >= CURDATE() AND p.criado_em < CURDATE() + INTERVAL 1 DAY)";
    $queryParams = [':rid' => $restauranteId];

    if ($filter === 'pendentes') {
        $query .= " AND p.status IN ('NOVO','PENDENTE','PREPARANDO')";
    }

    $query .= " ORDER BY p.criado_em DESC";

    $stmt = $db->prepare($query);
    foreach ($queryParams as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    }
    $stmt->execute();
    $pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $contadores = ['NOVO' => 0, 'PREPARANDO' => 0, 'PRONTO' => 0, 'ENTREGUE' => 0, 'CONTA' => 0, 'PAGO' => 0, 'CANCELADO' => 0];

    foreach ($pedidos as &$pedido) {
        $pedido['status'] = normalizar_status_pedido($pedido['status'] ?? 'NOVO');
        $pedido['origem'] = pedido_normalizar_origem($pedido['origem'] ?? 'BALCAO');
        if (isset($contadores[$pedido['status']])) {
            $contadores[$pedido['status']]++;
        }
    }
    unset($pedido);

    return [
        'pedidos' => $pedidos,
        'contadores' => $contadores,
        'updated_at' => date('c')
    ];
}

if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}

@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', 'off');
@ini_set('implicit_flush', '1');

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

set_time_limit(0);
$database = new Database();
$db = $database->getConnection();
pedido_schema_garantir($db);
$lastHash = '';

for ($i = 0; $i < 120; $i++) {
    if (connection_aborted()) {
        break;
    }

    try {
        $payload = carregar_payload_pedidos($db, $restauranteId, $filter);
        $hash = md5(json_encode($payload, JSON_UNESCAPED_UNICODE));

        if ($hash !== $lastHash) {
            echo "event: pedidos\n";
            echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
            $lastHash = $hash;
        } else {
            echo ": heartbeat\n\n";
        }

        @flush();
    } catch (Exception $e) {
        error_log('[pedido_sse] ' . $e->getMessage());
        echo "event: error\n";
        echo "data: {\"message\":\"erro interno\"}\n\n";
        @flush();
    }

    sleep(3);
}
