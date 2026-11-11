<?php
require_once __DIR__ . '/app_base.php';
/*
 * Ingredientes (versao simples, plano Basico): o que tenho, o que esta a acabar,
 * e a receita de cada prato para o sistema baixar os ingredientes ao vender.
 */
include_once APP_BASE . '/src/config/auth_check.php';
include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/csrf.php';
include_once APP_BASE . '/src/config/ingredientes_simples.php';

$perfil = strtoupper(trim((string)($_SESSION['perfil'] ?? '')));
if (!in_array($perfil, ['ADMIN', 'GERENTE'], true)) {
    header('Location: dashboard.php?erro=acesso_negado');
    exit;
}
$planoRid = function_exists('session_restaurante_capability_id') ? (int)session_restaurante_capability_id() : (int)($_SESSION['restaurante_id'] ?? 0);
$planoRid = $planoRid > 0 ? $planoRid : (int)($_SESSION['restaurante_id'] ?? 0);
if (plano_tem_funcionalidade_db($planoRid, 'estoque_avancado')) {
    // Profissional/Empresarial: tem o Estoque Inteligente completo.
    header('Location: estoque_inteligente.php');
    exit;
}
if (!plano_tem_ingredientes($planoRid)) {
    header('Location: dashboard.php?erro=funcionalidade_bloqueada');
    exit;
}
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $h(csrf_get_token()) ?>">
    <title>Ingredientes - Nivora</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/premium.css">
    <link rel="stylesheet" href="css/ingredientes.css?v=<?= (int)@filemtime(__DIR__ . '/css/ingredientes.css') ?>">
</head>
<body class="premium-ui">
<div class="container-fluid">
    <div class="row">
        <?php include_once __DIR__ . '/includes/sidebar.php'; ?>
        <main class="main-content ing">
            <header class="ing__topo">
                <div>
                    <h1>Ingredientes</h1>
                    <p>Registe o que tem e defina os ingredientes de cada prato. Nas vendas, o sistema desconta o stock automaticamente.</p>
                </div>
                <button type="button" class="ing__btn ing__btn--principal" id="ingNovo"><i class="fas fa-plus" aria-hidden="true"></i> Adicionar ingrediente</button>
            </header>

            <nav class="ing__abas" role="tablist" aria-label="Gestão de ingredientes">
                <button type="button" role="tab" id="tabIngredientes" class="ing__aba is-ativa" aria-selected="true" aria-controls="abaLista" tabindex="0" data-aba="lista">Ingredientes <span id="ingContagem" class="ing__aba-contagem"></span></button>
                <button type="button" role="tab" id="tabReceitas" class="ing__aba" aria-selected="false" aria-controls="abaReceitas" tabindex="-1" data-aba="receitas">Receitas dos pratos</button>
            </nav>

            <section id="abaLista" role="tabpanel" aria-labelledby="tabIngredientes" tabindex="0">
                <p id="ingStatus" class="visually-hidden" role="status" aria-live="polite" aria-atomic="true"></p>
                <div id="ingResumo" class="ing__resumo"></div>
                <div id="ingLista" class="ing__lista">
                    <p class="ing__carregando" role="status"><span class="ing__spinner" aria-hidden="true"></span>A carregar ingredientes…</p>
                </div>
            </section>

            <section id="abaReceitas" role="tabpanel" aria-labelledby="tabReceitas" tabindex="0" hidden>
                <p id="ingStatusReceitas" class="visually-hidden" role="status" aria-live="polite" aria-atomic="true"></p>
                <p class="ing__ajuda">Escolha um prato e diga o que leva <strong>para 1 prato</strong>. Ex.: Hambúrguer = 1 pão + 150 g de carne + 1 fatia de queijo.</p>
                <div id="ingPratos" class="ing__lista"><p class="ing__carregando" role="status"><span class="ing__spinner" aria-hidden="true"></span>A carregar pratos…</p></div>
            </section>

            <p class="ing__rodape">Precisa de lotes, validades, custo por prato ou relatórios de perdas? Estão no <a href="solicitar_plano.php">plano Profissional</a>.</p>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/ingredientes.js?v=<?= (int)@filemtime(__DIR__ . '/js/ingredientes.js') ?>"></script>
</body>
</html>
