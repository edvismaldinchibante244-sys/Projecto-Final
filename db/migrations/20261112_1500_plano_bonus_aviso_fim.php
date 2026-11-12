<?php
declare(strict_types=1);

/*
 * Oferta de boas-vindas: coluna própria para o email de fim de oferta.
 *
 * O cartão de resumo no painel (resumo_visto_em) e o email enviado pela
 * tarefa agendada passam a ter marcas separadas, para um não anular o outro:
 * sem isto, se o email saísse primeiro, o utilizador nunca via o resumo.
 * Idempotente.
 */
return [
    'id' => '20261112_1500_plano_bonus_aviso_fim',
    'description' => "Acrescenta plano_bonus.aviso_fim_em (email de fim da oferta).",
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $temTabela = (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plano_bonus'")->fetchColumn();
        if ($temTabela === 0) {
            return;
        }
        $temColuna = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plano_bonus'
              AND COLUMN_NAME = 'aviso_fim_em'")->fetchColumn();
        if ($temColuna === 0) {
            $db->exec("ALTER TABLE plano_bonus ADD COLUMN aviso_fim_em DATETIME DEFAULT NULL AFTER aviso12_em");
        }
    },
];
