<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/security.php';
security_start_session();
security_set_headers();

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/pedido_schema.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo nao permitido']);
    exit;
}

$ctx = api_guard([
    'roles' => ['ADMIN'],
    'rate_key' => 'pedidos_limpar_duplicados',
    'rate_max' => 10,
    'rate_window' => 60,
    'skip_turno' => true,
]);

$rid = (int)($ctx['restaurante_id'] ?? 0);
if ($rid <= 0) {
    echo json_encode(['success' => false, 'message' => 'Restaurante invalido']);
    exit;
}

$db = (new Database())->getConnection();
pedido_schema_garantir($db);
$errorLog = nv_storage('logs/pedidos_limpar_duplicados_error.log');

try {
    $sql = "
        SELECT
            p.id,
            p.mesa_id,
            COALESCE(p.origem, 'BALCAO') AS origem,
            COALESCE(p.observacao, '') AS observacao,
            p.total,
            p.criado_em,
            GROUP_CONCAT(CONCAT(ip.produto_id, ':', ip.quantidade) ORDER BY ip.produto_id, ip.quantidade SEPARATOR '|') AS assinatura
        FROM pedidos p
        INNER JOIN itens_pedido ip ON ip.pedido_id = p.id
        WHERE p.restaurante_id = :rid
          AND (p.criado_em >= CURDATE() AND p.criado_em < CURDATE() + INTERVAL 1 DAY)
          AND p.status IN ('NOVO', 'PENDENTE', 'CONFIRMADO', 'PREPARANDO', 'PRONTO', 'ENTREGUE', 'CONTA', 'CONTA_SOLICITADA')
        GROUP BY p.id, p.mesa_id, p.origem, p.observacao, p.total, p.criado_em
        ORDER BY p.criado_em ASC, p.id ASC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([':rid' => $rid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $grupos = [];
    foreach ($rows as $r) {
        $obsRaw = trim((string)$r['observacao']);
        $obsNorm = function_exists('mb_strtolower')
            ? mb_strtolower($obsRaw, 'UTF-8')
            : strtolower($obsRaw);

        $key = implode('|', [
            (int)$r['mesa_id'],
            strtoupper(trim((string)$r['origem'])),
            number_format((float)$r['total'], 2, '.', ''),
            $obsNorm,
            (string)($r['assinatura'] ?? ''),
        ]);
        $grupos[$key][] = $r;
    }

    $paraCancelar = [];
    foreach ($grupos as $lista) {
        if (count($lista) <= 1) {
            continue;
        }

        $manter = $lista[0];
        $tsManter = strtotime((string)$manter['criado_em']) ?: 0;
        for ($i = 1; $i < count($lista); $i++) {
            $cand = $lista[$i];
            $tsCand = strtotime((string)$cand['criado_em']) ?: 0;
            if (($tsCand - $tsManter) <= 90) {
                $paraCancelar[] = (int)$cand['id'];
            } else {
                $manter = $cand;
                $tsManter = $tsCand;
            }
        }
    }

    if (empty($paraCancelar)) {
        echo json_encode([
            'success' => true,
            'message' => 'Nenhum pedido duplicado encontrado hoje.',
            'cancelados' => 0,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $db->beginTransaction();

    $in = implode(',', array_fill(0, count($paraCancelar), '?'));
    $updPedidos = $db->prepare("UPDATE pedidos SET status = 'CANCELADO', atualizado_em = NOW(), cancelado_em = COALESCE(cancelado_em, NOW()) WHERE restaurante_id = ? AND id IN ($in)");
    $updPedidos->execute(array_merge([$rid], $paraCancelar));

    $updItens = $db->prepare("UPDATE itens_pedido SET status = 'entregue', entregue_em = COALESCE(entregue_em, NOW()) WHERE pedido_id IN ($in) AND COALESCE(status,'pendente') IN ('pendente','em_preparo','pronto')");
    $updItens->execute($paraCancelar);

    $db->prepare("
        UPDATE mesas m
        SET m.status = 'LIVRE'
        WHERE m.restaurante_id = :rid
          AND m.status = 'OCUPADA'
          AND NOT EXISTS (
              SELECT 1
              FROM pedidos p
              WHERE p.restaurante_id = m.restaurante_id
                AND p.mesa_id = m.id
                AND p.status IN ('NOVO', 'PENDENTE', 'CONFIRMADO', 'PREPARANDO', 'PRONTO', 'ENTREGUE', 'CONTA', 'CONTA_SOLICITADA')
          )
    ")->execute([':rid' => $rid]);

    $db->commit();

    if (function_exists('security_audit_event')) {
        security_audit_event('pedidos_duplicados_limpos', [
            'restaurante_id' => $rid,
            'usuario_id' => (int)($_SESSION['usuario_id'] ?? 0),
            'cancelados' => count($paraCancelar),
            'pedido_ids' => $paraCancelar,
        ]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Duplicados limpos com sucesso.',
        'cancelados' => count($paraCancelar),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('pedidos_limpar_duplicados: ' . $e->getMessage());
    @file_put_contents(
        $errorLog,
        '[' . date('Y-m-d H:i:s') . '] ' . $e->getMessage() . PHP_EOL,
        FILE_APPEND
    );
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro ao limpar duplicados.'], JSON_UNESCAPED_UNICODE);
}
