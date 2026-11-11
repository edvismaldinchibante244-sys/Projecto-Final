<?php
declare(strict_types=1);

return [
    'id' => '20260815_0900_pedido_qr_meta',
    'description' => 'Cria os metadados idempotentes do pedido QR e adiciona observacao aos itens.',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $stmt = $db->prepare(
            'SELECT column_name FROM information_schema.columns '
            . "WHERE table_schema = DATABASE() AND table_name = 'itens_pedido'"
        );
        $stmt->execute();
        $itemColumns = array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if ($itemColumns === []) {
            throw new RuntimeException('Tabela base itens_pedido inexistente; migracao interrompida.');
        }
        if (!in_array('subtotal', $itemColumns, true)) {
            throw new RuntimeException('Coluna base itens_pedido.subtotal inexistente; migracao interrompida.');
        }

        $db->exec("CREATE TABLE IF NOT EXISTS pedido_qr_meta (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            pedido_id BIGINT UNSIGNED NOT NULL,
            restaurante_id INT NOT NULL,
            token VARCHAR(128) NOT NULL,
            idem_key VARCHAR(128) NOT NULL,
            cliente_nome VARCHAR(160) NULL,
            cliente_telefone VARCHAR(60) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_qr_idem (restaurante_id, token, idem_key),
            INDEX idx_qr_token_created (token, created_at),
            INDEX idx_qr_pedido (pedido_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if (!in_array('observacao', $itemColumns, true)) {
            $db->exec('ALTER TABLE itens_pedido ADD COLUMN observacao VARCHAR(255) NULL AFTER subtotal');
        }

        $required = [
            'id', 'pedido_id', 'restaurante_id', 'token', 'idem_key',
            'cliente_nome', 'cliente_telefone', 'created_at',
        ];
        $stmt = $db->prepare(
            'SELECT column_name FROM information_schema.columns '
            . "WHERE table_schema = DATABASE() AND table_name = 'pedido_qr_meta'"
        );
        $stmt->execute();
        $missing = array_diff($required, array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        if ($missing !== []) {
            throw new RuntimeException('pedido_qr_meta existe com colunas em falta: ' . implode(', ', $missing));
        }

        foreach (['uq_qr_idem', 'idx_qr_token_created', 'idx_qr_pedido'] as $index) {
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.statistics '
                . "WHERE table_schema = DATABASE() AND table_name = 'pedido_qr_meta' AND index_name = ?"
            );
            $stmt->execute([$index]);
            if ((int)$stmt->fetchColumn() === 0) {
                throw new RuntimeException('Indice obrigatorio ausente em pedido_qr_meta: ' . $index);
            }
        }

        $column = $db->query(
            "SELECT data_type, character_maximum_length, is_nullable "
            . "FROM information_schema.columns WHERE table_schema = DATABASE() "
            . "AND table_name = 'itens_pedido' AND column_name = 'observacao' LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($column)
            || strtolower((string)$column['data_type']) !== 'varchar'
            || (int)$column['character_maximum_length'] !== 255
            || strtoupper((string)$column['is_nullable']) !== 'YES') {
            throw new RuntimeException('Coluna itens_pedido.observacao possui definicao incompatível.');
        }
    },
];
