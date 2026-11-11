<?php
declare(strict_types=1);

/*
 * Consolida o schema do Estoque Inteligente que antes era garantido em runtime
 * por estoque_inteligente_schema_garantir() (app/src/config/estoque_inteligente.php),
 * chamado em cada pedido novo. Depois de aplicada, as requisicoes deixam de executar DDL.
 * Idempotente: CREATE TABLE IF NOT EXISTS + colunas adicionadas so se faltarem.
 */
return [
    'id' => '20261030_1100_estoque_inteligente',
    'description' => 'Estoque inteligente: insumos, fichas tecnicas, saldos, lotes, movimentos, perdas e alertas.',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $db->exec("
            CREATE TABLE IF NOT EXISTS estoque_insumos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurante_id INT NOT NULL,
                filial_id INT NULL,
                nome VARCHAR(140) NOT NULL,
                sku VARCHAR(80) NULL,
                unidade_base ENUM('g','ml','un') NOT NULL DEFAULT 'un',
                estoque_minimo_base DECIMAL(14,3) NOT NULL DEFAULT 0,
                ativo TINYINT(1) NOT NULL DEFAULT 1,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_insumo_sku (restaurante_id, filial_id, sku),
                KEY idx_insumo_rest_filial (restaurante_id, filial_id),
                KEY idx_insumo_nome (nome)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS estoque_fichas_tecnicas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurante_id INT NOT NULL,
                filial_id INT NULL,
                produto_id INT NOT NULL,
                rendimento_por_produto DECIMAL(14,3) NOT NULL DEFAULT 1,
                ativo TINYINT(1) NOT NULL DEFAULT 1,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_ficha_produto (restaurante_id, filial_id, produto_id),
                KEY idx_ficha_rest_filial (restaurante_id, filial_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS estoque_ficha_itens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ficha_id INT NOT NULL,
                insumo_id INT NOT NULL,
                quantidade_base DECIMAL(14,3) NOT NULL,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_ficha_item_ficha (ficha_id),
                KEY idx_ficha_item_insumo (insumo_id),
                CONSTRAINT fk_ficha_item_ficha FOREIGN KEY (ficha_id) REFERENCES estoque_fichas_tecnicas(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS estoque_saldos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurante_id INT NOT NULL,
                filial_id INT NULL,
                insumo_id INT NOT NULL,
                saldo_base DECIMAL(14,3) NOT NULL DEFAULT 0,
                custo_medio DECIMAL(14,4) NOT NULL DEFAULT 0,
                atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_saldo_unico (restaurante_id, filial_id, insumo_id),
                KEY idx_saldo_rest_filial (restaurante_id, filial_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS estoque_lotes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurante_id INT NOT NULL,
                filial_id INT NULL,
                insumo_id INT NOT NULL,
                quantidade_base DECIMAL(14,3) NOT NULL,
                saldo_base DECIMAL(14,3) NOT NULL,
                custo_unitario_base DECIMAL(14,4) NOT NULL DEFAULT 0,
                validade DATE NULL,
                referencia_compra VARCHAR(120) NULL,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_lote_consumo (restaurante_id, filial_id, insumo_id, validade, criado_em),
                KEY idx_lote_validade (validade)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS estoque_movimentos (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                restaurante_id INT NOT NULL,
                filial_id INT NULL,
                insumo_id INT NOT NULL,
                tipo ENUM('ENTRADA','SAIDA','PERDA','AJUSTE','TRANSFERENCIA') NOT NULL,
                origem ENUM('COMPRA','PEDIDO','COZINHA','CAIXA','MANUAL','SISTEMA') NOT NULL DEFAULT 'SISTEMA',
                quantidade_base DECIMAL(14,3) NOT NULL,
                custo_unitario_base DECIMAL(14,4) NULL,
                custo_total DECIMAL(14,4) NULL,
                unidade_origem VARCHAR(12) NULL,
                pedido_id INT NULL,
                venda_id INT NULL,
                motivo VARCHAR(180) NULL,
                responsavel_id INT NULL,
                meta_json JSON NULL,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_mov_rest_filial_data (restaurante_id, filial_id, criado_em),
                KEY idx_mov_insumo (insumo_id, criado_em),
                KEY idx_mov_origem (origem, pedido_id, venda_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS estoque_perdas (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                restaurante_id INT NOT NULL,
                filial_id INT NULL,
                insumo_id INT NOT NULL,
                quantidade_base DECIMAL(14,3) NOT NULL,
                custo_perdido DECIMAL(14,4) NOT NULL DEFAULT 0,
                motivo VARCHAR(180) NOT NULL,
                responsavel_id INT NULL,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_perda_rest_filial_data (restaurante_id, filial_id, criado_em)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS estoque_alertas (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                restaurante_id INT NOT NULL,
                filial_id INT NULL,
                insumo_id INT NULL,
                tipo ENUM('REPOSICAO','VALIDADE','CONSUMO_ANOMALO') NOT NULL,
                severidade ENUM('BAIXA','MEDIA','ALTA','CRITICA') NOT NULL DEFAULT 'MEDIA',
                titulo VARCHAR(150) NOT NULL,
                detalhe VARCHAR(255) NULL,
                resolvido TINYINT(1) NOT NULL DEFAULT 0,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                resolvido_em TIMESTAMP NULL DEFAULT NULL,
                KEY idx_alerta_rest_filial (restaurante_id, filial_id, resolvido, criado_em)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Tabelas antigas podem existir sem colunas novas.
        $cols = [];
        $stmt = $db->query(
            "SELECT LOWER(COLUMN_NAME) FROM information_schema.columns "
            . "WHERE table_schema = DATABASE() AND table_name = 'estoque_movimentos'"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $c) {
            $cols[(string)$c] = true;
        }
        if (!isset($cols['pedido_id'])) {
            $db->exec('ALTER TABLE estoque_movimentos ADD COLUMN pedido_id INT NULL AFTER unidade_origem');
        }
        if (!isset($cols['venda_id'])) {
            $db->exec('ALTER TABLE estoque_movimentos ADD COLUMN venda_id INT NULL AFTER pedido_id');
        }
        if (!isset($cols['meta_json'])) {
            $db->exec('ALTER TABLE estoque_movimentos ADD COLUMN meta_json JSON NULL AFTER responsavel_id');
        }
    },
];
