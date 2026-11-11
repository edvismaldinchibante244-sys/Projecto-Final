<?php
require_once __DIR__ . '/app_base.php';

/*
 
   PÁGINA DE NOVA SENHA
   Usuário define nova senha após clicar no link

 */

include_once APP_BASE . '/src/config/security.php';
security_start_session();
include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/audit.php';
include_once APP_BASE . '/src/config/password_reset_helper.php';
include_once APP_BASE . '/src/config/password_policy.php';
include_once APP_BASE . '/src/Model/Auth.php';

security_set_headers();

$database = new Database();
$db = $database->getConnection();

$message = '';
$messageType = '';
$tokenValido = false;
$passwordMinLength = password_policy_min_length(null, true);
$token = password_reset_normalize_token((string)($_POST['token'] ?? $_GET['token'] ?? ''));
$reset = null;
$tokenStatus = 'missing';

// Se ja esta logado e nao veio com token de redefinicao, redireciona normalmente.
if ($token === '' && isset($_SESSION['logado']) && $_SESSION['logado'] === true) {
    header("Location: dashboard.php");
    exit;
}

// Verificar se token é válido
if ($token !== '') {
    $tokenStatus = password_reset_token_status($db, $token);
    if ($tokenStatus === 'valid') {
        $reset = password_reset_find_valid_token($db, $token);
        $tokenValido = (bool)$reset;
    }
}

// Token presente mas invalido: manter na pagina com mensagem clara (nao saltar para login).
if ($token !== '' && !$tokenValido) {
    if ($tokenStatus === 'expired') {
        $message = 'Este link de recuperação expirou. Solicite um novo link.';
    } elseif ($tokenStatus === 'used') {
        $message = 'Este link já foi utilizado. Se ainda precisa de acesso, solicite um novo link.';
    } else {
        $message = 'Link de recuperação inválido. Solicite um novo link.';
    }
    $messageType = 'danger';
}

// Processar formulário de nova senha
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValido) {
    if (!security_rate_limit('password_reset_page_consume', 8, 600)) {
        security_log_event('password_reset_page_rate_limited');
        $message = "Muitas tentativas. Solicite um novo link.";
        $messageType = "danger";
        $tokenValido = false;
    }

    $senha = isset($_POST['senha']) ? $_POST['senha'] : '';
    $confirmarSenha = isset($_POST['confirmar_senha']) ? $_POST['confirmar_senha'] : '';

    if ($messageType === "danger") {
        // Rate limit ja definiu a mensagem.
    } else {
        $pwdCheck = password_policy_validate($senha, ['privileged' => true]);
        if (!$pwdCheck['ok']) {
            $message = $pwdCheck['message'];
            $messageType = "danger";
        } elseif ($senha !== $confirmarSenha) {
            $message = "As senhas não conferem.";
            $messageType = "danger";
        } else {
        try {
            $db->beginTransaction();

            $resetBloqueado = password_reset_find_valid_token($db, $token, true);
            if (!$resetBloqueado) {
                throw new RuntimeException('Token de reset invalido ou expirado.');
            }

            $userIdToken = isset($resetBloqueado['user_id']) ? (int)$resetBloqueado['user_id'] : 0;
            if ($userIdToken > 0) {
                $stmtUsuario = $db->prepare("SELECT id, restaurante_id, super_admin FROM usuarios WHERE id = ? LIMIT 1 FOR UPDATE");
                $stmtUsuario->execute([$userIdToken]);
            } else {
                $stmtUsuario = $db->prepare("SELECT id, restaurante_id, super_admin FROM usuarios WHERE email_lookup = ? LIMIT 1 FOR UPDATE");
                $stmtUsuario->execute([personal_data_email_lookup((string)$resetBloqueado['email'])]);
            }
            $usuario = $stmtUsuario->fetch(PDO::FETCH_ASSOC);

            if (!$usuario) {
                throw new RuntimeException('Usuario do token de reset nao encontrado.');
            }

            $auth = new Auth($db);
            if (!$auth->atualizarSenha((int)$usuario['id'], $senha)) {
                throw new RuntimeException('Falha ao atualizar senha do usuario.');
            }

            if ((int)($usuario['super_admin'] ?? 0) !== 1) {
                $restauranteId = (int)($usuario['restaurante_id'] ?? 0);

                $stmtAtivarUsuario = $db->prepare("UPDATE usuarios SET ativo = 1 WHERE id = ?");
                $stmtAtivarUsuario->execute([(int)$usuario['id']]);

                if ($restauranteId > 0) {
                    $stmtAtivarRestaurante = $db->prepare("UPDATE restaurantes SET status = 'ATIVO' WHERE id = ?");
                    $stmtAtivarRestaurante->execute([$restauranteId]);
                }
            }

            password_reset_mark_used($db, (int)$resetBloqueado['id']);
            password_reset_invalidate_email_tokens($db, (string)$resetBloqueado['email']);
            $db->commit();
            audit_log($db, 'password_reset_completed_page', [
                'usuario_id' => (int)$usuario['id'],
                'restaurante_id' => (int)($usuario['restaurante_id'] ?? 0),
            ]);

            $message = "Senha redefinida com sucesso! Você já pode fazer login.";
            $messageType = "success";
            $tokenValido = false;
            header("Refresh: 3;url=index.php");
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            error_log('Nova senha page Error: ' . $e->getMessage());
            $message = "Não foi possível redefinir a senha. Solicite um novo link.";
            $messageType = "danger";
        }
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
    <title>Nova senha - Nivora Restaurant</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <!-- Premium UI: fontes, ícones e CSS global do sistema -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../public/css/style.css">
    <link rel="stylesheet" href="css/tokens.css?v=<?php echo (int)@filemtime(__DIR__ . '/css/tokens.css'); ?>">
    <link rel="stylesheet" href="css/nivora-auth.css?v=<?php echo (int)@filemtime(__DIR__ . '/css/nivora-auth.css'); ?>">
</head>

<body class="login-page nivora-reset-page">
    <div class="login-container">
        <section class="reset-brand-story" aria-label="Segurança da conta Nivora Restaurant">
            <p class="reset-story-kicker">Segurança de acesso</p>
            <h2>Proteja o acesso<br>à sua operação.</h2>
            <p class="reset-story-copy">Defina uma credencial forte para continuar a gerir o seu restaurante com tranquilidade.</p>
            <ul class="reset-trust-list" aria-label="Proteções da redefinição de senha">
                <li><i class="fas fa-link" aria-hidden="true"></i><span>Link de redefinição protegido e temporário</span></li>
                <li><i class="fas fa-circle-check" aria-hidden="true"></i><span>Validação dos requisitos em tempo real</span></li>
                <li><i class="fas fa-shield-halved" aria-hidden="true"></i><span>Senha processada de forma segura</span></li>
            </ul>
        </section>
        <div class="login-box">
            <div class="login-header">
                <span class="logo-icon"><i class="fas fa-lock"></i></span>
                <p class="brand-kicker">Nivora Restaurant</p>
                <h1>Nova Senha</h1>
                <p>Crie uma nova senha segura</p>
            </div>
            <div>
                <?php if ($message): ?>
                    <div class="alert <?php echo $messageType === 'success' ? 'alert-success' : 'alert-error'; ?>">
                        <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> me-2"></i><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <?php if ($messageType === 'success'): ?>
                        <a href="index.php" class="btn-primary" style="margin-top:18px;display:block;text-align:center;">
                            <i class="fas fa-sign-in-alt me-2"></i>Ir para Login
                        </a>
                    <?php elseif (!$tokenValido): ?>
                        <a href="esqueci_senha.php" class="btn-primary" style="margin-top:18px;display:block;text-align:center;">
                            <i class="fas fa-redo me-2"></i>Solicitar Novo Link
                        </a>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (!$tokenValido && $message === ''): ?>
                    <div class="token-invalido">
                        <i class="fas fa-times-circle" style="font-size:48px;color:var(--danger);"></i>
                        <h4>Link Inválido ou Expirado</h4>
                        <p class="text-muted">O link de recuperação de senha é inválido ou expirou.</p>
                        <a href="esqueci_senha.php" class="btn-primary" style="margin-top:18px;display:inline-block;">
                            <i class="fas fa-redo me-2"></i>Solicitar Novo Link
                        </a>
                    </div>
                <?php elseif ($tokenValido): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-shield-alt me-2"></i>Crie sua nova senha para ativar o acesso. Este link é válido por tempo limitado.
                    </div>
                    <form id="formNovaSenha" method="POST">
                        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="form-group">
                            <label for="senha">Nova Senha</label>
                            <div class="password-field">
                                <input type="password" class="form-control" name="senha" id="senha" required placeholder="Crie uma senha forte" minlength="<?= (int)$passwordMinLength ?>" maxlength="128" autocomplete="new-password" aria-describedby="passwordRequirements passwordStrengthLabel">
                                <button class="password-toggle" type="button" data-password-toggle="senha" aria-label="Mostrar nova senha"><i class="fas fa-eye"></i></button>
                            </div>
                            <div class="password-strength" aria-hidden="true"><span id="passwordStrengthBar"></span></div>
                            <p class="password-strength-label" id="passwordStrengthLabel" aria-live="polite">Comece a escrever a nova senha</p>
                            <ul class="password-requirements" id="passwordRequirements" aria-label="Requisitos da senha">
                                <li data-rule="length"><i class="fas fa-circle"></i><span>Pelo menos <?= (int)$passwordMinLength ?> caracteres</span></li>
                                <li data-rule="lower"><i class="fas fa-circle"></i><span>Uma letra minúscula</span></li>
                                <li data-rule="upper"><i class="fas fa-circle"></i><span>Uma letra maiúscula</span></li>
                                <li data-rule="number"><i class="fas fa-circle"></i><span>Um número</span></li>
                                <li data-rule="symbol"><i class="fas fa-circle"></i><span>Um símbolo, como ! @ # $ %</span></li>
                            </ul>
                        </div>
                        <div class="form-group">
                            <label for="confirmar_senha">Confirmar Nova Senha</label>
                            <div class="password-field">
                                <input type="password" class="form-control" name="confirmar_senha" id="confirmar_senha" required placeholder="Repita exatamente a nova senha" minlength="<?= (int)$passwordMinLength ?>" maxlength="128" autocomplete="new-password" aria-describedby="passwordMatchState">
                                <button class="password-toggle" type="button" data-password-toggle="confirmar_senha" aria-label="Mostrar confirmação da senha"><i class="fas fa-eye"></i></button>
                            </div>
                            <p class="password-match-state" id="passwordMatchState" aria-live="polite">Repita a senha para confirmar</p>
                        </div>
                        <button type="submit" class="btn-primary" id="btnSalvar" disabled>
                            <i class="fas fa-save me-2"></i>Salvar Nova Senha
                        </button>
                    </form>
                <?php endif; ?>

                <?php if (!isset($message) || $messageType !== 'success'): ?>
                    <div class="login-footer">
                        <a href="index.php"><i class="fas fa-arrow-left me-1"></i> Voltar para Login</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
        <?php if ($tokenValido): ?>
            const passwordMinLength = <?= (int)$passwordMinLength ?>;
            const passwordForm = document.getElementById('formNovaSenha');
            const passwordInput = document.getElementById('senha');
            const confirmInput = document.getElementById('confirmar_senha');
            const saveButton = document.getElementById('btnSalvar');
            const strengthBar = document.getElementById('passwordStrengthBar');
            const strengthLabel = document.getElementById('passwordStrengthLabel');
            const matchState = document.getElementById('passwordMatchState');

            function passwordRules(value) {
                return {
                    length: value.length >= passwordMinLength && value.length <= 128,
                    lower: /[a-z]/.test(value),
                    upper: /[A-Z]/.test(value),
                    number: /[0-9]/.test(value),
                    symbol: /[^a-zA-Z0-9]/.test(value)
                };
            }

            function updatePasswordFeedback() {
                const password = passwordInput.value;
                const confirmation = confirmInput.value;
                const rules = passwordRules(password);
                const completed = Object.values(rules).filter(Boolean).length;
                const allRulesPassed = completed === Object.keys(rules).length;
                const passwordsMatch = confirmation !== '' && password === confirmation;

                Object.entries(rules).forEach(([rule, passed]) => {
                    const item = document.querySelector(`[data-rule="${rule}"]`);
                    if (!item) return;
                    item.classList.toggle('is-valid', passed);
                    const icon = item.querySelector('i');
                    icon.className = passed ? 'fas fa-circle-check' : 'fas fa-circle';
                });

                strengthBar.style.width = `${completed * 20}%`;
                strengthBar.dataset.level = completed >= 5 ? 'strong' : (completed >= 3 ? 'medium' : 'weak');
                strengthLabel.textContent = password === ''
                    ? 'Comece a escrever a nova senha'
                    : (completed >= 5 ? 'Senha forte — todos os requisitos cumpridos' : (completed >= 3 ? 'Senha média — complete os requisitos restantes' : 'Senha fraca — ainda faltam requisitos'));

                matchState.classList.toggle('is-valid', passwordsMatch);
                matchState.classList.toggle('is-invalid', confirmation !== '' && !passwordsMatch);
                matchState.textContent = confirmation === ''
                    ? 'Repita a senha para confirmar'
                    : (passwordsMatch ? 'As senhas coincidem' : 'As senhas não coincidem');

                saveButton.disabled = !(allRulesPassed && passwordsMatch);
            }

            passwordInput.addEventListener('input', updatePasswordFeedback);
            confirmInput.addEventListener('input', updatePasswordFeedback);

            document.querySelectorAll('[data-password-toggle]').forEach((button) => {
                button.addEventListener('click', (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    const input = document.getElementById(button.dataset.passwordToggle);
                    if (!input) return;
                    const showing = input.type === 'text';
                    input.type = showing ? 'password' : 'text';
                    button.querySelector('i').className = showing ? 'fas fa-eye' : 'fas fa-eye-slash';
                    button.setAttribute('aria-label', showing ? 'Mostrar senha' : 'Ocultar senha');
                    button.setAttribute('aria-pressed', showing ? 'false' : 'true');
                });
            });

            passwordForm.addEventListener('submit', function(e) {
                const rules = passwordRules(passwordInput.value);
                if (!Object.values(rules).every(Boolean) || passwordInput.value !== confirmInput.value) {
                    e.preventDefault();
                    updatePasswordFeedback();
                }
            });
        <?php endif; ?>
    </script>
</body>

</html>
