<?php
require_once __DIR__ . '/app_base.php';
require_once APP_BASE . '/src/config/reserva_qr_schema.php';

/*
   CARDAPIO DIGITAL - PAGINA PUBLICA
 */

$rid      = (int)($_GET['rid'] ?? 0);
$mesa_id  = (int)($_GET['mesa_id'] ?? ($_GET['mesa'] ?? 0));

if (!$rid && trim((string)($_GET['token'] ?? '')) === '') {
    http_response_code(404);
    die('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Erro</title></head><body style="font-family:sans-serif;text-align:center;padding:60px;color:#666"><h2>QR Code inválido</h2><p>Por favor, escaneie o QR Code correto.</p></body></html>');
}

include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/plano_check.php';
include_once APP_BASE . '/src/config/promocao_schema.php';

$database = new Database();
$db = $database->getConnection();

if (function_exists('security_is_production') && security_is_production() && !security_is_https()) {
    http_response_code(403);
    die('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>HTTPS obrigatorio</title></head><body style="font-family:sans-serif;text-align:center;padding:60px;color:#666"><h2>Ligacao insegura</h2><p>Use HTTPS para abrir o cardapio.</p></body></html>');
}

if (function_exists('security_rate_limit') && !security_rate_limit('cardapio_publico', 120, 60)) {
    http_response_code(429);
    die('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Muitas tentativas</title></head><body style="font-family:sans-serif;text-align:center;padding:60px;color:#666"><h2>Muitas tentativas</h2><p>Aguarde alguns instantes e tente novamente.</p></body></html>');
}

$token_publico = trim((string)($_GET['token'] ?? ''));
$sessao_cardapio = null;
if ($token_publico !== '') {
    if (function_exists('security_rate_limit') && !security_rate_limit('cardapio_token_' . substr(hash('sha256', $token_publico), 0, 24), 60, 60)) {
        http_response_code(429);
        die('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Muitas tentativas</title></head><body style="font-family:sans-serif;text-align:center;padding:60px;color:#666"><h2>Muitas tentativas</h2><p>Aguarde alguns instantes e tente novamente.</p></body></html>');
    }
    $sessao_cardapio = reserva_qr_get_session_by_token($db, $token_publico, true);
    if (!$sessao_cardapio) {
        http_response_code(404);
        die('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Erro</title></head><body style="font-family:sans-serif;text-align:center;padding:60px;color:#666"><h2>QR Code invalido ou expirado</h2><p>Por favor, escaneie novamente o QR Code da mesa.</p></body></html>');
    }
    $rid = (int)($sessao_cardapio['restaurante_id'] ?? 0);
    $mesa_id = (int)($sessao_cardapio['mesa_id'] ?? 0);
}

if (!$rid) {
    http_response_code(404);
    die('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Erro</title></head><body style="font-family:sans-serif;text-align:center;padding:60px;color:#666"><h2>QR Code invalido</h2><p>Por favor, escaneie o QR Code correto.</p></body></html>');
}

// Buscar restaurante
$stmt = $db->prepare("SELECT * FROM restaurantes WHERE id = :id LIMIT 1");
$stmt->bindParam(':id', $rid);
$stmt->execute();
$restaurante = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$restaurante) {
    http_response_code(404);
    die('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Erro</title></head><body style="font-family:sans-serif;text-align:center;padding:60px;color:#666"><h2>Restaurante não encontrado</h2></body></html>');
}

$temCardapioDigital = plano_tem_funcionalidade_db($rid, 'cardapio_digital');
$temPedidosOnline = plano_tem_funcionalidade_db($rid, 'pedidos_online');
$temEstoqueAvancado = plano_tem_funcionalidade_db($rid, 'estoque_avancado');
$temSubcategorias = plano_tem_funcionalidade_db($rid, 'subcategorias');
$temNotificacoes = plano_tem_funcionalidade_db($rid, 'notificacoes');
$planoCardapio = plano_normalizar_nome((plano_get_dados($rid)['plano_nome'] ?? 'BASICO'));
$isCardapioEmpresarial = $planoCardapio === 'EMPRESARIAL';
$promocoes = promocao_plano_permitido($planoCardapio) ? promocao_listar_ativas($db, $rid) : [];
if (!$temCardapioDigital && !$temPedidosOnline) {
    http_response_code(403);
    die('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Indisponível</title></head><body style="font-family:sans-serif;text-align:center;padding:60px;color:#666"><h2>Cardápio digital indisponível</h2><p>Este restaurante não tem cardápio digital activo no plano actual.</p></body></html>');
}

// Buscar mesa
$mesa = null;
$pedido_qr_token = '';
$pedido_qr_habilitado = false;
$modo_takeaway = false;
if ($sessao_cardapio && $mesa_id && $temPedidosOnline) {
    $stmt = $db->prepare("SELECT * FROM mesas WHERE id = :id AND restaurante_id = :rid LIMIT 1");
    $stmt->bindParam(':id',  $mesa_id);
    $stmt->bindParam(':rid', $rid);
    $stmt->execute();
    $mesa = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($mesa) {
        $pedido_qr_token = $token_publico;
        $pedido_qr_habilitado = true;
    }
} elseif (false && $mesa_id && $temPedidosOnline) {
    $stmt = $db->prepare("SELECT * FROM mesas WHERE id = :id AND restaurante_id = :rid LIMIT 1");
    $stmt->bindParam(':id',  $mesa_id);
    $stmt->bindParam(':rid', $rid);
    $stmt->execute();
    $mesa = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($mesa) {
        $sessao_qr = reserva_qr_create_session($db, $rid, [
            'mesa_id' => (int)$mesa['id'],
            'expira_em_minutos' => 1440,
            'observacoes' => 'cardapio_publico_mesa',
        ]);
        if (!empty($sessao_qr['success']) && !empty($sessao_qr['token'])) {
            $pedido_qr_token = (string)$sessao_qr['token'];
            $pedido_qr_habilitado = true;
        }
    }
} elseif (false && $temPedidosOnline) {
    // PRO+: pedir sem QR de mesa (takeaway / balcão) — reutiliza token em cookie
    $cookieKey = 'cardapio_tk_' . $rid;
    $tokenCookie = trim((string)($_COOKIE[$cookieKey] ?? ''));
    if ($tokenCookie !== '') {
        $sessaoExistente = reserva_qr_get_session_by_token($db, $tokenCookie, true);
        if ($sessaoExistente && (int)($sessaoExistente['restaurante_id'] ?? 0) === $rid) {
            $pedido_qr_token = $tokenCookie;
            $pedido_qr_habilitado = true;
            $modo_takeaway = true;
        }
    }
    if (!$pedido_qr_habilitado) {
        $sessao_qr = reserva_qr_create_session($db, $rid, [
            'mesa_id' => null,
            'expira_em_minutos' => 1440,
            'observacoes' => 'cardapio_publico_takeaway',
        ]);
        if (!empty($sessao_qr['success']) && !empty($sessao_qr['token'])) {
            $pedido_qr_token = (string)$sessao_qr['token'];
            $pedido_qr_habilitado = true;
            $modo_takeaway = true;
            if (!headers_sent()) {
                setcookie($cookieKey, $pedido_qr_token, [
                    'expires' => time() + 86400,
                    'path' => '/',
                    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
        }
    }
} elseif ($mesa_id) {
    $stmt = $db->prepare("SELECT * FROM mesas WHERE id = :id AND restaurante_id = :rid LIMIT 1");
    $stmt->bindParam(':id',  $mesa_id);
$stmt->bindParam(':rid', $rid);
$stmt->execute();
    $mesa = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Produtos activos (hierarquia de categorias se houver parent_id)
if (is_file(APP_BASE . '/src/config/categoria_schema.php')) {
    require_once APP_BASE . '/src/config/categoria_schema.php';
    if (function_exists('categoria_schema_garantir')) {
        categoria_schema_garantir($db);
    }
}

// O menu publico e aberto por muitos clientes ao mesmo tempo: os produtos ficam em cache 45 s
// por restaurante (uma alteracao de preco ou foto aparece no maximo 45 s depois).
require_once APP_BASE . '/src/config/cache_curto.php';
$todos_produtos = cache_curto_lembrar('cardapio_produtos:' . (int)$rid, 45, static function () use ($db, $rid) {
    $stmt = $db->prepare("
        SELECT p.*,
               c.nome AS categoria_nome,
               c.parent_id AS categoria_parent_id,
               cp.nome AS categoria_pai_nome
        FROM produtos p
        LEFT JOIN categorias c ON p.categoria_id = c.id
        LEFT JOIN categorias cp ON cp.id = c.parent_id
        WHERE p.restaurante_id = :rid AND p.ativo = 1
        ORDER BY COALESCE(cp.nome, c.nome) ASC, c.parent_id IS NOT NULL ASC, c.nome ASC, p.nome ASC
    ");
    $stmt->bindParam(':rid', $rid);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
});

// Agrupar por categoria (subcategorias só no plano com a funcionalidade)
$por_categoria = [];
$categoria_parents = [];
$categorias_filtro = [];
foreach ($todos_produtos as $p) {
    $pai = trim((string)($p['categoria_pai_nome'] ?? ''));
    $cat = trim((string)($p['categoria_nome'] ?? 'Outros'));
    if ($cat === '') {
        $cat = 'Outros';
    }
    if ($temSubcategorias && $pai !== '') {
        $label = $pai . ' › ' . $cat;
        $parent = $pai;
    } elseif (!$temSubcategorias && $pai !== '') {
        $label = $pai;
        $parent = '';
    } else {
        $label = $cat;
        $parent = '';
    }
    $por_categoria[$label][] = $p;
    $categoria_parents[$label] = $parent;
    if ($parent !== '') {
        $categorias_filtro[$parent] = true;
    }
    $categorias_filtro[$label] = true;
}
$lista_filtros_categoria = array_keys($categorias_filtro);
usort($lista_filtros_categoria, static function ($a, $b) {
    $aSub = strpos($a, ' › ') !== false;
    $bSub = strpos($b, ' › ') !== false;
    if ($aSub !== $bSub) {
        return $aSub ? 1 : -1;
    }
    return strnatcasecmp($a, $b);
});

// Base URL para imagens Ã¢â‚¬â€ aponta para src/public onde as imagens sÃƒÂ£o salvas
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443 ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
$script_dir = dirname($_SERVER['SCRIPT_NAME']);
$base_url = rtrim($protocol . $host . $script_dir, '/');
$imagemMobileUrl = static function ($imagem) use ($base_url): string {
    $imagem = (string)$imagem;
    if (!preg_match('~^images/(p_[a-zA-Z0-9._-]+)\\.jpg$~', $imagem, $matches)) {
        return '';
    }
    $mobileName = $matches[1] . '.mobile.webp';
    return is_file(__DIR__ . '/images/' . $mobileName)
        ? $base_url . '/images/' . $mobileName
        : '';
};

// Monta URL segura do logotipo (aceita URL absoluta ou caminho salvo no sistema)
function restaurante_logo_url(?string $logoPath, string $baseUrl): string
{
    $logoPath = trim((string)$logoPath);
    if ($logoPath === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $logoPath)) {
        return $logoPath;
    }
    return rtrim($baseUrl, '/') . '/' . ltrim(str_replace('\\', '/', $logoPath), '/');
}

$logo_url = restaurante_logo_url($restaurante['logo'] ?? '', $base_url);

include_once APP_BASE . '/src/config/brand_theme_helper.php';
$brandTheme = brand_theme_resolve($restaurante);
$brandPrimary = $brandTheme['primary'];
$brandAccent = $brandTheme['accent'];

// Destaques: mais vendidos (30 dias) → flag destaque → fallback imagem
$produtos_destaque = [];
$idsMaisVendidos = [];
$quantidadeMaisVendidos = [];
try {
    $stmtTop = $db->prepare("
        SELECT ip.produto_id, SUM(ip.quantidade) AS qtd
        FROM itens_pedido ip
        INNER JOIN pedidos pe ON pe.id = ip.pedido_id
        WHERE pe.restaurante_id = :rid
          AND pe.criado_em >= (NOW() - INTERVAL 30 DAY)
          AND UPPER(COALESCE(pe.status,'')) NOT IN ('CANCELADO')
        GROUP BY ip.produto_id
        ORDER BY qtd DESC
        LIMIT 6
    ");
    $stmtTop->execute([':rid' => $rid]);
    $linhasMaisVendidos = $stmtTop->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $idsMaisVendidos = array_map('intval', array_column($linhasMaisVendidos, 'produto_id'));
    foreach ($linhasMaisVendidos as $linhaMaisVendida) {
        $quantidadeMaisVendidos[(int)($linhaMaisVendida['produto_id'] ?? 0)] = (int)($linhaMaisVendida['qtd'] ?? 0);
    }
} catch (Throwable $e) {
    $idsMaisVendidos = [];
    $quantidadeMaisVendidos = [];
}
$byId = [];
foreach ($todos_produtos as $p) {
    $byId[(int)$p['id']] = $p;
}
foreach ($idsMaisVendidos as $pid) {
    if (isset($byId[$pid])) {
        $produtos_destaque[] = $byId[$pid];
    }
}
if (count($produtos_destaque) < 2) {
foreach ($todos_produtos as $produtoItem) {
    if (!empty($produtoItem['destaque'])) {
        $produtos_destaque[] = $produtoItem;
        }
    }
}
if (empty($produtos_destaque)) {
    foreach ($todos_produtos as $produtoItem) {
        if (!empty($produtoItem['imagem'])) {
            $produtos_destaque[] = $produtoItem;
            if (count($produtos_destaque) >= 4) {
                break;
            }
        }
    }
}
if (empty($produtos_destaque) && !empty($todos_produtos)) {
    $produtos_destaque[] = $todos_produtos[0];
}
// Deduplicar e limitar
$seen = [];
$dedup = [];
foreach ($produtos_destaque as $pd) {
    $id = (int)($pd['id'] ?? 0);
    if ($id <= 0 || isset($seen[$id])) continue;
    $seen[$id] = true;
    $dedup[] = $pd;
    if (count($dedup) >= 6) break;
}
$produtos_destaque = $dedup;
$destaquesDeVendas = false;
if (!empty($produtos_destaque) && !empty($idsMaisVendidos)) {
    $destaquesDeVendas = in_array((int)$produtos_destaque[0]['id'], $idsMaisVendidos, true);
}

require_once APP_BASE . '/src/config/catalogo_nome_helper.php';

$mesaNumeroLabel = $mesa ? (string)($mesa['numero'] ?? '') : '';
$heroBadgeClass = $pedido_qr_habilitado ? 'can-order' : 'browse-only';
if ($pedido_qr_habilitado && $mesa) {
    $heroBadgeText = 'Mesa ' . $mesaNumeroLabel . ' · pode pedir';
} elseif ($pedido_qr_habilitado && $modo_takeaway) {
    $heroBadgeText = 'Para levar · pode pedir';
} elseif ($mesa) {
    $heroBadgeText = 'Mesa ' . $mesaNumeroLabel . ' · só visualização';
} elseif ($temCardapioDigital && !$temPedidosOnline) {
    $heroBadgeText = 'Cardápio digital · só visualização';
} else {
    $heroBadgeText = 'Cardápio digital';
}

$empIntel = null;
if ($isCardapioEmpresarial) {
    $horaLocal = (int)date('G');
    $empMomento = $horaLocal < 11 ? 'manhã' : ($horaLocal < 16 ? 'almoço' : 'jantar');
    $empSugestao = $produtos_destaque[0] ?? null;
    $empSugestaoId = (int)($empSugestao['id'] ?? 0);
    $empSugestaoNome = trim((string)($empSugestao['nome'] ?? ''));
    $empSugestaoCat = trim((string)($empSugestao['categoria_nome'] ?? ''));
    $empSugestaoPedidos = $empSugestaoId > 0 ? (int)($quantidadeMaisVendidos[$empSugestaoId] ?? 0) : 0;
    $empTopCats = [];
    foreach ($produtos_destaque as $itemDestaque) {
        $catNome = trim((string)($itemDestaque['categoria_nome'] ?? ''));
        if ($catNome !== '' && !in_array($catNome, $empTopCats, true)) {
            $empTopCats[] = $catNome;
        }
        if (count($empTopCats) >= 3) {
            break;
        }
    }
    $empIntel = [
        'momento' => $empMomento,
        'itens' => count($todos_produtos),
        'sugestao' => $empSugestaoNome,
        'sugestao_id' => $empSugestaoId,
        'categoria' => $empSugestaoCat,
        'pedidos' => $empSugestaoPedidos,
        'categorias' => $empTopCats,
        'ofertas' => count($promocoes),
    ];
}
?>
<!DOCTYPE html>
<html lang="pt">

<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="<?php echo htmlspecialchars($brandPrimary); ?>">
    <title><?php echo htmlspecialchars($restaurante['nome']); ?> - Cardápio</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/cardapio.css?v=<?= (int)filemtime(__DIR__ . '/css/cardapio.css') ?>">
    <link rel="stylesheet" href="css/cardapio-standard.css?v=<?= (int)filemtime(__DIR__ . '/css/cardapio-standard.css') ?>">
    <link rel="stylesheet" href="css/feedback.css?v=<?= (int)filemtime(__DIR__ . '/css/feedback.css') ?>">
    <style>
        :root {
            --primary: <?php echo htmlspecialchars($brandPrimary); ?>;
            --accent: <?php echo htmlspecialchars($brandAccent); ?>;
            --primary-dark: <?php echo htmlspecialchars($brandTheme['primary_dark'], ENT_QUOTES, 'UTF-8'); ?>;
            --primary-soft: <?php echo htmlspecialchars($brandTheme['primary_soft'], ENT_QUOTES, 'UTF-8'); ?>;
            --gradient: linear-gradient(135deg, <?php echo htmlspecialchars($brandPrimary); ?> 0%, <?php echo htmlspecialchars($brandAccent); ?> 100%);
            --brand-on-primary: <?php echo htmlspecialchars($brandTheme['on_primary'], ENT_QUOTES, 'UTF-8'); ?>;
            --brand-surface: <?php echo htmlspecialchars(brand_theme_mix($brandPrimary, '#FFFFFF', 0.93), ENT_QUOTES, 'UTF-8'); ?>;
            --brand-border: <?php echo htmlspecialchars(brand_theme_mix($brandPrimary, '#FFFFFF', 0.72), ENT_QUOTES, 'UTF-8'); ?>;
        }
    </style>
</head>

<body class="<?php echo $pedido_qr_habilitado ? 'order-mode' : 'browse-mode'; ?><?php echo $isCardapioEmpresarial ? ' cardapio-empresarial' : ''; ?>">
    <div class="menu-shell">
        <aside class="menu-sidebar">
            <div class="menu-sidebar-brand">
                <div class="menu-sidebar-logo">
                    <?php if ($logo_url): ?>
                        <img src="<?php echo htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo <?php echo htmlspecialchars($restaurante['nome']); ?>">
                    <?php else: ?>
                        <span><?php echo substr(strtoupper($restaurante['nome']), 0, 2); ?></span>
                    <?php endif; ?>
                </div>
                <h2 class="menu-sidebar-title"><?php echo htmlspecialchars($restaurante['nome']); ?></h2>
                <p class="menu-sidebar-subtitle"><?php
                    if ($mesa) {
                        echo 'Mesa ' . htmlspecialchars($mesa['numero']);
                    } elseif ($modo_takeaway) {
                        echo 'Pedido para levar';
                    } else {
                        echo 'Cardápio digital';
                    }
                ?></p>
                <?php if ($isCardapioEmpresarial): ?>
                    <span class="menu-emp-kicker">Experiência empresarial</span>
                <?php endif; ?>
            </div>

            <nav class="menu-sidebar-nav" aria-label="Categorias do cardápio">
                <button class="menu-sidebar-link active" data-category-filter="all" onclick="filterCategory('all', this)">
                    <i class="fas fa-border-all"></i><span>Tudo</span>
                </button>
                <?php foreach ($lista_filtros_categoria as $cat_nome): ?>
                    <?php
                    $isSubChip = strpos($cat_nome, ' › ') !== false;
                    $catIcon = cardapio_categoria_icone($cat_nome);
                    ?>
                    <button class="menu-sidebar-link<?php echo $isSubChip ? ' is-sub' : ''; ?>" data-category-filter="<?php echo htmlspecialchars($cat_nome, ENT_QUOTES, 'UTF-8'); ?>" onclick="filterCategory('<?php echo htmlspecialchars(addslashes($cat_nome)); ?>', this)">
                        <i class="fas <?php echo htmlspecialchars($catIcon); ?>"></i><span><?php echo htmlspecialchars($cat_nome); ?></span>
                    </button>
                <?php endforeach; ?>
            </nav>

            <div class="menu-sidebar-actions">
                <button class="sidebar-cart-btn" id="sidebarCartBtn" onclick="openCart()">
                    <span><i class="fas fa-shopping-bag"></i> Ver pedido</span>
                    <strong id="sidebarCartTotal">0,00 MZN</strong>
                </button>
            </div>
        </aside>

        <main class="menu-content">
            <!-- HERO HEADER -->
            <div class="hero">
                <div>
                    <div class="hero-mobile-brand" aria-hidden="true">
                        <?php if ($logo_url): ?>
                            <img src="<?php echo htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8'); ?>" alt="" width="48" height="48" decoding="async">
                        <?php else: ?>
                            <span><?php echo htmlspecialchars(substr(strtoupper($restaurante['nome']), 0, 2), ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                        <small>Cardápio de <?php echo htmlspecialchars($restaurante['nome'], ENT_QUOTES, 'UTF-8'); ?></small>
                    </div>
                    <div class="hero-badge <?php echo htmlspecialchars($heroBadgeClass); ?>">
                        <span><i class="fas <?php echo $pedido_qr_habilitado ? 'fa-check-circle' : 'fa-eye'; ?>"></i></span>
                        <span><?php echo htmlspecialchars($heroBadgeText); ?></span>
                    </div>
                    <h1><?php echo htmlspecialchars($restaurante['nome']); ?></h1>
                    <p class="hero-subtitle">
                        <?php if ($pedido_qr_habilitado && $mesa): ?>
                            Escolha os pratos e envie o pedido direto da mesa <?php echo htmlspecialchars($mesa['numero']); ?>.
                        <?php elseif ($pedido_qr_habilitado && $modo_takeaway): ?>
                            Monte o pedido no telemóvel — takeaway / balcão sem precisar do QR da mesa.
                        <?php elseif ($isCardapioEmpresarial): ?>
                            Escolha com contexto operacional: destaques, procura recente e pedido directo da mesa.
                        <?php elseif ($temCardapioDigital && !$temPedidosOnline): ?>
                            Explore o menu. Pedidos online disponíveis a partir do plano Profissional.
                        <?php else: ?>
                            Explore o menu. Para pedir na mesa, abra o cardápio pelo QR Code da mesa.
                        <?php endif; ?>
                    </p>
                </div>
                <div class="hero-brand-mark">
                    <?php if ($logo_url): ?>
                        <img src="<?php echo htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo <?php echo htmlspecialchars($restaurante['nome']); ?>">
                    <?php else: ?>
                        <span><?php echo substr(strtoupper($restaurante['nome']), 0, 2); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($isCardapioEmpresarial): ?>
            <section class="menu-enterprise-strip" aria-label="Resumo do atendimento">
                <div class="menu-enterprise-item"><span><i class="fas fa-utensils"></i></span><div><small>ATENDIMENTO</small><strong><?= $mesa ? 'Mesa ' . htmlspecialchars((string)$mesa['numero'], ENT_QUOTES, 'UTF-8') : ($modo_takeaway ? 'Pedido para levar' : 'Cardapio digital') ?></strong></div></div>
                <div class="menu-enterprise-item"><span class="<?= $pedido_qr_habilitado ? 'is-ready' : '' ?>"><i class="fas <?= $pedido_qr_habilitado ? 'fa-circle-check' : 'fa-eye' ?>"></i></span><div><small>PEDIDO</small><strong><?= $pedido_qr_habilitado ? 'Pode pedir agora' : 'Somente consulta' ?></strong></div></div>
                <div class="menu-enterprise-item"><span><i class="fas fa-tags"></i></span><div><small>OFERTAS</small><strong><?= count($promocoes) ?> <?= count($promocoes) === 1 ? 'combo ativo' : 'combos ativos' ?></strong></div></div>
                <button class="menu-enterprise-cart" type="button" onclick="openCart()"><i class="fas fa-bag-shopping"></i><span>Ver pedido</span><b id="enterpriseCartCount">0</b></button>
            </section>
            <?php if (!empty($empIntel)): ?>
            <section class="menu-emp-intel" aria-label="Sugestão operacional do cardápio">
                <div class="menu-emp-intel-copy">
                    <span class="menu-emp-intel-kicker"><i class="fas fa-chart-line"></i> Nivora Intelligence · <?php echo htmlspecialchars($empIntel['momento']); ?></span>
                    <strong><?php
                        echo $empIntel['sugestao'] !== ''
                            ? 'Sugestão: ' . htmlspecialchars($empIntel['sugestao'])
                            : 'Cardápio pronto para o serviço';
                    ?></strong>
                    <p><?php
                        if ($empIntel['pedidos'] > 0) {
                            echo (int)$empIntel['pedidos'] . ' pedidos nos últimos 30 dias';
                            if ($empIntel['categoria'] !== '') {
                                echo ' · ' . htmlspecialchars($empIntel['categoria']);
                            }
                            echo '. Leitura da procura actual.';
                        } else {
                            echo (int)$empIntel['itens'] . ' itens no cardápio';
                            if ($empIntel['ofertas'] > 0) {
                                echo ' · ' . (int)$empIntel['ofertas'] . ' ofertas activas';
                            }
                            echo '.';
                        }
                    ?></p>
                </div>
                <?php if (!empty($empIntel['categorias'])): ?>
                <div class="menu-emp-intel-chips">
                    <?php foreach ($empIntel['categorias'] as $catChip): ?>
                        <button type="button" class="menu-emp-chip" data-category-filter="<?php echo htmlspecialchars($catChip, ENT_QUOTES, 'UTF-8'); ?>" onclick="filterCategory('<?php echo htmlspecialchars(addslashes($catChip)); ?>', this)"><?php echo htmlspecialchars($catChip); ?></button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>
            <?php endif; ?>

            <?php if (!empty($produtos_destaque)): ?>
                <section class="featured-dish" aria-label="Destaque da casa">
                    <div class="featured-track" id="featuredTrack">
                        <?php foreach ($produtos_destaque as $i => $produto_destaque): ?>
                            <?php
                            $destaque_nome_attr = htmlspecialchars($produto_destaque['nome'] ?? '', ENT_QUOTES, 'UTF-8');
                            $destaque_nome_js = htmlspecialchars(addslashes($produto_destaque['nome'] ?? ''), ENT_QUOTES, 'UTF-8');
                            $destaque_imagem_js = htmlspecialchars(addslashes($produto_destaque['imagem'] ?? ''), ENT_QUOTES, 'UTF-8');
                            $destaque_desc_js = htmlspecialchars(addslashes($produto_destaque['descricao'] ?? ''), ENT_QUOTES, 'UTF-8');
                            $destaque_preco = (float)($produto_destaque['preco'] ?? 0);
                            $destaque_desc = trim((string)($produto_destaque['descricao'] ?? ''));
                            $destaque_tem_imagem = !empty($produto_destaque['imagem']);
                            $destaquePedidosRecentes = (int)($quantidadeMaisVendidos[(int)($produto_destaque['id'] ?? 0)] ?? 0);
                            $destaque_imagem_src = $destaque_tem_imagem
                                ? htmlspecialchars($base_url . '/' . $produto_destaque['imagem'], ENT_QUOTES, 'UTF-8')
                                : '';
                            $destaque_mobile_src = $imagemMobileUrl($produto_destaque['imagem'] ?? '');
                            ?>
                            <div class="featured-slide">
                            <div class="featured-card<?= $isCardapioEmpresarial ? ' featured-card-enterprise' : '' ?>">
                                <div class="featured-info">
                                        <div class="featured-kicker"><?php echo $isCardapioEmpresarial ? 'Destaque operacional' : ($destaquesDeVendas ? 'Mais pedidos' : 'Destaque da casa'); ?></div>
                                        <?php if ($isCardapioEmpresarial): ?>
                                        <div class="featured-enterprise-context"><span><i class="fas fa-circle-check"></i> Disponivel agora</span><span><i class="fas fa-chart-line"></i> <?= $destaquePedidosRecentes > 0 ? $destaquePedidosRecentes . ' pedidos recentes' : 'Selecao da casa' ?></span></div>
                                        <?php endif; ?>
                                        <h2 class="featured-title"><?php echo $destaque_nome_attr; ?></h2>
                                        <?php if ($destaque_desc !== ''): ?>
                                            <p class="featured-desc"><?php echo htmlspecialchars($destaque_desc, ENT_QUOTES, 'UTF-8'); ?></p>
                                        <?php else: ?>
                                            <p class="featured-desc">Uma sugestão especial do restaurante.</p>
                                        <?php endif; ?>
                                        <div class="featured-row">
                                            <span class="featured-price"><?php echo number_format($destaque_preco, 2, ',', '.'); ?>&nbsp;MZN</span>
                                            <button class="featured-add" type="button" onclick="openProductDetail(<?php echo (int)$produto_destaque['id']; ?>, '<?php echo $destaque_nome_js; ?>', <?php echo $destaque_preco; ?>, '<?php echo $destaque_imagem_js; ?>', '<?php echo $destaque_desc_js; ?>')">
                                                <?php echo $isCardapioEmpresarial ? 'Escolher item' : 'Ver detalhes'; ?> <i class="fas fa-arrow-right"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="featured-photo<?php echo $destaque_tem_imagem ? '' : ' no-photo'; ?>">
                                        <?php if ($destaque_tem_imagem): ?>
                                            <picture>
                                                <?php if ($destaque_mobile_src !== ''): ?><source media="(max-width: 700px)" type="image/webp" srcset="<?php echo htmlspecialchars($destaque_mobile_src, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                                                <img src="<?php echo $destaque_imagem_src; ?>" alt="<?php echo $destaque_nome_attr; ?>" width="900" height="560" loading="<?php echo $i === 0 ? 'eager' : 'lazy'; ?>" decoding="async"<?php echo $i === 0 ? ' fetchpriority="high"' : ''; ?>>
                                            </picture>
                                        <?php else: ?>
                                            <div class="photo-fallback" aria-hidden="true">
                                                <i class="fas fa-utensils"></i>
                                                <span>Sem foto</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="featured-dots" id="featuredDots" aria-label="Escolher prato em destaque">
                        <?php foreach ($produtos_destaque as $i => $_): ?>
                            <span class="<?php echo $i === 0 ? 'active' : ''; ?>"></span>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

    <!-- SEARCH + CATEGORIES (sticky) -->
    <div class="menu-sticky-tools">
    <div class="search-container">
        <div class="search-box">
            <input type="search" id="searchInput" aria-label="Pesquisar pratos e categorias" placeholder="<?php echo $isCardapioEmpresarial ? 'Procurar no cardápio desta mesa' : 'Pesquisar produtos…'; ?>" oninput="filterProducts()" autocomplete="off">
            <button class="search-btn" type="button" aria-label="Pesquisar pratos" onclick="document.getElementById('searchInput').focus()"><i class="fas fa-search"></i></button>
        </div>
    </div>

    <!-- CATEGORIES -->
    <?php if (!empty($por_categoria)): ?>
        <div class="categories" id="categories">
            <button class="cat-pill active" data-category-filter="all" onclick="filterCategory('all', this)"><i class="fas fa-border-all"></i><span>Tudo</span></button>
            <?php foreach ($lista_filtros_categoria as $cat_nome): ?>
                <?php
                $isSubChip = strpos($cat_nome, ' › ') !== false;
                $catIcon = cardapio_categoria_icone($cat_nome);
                ?>
                <button class="cat-pill<?php echo $isSubChip ? ' is-sub' : ''; ?>" data-category-filter="<?php echo htmlspecialchars($cat_nome, ENT_QUOTES, 'UTF-8'); ?>" onclick="filterCategory('<?php echo htmlspecialchars(addslashes($cat_nome)); ?>', this)">
                    <i class="fas <?php echo htmlspecialchars($catIcon); ?>"></i>
                    <span><?php echo htmlspecialchars($cat_nome); ?></span>
                </button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    </div>

    <!-- PRODUCTS -->
    <div class="menu-search-empty" id="menuSearchEmpty" role="status" hidden>
        <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
        <strong>Nenhum prato encontrado</strong>
        <p>Tente outro nome ou veja todas as categorias.</p>
        <button type="button" onclick="document.getElementById('searchInput').value = ''; filterProducts(); document.getElementById('searchInput').focus();">Limpar pesquisa</button>
    </div>
    <div id="productsContainer">
        <?php if (!empty($promocoes)): ?>
            <div class="category-section" data-category="Promocoes">
                <div class="category-header"><div class="category-emoji"><i class="fas fa-tags"></i></div><h2 class="category-title">Promocoes</h2><span class="category-count"><?= count($promocoes) ?> combos</span></div>
                <div class="section-ornament"><span><i class="fas fa-star"></i></span></div>
                <div class="products-grid promo-products-grid">
                <?php foreach ($promocoes as $promo):
                    $promoNome = (string)$promo['nome'];
                    $promoResumo = implode(' + ', array_map(static fn($i) => (int)$i['quantidade'] . 'x ' . $i['nome'], $promo['itens']));
                    $promoImagem = trim((string)($promo['imagem'] ?? ''));
                    $promoImagemSrc = $promoImagem !== '' ? $base_url . '/' . ltrim(str_replace('src/public/', '', $promoImagem), '/') : '';
                    $promoPrecoRegular = array_sum(array_map(static fn($i) => (float)($i['preco'] ?? 0) * (int)($i['quantidade'] ?? 1), $promo['itens'] ?? []));
                    $promoPrecoFinal = (float)$promo['preco_promocional'];
                    $promoDesconto = $promoPrecoRegular > $promoPrecoFinal
                        ? (($promoPrecoRegular - $promoPrecoFinal) / $promoPrecoRegular) * 100
                        : 0;
                    $promoItensComImagem = [];
                    foreach (array_slice($promo['itens'] ?? [], 0, 2) as $promoItemImagem) {
                        $imagemItem = trim((string)($promoItemImagem['imagem'] ?? ''));
                        $imagemSrc = $imagemItem === ''
                            ? ''
                            : (preg_match('/^https?:\/\//i', $imagemItem)
                                ? $imagemItem
                                : $base_url . '/' . ltrim(str_replace(['\\', 'src/public/'], ['/', ''], $imagemItem), '/'));
                        $promoItensComImagem[] = [
                            'src' => $imagemSrc,
                            'nome' => (string)($promoItemImagem['nome'] ?? 'Produto do combo'),
                        ];
                    }
                ?>
                    <div class="product-card" data-id="promo_<?= (int)$promo['id'] ?>" data-name="<?= htmlspecialchars(strtolower($promoNome), ENT_QUOTES, 'UTF-8') ?>">
                        <div class="product-image promo-combo-image<?= count($promoItensComImagem) < 2 ? ' is-single' : '' ?>">
                            <span class="promo-combo-chip"><i class="fas fa-tags"></i> Combo</span>
                            <div class="promo-combo-media">
                                <?php foreach ($promoItensComImagem as $promoItemImagem): ?>
                                <div class="promo-combo-photo"><?php if ($promoItemImagem['src'] !== ''): ?><img src="<?= htmlspecialchars($promoItemImagem['src'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($promoItemImagem['nome'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy"><?php else: ?><i class="fas fa-utensils" aria-hidden="true"></i><?php endif; ?></div>
                                <?php endforeach; ?>
                                <?php if (!$promoItensComImagem): ?><div class="promo-combo-photo"><i class="fas fa-tags" aria-hidden="true"></i></div><?php endif; ?>
                            </div>
                            <?php if (count($promoItensComImagem) >= 2): ?><span class="promo-combo-plus" aria-hidden="true">+</span><?php endif; ?>
                            <?php if (count($promo['itens'] ?? []) > 2): ?><span class="promo-combo-more">+<?= (int)count($promo['itens']) - 2 ?></span><?php endif; ?>
                        </div>
                        <div class="product-details"><div class="promo-offer-status"><span><i class="fas fa-circle-check"></i> Oferta ativa</span><small><?= count($promo['itens'] ?? []) ?> item(ns)</small></div><h3 class="product-name"><?= htmlspecialchars($promoNome, ENT_QUOTES, 'UTF-8') ?></h3><p class="product-desc"><?= htmlspecialchars($promoResumo, ENT_QUOTES, 'UTF-8') ?></p><?php if ($promoDesconto > 0): ?><p class="promo-savings"><i class="fas fa-piggy-bank"></i> Poupe <?= number_format($promoPrecoRegular - $promoPrecoFinal, 2, ',', '.') ?> MZN neste combo</p><?php endif; ?><div class="product-footer"><span class="promo-price-stack"><?php if ($promoDesconto > 0): ?><del><?= number_format($promoPrecoRegular,2,',','.') ?>&nbsp;MZN</del><em>-<?= number_format($promoDesconto, 0, ',', '.') ?>%</em><?php endif; ?><strong class="product-price"><?= number_format($promoPrecoFinal,2,',','.') ?>&nbsp;MZN</strong></span><button class="product-add promo-add-combo" type="button" onclick='addPromotionToCart(<?= (int)$promo['id'] ?>, <?= json_encode($promoNome, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_TAG) ?>, <?= $promoPrecoFinal ?>, <?= json_encode($promoImagem, JSON_UNESCAPED_SLASHES | JSON_HEX_APOS) ?>)' aria-label="Adicionar combo"></button></div></div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if (empty($todos_produtos) && empty($promocoes)): ?>
            <div class="empty-state">
                <div class="icon"><i class="fas fa-concierge-bell"></i></div>
                <h3>Cardápio em preparação</h3>
                <p>Os itens do menu serão exibidos aqui assim que estiverem disponíveis.</p>
            </div>
        <?php else: ?>
            <?php foreach ($por_categoria as $cat_nome => $produtos): ?>
                <?php
                $parentAttr = (string)($categoria_parents[$cat_nome] ?? '');
                $catIcon = cardapio_categoria_icone($cat_nome);
                ?>
                <div class="category-section" data-category="<?php echo htmlspecialchars($cat_nome); ?>"<?php echo $parentAttr !== '' ? ' data-parent="' . htmlspecialchars($parentAttr, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>>
                    <div class="category-header">
                        <div class="category-emoji"><i class="fas <?php echo htmlspecialchars($catIcon); ?>"></i></div>
                        <h2 class="category-title"><?php echo htmlspecialchars($cat_nome); ?></h2>
                        <span class="category-count"><?php echo count($produtos); ?> itens</span>
                    </div>
                    <div class="section-ornament"><span><i class="fas fa-star"></i></span></div>
                    <div class="products-grid">
                        <?php foreach ($produtos as $p): ?>
                            <?php
                            $produto_nome_attr = htmlspecialchars($p['nome'] ?? '', ENT_QUOTES, 'UTF-8');
                            $produto_nome_js = htmlspecialchars(addslashes($p['nome'] ?? ''), ENT_QUOTES, 'UTF-8');
                            $produto_imagem_js = htmlspecialchars(addslashes($p['imagem'] ?? ''), ENT_QUOTES, 'UTF-8');
                            $produto_desc_js = htmlspecialchars(addslashes($p['descricao'] ?? ''), ENT_QUOTES, 'UTF-8');
                            $produto_imagem_src = htmlspecialchars($base_url . '/' . ($p['imagem'] ?? ''), ENT_QUOTES, 'UTF-8');
                            $produto_mobile_src = $imagemMobileUrl($p['imagem'] ?? '');
                            $tem_imagem = !empty($p['imagem']);
                            $esgotado = !$temEstoqueAvancado && array_key_exists('estoque', $p) && $p['estoque'] !== null && (int)$p['estoque'] <= 0;
                            $itemIcon = cardapio_categoria_icone((string)($p['categoria_nome'] ?? $cat_nome));
                            $empPopular = $isCardapioEmpresarial && in_array((int)$p['id'], $idsMaisVendidos, true);
                            ?>
                            <div class="product-card<?php echo $esgotado ? ' sold-out' : ''; ?><?php echo $empPopular ? ' is-emp-popular' : ''; ?>"
                                data-id="<?php echo (int)$p['id']; ?>"
                                data-name="<?php echo htmlspecialchars(strtolower($p['nome'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                data-price="<?php echo htmlspecialchars((string)$p['preco'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-image="<?php echo $produto_imagem_js; ?>"
                                data-desc="<?php echo htmlspecialchars($p['descricao'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                data-esgotado="<?php echo $esgotado ? '1' : '0'; ?>"
                                <?php if (!$esgotado): ?>tabindex="0" role="button" aria-label="Ver detalhes de <?php echo $produto_nome_attr; ?>"<?php endif; ?>
                                onclick="<?php echo $esgotado ? 'return false;' : "openProductDetail(" . (int)$p['id'] . ", '" . $produto_nome_js . "', " . (float)$p['preco'] . ", '" . $produto_imagem_js . "', '" . $produto_desc_js . "')"; ?>">
                                <div class="product-image<?php echo $tem_imagem ? '' : ' no-photo'; ?>">
                                    <?php if ($tem_imagem): ?>
                                        <picture>
                                            <?php if ($produto_mobile_src !== ''): ?><source media="(max-width: 700px)" type="image/webp" srcset="<?php echo htmlspecialchars($produto_mobile_src, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                                            <img src="<?php echo $produto_imagem_src; ?>" alt="<?php echo $produto_nome_attr; ?>" width="640" height="400" loading="lazy" decoding="async">
                                        </picture>
                                    <?php else: ?>
                                        <div class="photo-fallback" aria-hidden="true">
                                            <i class="fas <?php echo htmlspecialchars($itemIcon); ?>"></i>
                                            <span>Sem foto</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="product-details">
                                    <?php if (!empty($empPopular)): ?>
                                        <span class="emp-popular-badge">Mais pedido</span>
                                    <?php endif; ?>
                                    <h3 class="product-name"><?php echo $produto_nome_attr; ?></h3>
                                    <?php if (!empty($p['descricao'])): ?>
                                        <p class="product-desc"><?php echo htmlspecialchars($p['descricao']); ?></p>
                                    <?php endif; ?>
                                    <?php if ($esgotado): ?>
                                        <span class="badge-esgotado">Esgotado</span>
                                    <?php endif; ?>
                                    <div class="product-footer">
                                        <span class="product-price"><?php echo number_format((float)$p['preco'], 2, ',', '.'); ?>&nbsp;MZN</span>
                                        <?php if (!$esgotado): ?>
                                        <button class="product-add" type="button"
                                            onclick="event.stopPropagation(); addToCart(<?php echo (int)$p['id']; ?>, '<?php echo $produto_nome_js; ?>', <?php echo (float)$p['preco']; ?>, '<?php echo $produto_imagem_js; ?>')"
                                            aria-label="Adicionar <?php echo $produto_nome_attr; ?>"></button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- FOOTER -->
    <div class="footer">
        <span class="footer-brand"><?php echo htmlspecialchars($restaurante['nome']); ?></span> &bull; cardápio digital
    </div>
        </main>
    </div>

    <!-- FLOATING CART -->
    <button class="cart-float" id="cartFloat" onclick="openCart()" aria-label="Abrir pedido" aria-controls="cartModal" aria-expanded="false">
        <div class="cart-left">
            <span class="cart-icon"><i class="fas fa-shopping-bag"></i></span>
            <div class="cart-info">
                <span class="cart-label">Seu pedido</span>
            <span class="cart-meta" aria-live="polite"><span id="cartCountDisplay">0</span> <span id="cartItemsLabel">itens</span> &bull; <span id="cartTotalDisplay">0,00 MZN</span></span>
            </div>
        </div>
        <span class="cart-chevron"><i class="fas fa-chevron-right"></i></span>
    </button>

    <!-- CART MODAL -->
    <div class="modal-overlay" id="modalOverlay" onclick="closeCart()"></div>
    <div class="cart-modal" id="cartModal">
        <div id="cartScreen">
            <div class="modal-header">
                <h2><i class="fas fa-receipt"></i> Seu pedido</h2>
                <button class="modal-close" onclick="closeCart()">&times;</button>
            </div>
            <div class="cart-items" id="cartItems"></div>
            <div class="cart-summary">
                <div class="cart-total-row">
                    <span class="cart-total-label">Total</span>
                    <span class="cart-total-value" id="cartTotalValue">0,00 MZN</span>
                </div>
                <div class="order-form">
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Nome <?php echo $modo_takeaway ? '(recomendado)' : '(opcional)'; ?></label>
                        <input type="text" id="clientName" placeholder="Nome completo" <?php echo $modo_takeaway ? 'required' : ''; ?>>
                    </div>
                    <?php if ($pedido_qr_habilitado): ?>
                    <div class="form-group">
                        <label><i class="fas fa-phone"></i> Telefone <?php echo $temNotificacoes ? '(WhatsApp)' : '(opcional)'; ?></label>
                        <input type="tel" id="clientPhone" placeholder="Telefone" inputmode="tel">
                    </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label><i class="fas fa-note-sticky"></i> Observações gerais</label>
                        <textarea id="orderNote" placeholder="Observações do pedido"></textarea>
                    </div>
                    <?php if ($pedido_qr_habilitado): ?>
                    <div class="order-notice" style="display:none;"></div>
                    <?php else: ?>
                    <div class="order-notice">
                        <i class="fas fa-qrcode"></i>
                        Pedido online disponível no plano Profissional (ou pelo QR Code da mesa).
                    </div>
                    <?php endif; ?>
                    <button class="btn-order" id="btnOrder" onclick="submitOrder()" <?php echo $pedido_qr_habilitado ? '' : 'disabled'; ?>>
                        <i class="fas fa-check"></i> Confirmar pedido
                    </button>
                </div>
            </div>
        </div>

        <div class="success-screen" id="successScreen">
            <div class="success-icon"><i class="fas fa-circle-check"></i></div>
            <h2>Pedido enviado</h2>
            <p>Seu pedido foi recebido com sucesso.</p>
            <div class="success-number" id="successNumber">#000</div>
            <div class="track-status" id="trackStatus" aria-live="polite">
                <div class="track-status-label">Acompanhe o estado</div>
                <div class="track-step current" data-step="NOVO"><span class="track-dot"></span> Recebido</div>
                <div class="track-step" data-step="PREPARANDO"><span class="track-dot"></span> Em preparo</div>
                <div class="track-step" data-step="PRONTO"><span class="track-dot"></span> Pronto</div>
                <div class="track-step" data-step="ENTREGUE"><span class="track-dot"></span> Entregue</div>
            </div>
            <p id="trackHint" style="margin-top:14px;">Actualizamos automaticamente a cada poucos segundos.</p>
            <div id="trackWhatsApp" class="track-whatsapp" hidden></div>
            <button class="btn-new" onclick="newOrder()"><i class="fas fa-plus"></i> Novo pedido</button>
        </div>
    </div>

    <!-- PRODUCT DETAIL VIEWER -->
    <div class="image-viewer" id="imageViewer" onclick="if(event.target===this)closeImageViewer()">
        <div class="image-viewer-sheet" role="dialog" aria-modal="true" aria-labelledby="viewerName">
            <button class="image-viewer-close" type="button" onclick="closeImageViewer()" aria-label="Fechar">&times;</button>
            <div class="image-viewer-photo" id="viewerPhotoWrap">
        <img id="viewerImage" src="" alt="Produto">
            </div>
        <div class="image-viewer-info">
            <div class="image-viewer-name" id="viewerName"></div>
            <div class="image-viewer-price" id="viewerPrice"></div>
            <p class="image-viewer-desc" id="viewerDesc"></p>
                <div class="viewer-note-group">
                    <label for="viewerNote"><i class="fas fa-note-sticky"></i> Obs. deste item</label>
                    <input type="text" id="viewerNote" maxlength="120" placeholder="Observações do pedido" autocomplete="off">
                </div>
                <div class="image-viewer-actions">
            <div class="viewer-qty">
                <button type="button" onclick="changeViewerQty(-1)" aria-label="Diminuir quantidade">−</button>
                <span id="viewerQty">1</span>
                <button type="button" onclick="changeViewerQty(1)" aria-label="Aumentar quantidade">+</button>
            </div>
                    <button class="image-viewer-add" id="viewerAddBtn" type="button" onclick="addFromViewer()">
                        <i class="fas fa-plus"></i> Adicionar ao pedido
            </button>
                </div>
            </div>
        </div>
    </div>

    <script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
        window.CARDAPIO_CONFIG = <?php echo json_encode([
            'rid' => (int)$rid,
            'mesaId' => (int)$mesa_id,
            'takeawayMode' => !empty($modo_takeaway),
            'baseUrl' => rtrim($base_url, '/') . '/',
            'qrOrderToken' => (string)$pedido_qr_token,
            'qrOrderEnabled' => !empty($pedido_qr_habilitado),
            'temNotificacoes' => !empty($temNotificacoes),
            'brandPrimary' => (string)($brandPrimary ?? '#FF6B35'),
            'brandAccent' => (string)($brandAccent ?? '#F7931E'),
            'restauranteNome' => (string)($restaurante['nome'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    </script>
    <script src="js/feedback.js?v=<?= (int)filemtime(__DIR__ . '/js/feedback.js') ?>"></script>
    <script src="js/nivora_dialogo.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nivora_dialogo.js'); ?>"></script>
    <script src="js/cardapio.js?v=<?= (int)filemtime(__DIR__ . '/js/cardapio.js') ?>"></script>
</body>

</html>
