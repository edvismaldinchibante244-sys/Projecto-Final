<?php

require_once __DIR__ . '/migration_state.php';

if (!function_exists('estoque_inteligente_log')) {
    function estoque_inteligente_log(string $evento, array $contexto = []): void
    {
        if (function_exists('security_audit_event')) {
            security_audit_event($evento, $contexto);
            return;
        }
        @error_log('[ESTOQUE_INTELIGENTE] ' . $evento . ' ' . json_encode($contexto));
    }
}

if (!function_exists('estoque_inteligente_tabela_existe')) {
    function estoque_inteligente_tabela_existe(PDO $db, string $tabela): bool
    {
        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :tabela
            LIMIT 1
        ");
        $stmt->bindValue(':tabela', $tabela, PDO::PARAM_STR);
        $stmt->execute();
        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('estoque_inteligente_schema_garantir')) {
    function estoque_inteligente_schema_garantir(PDO $db): void
    {
        static $ok = false;
        if ($ok) {
            return;
        }

        // Depois da migracao db/migrations/20261030_1100_estoque_inteligente,
        // nao executa DDL em cada pedido. Sem ela, o fallback legado continua activo.
        if (function_exists('migration_esta_aplicada')
            && migration_esta_aplicada($db, '20261030_1100_estoque_inteligente')) {
            $ok = true;
            return;
        }

        $db->exec("
            CREATE TABLE IF NOT EXISTS estoque_insumos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurante_id INT NOT NULL,
                filial_id INT NULL,
                nome VARCHAR(140) NOT NULL,
                sku VARCHAR(80) NULL,
                unidade_base ENUM('g','ml','un') NOT NULL DEFAULT 'un',
                estoque_minimo_base DECIMAL(14,3) NOT NULL DEFAULT 0,
                ativo TINYINT(1) NOT NULL DEFAULT 1,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_insumo_sku (restaurante_id, filial_id, sku),
                KEY idx_insumo_rest_filial (restaurante_id, filial_id),
                KEY idx_insumo_nome (nome)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS estoque_fichas_tecnicas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurante_id INT NOT NULL,
                filial_id INT NULL,
                produto_id INT NOT NULL,
                rendimento_por_produto DECIMAL(14,3) NOT NULL DEFAULT 1,
                ativo TINYINT(1) NOT NULL DEFAULT 1,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_ficha_produto (restaurante_id, filial_id, produto_id),
                KEY idx_ficha_rest_filial (restaurante_id, filial_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS estoque_ficha_itens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ficha_id INT NOT NULL,
                insumo_id INT NOT NULL,
                quantidade_base DECIMAL(14,3) NOT NULL,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_ficha_item_ficha (ficha_id),
                KEY idx_ficha_item_insumo (insumo_id),
                CONSTRAINT fk_ficha_item_ficha FOREIGN KEY (ficha_id) REFERENCES estoque_fichas_tecnicas(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS estoque_saldos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurante_id INT NOT NULL,
                filial_id INT NULL,
                insumo_id INT NOT NULL,
                saldo_base DECIMAL(14,3) NOT NULL DEFAULT 0,
                custo_medio DECIMAL(14,4) NOT NULL DEFAULT 0,
                atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_saldo_unico (restaurante_id, filial_id, insumo_id),
                KEY idx_saldo_rest_filial (restaurante_id, filial_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS estoque_lotes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurante_id INT NOT NULL,
                filial_id INT NULL,
                insumo_id INT NOT NULL,
                quantidade_base DECIMAL(14,3) NOT NULL,
                saldo_base DECIMAL(14,3) NOT NULL,
                custo_unitario_base DECIMAL(14,4) NOT NULL DEFAULT 0,
                validade DATE NULL,
                referencia_compra VARCHAR(120) NULL,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_lote_consumo (restaurante_id, filial_id, insumo_id, validade, criado_em),
                KEY idx_lote_validade (validade)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS estoque_movimentos (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                restaurante_id INT NOT NULL,
                filial_id INT NULL,
                insumo_id INT NOT NULL,
                tipo ENUM('ENTRADA','SAIDA','PERDA','AJUSTE','TRANSFERENCIA') NOT NULL,
                origem ENUM('COMPRA','PEDIDO','COZINHA','CAIXA','MANUAL','SISTEMA') NOT NULL DEFAULT 'SISTEMA',
                quantidade_base DECIMAL(14,3) NOT NULL,
                custo_unitario_base DECIMAL(14,4) NULL,
                custo_total DECIMAL(14,4) NULL,
                unidade_origem VARCHAR(12) NULL,
                pedido_id INT NULL,
                venda_id INT NULL,
                motivo VARCHAR(180) NULL,
                responsavel_id INT NULL,
                meta_json JSON NULL,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_mov_rest_filial_data (restaurante_id, filial_id, criado_em),
                KEY idx_mov_insumo (insumo_id, criado_em),
                KEY idx_mov_origem (origem, pedido_id, venda_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS estoque_perdas (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                restaurante_id INT NOT NULL,
                filial_id INT NULL,
                insumo_id INT NOT NULL,
                quantidade_base DECIMAL(14,3) NOT NULL,
                custo_perdido DECIMAL(14,4) NOT NULL DEFAULT 0,
                motivo VARCHAR(180) NOT NULL,
                responsavel_id INT NULL,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_perda_rest_filial_data (restaurante_id, filial_id, criado_em)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS estoque_alertas (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                restaurante_id INT NOT NULL,
                filial_id INT NULL,
                insumo_id INT NULL,
                tipo ENUM('REPOSICAO','VALIDADE','CONSUMO_ANOMALO') NOT NULL,
                severidade ENUM('BAIXA','MEDIA','ALTA','CRITICA') NOT NULL DEFAULT 'MEDIA',
                titulo VARCHAR(150) NOT NULL,
                detalhe VARCHAR(255) NULL,
                resolvido TINYINT(1) NOT NULL DEFAULT 0,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                resolvido_em TIMESTAMP NULL DEFAULT NULL,
                KEY idx_alerta_rest_filial (restaurante_id, filial_id, resolvido, criado_em)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Tabelas antigas podem existir sem colunas novas (CREATE IF NOT EXISTS não actualiza).
        try {
            $cols = [];
            $stmtCols = $db->query('SHOW COLUMNS FROM estoque_movimentos');
            while ($row = $stmtCols->fetch(PDO::FETCH_ASSOC)) {
                $cols[strtolower((string)$row['Field'])] = true;
            }
            if (!isset($cols['venda_id'])) {
                $db->exec('ALTER TABLE estoque_movimentos ADD COLUMN venda_id INT NULL AFTER pedido_id');
            }
            if (!isset($cols['pedido_id'])) {
                $db->exec('ALTER TABLE estoque_movimentos ADD COLUMN pedido_id INT NULL AFTER unidade_origem');
            }
            if (!isset($cols['meta_json'])) {
                $db->exec('ALTER TABLE estoque_movimentos ADD COLUMN meta_json JSON NULL AFTER responsavel_id');
            }
        } catch (Throwable $e) {
            // ignora se a tabela ainda não existir / já estiver correcta
        }

        $ok = true;
    }
}

if (!function_exists('estoque_unidade_base')) {
    function estoque_unidade_base(string $unidade): string
    {
        $u = strtolower(trim($unidade));
        $u = strtr($u, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a',
            'é' => 'e', 'ê' => 'e',
            'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u',
            'ç' => 'c',
        ]);

        if (in_array($u, ['kg', 'g', 'grama', 'gramas'], true)) {
            return 'g';
        }
        if (in_array($u, ['l', 'lt', 'litro', 'litros', 'ml'], true)) {
            return 'ml';
        }

        // Unidades de contagem (restaurante): preserva o rótulo.
        $mapContagem = [
            'unidade' => 'un',
            'unidades' => 'un',
            'un' => 'un',
            'fatia' => 'fatia',
            'fatias' => 'fatia',
            'caixa' => 'caixa',
            'caixas' => 'caixa',
            'pacote' => 'pacote',
            'pacotes' => 'pacote',
            'dose' => 'dose',
            'doses' => 'dose',
            'porcao' => 'porcao',
            'porcoes' => 'porcao',
            'lata' => 'lata',
            'latas' => 'lata',
            'garrafa' => 'garrafa',
            'garrafas' => 'garrafa',
            'unidade(s)' => 'un',
        ];
        if (isset($mapContagem[$u])) {
            return $mapContagem[$u];
        }
        // Permite unidade custom simples (ex.: "bife", "rolo").
        if (preg_match('/^[a-z0-9_\-]{1,20}$/', $u) === 1) {
            return $u;
        }
        return 'un';
    }
}

if (!function_exists('estoque_converter_para_base')) {
    function estoque_converter_para_base(float $quantidade, string $unidade): float
    {
        $u = strtolower(trim($unidade));
        if (in_array($u, ['kg'], true)) {
            return $quantidade * 1000.0;
        }
        if (in_array($u, ['g', 'grama', 'gramas'], true)) {
            return $quantidade;
        }
        if (in_array($u, ['l', 'lt', 'litro', 'litros'], true)) {
            return $quantidade * 1000.0;
        }
        if ($u === 'ml') {
            return $quantidade;
        }
        // Contagem (un, fatia, caixa, …): 1:1 com a unidade base.
        return $quantidade;
    }
}

if (!function_exists('estoque_compra_minima_relevante_base')) {
    function estoque_compra_minima_relevante_base(string $unidadeBase): float
    {
        $u = strtolower(trim($unidadeBase));
        if ($u === 'g' || $u === 'ml') {
            return 100.0;
        }
        if ($u === 'un') {
            return 1.0;
        }
        return 0.001;
    }
}

if (!function_exists('estoque_normalizar_sugestao_compra')) {
    function estoque_normalizar_sugestao_compra(float $sugestao, string $unidadeBase): float
    {
        if ($sugestao <= 0) {
            return 0.0;
        }
        return $sugestao >= estoque_compra_minima_relevante_base($unidadeBase)
            ? $sugestao
            : 0.0;
    }
}

if (!function_exists('estoque_unidade_preferida_entrada')) {
    /**
     * Unidade de entrada sugerida para o cadastro (kg para peso, l para volume, fatia/un…).
     * Evita gravar "2" como 2 g quando o utilizador pensava em 2 kg.
     */
    function estoque_unidade_preferida_entrada(string $unidadeBaseInsumo): string
    {
        $base = estoque_unidade_base($unidadeBaseInsumo);
        if ($base === 'g') {
            return 'kg';
        }
        if ($base === 'ml') {
            return 'l';
        }
        return $base !== '' ? $base : 'un';
    }
}

if (!function_exists('estoque_resolver_unidade_movimento')) {
    /**
     * Garante que a unidade do movimento é compatível com a do insumo.
     * Peso: kg/g · Volume: l/ml · Contagem: unidade do cadastro (ex.: fatia).
     * Vazio ou incompatível → unidade preferida do insumo (sem falhar).
     */
    function estoque_resolver_unidade_movimento(string $unidadeInformada, string $unidadeBaseInsumo): string
    {
        $baseInsumo = estoque_unidade_base($unidadeBaseInsumo);
        $preferida = estoque_unidade_preferida_entrada($baseInsumo);
        $u = strtolower(trim($unidadeInformada));

        if ($u === '') {
            return $preferida;
        }

        $baseInfo = estoque_unidade_base($u);

        if ($baseInsumo === 'g' && $baseInfo === 'g') {
            return in_array($u, ['kg', 'g'], true) ? $u : 'kg';
        }
        if ($baseInsumo === 'ml' && $baseInfo === 'ml') {
            return in_array($u, ['ml'], true) ? 'ml' : 'l';
        }
        if ($baseInsumo === $baseInfo) {
            return $baseInsumo;
        }

        // Família diferente (ex.: kg em fatia) → corrige para a do cadastro, sem erro.
        return $preferida;
    }
}

if (!function_exists('estoque_custo_para_base')) {
    /**
     * Converte custo unitário da unidade de entrada para custo por unidade base (g/ml/un).
     * Ex.: 450 MZN/kg → 0.45 MZN/g
     */
    function estoque_custo_para_base(?float $custoUnitario, string $unidade): ?float
    {
        if ($custoUnitario === null) {
            return null;
        }
        $factor = estoque_converter_para_base(1.0, $unidade);
        if ($factor <= 0) {
            return $custoUnitario;
        }
        return $custoUnitario / $factor;
    }
}

if (!function_exists('estoque_corrigir_custos_inflados')) {
    /**
     * Corrige custos gravados por engano em MZN/kg (ou MZN/l) sobre saldos em g/ml.
     * Heurística: custo_medio >= 1 em unidade base g/ml é quase sempre erro (ex.: 450 em vez de 0.45).
     */
    function estoque_corrigir_custos_inflados(PDO $db, int $restauranteId): int
    {
        $n = 0;
        $stmt = $db->prepare("
            UPDATE estoque_saldos s
            JOIN estoque_insumos i ON i.id = s.insumo_id
            SET s.custo_medio = s.custo_medio / 1000
            WHERE s.restaurante_id = ?
              AND i.unidade_base IN ('g', 'ml')
              AND s.custo_medio >= 1
        ");
        $stmt->execute([$restauranteId]);
        $n += $stmt->rowCount();

        $stmtL = $db->prepare("
            UPDATE estoque_lotes l
            JOIN estoque_insumos i ON i.id = l.insumo_id
            SET l.custo_unitario_base = l.custo_unitario_base / 1000
            WHERE l.restaurante_id = ?
              AND i.unidade_base IN ('g', 'ml')
              AND l.custo_unitario_base >= 1
        ");
        $stmtL->execute([$restauranteId]);
        $n += $stmtL->rowCount();

        return $n;
    }
}

if (!function_exists('estoque_upsert_saldo')) {
    function estoque_upsert_saldo(PDO $db, int $restauranteId, ?int $filialId, int $insumoId, float $deltaBase, ?float $custoUnit = null): void
    {
        // MySQL UNIQUE com NULL em filial_id não faz ON DUPLICATE KEY — actualiza/insere explicitamente.
        if ($filialId === null) {
            $stmtFind = $db->prepare("
                SELECT id, saldo_base, custo_medio
                FROM estoque_saldos
                WHERE restaurante_id = ? AND insumo_id = ? AND filial_id IS NULL
                ORDER BY id ASC
                LIMIT 1
            ");
            $stmtFind->execute([$restauranteId, $insumoId]);
        } else {
            $stmtFind = $db->prepare("
                SELECT id, saldo_base, custo_medio
                FROM estoque_saldos
                WHERE restaurante_id = ? AND insumo_id = ? AND filial_id = ?
                ORDER BY id ASC
                LIMIT 1
            ");
            $stmtFind->execute([$restauranteId, $insumoId, $filialId]);
        }
        $row = $stmtFind->fetch(PDO::FETCH_ASSOC);

        $custoNovo = ($custoUnit !== null && $custoUnit > 0) ? (float)$custoUnit : null;

        if ($row) {
            $saldoNovo = (float)$row['saldo_base'] + $deltaBase;
            $custoFinal = $custoNovo !== null ? $custoNovo : (float)$row['custo_medio'];
            $upd = $db->prepare("UPDATE estoque_saldos SET saldo_base = ?, custo_medio = ? WHERE id = ?");
            $upd->execute([$saldoNovo, $custoFinal, (int)$row['id']]);
        } else {
            $ins = $db->prepare("
                INSERT INTO estoque_saldos (restaurante_id, filial_id, insumo_id, saldo_base, custo_medio)
                VALUES (?, ?, ?, ?, ?)
            ");
            $ins->bindValue(1, $restauranteId, PDO::PARAM_INT);
            if ($filialId === null) {
                $ins->bindValue(2, null, PDO::PARAM_NULL);
            } else {
                $ins->bindValue(2, $filialId, PDO::PARAM_INT);
            }
            $ins->bindValue(3, $insumoId, PDO::PARAM_INT);
            $ins->bindValue(4, $deltaBase);
            $ins->bindValue(5, $custoNovo ?? 0);
            $ins->execute();
        }

        // Consolida duplicados de matriz (legado UNIQUE+NULL).
        if ($filialId === null) {
            $cnt = $db->prepare("
                SELECT COUNT(*) FROM estoque_saldos
                WHERE restaurante_id = ? AND insumo_id = ? AND filial_id IS NULL
            ");
            $cnt->execute([$restauranteId, $insumoId]);
            if ((int)$cnt->fetchColumn() > 1) {
                $sum = $db->prepare("
                    SELECT COALESCE(SUM(saldo_base),0), COALESCE(MAX(custo_medio),0)
                    FROM estoque_saldos
                    WHERE restaurante_id = ? AND insumo_id = ? AND filial_id IS NULL
                ");
                $sum->execute([$restauranteId, $insumoId]);
                $agg = $sum->fetch(PDO::FETCH_NUM) ?: [0, 0];
                $keep = $db->prepare("
                    SELECT MIN(id) FROM estoque_saldos
                    WHERE restaurante_id = ? AND insumo_id = ? AND filial_id IS NULL
                ");
                $keep->execute([$restauranteId, $insumoId]);
                $keepId = (int)$keep->fetchColumn();
                $db->prepare("
                    UPDATE estoque_saldos SET saldo_base = ?, custo_medio = ? WHERE id = ?
                ")->execute([(float)$agg[0], (float)$agg[1], $keepId]);
                $db->prepare("
                    DELETE FROM estoque_saldos
                    WHERE restaurante_id = ? AND insumo_id = ? AND filial_id IS NULL AND id <> ?
                ")->execute([$restauranteId, $insumoId, $keepId]);
            }
        }
    }
}

if (!function_exists('estoque_obter_saldo_insumo_base')) {
    function estoque_obter_saldo_insumo_base(PDO $db, int $restauranteId, ?int $filialId, int $insumoId): float
    {
        $stmt = $db->prepare("
            SELECT COALESCE(saldo_base, 0) AS saldo
            FROM estoque_saldos
            WHERE restaurante_id = :rid
              AND insumo_id = :insumo_id
              AND ((:filial_id_null IS NULL AND filial_id IS NULL) OR filial_id = :filial_id_val)
            LIMIT 1
        ");
        $stmt->execute([
            ':rid' => $restauranteId,
            ':insumo_id' => $insumoId,
            ':filial_id_null' => $filialId,
            ':filial_id_val' => $filialId,
        ]);
        return (float)($stmt->fetchColumn() ?: 0.0);
    }
}

if (!function_exists('estoque_validar_saldo_para_consumo')) {
    function estoque_validar_saldo_para_consumo(PDO $db, int $restauranteId, ?int $filialId, array $consumo): void
    {
        if (empty($consumo)) {
            return;
        }

        $insumoIds = array_map('intval', array_keys($consumo));
        $in = implode(',', array_fill(0, count($insumoIds), '?'));
        $sql = "
            SELECT id, nome
            FROM estoque_insumos
            WHERE restaurante_id = ?
              AND (? IS NULL OR filial_id = ?)
              AND id IN ($in)
        ";
        $stmtNomes = $db->prepare($sql);
        $stmtNomes->execute(array_merge([$restauranteId, $filialId, $filialId], $insumoIds));
        $nomesMap = [];
        foreach ($stmtNomes->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $nomesMap[(int)$row['id']] = (string)$row['nome'];
        }

        $faltas = [];
        foreach ($consumo as $insumoId => $qtdNecessaria) {
            $qtdNecessaria = max(0.0, (float)$qtdNecessaria);
            if ($qtdNecessaria <= 0) {
                continue;
            }
            $saldoAtual = estoque_obter_saldo_insumo_base($db, $restauranteId, $filialId, (int)$insumoId);
            if ($saldoAtual + 0.0001 < $qtdNecessaria) {
                $faltas[] = sprintf(
                    '%s (saldo %.3f base, precisa %.3f base)',
                    $nomesMap[(int)$insumoId] ?? ('Insumo #' . (int)$insumoId),
                    $saldoAtual,
                    $qtdNecessaria
                );
            }
        }

        if (!empty($faltas)) {
            throw new RuntimeException('ESTOQUE_INSUFICIENTE: ' . implode('; ', $faltas));
        }
    }
}

if (!function_exists('estoque_porcoes_disponiveis_por_produtos')) {
    /**
     * Calcula quantas unidades de cada produto dá para vender com o saldo atual dos insumos (ficha técnica).
     * Só devolve produtos que têm ficha técnica ativa. Produtos sem ficha ficam de fora (usar produtos.estoque).
     *
     * @return array<int,int> mapa produto_id => porções disponíveis (floor)
     */
    function estoque_porcoes_disponiveis_por_produtos(PDO $db, int $restauranteId, ?int $filialId, array $produtoIds): array
    {
        $produtoIds = array_values(array_unique(array_filter(array_map('intval', $produtoIds))));
        if ($restauranteId <= 0 || empty($produtoIds)) {
            return [];
        }

        $in = implode(',', array_fill(0, count($produtoIds), '?'));
        $sql = "
            SELECT ft.produto_id, ft.rendimento_por_produto, fi.insumo_id, fi.quantidade_base
            FROM estoque_fichas_tecnicas ft
            JOIN estoque_ficha_itens fi ON fi.ficha_id = ft.id
            WHERE ft.restaurante_id = ?
              AND (? IS NULL OR ft.filial_id = ?)
              AND ft.ativo = 1
              AND ft.produto_id IN ($in)
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge([$restauranteId, $filialId, $filialId], $produtoIds));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return [];
        }

        $fichas = [];
        $insumoIds = [];
        foreach ($rows as $r) {
            $pid = (int)$r['produto_id'];
            $insumoId = (int)$r['insumo_id'];
            if ($pid <= 0 || $insumoId <= 0) {
                continue;
            }
            if (!isset($fichas[$pid])) {
                $fichas[$pid] = [];
            }
            $fichas[$pid][] = [
                'insumo_id' => $insumoId,
                'quantidade_base' => (float)$r['quantidade_base'],
                'rendimento' => max(0.001, (float)$r['rendimento_por_produto']),
            ];
            $insumoIds[$insumoId] = true;
        }

        $saldos = [];
        foreach (array_keys($insumoIds) as $insumoId) {
            $saldos[(int)$insumoId] = estoque_obter_saldo_insumo_base($db, $restauranteId, $filialId, (int)$insumoId);
        }

        $resultado = [];
        foreach ($fichas as $pid => $itens) {
            $maxPorcoes = null;
            foreach ($itens as $item) {
                $consumoPorUnidade = ((float)$item['quantidade_base']) / ((float)$item['rendimento']);
                if ($consumoPorUnidade <= 0.0000001) {
                    continue;
                }
                $saldo = (float)($saldos[(int)$item['insumo_id']] ?? 0);
                $porcoes = (int)floor(($saldo + 0.0000001) / $consumoPorUnidade);
                if ($porcoes < 0) {
                    $porcoes = 0;
                }
                $maxPorcoes = $maxPorcoes === null ? $porcoes : min($maxPorcoes, $porcoes);
            }
            $resultado[(int)$pid] = $maxPorcoes === null ? 0 : (int)$maxPorcoes;
        }

        return $resultado;
    }
}

if (!function_exists('estoque_produto_ids_com_ficha_itens')) {
    /**
     * IDs de produtos com ficha técnica activa e pelo menos 1 insumo.
     *
     * @return array<int,true>
     */
    function estoque_produto_ids_com_ficha_itens(PDO $db, int $restauranteId, ?int $filialId, array $produtoIds): array
    {
        $produtoIds = array_values(array_unique(array_filter(array_map('intval', $produtoIds))));
        if ($restauranteId <= 0 || empty($produtoIds)) {
            return [];
        }

        $in = implode(',', array_fill(0, count($produtoIds), '?'));
        $sql = "
            SELECT DISTINCT ft.produto_id
            FROM estoque_fichas_tecnicas ft
            INNER JOIN estoque_ficha_itens fi ON fi.ficha_id = ft.id
            WHERE ft.restaurante_id = ?
              AND (? IS NULL OR ft.filial_id = ?)
              AND ft.ativo = 1
              AND ft.produto_id IN ($in)
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge([$restauranteId, $filialId, $filialId], $produtoIds));
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pid = (int)($row['produto_id'] ?? 0);
            if ($pid > 0) {
                $map[$pid] = true;
            }
        }
        return $map;
    }
}

if (!function_exists('estoque_validar_itens_com_ficha_obrigatoria')) {
    /**
     * No plano Profissional (estoque avançado), impede venda/pedido de produtos sem insumos na ficha.
     * Lança RuntimeException com prefixo SEM_FICHA: e nomes dos produtos.
     */
    function estoque_validar_itens_com_ficha_obrigatoria(PDO $db, int $restauranteId, ?int $filialId, array $itens): void
    {
        if (empty($itens)) {
            return;
        }

        $produtoIds = [];
        foreach ($itens as $item) {
            $pid = (int)($item['id'] ?? $item['Id'] ?? $item['produto_id'] ?? 0);
            if ($pid > 0) {
                $produtoIds[] = $pid;
            }
        }
        $produtoIds = array_values(array_unique($produtoIds));
        if (empty($produtoIds)) {
            return;
        }

        $comFicha = estoque_produto_ids_com_ficha_itens($db, $restauranteId, $filialId, $produtoIds);
        $semFicha = [];
        foreach ($produtoIds as $pid) {
            if (empty($comFicha[$pid])) {
                $semFicha[] = $pid;
            }
        }
        if (empty($semFicha)) {
            return;
        }

        $nomes = [];
        $in = implode(',', array_fill(0, count($semFicha), '?'));
        $stmt = $db->prepare("SELECT id, nome FROM produtos WHERE id IN ($in)");
        $stmt->execute($semFicha);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $nomes[(int)$row['id']] = (string)($row['nome'] ?? ('#' . $row['id']));
        }
        $lista = [];
        foreach ($semFicha as $pid) {
            $lista[] = $nomes[$pid] ?? ('#' . $pid);
        }
        throw new RuntimeException('SEM_FICHA: ' . implode(', ', $lista));
    }
}

if (!function_exists('estoque_registrar_movimento')) {
    function estoque_registrar_movimento(PDO $db, array $data): void
    {
        $stmt = $db->prepare("
            INSERT INTO estoque_movimentos
            (restaurante_id, filial_id, insumo_id, tipo, origem, quantidade_base, custo_unitario_base, custo_total, unidade_origem, pedido_id, venda_id, motivo, responsavel_id, meta_json)
            VALUES
            (:restaurante_id, :filial_id, :insumo_id, :tipo, :origem, :quantidade_base, :custo_unitario_base, :custo_total, :unidade_origem, :pedido_id, :venda_id, :motivo, :responsavel_id, :meta_json)
        ");
        $stmt->execute([
            ':restaurante_id' => (int)$data['restaurante_id'],
            ':filial_id' => array_key_exists('filial_id', $data) && $data['filial_id'] !== null && $data['filial_id'] !== ''
                ? (int)$data['filial_id']
                : null,
            ':insumo_id' => (int)$data['insumo_id'],
            ':tipo' => (string)$data['tipo'],
            ':origem' => (string)$data['origem'],
            ':quantidade_base' => (float)$data['quantidade_base'],
            ':custo_unitario_base' => isset($data['custo_unitario_base']) ? (float)$data['custo_unitario_base'] : null,
            ':custo_total' => isset($data['custo_total']) ? (float)$data['custo_total'] : null,
            ':unidade_origem' => $data['unidade_origem'] ?? null,
            ':pedido_id' => isset($data['pedido_id']) ? (int)$data['pedido_id'] : null,
            ':venda_id' => isset($data['venda_id']) ? (int)$data['venda_id'] : null,
            ':motivo' => $data['motivo'] ?? null,
            ':responsavel_id' => isset($data['responsavel_id']) ? (int)$data['responsavel_id'] : null,
            ':meta_json' => isset($data['meta_json']) ? json_encode($data['meta_json'], JSON_UNESCAPED_UNICODE) : null,
        ]);
    }
}

if (!function_exists('estoque_obter_ou_criar_insumo_no_restaurante')) {
    /**
     * Espelha um insumo noutro restaurante (por SKU ou nome+unidade).
     * Devolve o id do insumo no restaurante destino.
     */
    function estoque_obter_ou_criar_insumo_no_restaurante(
        PDO $db,
        int $insumoOrigemId,
        int $restauranteDestinoId
    ): int {
        $stmt = $db->prepare("SELECT * FROM estoque_insumos WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $insumoOrigemId]);
        $origem = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$origem) {
            throw new RuntimeException('Insumo de origem nao encontrado.');
        }

        if ((int)$origem['restaurante_id'] === $restauranteDestinoId) {
            return (int)$origem['id'];
        }

        $sku = trim((string)($origem['sku'] ?? ''));
        if ($sku !== '') {
            $stmt = $db->prepare("
                SELECT id FROM estoque_insumos
                WHERE restaurante_id = :rid
                  AND filial_id IS NULL
                  AND sku = :sku
                LIMIT 1
            ");
            $stmt->execute([':rid' => $restauranteDestinoId, ':sku' => $sku]);
            $found = (int)($stmt->fetchColumn() ?: 0);
            if ($found > 0) {
                return $found;
            }
        }

        $stmt = $db->prepare("
            SELECT id FROM estoque_insumos
            WHERE restaurante_id = :rid
              AND filial_id IS NULL
              AND nome = :nome
              AND unidade_base = :uni
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute([
            ':rid' => $restauranteDestinoId,
            ':nome' => (string)$origem['nome'],
            ':uni' => (string)$origem['unidade_base'],
        ]);
        $found = (int)($stmt->fetchColumn() ?: 0);
        if ($found > 0) {
            return $found;
        }

        $stmt = $db->prepare("
            INSERT INTO estoque_insumos
            (restaurante_id, filial_id, nome, sku, unidade_base, estoque_minimo_base, ativo)
            VALUES
            (:rid, NULL, :nome, :sku, :uni, :minimo, 1)
        ");
        $stmt->execute([
            ':rid' => $restauranteDestinoId,
            ':nome' => (string)$origem['nome'],
            ':sku' => null,
            ':uni' => (string)$origem['unidade_base'],
            ':minimo' => (float)($origem['estoque_minimo_base'] ?? 0),
        ]);

        $novoId = (int)$db->lastInsertId();
        estoque_garantir_sku_insumo($db, $restauranteDestinoId, $novoId);
        return $novoId;
    }
}

if (!function_exists('estoque_consumir_fifo_lotes')) {
    function estoque_consumir_fifo_lotes(PDO $db, int $restauranteId, ?int $filialId, int $insumoId, float $qtdBase): void
    {
        $falta = max(0.0, $qtdBase);
        if ($falta <= 0) {
            return;
        }

        $stmt = $db->prepare("
            SELECT id, saldo_base
            FROM estoque_lotes
            WHERE restaurante_id = :rid
              AND (:filial_id_null IS NULL OR filial_id = :filial_id_val)
              AND insumo_id = :insumo_id
              AND saldo_base > 0
            ORDER BY (validade IS NULL) ASC, validade ASC, criado_em ASC
        ");
        $stmt->execute([
            ':rid' => $restauranteId,
            ':filial_id_null' => $filialId,
            ':filial_id_val' => $filialId,
            ':insumo_id' => $insumoId,
        ]);
        $lotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($lotes as $lote) {
            if ($falta <= 0) {
                break;
            }
            $saldo = (float)$lote['saldo_base'];
            $consumo = min($saldo, $falta);
            $upd = $db->prepare("UPDATE estoque_lotes SET saldo_base = GREATEST(0, saldo_base - :qtd) WHERE id = :id");
            $upd->execute([':qtd' => $consumo, ':id' => (int)$lote['id']]);
            $falta -= $consumo;
        }
    }
}

if (!function_exists('estoque_gerar_sku')) {
    function estoque_gerar_sku(int $insumoId): string
    {
        return 'INS-' . str_pad((string)max(1, $insumoId), 5, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('estoque_garantir_sku_insumo')) {
    function estoque_garantir_sku_insumo(PDO $db, int $restauranteId, int $insumoId): string
    {
        $stmt = $db->prepare("
            SELECT id, sku
            FROM estoque_insumos
            WHERE id = :id AND restaurante_id = :rid
            LIMIT 1
        ");
        $stmt->execute([':id' => $insumoId, ':rid' => $restauranteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return '';
        }
        $sku = trim((string)($row['sku'] ?? ''));
        if ($sku !== '') {
            return $sku;
        }
        $sku = estoque_gerar_sku($insumoId);
        // Garante unicidade se já existir outro com o mesmo SKU.
        $chk = $db->prepare("
            SELECT id FROM estoque_insumos
            WHERE restaurante_id = :rid AND sku = :sku AND id <> :id
            LIMIT 1
        ");
        $chk->execute([':rid' => $restauranteId, ':sku' => $sku, ':id' => $insumoId]);
        if ($chk->fetchColumn()) {
            $sku = 'INS-' . $insumoId . '-' . substr(md5((string)$insumoId . microtime(true)), 0, 4);
        }
        $upd = $db->prepare("UPDATE estoque_insumos SET sku = :sku WHERE id = :id AND restaurante_id = :rid");
        $upd->execute([':sku' => $sku, ':id' => $insumoId, ':rid' => $restauranteId]);
        return $sku;
    }
}

if (!function_exists('estoque_backfill_skus')) {
    function estoque_backfill_skus(PDO $db, int $restauranteId): int
    {
        $stmt = $db->prepare("
            SELECT id
            FROM estoque_insumos
            WHERE restaurante_id = :rid
              AND (sku IS NULL OR TRIM(sku) = '')
        ");
        $stmt->execute([':rid' => $restauranteId]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $n = 0;
        foreach ($ids as $id) {
            if (estoque_garantir_sku_insumo($db, $restauranteId, (int)$id) !== '') {
                $n++;
            }
        }
        return $n;
    }
}

if (!function_exists('estoque_listar_lotes_validade')) {
    /**
     * Lotes com validade (vencidos ou a vencer em até 30 dias) e saldo > 0.
     */
    function estoque_listar_lotes_validade(PDO $db, int $restauranteId, ?int $filialId, int $diasJanela = 30): array
    {
        $diasJanela = max(7, min(90, $diasJanela));
        $sql = "
            SELECT
                l.id AS lote_id,
                l.insumo_id,
                i.nome AS insumo_nome,
                i.unidade_base,
                i.sku,
                l.saldo_base,
                l.validade,
                DATEDIFF(l.validade, CURDATE()) AS dias_restantes,
                l.referencia_compra,
                l.criado_em
            FROM estoque_lotes l
            JOIN estoque_insumos i ON i.id = l.insumo_id
            WHERE l.restaurante_id = :rid
              AND (:filial_id_null IS NULL OR l.filial_id = :filial_id_val)
              AND l.saldo_base > 0
              AND l.validade IS NOT NULL
              AND l.validade <= DATE_ADD(CURDATE(), INTERVAL {$diasJanela} DAY)
            ORDER BY l.validade ASC, l.saldo_base DESC
            LIMIT 50
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':rid' => $restauranteId,
            ':filial_id_null' => $filialId,
            ':filial_id_val' => $filialId,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $dias = (int)$r['dias_restantes'];
            if ($dias < 0) {
                $r['estado'] = 'VENCIDO';
                $r['severidade'] = 'CRITICA';
            } elseif ($dias <= 2) {
                $r['estado'] = 'CRITICO';
                $r['severidade'] = 'CRITICA';
            } elseif ($dias <= 7) {
                $r['estado'] = 'PROXIMO';
                $r['severidade'] = 'ALTA';
            } else {
                $r['estado'] = 'ATENCAO';
                $r['severidade'] = 'MEDIA';
            }
            $r['saldo_base'] = (float)$r['saldo_base'];
            $r['dias_restantes'] = $dias;
        }
        unset($r);
        return $rows;
    }
}

if (!function_exists('estoque_resolver_alertas_consumo_ok')) {
    function estoque_resolver_alertas_consumo_ok(PDO $db, int $restauranteId, ?int $filialId): void
    {
        // Consumo anómalo é só do dia: alertas de dias anteriores deixam de ser relevantes.
        $sql = "
            UPDATE estoque_alertas a
            SET a.resolvido = 1, a.resolvido_em = NOW()
            WHERE a.restaurante_id = :rid
              AND a.resolvido = 0
              AND a.tipo = 'CONSUMO_ANOMALO'
              AND a.criado_em < CURDATE()
              AND (
                    (:filial_null = 1 AND a.filial_id IS NULL)
                 OR (:filial_set = 0 AND a.filial_id = :filial_id)
              )
        ";
        $filialNullFlag = $filialId === null ? 1 : 0;
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':rid', $restauranteId, PDO::PARAM_INT);
        $stmt->bindValue(':filial_null', $filialNullFlag, PDO::PARAM_INT);
        $stmt->bindValue(':filial_set', $filialNullFlag, PDO::PARAM_INT);
        $stmt->bindValue(':filial_id', $filialId ?? 0, PDO::PARAM_INT);
        $stmt->execute();
    }
}

if (!function_exists('estoque_resolver_alertas_validade_ok')) {
    function estoque_resolver_alertas_validade_ok(PDO $db, int $restauranteId, ?int $filialId): void
    {
        // Resolve alertas de validade quando já não há lotes vencidos/próximos com saldo.
        $sql = "
            UPDATE estoque_alertas a
            SET a.resolvido = 1, a.resolvido_em = NOW()
            WHERE a.restaurante_id = :rid
              AND a.resolvido = 0
              AND a.tipo = 'VALIDADE'
              AND ((:filial_id_null IS NULL AND a.filial_id IS NULL) OR a.filial_id = :filial_id_val)
              AND NOT EXISTS (
                  SELECT 1
                  FROM estoque_lotes l
                  WHERE l.restaurante_id = a.restaurante_id
                    AND l.insumo_id = a.insumo_id
                    AND (:filial_id_null2 IS NULL OR l.filial_id = :filial_id_val2)
                    AND l.saldo_base > 0
                    AND l.validade IS NOT NULL
                    AND l.validade <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
              )
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':rid' => $restauranteId,
            ':filial_id_null' => $filialId,
            ':filial_id_val' => $filialId,
            ':filial_id_null2' => $filialId,
            ':filial_id_val2' => $filialId,
        ]);
    }
}

if (!function_exists('estoque_alerta_aberto_existe')) {
    function estoque_alerta_aberto_existe(PDO $db, int $restauranteId, ?int $filialId, int $insumoId, string $tipo): bool
    {
        $stmt = $db->prepare("
            SELECT 1
            FROM estoque_alertas
            WHERE restaurante_id = :rid
              AND ((:filial_id_null IS NULL AND filial_id IS NULL) OR filial_id = :filial_id_val)
              AND insumo_id = :insumo_id
              AND tipo = :tipo
              AND (resolvido = 0
                   -- Resolvido a mao pelo gerente (resolvido = 2): nao volta a avisar durante 24 h.
                   OR (resolvido = 2 AND resolvido_em > NOW() - INTERVAL 24 HOUR))
            LIMIT 1
        ");
        $stmt->execute([
            ':rid' => $restauranteId,
            ':filial_id_null' => $filialId,
            ':filial_id_val' => $filialId,
            ':insumo_id' => $insumoId,
            ':tipo' => $tipo,
        ]);
        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('estoque_formatar_quantidade')) {
    /**
     * Formata quantidade na unidade base (g/ml/un) para leitura humana (kg/l quando faz sentido).
     */
    function estoque_formatar_quantidade(float $qtdBase, string $unidadeBase): string
    {
        $base = estoque_unidade_base($unidadeBase);
        if ($base === 'g') {
            if (abs($qtdBase) >= 1000) {
                $kg = $qtdBase / 1000.0;
                $txt = number_format($kg, abs($kg - round($kg)) < 0.001 ? 0 : 2, ',', '.');
                return $txt . ' kg';
            }
            return number_format($qtdBase, abs($qtdBase - round($qtdBase)) < 0.001 ? 0 : 1, ',', '.') . ' g';
        }
        if ($base === 'ml') {
            if (abs($qtdBase) >= 1000) {
                $lt = $qtdBase / 1000.0;
                $txt = number_format($lt, abs($lt - round($lt)) < 0.001 ? 0 : 2, ',', '.');
                return $txt . ' l';
            }
            return number_format($qtdBase, abs($qtdBase - round($qtdBase)) < 0.001 ? 0 : 1, ',', '.') . ' ml';
        }
        $dec = abs($qtdBase - round($qtdBase)) < 0.001 ? 0 : 1;
        return number_format($qtdBase, $dec, ',', '.') . ' ' . ($base !== '' ? $base : 'un');
    }
}

if (!function_exists('estoque_resolver_alertas_reposicao_ok')) {
    function estoque_resolver_alertas_reposicao_ok(PDO $db, int $restauranteId, ?int $filialId): void
    {
        // Junta o saldo pelo filial do ALERTA (não só do cadastro do insumo).
        // Antes, alertas da matriz podiam ficar abertos mesmo com stock OK.
        // Nota: com ATTR_EMULATE_PREPARES=false, cada placeholder nomeado só pode aparecer 1x.
        $sql = "
            UPDATE estoque_alertas a
            JOIN estoque_insumos i ON i.id = a.insumo_id
            LEFT JOIN estoque_saldos s
              ON s.insumo_id = a.insumo_id
             AND s.restaurante_id = a.restaurante_id
             AND (
                    (a.filial_id IS NULL AND s.filial_id IS NULL)
                 OR (a.filial_id IS NOT NULL AND s.filial_id = a.filial_id)
             )
            SET a.resolvido = 1, a.resolvido_em = NOW()
            WHERE a.restaurante_id = :rid
              AND a.resolvido = 0
              AND a.tipo = 'REPOSICAO'
              AND (
                    (:filial_null = 1 AND a.filial_id IS NULL)
                 OR (:filial_set = 0 AND a.filial_id = :filial_id)
              )
              AND COALESCE(s.saldo_base, 0) > COALESCE(i.estoque_minimo_base, 0)
        ";
        $filialNullFlag = $filialId === null ? 1 : 0;
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':rid', $restauranteId, PDO::PARAM_INT);
        $stmt->bindValue(':filial_null', $filialNullFlag, PDO::PARAM_INT);
        $stmt->bindValue(':filial_set', $filialNullFlag, PDO::PARAM_INT);
        $stmt->bindValue(':filial_id', $filialId ?? 0, PDO::PARAM_INT);
        $stmt->execute();
    }
}

if (!function_exists('estoque_gerar_alertas_automaticos')) {
    function estoque_gerar_alertas_automaticos(PDO $db, int $restauranteId, ?int $filialId): void
    {
        // Fecha alertas de insumos apagados ou desativados (ficavam abertos para sempre).
        $db->prepare("UPDATE estoque_alertas a LEFT JOIN estoque_insumos i ON i.id = a.insumo_id AND i.restaurante_id = a.restaurante_id
            SET a.resolvido = 1, a.resolvido_em = NOW()
            WHERE a.restaurante_id = ? AND a.resolvido = 0 AND a.insumo_id IS NOT NULL AND (i.id IS NULL OR COALESCE(i.ativo, 1) = 0)")
            ->execute([$restauranteId]);
        // Fecha alertas de reposição/validade/consumo quando já não se aplicam.
        estoque_resolver_alertas_reposicao_ok($db, $restauranteId, $filialId);
        estoque_resolver_alertas_validade_ok($db, $restauranteId, $filialId);
        estoque_resolver_alertas_consumo_ok($db, $restauranteId, $filialId);
        estoque_backfill_skus($db, $restauranteId);

        // Colapsa duplicados abertos (mesmo insumo + tipo) — mantém o mais recente.
        $dupSql = "
            UPDATE estoque_alertas a
            JOIN (
                SELECT MAX(id) AS keep_id, restaurante_id, filial_id, insumo_id, tipo
                FROM estoque_alertas
                WHERE restaurante_id = :rid
                  AND resolvido = 0
                  AND (:filial_id_null IS NULL OR filial_id = :filial_id_val)
                GROUP BY restaurante_id, filial_id, insumo_id, tipo
                HAVING COUNT(*) > 1
            ) k
              ON a.restaurante_id = k.restaurante_id
             AND ((a.filial_id IS NULL AND k.filial_id IS NULL) OR a.filial_id = k.filial_id)
             AND a.insumo_id = k.insumo_id
             AND a.tipo = k.tipo
            SET a.resolvido = 1, a.resolvido_em = NOW()
            WHERE a.resolvido = 0
              AND a.id <> k.keep_id
        ";
        $dupStmt = $db->prepare($dupSql);
        $dupStmt->execute([
            ':rid' => $restauranteId,
            ':filial_id_null' => $filialId,
            ':filial_id_val' => $filialId,
        ]);

        // Saldo pelo contexto da filial do alerta (mesmo critério do resolve),
        // não pelo filial_id do cadastro do insumo — evita alertas fantasmas.
        // Placeholders únicos (EMULATE_PREPARES=false).
        $stmt = $db->prepare("
            SELECT i.id, i.nome, i.estoque_minimo_base, COALESCE(s.saldo_base,0) AS saldo
            FROM estoque_insumos i
            LEFT JOIN estoque_saldos s
              ON s.insumo_id = i.id
             AND s.restaurante_id = i.restaurante_id
             AND (
                    (:saldo_filial_null = 1 AND s.filial_id IS NULL)
                 OR (:saldo_filial_set = 0 AND s.filial_id = :saldo_filial_id)
             )
            WHERE i.restaurante_id = :rid
              AND i.ativo = 1
              AND (
                    (:ins_filial_null = 1 AND i.filial_id IS NULL)
                 OR (:ins_filial_set = 0 AND (i.filial_id IS NULL OR i.filial_id = :ins_filial_id))
              )
        ");
        $filialNullFlag = $filialId === null ? 1 : 0;
        $filialVal = $filialId ?? 0;
        $stmt->bindValue(':rid', $restauranteId, PDO::PARAM_INT);
        $stmt->bindValue(':saldo_filial_null', $filialNullFlag, PDO::PARAM_INT);
        $stmt->bindValue(':saldo_filial_set', $filialNullFlag, PDO::PARAM_INT);
        $stmt->bindValue(':saldo_filial_id', $filialVal, PDO::PARAM_INT);
        $stmt->bindValue(':ins_filial_null', $filialNullFlag, PDO::PARAM_INT);
        $stmt->bindValue(':ins_filial_set', $filialNullFlag, PDO::PARAM_INT);
        $stmt->bindValue(':ins_filial_id', $filialVal, PDO::PARAM_INT);
        $stmt->execute();
        $insumos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $insert = $db->prepare("
            INSERT INTO estoque_alertas (restaurante_id, filial_id, insumo_id, tipo, severidade, titulo, detalhe)
            VALUES (:rid, :filial_id, :insumo_id, :tipo, :sev, :titulo, :detalhe)
        ");

        foreach ($insumos as $i) {
            $saldo = (float)$i['saldo'];
            $minimo = (float)$i['estoque_minimo_base'];
            $insumoId = (int)$i['id'];
            if ($minimo > 0 && $saldo <= $minimo) {
                if (!estoque_alerta_aberto_existe($db, $restauranteId, $filialId, $insumoId, 'REPOSICAO')) {
                    $sev = $saldo <= 0 ? 'CRITICA' : 'ALTA';
                    $insert->execute([
                        ':rid' => $restauranteId,
                        ':filial_id' => $filialId,
                        ':insumo_id' => $insumoId,
                        ':tipo' => 'REPOSICAO',
                        ':sev' => $sev,
                        ':titulo' => 'Reposicao urgente: ' . $i['nome'],
                        ':detalhe' => 'Saldo atual ' . number_format($saldo, 3, '.', '') . ' abaixo do minimo ' . number_format($minimo, 3, '.', ''),
                    ]);
                }
            }
        }

        // Validade: inclui vencidos e próximos (7 dias). Atualiza alerta aberto se necessário.
        $val = $db->prepare("
            SELECT l.insumo_id, i.nome,
                   MIN(DATEDIFF(l.validade, CURDATE())) AS dias,
                   SUM(l.saldo_base) AS saldo_lote
            FROM estoque_lotes l
            JOIN estoque_insumos i ON i.id = l.insumo_id
            WHERE l.restaurante_id = :rid
              AND (:filial_id_null IS NULL OR l.filial_id = :filial_id_val)
              AND l.saldo_base > 0
              AND l.validade IS NOT NULL
              AND l.validade <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            GROUP BY l.insumo_id, i.nome
        ");
        $val->execute([
            ':rid' => $restauranteId,
            ':filial_id_null' => $filialId,
            ':filial_id_val' => $filialId,
        ]);
        $updVal = $db->prepare("
            UPDATE estoque_alertas
            SET severidade = ?, titulo = ?, detalhe = ?
            WHERE restaurante_id = ?
              AND insumo_id = ?
              AND tipo = 'VALIDADE'
              AND resolvido = 0
              AND (
                    (? = 1 AND filial_id IS NULL)
                 OR (? = 0 AND filial_id = ?)
              )
        ");
        foreach ($val->fetchAll(PDO::FETCH_ASSOC) as $lote) {
            $insumoId = (int)$lote['insumo_id'];
            $dias = (int)$lote['dias'];
            $sev = $dias < 0 ? 'CRITICA' : ($dias <= 2 ? 'CRITICA' : ($dias <= 5 ? 'ALTA' : 'MEDIA'));
            $titulo = $dias < 0
                ? ('Lote vencido: ' . $lote['nome'])
                : ('Validade proxima: ' . $lote['nome']);
            $detalhe = $dias < 0
                ? ('Vencido ha ' . abs($dias) . ' dia(s) · saldo ' . number_format((float)$lote['saldo_lote'], 3, '.', ''))
                : ('Vence em ' . $dias . ' dia(s) · saldo ' . number_format((float)$lote['saldo_lote'], 3, '.', ''));

            if (estoque_alerta_aberto_existe($db, $restauranteId, $filialId, $insumoId, 'VALIDADE')) {
                $filialMatchNull = $filialId === null ? 1 : 0;
                $updVal->execute([
                    $sev,
                    $titulo,
                    $detalhe,
                    $restauranteId,
                    $insumoId,
                    $filialMatchNull,
                    $filialMatchNull,
                    $filialId ?? 0,
                ]);
                continue;
            }
            $insert->execute([
                ':rid' => $restauranteId,
                ':filial_id' => $filialId,
                ':insumo_id' => $insumoId,
                ':tipo' => 'VALIDADE',
                ':sev' => $sev,
                ':titulo' => $titulo,
                ':detalhe' => $detalhe,
            ]);
        }

        // Só alerta quando a saída total supera o consumo previsto pelas vendas
        // e a média histórica; exige pelo menos 7 dias anteriores com movimento.
        $anom = $db->prepare("
            SELECT
                em.insumo_id,
                i.nome,
                i.unidade_base,
                SUM(CASE
                    WHEN em.tipo = 'SAIDA' AND em.criado_em >= CURDATE() AND em.criado_em <= NOW()
                    THEN em.quantidade_base ELSE 0
                END) AS hoje,
                SUM(CASE
                    WHEN em.tipo = 'SAIDA' AND em.origem IN ('PEDIDO', 'CAIXA')
                     AND em.criado_em >= CURDATE() AND em.criado_em <= NOW()
                    THEN em.quantidade_base ELSE 0
                END) AS previsto_hoje,
                COUNT(DISTINCT CASE
                    WHEN em.tipo = 'SAIDA' AND em.origem IN ('PEDIDO', 'CAIXA')
                     AND em.criado_em < CURDATE() AND TIME(em.criado_em) <= CURTIME()
                    THEN DATE(em.criado_em) ELSE NULL
                END) AS dias_historico,
                SUM(CASE
                    WHEN em.tipo = 'SAIDA' AND em.origem IN ('PEDIDO', 'CAIXA')
                     AND em.criado_em < CURDATE() AND TIME(em.criado_em) <= CURTIME()
                    THEN em.quantidade_base ELSE 0
                END) /
                NULLIF(COUNT(DISTINCT CASE
                    WHEN em.tipo = 'SAIDA' AND em.origem IN ('PEDIDO', 'CAIXA')
                     AND em.criado_em < CURDATE() AND TIME(em.criado_em) <= CURTIME()
                    THEN DATE(em.criado_em) ELSE NULL
                END), 0) AS media_dia
            FROM estoque_movimentos em
            JOIN estoque_insumos i ON i.id = em.insumo_id
            WHERE em.restaurante_id = :rid
              AND (:filial_id_null IS NULL OR em.filial_id = :filial_id_val)
              AND em.criado_em >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
              AND em.criado_em <= NOW()
            GROUP BY em.insumo_id, i.nome, i.unidade_base
            HAVING hoje > 0
               AND dias_historico >= 7
               AND media_dia > 0
               AND hoje > (GREATEST(previsto_hoje, media_dia) * 2.5)
        ");
        $anom->execute([
            ':rid' => $restauranteId,
            ':filial_id_null' => $filialId,
            ':filial_id_val' => $filialId,
        ]);
        $anomRows = $anom->fetchAll(PDO::FETCH_ASSOC);
        $insumosAnomalos = array_map(
            static fn(array $a): int => (int)$a['insumo_id'],
            $anomRows
        );
        $resolverAnomaliasDesatualizadasSql = "
            UPDATE estoque_alertas
            SET resolvido = 1, resolvido_em = NOW()
            WHERE restaurante_id = ?
              AND resolvido = 0
              AND tipo = 'CONSUMO_ANOMALO'
              AND criado_em >= CURDATE()
              AND criado_em < CURDATE() + INTERVAL 1 DAY
              AND ((? IS NULL AND filial_id IS NULL) OR filial_id = ?)
        ";
        $resolverAnomaliasDesatualizadasParams = [$restauranteId, $filialId, $filialId];
        if ($insumosAnomalos) {
            $placeholders = implode(',', array_fill(0, count($insumosAnomalos), '?'));
            $resolverAnomaliasDesatualizadasSql .= " AND insumo_id NOT IN ($placeholders)";
            $resolverAnomaliasDesatualizadasParams = array_merge(
                $resolverAnomaliasDesatualizadasParams,
                $insumosAnomalos
            );
        }
        $db->prepare($resolverAnomaliasDesatualizadasSql)->execute($resolverAnomaliasDesatualizadasParams);

        $atualizarAlertaAnomalia = $db->prepare("
            UPDATE estoque_alertas
            SET titulo = ?, detalhe = ?
            WHERE restaurante_id = ?
              AND insumo_id = ?
              AND tipo = 'CONSUMO_ANOMALO'
              AND resolvido = 0
              AND criado_em >= CURDATE()
              AND criado_em < CURDATE() + INTERVAL 1 DAY
              AND ((? IS NULL AND filial_id IS NULL) OR filial_id = ?)
        ");
        foreach ($anomRows as $a) {
            $insumoId = (int)$a['insumo_id'];
            $titulo = 'Consumo anomalo: ' . $a['nome'];
            $unidadeBase = (string)($a['unidade_base'] ?? 'un');
            $consumoHoje = estoque_formatar_quantidade((float)$a['hoje'], $unidadeBase);
            $consumoPrevisto = estoque_formatar_quantidade((float)$a['previsto_hoje'], $unidadeBase);
            $consumoMedio = estoque_formatar_quantidade((float)$a['media_dia'], $unidadeBase);
            $referencia = max((float)$a['previsto_hoje'], (float)$a['media_dia']);
            $vezesAcima = (float)$a['hoje'] / $referencia;
            $vezesTexto = number_format($vezesAcima, 1, ',', '.');
            if (str_ends_with($vezesTexto, ',0')) {
                $vezesTexto = substr($vezesTexto, 0, -2);
            }
            $detalhe = 'Hoje: ' . $consumoHoje .
                '. Previsto pelas vendas: ' . $consumoPrevisto .
                '. Média no mesmo horário (' . (int)$a['dias_historico'] . ' dias): ' . $consumoMedio .
                '. Total ' . $vezesTexto . ' vezes acima da referência.';
            $atualizarAlertaAnomalia->execute([
                $titulo,
                $detalhe,
                $restauranteId,
                $insumoId,
                $filialId,
                $filialId,
            ]);
            if (estoque_alerta_aberto_existe($db, $restauranteId, $filialId, $insumoId, 'CONSUMO_ANOMALO')) {
                continue;
            }
            $insert->execute([
                ':rid' => $restauranteId,
                ':filial_id' => $filialId,
                ':insumo_id' => $insumoId,
                ':tipo' => 'CONSUMO_ANOMALO',
                ':sev' => 'MEDIA',
                ':titulo' => $titulo,
                ':detalhe' => $detalhe,
            ]);
        }
    }
}

if (!function_exists('estoque_normalizar_nome_chave')) {
    function estoque_normalizar_nome_chave(string $nome): string
    {
        if (function_exists('mb_strtolower')) {
            $n = trim(mb_strtolower($nome, 'UTF-8'));
        } else {
            $n = trim(strtolower($nome));
        }
        $n = preg_replace('/\s+/', ' ', $n ?? '');
        return (string)$n;
    }
}

if (!function_exists('estoque_deduplicar_insumos')) {
    function estoque_deduplicar_insumos(PDO $db, int $restauranteId, ?int $filialId): int
    {
        $sql = "
            SELECT id, nome, unidade_base
            FROM estoque_insumos
            WHERE restaurante_id = :rid
              AND ativo = 1
              AND ((:filial_id_null IS NULL AND filial_id IS NULL) OR filial_id = :filial_id_val)
            ORDER BY id ASC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':rid' => $restauranteId,
            ':filial_id_null' => $filialId,
            ':filial_id_val' => $filialId,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) {
            return 0;
        }

        $grupos = [];
        foreach ($rows as $r) {
            $key = estoque_normalizar_nome_chave((string)$r['nome']) . '|' . (string)$r['unidade_base'];
            if (!isset($grupos[$key])) {
                $grupos[$key] = [];
            }
            $grupos[$key][] = (int)$r['id'];
        }

        $fundidos = 0;
        foreach ($grupos as $ids) {
            if (count($ids) <= 1) {
                continue;
            }
            $keepId = (int)$ids[0];
            $dupIds = array_slice($ids, 1);
            if (empty($dupIds)) {
                continue;
            }

            $in = implode(',', array_fill(0, count($dupIds), '?'));

            $stmtSum = $db->prepare("SELECT COALESCE(SUM(saldo_base),0) FROM estoque_saldos WHERE restaurante_id = ? AND ((? IS NULL AND filial_id IS NULL) OR filial_id = ?) AND insumo_id IN ($in)");
            $stmtSum->execute(array_merge([$restauranteId, $filialId, $filialId], $dupIds));
            $saldoDup = (float)$stmtSum->fetchColumn();
            if (abs($saldoDup) > 0.000001) {
                estoque_upsert_saldo($db, $restauranteId, $filialId, $keepId, $saldoDup, null);
            }

            $params = array_merge([$keepId], $dupIds);
            $db->prepare("UPDATE estoque_ficha_itens SET insumo_id = ? WHERE insumo_id IN ($in)")->execute($params);
            $db->prepare("UPDATE estoque_lotes SET insumo_id = ? WHERE insumo_id IN ($in)")->execute($params);
            $db->prepare("UPDATE estoque_movimentos SET insumo_id = ? WHERE insumo_id IN ($in)")->execute($params);
            $db->prepare("UPDATE estoque_perdas SET insumo_id = ? WHERE insumo_id IN ($in)")->execute($params);
            $db->prepare("UPDATE estoque_alertas SET insumo_id = ? WHERE insumo_id IN ($in)")->execute($params);

            $db->prepare("DELETE FROM estoque_saldos WHERE restaurante_id = ? AND ((? IS NULL AND filial_id IS NULL) OR filial_id = ?) AND insumo_id IN ($in)")
                ->execute(array_merge([$restauranteId, $filialId, $filialId], $dupIds));
            $db->prepare("DELETE FROM estoque_insumos WHERE restaurante_id = ? AND id IN ($in)")
                ->execute(array_merge([$restauranteId], $dupIds));

            $fundidos += count($dupIds);
        }

        return $fundidos;
    }
}

if (!function_exists('estoque_baixa_automatica_por_pedido')) {
    function estoque_baixa_automatica_por_pedido(PDO $db, int $restauranteId, ?int $filialId, int $pedidoId, ?int $responsavelId = null): void
    {
        $sql = "
            SELECT ip.produto_id, ip.quantidade, ft.id AS ficha_id, ft.rendimento_por_produto, fi.insumo_id, fi.quantidade_base
            FROM itens_pedido ip
            JOIN estoque_fichas_tecnicas ft
              ON ft.produto_id = ip.produto_id
             AND ft.restaurante_id = :rid
             AND ft.ativo = 1
             AND (:filial_id_null IS NULL OR ft.filial_id = :filial_id_val)
            JOIN estoque_ficha_itens fi ON fi.ficha_id = ft.id
            WHERE ip.pedido_id = :pedido_id
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':rid' => $restauranteId,
            ':filial_id_null' => $filialId,
            ':filial_id_val' => $filialId,
            ':pedido_id' => $pedidoId,
        ]);
        $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$linhas) {
            return;
        }

        $consumo = [];
        foreach ($linhas as $l) {
            $qtdPedido = (float)$l['quantidade'];
            $rendimento = max(0.001, (float)$l['rendimento_por_produto']);
            $qtdBaseInsumo = (float)$l['quantidade_base'];
            $totalInsumo = ($qtdPedido / $rendimento) * $qtdBaseInsumo;
            $insumoId = (int)$l['insumo_id'];
            if (!isset($consumo[$insumoId])) {
                $consumo[$insumoId] = 0.0;
            }
            $consumo[$insumoId] += $totalInsumo;
        }

        estoque_validar_saldo_para_consumo($db, $restauranteId, $filialId, $consumo);

        foreach ($consumo as $insumoId => $qtdBase) {
            estoque_upsert_saldo($db, $restauranteId, $filialId, $insumoId, -$qtdBase, null);
            estoque_consumir_fifo_lotes($db, $restauranteId, $filialId, $insumoId, $qtdBase);
            estoque_registrar_movimento($db, [
                'restaurante_id' => $restauranteId,
                'filial_id' => $filialId,
                'insumo_id' => $insumoId,
                'tipo' => 'SAIDA',
                'origem' => 'PEDIDO',
                'quantidade_base' => $qtdBase,
                'pedido_id' => $pedidoId,
                'responsavel_id' => $responsavelId,
                'motivo' => 'Baixa automatica por ficha tecnica',
            ]);
        }
    }
}

if (!function_exists('estoque_baixa_automatica_por_itens_venda')) {
    function estoque_baixa_automatica_por_itens_venda(PDO $db, int $restauranteId, ?int $filialId, array $itens, ?int $vendaId = null, ?int $responsavelId = null): void
    {
        if (empty($itens)) {
            return;
        }

        $produtoIds = [];
        foreach ($itens as $item) {
            $pid = (int)($item['id'] ?? $item['Id'] ?? $item['produto_id'] ?? 0);
            if ($pid > 0) {
                $produtoIds[] = $pid;
            }
        }
        $produtoIds = array_values(array_unique($produtoIds));
        if (empty($produtoIds)) {
            return;
        }

        $in = implode(',', array_fill(0, count($produtoIds), '?'));
        $sql = "
            SELECT ft.produto_id, ft.rendimento_por_produto, fi.insumo_id, fi.quantidade_base
            FROM estoque_fichas_tecnicas ft
            JOIN estoque_ficha_itens fi ON fi.ficha_id = ft.id
            WHERE ft.restaurante_id = ?
              AND (? IS NULL OR ft.filial_id = ?)
              AND ft.ativo = 1
              AND ft.produto_id IN ($in)
        ";
        $stmt = $db->prepare($sql);
        $params = array_merge([$restauranteId, $filialId, $filialId], $produtoIds);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return;
        }

        $fichas = [];
        foreach ($rows as $r) {
            $pid = (int)$r['produto_id'];
            if (!isset($fichas[$pid])) {
                $fichas[$pid] = [];
            }
            $fichas[$pid][] = $r;
        }

        $consumo = [];
        foreach ($itens as $item) {
            $pid = (int)($item['id'] ?? $item['Id'] ?? $item['produto_id'] ?? 0);
            $qtd = (float)($item['quantidade'] ?? $item['qtd'] ?? 0);
            if ($pid <= 0 || $qtd <= 0 || !isset($fichas[$pid])) {
                continue;
            }
            foreach ($fichas[$pid] as $f) {
                $rendimento = max(0.001, (float)$f['rendimento_por_produto']);
                $insumoId = (int)$f['insumo_id'];
                $qtdInsumo = ($qtd / $rendimento) * (float)$f['quantidade_base'];
                if (!isset($consumo[$insumoId])) {
                    $consumo[$insumoId] = 0.0;
                }
                $consumo[$insumoId] += $qtdInsumo;
            }
        }

        estoque_validar_saldo_para_consumo($db, $restauranteId, $filialId, $consumo);

        foreach ($consumo as $insumoId => $qtdBase) {
            estoque_upsert_saldo($db, $restauranteId, $filialId, $insumoId, -$qtdBase, null);
            estoque_consumir_fifo_lotes($db, $restauranteId, $filialId, $insumoId, $qtdBase);
            estoque_registrar_movimento($db, [
                'restaurante_id' => $restauranteId,
                'filial_id' => $filialId,
                'insumo_id' => $insumoId,
                'tipo' => 'SAIDA',
                'origem' => 'CAIXA',
                'quantidade_base' => $qtdBase,
                'venda_id' => $vendaId,
                'responsavel_id' => $responsavelId,
                'motivo' => 'Baixa automatica por ficha tecnica em venda direta',
            ]);
        }
    }
}

if (!function_exists('estoque_previsao_consumo')) {
    /**
     * Previsão de consumo e sugestão de compra.
     * $opcoes['modo'] = 'operacional' (default) | 'empresarial'
     * Emp: média por dia da semana, EWMA 7d vs janela, lead time + safety, urgência por validade.
     *
     * @return list<array<string,mixed>>
     */
    function estoque_previsao_consumo(PDO $db, int $restauranteId, ?int $filialId, int $diasJanela = 28, array $opcoes = []): array
    {
        $diasJanela = max(7, min(90, $diasJanela));
        $modo = strtolower(trim((string)($opcoes['modo'] ?? 'operacional')));
        $empresarial = ($modo === 'empresarial');
        $leadDays = max(1, min(30, (int)($opcoes['lead_days'] ?? ($empresarial ? 5 : 0))));
        $safetyDays = max(1, min(21, (int)($opcoes['safety_days'] ?? ($empresarial ? 3 : 3))));
        if (!$empresarial) {
            $leadDays = 0;
            $safetyDays = 3;
        }

        // Agrupamento diário (Emp) ou agregado (operacional).
        if ($empresarial) {
            $sql = "
                SELECT
                    em.insumo_id,
                    DATE(em.criado_em) AS dia,
                    DAYOFWEEK(em.criado_em) AS dow,
                    SUM(CASE WHEN em.tipo = 'SAIDA' THEN em.quantidade_base ELSE 0 END) AS saida_dia
                FROM estoque_movimentos em
                WHERE em.restaurante_id = :rid
                  AND (:filial_id_null IS NULL OR em.filial_id = :filial_id_val)
                  AND em.criado_em >= DATE_SUB(NOW(), INTERVAL {$diasJanela} DAY)
                  AND em.tipo = 'SAIDA'
                GROUP BY em.insumo_id, DATE(em.criado_em), DAYOFWEEK(em.criado_em)
            ";
        } else {
            $sql = "
                SELECT
                    em.insumo_id,
                    SUM(CASE WHEN em.tipo = 'SAIDA' THEN em.quantidade_base ELSE 0 END) AS total_saida,
                    SUM(CASE WHEN DAYOFWEEK(em.criado_em) IN (1,7) AND em.tipo = 'SAIDA' THEN em.quantidade_base ELSE 0 END) AS fim_semana_saida,
                    SUM(CASE WHEN em.tipo = 'SAIDA' AND em.criado_em >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN em.quantidade_base ELSE 0 END) AS saida_7d
                FROM estoque_movimentos em
                WHERE em.restaurante_id = :rid
                  AND (:filial_id_null IS NULL OR em.filial_id = :filial_id_val)
                  AND em.criado_em >= DATE_SUB(NOW(), INTERVAL {$diasJanela} DAY)
                GROUP BY em.insumo_id
            ";
        }
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':rid' => $restauranteId,
            ':filial_id_null' => $filialId,
            ':filial_id_val' => $filialId,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Saldos com critério alinhado a estoque_obter_saldo_insumo_base (não i.filial_id = s.filial_id).
        $saldosStmt = $db->prepare("
            SELECT i.id AS insumo_id, i.nome, i.unidade_base, i.estoque_minimo_base,
                   COALESCE((
                       SELECT s.saldo_base FROM estoque_saldos s
                       WHERE s.restaurante_id = i.restaurante_id
                         AND s.insumo_id = i.id
                         AND ((:filial_id_null IS NULL AND s.filial_id IS NULL) OR s.filial_id = :filial_id_val)
                       LIMIT 1
                   ), 0) AS saldo_base,
                   COALESCE((
                       SELECT s.custo_medio FROM estoque_saldos s
                       WHERE s.restaurante_id = i.restaurante_id
                         AND s.insumo_id = i.id
                         AND ((:filial_id_null2 IS NULL AND s.filial_id IS NULL) OR s.filial_id = :filial_id_val2)
                       LIMIT 1
                   ), 0) AS custo_medio
            FROM estoque_insumos i
            WHERE i.restaurante_id = :rid
              AND i.ativo = 1
              AND (:filial_id_null3 IS NULL OR i.filial_id = :filial_id_val3 OR i.filial_id IS NULL)
        ");
        $saldosStmt->execute([
            ':rid' => $restauranteId,
            ':filial_id_null' => $filialId,
            ':filial_id_val' => $filialId,
            ':filial_id_null2' => $filialId,
            ':filial_id_val2' => $filialId,
            ':filial_id_null3' => $filialId,
            ':filial_id_val3' => $filialId,
        ]);
        $saldoById = [];
        foreach ($saldosStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $saldoById[(int)$s['insumo_id']] = $s;
        }

        // Validade mais próxima (Emp: afecta risco e compra).
        $validadeById = [];
        if ($empresarial) {
            $valStmt = $db->prepare("
                SELECT l.insumo_id,
                       MIN(l.validade) AS validade_proxima,
                       MIN(DATEDIFF(l.validade, CURDATE())) AS dias_validade
                FROM estoque_lotes l
                WHERE l.restaurante_id = :rid
                  AND (:filial_id_null IS NULL OR l.filial_id = :filial_id_val)
                  AND l.saldo_base > 0.0001
                  AND l.validade IS NOT NULL
                GROUP BY l.insumo_id
            ");
            $valStmt->execute([
                ':rid' => $restauranteId,
                ':filial_id_null' => $filialId,
                ':filial_id_val' => $filialId,
            ]);
            foreach ($valStmt->fetchAll(PDO::FETCH_ASSOC) as $v) {
                $validadeById[(int)$v['insumo_id']] = [
                    'dias' => (int)$v['dias_validade'],
                    'validade' => (string)$v['validade_proxima'],
                ];
            }
        }

        $agg = [];
        if ($empresarial) {
            $hoje = new DateTimeImmutable('today');
            foreach ($rows as $r) {
                $insumoId = (int)$r['insumo_id'];
                if (!isset($agg[$insumoId])) {
                    $agg[$insumoId] = [
                        'total' => 0.0,
                        'saida_7d' => 0.0,
                        'por_dow' => [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0, 5 => 0.0, 6 => 0.0, 7 => 0.0],
                        'cnt_dow' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0],
                        'dias_uteis_saida' => 0.0,
                        'dias_fim_saida' => 0.0,
                        'cnt_uteis' => 0,
                        'cnt_fim' => 0,
                    ];
                }
                $saida = (float)$r['saida_dia'];
                $dow = (int)$r['dow'];
                $agg[$insumoId]['total'] += $saida;
                $agg[$insumoId]['por_dow'][$dow] += $saida;
                $agg[$insumoId]['cnt_dow'][$dow]++;
                if ($dow === 1 || $dow === 7) {
                    $agg[$insumoId]['dias_fim_saida'] += $saida;
                    $agg[$insumoId]['cnt_fim']++;
                } else {
                    $agg[$insumoId]['dias_uteis_saida'] += $saida;
                    $agg[$insumoId]['cnt_uteis']++;
                }
                try {
                    $dia = new DateTimeImmutable((string)$r['dia']);
                    if ($dia >= $hoje->modify('-7 days')) {
                        $agg[$insumoId]['saida_7d'] += $saida;
                    }
                } catch (Throwable $e) {
                    // ignore bad date
                }
            }
        } else {
            foreach ($rows as $r) {
                $agg[(int)$r['insumo_id']] = [
                    'total' => (float)$r['total_saida'],
                    'saida_7d' => (float)$r['saida_7d'],
                    'fim_semana' => (float)$r['fim_semana_saida'],
                ];
            }
        }

        $out = [];
        foreach ($agg as $insumoId => $a) {
            $saldoInfo = $saldoById[$insumoId] ?? null;
            $saldo = $saldoInfo ? (float)$saldoInfo['saldo_base'] : 0.0;
            $minimoAtual = $saldoInfo ? (float)$saldoInfo['estoque_minimo_base'] : 0.0;
            $custoMedio = $saldoInfo ? (float)$saldoInfo['custo_medio'] : 0.0;
            $valInfo = $validadeById[$insumoId] ?? null;
            $diasVal = $valInfo['dias'] ?? null;

            if ($empresarial) {
                $total = (float)$a['total'];
                $saida7 = (float)$a['saida_7d'];
                $unidadeBase = $saldoInfo['unidade_base'] ?? 'un';
                $mediaJanela = $total / $diasJanela;
                $media7 = $saida7 / 7.0;
                // EWMA simples: 60% recente + 40% janela.
                $mediaDia = ($media7 * 0.6) + ($mediaJanela * 0.4);
                $mediaUtil = $a['cnt_uteis'] > 0 ? ((float)$a['dias_uteis_saida'] / (float)$a['cnt_uteis']) : $mediaDia;
                $mediaFim = $a['cnt_fim'] > 0 ? ((float)$a['dias_fim_saida'] / (float)$a['cnt_fim']) : $mediaDia;
                $fatorSazonal = $mediaUtil > 0.0001 ? max(0.85, min(1.6, $mediaFim / $mediaUtil)) : 1.0;
                // Previsão próximos 7/14/28d com perfil semanal (próx. 7 dias a partir de amanhã).
                $prev7 = 0.0;
                $hojeDow = (int)date('w'); // 0=dom … 6=sáb → MySQL DAYOFWEEK 1=dom
                for ($i = 1; $i <= 7; $i++) {
                    $phpDow = ($hojeDow + $i) % 7;
                    $mysqlDow = $phpDow + 1;
                    $cnt = (int)($a['cnt_dow'][$mysqlDow] ?? 0);
                    $avgDow = $cnt > 0
                        ? ((float)$a['por_dow'][$mysqlDow] / $cnt)
                        : $mediaDia;
                    $prev7 += $avgDow;
                }
                $prev14 = $prev7 * 2.0;
                $prev28 = $prev7 * 4.0;
                $mediaPrevistaDia = $prev7 / 7.0;
                $horizon = $leadDays + $safetyDays;
                $alvoLead = $mediaDia * $horizon * max(1.0, $fatorSazonal);
                $minimoSugerido = max($minimoAtual, round($mediaDia * $safetyDays * max(1.0, $fatorSazonal), 3));
                $alvo = max($prev14, $minimoSugerido, $alvoLead);
                $sugestaoBruta = max(0.0, $alvo - $saldo);
                $sugestao = estoque_normalizar_sugestao_compra($sugestaoBruta, (string)$unidadeBase);

                // Se lote a expirar ≤7d, não recomendar repor o que vai estragar; preferir consumir.
                $motivoRisco = '';
                if ($diasVal !== null && $diasVal <= 7 && $saldo > 0.0001) {
                    $sugestao = estoque_normalizar_sugestao_compra(round($sugestao * 0.35, 3), (string)$unidadeBase);
                    $motivoRisco = $diasVal <= 0
                        ? 'Lote vencido — priorizar descarte/consumo, não repor'
                        : ('Lote a vencer em ' . $diasVal . 'd — reduzir compra');
                }

                $diasCobertura = $mediaDia > 0.0001 ? ($saldo / $mediaDia) : ($saldo > 0 ? 999.0 : 0.0);
                $tendencia = 'ESTAVEL';
                if ($mediaJanela > 0.0001) {
                    $ratio = $media7 / $mediaJanela;
                    if ($ratio >= 1.25) {
                        $tendencia = 'ALTA';
                    } elseif ($ratio <= 0.75) {
                        $tendencia = 'BAIXA';
                    }
                } elseif ($media7 > 0.0001) {
                    $tendencia = 'ALTA';
                }

                $risco = 'OK';
                if ($saldo <= 0 || $diasCobertura < 1.5) {
                    $risco = 'CRITICO';
                    $motivoRisco = $motivoRisco !== '' ? $motivoRisco : 'Cobertura crítica ou saldo zero';
                } elseif ($diasCobertura < 3 || ($minimoAtual > 0 && $saldo <= $minimoAtual)) {
                    $risco = 'BAIXO';
                    $motivoRisco = $motivoRisco !== '' ? $motivoRisco : 'Abaixo do mínimo ou cobertura < 3d';
                } elseif ($sugestao > 0 && $diasCobertura < ($leadDays + $safetyDays + 2)) {
                    $risco = 'ATENCAO';
                    $motivoRisco = $motivoRisco !== '' ? $motivoRisco : ('Dentro do lead time (' . $leadDays . 'd) + segurança');
                } elseif ($tendencia === 'ALTA' && $diasCobertura < 10 && $sugestao > 0) {
                    $risco = 'ATENCAO';
                    $motivoRisco = $motivoRisco !== '' ? $motivoRisco : 'Tendência de consumo a subir';
                } elseif ($motivoRisco === '' && $sugestaoBruta > 0 && $sugestao <= 0) {
                    $motivoRisco = 'Diferenca residual abaixo da compra minima';
                } elseif ($motivoRisco === '' && $sugestao > 0) {
                    $motivoRisco = 'Reposição para horizonte lead+' . $safetyDays . 'd';
                } elseif ($motivoRisco === '') {
                    $motivoRisco = 'Cobertura OK';
                }

                $out[] = [
                    'insumo_id' => $insumoId,
                    'nome' => $saldoInfo['nome'] ?? null,
                    'unidade_base' => $unidadeBase,
                    'saldo_base' => round($saldo, 3),
                    'media_diaria' => round($mediaDia, 3),
                    'media_prevista_diaria' => round($mediaPrevistaDia, 3),
                    'media_dia_util' => round($mediaUtil, 3),
                    'media_fim_semana' => round($mediaFim, 3),
                    'saida_7_dias' => round($saida7, 3),
                    'previsao_7_dias' => round($prev7, 3),
                    'previsao_14_dias' => round($prev14, 3),
                    'previsao_28_dias' => round($prev28, 3),
                    'fator_sazonal' => round($fatorSazonal, 3),
                    'tendencia' => $tendencia,
                    'lead_time_dias' => $leadDays,
                    'safety_dias' => $safetyDays,
                    'dias_cobertura' => round(min(999, $diasCobertura), 1),
                    'minimo_sugerido_base' => $minimoSugerido,
                    'sugestao_compra_base' => round($sugestao, 3),
                    'custo_estimado_compra' => round($sugestao * $custoMedio, 2),
                    'risco' => $risco,
                    'motivo_risco' => $motivoRisco,
                    'dias_validade' => $diasVal,
                    'modo' => 'empresarial',
                ];
            } else {
                $total = (float)$a['total'];
                $fimSemana = (float)$a['fim_semana'];
                $saida7 = (float)$a['saida_7d'];
                $unidadeBase = $saldoInfo['unidade_base'] ?? 'un';
                $mediaDia = $total / $diasJanela;
                $fatorSazonal = $total > 0 ? (1 + (($fimSemana / max(1.0, $total)) * 0.25)) : 1.0;
                $prev7 = $mediaDia * 7 * $fatorSazonal;
                $prev14 = $mediaDia * 14 * $fatorSazonal;
                $diasCobertura = $mediaDia > 0.0001 ? ($saldo / $mediaDia) : ($saldo > 0 ? 999.0 : 0.0);
                $minimoSugerido = max($minimoAtual, round($mediaDia * 3 * $fatorSazonal, 3));
                $alvo = max($prev14, $minimoSugerido);
                $sugestao = estoque_normalizar_sugestao_compra(max(0.0, $alvo - $saldo), (string)$unidadeBase);
                $risco = 'OK';
                if ($saldo <= 0 || $diasCobertura < 1.5) {
                    $risco = 'CRITICO';
                } elseif ($diasCobertura < 3 || ($minimoAtual > 0 && $saldo <= $minimoAtual)) {
                    $risco = 'BAIXO';
                } elseif ($sugestao > 0 && $diasCobertura < 7) {
                    $risco = 'ATENCAO';
                }

                $out[] = [
                    'insumo_id' => $insumoId,
                    'nome' => $saldoInfo['nome'] ?? null,
                    'unidade_base' => $unidadeBase,
                    'saldo_base' => round($saldo, 3),
                    'media_diaria' => round($mediaDia, 3),
                    'saida_7_dias' => round($saida7, 3),
                    'previsao_7_dias' => round($prev7, 3),
                    'previsao_14_dias' => round($prev14, 3),
                    'fator_sazonal' => round($fatorSazonal, 3),
                    'dias_cobertura' => round(min(999, $diasCobertura), 1),
                    'minimo_sugerido_base' => $minimoSugerido,
                    'sugestao_compra_base' => round($sugestao, 3),
                    'custo_estimado_compra' => round($sugestao * $custoMedio, 2),
                    'risco' => $risco,
                    'modo' => 'operacional',
                ];
            }
        }

        // Insumos sem movimentos mas com saldo baixo.
        foreach ($saldoById as $insumoId => $s) {
            $exists = false;
            foreach ($out as $o) {
                if ((int)$o['insumo_id'] === (int)$insumoId) {
                    $exists = true;
                    break;
                }
            }
            if ($exists) {
                continue;
            }
            $saldo = (float)$s['saldo_base'];
            $minimo = (float)$s['estoque_minimo_base'];
            $unidadeBase = (string)($s['unidade_base'] ?? 'un');
            if ($minimo <= 0 && $saldo > 0) {
                continue;
            }
            $sugestao = estoque_normalizar_sugestao_compra(max(0.0, $minimo - $saldo), $unidadeBase);
            if ($sugestao <= 0 && $saldo > $minimo) {
                continue;
            }
            $rowBase = [
                'insumo_id' => (int)$insumoId,
                'nome' => $s['nome'],
                'unidade_base' => $unidadeBase,
                'saldo_base' => round($saldo, 3),
                'media_diaria' => 0.0,
                'saida_7_dias' => 0.0,
                'previsao_7_dias' => 0.0,
                'previsao_14_dias' => 0.0,
                'fator_sazonal' => 1.0,
                'dias_cobertura' => $saldo > 0 ? 999.0 : 0.0,
                'minimo_sugerido_base' => $minimo,
                'sugestao_compra_base' => round($sugestao, 3),
                'custo_estimado_compra' => round($sugestao * (float)$s['custo_medio'], 2),
                'risco' => $saldo <= 0 ? 'CRITICO' : ($saldo <= $minimo ? 'BAIXO' : 'OK'),
                'modo' => $empresarial ? 'empresarial' : 'operacional',
            ];
            if ($empresarial) {
                $rowBase['media_dia_util'] = 0.0;
                $rowBase['media_fim_semana'] = 0.0;
                $rowBase['previsao_28_dias'] = 0.0;
                $rowBase['tendencia'] = 'ESTAVEL';
                $rowBase['lead_time_dias'] = $leadDays;
                $rowBase['safety_dias'] = $safetyDays;
                $rowBase['motivo_risco'] = $saldo <= 0 ? 'Sem stock e sem histórico de consumo' : 'Abaixo do mínimo cadastrado';
                $rowBase['dias_validade'] = $validadeById[(int)$insumoId]['dias'] ?? null;
            }
            $out[] = $rowBase;
        }

        usort($out, static function ($a, $b) {
            $rank = ['CRITICO' => 0, 'BAIXO' => 1, 'ATENCAO' => 2, 'OK' => 3];
            $ra = $rank[$a['risco']] ?? 9;
            $rb = $rank[$b['risco']] ?? 9;
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            return ((float)$b['sugestao_compra_base']) <=> ((float)$a['sugestao_compra_base']);
        });

        return $out;
    }
}

if (!function_exists('estoque_insights_empresariais')) {
    /**
     * Insights do cockpit Empresarial (score rico + distribuição + perdas/lotes).
     *
     * @param array<int,array<string,mixed>> $previsao
     * @param array<string,mixed> $extras perdas_30d, lotes_validade, sugestoes_transferencia
     * @return array<string,mixed>
     */
    function estoque_insights_empresariais(PDO $db, int $restauranteId, ?int $filialId, array $previsao, array $extras = []): array
    {
        $sugestoes = array_values(array_filter($previsao, static function ($p) {
            $qtd = (float)($p['sugestao_compra_base'] ?? 0);
            $unidadeBase = (string)($p['unidade_base'] ?? 'un');
            return $qtd >= estoque_compra_minima_relevante_base($unidadeBase);
        }));
        $sugestoes = array_slice($sugestoes, 0, 12);

        $stmtValorLotes = $db->prepare("
            SELECT COALESCE(SUM(l.saldo_base * COALESCE(l.custo_unitario_base, 0)), 0)
            FROM estoque_lotes l
            JOIN estoque_insumos i ON i.id = l.insumo_id AND i.ativo = 1
            WHERE l.restaurante_id = :rid
              AND (:filial_id_null IS NULL OR l.filial_id = :filial_id_val)
              AND l.saldo_base > 0
        ");
        $stmtValorLotes->execute([
            ':rid' => $restauranteId,
            ':filial_id_null' => $filialId,
            ':filial_id_val' => $filialId,
        ]);
        $valorLotes = (float)$stmtValorLotes->fetchColumn();

        $stmtValor = $db->prepare("
            SELECT COALESCE(SUM(COALESCE(s.saldo_base,0) * COALESCE(s.custo_medio,0)), 0)
            FROM estoque_saldos s
            JOIN estoque_insumos i ON i.id = s.insumo_id AND i.ativo = 1
            WHERE s.restaurante_id = :rid
              AND (:filial_id_null IS NULL OR s.filial_id = :filial_id_val)
        ");
        $stmtValor->execute([
            ':rid' => $restauranteId,
            ':filial_id_null' => $filialId,
            ':filial_id_val' => $filialId,
        ]);
        $valorSaldos = (float)$stmtValor->fetchColumn();
        $valorStock = $valorLotes > 0.0001 ? $valorLotes : $valorSaldos;

        $stmtSemFicha = $db->prepare("
            SELECT COUNT(*)
            FROM produtos p
            WHERE p.restaurante_id = :rid
              AND COALESCE(p.ativo, 1) = 1
              AND NOT EXISTS (
                  SELECT 1 FROM estoque_fichas_tecnicas ft
                  WHERE ft.produto_id = p.id
                    AND ft.restaurante_id = p.restaurante_id
                    AND ft.ativo = 1
              )
        ");
        $stmtSemFicha->execute([':rid' => $restauranteId]);
        $produtosSemFicha = (int)$stmtSemFicha->fetchColumn();

        $coberturas = array_values(array_filter(array_map(static function ($p) {
            $d = (float)($p['dias_cobertura'] ?? 0);
            return $d < 900 ? $d : null;
        }, $previsao)));
        $mediaCobertura = count($coberturas) ? array_sum($coberturas) / count($coberturas) : 0.0;

        $criticos = 0;
        $baixos = 0;
        $okCount = 0;
        $motivosTop = [];
        foreach ($previsao as $p) {
            $risco = (string)($p['risco'] ?? 'OK');
            if ($risco === 'CRITICO') {
                $criticos++;
            } elseif ($risco === 'BAIXO' || $risco === 'ATENCAO') {
                $baixos++;
            } else {
                $okCount++;
            }
            $motivo = trim((string)($p['motivo_risco'] ?? ''));
            if ($motivo !== '' && ($risco === 'CRITICO' || $risco === 'BAIXO' || $risco === 'ATENCAO')) {
                $motivosTop[] = [
                    'insumo' => (string)($p['nome'] ?? ('#' . ($p['insumo_id'] ?? ''))),
                    'risco' => $risco,
                    'motivo' => $motivo,
                ];
            }
        }
        $motivosTop = array_slice($motivosTop, 0, 5);

        $custoSugestoes = 0.0;
        foreach ($sugestoes as $s) {
            $custoSugestoes += (float)($s['custo_estimado_compra'] ?? 0);
        }

        $perdas30 = $extras['perdas_30d'] ?? null;
        if (!is_array($perdas30)) {
            $stmtPerdas = $db->prepare("
                SELECT COUNT(*) AS total_registros, COALESCE(SUM(custo_perdido),0) AS custo_total
                FROM estoque_perdas
                WHERE restaurante_id = :rid
                  AND criado_em >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                  AND (:filial_id_null IS NULL OR filial_id = :filial_id_val)
            ");
            $stmtPerdas->execute([
                ':rid' => $restauranteId,
                ':filial_id_null' => $filialId,
                ':filial_id_val' => $filialId,
            ]);
            $perdas30 = $stmtPerdas->fetch(PDO::FETCH_ASSOC) ?: ['total_registros' => 0, 'custo_total' => 0];
        }
        $custoPerdas = (float)($perdas30['custo_total'] ?? 0);
        $perdasIndice = $valorStock > 0.0001
            ? round(min(100, ($custoPerdas / $valorStock) * 100), 2)
            : ($custoPerdas > 0 ? 100.0 : 0.0);

        $lotesValidade = $extras['lotes_validade'] ?? null;
        if (!is_array($lotesValidade)) {
            $lotesValidade = function_exists('estoque_listar_lotes_validade')
                ? estoque_listar_lotes_validade($db, $restauranteId, $filialId, 30)
                : [];
        }
        $lotesEmRisco = 0;
        foreach ($lotesValidade as $lote) {
            $estado = (string)($lote['estado'] ?? '');
            if (in_array($estado, ['VENCIDO', 'CRITICO', 'PROXIMO'], true)) {
                $lotesEmRisco++;
            }
        }

        $sugestoesTransferencia = $extras['sugestoes_transferencia'] ?? [];
        if (!is_array($sugestoesTransferencia)) {
            $sugestoesTransferencia = [];
        }
        $desequilibrioRede = count($sugestoesTransferencia);

        $penalidade = ($criticos * 18)
            + (min(40, $produtosSemFicha) * 1.2)
            + (count($sugestoes) > 0 && $mediaCobertura < 3 ? 15 : 0)
            + min(20, $lotesEmRisco * 3)
            + min(15, (int)round($perdasIndice * 0.5))
            + min(12, $desequilibrioRede * 4);
        $score = max(0, min(100, (int)round(100 - $penalidade)));

        if ($criticos > 0) {
            array_unshift($motivosTop, [
                'insumo' => 'Rede',
                'risco' => 'CRITICO',
                'motivo' => $criticos . ' insumo(s) em risco crítico de cobertura',
            ]);
            $motivosTop = array_slice($motivosTop, 0, 5);
        } elseif ($lotesEmRisco > 0) {
            array_unshift($motivosTop, [
                'insumo' => 'Validade',
                'risco' => 'ATENCAO',
                'motivo' => $lotesEmRisco . ' lote(s) vencido(s) ou a vencer',
            ]);
            $motivosTop = array_slice($motivosTop, 0, 5);
        }

        return [
            'valor_stock' => round($valorStock, 2),
            'produtos_sem_ficha' => $produtosSemFicha,
            'dias_cobertura_media' => round($mediaCobertura, 1),
            'insumos_criticos_previstos' => $criticos,
            'sugestoes_compra' => $sugestoes,
            'custo_reposicao_sugerida' => round($custoSugestoes, 2),
            'score_saude' => $score,
            'lotes_em_risco' => $lotesEmRisco,
            'perdas_indice' => $perdasIndice,
            'perdas_custo_30d' => round($custoPerdas, 2),
            'cobertura_distribuicao' => [
                'critico' => $criticos,
                'baixo' => $baixos,
                'ok' => $okCount,
            ],
            'motivos_top' => $motivosTop,
            'sugestoes_transferencia' => array_slice($sugestoesTransferencia, 0, 10),
            'desequilibrio_rede' => $desequilibrioRede,
            'lead_time_dias' => (int)($previsao[0]['lead_time_dias'] ?? 5),
            'modo' => 'empresarial',
        ];
    }
}

if (!function_exists('estoque_insights_rede')) {
    /**
     * Sugestões de transferência entre matriz e filiais (SKU partilhado).
     * Origem = stock central (restaurante_id = matriz, post id 0) ou filial.
     *
     * @return list<array<string,mixed>>
     */
    function estoque_insights_rede(PDO $db, int $matrizId): array
    {
        if ($matrizId <= 0) {
            return [];
        }

        $stmtNodes = $db->prepare("
            SELECT id, nome, COALESCE(is_matriz, 0) AS is_matriz
            FROM restaurantes
            WHERE id = :matriz OR (filial_id = :matriz2 AND COALESCE(is_matriz, 0) = 0)
            ORDER BY COALESCE(is_matriz, 0) DESC, nome ASC
        ");
        $stmtNodes->execute([':matriz' => $matrizId, ':matriz2' => $matrizId]);
        $nodes = $stmtNodes->fetchAll(PDO::FETCH_ASSOC);
        if (count($nodes) < 2) {
            return [];
        }

        $bySku = [];
        foreach ($nodes as $node) {
            $rid = (int)$node['id'];
            $isMatriz = ((int)$node['is_matriz']) === 1;
            $stmt = $db->prepare("
                SELECT i.id AS insumo_id, i.sku, i.nome, i.unidade_base, i.estoque_minimo_base,
                       COALESCE(s.saldo_base, 0) AS saldo_base
                FROM estoque_insumos i
                LEFT JOIN estoque_saldos s
                  ON s.insumo_id = i.id
                 AND s.restaurante_id = i.restaurante_id
                 AND s.filial_id IS NULL
                WHERE i.restaurante_id = :rid
                  AND i.ativo = 1
                  AND i.sku IS NOT NULL
                  AND i.sku <> ''
            ");
            $stmt->execute([':rid' => $rid]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $sku = strtoupper(trim((string)$row['sku']));
                if ($sku === '') {
                    continue;
                }
                if (!isset($bySku[$sku])) {
                    $bySku[$sku] = [];
                }
                $saldo = (float)$row['saldo_base'];
                $minimo = (float)$row['estoque_minimo_base'];
                $excesso = max(0.0, $saldo - max($minimo * 1.5, $minimo + 1));
                $falta = max(0.0, $minimo - $saldo);
                $bySku[$sku][] = [
                    'restaurante_id' => $rid,
                    'post_id' => $isMatriz ? 0 : $rid,
                    'nome_local' => (string)$node['nome'],
                    'insumo_id' => (int)$row['insumo_id'],
                    'insumo_nome' => (string)$row['nome'],
                    'unidade_base' => (string)$row['unidade_base'],
                    'saldo_base' => $saldo,
                    'estoque_minimo_base' => $minimo,
                    'excesso' => $excesso,
                    'falta' => $falta,
                ];
            }
        }

        $sugestoes = [];
        foreach ($bySku as $sku => $locs) {
            if (count($locs) < 2) {
                continue;
            }
            $doadores = array_values(array_filter($locs, static fn($l) => (float)$l['excesso'] > 0.0001));
            $receptores = array_values(array_filter($locs, static fn($l) => (float)$l['falta'] > 0.0001));
            if (empty($doadores) || empty($receptores)) {
                continue;
            }
            usort($doadores, static fn($a, $b) => ((float)$b['excesso']) <=> ((float)$a['excesso']));
            usort($receptores, static fn($a, $b) => ((float)$b['falta']) <=> ((float)$a['falta']));

            foreach ($receptores as $dest) {
                foreach ($doadores as &$ori) {
                    if ((int)$ori['restaurante_id'] === (int)$dest['restaurante_id']) {
                        continue;
                    }
                    $qtd = min((float)$ori['excesso'], (float)$dest['falta']);
                    if ($qtd <= 0.0001) {
                        continue;
                    }
                    $sugestoes[] = [
                        'sku' => $sku,
                        'insumo_nome' => (string)$dest['insumo_nome'],
                        'unidade_base' => (string)$dest['unidade_base'],
                        'quantidade_base' => round($qtd, 3),
                        'origem_restaurante_id' => (int)$ori['restaurante_id'],
                        'origem_post_id' => (int)$ori['post_id'],
                        'origem_nome' => (string)$ori['nome_local'],
                        'origem_insumo_id' => (int)$ori['insumo_id'],
                        'destino_restaurante_id' => (int)$dest['restaurante_id'],
                        'destino_post_id' => (int)$dest['post_id'],
                        'destino_nome' => (string)$dest['nome_local'],
                        'destino_insumo_id' => (int)$dest['insumo_id'],
                        'motivo' => 'Excesso em ' . $ori['nome_local'] . ' · falta em ' . $dest['nome_local'],
                    ];
                    $ori['excesso'] = (float)$ori['excesso'] - $qtd;
                    $dest['falta'] = (float)$dest['falta'] - $qtd;
                    break;
                }
                unset($ori);
            }
        }

        usort($sugestoes, static fn($a, $b) => ((float)$b['quantidade_base']) <=> ((float)$a['quantidade_base']));
        return array_slice($sugestoes, 0, 15);
    }
}

if (!function_exists('estoque_aplicar_minimos_sugeridos')) {
    /**
     * Grava estoque_minimo_base a partir dos mínimos sugeridos pela previsão Emp.
     *
     * @param list<int> $insumoIds vazio = todos com sugestão > mínimo actual
     * @return array{atualizados:int,itens:list<array<string,mixed>>}
     */
    /**
     * Minimos sugeridos pelo motor Empresarial que SUBIRIAM o minimo atual.
     * Sem $aplicar so devolve a lista (pre-visualizacao); com $aplicar grava.
     * $insumoIds vazio = todos.
     */
    function estoque_aplicar_minimos_sugeridos(PDO $db, int $restauranteId, ?int $filialId, array $insumoIds = [], int $diasJanela = 28, bool $aplicar = true): array
    {
        $previsao = estoque_previsao_consumo($db, $restauranteId, $filialId, $diasJanela, ['modo' => 'empresarial']);
        $filtro = [];
        foreach ($insumoIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $filtro[$id] = true;
            }
        }

        $atualizados = 0;
        $itens = [];
        $stmtAtual = $db->prepare("SELECT estoque_minimo_base, nome FROM estoque_insumos WHERE id = :id AND restaurante_id = :rid LIMIT 1");
        $upd = $db->prepare("
            UPDATE estoque_insumos
            SET estoque_minimo_base = :minimo
            WHERE id = :id AND restaurante_id = :rid
        ");

        foreach ($previsao as $p) {
            $insumoId = (int)($p['insumo_id'] ?? 0);
            if ($insumoId <= 0) {
                continue;
            }
            if (!empty($filtro) && empty($filtro[$insumoId])) {
                continue;
            }
            $sugerido = (float)($p['minimo_sugerido_base'] ?? 0);
            if ($sugerido <= 0.0001) {
                continue;
            }
            $stmtAtual->execute([':id' => $insumoId, ':rid' => $restauranteId]);
            $row = $stmtAtual->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                continue;
            }
            $atual = (float)$row['estoque_minimo_base'];
            if ($sugerido <= $atual + 0.0001) {
                continue;
            }
            $item = [
                'insumo_id' => $insumoId,
                'nome' => (string)$row['nome'],
                'unidade' => (string)($p['unidade_base'] ?? ''),
                'minimo_anterior' => round($atual, 3),
                'minimo_novo' => round($sugerido, 3),
                'media_diaria' => round((float)($p['media_diaria'] ?? 0), 3),
                'lead_time_dias' => (int)($p['lead_time_dias'] ?? 0),
                'safety_dias' => (int)($p['safety_dias'] ?? 0),
            ];
            if (!$aplicar) {
                $itens[] = $item;
                continue;
            }
            $upd->execute([
                ':minimo' => round($sugerido, 3),
                ':id' => $insumoId,
                ':rid' => $restauranteId,
            ]);
            if ($upd->rowCount() > 0) {
                $atualizados++;
                $itens[] = $item;
            }
        }

        if ($aplicar) {
            estoque_inteligente_log('minimos_sugeridos_aplicados', [
                'restaurante_id' => $restauranteId,
                'atualizados' => $atualizados,
                'itens' => array_map(static fn($i) => [$i['insumo_id'], $i['minimo_anterior'], $i['minimo_novo']], $itens),
            ]);
        }

        return ['atualizados' => $atualizados, 'itens' => $itens];
    }

    /**
     * Desfaz uma aplicacao de minimos: repoe o valor anterior SO onde o minimo
     * ainda e o que foi aplicado (nao apaga alteracoes feitas entretanto).
     * $itens: [['insumo_id' => , 'minimo_anterior' => , 'minimo_novo' => ], ...]
     */
    function estoque_desfazer_minimos(PDO $db, int $restauranteId, array $itens): int
    {
        $upd = $db->prepare("
            UPDATE estoque_insumos
            SET estoque_minimo_base = :anterior
            WHERE id = :id AND restaurante_id = :rid AND ABS(estoque_minimo_base - :novo) < 0.0005
        ");
        $repostos = 0;
        foreach ($itens as $i) {
            if (!is_array($i) || (int)($i['insumo_id'] ?? 0) <= 0) {
                continue;
            }
            $upd->execute([
                ':anterior' => max(0, round((float)($i['minimo_anterior'] ?? 0), 3)),
                ':novo' => round((float)($i['minimo_novo'] ?? 0), 3),
                ':id' => (int)$i['insumo_id'],
                ':rid' => $restauranteId,
            ]);
            $repostos += $upd->rowCount();
        }
        estoque_inteligente_log('minimos_sugeridos_desfeitos', ['restaurante_id' => $restauranteId, 'repostos' => $repostos]);
        return $repostos;
    }
}
