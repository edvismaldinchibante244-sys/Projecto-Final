<?php
require_once __DIR__ . '/app_base.php';

/*
   Notifica servidor WebSocket sobre mudanças de pedido.
   Deve ser sempre não-bloqueante e nunca quebrar o fluxo HTTP principal.
 */

if (empty($pedido_data) || !is_array($pedido_data)) {
    if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Not found');
    }
    return;
}

require_once APP_BASE . '/src/config/realtime.php';

$restauranteId = isset($pedido_data['restaurante_id'])
    ? (int)$pedido_data['restaurante_id']
    : (int)($_SESSION['restaurante_id'] ?? 0);

// Sem REALTIME_URL/REALTIME_SECRET no .env nao faz nada (antes tentava sempre localhost:3001).
if (realtime_notificar($restauranteId, $pedido_data)) {
    error_log('WebSocket notified: pedido ' . ($pedido_data['numero_pedido'] ?? '?'));
}
