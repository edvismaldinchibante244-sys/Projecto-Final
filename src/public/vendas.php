<?php
require_once __DIR__ . '/app_base.php';

//Tela principal do PDV
// Este arquivo exibe o fluxo de vendas, pedidos abertos e histórico do dia.
// Comentários focados em manutenção e entendimento do fluxo.

// Proteção de acesso: só usuários autenticados
include_once APP_BASE . '/src/config/auth_check.php';
include_once APP_BASE . '/src/config/csrf.php';
// Conexão com banco de dados
include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/plano_check.php';
include_once APP_BASE . '/src/config/restaurante_context.php';
include_once APP_BASE . '/src/config/mesa_display_helper.php';
// Modelos principais
include_once APP_BASE . '/src/Model/Caixa.php';
include_once APP_BASE . '/src/Model/Produto.php';
include_once APP_BASE . '/src/Model/Mesa.php';
$csrfToken = csrf_get_token();

// Instancia conexão
$database = new Database();
$db = $database->getConnection();

// Instancia modelos
$caixa   = new Caixa($db);
$produto = new Produto($db);
$mesa    = new Mesa($db);
$restauranteFeatureId = session_restaurante_capability_id();
$restauranteFeatureId = $restauranteFeatureId > 0 ? $restauranteFeatureId : session_restaurante_contexto_id();
$temPedidosOnline = $restauranteFeatureId > 0 && plano_tem_funcionalidade_db($restauranteFeatureId, 'pedidos_online');
$temCaixa = $restauranteFeatureId > 0 && plano_tem_funcionalidade_db($restauranteFeatureId, 'caixa');
$temTelasBarCozinha = $restauranteFeatureId > 0 && plano_tem_funcionalidade_db($restauranteFeatureId, 'telas_bar_cozinha');
$fluxoPdvBasicoPagamento = !$temTelasBarCozinha;

$dadosPlanoVendas = plano_get_dados($restauranteFeatureId > 0 ? $restauranteFeatureId : (int)($_SESSION['restaurante_id'] ?? 0));
$plano_atual = plano_normalizar_nome($dadosPlanoVendas['plano_nome'] ?? 'BASICO');
$isEmpresarial = ($plano_atual === 'EMPRESARIAL');
$isEmpresarialUi = $isEmpresarial; // flag usada no HTML/CSS (sincronizada depois da sidebar)
require_once __DIR__ . '/includes/empresarial_ui.php';
$isEmpresarialUi = !empty($isEmpresarialUi) || $isEmpresarial;

// Verifica se há caixa aberto para o restaurante atual
$caixa_aberto = $caixa->buscarAberto($_SESSION['restaurante_id']);
$isProfissionalPdv = (($plano_atual ?? '') === 'PROFISSIONAL');

// Total ja cobrado neste caixa (Profissional; o Empresarial tem o mix completo).
$pdvTurno = ['total' => 0.0, 'qtd' => 0];
if ($isProfissionalPdv && !empty($caixa_aberto['id']) && isset($db) && $db instanceof PDO) {
    try {
        $stTurnoPdv = $db->prepare("
            SELECT COUNT(*) AS qtd, COALESCE(SUM(total_final), 0) AS total
            FROM vendas
            WHERE caixa_id = :cid AND restaurante_id = :rid
              AND UPPER(TRIM(COALESCE(status, ''))) = 'PAGO'
        ");
        $stTurnoPdv->execute([':cid' => (int)$caixa_aberto['id'], ':rid' => (int)$_SESSION['restaurante_id']]);
        $rTurnoPdv = $stTurnoPdv->fetch(PDO::FETCH_ASSOC) ?: [];
        $pdvTurno['total'] = (float)($rTurnoPdv['total'] ?? 0);
        $pdvTurno['qtd'] = (int)($rTurnoPdv['qtd'] ?? 0);
    } catch (Throwable $e) {
        // sem total do turno: o PDV continua a funcionar
    }
}

// Busca todos os produtos ativos para exibir no PDV
$stmt_produtos  = $produto->listar($_SESSION['restaurante_id']);
$todos_produtos = $stmt_produtos->fetchAll(PDO::FETCH_ASSOC);
$lista_produtos = [];
foreach ($todos_produtos as $_p) {
    if ($_p['ativo'] == 1) {
        $lista_produtos[] = $_p;
    }
}

// Com estoque inteligente: stock do PDV = porções possíveis pela ficha (insumos), não produtos.estoque.
$estoqueAvancadoPdv = $restauranteFeatureId > 0 && plano_tem_funcionalidade_db($restauranteFeatureId, 'estoque_avancado');
// Profissional: sem ficha/insumos = não vende (não entra no carrinho).
$exigeFichaCarrinhoPdv = $estoqueAvancadoPdv && ($plano_atual === 'PROFISSIONAL');
if ($estoqueAvancadoPdv && !empty($lista_produtos)) {
    try {
        require_once APP_BASE . '/src/config/estoque_inteligente.php';
        estoque_inteligente_schema_garantir($db);
        $filialIdPdv = null;
        if (function_exists('session_restaurante_auth_id')) {
            $authRidPdv = (int)session_restaurante_auth_id();
            if ($authRidPdv > 0 && $restauranteFeatureId > 0 && $authRidPdv !== $restauranteFeatureId) {
                $filialIdPdv = $authRidPdv;
            }
        }
        $idsPdv = array_map(static fn($p) => (int)($p['id'] ?? 0), $lista_produtos);
        $porcoesPdv = estoque_porcoes_disponiveis_por_produtos(
            $db,
            (int)$_SESSION['restaurante_id'],
            $filialIdPdv,
            $idsPdv
        );
        foreach ($lista_produtos as &$_pPdv) {
            $pidPdv = (int)($_pPdv['id'] ?? 0);
            if ($pidPdv <= 0) {
                continue;
            }
            if (array_key_exists($pidPdv, $porcoesPdv)) {
                $_pPdv['estoque'] = (int)$porcoesPdv[$pidPdv];
                $_pPdv['estoque_origem'] = 'ficha';
                $_pPdv['sem_ficha'] = false;
            } elseif ($exigeFichaCarrinhoPdv) {
                $_pPdv['estoque'] = 0;
                $_pPdv['estoque_origem'] = 'sem_ficha';
                $_pPdv['sem_ficha'] = true;
            }
        }
        unset($_pPdv);
    } catch (Throwable $e) {
        error_log('[vendas][estoque_ficha] ' . $e->getMessage());
    }
}

// Busca categorias de produtos
include_once APP_BASE . '/src/Model/Categoria.php';
$categoria = new Categoria($db);
$stmt_cat = $categoria->listar($_SESSION['restaurante_id']);
$lista_categorias = $stmt_cat->fetchAll(PDO::FETCH_ASSOC);

// Monta base URL para imagens dos produtos
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443 ? 'https://' : 'http://';
$base_url = $protocol . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '\/');
$base_url = $base_url . '/';

// Busca mesas disponíveis para seleção no carrinho
$stmt_mesas = $mesa->mesasLivres($_SESSION['restaurante_id']);
$lista_mesas = $stmt_mesas->fetchAll(PDO::FETCH_ASSOC);
$lista_mesas = array_values(array_filter($lista_mesas, static function ($m) {
    return !mesa_eh_balcao($m['numero'] ?? '');
}));

// Mesa Balcão / Take Away (para enviar à cozinha/bar sem mesa de salão)
$mesaBalcaoId = 0;
$mesaBalcaoLabel = '';
try {
    $mBal = mesa_garantir_balcao_canonico($db, (int)$_SESSION['restaurante_id']);
    $mesaBalcaoId = (int)($mBal['id'] ?? 0);
    $mesaBalcaoLabel = mesa_rotulo($mBal['numero'] ?? 'B1');
} catch (Throwable $e) {
    error_log('[vendas][mesa_balcao] ' . $e->getMessage());
}

// Segundo canal de balcão (Empresarial), quando activado em Mesas.
require_once APP_BASE . '/src/config/balcao_canal.php';
$balcao2Pdv = ['ativo' => false, 'nome' => '', 'mesa_id' => 0];
try {
    $balcao2Pdv = balcao2_config($db, (int)$_SESSION['restaurante_id'], $restauranteFeatureId);
} catch (Throwable $e) {
    error_log('[vendas][balcao2] ' . $e->getMessage());
}

function vendas_mesa_label_html($numero): string
{
    $numero = trim((string)$numero);
    if ($numero === '' || mesa_eh_balcao($numero)) {
        return '<span class="text-muted">Balcão</span>';
    }
    return htmlspecialchars(mesa_rotulo($numero), ENT_QUOTES, 'UTF-8');
}

// ===============================
// INTEGRAÇÃO COM PEDIDOS
// ===============================
// Busca pedidos abertos aguardando pagamento
// Exibir apenas pedidos PRONTO/ENTREGUE e sem itens pendentes (todos itens prontos ou entregues)

if ($fluxoPdvBasicoPagamento) {
    $stmt_abertos = $db->prepare(
        "SELECT p.id, p.numero_pedido, p.total, p.mesa_id,
                m.numero AS mesa_numero, p.cliente_nome, p.criado_em, p.status
         FROM pedidos p
         LEFT JOIN mesas m ON p.mesa_id = m.id
         WHERE p.restaurante_id = :rid
             AND (p.criado_em >= CURDATE() AND p.criado_em < CURDATE() + INTERVAL 1 DAY)
             AND p.status IN ('NOVO', 'PENDENTE', 'CONFIRMADO', 'PREPARANDO', 'ABERTO', 'EM_ANDAMENTO', 'PRONTO', 'ENTREGUE', 'CONTA', 'CONTA_SOLICITADA')
             AND EXISTS (SELECT 1 FROM itens_pedido ip WHERE ip.pedido_id = p.id)
         ORDER BY p.criado_em ASC"
    );
} else {
    $stmt_abertos = $db->prepare(
        "SELECT p.id, p.numero_pedido, p.total, p.mesa_id,
                m.numero AS mesa_numero, p.cliente_nome, p.criado_em, p.status
         FROM pedidos p
         LEFT JOIN mesas m ON p.mesa_id = m.id
         WHERE p.restaurante_id = :rid
             AND p.status IN ('PRONTO', 'ENTREGUE', 'CONTA', 'CONTA_SOLICITADA')
             AND NOT EXISTS (
                 SELECT 1 FROM itens_pedido ip
                 WHERE ip.pedido_id = p.id AND (ip.status = 'pendente' OR ip.status = 'em_preparo')
             )
         ORDER BY p.criado_em ASC"
    );
}
$stmt_abertos->execute([':rid' => $_SESSION['restaurante_id']]);
$pedidos_aguardando = $stmt_abertos->fetchAll(PDO::FETCH_ASSOC);

// Busca resumo e histórico das vendas do dia
$stmt_vh = $db->prepare(
    "SELECT v.id, v.numero_fatura, v.total_final, v.forma_pagamento, v.criado_em,
            u.nome AS usuario_nome, m.numero AS mesa_numero
     FROM vendas v
     LEFT JOIN usuarios u ON v.usuario_id = u.id
     LEFT JOIN mesas m ON v.mesa_id = m.id
     WHERE v.restaurante_id = :rid AND (v.criado_em >= CURDATE() AND v.criado_em < CURDATE() + INTERVAL 1 DAY)
     ORDER BY v.criado_em DESC
     LIMIT 25"
);
$stmt_vh->execute([':rid' => $_SESSION['restaurante_id']]);
$vendas_hoje = $stmt_vh->fetchAll(PDO::FETCH_ASSOC);
$total_hoje   = array_sum(array_column($vendas_hoje, 'total_final'));
$ticketMedio = !empty($vendas_hoje) ? ($total_hoje / count($vendas_hoje)) : 0.0;
$ridVendas = (int)($_SESSION['restaurante_id'] ?? 0);

$empPdv = [
    'pedidos_fluxo' => 0,
    'sem_estoque' => 0,
    'baixo_estoque' => 0,
    'mix_pagamento' => [],
    'top_produtos' => [],
    'vendas_por_produto' => [],
    'ultima_venda' => !empty($vendas_hoje[0]['criado_em']) ? date('H:i', strtotime((string)$vendas_hoje[0]['criado_em'])) : null,
];

foreach ($lista_produtos as $_pEst) {
    $est = (int)($_pEst['estoque'] ?? 0);
    if ($est <= 0) {
        $empPdv['sem_estoque']++;
    } elseif ($est <= 5) {
        $empPdv['baixo_estoque']++;
    }
}

$empPdv['cat_risco'] = [];
$empPdv['notas'] = [];
$empPdv['hora_pico'] = '—';
$empPdv['digital_pct'] = 0.0;

try {
    $stmtFluxo = $db->prepare(
        "SELECT COUNT(*) FROM pedidos
         WHERE restaurante_id = :rid
           AND (criado_em >= CURDATE() AND criado_em < CURDATE() + INTERVAL 1 DAY)
           AND status IN ('NOVO','PENDENTE','CONFIRMADO','PREPARANDO','ABERTO','EM_ANDAMENTO','PRONTO','ENTREGUE','CONTA','CONTA_SOLICITADA')"
    );
    $stmtFluxo->execute([':rid' => $ridVendas]);
    $empPdv['pedidos_fluxo'] = (int)$stmtFluxo->fetchColumn();
} catch (Throwable $e) {
    $empPdv['pedidos_fluxo'] = 0;
}

// Dados inteligentes do PDV (sempre preparar quando o plano é Empresarial)
if ($isEmpresarial || !empty($isEmpresarialUi)) {
    // Risco por categoria (stock)
    foreach ($lista_produtos as $_pCat) {
        $catNome = trim((string)($_pCat['categoria_nome'] ?? $_pCat['categoria'] ?? ''));
        if ($catNome === '') {
            $catNome = 'Geral';
        }
        if (!isset($empPdv['cat_risco'][$catNome])) {
            $empPdv['cat_risco'][$catNome] = ['out' => 0, 'low' => 0, 'total' => 0];
        }
        $empPdv['cat_risco'][$catNome]['total']++;
        $estC = (int)($_pCat['estoque'] ?? 0);
        if ($estC <= 0) {
            $empPdv['cat_risco'][$catNome]['out']++;
        } elseif ($estC <= 5) {
            $empPdv['cat_risco'][$catNome]['low']++;
        }
    }

    try {
        $stmtMix = $db->prepare(
            "SELECT forma_pagamento, COUNT(*) AS qtd, COALESCE(SUM(total_final), 0) AS total
             FROM vendas
             WHERE restaurante_id = :rid AND (criado_em >= CURDATE() AND criado_em < CURDATE() + INTERVAL 1 DAY)
               AND UPPER(TRIM(COALESCE(status,''))) = 'PAGO'
             GROUP BY forma_pagamento
             ORDER BY total DESC"
        );
        $stmtMix->execute([':rid' => $ridVendas]);
        $empPdv['mix_pagamento'] = $stmtMix->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $digitalTotal = 0.0;
        $mixSum = 0.0;
        foreach ($empPdv['mix_pagamento'] as $mx) {
            $t = (float)($mx['total'] ?? 0);
            $mixSum += $t;
            $fp = strtoupper(trim((string)($mx['forma_pagamento'] ?? '')));
            if ($fp !== 'DINHEIRO' && $fp !== '') {
                $digitalTotal += $t;
            }
        }
        $empPdv['digital_pct'] = $mixSum > 0 ? (($digitalTotal / $mixSum) * 100) : 0.0;
    } catch (Throwable $e) {
        $empPdv['mix_pagamento'] = [];
    }

    try {
        $stmtTop = $db->prepare(
            "SELECT p.id AS produto_id, p.nome, COALESCE(SUM(iv.quantidade), 0) AS qtd
             FROM itens_venda iv
             INNER JOIN vendas v ON v.id = iv.venda_id
             INNER JOIN produtos p ON p.id = iv.produto_id
             WHERE v.restaurante_id = :rid AND (v.criado_em >= CURDATE() AND v.criado_em < CURDATE() + INTERVAL 1 DAY)
               AND UPPER(TRIM(COALESCE(v.status,''))) = 'PAGO'
             GROUP BY p.id, p.nome
             ORDER BY qtd DESC
             LIMIT 8"
        );
        $stmtTop->execute([':rid' => $ridVendas]);
        $empPdv['top_produtos'] = $stmtTop->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($empPdv['top_produtos'] as $tp) {
            $empPdv['vendas_por_produto'][(int)$tp['produto_id']] = (int)$tp['qtd'];
        }
    } catch (Throwable $e) {
        // fallback sem filtro PAGO / nome de tabela alternativo
        try {
            $stmtTop2 = $db->prepare(
                "SELECT p.id AS produto_id, p.nome, COALESCE(SUM(iv.quantidade), 0) AS qtd
                 FROM itens_venda iv
                 INNER JOIN vendas v ON v.id = iv.venda_id
                 INNER JOIN produtos p ON p.id = iv.produto_id
                 WHERE v.restaurante_id = :rid AND (v.criado_em >= CURDATE() AND v.criado_em < CURDATE() + INTERVAL 1 DAY)
                 GROUP BY p.id, p.nome
                 ORDER BY qtd DESC
                 LIMIT 8"
            );
            $stmtTop2->execute([':rid' => $ridVendas]);
            $empPdv['top_produtos'] = $stmtTop2->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($empPdv['top_produtos'] as $tp) {
                $empPdv['vendas_por_produto'][(int)$tp['produto_id']] = (int)$tp['qtd'];
            }
        } catch (Throwable $e2) {
            $empPdv['top_produtos'] = [];
        }
    }

    try {
        $stmtPico = $db->prepare(
            "SELECT HOUR(criado_em) AS h, COUNT(*) AS qtd
             FROM vendas
             WHERE restaurante_id = :rid AND (criado_em >= CURDATE() AND criado_em < CURDATE() + INTERVAL 1 DAY)
             GROUP BY HOUR(criado_em)
             ORDER BY qtd DESC
             LIMIT 1"
        );
        $stmtPico->execute([':rid' => $ridVendas]);
        $picoRow = $stmtPico->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($picoRow) {
            $empPdv['hora_pico'] = sprintf('%02d:00', (int)$picoRow['h']);
        }
    } catch (Throwable $ePico) {
        $empPdv['hora_pico'] = '—';
    }

    $notasPdv = [];
    if ((int)$empPdv['sem_estoque'] > 0) {
        $notasPdv[] = [
            'tom' => 'warn',
            'chave' => 'stock_out',
            'titulo' => (int)$empPdv['sem_estoque'] . ' produto(s) sem stock no PDV',
            'txt' => 'Use o filtro “Sem stock”. Evite vender o que a ficha já não cobre.',
        ];
    }
    if ((int)$empPdv['baixo_estoque'] > 0) {
        $notasPdv[] = [
            'tom' => 'info',
            'titulo' => (int)$empPdv['baixo_estoque'] . ' com stock baixo (≤5)',
            'txt' => 'Repor antes do pico — no filtro “Baixo” vê a lista.',
        ];
    }
    if (count($pedidos_aguardando) > 0) {
        $notasPdv[] = [
            'tom' => 'warn',
            'titulo' => count($pedidos_aguardando) . ' pedido(s) a receber',
            'txt' => 'Há contas prontas na aba “A receber”. Não deixe o cliente à espera.',
        ];
    }
    if ((int)$empPdv['pedidos_fluxo'] >= 5) {
        $notasPdv[] = [
            'tom' => 'info',
            'titulo' => 'Salão/cozinha com ' . (int)$empPdv['pedidos_fluxo'] . ' em fluxo',
            'txt' => 'Priorize receber o que já está pronto e mantenha o PDV rápido.',
        ];
    }
    if ($empPdv['hora_pico'] !== '—' && count($vendas_hoje) > 0) {
        $notasPdv[] = [
            'tom' => 'info',
            'titulo' => 'Pico de vendas às ' . $empPdv['hora_pico'],
            'txt' => 'É o horário com mais movimentos hoje. Tenha troco e stock preparados.',
        ];
    }
    if ($empPdv['digital_pct'] >= 40 && $total_hoje > 0) {
        $notasPdv[] = [
            'tom' => 'ok',
            'titulo' => number_format((float)$empPdv['digital_pct'], 0, ',', '.') . '% digital hoje',
            'txt' => 'Menos pressão na gaveta. Confirme sempre o comprovativo no fecho.',
        ];
    }
    if (empty($vendas_hoje) && empty($pedidos_aguardando)) {
        $notasPdv[] = [
            'tom' => 'ok',
            'titulo' => 'PDV pronto — ainda sem vendas hoje',
            'txt' => 'Escolha produtos, confirme mesa/balcão e receba. Os insights enchem com o movimento.',
        ];
    }
    if (empty($notasPdv)) {
        $notasPdv[] = [
            'tom' => 'ok',
            'titulo' => 'Operação sob controlo',
            'txt' => 'Continue a receber e a acompanhar stock baixo no catálogo.',
        ];
    }
    $empPdv['notas'] = array_slice($notasPdv, 0, 3);

    usort($lista_produtos, static function ($a, $b) use ($empPdv) {
        $ea = (int)($a['estoque'] ?? 0) > 0 ? 0 : 1;
        $eb = (int)($b['estoque'] ?? 0) > 0 ? 0 : 1;
        if ($ea !== $eb) {
            return $ea <=> $eb;
        }
        $va = (int)($empPdv['vendas_por_produto'][(int)($a['id'] ?? 0)] ?? 0);
        $vb = (int)($empPdv['vendas_por_produto'][(int)($b['id'] ?? 0)] ?? 0);
        if ($va !== $vb) {
            return $vb <=> $va;
        }
        return strcasecmp((string)($a['nome'] ?? ''), (string)($b['nome'] ?? ''));
    });
}
?>
<?php
if (!function_exists('pdv_img_iniciais')) {
    /** Imagem de recurso com as iniciais do produto (gerada localmente, sem internet). */
    function pdv_img_iniciais(string $nome): string
    {
        $ini = '';
        foreach (preg_split('/\s+/u', trim($nome)) ?: [] as $parte) {
            if ($parte !== '' && mb_strlen($ini) < 2) {
                $ini .= mb_strtoupper(mb_substr($parte, 0, 1));
            }
        }
        $ini = $ini !== '' ? $ini : 'P';
        $cores = ['#ea580c', '#0f766e', '#2563eb', '#7c3aed', '#be123c', '#a16207', '#0e7490', '#4d7c0f'];
        $cor = $cores[abs(crc32($nome)) % count($cores)];
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 256 256"><rect width="256" height="256" fill="' . $cor . '"/>'
            . '<text x="50%" y="50%" dy=".35em" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="96" font-weight="700" fill="#ffffff">'
            . htmlspecialchars($ini, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</text></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
?>
<!DOCTYPE html>
<html lang="pt">

<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PDV - RestauranteSaaS</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link href="lib/bootstrap-5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="lib/fontawesome-6.5.1/css/all.min.css">
    <style>
        .menu-toggle-btn {
            display: none;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: #fff;
            border: none;
            border-radius: 16px;
            width: 46px;
            height: 46px;
            font-size: 18px;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 12px 26px rgba(255, 107, 53, 0.24);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .menu-toggle-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 16px 30px rgba(255, 107, 53, 0.3);
        }

        :root {
            --primary: #FF6B35;
            --primary-dark: #e55a2b;
            --secondary: #F7931E;
            --dark: #0f0f23;
            --dark-2: #1a1a2e;
            --dark-3: #16213e;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --text: #1e293b;
            --text-light: #64748b;
            --text-muted: #94a3b8;
            --bg: #f8fafc;
            --border: #e2e8f0;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Outfit', 'Segoe UI', 'Noto Sans', system-ui, -apple-system, 'Helvetica Neue', Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            overflow-x: hidden;
        }

        body.sidebar-mobile-open {
            overflow: hidden;
        }

        .container-fluid,
        .container-fluid > .row {
            min-height: 100vh;
        }

        .sidebar-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.58);
            backdrop-filter: blur(4px);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s ease;
            z-index: 1090;
        }

        body.sidebar-mobile-open .sidebar-backdrop {
            opacity: 1;
            pointer-events: auto;
        }

        .sidebar {
            width: 280px;
            min-height: 100vh;
            background: linear-gradient(180deg, var(--dark) 0%, var(--dark-2) 50%, var(--dark-3) 100%);
            position: fixed;
            left: 0;
            top: 0;
            z-index: 1000;
            padding: 0;
            display: flex;
            flex-direction: column;
        }

        .sidebar-brand {
            padding: 28px 24px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            background: linear-gradient(135deg, rgba(255, 107, 53, 0.15), rgba(247, 147, 30, 0.05));
        }

        .sidebar-brand h2 {
            color: white;
            font-family: 'Space Grotesk', 'Segoe UI Semibold', 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
            font-size: 20px;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-brand h2 i {
            color: var(--primary);
            font-size: 24px;
        }

        .sidebar-brand span {
            display: block;
            color: var(--text-muted);
            font-size: 11px;
            margin-top: 4px;
            margin-left: 36px;
            letter-spacing: 0.5px;
        }

        .sidebar-menu {
            flex: 1;
            padding: 20px 12px;
            overflow-y: auto;
            overflow-x: hidden;
            min-height: 0;
            padding-bottom: 12px;
        }

        .menu-title {
            color: var(--text-muted);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            padding: 12px 16px 8px;
            font-weight: 600;
        }

        .menu-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 18px;
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            border-radius: 12px;
            margin-bottom: 4px;
            transition: all 0.3s;
            cursor: pointer;
            font-weight: 500;
            font-size: 14px;
        }

        .menu-item:hover {
            background: rgba(255, 255, 255, 0.08);
            color: white;
            transform: translateX(4px);
        }

        .menu-item.active {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            box-shadow: 0 4px 15px rgba(255, 107, 53, 0.4);
        }

        .menu-item i {
            width: 20px;
            text-align: center;
            font-size: 16px;
        }

        .sidebar-footer {
            padding: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
        }

        .user-avatar {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 16px;
        }

        .user-details {
            flex: 1;
        }

        .user-name {
            color: white;
            font-weight: 600;
            font-size: 14px;
        }

        .user-role {
            color: var(--text-muted);
            font-size: 12px;
        }

        .main-content {
            margin-left: 280px;
            padding: 0;
            min-height: 100vh;
            background: var(--bg);
        }

        .top-bar {
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(14px);
            padding: 18px 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .top-bar-main {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
        }

        .top-bar-status {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            flex-wrap: wrap;
            gap: 12px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 42px;
            padding: 10px 16px;
            border-radius: 999px;
            font-size: 0.92rem;
            font-weight: 700;
            color: #fff;
            white-space: nowrap;
        }

        .status-badge.success {
            background: linear-gradient(135deg, var(--success), #20c997);
            box-shadow: 0 12px 24px rgba(16, 185, 129, 0.18);
        }

        .status-badge.danger {
            background: linear-gradient(135deg, var(--danger), #f87171);
            box-shadow: 0 12px 24px rgba(239, 68, 68, 0.16);
        }

        /* Profissional: aviso de pouco stock no botao do produto. */
        .produto-btn.is-low { border-color: #f59e0b; }
        .produto-btn .pdv-pro-low {
            position: absolute;
            top: 6px;
            right: 6px;
            background: #fef3c7;
            color: #92400e;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 999px;
        }
        .pdv-turno-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            border-radius: 999px;
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #065f46;
            font-size: 13px;
        }
        .pdv-turno-chip strong { color: #047857; }

        .page-title {
            font-family: 'Space Grotesk', 'Segoe UI Semibold', 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
            font-size: 24px;
            font-weight: 700;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-title i {
            color: var(--primary);
        }

        .content-area {
            padding: 32px;
        }

        .card {
            border: 1px solid rgba(226, 232, 240, 0.86);
            border-radius: 24px;
            box-shadow: 0 16px 36px rgba(15, 23, 42, 0.08);
            overflow: hidden;
        }

        .search-panel.card {
            overflow: visible;
            height: auto;
            min-height: 0;
        }

        /* Evita o catálogo esticar até à altura do carrinho (espaço branco) */
        .content-area > .row.g-4 {
            align-items: flex-start;
        }

        .content-area > .row.g-4 > [class*="col-"] {
            align-self: flex-start;
        }

        .content-area .card .text-center.py-5 {
            padding-top: 1.25rem !important;
            padding-bottom: 1.25rem !important;
        }

        #carrinhoItens {
            min-height: 80px !important;
        }

        .table-responsive {
            scrollbar-width: thin;
        }

        .table th,
        .table td {
            vertical-align: middle;
        }

        .produto-btn {
            background: white;
            border: 2px solid var(--border);
            border-radius: 16px;
            padding: 20px 15px;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
            position: relative;
        }

        .produto-btn:hover {
            border-color: var(--primary);
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(255, 107, 53, 0.2);
        }

        .produto-btn .icon {
            width: 60px;
            height: 60px;
            margin: 0 auto 10px;
            border-radius: 12px;
            object-fit: cover;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            background: linear-gradient(135deg, #fff3e0, #ffe0b2);
            overflow: hidden;
        }

        .produto-btn .icon img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .produto-btn .nome {
            font-weight: 600;
            font-size: 14px;
            color: var(--text);
            margin-bottom: 4px;
        }

        .produto-btn .preco {
            color: var(--primary);
            font-weight: 700;
            font-size: 16px;
        }

        .carrinho-card {
            background: white;
            border-radius: 20px;
            box-shadow: var(--shadow-lg);
            padding: 24px;
            position: sticky;
            top: 100px;
        }

        .total-box {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border-radius: 16px;
            padding: 20px;
            text-align: center;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(255, 107, 53, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 107, 53, 0.4);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), #20c997);
            border: none;
            padding: 14px 24px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 16px;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
        }

        .btn-danger {
            background: var(--danger);
            border: none;
            border-radius: 12px;
            padding: 12px 24px;
            font-weight: 600;
        }

        .btn-outline-danger {
            border: 2px solid var(--danger);
            color: var(--danger);
            background: transparent;
            border-radius: 12px;
            padding: 12px 24px;
            font-weight: 600;
        }

        .btn-outline-danger:hover {
            background: var(--danger);
            color: white;
        }

        .form-control,
        .form-select {
            padding: 12px 16px;
            border-radius: 12px;
            border: 2px solid var(--border);
            font-size: 14px;
            transition: all 0.3s;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(255, 107, 53, 0.1);
        }

        @media (max-width: 992px) {
            .menu-toggle-btn {
                display: inline-flex;
            }

            .sidebar {
                width: min(320px, calc(100vw - 24px)) !important;
                max-width: calc(100vw - 24px);
                height: 100dvh !important;
                min-height: 100dvh !important;
                left: 12px !important;
                top: 12px !important;
                transform: translateX(-120%);
                transition: transform 0.28s ease;
                z-index: 1100 !important;
                border-radius: 28px;
                overflow: hidden;
                box-shadow: 0 24px 60px rgba(15, 23, 42, 0.38);
            }

            body.sidebar-mobile-open .sidebar {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                width: 100%;
                max-width: 100%;
            }

            .top-bar {
                padding: 16px 20px;
                gap: 14px;
                flex-wrap: wrap;
            }

            .top-bar-status {
                width: 100%;
                justify-content: flex-start;
            }

            .content-area {
                padding: 20px;
            }

            .produto-btn {
                padding: 16px 12px;
            }

            .produto-btn .icon {
                width: 54px;
                height: 54px;
                font-size: 24px;
            }

            .produto-btn .nome {
                font-size: 13px;
            }

            .produto-btn .preco {
                font-size: 15px;
            }

            .carrinho-card {
                position: static;
                top: auto;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                width: 100%;
                max-width: 100%;
            }

            .content-area {
                padding: 16px;
            }

            .top-bar {
                padding: 14px 16px;
                gap: 10px;
            }

            .top-bar-main {
                width: 100%;
                align-items: center;
            }

            .top-bar-status {
                width: 100%;
            }

            .page-title {
                font-size: 18px;
            }

            .row.g-4 > [class*="col-"] {
                width: 100%;
                max-width: 100%;
                flex: 0 0 100%;
            }

            .carrinho-card {
                padding: 20px;
                position: static;
            }

            .carrinho-card h4 {
                font-size: 18px;
            }

            .produto-btn {
                padding: 15px 10px;
                border-radius: 14px;
            }

            .produto-btn .icon {
                width: 50px;
                height: 50px;
                font-size: 22px;
            }

            .produto-btn .preco {
                font-size: 14px;
            }

            .card-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .card-header .btn,
            .card-header span[style] {
                width: 100%;
                text-align: center;
            }
        }

        @media (max-width: 576px) {
            .top-bar {
                padding: 12px 14px;
            }

            .menu-toggle-btn {
                width: 42px;
                height: 42px;
                border-radius: 14px;
            }

            .page-title {
                font-size: 17px;
                gap: 8px;
            }

            .content-area {
                padding: 12px;
            }

            .content-area .card {
                border-radius: 16px;
            }

            .card.p-4 {
                padding: 16px !important;
            }

            .produto-item {
                padding-left: 4px;
                padding-right: 4px;
            }

            .produto-btn {
                padding: 12px 10px;
            }

            .produto-btn .icon {
                width: 46px;
                height: 46px;
                font-size: 20px;
            }

            .produto-btn .nome {
                font-size: 12px;
            }

            .produto-btn .preco {
                font-size: 13px;
            }

            .carrinho-card {
                padding: 16px;
                border-radius: 16px;
            }

            .total-box {
                padding: 16px;
            }

            #total {
                font-size: 24px !important;
            }

            #carrinhoItens {
                max-height: 240px !important;
            }

            .carrinho-card .d-flex.justify-content-between.align-items-center.mb-3 {
                flex-direction: column;
                align-items: stretch !important;
                gap: 8px;
            }

            .carrinho-card #desconto {
                width: 100% !important;
            }

            .card-header h5 {
                font-size: 16px;
            }

            .card-header .btn {
                width: 100%;
            }

            .status-badge {
                width: 100%;
                white-space: normal;
                border-radius: 16px;
                justify-content: center;
                text-align: center;
            }

            .sidebar {
                left: 8px !important;
                top: 8px !important;
                width: calc(100vw - 16px) !important;
                max-width: calc(100vw - 16px);
                border-radius: 24px;
            }
        }
    </style>
    <link rel="stylesheet" href="css/premium.css">
    <?php empresarial_head_assets(); ?>
    <style>
        /* ---- PDV Empresarial (nov 2026) ---- */
        @media (min-width: 992px) {
            body.plano-empresarial > .container-fluid > .row > .main-content { margin-left: 280px !important; width: calc(100% - 280px) !important; max-width: none !important; flex: none !important; }
        }
        body.plano-empresarial.sidebar-desktop-collapsed > .container-fluid > .row > .main-content { margin-left: 0 !important; width: 100% !important; }
        body.plano-empresarial .pdv-emp-panel .card-header,
        body.plano-empresarial .pdv-emp-panel .card-header h5 { color: #9a3412 !important; }
        body.plano-empresarial .pdv-emp-panel .card-header .badge { background: #fed7aa !important; color: #9a3412 !important; }
        body.plano-empresarial .pdv-emp-panel .card-header .btn { color: #9a3412 !important; border-color: #fdba74 !important; background: #fff !important; }
        body.plano-empresarial .pdv-emp-foto { width: 100%; aspect-ratio: 4 / 3; border-radius: 12px; overflow: hidden; margin-bottom: 8px; background: #f1f5f9; }
        body.plano-empresarial .pdv-emp-foto img { width: 100%; height: 100%; object-fit: cover; display: block; }
        body.plano-empresarial .produto-btn.is-out .pdv-emp-foto { filter: grayscale(.8); opacity: .6; }
        body.plano-empresarial .pdv-emp-stock-row span { white-space: nowrap; }
        body.plano-empresarial .pdv-emp-oculto { position: absolute !important; width: 1px !important; height: 1px !important; opacity: 0 !important; pointer-events: none !important; }
        body.plano-empresarial .pdv-emp-pay-btns { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }
        body.plano-empresarial .pdv-emp-pay-btns button {
            display: flex; flex-direction: column; align-items: center; gap: 3px; padding: 9px 4px; min-height: 56px;
            border: 1.5px solid #e2e8f0; border-radius: 12px; background: #fff; color: #334155; font-weight: 700; font-size: 12.5px;
        }
        body.plano-empresarial .pdv-emp-pay-btns button i { font-size: 16px; color: #64748b; }
        body.plano-empresarial .pdv-emp-pay-btns button:hover { border-color: #fdba74; }
        body.plano-empresarial .pdv-emp-pay-btns button.is-active { border-color: #ea580c; background: #fff7ed; color: #9a3412; box-shadow: 0 0 0 3px rgba(234,88,12,.12); }
        body.plano-empresarial .pdv-emp-pay-btns button.is-active i { color: #ea580c; }
        body.plano-empresarial .pdv-emp-troco-rapido { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
        body.plano-empresarial .pdv-emp-troco-rapido button { flex: 1 1 auto; padding: 7px 10px; border-radius: 10px; border: 1px solid #d1fae5; background: #ecfdf5; color: #047857; font-weight: 700; font-size: 13px; }
        body.plano-empresarial .pdv-emp-troco-rapido button:first-child { background: #047857; color: #fff; border-color: #047857; }
        /* Carrinho compacto e sempre visível */
        /* overflow-x: clip (em vez de hidden) para os botões fixos ao fundo funcionarem */
        html:has(body.plano-empresarial), body.plano-empresarial { overflow-x: clip !important; overflow-y: visible !important; }
        /* Sem barra de deslocamento no carrinho: os botões de pagar ficam presos ao fundo do ecrã */
        body.plano-empresarial .pdv-emp-workspace .pdv-emp-cart { position: relative !important; top: auto !important; max-height: none !important; overflow: visible !important; }
        body.plano-empresarial .pdv-emp-cart .mb-3 { margin-bottom: .6rem !important; }
        body.plano-empresarial .pdv-emp-cart .pdv-emp-total-box { padding-top: 10px !important; padding-bottom: 10px !important; }
        body.plano-empresarial .pdv-emp-cart #pdvPayHint,
        body.plano-empresarial .pdv-emp-cart #pdvMesaMeta { display: none; }
        body.plano-empresarial .pdv-emp-cart .pdv-emp-cart-field .form-label, body.plano-empresarial .pdv-emp-cart label.form-label { margin-bottom: 4px; }
        body.plano-empresarial .pdv-emp-cart #carrinhoTrocoBox { padding: 8px 12px !important; }
        body.plano-empresarial .pdv-emp-cart .pdv-emp-cart-hint { padding: 8px 10px; font-size: 12px; }
        body.plano-empresarial .pdv-emp-cart #pdvModeBalcaoActions,
        body.plano-empresarial .pdv-emp-cart #pdvModeMesaActions,
        body.plano-empresarial .pdv-emp-cart #btnFinalizarVenda {
            position: sticky; bottom: 0; z-index: 3; background: #fff;
            padding-top: 8px; box-shadow: 0 -10px 14px -10px rgba(15,23,42,.18);
        }
        body.plano-empresarial .pdv-emp-cart #btnPagarEnviar, body.plano-empresarial .pdv-emp-cart #btnFinalizarVenda { padding-top: .8rem !important; padding-bottom: .8rem !important; }
    </style>
</head>

<body class="<?php echo htmlspecialchars(empresarial_body_class('premium-ui')); ?>">
    <div class="container-fluid">
        <div class="row">
            <?php include_once __DIR__ . '/includes/sidebar.php'; ?>
            <?php
            // Sidebar é a fonte de verdade do plano — realinha a UI Empresarial
            $plano_atual = plano_normalizar_nome((string)($plano_atual ?? 'BASICO'));
            $isEmpresarial = ($plano_atual === 'EMPRESARIAL');
            $isEmpresarialUi = $isEmpresarial;
            if ($isEmpresarialUi) {
                echo '<script nonce="' . (function_exists('security_csp_nonce') ? security_csp_nonce() : '') . '">document.body.classList.add("plano-empresarial");</script>';
                echo '<link rel="stylesheet" href="css/empresarial.css?v=20260726af">' . "\n";
            }
            ?>
            <div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div>

            <main class="main-content col-md-9 ms-sm-auto col-lg-10">
                <div class="top-bar">
                    <div class="top-bar-main">
                        <h1 class="page-title mb-0"><i class="fas fa-cash-register"></i> PDV - Ponto de Venda <?php include __DIR__ . '/includes/plan_page_badge.php'; ?></h1>
                    </div>
                    <div class="top-bar-status">
                        <?php if ($isProfissionalPdv && $caixa_aberto): ?>
                            <span class="pdv-turno-chip" title="Já cobrado neste caixa">
                                <i class="fas fa-sack-dollar"></i>
                                <span><strong><?php echo number_format($pdvTurno['total'], 2, ',', '.'); ?> MZN</strong> neste turno · <?php echo (int)$pdvTurno['qtd']; ?> venda(s)</span>
                            </span>
                        <?php endif; ?>
                        <?php if ($caixa_aberto): ?>
                            <span class="status-badge success"><i class="fas fa-circle-check"></i> Caixa aberto desde <?php echo date('H:i', strtotime($caixa_aberto['data_abertura'])); ?></span>
                        <?php else: ?>
                            <span class="status-badge danger"><i class="fas fa-lock"></i> Caixa fechado</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="content-area">
                    <?php if (!empty($isEmpresarialUi)): ?>
                        <div class="pdv-emp-kpis">
                            <div class="pdv-emp-kpi kpi-orange">
                                <span class="label">Vendas hoje</span>
                                <strong><?php echo number_format($total_hoje, 2, ',', '.'); ?> <small>MZN</small></strong>
                            </div>
                            <div class="pdv-emp-kpi kpi-blue">
                                <span class="label">Em fluxo</span>
                                <strong><?php echo (int)$empPdv['pedidos_fluxo']; ?></strong>
                            </div>
                            <div class="pdv-emp-kpi kpi-amber">
                                <span class="label">A receber</span>
                                <strong><?php echo count($pedidos_aguardando); ?></strong>
                            </div>
                            <div class="pdv-emp-kpi kpi-green">
                                <span class="label">Ticket médio</span>
                                <strong><?php echo number_format($ticketMedio, 2, ',', '.'); ?> <small>MZN</small></strong>
                            </div>
                            <div class="pdv-emp-kpi">
                                <span class="label">Nº vendas</span>
                                <strong><?php echo count($vendas_hoje); ?></strong>
                            </div>
                            <div class="pdv-emp-kpi kpi-meta<?php echo ((int)$empPdv['sem_estoque'] > 0) ? ' is-risk' : ''; ?>">
                                <span class="label">Stock / última venda</span>
                                <strong class="pdv-emp-kpi-meta-text"><?php echo (int)$empPdv['sem_estoque']; ?> sem · <?php echo (int)$empPdv['baixo_estoque']; ?> baixos</strong>
                                <small>Última: <?php echo htmlspecialchars((string)($empPdv['ultima_venda'] ?: '—')); ?><?php echo ($empPdv['hora_pico'] ?? '—') !== '—' ? ' · pico ' . htmlspecialchars((string)$empPdv['hora_pico']) : ''; ?></small>
                            </div>
                        </div>

                        <?php if (!empty($empPdv['notas'])): ?>
                        <div class="pdv-emp-intel">
                            <div class="pdv-emp-intel-head">
                                <strong><i class="fas fa-brain me-2"></i>Leitura do PDV</strong>
                                <span>Hoje · stock, fila e ritmo de caixa</span>
                            </div>
                            <ul class="pdv-emp-intel-notes">
                                <?php foreach ($empPdv['notas'] as $notaPdv):
                                    if (($notaPdv['chave'] ?? '') === 'stock_out') { continue; } ?>
                                    <li class="tom-<?php echo htmlspecialchars((string)($notaPdv['tom'] ?? 'info')); ?>">
                                        <strong><?php echo htmlspecialchars((string)($notaPdv['titulo'] ?? '')); ?></strong>
                                        <span><?php echo htmlspecialchars((string)($notaPdv['txt'] ?? '')); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if (!$caixa_aberto): ?>
                        <div class="alert d-flex align-items-center mb-4" style="background: var(--warning); color: white; border-radius: 16px; padding: 20px;">
                            <i class="fas fa-exclamation-triangle fs-2 me-3"></i>
                            <div class="flex-grow-1"><strong>Atenção!</strong> Para realizar vendas, é necessário abrir o caixa primeiro.</div>
                            <a href="caixa.php" class="btn" style="background: white; color: var(--warning);">Abrir Caixa</a>
                        </div>
                    <?php endif; ?>

                    <?php if ($caixa_aberto): ?>
                        <div class="row g-4 align-items-start<?php echo !empty($isEmpresarialUi) ? ' pdv-emp-workspace' : ''; ?>">
                            <div class="col-lg-8">
                                <div class="card search-panel p-4<?php echo !empty($isEmpresarialUi) ? ' pdv-emp-catalog' : ''; ?>">
                                    <?php if (!empty($isEmpresarialUi)): ?>
                                        <div class="pdv-emp-toolbar">
                                            <div class="pdv-emp-toolbar-title">
                                                <h4 class="mb-0"><i class="fas fa-utensils me-2"></i>Catálogo</h4>
                                                <span class="pdv-emp-count" id="pdv-emp-prod-count"><?php echo count($lista_produtos); ?></span>
                                                
                                            </div>
                                            <div class="search-chip-list" id="pdvFiltroChips">
                                                <button type="button" class="search-chip active" data-stock-filter="todos">Todos <em><?php echo count($lista_produtos); ?></em></button>
                                                <button type="button" class="search-chip" data-stock-filter="estoque">Em stock <em><?php echo max(0, count($lista_produtos) - (int)$empPdv['sem_estoque']); ?></em></button>
                                                <button type="button" class="search-chip<?php echo (int)$empPdv['baixo_estoque'] > 0 ? ' has-low' : ''; ?>" data-stock-filter="baixo">Baixo <em><?php echo (int)$empPdv['baixo_estoque']; ?></em></button>
                                                <button type="button" class="search-chip<?php echo (int)$empPdv['sem_estoque'] > 0 ? ' has-risk' : ''; ?>" data-stock-filter="sem">Sem stock <em><?php echo (int)$empPdv['sem_estoque']; ?></em></button>
                                                <button type="button" class="search-chip" data-stock-filter="top">Mais vendidos <em><?php echo count($empPdv['top_produtos'] ?? []); ?></em></button>
                                            </div>
                                        </div>
                                        <?php if (!empty($empPdv['top_produtos'])): ?>
                                            <div class="pdv-emp-quick mb-3">
                                                <span class="pdv-emp-quick-label">Sugestões de hoje</span>
                                                <?php foreach (array_slice($empPdv['top_produtos'], 0, 6) as $tp): ?>
                                                    <button type="button" class="pdv-emp-quick-btn" data-id="<?php echo (int)$tp['produto_id']; ?>">
                                                        <?php echo htmlspecialchars((string)$tp['nome']); ?>
                                                        <small><?php echo (int)$tp['qtd']; ?>×</small>
                                                    </button>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <div class="row g-3 mb-3">
                                        <div class="col-md-8">
                                            <input type="text" id="buscarProduto" class="form-control" placeholder="<?php echo !empty($isEmpresarialUi) ? 'Pesquisar produto (atalho /)' : 'Pesquisar produto…'; ?>" autocomplete="off">
                                        </div>
                                        <div class="col-md-4">
                                            <select id="filtroCategoria" class="form-select">
                                                <option value="">Todas as categorias</option>
                                                <?php foreach ($lista_categorias as $cat):
                                                    $catOptNome = (string)($cat['nome'] ?? '');
                                                    $crOpt = $empPdv['cat_risco'][$catOptNome] ?? null;
                                                    $outOpt = (int)($crOpt['out'] ?? 0);
                                                    $lowOpt = (int)($crOpt['low'] ?? 0);
                                                    $sufixoRisco = '';
                                                    if (!empty($isEmpresarialUi) && $outOpt > 0) {
                                                        $sufixoRisco = ' · ⚠ ' . $outOpt . ' sem stock';
                                                    } elseif (!empty($isEmpresarialUi) && $lowOpt > 0) {
                                                        $sufixoRisco = ' · ' . $lowOpt . ' baixo';
                                                    }
                                                    ?>
                                                    <option value="<?php echo htmlspecialchars($catOptNome); ?>"><?php echo htmlspecialchars($catOptNome . $sufixoRisco); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <?php if (!empty($isEmpresarialUi) && !empty($empPdv['cat_risco'])): ?>
                                        <div class="pdv-emp-cat-risk mb-3" id="pdvCatRiskChips">
                                            <button type="button" class="pdv-emp-cat-chip is-all is-active" data-cat="">
                                                Todas
                                            </button>
                                            <?php foreach ($empPdv['cat_risco'] as $catRNome => $cr):
                                                $outC = (int)($cr['out'] ?? 0);
                                                $lowC = (int)($cr['low'] ?? 0);
                                                $totC = (int)($cr['total'] ?? 0);
                                                $chipClass = $outC > 0 ? ' is-risk' : ($lowC > 0 ? ' is-low' : '');
                                                ?>
                                                <button type="button" class="pdv-emp-cat-chip<?php echo $chipClass; ?>" data-cat="<?php echo htmlspecialchars((string)$catRNome, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <?php echo htmlspecialchars((string)$catRNome); ?>
                                                    <em class="tot"><?php echo $totC; ?></em>
                                                    <?php if ($outC > 0): ?>
                                                        <em class="risk" title="Esgotados">!</em>
                                                    <?php elseif ($lowC > 0): ?>
                                                        <em class="low" title="Stock baixo"><?php echo $lowC; ?></em>
                                                    <?php endif; ?>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="row g-3" id="pdvCatalogGrid">
                                        <?php foreach ($lista_produtos as $p): ?>
                                            <?php
                                            $pid = (int)$p['id'];
                                            $estq = (int)($p['estoque'] ?? 0);
                                            $vendasQtd = (int)($empPdv['vendas_por_produto'][$pid] ?? 0);
                                            $semFichaPdv = !empty($p['sem_ficha']);
                                            $stockState = ($semFichaPdv || $estq <= 0) ? 'sem' : ($estq <= 5 ? 'baixo' : 'estoque');
                                            $fichaOrigem = (!empty($p['estoque_origem']) && $p['estoque_origem'] === 'ficha');
                                            ?>
                                            <div class="col-6 col-md-4 col-lg-3 produto-item"
                                                 data-categoria="<?php echo htmlspecialchars($p['categoria_nome'] ?? ''); ?>"
                                                 data-id="<?php echo $pid; ?>"
                                                 data-nome="<?php echo htmlspecialchars($p['nome']); ?>"
                                                 data-preco="<?php echo $p['preco']; ?>"
                                                 data-estoque="<?php echo $estq; ?>"
                                                 data-sem-ficha="<?php echo $semFichaPdv ? '1' : '0'; ?>"
                                                 data-stock-state="<?php echo $stockState; ?>"
                                                 data-vendas="<?php echo $vendasQtd; ?>">
                                                <div class="produto-btn<?php echo ($semFichaPdv || $estq <= 0) ? ' is-out' : ($estq <= 5 && (!empty($isEmpresarialUi) || $isProfissionalPdv) ? ' is-low' : ''); ?>" onclick="adicionarProdutoCarrinho(this)">
                                                    <?php if (!empty($isEmpresarialUi) && $vendasQtd > 0): ?>
                                                        <div class="pdv-emp-sold-badge"><?php echo $vendasQtd; ?> hoje</div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($isEmpresarialUi)):
                                                        $pdvIni = pdv_img_iniciais((string)$p['nome']);
                                                        $pdvSrc = !empty($p['imagem']) ? $base_url . str_replace('src/public/', '', (string)$p['imagem']) : $pdvIni; ?>
                                                        <div class="pdv-emp-foto"><img src="<?php echo htmlspecialchars($pdvSrc, ENT_QUOTES, 'UTF-8'); ?>" data-fallback="<?php echo htmlspecialchars($pdvIni, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($p['nome']); ?>" loading="lazy" onerror="this.onerror=null;this.src=this.dataset.fallback;"></div>
                                                    <?php elseif (!empty($p['imagem'])): ?>
                                                        <div class="icon"><img src="<?php echo $base_url . htmlspecialchars($p['imagem']); ?>" alt="<?php echo htmlspecialchars($p['nome']); ?>" onerror="this.parentElement.innerHTML='🍽️'"></div>
                                                    <?php else: ?>
                                                        <div class="icon">🍽️</div>
                                                    <?php endif; ?>
                                                    <?php if ($isProfissionalPdv && !$semFichaPdv && $estq > 0 && $estq <= 5): ?>
                                                        <span class="pdv-pro-low">Restam <?php echo (int)$estq; ?></span>
                                                    <?php endif; ?>
                                                    <div class="nome"><?php echo htmlspecialchars($p['nome']); ?></div>
                                                    <div class="preco"><?php echo number_format($p['preco'], 2, ',', '.'); ?> MZN</div>
                                                    <?php if (!empty($isEmpresarialUi)): ?>
                                                        <div class="pdv-emp-stock-row">
                                                            <?php if ($semFichaPdv): ?>
                                                                <span class="pdv-emp-badge-out"><i class="fas fa-clipboard-list"></i> Sem ficha</span>
                                                            <?php elseif ($estq <= 0): ?>
                                                                <span class="pdv-emp-badge-out"><i class="fas fa-ban"></i> Esgotado</span>
                                                            <?php elseif ($estq <= 5): ?>
                                                                <span class="pdv-emp-badge-low"><i class="fas fa-exclamation"></i> Restam <?php echo $estq; ?></span>
                                                            <?php else: ?>
                                                                <span class="pdv-emp-badge-ok"><i class="fas fa-check"></i> <?php echo $estq; ?> disponíveis</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <?php if ($semFichaPdv): ?>
                                                            <div class="text-danger small mt-1">Sem ficha técnica</div>
                                                        <?php elseif ($estq <= 0): ?>
                                                            <div class="text-danger small mt-1">Sem estoque</div>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if (empty($lista_produtos)): ?>
                                            <div class="col-12 text-center py-5 text-muted">
                                                <i class="fas fa-pizza-slice fa-3x mb-3 d-block"></i>
                                                <p>Nenhum produto ativo</p>
                                                <a href="produtos.php" class="btn btn-primary">Cadastrar produtos</a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php if (!empty($isEmpresarialUi)): ?>
                                <!-- Abas e painéis na mesma coluna do catálogo (evita espaço vazio) -->
                                <div class="pdv-emp-tabs mt-3" id="pdv-emp-tabs">
                                    <button type="button" class="is-active" data-tab="receber">A receber <span class="badge"><?php echo count($pedidos_aguardando); ?></span></button>
                                    <button type="button" data-tab="historico">Histórico <span class="badge"><?php echo count($vendas_hoje); ?></span></button>
                                    <button type="button" data-tab="insights">Insights</button>
                                    <a class="pdv-emp-tab-link" href="caixa_mesas.php">Contas</a>
                                    <a class="pdv-emp-tab-link" href="mesas.php">Mesas</a>
                                </div>

                                <div class="card mt-2 pdv-emp-panel" id="pdv-panel-receber" data-pdv-panel="receber">
                                    <div class="card-header d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, var(--warning), #d97706); color: white; border-radius: 16px 16px 0 0; padding: 14px 18px;">
                                        <h5 class="mb-0"><i class="fas fa-clock me-2"></i><?php echo $fluxoPdvBasicoPagamento ? 'Pedidos para Receber' : 'Pedidos Aguardando Pagamento'; ?>
                                            <span class="badge ms-2" style="background: rgba(255,255,255,0.25);"><?php echo count($pedidos_aguardando); ?></span>
                                        </h5>
                                        <?php if ($temPedidosOnline): ?>
                                            <a href="pedidos.php" class="btn btn-sm" style="background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.4);">Ver todos</a>
                                        <?php endif; ?>
                                    </div>
                                    <div class="card-body p-0">
                                        <?php if (empty($pedidos_aguardando)): ?>
                                            <div class="text-center py-3 text-muted">
                                                <i class="fas fa-check-circle fa-lg mb-2 d-block" style="color: var(--success);"></i>
                                                Nenhum pedido aguardando pagamento
                                            </div>
                                        <?php else: ?>
                                            <div class="table-responsive">
                                                <table class="table mb-0">
                                                    <thead style="background: #fef9c3;">
                                                        <tr>
                                                            <th class="ps-3">Pedido</th>
                                                            <th>Mesa</th>
                                                            <th>Cliente</th>
                                                            <th>Total</th>
                                                            <th>Horário</th>
                                                            <th style="min-width:260px;">Receber</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($pedidos_aguardando as $pa): ?>
                                                            <tr id="pedido_row_<?php echo $pa['id']; ?>">
                                                                <td class="ps-3 fw-bold">#<?php echo htmlspecialchars($pa['numero_pedido'] ?? $pa['id']); ?></td>
                                                                <td><?php echo vendas_mesa_label_html($pa['mesa_numero'] ?? ''); ?></td>
                                                                <td><?php echo $pa['cliente_nome'] ? htmlspecialchars($pa['cliente_nome']) : '—'; ?></td>
                                                                <td class="fw-bold" style="color: var(--primary);"><?php echo number_format($pa['total'], 2, ',', '.'); ?> MZN</td>
                                                                <td class="text-muted small"><?php echo date('H:i', strtotime($pa['criado_em'])); ?></td>
                                                                <td>
                                                                    <div class="d-flex gap-2 align-items-center">
                                                                        <select id="forma_pag_<?php echo $pa['id']; ?>" class="form-select form-select-sm" style="width:130px;">
                                                                            <option value="DINHEIRO">Dinheiro</option>
                                                                            <option value="MPESA">M-Pesa</option>
                                                                            <option value="EMOLA">e-Mola</option>
                                                                            <option value="CARTAO">Cartão</option>
                                                                            <option value="TRANSFERENCIA">Transferência</option>
                                                                        </select>
                                                                        <button id="btn_pagar_<?php echo $pa['id']; ?>" class="btn btn-success btn-sm" onclick="receberPagamentoPedido(<?php echo $pa['id']; ?>)">Receber</button>
                                                                    </div>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="card mt-2 mb-0 pdv-emp-panel is-hidden" id="pdv-panel-historico" data-pdv-panel="historico">
                                    <div class="card-header d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, var(--dark), var(--dark-2)); color: white; border-radius: 16px 16px 0 0; padding: 14px 18px;">
                                        <h5 class="mb-0"><i class="fas fa-history me-2"></i>Vendas de Hoje
                                            <span class="badge ms-2" style="background: rgba(255,255,255,0.15);"><?php echo count($vendas_hoje); ?></span>
                                        </h5>
                                        <span style="font-size: 15px; font-weight: 700; color: #10b981;">
                                            <?php echo number_format($total_hoje, 2, ',', '.'); ?> MZN
                                        </span>
                                    </div>
                                    <div class="card-body p-0">
                                        <?php if (empty($vendas_hoje)): ?>
                                            <div class="text-center py-3 text-muted">Nenhuma venda registada hoje</div>
                                        <?php else: ?>
                                            <div class="table-responsive">
                                                <table class="table mb-0">
                                                    <thead style="background: #f8fafc;">
                                                        <tr>
                                                            <th class="ps-3">Fatura</th>
                                                            <th>Mesa</th>
                                                            <th>Operador</th>
                                                            <th>Pagamento</th>
                                                            <th>Total</th>
                                                            <th>Hora</th>
                                                            <th></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php
                                                        $forma_icons = ['DINHEIRO' => '💵', 'MPESA' => '📱', 'EMOLA' => '📱', 'CARTAO' => '💳', 'TRANSFERENCIA' => '🏦'];
                                                        foreach ($vendas_hoje as $vh):
                                                            $icone = $forma_icons[$vh['forma_pagamento']] ?? '💰';
                                                        ?>
                                                            <tr>
                                                                <td class="ps-3 fw-bold text-primary"><?php echo htmlspecialchars($vh['numero_fatura']); ?></td>
                                                                <td><?php echo vendas_mesa_label_html($vh['mesa_numero'] ?? ''); ?></td>
                                                                <td class="text-muted small"><?php echo htmlspecialchars($vh['usuario_nome'] ?? '—'); ?></td>
                                                                <td><?php echo $icone . ' ' . htmlspecialchars($vh['forma_pagamento']); ?></td>
                                                                <td class="fw-bold" style="color: var(--success);"><?php echo number_format($vh['total_final'], 2, ',', '.'); ?> MZN</td>
                                                                <td class="text-muted small"><?php echo date('H:i', strtotime($vh['criado_em'])); ?></td>
                                                                <td>
                                                                    <a href="comprovante.php?id=<?php echo $vh['id']; ?>&amp;auto_print=1" class="btn btn-outline-secondary btn-sm" onclick="if(window.ReceiptPrint){ReceiptPrint.reprint(<?php echo (int)$vh['id']; ?>);return false;}" title="Reimprimir recibo"><i class="fas fa-print"></i></a>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="card mt-2 mb-0 pdv-emp-panel is-hidden" id="pdv-panel-insights" data-pdv-panel="insights">
                                    <div class="card-header" style="background:#fff7ed;border-bottom:1px solid #fed7aa;padding:14px 18px;">
                                        <h5 class="mb-0" style="color:#9a3412;"><i class="fas fa-chart-pie me-2"></i>Insights do dia</h5>
                                    </div>
                                    <div class="card-body">
                                        <?php if (!empty($empPdv['notas'])): ?>
                                            <ul class="pdv-emp-intel-notes mb-3">
                                                <?php foreach ($empPdv['notas'] as $notaIns): ?>
                                                    <li class="tom-<?php echo htmlspecialchars((string)($notaIns['tom'] ?? 'info')); ?>">
                                                        <strong><?php echo htmlspecialchars((string)($notaIns['titulo'] ?? '')); ?></strong>
                                                        <span><?php echo htmlspecialchars((string)($notaIns['txt'] ?? '')); ?></span>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <h6 class="fw-bold">Mix de pagamento</h6>
                                                <?php if (empty($empPdv['mix_pagamento'])): ?>
                                                    <p class="text-muted small mb-0">Sem vendas registadas hoje — o mix aparece após a primeira venda paga.</p>
                                                <?php else: ?>
                                                    <?php foreach ($empPdv['mix_pagamento'] as $mix): ?>
                                                        <?php
                                                        $mixTotal = (float)($mix['total'] ?? 0);
                                                        $mixPct = $total_hoje > 0 ? (int)round(($mixTotal / $total_hoje) * 100) : 0;
                                                        ?>
                                                        <div class="pdv-emp-mix-row">
                                                            <span><?php echo htmlspecialchars((string)($mix['forma_pagamento'] ?? '—')); ?> · <?php echo (int)($mix['qtd'] ?? 0); ?></span>
                                                            <strong><?php echo number_format($mixTotal, 2, ',', '.'); ?> (<?php echo $mixPct; ?>%)</strong>
                                                        </div>
                                                        <div class="pdv-emp-mix-bar"><i style="width:<?php echo max(4, min(100, $mixPct)); ?>%"></i></div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="col-md-6">
                                                <h6 class="fw-bold">Mais vendidos</h6>
                                                <?php if (empty($empPdv['top_produtos'])): ?>
                                                    <p class="text-muted small mb-0">Ainda sem ranking. Use o filtro “Mais vendidos” depois das primeiras vendas.</p>
                                                <?php else: ?>
                                                    <?php foreach (array_slice($empPdv['top_produtos'], 0, 6) as $tp): ?>
                                                        <button type="button" class="pdv-emp-top-btn mb-2" data-id="<?php echo (int)$tp['produto_id']; ?>">
                                                            <span><?php echo htmlspecialchars((string)$tp['nome']); ?></span>
                                                            <em><?php echo (int)$tp['qtd']; ?>×</em>
                                                        </button>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                                <?php if (($empPdv['hora_pico'] ?? '—') !== '—'): ?>
                                                    <div class="pdv-emp-pico mt-3">
                                                        <span>Hora pico</span>
                                                        <strong><?php echo htmlspecialchars((string)$empPdv['hora_pico']); ?></strong>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="col-lg-4">
                                <div class="carrinho-card<?php echo !empty($isEmpresarialUi) ? ' pdv-emp-cart' : ''; ?>">
                                    <?php if (!empty($isEmpresarialUi)): ?>
                                        <div class="pdv-emp-cart-head">
                                            <h4 class="mb-0"><i class="fas fa-shopping-cart me-2"></i>Carrinho</h4>
                                            <span class="pdv-emp-cart-count" id="pdvCartCount">0</span>
                                        </div>
                                        <div class="pdv-emp-cart-hint" id="pdvCartHint">
                                            <?php if ($temTelasBarCozinha): ?>
                                                <strong id="pdvFlowTitle">Fluxo de salão</strong>
                                                <span id="pdvFlowText">Com mesa: envia à cozinha/bar e paga depois em “A receber”.</span>
                                            <?php elseif (count($pedidos_aguardando) > 0): ?>
                                                <strong><?php echo count($pedidos_aguardando); ?> a receber</strong>
                                                <span>Há pedidos prontos na aba “A receber”.</span>
                                            <?php else: ?>
                                                <strong>PDV pronto</strong>
                                                <span>Adicione itens e receba o pagamento.</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <h4 class="mb-3"><i class="fas fa-shopping-cart me-2"></i>Carrinho</h4>
                                    <?php endif; ?>

                                    <div class="mb-3<?php echo !empty($isEmpresarialUi) ? ' pdv-emp-cart-field' : ''; ?>">
                                        <label class="form-label"><?php echo $temTelasBarCozinha ? 'Onde servir?' : ( !empty($isEmpresarialUi) ? 'Mesa / balcão' : 'Mesa (opcional)'); ?></label>
                                        <select id="mesa_id" class="form-select" onchange="sincronizarModoCarrinhoPdv()">
                                            <option value="">Balcão / Take-away</option>
                                            <?php if (!empty($balcao2Pdv['ativo']) && (int)$balcao2Pdv['mesa_id'] > 0): ?>
                                                <option value="<?php echo (int)$balcao2Pdv['mesa_id']; ?>"><?php echo htmlspecialchars((string)$balcao2Pdv['nome']); ?> (balcão)</option>
                                            <?php endif; ?>
                                            <?php foreach ($lista_mesas as $m): ?>
                                                <option value="<?php echo $m['id']; ?>">Mesa <?php echo htmlspecialchars((string)$m['numero']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if ($temTelasBarCozinha): ?>
                                            <small class="pdv-emp-cart-meta" id="pdvMesaMeta">
                                                Mesa = enviar agora, pagar no fim · Balcão = pagar e enviar à cozinha
                                            </small>
                                        <?php elseif (!empty($isEmpresarialUi)): ?>
                                            <small class="pdv-emp-cart-meta"><?php echo count($lista_mesas); ?> mesa(s) livre(s) · mesa opcional</small>
                                        <?php endif; ?>
                                    </div>

                                    <div id="carrinhoItens" class="<?php echo !empty($isEmpresarialUi) ? 'pdv-emp-cart-items' : ''; ?>" style="min-height: 80px; max-height: 280px; overflow-y: auto;">
                                        <div class="<?php echo !empty($isEmpresarialUi) ? 'pdv-emp-cart-empty' : 'text-center text-muted py-4'; ?>">
                                            <?php if (!empty($isEmpresarialUi)): ?>
                                                <i class="fas fa-basket-shopping"></i>
                                                <strong>Carrinho vazio</strong>
                                                <span>Toque num produto do catálogo para começar.</span>
                                            <?php else: ?>
                                                <i class="fas fa-shopping-cart fa-2x mb-2 d-block"></i>
                                                Carrinho vazio
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="border-top pt-3 mt-3<?php echo !empty($isEmpresarialUi) ? ' pdv-emp-cart-totals' : ''; ?>">
                                        <div class="d-flex justify-content-between mb-2">
                                            <span>Subtotal</span>
                                            <span id="subtotal" class="fw-bold">0,00 MZN</span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center mb-3" id="carrinhoDescontoRow">
                                            <label for="desconto" class="mb-0">Desconto <small class="text-muted">(MZN)</small></label>
                                            <input type="number" id="desconto" class="form-control<?php echo !empty($isEmpresarialUi) ? ' pdv-emp-discount' : ''; ?>" style="width: 100px;" value="0" min="0" step="0.01" placeholder="0,00" title="Desconto em meticais (MZN), não em percentagem" onchange="calcularTotal()" oninput="calcularTotal()">
                                        </div>
                                    </div>

                                    <div class="total-box mb-3<?php echo !empty($isEmpresarialUi) ? ' pdv-emp-total-box' : ''; ?>">
                                        <div class="small opacity-75" id="pdvTotalLabel">TOTAL A PAGAR</div>
                                        <div id="total" class="fw-bold" style="font-size: 28px;">0,00 MZN</div>
                                    </div>

                                    <div id="carrinhoPagamentoBlock">
                                        <div class="mb-3<?php echo !empty($isEmpresarialUi) ? ' pdv-emp-cart-field' : ''; ?>">
                                            <label class="form-label">Forma de pagamento</label>
                                            <?php if (!empty($isEmpresarialUi)): ?>
                                            <div class="pdv-emp-pay-btns" id="pdvPayBtns" role="group" aria-label="Forma de pagamento">
                                                <button type="button" data-forma="DINHEIRO"><i class="fas fa-money-bill-wave"></i>Dinheiro</button>
                                                <button type="button" data-forma="MPESA"><i class="fas fa-mobile-screen"></i>M-Pesa</button>
                                                <button type="button" data-forma="EMOLA"><i class="fas fa-mobile-screen-button"></i>e-Mola</button>
                                                <button type="button" data-forma="CARTAO"><i class="fas fa-credit-card"></i>Cartão</button>
                                                <button type="button" data-forma="TRANSFERENCIA"><i class="fas fa-building-columns"></i>Transf.</button>
                                            </div>
                                            <?php endif; ?>
                                            <select id="forma_pagamento" class="form-select<?php echo !empty($isEmpresarialUi) ? ' pdv-emp-oculto' : ''; ?>" onchange="atualizarPagamentoCarrinhoUI()">
                                                <option value="DINHEIRO">💵 Dinheiro</option>
                                                <option value="MPESA">📱 M-Pesa</option>
                                                <option value="EMOLA">📱 e-Mola</option>
                                                <option value="CARTAO">💳 Cartão</option>
                                                <option value="TRANSFERENCIA">🏦 Transferência</option>
                                            </select>
                                            <?php if (!empty($isEmpresarialUi) || $temTelasBarCozinha): ?>
                                                <small class="pdv-emp-cart-meta" id="pdvPayHint">Em dinheiro: indique o valor entregue para calcular o troco.</small>
                                            <?php endif; ?>
                                        </div>

                                        <div class="mb-3<?php echo !empty($isEmpresarialUi) ? ' pdv-emp-cart-field' : ''; ?>" id="carrinhoValorRecebidoBox">
                                            <label class="form-label">Valor recebido (MZN)</label>
                                            <input type="number" id="valor_recebido" class="form-control" min="0" step="0.01" placeholder="Valor recebido" oninput="atualizarPagamentoCarrinhoUI()">
                                            <?php if (!empty($isEmpresarialUi)): ?>
                                            <div class="pdv-emp-troco-rapido" id="pdvTrocoRapido">
                                                <button type="button" data-valor="exato">Valor exato</button>
                                                <button type="button" data-valor="100">100</button>
                                                <button type="button" data-valor="200">200</button>
                                                <button type="button" data-valor="500">500</button>
                                                <button type="button" data-valor="1000">1000</button>
                                            </div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="mb-3 p-3 rounded<?php echo !empty($isEmpresarialUi) ? ' pdv-emp-troco' : ''; ?>" id="carrinhoTrocoBox" style="<?php echo empty($isEmpresarialUi) ? 'background:rgba(16,185,129,0.08);border:1px solid rgba(16,185,129,0.35);' : ''; ?>">
                                            <div class="small text-muted mb-1">Troco</div>
                                            <div id="carrinhoTroco" class="fw-bold" style="font-size:1.15rem;color:#059669;">0,00 MZN</div>
                                        </div>
                                    </div>

                                    <?php if ($temTelasBarCozinha): ?>
                                        <div id="pdvModeMesaActions" style="display:none;">
                                            <button type="button" class="btn <?php echo !empty($isEmpresarialUi) ? 'pdv-emp-btn-send' : 'btn-warning'; ?> w-100 mb-2 py-3" id="btnEnviarPedidoCarrinho" onclick="enviarPedidoCarrinho()">
                                                <i class="fas fa-utensils me-2"></i>Enviar à cozinha / bar
                                            </button>
                                            <p class="small text-muted mb-2 mb-md-3" id="pdvMesaPayNote">O pagamento fica para depois, em “A receber” / Contas / Caixa.</p>
                                        </div>
                                        <div id="pdvModeBalcaoActions">
                                            <button type="button" class="btn <?php echo !empty($isEmpresarialUi) ? 'pdv-emp-btn-pay' : 'btn-success'; ?> w-100 mb-2 py-3" id="btnPagarEnviar" onclick="pagarEEnviarCarrinho()">
                                                <i class="fas fa-cash-register me-2"></i>Pagar e enviar
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary w-100 mb-2" id="btnVendaDirecta" onclick="finalizarVenda()">
                                                <i class="fas fa-bolt me-2"></i>Venda directa (sem cozinha)
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <button type="button" class="btn <?php echo !empty($isEmpresarialUi) ? 'pdv-emp-btn-pay' : 'btn-success'; ?> w-100 mb-2 py-3" id="btnFinalizarVenda" onclick="finalizarVenda()">
                                            <i class="fas fa-check me-2"></i>Receber pagamento
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" class="btn <?php echo !empty($isEmpresarialUi) ? 'pdv-emp-btn-clear' : 'btn-outline-danger'; ?> w-100" onclick="limparCarrinho()">
                                        <i class="fas fa-trash me-2"></i>Limpar carrinho
                                    </button>
                                    <div id="alertVenda" class="alert mt-3" style="display: none;"></div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($caixa_aberto && empty($isEmpresarialUi)): ?>
                        <!-- Layout padrão (não Empresarial) -->
                        <div class="card mt-4" id="pdv-panel-receber">
                            <div class="card-header d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, var(--warning), #d97706); color: white; border-radius: 16px 16px 0 0; padding: 18px 24px;">
                                <h5 class="mb-0"><i class="fas fa-clock me-2"></i><?php echo $fluxoPdvBasicoPagamento ? 'Pedidos para Receber' : 'Pedidos Aguardando Pagamento'; ?>
                                    <span class="badge ms-2" style="background: rgba(255,255,255,0.25);"><?php echo count($pedidos_aguardando); ?></span>
                                </h5>
                                <?php if ($temPedidosOnline): ?>
                                    <a href="pedidos.php" class="btn btn-sm" style="background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.4);">Ver todos os pedidos</a>
                                <?php endif; ?>
                            </div>
                            <div class="card-body p-0">
                                <?php if (empty($pedidos_aguardando)): ?>
                                    <div class="text-center py-4 text-muted">
                                        <i class="fas fa-check-circle fa-2x mb-2 d-block" style="color: var(--success);"></i>
                                        Nenhum pedido aguardando pagamento
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table mb-0">
                                            <thead style="background: #fef9c3;">
                                                <tr>
                                                    <th class="ps-4">Pedido</th>
                                                    <th>Mesa</th>
                                                    <th>Cliente</th>
                                                    <th>Total</th>
                                                    <th>Horário</th>
                                                    <th style="min-width:300px;">Receber</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($pedidos_aguardando as $pa): ?>
                                                    <tr id="pedido_row_<?php echo $pa['id']; ?>">
                                                        <td class="ps-4 fw-bold">#<?php echo htmlspecialchars($pa['numero_pedido'] ?? $pa['id']); ?></td>
                                                        <td><?php echo vendas_mesa_label_html($pa['mesa_numero'] ?? ''); ?></td>
                                                        <td><?php echo $pa['cliente_nome'] ? htmlspecialchars($pa['cliente_nome']) : '<span class="text-muted">—</span>'; ?></td>
                                                        <td class="fw-bold" style="color: var(--primary);"><?php echo number_format($pa['total'], 2, ',', '.'); ?> MZN</td>
                                                        <td class="text-muted small"><?php echo date('H:i', strtotime($pa['criado_em'])); ?></td>
                                                        <td>
                                                            <div class="d-flex gap-2 align-items-center">
                                                                <select id="forma_pag_<?php echo $pa['id']; ?>" class="form-select form-select-sm" style="width:150px;">
                                                                    <option value="DINHEIRO">Dinheiro</option>
                                                                    <option value="MPESA">M-Pesa</option>
                                                                    <option value="EMOLA">e-Mola</option>
                                                                    <option value="CARTAO">Cartão</option>
                                                                    <option value="TRANSFERENCIA">Transferência</option>
                                                                </select>
                                                                <button id="btn_pagar_<?php echo $pa['id']; ?>" class="btn btn-success btn-sm" onclick="receberPagamentoPedido(<?php echo $pa['id']; ?>)">Receber</button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="card mt-4 mb-4" id="pdv-panel-historico">
                            <div class="card-header d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, var(--dark), var(--dark-2)); color: white; border-radius: 16px 16px 0 0; padding: 18px 24px;">
                                <h5 class="mb-0"><i class="fas fa-history me-2"></i>Vendas de Hoje
                                    <span class="badge ms-2" style="background: rgba(255,255,255,0.15);"><?php echo count($vendas_hoje); ?></span>
                                </h5>
                                <span style="font-size: 18px; font-weight: 700; color: #10b981;">
                                    Total: <?php echo number_format($total_hoje, 2, ',', '.'); ?> MZN
                                </span>
                            </div>
                            <div class="card-body p-0">
                                <?php if (empty($vendas_hoje)): ?>
                                    <div class="text-center py-4 text-muted">Nenhuma venda registada hoje</div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table mb-0">
                                            <thead style="background: #f8fafc;">
                                                <tr>
                                                    <th class="ps-4">Fatura</th>
                                                    <th>Mesa</th>
                                                    <th>Operador</th>
                                                    <th>Pagamento</th>
                                                    <th>Total</th>
                                                    <th>Hora</th>
                                                    <th></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $forma_icons = ['DINHEIRO' => '💵', 'MPESA' => '📱', 'EMOLA' => '📱', 'CARTAO' => '💳', 'TRANSFERENCIA' => '🏦'];
                                                foreach ($vendas_hoje as $vh):
                                                    $icone = $forma_icons[$vh['forma_pagamento']] ?? '💰';
                                                ?>
                                                    <tr>
                                                        <td class="ps-4 fw-bold text-primary"><?php echo htmlspecialchars($vh['numero_fatura']); ?></td>
                                                        <td><?php echo vendas_mesa_label_html($vh['mesa_numero'] ?? ''); ?></td>
                                                        <td class="text-muted small"><?php echo htmlspecialchars($vh['usuario_nome'] ?? '—'); ?></td>
                                                        <td><?php echo $icone . ' ' . htmlspecialchars($vh['forma_pagamento']); ?></td>
                                                        <td class="fw-bold" style="color: var(--success);"><?php echo number_format($vh['total_final'], 2, ',', '.'); ?> MZN</td>
                                                        <td class="text-muted small"><?php echo date('H:i', strtotime($vh['criado_em'])); ?></td>
                                                        <td>
                                                            <a href="comprovante.php?id=<?php echo $vh['id']; ?>&amp;auto_print=1" class="btn btn-outline-secondary btn-sm" onclick="if(window.ReceiptPrint){ReceiptPrint.reprint(<?php echo (int)$vh['id']; ?>);return false;}" title="Reimprimir recibo"><i class="fas fa-print"></i></a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                </div>
            </main>
        </div>
    </div>
    <script src="lib/bootstrap-5.3.2/js/bootstrap.bundle.min.js"></script>
    <script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
        const CSRF_TOKEN = <?php echo json_encode($csrfToken, JSON_UNESCAPED_UNICODE); ?>;
        const IS_EMPRESARIAL = <?php echo !empty($isEmpresarialUi) ? 'true' : 'false'; ?>;
        const EXIGE_FICHA_CARRINHO = <?php echo !empty($exigeFichaCarrinhoPdv) ? 'true' : 'false'; ?>;
        const ESTOQUE_AVANCADO_PDV = <?php echo !empty($estoqueAvancadoPdv) ? 'true' : 'false'; ?>;
        const TEM_TELAS_BAR_COZINHA = <?php echo !empty($temTelasBarCozinha) ? 'true' : 'false'; ?>;
        const MESA_BALCAO_ID = <?php echo (int)$mesaBalcaoId; ?>;
        const MESA_BALCAO2_ID = <?php echo !empty($balcao2Pdv['ativo']) ? (int)$balcao2Pdv['mesa_id'] : 0; ?>;
        const MESA_BALCAO2_NOME = <?php echo json_encode(!empty($balcao2Pdv['ativo']) ? (string)$balcao2Pdv['nome'] : '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    </script>
    <script src="js/receipt_print.js?v=20260821a"></script>
    <script src="js/pdv.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/pdv.js'); ?>"></script>
    <?php if (!empty($isEmpresarialUi)): ?>
    <script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
        (function initPdvEmpresarial() {
            window.PDV_STOCK_FILTER = 'todos';

            document.querySelectorAll('#pdvFiltroChips [data-stock-filter]').forEach(function (chip) {
                chip.addEventListener('click', function () {
                    document.querySelectorAll('#pdvFiltroChips [data-stock-filter]').forEach(function (c) { c.classList.remove('active'); });
                    chip.classList.add('active');
                    window.PDV_STOCK_FILTER = chip.getAttribute('data-stock-filter') || 'todos';
                    if (typeof filtrarProdutos === 'function') filtrarProdutos();
                });
            });

            document.querySelectorAll('.pdv-emp-quick-btn, .pdv-emp-top-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var id = String(btn.getAttribute('data-id') || '');
                    var item = document.querySelector('.produto-item[data-id="' + id + '"]');
                    if (!item) return;
                    var el = item.querySelector('.produto-btn');
                    if (el && typeof adicionarProdutoCarrinho === 'function') adicionarProdutoCarrinho(el);
                });
            });

            document.querySelectorAll('#pdvCatRiskChips .pdv-emp-cat-chip').forEach(function (chip) {
                chip.addEventListener('click', function () {
                    var cat = String(chip.getAttribute('data-cat') || '');
                    var sel = document.getElementById('filtroCategoria');
                    if (!sel) return;
                    document.querySelectorAll('#pdvCatRiskChips .pdv-emp-cat-chip').forEach(function (c) {
                        c.classList.toggle('is-active', c === chip);
                    });
                    sel.value = cat;
                    // Manter filtro de stock actual; só muda categoria
                    if (typeof filtrarProdutos === 'function') filtrarProdutos();
                });
            });

            var tabs = document.querySelectorAll('#pdv-emp-tabs [data-tab]');
            tabs.forEach(function (tab) {
                tab.addEventListener('click', function () {
                    var key = tab.getAttribute('data-tab');
                    tabs.forEach(function (t) { t.classList.remove('is-active'); });
                    tab.classList.add('is-active');
                    document.querySelectorAll('[data-pdv-panel]').forEach(function (panel) {
                        panel.classList.toggle('is-hidden', panel.getAttribute('data-pdv-panel') !== key);
                    });
                });
            });

            document.addEventListener('keydown', function (ev) {
                if (ev.key !== '/' || ev.ctrlKey || ev.metaKey || ev.altKey) return;
                var tag = (ev.target && ev.target.tagName) ? ev.target.tagName.toLowerCase() : '';
                if (tag === 'input' || tag === 'textarea' || tag === 'select') return;
                var busca = document.getElementById('buscarProduto');
                if (!busca) return;
                ev.preventDefault();
                busca.focus();
                busca.select();
            });
        })();
    </script>
    <?php endif; ?>
</body>

</html>
