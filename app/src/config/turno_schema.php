<?php

if (!function_exists('turno_schema_tabela_existe')) {
    function turno_schema_tabela_existe(PDO $db, string $tabela): bool
    {
        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :tabela
            LIMIT 1
        ");
        $stmt->bindValue(':tabela', $tabela, PDO::PARAM_STR);
        $stmt->execute();

        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('turno_schema_coluna_existe')) {
    function turno_schema_coluna_existe(PDO $db, string $tabela, string $coluna): bool
    {
        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :tabela
              AND COLUMN_NAME = :coluna
            LIMIT 1
        ");
        $stmt->bindValue(':tabela', $tabela, PDO::PARAM_STR);
        $stmt->bindValue(':coluna', $coluna, PDO::PARAM_STR);
        $stmt->execute();

        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('turno_schema_indice_existe')) {
    function turno_schema_indice_existe(PDO $db, string $tabela, string $indice): bool
    {
        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :tabela
              AND INDEX_NAME = :indice
            LIMIT 1
        ");
        $stmt->bindValue(':tabela', $tabela, PDO::PARAM_STR);
        $stmt->bindValue(':indice', $indice, PDO::PARAM_STR);
        $stmt->execute();

        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('turno_schema_exec')) {
    function turno_schema_exec(PDO $db, string $sql): bool
    {
        try {
            $db->exec($sql);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('turno_schema_fk_refere_tabela')) {
    function turno_schema_fk_refere_tabela(PDO $db, string $tabela, string $constraintName, string $referencedTable): bool
    {
        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :tabela
              AND CONSTRAINT_NAME = :constraint_name
              AND REFERENCED_TABLE_NAME = :referenced_table
            LIMIT 1
        ");
        $stmt->bindValue(':tabela', $tabela, PDO::PARAM_STR);
        $stmt->bindValue(':constraint_name', $constraintName, PDO::PARAM_STR);
        $stmt->bindValue(':referenced_table', $referencedTable, PDO::PARAM_STR);
        $stmt->execute();
        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('turno_schema_sql_completo')) {
    function turno_schema_sql_completo(): string
    {
        return <<<SQL
CREATE TABLE IF NOT EXISTS funcionarios_turnos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id BIGINT UNSIGNED NOT NULL,
    restaurante_id BIGINT UNSIGNED NOT NULL,
    cargo VARCHAR(30) NOT NULL,
    data DATE NOT NULL,
    data_saida DATE NULL DEFAULT NULL,
    turno VARCHAR(20) NOT NULL DEFAULT 'MANHA',
    hora_entrada TIME NOT NULL,
    hora_saida TIME NULL DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'ATIVO',
    observacoes TEXT NULL,
    motivo_intervencao TEXT NULL,
    responsavel_abertura_id BIGINT UNSIGNED NULL DEFAULT NULL,
    responsavel_fechamento_id BIGINT UNSIGNED NULL DEFAULT NULL,
    abertura_manual TINYINT(1) NOT NULL DEFAULT 0,
    fechamento_manual TINYINT(1) NOT NULL DEFAULT 0,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_turnos_restaurante_status (restaurante_id, status, data),
    INDEX idx_turnos_usuario_status (usuario_id, status),
    INDEX idx_turnos_restaurante_data (restaurante_id, data)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS caixa_turnos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurante_id BIGINT UNSIGNED NOT NULL,
    caixa_id BIGINT UNSIGNED NOT NULL,
    turno_id BIGINT UNSIGNED NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    data_abertura DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_fechamento DATETIME NULL DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'ABERTO',
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_caixa_turno_aberto (caixa_id, turno_id),
    INDEX idx_caixa_turnos_restaurante_status (restaurante_id, status),
    INDEX idx_caixa_turnos_turno (turno_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auditoria_turnos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurante_id BIGINT UNSIGNED NOT NULL,
    turno_id BIGINT UNSIGNED NULL DEFAULT NULL,
    responsavel_id BIGINT UNSIGNED NOT NULL,
    funcionario_afetado_id BIGINT UNSIGNED NOT NULL,
    tipo_acao VARCHAR(40) NOT NULL,
    motivo TEXT NOT NULL,
    payload_json JSON NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_auditoria_restaurante_data (restaurante_id, criado_em),
    INDEX idx_auditoria_funcionario (funcionario_afetado_id, criado_em),
    INDEX idx_auditoria_tipo (tipo_acao, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
    }
}

if (!function_exists('turno_schema_garantir')) {
    function turno_schema_garantir(PDO $db): bool
    {
        static $status = null;

        if ($status !== null) {
            return $status;
        }

        // Estas verificacoes (CREATE TABLE, colunas, correcao de dados antigos) corriam em CADA pedido
        // e atualizavam a tabela de turnos inteira. Agora correm no maximo uma vez por hora por servidor.
        require_once __DIR__ . '/cache_curto.php';
        [$jaVerificado] = cache_curto_obter('turno_schema_ok');
        if ($jaVerificado) {
            return $status = true;
        }

        $ok = true;

        $ok = turno_schema_exec($db, "
            CREATE TABLE IF NOT EXISTS funcionarios_turnos (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                usuario_id BIGINT UNSIGNED NOT NULL,
                restaurante_id BIGINT UNSIGNED NOT NULL,
                data DATE NOT NULL,
                turno VARCHAR(20) NOT NULL DEFAULT 'MANHA',
                hora_entrada TIME NULL DEFAULT NULL,
                hora_saida TIME NULL DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'ATIVO',
                observacoes TEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ") && $ok;

        $colunas = [
            'data_saida' => "ALTER TABLE funcionarios_turnos ADD COLUMN data_saida DATE NULL DEFAULT NULL AFTER data",
            'cargo' => "ALTER TABLE funcionarios_turnos ADD COLUMN cargo VARCHAR(30) NOT NULL DEFAULT 'GARCOM' AFTER restaurante_id",
            'motivo_intervencao' => "ALTER TABLE funcionarios_turnos ADD COLUMN motivo_intervencao TEXT NULL AFTER observacoes",
            'responsavel_abertura_id' => "ALTER TABLE funcionarios_turnos ADD COLUMN responsavel_abertura_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER motivo_intervencao",
            'responsavel_fechamento_id' => "ALTER TABLE funcionarios_turnos ADD COLUMN responsavel_fechamento_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER responsavel_abertura_id",
            'abertura_manual' => "ALTER TABLE funcionarios_turnos ADD COLUMN abertura_manual TINYINT(1) NOT NULL DEFAULT 0 AFTER responsavel_fechamento_id",
            'fechamento_manual' => "ALTER TABLE funcionarios_turnos ADD COLUMN fechamento_manual TINYINT(1) NOT NULL DEFAULT 0 AFTER abertura_manual",
            'criado_em' => "ALTER TABLE funcionarios_turnos ADD COLUMN criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER fechamento_manual",
            'atualizado_em' => "ALTER TABLE funcionarios_turnos ADD COLUMN atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER criado_em",
        ];

        foreach ($colunas as $coluna => $sql) {
            if (!turno_schema_coluna_existe($db, 'funcionarios_turnos', $coluna)) {
                $ok = turno_schema_exec($db, $sql) && $ok;
            }
        }

        if (!turno_schema_indice_existe($db, 'funcionarios_turnos', 'idx_turnos_restaurante_status')) {
            $ok = turno_schema_exec($db, "ALTER TABLE funcionarios_turnos ADD INDEX idx_turnos_restaurante_status (restaurante_id, status, data)") && $ok;
        }
        if (!turno_schema_indice_existe($db, 'funcionarios_turnos', 'idx_turnos_usuario_status')) {
            $ok = turno_schema_exec($db, "ALTER TABLE funcionarios_turnos ADD INDEX idx_turnos_usuario_status (usuario_id, status)") && $ok;
        }
        if (!turno_schema_indice_existe($db, 'funcionarios_turnos', 'idx_turnos_restaurante_data')) {
            $ok = turno_schema_exec($db, "ALTER TABLE funcionarios_turnos ADD INDEX idx_turnos_restaurante_data (restaurante_id, data)") && $ok;
        }

        // Corrige FK legado incorreto: funcionarios_turnos.restaurante_id -> compras_planos(restaurante_id)
        // Deve apontar para restaurantes(id).
        if (!turno_schema_fk_refere_tabela($db, 'funcionarios_turnos', 'funcionarios_turnos_ibfk_2', 'restaurantes')) {
            $ok = turno_schema_exec($db, "ALTER TABLE funcionarios_turnos DROP FOREIGN KEY funcionarios_turnos_ibfk_2") && $ok;
            $ok = turno_schema_exec(
                $db,
                "ALTER TABLE funcionarios_turnos
                 ADD CONSTRAINT funcionarios_turnos_ibfk_2
                 FOREIGN KEY (restaurante_id) REFERENCES restaurantes(id)
                 ON UPDATE CASCADE ON DELETE RESTRICT"
            ) && $ok;
        }

        $ok = turno_schema_exec($db, "
            CREATE TABLE IF NOT EXISTS caixa_turnos (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                restaurante_id BIGINT UNSIGNED NOT NULL,
                caixa_id BIGINT UNSIGNED NOT NULL,
                turno_id BIGINT UNSIGNED NOT NULL,
                usuario_id BIGINT UNSIGNED NOT NULL,
                data_abertura DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                data_fechamento DATETIME NULL DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'ABERTO',
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_caixa_turno_aberto (caixa_id, turno_id),
                INDEX idx_caixa_turnos_restaurante_status (restaurante_id, status),
                INDEX idx_caixa_turnos_turno (turno_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ") && $ok;

        $ok = turno_schema_exec($db, "
            CREATE TABLE IF NOT EXISTS auditoria_turnos (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                restaurante_id BIGINT UNSIGNED NOT NULL,
                turno_id BIGINT UNSIGNED NULL DEFAULT NULL,
                responsavel_id BIGINT UNSIGNED NOT NULL,
                funcionario_afetado_id BIGINT UNSIGNED NOT NULL,
                tipo_acao VARCHAR(40) NOT NULL,
                motivo TEXT NOT NULL,
                payload_json JSON NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_auditoria_restaurante_data (restaurante_id, criado_em),
                INDEX idx_auditoria_funcionario (funcionario_afetado_id, criado_em),
                INDEX idx_auditoria_tipo (tipo_acao, criado_em)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ") && $ok;

        // Migração defensiva de dados legados.
        $ok = turno_schema_exec($db, "
            UPDATE funcionarios_turnos
            SET data_saida = CASE
                WHEN hora_saida IS NULL OR hora_saida = '00:00:00' THEN NULL
                WHEN hora_entrada IS NOT NULL AND hora_saida < hora_entrada THEN DATE_ADD(data, INTERVAL 1 DAY)
                ELSE data
            END
            WHERE data_saida IS NULL
        ") && $ok;

        $ok = turno_schema_exec($db, "
            UPDATE funcionarios_turnos ft
            INNER JOIN usuarios u ON u.id = ft.usuario_id
            SET ft.cargo = UPPER(REPLACE(COALESCE(NULLIF(ft.cargo, ''), u.perfil), 'Ç', 'C'))
            WHERE ft.cargo IS NULL OR ft.cargo = '' OR ft.cargo = 'GARÇOM'
        ") && $ok;

        $ok = turno_schema_exec($db, "
            UPDATE funcionarios_turnos
            SET status = CASE UPPER(status)
                WHEN 'ATIVO' THEN 'ATIVO'
                WHEN 'FINALIZADO' THEN 'ENCERRADO'
                WHEN 'ENCERRADO' THEN 'ENCERRADO'
                WHEN 'PLANEJADO' THEN 'PLANEJADO'
                WHEN 'AUSENTE' THEN 'AUSENTE'
                ELSE UPPER(status)
            END
        ") && $ok;

        $status = $ok;
        // Guarda em cache quando as tabelas existem, mesmo que uma das correcoes de dados antigos falhe
        // (ex.: colacao diferente): repeti-la em cada pedido nao a faria passar.
        if ($ok || (turno_schema_tabela_existe($db, 'funcionarios_turnos') && turno_schema_tabela_existe($db, 'caixa_turnos')
            && turno_schema_tabela_existe($db, 'auditoria_turnos'))) {
            cache_curto_guardar('turno_schema_ok', 1, 3600);
        }
        return $status;
    }
}
