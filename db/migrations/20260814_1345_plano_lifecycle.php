<?php
declare(strict_types=1);

return [
    'id' => '20260814_1345_plano_lifecycle',
    'description' => 'Cria logs de cobranca e controlo idempotente de notificacoes do ciclo de planos.',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS plano_cobranca_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            restaurante_id INT NOT NULL,
            evento VARCHAR(80) NOT NULL,
            severidade VARCHAR(20) NOT NULL DEFAULT 'INFO',
            detalhes_json JSON NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pcl_restaurante (restaurante_id),
            INDEX idx_pcl_evento (evento),
            INDEX idx_pcl_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS plano_lifecycle_notificacoes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            restaurante_id INT NOT NULL,
            data_fim DATE NOT NULL,
            dias_restantes INT NOT NULL,
            canal VARCHAR(20) NOT NULL DEFAULT 'email',
            destinatario VARCHAR(190) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_lifecycle_notif (restaurante_id, data_fim, dias_restantes, canal),
            INDEX idx_lifecycle_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $required = [
            'plano_cobranca_logs' => [
                'id', 'restaurante_id', 'evento', 'severidade', 'detalhes_json', 'created_at',
            ],
            'plano_lifecycle_notificacoes' => [
                'id', 'restaurante_id', 'data_fim', 'dias_restantes', 'canal',
                'destinatario', 'created_at',
            ],
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

        $indexes = [
            'plano_cobranca_logs' => ['idx_pcl_restaurante', 'idx_pcl_evento', 'idx_pcl_created'],
            'plano_lifecycle_notificacoes' => ['uniq_lifecycle_notif', 'idx_lifecycle_created'],
        ];
        foreach ($indexes as $table => $names) {
            foreach ($names as $index) {
                $stmt = $db->prepare(
                    'SELECT COUNT(*) FROM information_schema.statistics '
                    . 'WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
                );
                $stmt->execute([$table, $index]);
                if ((int)$stmt->fetchColumn() === 0) {
                    throw new RuntimeException('Indice obrigatorio ausente: ' . $table . '.' . $index);
                }
            }
        }
    },
];
