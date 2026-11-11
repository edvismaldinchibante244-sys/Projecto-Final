<?php

require_once __DIR__ . '/migration_state.php';

if (!function_exists('pedido_schema_coluna_existe')) {
    function pedido_schema_coluna_existe(PDO $db, string $tabela, string $coluna): bool
    {
        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :tabela
              AND COLUMN_NAME = :coluna
            LIMIT 1
        ");
        $stmt->bindValue(':tabela', $tabela, PDO::PARAM_STR);
        $stmt->bindValue(':coluna', $coluna, PDO::PARAM_STR);
        $stmt->execute();

        return (bool)$stmt->fetchColumn();
    }
}

if (!defined('PEDIDO_SCHEMA_MIGRATION_ID')) {
    define('PEDIDO_SCHEMA_MIGRATION_ID', '20261030_1000_pedido_schema');
}

if (!function_exists('pedido_schema_tipo_coluna')) {
    function pedido_schema_tipo_coluna(PDO $db, string $tabela, string $coluna): string
    {
        $stmt = $db->prepare("
            SELECT COLUMN_TYPE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tabela AND COLUMN_NAME = :coluna
            LIMIT 1
        ");
        $stmt->execute([':tabela' => $tabela, ':coluna' => $coluna]);
        return strtolower((string)$stmt->fetchColumn());
    }
}

if (!function_exists('pedido_schema_garantir')) {
    /**
     * Garante o schema de pedidos.
     *
     * Com a migracao db/migrations/20261030_1000_pedido_schema aplicada, nao
     * executa DDL nem UPDATEs: apenas uma consulta (em cache por processo).
     * Sem a migracao (instalacoes antigas), mantem o fallback legado, mas so
     * altera o que realmente falta - antes fazia ALTER TABLE ... MODIFY e um
     * UPDATE a todos os pedidos em cada processo PHP.
     */
    function pedido_schema_garantir(PDO $db): bool
    {
        static $status = null;

        if ($status !== null) {
            return $status;
        }

        if (function_exists('migration_esta_aplicada') && migration_esta_aplicada($db, PEDIDO_SCHEMA_MIGRATION_ID)) {
            return $status = true;
        }

        // ---- Fallback legado (ate a migracao ser aplicada) ----

        if (function_exists('pedido_sequencia_garantir')) {
            pedido_sequencia_garantir($db);
        }

        if (!pedido_schema_coluna_existe($db, 'pedidos', 'origem')) {
            try {
                $db->exec("ALTER TABLE pedidos ADD COLUMN origem VARCHAR(20) NOT NULL DEFAULT 'BALCAO' AFTER observacao");
            } catch (Throwable $e) {
                // Outra requisicao pode ter criado a coluna em paralelo.
            }
        }

        if (pedido_schema_coluna_existe($db, 'pedidos', 'origem')) {
            try {
                // So linhas que precisam de normalizacao (antes: todos os pedidos).
                $db->exec("
                    UPDATE pedidos
                    SET origem = CASE WHEN origem IS NULL OR origem = '' THEN 'BALCAO' ELSE UPPER(origem) END
                    WHERE origem IS NULL OR origem = '' OR BINARY origem <> BINARY UPPER(origem)
                ");
            } catch (Throwable $e) {
                // Nao bloquear a aplicacao por falha de normalizacao legada.
            }
        }

        $timestampColumns = ['iniciado_preparo_em', 'pronto_em', 'entregue_em', 'conta_em', 'pago_em', 'cancelado_em'];
        $timestampColumnsCreated = false;
        foreach ($timestampColumns as $column) {
            if (pedido_schema_coluna_existe($db, 'pedidos', $column)) {
                continue;
            }
            try {
                $db->exec("ALTER TABLE pedidos ADD COLUMN {$column} DATETIME NULL");
                $timestampColumnsCreated = true;
            } catch (Throwable $e) {
                // Outra requisicao pode ter criado a coluna em paralelo.
            }
        }

        // Compatibilidade: preserva uma duracao final para pedidos antigos.
        if ($timestampColumnsCreated) {
            $preenchimentos = [
                'iniciado_preparo_em' => "('PREPARANDO','PRONTO','ENTREGUE','CONTA','CONTA_SOLICITADA','PAGO')",
                'pronto_em' => "('PRONTO','ENTREGUE','CONTA','CONTA_SOLICITADA','PAGO')",
                'entregue_em' => "('ENTREGUE','CONTA','CONTA_SOLICITADA','PAGO')",
                'conta_em' => "('CONTA','CONTA_SOLICITADA')",
                'pago_em' => "('PAGO')",
                'cancelado_em' => "('CANCELADO')",
            ];
            try {
                foreach ($preenchimentos as $col => $estados) {
                    $db->exec("UPDATE pedidos SET {$col} = COALESCE({$col}, atualizado_em, criado_em)
                               WHERE {$col} IS NULL AND UPPER(status) IN {$estados}");
                }
            } catch (Throwable $e) {
                // Uma instalacao legada continua com o fallback disponivel.
            }
        }

        // Inclui CONTA / fluxo caixa (senao MySQL grava '' e o pagamento falha).
        // So executa o ALTER se o ENUM ainda nao estiver correcto (antes: sempre).
        $enumAlvo = "enum('pendente','novo','confirmado','preparando','aberto','em_andamento','pronto','entregue','conta','conta_solicitada','pago','cancelado')";
        try {
            if (pedido_schema_tipo_coluna($db, 'pedidos', 'status') !== $enumAlvo) {
                $db->exec("
                    ALTER TABLE pedidos
                    MODIFY COLUMN status ENUM(
                        'PENDENTE','NOVO','CONFIRMADO','PREPARANDO','ABERTO','EM_ANDAMENTO',
                        'PRONTO','ENTREGUE','CONTA','CONTA_SOLICITADA','PAGO','CANCELADO'
                    ) NOT NULL DEFAULT 'PENDENTE'
                ");
            }
        } catch (Throwable $e) {
            error_log('[pedido_schema_garantir] ENUM status: ' . $e->getMessage());
        }

        $status = pedido_schema_coluna_existe($db, 'pedidos', 'origem');
        return $status;
    }
}

if (!function_exists('pedido_normalizar_origem')) {
    function pedido_normalizar_origem($origem): string
    {
        $valor = strtoupper(trim((string)$origem));
        $validas = ['QR', 'GARCOM', 'BALCAO'];
        return in_array($valor, $validas, true) ? $valor : 'BALCAO';
    }
}

/*
 * Numeracao diaria de pedidos sem duplicados.
 *
 * Antes: "SELECT MAX(numero_pedido)+1" sem bloqueio. Dois pedidos ao mesmo
 * tempo liam o mesmo MAX e recebiam o mesmo numero.
 *
 * Agora: um contador por (restaurante, dia) na tabela pedido_sequencias.
 *  1. pedido_sequencia_preparar() - FORA da transacao: cria a linha do dia
 *     (se ainda nao existir) a partir do maior numero ja usado hoje.
 *  2. pedido_proximo_numero()     - DENTRO da transacao: um unico UPDATE
 *     atomico. O InnoDB bloqueia a linha do contador ate ao commit, por isso
 *     pedidos simultaneos ficam em fila e cada um recebe um numero diferente.
 *     Se a transacao for desfeita, o numero tambem e devolvido.
 *
 * A tabela e criada pela migracao 20261030_0930_pedido_sequencias; o
 * CREATE TABLE abaixo e o fallback legado (corre fora de transacao, porque
 * DDL faz commit implicito no MySQL).
 */
if (!function_exists('pedido_sequencia_garantir')) {
    function pedido_sequencia_garantir(PDO $db): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        if (function_exists('migration_esta_aplicada')
            && (migration_esta_aplicada($db, '20261030_1000_pedido_schema')
                || migration_esta_aplicada($db, '20261030_0930_pedido_sequencias'))) {
            return $ok = true;
        }
        if ($db->inTransaction()) {
            return false;
        }
        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS pedido_sequencias (
                    restaurante_id INT NOT NULL,
                    dia DATE NOT NULL,
                    ultimo INT UNSIGNED NOT NULL,
                    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (restaurante_id, dia)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $ok = true;
        } catch (Throwable $e) {
            error_log('[pedido_sequencia_garantir] ' . $e->getMessage());
            $ok = false;
        }
        return $ok;
    }
}

if (!function_exists('pedido_sequencia_preparar')) {
    /**
     * Garante a linha do contador de HOJE para o restaurante.
     * Chamar ANTES de beginTransaction(). Seguro chamar varias vezes.
     */
    function pedido_sequencia_preparar(PDO $db, int $restauranteId): void
    {
        if ($restauranteId <= 0 || $db->inTransaction()) {
            return;
        }
        pedido_sequencia_garantir($db);
        try {
            $existe = $db->prepare('SELECT 1 FROM pedido_sequencias WHERE restaurante_id = :rid AND dia = CURDATE() LIMIT 1');
            $existe->execute([':rid' => $restauranteId]);
            if ($existe->fetchColumn()) {
                return;
            }
            $maior = $db->prepare("
                SELECT COALESCE(MAX(CAST(numero_pedido AS UNSIGNED)), 0)
                FROM pedidos
                WHERE restaurante_id = :rid AND (criado_em >= CURDATE() AND criado_em < CURDATE() + INTERVAL 1 DAY)
            ");
            $maior->execute([':rid' => $restauranteId]);
            $ultimo = (int)$maior->fetchColumn();

            // Autocommit: se dois pedidos tentarem criar a linha ao mesmo tempo,
            // o segundo e simplesmente ignorado (a linha ja existe).
            $ins = $db->prepare('INSERT IGNORE INTO pedido_sequencias (restaurante_id, dia, ultimo) VALUES (:rid, CURDATE(), :ultimo)');
            $ins->execute([':rid' => $restauranteId, ':ultimo' => $ultimo]);
        } catch (Throwable $e) {
            error_log('[pedido_sequencia_preparar] ' . $e->getMessage());
        }
    }
}

if (!function_exists('pedido_proximo_numero')) {
    /**
     * Devolve o proximo numero de pedido do dia (ex.: "0007") para o restaurante.
     * Chamar DENTRO da transacao que insere o pedido, depois de
     * pedido_sequencia_preparar() ter sido chamado fora dela.
     */
    function pedido_proximo_numero(PDO $db, int $restauranteId): string
    {
        try {
            $upd = $db->prepare('
                UPDATE pedido_sequencias
                SET ultimo = LAST_INSERT_ID(ultimo + 1)
                WHERE restaurante_id = :rid AND dia = CURDATE()
            ');
            $upd->execute([':rid' => $restauranteId]);

            if ($upd->rowCount() === 0) {
                // Linha do dia em falta (ex.: a meia-noite passou entre a preparacao
                // e este ponto). Cria-a aqui; e um caso raro.
                $ins = $db->prepare("
                    INSERT INTO pedido_sequencias (restaurante_id, dia, ultimo)
                    SELECT :rid, CURDATE(), LAST_INSERT_ID(COALESCE(MAX(CAST(numero_pedido AS UNSIGNED)), 0) + 1)
                    FROM pedidos
                    WHERE restaurante_id = :rid2 AND (criado_em >= CURDATE() AND criado_em < CURDATE() + INTERVAL 1 DAY)
                    ON DUPLICATE KEY UPDATE ultimo = LAST_INSERT_ID(ultimo + 1)
                ");
                $ins->execute([':rid' => $restauranteId, ':rid2' => $restauranteId]);
            }

            $numero = (int)$db->query('SELECT LAST_INSERT_ID()')->fetchColumn();
            if ($numero <= 0) {
                throw new RuntimeException('Contador de pedidos devolveu um valor invalido.');
            }
            return str_pad((string)$numero, 4, '0', STR_PAD_LEFT);
        } catch (PDOException $e) {
            // So usar o metodo antigo se a tabela ainda nao existir (migracao por
            // aplicar). Qualquer outro erro (ex.: deadlock) e propagado para que a
            // transacao seja desfeita - nunca gerar numeros fora da transacao.
            $codigoMysql = (int)($e->errorInfo[1] ?? 0);
            if ($codigoMysql !== 1146) {
                throw $e;
            }
            error_log('[pedido_proximo_numero] tabela pedido_sequencias em falta; a usar metodo antigo.');
        }

        $stmt = $db->prepare("
            SELECT COALESCE(MAX(CAST(numero_pedido AS UNSIGNED)), 0) + 1
            FROM pedidos
            WHERE restaurante_id = :rid AND (criado_em >= CURDATE() AND criado_em < CURDATE() + INTERVAL 1 DAY)
        ");
        $stmt->execute([':rid' => $restauranteId]);
        return str_pad((string)(int)$stmt->fetchColumn(), 4, '0', STR_PAD_LEFT);
    }
}
