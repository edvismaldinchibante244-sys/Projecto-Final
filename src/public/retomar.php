<?php
require_once __DIR__ . '/app_base.php';
/*
 * Sessao em pausa — o UNICO ecra que aparece quando a sessao expira (computador ou terminal).
 * Mostra quem estava ligado; continua-se com o PIN (equipa com PIN) ou a palavra-passe.
 * "Outra pessoa": no terminal mostra a equipa (PIN); nos outros abre o login normal.
 */
require_once APP_BASE . '/src/config/security.php';
require_once APP_BASE . '/src/config/csrf.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/personal_data.php';
require_once APP_BASE . '/src/config/sessao_retomar.php';
require_once APP_BASE . '/src/config/terminal_pin.php';

security_start_session();
security_set_headers();

$pausa = null;
try {
    $pausa = sr_obter(Database::infra());
} catch (Throwable $e) {
    error_log('retomar.php: ' . $e->getMessage());
}
if (!$pausa) {
    sr_gravar_cookie('', time() - 3600);
    header('Location: index.php');
    exit;
}
// Sessao ainda valida (ex.: outro separador ja retomou): segue para a pagina.
$voltar = sr_voltar_seguro($_GET['voltar'] ?? '');
if (!empty($_SESSION['logado']) && (int)($_SESSION['usuario_id'] ?? 0) === (int)$pausa['usuario_id']) {
    header('Location: ' . ($voltar !== '' ? $voltar : 'dashboard.php'));
    exit;
}
$csrf = csrf_get_token();
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$nome = trim((string)$pausa['nome']) ?: 'Utilizador';
$partes = preg_split('/\s+/', $nome) ?: [$nome];
$iniciais = mb_strtoupper(mb_substr($partes[0], 0, 1) . (count($partes) > 1 ? mb_substr(end($partes), 0, 1) : ''));
$email = (string)$pausa['email'];
$emailMasc = preg_replace('/^(.{2})[^@]*(@.*)$/u', '$1•••$2', $email) ?: '';
$rotulos = ['ADMIN' => 'Administrador', 'GERENTE' => 'Gerente', 'CAIXA' => 'Caixa', 'GARCOM' => 'Empregado de mesa', 'COZINHA' => 'Cozinha', 'BAR' => 'Bar'];
$perfil = strtoupper((string)$pausa['perfil']);
$temPin = !empty($pausa['tem_pin']) && terminal_perfil_usa_pin($perfil);
$noTerminal = terminal_tem_cookie();
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $h($csrf) ?>">
    <meta name="robots" content="noindex">
    <title>Sessão em pausa — <?= $h($pausa['restaurante_nome'] ?: 'Nivora') ?></title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:radial-gradient(1200px 600px at 50% -10%,#2a2019 0%,#14110f 60%);color:#f5efe6}
        .rt{width:min(420px,100%);background:#1c1815;border:1px solid #3a322b;border-radius:20px;padding:32px 28px 26px;box-shadow:0 24px 60px rgba(0,0,0,.45);text-align:center}
        .rt__marca{font-size:.75rem;letter-spacing:.14em;text-transform:uppercase;color:#b9ad9c;margin:0 0 22px}
        .rt__foto{width:76px;height:76px;margin:0 auto 14px;border-radius:50%;background:linear-gradient(135deg,#e0b878,#b9874a);color:#14110f;font-weight:700;font-size:1.7rem;display:flex;align-items:center;justify-content:center;box-shadow:0 0 0 4px rgba(201,163,106,.18)}
        .rt h1{font-size:1.35rem;margin:0}
        .rt__quem{margin:.3rem 0 0;color:#b9ad9c;font-size:.92rem}
        .rt__aviso{display:flex;gap:10px;align-items:flex-start;text-align:left;margin:22px 0 18px;padding:12px 14px;border-radius:12px;background:#241e19;border:1px solid #3a322b;color:#d8ccbb;font-size:.88rem;line-height:1.45}
        .rt__aviso i{color:#c9a36a;margin-top:2px}
        .rt label{display:block;text-align:left;font-size:.85rem;font-weight:600;margin-bottom:6px;color:#e8dccb}
        .rt__campo{position:relative}
        .rt input{width:100%;height:48px;border-radius:12px;border:1px solid #4a4037;background:#14110f;color:#f5efe6;padding:0 46px 0 14px;font:inherit;font-size:1rem}
        .rt input:focus{outline:none;border-color:#c9a36a;box-shadow:0 0 0 3px rgba(201,163,106,.25)}
        .rt__olho{position:absolute;right:6px;top:6px;width:36px;height:36px;border:0;border-radius:8px;background:transparent;color:#b9ad9c;cursor:pointer}
        .rt__erro{min-height:1.3em;margin:8px 0 4px;color:#ff9b8a;font-size:.88rem;text-align:left}
        .rt__btn{width:100%;height:48px;border:0;border-radius:12px;background:#c9a36a;color:#14110f;font:inherit;font-weight:700;font-size:1rem;cursor:pointer;margin-top:6px}
        .rt__btn:hover{background:#d6b27a}.rt__btn:disabled{opacity:.6;cursor:wait}
        .rt__outra{display:inline-block;color:#cfc3b2;margin-top:16px;background:none;border:0;color:#cfc3b2;font:inherit;font-size:.9rem;cursor:pointer;text-decoration:underline}
        .rt__pin{margin-top:4px}
        .rt__pontos{display:flex;justify-content:center;gap:12px;height:22px;margin:4px 0 6px}
        .rt__pontos span{width:16px;height:16px;border-radius:50%;border:2px solid #c9a36a}
        .rt__pontos span.cheio{background:#c9a36a}
        .rt__teclado{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:6px}
        .rt__teclado button{height:58px;border-radius:14px;border:1px solid #3a322b;background:#241e19;color:inherit;font-size:1.4rem;cursor:pointer;font-family:inherit}
        .rt__teclado button:active{background:#2e2721}
        .rt__teclado .rt__ok{font-size:1rem;font-weight:700;background:#c9a36a;color:#14110f;border-color:#c9a36a}
        .rt__teclado button:disabled{opacity:.5;cursor:wait}
        .rt__alternar{margin-top:14px;background:none;border:0;color:#c9a36a;font:inherit;font-size:.9rem;cursor:pointer}
        .rt__erro--pin{text-align:center}
        .rt__rodape{margin-top:18px;font-size:.78rem;color:#8f8476}
    </style>
</head>
<body>
<main class="rt" aria-labelledby="rtTitulo">
    <p class="rt__marca"><?= $h($pausa['restaurante_nome'] ?: 'Nivora Restaurant') ?></p>
    <div class="rt__foto" aria-hidden="true"><?= $h($iniciais) ?></div>
    <h1 id="rtTitulo">Olá, <?= $h($partes[0]) ?></h1>
    <p class="rt__quem"><?= $h($rotulos[$perfil] ?? ucfirst(strtolower($perfil))) ?><?= $emailMasc !== '' ? ' · ' . $h($emailMasc) : '' ?></p>

    <div class="rt__aviso"><i class="fas fa-pause-circle" aria-hidden="true"></i>
        <span id="rtAvisoTexto"><?= $temPin ? 'Sessão em pausa. Introduza o seu PIN para continuar onde estava.' : 'Sessão em pausa. Introduza a palavra-passe para continuar onde estava.' ?></span></div>

    <?php if ($temPin): ?>
    <section class="rt__pin" id="rtPin" aria-label="PIN">
        <div class="rt__pontos" id="rtPontos" aria-hidden="true"></div>
        <p class="rt__erro rt__erro--pin" id="rtErroPin" role="alert"></p>
        <div class="rt__teclado">
            <?php foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $n): ?>
                <button type="button" data-tecla="<?= $n ?>"><?= $n ?></button>
            <?php endforeach; ?>
            <button type="button" data-tecla="apagar" aria-label="Apagar">⌫</button>
            <button type="button" data-tecla="0">0</button>
            <button type="button" data-tecla="ok" class="rt__ok">Continuar</button>
        </div>
        <button type="button" class="rt__alternar" id="rtUsarSenha">Usar a palavra-passe</button>
    </section>
    <?php endif; ?>

    <form id="rtForm" autocomplete="on" novalidate<?= $temPin ? ' hidden' : '' ?>>
        <input type="hidden" name="voltar" value="<?= $h($voltar) ?>">
        <input type="text" name="username" value="<?= $h($email) ?>" autocomplete="username" hidden>
        <label for="rtSenha">Palavra-passe</label>
        <div class="rt__campo">
            <input type="password" id="rtSenha" name="senha" autocomplete="current-password" required<?= $temPin ? '' : ' autofocus' ?>>
            <button type="button" class="rt__olho" id="rtOlho" aria-label="Mostrar palavra-passe" aria-pressed="false"><i class="fas fa-eye" aria-hidden="true"></i></button>
        </div>
        <p class="rt__erro" id="rtErro" role="alert"></p>
        <button type="submit" class="rt__btn" id="rtEntrar">Continuar</button>
        <?php if ($temPin): ?><button type="button" class="rt__alternar" id="rtUsarPin">Usar o PIN</button><?php endif; ?>
    </form>
    <?php if ($noTerminal): ?>
        <a class="rt__outra" href="terminal.php">Não é <?= $h($partes[0]) ?>? Outra pessoa</a>
    <?php else: ?>
        <button type="button" class="rt__outra" id="rtOutra">Não é <?= $h($partes[0]) ?>? Entrar com outra conta</button>
    <?php endif; ?>
    <p class="rt__rodape">Por segurança, após várias tentativas erradas terá de entrar de novo.</p>
</main>
<script nonce="<?= $h(security_csp_nonce()) ?>">
(function () {
    var csrf = document.querySelector('meta[name="csrf-token"]').content;
    var form = document.getElementById('rtForm'), senha = document.getElementById('rtSenha'), erro = document.getElementById('rtErro'), btn = document.getElementById('rtEntrar');
    var voltar = form.voltar.value;
    function enviar(dados) {
        var fd = new FormData();
        Object.keys(dados).forEach(function (k) { fd.append(k, dados[k]); });
        fd.append('_csrf', csrf);
        return fetch('api/retomar.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf } })
            .then(function (r) { return r.json().catch(function () { return { success: false, message: 'Não foi possível continuar. Tente de novo.' }; }); });
    }
    document.getElementById('rtOlho').addEventListener('click', function () {
        var ver = senha.type === 'password';
        senha.type = ver ? 'text' : 'password';
        this.setAttribute('aria-pressed', ver ? 'true' : 'false');
        this.innerHTML = '<i class="fas ' + (ver ? 'fa-eye-slash' : 'fa-eye') + '" aria-hidden="true"></i>';
    });
    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        if (!senha.value) { erro.textContent = 'Introduza a palavra-passe.'; senha.focus(); return; }
        btn.disabled = true; btn.textContent = 'A verificar…'; erro.textContent = '';
        enviar({ acao: 'entrar', senha: senha.value, voltar: voltar }).then(function (j) {
            if (j.success || j.redirect) { location.replace(j.redirect || 'dashboard.php'); return; }
            erro.textContent = j.message || 'Palavra-passe incorreta.';
            senha.value = ''; senha.focus();
            btn.disabled = false; btn.textContent = 'Continuar';
        });
    });
    // PIN
    var secPin = document.getElementById('rtPin'), aviso = document.getElementById('rtAvisoTexto');
    if (secPin) {
        var pin = '', pontos = document.getElementById('rtPontos'), erroPin = document.getElementById('rtErroPin'), ocupado = false;
        var desenhar = function () {
            var n = Math.max(4, pin.length), html = '';
            for (var i = 0; i < n; i++) { html += '<span class="' + (i < pin.length ? 'cheio' : '') + '"></span>'; }
            pontos.innerHTML = html;
        };
        var confirmar = function () {
            if (ocupado) { return; }
            if (pin.length < 4) { erroPin.textContent = 'O PIN tem 4 a 6 números.'; return; }
            ocupado = true; erroPin.textContent = '';
            secPin.querySelectorAll('.rt__teclado button').forEach(function (b) { b.disabled = true; });
            enviar({ acao: 'pin', pin: pin, voltar: voltar }).then(function (j) {
                if (j.success || j.redirect) { location.replace(j.redirect || 'dashboard.php'); return; }
                erroPin.textContent = j.message || 'PIN incorreto.';
                pin = ''; desenhar(); ocupado = false;
                secPin.querySelectorAll('.rt__teclado button').forEach(function (b) { b.disabled = false; });
            });
        };
        var tecla = function (t) {
            if (ocupado) { return; }
            if (t === 'apagar') { pin = pin.slice(0, -1); }
            else if (t === 'ok') { confirmar(); return; }
            else if (pin.length < 6) { pin += t; }
            erroPin.textContent = '';
            desenhar();
        };
        secPin.querySelectorAll('[data-tecla]').forEach(function (b) { b.addEventListener('click', function () { tecla(b.getAttribute('data-tecla')); }); });
        document.addEventListener('keydown', function (e) {
            if (secPin.hidden) { return; }
            if (/^\d$/.test(e.key)) { tecla(e.key); } else if (e.key === 'Backspace') { tecla('apagar'); } else if (e.key === 'Enter') { tecla('ok'); }
        });
        desenhar();
        document.getElementById('rtUsarSenha').addEventListener('click', function () {
            secPin.hidden = true; form.hidden = false; senha.focus();
            aviso.textContent = 'Sessão em pausa. Introduza a palavra-passe para continuar onde estava.';
        });
        document.getElementById('rtUsarPin').addEventListener('click', function () {
            form.hidden = true; secPin.hidden = false;
            aviso.textContent = 'Sessão em pausa. Introduza o seu PIN para continuar onde estava.';
        });
    }
    var outra = document.getElementById('rtOutra');
    if (outra) {
        outra.addEventListener('click', function () {
            enviar({ acao: 'outra_conta' }).then(function () { location.replace('index.php'); });
        });
    }
})();
</script>
</body>
</html>
