<?php
require_once __DIR__ . '/app_base.php';
// Proteção da página
include_once APP_BASE . '/src/config/auth_check.php';
include_once APP_BASE . '/src/config/csrf.php';
include_once APP_BASE . '/src/config/restaurante_context.php';
include_once APP_BASE . '/src/config/plano_check.php';
// Verificar restaurante_id
$restaurante_id = session_restaurante_contexto_id();
if ($restaurante_id <= 0) {
    header("Location: index.php?erro=sem_restaurante");
    exit;
}
$planRid = function_exists('session_restaurante_capability_id')
    ? (int)session_restaurante_capability_id()
    : $restaurante_id;
$planRid = $planRid > 0 ? $planRid : $restaurante_id;
// Verifica se o plano tem estoque avançado (mas para o plano BASICO forçamos a versão simples)
$temEstoqueAvancado = plano_tem_funcionalidade_db($planRid, 'estoque_avancado');
$temDashboardInteligente = plano_tem_funcionalidade_db($planRid, 'dashboard_inteligente');
$temAnaliseAbc = plano_tem_funcionalidade_db($planRid, 'analise_abc_vendas');
$temRecomendacoesIa = plano_tem_funcionalidade_db($planRid, 'recomendacoes_automaticas');
$temPrevisaoDemanda = plano_tem_funcionalidade_db($planRid, 'previsao_demanda');

$dados_plano = plano_get_dados($planRid);
$plano_atual = plano_normalizar_nome($dados_plano['plano_nome'] ?? 'BASICO');
$showEstoqueAvancado = $temEstoqueAvancado && $plano_atual !== 'BASICO';
$temSubcategorias = plano_tem_funcionalidade_db($planRid, 'subcategorias');
require_once __DIR__ . '/includes/empresarial_ui.php';

include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/Model/Produto.php';
include_once APP_BASE . '/src/Model/Categoria.php';
require_once __DIR__ . '/include/produtos_ia_analytics.php';

// Conectar ao banco
$database = new Database();
$db = $database->getConnection();

// Instanciar classes
$produto   = new Produto($db);
$categoria = new Categoria($db);

// Buscar produtos (array)
$stmt_produtos = $produto->listar($restaurante_id);
$lista_produtos = $stmt_produtos->fetchAll(PDO::FETCH_ASSOC);

$painelIa = produtos_ia_montar_painel($db, $restaurante_id, $lista_produtos, [
    'dashboard_inteligente' => $temDashboardInteligente,
    'analise_abc_vendas' => $temAnaliseAbc,
    'recomendacoes_automaticas' => $temRecomendacoesIa,
    'previsao_demanda' => $temPrevisaoDemanda,
]);
$temPainelIa = !empty($painelIa['ativo']);

// EMP: quais produtos têm ficha técnica (estoque inteligente).
$produtosComFicha = [];
$produtosSemFichaCount = 0;
$produtosUsaFicha = in_array($plano_atual, ['EMPRESARIAL', 'PROFISSIONAL'], true);
if ($produtosUsaFicha && $showEstoqueAvancado && $db instanceof PDO) {
    try {
        include_once APP_BASE . '/src/config/estoque_inteligente.php';
        if (function_exists('estoque_inteligente_schema_garantir')) {
            estoque_inteligente_schema_garantir($db);
        }
        $stmtFichas = $db->prepare("
            SELECT DISTINCT produto_id
            FROM estoque_fichas_tecnicas
            WHERE restaurante_id = :rid
              AND COALESCE(ativo, 1) = 1
        ");
        $stmtFichas->execute([':rid' => $restaurante_id]);
        foreach ($stmtFichas->fetchAll(PDO::FETCH_ASSOC) as $fr) {
            $pidF = (int)($fr['produto_id'] ?? 0);
            if ($pidF > 0) {
                $produtosComFicha[$pidF] = true;
            }
        }
        foreach ($lista_produtos as $lp) {
            if (empty($produtosComFicha[(int)($lp['id'] ?? 0)])) {
                $produtosSemFichaCount++;
            }
        }
    } catch (Throwable $e) {
        $produtosComFicha = [];
        $produtosSemFichaCount = 0;
    }
}

// Imagem de recurso com as iniciais (gerada localmente, sem internet).
if (!function_exists('produto_img_iniciais')) {
    function produto_img_iniciais(string $nome): string
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
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 128 128"><rect width="128" height="128" fill="' . $cor . '"/>'
            . '<text x="50%" y="50%" dy=".35em" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="50" font-weight="700" fill="#ffffff">'
            . htmlspecialchars($ini, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</text></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}

// Empresarial: porções disponíveis (pela ficha) e custo/margem de cada produto.
$empPorcoes = [];
$empCusto = [];
const PRODUTO_MARGEM_BAIXA_PCT = 50;
if ($produtosUsaFicha && !empty($showEstoqueAvancado) && $db instanceof PDO && !empty($produtosComFicha)) {
    try {
        $filialIdProd = null;
        if (function_exists('session_restaurante_auth_id')) {
            $authRidProd = (int)session_restaurante_auth_id();
            if ($authRidProd > 0 && $authRidProd !== (int)$restaurante_id) {
                $filialIdProd = $authRidProd;
            }
        }
        if (function_exists('estoque_porcoes_disponiveis_por_produtos')) {
            $empPorcoes = estoque_porcoes_disponiveis_por_produtos($db, (int)$restaurante_id, $filialIdProd, array_keys($produtosComFicha));
        }
        // Custo por unidade vendida = soma(quantidade do insumo / rendimento × custo médio do insumo)
        $stCusto = $db->prepare("
            SELECT ft.produto_id,
                   SUM((fi.quantidade_base / GREATEST(ft.rendimento_por_produto, 0.001)) * COALESCE(s.custo_medio, 0)) AS custo,
                   SUM(CASE WHEN COALESCE(s.custo_medio, 0) <= 0 THEN 1 ELSE 0 END) AS sem_custo
            FROM estoque_fichas_tecnicas ft
            JOIN estoque_ficha_itens fi ON fi.ficha_id = ft.id
            LEFT JOIN estoque_saldos s ON s.insumo_id = fi.insumo_id AND s.restaurante_id = ft.restaurante_id
                 AND ((? IS NULL AND s.filial_id IS NULL) OR s.filial_id = ?)
            WHERE ft.restaurante_id = ? AND ft.ativo = 1
              AND ((? IS NULL AND ft.filial_id IS NULL) OR ft.filial_id = ?)
            GROUP BY ft.produto_id
        ");
        $stCusto->execute([$filialIdProd, $filialIdProd, (int)$restaurante_id, $filialIdProd, $filialIdProd]);
        foreach ($stCusto->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rc) {
            $c = (float)$rc['custo'];
            // Custo só é fiável se todos os insumos tiverem custo registado
            $empCusto[(int)$rc['produto_id']] = ((int)$rc['sem_custo'] === 0 && $c > 0) ? round($c, 2) : null;
        }
    } catch (Throwable $eCusto) {
        error_log('[PRODUTOS][CUSTO] ' . $eCusto->getMessage());
    }
}

// Normaliza caminho de imagem para evitar 404 por registros legados.
$normalizarImagemProduto = static function ($imagem) {
    $img = trim((string)$imagem);
    if ($img === '') {
        return '';
    }

    $img = str_replace('\\', '/', $img);
    $img = preg_replace('#^(\./)+#', '', $img);
    $img = str_replace('src/public/', '', $img);
    $img = ltrim($img, '/');

    if (strpos($img, 'images/') !== 0 && preg_match('/\.(png|jpe?g|webp|gif)$/i', $img)) {
        $img = 'images/' . $img;
    }

    $arquivo = __DIR__ . '/' . $img;
    return is_file($arquivo) ? $img : '';
};

foreach ($lista_produtos as &$produtoItem) {
    $produtoItem['imagem'] = $normalizarImagemProduto($produtoItem['imagem'] ?? '');
}
unset($produtoItem);

// Buscar categorias para o filtro/modal
$stmt_cat = $categoria->listar($restaurante_id);
$lista_categorias = $stmt_cat->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$total_produtos = count($lista_produtos);
$produtos_ativos = count(array_filter($lista_produtos, fn($p) => $p['ativo'] == 1));
$estoque_baixo = count(array_filter($lista_produtos, fn($p) => (int)($p['estoque'] ?? 0) <= (int)($p['estoque_minimo'] ?? 5)));
$sem_estoque = count(array_filter($lista_produtos, fn($p) => (int)($p['estoque'] ?? 0) <= 0));
$total_unidades_estoque = array_sum(array_map(static fn($p) => max(0, (int)($p['estoque'] ?? 0)), $lista_produtos));

function produto_estoque_meta(array $produto): array
{
    $estoque = max(0, (int)($produto['estoque'] ?? 0));
    $minimo = max(1, (int)($produto['estoque_minimo'] ?? 5));
    $referencia = max($minimo * 2, $minimo);

    if ($estoque <= 0) {
        $status = 'zerado';
        $label = 'Sem estoque';
        $classe = 'danger';
    } elseif ($estoque <= $minimo) {
        $status = 'baixo';
        $label = 'Estoque baixo';
        $classe = 'warning';
    } elseif ($estoque <= $referencia) {
        $status = 'atencao';
        $label = 'Atenção';
        $classe = 'warning';
    } else {
        $status = 'ok';
        $label = 'Saudável';
        $classe = 'success';
    }

    $percentual = (int)min(100, round(($estoque / $referencia) * 100));

    return [
        'estoque' => $estoque,
        'minimo' => $minimo,
        'status' => $status,
        'label' => $label,
        'classe' => $classe,
        'percentual' => $percentual,
    ];
}

function produto_render_estoque_celula(array $produto, bool $temEstoqueAvancado, bool $cockpitEmpresarial = false, ?bool $temFicha = null, ?int $porcoes = null): string
{
    if ($temEstoqueAvancado) {
        // Sem ficha tecnica o produto nao da baixa nenhuma ao ser vendido: tem de avisar.
        if ($temFicha === false) {
            return '<div class="stock-cell">'
                . '<span class="badge-custom badge-danger"><i class="fas fa-triangle-exclamation me-1"></i>Sem ficha</span>'
                . '<a href="estoque_inteligente.php?tab=fichas" class="stock-link-advanced"><i class="fas fa-clipboard-list me-1"></i>Criar ficha</a>'
                . '</div>';
        }
        $porcoesHtml = '';
        if ($porcoes !== null) {
            $classePorcoes = $porcoes <= 0 ? 'danger' : ($porcoes <= 5 ? 'warning' : 'success');
            $porcoesHtml = '<span class="badge-custom badge-' . $classePorcoes . '" title="Doses que ainda dá para fazer com o stock actual">'
                . $porcoes . ' porç' . ($porcoes === 1 ? 'ão' : 'ões') . '</span>';
        }
        if ($cockpitEmpresarial) {
            return '<div class="stock-cell">'
                . ($porcoesHtml !== '' ? $porcoesHtml : '<span class="badge-custom badge-warning">Via insumos</span>')
                . '<a href="estoque_inteligente.php" class="stock-link-advanced"><i class="fas fa-boxes-stacked me-1"></i>Estoque Inteligente</a>'
                . '</div>';
        }
        return '<div class="stock-cell">'
            . ($porcoesHtml !== '' ? $porcoesHtml : '<span class="badge-custom badge-warning">Via insumos</span>')
            . '<a href="estoque_inteligente.php?tab=fichas" class="stock-link-advanced"><i class="fas fa-clipboard-list me-1"></i>Ficha técnica</a>'
            . '</div>';
    }

    $meta = produto_estoque_meta($produto);

    return '<div class="stock-cell simple-stock-cell" data-estoque-status="' . htmlspecialchars($meta['status'], ENT_QUOTES, 'UTF-8') . '">'
        . '<div class="d-flex align-items-center justify-content-between">'
        . '<small class="stock-name truncate-ellipsis">' . htmlspecialchars($produto['nome'] ?? '', ENT_QUOTES, 'UTF-8') . '</small>'
        . '<span class="badge-custom badge-' . htmlspecialchars($meta['classe'], ENT_QUOTES, 'UTF-8') . '">' . (int)$meta['estoque'] . '</span>'
        . '</div>'
        . '</div>';
}

// Base URL - adjusted to point to src/public where the API actually is
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443 ? 'https://' : 'http://';
$base_url = $protocol . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '\/');
// Don't replace src/public with public - the API is in src/public/api/
$base_url = $base_url . '/';
$csrf_token = csrf_get_token();
?>
<!DOCTYPE html>
<html lang="pt">

<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Produtos - RestauranteSaaS</title>

    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link href="lib/bootstrap-5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="lib/fontawesome-6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/premium.css">
    <style>
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

        @media (max-width: 991px) {
            .sidebar-toggle-btn {
                display: block !important;
            }

            .sidebar {
                position: fixed !important;
                top: 0;
                left: 0;
                height: 100vh;
                z-index: 2000;
                transition: left 0.3s cubic-bezier(.4, 0, .2, 1);
                box-shadow: 2px 0 16px rgba(0, 0, 0, 0.08);
            }

            .sidebar.sidebar-hidden {
                left: -100vw !important;
            }

            .main-content-blur {
                filter: blur(2px) grayscale(0.1);
                pointer-events: none;
            }
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
            background: white;
            padding: 20px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 100;
        }

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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            box-shadow: var(--shadow);
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--primary);
            opacity: 0;
            transition: opacity 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .stat-card:hover::before {
            opacity: 1;
        }

        .stat-card.success::before {
            background: var(--success);
        }

        .stat-card.warning::before {
            background: var(--warning);
        }

        .stat-card.danger::before {
            background: var(--danger);
        }

        .stat-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }

        .stat-icon.primary {
            background: rgba(255, 107, 53, 0.1);
            color: var(--primary);
        }

        .stat-icon.success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .stat-icon.warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .stat-icon.danger {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .stat-value {
            font-family: 'Space Grotesk', 'Segoe UI Semibold', 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
            font-size: 32px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 4px;
        }

        .stat-label {
            color: var(--text-light);
            font-size: 14px;
            font-weight: 500;
        }

        .card {
            background: white;
            border-radius: 20px;
            box-shadow: var(--shadow);
            border: none;
            overflow: hidden;
            margin-bottom: 24px;
        }

        .card-header {
            background: white;
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title {
            font-family: 'Space Grotesk', 'Segoe UI Semibold', 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
            font-size: 18px;
            font-weight: 700;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-title i {
            color: var(--primary);
        }

        .card-body {
            padding: 0;
        }

        .table {
            margin: 0;
        }

        .table thead th {
            background: var(--bg);
            padding: 16px 20px;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-light);
            border: none;
        }

        .table tbody td {
            padding: 16px 20px;
            vertical-align: middle;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
        }

        .table tbody tr:hover {
            background: rgba(255, 107, 53, 0.02);
        }

        .badge-custom {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .badge-warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .badge-danger {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
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
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
        }

        .btn-sm {
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 12px;
        }

        .btn-success {
            background: var(--success);
            border: none;
        }

        .btn-warning {
            background: var(--warning);
            border: none;
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            border: none;
        }

        .btn-info {
            background: var(--info);
            border: none;
        }

        .btn-action {
            width: 36px;
            height: 36px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            margin: 0 3px;
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

        .product-img {
            width: 120px;
            height: 120px;
            object-fit: cover;
            border: 3px solid var(--border);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            background-color: #f8f9fa;
        }

        .produtos-toolbar-card,
        .produtos-categoria-card {
            overflow: visible;
        }

        .produtos-toolbar-actions {
            align-items: stretch;
        }

        .produto-status-stack {
            display: inline-flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
        }

        .product-cell {
            display: flex;
            align-items: center;
            gap: 18px;
        }

        .product-info {
            display: flex;
            flex-direction: column;
        }

        .product-name {
            font-weight: 700;
            font-size: 16px;
            color: var(--text);
        }

        .product-desc {
            font-size: 13px;
            color: var(--text-muted);
            max-width: 250px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .modal-content {
            border: none;
            border-radius: 24px;
            box-shadow: var(--shadow-lg);
        }

        .modal-header {
            padding: 24px 28px;
            border-bottom: 1px solid var(--border);
            border-radius: 24px 24px 0 0;
        }

        .modal-title {
            font-family: 'Space Grotesk', 'Segoe UI Semibold', 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
            font-weight: 700;
            font-size: 20px;
        }

        .modal-body {
            padding: 28px;
        }

        .modal-footer {
            padding: 20px 28px;
            border-top: 1px solid var(--border);
        }

        .form-label {
            font-weight: 600;
            font-size: 13px;
            color: var(--text);
            margin-bottom: 8px;
        }

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                position: relative;
                min-height: auto;
            }

            .main-content {
                margin-left: 0;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .content-area {
                padding: 20px;
            }

            .produtos-toolbar-actions>[class*="col-"] {
                width: 100%;
                max-width: 100%;
                flex: 0 0 100%;
            }
        }

        @media (max-width: 576px) {
            .main-content {
                padding: 12px;
            }

            .top-bar {
                padding: 12px 16px;
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
                margin: 0 0 16px 0;
            }

            .page-title {
                font-size: 18px;
                gap: 8px;
            }

            .content-area {
                padding: 12px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 14px;
                margin-bottom: 20px;
            }

            .stat-card {
                padding: 16px;
                border-radius: 18px;
            }

            #botoesCategoria {
                flex-direction: column;
            }

            #botoesCategoria .btn {
                width: 100%;
            }

            .card .row.g-3>[class*="col-"] {
                width: 100%;
                max-width: 100%;
                flex: 0 0 100%;
            }

            .card {
                border-radius: 18px;
            }

            .card-body {
                padding: 16px;
            }

            .table-responsive {
                border-radius: 16px;
            }

            .table thead th,
            .table tbody td {
                padding: 10px 12px;
                font-size: 12px;
                white-space: normal;
            }

            .product-img {
                width: 56px !important;
                height: 56px !important;
            }

            .product-name {
                font-size: 13px;
            }

            .product-desc {
                font-size: 11px;
            }

            .btn-action {
                width: 38px;
                height: 38px;
                padding: 0;
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }

            .table td.text-center {
                white-space: nowrap;
            }

            .modal-dialog.modal-lg {
                max-width: calc(100% - 20px);
                margin: 10px;
            }

            .modal-content {
                border-radius: 18px;
            }

            .modal-header,
            .modal-body,
            .modal-footer {
                padding-left: 16px;
                padding-right: 16px;
            }

            .modal-footer {
                flex-direction: column;
                align-items: stretch;
            }

            .modal-footer .btn {
                width: 100%;
            }
        }

        /* Corporate consistency without changing palette */
        .card {
            border-radius: 12px;
            border: 1px solid #dbe3ef;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
        }

        .card-header {
            padding: 12px 16px;
        }

        .card-body {
            padding: 14px 16px;
        }

        .table thead th {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .02em;
        }

        .table tbody td {
            font-size: 13px;
        }

        .btn {
            border-radius: 10px;
            font-size: 14px;
            padding: 9px 14px;
        }

        .btn-sm {
            font-size: 12px;
            padding: 7px 10px;
            border-radius: 9px;
        }

        .stock-cell {
            min-width: 132px;
        }

        .stock-cell-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 6px;
        }

        .stock-qty {
            font-size: 1.05rem;
            color: #0f172a;
        }

        .stock-meter {
            height: 7px;
            border-radius: 999px;
            background: #e8edf5;
            overflow: hidden;
            margin-bottom: 4px;
        }

        .stock-meter-fill {
            display: block;
            height: 100%;
            border-radius: inherit;
            background: #22c55e;
        }

        .stock-meter-fill.is-warning {
            background: #f59e0b;
        }

        .stock-meter-fill.is-danger {
            background: #ef4444;
        }

        .stock-meter-fill.is-success {
            background: #22c55e;
        }

        .stock-min {
            color: #64748b;
            font-size: 11px;
        }

        .stock-link-advanced {
            display: inline-flex;
            align-items: center;
            margin-top: 6px;
            font-size: 12px;
            font-weight: 600;
            color: #c2410c;
            text-decoration: none;
        }

        .stock-link-advanced:hover {
            color: #9a3412;
            text-decoration: underline;
        }

        .stock-panel {
            border: 1px dashed #dbe3ef;
            border-radius: 12px;
            padding: 12px;
            background: #f8fafc;
        }

        .stock-panel-title {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #64748b;
            margin-bottom: 10px;
        }

        .stock-op-btn.active {
            background: #ff6b35;
            border-color: #ff6b35;
            color: #fff;
        }

        .stock-preview-box {
            border-radius: 12px;
            padding: 12px 14px;
            background: #fff7ed;
            border: 1px solid #fed7aa;
        }

        .form-control,
        .form-select {
            min-height: 40px;
            font-size: 14px;
            border-radius: 10px;
        }

        .modal-content {
            border-radius: 14px;
            border: 1px solid #dbe3ef;
            box-shadow: 0 16px 36px rgba(15, 23, 42, 0.18);
        }

        @media (min-width: 1200px) {
            .main-content {
                padding-top: 14px;
            }

            .card {
                margin-bottom: 12px;
            }

            .card-header {
                padding: 10px 14px;
            }

            .card-body {
                padding: 12px 14px;
            }

            .table tbody td {
                padding-top: .55rem;
                padding-bottom: .55rem;
            }

            .btn-action {
                width: 36px;
                height: 36px;
            }
        }
    </style>
    <style>
        .plan-ladder .badge-plan {
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 13px;
            display: inline-block;
        }

        .plan-ladder .current-plan {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
            transform: translateY(-2px);
        }
    </style>
    <?php empresarial_head_assets(); ?>
    <style>
        /* ---- Produtos Empresarial (nov 2026) ---- */
        body.plano-empresarial .emp-sit-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
        body.plano-empresarial .emp-sit-chip { border: 1px solid #e2e8f0; background: #fff; color: #334155; border-radius: 999px; padding: 6px 13px; font-weight: 700; font-size: 13px; }
        body.plano-empresarial .emp-sit-chip em { font-style: normal; margin-left: 4px; padding: 0 7px; border-radius: 999px; background: #f1f5f9; font-size: 11.5px; }
        body.plano-empresarial .emp-sit-chip.is-warn em { background: #fef3c7; color: #92400e; }
        body.plano-empresarial .emp-sit-chip.is-red em { background: #fee2e2; color: #b91c1c; }
        body.plano-empresarial .emp-sit-chip.active { background: #ea580c; color: #fff; border-color: #ea580c; }
        body.plano-empresarial .emp-sit-chip.active em { background: rgba(255,255,255,.25); color: #fff; }
        body.plano-empresarial .emp-margem { font-size: 11.5px; color: #047857; font-weight: 600; margin-top: 2px; white-space: nowrap; }
        body.plano-empresarial .emp-margem.is-baixa { color: #b91c1c; }
        body.plano-empresarial .emp-margem.is-sem { color: #94a3b8; font-weight: 500; }
        body.plano-empresarial .emp-stock-porcoes { display: inline-block; font-weight: 800; font-size: 13.5px; color: #047857; margin-bottom: 2px; }
        body.plano-empresarial .emp-stock-porcoes.is-baixo { color: #b45309; }
        body.plano-empresarial .emp-stock-porcoes.is-zero { color: #b91c1c; }
        body.plano-empresarial .emp-stock-cell { display: flex; flex-direction: column; align-items: flex-start; gap: 2px; }
        body.plano-empresarial .emp-sem-ficha-link { text-decoration: none; white-space: nowrap; }
        body.plano-empresarial .emp-cat-tag.is-sem-cat { color: #94a3b8 !important; font-style: italic; font-weight: 500; }
        body.plano-empresarial .emp-sel-col { width: 38px; padding-right: 0 !important; }
        body.plano-empresarial .emp-sel-col .form-check-input { width: 18px; height: 18px; cursor: pointer; }
        body.plano-empresarial .emp-lote-bar { position: sticky; top: 8px; z-index: 20; display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 10px; padding: 10px 14px; border-radius: 14px; background: #0f172a; color: #fff; box-shadow: 0 10px 24px rgba(15,23,42,.2); }
        body.plano-empresarial .emp-lote-bar strong { margin-right: 6px; }
        body.plano-empresarial .emp-lote-bar .btn { background: #1e293b; color: #fff; border: 1px solid #334155; border-radius: 10px; font-weight: 700; }
        body.plano-empresarial .emp-lote-bar .btn:hover { background: #334155; }
        body.plano-empresarial .emp-lote-bar select { width: auto; min-width: 190px; border-radius: 10px; }
        body.plano-empresarial .emp-lote-bar .emp-lote-limpar { background: transparent; border-color: transparent; color: #cbd5e1; }
        body.plano-empresarial .emp-produtos-table tr.is-sel td { background: #fff7ed !important; }
    </style>
</head>

<body class="<?php echo htmlspecialchars(empresarial_body_class('premium-ui')); ?>">
    <div class="container-fluid">
        <div class="row">
            <!-- SIDEBAR -->
            <?php include_once __DIR__ . '/includes/sidebar.php'; ?>

            <!-- MAIN CONTENT -->
            <main class="main-content col-md-9 ms-sm-auto col-lg-10">
                <div class="top-bar">
                    <div>
                        <?php if ($plano_atual === 'EMPRESARIAL'): ?>
                            <h1 class="page-title mb-1"><i class="fas fa-utensils"></i> Comando do Cardápio <?php include __DIR__ . '/includes/plan_page_badge.php'; ?></h1>
                            <div class="small text-muted">Cardápio da rede · fichas técnicas e baixa automática no Estoque Inteligente.</div>
                        <?php else: ?>
                            <h1 class="page-title mb-1"><i class="fas fa-pizza-slice"></i> Gestão de Produtos <?php include __DIR__ . '/includes/plan_page_badge.php'; ?></h1>
                            <?php if ($plano_atual === 'BASICO'): ?>
                                <div class="small text-muted">Cardápio e estoque simples por produto.</div>
                            <?php elseif ($plano_atual === 'PROFISSIONAL'): ?>
                                <div class="small text-muted">Cardápio da unidade · fichas técnicas e baixa automática nas vendas.</div>
                            <?php else: ?>
                                <div class="small text-muted">Cardápio + estoque da rede.</div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
                        <?php if ($plano_atual === 'EMPRESARIAL' && $showEstoqueAvancado): ?>
                            <a href="estoque_inteligente.php?tab=fichas" class="btn btn-sm emp-prod-cta"><i class="fas fa-clipboard-list me-1"></i>Fichas técnicas</a>
                            <a href="estoque_inteligente.php" class="btn btn-sm emp-prod-cta"><i class="fas fa-boxes-stacked me-1"></i>Estoque Inteligente</a>
                        <?php elseif ($showEstoqueAvancado): ?>
                            <?php if ($plano_atual === 'PROFISSIONAL' && $produtosSemFichaCount > 0): ?>
                                <a href="estoque_inteligente.php?tab=fichas" class="badge-custom badge-danger text-decoration-none" title="Produtos sem ficha não dão baixa no stock quando são vendidos">
                                    <i class="fas fa-triangle-exclamation me-1"></i><?php echo (int)$produtosSemFichaCount; ?> sem ficha técnica
                                </a>
                            <?php elseif ($plano_atual === 'PROFISSIONAL'): ?>
                                <span class="badge-custom badge-success"><i class="fas fa-check me-1"></i>Todos com ficha técnica</span>
                            <?php endif; ?>
                            <a href="estoque_inteligente.php?tab=fichas" class="badge-custom badge-warning text-decoration-none">Saldo via ficha técnica</a>
                        <?php else: ?>
                            <span class="badge-custom badge-success">Fonte de saldo: Estoque Simples</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="content-area">
                    <?php
                    $planUsage = plano_verificar_limite_db($planRid, 'produtos', (int)$produtos_ativos);
                    $planUsageLabel = 'Produtos activos';
                    include __DIR__ . '/includes/plan_usage_banner.php';
                    $bloquearNovoProduto = !empty($planUsageBloqueado);
                    ?>
                    <?php if ($plano_atual === 'EMPRESARIAL'):
                        $empPctAtivos = $total_produtos > 0 ? round(($produtos_ativos / $total_produtos) * 100, 1) : 0;
                        $empPctSemFicha = $total_produtos > 0 ? round(($produtosSemFichaCount / $total_produtos) * 100, 1) : 0;
                        $empComFicha = max(0, $total_produtos - $produtosSemFichaCount);
                        $empPctComFicha = $total_produtos > 0 ? round(($empComFicha / $total_produtos) * 100, 1) : 0;
                        $empCatTone = static function (string $nome): string {
                            $n = function_exists('mb_strtolower') ? mb_strtolower($nome, 'UTF-8') : strtolower($nome);
                            if (preg_match('/alco|cerveja|vinho|whisky|gin/u', $n)) {
                                return 'purple';
                            }
                            if (preg_match('/bebida|coca|fanta|sumo|suco|água|agua|refri/u', $n)) {
                                return 'blue';
                            }
                            if (preg_match('/pizza/u', $n)) {
                                return 'orange';
                            }
                            if (preg_match('/sobremesa|doce|bolo/u', $n)) {
                                return 'pink';
                            }
                            if (preg_match('/prato|principal|grelhado|hamb/u', $n)) {
                                return 'teal';
                            }
                            return 'slate';
                        };
                    ?>
                    <div class="emp-prod-kpis mb-3">
                        <div class="emp-prod-kpi">
                            <div class="emp-prod-kpi-top">
                                <span class="emp-prod-kpi-ico tone-orange"><i class="fas fa-box"></i></span>
                            </div>
                            <div class="emp-prod-kpi-val"><?php echo (int)$total_produtos; ?></div>
                            <div class="emp-prod-kpi-label">Total de Produtos</div>
                            <div class="emp-prod-kpi-sub">Todos os produtos cadastrados</div>
                        </div>
                        <div class="emp-prod-kpi">
                            <div class="emp-prod-kpi-top">
                                <span class="emp-prod-kpi-ico tone-green"><i class="fas fa-check"></i></span>
                                <em class="emp-prod-kpi-pct"><?php echo number_format($empPctAtivos, $empPctAtivos == floor($empPctAtivos) ? 0 : 1, ',', '.'); ?>%</em>
                            </div>
                            <div class="emp-prod-kpi-val"><?php echo (int)$produtos_ativos; ?></div>
                            <div class="emp-prod-kpi-label">Produtos Ativos</div>
                            <div class="emp-prod-kpi-sub">Disponíveis no cardápio</div>
                        </div>
                        <?php if ($showEstoqueAvancado): ?>
                        <div class="emp-prod-kpi <?php echo $produtosSemFichaCount > 0 ? 'is-warn' : ''; ?>">
                            <div class="emp-prod-kpi-top">
                                <span class="emp-prod-kpi-ico tone-amber"><i class="fas fa-clipboard-list"></i></span>
                                <em class="emp-prod-kpi-pct tone-amber"><?php echo number_format($empPctSemFicha, $empPctSemFicha == floor($empPctSemFicha) ? 0 : 1, ',', '.'); ?>%</em>
                            </div>
                            <div class="emp-prod-kpi-val"><?php echo (int)$produtosSemFichaCount; ?></div>
                            <div class="emp-prod-kpi-label">Sem ficha técnica</div>
                            <div class="emp-prod-kpi-sub">Precisam de ficha para baixa automática</div>
                        </div>
                        <div class="emp-prod-kpi">
                            <div class="emp-prod-kpi-top">
                                <span class="emp-prod-kpi-ico tone-blue"><i class="fas fa-link"></i></span>
                                <em class="emp-prod-kpi-pct tone-blue"><?php echo number_format($empPctComFicha, $empPctComFicha == floor($empPctComFicha) ? 0 : 1, ',', '.'); ?>%</em>
                            </div>
                            <div class="emp-prod-kpi-val"><?php echo (int)$empComFicha; ?></div>
                            <div class="emp-prod-kpi-label">Com ficha (baixa auto)</div>
                            <div class="emp-prod-kpi-sub">Stock desconta nas vendas</div>
                        </div>
                        <?php else: ?>
                        <div class="emp-prod-kpi is-warn">
                            <div class="emp-prod-kpi-top">
                                <span class="emp-prod-kpi-ico tone-amber"><i class="fas fa-exclamation-triangle"></i></span>
                            </div>
                            <div class="emp-prod-kpi-val"><?php echo (int)$estoque_baixo; ?></div>
                            <div class="emp-prod-kpi-label">Estoque baixo</div>
                            <div class="emp-prod-kpi-sub">Abaixo do mínimo</div>
                        </div>
                        <div class="emp-prod-kpi">
                            <div class="emp-prod-kpi-top">
                                <span class="emp-prod-kpi-ico tone-red"><i class="fas fa-box-open"></i></span>
                            </div>
                            <div class="emp-prod-kpi-val"><?php echo (int)$sem_estoque; ?></div>
                            <div class="emp-prod-kpi-label">Sem estoque</div>
                            <div class="emp-prod-kpi-sub">Zerados no cardápio</div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="emp-prod-toolbar card dash-emp-card mb-3">
                        <div class="card-body">
                            <?php if (true): ?>
                            <div class="emp-sit-chips" id="empSituacao" role="group" aria-label="Filtrar por situação">
                                <button type="button" class="emp-sit-chip active" data-sit="">Todos <em data-sit-count=""></em></button>
                                <?php if ($showEstoqueAvancado): ?>
                                <button type="button" class="emp-sit-chip is-warn" data-sit="semficha">Sem ficha <em data-sit-count="semficha"></em></button>
                                <button type="button" class="emp-sit-chip is-warn" data-sit="baixo">Stock baixo <em data-sit-count="baixo"></em></button>
                                <?php endif; ?>
                                <button type="button" class="emp-sit-chip" data-sit="inativo">Inativos <em data-sit-count="inativo"></em></button>
                                <?php if ($showEstoqueAvancado): ?>
                                <button type="button" class="emp-sit-chip is-red" data-sit="margem">Margem baixa <em data-sit-count="margem"></em></button>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <div class="emp-prod-cats<?php echo empty($lista_categorias) ? ' d-none' : ''; ?>" id="botoesCategoria">
                                <button type="button" class="emp-cat-chip active" data-categoria-id="">Todas</button>
                                <?php foreach ($lista_categorias as $cat):
                                    $labelCatBtn = !empty($cat['parent_nome'])
                                        ? ($cat['parent_nome'] . ' › ' . $cat['nome'])
                                        : (string)($cat['nome'] ?? '');
                                    $toneCat = $empCatTone($labelCatBtn);
                                ?>
                                    <button type="button" class="emp-cat-chip tone-<?php echo htmlspecialchars($toneCat, ENT_QUOTES, 'UTF-8'); ?>"
                                        data-categoria-id="<?php echo (int)$cat['id']; ?>"
                                        data-nome="<?php echo htmlspecialchars($labelCatBtn, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($labelCatBtn, ENT_QUOTES, 'UTF-8'); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            <div class="emp-prod-filters">
                                <div class="emp-prod-search">
                                    <i class="fas fa-search" aria-hidden="true"></i>
                                    <input type="text" id="buscar" class="form-control" placeholder="Pesquisar produto…" autocomplete="off">
                                </div>
                                <select id="filtroCategoria" class="form-select emp-prod-select">
                                    <option value="">Todas as categorias</option>
                                    <?php foreach ($lista_categorias as $cat):
                                        $labelCatFiltro = !empty($cat['parent_nome'])
                                            ? ($cat['parent_nome'] . ' › ' . $cat['nome'])
                                            : (string)($cat['nome'] ?? '');
                                    ?>
                                        <option value="<?php echo htmlspecialchars((string)$cat['id'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($labelCatFiltro, ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (!empty($bloquearNovoProduto)): ?>
                                    <button class="btn emp-split-action is-locked" type="button" disabled title="Limite do plano atingido">
                                        <span class="emp-split-action-icon"><i class="fas fa-lock"></i></span>
                                        <span class="emp-split-action-copy">
                                            <strong>Limite de produtos</strong>
                                            <small>Plano sem vagas no cardápio</small>
                                        </span>
                                    </button>
                                <?php else: ?>
                                    <button class="btn emp-split-action" type="button" onclick="abrirModal()">
                                        <span class="emp-split-action-icon"><i class="fas fa-plus"></i></span>
                                        <span class="emp-split-action-copy">
                                            <strong>Adicionar produto</strong>
                                            <small>Incluir no cardápio do salão</small>
                                        </span>
                                        <i class="fas fa-arrow-right emp-split-action-arrow" aria-hidden="true"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="emp-lote-bar" id="empLoteBar" hidden>
                        <strong><span id="empLoteN">0</span> selecionado(s)</strong>
                        <button type="button" class="btn btn-sm" data-lote="ativar"><i class="fas fa-eye me-1"></i>Ativar</button>
                        <button type="button" class="btn btn-sm" data-lote="desativar"><i class="fas fa-eye-slash me-1"></i>Desativar</button>
                        <select class="form-select form-select-sm" id="empLoteCategoria" aria-label="Mudar categoria">
                            <option value="">Mudar categoria…</option>
                            <option value="0">Sem categoria</option>
                            <?php foreach ($lista_categorias as $catL):
                                $lblL = !empty($catL['parent_nome']) ? ($catL['parent_nome'] . ' › ' . $catL['nome']) : (string)($catL['nome'] ?? ''); ?>
                                <option value="<?php echo (int)$catL['id']; ?>"><?php echo htmlspecialchars($lblL, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-sm emp-lote-limpar" data-lote="limpar">Limpar seleção</button>
                    </div>
                    <div class="card dash-emp-card emp-produtos-table-card">
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table emp-produtos-table mb-0">
                                    <thead>
                                        <tr>
                                            <th class="emp-sel-col"><input type="checkbox" class="form-check-input" id="empSelTodos" aria-label="Selecionar todos"></th>
                                            <th>Produto</th>
                                            <th>Categoria</th>
                                            <th><?php echo $showEstoqueAvancado ? 'Preço / margem' : 'Preço'; ?></th>
                                            <th><?php echo $showEstoqueAvancado ? 'Ficha / Stock' : 'Estoque'; ?></th>
                                            <th>Status</th>
                                            <th class="text-end">Acções</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tabelaProdutos">
                                        <?php foreach ($lista_produtos as $p):
                                            $pidRow = (int)($p['id'] ?? 0);
                                            $temFichaRow = !empty($produtosComFicha[$pidRow]);
                                            $catNomeRow = trim((string)($p['categoria_nome'] ?? ''));
                                            $semCatRow = $catNomeRow === '' || $catNomeRow === '—';
                                            if ($semCatRow) { $catNomeRow = 'Sem categoria'; }
                                            $porcoesRow = $temFichaRow && array_key_exists($pidRow, $empPorcoes) ? (int)$empPorcoes[$pidRow] : null;
                                            $custoRow = $temFichaRow ? ($empCusto[$pidRow] ?? null) : null;
                                            $precoRow = (float)($p['preco'] ?? 0);
                                            $margemRow = ($custoRow !== null && $precoRow > 0) ? (int)round(($precoRow - $custoRow) / $precoRow * 100) : null;
                                            $sit = [];
                                            if ($showEstoqueAvancado && !$temFichaRow) { $sit[] = 'semficha'; }
                                            if ($porcoesRow !== null && $porcoesRow <= 5) { $sit[] = 'baixo'; }
                                            if (empty($p['ativo'])) { $sit[] = 'inativo'; }
                                            if ($margemRow !== null && $margemRow < PRODUTO_MARGEM_BAIXA_PCT) { $sit[] = 'margem'; }
                                            $toneRow = $empCatTone($catNomeRow);
                                            $stockUn = max(0, (int)($p['estoque'] ?? 0));
                                            $caminho_imagem = (string)($p['imagem'] ?? '');
                                            if ($caminho_imagem !== '') {
                                                $caminho_imagem = str_replace('src/public/', '', $caminho_imagem);
                                            }
                                            if ($caminho_imagem !== '' && is_file(__DIR__ . '/' . $caminho_imagem)) {
                                                $imgSrc = $base_url . $caminho_imagem;
                                            } else {
                                                $cores = ['ea580c', '0ea5e9', '16a34a', '7c3aed', 'db2777', '0891b2'];
                                                $cor = $cores[abs(crc32((string)$p['nome'])) % count($cores)];
                                                $imgSrc = produto_img_iniciais((string)$p['nome']);
                                            }
                                        ?>
                                            <tr class="<?php echo ($showEstoqueAvancado && !$temFichaRow) ? 'emp-row-sem-ficha' : ''; ?>"
                                                data-categoria-id="<?php echo htmlspecialchars((string)($p['categoria_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                data-situacao="<?php echo htmlspecialchars(implode(' ', $sit), ENT_QUOTES, 'UTF-8'); ?>"
                                                data-produto-id="<?php echo $pidRow; ?>">
                                                <td class="emp-sel-col"><input type="checkbox" class="form-check-input emp-sel" value="<?php echo $pidRow; ?>" aria-label="Selecionar <?php echo htmlspecialchars((string)$p['nome'], ENT_QUOTES, 'UTF-8'); ?>"></td>
                                                <td>
                                                    <div class="emp-prod-cell">
                                                        <img src="<?php echo htmlspecialchars($imgSrc, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="emp-prod-thumb" width="48" height="48" loading="lazy"
                                                             data-fallback="<?php echo htmlspecialchars(produto_img_iniciais((string)$p['nome']), ENT_QUOTES, 'UTF-8'); ?>" onerror="this.onerror=null;this.src=this.dataset.fallback;">
                                                        <div class="emp-prod-copy">
                                                            <strong><?php echo htmlspecialchars((string)$p['nome'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                                            <?php if (!empty($p['descricao'])): ?>
                                                                <span><?php echo htmlspecialchars((string)$p['descricao'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <button type="button" class="emp-cat-tag tone-<?php echo htmlspecialchars($toneRow, ENT_QUOTES, 'UTF-8'); ?><?php echo $semCatRow ? ' is-sem-cat' : ''; ?>"
                                                        data-categoria-id="<?php echo htmlspecialchars((string)($p['categoria_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                        title="Filtrar por esta categoria">
                                                        <?php echo htmlspecialchars($catNomeRow, ENT_QUOTES, 'UTF-8'); ?>
                                                    </button>
                                                </td>
                                                <td class="emp-prod-price"><?php echo number_format((float)$p['preco'], 2, ',', '.'); ?> <small>MZN</small>
                                                    <?php if ($showEstoqueAvancado): ?>
                                                        <?php if ($custoRow !== null): ?>
                                                            <div class="emp-margem<?php echo $margemRow < PRODUTO_MARGEM_BAIXA_PCT ? ' is-baixa' : ''; ?>" title="Custo dos insumos pela ficha técnica">
                                                                Custo <?php echo number_format($custoRow, 2, ',', '.'); ?> · margem <?php echo (int)$margemRow; ?>%<?php echo $margemRow < PRODUTO_MARGEM_BAIXA_PCT ? ' ⚠' : ''; ?>
                                                            </div>
                                                        <?php elseif ($temFichaRow): ?>
                                                            <div class="emp-margem is-sem" title="Algum insumo da ficha ainda não tem custo registado (entrada com custo no Estoque Inteligente)">Custo por registar</div>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($showEstoqueAvancado): ?>
                                                        <div class="emp-stock-cell">
                                                            <?php if ($temFichaRow): ?>
                                                                <?php if ($porcoesRow !== null): ?>
                                                                    <span class="emp-stock-porcoes<?php echo $porcoesRow <= 0 ? ' is-zero' : ($porcoesRow <= 5 ? ' is-baixo' : ''); ?>"><?php echo $porcoesRow <= 0 ? 'Sem stock' : number_format($porcoesRow, 0, ',', '.') . ' porções'; ?></span>
                                                                <?php endif; ?>
                                                                <a href="estoque_inteligente.php?tab=fichas" class="emp-stock-link">Com ficha · baixa automática</a>
                                                            <?php else: ?>
                                                                <a href="estoque_inteligente.php?tab=fichas" class="emp-stock-badge is-warn emp-sem-ficha-link"><i class="fas fa-clipboard-list me-1"></i>Sem ficha técnica — criar</a>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <?php echo produto_render_estoque_celula($p, false); ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($p['ativo'])): ?>
                                                        <span class="emp-status is-on"><i></i>Ativo</span>
                                                    <?php else: ?>
                                                        <span class="emp-status is-off"><i></i>Inativo</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <div class="emp-prod-actions">
                                                        <button type="button" class="emp-act" onclick="editarProduto(<?php echo $pidRow; ?>)" title="Editar"><i class="fas fa-pen"></i></button>
                                                        <?php if ($showEstoqueAvancado): ?>
                                                            <a class="emp-act tone-amber" href="estoque_inteligente.php<?php echo !$temFichaRow ? '?tab=fichas' : ''; ?>" title="<?php echo !$temFichaRow ? 'Criar ficha' : 'Estoque'; ?>"><i class="fas fa-<?php echo !$temFichaRow ? 'clipboard-list' : 'boxes-stacked'; ?>"></i></a>
                                                        <?php else: ?>
                                                            <button type="button" class="emp-act tone-amber" onclick="abrirModalEstoque(<?php echo $pidRow; ?>)" title="Ajustar estoque"><i class="fas fa-boxes-stacked"></i></button>
                                                        <?php endif; ?>
                                                        <button type="button" class="emp-act tone-red" onclick="deletarProduto(<?php echo $pidRow; ?>)" title="Excluir"><i class="fas fa-trash"></i></button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($lista_produtos)): ?>
                                            <tr>
                                                <td colspan="7" class="text-center py-5">
                                                    <i class="fas fa-utensils fa-3x text-muted mb-3 d-block"></i>
                                                    <p class="text-muted mb-3">Nenhum produto cadastrado</p>
                                                    <button class="btn emp-split-action" type="button" onclick="abrirModal()">
                                                        <span class="emp-split-action-icon"><i class="fas fa-plus"></i></span>
                                                        <span class="emp-split-action-copy">
                                                            <strong>Adicionar produto</strong>
                                                            <small>Cadastrar o primeiro item</small>
                                                        </span>
                                                        <i class="fas fa-arrow-right emp-split-action-arrow" aria-hidden="true"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if (!empty($lista_produtos)): ?>
                                <div class="emp-prod-foot">
                                    <span>Mostrando <strong><?php echo count($lista_produtos); ?></strong> produto<?php echo count($lista_produtos) === 1 ? '' : 's'; ?></span>
                                    <?php if ($showEstoqueAvancado && $produtosSemFichaCount > 0): ?>
                                        <a href="estoque_inteligente.php?tab=fichas"><?php echo (int)$produtosSemFichaCount; ?> sem ficha → completar</a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php else: ?>
                    <!-- STATS -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div class="stat-icon primary"><i class="fas fa-box"></i></div>
                            </div>
                            <div class="stat-value" style="color: var(--primary);"><?php echo $total_produtos; ?></div>
                            <div class="stat-label">Total de Produtos</div>
                        </div>
                        <div class="stat-card success">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div class="stat-icon success"><i class="fas fa-check-circle"></i></div>
                            </div>
                            <div class="stat-value" style="color: var(--success);"><?php echo $produtos_ativos; ?></div>
                            <div class="stat-label">Produtos Ativos</div>
                        </div>
                        <div class="stat-card warning">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div class="stat-icon warning"><i class="fas fa-exclamation-triangle"></i></div>
                            </div>
                            <div class="stat-value" style="color: var(--warning);"><?php echo $estoque_baixo; ?></div>
                            <div class="stat-label"><?php echo $temEstoqueAvancado ? 'Baixo (campo simples)' : 'Estoque baixo'; ?></div>
                        </div>
                        <?php if (!$showEstoqueAvancado): ?>
                            <div class="stat-card" style="border-color:#fecaca;">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div class="stat-icon" style="background:#fee2e2;color:#dc2626;"><i class="fas fa-box-open"></i></div>
                                </div>
                                <div class="stat-value" style="color:#dc2626;"><?php echo $sem_estoque; ?></div>
                                <div class="stat-label">Sem estoque</div>
                            </div>
                            <div class="stat-card">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div class="stat-icon primary"><i class="fas fa-cubes"></i></div>
                                </div>
                                <div class="stat-value" style="color: var(--primary);"><?php echo number_format($total_unidades_estoque, 0, ',', '.'); ?></div>
                                <div class="stat-label">Unidades em stock</div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- BOTÕES DE CATEGORIA -->
                    <div class="card mb-4 produtos-categoria-card">
                        <div class="card-body">
                            <div class="d-flex flex-wrap gap-2" id="botoesCategoria">
                                <button class="btn btn-outline-primary categoria-btn active" data-categoria-id="">
                                    <i class="fas fa-list me-1"></i>Todas
                                </button>
                                <?php foreach ($lista_categorias as $cat):
                                    $labelCatBtn = !empty($cat['parent_nome'])
                                        ? ($cat['parent_nome'] . ' › ' . $cat['nome'])
                                        : $cat['nome'];
                                ?>
                                    <button class="btn btn-outline-primary categoria-btn"
                                        data-categoria-id="<?php echo $cat['id']; ?>"
                                        data-nome="<?php echo htmlspecialchars($labelCatBtn); ?>">
                                        <?php echo htmlspecialchars($labelCatBtn); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- AÇÕES -->
                    <div class="card produtos-toolbar-card">
                        <div class="card-body">
                            <div class="row g-3 produtos-toolbar-actions">
                                <div class="col-lg-4 col-md-6">
                                    <input type="text" id="buscar" class="form-control" placeholder="Pesquisar produto…">
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <select id="filtroCategoria" class="form-select">
                                        <option value="">Todas as categorias</option>
                                        <?php foreach ($lista_categorias as $cat):
                                            $labelCatFiltro = !empty($cat['parent_nome'])
                                                ? ($cat['parent_nome'] . ' › ' . $cat['nome'])
                                                : $cat['nome'];
                                        ?>
                                            <option value="<?php echo htmlspecialchars($cat['id']); ?>"><?php echo htmlspecialchars($labelCatFiltro); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php if (!$temEstoqueAvancado): ?>
                                    <div class="col-lg-2 col-md-6">
                                        <select id="filtroEstoque" class="form-select">
                                            <option value="">Todos os estoques</option>
                                            <option value="zerado">Sem estoque</option>
                                            <option value="baixo">Estoque baixo</option>
                                            <option value="atencao">Atenção</option>
                                            <option value="ok">Saudável</option>
                                        </select>
                                    </div>
                                <?php endif; ?>
                                <div class="col-lg-<?php echo $showEstoqueAvancado ? '6' : '4'; ?> col-md-6">
                                    <?php if (!empty($bloquearNovoProduto)): ?>
                                        <button class="btn btn-secondary w-100" type="button" disabled title="Limite do plano atingido">
                                            <i class="fas fa-lock me-2"></i>Limite de produtos
                                        </button>
                                    <?php else: ?>
                                        <button class="btn btn-primary w-100" onclick="abrirModal()"><i class="fas fa-plus me-2"></i>Novo Produto</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- TABELA -->
                    <div class="card">
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Imagem</th>
                                            <th>Produto</th>
                                            <th>Categoria</th>
                                            <th>Preço</th>
                                            <th><?php echo $showEstoqueAvancado ? 'Saldo' : 'Estoque / Mín.'; ?></th>
                                            <th>Status</th>
                                            <th class="text-center">Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tabelaProdutos">
                                        <?php foreach ($lista_produtos as $p): ?>
                                            <tr>
                                                <td>
                                                    <?php
                                                    $caminho_imagem = $p['imagem'];
                                                    if ($caminho_imagem) {
                                                        $caminho_imagem = str_replace('src/public/', '', $caminho_imagem);
                                                    }

                                                    if (!empty($caminho_imagem) && file_exists(__DIR__ . '/' . $caminho_imagem)) {
                                                        $imgSrc = $base_url . $caminho_imagem;
                                                    } else {
                                                        $cores = ['FF6B35', 'F7931E', '10b981', '3b82f6', '8b5cf6', 'ec4899'];
                                                        $cor = $cores[array_rand($cores)];
                                                        $imgSrc = produto_img_iniciais((string)$p['nome']);
                                                    }
                                                    ?>
                                                    <img src="<?php echo $imgSrc; ?>" class="product-img" alt="<?php echo htmlspecialchars($p['nome']); ?>" style="width: 80px; height: 80px;" data-fallback="<?php echo htmlspecialchars(produto_img_iniciais((string)$p['nome']), ENT_QUOTES, 'UTF-8'); ?>" onerror="this.onerror=null;this.src=this.dataset.fallback;">
                                                </td>
                                                <td>
                                                    <div class="product-info">
                                                        <span class="product-name"><?php echo htmlspecialchars($p['nome']); ?></span>
                                                        <?php if (!empty($p['descricao'])): ?>
                                                            <span class="product-desc"><?php echo htmlspecialchars($p['descricao']); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td style="cursor:pointer; color:#FF6B35; text-decoration:underline;" title="Clique para filtrar por esta categoria" data-categoria-id="<?php echo htmlspecialchars($p['categoria_id']); ?>"><?php echo htmlspecialchars($p['categoria_nome'] ?? '—'); ?></td>
                                                <td><strong><?php echo number_format($p['preco'], 2, ',', '.'); ?> MZN</strong></td>
                                                <td><?php
                                                    $pidCel = (int)($p['id'] ?? 0);
                                                    $temFichaCel = !empty($produtosUsaFicha) && $showEstoqueAvancado
                                                        ? !empty($produtosComFicha[$pidCel])
                                                        : null;
                                                    $porcoesCel = array_key_exists($pidCel, $empPorcoes) ? (int)$empPorcoes[$pidCel] : null;
                                                    echo produto_render_estoque_celula($p, $showEstoqueAvancado, $plano_atual === 'EMPRESARIAL', $temFichaCel, $porcoesCel);
                                                ?></td>
                                                <td>
                                                    <span class="produto-status-stack">
                                                        <?php if ($p['ativo']): ?>
                                                            <span class="badge-custom badge-success">Ativo</span>
                                                        <?php else: ?>
                                                            <span class="badge-custom badge-danger">Inativo</span>
                                                        <?php endif; ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <button class="btn btn-info btn-action btn-sm" onclick="editarProduto(<?php echo $p['id']; ?>)" title="Editar"><i class="fas fa-edit"></i></button>
                                                    <?php if (!$temEstoqueAvancado): ?>
                                                        <button class="btn btn-warning btn-action btn-sm" onclick="abrirModalEstoque(<?php echo (int)$p['id']; ?>)" title="Ajustar estoque"><i class="fas fa-boxes-stacked"></i></button>
                                                    <?php elseif ($plano_atual === 'EMPRESARIAL'): ?>
                                                        <a class="btn btn-warning btn-action btn-sm" href="estoque_inteligente.php" title="Estoque Inteligente"><i class="fas fa-boxes-stacked"></i></a>
                                                    <?php else: ?>
                                                        <a class="btn btn-warning btn-action btn-sm" href="estoque_inteligente.php?tab=fichas" title="Ficha técnica"><i class="fas fa-clipboard-list"></i></a>
                                                    <?php endif; ?>
                                                    <button class="btn btn-danger btn-action btn-sm" onclick="deletarProduto(<?php echo $p['id']; ?>)" title="Excluir"><i class="fas fa-trash"></i></button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($lista_produtos)): ?>
                                            <tr>
                                                <td colspan="7" class="text-center py-5">
                                                    <i class="fas fa-pizza-slice fa-3x text-muted mb-3 d-block"></i>
                                                    <p class="text-muted mb-3">Nenhum produto cadastrado</p>
                                                    <button class="btn btn-primary" onclick="abrirModal()"><i class="fas fa-plus me-2"></i>Cadastrar primeiro produto</button>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- MODAL PRODUTO -->
    <div class="modal fade<?php echo $plano_atual === 'EMPRESARIAL' ? ' emp-form-modal' : ''; ?>" id="modalProduto" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered<?php echo $plano_atual === 'EMPRESARIAL' ? ' emp-form-dialog' : ''; ?>">
            <div class="modal-content<?php echo $plano_atual === 'EMPRESARIAL' ? ' emp-form-content' : ''; ?>">
                <div class="modal-header<?php echo $plano_atual === 'EMPRESARIAL' ? '' : ' bg-primary text-white'; ?>">
                    <?php if ($plano_atual === 'EMPRESARIAL'): ?>
                        <div class="emp-form-heading">
                            <span class="emp-form-heading-icon"><i class="fas fa-utensils"></i></span>
                            <div>
                                <span class="emp-form-kicker"><i class="fas fa-crown"></i> Cardápio do salão</span>
                                <h5 class="modal-title" id="tituloModal">Novo Produto</h5>
                                <p>Defina o nome, o preço e a categoria que o cliente vê no cardápio.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <h5 class="modal-title" id="tituloModal"><i class="fas fa-plus me-2"></i>Novo Produto</h5>
                    <?php endif; ?>
                    <button type="button" class="btn-close<?php echo $plano_atual === 'EMPRESARIAL' ? '' : ' btn-close-white'; ?>" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert" id="alertModal" style="display: none;"></div>
                    <form id="formProduto" enctype="multipart/form-data"<?php echo $plano_atual === 'EMPRESARIAL' ? ' class="emp-form-fields"' : ''; ?>>
                        <input type="hidden" id="produto_id" name="produto_id">

                        <!-- Foto Centralizada -->
                        <div class="text-center mb-4">
                            <div class="position-relative d-inline-block">
                                <img id="imagemPreview" src="" class="img-fluid rounded-circle"
                                    style="width:120px;height:120px;object-fit:cover;border:4px solid #FF6B35;display:none;">
                                <div id="imagemPlaceholder" class="rounded-circle bg-light d-flex align-items-center justify-content-center"
                                    style="width:120px;height:120px;border:4px solid #FF6B35;">
                                    <i class="fas fa-utensils fa-3x text-muted"></i>
                                </div>
                                <button type="button" class="btn btn-sm btn-primary position-absolute bottom-0 end-0 rounded-circle"
                                    style="width:36px;height:36px;" onclick="document.getElementById('imagem').click()">
                                    <i class="fas fa-camera"></i>
                                </button>
                            </div>
                            <input type="file" id="imagem" name="imagem" accept="image/*" style="display:none;" onchange="previewImagem(this)">
                            <input type="hidden" id="imagem_existing" name="imagem_existing">
                            <p class="text-muted small mt-2">Foto do prato no cardápio</p>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label" for="nome">Nome no cardápio *</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-tag"></i></span>
                                    <input type="text" id="nome" name="nome" class="form-control" required placeholder="Nome que o cliente vê no cardápio">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="preco">Preço de venda (MZN) *</label>
                                <div class="input-group">
                                    <span class="input-group-text">MZN</span>
                                    <input type="number" id="preco" name="preco" class="form-control" step="0.01" min="0" required placeholder="Preço cobrado no pedido">
                                </div>
                            </div>
                        </div>

                        <div class="row g-3 mt-2">
                            <div class="col-12">
                                <label class="form-label">Categoria do cardápio</label>
                                <div class="input-group mb-2">
                                    <input type="text" id="novaCategoria" class="form-control" placeholder="Nome da nova categoria" autocomplete="off">
                                    <button class="btn btn-success" type="button" onclick="adicionarNovaCategoria()"><i class="fas fa-plus-circle"></i></button>
                                </div>
                                <?php if (!empty($temSubcategorias)): ?>
                                <div class="input-group mb-2">
                                    <select id="categoriaPaiSub" class="form-select" style="max-width: 45%;">
                                        <option value="">Categoria pai do cardápio</option>
                                        <?php foreach ($lista_categorias as $cat): ?>
                                            <?php if (empty($cat['parent_id'])): ?>
                                            <option value="<?php echo (int)$cat['id']; ?>"><?php echo htmlspecialchars($cat['nome']); ?></option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="text" id="novaSubcategoria" class="form-control" placeholder="Nome da nova subcategoria" autocomplete="off">
                                    <button class="btn btn-outline-success" type="button" onclick="adicionarNovaSubcategoria()" title="Criar subcategoria">
                                        <i class="fas fa-sitemap"></i>
                                    </button>
                                </div>
                                <?php endif; ?>
                                <select id="categoria_id" name="categoria_id" class="form-select">
                                    <option value="">Secção do cardápio</option>
                                    <?php foreach ($lista_categorias as $cat):
                                        $labelCat = !empty($cat['parent_nome'])
                                            ? ($cat['parent_nome'] . ' › ' . $cat['nome'])
                                            : $cat['nome'];
                                    ?>
                                        <option value="<?php echo htmlspecialchars($cat['id']); ?>"><?php echo htmlspecialchars($labelCat); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="row g-3 mt-2">
                            <div class="col-12">
                                <label class="form-label" for="descricao">Descrição para o cliente</label>
                                <textarea id="descricao" name="descricao" class="form-control" rows="2" placeholder="Texto que aparece junto do prato no cardápio"></textarea>
                            </div>
                        </div>

                        <div class="row g-3 mt-2">
                            <div class="col-md-4">
                                <label class="form-label" for="custo">Custo do prato (MZN)</label>
                                <div class="input-group">
                                    <span class="input-group-text">MZN</span>
                                    <input type="number" id="custo" name="custo" class="form-control" step="0.01" min="0" placeholder="Custo para calcular a margem">
                                </div>
                            </div>
                            <div class="col-md-4 simple-stock-only" <?php echo $showEstoqueAvancado ? 'style="display:none;"' : ''; ?>>
                                <div class="stock-panel">
                                    <div class="stock-panel-title"><i class="fas fa-boxes-stacked me-1"></i>Controle de estoque</div>
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <label class="form-label">Quantidade actual</label>
                                            <input type="number" id="estoque" name="estoque" class="form-control" value="0" min="0" placeholder="Unidades em stock agora">
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label">Estoque mínimo</label>
                                            <input type="number" id="estoque_minimo" name="estoque_minimo" class="form-control" value="5" min="0" placeholder="Alerta de stock baixo">
                                        </div>
                                    </div>
                                    <div class="form-text mt-2">Quando o saldo atingir o mínimo, o produto aparece como estoque baixo.</div>
                                </div>
                            </div>
                        </div>

                        <div class="form-check form-switch mt-3">
                            <input type="checkbox" id="ativo" name="ativo" class="form-check-input" checked>
                            <label class="form-check-label">Disponível no cardápio</label>
                        </div>

                        <div class="form-check form-switch mt-2">
                            <input type="checkbox" id="destaque" name="destaque" class="form-check-input">
                            <label class="form-check-label">Destaque no cardápio público</label>
                            <div class="form-text">Aparece no carrossel de destaque do cardápio.</div>
                        </div>
                    </form>
                    <?php if ($plano_atual === 'EMPRESARIAL'): ?>
                        <aside class="emp-form-preview" aria-live="polite">
                            <div class="emp-form-preview-top">
                                <span>Pré-visualização no cardápio</span>
                            </div>
                            <div class="emp-form-preview-card">
                                <span class="emp-form-heading-icon" style="width:42px;height:42px;flex-basis:42px;font-size:16px;background:#fff7ed;color:#9a3412;border-color:#fed7aa;"><i class="fas fa-utensils"></i></span>
                                <div>
                                    <strong id="prodPreviewNome">Nome no cardápio</strong>
                                    <small id="prodPreviewPreco">Preço de venda</small>
                                </div>
                            </div>
                            <p>O produto fica visível no cardápio depois de gravar, se estiver disponível.</p>
                        </aside>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" form="formProduto" class="btn<?php echo $plano_atual === 'EMPRESARIAL' ? ' emp-form-submit' : ' btn-primary'; ?>"><i class="fas fa-save me-2"></i>Salvar produto</button>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$showEstoqueAvancado): ?>
        <div class="modal fade" id="modalEstoque" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title"><i class="fas fa-boxes-stacked me-2"></i>Ajustar estoque</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="estoqueProdutoId" value="">
                        <p class="mb-2"><strong id="estoqueProdutoNome">Produto</strong></p>
                        <div class="stock-preview-box mb-3">
                            <div class="d-flex justify-content-between">
                                <span>Saldo atual</span>
                                <strong id="estoqueAtualValor">0</strong>
                            </div>
                            <div class="d-flex justify-content-between mt-1">
                                <span>Estoque mínimo</span>
                                <span id="estoqueMinimoValor">0</span>
                            </div>
                        </div>
                        <label class="form-label">Tipo de movimento</label>
                        <div class="btn-group w-100 mb-3" role="group" id="estoqueTipoGrupo">
                            <button type="button" class="btn btn-outline-warning stock-op-btn active" data-tipo="ENTRADA">Entrada</button>
                            <button type="button" class="btn btn-outline-warning stock-op-btn" data-tipo="SAIDA">Saída</button>
                            <button type="button" class="btn btn-outline-warning stock-op-btn" data-tipo="DEFINIR">Definir saldo</button>
                        </div>
                        <label class="form-label" for="estoqueQuantidadeInput" id="estoqueQuantidadeLabel">Quantidade da entrada</label>
                        <input type="number" id="estoqueQuantidadeInput" class="form-control" min="0" value="1" placeholder="Quantidade a ajustar">
                        <div class="form-text mt-2">Novo saldo previsto: <strong id="estoqueNovoPreview">0</strong></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="button" class="btn btn-warning" id="btnConfirmarEstoque"><i class="fas fa-check me-1"></i>Confirmar</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script src="lib/bootstrap-5.3.2/js/bootstrap.bundle.min.js"></script>
    <script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
        const BASE_URL = '<?php echo $base_url; ?>';
        const RESTAURANTE_ID = <?php echo (int)$restaurante_id; ?>;
        const CSRF_TOKEN = <?php echo json_encode($csrf_token, JSON_UNESCAPED_UNICODE); ?>;
        const ESTOQUE_AVANCADO = <?php echo $showEstoqueAvancado ? 'true' : 'false'; ?>;
        const ESTOQUE_EMP_COCKPIT = <?php echo ($plano_atual === 'EMPRESARIAL') ? 'true' : 'false'; ?>;
        const TEM_SUBCATEGORIAS = <?php echo !empty($temSubcategorias) ? 'true' : 'false'; ?>;
        const PRODUTOS_INICIAIS = <?php echo json_encode($lista_produtos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    </script>
    <script src="js/produtos.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/produtos.js'); ?>"></script>
    <link rel="stylesheet" href="css/feedback.css?v=<?php echo (int)@filemtime(__DIR__ . '/css/feedback.css'); ?>">
    <script src="js/feedback.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/feedback.js'); ?>"></script>
    <script src="js/nivora_dialogo.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nivora_dialogo.js'); ?>"></script>
</body>

</html>
