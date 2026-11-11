/**
 * JavaScript do login (Bootstrap 5)
 */

document.addEventListener('DOMContentLoaded', function () {
    const loginForm = document.getElementById('formLogin');
    const alertDiv = document.getElementById('loginAlert');

    if (!loginForm) {
        console.error('Formulario de login nao encontrado.');
        return;
    }

    loginForm.addEventListener('submit', function (e) {
        e.preventDefault();

        const emailInput = document.querySelector('input[name="email"]');
        const senhaInput = document.querySelector('input[name="senha"]');
        const otpInput = document.getElementById('otpLoginCode');
        const otpToken = document.getElementById('twoFactorToken');

        const email = emailInput ? emailInput.value : '';
        const senha = senhaInput ? senhaInput.value : '';

        if (!email || !senha) {
            showAlert('Preencha todos os campos.', 'danger');
            return;
        }

        const formData = new FormData();
        formData.append('email', email);
        formData.append('senha', senha);
        if (otpToken && otpToken.value) {
            formData.append('two_factor_token', otpToken.value);
            formData.append('otp_code', otpInput ? otpInput.value : '');
        }

        fetch('api/login_simples.php', {
            method: 'POST',
            body: formData
        })
            .then(response => response.text().then(text => ({
                ok: response.ok,
                status: response.status,
                text: text
            })))
            .then(result => {
                let data;

                try {
                    data = JSON.parse(result.text);
                } catch (e) {
                    throw new Error('Resposta invalida do servidor. Verifique o log PHP da hospedagem.');
                }

                if (!result.ok && data && data.message) {
                    throw new Error(data.message);
                }
                if (!result.ok) {
                    throw new Error('Erro HTTP ' + result.status + '.');
                }

                if (data.success) {
                    showAlert(data.message || 'Login realizado com sucesso.', 'success');
                    setTimeout(() => {
                        window.location.href = data.redirect || 'dashboard.php';
                    }, 1000);
                    return;
                }

                if (data.requires_2fa) {
                    if (otpToken) {
                        otpToken.value = data.two_factor_token || '';
                    }
                    if (otpInput) {
                        const group = otpInput.closest('.mb-3');
                        if (group) {
                            group.style.display = 'block';
                        }
                        otpInput.required = true;
                        otpInput.focus();
                    }
                    showAlert(data.debug_otp
                        ? ((data.message || 'Informe o codigo de verificacao.') + ' Codigo local: ' + data.debug_otp)
                        : (data.message || 'Informe o codigo de verificacao.'), 'info');
                    return;
                }

                showAlert(data.message || 'Nao foi possivel processar o login.', 'danger');
            })
            .catch(error => {
                showAlert('Nao foi possivel processar o login. ' + (error && error.message ? error.message : 'Tente novamente.'), 'danger');
            });
    });

    function showAlert(message, type) {
        alertDiv.textContent = message;
        alertDiv.className = 'alert alert-' + type;
        alertDiv.style.display = 'block';
        setTimeout(() => {
            alertDiv.style.display = 'none';
        }, 5000);
    }
});
