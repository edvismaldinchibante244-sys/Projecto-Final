<?php

require_once __DIR__ . '/migration_state.php';

if (!defined('CARRINHO_CLIENTE_SCHEMA_MIGRATION_ID')) {
    define('CARRINHO_CLIENTE_SCHEMA_MIGRATION_ID', '20260814_1240_cliente_carrinho');
}
/**
 * Schema do carrinho do app cliente (API v1).
 */

if (!function_exists('carrinho_cliente_ensure_schema')) {
    function carrinho_cliente_ensure_schema(PDO $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        if (migration_esta_aplicada($db, CARRINHO_CLIENTE_SCHEMA_MIGRATION_ID)) {
            $done = true;
            return;
        }
        $done = true;

        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS cliente_carrinhos (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    cliente_id INT NOT NULL,
                    restaurante_id INT NOT NULL,
                    atualizado_em DATETIME NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_carrinho_cliente_rest (cliente_id, restaurante_id),
                    INDEX idx_carrinho_rest (restaurante_id),
                    CONSTRAINT fk_carrinho_cliente
                        FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            error_log('[CARRINHO][SCHEMA_HEAD] ' . $e->getMessage());
        }

        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS cliente_carrinho_itens (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            error_log('[CARRINHO][SCHEMA_ITENS] ' . $e->getMessage());
        }
    }
}
