<?php

require_once __DIR__ . '/migration_state.php';

function promocao_timezone(): DateTimeZone
{
    static $timezone = null;
    if ($timezone instanceof DateTimeZone) return $timezone;
    $name = trim((string)(getenv('APP_TIMEZONE') ?: getenv('TZ') ?: 'Africa/Maputo'));
    try {
        $timezone = new DateTimeZone($name);
    } catch (Throwable $e) {
        error_log('[PROMOCOES][TIMEZONE] Timezone invalido: ' . $name);
        $timezone = new DateTimeZone('Africa/Maputo');
    }
    return $timezone;
}

function promocao_data_local_para_utc(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') return null;
    $format = strpos($value, 'T') !== false ? '!Y-m-d\TH:i' : '!Y-m-d H:i:s';
    $date = DateTimeImmutable::createFromFormat($format, $value, promocao_timezone());
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        throw new InvalidArgumentException('Data ou hora promocional invalida.');
    }
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function promocao_data_utc_para_local(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
    if (!$date) return null;
    return $date->setTimezone(promocao_timezone())->format('Y-m-d H:i:s');
}

function promocao_schema_garantir(PDO $db): void
{
    static $done = [];
    $key = spl_object_id($db);
    if (isset($done[$key])) return;

    // Depois da migracao db/migrations/20261030_1130_promocoes_pagamentos,
    // nao inspecciona nem altera o schema. Sem ela, o fallback legado continua activo.
    if (migration_esta_aplicada($db, '20261030_1130_promocoes_pagamentos')) {
        $done[$key] = true;
        return;
    }

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

    $hasUtcFlag = $db->query("SHOW COLUMNS FROM promocoes LIKE 'horarios_utc'")->fetch(PDO::FETCH_ASSOC);
    $needsUtcDefault = !$hasUtcFlag || (string)($hasUtcFlag['Default'] ?? '') !== '1';
    if (!$hasUtcFlag) {
        $db->exec('ALTER TABLE promocoes ADD COLUMN horarios_utc TINYINT(1) NOT NULL DEFAULT 0 AFTER ativo');
    }
    $legacyRows = $db->query('SELECT id, inicio_em, fim_em FROM promocoes WHERE horarios_utc = 0')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($legacyRows) {
        $migrate = $db->prepare('UPDATE promocoes SET inicio_em = :inicio, fim_em = :fim, horarios_utc = 1 WHERE id = :id AND horarios_utc = 0');
        foreach ($legacyRows as $legacyRow) {
            $migrate->execute([
                'id' => (int)$legacyRow['id'],
                'inicio' => promocao_data_local_para_utc($legacyRow['inicio_em'] ?? null),
                'fim' => promocao_data_local_para_utc($legacyRow['fim_em'] ?? null),
            ]);
        }
    }
    if ($needsUtcDefault) {
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

    $hasPromoItem = $db->query("SHOW COLUMNS FROM itens_pedido LIKE 'promocao_id'")->fetch(PDO::FETCH_ASSOC);
    if (!$hasPromoItem) {
        $db->exec('ALTER TABLE itens_pedido ADD COLUMN promocao_id INT NULL AFTER produto_id');
        $db->exec('ALTER TABLE itens_pedido ADD KEY idx_itens_pedido_promocao (promocao_id)');
    }

    // Mantem a origem comercial depois do pagamento para medir o resultado real.
    $hasVendaPromo = $db->query("SHOW COLUMNS FROM itens_venda LIKE 'promocao_id'")->fetch(PDO::FETCH_ASSOC);
    if (!$hasVendaPromo) {
        $db->exec('ALTER TABLE itens_venda ADD COLUMN promocao_id INT NULL AFTER produto_id');
        $db->exec('ALTER TABLE itens_venda ADD KEY idx_itens_venda_promocao (promocao_id)');
    }

    $done[$key] = true;
}

function promocao_plano_permitido(string $plano): bool
{
    return in_array(strtoupper(trim($plano)), ['PROFISSIONAL', 'EMPRESARIAL'], true);
}

function promocao_listar_ativas(PDO $db, int $restauranteId): array
{
    promocao_schema_garantir($db);
    $stmt = $db->prepare("SELECT pr.*,
            (SELECT COUNT(*) FROM promocao_itens pic WHERE pic.promocao_id = pr.id) AS total_componentes
        FROM promocoes pr
        WHERE pr.restaurante_id = :rid AND pr.ativo = 1
          AND (pr.inicio_em IS NULL OR pr.inicio_em <= UTC_TIMESTAMP())
          AND (pr.fim_em IS NULL OR pr.fim_em >= UTC_TIMESTAMP())
          AND EXISTS (
              SELECT 1 FROM promocao_itens pi
              INNER JOIN produtos p ON p.id = pi.produto_id
              WHERE pi.promocao_id = pr.id AND p.restaurante_id = pr.restaurante_id AND p.ativo = 1
          )
        ORDER BY pr.criado_em DESC");
    $stmt->execute(['rid' => $restauranteId]);
    $promocoes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$promocoes) return [];

    $ids = array_map(static fn($row) => (int)$row['id'], $promocoes);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $items = $db->prepare("SELECT pi.promocao_id, pi.produto_id, pi.quantidade, p.nome, p.preco, p.imagem
        FROM promocao_itens pi
        INNER JOIN produtos p ON p.id = pi.produto_id
        WHERE pi.promocao_id IN ($in) AND p.ativo = 1
        ORDER BY pi.id");
    $items->execute($ids);
    $byPromo = [];
    foreach ($items->fetchAll(PDO::FETCH_ASSOC) ?: [] as $item) {
        $byPromo[(int)$item['promocao_id']][] = $item;
    }
    foreach ($promocoes as &$promo) {
        $promo['itens'] = $byPromo[(int)$promo['id']] ?? [];
        if (empty($promo['imagem']) && !empty($promo['itens'][0]['imagem'])) {
            $promo['imagem'] = $promo['itens'][0]['imagem'];
        }
    }
    unset($promo);
    return array_values(array_filter($promocoes, static function ($promo) {
        return !empty($promo['itens']) && count($promo['itens']) === (int)($promo['total_componentes'] ?? 0);
    }));
}

/**
 * Remove de uma linha de pedido recebida do cliente todos os campos internos
 * (chaves começadas por "_", como _preco_override e _subtotal_override).
 * Esses campos só podem ser criados pelo servidor em promocao_expandir_linhas();
 * se viessem do pedido HTTP, o cliente poderia definir o próprio preço.
 */
function pedido_linha_entrada_limpar($linha): array
{
    if (!is_array($linha)) {
        return [];
    }
    foreach (array_keys($linha) as $chave) {
        if (!is_string($chave) || $chave === '' || $chave[0] === '_') {
            unset($linha[$chave]);
        }
    }
    return $linha;
}

/** Expande {promocao_id, qtd} em produtos reais com valores promocionais. */
function promocao_expandir_linhas(PDO $db, int $restauranteId, array $linhas): array
{
    $regular = [];
    $pedidas = [];
    foreach ($linhas as $linha) {
        // Segurança: as linhas chegam do cliente. Nunca confiar em campos internos.
        $linha = pedido_linha_entrada_limpar($linha);
        $promoId = (int)($linha['promocao_id'] ?? 0);
        if ($promoId <= 0) {
            $regular[] = $linha;
            continue;
        }
        $qtd = max(1, min(50, (int)($linha['qtd'] ?? $linha['qty'] ?? $linha['quantidade'] ?? 1)));
        $pedidas[] = ['id' => $promoId, 'qtd' => $qtd];
    }
    if (!$pedidas) return $regular;

    if (!function_exists('plano_get_dados')) {
        require_once __DIR__ . '/plano_check.php';
    }
    $plano = plano_normalizar_nome((plano_get_dados($restauranteId)['plano_nome'] ?? 'BASICO'));
    if (!promocao_plano_permitido($plano)) {
        throw new DomainException('Promocoes indisponiveis no plano atual.');
    }

    promocao_schema_garantir($db);
    foreach ($pedidas as $pedidoPromo) {
        $stmt = $db->prepare("SELECT id, nome, preco_promocional
            FROM promocoes
            WHERE id = :id AND restaurante_id = :rid AND ativo = 1
              AND (inicio_em IS NULL OR inicio_em <= UTC_TIMESTAMP())
              AND (fim_em IS NULL OR fim_em >= UTC_TIMESTAMP())
            LIMIT 1");
        $stmt->execute(['id' => $pedidoPromo['id'], 'rid' => $restauranteId]);
        $promo = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$promo) throw new DomainException('Promocao indisponivel ou expirada.');

        $stmtItens = $db->prepare("SELECT pi.produto_id, pi.quantidade, p.preco
            FROM promocao_itens pi
            INNER JOIN produtos p ON p.id = pi.produto_id
            WHERE pi.promocao_id = :promo AND p.restaurante_id = :rid AND p.ativo = 1
            ORDER BY pi.id FOR UPDATE");
        $stmtItens->execute(['promo' => $pedidoPromo['id'], 'rid' => $restauranteId]);
        $componentes = $stmtItens->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $countStmt = $db->prepare('SELECT COUNT(*) FROM promocao_itens WHERE promocao_id = :promo');
        $countStmt->execute(['promo' => $pedidoPromo['id']]);
        if (!$componentes || count($componentes) !== (int)$countStmt->fetchColumn()) {
            throw new DomainException('Um produto desta promocao esta indisponivel.');
        }

        $pesoTotal = 0.0;
        foreach ($componentes as $componente) {
            $pesoTotal += (float)$componente['preco'] * (int)$componente['quantidade'];
        }
        $totalPromo = round((float)$promo['preco_promocional'] * $pedidoPromo['qtd'], 2);
        $restante = $totalPromo;
        $ultimo = count($componentes) - 1;
        foreach ($componentes as $index => $componente) {
            $qtdProduto = (int)$componente['quantidade'] * $pedidoPromo['qtd'];
            $peso = (float)$componente['preco'] * (int)$componente['quantidade'];
            $subtotal = $index === $ultimo
                ? $restante
                : round($totalPromo * ($pesoTotal > 0 ? $peso / $pesoTotal : 1 / count($componentes)), 2);
            $restante = round($restante - $subtotal, 2);
            $regular[] = [
                'produto_id' => (int)$componente['produto_id'],
                'qtd' => $qtdProduto,
                '_promocao_id' => (int)$promo['id'],
                '_promocao_nome' => (string)$promo['nome'],
                '_preco_override' => round($subtotal / max(1, $qtdProduto), 4),
                '_subtotal_override' => $subtotal,
            ];
        }
    }
    return $regular;
}
