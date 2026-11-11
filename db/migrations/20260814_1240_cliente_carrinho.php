<?php
declare(strict_types=1);

return [
    'id' => '20260814_1240_cliente_carrinho',
    'description' => 'Cria carrinhos persistentes e respectivos itens do cliente.',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $clientes = $db->query(
            "SELECT COUNT(*) FROM information_schema.tables "
            . "WHERE table_schema = DATABASE() AND table_name = 'clientes'"
        );
        if ((int)$clientes->fetchColumn() !== 1) {
            throw new RuntimeException('Tabela base clientes inexistente; migracao interrompida.');
        }

        $db->exec("CREATE TABLE IF NOT EXISTS cliente_carrinhos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cliente_id INT NOT NULL,
            restaurante_id INT NOT NULL,
            atualizado_em DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_carrinho_cliente_rest (cliente_id, restaurante_id),
            INDEX idx_carrinho_rest (restaurante_id),
            CONSTRAINT fk_carrinho_cliente
                FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS cliente_carrinho_itens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            carrinho_id INT NOT NULL,
            produto_id INT NOT NULL,
            quantidade DECIMAL(10,2) NOT NULL DEFAULT 1,
            preco_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_carrinho_produto (carrinho_id, produto_id),
            INDEX idx_carrinho_itens_produto (produto_id),
            CONSTRAINT fk_carrinho_itens_carrinho
                FOREIGN KEY (carrinho_id) REFERENCES cliente_carrinhos(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $required = [
            'cliente_carrinhos' => ['id', 'cliente_id', 'restaurante_id', 'atualizado_em', 'created_at'],
            'cliente_carrinho_itens' => ['id', 'carrinho_id', 'produto_id', 'quantidade', 'preco_unitario', 'created_at', 'updated_at'],
        ];
        foreach ($required as $table => $columns) {
            $stmt = $db->prepare(
                'SELECT column_name FROM information_schema.columns '
                . 'WHERE table_schema = DATABASE() AND table_name = ?'
            );
            $stmt->execute([$table]);
            $found = array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));
            $missing = array_diff($columns, $found);
            if ($missing !== []) {
                throw new RuntimeException($table . ' existe com colunas em falta: ' . implode(', ', $missing));
            }
        }

        foreach (['uq_carrinho_cliente_rest', 'uq_carrinho_produto'] as $index) {
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.statistics '
                . 'WHERE table_schema = DATABASE() AND index_name = ?'
            );
            $stmt->execute([$index]);
            if ((int)$stmt->fetchColumn() === 0) {
                throw new RuntimeException('Indice obrigatorio ausente: ' . $index);
            }
        }
    },
];
