<?php
declare(strict_types=1);

return [
    'id' => '20260814_1210_restaurante_brand_theme',
    'description' => 'Adiciona cores primaria e secundaria ao tema do restaurante.',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $table = $db->query(
            "SELECT COUNT(*) FROM information_schema.tables "
            . "WHERE table_schema = DATABASE() AND table_name = 'restaurantes'"
        );
        if ((int)$table->fetchColumn() !== 1) {
            throw new RuntimeException('Tabela base restaurantes inexistente; migracao interrompida.');
        }

        $stmt = $db->query(
            "SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, COLUMN_DEFAULT "
            . "FROM information_schema.columns WHERE table_schema = DATABASE() "
            . "AND table_name = 'restaurantes' AND COLUMN_NAME IN ('cor_primaria','cor_secundaria')"
        );
        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[strtolower((string)$column['COLUMN_NAME'])] = $column;
        }

        $ensureColorColumn = static function (string $name) use ($db, $columns): void {
            if (!isset($columns[$name])) {
                $db->exec('ALTER TABLE restaurantes ADD COLUMN `' . $name . '` VARCHAR(7) NULL DEFAULT NULL');
                return;
            }

            $column = $columns[$name];
            $type = strtolower((string)($column['DATA_TYPE'] ?? ''));
            $length = (int)($column['CHARACTER_MAXIMUM_LENGTH'] ?? 0);
            $nullable = strtoupper((string)($column['IS_NULLABLE'] ?? ''));
            if ($type !== 'varchar' || $length !== 7 || $nullable !== 'YES') {
                throw new RuntimeException('restaurantes.' . $name . ' existe com definicao incompativel; requer revisao manual.');
            }
        };

        $ensureColorColumn('cor_primaria');
        $ensureColorColumn('cor_secundaria');
    },
];
