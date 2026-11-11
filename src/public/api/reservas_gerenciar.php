<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/reserva_qr_schema.php';
require_once APP_BASE . '/src/Model/Reserva.php';
require_once APP_BASE . '/src/Service/ReservaService.php';
require_once APP_BASE . '/src/api/reserva_notificacoes.php';

header('Content-Type: application/json; charset=utf-8');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    security_json(['success' => false, 'message' => 'Metodo nao permitido.'], 405);
}

$ctx = api_guard([
    'roles' => ['ADMIN', 'GERENTE', 'GARCOM'],
    'rate_key' => 'reservas_gerenciar',
    'rate_max' => 60,
    'rate_window' => 60,
]);

$data = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data) || empty($data)) {
    $data = $_POST;
}

if (!is_array($data) || empty($data)) {
    security_json(['success' => false, 'message' => 'Dados invalidos.'], 400);
}

$restauranteId = (int)$ctx['restaurante_id'];
$reservaId = (int)($data['reserva_id'] ?? 0);
$acaoEntrada = strtolower(trim((string)($data['acao'] ?? $data['status'] ?? '')));
$mesaId = (int)($data['mesa_id'] ?? $data['mesa_atribuida'] ?? 0);

$acao = match ($acaoEntrada) {
    'confirmada', 'confirmado', 'confirmar' => 'confirmar',
    'cancelada', 'cancelado', 'cancelar' => 'cancelar',
    'no-show', 'no_show', 'noshow' => 'no-show',
    'checkin', 'check-in' => 'checkin',
    default => '',
};

if ($reservaId <= 0 || $acao === '') {
    security_json(['success' => false, 'message' => 'Reserva ou acao invalida.'], 422);
}

try {
    $db = (new Database())->getConnection();
    reserva_qr_ensure_tables($db);
    reserva_qr_cleanup($db);
    $modelReserva = new \App\Model\Reserva($db);
    $serviceReserva = new \App\Service\ReservaService($db, $modelReserva);

    $resultado = match ($acao) {
        'confirmar' => $serviceReserva->confirmarReserva($reservaId, $restauranteId),
        'cancelar' => $serviceReserva->cancelarReserva($reservaId, $restauranteId),
        'no-show' => $serviceReserva->marcarNoShow($reservaId, $restauranteId),
        'checkin' => $mesaId > 0
            ? $serviceReserva->fazerCheckIn($reservaId, $mesaId, $restauranteId)
            : ['success' => false, 'message' => 'Mesa invalida para check-in.'],
        default => ['success' => false, 'message' => 'Acao nao suportada.'],
    };

    if (!empty($resultado['success']) && !empty($data['origem']) && in_array($data['origem'], ['app', 'telefone', 'presencial'], true)) {
        $stmtOrigem = $db->prepare("
            UPDATE reservas
            SET origem = :origem, atualizado_em = NOW()
            WHERE id = :id
              AND restaurante_id = :restaurante_id
        ");
        $stmtOrigem->execute([
            ':origem' => $data['origem'],
            ':id' => $reservaId,
            ':restaurante_id' => $restauranteId,
        ]);
    }

    if (!empty($resultado['success']) && in_array($acao, ['confirmar', 'cancelar'], true)) {
        $statusNotificacao = $acao === 'confirmar' ? 'confirmado' : 'cancelado';
        if (!reserva_notificacoes_enviar_status_cliente($db, $reservaId, $statusNotificacao, $restauranteId)) {
            error_log('[RESERVAS][GERENCIAR][NOTIFICACAO] Falha ao notificar cliente da reserva ' . $reservaId . '.');
        }
    }

    if (!empty($resultado['success'])) {
        security_audit_event('reserva_acao_gerenciada', [
            'reserva_id' => $reservaId,
            'acao' => $acao,
            'mesa_id' => $mesaId,
        ]);
    }

    security_json($resultado, !empty($resultado['success']) ? 200 : 422);
} catch (\Throwable $e) {
    error_log('[RESERVAS][GERENCIAR][ERROR] ' . $e->getMessage());
    security_json(['success' => false, 'message' => 'Erro interno ao atualizar a reserva.'], 500);
}
