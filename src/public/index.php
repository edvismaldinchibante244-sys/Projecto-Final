<?php
require_once __DIR__ . '/app_base.php';

// PÁGINA INICIAL - NIVORA RESTAURANT
include_once APP_BASE . '/src/config/security.php';
security_start_session();
security_set_headers();
security_regenerate_session(15);
?>
<!DOCTYPE html>
<html lang="pt">

<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nivora Restaurant — Sistema para restaurantes</title>
    <meta name="description" content="Nivora Restaurant: operação, vendas e crescimento do seu restaurante num só sistema.">
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,600;0,700;1,500&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/tokens.css?v=<?php echo (int)@filemtime(__DIR__ . '/css/tokens.css'); ?>">
    <link rel="stylesheet" href="css/auth-landing.css?v=<?php echo (int)@filemtime(__DIR__ . '/css/auth-landing.css'); ?>">
    <link rel="stylesheet" href="css/legal.css?v=20260808a">
</head>

<body class="edvistro-auth">

    <main class="ed-landing">
        <section class="ed-hero" aria-label="Nivora Restaurant">
            <div class="ed-hero__bg" role="img" aria-label="Restaurante em serviço"></div>
            <div class="ed-hero__shade" aria-hidden="true"></div>
            <div class="ed-hero__glow" aria-hidden="true"></div>

            <div class="ed-hero__frame">
                <div class="ed-hero__content">
                    <h1 class="ed-brand"><span>NIVORA</span><span>RESTAURANT</span></h1>
                    <p class="ed-promise">O seu restaurante,<br><em>no seu melhor momento.</em></p>
                    <p class="ed-support">Uma presença que o cliente sente — e uma operação que o dono controla.</p>
                    <div class="ed-cta-row">
                        <a href="solicitar_plano.php" class="ed-btn ed-btn--desire">Quero o meu restaurante assim</a>
                        <button type="button" class="ed-login-link" data-bs-toggle="modal" data-bs-target="#modalLogin">
                            Já tenho conta — Entrar
                        </button>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <?php $legalFooterCompact = true; include __DIR__ . '/includes/legal_footer.php'; ?>

    <!-- Modal Login -->
    <div class="modal fade login-modal" id="modalLogin" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <span class="modal-title-icon"><i class="fas fa-utensils"></i></span>
                        <span>Entrar na Nivora Restaurant</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <p class="modal-copy">Aceda ao painel com o email da conta do restaurante.</p>
                    <form id="formLogin">
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" placeholder="Email da conta" required autocomplete="username">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Senha</label>
                            <div class="password-field">
                                <input type="password" name="senha" id="loginPassword" class="form-control" placeholder="A sua palavra-passe" required autocomplete="current-password">
                                <button type="button" class="password-toggle" data-password-toggle="loginPassword" aria-label="Mostrar senha" aria-pressed="false">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mb-3" id="otpLoginGroup" style="display:none;">
                            <label class="form-label">Código de verificação</label>
                            <input type="text" id="otpLoginCode" class="form-control" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="Código de 6 dígitos">
                            <input type="hidden" id="twoFactorToken">
                        </div>
                        <div id="loginAlert" class="alert" style="display: none;"></div>
                        <button type="submit" class="btn btn-login text-white">
                            <i class="fas fa-sign-in-alt me-2"></i>Entrar
                        </button>
                    </form>
                    <div class="text-center mt-3">
                        <a href="#" class="forgot-link" data-bs-toggle="modal" data-bs-target="#modalEsqueciSenha">Esqueceu a senha?</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Esqueci a Senha -->
    <div class="modal fade login-modal" id="modalEsqueciSenha" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <span class="modal-title-icon"><i class="fas fa-key"></i></span>
                        <span>Recuperar senha</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <p class="modal-copy">Indique o email da conta para receber um link seguro de redefinição.</p>
                    <div id="emailForm">
                        <form id="formEsqueciSenha">
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" id="emailRecuperacao" class="form-control" placeholder="Email da conta" required>
                            </div>
                            <div id="esqueciAlert" class="alert" style="display: none;"></div>
                            <button type="submit" class="btn btn-recovery" id="btnEnviarLink">
                                <i class="fas fa-paper-plane me-2"></i>Enviar link de recuperação
                            </button>
                        </form>
                    </div>

                    <div id="timerContainer" class="recovery-success-state" style="display: none;">
                        <div class="recovery-status-icon">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <h5>Email enviado</h5>
                        <p>Se o email estiver cadastrado, enviámos um link de recuperação.</p>
                        <p>Confira também a pasta de spam.</p>
                        <div class="recovery-countdown-card">
                            <span class="recovery-countdown-label">Nova solicitação disponível em</span>
                            <span id="countdownTimer">60</span>
                            <span class="recovery-countdown-unit">segundos</span>
                        </div>
                        <hr class="recovery-divider">
                        <p class="recovery-note" id="recoveryHint">Use o link recebido por email para redefinir a senha com segurança.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
        // Exibir mensagem de sessão ao carregar a página (para fluxo de recuperação de senha)
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (isset($_SESSION['mensagem_login'])): ?>
                // Abrir modal de login e exibir mensagem
                var loginModal = new bootstrap.Modal(document.getElementById('modalLogin'));
                var alertDiv = document.getElementById('loginAlert');
                alertDiv.className = 'alert alert-warning';
                alertDiv.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i><?php echo addslashes($_SESSION['mensagem_login']); ?>';
                alertDiv.style.display = 'block';
                loginModal.show();
                <?php unset($_SESSION['mensagem_login']); ?>
            <?php endif; ?>
        });

        let recoveryTimer = null;

        // Função para iniciar o timer após enviar o email
        function startRecoveryTimer(data) {
            let seconds = 60;
            const countdownElement = document.getElementById('countdownTimer');
            const timerContainer = document.getElementById('timerContainer');
            const emailForm = document.getElementById('emailForm');
            const recoveryHint = document.getElementById('recoveryHint');
            data = data || {};

            var hintHtml = 'Use o link recebido por email para redefinir a senha com segurança.';
            if (data.warning) {
                hintHtml = '<strong>Aviso:</strong> ' + String(data.warning).replace(/</g, '&lt;') + '<br>' + hintHtml;
            }
            if (data.debug_link) {
                hintHtml += '<br><br><a class="btn btn-recovery" style="display:inline-block;margin-top:8px;" href="' +
                    String(data.debug_link).replace(/"/g, '&quot;') +
                    '">Abrir link de recuperação agora</a>';
            }
            recoveryHint.innerHTML = hintHtml;

            // Ocultar formulário de email e mostrar timer
            emailForm.style.display = 'none';
            timerContainer.style.display = 'block';

            if (data.email_sent === false) {
                var title = timerContainer.querySelector('h5');
                if (title) {
                    title.textContent = data.debug_link ? 'Link pronto (email não enviado)' : 'Pedido registado';
                }
            }

            recoveryTimer = setInterval(function() {
                seconds--;
                countdownElement.textContent = seconds;

                if (seconds <= 0) {
                    clearInterval(recoveryTimer);
                    // Voltar ao formulário de email
                    timerContainer.style.display = 'none';
                    emailForm.style.display = 'block';
                    document.getElementById('formEsqueciSenha').reset();
                    recoveryHint.textContent = 'Use o link recebido por email para redefinir a senha com segurança.';
                    var titleReset = timerContainer.querySelector('h5');
                    if (titleReset) {
                        titleReset.textContent = 'Email enviado';
                    }
                    // Re-activar botão para nova tentativa
                    var btnReativar = document.getElementById('btnEnviarLink');
                    if (btnReativar) {
                        btnReativar.disabled = false;
                        btnReativar.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Enviar Link de Recuperação';
                    }
                }
            }, 1000);
        }

        // Limpar estado quando o modal é fechado
        document.getElementById('modalEsqueciSenha').addEventListener('hidden.bs.modal', function() {
            if (recoveryTimer) {
                clearInterval(recoveryTimer);
                recoveryTimer = null;
            }
            // Resetar visualização
            document.getElementById('emailForm').style.display = 'block';
            document.getElementById('timerContainer').style.display = 'none';
            document.getElementById('countdownTimer').textContent = '60';
            document.getElementById('formEsqueciSenha').reset();
            document.getElementById('esqueciAlert').style.display = 'none';
            document.getElementById('recoveryHint').textContent = 'Use o link recebido por email para redefinir a senha com segurança.';
            // Garantir que o botão está sempre desbloqueado ao fechar/reabrir o modal
            var btnReset = document.getElementById('btnEnviarLink');
            if (btnReset) {
                btnReset.disabled = false;
                btnReset.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Enviar Link de Recuperação';
            }
        });

        // Enviar email de recuperação
        document.getElementById('formEsqueciSenha').addEventListener('submit', function(e) {
            e.preventDefault();

            var formData = new FormData(this);
            var alertDiv = document.getElementById('esqueciAlert');
            var btn = document.getElementById('btnEnviarLink');
            var email = document.getElementById('emailRecuperacao').value;

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Enviando...';

            // Usar a API correta para enviar email de recuperação
            fetch('api/esqueci_senha.php', {
                    method: 'POST',
                    body: formData
                })
                .then(async (response) => {
                    const raw = await response.text();
                    try {
                        const data = JSON.parse(raw);
                        if (!response.ok && data && data.message) {
                            return data;
                        }
                        return data;
                    } catch (e) {
                        throw new Error('Resposta inválida do servidor. Verifique o log do PHP para detalhes.');
                    }
                })
                .then(data => {
                    if (data.success) {
                        startRecoveryTimer(data);
                        if (data.message) {
                            alertDiv.className = data.email_sent === false ? 'alert alert-warning' : 'alert alert-success';
                            alertDiv.textContent = data.message + (data.warning ? (' ' + data.warning) : '');
                            alertDiv.style.display = 'block';
                        }
                    } else {
                        alertDiv.className = 'alert alert-danger';
                        alertDiv.textContent = data.message || 'Erro ao enviar email';
                        alertDiv.style.display = 'block';
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Enviar Link de Recuperação';
                    }
                })
                .catch(err => {
                    alertDiv.className = 'alert alert-danger';
                    alertDiv.textContent = 'Não foi possível enviar o link de recuperação. Tente novamente.';
                    alertDiv.style.display = 'block';
                    if (err && err.message) {
                        alertDiv.textContent = 'Nao foi possivel enviar o link de recuperacao. ' + err.message;
                    }
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Enviar Link de Recuperação';
                });
        });

        function formatBlockedUntil(value) {
            if (!value) {
                return '';
            }
            const normalized = String(value).replace(' ', 'T');
            const date = new Date(normalized);
            if (Number.isNaN(date.getTime())) {
                return value;
            }
            return date.toLocaleString('pt-PT', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        function renderLoginAlert(alertDiv, level, message, icon) {
            alertDiv.className = 'alert ' + level;
            alertDiv.innerHTML = '<i class="' + (icon || 'fas fa-circle-info') + ' me-2"></i>' + message;
            alertDiv.style.display = 'block';
        }

        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('[data-password-toggle]').forEach(function(button) {
                button.addEventListener('click', function() {
                    var input = document.getElementById(button.getAttribute('data-password-toggle'));
                    var icon = button.querySelector('i');
                    if (!input) {
                        return;
                    }

                    var show = input.type === 'password';
                    input.type = show ? 'text' : 'password';
                    button.setAttribute('aria-pressed', show ? 'true' : 'false');
                    button.setAttribute('aria-label', show ? 'Ocultar senha' : 'Mostrar senha');
                    if (icon) {
                        icon.className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
                    }
                    input.focus();
                });
            });

            const loginForm = document.getElementById('formLogin');
            if (!loginForm) {
                return;
            }

            loginForm.addEventListener('submit', function(e) {
                e.preventDefault();

                var formData = new FormData(this);
                var alertDiv = document.getElementById('loginAlert');
                var otpGroup = document.getElementById('otpLoginGroup');
                var otpInput = document.getElementById('otpLoginCode');
                var twoFactorToken = document.getElementById('twoFactorToken');
                var submitBtn = this.querySelector('button[type="submit"]');
                var isOtpVerification = Boolean(twoFactorToken.value);
                var redirecting = false;

                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>' +
                    (isOtpVerification ? 'A verificar código...' : 'A validar acesso...');
                renderLoginAlert(
                    alertDiv,
                    'alert-info',
                    isOtpVerification ?
                    'A confirmar o código de segurança...' :
                    'A validar os dados e preparar o código de segurança...',
                    'fas fa-shield-halved'
                );

                fetch('api/login_process.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(function(response) {
                        return response.text().then(function(text) {
                            return {
                                ok: response.ok,
                                status: response.status,
                                text: text
                            };
                        });
                    })
                    .then(function(result) {
                        if (!result.text || result.text.trim() === '') {
                            throw new Error('Resposta vazia do servidor');
                        }

                        var data = JSON.parse(result.text);
                        if (!result.ok && data && data.message) {
                            throw new Error(data.message);
                        }
                        if (!result.ok) {
                            throw new Error('Erro na conexao: ' + result.status);
                        }
                        if (data.success) {
                            redirecting = true;
                            window.location.assign(data.redirect || 'dashboard.php');
                            return;
                        }

                        if (data.requires_2fa) {
                            twoFactorToken.value = data.two_factor_token || '';
                            twoFactorToken.name = 'two_factor_token';
                            otpInput.name = 'otp_code';
                            otpGroup.style.display = 'block';
                            otpInput.required = true;
                            otpInput.focus();

                            renderLoginAlert(
                                alertDiv,
                                'alert-warning',
                                data.debug_otp ?
                                ((data.message || 'Informe o codigo de verificacao.') + ' Codigo local: ' + data.debug_otp) :
                                (data.message || 'Informe o codigo de verificacao.'),
                                'fas fa-shield-halved'
                            );
                            return;
                        }

                        if (data.requires_2fa_restart) {
                            twoFactorToken.value = '';
                            twoFactorToken.name = '';
                            otpInput.value = '';
                            otpInput.name = '';
                            otpInput.required = false;
                            otpGroup.style.display = 'none';
                            renderLoginAlert(alertDiv, 'alert-warning', data.message || 'Sessao de verificacao expirada. Faca login novamente.', 'fas fa-exclamation-triangle');
                            return;
                        }

                        var message = data.message || 'Erro ao fazer login';
                        var level = 'alert-danger';
                        var icon = 'fas fa-circle-xmark';

                        if (data.reason === 'rate_limited') {
                            level = 'alert-warning';
                            icon = 'fas fa-hourglass-half';
                        } else if (data.reason === 'account_blocked') {
                            level = 'alert-warning';
                            icon = 'fas fa-lock';
                            if (data.blocked_until) {
                                message += ' Tente novamente depois de ' + formatBlockedUntil(data.blocked_until) + '.';
                            }
                        } else if (data.reason === 'invalid_credentials') {
                            message += ' Se necessario, use "Esqueceu a senha?" para redefinir o acesso.';
                        }

                        renderLoginAlert(alertDiv, level, message, icon);
                    })
                    .catch(function(error) {
                        renderLoginAlert(alertDiv, 'alert-danger', 'Nao foi possivel processar o login. ' + (error && error.message ? error.message : 'Tente novamente.'), 'fas fa-circle-xmark');
                    })
                    .finally(function() {
                        if (redirecting) {
                            return;
                        }
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="fas fa-sign-in-alt me-2"></i>Entrar';
                    });
            });
        });
    </script>
</body>

</html>
