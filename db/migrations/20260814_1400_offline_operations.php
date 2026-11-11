<?php
declare(strict_types=1);

return [
    'id' => '20260814_1400_offline_operations',
    'description' => 'Cria o registo idempotente das operacoes reenviadas pela fila offline.',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS offline_operations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            op_id VARCHAR(100) NOT NULL,
            restaurante_id INT NOT NULL,
            usuario_id INT NULL,
            endpoint VARCHAR(190) NOT NULL,
            request_hash CHAR(64) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'PROCESSING',
            response_json LONGTEXT NULL,
            http_status SMALLINT NULL,
            attempts INT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_offline_operation (restaurante_id, op_id),
            KEY idx_offline_operations_status (status, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $required = [
            'id', 'op_id', 'restaurante_id', 'usuario_id', 'endpoint', 'request_hash',
            'status', 'response_json', 'http_status', 'attempts', 'created_at',
            'updated_at', 'completed_at',
        ];
        $columns = $db->query(
            "SELECT column_name FROM information_schema.columns "
            . "WHERE table_schema = DATABASE() AND table_name = 'offline_operations'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $missing = array_diff($required, array_map('strtolower', $columns));
        if ($missing !== []) {
            throw new RuntimeException('offline_operations existe com colunas em falta: ' . implode(', ', $missing));
        }

        foreach (['uq_offline_operation', 'idx_offline_operations_status'] as $index) {
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.statistics '
                . "WHERE table_schema = DATABASE() AND table_name = 'offline_operations' AND index_name = ?"
            );
            $stmt->execute([$index]);
            if ((int)$stmt->fetchColumn() === 0) {
                throw new RuntimeException('Indice obrigatorio ausente em offline_operations: ' . $index);
            }
        }
    },
];
