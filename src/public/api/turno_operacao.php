<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/turno_helpers.php';
require_once APP_BASE . '/src/Service/TurnoService.php';

header('Content-Type: application/json; charset=utf-8');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    security_json(['success' => false, 'message' => 'Metodo nao permitido.'], 405);
}

$ctx = api_guard([
    'roles' => ['ADMIN', 'GERENTE', 'CAIXA', 'GARCOM', 'COZINHA', 'BAR'],
    'rate_key' => 'turno_operacao',
    'rate_max' => 60,
    'rate_window' => 60,
    'skip_turno' => true,
]);

$data = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = $_POST;
}

$acao = strtolower(trim((string)($data['acao'] ?? '')));
$contexto = [
    'usuario_id' => (int)$ctx['usuario_id'],
    'restaurante_id' => (int)$ctx['restaurante_id'],
    'perfil' => $ctx['perfil'],
];

try {
    $turnoService = new TurnoService(new Database());

    switch ($acao) {
        case 'iniciar':
            $resultado = $turnoService->iniciarTurno(
                $contexto['usuario_id'],
                $contexto['restaurante_id'],
                $data['turno'] ?? null
            );
            break;

        case 'encerrar':
            $resultado = $turnoService->encerrarTurno(
                $contexto['usuario_id'],
                $contexto['restaurante_id']
            );
            break;

        case 'abrir_manual':
            if (!in_array($contexto['perfil'], ['ADMIN', 'GERENTE', 'CAIXA'], true)) {
                security_json(['success' => false, 'message' => 'Sem permissao para abrir turno manualmente.'], 403);
            }
            $db = (new Database())->getConnection();
            resource_require_usuario(
                $db,
                (int)($data['funcionario_id'] ?? 0),
                $contexto['restaurante_id'],
                ['id']
            );
            $resultado = $turnoService->abrirTurnoManual(
                $contexto,
                (int)($data['funcionario_id'] ?? 0),
                trim((string)($data['motivo'] ?? '')),
                $data['turno'] ?? null
            );
            break;

        case 'fechar_manual':
            if (!in_array($contexto['perfil'], ['ADMIN', 'GERENTE', 'CAIXA'], true)) {
                security_json(['success' => false, 'message' => 'Sem permissao para fechar turno manualmente.'], 403);
            }
            $db = (new Database())->getConnection();
            resource_require_usuario(
                $db,
                (int)($data['funcionario_id'] ?? 0),
                $contexto['restaurante_id'],
                ['id']
            );
            $resultado = $turnoService->fecharTurnoManual(
                $contexto,
                (int)($data['funcionario_id'] ?? 0),
                trim((string)($data['motivo'] ?? '')),
                (int)($data['turno_id'] ?? 0) > 0 ? (int)$data['turno_id'] : null
            );
            break;

        case 'status':
            $resultado = [
                'success' => true,
                'turno_ativo' => $turnoService->obterTurnoAtivoUsuario($contexto['usuario_id'], $contexto['restaurante_id']),
            ];
            break;

        default:
            security_json(['success' => false, 'message' => 'Acao invalida.'], 400);
    }

    security_json($resultado);
} catch (RuntimeException $e) {
    error_log('[turno_operacao][runtime] ' . $e->getMessage());
    security_json([
        'success' => false,
        'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Operacao de turno invalida.',
    ], 422);
} catch (Throwable $e) {
    error_log('[turno_operacao][fatal] ' . $e->getMessage());
    security_json([
        'success' => false,
        'message' => 'Erro ao processar operacao de turno.',
    ], 500);
}
