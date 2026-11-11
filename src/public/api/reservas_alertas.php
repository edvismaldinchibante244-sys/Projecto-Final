<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';

header('Content-Type: application/json; charset=utf-8');

$ctx = api_guard([
    'roles' => ['ADMIN', 'GERENTE', 'GARCOM'],
    'rate_key' => 'reservas_alertas',
    'rate_max' => 120,
    'rate_window' => 60,
    'skip_csrf' => true,
]);

$restauranteId = (int)$ctx['restaurante_id'];

try {
    $db = (new Database())->getConnection();

    $stmtResumo = $db->prepare("
        SELECT
            COUNT(*) AS total_ativas,
            SUM(CASE WHEN status = 'pendente' THEN 1 ELSE 0 END) AS pendentes,
            SUM(CASE WHEN status = 'confirmado' THEN 1 ELSE 0 END) AS confirmadas
        FROM reservas
        WHERE restaurante_id = :restaurante_id
          AND data_reserva >= CURDATE()
          AND status IN ('pendente', 'confirmado')
    ");
    $stmtResumo->execute([':restaurante_id' => $restauranteId]);
    $resumo = $stmtResumo->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmtUltima = $db->prepare("
        SELECT
            r.id,
            r.nome_cliente,
            r.data_reserva,
            r.hora_reserva,
            r.status,
            r.mesa_atribuida,
            m.numero AS mesa_numero,
            r.criado_em
        FROM reservas r
        LEFT JOIN mesas m ON m.id = r.mesa_atribuida
        WHERE r.restaurante_id = :restaurante_id
          AND r.data_reserva >= CURDATE()
          AND r.status = 'pendente'
        ORDER BY r.id DESC
        LIMIT 1
    ");
    $stmtUltima->execute([':restaurante_id' => $restauranteId]);
    $ultima = $stmtUltima->fetch(PDO::FETCH_ASSOC) ?: null;

    security_json([
        'success' => true,
        'restaurante_id' => $restauranteId,
        'total_ativas' => (int)($resumo['total_ativas'] ?? 0),
        'pendentes' => (int)($resumo['pendentes'] ?? 0),
        'confirmadas' => (int)($resumo['confirmadas'] ?? 0),
        'ultima_pendente' => $ultima ? [
            'id' => (int)($ultima['id'] ?? 0),
            'nome_cliente' => (string)($ultima['nome_cliente'] ?? ''),
            'data_reserva' => (string)($ultima['data_reserva'] ?? ''),
            'hora_reserva' => (string)($ultima['hora_reserva'] ?? ''),
            'status' => (string)($ultima['status'] ?? ''),
            'mesa_atribuida' => (int)($ultima['mesa_atribuida'] ?? 0),
            'mesa_numero' => $ultima['mesa_numero'] ?? null,
            'criado_em' => (string)($ultima['criado_em'] ?? ''),
        ] : null,
    ]);
} catch (Throwable $e) {
    error_log('[RESERVAS_ALERTAS][ERROR] ' . $e->getMessage());
    security_json([
        'success' => false,
        'message' => 'Erro ao consultar alertas de reservas.',
    ], 500);
}
