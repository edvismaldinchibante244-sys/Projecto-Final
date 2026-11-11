<?php
require_once __DIR__ . '/../app_base.php';

/**
 * API – Mesas com contas abertas (visão do Caixa)
 * Retorna mesas com pedido ativo pronto para pagamento.
 */

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/plano_check.php';
require_once APP_BASE . '/src/config/restaurante_context.php';

header('Content-Type: application/json; charset=utf-8');

api_guard([
    'roles' => ['ADMIN', 'GERENTE', 'CAIXA'],
    'rate_key' => 'caixa_mesas_abertas',
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
plano_verificar_funcionalidade($planRid, 'contas_abertas', true);

$db = (new Database())->getConnection();

// Busca mesas com pedido em aberto (todas as etapas antes do PAGO)
// Agora inclui pedidos pagos para permitir cancelamento
// Corrigido: variável PHP precisa de $

// Lógica antiga: buscar apenas o último pedido aberto do dia para cada mesa

// Ajuste: incluir pedidos com status ENTREGUE também
$sql = "
    SELECT
        m.id            AS mesa_id,
        m.numero        AS mesa_numero,
        m.capacidade,
        p.id            AS pedido_id,
        p.numero_pedido,
        p.status        AS pedido_status,
        p.total         AS pedido_total,
        0               AS desconto,
        p.total         AS total_bruto,
        p.criado_em     AS pedido_criado_em,
        TIMESTAMPDIFF(MINUTE, p.criado_em, NOW())  AS minutos_aberto,
        (SELECT COUNT(*) FROM itens_pedido ip WHERE ip.pedido_id = p.id) AS qtd_itens,
        u.nome          AS garcom_nome
    FROM mesas m
    INNER JOIN pedidos p
        ON p.mesa_id = m.id
        AND p.restaurante_id = :rid
        AND p.status IN ('NOVO', 'PENDENTE', 'CONFIRMADO', 'PREPARANDO', 'ABERTO', 'EM_ANDAMENTO', 'PRONTO', 'ENTREGUE', 'CONTA', 'CONTA_SOLICITADA')
        AND p.id = (
            SELECT id FROM pedidos
            WHERE mesa_id = m.id
              AND restaurante_id = :rid2
              AND status IN ('NOVO', 'PENDENTE', 'CONFIRMADO', 'PREPARANDO', 'ABERTO', 'EM_ANDAMENTO', 'PRONTO', 'ENTREGUE', 'CONTA', 'CONTA_SOLICITADA')
            ORDER BY criado_em DESC
            LIMIT 1
        )
    LEFT JOIN usuarios u ON u.id = p.garcom_id
    WHERE m.restaurante_id = :rid3
    ORDER BY p.criado_em ASC
";

$stmt = $db->prepare($sql);
$stmt->bindValue(':rid',  $restaurante_id, PDO::PARAM_INT);
$stmt->bindValue(':rid2', $restaurante_id, PDO::PARAM_INT);
$stmt->bindValue(':rid3', $restaurante_id, PDO::PARAM_INT);
$stmt->execute();
$mesas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar itens resumidos de cada pedido
$stmt_itens = $db->prepare(
    "SELECT ip.pedido_id, COALESCE(p.nome, CONCAT('Produto #', ip.produto_id)) AS produto, ip.quantidade, ip.preco_unitario
     FROM itens_pedido ip
     LEFT JOIN produtos p ON p.id = ip.produto_id
     WHERE ip.pedido_id = :pid
     ORDER BY COALESCE(p.nome, CONCAT('Produto #', ip.produto_id))"
);

foreach ($mesas as &$m) {
    $m['mesa_id']       = (int)$m['mesa_id'];
    $m['pedido_id']     = (int)$m['pedido_id'];
    $m['pedido_total']  = (float)$m['pedido_total'];
    $m['desconto']      = (float)$m['desconto'];
    $m['qtd_itens']     = (int)$m['qtd_itens'];
    $m['minutos_aberto'] = (int)$m['minutos_aberto'];

    $stmt_itens->execute(['pid' => $m['pedido_id']]);
    $m['itens'] = $stmt_itens->fetchAll(PDO::FETCH_ASSOC);
}
unset($m);

$total_aberto = array_sum(array_column($mesas, 'pedido_total'));

echo json_encode([
    'success'      => true,
    'mesas'        => $mesas,
    'qtd_abertas'  => count($mesas),
    'total_aberto' => round($total_aberto, 2),
    'updated_at'   => date('c'),
], JSON_UNESCAPED_UNICODE);

