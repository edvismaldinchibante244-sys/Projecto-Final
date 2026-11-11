<?php
declare(strict_types=1);

return [
    'id' => '20260814_1145_categoria_hierarquia',
    'description' => 'Adiciona hierarquia e estado activo as categorias existentes.',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $table = $db->query(
            "SELECT COUNT(*) FROM information_schema.tables "
            . "WHERE table_schema = DATABASE() AND table_name = 'categorias'"
        );
        if ((int)$table->fetchColumn() !== 1) {
            throw new RuntimeException('Tabela base categorias inexistente; migracao interrompida.');
        }

        $columnsStmt = $db->query(
            "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT "
            . "FROM information_schema.columns "
            . "WHERE table_schema = DATABASE() AND table_name = 'categorias' "
            . "AND COLUMN_NAME IN ('parent_id', 'ativo')"
        );
        $columns = [];
        foreach ($columnsStmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[strtolower((string)$column['COLUMN_NAME'])] = $column;
        }

        if (!isset($columns['parent_id'])) {
            $db->exec('ALTER TABLE categorias ADD COLUMN parent_id INT NULL DEFAULT NULL AFTER restaurante_id');
        } else {
            $type = strtolower((string)($columns['parent_id']['DATA_TYPE'] ?? ''));
            $nullable = strtoupper((string)($columns['parent_id']['IS_NULLABLE'] ?? ''));
            if (!in_array($type, ['int', 'integer'], true) || $nullable !== 'YES') {
                throw new RuntimeException('categorias.parent_id existe com definicao incompativel; requer revisao manual.');
            }
        }

        if (!isset($columns['ativo'])) {
            $db->exec('ALTER TABLE categorias ADD COLUMN ativo TINYINT(1) NOT NULL DEFAULT 1');
        } else {
            $type = strtolower((string)($columns['ativo']['DATA_TYPE'] ?? ''));
            $nullable = strtoupper((string)($columns['ativo']['IS_NULLABLE'] ?? ''));
            $default = (string)($columns['ativo']['COLUMN_DEFAULT'] ?? '');
            if ($type !== 'tinyint' || $nullable !== 'NO' || $default !== '1') {
                throw new RuntimeException('categorias.ativo existe com definicao incompativel; requer revisao manual.');
            }
        }
    },
];
