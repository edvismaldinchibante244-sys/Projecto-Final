<?php
declare(strict_types=1);

return [
    'id' => '20260814_1255_audit_logs',
    'description' => 'Cria a tabela central de auditoria e respectivos indices.',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS audit_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event VARCHAR(120) NOT NULL,
            severity VARCHAR(20) NOT NULL DEFAULT 'INFO',
            usuario_id INT NULL,
            restaurante_id INT NULL,
            ip VARCHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            method VARCHAR(12) NULL,
            uri VARCHAR(500) NULL,
            context_json JSON NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_audit_event (event),
            INDEX idx_audit_usuario (usuario_id),
            INDEX idx_audit_restaurante (restaurante_id),
            INDEX idx_audit_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $requiredColumns = [
            'id', 'event', 'severity', 'usuario_id', 'restaurante_id', 'ip',
            'user_agent', 'method', 'uri', 'context_json', 'created_at',
        ];
        $columns = $db->query(
            "SELECT column_name FROM information_schema.columns "
            . "WHERE table_schema = DATABASE() AND table_name = 'audit_logs'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $columns = array_map('strtolower', $columns);
        $missing = array_diff($requiredColumns, $columns);
        if ($missing !== []) {
            throw new RuntimeException('audit_logs existe com colunas em falta: ' . implode(', ', $missing));
        }

        foreach (['idx_audit_event', 'idx_audit_usuario', 'idx_audit_restaurante', 'idx_audit_created'] as $index) {
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.statistics '
                . "WHERE table_schema = DATABASE() AND table_name = 'audit_logs' AND index_name = ?"
            );
            $stmt->execute([$index]);
            if ((int)$stmt->fetchColumn() === 0) {
                throw new RuntimeException('Indice obrigatorio ausente em audit_logs: ' . $index);
            }
        }
    },
];
