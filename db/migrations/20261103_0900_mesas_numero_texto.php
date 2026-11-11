<?php
declare(strict_types=1);

/*
 * mesas.numero passa de INT para VARCHAR(30).
 * O sistema usa nomes como "B1" (balcao) e "Varanda 2"; num banco novo a coluna INT
 * gravava 0 ou dava erro. Os numeros existentes ficam iguais (12 -> "12"). Idempotente.
 */
return [
    'id' => '20261103_0900_mesas_numero_texto',
    'description' => 'Numero da mesa aceita texto (balcao B1, zonas do salao).',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $tipo = $db->query(
            "SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mesas' AND COLUMN_NAME = 'numero'"
        )->fetchColumn();
        if ($tipo === false) {
            throw new RuntimeException('Tabela mesas sem coluna numero; migracao interrompida.');
        }
        if (strtolower((string)$tipo) !== 'varchar') {
            $db->exec('ALTER TABLE mesas MODIFY numero VARCHAR(30) NOT NULL');
        }
    },
];
