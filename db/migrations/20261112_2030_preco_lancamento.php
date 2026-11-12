<?php
declare(strict_types=1);

/*
 * Preco de lancamento: desconto preso ao restaurante.
 *
 * Quem adere durante a campanha mantem o desconto enquanto for cliente, por
 * isso a percentagem fica gravada no proprio restaurante em vez de ser
 * calculada a partir da data em cada cobranca. Assim, acabada a campanha, as
 * renovacoes destes clientes continuam ao preco a que aderiram.
 * Idempotente.
 */
return [
    'id' => '20261112_2030_preco_lancamento',
    'description' => "Acrescenta restaurantes.desconto_lancamento e desconto_lancamento_em.",
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $temTabela = (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurantes'")->fetchColumn();
        if ($temTabela === 0) {
            return;
        }

        $coluna = static function (string $nome) use ($db): bool {
            $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurantes'
                  AND COLUMN_NAME = :c");
            $stmt->execute([':c' => $nome]);
            return (int)$stmt->fetchColumn() > 0;
        };

        if (!$coluna('desconto_lancamento')) {
            $db->exec("ALTER TABLE restaurantes
                ADD COLUMN desconto_lancamento DECIMAL(5,2) DEFAULT NULL
                COMMENT 'Percentagem de desconto de lancamento presa a este restaurante'");
        }

        if (!$coluna('desconto_lancamento_em')) {
            $db->exec("ALTER TABLE restaurantes
                ADD COLUMN desconto_lancamento_em DATETIME DEFAULT NULL
                COMMENT 'Quando o desconto de lancamento foi atribuido'");
        }
    },
];
