<?php
require_once __DIR__ . '/../app_base.php';

header('Content-Type: application/json; charset=utf-8');

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/restaurante_context.php';
require_once APP_BASE . '/src/config/audit.php';

/*
 * Numeros do topo do dashboard do administrador.
 *  GET  -> vendas de hoje, fila de pedidos, mesas e caixa (para actualizar sem recarregar);
 *  POST -> guarda a meta de vendas do dia (config.meta_vendas_dia).
 */
api_guard([
    'roles' => ['ADMIN'],
    'rate_key' => 'dashboard_resumo',
    'rate_max' => 30,
    'rate_window' => 60,
    'skip_turno' => true,
]);

$rid = (int)session_restaurante_contexto_id();
if ($rid <= 0) {
    security_json(['success' => false, 'message' => 'Restaurante invalido.'], 422);
}

try {
    $db = (new Database())->getConnection();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $meta = (float)str_replace(',', '.', (string)($_POST['meta'] ?? '0'));
        if ($meta < 0 || $meta > 999999999) {
            security_json(['success' => false, 'message' => 'Valor de meta inválido.'], 422);
        }
        $db->prepare("INSERT INTO config (restaurante_id, chave, valor, updated_at) VALUES (?, 'meta_vendas_dia', ?, NOW())
            ON DUPLICATE KEY UPDATE valor = VALUES(valor), updated_at = NOW()")
            ->execute([$rid, (string)round($meta, 2)]);
        audit_log($db, 'meta_vendas_dia_alterada', ['restaurante_id' => $rid, 'meta' => round($meta, 2)]);
        security_json(['success' => true, 'message' => $meta > 0 ? 'Meta do dia guardada.' : 'Meta do dia removida.']);
    }

    $st = $db->prepare("SELECT COUNT(*) AS qtd, COALESCE(SUM(total_final), 0) AS total FROM vendas
        WHERE restaurante_id = ? AND UPPER(TRIM(COALESCE(status, ''))) = 'PAGO' AND criado_em >= CURDATE()");
    $st->execute([$rid]);
    $vendas = $st->fetch(PDO::FETCH_ASSOC) ?: ['qtd' => 0, 'total' => 0];

    $st = $db->prepare("SELECT
            SUM(CASE WHEN UPPER(TRIM(status)) IN ('NOVO','PENDENTE','PREPARANDO') THEN 1 ELSE 0 END) AS pendentes,
            SUM(CASE WHEN UPPER(TRIM(status)) = 'PREPARANDO' THEN 1 ELSE 0 END) AS preparando,
            SUM(CASE WHEN UPPER(TRIM(status)) = 'PRONTO' THEN 1 ELSE 0 END) AS prontos
        FROM pedidos WHERE restaurante_id = ?");
    $st->execute([$rid]);
    $fila = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $st = $db->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'OCUPADA' THEN 1 ELSE 0 END) AS ocupadas
        FROM mesas WHERE restaurante_id = ?");
    $st->execute([$rid]);
    $mesas = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $st = $db->prepare("SELECT COUNT(*) FROM caixas WHERE restaurante_id = ? AND UPPER(status) = 'ABERTO'");
    $st->execute([$rid]);
    $caixaAberto = (int)$st->fetchColumn() > 0;

    $qtd = (int)$vendas['qtd'];
    $total = (float)$vendas['total'];
    security_json([
        'success' => true,
        'total_hoje' => $total,
        'qtd_hoje' => $qtd,
        'ticket' => $qtd > 0 ? $total / $qtd : 0,
        'fila_total' => (int)($fila['pendentes'] ?? 0) + (int)($fila['preparando'] ?? 0) + (int)($fila['prontos'] ?? 0),
        'mesas_ocupadas' => (int)($mesas['ocupadas'] ?? 0),
        'mesas_total' => (int)($mesas['total'] ?? 0),
        'caixa_aberto' => $caixaAberto,
        'hora' => date('H:i'),
    ]);
} catch (Throwable $e) {
    error_log('dashboard_resumo: ' . $e->getMessage());
    security_json(['success' => false, 'message' => 'Não foi possível carregar os números.'], 500);
}
