<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/input_validation.php';

header('Content-Type: application/json; charset=utf-8');

$ctx = api_guard([
    'roles' => ['ADMIN', 'GERENTE'],
    'rate_key' => 'relatorio_reservas',
    'rate_max' => 120,
    'rate_window' => 60,
    'skip_csrf' => true,
]);

$restauranteSessao = (int)$ctx['restaurante_id'];
$restauranteId = $restauranteSessao;
$dataInicio = input_iso_date($_GET['data_inicio'] ?? date('Y-m-01'));
$dataFim = input_iso_date($_GET['data_fim'] ?? date('Y-m-t'));

if ($restauranteId <= 0) {
    security_json(['success' => false, 'message' => 'restaurante_id obrigatorio.'], 400);
}

if ($dataInicio === null || $dataFim === null || $dataInicio > $dataFim) {
    security_json(['success' => false, 'message' => 'Intervalo de datas invalido.'], 400);
}

$db = (new Database())->getConnection();
$params = [
    ':restaurante_id' => $restauranteId,
    ':data_inicio' => $dataInicio,
    ':data_fim' => $dataFim,
];

$stmt = $db->prepare("
    SELECT DATE(data_reserva) AS dia, COUNT(*) AS total
    FROM reservas
    WHERE restaurante_id = :restaurante_id
      AND data_reserva BETWEEN :data_inicio AND :data_fim
      AND status = 'confirmado'
    GROUP BY dia
    ORDER BY dia DESC
    LIMIT 30
");
$stmt->execute($params);
$porDia = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $db->prepare("
    SELECT YEARWEEK(data_reserva, 1) AS semana, COUNT(*) AS total
    FROM reservas
    WHERE restaurante_id = :restaurante_id
      AND data_reserva BETWEEN :data_inicio AND :data_fim
      AND status = 'confirmado'
    GROUP BY semana
    ORDER BY semana DESC
    LIMIT 12
");
$stmt->execute($params);
$porSemana = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $db->prepare("
    SELECT DATE_FORMAT(data_reserva, '%Y-%m') AS mes, COUNT(*) AS total
    FROM reservas
    WHERE restaurante_id = :restaurante_id
      AND data_reserva BETWEEN :data_inicio AND :data_fim
      AND status = 'confirmado'
    GROUP BY mes
    ORDER BY mes DESC
    LIMIT 12
");
$stmt->execute($params);
$porMes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $db->prepare("
    SELECT AVG(qtd) AS ocupacao_media
    FROM (
        SELECT COUNT(*) AS qtd
        FROM reservas
        WHERE restaurante_id = :restaurante_id
          AND data_reserva BETWEEN :data_inicio AND :data_fim
          AND status = 'confirmado'
          AND mesa_atribuida IS NOT NULL
        GROUP BY data_reserva, mesa_atribuida
    ) t
");
$stmt->execute($params);
$ocupacaoMedia = $stmt->fetchColumn();

$stmt = $db->prepare("
    SELECT status, COUNT(*) AS total
    FROM reservas
    WHERE restaurante_id = :restaurante_id
      AND data_reserva BETWEEN :data_inicio AND :data_fim
    GROUP BY status
");
$stmt->execute($params);
$totaisStatus = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $db->prepare("
    SELECT origem, COUNT(*) AS total
    FROM reservas
    WHERE restaurante_id = :restaurante_id
      AND data_reserva BETWEEN :data_inicio AND :data_fim
    GROUP BY origem
");
$stmt->execute($params);
$porOrigem = $stmt->fetchAll(PDO::FETCH_ASSOC);

security_json([
    'success' => true,
    'periodo' => [
        'data_inicio' => $dataInicio,
        'data_fim' => $dataFim,
    ],
    'por_dia' => $porDia,
    'por_semana' => $porSemana,
    'por_mes' => $porMes,
    'ocupacao_media' => $ocupacaoMedia !== false ? (float)$ocupacaoMedia : 0.0,
    'totais_status' => $totaisStatus,
    'pendentes_canceladas' => array_values(array_filter(
        $totaisStatus,
        static fn(array $item): bool => in_array((string)($item['status'] ?? ''), ['pendente', 'cancelado'], true)
    )),
    'por_origem' => $porOrigem,
]);
