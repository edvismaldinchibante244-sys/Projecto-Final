<?php
require_once __DIR__ . '/../app/src/config/database.php';
require_once __DIR__ . '/../app/src/config/promocao_schema.php';
require_once __DIR__ . '/../app/src/Model/Venda.php';

$db = (new Database())->getConnection();
promocao_schema_garantir($db);

$contexto = $db->query("SELECT pr.id AS promocao_id, pr.restaurante_id, pi.produto_id
    FROM promocoes pr
    INNER JOIN promocao_itens pi ON pi.promocao_id = pr.id
    ORDER BY pr.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$contexto) {
    fwrite(STDERR, "FALHA: nenhuma promocao com produto disponivel para testar.\n");
    exit(1);
}

$vendaStmt = $db->prepare("SELECT id FROM vendas
    WHERE restaurante_id = :rid AND UPPER(TRIM(COALESCE(status, ''))) = 'PAGO'
    ORDER BY id DESC LIMIT 1");
$vendaStmt->execute(['rid' => (int)$contexto['restaurante_id']]);
$vendaId = (int)$vendaStmt->fetchColumn();
if ($vendaId <= 0) {
    fwrite(STDERR, "FALHA: nenhuma venda paga do restaurante para testar atribuicao.\n");
    exit(1);
}

$db->beginTransaction();
try {
    $model = new Venda($db);
    $ok = $model->adicionarItem(
        $vendaId,
        (int)$contexto['produto_id'],
        1,
        1.23,
        (int)$contexto['promocao_id']
    );
    if (!$ok) {
        throw new RuntimeException('Venda::adicionarItem devolveu false.');
    }

    $check = $db->prepare('SELECT promocao_id, subtotal FROM itens_venda WHERE id = LAST_INSERT_ID()');
    $check->execute();
    $row = $check->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['promocao_id'] !== (int)$contexto['promocao_id'] || abs((float)$row['subtotal'] - 1.23) > 0.001) {
        throw new RuntimeException('A origem promocional nao foi preservada em itens_venda.');
    }

    $metricas = $db->prepare("SELECT COUNT(DISTINCT iv.venda_id) AS transacoes, SUM(iv.subtotal) AS receita
        FROM itens_venda iv
        INNER JOIN vendas v ON v.id = iv.venda_id
        INNER JOIN promocoes pr ON pr.id = iv.promocao_id AND pr.restaurante_id = v.restaurante_id
        WHERE v.restaurante_id = :rid AND iv.promocao_id = :promo
          AND UPPER(TRIM(COALESCE(v.status, ''))) = 'PAGO'");
    $metricas->execute([
        'rid' => (int)$contexto['restaurante_id'],
        'promo' => (int)$contexto['promocao_id'],
    ]);
    $resultado = $metricas->fetch(PDO::FETCH_ASSOC);
    if (!$resultado || (int)$resultado['transacoes'] < 1 || (float)$resultado['receita'] < 1.23) {
        throw new RuntimeException('A agregacao comercial nao encontrou a venda promocional.');
    }

    $db->rollBack();
    echo "OK: promocao_id e metricas comerciais validados; transacao revertida.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, 'FALHA: ' . $e->getMessage() . "\n");
    exit(1);
}
