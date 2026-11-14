<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/reserva_qr_schema.php';
require_once APP_BASE . '/src/Model/Reserva.php';
require_once APP_BASE . '/src/Service/ReservaService.php';
require_once APP_BASE . '/src/api/reserva_notificacoes.php';

header('Content-Type: application/json; charset=utf-8');

$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$ctx = api_guard([
    'roles' => ['ADMIN', 'GERENTE', 'GARCOM'],
    'rate_key' => 'reservas_api',
    'rate_max' => 120,
    'rate_window' => 60,
    'skip_csrf' => $requestMethod === 'GET',
]);

$restauranteId = (int)$ctx['restaurante_id'];
$db = (new Database())->getConnection();
reserva_qr_ensure_tables($db);
reserva_qr_cleanup($db);
$requestPath = trim((string)($_GET['route'] ?? ''), '/');

$modelReserva = new \App\Model\Reserva($db);
$serviceReserva = new \App\Service\ReservaService($db, $modelReserva);

function reservas_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function reservas_notificar_cliente_status(PDO $db, int $reservaId, int $restauranteId, string $status): array
{
    $enviado = reserva_notificacoes_enviar_status_cliente($db, $reservaId, $status, $restauranteId);
    if (!$enviado) {
        error_log('[RESERVAS][API][NOTIFICACAO] Falha ao notificar cliente da reserva ' . $reservaId . '.');
    }

    return [
        'notificado' => (bool)$enviado,
        'notificacao_status' => $status,
    ];
}

try {
    if ($requestMethod === 'GET' && $requestPath === '') {
        $data = trim((string)($_GET['data'] ?? date('Y-m-d')));
        $reservas = $modelReserva->obterPorData($restauranteId, $data);

        security_json([
            'success' => true,
            'data' => $reservas,
            'total' => count($reservas),
            'ocupacao' => $serviceReserva->calcularOcupacao($restauranteId, $data),
        ]);
    }

    if ($requestMethod === 'GET' && $requestPath === 'disponibilidade') {
        $data = trim((string)($_GET['data'] ?? date('Y-m-d')));
        $hora = trim((string)($_GET['hora'] ?? '19:00'));
        $quantidade = (int)($_GET['quantidade'] ?? 2);

        $mesasDisponiveis = $serviceReserva->validarDisponibilidade(
            $restauranteId,
            $data,
            $hora,
            $quantidade
        );

        security_json([
            'success' => true,
            'mesas_disponiveis' => $mesasDisponiveis,
            'total' => count($mesasDisponiveis),
        ]);
    }

    if ($requestMethod === 'GET' && ctype_digit($requestPath)) {
        $reservaId = (int)$requestPath;
        $reserva = $modelReserva->obter($reservaId, $restauranteId);
        if (!$reserva) {
            security_json(['success' => false, 'message' => 'Reserva nao encontrada.'], 404);
        }

        security_json(['success' => true, 'data' => $reserva]);
    }

    if ($requestMethod === 'POST' && $requestPath === '') {
        $dados = reservas_read_json_body();
        if (empty($dados)) {
            $dados = $_POST;
        }
        $dados['restaurante_id'] = $restauranteId;

        $resultado = $serviceReserva->criarReserva($dados);
        if (!empty($resultado['success'])) {
            security_audit_event('reserva_criada', [
                'reserva_id' => (int)($resultado['reserva_id'] ?? 0),
            ]);
        }

        security_json(
            $resultado,
            !empty($resultado['success']) ? 200 : (($resultado['code'] ?? '') === 'SEM_DISPONIBILIDADE' ? 409 : 422)
        );
    }

    if (preg_match('/^(\d+)\/confirmar$/', $requestPath, $m) && in_array($requestMethod, ['PUT', 'POST'], true)) {
        $reservaId = (int)$m[1];
        // Mesa escolhida pelo restaurante ao confirmar: e a que vai na
        // mensagem ao cliente, por isso nao pode ser trocada em silencio.
        $dadosConfirmar = reservas_read_json_body();
        if (empty($dadosConfirmar)) {
            $dadosConfirmar = $_POST;
        }
        $mesaConfirmar = (int)($dadosConfirmar['mesa_id'] ?? 0);
        $resultado = $serviceReserva->confirmarReserva($reservaId, $restauranteId, $mesaConfirmar);
        if (!empty($resultado['success'])) {
            $notif = reservas_notificar_cliente_status($db, $reservaId, $restauranteId, 'confirmado');
            $resultado = array_merge($resultado, $notif);
            if (!empty($notif['notificado'])) {
                $resultado['message'] = trim((string)($resultado['message'] ?? 'Reserva confirmada com sucesso.'))
                    . ' Email de confirmação enviado ao cliente.';
            } else {
                $resultado['message'] = trim((string)($resultado['message'] ?? 'Reserva confirmada com sucesso.'))
                    . ' Sem email do cliente ou o envio falhou.';
            }
            security_audit_event('reserva_confirmada', ['reserva_id' => $reservaId]);
        }

        security_json($resultado, !empty($resultado['success']) ? 200 : 422);
    }

    if (preg_match('/^(\d+)\/checkin$/', $requestPath, $m) && in_array($requestMethod, ['PUT', 'POST'], true)) {
        $dados = reservas_read_json_body();
        if (empty($dados)) {
            $dados = $_POST;
        }
        $mesaId = (int)($dados['mesa_id'] ?? 0);
        if ($mesaId <= 0) {
            security_json(['success' => false, 'message' => 'Mesa invalida.'], 400);
        }

        $reservaId = (int)$m[1];
        $resultado = $serviceReserva->fazerCheckIn($reservaId, $mesaId, $restauranteId);
        if (!empty($resultado['success'])) {
            security_audit_event('reserva_checkin', ['reserva_id' => $reservaId, 'mesa_id' => $mesaId]);
        }

        security_json($resultado, !empty($resultado['success']) ? 200 : 422);
    }

    if (preg_match('/^(\d+)\/no-show$/', $requestPath, $m) && in_array($requestMethod, ['PUT', 'POST'], true)) {
        $reservaId = (int)$m[1];
        $resultado = $serviceReserva->marcarNoShow($reservaId, $restauranteId);
        if (!empty($resultado['success'])) {
            security_audit_event('reserva_no_show', ['reserva_id' => $reservaId]);
        }

        security_json($resultado, !empty($resultado['success']) ? 200 : 422);
    }

    if (ctype_digit($requestPath) && in_array($requestMethod, ['DELETE', 'POST'], true)) {
        $reservaId = (int)$requestPath;
        $resultado = $serviceReserva->cancelarReserva($reservaId, $restauranteId);
        if (!empty($resultado['success'])) {
            $notif = reservas_notificar_cliente_status($db, $reservaId, $restauranteId, 'cancelado');
            $resultado = array_merge($resultado, $notif);
            if (!empty($notif['notificado'])) {
                $resultado['message'] = trim((string)($resultado['message'] ?? 'Reserva cancelada com sucesso.'))
                    . ' Email de cancelamento enviado ao cliente.';
            } else {
                $resultado['message'] = trim((string)($resultado['message'] ?? 'Reserva cancelada com sucesso.'))
                    . ' Sem email do cliente ou o envio falhou.';
            }
            security_audit_event('reserva_cancelada', ['reserva_id' => $reservaId]);
        }

        security_json($resultado, !empty($resultado['success']) ? 200 : 422);
    }

    security_json(['success' => false, 'message' => 'Endpoint de reservas nao encontrado.'], 404);
} catch (\InvalidArgumentException $e) {
    security_json([
        'success' => false,
        'message' => security_exception_message(
            $e,
            'Dados da reserva invalidos.',
            'reservas_validation_failed'
        ),
    ], 422);
} catch (\Throwable $e) {
    security_json([
        'success' => false,
        'message' => security_exception_message(
            $e,
            'Erro interno ao processar a reserva.',
            'reservas_api_failed'
        ),
    ], 500);
}
