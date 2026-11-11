<?php
require_once __DIR__ . '/../app_base.php';

/*
  API - Receber Pagamento de Pedido
  Converte pedido em venda PAGA no caixa e liberta a mesa.
  A logica de negocio esta em app/src/Service/PagamentoService.php.
*/

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/plano_check.php';
require_once APP_BASE . '/src/config/restaurante_context.php';
require_once APP_BASE . '/src/config/turno_helpers.php';
require_once APP_BASE . '/src/Service/TurnoService.php';
require_once APP_BASE . '/src/Service/PagamentoService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

api_guard([
    'roles' => ['ADMIN', 'GERENTE', 'CAIXA'],
    'rate_key' => 'pedido_pagar',
    'rate_max' => 40,
    'rate_window' => 60,
]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo nao permitido']);
    exit;
}

if (!isset($_SESSION['restaurante_id'], $_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'message' => 'Nao autenticado']);
    exit;
}

$restauranteId = (int)$_SESSION['restaurante_id'];
$perfilRaw = strtoupper(trim((string)($_SESSION['perfil'] ?? '')));
$perfil = turno_normalizar_perfil($perfilRaw === 'OPERADOR' ? 'GARCOM' : $perfilRaw);

$database = new Database();
$db = $database->getConnection();

if (turno_usuario_exige_turno_ativo($perfil)) {
    $turnoService = new TurnoService($database);
    $turnoRestauranteId = function_exists('session_restaurante_auth_id') ? session_restaurante_auth_id() : $restauranteId;
    if (!$turnoService->obterTurnoAtivoUsuario((int)$_SESSION['usuario_id'], $turnoRestauranteId)) {
        echo json_encode(['success' => false, 'message' => 'É necessário ter turno ativo para receber pagamentos'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Funcionalidades do plano (sempre do plano da matriz).
$planoRid = function_exists('session_restaurante_capability_id') ? (int)session_restaurante_capability_id() : 0;
$planoRid = $planoRid > 0 ? $planoRid : $restauranteId;

$resultado = (new PagamentoService($db))->pagarPedido(
    [
        'restaurante_id' => $restauranteId,
        'usuario_id' => (int)$_SESSION['usuario_id'],
        // Basico (sem KDS): pagar pedidos abertos no PDV/Vendas.
        'telas_bar_cozinha' => (bool)plano_tem_funcionalidade_db($planoRid, 'telas_bar_cozinha'),
        // Profissional+ com Contas Abertas: fechar conta com itens em preparo.
        'contas_abertas' => (bool)plano_tem_funcionalidade_db($planoRid, 'contas_abertas'),
        'estoque_avancado' => (bool)plano_tem_funcionalidade_db($planoRid, 'estoque_avancado'),
        'idempotencia_payload' => $_POST,
    ],
    [
        'pedido_id' => (int)($_POST['id'] ?? 0),
        'forma_pagamento' => (string)($_POST['forma_pagamento'] ?? 'DINHEIRO'),
        'desconto_percent' => (float)($_POST['desconto_percent'] ?? ($_POST['desconto'] ?? 0)),
        'observacao' => (string)($_POST['observacao'] ?? ''),
        'referencia' => (string)($_POST['referencia'] ?? ($_POST['referencia_pagamento'] ?? '')),
        'valor_recebido' => $_POST['valor_recebido'] ?? null,
        'pagar_ao_enviar' => !empty($_POST['pagar_ao_enviar']) && (string)$_POST['pagar_ao_enviar'] !== '0',
    ]
);

http_response_code($resultado['http']);
echo json_encode($resultado['body'], JSON_UNESCAPED_UNICODE);
