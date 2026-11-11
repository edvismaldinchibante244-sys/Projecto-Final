<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Estado de um trabalho em segundo plano (ex.: exportacao de dados).
   GET ?id=N  ->  {success, trabalho: {id, estado, pronto, tamanho, erro, expira_em}}
   Se o trabalho ainda estiver PENDENTE e nao houver tarefa agendada a trata-lo, este pedido
   trata-o ele proprio (uma so vez, gracas a reserva atomica). A pagina continua a perguntar.
*/
include_once APP_BASE . '/src/config/api_guard.php';
include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/plano_upgrade_guard.php';
include_once APP_BASE . '/src/config/trabalhos_fila.php';

$ctx = api_guard(['rate_key' => 'trabalho_estado', 'rate_max' => 120, 'rate_window' => 60, 'skip_turno' => true]);
$db = (new Database())->getConnection();
$rid = plano_upgrade_restaurante_alvo_id($db);
$id = (int)($_GET['id'] ?? 0);
if ($rid <= 0 || $id <= 0 || !plano_upgrade_usuario_pode_gerir($db, (int)$ctx['usuario_id'], $rid)) {
    security_json(['success' => false, 'message' => 'Sem acesso.'], 403);
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
$t = fila_disponivel($db) ? fila_obter($db, $id) : null;
if (!$t || (int)$t['restaurante_id'] !== $rid) {
    security_json(['success' => false, 'message' => 'Pedido não encontrado.'], 404);
}

// Ainda pendente (nenhuma tarefa agendada o apanhou): trata-o agora, neste pedido.
if ($t['estado'] === 'PENDENTE') {
    fila_recuperar_presos($db);
    $reservado = fila_reservar($db, $id);
    if ($reservado) {
        ignore_user_abort(true);
        $t = fila_executar($db, $reservado, false);
    } else {
        $t = fila_obter($db, $id) ?? $t;
    }
}
security_json(['success' => true, 'trabalho' => fila_publico($t)]);
