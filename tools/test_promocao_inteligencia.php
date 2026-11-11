<?php
require_once __DIR__ . '/../app/src/config/database.php';
require_once __DIR__ . '/../app/src/config/plano_check.php';

$db = (new Database())->getConnection();
$rid = 0;
foreach ($db->query('SELECT id FROM restaurantes ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $candidate) {
    $plano = plano_normalizar_nome((plano_get_dados((int)$candidate)['plano_nome'] ?? 'BASICO'));
    if ($plano === 'EMPRESARIAL') {
        $rid = (int)$candidate;
        break;
    }
}
if ($rid <= 0) {
    fwrite(STDERR, "FALHA: nenhum restaurante Empresarial encontrado.\n");
    exit(1);
}

try {
    $stmt = $db->prepare("
        SELECT p.id, p.nome, p.preco, COALESCE(NULLIF(TRIM(c.nome), ''), '') AS categoria,
               COALESCE(SUM(CASE WHEN v.id IS NOT NULL THEN iv.quantidade ELSE 0 END), 0) AS qtd_30,
               COALESCE(SUM(CASE WHEN v.id IS NOT NULL AND DATE(v.criado_em) >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) THEN iv.quantidade ELSE 0 END), 0) AS qtd_14
        FROM produtos p
        LEFT JOIN categorias c ON c.id = p.categoria_id
        LEFT JOIN itens_venda iv ON iv.produto_id = p.id
        LEFT JOIN vendas v ON v.id = iv.venda_id
            AND v.restaurante_id = p.restaurante_id
            AND UPPER(TRIM(COALESCE(v.status, ''))) = 'PAGO'
            AND DATE(v.criado_em) >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
        WHERE p.restaurante_id = :rid AND COALESCE(p.ativo, 1) = 1
        GROUP BY p.id, p.nome, p.preco, c.nome
        ORDER BY qtd_30 DESC, p.nome ASC
    ");
    $stmt->execute(['rid' => $rid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        foreach (['id', 'nome', 'preco', 'categoria', 'qtd_30', 'qtd_14'] as $column) {
            if (!array_key_exists($column, $row)) {
                throw new RuntimeException('Coluna ausente no resultado: ' . $column);
            }
        }
    }
    echo 'OK: inteligencia promocional analisou ' . count($rows) . ' produto(s) do restaurante Empresarial #' . $rid . ".\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FALHA: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
