<?php
/** Autorizacao do gerente: lista quem pode autorizar e emite o codigo de uso unico. */
require_once __DIR__ . '/../app_base.php';
require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/personal_data.php';
require_once APP_BASE . '/src/config/autorizacao_gerente.php';

header('Content-Type: application/json; charset=utf-8');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    security_json(['success' => false, 'message' => 'Método não permitido.'], 405);
}
$ctx = api_guard(['rate_key' => 'gerente_autorizar', 'rate_max' => 40, 'rate_window' => 60]);
$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
$rid = (int)$ctx['restaurante_id'];

try {
    $db = (new Database())->getConnection();
    $acao = (string)($input['acao'] ?? '');
    if ($acao === 'listar') {
        security_json(['success' => true, 'autorizadores' => autorizacao_gerente_autorizadores($db, $rid)]);
    }
    if ($acao === 'autorizar') {
        $r = autorizacao_gerente_emitir(
            $db,
            $rid,
            (string)($input['acao_alvo'] ?? ''),
            (int)($input['alvo'] ?? 0),
            (int)($input['autorizador_id'] ?? 0),
            (string)($input['credencial'] ?? '')
        );
        if (!$r['ok']) {
            security_json(['success' => false, 'message' => $r['mensagem']], 401);
        }
        security_json(['success' => true, 'codigo' => $r['codigo']]);
    }
    // Pedir a distancia: o gerente/administrador aprova no seu proprio dispositivo.
    $uid = (int)$ctx['usuario_id'];
    if ($acao === 'pedir_remoto') {
        $r = autorizacao_remota_pedir($db, $rid, $uid, (string)($input['acao_alvo'] ?? ''), (int)($input['alvo'] ?? 0));
        if (!$r['ok']) {
            security_json(['success' => false, 'message' => $r['mensagem']], 400);
        }
        security_json(['success' => true, 'id' => $r['id'], 'codigo' => $r['codigo']]);
    }
    if ($acao === 'estado_remoto' || $acao === 'desistir_remoto') {
        $id = (int)($input['id'] ?? 0);
        if ($acao === 'desistir_remoto') {
            $db->prepare("UPDATE autorizacoes_remotas SET estado = 'CANCELADA' WHERE id = ? AND restaurante_id = ? AND solicitado_por = ? AND estado = 'PENDENTE'")
                ->execute([$id, $rid, $uid]);
        }
        $st = $db->prepare("SELECT a.estado, a.expira_em < NOW() AS expirou, u.nome AS decidido_nome
            FROM autorizacoes_remotas a LEFT JOIN usuarios u ON u.id = a.decidido_por
            WHERE a.id = ? AND a.restaurante_id = ? AND a.solicitado_por = ?");
        $st->execute([$id, $rid, $uid]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a) {
            security_json(['success' => false, 'message' => 'Pedido não encontrado.'], 404);
        }
        $estado = ($a['estado'] === 'PENDENTE' && (int)$a['expirou'] === 1) ? 'EXPIRADA' : (string)$a['estado'];
        security_json(['success' => true, 'estado' => $estado,
            'decidido_por' => $a['decidido_nome'] ? (string)personal_data_decrypt_result((string)$a['decidido_nome'], 'nome') : null]);
    }
    security_json(['success' => false, 'message' => 'Ação inválida.'], 400);
} catch (Throwable $e) {
    error_log('api/gerente_autorizar: ' . $e->getMessage());
    // A causa vai na mensagem (sem dados pessoais) para se perceber logo o que falhou.
    security_json(['success' => false, 'message' => 'Erro ao pedir autorização: ' . $e->getMessage()], 500);
}
