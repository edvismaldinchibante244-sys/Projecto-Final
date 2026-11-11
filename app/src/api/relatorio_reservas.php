<?php
require_once __DIR__ . '/../config/internal_api.php';
internal_api_deny_direct_http_execution(__FILE__);

// API para relatório detalhado de reservas e ocupação
// Endpoint: /src/api/relatorio_reservas.php
// Parâmetros: restaurante_id, data_inicio, data_fim (YYYY-MM-DD)

include_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido.']);
    exit;
}

$restaurante_id = (int)($_GET['restaurante_id'] ?? 0);
$data_inicio = $_GET['data_inicio'] ?? date('Y-m-01');
$data_fim = $_GET['data_fim'] ?? date('Y-m-d');

if ($restaurante_id <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Restaurante não informado.']);
    exit;
}

$db = (new Database())->getConnection();

// Total de reservas por status
$stmt = $db->prepare("SELECT status, COUNT(*) as total FROM reservas WHERE restaurante_id = :restaurante_id AND data_reserva BETWEEN :data_inicio AND :data_fim GROUP BY status");
$stmt->execute([
    ':restaurante_id' => $restaurante_id,
    ':data_inicio' => $data_inicio,
    ':data_fim' => $data_fim
]);
$reservas_status = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Ocupação por mesa
$stmt = $db->prepare("SELECT mesa_atribuida, COUNT(*) as total FROM reservas WHERE restaurante_id = :restaurante_id AND data_reserva BETWEEN :data_inicio AND :data_fim AND status = 'confirmado' GROUP BY mesa_atribuida");
$stmt->execute([
    ':restaurante_id' => $restaurante_id,
    ':data_inicio' => $data_inicio,
    ':data_fim' => $data_fim
]);
$ocupacao_mesas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Taxa de ocupação (reservas confirmadas / total de mesas * dias)
$stmt = $db->prepare("SELECT COUNT(*) FROM mesas WHERE restaurante_id = :restaurante_id");
$stmt->execute([':restaurante_id' => $restaurante_id]);
$total_mesas = (int)$stmt->fetchColumn();

date_default_timezone_set('Africa/Maputo');
$periodo = (strtotime($data_fim) - strtotime($data_inicio)) / 86400 + 1;
$total_possivel = $total_mesas * $periodo;
$total_confirmadas = (int)($reservas_status['confirmado'] ?? 0);
$taxa_ocupacao = $total_possivel > 0 ? round($total_confirmadas / $total_possivel * 100, 1) : 0;

// No-show/canceladas
$total_noshow = (int)($reservas_status['no-show'] ?? 0);
$total_canceladas = (int)($reservas_status['cancelado'] ?? 0);

// Reservas detalhadas
$stmt = $db->prepare("SELECT * FROM reservas WHERE restaurante_id = :restaurante_id AND data_reserva BETWEEN :data_inicio AND :data_fim ORDER BY data_reserva DESC, hora_reserva DESC");
$stmt->execute([
    ':restaurante_id' => $restaurante_id,
    ':data_inicio' => $data_inicio,
    ':data_fim' => $data_fim
]);
$reservas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Resumo final
$result = [
    'success' => true,
    'periodo' => [
        'inicio' => $data_inicio,
        'fim' => $data_fim,
        'dias' => $periodo
    ],
    'total_reservas' => array_sum($reservas_status),
    'status' => $reservas_status,
    'ocupacao_mesas' => $ocupacao_mesas,
    'taxa_ocupacao' => $taxa_ocupacao,
    'no_show' => $total_noshow,
    'canceladas' => $total_canceladas,
    'reservas' => $reservas
];
echo json_encode($result, JSON_UNESCAPED_UNICODE);
