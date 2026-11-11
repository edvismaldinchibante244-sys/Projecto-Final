<?php
declare(strict_types=1);

return [
    'id' => '20260821_1745_password_resets',
    'description' => 'Versiona a tabela de redefinicao de senha, lookup de email e ligacao ao utilizador.',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $tableExists = (int)$db->query(
            "SELECT COUNT(*) FROM information_schema.tables "
            . "WHERE table_schema = DATABASE() AND table_name = 'password_resets'"
        )->fetchColumn() === 1;

        if (!$tableExists) {
            $db->exec("CREATE TABLE password_resets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email TEXT NOT NULL,
                email_lookup CHAR(64) NOT NULL,
                user_id INT NULL,
                token VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NOT NULL,
                used TINYINT(1) DEFAULT 0,
                INDEX idx_token (token),
                INDEX idx_email_lookup (email_lookup),
                INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            $columns = $db->query(
                "SELECT column_name FROM information_schema.columns "
                . "WHERE table_schema = DATABASE() AND table_name = 'password_resets'"
            )->fetchAll(PDO::FETCH_COLUMN);
            $columns = array_map('strtolower', $columns);

            foreach (['id', 'email', 'token', 'expires_at', 'used'] as $required) {
                if (!in_array($required, $columns, true)) {
                    throw new RuntimeException(
                        'Coluna base password_resets.' . $required . ' inexistente; migracao interrompida.'
                    );
                }
            }
            if (!in_array('user_id', $columns, true)) {
                $db->exec('ALTER TABLE password_resets ADD COLUMN user_id INT NULL AFTER email');
            }
            if (!in_array('email_lookup', $columns, true)) {
                $db->exec('ALTER TABLE password_resets ADD COLUMN email_lookup CHAR(64) NULL AFTER email');
            }

            foreach (['idx_token' => 'token', 'idx_email_lookup' => 'email_lookup', 'idx_user_id' => 'user_id'] as $index => $column) {
                $stmt = $db->prepare(
                    'SELECT COUNT(*) FROM information_schema.statistics '
                    . "WHERE table_schema = DATABASE() AND table_name = 'password_resets' AND index_name = ?"
                );
                $stmt->execute([$index]);
                if ((int)$stmt->fetchColumn() === 0) {
                    $db->exec('CREATE INDEX `' . $index . '` ON password_resets (`' . $column . '`)');
                }
            }
        }

        $requiredColumns = [
            'id', 'email', 'email_lookup', 'user_id', 'token',
            'created_at', 'expires_at', 'used',
        ];
        $columns = $db->query(
            "SELECT column_name FROM information_schema.columns "
            . "WHERE table_schema = DATABASE() AND table_name = 'password_resets'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $missing = array_diff($requiredColumns, array_map('strtolower', $columns));
        if ($missing !== []) {
            throw new RuntimeException('password_resets possui colunas em falta: ' . implode(', ', $missing));
        }

        foreach (['idx_token', 'idx_email_lookup', 'idx_user_id'] as $index) {
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.statistics '
                . "WHERE table_schema = DATABASE() AND table_name = 'password_resets' AND index_name = ?"
            );
            $stmt->execute([$index]);
            if ((int)$stmt->fetchColumn() !== 1) {
                throw new RuntimeException('Indice obrigatorio ausente ou duplicado em password_resets: ' . $index);
            }
        }

        $lookup = $db->query(
            "SELECT data_type, character_maximum_length FROM information_schema.columns "
            . "WHERE table_schema = DATABASE() AND table_name = 'password_resets' "
            . "AND column_name = 'email_lookup' LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($lookup)
            || strtolower((string)$lookup['data_type']) !== 'char'
            || (int)$lookup['character_maximum_length'] !== 64) {
            throw new RuntimeException('Coluna password_resets.email_lookup possui definicao incompativel.');
        }
    },
];
