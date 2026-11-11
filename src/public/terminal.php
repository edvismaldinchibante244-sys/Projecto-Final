<?php
require_once __DIR__ . '/app_base.php';
/*
 * Ecra de bloqueio do terminal do restaurante.
 * Abrir esta pagina termina a sessao atual (o funcionario "bloqueia" o terminal) e mostra
 * a equipa; cada um entra com o seu PIN.
 */
require_once APP_BASE . '/src/config/security.php';
require_once APP_BASE . '/src/config/csrf.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/personal_data.php';
require_once APP_BASE . '/src/config/terminal_pin.php';

security_start_session();
security_set_headers();

$terminal = null;
$equipa = [];
try {
    $db = (new Database())->getConnection();
    $terminal = terminal_atual($db);
    if ($terminal) {
        $equipa = terminal_funcionarios($db, (int)$terminal['restaurante_id']);
    }
} catch (Throwable $e) {
    error_log('terminal.php: ' . $e->getMessage());
}

if (!$terminal) {
    // Cookie invalido ou terminal revogado: apaga-o e volta ao login normal.
    terminal_gravar_cookie('', time() - 3600);
    header('Location: index.php');
    exit;
}

if (!empty($_SESSION['logado'])) {
    if (!empty($_SESSION['usuario_id'])) {
        try {
            require_once APP_BASE . '/src/config/presenca_online.php';
            presenca_marcar_usuario_offline($db, (int)$_SESSION['usuario_id'], (int)($_SESSION['restaurante_id'] ?? 0));
        } catch (Throwable $e) {
        }
    }
    $_SESSION = [];
    session_regenerate_id(true);
}
$csrf = csrf_get_token();
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$rotulos = ['GARCOM' => 'Garçom', 'CAIXA' => 'Caixa', 'GERENTE' => 'Gerente', 'COZINHA' => 'Cozinha', 'BAR' => 'Bar'];
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $h($csrf) ?>">
    <title>Terminal — <?= $h($terminal['restaurante_nome']) ?></title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="css/terminal.css?v=20261101a">
</head>
<body class="term">
<main class="term__caixa">
    <header class="term__topo">
        <h1><?= $h($terminal['restaurante_nome']) ?></h1>
        <p><?= $h($terminal['nome']) ?> · <span id="termRelogio"></span></p>
    </header>

    <section id="termEquipa" class="term__equipa" aria-label="Quem vai entrar?">
        <h2>Quem vai entrar?</h2>
        <?php if (!$equipa): ?>
            <p class="term__vazio">Ainda ninguém definiu um PIN. Cada funcionário entra uma vez com email e palavra-passe e escolhe <strong>Definir PIN</strong> no menu.</p>
        <?php endif; ?>
        <div class="term__grelha">
            <?php foreach ($equipa as $u): ?>
                <button type="button" class="term__pessoa" data-id="<?= (int)$u['id'] ?>" data-nome="<?= $h($u['nome']) ?>">
                    <span class="term__inicial"><?= $h(mb_strtoupper(mb_substr($u['nome'], 0, 1))) ?></span>
                    <span class="term__nome"><?= $h($u['nome']) ?></span>
                    <span class="term__perfil"><?= $h($rotulos[$u['perfil']] ?? $u['perfil']) ?></span>
                </button>
            <?php endforeach; ?>
        </div>
    </section>

    <section id="termPin" class="term__pin" hidden aria-live="polite">
        <h2 id="termPinNome"></h2>
        <div class="term__pontos" id="termPontos" aria-label="PIN"></div>
        <p class="term__erro" id="termErro" role="alert"></p>
        <div class="term__teclado">
            <?php foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $n): ?>
                <button type="button" data-tecla="<?= $n ?>"><?= $n ?></button>
            <?php endforeach; ?>
            <button type="button" data-tecla="apagar" aria-label="Apagar">⌫</button>
            <button type="button" data-tecla="0">0</button>
            <button type="button" data-tecla="ok" class="term__ok">Entrar</button>
        </div>
        <button type="button" class="term__voltar" id="termVoltar">← Escolher outra pessoa</button>
    </section>

    <footer class="term__rodape">
        <a href="index.php">Administrador? Entrar com email e palavra-passe</a>
    </footer>
</main>
<script src="js/terminal.js?v=20261101a"></script>
</body>
</html>
