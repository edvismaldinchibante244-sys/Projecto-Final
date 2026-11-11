<?php
declare(strict_types=1);

/*
 * Terminais de confianca + PIN da equipa (app/src/config/terminal_pin.php).
 *  - terminais: computador/tablet marcado pelo administrador como terminal do restaurante.
 *    Guarda so o hash (sha256) do codigo que fica no cookie do dispositivo.
 *  - usuarios.pin_hash: PIN de 4-6 digitos (password_hash), com contador de falhas e bloqueio.
 * Idempotente.
 */
return [
    'id' => '20261101_1200_terminais_pin',
    'description' => 'Terminais de confianca do restaurante e PIN da equipa para desbloqueio rapido.',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS terminais (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            restaurante_id INT NOT NULL,
            nome VARCHAR(80) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            criado_por INT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ultimo_uso_em DATETIME NULL,
            revogado_em DATETIME NULL,
            UNIQUE KEY uq_terminais_token (token_hash),
            KEY idx_terminais_restaurante (restaurante_id, revogado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $colunas = $db->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios'"
        )->fetchAll(PDO::FETCH_COLUMN);
        if (!$colunas) {
            throw new RuntimeException('Tabela usuarios inexistente; migracao interrompida.');
        }
        $novas = [
            'pin_hash' => 'VARCHAR(255) NULL',
            'pin_atualizado_em' => 'DATETIME NULL',
            'pin_falhas' => 'INT NOT NULL DEFAULT 0',
            'pin_bloqueado_ate' => 'DATETIME NULL',
        ];
        foreach ($novas as $nome => $tipo) {
            if (!in_array($nome, $colunas, true)) {
                $db->exec("ALTER TABLE usuarios ADD COLUMN {$nome} {$tipo}");
            }
        }
    },
];
