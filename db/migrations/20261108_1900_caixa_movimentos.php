<?php
declare(strict_types=1);

/*
 * Sangria (dinheiro que sai da gaveta durante o turno) e reforco (dinheiro que entra).
 * Entram no "esperado na gaveta" do fecho. Tambem a opcao "fecho as cegas" por restaurante.
 * Idempotente.
 */
return [
    'id' => '20261108_1900_caixa_movimentos',
    'description' => 'Sangria/reforco de caixa e opcao de fecho as cegas.',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS caixa_movimentos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            restaurante_id INT NOT NULL,
            caixa_id INT NOT NULL,
            usuario_id INT NOT NULL,
            autorizado_por INT NULL,
            tipo VARCHAR(10) NOT NULL,
            valor DECIMAL(12,2) NOT NULL,
            motivo VARCHAR(160) NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_caixa_movimentos_caixa (caixa_id, tipo),
            KEY idx_caixa_movimentos_rest (restaurante_id, criado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $col = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurantes' AND COLUMN_NAME = 'caixa_fecho_cego'")->fetchColumn();
        if ((int)$col === 0) {
            $db->exec("ALTER TABLE restaurantes ADD COLUMN caixa_fecho_cego TINYINT(1) NOT NULL DEFAULT 0");
        }
    },
];
