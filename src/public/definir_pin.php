<?php
require_once __DIR__ . '/app_base.php';

include_once APP_BASE . '/src/config/auth_check.php';
include_once APP_BASE . '/src/config/csrf.php';
include_once APP_BASE . '/src/config/terminal_pin.php';

if (!empty($_SESSION['super_admin']) || !terminal_perfil_usa_pin($_SESSION['perfil'] ?? '')) {
    header('Location: dashboard.php?erro=acesso_negado');
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
    <title>Definir PIN - Nivora</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/premium.css">
    <link rel="stylesheet" href="css/definir-pin.css?v=20261101a">
</head>
<body class="premium-ui">
<div class="container-fluid">
    <div class="row">
        <?php include_once __DIR__ . '/includes/sidebar.php'; ?>
        <main class="main-content">
            <div class="pin-page">
                <a href="dashboard.php" class="back-btn"><i class="fas fa-arrow-left" aria-hidden="true"></i> Voltar</a>
                <header class="pin-page-header">
                    <h1 class="h3">Definir PIN</h1>
                    <p class="text-muted">Crie um PIN pessoal para entrar nos terminais do restaurante sem escrever email e palavra-passe.</p>
                </header>

                <div id="termAviso" class="alert d-none pin-alert" role="alert"></div>

                <div class="pin-page-grid">
                    <form id="formPin" class="card pin-form-card" autocomplete="off">
                        <div class="card-body">
                            <div class="pin-form-field">
                                <label class="form-label" for="pinNovo">Novo PIN</label>
                                <input class="form-control" id="pinNovo" type="password" inputmode="numeric" pattern="[0-9]{4,6}" minlength="4" maxlength="6" required autocomplete="new-password" aria-describedby="pinRules">
                                <p class="pin-field-hint" id="pinRules">Use 4 a 6 dígitos. Sequências e números repetidos não são aceites.</p>
                            </div>

                            <div class="pin-form-field">
                                <label class="form-label" for="pinConfirmar">Repita o PIN</label>
                                <input class="form-control" id="pinConfirmar" type="password" inputmode="numeric" pattern="[0-9]{4,6}" minlength="4" maxlength="6" required autocomplete="new-password">
                            </div>

                            <div class="pin-form-field">
                                <label class="form-label" for="pinSenha">Palavra-passe da sua conta</label>
                                <input class="form-control" id="pinSenha" type="password" required autocomplete="current-password" aria-describedby="pinPasswordHint">
                                <p class="pin-field-hint" id="pinPasswordHint">Necessária para confirmar esta alteração.</p>
                            </div>

                            <button class="btn btn-primary pin-submit" type="submit">
                                <i class="fas fa-key" aria-hidden="true"></i>
                                <span>Guardar PIN</span>
                            </button>
                        </div>
                    </form>

                    <aside class="pin-guidance" aria-labelledby="pinGuidanceTitle">
                        <h2 id="pinGuidanceTitle">Proteja o seu acesso</h2>
                        <p>O PIN é só para entrar rapidamente nos terminais do restaurante. Mantenha-o em segredo.</p>
                        <ul>
                            <li><i class="fas fa-user-shield" aria-hidden="true"></i><span>Não partilhe o PIN com outras pessoas.</span></li>
                            <li><i class="fas fa-shield-halved" aria-hidden="true"></i><span>Evite códigos fáceis de adivinhar, como 1234 ou 1111.</span></li>
                            <li><i class="fas fa-key" aria-hidden="true"></i><span>Se esquecer o PIN, peça ao administrador para o apagar e defina outro.</span></li>
                        </ul>
                    </aside>
                </div>
            </div>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/terminais.js?v=20261101a"></script>
</body>
</html>
