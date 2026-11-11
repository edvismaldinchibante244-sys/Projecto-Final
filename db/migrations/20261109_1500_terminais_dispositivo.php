<?php
declare(strict_types=1);

/*
 * Terminais: guarda que aparelho/navegador registou o terminal (para distinguir terminais
 * com nomes parecidos) e um registo dos desbloqueios por PIN em cada terminal.
 * Idempotente.
 */
return [
    'id' => '20261109_1500_terminais_dispositivo',
    'description' => 'Dispositivo do terminal e registo de acessos por terminal.',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $temTabela = (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'terminais'")->fetchColumn();
        if ($temTabela > 0) {
            $col = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'terminais' AND COLUMN_NAME = 'dispositivo'")->fetchColumn();
            if ($col === 0) {
                $db->exec("ALTER TABLE terminais ADD COLUMN dispositivo VARCHAR(80) NULL AFTER nome");
            }
        }

        $db->exec("CREATE TABLE IF NOT EXISTS terminal_acessos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            restaurante_id INT NOT NULL,
            terminal_id INT NOT NULL,
            usuario_id INT NULL,
            resultado VARCHAR(12) NOT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_terminal_acessos_terminal (terminal_id, criado_em),
            KEY idx_terminal_acessos_rest (restaurante_id, criado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    },
];
