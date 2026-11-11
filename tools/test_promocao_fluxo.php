<?php
require_once __DIR__ . '/../app/src/config/database.php';
require_once __DIR__ . '/../app/src/config/plano_check.php';
require_once __DIR__ . '/../app/src/config/promocao_schema.php';

$db = (new Database())->getConnection();
promocao_schema_garantir($db);
$promoColumn = $db->query("SHOW COLUMNS FROM itens_pedido LIKE 'promocao_id'")->fetch(PDO::FETCH_ASSOC);
if (!$promoColumn) {
    fwrite(STDERR, "FALHA: coluna itens_pedido.promocao_id ausente.\n");
    exit(1);
}
$vendaPromoColumn = $db->query("SHOW COLUMNS FROM itens_venda LIKE 'promocao_id'")->fetch(PDO::FETCH_ASSOC);
if (!$vendaPromoColumn) {
    fwrite(STDERR, "FALHA: coluna itens_venda.promocao_id ausente.\n");
    exit(1);
}
$restaurantes = $db->query('SELECT id FROM restaurantes ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$rid = 0;
$produtos = [];
foreach ($restaurantes as $candidate) {
    $plano = plano_normalizar_nome((plano_get_dados((int)$candidate)['plano_nome'] ?? 'BASICO'));
    if (!promocao_plano_permitido($plano)) continue;
    $stmt = $db->prepare('SELECT id FROM produtos WHERE restaurante_id=:rid AND ativo=1 ORDER BY id LIMIT 2');
    $stmt->execute(['rid' => (int)$candidate]);
    $produtos = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($produtos) === 2) {
        $rid = (int)$candidate;
        break;
    }
}
if ($rid <= 0) {
    fwrite(STDERR, "FALHA: nenhum restaurante Profissional/Empresarial com dois produtos ativos.\n");
    exit(1);
}

try {
    $db->beginTransaction();
    $stmt = $db->prepare("INSERT INTO promocoes (restaurante_id,nome,preco_promocional,ativo) VALUES (:rid,'TESTE COMBO',800,1)");
    $stmt->execute(['rid' => $rid]);
    $promoId = (int)$db->lastInsertId();
    $item = $db->prepare('INSERT INTO promocao_itens (promocao_id,produto_id,quantidade) VALUES (:promo,:produto,:qtd)');
    $item->execute(['promo' => $promoId, 'produto' => (int)$produtos[0], 'qtd' => 1]);
    $item->execute(['promo' => $promoId, 'produto' => (int)$produtos[1], 'qtd' => 1]);
    $linhas = promocao_expandir_linhas($db, $rid, [['promocao_id' => $promoId, 'qtd' => 1]]);
    $total = round(array_sum(array_map(static fn($linha) => (float)$linha['_subtotal_override'], $linhas)), 2);
    if (count($linhas) !== 2 || $total !== 800.0) {
        throw new RuntimeException('Expansao incorreta: linhas=' . count($linhas) . ', total=' . $total);
    }
    $db->rollBack();
    echo "OK: combo expandido em 2 produtos, total promocional 800.00 MZN, transacao revertida.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, 'FALHA: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
