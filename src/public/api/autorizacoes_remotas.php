<?php
/*
 * Autorizacoes a distancia — lado do gerente/administrador.
 *   GET                              -> pedidos pendentes do restaurante (+ token CSRF para decidir)
 *   POST id=N decisao=aprovar|recusar -> decide (uma so vez; o primeiro gerente a decidir ganha)
 * O garcom recebe a resposta em api/gerente_autorizar.php (acao=estado_remoto).
 */
require_once __DIR__ . '/../app_base.php';
require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/personal_data.php';
require_once APP_BASE . '/src/config/csrf.php';
require_once APP_BASE . '/src/config/autorizacao_gerente.php';

$ctx = api_guard(['rate_key' => 'autorizacoes_remotas', 'rate_max' => 120, 'rate_window' => 60, 'skip_turno' => true]);
header('Cache-Control: no-store');
$rid = (int)$ctx['restaurante_id'];
$uid = (int)$ctx['usuario_id'];
if (!in_array(terminal_normalizar_perfil($ctx['perfil']), ['ADMIN', 'GERENTE'], true)) {
    security_json(['success' => false, 'message' => 'Só gerentes e administradores.'], 403);
}
$db = autorizacao_remota_db();
if (!$db) {
    security_json(['success' => true, 'pendentes' => []]);
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $decisao = (string)($_POST['decisao'] ?? '');
    if (!in_array($decisao, ['aprovar', 'recusar'], true)) {
        security_json(['success' => false, 'message' => 'Decisão inválida.'], 400);
    }
    $st = $db->prepare("UPDATE autorizacoes_remotas SET estado = ?, decidido_por = ?, decidido_em = NOW()
        WHERE id = ? AND restaurante_id = ? AND estado = 'PENDENTE' AND expira_em > NOW() AND solicitado_por <> ?");
    $st->execute([$decisao === 'aprovar' ? 'APROVADA' : 'RECUSADA', $uid, $id, $rid, $uid]);
    if ($st->rowCount() !== 1) {
        security_json(['success' => false, 'message' => 'Este pedido já foi respondido ou expirou.']);
    }
    $log = ['id' => $id, 'decisao' => $decisao, 'autorizado_por' => $uid, 'restaurante_id' => $rid];
    security_log_event('autorizacao_remota_' . $decisao, $log);
    if (function_exists('audit_log')) {
        @audit_log(null, 'autorizacao_remota', $log);
    }
    security_json(['success' => true, 'message' => $decisao === 'aprovar' ? 'Aprovado.' : 'Recusado.']);
}

session_write_close();
$st = $db->prepare("SELECT a.id, a.acao, a.descricao, u.nome, u.perfil, TIMESTAMPDIFF(SECOND, a.criado_em, NOW()) AS ha_seg
    FROM autorizacoes_remotas a JOIN usuarios u ON u.id = a.solicitado_por
    WHERE a.restaurante_id = ? AND a.estado = 'PENDENTE' AND a.expira_em > NOW() AND a.solicitado_por <> ?
    ORDER BY a.id LIMIT 20");
$st->execute([$rid, $uid]);
$rotulos = ['GARCOM' => 'Garçom', 'CAIXA' => 'Caixa', 'COZINHA' => 'Cozinha', 'BAR' => 'Bar', 'GERENTE' => 'Gerente', 'ADMIN' => 'Administrador'];
$lista = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $a) {
    $lista[] = [
        'id' => (int)$a['id'],
        'titulo' => AUTORIZACAO_GERENTE_ACOES[$a['acao']] ?? $a['acao'],
        'descricao' => (string)$a['descricao'],
        'quem' => (string)personal_data_decrypt_result((string)$a['nome'], 'nome'),
        'perfil' => $rotulos[terminal_normalizar_perfil($a['perfil'])] ?? $a['perfil'],
        'ha_seg' => (int)$a['ha_seg'],
    ];
}
security_json(['success' => true, 'pendentes' => $lista, 'csrf' => csrf_get_token()]);
