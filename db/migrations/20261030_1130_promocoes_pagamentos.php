<?php
declare(strict_types=1);

/*
 * Consolida o schema que antes era garantido em runtime por:
 *  - promocao_schema_garantir()  (app/src/config/promocao_schema.php)
 *  - Venda::garantirSchemaPagamentoPublico() (app/src/Model/Venda.php)
 * Depois de aplicada, pedidos e pagamentos deixam de inspeccionar/alterar o schema.
 * Idempotente.
 */
return [
    'id' => '20261030_1130_promocoes_pagamentos',
    'description' => 'Promocoes (tabelas, horarios UTC, promocao_id nos itens) e colunas de pagamento das vendas (troco, EMOLA).',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $colunas = static function (string $tabela) use ($db): array {
            $stmt = $db->prepare(
                "SELECT LOWER(COLUMN_NAME) AS nome, LOWER(COLUMN_TYPE) AS tipo, COLUMN_DEFAULT AS padrao "
                . "FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?"
            );
            $stmt->execute([$tabela]);
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $out[(string)$c['nome']] = $c;
            }
            return $out;
        };

        foreach (['produtos', 'itens_pedido', 'vendas', 'itens_venda'] as $base) {
            if ($colunas($base) === []) {
                throw new RuntimeException("Tabela base {$base} inexistente; migracao interrompida.");
            }
        }

        // ---------- Promocoes ----------
        $db->exec("CREATE TABLE IF NOT EXISTS promocoes (
            id INT NOT NULL AUTO_INCREMENT,
            restaurante_id INT NOT NULL,
            nome VARCHAR(150) NOT NULL,
            descricao VARCHAR(500) NULL,
            preco_promocional DECIMAL(12,2) NOT NULL,
            imagem VARCHAR(255) NULL,
            inicio_em DATETIME NULL,
            fim_em DATETIME NULL,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            horarios_utc TINYINT(1) NOT NULL DEFAULT 1,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_promocoes_ativas (restaurante_id, ativo, inicio_em, fim_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $promo = $colunas('promocoes');
        if (!isset($promo['horarios_utc'])) {
            $db->exec('ALTER TABLE promocoes ADD COLUMN horarios_utc TINYINT(1) NOT NULL DEFAULT 0 AFTER ativo');
        }

        // Promocoes antigas guardavam horas locais: converter para UTC (mesma regra do runtime).
        $tzNome = trim((string)(getenv('APP_TIMEZONE') ?: getenv('TZ') ?: 'Africa/Maputo'));
        try {
            $tz = new DateTimeZone($tzNome);
        } catch (Throwable $e) {
            $tz = new DateTimeZone('Africa/Maputo');
        }
        $paraUtc = static function ($valor) use ($tz): ?string {
            $valor = trim((string)$valor);
            if ($valor === '') {
                return null;
            }
            $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $valor, $tz);
            if (!$d) {
                throw new RuntimeException("Data promocional invalida: {$valor}; requer revisao manual.");
            }
            return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        };
        $legado = $db->query('SELECT id, inicio_em, fim_em FROM promocoes WHERE horarios_utc = 0')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($legado) {
            $upd = $db->prepare('UPDATE promocoes SET inicio_em = :inicio, fim_em = :fim, horarios_utc = 1 WHERE id = :id AND horarios_utc = 0');
            foreach ($legado as $row) {
                $upd->execute([
                    'id' => (int)$row['id'],
                    'inicio' => $paraUtc($row['inicio_em'] ?? null),
                    'fim' => $paraUtc($row['fim_em'] ?? null),
                ]);
            }
        }
        $promo = $colunas('promocoes');
        if ((string)($promo['horarios_utc']['padrao'] ?? '') !== '1') {
            $db->exec('ALTER TABLE promocoes MODIFY horarios_utc TINYINT(1) NOT NULL DEFAULT 1');
        }

        $db->exec("CREATE TABLE IF NOT EXISTS promocao_itens (
            id INT NOT NULL AUTO_INCREMENT,
            promocao_id INT NOT NULL,
            produto_id INT NOT NULL,
            quantidade INT NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY uq_promocao_produto (promocao_id, produto_id),
            KEY idx_promocao_itens_produto (produto_id),
            CONSTRAINT fk_promocao_itens_promocao FOREIGN KEY (promocao_id) REFERENCES promocoes(id) ON DELETE CASCADE,
            CONSTRAINT fk_promocao_itens_produto FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        if (!isset($colunas('itens_pedido')['promocao_id'])) {
            $db->exec('ALTER TABLE itens_pedido ADD COLUMN promocao_id INT NULL AFTER produto_id');
            $db->exec('ALTER TABLE itens_pedido ADD KEY idx_itens_pedido_promocao (promocao_id)');
        }
        if (!isset($colunas('itens_venda')['promocao_id'])) {
            $db->exec('ALTER TABLE itens_venda ADD COLUMN promocao_id INT NULL AFTER produto_id');
            $db->exec('ALTER TABLE itens_venda ADD KEY idx_itens_venda_promocao (promocao_id)');
        }

        // ---------- Pagamentos (vendas) ----------
        $vendas = $colunas('vendas');
        if (!isset($vendas['valor_recebido'])) {
            $db->exec('ALTER TABLE vendas ADD COLUMN valor_recebido DECIMAL(10,2) NULL DEFAULT NULL AFTER forma_pagamento');
        }
        if (!isset($vendas['troco'])) {
            $db->exec('ALTER TABLE vendas ADD COLUMN troco DECIMAL(10,2) NULL DEFAULT NULL AFTER valor_recebido');
        }
        $formaTipo = (string)($vendas['forma_pagamento']['tipo'] ?? '');
        if ($formaTipo !== '' && strpos($formaTipo, 'emola') === false) {
            $fora = (int)$db->query(
                "SELECT COUNT(*) FROM vendas WHERE forma_pagamento IS NOT NULL "
                . "AND UPPER(forma_pagamento) NOT IN ('DINHEIRO','MPESA','EMOLA','CARTAO','TRANSFERENCIA')"
            )->fetchColumn();
            if ($fora > 0) {
                throw new RuntimeException("vendas.forma_pagamento tem {$fora} linhas fora do ENUM; requer revisao manual.");
            }
            $db->exec("ALTER TABLE vendas MODIFY COLUMN forma_pagamento ENUM('DINHEIRO','MPESA','EMOLA','CARTAO','TRANSFERENCIA') DEFAULT 'DINHEIRO'");
        }
    },
];
