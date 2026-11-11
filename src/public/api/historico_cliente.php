<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/plano_check.php';
require_once APP_BASE . '/src/config/restaurante_context.php';

header('Content-Type: application/json; charset=utf-8');

$ctx = api_guard([
    'roles' => ['ADMIN', 'GERENTE'],
    'rate_key' => 'historico_cliente',
    'rate_max' => 60,
    'rate_window' => 60,
    'skip_csrf' => true,
]);

$clienteId = (int)($_GET['cliente_id'] ?? 0);
if ($clienteId <= 0) {
    security_json(['success' => false, 'message' => 'cliente_id obrigatorio.'], 400);
}

$db = (new Database())->getConnection();
$restauranteId = (int)$ctx['restaurante_id'];
$planoRid = function_exists('session_restaurante_capability_id')
    ? (int)session_restaurante_capability_id()
    : $restauranteId;
$planoRid = $planoRid > 0 ? $planoRid : $restauranteId;
if (!plano_tem_funcionalidade_db($planoRid, 'historico_clientes')) {
    security_json([
        'success' => false,
        'message' => 'Histórico de clientes disponível a partir do plano Profissional.',
        'funcionalidade' => 'historico_clientes',
    ], 403);
}

$cliente = resource_require_cliente($db, $clienteId, $restauranteId, ['id', 'nome', 'telefone', 'email']);


$telefone = trim((string)($cliente['telefone'] ?? ''));
$email = trim((string)($cliente['email'] ?? ''));

// Reservas por cliente_id OU contacto (reservas antigas sem cliente_id)
$conds = ['(r.cliente_id IS NOT NULL AND r.cliente_id = :cid)'];
$paramsBase = [
    ':cid' => $clienteId,
    ':restaurante_id' => $restauranteId,
];
if ($telefone !== '') {
    $conds[] = '(r.telefone_cliente IS NOT NULL AND TRIM(r.telefone_cliente) = :tel)';
    $paramsBase[':tel'] = $telefone;
}
if ($email !== '') {
    $conds[] = '(r.email_cliente IS NOT NULL AND LOWER(TRIM(r.email_cliente)) = LOWER(:email))';
    $paramsBase[':email'] = $email;
}
$whereReserva = '(' . implode(' OR ', $conds) . ') AND r.restaurante_id = :restaurante_id';

$stmt = $db->prepare("
    SELECT COUNT(*) AS total
    FROM reservas r
    WHERE {$whereReserva}
");
$stmt->execute($paramsBase);
$total = (int)$stmt->fetchColumn();

$stmt = $db->prepare("
    SELECT DATE_FORMAT(r.data_reserva, '%Y-%m') AS mes, COUNT(*) AS qtd
    FROM reservas r
    WHERE {$whereReserva}
    GROUP BY mes
    ORDER BY mes DESC
    LIMIT 24
");
$stmt->execute($paramsBase);
$frequencia = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $db->prepare("
    SELECT
        r.id,
        r.data_reserva,
        r.hora_reserva,
        r.quantidade_pessoas,
        r.status,
        r.mesa_atribuida,
        m.numero AS mesa_numero
    FROM reservas r
    LEFT JOIN mesas m ON m.id = r.mesa_atribuida AND m.restaurante_id = r.restaurante_id
    WHERE {$whereReserva}
    ORDER BY r.data_reserva DESC, r.hora_reserva DESC
    LIMIT 20
");
$stmt->execute($paramsBase);
$reservasRecentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $db->prepare("
    SELECT id, data_compra, valor_total, pontos_ganhos, venda_id
    FROM cliente_historico_compras
    WHERE cliente_id = :cliente_id
      AND restaurante_id = :restaurante_id
    ORDER BY data_compra DESC
    LIMIT 20
");
$stmt->execute([
    ':cliente_id' => $clienteId,
    ':restaurante_id' => $restauranteId,
]);
$compras = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ultimaVisita = null;
foreach ($reservasRecentes as $r) {
    $d = (string)($r['data_reserva'] ?? '');
    if ($d !== '') {
        $ultimaVisita = $d;
        break;
    }
}
if ($compras) {
    $dc = substr((string)($compras[0]['data_compra'] ?? ''), 0, 10);
    if ($dc !== '' && ($ultimaVisita === null || $dc > $ultimaVisita)) {
        $ultimaVisita = $dc;
    }
}

security_json([
    'success' => true,
    'total_reservas' => $total,
    'frequencia' => $frequencia,
    'reservas_recentes' => $reservasRecentes,
    'compras' => $compras,
    'data_ultima_visita' => $ultimaVisita,
    'restaurantes_mais_visitados' => [
        ['restaurante_id' => $restauranteId, 'qtd' => $total],
    ],
]);
