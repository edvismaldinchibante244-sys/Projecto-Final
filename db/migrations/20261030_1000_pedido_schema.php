<?php
declare(strict_types=1);

/*
 * Consolida o schema de pedidos que antes era garantido em runtime por
 * pedido_schema_garantir() (app/src/config/pedido_schema.php).
 * Depois de aplicada, as requisicoes normais deixam de executar DDL.
 * Idempotente: pode correr numa base que ja tenha parte das colunas.
 */
return [
    'id' => '20261030_1000_pedido_schema',
    'description' => 'Pedidos: coluna origem, timestamps por etapa, ENUM de status com CONTA e contador pedido_sequencias.',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $tabela = $db->query(
            "SELECT COUNT(*) FROM information_schema.tables "
            . "WHERE table_schema = DATABASE() AND table_name = 'pedidos'"
        );
        if ((int)$tabela->fetchColumn() !== 1) {
            throw new RuntimeException('Tabela base pedidos inexistente; migracao interrompida.');
        }

        $colunas = [];
        $stmt = $db->query(
            "SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.columns "
            . "WHERE table_schema = DATABASE() AND table_name = 'pedidos'"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $colunas[strtolower((string)$c['COLUMN_NAME'])] = strtolower((string)$c['COLUMN_TYPE']);
        }

        // 1) origem
        if (!isset($colunas['origem'])) {
            $db->exec("ALTER TABLE pedidos ADD COLUMN origem VARCHAR(20) NOT NULL DEFAULT 'BALCAO' AFTER observacao");
        }
        $db->exec("
            UPDATE pedidos
            SET origem = CASE WHEN origem IS NULL OR origem = '' THEN 'BALCAO' ELSE UPPER(origem) END
            WHERE origem IS NULL OR origem = '' OR BINARY origem <> BINARY UPPER(origem)
        ");

        // 2) timestamps por etapa + preenchimento historico
        $timestamps = ['iniciado_preparo_em', 'pronto_em', 'entregue_em', 'conta_em', 'pago_em', 'cancelado_em'];
        $criados = false;
        foreach ($timestamps as $col) {
            if (!isset($colunas[$col])) {
                $db->exec("ALTER TABLE pedidos ADD COLUMN {$col} DATETIME NULL");
                $criados = true;
            }
        }
        if ($criados) {
            $preenchimentos = [
                'iniciado_preparo_em' => "('PREPARANDO','PRONTO','ENTREGUE','CONTA','CONTA_SOLICITADA','PAGO')",
                'pronto_em' => "('PRONTO','ENTREGUE','CONTA','CONTA_SOLICITADA','PAGO')",
                'entregue_em' => "('ENTREGUE','CONTA','CONTA_SOLICITADA','PAGO')",
                'conta_em' => "('CONTA','CONTA_SOLICITADA')",
                'pago_em' => "('PAGO')",
                'cancelado_em' => "('CANCELADO')",
            ];
            foreach ($preenchimentos as $col => $estados) {
                $db->exec("UPDATE pedidos SET {$col} = COALESCE({$col}, atualizado_em, criado_em)
                           WHERE {$col} IS NULL AND UPPER(status) IN {$estados}");
            }
        }

        // 3) ENUM de status (so altera se for diferente; recusa se houver valores fora da lista)
        $enumAlvo = "enum('pendente','novo','confirmado','preparando','aberto','em_andamento','pronto','entregue','conta','conta_solicitada','pago','cancelado')";
        if (($colunas['status'] ?? '') !== $enumAlvo) {
            $fora = (int)$db->query("
                SELECT COUNT(*) FROM pedidos
                WHERE UPPER(status) NOT IN ('PENDENTE','NOVO','CONFIRMADO','PREPARANDO','ABERTO','EM_ANDAMENTO',
                                            'PRONTO','ENTREGUE','CONTA','CONTA_SOLICITADA','PAGO','CANCELADO')
            ")->fetchColumn();
            if ($fora > 0) {
                throw new RuntimeException("pedidos.status tem {$fora} linhas com valores fora do ENUM; requer revisao manual.");
            }
            $db->exec("
                ALTER TABLE pedidos MODIFY COLUMN status ENUM(
                    'PENDENTE','NOVO','CONFIRMADO','PREPARANDO','ABERTO','EM_ANDAMENTO',
                    'PRONTO','ENTREGUE','CONTA','CONTA_SOLICITADA','PAGO','CANCELADO'
                ) NOT NULL DEFAULT 'PENDENTE'
            ");
        }

        // 4) contador atomico de numeros de pedido
        $db->exec("CREATE TABLE IF NOT EXISTS pedido_sequencias (
            restaurante_id INT NOT NULL,
            dia DATE NOT NULL,
            ultimo INT UNSIGNED NOT NULL,
            atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (restaurante_id, dia)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    },
];
