<?php
require_once __DIR__ . '/app_base.php';

require_once APP_BASE . '/src/config/auth_check.php';
require_once APP_BASE . '/src/config/csrf.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/restaurante_context.php';
require_once APP_BASE . '/src/Service/TurnoService.php';
require_once APP_BASE . '/src/config/turno_helpers.php';

requirePermissionOrRedirect(['ADMIN', 'GERENTE', 'BAR'], 'novo_pedido.php');

$database = new Database();
$db = $database->getConnection();

// Plano Básico: preparo no PDV (sem tela Bar)
$restauranteId = function_exists('session_restaurante_auth_id') ? session_restaurante_auth_id() : (int)($_SESSION['restaurante_id'] ?? 0);
$planRidBar = function_exists('session_restaurante_capability_id') ? (int)session_restaurante_capability_id() : $restauranteId;
$planRidBar = $planRidBar > 0 ? $planRidBar : $restauranteId;
require_once APP_BASE . '/src/config/plano_check.php';
if ($planRidBar > 0 && !plano_tem_funcionalidade_db($planRidBar, 'telas_bar_cozinha')) {
    header('Location: novo_pedido.php?aviso=fluxo_basico_pdv');
    exit;
}

// Verifica se o usuário do bar está com turno ativo
$perfil = $_SESSION['perfil'] ?? '';
$usuarioId = $_SESSION['usuario_id'] ?? 0;
$csrfToken = csrf_get_token();
if (turno_usuario_exige_turno_ativo($perfil)) {
    $turnoService = new TurnoService($database);
    $turnoAtivo = $turnoService->obterTurnoAtivoUsuario((int)$usuarioId, (int)$restauranteId);
    if (!$turnoAtivo) {
        header('Location: iniciar_turno.php?erro=turno_obrigatorio');
        exit;
    }
}

// Segundo canal de balcão (Empresarial), quando activado em Mesas.
require_once APP_BASE . '/src/config/mesa_display_helper.php';
require_once APP_BASE . '/src/config/balcao_canal.php';
$kdsBalcao2 = ['ativo' => false, 'nome' => '', 'mesa_id' => 0, 'numero' => ''];
try {
    $kdsBalcao2 = balcao2_config($db, (int)$restauranteId, $planRidBar);
} catch (Throwable $e) {
    error_log('[kds][balcao2] ' . $e->getMessage());
}
$kdsCanaisAtivo = !empty($kdsBalcao2['ativo']);

/**
 * Rótulo e canal de um item: "Mesa 5", "Balcão" ou o nome do segundo canal.
 * Devolve [texto, chave do canal] — a chave serve para o filtro e para o CSS.
 */
function kds_origem_item(array $item, array $balcao2): array
{
    $numero = trim((string)($item['mesa_numero'] ?? ''));
    if ($numero === '') {
        return ['Balcão', 'balcao'];
    }
    if (!mesa_eh_balcao($numero)) {
        return ['Mesa ' . $numero, 'mesa'];
    }
    if (!empty($balcao2['ativo']) && $numero === (string)$balcao2['numero']) {
        return [(string)$balcao2['nome'], 'balcao2'];
    }
    return ['Balcão', 'balcao'];
}

// Busca itens destinados ao bar, status em_preparo ou pronto
$stmt = $db->prepare("
    SELECT i.id, i.pedido_id, i.produto_id,
           COALESCE(i.status, 'em_preparo') AS status,
           CASE 
               WHEN COALESCE(i.status, 'em_preparo') = 'em_preparo' THEN COALESCE(i.iniciado_preparo_em, ped.criado_em)
               WHEN i.status = 'pronto' THEN i.pronto_em
               ELSE NULL
           END AS data_status,
           COALESCE(NULLIF(i.produto_nome, ''), p.nome) AS produto_nome,
           p.imagem AS produto_imagem,
           i.quantidade,
           i.observacao AS item_observacao,
           ped.observacao AS pedido_observacao,
           ped.numero_pedido,
           u.nome AS garcom_nome,
           m.numero AS mesa_numero
    FROM itens_pedido i
    JOIN produtos p ON i.produto_id = p.id
    JOIN pedidos ped ON i.pedido_id = ped.id
    LEFT JOIN mesas m ON ped.mesa_id = m.id
    LEFT JOIN usuarios u ON u.id = ped.garcom_id
    WHERE ped.restaurante_id = :rid
      AND i.destino = 'bar'
      AND ped.status IN ('NOVO', 'PENDENTE', 'CONFIRMADO', 'PREPARANDO', 'PRONTO', 'PAGO', 'ENTREGUE')
      -- PAGO/ENTREGUE (ex.: take-away pago ao enviar) so enquanto e recente: itens nunca marcados
      -- como prontos em pedidos ja pagos nao ficam para sempre no topo do ecra.
      AND (ped.status NOT IN ('PAGO', 'ENTREGUE') OR ped.criado_em >= DATE_SUB(NOW(), INTERVAL 12 HOUR))
      AND COALESCE(i.status, 'em_preparo') IN ('em_preparo', 'pronto')
    ORDER BY FIELD(COALESCE(i.status, 'em_preparo'), 'em_preparo', 'pronto'), data_status ASC
");
$stmt->bindValue(':rid', (int)$restauranteId, PDO::PARAM_INT);
$stmt->execute();
$itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

$grupos = [
    'em_preparo' => [],
    'pronto' => [],
];
foreach ($itens as $item) {
    $status = strtolower((string)($item['status'] ?? 'em_preparo'));
    if (!isset($grupos[$status])) {
        $status = 'em_preparo';
    }
    $grupos[$status][] = $item;
}

require_once __DIR__ . '/includes/kds_plan.php';

$kdsOldestPrep = null;
foreach ($grupos['em_preparo'] as $prepItem) {
    $ts = strtotime((string)($prepItem['data_status'] ?? ''));
    if ($ts) {
        $mins = (int)floor((time() - $ts) / 60);
        if ($kdsOldestPrep === null || $mins > $kdsOldestPrep) {
            $kdsOldestPrep = $mins;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt">

<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $kdsLayout === 'intelligent-command' ? 'Comando Inteligente do Bar' : ($kdsLayout === 'operational-kds' ? 'KDS Operacional do Bar' : 'Bar - Itens do Dia'); ?></title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="lib/fontawesome-6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/kds-plan.css">
    <link rel="stylesheet" href="css/feedback.css?v=<?php echo (int)filemtime(__DIR__ . '/css/feedback.css'); ?>">
    <script src="js/feedback.js?v=<?php echo (int)filemtime(__DIR__ . '/js/feedback.js'); ?>"></script>
    <style>
        :root {
            --kds-bg: #0f1115;
            --kds-panel: #171a21;
            --kds-panel-2: #1c202a;
            --kds-border: #2a303b;
            --kds-text: #e5e7eb;
            --kds-muted: #9aa3af;
            --kds-blue: #3b82f6;
            --kds-amber: #f59e0b;
            --kds-green: #10b981;
            --kds-danger: #ef4444;
        }

        body {
            background: var(--kds-bg);
            color: var(--kds-text);
            font-family: 'Poppins', 'Segoe UI', sans-serif;
        }

        .kds-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 24px;
            border-bottom: 1px solid var(--kds-border);
            background: #0b0d12;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .kds-title {
            font-size: 20px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .kds-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            color: var(--kds-muted);
        }

        .kds-actions a {
            text-decoration: none;
            color: var(--kds-text);
            border: 1px solid var(--kds-border);
            padding: 6px 12px;
            border-radius: 8px;
            background: #0f131b;
        }

        .kds-actions a.btn-primary {
            background: #fbbf24;
            border-color: #fbbf24;
            color: #111827;
            font-weight: 700;
        }

        .board {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
            padding: 18px;
        }

        .column {
            background: var(--kds-panel);
            border: 1px solid var(--kds-border);
            border-radius: 14px;
            overflow: hidden;
            min-height: 200px;
        }

        .column-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            font-size: 12px;
        }

        .column-header .count {
            background: rgba(255, 255, 255, 0.12);
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 12px;
        }

        .column-body {
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .column-novo .column-header {
            background: rgba(59, 130, 246, 0.2);
            color: #bfdbfe;
        }

        .column-preparo .column-header {
            background: rgba(245, 158, 11, 0.2);
            color: #fde68a;
        }

        .column-pronto .column-header {
            background: rgba(16, 185, 129, 0.2);
            color: #bbf7d0;
        }

        .card-item {
            background: var(--kds-panel-2);
            border: 1px solid var(--kds-border);
            border-radius: 14px;
            padding: 14px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.25);
        }

        .card-item.antigo {
            border-color: var(--kds-danger);
        }

        .card-item.critico {
            border-color: var(--kds-danger);
            box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.35), 0 10px 24px rgba(239, 68, 68, 0.25);
        }

        .card-item.critico .tempo {
            color: #fecaca;
        }

        .card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-weight: 700;
        }

        .card-sub {
            font-size: 12px;
            color: var(--kds-muted);
        }

        .badge-qtd {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #111827;
            border: 1px solid var(--kds-border);
            border-radius: 999px;
            padding: 3px 8px;
            font-size: 12px;
            color: var(--kds-text);
        }

        .tempo {
            font-size: 12px;
            color: #f87171;
            font-weight: 600;
        }

        .btn-status {
            border: none;
            border-radius: 10px;
            padding: 10px 14px;
            font-weight: 700;
            cursor: pointer;
            font-size: 13px;
        }

        .btn-start {
            background: #f59e0b;
            color: #111827;
        }

        .btn-ready {
            background: #10b981;
            color: #0b1220;
        }

        .btn-delivered {
            background: #3b82f6;
            color: #0b1220;
        }

        .btn-status[disabled] {
            background: #374151;
            color: #9ca3af;
            cursor: not-allowed;
        }

        .status-pronto {
            color: var(--kds-green);
            font-weight: 700;
        }

        .empty-state {
            padding: 22px 14px;
            text-align: center;
            color: var(--kds-muted);
            border: 1px dashed var(--kds-border);
            border-radius: 12px;
        }

        @media (max-width: 980px) {
            .board {
                grid-template-columns: 1fr;
            }
        }

        /* Segundo canal de balcão: etiqueta no cartão e filtro por canal */
        .kds-origem.is-canal2 {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 2px 10px;
            border-radius: 999px;
            background: rgba(14, 165, 233, .16);
            color: #0369a1;
            font-weight: 700;
        }

        .kds-canais {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 0 0 14px;
        }

        .kds-canal-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 15px;
            border-radius: 999px;
            border: 1px solid rgba(148, 163, 184, .45);
            background: transparent;
            color: inherit;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }

        .kds-canal-btn.is-on {
            border-color: #0ea5e9;
            background: rgba(14, 165, 233, .18);
            color: #0369a1;
        }

        .card-item.is-filtrado { display: none; }
    </style>
    <link rel="stylesheet" href="css/kds-command.css?v=<?php echo (int)@filemtime(__DIR__ . '/css/kds-command.css'); ?>">
</head>

<body class="kds-command-v2 plan-theme-<?php echo htmlspecialchars($planThemeSlug, ENT_QUOTES, 'UTF-8'); ?> kds-tier-<?php echo htmlspecialchars($kdsTier, ENT_QUOTES, 'UTF-8'); ?> kds-layout-<?php echo htmlspecialchars($kdsLayout, ENT_QUOTES, 'UTF-8'); ?><?php echo !empty($kdsModoSimples) ? ' kds-mode-simple' : ''; ?>">
    <?php if (!empty($kdsModoSimples)): ?>
        <?php
        $kdsTituloArea = 'Bar';
        include __DIR__ . '/includes/kds_lista_simples.php';
        ?>
    <?php else: ?>
    <div class="kds-topbar">
        <div class="kds-title">
            <?php echo $kdsLayout === 'intelligent-command' ? 'Comando Inteligente · Bar' : 'KDS Operacional · Bar'; ?>
            <span class="kds-plan-badge"><?php echo htmlspecialchars($kdsPlanLabel, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <div class="kds-actions">
            <span class="kds-online"><i class="fas fa-circle" aria-hidden="true"></i>Sistema online</span>
            <button class="kds-sound-toggle" type="button" data-kds-sound-toggle hidden aria-label="Controlar alertas sonoros"></button>
            <span class="kds-action-separator" aria-hidden="true"></span>
            <span id="kds-clock"></span>
            <?php if (in_array(strtoupper((string)$perfil), ['ADMIN','GERENTE','GARCOM','SUPER_ADMIN'], true)): ?>
                <a class="btn-primary" href="novo_pedido.php"><i class="fas fa-plus" aria-hidden="true"></i>Novo pedido</a>
            <?php endif; ?>
            <a class="js-kds-voltar" href="<?php echo in_array(strtoupper((string)$perfil), ['BAR', 'COZINHA'], true) ? 'iniciar_turno.php' : 'dashboard.php'; ?>"><i class="fas fa-arrow-left" aria-hidden="true"></i>Voltar</a>
        </div>
    </div>

    <?php if (!empty($kdsIntelEnabled)): ?>
        <?php
        $kdsAreaLabel = 'Bar';
        include __DIR__ . '/includes/kds_empresarial_intel.php';
        ?>
    <?php elseif (!empty($kdsShowSummary)): ?>
        <div class="kds-summary">
            <span class="pill">Modo <strong>Operacional</strong></span>
            <span class="pill">SLA <strong><?php echo (int)$kdsSlaWarn; ?>/<?php echo (int)$kdsSlaCrit; ?> min</strong></span>
            <span class="pill">Em preparo <strong><?php echo count($grupos['em_preparo']); ?></strong></span>
            <span class="pill">Prontos <strong><?php echo count($grupos['pronto']); ?></strong></span>
            <span class="pill">Na fila <strong><?php echo count($grupos['em_preparo']) + count($grupos['pronto']); ?> item(s)</strong></span>
            <span class="pill">Mais antigo <strong><?php echo $kdsOldestPrep === null ? '—' : ($kdsOldestPrep . ' min'); ?></strong></span>
        </div>
    <?php endif; ?>

    <?php if ($kdsCanaisAtivo): ?>
        <div class="kds-canais" id="kdsCanais" role="group" aria-label="Filtrar por canal">
            <button type="button" class="kds-canal-btn is-on" data-canal-filtro="todos">Todos</button>
            <button type="button" class="kds-canal-btn" data-canal-filtro="mesa">Salão</button>
            <button type="button" class="kds-canal-btn" data-canal-filtro="balcao">Balcão</button>
            <button type="button" class="kds-canal-btn" data-canal-filtro="balcao2"><i class="fas fa-bag-shopping" aria-hidden="true"></i> <?= htmlspecialchars((string)$kdsBalcao2['nome'], ENT_QUOTES, 'UTF-8') ?></button>
        </div>
    <?php endif; ?>

    <div class="board kds-refresh-root">
        <?php
        $preparoParticionado = kds_partition_preparation_items($grupos['em_preparo'] ?? [], (int)$kdsSlaCrit);
        $preparoRegular = $preparoParticionado['regular'];
        $itensCriticos = $preparoParticionado['critical'];
        $colunas = [
            'em_preparo' => [
                'label' => 'EM PREPARO',
                'class' => 'column-preparo',
                'icon' => 'fa-martini-glass-citrus',
                'items' => !empty($kdsHasCriticalColumn) ? $preparoRegular : ($grupos['em_preparo'] ?? []),
            ],
            'pronto' => ['label' => 'PRONTO', 'class' => 'column-pronto', 'icon' => 'fa-circle-check', 'items' => $grupos['pronto'] ?? []],
        ];
        if (!empty($kdsHasCriticalColumn)) {
            $colunas['critico'] = ['label' => 'CRÍTICO', 'class' => 'column-critico', 'icon' => 'fa-triangle-exclamation', 'items' => $itensCriticos];
        }
        foreach ($colunas as $statusKey => $info):
            $lista = $info['items'];
        ?>
            <div class="column <?= $info['class'] ?>">
                <div class="column-header">
                    <span><i class="fas <?= htmlspecialchars($info['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i><?= $info['label'] ?></span>
                    <span class="count"><?= count($lista) ?></span>
                </div>
                <div class="column-body">
                    <?php if (empty($lista)): ?>
                        <div class="empty-state">
                            <span class="empty-state-icon" aria-hidden="true"><i class="fas <?= $statusKey === 'critico' ? 'fa-triangle-exclamation' : ($statusKey === 'pronto' ? 'fa-bell-concierge' : 'fa-martini-glass-citrus') ?>"></i></span>
                            <strong>Fila limpa —</strong>
                            <span>à espera do próximo pedido</span>
                        </div>
                    <?php else: ?>
                        <?php foreach ($lista as $item):
                            $criado = strtotime($item['data_status']);
                            $minutos = $criado ? floor((time() - $criado) / 60) : 0;
                            $critico = $minutos > (int)$kdsSlaCrit ? 'critico' : '';
                            $antigo = $minutos > (int)$kdsSlaWarn ? 'antigo' : '';
                            $tempoTexto = $minutos <= 0 ? 'agora' : ($minutos . ' min atrás');
                            [$mesaTexto, $kdsCanalItem] = kds_origem_item($item, $kdsBalcao2);
                            $qtd = (int)($item['quantidade'] ?? 1);
                            $produtoImagem = !empty($kdsShowProductImages)
                                ? kds_product_image_url($item['produto_imagem'] ?? '')
                                : '';
                        ?>
                            <div class="card-item <?= $antigo ?> <?= $critico ?>" data-id="<?= (int)$item['id'] ?>" data-status="<?= htmlspecialchars($item['status']) ?>" data-canal="<?= htmlspecialchars($kdsCanalItem, ENT_QUOTES, 'UTF-8') ?>">
                                <div class="card-top">
                                    <span class="kds-origem<?= ($kdsCanaisAtivo && $kdsCanalItem === 'balcao2') ? ' is-canal2' : '' ?>"><?php if ($kdsCanaisAtivo && $kdsCanalItem === 'balcao2'): ?><i class="fas fa-bag-shopping" aria-hidden="true"></i> <?php endif; ?><?= htmlspecialchars($mesaTexto) ?></span>
                                    <?php if ($critico !== ''): ?>
                                        <span class="sla-chip crit">Crítico</span>
                                    <?php elseif ($statusKey === 'em_preparo' && $minutos > (int)$kdsSlaWarn): ?>
                                        <span class="sla-chip warn">Atenção</span>
                                    <?php endif; ?>
                                </div>
                                <?php
                                $kdsEmpCard = ($kdsTier ?? '') === 'enterprise';
                                $numPedidoTxt = trim((string)($item['numero_pedido'] ?? '')) !== '' ? (string)$item['numero_pedido'] : (string)(int)$item['pedido_id'];
                                $garcomTxt = trim((string)($item['garcom_nome'] ?? ''));
                                $obsLista = [];
                                foreach (['item_observacao', 'pedido_observacao'] as $obsCampo) {
                                    $obsTxt = trim((string)($item[$obsCampo] ?? ''));
                                    if ($obsTxt !== '' && !in_array($obsTxt, $obsLista, true)) { $obsLista[] = $obsTxt; }
                                }
                                ?>
                                <?php if ($kdsEmpCard): ?>
                                    <div class="card-sub kds-card-meta">
                                        Pedido #<?= htmlspecialchars($numPedidoTxt, ENT_QUOTES, 'UTF-8') ?><?php if ($garcomTxt !== ''): ?> · <i class="fas fa-user" aria-hidden="true"></i> <?= htmlspecialchars($garcomTxt, ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
                                    </div>
                                <?php elseif (!empty($kdsShowPedidoId)): ?>
                                    <div class="card-sub">
                                        Pedido #<?= (int)$item['pedido_id'] ?>
                                        <?php if (!empty($kdsShowItemId)): ?>
                                            · Item #<?= (int)$item['id'] ?>
                                        <?php endif; ?>
                                        <?php if ($garcomTxt !== ''): ?>
                                            · <i class="fas fa-user" aria-hidden="true"></i> <?= htmlspecialchars($garcomTxt, ENT_QUOTES, 'UTF-8') ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($kdsEmpCard): ?>
                                    <div class="kds-card-produto<?= $produtoImagem !== '' ? ' tem-foto' : '' ?>">
                                        <?php if ($produtoImagem !== ''): ?>
                                            <div class="kds-card-foto-wrap">
                                                <img class="kds-card-foto-bg" src="<?= htmlspecialchars($produtoImagem, ENT_QUOTES, 'UTF-8') ?>" alt="" aria-hidden="true" loading="lazy" decoding="async">
                                                <img class="kds-card-foto" src="<?= htmlspecialchars($produtoImagem, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($item['produto_nome'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy" decoding="async" onerror="this.closest('.kds-card-produto').classList.remove('tem-foto');this.parentNode.remove()">
                                            </div>
                                        <?php endif; ?>
                                        <div class="kds-card-produto-txt">
                                            <span class="kds-card-qtd"><?= $qtd ?>×</span>
                                            <strong><?= htmlspecialchars($item['produto_nome']) ?></strong>
                                        </div>
                                    </div>
                                <?php elseif (!empty($kdsShowProductImages)): ?>
                                    <div class="kds-product-row">
                                        <?php if ($produtoImagem !== ''): ?>
                                            <img class="kds-product-image" src="<?= htmlspecialchars($produtoImagem, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($item['produto_nome'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy" decoding="async">
                                        <?php else: ?>
                                            <div class="kds-product-image kds-product-placeholder" aria-hidden="true"><?= htmlspecialchars(strtoupper(substr((string)$item['produto_nome'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                        <div class="kds-product-info">
                                            <span class="badge-qtd"><?= $qtd ?>x</span>
                                            <strong><?= htmlspecialchars($item['produto_nome']) ?></strong>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div>
                                        <span class="badge-qtd"><?= $qtd ?>x</span>
                                        <strong><?= htmlspecialchars($item['produto_nome']) ?></strong>
                                    </div>
                                <?php endif; ?>
                                <?php foreach ($obsLista as $obsTxt): ?>
                                    <div class="kds-card-obs"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i> <?= htmlspecialchars($obsTxt, ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endforeach; ?>
                                <div class="tempo"><?= $tempoTexto ?></div>
                                <?php if ($statusKey === 'em_preparo' || $statusKey === 'critico'): ?>
                                    <button class="btn-status btn-ready" type="button" data-next="pronto"><i class="fas fa-check" aria-hidden="true"></i>Marcar pronto</button>
                                <?php else: ?>
                                    <button class="btn-status btn-delivered" type="button" data-next="entregue"><i class="fas fa-arrow-right" aria-hidden="true"></i>Marcar entregue</button>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <audio id="audio-novo" src="data:audio/wav;base64,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" preload="auto"></audio>
    <audio id="audio-pronto" src="data:audio/wav;base64,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" preload="auto"></audio>
    <script src="js/receipt_print.js?v=20260821a"></script>
    <script src="js/offline_sync.js?v=20261101b"></script>
    <script src="js/nv_operacao.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_operacao.js'); ?>"></script>
    <script src="js/kds_sound_alerts.js?v=20260814a"></script>
    <script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
        // Filtro por canal (só existe com o segundo balcão activo).
        (function initKdsCanais() {
            var barra = document.getElementById('kdsCanais');
            if (!barra) return;
            var escolhido = 'todos';
            function aplicar() {
                document.querySelectorAll('.card-item').forEach(function (card) {
                    var canal = card.getAttribute('data-canal') || 'mesa';
                    card.classList.toggle('is-filtrado', escolhido !== 'todos' && canal !== escolhido);
                });
            }
            barra.addEventListener('click', function (ev) {
                var btn = ev.target.closest('[data-canal-filtro]');
                if (!btn) return;
                escolhido = btn.getAttribute('data-canal-filtro') || 'todos';
                barra.querySelectorAll('.kds-canal-btn').forEach(function (b) {
                    b.classList.toggle('is-on', b === btn);
                });
                aplicar();
            });
            // O quadro recarrega sozinho: reaplica o filtro depois de cada actualização.
            var alvo = document.querySelector('.kds-refresh-root');
            if (alvo && window.MutationObserver) {
                new MutationObserver(aplicar).observe(alvo, { childList: true, subtree: true });
            }
            aplicar();
        })();
        OfflineSync.init({
            csrfToken: <?php echo json_encode($csrfToken, JSON_UNESCAPED_UNICODE); ?>,
            syncIntervalMs: 8000
        });
        // Relógio
        const clockEl = document.getElementById('kds-clock');
        const tick = () => {
            const now = new Date();
            clockEl.textContent = now.toLocaleTimeString('pt-BR', {
                hour12: false
            });
        };
        tick();
        setInterval(tick, 1000);

        document.querySelectorAll('.js-kds-voltar').forEach((link) => {
            link.addEventListener('click', (event) => {
                event.preventDefault();
                window.location.replace(link.getAttribute('href') || 'novo_pedido.php');
            });
        });

        // Beep fallback (caso áudio não carregue)
        let audioCtx = null;

        function beep(freq, dur, volume = 0.5) {
            try {
                if (!audioCtx) audioCtx = new(window.AudioContext || window.webkitAudioContext)();
                const o = audioCtx.createOscillator();
                const g = audioCtx.createGain();
                o.connect(g);
                g.connect(audioCtx.destination);
                o.frequency.value = freq;
                g.gain.setValueAtTime(volume, audioCtx.currentTime);
                g.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + dur);
                o.start();
                o.stop(audioCtx.currentTime + dur);
            } catch (e) {}
        }

        function beepNovo() {
            beep(880, 0.1, 0.25);
            setTimeout(() => beep(1046, 0.1, 0.25), 120);
        }

        function beepPronto() {
            beep(1318, 0.14, 0.4);
            setTimeout(() => beep(1568, 0.16, 0.45), 160);
        }

        const kdsSoundAlerts = <?php echo !empty($kdsSoundAlerts) ? 'true' : 'false'; ?>;
        const soundController = window.KdsSoundAlerts.create({
            enabled: kdsSoundAlerts,
            area: 'bar'
        });

        // Atualizar status do item
        document.addEventListener('click', async (e) => {
            const btn = e.target.closest('.btn-status');
            if (!btn) return;
            const card = btn.closest('.card-item');
            const itemId = card?.dataset.id;
            const next = btn.dataset.next;
            if (!itemId || !next) return;

            btn.disabled = true;
            try {
                const result = await OfflineSync.executeOrQueue({
                    label: 'Atualizar item ' + itemId + ' para ' + next,
                    endpoint: 'api/pedido_item_status.php',
                    method: 'POST',
                    body: { item_id: String(itemId), status: String(next) },
                    headers: {
                        'X-CSRF-Token': <?php echo json_encode($csrfToken, JSON_UNESCAPED_UNICODE); ?>,
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                if (result.queued) {
                    NivoraFeedback.info('Acao guardada e pendente de sincronizacao.');
                } else {
                    soundController.acknowledgeTransition(itemId, next);
                    atualizarBoard(400);
                }
            } catch (error) {
                NivoraFeedback.error(error.message || 'Erro ao atualizar status!');
                btn.disabled = false;
            }
        });

        let carregandoBoard = false;

        // Atualização automática dos cards (pode ser AJAX para atualizar só a lista)
        function atualizarBoard(delay = 0) {
            setTimeout(() => {
                if (carregandoBoard) return;
                carregandoBoard = true;
                fetch(window.location.href, {
                        cache: 'no-store'
                    })
                    .then(r => r.text())
                    .then(html => {
                        const temp = document.createElement('div');
                        temp.innerHTML = html;
                        const novaBoard = temp.querySelector('.kds-refresh-root');
                        const board = document.querySelector('.kds-refresh-root');
                        if (!novaBoard || !board) return;

                        const novaSummary = temp.querySelector('.kds-summary');
                        const summary = document.querySelector('.kds-summary');
                        if (novaSummary && summary) {
                            summary.innerHTML = novaSummary.innerHTML;
                        }

                        const novaIntel = temp.querySelector('[data-kds-intel]');
                        const intel = document.querySelector('[data-kds-intel]');
                        if (novaIntel && intel) {
                            intel.outerHTML = novaIntel.outerHTML;
                        }

                        soundController.compareAndNotify(novaBoard);

                        board.innerHTML = novaBoard.innerHTML;
                    })
                    .catch(() => {})
                    .finally(() => {
                        carregandoBoard = false;
                    });
            }, delay);
        }

        // So recarrega quando ha novidade (ver js/nv_operacao.js).
        NvOperacao.vigiar({ intervalo: 4000, forcarCada: 60000, aoMudar: () => atualizarBoard() });
    </script>
</body>

</html>
