<?php
require_once __DIR__ . '/../app_base.php';
require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/plano_check.php';
require_once APP_BASE . '/src/config/restaurante_context.php';
require_once APP_BASE . '/src/config/mesa_display_helper.php';

header('Content-Type: application/json');

api_guard([
    'roles' => ['ADMIN', 'GERENTE', 'GARCOM'],
    'rate_key' => 'garcom_mesas',
    'rate_max' => 120,
    'rate_window' => 60,
]);

$restaurante_id = (int)$_SESSION['restaurante_id'];
$garcom_id = (int)$_SESSION['usuario_id'];
$planRid = function_exists('session_restaurante_capability_id')
    ? (int)session_restaurante_capability_id()
    : $restaurante_id;
if ($planRid <= 0) {
    $planRid = $restaurante_id;
}
plano_verificar_funcionalidade($planRid, 'garcons', true);

$db = (new Database())->getConnection();

$stmt = $db->prepare(
    "SELECT numero, status
     FROM mesas
     WHERE restaurante_id = :rid
       AND (garcom_id = :garcom_id OR garcom_id IS NULL)"
);

$stmt->execute(['garcom_id' => $garcom_id, 'rid' => $restaurante_id]);
$mesas = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], static function ($m) {
    return !mesa_eh_balcao($m['numero'] ?? '');
}));

$stats = [
    'total' => count($mesas),
    'ocupadas' => count(array_filter($mesas, static fn($m) => ($m['status'] ?? '') === 'OCUPADA')),
    'livres' => count(array_filter($mesas, static fn($m) => ($m['status'] ?? '') === 'LIVRE')),
];

echo json_encode([
    'success' => true,
    'total' => (int)$stats['total'],
    'ocupadas' => (int)$stats['ocupadas'],
    'livres' => (int)$stats['livres'],
]);
