<?php
require_once __DIR__ . '/app_base.php';

/*
   Tela Garçom – Novo Pedido (Fluxo sem QR Code)
  Garçom seleciona mesa, adiciona itens e envia para a cozinha.
*/
include_once APP_BASE . '/src/config/auth_check.php';
include_once APP_BASE . '/src/config/csrf.php';
include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/plano_check.php';
include_once APP_BASE . '/src/config/restaurante_context.php';
include_once APP_BASE . '/src/config/mesa_display_helper.php';
include_once APP_BASE . '/src/config/promocao_schema.php';
include_once APP_BASE . '/src/Model/Mesa.php';

requirePermissionOrRedirect(['ADMIN', 'GERENTE', 'GARCOM', 'COZINHA', 'BAR']);

$database  = new Database();
$db        = $database->getConnection();
$mesa_obj  = new Mesa($db);
$rid       = session_restaurante_contexto_id();
$restauranteFeatureId = session_restaurante_capability_id();
$restauranteFeatureId = $restauranteFeatureId > 0 ? $restauranteFeatureId : $rid;
$temPedidosOnline = $restauranteFeatureId > 0 && plano_tem_funcionalidade_db($restauranteFeatureId, 'pedidos_online');
$temEstoqueAvancado = $restauranteFeatureId > 0 && plano_tem_funcionalidade_db($restauranteFeatureId, 'estoque_avancado');
$temTelasBarCozinha = $restauranteFeatureId > 0 && plano_tem_funcionalidade_db($restauranteFeatureId, 'telas_bar_cozinha');
$temHistoricoClientes = $restauranteFeatureId > 0 && plano_tem_funcionalidade_db($restauranteFeatureId, 'historico_clientes');
$dadosPlanoNovo = plano_get_dados($restauranteFeatureId);
$plano_atual = plano_normalizar_nome($dadosPlanoNovo['plano_nome'] ?? 'BASICO');
$promocoes = promocao_plano_permitido($plano_atual) ? promocao_listar_ativas($db, $rid) : [];
require_once __DIR__ . '/includes/empresarial_ui.php';
$csrfToken = csrf_get_token();
$avisoFluxoBasico = isset($_GET['aviso']) && $_GET['aviso'] === 'fluxo_basico_pdv';

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? null) == 443)
    ? 'https://'
    : 'http://';
$base_url = $protocol . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '\\/') . '/';

if ($rid <= 0) {
    header('Location: index.php');
    exit;
}

$balcaoCanonicoNovoPedido = mesa_garantir_balcao_canonico($db, (int)$rid);
$stmt_mesas = $mesa_obj->listar($rid);
$mesas      = $stmt_mesas->fetchAll(PDO::FETCH_ASSOC);

$produtos = [];
try {
    $stmt_produtos = $db->prepare(
        "SELECT p.id, p.nome, p.preco, p.imagem, COALESCE(p.estoque, 0) AS estoque, c.nome AS categoria
         FROM produtos p
         LEFT JOIN categorias c ON p.categoria_id = c.id
         WHERE p.restaurante_id = :rid AND COALESCE(p.ativo, 1) = 1
         ORDER BY COALESCE(c.nome, 'Geral'), p.nome"
    );
    $stmt_produtos->execute(['rid' => $rid]);
    $produtos = $stmt_produtos->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $eProd) {
    error_log('[NOVO_PEDIDO][PRODUTOS] ' . $eProd->getMessage());
    try {
        $stmt_produtos = $db->prepare(
            "SELECT p.id, p.nome, p.preco, p.imagem, c.nome AS categoria
             FROM produtos p
             LEFT JOIN categorias c ON p.categoria_id = c.id
             WHERE p.restaurante_id = :rid AND COALESCE(p.ativo, 1) = 1
             ORDER BY COALESCE(c.nome, 'Geral'), p.nome"
        );
        $stmt_produtos->execute(['rid' => $rid]);
        $produtos = $stmt_produtos->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($produtos as &$pFix) {
            $pFix['estoque'] = 0;
        }
        unset($pFix);
    } catch (Throwable $eProd2) {
        error_log('[NOVO_PEDIDO][PRODUTOS_FALLBACK] ' . $eProd2->getMessage());
        $produtos = [];
    }
}

// Estoque inteligente: porções disponíveis pela ficha (insumos), não produtos.estoque.
$exigeFichaCarrinhoNp = $temEstoqueAvancado && ($plano_atual === 'PROFISSIONAL');
if ($temEstoqueAvancado && !empty($produtos)) {
    try {
        require_once APP_BASE . '/src/config/estoque_inteligente.php';
        estoque_inteligente_schema_garantir($db);
        $filialIdNp = null;
        if (function_exists('session_restaurante_auth_id')) {
            $authRidNp = (int)session_restaurante_auth_id();
            if ($authRidNp > 0 && $restauranteFeatureId > 0 && $authRidNp !== $restauranteFeatureId) {
                $filialIdNp = $authRidNp;
            }
        }
        $idsNp = array_map(static fn($p) => (int)($p['id'] ?? 0), $produtos);
        $porcoesNp = estoque_porcoes_disponiveis_por_produtos($db, $rid, $filialIdNp, $idsNp);
        foreach ($produtos as &$pNp) {
            $pidNp = (int)($pNp['id'] ?? 0);
            if ($pidNp <= 0) {
                continue;
            }
            if (array_key_exists($pidNp, $porcoesNp)) {
                $pNp['estoque'] = (int)$porcoesNp[$pidNp];
                $pNp['estoque_origem'] = 'ficha';
                $pNp['sem_ficha'] = false;
            } elseif ($exigeFichaCarrinhoNp) {
                $pNp['estoque'] = 0;
                $pNp['estoque_origem'] = 'sem_ficha';
                $pNp['sem_ficha'] = true;
            }
        }
        unset($pNp);
    } catch (Throwable $eEstNp) {
        error_log('[NOVO_PEDIDO][ESTOQUE_FICHA] ' . $eEstNp->getMessage());
    }
}

// Agrupar por categoria
$por_categoria = [];
foreach ($produtos as $prod) {
    $cat = trim((string)($prod['categoria'] ?? ''));
    if ($cat === '') {
        $cat = 'Geral';
    }
    $por_categoria[$cat][] = $prod;
}

function novo_pedido_nome_busca(string $nome): string
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($nome, 'UTF-8');
    }
    return strtolower($nome);
}

function novo_pedido_img_src(array $p, string $baseUrl): string
{
    $nomeProd = (string)($p['nome'] ?? 'Produto');
    $caminho = trim((string)($p['imagem'] ?? ''));
    if ($caminho !== '') {
        $caminho = str_replace('src/public/', '', $caminho);
    }
    if ($caminho !== '' && preg_match('/^https?:\/\//i', $caminho)) {
        return $caminho;
    }
    if ($caminho !== '') {
        $rel = ltrim($caminho, '/');
        if (@file_exists(__DIR__ . '/' . $rel)) {
            return $baseUrl . $rel;
        }
    }
    return novo_pedido_img_iniciais($nomeProd);
}

/** Imagem de recurso (iniciais do produto) gerada localmente — funciona sem internet. */
function novo_pedido_img_iniciais(string $nomeProd): string
{
    $ini = '';
    foreach (preg_split('/\s+/u', trim($nomeProd)) ?: [] as $parte) {
        if ($parte !== '' && mb_strlen($ini) < 2) {
            $ini .= mb_strtoupper(mb_substr($parte, 0, 1));
        }
    }
    if ($ini === '') {
        $ini = 'P';
    }
    $cores = ['#ea580c', '#0f766e', '#2563eb', '#7c3aed', '#be123c', '#a16207', '#0e7490', '#4d7c0f'];
    $cor = $cores[abs(crc32($nomeProd)) % count($cores)];
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 256 256">'
        . '<rect width="256" height="256" fill="' . $cor . '"/>'
        . '<text x="50%" y="50%" dy=".35em" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="96" font-weight="700" fill="#ffffff">'
        . htmlspecialchars($ini, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</text></svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

function novo_pedido_cat_icon(string $cat): string
{
    $c = novo_pedido_nome_busca($cat);
    if (strpos($c, 'cerveja') !== false || strpos($c, 'alcool') !== false || strpos($c, 'alcol') !== false) {
        return 'fa-beer-mug-empty';
    }
    if (strpos($c, 'sumo') !== false || strpos($c, 'suco') !== false || strpos($c, 'natural') !== false) {
        return 'fa-lemon';
    }
    if (strpos($c, 'agua') !== false || strpos($c, 'água') !== false) {
        return 'fa-droplet';
    }
    if (strpos($c, 'refriger') !== false || strpos($c, 'bebida') !== false) {
        return 'fa-glass-water';
    }
    if (strpos($c, 'comida') !== false || strpos($c, 'prato') !== false || strpos($c, 'refeic') !== false) {
        return 'fa-utensils';
    }
    return 'fa-bowl-food';
}

$mesaBalcaoId = (int)($balcaoCanonicoNovoPedido['id'] ?? 0);
$mesaBalcaoLabel = mesa_rotulo($balcaoCanonicoNovoPedido['numero'] ?? 'B1');

// Segundo canal de balcão (Empresarial), quando activado em Mesas.
require_once APP_BASE . '/src/config/balcao_canal.php';
$npBalcao2 = ['ativo' => false, 'nome' => '', 'mesa_id' => 0];
try {
    $npBalcao2 = balcao2_config($db, (int)$rid);
} catch (Throwable $e) {
    error_log('[novo_pedido][balcao2] ' . $e->getMessage());
}

$catCounts = [];
foreach ($por_categoria as $catNome => $listaCat) {
    $catCounts[$catNome] = count($listaCat);
}
$totalProdutosActivos = count($produtos);
$temReservasQrNovo = $restauranteFeatureId > 0 && plano_tem_funcionalidade_db($restauranteFeatureId, 'reservas_qr');

// Contagem de mesas por estado (sem balcao), usada no resumo simples do Profissional.
$npMesasEstado = ['livres' => 0, 'ocupadas' => 0, 'reservadas' => 0];
foreach ($mesas as $mCont) {
    if (function_exists('mesa_eh_balcao') && mesa_eh_balcao((string)($mCont['numero'] ?? ''))) {
        continue;
    }
    $stCont = strtoupper(trim((string)($mCont['status'] ?? '')));
    if ($stCont === 'OCUPADA' || $stCont === 'COM_PEDIDO') {
        $npMesasEstado['ocupadas']++;
    } elseif ($stCont === 'RESERVADA') {
        $npMesasEstado['reservadas']++;
    } else {
        $npMesasEstado['livres']++;
    }
}
$isProfissionalNp = (($plano_atual ?? '') === 'PROFISSIONAL');

$npEmpIntel = null;
if (($plano_atual ?? '') === 'EMPRESARIAL') {
    $mesasLivres = 0;
    $mesasOcupadas = 0;
    $mesasReservadas = 0;
    foreach ($mesas as $mEmp) {
        $st = strtoupper(trim((string)($mEmp['status'] ?? '')));
        if ($st === 'LIVRE' || $st === '') {
            $mesasLivres++;
        } elseif ($st === 'OCUPADA' || $st === 'COM_PEDIDO') {
            $mesasOcupadas++;
        } elseif ($st === 'RESERVADA') {
            $mesasReservadas++;
        }
    }

    $stockBaixo = [];
    $stockEsgotado = 0;
    $catRisco = []; // cat => ['out' => n, 'low' => n]
    if ($temEstoqueAvancado) {
        foreach ($produtos as $pEmp) {
            $est = (int)($pEmp['estoque'] ?? 0);
            $nomeP = trim((string)($pEmp['nome'] ?? 'Produto'));
            $catP = trim((string)($pEmp['categoria'] ?? ''));
            if ($catP === '') {
                $catP = 'Geral';
            }
            if (!isset($catRisco[$catP])) {
                $catRisco[$catP] = ['out' => 0, 'low' => 0];
            }
            if ($est <= 0) {
                $stockEsgotado++;
                $catRisco[$catP]['out']++;
            } elseif ($est <= 3) {
                $stockBaixo[] = $nomeP . ' (' . $est . ')';
                $catRisco[$catP]['low']++;
            }
        }
        $stockBaixo = array_slice($stockBaixo, 0, 3);
    }

    $topHoje = [];
    $filaCozinha = 0;
    if ($db instanceof PDO) {
        try {
            $stmtTop = $db->prepare("
                SELECT p.nome, SUM(pi.quantidade) AS qtd
                FROM pedido_itens pi
                INNER JOIN pedidos ped ON ped.id = pi.pedido_id
                INNER JOIN produtos p ON p.id = pi.produto_id
                WHERE ped.restaurante_id = :rid
                  AND (ped.criado_em >= CURDATE() AND ped.criado_em < CURDATE() + INTERVAL 1 DAY)
                  AND UPPER(TRIM(COALESCE(ped.status, ''))) NOT IN ('CANCELADO', 'CANCELADA')
                GROUP BY p.id, p.nome
                ORDER BY qtd DESC
                LIMIT 3
            ");
            $stmtTop->execute([':rid' => (int)$rid]);
            $topHoje = $stmtTop->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $eTop) {
            try {
                $stmtTop2 = $db->prepare("
                    SELECT p.nome, SUM(vi.quantidade) AS qtd
                    FROM venda_itens vi
                    INNER JOIN vendas v ON v.id = vi.venda_id
                    INNER JOIN produtos p ON p.id = vi.produto_id
                    WHERE v.restaurante_id = :rid
                      AND (v.criado_em >= CURDATE() AND v.criado_em < CURDATE() + INTERVAL 1 DAY)
                      AND UPPER(TRIM(COALESCE(v.status, ''))) = 'PAGO'
                    GROUP BY p.id, p.nome
                    ORDER BY qtd DESC
                    LIMIT 3
                ");
                $stmtTop2->execute([':rid' => (int)$rid]);
                $topHoje = $stmtTop2->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $eTop2) {
                $topHoje = [];
            }
        }
        try {
            $stmtFila = $db->prepare("
                SELECT COUNT(DISTINCT ped.id)
                FROM pedidos ped
                INNER JOIN itens_pedido ip ON ip.pedido_id = ped.id
                WHERE ped.restaurante_id = :rid
                  AND LOWER(TRIM(COALESCE(ip.destino, 'cozinha'))) = 'cozinha'
                  AND LOWER(TRIM(COALESCE(ip.status, 'em_preparo'))) IN ('pendente', 'em_preparo', 'pronto')
                  AND UPPER(TRIM(COALESCE(ped.status, 'NOVO'))) IN (
                      'NOVO', 'PENDENTE', 'CONFIRMADO', 'PREPARANDO', 'PRONTO', 'PAGO'
                  )
            ");
            $stmtFila->execute([':rid' => (int)$rid]);
            $filaCozinha = (int)$stmtFila->fetchColumn();
        } catch (Throwable $eFila) {
            error_log('[NOVO_PEDIDO][FILA_COZINHA] ' . $eFila->getMessage());
            $filaCozinha = 0;
        }
    }

    $notasNp = [];
    if ($stockEsgotado > 0) {
        $notasNp[] = [
            'tom' => 'warn',
            'chave' => 'stock_out',
            'titulo' => $stockEsgotado . ' produto(s) sem porções agora',
            'txt' => 'Evite vender o que a ficha já não cobre. Repor insumos ou ocultar no cardápio.',
        ];
    }
    if ($stockBaixo) {
        $notasNp[] = [
            'tom' => 'warn',
            'titulo' => 'Stock a acabar: ' . implode(', ', $stockBaixo),
            'txt' => 'Abaixo de 3 porções. Avise a cozinha antes do pico.',
        ];
    }
    if ($mesasOcupadas > 0 && $mesasLivres === 0) {
        $notasNp[] = [
            'tom' => 'warn',
            'titulo' => 'Salão cheio — 0 mesas livres',
            'txt' => 'Use Take Away/Balcão ou peça ao salão para libertar mesas.',
        ];
    } elseif ($mesasLivres > 0) {
        $notasNp[] = [
            'tom' => 'ok',
            'chave' => 'mesas_ok',
            'titulo' => $mesasLivres . ' mesa(s) livre(s) no salão',
            'txt' => $mesasOcupadas . ' ocupada(s)' . ($mesasReservadas > 0 ? ' · ' . $mesasReservadas . ' reservada(s)' : '') . '.',
        ];
    }
    if ($filaCozinha >= 5) {
        $notasNp[] = [
            'tom' => 'info',
            'titulo' => 'Cozinha com ' . $filaCozinha . ' pedido(s) na fila',
            'txt' => 'Agrupe itens semelhantes e confirme observações antes de enviar.',
        ];
    }
    if (!empty($topHoje)) {
        $nomesTop = array_map(static fn($r) => (string)($r['nome'] ?? ''), $topHoje);
        $notasNp[] = [
            'tom' => 'info',
            'titulo' => 'Mais pedidos hoje: ' . implode(', ', array_filter($nomesTop)),
            'txt' => 'Sugira estes ao cliente — já estão a sair bem e a cozinha está aquecida neles.',
        ];
    }
    if (empty($notasNp)) {
        $notasNp[] = [
            'tom' => 'ok',
            'titulo' => 'Pronto para montar pedidos',
            'txt' => 'Escolha mesa, adicione itens e envie. A leitura da unidade actualiza ao refrescar.',
        ];
    }

    $npEmpIntel = [
        'mesas_livres' => $mesasLivres,
        'mesas_ocupadas' => $mesasOcupadas,
        'mesas_reservadas' => $mesasReservadas,
        'fila' => $filaCozinha,
        'stock_esgotado' => $stockEsgotado,
        'top' => $topHoje,
        'notas' => array_slice($notasNp, 0, 3),
        'cat_risco' => $catRisco,
    ];
}
?>
<!DOCTYPE html>
<html lang="pt">

<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Novo Pedido – Garçom</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">

    <link href="lib/bootstrap-5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="lib/fontawesome-6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #FF6B35;
            --primary-dark: #e55a2b;
            --bg: #f8fafc;
            --bg-soft: linear-gradient(135deg, #fff7f1 0%, #f8fafc 100%);
            --text: #1e293b;
            --border: #e2e8f0;
            --card-bg: #ffffff;
            --shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            --shadow-lg: 0 18px 40px rgba(15, 23, 42, 0.08);
        }

        body {
            font-family: 'Outfit', 'Segoe UI', 'Noto Sans', system-ui, -apple-system, 'Helvetica Neue', Arial, sans-serif;
            background: var(--bg-soft);
            color: var(--text);
            min-height: 100vh;
        }

        .topbar {
            background: #fff;
            padding: 14px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: var(--shadow);
        }

        .topbar h1 {
            font-family: 'Space Grotesk', 'Segoe UI Semibold', 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
            font-size: 20px;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .topbar h1 i {
            color: var(--primary);
        }

        .hero-panel {
            margin: 20px auto 0;
            max-width: 1400px;
            padding: 20px 24px;
            background: linear-gradient(135deg, rgba(255, 107, 53, 0.12), rgba(255, 255, 255, 0.96));
            border: 1px solid rgba(255, 107, 53, 0.16);
            border-radius: 22px;
            box-shadow: var(--shadow-lg);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }

        .hero-panel h2 {
            font-family: 'Space Grotesk', 'Segoe UI Semibold', 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
            font-size: 22px;
            font-weight: 700;
            margin: 0 0 6px;
        }

        .hero-panel p {
            margin: 0;
            color: #64748b;
            font-size: 14px;
        }

        .hero-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(255, 107, 53, 0.12);
            color: var(--primary-dark);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.3px;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .hero-highlights {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .hero-kpi {
            min-width: 120px;
            background: rgba(255, 255, 255, 0.86);
            border: 1px solid rgba(226, 232, 240, 0.9);
            border-radius: 14px;
            padding: 10px 12px;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.05);
        }

        .hero-kpi .label {
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 4px;
        }

        .hero-kpi .value {
            font-size: 14px;
            font-weight: 700;
            color: var(--text);
        }

        .content {
            display: grid;
            grid-template-columns: 1fr 360px;
            gap: 20px;
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }

        @media(max-width:900px) {
            .content {
                grid-template-columns: 1fr;
            }
        }

        /* Produtos */
        .section-title {
            font-family: 'Space Grotesk', 'Segoe UI Semibold', 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #64748b;
            margin: 16px 0 10px;
            padding: 10px 12px;
            background: rgba(255, 255, 255, 0.72);
            border: 1px solid rgba(226, 232, 240, 0.8);
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .produtos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 12px;
            margin-bottom: 18px;
            min-height: 40px;
        }

        #produtos-area {
            min-width: 0;
            width: 100%;
        }

        .produto-btn {
            background: var(--card-bg);
            border: 1.5px solid var(--border);
            border-radius: 16px;
            padding: 10px 10px 12px;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
            position: relative;
            overflow: hidden;
            display: block;
            width: 100%;
            color: var(--text);
            visibility: visible;
            opacity: 1;
        }

        .produto-btn::before {
            content: '';
            position: absolute;
            inset: 0 0 auto 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), #f59e0b);
        }

        .produto-btn:hover {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.12);
            transform: translateY(-2px);
        }

        /* Stock no ecra padrao: esgotado nao se clica, pouco stock fica avisado. */
        .produto-btn.is-out { opacity: .55; cursor: not-allowed; border-style: dashed; }
        .produto-btn.is-out:hover { transform: none; box-shadow: none; }
        .produto-btn.is-low { border-color: #f59e0b; }
        .produto-btn .p-stock-tag {
            position: absolute;
            top: 6px;
            left: 6px;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 999px;
            background: #fee2e2;
            color: #b91c1c;
            z-index: 2;
        }
        .produto-btn .p-stock-tag.low { background: #fef3c7; color: #92400e; }
        .np-mesas-resumo {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 8px;
            font-size: 12px;
            color: #64748b;
        }
        .np-mesas-resumo span { display: inline-flex; align-items: center; gap: 4px; }
        .np-mesas-resumo span::before {
            content: '';
            width: 9px;
            height: 9px;
            border-radius: 3px;
            display: inline-block;
            background: #cbd5e1;
        }
        .np-mesas-resumo .is-livre::before { background: #22c55e; }
        .np-mesas-resumo .is-ocupada::before { background: #f59e0b; }
        .np-mesas-resumo .is-reservada::before { background: #6366f1; }
        .np-mesas-resumo strong { color: #0f172a; }

        .produto-btn .p-img-wrap {
            width: 100%;
            aspect-ratio: 1 / 1;
            border-radius: 10px;
            overflow: hidden;
            background: #f1f5f9;
            margin-bottom: 8px;
        }

        .produto-btn .p-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .produto-btn .p-nome {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 4px;
        }

        .produto-btn .p-preco {
            color: var(--primary);
            font-weight: 700;
            font-size: 13px;
        }

        /* Carrinho */
        .cart-panel {
            background: linear-gradient(180deg, #ffffff 0%, #fffaf6 100%);
            border-radius: 24px;
            border: 1px solid rgba(255, 107, 53, 0.14);
            box-shadow: var(--shadow-lg);
            display: flex;
            flex-direction: column;
            position: sticky;
            top: 80px;
            max-height: calc(100vh - 100px);
        }

        .cart-panel .cart-header {
            padding: 18px 20px 12px;
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
            font-family: 'Space Grotesk', 'Segoe UI Semibold', 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
            font-size: 16px;
            font-weight: 700;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .cart-body {
            flex: 1;
            overflow-y: auto;
            padding: 12px 16px;
        }

        .mesa-select {
            margin-bottom: 14px;
        }

        .cart-empty {
            text-align: center;
            color: #94a3b8;
            padding: 30px 10px;
            font-size: 13px;
        }

        .cart-empty i {
            font-size: 32px;
            display: block;
            margin-bottom: 8px;
        }

        .cart-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 0;
            border-bottom: 1px solid var(--border);
        }

        .cart-item:last-child {
            border-bottom: none;
        }

        .cart-item .ci-nome {
            flex: 1;
            font-size: 14px;
            font-weight: 500;
        }

        .cart-item .ci-preco {
            font-size: 13px;
            color: #64748b;
        }

        .qty-ctrl {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .qty-btn {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            border: 1.5px solid var(--border);
            background: #f1f5f9;
            cursor: pointer;
            font-size: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.15s;
        }

        .qty-btn:hover {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }

        .qty-num {
            font-weight: 700;
            font-size: 14px;
            min-width: 20px;
            text-align: center;
        }

        .cart-footer {
            padding: 14px 16px;
            border-top: 1px solid var(--border);
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            font-weight: 700;
            font-size: 17px;
            margin-bottom: 12px;
        }

        .total-row span:last-child {
            color: var(--primary);
        }

        .obs-field {
            margin-bottom: 12px;
        }

        .obs-field label {
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
        }

        .obs-field textarea {
            font-size: 13px;
            resize: none;
            border-radius: 10px;
        }

        .stock-check-box {
            border-radius: 10px;
            border: 1px solid #fecaca;
            background: #fff1f2;
            color: #991b1b;
            padding: 10px 12px;
            font-size: 12px;
            margin-bottom: 10px;
            display: none;
        }

        .stock-check-box ul {
            margin: 6px 0 0 18px;
            padding: 0;
        }

        .btn-enviar {
            width: 100%;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 12px;
            padding: 14px;
            font-weight: 700;
            font-size: 15px;
            cursor: pointer;
            transition: background 0.2s;
        }

        .btn-enviar:hover:not(:disabled) {
            background: var(--primary-dark);
        }

        .btn-enviar:disabled {
            opacity: 0.6;
            cursor: default;
        }

        .toast-container {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 9999;
        }

        /* Responsividade para possível menu lateral futuro */
        .sidebar.sidebar-hidden {
            left: -100vw !important;
            transition: left 0.3s cubic-bezier(.4, 0, .2, 1);
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 80vw;
                max-width: 340px;
                min-width: 220px;
                position: fixed;
                left: 0;
                top: 0;
                z-index: 2000;
                height: 100vh;
                transition: left 0.3s cubic-bezier(.4, 0, .2, 1);
                box-shadow: 2px 0 16px rgba(0, 0, 0, 0.08);
                display: flex;
            }
        }

        @media (min-width: 1200px) {
            .content {
                gap: 16px;
                padding: 16px;
                max-width: 1320px;
            }

            .topbar {
                padding: 12px 18px;
            }

            .produto-btn {
                border-radius: 12px;
                padding: 8px 8px 10px;
            }

            .cart-panel {
                border-radius: 14px;
                box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
            }
        }
    </style>
    <link rel="stylesheet" href="css/premium.css">
    <?php empresarial_head_assets(); ?>
    <?php if (!empty($isEmpresarialUi)): ?>
        <link rel="stylesheet" href="css/novo-pedido-empresarial.css?v=<?php echo (int)@filemtime(__DIR__ . "/css/novo-pedido-empresarial.css"); ?>">
    <?php endif; ?>
</head>

<body class="<?php echo htmlspecialchars(empresarial_body_class('premium-ui pedido-builder-page' . (!empty($isEmpresarialUi) ? ' pedido-emp-shell' : ''))); ?>">

    <?php
    $perfilAtual = normalizePerfil($_SESSION['perfil'] ?? '');
    $mostrarCozinha = $temTelasBarCozinha && in_array($perfilAtual, ['ADMIN', 'GERENTE', 'COZINHA'], true);
    $mostrarBar = $temTelasBarCozinha && in_array($perfilAtual, ['ADMIN', 'GERENTE', 'BAR'], true);
    ?>

<?php if (!empty($isEmpresarialUi)): ?>
    <!-- ===== LAYOUT EMPRESARIAL (mockup) ===== -->
    <div class="np-topbar">
        <div class="np-brand">
            <div class="np-brand-icon"><i class="fas fa-utensils"></i></div>
            <div>
                <h1>Novo Pedido <?php include __DIR__ . '/includes/plan_page_badge.php'; ?></h1>
                <p class="np-tagline">Peça. Acompanhe. Receba.</p>
            </div>
        </div>
        <div class="np-nav">
            <?php if ($mostrarCozinha): ?>
                <a href="cozinha.php" class="btn btn-sm btn-cozinha"><i class="fas fa-fire me-1"></i>Cozinha</a>
            <?php endif; ?>
            <?php if ($mostrarBar): ?>
                <a href="bar.php" class="btn btn-sm btn-outline-info"><i class="fas fa-martini-glass-citrus me-1"></i>Bar</a>
            <?php endif; ?>
            <?php if ($temPedidosOnline): ?>
                <a href="pedidos.php" class="btn btn-sm btn-outline-success"><i class="fas fa-list me-1"></i>Pedidos</a>
            <?php endif; ?>
            <?php if ($temReservasQrNovo): ?>
                <a href="mesas.php" class="btn btn-sm btn-outline-warning"><i class="fas fa-calendar-check me-1"></i>Reservas</a>
            <?php endif; ?>
            <a href="dashboard.php" class="btn btn-sm btn-outline-secondary" title="Início"><i class="fas fa-home"></i></a>
        </div>
    </div>

    <div class="np-toolbar">
        <div class="np-search">
            <i class="fas fa-search"></i>
            <input type="search" id="empBuscaProduto" placeholder="Pesquisar produtos…" autocomplete="off">
        </div>
        <?php if ($temHistoricoClientes): ?>
            <input type="text" id="empClienteNome" class="form-control" style="max-width:220px;min-height:44px;border-radius:12px;" placeholder="Nome do cliente (opcional)" autocomplete="off">
        <?php endif; ?>
        <div class="np-toolbar-meta">
            <span class="np-count" id="produtosCountLabel"><?php echo (int)$totalProdutosActivos; ?> produtos disponíveis</span>
            <?php if ($temPedidosOnline): ?>
                <a class="np-link-fila" href="pedidos.php">Ver fila de pedidos <i class="fas fa-arrow-right ms-1"></i></a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (is_array($npEmpIntel)): ?>
    <div class="np-intel">
        <div class="np-intel-kpis">
            <div><span>Mesas livres</span><strong><?php echo (int)$npEmpIntel['mesas_livres']; ?></strong></div>
            <div><span>Ocupadas</span><strong><?php echo (int)$npEmpIntel['mesas_ocupadas']; ?></strong></div>
            <div><span>Fila cozinha</span><strong id="npFilaCozinhaCount"><?php echo (int)$npEmpIntel['fila']; ?></strong></div>
            <div><span>Sem stock</span><strong><?php echo (int)$npEmpIntel['stock_esgotado']; ?></strong></div>
        </div>
        <?php if (!empty($npEmpIntel['top'])): ?>
        <div class="np-intel-tops">
            <span class="np-intel-tops-label">Sugestões de hoje</span>
            <?php foreach ($npEmpIntel['top'] as $topRow): ?>
                <button type="button" class="np-top-chip" data-top-nome="<?php echo htmlspecialchars((string)($topRow['nome'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars((string)($topRow['nome'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                    <em><?php echo (int)($topRow['qtd'] ?? 0); ?>×</em>
                </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php
        $npNotasVisiveis = array_filter((array)($npEmpIntel['notas'] ?? []), static fn($n) => !in_array((string)($n['chave'] ?? ''), ['stock_out', 'mesas_ok'], true));
        ?>
        <?php if (!empty($npNotasVisiveis)): ?>
        <ul class="np-intel-notes">
            <?php foreach ($npEmpIntel['notas'] as $nn):
                // Já visíveis nos cartões (Mesas livres / Sem stock) ou na coluna das categorias
                if (in_array((string)($nn['chave'] ?? ''), ['stock_out', 'mesas_ok'], true)) { continue; } ?>
                <li class="tom-<?php echo htmlspecialchars((string)($nn['tom'] ?? 'info')); ?>">
                    <strong><?php echo htmlspecialchars((string)($nn['titulo'] ?? '')); ?></strong>
                    <span><?php echo htmlspecialchars((string)($nn['txt'] ?? '')); ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="np-shell">
        <aside class="np-cats" aria-label="Categorias">
            <div class="np-cats-title">
                Categorias
                <?php if (is_array($npEmpIntel) && (int)$npEmpIntel['stock_esgotado'] > 0): ?>
                    <span class="np-cats-risk-total" title="Produtos sem porções"><?php echo (int)$npEmpIntel['stock_esgotado']; ?> risco</span>
                <?php endif; ?>
            </div>
            <?php
            $riscoTodosOut = is_array($npEmpIntel) ? (int)$npEmpIntel['stock_esgotado'] : 0;
            $riscoTodosLow = 0;
            if (is_array($npEmpIntel) && !empty($npEmpIntel['cat_risco'])) {
                foreach ($npEmpIntel['cat_risco'] as $cr) {
                    $riscoTodosLow += (int)($cr['low'] ?? 0);
                }
            }
            ?>
            <button type="button" class="np-cat-btn is-active<?php echo $riscoTodosOut > 0 ? ' has-risk' : ($riscoTodosLow > 0 ? ' has-low' : ''); ?>" data-cat="*">
                <span class="left"><i class="fas fa-border-all"></i> Todos</span>
                <span class="np-cat-badges">
                    <?php if ($riscoTodosOut > 0): ?>
                        <span class="np-cat-risk" title="Esgotados"><?php echo $riscoTodosOut; ?></span>
                    <?php elseif ($riscoTodosLow > 0): ?>
                        <span class="np-cat-low" title="Stock baixo"><?php echo $riscoTodosLow; ?></span>
                    <?php endif; ?>
                    <span class="count"><?php echo (int)$totalProdutosActivos; ?></span>
                </span>
            </button>
            <?php foreach ($catCounts as $catNome => $qtdCat):
                $crCat = (is_array($npEmpIntel) && isset($npEmpIntel['cat_risco'][$catNome]))
                    ? $npEmpIntel['cat_risco'][$catNome]
                    : ['out' => 0, 'low' => 0];
                $outCat = (int)($crCat['out'] ?? 0);
                $lowCat = (int)($crCat['low'] ?? 0);
                $riskClass = $outCat > 0 ? ' has-risk' : ($lowCat > 0 ? ' has-low' : '');
                ?>
                <button type="button" class="np-cat-btn<?php echo $riskClass; ?>" data-cat="<?php echo htmlspecialchars($catNome, ENT_QUOTES, 'UTF-8'); ?>">
                    <span class="left"><i class="fas <?php echo htmlspecialchars(novo_pedido_cat_icon($catNome), ENT_QUOTES, 'UTF-8'); ?>"></i> <?php echo htmlspecialchars($catNome, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="np-cat-badges">
                        <?php if ($outCat > 0): ?>
                            <span class="np-cat-risk" title="<?php echo $outCat; ?> esgotado(s)">!</span>
                        <?php elseif ($lowCat > 0): ?>
                            <span class="np-cat-low" title="<?php echo $lowCat; ?> com stock baixo"><?php echo $lowCat; ?></span>
                        <?php endif; ?>
                        <span class="count"><?php echo (int)$qtdCat; ?></span>
                    </span>
                </button>
            <?php endforeach; ?>
            <div class="np-cat-promo<?php echo (is_array($npEmpIntel) && (int)$npEmpIntel['stock_esgotado'] > 0) ? ' is-risk' : ''; ?>">
                <?php if (is_array($npEmpIntel) && !empty($npEmpIntel['notas'][0])): ?>
                    <strong><?php echo htmlspecialchars((string)$npEmpIntel['notas'][0]['titulo']); ?></strong>
                    <span><?php echo htmlspecialchars((string)($npEmpIntel['notas'][0]['txt'] ?? '')); ?></span>
                <?php else: ?>
                    <strong>Dica rápida</strong>
                    <span>Use categorias e a pesquisa para montar o pedido em segundos.</span>
                <?php endif; ?>
            </div>
        </aside>

        <main class="np-main">
            <div class="np-section-head">
                <h2 id="npSectionTitle">Todos</h2>
            </div>
            <?php if (empty($produtos)): ?>
                <div class="alert alert-warning mb-0">
                    <strong>Nenhum produto activo.</strong>
                    <div class="small mt-1">Cadastre em <a href="produtos.php">Produtos</a>.</div>
                </div>
            <?php else: ?>
                <div class="np-grid" id="produtos-area">
                    <?php foreach ($produtos as $p):
                        $nomeProd = (string)($p['nome'] ?? 'Produto');
                        $catProd = trim((string)($p['categoria'] ?? '')) ?: 'Geral';
                        $nomeBusca = novo_pedido_nome_busca($nomeProd . ' ' . $catProd);
                        $imgSrc = novo_pedido_img_src($p, $base_url);
                        $nomeJson = json_encode($nomeProd, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP);
                        if ($nomeJson === false) {
                            $nomeJson = json_encode('Produto');
                        }
                        $pid = (int)$p['id'];
                        $preco = (float)$p['preco'];
                        $estProd = (int)($p['estoque'] ?? 0);
                        $semFichaNp = !empty($p['sem_ficha']);
                        $stockState = 'ok';
                        $stockLabel = 'Disponível';
                        if ($temEstoqueAvancado) {
                            if ($semFichaNp) {
                                $stockState = 'out';
                                $stockLabel = 'Sem ficha';
                            } elseif ($estProd <= 0) {
                                $stockState = 'out';
                                $stockLabel = 'Esgotado';
                            } elseif ($estProd <= 3) {
                                $stockState = 'low';
                                $stockLabel = 'Restam ' . $estProd;
                            }
                        }
                        $cardDisabled = ($stockState === 'out');
                        ?>
                        <article class="np-card produto-btn<?php echo $cardDisabled ? ' is-out' : ($stockState === 'low' ? ' is-low' : ''); ?>"
                            data-id="<?php echo $pid; ?>"
                            data-nome="<?php echo htmlspecialchars($nomeBusca, ENT_QUOTES, 'UTF-8'); ?>"
                            data-cat="<?php echo htmlspecialchars($catProd, ENT_QUOTES, 'UTF-8'); ?>"
                            data-preco="<?php echo htmlspecialchars((string)$preco, ENT_QUOTES, 'UTF-8'); ?>"
                            data-estoque="<?php echo $temEstoqueAvancado ? $estProd : ''; ?>"
                            data-sem-ficha="<?php echo $semFichaNp ? '1' : '0'; ?>"
                            data-label="<?php echo htmlspecialchars($nomeProd, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="np-card-img" role="button" tabindex="0" title="<?php echo $semFichaNp ? 'Sem ficha técnica' : ($cardDisabled ? 'Sem stock' : 'Adicionar ao pedido'); ?>" <?php if (!$cardDisabled): ?>onclick='addItem(<?php echo $pid; ?>, <?php echo $nomeJson; ?>, <?php echo $preco; ?>)'<?php endif; ?>>
                                <img src="<?php echo htmlspecialchars($imgSrc, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($nomeProd, ENT_QUOTES, 'UTF-8'); ?>" loading="lazy" data-fallback="<?php echo htmlspecialchars(novo_pedido_img_iniciais($nomeProd), ENT_QUOTES, 'UTF-8'); ?>" onerror="this.onerror=null;this.src=this.dataset.fallback;">
                            </div>
                            <div class="np-card-body">
                                <div class="np-card-name" role="button" tabindex="0" title="<?php echo $semFichaNp ? 'Sem ficha técnica' : ($cardDisabled ? 'Sem stock' : 'Adicionar ao pedido'); ?>" <?php if (!$cardDisabled): ?>onclick='addItem(<?php echo $pid; ?>, <?php echo $nomeJson; ?>, <?php echo $preco; ?>)'<?php endif; ?>><?php echo htmlspecialchars($nomeProd, ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="np-card-meta">
                                    <span class="np-card-price">MZN <?php echo number_format($preco, 2, ',', '.'); ?></span>
                                    <span class="np-badge-<?php echo $stockState === 'ok' ? 'ok' : ($stockState === 'low' ? 'low' : 'out'); ?>">
                                        <i class="fas fa-<?php echo $stockState === 'ok' ? 'check' : ($stockState === 'low' ? 'exclamation' : 'ban'); ?>"></i>
                                        <?php echo htmlspecialchars($stockLabel, ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </div>
                                <div class="np-qty" data-id="<?php echo $pid; ?>">
                                    <button type="button" class="np-qty-minus" aria-label="Diminuir" onclick="event.stopPropagation(); changeQty(<?php echo $pid; ?>, -1)" <?php echo $cardDisabled ? 'disabled' : ''; ?>>−</button>
                                    <span class="np-qty-val" data-qty-for="<?php echo $pid; ?>">0</span>
                                    <button type="button" class="np-qty-plus" aria-label="Aumentar" <?php if ($cardDisabled): ?>disabled<?php else: ?>onclick='event.stopPropagation(); addItem(<?php echo $pid; ?>, <?php echo $nomeJson; ?>, <?php echo $preco; ?>)'<?php endif; ?>>+</button>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>

        <aside class="np-cart cart-panel">
            <div class="np-cart-head cart-header">
                <i class="fas fa-shopping-cart" style="color:#ea580c"></i> Meu Pedido
            </div>
            <div class="np-cart-note">
                <?php if ($temTelasBarCozinha): ?>
                    Este pedido será preparado na cozinha<?php echo is_array($npEmpIntel) && (int)$npEmpIntel['fila'] > 0 ? ' · fila actual: ' . (int)$npEmpIntel['fila'] : ''; ?>.
                <?php else: ?>
                    Este pedido será registado no painel de pedidos.
                <?php endif; ?>
            </div>
            <div class="np-cart-body">
                <div class="mesa-select np-mesa-wrap" id="npMesaWrap">
                    <label class="form-label fw-bold" style="font-size:12px">Mesa</label>
                    <select id="sel-mesa" class="form-select form-select-sm np-mesa-select-oculto" required>
                        <option value="">Selecionar mesa</option>
                        <?php foreach ($mesas as $m): ?>
                            <?php $numeroMesa = (string)($m['numero'] ?? ''); ?>
                            <?php if (mesa_eh_balcao($numeroMesa)) { continue; } ?>
                            <option value="<?php echo (int)$m['id']; ?>"
                                <?php echo ($m['status'] === 'OCUPADA') ? 'style="color:#f59e0b"' : ''; ?>>
                                <?php echo htmlspecialchars(mesa_rotulo($numeroMesa), ENT_QUOTES, 'UTF-8'); ?>
                                <?php echo ($m['status'] === 'OCUPADA') ? ' ⚠ Ocupada' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="np-mesa-btns" id="npMesaBtns" role="group" aria-label="Escolher mesa">
                        <?php foreach ($mesas as $m): ?>
                            <?php $numeroMesa = (string)($m['numero'] ?? ''); ?>
                            <?php if (mesa_eh_balcao($numeroMesa)) { continue; } ?>
                            <?php $ocupadaBtn = (($m['status'] ?? '') === 'OCUPADA'); ?>
                            <button type="button" class="np-mesa-btn<?php echo $ocupadaBtn ? ' is-ocupada' : ''; ?>" data-mesa-id="<?php echo (int)$m['id']; ?>"
                                title="<?php echo htmlspecialchars(mesa_rotulo($numeroMesa) . ($ocupadaBtn ? ' · ocupada (junta ao pedido aberto)' : ' · livre'), ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($numeroMesa, ENT_QUOTES, 'UTF-8'); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <div class="np-mesa-legenda"><span class="is-livre"></span>Livre <span class="is-ocupada"></span>Ocupada</div>
                </div>
                <div id="cart-items">
                    <div class="np-cart-empty cart-empty">
                        <i class="fas fa-utensils"></i>
                        Selecione produtos para montar o pedido
                    </div>
                </div>
            </div>
            <div class="np-cart-foot cart-footer">
                <div class="np-total-row total-row">
                    <span>Total</span>
                    <span id="cart-total">MZN 0,00</span>
                </div>
                <div class="obs-field mb-2">
                    <label for="obs-pedido" class="form-label" style="font-size:12px;font-weight:700;">Observação (opcional)</label>
                    <textarea id="obs-pedido" class="form-control" rows="2" maxlength="100"
                        placeholder="Observações para a cozinha"></textarea>
                </div>
                <label class="np-takeaway <?php echo $mesaBalcaoId > 0 ? '' : 'opacity-75'; ?>" id="npTakeawayBox">
                    <input type="checkbox" id="npTakeaway" <?php echo $mesaBalcaoId > 0 ? '' : 'disabled'; ?>>
                    <span>
                        <strong>Take Away — sem mesa de salão</strong>
                        <small><?php echo $mesaBalcaoId > 0
                            ? ('Usa o canal automático “' . htmlspecialchars($mesaBalcaoLabel, ENT_QUOTES, 'UTF-8') . '”.')
                            : 'Canal de Balcão / Take-away indisponível.'; ?></small>
                    </span>
                </label>
                <?php if (!empty($npBalcao2['ativo']) && $mesaBalcaoId > 0): ?>
                    <div class="np-balcao-canais d-none" id="npBalcaoCanais">
                        <label><input type="radio" name="npCanalBalcao" value="<?php echo (int)$mesaBalcaoId; ?>" checked> Balcão</label>
                        <label><input type="radio" name="npCanalBalcao" value="<?php echo (int)$npBalcao2['mesa_id']; ?>"> <?php echo htmlspecialchars((string)$npBalcao2['nome']); ?></label>
                    </div>
                <?php endif; ?>
                <div id="stock-check-box" class="stock-check-box"></div>
                <button type="button" class="np-btn-send btn-enviar" id="btn-enviar" onclick="enviarPedido()" disabled>
                    <i class="fas fa-paper-plane me-2"></i> Enviar Pedido
                </button>
            </div>
        </aside>
    </div>

<?php else: ?>
    <!-- ===== LAYOUT PADRÃO (não Empresarial) ===== -->
    <div class="topbar">
        <h1><i class="fas fa-clipboard-list"></i> Novo Pedido <?php include __DIR__ . '/includes/plan_page_badge.php'; ?></h1>
        <div class="d-flex gap-2">
            <?php if ($mostrarCozinha): ?>
                <a href="cozinha.php" class="btn btn-sm btn-outline-warning"><i class="fas fa-fire me-1"></i> Cozinha</a>
            <?php endif; ?>
            <?php if ($mostrarBar): ?>
                <a href="bar.php" class="btn btn-sm btn-outline-info"><i class="fas fa-martini-glass-citrus me-1"></i> Bar</a>
            <?php endif; ?>
            <?php if ($temPedidosOnline): ?>
                <a href="pedidos.php" class="btn btn-sm btn-outline-warning"><i class="fas fa-list me-1"></i> Pedidos</a>
            <?php endif; ?>
            <a href="dashboard.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-home"></i></a>
        </div>
    </div>

    <?php if (!empty($avisoFluxoBasico) || !$temTelasBarCozinha): ?>
        <div class="alert alert-light border mx-3 mt-3 mb-0" style="border-radius:12px;max-width:960px;">
            <strong>Fluxo Básico:</strong> crie o pedido aqui no <strong>PDV</strong>. Depois abra o <strong>Caixa</strong> e receba o pagamento em <strong>Vendas → Pedidos para Receber</strong>.
            Telas Bar e Cozinha estão disponíveis a partir do plano Profissional.
        </div>
    <?php endif; ?>

    <div class="hero-panel">
        <div>
            <div class="hero-eyebrow"><i class="fas fa-bolt me-1"></i> Fluxo executivo</div>
            <h2>Montar pedido com rapidez e controlo</h2>
            <p>
                <?php if ($temTelasBarCozinha): ?>
                    Selecione a mesa, adicione itens e envie tudo com um fluxo claro para a cozinha e para o salão.
                <?php else: ?>
                    Selecione a mesa, adicione itens e registe o pedido no painel de pedidos.
                <?php endif; ?>
            </p>
        </div>
        <div class="hero-highlights">
            <div class="hero-kpi">
                <div class="label">Mesa</div>
                <div class="value" id="heroMesaLabel">Aguardando seleção</div>
            </div>
            <div class="hero-kpi">
                <div class="label">Itens</div>
                <div class="value" id="heroItensLabel">0 itens</div>
            </div>
            <div class="hero-kpi">
                <div class="label">Total</div>
                <div class="value" id="heroTotalLabel">MZN 0,00</div>
            </div>
        </div>
    </div>

    <div class="content">
        <div id="produtos-area">
            <?php if (!empty($promocoes)): ?>
                <div class="section-title"><i class="fas fa-tags me-2"></i>Promocoes</div>
                <div class="produtos-grid">
                    <?php foreach ($promocoes as $promo):
                        $promoNome = (string)$promo['nome'];
                        $promoItensResumo = implode(' + ', array_map(static fn($i) => (int)$i['quantidade'] . 'x ' . $i['nome'], $promo['itens']));
                        $promoJson = json_encode(['id' => (int)$promo['id'], 'nome' => $promoNome, 'preco' => (float)$promo['preco_promocional'], 'itens' => $promo['itens']], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP);
                    ?>
                    <button type="button" class="produto-btn" data-nome="<?= htmlspecialchars(novo_pedido_nome_busca($promoNome), ENT_QUOTES, 'UTF-8') ?>" onclick='addPromotion(<?= $promoJson ?>)'>
                        <div class="p-img-wrap d-flex align-items-center justify-content-center bg-warning-subtle"><i class="fas fa-tags fa-2x text-warning-emphasis"></i></div>
                        <div class="p-nome"><?= htmlspecialchars($promoNome, ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="small text-muted px-2"><?= htmlspecialchars($promoItensResumo, ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="p-preco">MZN <?= number_format((float)$promo['preco_promocional'], 2, ',', '.') ?></div>
                    </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if (empty($produtos)): ?>
                <div class="alert alert-warning mt-3 mb-0">
                    <strong>Nenhum produto activo encontrado.</strong>
                    <div class="small mt-1">Cadastre ou reactive produtos em <a href="produtos.php">Produtos</a>.</div>
                </div>
            <?php else: ?>
                <?php foreach ($por_categoria as $cat => $prods): ?>
                    <div class="section-title"><?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="produtos-grid">
                        <?php foreach ($prods as $p):
                            $nomeProd = (string)($p['nome'] ?? 'Produto');
                            $nomeBusca = novo_pedido_nome_busca($nomeProd);
                            $imgSrc = novo_pedido_img_src($p, $base_url);
                            $nomeJson = json_encode($nomeProd, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP);
                            if ($nomeJson === false) {
                                $nomeJson = json_encode('Produto');
                            }
                            ?>
                            <?php
                            // Estado do stock tambem no ecra padrao: esgotado nao se pode vender.
                            $estProdStd = (int)($p['estoque'] ?? 0);
                            $semFichaStd = !empty($p['sem_ficha']);
                            $estadoStd = 'ok';
                            $rotuloStd = '';
                            if ($temEstoqueAvancado) {
                                if ($semFichaStd) {
                                    $estadoStd = 'out';
                                    $rotuloStd = 'Sem ficha';
                                } elseif ($estProdStd <= 0) {
                                    $estadoStd = 'out';
                                    $rotuloStd = 'Esgotado';
                                } elseif ($estProdStd <= 3) {
                                    $estadoStd = 'low';
                                    $rotuloStd = 'Restam ' . $estProdStd;
                                }
                            }
                            $bloqueadoStd = ($estadoStd === 'out');
                            ?>
                            <button type="button"
                                class="produto-btn<?php echo $bloqueadoStd ? ' is-out' : ($estadoStd === 'low' ? ' is-low' : ''); ?>"
                                data-nome="<?php echo htmlspecialchars($nomeBusca, ENT_QUOTES, 'UTF-8'); ?>"
                                data-id="<?php echo (int)$p['id']; ?>"
                                data-preco="<?php echo htmlspecialchars((string)(float)$p['preco'], ENT_QUOTES, 'UTF-8'); ?>"
                                <?php if ($bloqueadoStd): ?>
                                    disabled title="<?php echo $semFichaStd ? 'Sem ficha técnica' : 'Sem stock'; ?>"
                                <?php else: ?>
                                    onclick='addItem(<?php echo (int)$p['id']; ?>, <?php echo $nomeJson; ?>, <?php echo (float)$p['preco']; ?>)'
                                <?php endif; ?>>
                                <div class="p-img-wrap">
                                    <img class="p-img" src="<?php echo htmlspecialchars($imgSrc, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($nomeProd, ENT_QUOTES, 'UTF-8'); ?>" loading="lazy" decoding="async" data-fallback="<?php echo htmlspecialchars(novo_pedido_img_iniciais($nomeProd), ENT_QUOTES, 'UTF-8'); ?>" onerror="this.onerror=null;this.src=this.dataset.fallback;">
                                    <?php if ($rotuloStd !== ''): ?>
                                        <span class="p-stock-tag <?php echo $estadoStd; ?>"><?php echo htmlspecialchars($rotuloStd, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="p-nome"><?php echo htmlspecialchars($nomeProd, ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="p-preco">MZN <?php echo number_format((float)$p['preco'], 2, ',', '.'); ?></div>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="cart-panel">
            <div class="cart-header">
                <i class="fas fa-shopping-cart me-2" style="color:var(--primary)"></i>Pedido
            </div>
            <div class="cart-body">
                <div class="mesa-select">
                    <label class="form-label fw-bold" style="font-size:13px">Mesa</label>
                    <?php if ($isProfissionalNp): ?>
                        <div class="np-mesas-resumo" aria-label="Mesas por estado">
                            <span class="is-livre"><strong><?php echo (int)$npMesasEstado['livres']; ?></strong> livres</span>
                            <span class="is-ocupada"><strong><?php echo (int)$npMesasEstado['ocupadas']; ?></strong> ocupadas</span>
                            <?php if ($npMesasEstado['reservadas'] > 0): ?>
                                <span class="is-reservada"><strong><?php echo (int)$npMesasEstado['reservadas']; ?></strong> reservadas</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <select id="sel-mesa" class="form-select form-select-sm" required>
                        <option value="">Selecionar mesa</option>
                        <?php foreach ($mesas as $m): ?>
                            <?php $numeroMesa = (string)($m['numero'] ?? ''); ?>
                            <?php if (mesa_eh_balcao($numeroMesa)) { continue; } ?>
                            <option value="<?php echo (int)$m['id']; ?>"
                                <?php echo ($m['status'] === 'OCUPADA') ? 'style="color:#f59e0b"' : ''; ?>>
                                <?php echo htmlspecialchars(mesa_rotulo($numeroMesa), ENT_QUOTES, 'UTF-8'); ?>
                                <?php echo ($m['status'] === 'OCUPADA') ? ' ⚠ Ocupada' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="cart-items">
                    <div class="cart-empty">
                        <i class="fas fa-utensils"></i>
                        Selecione produtos para montar o pedido
                    </div>
                </div>
            </div>
            <div class="cart-footer">
                <div class="total-row">
                    <span>Total</span>
                    <span id="cart-total">MZN 0,00</span>
                </div>
                <div class="obs-field">
                    <label for="obs-pedido">Observação (opcional)</label>
                    <textarea id="obs-pedido" class="form-control mt-1" rows="2" maxlength="300"
                        placeholder="<?php echo $temTelasBarCozinha ? 'Observações para a cozinha' : 'Observações do pedido'; ?>"></textarea>
                </div>
                <div id="stock-check-box" class="stock-check-box"></div>
                <button class="btn-enviar" id="btn-enviar" onclick="enviarPedido()" disabled>
                    <i class="fas fa-paper-plane me-2"></i> Enviar Pedido
                </button>
            </div>
        </div>
    </div>
<?php endif; ?>



    <!-- Modal de Sucesso do Pedido -->
    <div class="modal fade" id="modalPedidoSucesso" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content text-center p-4 position-relative">
                <!-- Confete animado -->
                <div id="confetti-container" style="pointer-events:none;position:absolute;top:0;left:0;width:100%;height:100%;z-index:10;"></div>
                <div class="mb-3" style="z-index:20;position:relative;">
                    <i class="fas fa-check-circle fa-3x text-success"></i>
                </div>
                <h3 class="mb-2">Pedido enviado!</h3>
                <div id="pedidoNumeroSucesso" class="fw-bold fs-4 mb-2"></div>
                <p class="text-muted mb-3" id="pedidoDestinoSucesso">Pedido registado com sucesso.<br>Em instantes ele aparecerá no painel de pedidos.</p>
                <button type="button" class="btn btn-success w-100" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>

    <div class="toast-container" id="toasts"></div>

    <script src="lib/bootstrap-5.3.2/js/bootstrap.bundle.min.js"></script>
    <script src="js/receipt_print.js?v=20260821a"></script>
    <script src="js/offline_sync.js?v=20261101b"></script>
    <script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
        const BOTAO_ENVIAR_PEDIDO_HTML = '<i class="fas fa-paper-plane me-2"></i> Enviar Pedido';
        const ESTOQUE_AVANCADO_ATIVO = <?php echo $temEstoqueAvancado ? 'true' : 'false'; ?>;
        const TEM_TELAS_BAR_COZINHA = <?php echo $temTelasBarCozinha ? 'true' : 'false'; ?>;
        const EXIGE_FICHA_CARRINHO = <?php echo !empty($exigeFichaCarrinhoNp) ? 'true' : 'false'; ?>;
        const IS_EMPRESARIAL_UI = <?php echo !empty($isEmpresarialUi) ? 'true' : 'false'; ?>;
        const MESA_BALCAO_ID = <?php echo (int)$mesaBalcaoId; ?>;
        OfflineSync.init({
            csrfToken: <?php echo json_encode($csrfToken, JSON_UNESCAPED_UNICODE); ?>,
            syncIntervalMs: 12000
        });

        const cart = {}; // {id: {id, nome, preco, qtd}}
        const CART_DRAFT_KEY = 'novo_pedido:' + window.location.pathname;
        let precheckEstoqueOk = true;
        let precheckTimer = null;
        let activeCategory = '*';

        function montarMensagemDestino(destinoResumo) {
            if (!TEM_TELAS_BAR_COZINHA) {
                return 'Pedido registado com sucesso.<br>Em instantes ele aparecerá no painel de pedidos.';
            }

            if (destinoResumo === 'bar') {
                return 'Seu pedido foi enviado para o bar.<br>Em instantes ele aparecerá no painel do bar.';
            }

            if (destinoResumo === 'cozinha_bar') {
                return 'Seu pedido foi enviado para a cozinha e ao bar.<br>Em instantes ele aparecerá nos painéis de produção.';
            }

            return 'Seu pedido foi enviado para a cozinha.<br>Em instantes ele aparecerá no painel de pedidos.';
        }

        function atualizarFilaCozinhaAposPedido(data) {
            if (!data || data.duplicado) {
                return;
            }

            const destinos = Array.isArray(data.destinos)
                ? data.destinos.map(destino => String(destino).toLowerCase())
                : [];
            const destinoResumo = String(data.destino_resumo || '').toLowerCase();
            const incluiCozinha = destinos.includes('cozinha')
                || destinoResumo === 'cozinha'
                || destinoResumo === 'cozinha_bar';
            if (!incluiCozinha) {
                return;
            }

            const contador = document.getElementById('npFilaCozinhaCount');
            if (!contador) {
                return;
            }
            const valorAtual = parseInt(contador.textContent || '0', 10) || 0;
            contador.textContent = String(valorAtual + 1);
        }

        function syncCardQtys() {
            document.querySelectorAll('[data-qty-for]').forEach(function(el) {
                var id = el.getAttribute('data-qty-for');
                el.textContent = String((cart[id] && cart[id].qtd) ? cart[id].qtd : 0);
            });
        }

        function addItem(id, nome, preco) {
            var card = document.querySelector('.np-card[data-id="' + id + '"]');
            if (EXIGE_FICHA_CARRINHO && card && card.getAttribute('data-sem-ficha') === '1') {
                showToast('Produto sem ficha técnica / insumos. Complete a ficha antes de pedir.', 'warning');
                return;
            }
            if (IS_EMPRESARIAL_UI || ESTOQUE_AVANCADO_ATIVO) {
                if (card && card.classList.contains('is-out')) {
                    showToast(
                        (card.getAttribute('data-sem-ficha') === '1')
                            ? 'Produto sem ficha técnica / insumos.'
                            : 'Produto sem stock / porções disponíveis.',
                        'warning'
                    );
                    return;
                }
                if (card) {
                    var estAttr = card.getAttribute('data-estoque');
                    if (estAttr !== null && estAttr !== '') {
                        var estMax = parseInt(estAttr, 10);
                        var qtdAtual = (cart[id] && cart[id].qtd) ? Number(cart[id].qtd) : 0;
                        if (Number.isFinite(estMax) && qtdAtual >= estMax) {
                            showToast('Só restam ' + estMax + ' porção(ões) deste produto.', 'warning');
                            return;
                        }
                    }
                }
            }
            if (!cart[id]) {
                cart[id] = {
                    id,
                    nome,
                    preco,
                    qtd: 0
                };
            }
            cart[id].qtd++;
            renderCart();
        }

        function changeQty(id, delta) {
            if (!cart[id] && delta > 0) {
                return;
            }
            if (!cart[id]) return;
            cart[id].qtd += delta;
            if (cart[id].qtd <= 0) delete cart[id];
            renderCart();
        }

        function addPromotion(promo) {
            const key = String(-Number(promo.id));
            if (!cart[key]) {
                cart[key] = {
                    id: 0,
                    promotionId: Number(promo.id),
                    nome: String(promo.nome),
                    preco: Number(promo.preco),
                    qtd: 0,
                    components: Array.isArray(promo.itens) ? promo.itens : []
                };
            }
            cart[key].qtd++;
            renderCart();
        }

        function removeItem(id) {
            if (cart[id]) delete cart[id];
            renderCart();
        }

        function isTakeawayOn() {
            var el = document.getElementById('npTakeaway');
            return !!(el && el.checked && MESA_BALCAO_ID > 0);
        }

        // Com o segundo canal activo, o take-away pergunta para que balcão vai.
        function npMesaBalcaoEscolhida() {
            var marcado = document.querySelector('#npBalcaoCanais input[name="npCanalBalcao"]:checked');
            if (marcado) {
                var v = parseInt(marcado.value, 10) || 0;
                if (v > 0) return v;
            }
            return MESA_BALCAO_ID;
        }

        (function initNpCanais() {
            var caixa = document.getElementById('npBalcaoCanais');
            var chk = document.getElementById('npTakeaway');
            if (!caixa || !chk) return;
            var sync = function () { caixa.classList.toggle('d-none', !chk.checked); };
            chk.addEventListener('change', sync);
            sync();
        })();

        function mesaSelecionadaOk() {
            if (isTakeawayOn()) return true;
            var sel = document.getElementById('sel-mesa');
            return !!(sel && sel.value);
        }

        function updateHeroSummary() {
            const mesaSelect = document.getElementById('sel-mesa');
            let mesaText = 'Aguardando seleção';
            if (isTakeawayOn()) {
                mesaText = 'Take Away';
            } else if (mesaSelect && mesaSelect.value) {
                mesaText = mesaSelect.options[mesaSelect.selectedIndex]?.text || 'Mesa selecionada';
            }
            const itemCount = Object.values(cart).reduce((sum, it) => sum + (Number(it.qtd) || 0), 0);
            const total = Object.values(cart).reduce((sum, it) => sum + (Number(it.preco || 0) * Number(it.qtd || 0)), 0);
            const heroMesa = document.getElementById('heroMesaLabel');
            const heroItens = document.getElementById('heroItensLabel');
            const heroTotal = document.getElementById('heroTotalLabel');
            if (heroMesa) heroMesa.textContent = String(mesaText).replace(' ⚠ Ocupada', '');
            if (heroItens) heroItens.textContent = `${itemCount} ${itemCount === 1 ? 'item' : 'itens'}`;
            if (heroTotal) heroTotal.textContent = 'MZN ' + fmt(total);
        }

        function renderCart() {
            OfflineSync.saveDraft(CART_DRAFT_KEY, {
                cart: cart,
                mesa_id: document.getElementById('sel-mesa')?.value || '',
                observacao: document.getElementById('obs-pedido')?.value || '',
                takeaway: !!document.getElementById('npTakeaway')?.checked
            }).catch(() => {});
            const el = document.getElementById('cart-items');
            const stockBox = document.getElementById('stock-check-box');
            const ids = Object.keys(cart);
            syncCardQtys();
            if (!ids.length) {
                el.innerHTML = IS_EMPRESARIAL_UI
                    ? '<div class="np-cart-empty cart-empty"><i class="fas fa-utensils"></i>Selecione produtos para montar o pedido</div>'
                    : '<div class="cart-empty"><i class="fas fa-utensils"></i>Selecione produtos para montar o pedido</div>';
                document.getElementById('cart-total').textContent = 'MZN 0,00';
                document.getElementById('btn-enviar').disabled = true;
                precheckEstoqueOk = true;
                if (stockBox) {
                    stockBox.style.display = 'none';
                    stockBox.innerHTML = '';
                }
                updateHeroSummary();
                return;
            }

            let html = '',
                total = 0;
            ids.forEach(id => {
                const it = cart[id];
                const sub = it.preco * it.qtd;
                total += sub;
                if (IS_EMPRESARIAL_UI) {
                    html += `<div class="np-cart-item">
            <div class="ci-info">
                <div class="ci-name">${escHtml(it.nome)}</div>
                <div class="ci-sub">${it.qtd} un</div>
            </div>
            <div class="ci-price">MZN ${fmt(sub)}</div>
            <button type="button" class="ci-del" onclick="removeItem(${id})" title="Remover"><i class="fas fa-trash"></i></button>
        </div>`;
                } else {
                    html += `<div class="cart-item">
            <div class="qty-ctrl">
                <button class="qty-btn" onclick="changeQty(${id},-1)">−</button>
                <span class="qty-num">${it.qtd}</span>
                <button class="qty-btn" onclick="changeQty(${id},1)">+</button>
            </div>
            <div class="ci-nome">${escHtml(it.nome)}</div>
            <div class="ci-preco">MZN ${fmt(sub)}</div>
        </div>`;
                }
            });

            el.innerHTML = html;
            document.getElementById('cart-total').textContent = 'MZN ' + fmt(total);
            document.getElementById('btn-enviar').disabled = !mesaSelecionadaOk() || !precheckEstoqueOk;
            agendarPrecheckEstoque();
            updateHeroSummary();
        }

        var selMesaEl = document.getElementById('sel-mesa');
        if (selMesaEl) {
            selMesaEl.addEventListener('change', () => {
                const hasItems = Object.keys(cart).length > 0;
                document.getElementById('btn-enviar').disabled = !hasItems || !mesaSelecionadaOk() || !precheckEstoqueOk;
                updateHeroSummary();
            });
        }

        // Botões de mesa (Empresarial): espelham o select escondido
        (function () {
            var box = document.getElementById('npMesaBtns');
            var sel = document.getElementById('sel-mesa');
            if (!box || !sel) return;
            var ultimo = null;
            function sincronizar() {
                if (sel.value === ultimo) return;
                ultimo = sel.value;
                box.querySelectorAll('.np-mesa-btn').forEach(function (b) {
                    b.classList.toggle('is-active', b.getAttribute('data-mesa-id') === sel.value);
                });
            }
            box.addEventListener('click', function (ev) {
                var b = ev.target.closest('.np-mesa-btn');
                if (!b) return;
                sel.value = b.getAttribute('data-mesa-id');
                sel.dispatchEvent(new Event('change', { bubbles: true }));
                sincronizar();
            });
            sel.addEventListener('change', sincronizar);
            setInterval(sincronizar, 400);
            sincronizar();
        })();

        // Preload mesa_id from URL (ex.: check-in de reserva)
        const urlParams = new URLSearchParams(window.location.search);
        const mesaId = urlParams.get('mesa_id');
        const origemReserva = urlParams.get('origem') === 'reserva_checkin';
        if (mesaId && selMesaEl) {
            selMesaEl.value = mesaId;
            renderCart();
            if (origemReserva) {
                const aviso = document.createElement('div');
                aviso.className = 'alert alert-success py-2 px-3 mb-3';
                aviso.innerHTML = '<i class="fas fa-check-circle me-2"></i>Check-in feito. Mesa selecionada — lance os itens do pedido.';
                const host = document.querySelector('.content-area') || document.querySelector('main') || document.body;
                if (host.firstElementChild) host.insertBefore(aviso, host.firstElementChild);
                else host.prepend(aviso);
            }
        } else {
            updateHeroSummary();
        }

        OfflineSync.getDraft(CART_DRAFT_KEY).then((draft) => {
            const saved = draft?.payload;
            if (!saved || !saved.cart) return;
            Object.keys(saved.cart).forEach((id) => { cart[id] = saved.cart[id]; });
            if (selMesaEl && saved.mesa_id) selMesaEl.value = saved.mesa_id;
            const obsDraft = document.getElementById('obs-pedido');
            if (obsDraft) obsDraft.value = saved.observacao || '';
            const takeawayDraft = document.getElementById('npTakeaway');
            if (takeawayDraft) takeawayDraft.checked = !!saved.takeaway;
            renderCart();
            if (Object.keys(cart).length) showToast('Carrinho anterior restaurado.', 'warning');
        }).catch(() => {});
        document.getElementById('obs-pedido')?.addEventListener('input', () => renderCart());

        function fmt(n) {
            return Number(n).toLocaleString('pt-BR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function escHtml(str) {
            return String(str ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        function formatBase(qtd, unidadeBase) {
            const n = Number(qtd || 0);
            if (unidadeBase === 'g') return `${fmt(n)} g`;
            if (unidadeBase === 'ml') return `${fmt(n)} ml`;
            return `${fmt(n)} un`;
        }

        function agendarPrecheckEstoque() {
            if (!ESTOQUE_AVANCADO_ATIVO) {
                precheckEstoqueOk = true;
                return;
            }
            if (precheckTimer) clearTimeout(precheckTimer);
            precheckTimer = setTimeout(precheckEstoque, 250);
        }

        async function precheckEstoque() {
            const ids = Object.keys(cart);
            const stockBox = document.getElementById('stock-check-box');
            if (!stockBox) return;
            if (!ESTOQUE_AVANCADO_ATIVO || !ids.length) {
                precheckEstoqueOk = true;
                stockBox.style.display = 'none';
                stockBox.innerHTML = '';
                return;
            }

            const itens = [];
            ids.forEach(id => {
                const item = cart[id];
                if (item.promotionId && Array.isArray(item.components)) {
                    item.components.forEach(component => itens.push({
                        id: Number(component.produto_id),
                        qtd: Number(component.quantidade) * Number(item.qtd)
                    }));
                } else {
                    itens.push({ id: item.id, qtd: item.qtd });
                }
            });

            try {
                const fd = new FormData();
                fd.append('itens', JSON.stringify(itens));
                fd.append('_csrf', <?php echo json_encode($csrfToken, JSON_UNESCAPED_UNICODE); ?>);

                const resp = await fetch('api/estoque_pedido_precheck.php', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    headers: {
                        'X-CSRF-Token': <?php echo json_encode($csrfToken, JSON_UNESCAPED_UNICODE); ?>,
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                const data = await resp.json();
                if (!resp.ok || !data || data.success !== true) {
                    precheckEstoqueOk = true;
                    stockBox.style.display = 'none';
                    stockBox.innerHTML = '';
                } else if (data.enabled && data.ok === false && Array.isArray(data.sem_ficha) && data.sem_ficha.length) {
                    precheckEstoqueOk = false;
                    const nomes = data.sem_ficha.map(n => `<li><b>${escHtml(n)}</b></li>`).join('');
                    stockBox.innerHTML = `<div><i class="fas fa-clipboard-list me-1"></i> Sem ficha técnica / insumos:</div><ul>${nomes}</ul>`;
                    stockBox.style.display = 'block';
                } else if (data.enabled && data.ok === false && Array.isArray(data.faltas) && data.faltas.length) {
                    precheckEstoqueOk = false;
                    const linhas = data.faltas.map(f => `<li><b>${escHtml(f.nome)}</b>: saldo ${formatBase(f.saldo_base, f.unidade_base)}, precisa ${formatBase(f.necessario_base, f.unidade_base)}</li>`).join('');
                    stockBox.innerHTML = `<div><i class="fas fa-triangle-exclamation me-1"></i> Estoque insuficiente para este pedido:</div><ul>${linhas}</ul>`;
                    stockBox.style.display = 'block';
                } else {
                    precheckEstoqueOk = true;
                    stockBox.style.display = 'none';
                    stockBox.innerHTML = '';
                }
            } catch (e) {
                precheckEstoqueOk = true;
                stockBox.style.display = 'none';
                stockBox.innerHTML = '';
            }

            document.getElementById('btn-enviar').disabled = !mesaSelecionadaOk() || !precheckEstoqueOk;
        }


        // Função simples de confete (efeito visual ao mostrar modal de sucesso)
        function confetti() {
            const colors = ['#FF6B35', '#F7931E', '#10b981', '#3b82f6', '#8b5cf6', '#ec4899'];
            const container = document.getElementById('confetti-container');
            if (!container) return;
            container.innerHTML = '';
            for (let i = 0; i < 32; i++) {
                const el = document.createElement('div');
                el.style.position = 'absolute';
                el.style.left = Math.random() * 100 + '%';
                el.style.top = '-20px';
                el.style.width = '12px';
                el.style.height = '12px';
                el.style.borderRadius = '50%';
                el.style.background = colors[Math.floor(Math.random() * colors.length)];
                el.style.opacity = 0.85;
                el.style.transform = `scale(${0.7 + Math.random()*0.7})`;
                el.style.transition = 'top 1.2s cubic-bezier(.4,1.4,.6,1), opacity 1.2s';
                container.appendChild(el);
                setTimeout(() => {
                    el.style.top = (60 + Math.random() * 30) + '%';
                    el.style.opacity = 0;
                }, 30 + Math.random() * 100);
            }
            setTimeout(() => {
                container.innerHTML = '';
            }, 1400);
        }

        function isNetworkError(err) {
            const msg = String((err && err.message) || err || '').toLowerCase();
            return err instanceof TypeError ||
                msg.includes('failed to fetch') ||
                msg.includes('networkerror') ||
                msg.includes('network request failed') ||
                msg.includes('load failed');
        }

        async function backendReachable() {
            try {
                const resp = await fetch('api/online_ping.php?_=' + Date.now(), {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                return !!resp;
            } catch (e) {
                return false;
            }
        }

        async function enviarPedido() {
            // Garantir que mesaId seja inteiro (evita erro de 'Mesa inválida' no backend)
            let mesaId = 0;
            if (isTakeawayOn()) {
                mesaId = npMesaBalcaoEscolhida();
            } else {
                const mesaIdRaw = document.getElementById('sel-mesa').value;
                mesaId = parseInt(mesaIdRaw, 10) || 0;
            }
            if (!mesaId) {
                showToast(isTakeawayOn() ? 'Take Away indisponível: crie mesa Balcão.' : 'Selecione uma mesa!', 'warning');
                return;
            }
            const ids = Object.keys(cart);
            if (!ids.length) {
                showToast('Carrinho vazio!', 'warning');
                return;
            }
            if (!precheckEstoqueOk) {
                showToast('Estoque insuficiente para este pedido. Ajuste os itens.', 'warning');
                return;
            }

            const itens = ids.map(id => cart[id].promotionId ? {
                promocao_id: cart[id].promotionId,
                qtd: cart[id].qtd
            } : {
                id: cart[id].id,
                nome: cart[id].nome,
                preco: cart[id].preco,
                qtd: cart[id].qtd
            });
            const obsEl = document.getElementById('obs-pedido');
            let obs = obsEl ? obsEl.value.trim() : '';
            const clienteEmp = document.getElementById('empClienteNome');
            if (clienteEmp && clienteEmp.value.trim()) {
                const cli = clienteEmp.value.trim();
                obs = obs ? (`Cliente: ${cli} | ${obs}`) : (`Cliente: ${cli}`);
            }

            const btn = document.getElementById('btn-enviar');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Enviando…';

            try {
                const result = await OfflineSync.executeOrQueue({
                    label: 'Criar pedido da mesa ' + mesaId,
                    endpoint: 'api/pedido_novo.php',
                    method: 'POST',
                    body: {
                        mesa_id: String(mesaId),
                        itens: JSON.stringify(itens),
                        observacao: obs || ''
                    },
                    headers: {
                        'X-CSRF-Token': <?php echo json_encode($csrfToken, JSON_UNESCAPED_UNICODE); ?>,
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                if (result.queued) {
                    showToast('Pedido guardado. Sera enviado automaticamente quando houver internet.', 'warning');
                    Object.keys(cart).forEach(k => delete cart[k]);
                    document.getElementById('obs-pedido').value = '';
                    await OfflineSync.removeDraft(CART_DRAFT_KEY);
                    renderCart();
                    return;
                }
                const data = result.data;

                if (data.success) {
                    atualizarFilaCozinhaAposPedido(data);
                    // Modal de sucesso profissional
                    document.getElementById('pedidoNumeroSucesso').textContent = `#${data.numero_pedido}`;
                    document.getElementById('pedidoDestinoSucesso').innerHTML = montarMensagemDestino(String(data.destino_resumo || 'cozinha'));
                    var modal = new bootstrap.Modal(document.getElementById('modalPedidoSucesso'));
                    modal.show();
                    confetti(); // Efeito visual
                    // Limpar carrinho e campos
                    Object.keys(cart).forEach(k => delete cart[k]);
                    document.getElementById('obs-pedido').value = '';
                    if (document.getElementById('sel-mesa')) document.getElementById('sel-mesa').value = '';
                    var tw = document.getElementById('npTakeaway');
                    if (tw) tw.checked = false;
                    var wrap = document.getElementById('npMesaWrap');
                    if (wrap) wrap.classList.remove('is-hidden');
                    var box = document.getElementById('npTakeawayBox');
                    if (box) box.classList.remove('is-on');
                    await OfflineSync.removeDraft(CART_DRAFT_KEY);
                    renderCart();
                } else {
                    const msg = data.detail ? (data.message + ': ' + data.detail) : (data.message || 'Erro ao enviar pedido.');
                    showToast(msg, 'danger');
                    btn.disabled = false;
                    btn.innerHTML = BOTAO_ENVIAR_PEDIDO_HTML;
                }
            } catch (e) {
                showToast(String(e.message || 'Falha ao guardar o pedido.'), 'danger');
                btn.disabled = false;
                btn.innerHTML = BOTAO_ENVIAR_PEDIDO_HTML;
            }
        }

        function showToast(msg, type = 'success') {
            const id = 'toast_' + Date.now();
            const colors = {
                success: '#10b981',
                warning: '#f59e0b',
                danger: '#ef4444'
            };
            const div = document.createElement('div');
            div.id = id;
            div.style.cssText = `background:${colors[type]||'#333'};color:#fff;padding:12px 18px;border-radius:12px;
        margin-top:8px;font-weight:600;font-size:14px;box-shadow:0 4px 12px rgba(0,0,0,0.2);
        animation:fadeIn 0.3s ease;`;
            div.innerHTML = `<i class="fas fa-${type==='success'?'check':'exclamation-triangle'} me-2"></i>${msg}`;
            document.getElementById('toasts').appendChild(div);
            setTimeout(() => div.remove(), 4000);
        }

        (function initEmpBuscaProduto() {
            const input = document.getElementById('empBuscaProduto');
            const countLabel = document.getElementById('produtosCountLabel');

            function applyFilters() {
                const q = input ? (input.value || '').trim().toLowerCase() : '';
                let visible = 0;
                document.querySelectorAll('.np-card.produto-btn, .produtos-grid .produto-btn').forEach((btn) => {
                    const nome = (btn.getAttribute('data-nome') || '').toLowerCase();
                    const cat = (btn.getAttribute('data-cat') || '').toLowerCase();
                    const catOk = !IS_EMPRESARIAL_UI || activeCategory === '*' || cat === String(activeCategory || '').toLowerCase();
                    const qOk = !q || nome.indexOf(q) !== -1 || cat.indexOf(q) !== -1;
                    const show = catOk && qOk;
                    if (btn.classList.contains('np-card')) {
                        btn.classList.toggle('is-hidden', !show);
                    } else {
                        btn.style.display = show ? '' : 'none';
                        const section = btn.closest('.produtos-grid');
                        if (section) {
                            const title = section.previousElementSibling;
                            const anyVisible = Array.from(section.querySelectorAll('.produto-btn')).some((b) => b.style.display !== 'none');
                            if (title && title.classList.contains('section-title')) {
                                title.style.display = anyVisible ? '' : 'none';
                            }
                            section.style.display = anyVisible ? '' : 'none';
                        }
                    }
                    if (show) visible++;
                });
                if (countLabel) {
                    countLabel.textContent = visible + (IS_EMPRESARIAL_UI ? ' produtos disponíveis' : (' produto(s)' + (q ? ' encontrados' : ' activos')));
                }
            }

            if (input) {
                input.addEventListener('input', applyFilters);
            }

            document.querySelectorAll('.np-cat-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    document.querySelectorAll('.np-cat-btn').forEach(function(b) { b.classList.remove('is-active'); });
                    btn.classList.add('is-active');
                    activeCategory = btn.getAttribute('data-cat') || '*';
                    var title = document.getElementById('npSectionTitle');
                    if (title) title.textContent = activeCategory === '*' ? 'Todos' : activeCategory;
                    applyFilters();
                });
            });

            var takeaway = document.getElementById('npTakeaway');
            var mesaWrap = document.getElementById('npMesaWrap');
            var takeBox = document.getElementById('npTakeawayBox');
            if (takeaway) {
                takeaway.addEventListener('change', function() {
                    if (mesaWrap) mesaWrap.classList.toggle('is-hidden', takeaway.checked);
                    if (takeBox) takeBox.classList.toggle('is-on', takeaway.checked);
                    if (takeaway.checked && selMesaEl) selMesaEl.value = '';
                    const hasItems = Object.keys(cart).length > 0;
                    document.getElementById('btn-enviar').disabled = !hasItems || !mesaSelecionadaOk() || !precheckEstoqueOk;
                    updateHeroSummary();
                });
            }

            document.querySelectorAll('.np-top-chip').forEach(function(chip) {
                chip.addEventListener('click', function() {
                    var nomeTop = String(chip.getAttribute('data-top-nome') || '').trim();
                    if (!nomeTop) return;
                    var busca = document.getElementById('empBuscaProduto');
                    if (busca) {
                        busca.value = nomeTop;
                        busca.dispatchEvent(new Event('input', { bubbles: true }));
                        busca.focus();
                    }
                    var card = Array.prototype.find.call(document.querySelectorAll('.np-card'), function(c) {
                        return String(c.getAttribute('data-label') || '').toLowerCase() === nomeTop.toLowerCase();
                    });
                    if (card && !card.classList.contains('is-out')) {
                        var pid = Number(card.getAttribute('data-id'));
                        var preco = Number(card.getAttribute('data-preco'));
                        var label = card.getAttribute('data-label') || nomeTop;
                        if (pid > 0) addItem(pid, label, preco);
                    }
                });
            });
        })();
    </script>
<script src="js/nivora_dialogo.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nivora_dialogo.js'); ?>"></script>
</body>

</html>
