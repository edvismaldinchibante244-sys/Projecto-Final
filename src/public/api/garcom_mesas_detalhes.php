<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Mesas com dados do pedido ativo
 */

try {
    require_once APP_BASE . '/src/config/api_guard.php';
    require_once APP_BASE . '/src/config/database.php';
    require_once APP_BASE . '/src/config/plano_check.php';
    require_once APP_BASE . '/src/config/restaurante_context.php';
    require_once APP_BASE . '/src/config/mesa_display_helper.php';

    header('Content-Type: application/json; charset=utf-8');

    api_guard([
        'roles' => ['ADMIN', 'GERENTE', 'GARCOM', 'CAIXA'],
        'rate_key' => 'garcom_mesas_detalhes',
        'rate_max' => 120,
        'rate_window' => 60,
    ]);

    $restaurante_id = (int)$_SESSION['restaurante_id'];
    $planRid = function_exists('session_restaurante_capability_id')
        ? (int)session_restaurante_capability_id()
        : $restaurante_id;
    if ($planRid <= 0) {
        $planRid = $restaurante_id;
    }
    plano_verificar_funcionalidade($planRid, 'garcons', true);

    $db = (new Database())->getConnection();

    $sql = "
        SELECT
            m.id,
            m.numero,
            m.capacidade,
            m.status,
            p.id AS pedido_id,
            p.numero_pedido,
            p.status AS pedido_status,
            p.total AS pedido_total,
            p.criado_em AS pedido_criado_em,
            p.garcom_id,
            u.nome AS garcom_nome,
            (SELECT COUNT(*) FROM itens_pedido ip WHERE ip.pedido_id = p.id) AS pedido_itens,
            (SELECT COUNT(*) FROM itens_pedido ip WHERE ip.pedido_id = p.id AND COALESCE(ip.status,'pendente') = 'pronto' AND (ip.destino = 'cozinha' OR ip.destino IS NULL)) AS itens_prontos_cozinha,
            (SELECT COUNT(*) FROM itens_pedido ip WHERE ip.pedido_id = p.id AND COALESCE(ip.status,'pendente') = 'pronto' AND ip.destino = 'bar') AS itens_prontos_bar,
            TIMESTAMPDIFF(MINUTE, p.criado_em, NOW()) AS pedido_minutos
        FROM mesas m
        LEFT JOIN pedidos p
            ON p.mesa_id = m.id
            AND p.restaurante_id = :rid
            AND p.status NOT IN ('PAGO','CANCELADO')
            AND (p.criado_em >= CURDATE() AND p.criado_em < CURDATE() + INTERVAL 1 DAY)
            AND p.id = (
                SELECT id FROM pedidos
                WHERE mesa_id = m.id
                  AND restaurante_id = :rid2
                  AND status NOT IN ('PAGO','CANCELADO')
                  AND (criado_em >= CURDATE() AND criado_em < CURDATE() + INTERVAL 1 DAY)
                ORDER BY criado_em DESC
                LIMIT 1
            )
        LEFT JOIN usuarios u ON u.id = p.garcom_id
        WHERE m.restaurante_id = :rid3
        ORDER BY m.numero ASC
    ";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':rid', $restaurante_id, PDO::PARAM_INT);
    $stmt->bindValue(':rid2', $restaurante_id, PDO::PARAM_INT);
    $stmt->bindValue(':rid3', $restaurante_id, PDO::PARAM_INT);
    $stmt->execute();
    $mesas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $mesas = array_values(array_filter($mesas, static function ($m) {
        return !mesa_eh_balcao($m['numero'] ?? '');
    }));

    foreach ($mesas as &$m) {
        $m['id'] = (int)$m['id'];
        $m['numero'] = (int)$m['numero'];
        $m['capacidade'] = (int)($m['capacidade'] ?? 4);
        $m['pedido_id'] = $m['pedido_id'] ? (int)$m['pedido_id'] : null;
        $m['pedido_total'] = $m['pedido_total'] !== null ? (float)$m['pedido_total'] : null;
        $m['pedido_itens'] = (int)$m['pedido_itens'];
        $m['itens_prontos_cozinha'] = (int)($m['itens_prontos_cozinha'] ?? 0);
        $m['itens_prontos_bar'] = (int)($m['itens_prontos_bar'] ?? 0);
        $m['pedido_minutos'] = $m['pedido_minutos'] !== null ? (int)$m['pedido_minutos'] : null;
    }
    unset($m);

    $total = count($mesas);
    $livres = count(array_filter($mesas, fn($x) => $x['status'] === 'LIVRE'));
    $ocupadas = count(array_filter($mesas, fn($x) => $x['status'] === 'OCUPADA'));
    $atrasadas = count(array_filter($mesas, fn($x) => $x['pedido_minutos'] !== null && $x['pedido_minutos'] >= 15));

    echo json_encode([
        'success' => true,
        'mesas' => $mesas,
        'stats' => compact('total', 'livres', 'ocupadas', 'atrasadas'),
        'updated_at' => date('c'),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => security_exception_message(
            $e,
            'Erro interno ao carregar as mesas.',
            'garcom_mesas_detalhes_failed'
        ),
    ]);
}
