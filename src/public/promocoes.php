<?php
require_once __DIR__ . '/app_base.php';
require_once APP_BASE . '/src/config/auth_check.php';
require_once APP_BASE . '/src/config/csrf.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/plano_check.php';
require_once APP_BASE . '/src/config/restaurante_context.php';
require_once APP_BASE . '/src/config/promocao_schema.php';

requirePermissionOrRedirect(['ADMIN', 'GERENTE']);
$rid = session_restaurante_contexto_id();
$planRid = session_restaurante_capability_id() ?: $rid;
$plano = plano_normalizar_nome((plano_get_dados($planRid)['plano_nome'] ?? 'BASICO'));
$permitido = promocao_plano_permitido($plano);
$csrfToken = csrf_get_token();
$db = (new Database())->getConnection();
promocao_schema_garantir($db);

$prodStmt = $db->prepare('SELECT id,nome,preco,imagem FROM produtos WHERE restaurante_id=:rid AND ativo=1 ORDER BY nome');
$prodStmt->execute(['rid' => $rid]);
$produtos = $prodStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$normalizarImagemProduto = static function ($imagem): string {
    $img = trim((string)$imagem);
    if ($img === '') {
        return '';
    }
    if (preg_match('/^https?:\/\//i', $img)) {
        return $img;
    }
    $img = str_replace('\\', '/', $img);
    $img = preg_replace('#^(\./)+#', '', $img);
    $img = str_replace('src/public/', '', $img);
    $img = ltrim($img, '/');
    if (strpos($img, 'images/') !== 0 && preg_match('/\.(png|jpe?g|webp|gif)$/i', $img)) {
        $img = 'images/' . $img;
    }
    return is_file(__DIR__ . '/' . $img) ? $img : '';
};
// Stock pela ficha técnica e custo de cada produto (Empresarial com Estoque Inteligente)
require_once APP_BASE . '/src/config/produto_custos.php';
$promoComFicha = [];
$promoPorcoes = [];
$promoCustos = [];
$promoEstoqueAvancado = plano_tem_funcionalidade_db($planRid, 'estoque_avancado');
if ($promoEstoqueAvancado && $produtos) {
    try {
        require_once APP_BASE . '/src/config/estoque_inteligente.php';
        $filialPromo = null;
        if (function_exists('session_restaurante_auth_id')) {
            $authPromo = (int)session_restaurante_auth_id();
            if ($authPromo > 0 && $authPromo !== (int)$rid) { $filialPromo = $authPromo; }
        }
        $idsPromo = array_map(static fn($x) => (int)$x['id'], $produtos);
        if (function_exists('estoque_porcoes_disponiveis_por_produtos')) {
            $promoPorcoes = estoque_porcoes_disponiveis_por_produtos($db, (int)$rid, $filialPromo, $idsPromo);
            foreach (array_keys($promoPorcoes) as $pidF) { $promoComFicha[(int)$pidF] = true; }
        }
        $promoCustos = produto_custos_ficha($db, (int)$rid, $filialPromo);
    } catch (Throwable $ePromoStock) {
        error_log('[PROMOCOES][STOCK] ' . $ePromoStock->getMessage());
    }
}
if (!function_exists('promo_img_iniciais')) {
    function promo_img_iniciais(string $nome): string
    {
        $ini = '';
        foreach (preg_split('/\s+/u', trim($nome)) ?: [] as $parte) {
            if ($parte !== '' && mb_strlen($ini) < 2) { $ini .= mb_strtoupper(mb_substr($parte, 0, 1)); }
        }
        $ini = $ini !== '' ? $ini : 'P';
        $cores = ['#ea580c', '#0f766e', '#2563eb', '#7c3aed', '#be123c', '#a16207', '#0e7490', '#4d7c0f'];
        $cor = $cores[abs(crc32($nome)) % count($cores)];
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96"><rect width="96" height="96" fill="' . $cor . '"/>'
            . '<text x="50%" y="50%" dy=".35em" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="38" font-weight="700" fill="#ffffff">'
            . htmlspecialchars($ini, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</text></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
foreach ($produtos as &$produto) {
    $produto['imagem_url'] = $normalizarImagemProduto($produto['imagem'] ?? '');
    $pidPromo = (int)$produto['id'];
    $produto['custo'] = $promoCustos[$pidPromo] ?? null;
    $produto['stock_estado'] = 'ok';
    if ($promoEstoqueAvancado) {
        if (empty($promoComFicha[$pidPromo])) {
            $produto['stock_estado'] = 'semficha';
        } elseif ((int)($promoPorcoes[$pidPromo] ?? 0) <= 0) {
            $produto['stock_estado'] = 'esgotado';
        } elseif ((int)$promoPorcoes[$pidPromo] <= 5) {
            $produto['stock_estado'] = 'baixo';
        }
    }
    $produto['porcoes'] = $promoPorcoes[$pidPromo] ?? null;
}
unset($produto);

$promoStmt = $db->prepare('SELECT * FROM promocoes WHERE restaurante_id=:rid ORDER BY ativo DESC, criado_em DESC');
$promoStmt->execute(['rid' => $rid]);
$promocoes = $promoStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($promocoes as &$promoHorario) {
    $promoHorario['inicio_em'] = promocao_data_utc_para_local($promoHorario['inicio_em'] ?? null);
    $promoHorario['fim_em'] = promocao_data_utc_para_local($promoHorario['fim_em'] ?? null);
}
unset($promoHorario);
$byPromo = [];
if ($promocoes) {
    $ids = array_map(static fn($p) => (int)$p['id'], $promocoes);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $itemStmt = $db->prepare("SELECT pi.promocao_id,pi.produto_id,pi.quantidade,p.nome,p.preco,p.imagem
        FROM promocao_itens pi INNER JOIN produtos p ON p.id=pi.produto_id
        WHERE pi.promocao_id IN ($in) ORDER BY pi.id");
    $itemStmt->execute($ids);
    foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $item) {
        $byPromo[(int)$item['promocao_id']][] = $item;
    }
}

$agora = (new DateTimeImmutable('now', promocao_timezone()))->getTimestamp();
$promoTimestampLocal = static function ($value): ?int {
    $value = trim((string)$value);
    if ($value === '') return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, promocao_timezone());
    return $date ? $date->getTimestamp() : null;
};
$totalAtivas = 0;
$totalAgendadas = 0;
$totalExpiradas = 0;
$economiaAcumulada = 0.0;
foreach ($promocoes as &$promo) {
    $promo['itens'] = $byPromo[(int)$promo['id']] ?? [];
    $promo['preco_regular'] = array_sum(array_map(static fn($i) => (float)$i['preco'] * (int)$i['quantidade'], $promo['itens']));
    $promo['economia'] = max(0, $promo['preco_regular'] - (float)$promo['preco_promocional']);
    $promo['desconto_percent'] = $promo['preco_regular'] > 0 ? round(($promo['economia'] / $promo['preco_regular']) * 100, 1) : 0;
    $inicioTs = $promoTimestampLocal($promo['inicio_em'] ?? null);
    $fimTs = $promoTimestampLocal($promo['fim_em'] ?? null);
    if (!$promo['ativo']) {
        $promo['estado_operacional'] = 'INATIVA';
    } elseif ($fimTs && $fimTs < $agora) {
        $promo['estado_operacional'] = 'EXPIRADA';
        $totalExpiradas++;
    } elseif ($inicioTs && $inicioTs > $agora) {
        $promo['estado_operacional'] = 'AGENDADA';
        $totalAgendadas++;
    } else {
        $promo['estado_operacional'] = 'ATIVA';
        $totalAtivas++;
        $economiaAcumulada += $promo['economia'];
    }
}
unset($promo);

// Receita atribuida usa apenas vendas efetivamente pagas. O identificador da
// campanha e preservado em itens_venda a partir desta versao.
$promoPerformanceById = [];
$promoReceitaTotal = 0.0;
$promoReceita7Dias = 0.0;
$promoReceita7DiasAnterior = 0.0;
$promoTransacoesTotal = 0;
$promoTransacoes7Dias = 0;
$promoCampanhaLider = null;
if ($promocoes) {
    try {
        $performanceStmt = $db->prepare("SELECT iv.promocao_id,
                COUNT(DISTINCT iv.venda_id) AS transacoes,
                COUNT(DISTINCT CASE WHEN v.criado_em >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY) THEN iv.venda_id END) AS transacoes_7d,
                COALESCE(SUM(iv.quantidade), 0) AS itens_vendidos,
                COALESCE(SUM(iv.subtotal), 0) AS receita,
                COALESCE(SUM(CASE WHEN v.criado_em >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY) THEN iv.subtotal ELSE 0 END), 0) AS receita_7d,
                COALESCE(SUM(CASE WHEN v.criado_em >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 14 DAY)
                                  AND v.criado_em < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY) THEN iv.subtotal ELSE 0 END), 0) AS receita_7d_anterior,
                MAX(v.criado_em) AS ultima_venda
            FROM itens_venda iv
            INNER JOIN vendas v ON v.id = iv.venda_id
            INNER JOIN promocoes pr ON pr.id = iv.promocao_id AND pr.restaurante_id = v.restaurante_id
            WHERE v.restaurante_id = :rid
              AND iv.promocao_id IS NOT NULL
              AND UPPER(TRIM(COALESCE(v.status, ''))) = 'PAGO'
            GROUP BY iv.promocao_id");
        $performanceStmt->execute(['rid' => $rid]);
        foreach ($performanceStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $performance) {
            $promoIdPerformance = (int)$performance['promocao_id'];
            $performance['transacoes'] = (int)$performance['transacoes'];
            $performance['transacoes_7d'] = (int)$performance['transacoes_7d'];
            $performance['itens_vendidos'] = (int)$performance['itens_vendidos'];
            $performance['receita'] = (float)$performance['receita'];
            $performance['receita_7d'] = (float)$performance['receita_7d'];
            $performance['receita_7d_anterior'] = (float)$performance['receita_7d_anterior'];
            $promoPerformanceById[$promoIdPerformance] = $performance;
            $promoReceitaTotal += $performance['receita'];
            $promoReceita7Dias += $performance['receita_7d'];
            $promoReceita7DiasAnterior += $performance['receita_7d_anterior'];
            $promoTransacoesTotal += $performance['transacoes'];
            $promoTransacoes7Dias += $performance['transacoes_7d'];
            if ($promoCampanhaLider === null || $performance['receita'] > $promoCampanhaLider['receita']) {
                $promoCampanhaLider = $performance;
            }
        }
    } catch (Throwable $e) {
        error_log('[PROMOCOES][PERFORMANCE] ' . $e->getMessage());
    }
}
foreach ($promocoes as &$promoPerformanceItem) {
    $promoPerformanceItem['performance'] = $promoPerformanceById[(int)$promoPerformanceItem['id']] ?? [
        'transacoes' => 0,
        'transacoes_7d' => 0,
        'itens_vendidos' => 0,
        'receita' => 0.0,
        'receita_7d' => 0.0,
        'receita_7d_anterior' => 0.0,
        'ultima_venda' => null,
    ];
    if ($promoCampanhaLider !== null && (int)$promoCampanhaLider['promocao_id'] === (int)$promoPerformanceItem['id']) {
        $promoCampanhaLider['nome'] = (string)$promoPerformanceItem['nome'];
    }
}
unset($promoPerformanceItem);
$promoVariacao7Dias = $promoReceita7DiasAnterior > 0
    ? (($promoReceita7Dias - $promoReceita7DiasAnterior) / $promoReceita7DiasAnterior) * 100
    : null;

$promoProdutosCobertos = [];
$promoDescontos = [];
$promoDuracoes = [];
$promoAgenda = [];
foreach ($promocoes as $promoRadarItem) {
    foreach ($promoRadarItem['itens'] as $itemRadar) {
        $promoProdutosCobertos[(int)$itemRadar['produto_id']] = true;
    }
    if ((float)$promoRadarItem['desconto_percent'] > 0) {
        $promoDescontos[] = (float)$promoRadarItem['desconto_percent'];
    }
    $inicioRadar = $promoTimestampLocal($promoRadarItem['inicio_em'] ?? null);
    $fimRadar = $promoTimestampLocal($promoRadarItem['fim_em'] ?? null);
    if ($inicioRadar && $fimRadar && $fimRadar > $inicioRadar) {
        $promoDuracoes[] = ($fimRadar - $inicioRadar) / 86400;
    }
    $promoAgenda[] = [
        'nome' => (string)$promoRadarItem['nome'],
        'estado' => (string)$promoRadarItem['estado_operacional'],
        'inicio' => $promoRadarItem['inicio_em'] ?? null,
        'fim' => $promoRadarItem['fim_em'] ?? null,
        'desconto' => (float)$promoRadarItem['desconto_percent'],
    ];
}
usort($promoAgenda, static function (array $a, array $b): int {
    return strcmp((string)($a['inicio'] ?? '9999-12-31'), (string)($b['inicio'] ?? '9999-12-31'));
});
$promoDescontoMedio = $promoDescontos ? array_sum($promoDescontos) / count($promoDescontos) : 0.0;
$promoDuracaoMedia = $promoDuracoes ? array_sum($promoDuracoes) / count($promoDuracoes) : 0.0;
$promoCobertura = $produtos ? (count($promoProdutosCobertos) / count($produtos)) * 100 : 0.0;
$promoProxima = null;
foreach ($promoAgenda as $agendaItem) {
    if ($agendaItem['estado'] === 'AGENDADA') {
        $promoProxima = $agendaItem;
        break;
    }
}

$isEmpresarialUi = $plano === 'EMPRESARIAL';
$promoInsights = [];
$promoSaude = 100;
$promoComboInsight = null;
$promoAiRecommendations = [];
$promoAiLearningMessage = 'Ainda sem vendas para sugerir combos. As sugestões aparecem quando houver vendas pagas de pratos e bebidas.';
if ($isEmpresarialUi) {
    if ($promoCampanhaLider !== null && (float)$promoCampanhaLider['receita'] > 0) {
        $promoInsights[] = [
            'tom' => 'success',
            'icone' => 'fa-trophy',
            'titulo' => 'Campanha lider: ' . ($promoCampanhaLider['nome'] ?? ('#' . (int)$promoCampanhaLider['promocao_id'])),
            'texto' => number_format((float)$promoCampanhaLider['receita'], 2, ',', '.') . ' MZN em ' . (int)$promoCampanhaLider['transacoes'] . ' transacao(oes) paga(s).',
        ];
    }
    if ($promoVariacao7Dias !== null && abs($promoVariacao7Dias) >= 10) {
        $subiu = $promoVariacao7Dias > 0;
        $promoInsights[] = [
            'tom' => $subiu ? 'success' : 'danger',
            'icone' => $subiu ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down',
            'titulo' => 'Receita promocional ' . ($subiu ? 'cresceu' : 'caiu') . ' ' . number_format(abs($promoVariacao7Dias), 0, ',', '.') . '%',
            'texto' => 'Comparação dos últimos 7 dias com os 7 dias anteriores.',
        ];
        if (!$subiu) $promoSaude -= 15;
    }
    if ($totalAtivas === 0) {
        $promoSaude -= 35;
        $promoInsights[] = [
            'tom' => 'warning',
            'icone' => 'fa-bullhorn',
            'titulo' => 'Nenhuma campanha ativa',
            'texto' => 'O cardápio está sem nenhuma oferta publicada neste momento.',
        ];
    }
    if ($totalExpiradas > 0) {
        $promoSaude -= min(20, $totalExpiradas * 5);
        $promoInsights[] = [
            'tom' => 'neutral',
            'icone' => 'fa-clock-rotate-left',
            'titulo' => $totalExpiradas . ' campanha(s) expirada(s)',
            'texto' => 'Revise o historico e reutilize apenas as ofertas que deram resultado.',
        ];
    }

    $expiramBreve = 0;
    foreach ($promocoes as $promoAnalise) {
        $fimAnalise = $promoTimestampLocal($promoAnalise['fim_em'] ?? null);
        $inicioAnalise = $promoTimestampLocal($promoAnalise['inicio_em'] ?? null);
        $inicioEfetivo = $inicioAnalise ?: $promoTimestampLocal(promocao_data_utc_para_local($promoAnalise['criado_em'] ?? null));
        if ($promoAnalise['estado_operacional'] === 'ATIVA'
            && (int)($promoAnalise['performance']['transacoes'] ?? 0) === 0
            && $inicioEfetivo
            && $inicioEfetivo < ($agora - 86400)) {
            $promoSaude -= 8;
            $promoInsights[] = [
                'tom' => 'warning',
                'icone' => 'fa-eye-slash',
                'titulo' => $promoAnalise['nome'] . ' ainda não converteu',
                'texto' => 'Está ativa há mais de 24 horas sem nenhuma venda paga. Reveja o destaque, o preço ou a composição.',
            ];
        }
        if ($promoAnalise['estado_operacional'] === 'ATIVA' && $fimAnalise && $fimAnalise <= ($agora + 3 * 86400)) {
            $expiramBreve++;
        }
        if ((float)$promoAnalise['desconto_percent'] >= 40 && $promoAnalise['estado_operacional'] === 'ATIVA') {
            $promoSaude -= 10;
            $promoInsights[] = [
                'tom' => 'danger',
                'icone' => 'fa-triangle-exclamation',
                'titulo' => 'Desconto elevado em ' . $promoAnalise['nome'],
                'texto' => 'A reducao e de ' . number_format((float)$promoAnalise['desconto_percent'], 1, ',', '.') . '%. Confirme a margem antes de manter a campanha.',
            ];
        }
    }
    if ($expiramBreve > 0) {
        $promoSaude -= min(15, $expiramBreve * 5);
        $promoInsights[] = [
            'tom' => 'warning',
            'icone' => 'fa-calendar-day',
            'titulo' => $expiramBreve . ' campanha(s) termina(m) em ate 3 dias',
            'texto' => 'Prepare a renovação ou substituição para não deixar o cardápio sem oferta.',
        ];
    }

    try {
        $stmtInteligencia = $db->prepare("
            SELECT p.id, p.nome, p.preco, COALESCE(NULLIF(TRIM(c.nome), ''), '') AS categoria,
                   COALESCE(SUM(CASE WHEN v.id IS NOT NULL THEN iv.quantidade ELSE 0 END), 0) AS qtd_30,
                   COALESCE(SUM(CASE WHEN v.id IS NOT NULL AND v.criado_em >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) THEN iv.quantidade ELSE 0 END), 0) AS qtd_14
            FROM produtos p
            LEFT JOIN categorias c ON c.id = p.categoria_id
            LEFT JOIN itens_venda iv ON iv.produto_id = p.id
            LEFT JOIN vendas v ON v.id = iv.venda_id
                AND v.restaurante_id = p.restaurante_id
                AND UPPER(TRIM(COALESCE(v.status, ''))) = 'PAGO'
                AND v.criado_em >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
            WHERE p.restaurante_id = :rid AND COALESCE(p.ativo, 1) = 1
            GROUP BY p.id, p.nome, p.preco, c.nome
            ORDER BY qtd_30 DESC, p.nome ASC
        ");
        $stmtInteligencia->execute(['rid' => $rid]);
        $vendasProdutos = $stmtInteligencia->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $normalizarBusca = static function (string $texto): string {
            return function_exists('mb_strtolower') ? mb_strtolower($texto, 'UTF-8') : strtolower($texto);
        };
        $ehBebida = static function (array $produto) use ($normalizarBusca): bool {
            $texto = $normalizarBusca((string)($produto['categoria'] ?? '') . ' ' . (string)($produto['nome'] ?? ''));
            return (bool)preg_match('/bebida|coca|fanta|sumo|suco|agua|cerveja|refri|refrigerante|cha|cafe/u', $texto);
        };
        $liderComida = null;
        $bebidaComplementar = null;
        $comidasComVenda = [];
        $bebidasComVenda = [];
        foreach ($vendasProdutos as $produtoVenda) {
            if ((float)$produtoVenda['preco'] <= 0) continue;
            if ((int)($produtoVenda['qtd_30'] ?? 0) <= 0) continue; // só produtos com vendas reais
            if ($ehBebida($produtoVenda)) $bebidasComVenda[] = $produtoVenda;
            else $comidasComVenda[] = $produtoVenda;
        }
        $liderComida = $comidasComVenda[0] ?? null;
        $bebidaComplementar = $bebidasComVenda[0] ?? null;
        if ($liderComida && $bebidaComplementar) {
            $precoRegularSugestao = (float)$liderComida['preco'] + (float)$bebidaComplementar['preco'];
            $precoSugerido = floor(($precoRegularSugestao * 0.9) / 5) * 5;
            array_unshift($promoInsights, [
                'tom' => 'opportunity',
                'icone' => 'fa-wand-magic-sparkles',
                'titulo' => 'Combo sugerido por vendas',
                'texto' => $liderComida['nome'] . ' (' . (int)$liderComida['qtd_30'] . ' vendas) com ' . $bebidaComplementar['nome'] . ' (' . (int)$bebidaComplementar['qtd_30'] . ' vendas). Sugestão com 10% de incentivo.',
                'produto_ids' => [(int)$liderComida['id'], (int)$bebidaComplementar['id']],
                'nome_combo' => $liderComida['nome'] . ' + ' . $bebidaComplementar['nome'],
                'preco_sugerido' => max(0.01, $precoSugerido),
            ]);

            $paresIa = [
                [$comidasComVenda[0] ?? null, $bebidasComVenda[0] ?? null, 10, 'Aumentar o ticket médio'],
                [$comidasComVenda[1] ?? null, $bebidasComVenda[0] ?? null, 8, 'Oferta com margem protegida'],
                [$comidasComVenda[0] ?? null, $bebidasComVenda[1] ?? null, 12, 'Teste de conversão'],
            ];
            $paresAplicados = [];
            foreach ($paresIa as [$comidaIa, $bebidaIa, $descontoIa, $estrategiaIa]) {
                if (!$comidaIa || !$bebidaIa) continue;
                $chaveIa = (int)$comidaIa['id'] . ':' . (int)$bebidaIa['id'];
                if (isset($paresAplicados[$chaveIa])) continue;
                $paresAplicados[$chaveIa] = true;
                $regularIa = (float)$comidaIa['preco'] + (float)$bebidaIa['preco'];
                $precoIa = max(0.01, floor(($regularIa * (1 - $descontoIa / 100)) / 5) * 5);
                $promoAiRecommendations[] = [
                    'estrategia' => $estrategiaIa,
                    'nome' => $comidaIa['nome'] . ' + ' . $bebidaIa['nome'],
                    'texto' => (int)$comidaIa['qtd_30'] . ' vendas do prato e ' . (int)$bebidaIa['qtd_30'] . ' da bebida nos últimos 30 dias.',
                    'desconto' => $descontoIa,
                    'preco' => $precoIa,
                    'itens' => [
                        ['produto_id' => (int)$comidaIa['id'], 'quantidade' => 1],
                        ['produto_id' => (int)$bebidaIa['id'], 'quantidade' => 1],
                    ],
                ];
            }
            if ($promoAiRecommendations) {
                $promoAiLearningMessage = 'Sugestões geradas a partir das vendas pagas dos últimos 30 dias.';
            }
        } elseif (!$vendasProdutos || array_sum(array_map(static fn($p) => (int)$p['qtd_30'], $vendasProdutos)) === 0) {
            $promoInsights[] = [
                'tom' => 'info',
                'icone' => 'fa-chart-line',
                'titulo' => 'Ainda sem vendas para sugerir combos',
                'texto' => 'As sugestões aparecem quando houver vendas pagas nos últimos 30 dias.',
            ];
        } else {
            $promoAiLearningMessage = 'Há vendas, mas é preciso pelo menos um prato e uma bebida com vendas para sugerir um combo.';
        }
    } catch (Throwable $e) {
        error_log('[PROMOCOES][INTELIGENCIA] ' . $e->getMessage());
    }
    $promoSaude = max(0, min(100, $promoSaude));
    if (!$promoInsights) {
        $promoInsights[] = [
            'tom' => 'success',
            'icone' => 'fa-circle-check',
            'titulo' => 'Campanhas sob controlo',
            'texto' => 'Não há campanhas a terminar em breve nem descontos que precisem de atenção.',
        ];
    }
    $promoInsights = array_slice($promoInsights, 0, 4);
    foreach ($promoInsights as $insightDisponivel) {
        if (!empty($insightDisponivel['produto_ids'])) {
            $promoComboInsight = $insightDisponivel;
            break;
        }
    }
}
require_once __DIR__ . '/includes/empresarial_ui.php';
$temaPlano = strtolower((string)(preg_replace('/[^A-Z]/', '', $plano) ?: 'BASICO'));
$bodyClasses = trim('premium-ui plan-theme-' . $temaPlano . ' ' . empresarial_body_class('promo-page'));
?>
<!doctype html>
<html lang="pt">
<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Comando de Promoções - Nivora Restaurant</title>
    <link href="lib/bootstrap-5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="lib/fontawesome-6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <?php empresarial_head_assets(); ?>
    <link rel="stylesheet" href="css/promocoes-empresarial.css?v=<?= (int)filemtime(__DIR__ . '/css/promocoes-empresarial.css') ?>">
    <style>
        .promo-stock-tag { font-style: normal; font-weight: 700; padding: 1px 6px; border-radius: 6px; font-size: 11px; }
        .promo-stock-tag.is-warn { background: #fef3c7; color: #92400e; }
        .promo-stock-tag.is-out { background: #fee2e2; color: #b91c1c; }
        .promo-product.is-stock-esgotado .promo-product-copy strong { color: #94a3b8; }
        .promo-product-thumb img { width: 100%; height: 100%; object-fit: cover; border-radius: inherit; }
        .promo-margem-aviso { margin: 8px 0; padding: 8px 10px; border-radius: 10px; background: #fffbeb; color: #92400e; font-size: 12.5px; font-weight: 600; }
        .promo-margem-aviso.is-perda { background: #fef2f2; color: #b91c1c; }
    </style>
</head>
<body class="<?= htmlspecialchars($bodyClasses, ENT_QUOTES, 'UTF-8') ?>">
<?php include __DIR__ . '/includes/sidebar.php'; ?>

<main class="promo-command">
    <div class="promo-content-area">
    <header class="promo-command-head">
        <div>
            <div class="promo-title-line"><h1><i class="fas fa-tags"></i> Promo&ccedil;&otilde;es</h1><span class="promo-revenue-label"><?= htmlspecialchars($isEmpresarialUi ? 'EMPRESARIAL' : $plano, ENT_QUOTES, 'UTF-8') ?></span></div>
            <p>Crie campanhas, acompanhe vendas atribu&iacute;das e mantenha o card&aacute;pio atualizado.</p>
        </div>
        <div class="promo-head-actions">
            <a class="promo-btn-secondary" href="cardapio.php?rid=<?= (int)$rid ?>" target="_blank" rel="noopener"><i class="fas fa-arrow-up-right-from-square"></i> Ver card&aacute;pio</a>
            <?php if ($promocoes): ?><button class="promo-btn-secondary" id="promoExportCsv" type="button"><i class="fas fa-file-arrow-down"></i> Exportar</button><?php endif; ?>
            <?php if ($permitido): ?><button class="btn promo-btn-primary" id="newPromoTop" type="button"><i class="fas fa-plus"></i> Nova campanha</button><?php endif; ?>
        </div>
    </header>

    <?php if (!$permitido): ?>
        <div class="promo-plan-lock"><i class="fas fa-lock"></i><div><strong>Módulo indisponível</strong><span>As promoções estão disponíveis nos planos Profissional e Empresarial.</span></div></div>
    <?php else: ?>
        <section class="promo-kpis" aria-label="Panorama comercial de promocoes">
            <div class="promo-kpi"><span class="promo-kpi-icon orange"><i class="fas fa-tags"></i></span><div><strong><?= count($promocoes) ?></strong><span>Campanhas</span><small>Total cadastrado</small></div></div>
            <div class="promo-kpi"><span class="promo-kpi-icon green"><i class="fas fa-circle-check"></i></span><div><strong><?= $totalAtivas ?></strong><span>Ativas agora</span><small>Visíveis no cardápio</small></div></div>
            <div class="promo-kpi"><span class="promo-kpi-icon blue"><i class="fas fa-receipt"></i></span><div><strong><?= $promoTransacoes7Dias ?></strong><span>Transa&ccedil;&otilde;es em 7 dias</span><small>Vendas pagas com campanha</small></div></div>
            <div class="promo-kpi"><span class="promo-kpi-icon amber"><i class="fas fa-coins"></i></span><div><strong><?= number_format($promoReceitaTotal, 2, ',', '.') ?></strong><span>MZN de receita promocional</span><small><?= $promoTransacoesTotal > 0 ? $promoTransacoesTotal . ' transa&ccedil;&atilde;o(oes) atribu&iacute;da(s)' : 'Aguardando a primeira venda atribu&iacute;da' ?></small></div></div>
        </section>

        <?php if (!$isEmpresarialUi && $promocoes): ?>
        <div class="promo-estado-chips" aria-label="Estado das campanhas">
            <span class="is-ativa"><i class="fas fa-circle"></i> Ativas <b><?= (int)$totalAtivas ?></b></span>
            <span class="is-agendada"><i class="fas fa-circle"></i> Agendadas <b><?= (int)$totalAgendadas ?></b></span>
            <span class="is-expirada"><i class="fas fa-circle"></i> Expiradas <b><?= (int)$totalExpiradas ?></b></span>
        </div>
        <?php endif; ?>

        <?php if ($isEmpresarialUi): ?>
        <section class="promo-smartbar" aria-labelledby="promoSmartTitle">
            <div class="promo-smart-score" style="--health:<?= (int)$promoSaude ?>"><span><i class="fas fa-brain"></i></span><div><small>INTELIG&Ecirc;NCIA COMERCIAL</small><strong id="promoSmartTitle"><?php if (empty($promocoes)): ?>Sem campanhas ainda<?php else: ?>Sa&uacute;de promocional <?= (int)$promoSaude ?>/100<?php endif; ?></strong></div></div>
            <div class="promo-smart-insights">
                <?php foreach (array_slice($promoInsights, 0, 2) as $insight): ?>
                <div class="promo-smart-note tone-<?= htmlspecialchars($insight['tom'], ENT_QUOTES, 'UTF-8') ?>"><i class="fas <?= htmlspecialchars($insight['icone'], ENT_QUOTES, 'UTF-8') ?>"></i><span><strong><?= htmlspecialchars($insight['titulo'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($insight['texto'], ENT_QUOTES, 'UTF-8') ?></small></span><?php if (!empty($insight['produto_ids'])): ?><button class="promo-smart-build" type="button" data-products='<?= htmlspecialchars(json_encode($insight['produto_ids']), ENT_QUOTES, 'UTF-8') ?>' data-name="<?= htmlspecialchars($insight['nome_combo'], ENT_QUOTES, 'UTF-8') ?>" data-price="<?= htmlspecialchars((string)$insight['preco_sugerido'], ENT_QUOTES, 'UTF-8') ?>">Montar combo</button><?php endif; ?></div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <div class="promo-workspace">
            <form id="promoForm" class="promo-builder">
                <div class="promo-builder-head">
                    <div><span class="promo-builder-symbol"><i class="fas fa-tags"></i></span><span><small>CADASTRO</small><h2 id="promoFormTitle">Nova campanha</h2></span></div>
                    <button class="promo-icon-btn" id="promoCancel" type="button" title="Limpar formulario" hidden><i class="fas fa-xmark"></i></button>
                </div>
                <p class="promo-builder-intro">Monte uma oferta completa, valide o desconto e publique quando estiver pronta para vender.</p>
                <input type="hidden" id="promoId" value="0">

                <div class="promo-form-step"><span>01</span><strong>Defina a oferta</strong></div>
                <div class="promo-field"><label for="promoNome">Nome comercial</label><input id="promoNome" maxlength="150" required placeholder="Nome da oferta apresentado ao cliente"></div>
                <div class="promo-field"><label for="promoDescricao">Descrição no cardápio</label><textarea id="promoDescricao" maxlength="500" rows="2" placeholder="Texto curto para apresentar a oferta"></textarea></div>

                <div class="promo-form-step"><span>02</span><strong>Componha o combo</strong></div>
                <div class="promo-field-head"><label>Produtos do combo</label><span id="selectedProductsCount">0 selecionados</span></div>
                <div class="promo-product-search"><i class="fas fa-magnifying-glass"></i><input id="promoProductSearch" type="search" placeholder="Pesquisar produto" autocomplete="off"></div>
                <div class="promo-products" id="promoProducts">
                    <?php foreach ($produtos as $produto): ?>
                        <label class="promo-product is-stock-<?= htmlspecialchars($produto['stock_estado'], ENT_QUOTES, 'UTF-8') ?>" data-custo="<?= $produto['custo'] !== null ? htmlspecialchars((string)$produto['custo'], ENT_QUOTES, 'UTF-8') : '' ?>" data-name="<?= htmlspecialchars(strtolower((string)$produto['nome']), ENT_QUOTES, 'UTF-8') ?>">
                            <input class="promo-check" type="checkbox" value="<?= (int)$produto['id'] ?>">
                            <span class="promo-product-mark"><i class="fas fa-check"></i></span>
                            <span class="promo-product-thumb"><?php if ($produto['imagem_url'] !== ''): ?><img src="<?= htmlspecialchars($produto['imagem_url'], ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy"><?php else: ?><img src="<?= htmlspecialchars(promo_img_iniciais((string)$produto['nome']), ENT_QUOTES, 'UTF-8') ?>" alt=""><?php endif; ?></span>
                            <span class="promo-product-copy"><strong><?= htmlspecialchars($produto['nome'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= number_format((float)$produto['preco'], 2, ',', '.') ?> MZN / unidade<?php if ($produto['stock_estado'] === 'semficha'): ?> · <em class="promo-stock-tag is-warn">sem ficha técnica</em><?php elseif ($produto['stock_estado'] === 'esgotado'): ?> · <em class="promo-stock-tag is-out">esgotado</em><?php elseif ($produto['stock_estado'] === 'baixo'): ?> · <em class="promo-stock-tag is-warn">restam <?= (int)$produto['porcoes'] ?></em><?php endif; ?></small></span>
                            <span class="promo-qty-wrap"><button type="button" class="promo-qty-btn qty-minus" title="Diminuir"><i class="fas fa-minus"></i></button><input class="promo-qtd" type="number" value="1" min="1" max="100" aria-label="Quantidade"><button type="button" class="promo-qty-btn qty-plus" title="Aumentar"><i class="fas fa-plus"></i></button></span>
                            <input type="hidden" class="promo-product-price" value="<?= htmlspecialchars((string)(float)$produto['preco'], ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                    <?php endforeach; ?>
                </div>

                <?php if ($isEmpresarialUi): ?>
                <section class="promo-ai-assistant" aria-labelledby="promoAiTitle">
                    <header><span><i class="fas fa-sparkles"></i></span><div><small>ANÁLISE</small><strong id="promoAiTitle">Sugest&otilde;es para a campanha</strong></div><?php if ($promoAiRecommendations): ?><b>Baseado nas vendas</b><?php endif; ?></header>
                    <?php if ($promoAiRecommendations): ?>
                    <div class="promo-ai-recommendations">
                        <?php foreach (array_slice($promoAiRecommendations, 0, 3) as $indiceIa => $recomendacaoIa): ?>
                        <article>
                            <span><?= str_pad((string)($indiceIa + 1), 2, '0', STR_PAD_LEFT) ?></span>
                            <div><strong><?= htmlspecialchars($recomendacaoIa['estrategia'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($recomendacaoIa['nome'], ENT_QUOTES, 'UTF-8') ?> &middot; <?= htmlspecialchars($recomendacaoIa['texto'], ENT_QUOTES, 'UTF-8') ?></small></div>
                            <button class="promo-ai-apply" type="button" data-items='<?= htmlspecialchars(json_encode($recomendacaoIa['itens']), ENT_QUOTES, 'UTF-8') ?>' data-name="<?= htmlspecialchars($recomendacaoIa['nome'], ENT_QUOTES, 'UTF-8') ?>" data-price="<?= htmlspecialchars((string)$recomendacaoIa['preco'], ENT_QUOTES, 'UTF-8') ?>" data-discount="<?= (int)$recomendacaoIa['desconto'] ?>">Aplicar</button>
                        </article>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="promo-ai-learning"><i class="fas fa-chart-line"></i><?= htmlspecialchars($promoAiLearningMessage, ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                    <footer><button type="button" id="promoAiCopy"><i class="fas fa-pen-nib"></i> Gerar descrição</button><button type="button" id="promoAiSchedule"><i class="fas fa-calendar-week"></i> Programar 7 dias</button><button type="button" id="promoAiPrice"><i class="fas fa-wand-magic-sparkles"></i> Aplicar preço sugerido</button></footer>
                </section>
                <section class="promo-live-preview" aria-labelledby="promoPreviewTitle" hidden>
                    <header><span><i class="fas fa-mobile-screen-button"></i> Como aparece no card&aacute;pio</span><b id="promoPreviewState">Em revis&atilde;o</b></header>
                    <div class="promo-preview-body">
                        <div class="promo-preview-media" id="promoPreviewMedia"><i class="fas fa-utensils"></i></div>
                        <div class="promo-preview-copy"><h3 id="promoPreviewTitle"></h3><p id="promoPreviewDescription"></p><div><del id="promoPreviewRegular"></del><strong id="promoPreviewPrice"></strong><em id="promoPreviewDiscount"></em></div></div>
                    </div>
                </section>
                <?php endif; ?>

                <div class="promo-form-step"><span>03</span><strong>Proteja a margem</strong></div>
                <div class="promo-commercial-fields">
                    <div class="promo-field promo-price-input"><label for="promoPreco">Pre&ccedil;o promocional</label><span><input id="promoPreco" type="number" min="0.01" step="0.01" required placeholder="0,00"><b>MZN</b></span></div>
                    <div class="promo-period-group"><label>Validade</label><div><input id="promoInicio" type="datetime-local" aria-label="Inicio da campanha"><span>at&eacute;</span><input id="promoFim" type="datetime-local" aria-label="Fim da campanha"></div></div>
                </div>

                <div class="promo-form-lower">
                    <div class="promo-field"><label for="promoAtivo">Estado</label><select id="promoAtivo"><option value="1">Ativa</option><option value="0">Pausada</option></select></div>
                    <aside class="promo-summary"><strong>Resumo da campanha</strong><dl><div><dt>Subtotal</dt><dd id="regularTotal">0,00 MZN</dd></div><div><dt>Desconto</dt><dd id="savingTotal">0,00 MZN</dd></div><div class="promo-summary-final"><dt>Pre&ccedil;o final</dt><dd id="promoFinalTotal">0,00 MZN</dd></div><div id="promoCustoRow" hidden><dt>Custo dos insumos</dt><dd id="promoCustoTotal">—</dd></div></dl><div class="promo-margem-aviso" id="promoMargemAviso" hidden></div><?php if ($isEmpresarialUi): ?><div class="promo-smart-price"><span><i class="fas fa-wand-magic-sparkles"></i> Recomendado: <b id="smartRecommendedPrice">0,00 MZN</b></span><button id="applySmartPrice" type="button">Aplicar</button></div><div class="promo-quality"><div><span>Qualidade comercial</span><b id="promoQualityScore">0/100</b></div><i><span id="promoQualityBar"></span></i><small id="promoQualityMessage">Selecione os produtos para iniciar a análise.</small></div><?php endif; ?><small><i class="fas fa-shield-halved"></i> Desconto atual: <b id="discountPercent">0%</b></small></aside>
                </div>
                <div id="promoAlert" aria-live="polite"></div>
                <div class="promo-form-actions"><button class="promo-save" id="promoSave" type="submit"><i class="fas fa-paper-plane"></i><span>Publicar campanha</span></button><button class="promo-clear" id="promoClear" type="button">Limpar</button></div>
            </form>

            <section class="promo-portfolio<?= !$promocoes ? ' is-empty' : '' ?>">
                <div class="promo-toolbar">
                    <div><small>GEST&Atilde;O</small><h2>Campanhas cadastradas</h2></div>
                    <div class="promo-toolbar-tools">
                        <div class="promo-list-search"><i class="fas fa-magnifying-glass"></i><input id="promoListSearch" type="search" placeholder="Pesquisar campanha"></div>
                        <select id="promoStatusFilter" aria-label="Filtrar estado"><option value="">Todos os estados</option><option value="ATIVA">Ativas</option><option value="AGENDADA">Agendadas</option><option value="INATIVA">Inativas</option><option value="EXPIRADA">Expiradas</option></select>
                    </div>
                </div>

                <div class="promo-table-shell">
                    <table class="promo-table">
                        <thead><tr><th>Campanha</th><th>Composição</th><th>Preços</th><th>Resultado pago</th><th>Período</th><th>Estado</th><th class="text-end">Acoes</th></tr></thead>
                        <tbody id="promoTableBody">
                        <?php foreach ($promocoes as $promo): ?>
                            <?php $promoImagem = $normalizarImagemProduto($promo['imagem'] ?? ($promo['itens'][0]['imagem'] ?? '')); ?>
                            <?php $estadoLabel = $promo['estado_operacional'] === 'INATIVA' ? 'Pausada' : ucfirst(strtolower($promo['estado_operacional'])); ?>
                            <tr data-name="<?= htmlspecialchars(strtolower((string)$promo['nome']), ENT_QUOTES, 'UTF-8') ?>" data-status="<?= htmlspecialchars($promo['estado_operacional'], ENT_QUOTES, 'UTF-8') ?>">
                                <td><div class="promo-campaign-cell"><span class="promo-campaign-image"><?php if ($promoImagem !== ''): ?><img src="<?= htmlspecialchars($promoImagem, ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy"><?php else: ?><i class="fas fa-utensils"></i><?php endif; ?></span><div><strong><?= htmlspecialchars($promo['nome'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($promo['descricao'] ?: 'Sem descrição', ENT_QUOTES, 'UTF-8') ?></small></div></div></td>
                                <td><div class="promo-components"><?php foreach ($promo['itens'] as $item): ?><span>&bull; <b><?= (int)$item['quantidade'] ?>x</b> <?= htmlspecialchars($item['nome'], ENT_QUOTES, 'UTF-8') ?></span><?php endforeach; ?></div></td>
                                <td><div class="promo-price-cell"><strong><?= number_format((float)$promo['preco_promocional'], 2, ',', '.') ?> MZN</strong><del><?= number_format((float)$promo['preco_regular'], 2, ',', '.') ?> MZN</del><?php if ($promo['desconto_percent'] > 0): ?><em>-<?= number_format((float)$promo['desconto_percent'], 1, ',', '.') ?>%</em><?php endif; ?></div></td>
                                <td><div class="promo-performance-cell"><strong><?= number_format((float)$promo['performance']['receita'], 2, ',', '.') ?> MZN</strong><span><i class="fas fa-receipt"></i> <?= (int)$promo['performance']['transacoes'] ?> transa&ccedil;&otilde;es</span><small><?= $promo['performance']['ultima_venda'] ? 'Ultima ' . date('d/m H:i', strtotime((string)promocao_data_utc_para_local($promo['performance']['ultima_venda']))) : 'Sem venda atribu&iacute;da' ?></small></div></td>
                                <td><div class="promo-date-cell"><span><?= $promo['inicio_em'] ? date('d/m/Y H:i', strtotime($promo['inicio_em'])) : 'Início imediato' ?></span><small><?= $promo['fim_em'] ? 'Até ' . date('d/m/Y H:i', strtotime($promo['fim_em'])) : 'Sem data final' ?></small></div></td>
                                <td><span class="promo-state state-<?= strtolower($promo['estado_operacional']) ?>"><i></i><?= htmlspecialchars($estadoLabel, ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><div class="promo-row-actions"><button type="button" onclick='editarPromo(<?= json_encode($promo, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_TAG) ?>)' title="Editar campanha"><i class="fas fa-pen-to-square"></i></button><button type="button" onclick='duplicarPromo(<?= json_encode($promo, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_TAG) ?>)' title="Duplicar como rascunho"><i class="fas fa-copy"></i></button><button class="<?= (int)$promo['ativo'] === 1 ? 'is-pause' : 'is-resume' ?>" type="button" onclick="alterarEstadoPromo(<?= (int)$promo['id'] ?>, <?= (int)$promo['ativo'] === 1 ? 0 : 1 ?>)" title="<?= (int)$promo['ativo'] === 1 ? 'Pausar campanha' : 'Reativar campanha' ?>"><i class="fas <?= (int)$promo['ativo'] === 1 ? 'fa-pause' : 'fa-play' ?>"></i></button></div></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if (!$promocoes): ?>
                    <div class="promo-empty">
                        <div class="promo-empty-mark"><i class="fas fa-tags"></i></div>
                        <div class="promo-empty-copy"><small>AINDA NÃO HÁ CAMPANHAS PUBLICADAS</small><strong>Crie a primeira oferta do cardápio</strong><p>Use o construtor ao lado para escolher os produtos, definir o preço e publicar quando a oferta estiver pronta.</p><div class="promo-empty-actions"><button type="button" id="emptyNewPromo"><i class="fas fa-arrow-left"></i> Ir para o construtor</button><?php if ($promoComboInsight): ?><button class="promo-smart-build is-primary" type="button" data-products='<?= htmlspecialchars(json_encode($promoComboInsight['produto_ids']), ENT_QUOTES, 'UTF-8') ?>' data-name="<?= htmlspecialchars($promoComboInsight['nome_combo'], ENT_QUOTES, 'UTF-8') ?>" data-price="<?= htmlspecialchars((string)$promoComboInsight['preco_sugerido'], ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-wand-magic-sparkles"></i> Usar sugestão</button><?php endif; ?></div></div>
                    </div>
                    <?php endif; ?>
                    <div class="promo-no-results" id="promoNoResults" hidden>Nenhuma campanha corresponde aos filtros.</div>
                    <?php if ($promocoes): ?><footer class="promo-table-footer"><span id="promoTableCount">Mostrando 0 campanhas</span><div><button id="promoPrevPage" type="button" title="P&aacute;gina anterior"><i class="fas fa-chevron-left"></i></button><b id="promoCurrentPage">1</b><button id="promoNextPage" type="button" title="Pr&oacute;xima p&aacute;gina"><i class="fas fa-chevron-right"></i></button></div></footer><?php endif; ?>
                </div>
                <?php if ($isEmpresarialUi && $promocoes): ?>
                <section class="promo-radar" aria-labelledby="promoRadarTitle">
                    <header><div><span><i class="fas fa-crosshairs"></i></span><div><small>RADAR COMERCIAL</small><h3 id="promoRadarTitle">Performance e agenda das ofertas</h3></div></div><b>Vendas pagas</b></header>
                    <div class="promo-radar-body">
                        <div class="promo-radar-metrics">
                            <div><span>Receita em 7 dias</span><strong><?= number_format($promoReceita7Dias, 2, ',', '.') ?> MZN</strong><small><?= $promoVariacao7Dias === null ? 'Primeiro per&iacute;odo de medi&ccedil;&atilde;o' : (($promoVariacao7Dias >= 0 ? '+' : '') . number_format($promoVariacao7Dias, 0, ',', '.') . '% vs. 7 dias anteriores') ?></small></div>
                            <div><span>Transa&ccedil;&otilde;es atribu&iacute;das</span><strong><?= $promoTransacoesTotal ?></strong><small><?= $promoCampanhaLider ? 'L&iacute;der: ' . htmlspecialchars((string)($promoCampanhaLider['nome'] ?? ('#' . $promoCampanhaLider['promocao_id'])), ENT_QUOTES, 'UTF-8') : 'Aguardando primeira convers&atilde;o' ?></small></div>
                            <div><span>Prote&ccedil;&atilde;o de margem</span><strong><?= number_format($promoDescontoMedio, 1, ',', '.') ?>%</strong><small><?= $promoDescontoMedio > 25 ? 'Desconto m&eacute;dio acima da faixa recomendada' : number_format($promoCobertura, 0, ',', '.') . '% do card&aacute;pio em campanhas' ?></small></div>
                        </div>
                        <div class="promo-agenda">
                            <div class="promo-agenda-head"><strong>Agenda de ativa&ccedil;&atilde;o</strong><small><?= $promoProxima ? 'Pr&oacute;xima: ' . htmlspecialchars($promoProxima['nome'], ENT_QUOTES, 'UTF-8') : 'Todas as campanhas j&aacute; iniciaram' ?></small></div>
                            <div class="promo-agenda-list">
                                <?php foreach (array_slice($promoAgenda, 0, 4) as $agenda): ?>
                                <div><i class="state-<?= strtolower($agenda['estado']) ?>"></i><span><strong><?= htmlspecialchars($agenda['nome'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= $agenda['inicio'] ? date('d/m H:i', strtotime($agenda['inicio'])) : 'In&iacute;cio imediato' ?> &middot; <?= number_format($agenda['desconto'], 1, ',', '.') ?>% desconto</small></span><b><?= htmlspecialchars($agenda['estado'] === 'INATIVA' ? 'PAUSADA' : $agenda['estado'], ENT_QUOTES, 'UTF-8') ?></b></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </section>
                <?php endif; ?>
            </section>
        </div>
    <?php endif; ?>
    </div>
</main>

<script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
const PROMO_CSRF = <?= json_encode($csrfToken) ?>;
const promoForm = document.getElementById('promoForm');
const money = value => Number(value || 0).toLocaleString('pt-MZ', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' MZN';

function selectedPromoItems() {
    return Array.from(document.querySelectorAll('.promo-check:checked')).map(check => {
        const row = check.closest('.promo-product');
        return { produto_id: Number(check.value), quantidade: Number(row.querySelector('.promo-qtd').value || 1) };
    });
}

function updatePricingPreview() {
    let regular = 0;
    const selectedRows = [];
    document.querySelectorAll('.promo-product').forEach(row => {
        const checked = row.querySelector('.promo-check').checked;
        row.classList.toggle('is-selected', checked);
        if (checked) {
            selectedRows.push(row);
            regular += Number(row.querySelector('.promo-product-price').value || 0) * Number(row.querySelector('.promo-qtd').value || 1);
        }
    });
    const promotional = Number(document.getElementById('promoPreco').value || 0);
    const saving = Math.max(0, regular - promotional);
    const pct = regular > 0 ? saving / regular * 100 : 0;
    const recommended = regular > 0 ? Math.floor((regular * 0.9) / 5) * 5 : 0;
    document.getElementById('regularTotal').textContent = money(regular);
    document.getElementById('savingTotal').textContent = money(saving);
    document.getElementById('promoFinalTotal').textContent = money(promotional);
    document.getElementById('discountPercent').textContent = pct > 0 ? '-' + pct.toLocaleString('pt-MZ', {maximumFractionDigits: 1}) + '%' : '0%';
    const recommendedTarget = document.getElementById('smartRecommendedPrice');
    if (recommendedTarget) recommendedTarget.textContent = money(recommended);
    const count = document.querySelectorAll('.promo-check:checked').length;
    document.getElementById('selectedProductsCount').textContent = count + (count === 1 ? ' selecionado' : ' selecionados');
    // Só mostra recomendação e qualidade depois de escolher produtos
    const smartBox = document.querySelector('.promo-smart-price');
    const qualityBox = document.querySelector('.promo-quality');
    if (smartBox) smartBox.hidden = count < 2;
    if (qualityBox) qualityBox.hidden = count < 1;
    // Custo dos insumos e margem da promoção
    const custoRow = document.getElementById('promoCustoRow');
    const avisoMargem = document.getElementById('promoMargemAviso');
    if (custoRow && avisoMargem) {
        let custo = 0;
        let custoCompleto = selectedRows.length > 0;
        selectedRows.forEach(row => {
            const c = row.dataset.custo;
            if (c === '' || c === undefined) { custoCompleto = false; return; }
            custo += Number(c) * Number(row.querySelector('.promo-qtd').value || 1);
        });
        avisoMargem.hidden = true;
        avisoMargem.className = 'promo-margem-aviso';
        if (custoCompleto && custo > 0) {
            custoRow.hidden = false;
            let txt = money(custo);
            if (promotional > 0) {
                const margem = Math.round((promotional - custo) / promotional * 100);
                txt += ' · margem ' + margem + '%';
                if (promotional <= custo) {
                    avisoMargem.hidden = false;
                    avisoMargem.classList.add('is-perda');
                    avisoMargem.textContent = 'Atenção: o preço da promoção não cobre o custo dos insumos (' + money(custo) + '). Cada venda dá prejuízo.';
                } else if (margem < 30) {
                    avisoMargem.hidden = false;
                    avisoMargem.textContent = 'Margem baixa (' + margem + '%). Confirme se compensa antes de publicar.';
                }
            }
            document.getElementById('promoCustoTotal').textContent = txt;
        } else {
            custoRow.hidden = selectedRows.length === 0;
            document.getElementById('promoCustoTotal').textContent = selectedRows.length ? 'Por registar (ficha ou custo em falta)' : '—';
        }
        if (promotional > 0 && regular > 0 && promotional >= regular) {
            avisoMargem.hidden = false;
            avisoMargem.className = 'promo-margem-aviso is-perda';
            avisoMargem.textContent = 'O preço da promoção tem de ser menor do que comprar os produtos em separado (' + money(regular) + ').';
        }
    }
    const qualityScore = document.getElementById('promoQualityScore');
    if (qualityScore) {
        const nameLength = document.getElementById('promoNome').value.trim().length;
        const startDate = document.getElementById('promoInicio').value;
        const endDate = document.getElementById('promoFim').value;
        let score = 0;
        if (nameLength >= 8) score += 20;
        if (count >= 2) score += 30;
        if (count >= 3) score += 5;
        if (promotional > 0 && regular > 0) score += 10;
        if (pct >= 5 && pct <= 25) score += 25;
        else if (pct > 0 && pct <= 40) score += 15;
        if (startDate && endDate && new Date(endDate) > new Date(startDate)) score += 15;
        score = Math.min(100, score);
        qualityScore.textContent = score + '/100';
        document.getElementById('promoQualityBar').style.width = score + '%';
        const quality = qualityScore.closest('.promo-quality');
        const message = document.getElementById('promoQualityMessage');
        quality.dataset.tone = score >= 80 ? 'strong' : (score >= 55 ? 'medium' : 'weak');
        if (count < 2) message.textContent = 'Escolha pelo menos dois produtos complementares.';
        else if (promotional <= 0) message.textContent = 'Defina o preço final da campanha.';
        else if (pct <= 0) message.textContent = 'O preço precisa de criar uma vantagem para o cliente.';
        else if (pct > 40) message.textContent = 'Desconto agressivo: confirme a margem antes de publicar.';
        else if (!startDate || !endDate) message.textContent = 'Defina a validade para controlar a campanha.';
        else if (score >= 80) message.textContent = 'Campanha bem estruturada e pronta para revisão.';
        else message.textContent = 'Complete os dados para fortalecer a campanha.';
    }
    const previewTitle = document.getElementById('promoPreviewTitle');
    if (previewTitle) {
        const campaignName = document.getElementById('promoNome').value.trim();
        const campaignDescription = document.getElementById('promoDescricao').value.trim();
        const preview = previewTitle.closest('.promo-live-preview');
        const isReadyForPreview = campaignName.length >= 3 && selectedRows.length >= 2 && promotional > 0;
        preview.hidden = !isReadyForPreview;
        if (!isReadyForPreview) return;
        previewTitle.textContent = campaignName;
        document.getElementById('promoPreviewDescription').textContent = campaignDescription || selectedRows.map(row => row.querySelector('.promo-product-copy strong').textContent.trim()).join(' + ');
        document.getElementById('promoPreviewRegular').textContent = money(regular);
        document.getElementById('promoPreviewPrice').textContent = money(promotional);
        document.getElementById('promoPreviewDiscount').textContent = pct > 0 ? '-' + pct.toLocaleString('pt-MZ', {maximumFractionDigits: 1}) + '%' : '0%';
        document.getElementById('promoPreviewState').textContent = campaignName.length >= 8 && pct > 0 ? 'Pronta para publicar' : 'Em revisao';
        document.getElementById('promoPreviewRegular').hidden = pct <= 0;
        document.getElementById('promoPreviewDiscount').hidden = pct <= 0;
        const previewMedia = document.getElementById('promoPreviewMedia');
        previewMedia.replaceChildren();
        selectedRows.slice(0, 2).forEach(row => {
            const source = row.querySelector('.promo-product-thumb img');
            if (!source) return;
            const image = document.createElement('img');
            image.src = source.src;
            image.alt = '';
            previewMedia.appendChild(image);
        });
        if (!previewMedia.children.length) {
            const icon = document.createElement('i');
            icon.className = 'fas fa-utensils';
            previewMedia.appendChild(icon);
        }
        previewMedia.classList.toggle('has-pair', previewMedia.children.length > 1);
    }
}

function resetPromo() {
    if (!promoForm) return;
    promoForm.reset();
    document.getElementById('promoId').value = '0';
    document.getElementById('promoAtivo').value = '1';
    document.getElementById('promoFormTitle').textContent = 'Criar nova campanha';
    document.querySelector('#promoSave span').textContent = 'Publicar campanha';
    document.getElementById('promoCancel').hidden = true;
    document.querySelectorAll('.promo-qtd').forEach(input => input.value = '1');
    document.getElementById('promoAlert').innerHTML = '';
    updatePricingPreview();
}

window.editarPromo = function (promo) {
    resetPromo();
    document.getElementById('promoId').value = promo.id;
    document.getElementById('promoNome').value = promo.nome || '';
    document.getElementById('promoDescricao').value = promo.descricao || '';
    document.getElementById('promoPreco').value = promo.preco_promocional || '';
    document.getElementById('promoAtivo').value = Number(promo.ativo) === 1 ? '1' : '0';
    document.getElementById('promoInicio').value = promo.inicio_em ? String(promo.inicio_em).replace(' ', 'T').slice(0, 16) : '';
    document.getElementById('promoFim').value = promo.fim_em ? String(promo.fim_em).replace(' ', 'T').slice(0, 16) : '';
    (promo.itens || []).forEach(item => {
        const check = document.querySelector('.promo-check[value="' + item.produto_id + '"]');
        if (!check) return;
        check.checked = true;
        check.closest('.promo-product').querySelector('.promo-qtd').value = item.quantidade;
    });
    document.getElementById('promoFormTitle').textContent = 'Editar campanha';
    document.querySelector('#promoSave span').textContent = 'Guardar alteracoes';
    document.getElementById('promoCancel').hidden = false;
    updatePricingPreview();
    document.querySelector('.promo-builder').scrollIntoView({behavior: 'smooth', block: 'start'});
};

window.duplicarPromo = function (promo) {
    resetPromo();
    document.getElementById('promoNome').value = 'Copia - ' + (promo.nome || 'Campanha');
    document.getElementById('promoDescricao').value = promo.descricao || '';
    document.getElementById('promoPreco').value = promo.preco_promocional || '';
    document.getElementById('promoAtivo').value = '0';
    (promo.itens || []).forEach(item => {
        const check = document.querySelector('.promo-check[value="' + item.produto_id + '"]');
        if (!check) return;
        check.checked = true;
        check.closest('.promo-product').querySelector('.promo-qtd').value = item.quantidade;
    });
    document.getElementById('promoFormTitle').textContent = 'Rever copia da campanha';
    document.querySelector('#promoSave span').textContent = 'Guardar rascunho';
    document.getElementById('promoCancel').hidden = false;
    updatePricingPreview();
    document.querySelector('.promo-builder').scrollIntoView({behavior: 'smooth', block: 'start'});
    document.getElementById('promoNome').focus();
};

if (promoForm) promoForm.addEventListener('submit', async event => {
    event.preventDefault();
    const button = document.getElementById('promoSave');
    const original = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>A guardar</span>';
    const fields = {id:'promoId',nome:'promoNome',descricao:'promoDescricao',preco_promocional:'promoPreco',inicio_em:'promoInicio',fim_em:'promoFim'};
    const body = new FormData();
    body.append('_csrf', PROMO_CSRF);
    Object.entries(fields).forEach(([key, id]) => body.append(key, document.getElementById(id).value));
    body.append('ativo', document.getElementById('promoAtivo').value === '1' ? '1' : '0');
    body.append('itens', JSON.stringify(selectedPromoItems()));
    try {
        const response = await fetch('api/promocao_salvar.php', {method:'POST', body, credentials:'same-origin', headers:{'X-CSRF-Token':PROMO_CSRF,'X-Requested-With':'XMLHttpRequest'}});
        const data = await response.json();
        if (!data.success) throw new Error(data.message || 'Não foi possível guardar a campanha.');
        location.reload();
    } catch (error) {
        document.getElementById('promoAlert').innerHTML = '<div class="promo-form-error"><i class="fas fa-circle-exclamation"></i>' + String(error.message || error) + '</div>';
        button.disabled = false;
        button.innerHTML = original;
    }
});

window.alterarEstadoPromo = async function (id, ativo) {
    const acao = ativo === 1 ? 'reativar' : 'pausar';
    if (!(await nivoraConfirmar('Deseja ' + acao + ' esta campanha?'))) return;
    const body = new FormData();
    body.append('_csrf', PROMO_CSRF);
    body.append('id', id);
    body.append('ativo', String(ativo));
    const response = await fetch('api/promocao_status.php', {method:'POST', body, credentials:'same-origin', headers:{'X-CSRF-Token':PROMO_CSRF,'X-Requested-With':'XMLHttpRequest'}});
    const data = await response.json();
    if (data.success) {
        NivoraFeedback.reloadWithMessage(data.message || 'Campanha atualizada.', 'success');
    } else {
        NivoraFeedback.error(data.message || 'Não foi possível atualizar a campanha.');
    }
};

document.querySelectorAll('.promo-check,.promo-qtd').forEach(input => input.addEventListener('change', updatePricingPreview));
document.getElementById('promoPreco')?.addEventListener('input', updatePricingPreview);
['promoNome', 'promoDescricao', 'promoInicio', 'promoFim'].forEach(id => document.getElementById(id)?.addEventListener('input', updatePricingPreview));
document.querySelectorAll('.promo-qty-btn').forEach(button => button.addEventListener('click', () => {
    const input = button.closest('.promo-qty-wrap').querySelector('.promo-qtd');
    input.value = Math.max(1, Math.min(100, Number(input.value || 1) + (button.classList.contains('qty-plus') ? 1 : -1)));
    button.closest('.promo-product').querySelector('.promo-check').checked = true;
    updatePricingPreview();
}));
document.getElementById('promoProductSearch')?.addEventListener('input', event => {
    const query = event.target.value.toLowerCase().trim();
    document.querySelectorAll('.promo-product').forEach(row => row.hidden = query !== '' && !row.dataset.name.includes(query));
});
let promoCurrentPage = 1;
const promoPageSize = 6;
function filterPromoTable(resetPage = false) {
    if (resetPage) promoCurrentPage = 1;
    const query = (document.getElementById('promoListSearch')?.value || '').toLowerCase().trim();
    const status = document.getElementById('promoStatusFilter')?.value || '';
    const rows = Array.from(document.querySelectorAll('#promoTableBody tr'));
    const matches = rows.filter(row => (!query || row.dataset.name.includes(query)) && (!status || row.dataset.status === status));
    const pages = Math.max(1, Math.ceil(matches.length / promoPageSize));
    promoCurrentPage = Math.min(promoCurrentPage, pages);
    const start = (promoCurrentPage - 1) * promoPageSize;
    rows.forEach(row => row.hidden = true);
    matches.slice(start, start + promoPageSize).forEach(row => row.hidden = false);
    const count = document.getElementById('promoTableCount');
    if (count) {
        const first = matches.length ? start + 1 : 0;
        const last = Math.min(start + promoPageSize, matches.length);
        count.textContent = 'Mostrando ' + first + ' a ' + last + ' de ' + matches.length + ' campanhas';
    }
    const page = document.getElementById('promoCurrentPage');
    if (page) page.textContent = promoCurrentPage;
    const prev = document.getElementById('promoPrevPage');
    const next = document.getElementById('promoNextPage');
    if (prev) prev.disabled = promoCurrentPage <= 1;
    if (next) next.disabled = promoCurrentPage >= pages;
    const noResults = document.getElementById('promoNoResults');
    if (noResults) noResults.hidden = matches.length > 0 || rows.length === 0;
    const table = document.querySelector('.promo-table');
    if (table) table.hidden = matches.length === 0 && rows.length > 0;
    const footer = document.querySelector('.promo-table-footer');
    if (footer) footer.hidden = matches.length === 0;
}
document.getElementById('promoListSearch')?.addEventListener('input', () => filterPromoTable(true));
document.getElementById('promoStatusFilter')?.addEventListener('change', () => filterPromoTable(true));
document.getElementById('promoPrevPage')?.addEventListener('click', () => {
    if (promoCurrentPage > 1) {
        promoCurrentPage--;
        filterPromoTable();
    }
});
document.getElementById('promoNextPage')?.addEventListener('click', () => {
    promoCurrentPage++;
    filterPromoTable();
});
document.getElementById('applySmartPrice')?.addEventListener('click', () => {
    const regular = Array.from(document.querySelectorAll('.promo-product')).reduce((total, row) => {
        if (!row.querySelector('.promo-check').checked) return total;
        return total + Number(row.querySelector('.promo-product-price').value || 0) * Number(row.querySelector('.promo-qtd').value || 1);
    }, 0);
    if (regular <= 0) return;
    document.getElementById('promoPreco').value = (Math.floor((regular * 0.9) / 5) * 5).toFixed(2);
    updatePricingPreview();
});
document.getElementById('promoClear')?.addEventListener('click', resetPromo);
document.getElementById('promoCancel')?.addEventListener('click', resetPromo);
document.getElementById('newPromoTop')?.addEventListener('click', () => { resetPromo(); document.querySelector('.promo-builder').scrollIntoView({behavior:'smooth'}); document.getElementById('promoNome').focus(); });
document.getElementById('emptyNewPromo')?.addEventListener('click', () => document.getElementById('newPromoTop')?.click());
document.getElementById('promoExportCsv')?.addEventListener('click', () => {
    const headers = ['Campanha', 'Composicao', 'Precificacao', 'Resultado pago', 'Periodo', 'Estado'];
    const rows = Array.from(document.querySelectorAll('#promoTableBody tr')).map(row => Array.from(row.querySelectorAll('td')).slice(0, 6).map(cell => cell.innerText.replace(/\s+/g, ' ').trim()));
    const csv = [headers, ...rows].map(row => row.map(value => '"' + String(value).replace(/"/g, '""') + '"').join(';')).join('\r\n');
    const blob = new Blob(["\uFEFF" + csv], {type: 'text/csv;charset=utf-8'});
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'desempenho-promocoes.csv';
    link.click();
    URL.revokeObjectURL(link.href);
});
document.querySelectorAll('.promo-ai-apply').forEach(button => button.addEventListener('click', () => {
    resetPromo();
    let items = [];
    try { items = JSON.parse(button.dataset.items || '[]'); } catch (error) { items = []; }
    items.forEach(item => {
        const check = document.querySelector('.promo-check[value="' + Number(item.produto_id) + '"]');
        if (!check) return;
        check.checked = true;
        check.closest('.promo-product').querySelector('.promo-qtd').value = Math.max(1, Number(item.quantidade || 1));
    });
    document.getElementById('promoNome').value = button.dataset.name || '';
    document.getElementById('promoPreco').value = button.dataset.price || '';
    document.getElementById('promoAtivo').value = '0';
    document.getElementById('promoDescricao').value = 'Oferta recomendada pela IA comercial com ' + String(button.dataset.discount || '0') + '% de incentivo.';
    updatePricingPreview();
    document.getElementById('promoNome').focus();
}));
document.getElementById('promoAiCopy')?.addEventListener('click', () => {
    const nomes = Array.from(document.querySelectorAll('.promo-check:checked')).map(check => check.closest('.promo-product').querySelector('.promo-product-copy strong').textContent.trim());
    if (nomes.length < 2) {
        document.getElementById('promoAlert').innerHTML = '<div class="promo-form-error"><i class="fas fa-circle-exclamation"></i>Selecione pelo menos dois produtos para gerar a descrição.</div>';
        return;
    }
    const desconto = document.getElementById('discountPercent').textContent || '0%';
    document.getElementById('promoDescricao').value = 'Combo ' + nomes.join(' + ') + '. Oferta especial com ' + desconto + ' de incentivo, disponível por tempo limitado.';
    updatePricingPreview();
});
document.getElementById('promoAiSchedule')?.addEventListener('click', () => {
    const toInputDate = date => {
        const pad = value => String(value).padStart(2, '0');
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + 'T' + pad(date.getHours()) + ':' + pad(date.getMinutes());
    };
    const inicio = new Date();
    inicio.setMinutes(0, 0, 0);
    inicio.setHours(inicio.getHours() + 1);
    const fim = new Date(inicio);
    fim.setDate(fim.getDate() + 7);
    document.getElementById('promoInicio').value = toInputDate(inicio);
    document.getElementById('promoFim').value = toInputDate(fim);
    updatePricingPreview();
});
document.getElementById('promoAiPrice')?.addEventListener('click', () => document.getElementById('applySmartPrice')?.click());
document.querySelectorAll('.promo-smart-build').forEach(button => button.addEventListener('click', () => {
    resetPromo();
    let productIds = [];
    try { productIds = JSON.parse(button.dataset.products || '[]').map(Number); } catch (error) { productIds = []; }
    productIds.forEach(productId => {
        const check = document.querySelector('.promo-check[value="' + productId + '"]');
        if (check) check.checked = true;
    });
    document.getElementById('promoNome').value = button.dataset.name || '';
    document.getElementById('promoDescricao').value = 'Combo recomendado com base nas vendas dos últimos 30 dias.';
    document.getElementById('promoPreco').value = button.dataset.price || '';
    updatePricingPreview();
    document.querySelector('.promo-builder').scrollIntoView({behavior:'smooth', block:'start'});
}));
if (promoForm) updatePricingPreview();
filterPromoTable();
requestAnimationFrame(() => {
    const activeMenuItem = document.querySelector('.sidebar-menu .menu-item.active');
    const sidebarMenu = activeMenuItem?.closest('.sidebar-menu');
    if (activeMenuItem && sidebarMenu) {
        sidebarMenu.scrollTop = Math.max(0, activeMenuItem.offsetTop - sidebarMenu.clientHeight / 2);
    }
});
</script>
</body>
</html>
