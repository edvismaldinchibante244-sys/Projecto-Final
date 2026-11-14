<?php
// Este ficheiro e um fragmento: so faz sentido incluido por uma pagina, que e
// quem verifica a sessao. Pedido directamente pelo navegador, nao responde.
// Hoje o reescritor de URLs ja devolve 404, mas isso e um efeito secundario
// das regras de reescrita, nao uma decisao: se mudarem, o fragmento ficava a
// descoberto. Esta linha torna a recusa deliberada.
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Not found');
}

/**
 * Análises inteligentes de produtos (EMP).
 * Funções puras + agregação — incluir a partir de produtos.php.
 */

if (!function_exists('produtos_ia_abc_por_receita')) {
    /**
     * ABC por receita do período (Pareto ~80/95).
     * Espera itens com chave `receita` (ou `total_vendido` * preco).
     *
     * @param list<array<string,mixed>> $itens
     * @return list<array<string,mixed>>
     */
    function produtos_ia_abc_por_receita(array $itens): array
    {
        $rows = array_map(static function ($p) {
            $receita = (float)($p['receita'] ?? 0);
            if ($receita <= 0) {
                $receita = (float)($p['total_vendido'] ?? 0) * (float)($p['preco'] ?? 0);
            }
            $p['receita'] = $receita;
            return $p;
        }, $itens);

        usort($rows, static fn($a, $b) => ((float)$b['receita']) <=> ((float)$a['receita']));
        $total = array_sum(array_column($rows, 'receita'));
        $acumulado = 0.0;
        foreach ($rows as &$p) {
            $acumulado += (float)$p['receita'];
            $pct = $total > 0 ? ($acumulado / $total) * 100 : 100.0;
            $p['pct_receita'] = $total > 0 ? round(((float)$p['receita'] / $total) * 100, 1) : 0.0;
            $p['pct_acumulado'] = round($pct, 1);
            if ($pct <= 80) {
                $p['categoria_abc'] = 'A';
            } elseif ($pct <= 95) {
                $p['categoria_abc'] = 'B';
            } else {
                $p['categoria_abc'] = 'C';
            }
        }
        unset($p);
        return array_values($rows);
    }
}

if (!function_exists('produtos_ia_calcular_abc')) {
    function produtos_ia_calcular_abc(array $produtos): array
    {
        $comValor = array_map(static function ($p) {
            $preco = (float)($p['preco'] ?? 0);
            $estoque = (float)($p['estoque'] ?? 0);
            $custo = (float)($p['custo'] ?? 0);
            $p['valor_estoque'] = $preco * $estoque;
            $p['margem'] = $preco - $custo;
            $p['margem_percent'] = $preco > 0 ? (($preco - $custo) / $preco) * 100 : 0.0;
            return $p;
        }, $produtos);

        uasort($comValor, static fn($a, $b) => ($b['valor_estoque'] ?? 0) <=> ($a['valor_estoque'] ?? 0));

        $total = array_sum(array_column($comValor, 'valor_estoque'));
        $acumulado = 0.0;
        foreach ($comValor as &$p) {
            $acumulado += (float)($p['valor_estoque'] ?? 0);
            $percentual = $total > 0 ? ($acumulado / $total) * 100 : 100.0;
            if ($percentual <= 80) {
                $p['categoria_abc'] = 'A';
            } elseif ($percentual <= 95) {
                $p['categoria_abc'] = 'B';
            } else {
                $p['categoria_abc'] = 'C';
            }
        }
        unset($p);

        return array_values($comValor);
    }
}

if (!function_exists('produtos_ia_calcular_performance')) {
    function produtos_ia_calcular_performance(array $produtos): array
    {
        $comValor = array_map(static function ($p) {
            $preco = (float)($p['preco'] ?? 0);
            $estoque = (float)($p['estoque'] ?? 0);
            $custo = (float)($p['custo'] ?? 0);
            $p['valor_estoque'] = $preco * $estoque;
            $p['margem_percent'] = $preco > 0 ? (($preco - $custo) / $preco) * 100 : 0.0;
            return $p;
        }, $produtos);

        $margens = array_values(array_filter(array_column($comValor, 'margem_percent'), static fn($v) => is_numeric($v)));
        $estoques = array_values(array_filter(array_column($comValor, 'estoque'), static fn($v) => is_numeric($v)));
        sort($margens);
        sort($estoques);
        $medianaMargem = $margens ? (float)$margens[(int)floor(count($margens) / 2)] : 0.0;
        $medianaEstoque = $estoques ? (float)$estoques[(int)floor(count($estoques) / 2)] : 0.0;

        foreach ($comValor as &$p) {
            $altMargem = (float)($p['margem_percent'] ?? 0) >= $medianaMargem;
            $altVolume = (float)($p['estoque'] ?? 0) >= $medianaEstoque;
            if ($altVolume && $altMargem) {
                $p['categoria_performance'] = 'OURO';
            } elseif ($altVolume && !$altMargem) {
                $p['categoria_performance'] = 'PRATA';
            } elseif (!$altVolume && $altMargem) {
                $p['categoria_performance'] = 'BRONZE';
            } else {
                $p['categoria_performance'] = 'CHUMBO';
            }
        }
        unset($p);

        return array_values($comValor);
    }
}

if (!function_exists('produtos_ia_gerar_recomendacao')) {
    function produtos_ia_gerar_recomendacao(array $produto): array
    {
        $recs = [];
        $estoque = (float)($produto['estoque'] ?? 0);
        $estoqueMin = (float)($produto['estoque_minimo'] ?? 0);
        $margem = (float)($produto['margem_percent'] ?? 0);
        $abc = (string)($produto['categoria_abc'] ?? '');

        if ($estoqueMin > 0 && $estoque <= $estoqueMin) {
            $recs[] = 'REPOSICAO';
        }
        if ($abc === 'C' && $estoque > max(5, $estoqueMin * 3)) {
            $recs[] = 'PROMOCAO';
        }
        if ($margem < 15 && $abc === 'A') {
            $recs[] = 'REVER_PRECO';
        }
        if (!$recs) {
            $recs[] = 'MANTER';
        }
        return $recs;
    }
}

if (!function_exists('produtos_ia_previsao_demanda')) {
    /**
     * Previsão simples: média diária dos últimos 30 dias * horizonte.
     * @return array<int,array{produto_id:int,nome:string,media_diaria:float,previsao_7d:float,previsao_30d:float}>
     */
    function produtos_ia_previsao_demanda(PDO $db, int $restauranteId, int $horizonteDias = 30): array
    {
        $horizonteDias = max(7, min(60, $horizonteDias));
        try {
            $stmt = $db->prepare("
                SELECT
                    p.id AS produto_id,
                    p.nome,
                    COALESCE(SUM(ip.quantidade), 0) / 30.0 AS media_diaria
                FROM produtos p
                LEFT JOIN itens_pedido ip ON ip.produto_id = p.id
                LEFT JOIN pedidos ped ON ped.id = ip.pedido_id
                    AND ped.restaurante_id = p.restaurante_id
                    AND ped.criado_em >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                WHERE p.restaurante_id = :rid
                GROUP BY p.id, p.nome
                HAVING media_diaria > 0
                ORDER BY media_diaria DESC
                LIMIT 12
            ");
            $stmt->execute([':rid' => $restauranteId]);
            $rows = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $media = (float)$row['media_diaria'];
                $rows[] = [
                    'produto_id' => (int)$row['produto_id'],
                    'nome' => (string)$row['nome'],
                    'media_diaria' => round($media, 2),
                    'previsao_7d' => round($media * 7, 1),
                    'previsao_30d' => round($media * $horizonteDias, 1),
                ];
            }
            return $rows;
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('produtos_ia_montar_painel')) {
    function produtos_ia_montar_painel(PDO $db, int $restauranteId, array $listaProdutos, array $flags): array
    {
        $temAbc = !empty($flags['analise_abc_vendas']);
        $temRec = !empty($flags['recomendacoes_automaticas']);
        $temPrev = !empty($flags['previsao_demanda']);
        $temDash = !empty($flags['dashboard_inteligente']);

        $analisados = $listaProdutos;
        if ($temAbc || $temRec || $temDash) {
            $analisados = produtos_ia_calcular_abc($listaProdutos);
            $analisados = produtos_ia_calcular_performance($analisados);
        }

        $contagemAbc = ['A' => 0, 'B' => 0, 'C' => 0];
        $recomendacoes = [];
        foreach ($analisados as &$p) {
            $abc = (string)($p['categoria_abc'] ?? '');
            if (isset($contagemAbc[$abc])) {
                $contagemAbc[$abc]++;
            }
            if ($temRec) {
                $p['recomendacoes'] = produtos_ia_gerar_recomendacao($p);
                if (($p['recomendacoes'][0] ?? 'MANTER') !== 'MANTER') {
                    $estNow = (float)($p['estoque'] ?? 0);
                    $estMin = (float)($p['estoque_minimo'] ?? 0);
                    $acaoRaw = (string)$p['recomendacoes'][0];
                    $acaoLabel = $acaoRaw;
                    if ($acaoRaw === 'REPOSICAO') {
                        $acaoLabel = 'Repor stock';
                    } elseif ($acaoRaw === 'REVER_PRECO') {
                        $acaoLabel = 'Rever preço';
                    } elseif ($acaoRaw === 'PROMOCAO') {
                        $acaoLabel = 'Promover';
                    }
                    $recomendacoes[] = [
                        'nome' => (string)($p['nome'] ?? ''),
                        'acao' => $acaoRaw,
                        'acao_label' => $acaoLabel,
                        'abc' => $abc,
                        'estoque' => $estNow,
                        'estoque_minimo' => $estMin,
                        'faltam' => max(0, $estMin - $estNow),
                    ];
                }
            }
        }
        unset($p);

        usort($recomendacoes, static fn($a, $b) => strcmp($a['acao'], $b['acao']));
        $recomendacoes = array_slice($recomendacoes, 0, 8);

        $topMargem = $analisados;
        usort($topMargem, static fn($a, $b) => ($b['margem_percent'] ?? 0) <=> ($a['margem_percent'] ?? 0));
        $topMargem = array_slice($topMargem, 0, 5);

        $porProduto = [];
        foreach ($analisados as $p) {
            $pid = (int)($p['id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $acao = 'MANTER';
            if (!empty($p['recomendacoes'][0])) {
                $acao = (string)$p['recomendacoes'][0];
            }
            $porProduto[$pid] = [
                'abc' => (string)($p['categoria_abc'] ?? ''),
                'performance' => (string)($p['categoria_performance'] ?? ''),
                'acao' => $acao,
                'margem_percent' => round((float)($p['margem_percent'] ?? 0), 1),
            ];
        }

        $nReposicao = count(array_filter($recomendacoes, static fn($r) => ($r['acao'] ?? '') === 'REPOSICAO'));
        $nPromocao = count(array_filter($recomendacoes, static fn($r) => ($r['acao'] ?? '') === 'PROMOCAO'));
        $nPreco = count(array_filter($recomendacoes, static fn($r) => ($r['acao'] ?? '') === 'REVER_PRECO'));
        $nA = (int)($contagemAbc['A'] ?? 0);
        $nCriticos = $nReposicao + $nPreco;

        if ($nReposicao >= 1) {
            $intelTom = $nReposicao >= 3 ? 'crit' : 'warn';
            $intelTitulo = $nReposicao === 1
                ? '1 produto precisa de stock'
                : ($nReposicao . ' produtos precisam de stock');
            $intelTxt = 'Stock actual está no mínimo ou abaixo. Repor estes itens para não perder vendas.';
        } elseif ($nPreco > 0) {
            $intelTom = 'warn';
            $intelTitulo = 'Rever preço em ' . $nPreco . ' produto(s)';
            $intelTxt = 'Margem baixa em item importante — confirme custo e preço de venda.';
        } elseif ($nPromocao > 0) {
            $intelTom = 'info';
            $intelTitulo = $nPromocao . ' produto(s) com stock parado';
            $intelTxt = 'Há stock a mais e pouca saída — considere promoção no salão.';
        } else {
            $intelTom = 'ok';
            $intelTitulo = 'Stock do cardápio ok';
            $intelTxt = 'Nenhum produto abaixo do mínimo agora.';
        }

        return [
            'ativo' => $temAbc || $temRec || $temPrev || $temDash,
            'contagem_abc' => $contagemAbc,
            'recomendacoes' => $recomendacoes,
            'top_margem' => $topMargem,
            'previsao' => $temPrev ? produtos_ia_previsao_demanda($db, $restauranteId) : [],
            'por_produto' => $porProduto,
            'intel' => [
                'tom' => $intelTom,
                'titulo' => $intelTitulo,
                'txt' => $intelTxt,
                'criticos' => $nCriticos,
                'reposicao' => $nReposicao,
            ],
            'flags' => [
                'abc' => $temAbc,
                'recomendacoes' => $temRec,
                'previsao' => $temPrev,
                'dashboard' => $temDash,
            ],
        ];
    }
}
