<?php
declare(strict_types=1);

/*
 * Reservas feitas pelo QR usam origem 'qr', que nao existia no ENUM original.
 * Idempotente: so altera a coluna se o ENUM ainda nao incluir 'qr'.
 */

return [
    'id' => '20261031_0900_reserva_origem_qr',
    'description' => 'Adiciona a origem qr ao ENUM reservas.origem.',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $origemColumn = $db->query("SHOW COLUMNS FROM reservas LIKE 'origem'")->fetch(PDO::FETCH_ASSOC);
        if (!is_array($origemColumn)) {
            throw new RuntimeException('Coluna reservas.origem inexistente; migracao interrompida.');
        }

        $type = strtolower((string)($origemColumn['Type'] ?? ''));
        if (!str_contains($type, 'enum(') || str_contains($type, "'qr'")) {
            return;
        }

        $db->exec(
            "ALTER TABLE reservas MODIFY COLUMN origem "
            . "ENUM('app','telefone','presencial','qr') DEFAULT 'app'"
        );
    },
];
