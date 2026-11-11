<?php
declare(strict_types=1);

/*
 * Numeracao de faturas sem duplicados (Venda::gerarNumeroFatura).
 *  1. Cria o contador atomico fatura_sequencias (restaurante, ano).
 *  2. Adiciona UNIQUE (restaurante_id, numero_fatura) em vendas, para que a
 *     propria base de dados recuse uma fatura repetida. Se ja existirem
 *     duplicados historicos, a migracao para e lista-os: tem de ser revisto
 *     manualmente (sao documentos fiscais, nao se renumeram automaticamente).
 * Idempotente.
 */
return [
    'id' => '20261030_1200_fatura_sequencias',
    'description' => 'Contador atomico de faturas por restaurante e ano, e indice unico de numero_fatura.',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS fatura_sequencias (
            restaurante_id INT NOT NULL,
            ano SMALLINT UNSIGNED NOT NULL,
            ultimo INT UNSIGNED NOT NULL,
            atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (restaurante_id, ano)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $temIndice = (int)$db->query(
            "SELECT COUNT(*) FROM information_schema.statistics "
            . "WHERE table_schema = DATABASE() AND table_name = 'vendas' AND index_name = 'uq_vendas_restaurante_fatura'"
        )->fetchColumn();
        if ($temIndice > 0) {
            return;
        }

        $duplicados = $db->query("
            SELECT restaurante_id, numero_fatura, COUNT(*) AS total
            FROM vendas
            WHERE numero_fatura IS NOT NULL AND numero_fatura <> ''
            GROUP BY restaurante_id, numero_fatura
            HAVING COUNT(*) > 1
            ORDER BY total DESC
            LIMIT 20
        ")->fetchAll(PDO::FETCH_ASSOC);

        if ($duplicados) {
            $lista = [];
            foreach ($duplicados as $d) {
                $lista[] = 'restaurante ' . $d['restaurante_id'] . ' ' . $d['numero_fatura'] . ' (x' . $d['total'] . ')';
            }
            throw new RuntimeException(
                'Existem faturas duplicadas; o indice unico nao foi criado. Reveja manualmente: '
                . implode('; ', $lista)
            );
        }

        $db->exec('ALTER TABLE vendas ADD UNIQUE KEY uq_vendas_restaurante_fatura (restaurante_id, numero_fatura)');
    },
];
