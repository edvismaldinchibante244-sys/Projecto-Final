<?php
declare(strict_types=1);

/*
 * Corrige chaves estrangeiras de restaurante_id que apontavam para
 * compras_planos(restaurante_id) em vez de restaurantes(id).
 *
 * Efeitos do erro:
 *  - inserir um cliente (ex.: reserva pelo QR) falhava com erro 1452 quando o
 *    restaurante nao tinha nenhuma linha em compras_planos (plano atribuido
 *    manualmente, teste, migrado...);
 *  - apagar uma compra de plano apagava em cascata os clientes, cupoes,
 *    movimentos de stock, pontos de fidelidade, etc. do restaurante.
 *
 * Para cada tabela: remove a FK errada e cria fk_<tabela>_restaurante para
 * restaurantes(id) ON DELETE CASCADE (a regra das outras tabelas, ex. pedidos).
 * Se houver linhas de restaurantes que ja nao existem, a FK nova nao e criada
 * para essa tabela (remover a errada ja resolve o erro) e a migracao avisa.
 * Idempotente.
 */
return [
    'id' => '20261030_1400_fk_restaurante_corrigidas',
    'description' => 'restaurante_id de clientes, cupoes, stock, fidelidade, campanhas e imagens passa a referenciar restaurantes(id).',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $erradas = $db->query("
            SELECT k.TABLE_NAME AS tabela, k.CONSTRAINT_NAME AS nome
            FROM information_schema.KEY_COLUMN_USAGE k
            WHERE k.TABLE_SCHEMA = DATABASE()
              AND k.COLUMN_NAME = 'restaurante_id'
              AND k.REFERENCED_TABLE_NAME = 'compras_planos'
            ORDER BY k.TABLE_NAME
        ")->fetchAll(PDO::FETCH_ASSOC);

        $tabelas = [];
        foreach ($erradas as $fk) {
            $tabela = (string)$fk['tabela'];
            $nome = (string)$fk['nome'];
            if (!preg_match('/^[A-Za-z0-9_]+$/', $tabela) || !preg_match('/^[A-Za-z0-9_]+$/', $nome)) {
                throw new RuntimeException("Nome inesperado de tabela/FK: {$tabela}.{$nome}");
            }
            $db->exec("ALTER TABLE `{$tabela}` DROP FOREIGN KEY `{$nome}`");
            $tabelas[$tabela] = true;
        }

        $avisos = [];
        foreach (array_keys($tabelas) as $tabela) {
            $jaTem = (int)$db->query("
                SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $db->quote($tabela) . "
                  AND COLUMN_NAME = 'restaurante_id' AND REFERENCED_TABLE_NAME = 'restaurantes'
            ")->fetchColumn();
            if ($jaTem > 0) {
                continue;
            }
            $orfas = (int)$db->query("
                SELECT COUNT(*) FROM `{$tabela}` t
                LEFT JOIN restaurantes r ON r.id = t.restaurante_id
                WHERE t.restaurante_id IS NOT NULL AND r.id IS NULL
            ")->fetchColumn();
            if ($orfas > 0) {
                $avisos[] = "{$tabela}: {$orfas} linha(s) de restaurantes inexistentes; FK nova nao criada";
                continue;
            }
            $db->exec("ALTER TABLE `{$tabela}` ADD CONSTRAINT `fk_{$tabela}_restaurante` "
                . "FOREIGN KEY (`restaurante_id`) REFERENCES `restaurantes` (`id`) ON DELETE CASCADE");
        }

        if ($avisos) {
            error_log('[MIGRACAO 20261030_1400] ' . implode('; ', $avisos));
        }
    },
];
