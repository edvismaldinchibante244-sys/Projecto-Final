<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Cancelar o plano (Termos, seccao 5) ou desistir do cancelamento.
   So o administrador que gere o plano. O cancelamento produz efeito no fim do periodo pago.
   POST acao=cancelar|desistir, motivo (opcional), confirmacao=CANCELAR (para cancelar).
*/
include_once APP_BASE . '/src/config/api_guard.php';
include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/plano_upgrade_guard.php';
include_once APP_BASE . '/src/config/plano_encerramento.php';

header('Content-Type: application/json');
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    security_json(['success' => false, 'message' => 'Metodo nao permitido'], 405);
}
$ctx = api_guard(['rate_key' => 'plano_cancelar', 'rate_max' => 10, 'rate_window' => 300, 'skip_turno' => true]);
$db = (new Database())->getConnection();
$rid = plano_upgrade_restaurante_alvo_id($db);
if ($rid <= 0 || !plano_upgrade_usuario_pode_gerir($db, (int)$ctx['usuario_id'], $rid)) {
    security_json(['success' => false, 'message' => 'Apenas o administrador do restaurante pode cancelar o plano.'], 403);
}
$acao = (string)($_POST['acao'] ?? '');
// O estado do plano fica em cache na sessao (60 s): forcar nova avaliacao no proximo pedido.
unset($_SESSION['plano_lifecycle']['_avaliado_em']);
try {
    if ($acao === 'cancelar') {
        if (strtoupper(trim((string)($_POST['confirmacao'] ?? ''))) !== 'CANCELAR') {
            security_json(['success' => false, 'message' => 'Escreva CANCELAR para confirmar.'], 422);
        }
        $r = enc_pedir_cancelamento($db, $rid, (int)$ctx['usuario_id'], (string)($_POST['motivo'] ?? ''));
        security_json(['success' => true, 'efeito_em' => $r['efeito_em'],
            'message' => 'Cancelamento registado. O sistema funciona normalmente ate ' . date('d/m/Y', strtotime($r['efeito_em'])) . '.']);
    }
    if ($acao === 'desistir') {
        enc_desistir_cancelamento($db, $rid);
        security_json(['success' => true, 'message' => 'Cancelamento anulado. O plano continua activo.']);
    }
    security_json(['success' => false, 'message' => 'Accao invalida.'], 400);
} catch (InvalidArgumentException $e) {
    security_json(['success' => false, 'message' => $e->getMessage()], 409);
} catch (Throwable $e) {
    error_log('plano_cancelar: ' . $e->getMessage());
    security_json(['success' => false, 'message' => 'Nao foi possivel concluir. Tente de novo.'], 500);
}
