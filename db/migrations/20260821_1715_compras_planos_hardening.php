<?php
declare(strict_types=1);

return [
    'id' => '20260821_1715_compras_planos_hardening',
    'description' => 'Adiciona hash do comprovativo e permite TRIAL nas compras de planos.',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $columns = $db->query(
            "SELECT column_name FROM information_schema.columns "
            . "WHERE table_schema = DATABASE() AND table_name = 'compras_planos'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $columns = array_map('strtolower', $columns);

        if ($columns === []) {
            throw new RuntimeException('Tabela base compras_planos inexistente; migracao interrompida.');
        }
        foreach (['comprovativo_path', 'metodo_pagamento'] as $required) {
            if (!in_array($required, $columns, true)) {
                throw new RuntimeException('Coluna base compras_planos.' . $required . ' inexistente; migracao interrompida.');
            }
        }

        if (!in_array('hash_arquivo', $columns, true)) {
            $db->exec('ALTER TABLE compras_planos ADD COLUMN hash_arquivo CHAR(64) NULL AFTER comprovativo_path');
        }

        $index = $db->query(
            "SELECT COUNT(*) FROM information_schema.statistics "
            . "WHERE table_schema = DATABASE() AND table_name = 'compras_planos' "
            . "AND index_name = 'idx_compras_hash_arquivo'"
        );
        if ((int)$index->fetchColumn() === 0) {
            $db->exec('CREATE INDEX idx_compras_hash_arquivo ON compras_planos (hash_arquivo)');
        }

        $method = $db->query(
            "SELECT column_type FROM information_schema.columns "
            . "WHERE table_schema = DATABASE() AND table_name = 'compras_planos' "
            . "AND column_name = 'metodo_pagamento' LIMIT 1"
        )->fetchColumn();
        if (!is_string($method) || $method === '') {
            throw new RuntimeException('Definicao de compras_planos.metodo_pagamento indisponivel.');
        }
        if (stripos($method, "'trial'") === false) {
            $allowed = ['DINHEIRO', 'MPESA', 'CARTAO', 'TRANSFERENCIA', 'TRIAL'];
            $values = $db->query(
                "SELECT DISTINCT UPPER(metodo_pagamento) FROM compras_planos "
                . 'WHERE metodo_pagamento IS NOT NULL'
            )->fetchAll(PDO::FETCH_COLUMN);
            $unsupported = array_diff(array_map('strtoupper', $values), $allowed);
            if ($unsupported !== []) {
                throw new RuntimeException(
                    'Metodo de pagamento nao suportado; migracao interrompida: ' . implode(', ', $unsupported)
                );
            }
            $db->exec(
                "ALTER TABLE compras_planos MODIFY COLUMN metodo_pagamento "
                . "ENUM('DINHEIRO','MPESA','CARTAO','TRANSFERENCIA','TRIAL') NOT NULL"
            );
        }

        $hash = $db->query(
            "SELECT data_type, character_maximum_length, is_nullable "
            . "FROM information_schema.columns WHERE table_schema = DATABASE() "
            . "AND table_name = 'compras_planos' AND column_name = 'hash_arquivo' LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($hash)
            || strtolower((string)$hash['data_type']) !== 'char'
            || (int)$hash['character_maximum_length'] !== 64
            || strtoupper((string)$hash['is_nullable']) !== 'YES') {
            throw new RuntimeException('Coluna compras_planos.hash_arquivo possui definicao incompativel.');
        }
    },
];
