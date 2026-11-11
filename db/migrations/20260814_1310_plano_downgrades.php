<?php
declare(strict_types=1);

return [
    'id' => '20260814_1310_plano_downgrades',
    'description' => 'Cria a fila de downgrades agendados de planos.',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS plano_downgrades (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            restaurante_id INT NOT NULL,
            plano_origem VARCHAR(30) NOT NULL,
            plano_destino VARCHAR(30) NOT NULL,
            aplicar_em DATE NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'AGENDADO',
            limites_json LONGTEXT NULL,
            criado_por INT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            aplicado_em DATETIME NULL,
            cancelado_em DATETIME NULL,
            INDEX idx_downgrade_fila (status, aplicar_em),
            INDEX idx_downgrade_restaurante (restaurante_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $requiredColumns = [
            'id', 'restaurante_id', 'plano_origem', 'plano_destino', 'aplicar_em',
            'status', 'limites_json', 'criado_por', 'criado_em', 'atualizado_em',
            'aplicado_em', 'cancelado_em',
        ];
        $columns = $db->query(
            "SELECT column_name FROM information_schema.columns "
            . "WHERE table_schema = DATABASE() AND table_name = 'plano_downgrades'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $columns = array_map('strtolower', $columns);
        $missing = array_diff($requiredColumns, $columns);
        if ($missing !== []) {
            throw new RuntimeException('plano_downgrades existe com colunas em falta: ' . implode(', ', $missing));
        }

        foreach (['idx_downgrade_fila', 'idx_downgrade_restaurante'] as $index) {
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.statistics '
                . "WHERE table_schema = DATABASE() AND table_name = 'plano_downgrades' AND index_name = ?"
            );
            $stmt->execute([$index]);
            if ((int)$stmt->fetchColumn() === 0) {
                throw new RuntimeException('Indice obrigatorio ausente em plano_downgrades: ' . $index);
            }
        }
    },
];
