<?php
require_once __DIR__ . '/../app_base.php';
require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/Model/Caixa.php';
header('Content-Type: application/json');

api_guard([
    'roles' => ['ADMIN', 'GERENTE', 'CAIXA'],
    'rate_key' => 'caixa_turno',
    'rate_max' => 120,
    'rate_window' => 60,
]);

$database = new Database();
$db = $database->getConnection();
$rid = $_SESSION['restaurante_id'] ?? 0;
$uid = $_SESSION['usuario_id'] ?? 0;
$perfil = strtoupper(trim($_SESSION['perfil'] ?? ''));

if ($rid <= 0 || $uid <= 0) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

$caixa = new Caixa($db);
$stmt_caixa_usuario = $db->prepare("SELECT * FROM caixas WHERE restaurante_id = :rid AND usuario_id = :uid AND status = 'ABERTO' ORDER BY data_abertura DESC LIMIT 1");
$stmt_caixa_usuario->execute([':rid' => $rid, ':uid' => $uid]);
$caixa_aberto = $stmt_caixa_usuario->fetch(PDO::FETCH_ASSOC) ?: $caixa->buscarAberto($rid);

if (!$caixa_aberto) {
    echo json_encode([
        'success' => true,
        'aberto' => false,
        'total_turno' => 0,
        'hora_abertura' => null,
        'diferenca' => 0
    ]);
    exit;
}

$total_turno = $db->prepare("SELECT SUM(total_final) as total FROM vendas WHERE caixa_id = ? AND status = 'PAGO'");
$total_turno->execute([$caixa_aberto['id']]);
$total = $total_turno->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

$diferenca = $caixa_aberto['saldo_final'] - $caixa_aberto['saldo_inicial'] - $total;

echo json_encode([
    'success' => true,
    'aberto' => true,
    'total_turno' => (float)$total,
    'hora_abertura' => date('H:i', strtotime($caixa_aberto['data_abertura'])),
    'diferenca' => (float)$diferenca,
    'caixa_id' => $caixa_aberto['id']
]);
?>


