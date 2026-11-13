<?php
declare(strict_types=1);

/*
 * Separa o registo interno da plataforma dos restaurantes clientes.
 *
 * A ferramenta criar_super_admin.php cria um restaurante "Nivora (Plataforma)"
 * para poder ligar o utilizador dono da plataforma (usuarios exige
 * restaurante_id). Esse registo nao e um cliente, mas estava a ser contado
 * como tal na lista e em todos os indicadores do painel, o que inflacionava
 * os numeros do negocio em uma unidade.
 *
 * Marca-os com restaurantes.plataforma = 1. Idempotente.
 */
return [
    'id' => '20261113_1130_restaurante_plataforma',
    'description' => "Acrescenta restaurantes.plataforma e marca os registos internos existentes.",
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $temTabela = (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurantes'")->fetchColumn();
        if ($temTabela === 0) {
            return;
        }

        $temColuna = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurantes'
              AND COLUMN_NAME = 'plataforma'")->fetchColumn();

        if ($temColuna === 0) {
            $db->exec("ALTER TABLE restaurantes
                ADD COLUMN plataforma TINYINT(1) NOT NULL DEFAULT 0
                COMMENT 'Registo interno do dono da plataforma; nao e um cliente'");
            $db->exec("CREATE INDEX idx_restaurantes_plataforma ON restaurantes (plataforma)");
        }

        // Marca os que tenham um super admin associado: e o criterio seguro,
        // porque nao depende do nome do registo.
        $db->exec("UPDATE restaurantes r
            JOIN usuarios u ON u.restaurante_id = r.id AND u.super_admin = 1
            SET r.plataforma = 1
            WHERE r.plataforma = 0");

        // Apanha tambem os orfaos: registos criados pela ferramenta cujo
        // super admin foi entretanto removido. Sem isto ficariam a contar
        // como clientes para sempre.
        $db->exec("UPDATE restaurantes
            SET plataforma = 1
            WHERE plataforma = 0
              AND nome = 'Nivora (Plataforma)'
              AND id NOT IN (SELECT DISTINCT restaurante_id FROM (
                    SELECT restaurante_id FROM usuarios WHERE restaurante_id IS NOT NULL
                  ) AS u)");
    },
];
