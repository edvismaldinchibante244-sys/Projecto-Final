<?php
require_once __DIR__ . '/app_base.php';

/**
 * Recuperar senha — Nivora Restaurant
 */

require_once APP_BASE . '/src/config/security.php';
security_start_session();
include_once APP_BASE . '/src/config/database.php';

$database = new Database();
$db = $database->getConnection();

$message = '';
$messageType = '';

if (isset($_SESSION['logado']) && $_SESSION['logado'] === true) {
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt">

<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar senha — Nivora Restaurant</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,600;0,700;1,500&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/tokens.css?v=<?php echo (int)@filemtime(__DIR__ . '/css/tokens.css'); ?>">
    <link rel="stylesheet" href="css/auth-landing.css?v=<?php echo (int)@filemtime(__DIR__ . '/css/auth-landing.css'); ?>">
</head>

<body class="edvistro-auth">

    <div class="ed-auth-page">
        <div class="ed-auth-card">
            <div class="ed-auth-card__head">
                <p class="ed-auth-card__brand">NIVORA RESTAURANT</p>
                <h1>Recuperar senha</h1>
                <p>Indique o email da conta para receber o link de redefinição.</p>
            </div>

            <div class="ed-auth-card__body">
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form id="formRecuperar" method="POST">
                    <div id="recuperarAlert" class="alert" style="display:none;"></div>
                    <div class="mb-3">
                        <label class="form-label" for="email">Email</label>
                        <input type="email" class="form-control" name="email" id="email" required placeholder="Email da conta" autocomplete="email">
                    </div>

                    <button type="submit" class="ed-btn ed-btn--solid ed-btn--block" id="btnEnviar">
                        <i class="fas fa-paper-plane"></i> Enviar link de recuperação
                    </button>
                </form>

                <div id="debugLinkContainer" style="margin-top:12px;"></div>

                <div class="back-link">
                    <a href="index.php"><i class="fas fa-arrow-left me-1"></i> Voltar para a Nivora Restaurant</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
        document.getElementById('formRecuperar').addEventListener('submit', function(e) {
            e.preventDefault();

            const email = document.getElementById('email').value;
            const btn = document.getElementById('btnEnviar');
            const originalText = btn.innerHTML;
            const alertBox = document.getElementById('recuperarAlert');
            const cont = document.getElementById('debugLinkContainer');

            function escapeHtml(value) {
                return String(value || '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function showMessage(type, message) {
                alertBox.className = 'alert alert-' + type;
                alertBox.textContent = message;
                alertBox.style.display = 'block';
            }

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Enviando...';
            alertBox.style.display = 'none';
            cont.innerHTML = '';

            fetch('api/esqueci_senha.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'email=' + encodeURIComponent(email)
                })
                .then(async (response) => {
                    const raw = await response.text();
                    try {
                        return JSON.parse(raw);
                    } catch (err) {
                        throw new Error('Resposta inválida do servidor.');
                    }
                })
                .then(data => {
                    if (data.success) {
                        showMessage(data.email_sent === false ? 'warning' : 'success', data.message + (data.warning ? (' ' + data.warning) : ''));
                        document.getElementById('formRecuperar').reset();
                        if (data.debug_link) {
                            const link = escapeHtml(data.debug_link);
                            cont.innerHTML = '<div class="alert alert-info">Link de recuperacao local: <a href="' + link + '" target="_blank" rel="noopener">' + link + '</a></div>';
                        } else if (data.warning) {
                            cont.innerHTML = '<div class="alert alert-warning">' + escapeHtml(data.warning) + '</div>';
                        }
                    } else {
                        showMessage('danger', data.message || 'Nao foi possivel enviar o link de recuperacao.');
                    }
                })
                .catch(err => {
                    showMessage('danger', 'Nao foi possivel enviar o link de recuperacao. ' + err.message);
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                });
        });
    </script>
</body>

</html>
