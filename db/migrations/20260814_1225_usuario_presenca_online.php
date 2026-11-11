<?php
declare(strict_types=1);

return [
    'id' => '20260814_1225_usuario_presenca_online',
    'description' => 'Adiciona ultimo acesso para presenca online dos utilizadores.',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $table = $db->query(
            "SELECT COUNT(*) FROM information_schema.tables "
            . "WHERE table_schema = DATABASE() AND table_name = 'usuarios'"
        );
        if ((int)$table->fetchColumn() !== 1) {
            throw new RuntimeException('Tabela base usuarios inexistente; migracao interrompida.');
        }

        $stmt = $db->query(
            "SELECT DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.columns "
            . "WHERE table_schema = DATABASE() AND table_name = 'usuarios' "
            . "AND column_name = 'ultimo_acesso' LIMIT 1"
        );
        $column = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$column) {
            $db->exec('ALTER TABLE usuarios ADD COLUMN ultimo_acesso DATETIME NULL DEFAULT NULL');
            return;
        }

        $type = strtolower((string)($column['DATA_TYPE'] ?? ''));
        $nullable = strtoupper((string)($column['IS_NULLABLE'] ?? ''));
        if ($type !== 'datetime' || $nullable !== 'YES') {
            throw new RuntimeException('usuarios.ultimo_acesso existe com definicao incompativel; requer revisao manual.');
        }
    },
];
