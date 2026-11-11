<?php
require_once __DIR__ . '/app_base.php';

include_once APP_BASE . '/src/config/auth_check.php';
include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/plano_check.php';
include_once APP_BASE . '/src/config/csrf.php';
include_once APP_BASE . '/src/config/filiais_helper.php';
require_once __DIR__ . '/includes/empresarial_ui.php';

$perfil = strtoupper(trim((string)($_SESSION['perfil'] ?? '')));
if ($perfil !== 'ADMIN') {
    header('Location: dashboard.php?erro=acesso_negado');
    exit;
}

$db = (new Database())->getConnection();

try {
    $contexto = filiais_obter_contexto(
        $db,
        (int)($_SESSION['restaurante_id'] ?? 0),
        (int)($_SESSION['matriz_id'] ?? 0)
    );
    filiais_exigir_matriz($contexto, 'Somente a matriz pode aceder a gestao de filiais.');
} catch (Throwable $e) {
    error_log('[FILIAIS][PAGINA][CONTEXTO] ' . $e->getMessage());
    header('Location: dashboard.php?erro=acesso_filiais_matriz');
    exit;
}

if (empty($contexto['tem_multi_filial'])) {
    header('Location: configuracoes.php?erro=plano_sem_multi_filial');
    exit;
}

$matrizId = (int)$contexto['matriz_id'];
$matriz = $contexto['matriz'];
$restauranteAtual = $contexto['restaurante_atual'];
$contextoFilialAtivo = false;
$filialAtual = null;
$dadosPlano = $contexto['plano'];
$nomePlano = $dadosPlano['nome_display'] ?? ($dadosPlano['plano_nome'] ?? ($matriz['plano'] ?? 'Plano atual'));
$csrfToken = csrf_get_token();
$planoCapabilityId = function_exists('session_restaurante_capability_id')
    ? (int)session_restaurante_capability_id()
    : $matrizId;
$planoCapabilityId = $planoCapabilityId > 0 ? $planoCapabilityId : $matrizId;
$temComparativoFiliais = plano_tem_funcionalidade_db($planoCapabilityId, 'comparativo_filiais')
    || plano_tem_funcionalidade_db($planoCapabilityId, 'dashboard_executivo');
$temTransferenciaEstoqueFiliais = plano_tem_funcionalidade_db($planoCapabilityId, 'transferencia_estoque');

$secao = $_GET['secao'] ?? 'inicio';
$periodoDias = (int)($_GET['periodo'] ?? 30);
if (!in_array($periodoDias, [7, 30, 90], true)) {
    $periodoDias = 30;
}
$secoesValidas = ['inicio', 'dashboard', 'estoque', 'funcionarios'];
if (!in_array($secao, $secoesValidas, true)) {
    $secao = 'inicio';
}
if ($secao === 'dashboard' && !$temComparativoFiliais) {
    header('Location: filiais.php?secao=inicio&tipo=warning&msg=' . urlencode('Comparativo de filiais disponivel no plano Empresarial.'));
    exit;
}
if ($secao === 'estoque' && !$temTransferenciaEstoqueFiliais) {
    header('Location: filiais.php?secao=inicio&tipo=warning&msg=' . urlencode('Estoque entre filiais disponivel no plano Empresarial.'));
    exit;
}

$mensagem = '';
$tipoMsg = 'success';
if (isset($_GET['msg'])) {
    $mensagem = htmlspecialchars((string)$_GET['msg'], ENT_QUOTES, 'UTF-8');
    $tipoMsg = strtolower(trim((string)($_GET['tipo'] ?? 'success')));
    if ($tipoMsg === 'error') {
        $tipoMsg = 'danger';
    }
    if (!in_array($tipoMsg, ['success', 'danger', 'warning', 'info'], true)) {
        $tipoMsg = 'success';
    }
}

$stmt = $db->prepare("
    SELECT id, nome, email, telefone, endereco, cidade, status, created_at, atualizado_em AS updated_at
    FROM restaurantes
    WHERE filial_id = ? AND is_matriz = 0
    ORDER BY CASE WHEN status = 'ATIVO' THEN 0 ELSE 1 END, nome ASC
");
$stmt->execute([$matrizId]);
$filiais = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filialIds = array_map('intval', array_column($filiais, 'id'));
$redeIds = array_values(array_unique(array_merge([$matrizId], $filialIds)));
$vendasPorFilial = [];
$vendasAnteriorPorFilial = [];
$estoquePorFilial = [];
$funcionariosPorFilial = [];
$hojePorUnidade = [];

$placeholders = implode(', ', array_fill(0, count($redeIds), '?'));

// Só vendas pagas, no período escolhido e no período imediatamente anterior
$stmtVendas = $db->prepare("
    SELECT restaurante_id,
           COALESCE(SUM(CASE WHEN criado_em >= DATE_SUB(NOW(), INTERVAL ? DAY) THEN total_final ELSE 0 END), 0) AS total_vendas,
           SUM(CASE WHEN criado_em >= DATE_SUB(NOW(), INTERVAL ? DAY) THEN 1 ELSE 0 END) AS num_vendas,
           COALESCE(SUM(CASE WHEN criado_em < DATE_SUB(NOW(), INTERVAL ? DAY) THEN total_final ELSE 0 END), 0) AS total_anterior
    FROM vendas
    WHERE restaurante_id IN ($placeholders)
      AND status = 'PAGO'
      AND criado_em >= DATE_SUB(NOW(), INTERVAL ? DAY)
    GROUP BY restaurante_id
");
$stmtVendas->execute(array_merge([$periodoDias, $periodoDias, $periodoDias], $redeIds, [$periodoDias * 2]));
while ($row = $stmtVendas->fetch(PDO::FETCH_ASSOC)) {
    $vendasPorFilial[(int)$row['restaurante_id']] = [
        'total' => (float)$row['total_vendas'],
        'num_vendas' => (int)$row['num_vendas'],
    ];
    $vendasAnteriorPorFilial[(int)$row['restaurante_id']] = (float)$row['total_anterior'];
}

// Stock crítico a partir do Estoque Inteligente (insumos com mínimo definido)
try {
    $stmtEstoque = $db->prepare("
        SELECT i.restaurante_id, i.nome, i.unidade_base,
               COALESCE(SUM(s.saldo_base), 0) AS saldo,
               i.estoque_minimo_base AS minimo
        FROM estoque_insumos i
        LEFT JOIN estoque_saldos s ON s.insumo_id = i.id AND s.restaurante_id = i.restaurante_id
        WHERE i.restaurante_id IN ($placeholders)
          AND i.ativo = 1
          AND i.estoque_minimo_base > 0
        GROUP BY i.id, i.restaurante_id, i.nome, i.unidade_base, i.estoque_minimo_base
        HAVING saldo <= minimo
        ORDER BY i.restaurante_id ASC, COALESCE(SUM(s.saldo_base), 0) / i.estoque_minimo_base ASC, i.nome ASC
    ");
    $stmtEstoque->execute($redeIds);
    while ($row = $stmtEstoque->fetch(PDO::FETCH_ASSOC)) {
        $restauranteId = (int)$row['restaurante_id'];
        if (!isset($estoquePorFilial[$restauranteId])) {
            $estoquePorFilial[$restauranteId] = ['total_baixo' => 0, 'itens' => []];
        }
        $estoquePorFilial[$restauranteId]['total_baixo']++;
        if (count($estoquePorFilial[$restauranteId]['itens']) < 10) {
            $estoquePorFilial[$restauranteId]['itens'][] = [
                'nome' => $row['nome'],
                'estoque' => (float)$row['saldo'],
                'estoque_minimo' => (float)$row['minimo'],
                'unidade' => (string)$row['unidade_base'],
                'zerado' => (float)$row['saldo'] <= 0,
            ];
        }
    }
} catch (Throwable $e) {
    error_log('[FILIAIS][ESTOQUE] ' . $e->getMessage());
}

$stmtFuncionarios = $db->prepare("
    SELECT
        u.id,
        u.restaurante_id,
        u.nome,
        u.email,
        u.perfil,
        u.ativo,
        COALESCE(v.total_vendas, 0) AS vendas_total,
        COALESCE(v.valor_vendas, 0) AS vendas_valor
    FROM usuarios u
    LEFT JOIN (
        SELECT usuario_id, COUNT(*) AS total_vendas, SUM(total_final) AS valor_vendas
        FROM vendas
        WHERE status = 'PAGO'
          AND criado_em >= DATE_SUB(NOW(), INTERVAL ? DAY)
          AND restaurante_id IN ($placeholders)
        GROUP BY usuario_id
    ) v ON v.usuario_id = u.id
    WHERE u.restaurante_id IN ($placeholders)
    ORDER BY u.restaurante_id ASC, vendas_valor DESC, u.nome ASC
");
$stmtFuncionarios->execute(array_merge([$periodoDias], $redeIds, $redeIds));
while ($row = $stmtFuncionarios->fetch(PDO::FETCH_ASSOC)) {
    $restauranteId = (int)$row['restaurante_id'];
    if (!isset($funcionariosPorFilial[$restauranteId])) {
        $funcionariosPorFilial[$restauranteId] = ['total' => 0, 'funcionarios' => []];
    }
    $funcionariosPorFilial[$restauranteId]['total']++;
    $funcionariosPorFilial[$restauranteId]['funcionarios'][] = [
        'id' => (int)$row['id'],
        'nome' => $row['nome'],
        'email' => $row['email'],
        'perfil' => $row['perfil'],
        'ativo' => (int)$row['ativo'] === 1,
        'vendas_total' => (int)$row['vendas_total'],
        'vendas_valor' => (float)$row['vendas_valor'],
    ];
}

// Estado de hoje em cada unidade
foreach ($redeIds as $rid) {
    $hojePorUnidade[$rid] = ['caixa_aberto' => false, 'em_turno' => 0, 'contas_abertas' => 0, 'ultima_venda' => null];
}
$consultasHoje = [
    'caixa_aberto' => "SELECT restaurante_id, COUNT(*) AS v FROM caixas WHERE restaurante_id IN ($placeholders) AND UPPER(status) = 'ABERTO' GROUP BY restaurante_id",
    'em_turno' => "SELECT restaurante_id, COUNT(DISTINCT usuario_id) AS v FROM funcionarios_turnos WHERE restaurante_id IN ($placeholders) AND UPPER(status) = 'ATIVO' AND data >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) GROUP BY restaurante_id",
    'contas_abertas' => "SELECT restaurante_id, COUNT(*) AS v FROM pedidos WHERE restaurante_id IN ($placeholders) AND status IN ('NOVO','PENDENTE','CONFIRMADO','PREPARANDO','ABERTO','EM_ANDAMENTO','PRONTO','ENTREGUE','CONTA','CONTA_SOLICITADA') GROUP BY restaurante_id",
    'ultima_venda' => "SELECT restaurante_id, MAX(criado_em) AS v FROM vendas WHERE restaurante_id IN ($placeholders) AND status = 'PAGO' AND criado_em >= CURDATE() GROUP BY restaurante_id",
];
foreach ($consultasHoje as $chave => $sqlHoje) {
    try {
        $stHoje = $db->prepare($sqlHoje);
        $stHoje->execute($redeIds);
        while ($row = $stHoje->fetch(PDO::FETCH_ASSOC)) {
            $rid = (int)$row['restaurante_id'];
            if (!isset($hojePorUnidade[$rid])) {
                continue;
            }
            if ($chave === 'caixa_aberto') {
                $hojePorUnidade[$rid][$chave] = (int)$row['v'] > 0;
            } elseif ($chave === 'ultima_venda') {
                $hojePorUnidade[$rid][$chave] = $row['v'] ? date('H:i', strtotime((string)$row['v'])) : null;
            } else {
                $hojePorUnidade[$rid][$chave] = (int)$row['v'];
            }
        }
    } catch (Throwable $e) {
        error_log('[FILIAIS][HOJE][' . $chave . '] ' . $e->getMessage());
    }
}

// Últimas transferências de stock na rede
$transferenciasRede = [];
try {
    $stTrf = $db->prepare("
        SELECT m.criado_em, m.quantidade_base, m.unidade_origem, m.meta_json, m.motivo,
               i.nome AS insumo_nome, i.unidade_base, u.nome AS responsavel_nome
        FROM estoque_movimentos m
        LEFT JOIN estoque_insumos i ON i.id = m.insumo_id
        LEFT JOIN usuarios u ON u.id = m.responsavel_id
        WHERE m.restaurante_id IN ($placeholders)
          AND m.tipo = 'TRANSFERENCIA'
          AND m.meta_json LIKE '%\"sentido\":\"SAIDA\"%'
        ORDER BY m.criado_em DESC, m.id DESC
        LIMIT 20
    ");
    $stTrf->execute($redeIds);
    foreach ($stTrf->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $meta = json_decode((string)($row['meta_json'] ?? ''), true) ?: [];
        $transferenciasRede[] = [
            'quando' => date('d/m/Y H:i', strtotime((string)$row['criado_em'])),
            'insumo' => (string)($row['insumo_nome'] ?? 'Insumo'),
            'quantidade' => (float)$row['quantidade_base'],
            'unidade' => (string)($row['unidade_base'] ?? ''),
            'origem' => (string)($meta['origem_nome'] ?? '—'),
            'destino' => (string)($meta['destino_nome'] ?? '—'),
            'responsavel' => (string)($row['responsavel_nome'] ?? '—'),
            'ref' => (string)($meta['ref'] ?? ''),
        ];
    }
} catch (Throwable $e) {
    error_log('[FILIAIS][TRANSFERENCIAS] ' . $e->getMessage());
}

$totalFiliais = count($filiais);
$filiaisAtivas = 0;
$totalFuncionarios = 0;
$totalVendas30Dias = 0.0;
$totalAlertasEstoque = 0;
$cidades = [];

foreach ($filiais as &$filial) {
    $filialId = (int)$filial['id'];
    $filial['vendas_30d'] = (float)($vendasPorFilial[$filialId]['total'] ?? 0);
    $filial['num_vendas_30d'] = (int)($vendasPorFilial[$filialId]['num_vendas'] ?? 0);
    $filial['estoque_critico_total'] = (int)($estoquePorFilial[$filialId]['total_baixo'] ?? 0);
    $filial['estoque_critico_itens'] = $estoquePorFilial[$filialId]['itens'] ?? [];
    $filial['funcionarios_total'] = (int)($funcionariosPorFilial[$filialId]['total'] ?? 0);
    $filial['funcionarios_lista'] = $funcionariosPorFilial[$filialId]['funcionarios'] ?? [];
    $filial['status_normalizado'] = filiais_normalizar_status($filial['status'] ?? 'ATIVO');
    $filial['vendas_anterior'] = (float)($vendasAnteriorPorFilial[$filialId] ?? 0);
    $filial['hoje'] = $hojePorUnidade[$filialId] ?? null;

    if ($filial['status_normalizado'] === 'ATIVO') {
        $filiaisAtivas++;
    }

    if (!empty($filial['cidade'])) {
        $cidades[strtolower((string)$filial['cidade'])] = $filial['cidade'];
    }

    $totalFuncionarios += $filial['funcionarios_total'];
    $totalVendas30Dias += $filial['vendas_30d'];
    $totalAlertasEstoque += $filial['estoque_critico_total'];
}
unset($filial);

// A matriz também é uma unidade da rede: entra nos totais e no comparativo
$matrizUnidade = [
    'id' => $matrizId,
    'nome' => (string)($matriz['nome'] ?? 'Matriz'),
    'cidade' => (string)($matriz['cidade'] ?? ''),
    'is_matriz' => true,
    'status_normalizado' => 'ATIVO',
    'vendas_30d' => (float)($vendasPorFilial[$matrizId]['total'] ?? 0),
    'num_vendas_30d' => (int)($vendasPorFilial[$matrizId]['num_vendas'] ?? 0),
    'vendas_anterior' => (float)($vendasAnteriorPorFilial[$matrizId] ?? 0),
    'estoque_critico_total' => (int)($estoquePorFilial[$matrizId]['total_baixo'] ?? 0),
    'estoque_critico_itens' => $estoquePorFilial[$matrizId]['itens'] ?? [],
    'funcionarios_total' => (int)($funcionariosPorFilial[$matrizId]['total'] ?? 0),
    'funcionarios_lista' => $funcionariosPorFilial[$matrizId]['funcionarios'] ?? [],
    'hoje' => $hojePorUnidade[$matrizId] ?? null,
];
$totalFuncionarios += $matrizUnidade['funcionarios_total'];
$totalVendas30Dias += $matrizUnidade['vendas_30d'];
$totalAlertasEstoque += $matrizUnidade['estoque_critico_total'];

$unidadesRede = array_merge([$matrizUnidade], array_map(static function ($f) {
    $f['is_matriz'] = false;
    return $f;
}, $filiais));
foreach ($unidadesRede as &$un) {
    $un['ticket_medio'] = $un['num_vendas_30d'] > 0 ? $un['vendas_30d'] / $un['num_vendas_30d'] : 0.0;
    $un['variacao_pct'] = $un['vendas_anterior'] > 0 ? (($un['vendas_30d'] - $un['vendas_anterior']) / $un['vendas_anterior']) * 100 : null;
    $un['parte_pct'] = $totalVendas30Dias > 0 ? ($un['vendas_30d'] / $totalVendas30Dias) * 100 : 0.0;
}
unset($un);
usort($unidadesRede, static function ($a, $b) {
    return $b['vendas_30d'] <=> $a['vendas_30d'] ?: strcasecmp((string)$a['nome'], (string)$b['nome']);
});

if ($secao === 'dashboard' && ($_GET['exportar'] ?? '') === 'csv') {
    $nomeFicheiro = 'comparativo_rede_' . $periodoDias . 'd_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nomeFicheiro . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $csvLinha = static function (array $campos) use ($out): void {
        fputcsv($out, $campos, ';', '"', '\\');
    };
    $csvLinha(['Comparativo da rede', 'Últimos ' . $periodoDias . ' dias', 'Exportado em ' . date('d/m/Y H:i')]);
    $csvLinha([]);
    $csvLinha(['Posição', 'Unidade', 'Tipo', 'Cidade', 'Vendas pagas (MZN)', 'N.º de vendas', 'Ticket médio (MZN)', 'Período anterior (MZN)', 'Variação (%)', 'Parte da rede (%)', 'Equipa', 'Alertas de stock']);
    $pos = 0;
    foreach ($unidadesRede as $un) {
        $pos++;
        $csvLinha([
            $pos,
            $un['nome'],
            $un['is_matriz'] ? 'Matriz' : 'Filial',
            $un['cidade'] ?: '',
            number_format($un['vendas_30d'], 2, ',', ''),
            $un['num_vendas_30d'],
            number_format($un['ticket_medio'], 2, ',', ''),
            number_format($un['vendas_anterior'], 2, ',', ''),
            $un['variacao_pct'] === null ? '' : number_format($un['variacao_pct'], 1, ',', ''),
            number_format($un['parte_pct'], 1, ',', ''),
            $un['funcionarios_total'],
            $un['estoque_critico_total'],
        ]);
    }
    $csvLinha(['', 'Total da rede', '', '', number_format($totalVendas30Dias, 2, ',', ''), array_sum(array_column($unidadesRede, 'num_vendas_30d'))]);
    fclose($out);
    exit;
}

$limiteFiliais = plano_verificar_limite_db($matrizId, 'filiais', $totalFiliais);
$podeCriarFilial = ((int)$limiteFiliais['limite'] === -1) || ((int)$limiteFiliais['restante'] > 0);
$limiteTexto = ((int)$limiteFiliais['limite'] === -1) ? 'Ilimitadas' : ($totalFiliais . '/' . (int)$limiteFiliais['limite']);
$restantesTexto = ((int)$limiteFiliais['limite'] === -1) ? 'Ilimitado' : (string)(int)$limiteFiliais['restante'];
$cidadeCobertura = count($cidades);

// Inteligência da rede (dados já calculados acima)
$filiaisIntel = [
    'sem_vendas' => [],
    'com_alerta_estoque' => [],
    'sem_equipa' => [],
    'top' => null,
    'notas' => [],
];
foreach ($filiais as $fIntel) {
    $nomeF = (string)($fIntel['nome'] ?? 'Filial');
    if (($fIntel['status_normalizado'] ?? '') === 'ATIVO' && (float)($fIntel['vendas_30d'] ?? 0) <= 0.0001) {
        $filiaisIntel['sem_vendas'][] = $nomeF;
    }
    if ((int)($fIntel['estoque_critico_total'] ?? 0) > 0) {
        $filiaisIntel['com_alerta_estoque'][] = $nomeF . ' (' . (int)$fIntel['estoque_critico_total'] . ')';
    }
    if ((int)($fIntel['funcionarios_total'] ?? 0) <= 0) {
        $filiaisIntel['sem_equipa'][] = $nomeF;
    }
    if ($filiaisIntel['top'] === null
        || (float)$fIntel['vendas_30d'] > (float)$filiaisIntel['top']['vendas_30d']) {
        $filiaisIntel['top'] = $fIntel;
    }
}
foreach ($unidadesRede as $unTop) {
    if ($filiaisIntel['top'] === null || (float)$unTop['vendas_30d'] > (float)$filiaisIntel['top']['vendas_30d']) {
        $filiaisIntel['top'] = $unTop;
    }
}
if ($totalFiliais === 0) {
    $filiaisIntel['notas'][] = [
        'tom' => 'warn',
        'titulo' => 'Rede ainda sem unidades',
        'txt' => 'Crie a primeira filial para começar a comparar vendas, stock e equipa.',
        'cta' => null,
    ];
} else {
    if ($filiaisIntel['top'] && (float)$filiaisIntel['top']['vendas_30d'] > 0) {
        $filiaisIntel['notas'][] = [
            'tom' => 'ok',
            'titulo' => 'Melhor unidade (' . $periodoDias . ' dias): ' . (string)$filiaisIntel['top']['nome'],
            'txt' => number_format((float)$filiaisIntel['top']['vendas_30d'], 2, ',', '.') . ' MZN · ' . (int)$filiaisIntel['top']['num_vendas_30d'] . ' vendas · ' . (int)$filiaisIntel['top']['funcionarios_total'] . ' na equipa.',
            'cta' => 'dashboard',
        ];
    }
    if ($filiaisIntel['sem_vendas']) {
        $lista = array_slice($filiaisIntel['sem_vendas'], 0, 3);
        $filiaisIntel['notas'][] = [
            'tom' => 'warn',
            'titulo' => count($filiaisIntel['sem_vendas']) . ' filial(is) sem vendas em ' . $periodoDias . ' dias',
            'txt' => implode(', ', $lista) . (count($filiaisIntel['sem_vendas']) > 3 ? '…' : '') . '. Confirme se a unidade está a operar e a fechar contas.',
            'cta' => 'dashboard',
        ];
    }
    if ($totalAlertasEstoque > 0) {
        $lista = array_slice($filiaisIntel['com_alerta_estoque'], 0, 3);
        $filiaisIntel['notas'][] = [
            'tom' => 'warn',
            'titulo' => $totalAlertasEstoque . ' alerta(s) de stock na rede',
            'txt' => implode(', ', $lista) . (count($filiaisIntel['com_alerta_estoque']) > 3 ? '…' : '') . '. Transfira stock ou reponha nas unidades críticas.',
            'cta' => $temTransferenciaEstoqueFiliais ? 'estoque' : null,
        ];
    }
    if ($filiaisIntel['sem_equipa']) {
        $lista = array_slice($filiaisIntel['sem_equipa'], 0, 3);
        $filiaisIntel['notas'][] = [
            'tom' => 'info',
            'titulo' => count($filiaisIntel['sem_equipa']) . ' unidade(s) sem colaboradores',
            'txt' => implode(', ', $lista) . '. Associe utilizadores à filial para operação diária.',
            'cta' => 'funcionarios',
        ];
    }
    if (!$podeCriarFilial) {
        $filiaisIntel['notas'][] = [
            'tom' => 'info',
            'titulo' => 'Limite de filiais atingido',
            'txt' => 'Capacidade actual: ' . $limiteTexto . '. Desactive unidades antigas ou contacte suporte para expandir.',
            'cta' => null,
        ];
    }
}
$filiaisIntel['notas'] = array_slice($filiaisIntel['notas'], 0, 3);

// Ranking por receita (lista / dashboard)
usort($filiais, static function ($a, $b) {
    $va = (float)($a['vendas_30d'] ?? 0);
    $vb = (float)($b['vendas_30d'] ?? 0);
    if ($va === $vb) {
        return strcasecmp((string)($a['nome'] ?? ''), (string)($b['nome'] ?? ''));
    }
    return $vb <=> $va;
});
$rank = 0;
foreach ($filiais as &$fRank) {
    $rank++;
    $fRank['rank_30d'] = $rank;
}
unset($fRank);

$configJs = [
    'csrfToken' => $csrfToken,
    'createUrl' => 'api/filial_criar.php',
    'updateUrl' => 'api/filial_atualizar.php',
    'transferEnabled' => !empty($temTransferenciaEstoqueFiliais),
    'transferUrl' => 'api/estoque_transferir.php',
    'insumosUrl' => 'api/estoque_insumo_listar.php',
    'secao' => $secao,
];

$opcoesFiliaisTransferencia = [
    ['id' => 0, 'nome' => 'Stock central (matriz)'],
];
foreach ($filiais as $fOpt) {
    $opcoesFiliaisTransferencia[] = [
        'id' => (int)$fOpt['id'],
        'nome' => (string)$fOpt['nome'],
    ];
}

function filiais_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function filiais_moeda($valor): string
{
    return number_format((float)$valor, 2, ',', '.') . ' MZN';
}
?>
<!DOCTYPE html>
<html lang="pt">

<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Filiais - RestauranteSaaS</title>

    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link href="lib/bootstrap-5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/filiais.css?v=<?php echo (int)@filemtime(__DIR__ . "/css/filiais.css"); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="lib/fontawesome-6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/premium.css">
    <?php empresarial_head_assets(); ?>
</head>

<body class="<?php echo htmlspecialchars(empresarial_body_class('premium-ui')); ?>">
    <div class="container-fluid">
        <div class="row">
            <?php include_once __DIR__ . '/includes/sidebar.php'; ?>

            <main class="main-content filiais-page">
                <a href="dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Voltar </a>

                <section class="hero-card filiais-hero-emp">
                    <div class="hero-main">
                        <div>
                            <div class="filiais-exclusive-pill"><i class="fas fa-crown"></i> Rede Empresarial</div>
                            <h1>Gestão de Filiais <?php include __DIR__ . '/includes/plan_page_badge.php'; ?></h1>
                            <p>Cockpit da rede — compare unidades, stock crítico e equipa com os dados reais dos últimos <?php echo (int)$periodoDias; ?> dias.</p>
                            <div class="hero-meta">
                                <span class="hero-chip"><i class="fas fa-building"></i><?php echo filiais_h($matriz['nome'] ?? 'Matriz'); ?></span>
                                <span class="hero-chip"><i class="fas fa-sitemap"></i><?php echo filiais_h($limiteTexto); ?> filiais</span>
                                <span class="hero-chip"><i class="fas fa-globe"></i><?php echo (int)$cidadeCobertura; ?> cidades</span>
                                <?php if ($totalAlertasEstoque > 0): ?>
                                    <span class="hero-chip hero-chip-warn"><i class="fas fa-triangle-exclamation"></i><?php echo (int)$totalAlertasEstoque; ?> alertas stock</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="hero-actions">
                            <button type="button" class="btn-brand" data-bs-toggle="modal" data-bs-target="#modalAdicionarFilial" <?php echo $podeCriarFilial ? '' : 'disabled'; ?>>
                                <i class="fas fa-plus"></i> Nova Filial
                            </button>
                            <a href="filiais.php?secao=<?php echo filiais_h($secao); ?>" class="btn-ghost"><i class="fas fa-rotate"></i> Recalcular</a>
                        </div>
                    </div>
                </section>

                <?php if ($mensagem !== ''): ?>
                    <div class="alert alert-<?php echo filiais_h($tipoMsg); ?> mb-4"><?php echo $mensagem; ?></div>
                <?php endif; ?>

                <section class="stat-grid">
                    <article class="stats-card <?php echo $filiaisAtivas < $totalFiliais ? 'is-warn' : 'is-ok'; ?>">
                        <span class="stats-label">Filiais ativas</span>
                        <strong><?php echo (int)$filiaisAtivas; ?>/<?php echo (int)$totalFiliais; ?></strong>
                        <p><?php echo max(0, $totalFiliais - $filiaisAtivas); ?> inactivas</p>
                    </article>
                    <article class="stats-card">
                        <span class="stats-label">Equipa na rede</span>
                        <strong><?php echo (int)$totalFuncionarios; ?></strong>
                        <p><?php echo count($filiaisIntel['sem_equipa']); ?> unidade(s) sem staff</p>
                    </article>
                    <article class="stats-card <?php echo $totalVendas30Dias <= 0 && $totalFiliais > 0 ? 'is-warn' : 'is-ok'; ?>">
                        <span class="stats-label">Vendas pagas · <?php echo (int)$periodoDias; ?> dias (rede)</span>
                        <strong><?php echo filiais_h(filiais_moeda($totalVendas30Dias)); ?></strong>
                        <p><?php echo count($filiaisIntel['sem_vendas']); ?> sem vendas no período</p>
                    </article>
                    <article class="stats-card <?php echo $totalAlertasEstoque > 0 ? 'is-warn' : ''; ?>">
                        <span class="stats-label">Stock crítico</span>
                        <strong><?php echo (int)$totalAlertasEstoque; ?></strong>
                        <p><?php echo $podeCriarFilial ? ('Vagas: ' . filiais_h($restantesTexto)) : 'Limite de filiais cheio'; ?></p>
                    </article>
                </section>

                <?php if (!empty($filiaisIntel['notas'])): ?>
                    <section class="filiais-intel-notes" aria-label="Prioridades da rede">
                        <?php foreach ($filiaisIntel['notas'] as $nota): ?>
                            <article class="filiais-intel-note tom-<?php echo filiais_h($nota['tom'] ?? 'info'); ?>">
                                <div>
                                    <strong><?php echo filiais_h($nota['titulo'] ?? ''); ?></strong>
                                    <span><?php echo filiais_h($nota['txt'] ?? ''); ?></span>
                                </div>
                                <?php if (!empty($nota['cta'])): ?>
                                    <a href="filiais.php?secao=<?php echo filiais_h($nota['cta']); ?>">Abrir</a>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </section>
                <?php endif; ?>

                <section class="nav-grid">
                    <a href="filiais.php?secao=inicio&amp;periodo=<?php echo (int)$periodoDias; ?>" class="nav-card <?php echo $secao === 'inicio' ? 'active' : ''; ?>"><span>Minhas Filiais</span><small><?php echo (int)$totalFiliais; ?> unidades · ranking <?php echo (int)$periodoDias; ?> dias</small></a>
                    <?php if ($temComparativoFiliais): ?>
                    <a href="filiais.php?secao=dashboard&amp;periodo=<?php echo (int)$periodoDias; ?>" class="nav-card <?php echo $secao === 'dashboard' ? 'active' : ''; ?>"><span>Comparativo</span><small>Receita e equipa</small></a>
                    <?php endif; ?>
                    <?php if ($temTransferenciaEstoqueFiliais): ?>
                    <a href="filiais.php?secao=estoque&amp;periodo=<?php echo (int)$periodoDias; ?>" class="nav-card <?php echo $secao === 'estoque' ? 'active' : ''; ?>"><span>Estoque</span><small><?php echo (int)$totalAlertasEstoque; ?> alertas · transferir</small></a>
                    <?php endif; ?>
                    <a href="filiais.php?secao=funcionarios&amp;periodo=<?php echo (int)$periodoDias; ?>" class="nav-card <?php echo $secao === 'funcionarios' ? 'active' : ''; ?>"><span>Funcionários</span><small><?php echo (int)$totalFuncionarios; ?> na rede</small></a>
                </section>

                <?php include __DIR__ . '/includes/filiais_sections.php'; ?>
            </main>
        </div>
    </div>

    <div class="modal fade" id="modalAdicionarFilial" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus me-2 text-primary"></i>Nova Filial</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div id="alertFilialCriacao" class="alert d-none mx-3 mt-3 mb-0"></div>
                <form id="formNovaFilial">
                    <input type="hidden" name="_csrf" value="<?php echo filiais_h($csrfToken); ?>">
                    <div class="modal-body">
                        <label class="form-label" for="nome_filial">Nome da Filial *</label>
                        <input type="text" id="nome_filial" name="nome_filial" class="form-control mb-3" required placeholder="Nome da filial">
                        <label class="form-label" for="email_filial">Email</label>
                        <input type="email" id="email_filial" name="email_filial" class="form-control mb-3" placeholder="Email da filial">
                        <label class="form-label" for="telefone_filial">Telefone</label>
                        <input type="text" id="telefone_filial" name="telefone_filial" class="form-control mb-3" placeholder="Telefone">
                        <label class="form-label" for="endereco_filial">Endereco</label>
                        <input type="text" id="endereco_filial" name="endereco_filial" class="form-control mb-3" placeholder="Endereço">
                        <label class="form-label" for="cidade_filial">Cidade</label>
                        <input type="text" id="cidade_filial" name="cidade_filial" class="form-control" placeholder="Cidade">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" id="btnCriarFilial" class="btn btn-primary"><i class="fas fa-plus me-1"></i> Criar Filial</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalEditarFilial" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-pen me-2 text-primary"></i>Editar Filial</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div id="alertFilialEdicao" class="alert d-none mx-3 mt-3 mb-0"></div>
                <form id="formEditarFilial">
                    <input type="hidden" name="_csrf" value="<?php echo filiais_h($csrfToken); ?>">
                    <input type="hidden" id="edit_filial_id" name="filial_id" value="">
                    <div class="modal-body">
                        <label class="form-label" for="edit_nome_filial">Nome da Filial *</label>
                        <input type="text" id="edit_nome_filial" name="nome_filial" class="form-control mb-3" required placeholder="Nome da filial">
                        <label class="form-label" for="edit_email_filial">Email</label>
                        <input type="email" id="edit_email_filial" name="email_filial" class="form-control mb-3" placeholder="Email da filial">
                        <label class="form-label" for="edit_telefone_filial">Telefone</label>
                        <input type="text" id="edit_telefone_filial" name="telefone_filial" class="form-control mb-3" placeholder="Telefone">
                        <label class="form-label" for="edit_endereco_filial">Endereco</label>
                        <input type="text" id="edit_endereco_filial" name="endereco_filial" class="form-control mb-3" placeholder="Endereço">
                        <label class="form-label" for="edit_cidade_filial">Cidade</label>
                        <input type="text" id="edit_cidade_filial" name="cidade_filial" class="form-control mb-3" placeholder="Cidade">
                        <label class="form-label" for="edit_status_filial">Status</label>
                        <select id="edit_status_filial" name="status" class="form-select">
                            <option value="ATIVO">Ativo</option>
                            <option value="INATIVO">Inativo</option>
                        </select>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" id="btnSalvarFilial" class="btn btn-primary"><i class="fas fa-save me-1"></i> Salvar Alteracoes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
        window.FILIAIS_CONFIG = <?php echo json_encode($configJs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="lib/bootstrap-5.3.2/js/bootstrap.bundle.min.js"></script>
    <script src="js/filiais.js"></script>
</body>

</html>
