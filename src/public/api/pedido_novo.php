<?php
require_once __DIR__ . '/../app_base.php';
ob_start();

/*
  API - Criar Novo Pedido (staff autenticado).
  A logica de negocio esta em app/src/Service/PedidoService.php.
  Pedidos de clientes via QR usam api/reserva_publica.php?route=pedido_qr
  (com token da mesa).
 */

require_once APP_BASE . '/src/config/security.php';
security_start_session();
security_set_headers();
require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/turno_helpers.php';
require_once APP_BASE . '/src/config/plano_check.php';
require_once APP_BASE . '/src/config/restaurante_context.php';
require_once APP_BASE . '/src/Service/PedidoService.php';

header('Content-Type: application/json; charset=utf-8');

function pedido_novo_json(array $payload, int $status = 200): void
{
    if (ob_get_length()) {
        @ob_clean();
    }
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pedido_novo_json(['success' => false, 'message' => 'Metodo nao permitido'], 405);
}

$is_json = strpos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false;
$body = $is_json ? json_decode((string)file_get_contents('php://input'), true) : $_POST;
$body = is_array($body) ? $body : [];

// Seguranca: este endpoint e exclusivo para funcionarios autenticados.
// O antigo fluxo publico (apenas "rid" + "mesa_id", sem token) permitia a
// qualquer pessoa enviar pedidos para qualquer mesa de qualquer restaurante.
$temSessaoRestaurante = isset($_SESSION['restaurante_id']) && (int)$_SESSION['restaurante_id'] > 0;
if (!$temSessaoRestaurante) {
    if (!empty($body['rid'])) {
        security_log_event('pedido_novo_publico_bloqueado', ['rid' => (int)$body['rid']]);
        pedido_novo_json([
            'success' => false,
            'message' => 'Pedidos de clientes devem ser feitos pelo QR Code da mesa.',
            'code' => 'QR_TOKEN_REQUIRED',
        ], 403);
    }
    pedido_novo_json(['success' => false, 'message' => 'Nao autenticado'], 401);
}

api_guard([
    'roles' => ['ADMIN', 'GERENTE', 'GARCOM', 'COZINHA', 'BAR'],
    'rate_key' => 'pedido_novo_staff',
    'rate_max' => 60,
    'rate_window' => 60,
]);

// O restaurante vem sempre da sessao; um "rid" enviado no body e ignorado.
$restauranteId = (int)$_SESSION['restaurante_id'];

$itens = $is_json
    ? (is_array($body['itens'] ?? null) ? $body['itens'] : [])
    : json_decode((string)($body['itens'] ?? '[]'), true);

// Plano e filial (o plano e o da matriz; a filial so quando difere da matriz).
$planoRid = (int)session_restaurante_capability_id();
$planoRid = $planoRid > 0 ? $planoRid : $restauranteId;
$authRid = (int)session_restaurante_auth_id();
$estoqueAvancado = false;
try {
    $estoqueAvancado = (bool)plano_tem_funcionalidade_db($planoRid, 'estoque_avancado');
} catch (Throwable $e) {
    error_log('pedido_novo: falha em plano_tem_funcionalidade_db: ' . $e->getMessage());
}

$estoqueIngredientes = $estoqueAvancado;
if (!$estoqueIngredientes) {
    try {
        require_once APP_BASE . '/src/config/ingredientes_simples.php';
        $estoqueIngredientes = plano_tem_ingredientes($planoRid);
    } catch (Throwable $e) {
        error_log('pedido_novo: ingredientes: ' . $e->getMessage());
    }
}

$database = new Database();
$resultado = (new PedidoService($database->getConnection()))->criar(
    [
        'restaurante_id' => $restauranteId,
        'usuario_id' => (int)($_SESSION['usuario_id'] ?? 0),
        'perfil' => (string)($_SESSION['perfil'] ?? ''),
        'plano_nome' => plano_normalizar_nome(plano_get_dados($planoRid)['plano_nome'] ?? 'BASICO'),
        'estoque_avancado' => $estoqueAvancado,
        'estoque_ingredientes' => $estoqueIngredientes,
        'filial_id' => ($authRid > 0 && $authRid !== $planoRid) ? $authRid : null,
        'idempotencia_payload' => $_POST,
    ],
    [
        'mesa_id' => (int)($body['mesa_id'] ?? ($body['mesa'] ?? 0)),
        'observacao' => (string)($body['observacao'] ?? ''),
        'itens' => is_array($itens) ? $itens : [],
    ]
);

// Notificacao em tempo real (KDS) so depois do commit.
if (!empty($resultado['evento'])) {
    $pedido_data = $resultado['evento'];
    include __DIR__ . '/../notify_websocket.php';
}

pedido_novo_json($resultado['body'], $resultado['http']);
