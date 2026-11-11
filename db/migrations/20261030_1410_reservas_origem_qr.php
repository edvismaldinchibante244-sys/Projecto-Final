<?php
declare(strict_types=1);

/*
 * Reservas feitas pelo QR gravam origem = 'qr' (reserva_publica_handler.php,
 * ReservaService::ORIGENS_VALIDAS), mas a coluna reservas.origem so aceitava
 * 'app','telefone','presencial'. Com o MySQL em modo estrito, todas as reservas
 * por QR falhavam com "Data truncated for column 'origem'".
 * Acrescenta 'qr' ao ENUM, mantendo os valores, o default e o NULL atuais.
 * Idempotente.
 */
return [
    'id' => '20261030_1410_reservas_origem_qr',
    'description' => "Aceita origem 'qr' nas reservas criadas pelo QR.",
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $col = $db->query("
            SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reservas' AND COLUMN_NAME = 'origem'
        ")->fetch(PDO::FETCH_ASSOC);
        if (!$col) {
            throw new RuntimeException('Coluna reservas.origem inexistente; migracao interrompida.');
        }

        $tipo = (string)$col['COLUMN_TYPE'];
        if (stripos($tipo, 'enum(') !== 0) {
            return; // VARCHAR ou outro tipo: ja aceita 'qr'
        }
        preg_match_all("/'((?:[^']|'')*)'/", $tipo, $m);
        $valores = array_map(static fn($v) => str_replace("''", "'", $v), $m[1]);
        if (in_array('qr', $valores, true)) {
            return;
        }
        $valores[] = 'qr';

        $lista = implode(',', array_map([$db, 'quote'], $valores));
        $nulo = strtoupper((string)$col['IS_NULLABLE']) === 'YES' ? 'NULL' : 'NOT NULL';
        $default = $col['COLUMN_DEFAULT'];
        $sqlDefault = '';
        if ($default !== null && strtoupper((string)$default) !== 'NULL') {
            $sqlDefault = ' DEFAULT ' . $db->quote(trim((string)$default, "'"));
        } elseif ($nulo === 'NULL') {
            $sqlDefault = ' DEFAULT NULL';
        }

        $db->exec("ALTER TABLE reservas MODIFY COLUMN origem ENUM({$lista}) {$nulo}{$sqlDefault}");
    },
];
