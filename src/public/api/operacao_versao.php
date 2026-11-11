<?php
require_once __DIR__ . '/../app_base.php';

/*
 * Versao da operacao do restaurante (pedidos, itens e mesas).
 *
 * Os ecras de operacao (cozinha, bar, pedidos, mesas do garcom e do caixa) perguntam aqui,
 * de poucos em poucos segundos, se algo mudou. A resposta e minuscula:
 *   GET ?v=<versao que o ecra ja tem>  -> 204 sem corpo, se nada mudou
 *                                      -> 200 {"v":"..."} se mudou (o ecra recarrega a lista)
 *   GET ?rt=1                          -> inclui o codigo da sala de tempo real, se estiver ativo
 * O numero vem da tabela operacao_versao (mantida por triggers). Sem ela, usa um calculo de recurso
 * barato (indices por restaurante + data).
 */
require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/realtime.php';

$ctx = api_guard([
    'roles' => ['ADMIN', 'GERENTE', 'CAIXA', 'GARCOM', 'COZINHA', 'BAR'],
    'rate_key' => 'operacao_versao',
    'rate_max' => 900,
    'rate_window' => 60,
    'skip_turno' => true,
]);
$rid = (int)$ctx['restaurante_id'];
$querRealtime = ($_GET['rt'] ?? '') === '1';
session_write_close();

header('Cache-Control: no-store');
if ($rid <= 0) {
    http_response_code(204);
    exit;
}

$db = Database::infra(); // a mesma ligacao das verificacoes de sessao (1 ligacao por pedido)
$versao = null;
try {
    $st = $db->prepare('SELECT versao FROM operacao_versao WHERE restaurante_id = ?');
    $st->execute([$rid]);
    $v = $st->fetchColumn();
    if ($v !== false) {
        $versao = 't' . $v;
    }
} catch (Throwable $e) {
    // Tabela ainda nao existe: calculo de recurso.
}
if ($versao === null) {
    try {
        $p = $db->prepare('SELECT COUNT(*), COALESCE(MAX(atualizado_em), 0), COALESCE(MAX(id), 0) FROM pedidos
            WHERE restaurante_id = ? AND atualizado_em >= NOW() - INTERVAL 2 DAY');
        $p->execute([$rid]);
        $m = $db->prepare('SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT(id, \':\', COALESCE(status, \'\')))), 0) FROM mesas WHERE restaurante_id = ?');
        $m->execute([$rid]);
        $versao = 'c' . substr(md5(implode('|', $p->fetch(PDO::FETCH_NUM)) . '|' . implode('|', $m->fetch(PDO::FETCH_NUM))), 0, 16);
    } catch (Throwable $e) {
        error_log('[operacao_versao] ' . $e->getMessage());
        $versao = 'x' . time(); // em caso de erro o ecra recarrega como antes
    }
}

if (!$querRealtime && isset($_GET['v']) && hash_equals($versao, (string)$_GET['v'])) {
    http_response_code(204);
    exit;
}

$resposta = ['v' => $versao];
if ($querRealtime) {
    $resposta['rt'] = realtime_codigo_sala($rid);
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode($resposta);
