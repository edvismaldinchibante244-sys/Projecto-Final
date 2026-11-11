<?php
require_once __DIR__ . '/../app_base.php';
require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
header('Content-Type: application/json');

api_guard([
    'roles' => ['ADMIN', 'GERENTE', 'CAIXA'],
    'rate_key' => 'caixa_pagamentos',
    'rate_max' => 120,
    'rate_window' => 60,
]);

$database = new Database();
$db = $database->getConnection();
$rid = $_SESSION['restaurante_id'] ?? 0;
$uid = $_SESSION['usuario_id'] ?? 0;

if ($rid <= 0 || $uid <= 0) {
    http_response_code(403);
    exit(json_encode(['error' => 'Unauthorized']));
}

$stmt = $db->prepare("
    SELECT 
        forma_pagamento,
        COUNT(*) as qtd,
        SUM(total_final) as total
    FROM vendas 
    WHERE restaurante_id = ? AND usuario_id = ? AND (criado_em >= CURDATE() AND criado_em < CURDATE() + INTERVAL 1 DAY) AND status = 'PAGO'
    GROUP BY forma_pagamento
");
$stmt->execute([$rid, $uid]);
$pagamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$dados = [
    'DINHEIRO' => ['qtd' => 0, 'total' => 0],
    'MPESA' => ['qtd' => 0, 'total' => 0],
    'EMOLA' => ['qtd' => 0, 'total' => 0],
    'M-PESA' => ['qtd' => 0, 'total' => 0],
    'E-MOLA' => ['qtd' => 0, 'total' => 0],
    'CARTAO' => ['qtd' => 0, 'total' => 0],
    'PIX' => ['qtd' => 0, 'total' => 0]
];

foreach ($pagamentos as $p) {
    $dados[$p['forma_pagamento']] = [
        'qtd' => (int)$p['qtd'],
        'total' => number_format($p['total'], 2)
    ];
}

echo json_encode($dados);
?>


