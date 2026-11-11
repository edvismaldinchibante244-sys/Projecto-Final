<?php
declare(strict_types=1);

return [
    'id' => '20260814_1440_security_monitor',
    'description' => 'Cria bloqueios de IP, eventos de seguranca e a fila de sincronizacao offline.',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS blocked_ips (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(64) NOT NULL,
            motivo VARCHAR(255) NOT NULL,
            origem VARCHAR(40) NOT NULL DEFAULT 'manual',
            bloqueio_tipo ENUM('TEMPORARIO','PERMANENTE') NOT NULL DEFAULT 'TEMPORARIO',
            bloqueado_ate DATETIME NULL,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            criado_por INT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_blocked_ip (ip),
            INDEX idx_blocked_ativo (ativo),
            INDEX idx_blocked_ate (bloqueado_ate)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS security_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_name VARCHAR(80) NOT NULL,
            attack_type VARCHAR(40) NOT NULL DEFAULT 'SUSPEITO',
            severity ENUM('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL DEFAULT 'MEDIUM',
            ip VARCHAR(64) NULL,
            endpoint VARCHAR(255) NULL,
            method VARCHAR(12) NULL,
            payload_excerpt VARCHAR(600) NULL,
            source_log VARCHAR(60) NOT NULL DEFAULT 'security.log',
            context_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_security_events_created (created_at),
            INDEX idx_security_events_type (attack_type),
            INDEX idx_security_events_ip (ip)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS offline_sync_queue (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            op_id VARCHAR(80) NOT NULL,
            restaurante_id INT NULL,
            usuario_id INT NULL,
            device_id VARCHAR(120) NULL,
            endpoint VARCHAR(255) NOT NULL,
            method VARCHAR(12) NOT NULL DEFAULT 'POST',
            status ENUM('PENDING','DONE','FAILED') NOT NULL DEFAULT 'PENDING',
            attempts INT NOT NULL DEFAULT 0,
            last_error VARCHAR(255) NULL,
            payload_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            last_sync_at DATETIME NULL,
            UNIQUE KEY uq_offline_op_id (op_id),
            INDEX idx_offline_status (status),
            INDEX idx_offline_restaurante (restaurante_id),
            INDEX idx_offline_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $required = [
            'blocked_ips' => [
                'id', 'ip', 'motivo', 'origem', 'bloqueio_tipo', 'bloqueado_ate',
                'ativo', 'criado_por', 'criado_em', 'atualizado_em',
            ],
            'security_events' => [
                'id', 'event_name', 'attack_type', 'severity', 'ip', 'endpoint',
                'method', 'payload_excerpt', 'source_log', 'context_json', 'created_at',
            ],
            'offline_sync_queue' => [
                'id', 'op_id', 'restaurante_id', 'usuario_id', 'device_id', 'endpoint',
                'method', 'status', 'attempts', 'last_error', 'payload_json', 'created_at',
                'updated_at', 'last_sync_at',
            ],
        ];
        foreach ($required as $table => $columns) {
            $stmt = $db->prepare(
                'SELECT column_name FROM information_schema.columns '
                . 'WHERE table_schema = DATABASE() AND table_name = ?'
            );
            $stmt->execute([$table]);
            $missing = array_diff($columns, array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN)));
            if ($missing !== []) {
                throw new RuntimeException($table . ' existe com colunas em falta: ' . implode(', ', $missing));
            }
        }

        $indexes = [
            'blocked_ips' => ['idx_blocked_ip', 'idx_blocked_ativo', 'idx_blocked_ate'],
            'security_events' => ['idx_security_events_created', 'idx_security_events_type', 'idx_security_events_ip'],
            'offline_sync_queue' => ['uq_offline_op_id', 'idx_offline_status', 'idx_offline_restaurante', 'idx_offline_updated'],
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
