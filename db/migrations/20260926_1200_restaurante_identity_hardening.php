<?php
declare(strict_types=1);

return [
    'id' => '20260926_1200_restaurante_identity_hardening',
    'description' => 'Garante identificadores unicos para NUIT e telefone de restaurantes.',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $columns = $db->query(
            "SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'restaurantes'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $columns = array_map('strtolower', $columns);
        if ($columns === []) {
            throw new RuntimeException('Tabela restaurantes inexistente; migracao interrompida.');
        }

        if (!in_array('telefone_lookup', $columns, true)) {
            $db->exec('ALTER TABLE restaurantes ADD COLUMN telefone_lookup CHAR(64) NULL AFTER telefone');
        }

        $dupNuit = $db->query(
            "SELECT nuit_lookup, COUNT(*) total FROM restaurantes "
            . "WHERE nuit_lookup IS NOT NULL AND nuit_lookup <> '' GROUP BY nuit_lookup HAVING COUNT(*) > 1 LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if ($dupNuit) {
            throw new RuntimeException('Existem NUITs duplicados; corrija-os antes de aplicar o indice unico.');
        }

        $nuitIndex = $db->query(
            "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() "
            . "AND table_name = 'restaurantes' AND index_name = 'uq_restaurantes_nuit_lookup'"
        );
        if ((int)$nuitIndex->fetchColumn() === 0) {
            $db->exec('CREATE UNIQUE INDEX uq_restaurantes_nuit_lookup ON restaurantes (nuit_lookup)');
        }

        $phoneIndex = $db->query(
            "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() "
            . "AND table_name = 'restaurantes' AND index_name = 'uq_restaurantes_telefone_lookup'"
        );
        if ((int)$phoneIndex->fetchColumn() === 0) {
            $db->exec('CREATE UNIQUE INDEX uq_restaurantes_telefone_lookup ON restaurantes (telefone_lookup)');
        }
    },
];
