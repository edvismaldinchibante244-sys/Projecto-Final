<?php
declare(strict_types=1);

/*
 * Desempenho com muitos restaurantes em simultaneo.
 *  1. Indices nas tabelas que mais crescem (por restaurante + data).
 *  2. operacao_versao: um numero por restaurante que sobe sempre que um pedido, item ou mesa muda.
 *     Os ecras (cozinha, bar, pedidos, mesas) perguntam so este numero e so voltam a carregar
 *     a lista quando ele muda. Mantido por triggers, para apanhar todas as alteracoes.
 *     Se o alojamento nao permitir triggers, a migracao continua: o sistema usa o calculo de recurso.
 *  3. vendas_resumo_diario: totais por dia, para relatorios e painel nao somarem meses de vendas.
 * Idempotente.
 */
return [
    'id' => '20261108_0900_desempenho_operacao',
    'description' => 'Indices de desempenho, versao de operacao (triggers) e resumo diario de vendas.',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $existeTabela = static function (string $t) use ($db): bool {
            $st = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $st->execute([$t]);
            return (bool)$st->fetchColumn();
        };
        $colunas = static function (string $t) use ($db): array {
            $st = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $st->execute([$t]);
            return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        };
        $indice = static function (string $t, string $nome, array $cols) use ($db, $existeTabela, $colunas): void {
            if (!$existeTabela($t) || array_diff($cols, $colunas($t))) {
                return;
            }
            $st = $db->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
            $st->execute([$t, $nome]);
            if (!$st->fetchColumn()) {
                $db->exec("ALTER TABLE `$t` ADD INDEX `$nome` (`" . implode('`,`', $cols) . '`)');
            }
        };

        // 1) Indices.
        $indice('pedidos', 'idx_pedidos_rest_atualizado', ['restaurante_id', 'atualizado_em']);
        $indice('pedidos', 'idx_pedidos_rest_criado', ['restaurante_id', 'criado_em']);
        $indice('vendas', 'idx_vendas_rest_criado', ['restaurante_id', 'criado_em']);
        $indice('vendas', 'idx_vendas_rest_status_criado', ['restaurante_id', 'status', 'criado_em']);
        $indice('audit_logs', 'idx_audit_rest_created', ['restaurante_id', 'created_at']);
        $indice('mesas', 'idx_mesas_rest_status', ['restaurante_id', 'status']);
        $indice('caixa_turnos', 'idx_caixa_turnos_rest_abertura', ['restaurante_id', 'data_abertura']);
        $indice('security_rate_limits', 'idx_rate_limits_expira', ['expira_em']);

        // 2) Versao de operacao.
        $db->exec("CREATE TABLE IF NOT EXISTS operacao_versao (
            restaurante_id INT NOT NULL PRIMARY KEY,
            versao BIGINT UNSIGNED NOT NULL DEFAULT 0,
            atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $sobe = static fn(string $rid) => "INSERT INTO operacao_versao (restaurante_id, versao) VALUES ($rid, 1) ON DUPLICATE KEY UPDATE versao = versao + 1";
        $triggers = [
            'pedidos' => ['NEW.restaurante_id', 'NEW.restaurante_id', 'OLD.restaurante_id'],
            'mesas' => ['NEW.restaurante_id', 'NEW.restaurante_id', 'OLD.restaurante_id'],
            'itens_pedido' => [
                '(SELECT p.restaurante_id FROM pedidos p WHERE p.id = NEW.pedido_id)',
                '(SELECT p.restaurante_id FROM pedidos p WHERE p.id = NEW.pedido_id)',
                '(SELECT p.restaurante_id FROM pedidos p WHERE p.id = OLD.pedido_id)',
            ],
        ];
        foreach ($triggers as $tabela => [$ins, $upd, $del]) {
            if (!$existeTabela($tabela)) {
                continue;
            }
            foreach (['ai' => ['INSERT', $ins], 'au' => ['UPDATE', $upd], 'ad' => ['DELETE', $del]] as $suf => [$evento, $rid]) {
                $nome = "trg_nv_ov_{$tabela}_{$suf}";
                try {
                    $db->exec("DROP TRIGGER IF EXISTS `$nome`");
                    $db->exec("CREATE TRIGGER `$nome` AFTER $evento ON `$tabela` FOR EACH ROW BEGIN
                        IF $rid IS NOT NULL THEN " . $sobe($rid) . "; END IF;
                    END");
                } catch (Throwable $e) {
                    // Alojamento sem permissao para triggers: o endpoint usa o calculo de recurso.
                    error_log('[migracao desempenho] trigger ' . $nome . ': ' . $e->getMessage());
                }
            }
        }

        // 3) Resumo diario de vendas.
        $db->exec("CREATE TABLE IF NOT EXISTS vendas_resumo_diario (
            restaurante_id INT NOT NULL,
            dia DATE NOT NULL,
            vendas INT UNSIGNED NOT NULL DEFAULT 0,
            canceladas INT UNSIGNED NOT NULL DEFAULT 0,
            total DECIMAL(14,2) NOT NULL DEFAULT 0,
            descontos DECIMAL(14,2) NOT NULL DEFAULT 0,
            calculado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (restaurante_id, dia),
            KEY idx_resumo_dia (dia)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Dias ja calculados pela rotina de manutencao (para saber se o resumo esta completo).
        $db->exec("CREATE TABLE IF NOT EXISTS vendas_resumo_estado (
            dia DATE NOT NULL PRIMARY KEY,
            calculado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Venda criada ou alterada num dia ja resumido (ex.: venda offline sincronizada mais tarde,
        // cancelamento de uma venda antiga): esse dia deixa de contar como resumido e volta a ser
        // somado diretamente ate a rotina o recalcular. Assim os totais nunca ficam errados.
        foreach (['ai' => ['INSERT', ['NEW']], 'au' => ['UPDATE', ['NEW', 'OLD']], 'ad' => ['DELETE', ['OLD']]] as $suf => [$evento, $linhas]) {
            $nome = "trg_nv_resumo_vendas_$suf";
            $corpo = '';
            foreach ($linhas as $l) {
                $corpo .= "IF $l.criado_em < CURDATE() THEN DELETE FROM vendas_resumo_estado WHERE dia = DATE($l.criado_em); END IF;\n";
            }
            try {
                $db->exec("DROP TRIGGER IF EXISTS `$nome`");
                $db->exec("CREATE TRIGGER `$nome` AFTER $evento ON `vendas` FOR EACH ROW BEGIN\n$corpo END");
            } catch (Throwable $e) {
                error_log('[migracao desempenho] trigger ' . $nome . ': ' . $e->getMessage());
            }
        }
    },
];
