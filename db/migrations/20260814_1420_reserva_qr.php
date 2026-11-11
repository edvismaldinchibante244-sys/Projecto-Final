<?php
declare(strict_types=1);

return [
    'id' => '20260814_1420_reserva_qr',
    'description' => 'Cria sessoes e pre-bloqueios QR e amplia o ciclo de estado das reservas.',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $reservas = $db->query(
            "SELECT COUNT(*) FROM information_schema.tables "
            . "WHERE table_schema = DATABASE() AND table_name = 'reservas'"
        );
        if ((int)$reservas->fetchColumn() !== 1) {
            throw new RuntimeException('Tabela base reservas inexistente; migracao interrompida.');
        }

        $db->exec("CREATE TABLE IF NOT EXISTS reserva_qr_sessoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurante_id INT NOT NULL,
            mesa_id INT NULL,
            token VARCHAR(96) NOT NULL UNIQUE,
            nome_atendente VARCHAR(120) NULL,
            telefone_cliente VARCHAR(40) NULL,
            observacoes VARCHAR(255) NULL,
            expires_at DATETIME NOT NULL,
            consumed_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_restaurante_expires (restaurante_id, expires_at),
            INDEX idx_token_expires (token, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS reserva_pre_bloqueios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurante_id INT NOT NULL,
            mesa_id INT NOT NULL,
            token_sessao VARCHAR(96) NOT NULL,
            data_reserva DATE NOT NULL,
            hora_reserva TIME NOT NULL,
            quantidade_pessoas INT NOT NULL DEFAULT 1,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_restaurante_data_hora (restaurante_id, data_reserva, hora_reserva),
            INDEX idx_token_sessao (token_sessao),
            INDEX idx_expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $statusColumn = $db->query("SHOW COLUMNS FROM reservas LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        if (!is_array($statusColumn)) {
            throw new RuntimeException('Coluna reservas.status inexistente; migracao interrompida.');
        }
        $type = strtolower((string)($statusColumn['Type'] ?? ''));
        if (str_contains($type, 'enum(')
            && (!str_contains($type, "'chegou'") || !str_contains($type, "'concluido'"))) {
            $db->exec(
                "ALTER TABLE reservas MODIFY COLUMN status "
                . "ENUM('pendente','confirmado','chegou','concluido','no-show','cancelado') "
                . "NULL DEFAULT 'pendente'"
            );
        }

        $required = [
            'reserva_qr_sessoes' => ['id', 'restaurante_id', 'mesa_id', 'token', 'nome_atendente', 'telefone_cliente', 'observacoes', 'expires_at', 'consumed_at', 'created_at', 'updated_at'],
            'reserva_pre_bloqueios' => ['id', 'restaurante_id', 'mesa_id', 'token_sessao', 'data_reserva', 'hora_reserva', 'quantidade_pessoas', 'expires_at', 'created_at', 'updated_at'],
        ];
        foreach ($required as $table => $columns) {
            $stmt = $db->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?');
            $stmt->execute([$table]);
            $missing = array_diff($columns, array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN)));
            if ($missing !== []) {
                throw new RuntimeException($table . ' existe com colunas em falta: ' . implode(', ', $missing));
            }
        }

        $indexes = [
            'reserva_qr_sessoes' => ['idx_restaurante_expires', 'idx_token_expires'],
            'reserva_pre_bloqueios' => ['idx_restaurante_data_hora', 'idx_token_sessao', 'idx_expires_at'],
        ];
        foreach ($indexes as $table => $names) {
            foreach ($names as $index) {
                $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
                $stmt->execute([$table, $index]);
                if ((int)$stmt->fetchColumn() === 0) {
                    throw new RuntimeException('Indice obrigatorio ausente: ' . $table . '.' . $index);
                }
            }
        }

        $statusColumn = $db->query("SHOW COLUMNS FROM reservas LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        $type = strtolower((string)($statusColumn['Type'] ?? ''));
        if (str_contains($type, 'enum(')
            && (!str_contains($type, "'chegou'") || !str_contains($type, "'concluido'"))) {
            throw new RuntimeException('ENUM reservas.status nao contem o ciclo QR completo.');
        }
    },
];
