<?php
require_once __DIR__ . '/app_base.php';
require_once APP_BASE . '/src/config/security.php';

/*
   Página para criar o primeiro usuário admin do restaurante
   Acessível apenas após login temporário com senha do restaurante
 */

security_start_session();
security_set_headers();
include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/csrf.php';

if (!isset($_SESSION['logado']) || !isset($_SESSION['login_restaurante_temp']) || $_SESSION['login_restaurante_temp'] != true) {
    header('Location: index.php');
    exit;
}

$database = new Database();
$db = $database->getConnection();

$restaurante_nome = $_SESSION['nome'] ?? 'Restaurante';
$restaurante_email = $_SESSION['email'] ?? '';
$restaurante_id = $_SESSION['restaurante_id'] ?? 0;
$csrfToken = csrf_get_token();

?>
<!DOCTYPE html>
<html lang="pt">

<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Criar Primeiro Admin - RestauranteSaaS</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        :root {
            --primary: #FF6B35;
            --primary-dark: #e55a2b;
            --secondary: #F7931E;
            --dark: #0f0f23;
            --dark-2: #1a1a2e;
            --dark-3: #16213e;
            --gold: #D4AF37;
            --gold-light: #F4E4BA;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --text: #1e293b;
            --text-light: #64748b;
            --text-muted: #94a3b8;
            --bg: #f8fafc;
            --border: #e2e8f0;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background: linear-gradient(135deg, var(--bg) 0%, var(--primary) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: var(--shadow-lg);
            padding: 40px;
            width: 100%;
            max-width: 500px;
            position: relative;
            overflow: hidden;
        }

        .login-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
        }

        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .login-header h1 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 28px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 8px;
        }

        .login-header p {
            color: var(--text-light);
            font-size: 16px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            font-weight: 600;
            color: var(--text);
            margin-bottom: 8px;
            display: block;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--border);
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.3s;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.1);
            outline: none;
        }

        .btn-primary-custom {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border: none;
            border-radius: 12px;
            color: white;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255, 107, 53, 0.4);
        }

        .alert {
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .welcome-message {
            background: linear-gradient(135deg, var(--success), #34d399);
            color: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            text-align: center;
        }

        .welcome-message i {
            font-size: 24px;
            margin-bottom: 10px;
        }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="welcome-message">
            <i class="fas fa-check-circle"></i>
            <h4>Bem-vindo ao RestauranteSaaS!</h4>
            <p>Seu plano foi aprovado. Agora crie seu primeiro usuário administrador.</p>
        </div>

        <div class="login-header">
            <h1>Criar Primeiro Admin</h1>
            <p>Configure a conta principal do restaurante</p>
        </div>

        <div id="alertContainer"></div>

        <form id="formCriarAdmin">
            <div class="form-group">
                <label class="form-label">Nome Completo</label>
                <input type="text" class="form-control" id="nome" required placeholder="Nome completo">
            </div>

            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" class="form-control" id="email" value="<?php echo htmlspecialchars($restaurante_email); ?>" readonly>
                <small class="text-muted">Este email será usado para fazer login</small>
            </div>

            <div class="form-group">
                <label class="form-label">Senha</label>
                <input type="password" class="form-control" id="senha" required minlength="12" placeholder="Nova senha (mín. 12 · maiúscula, número e símbolo)">
                <small class="text-muted">Mínimo 6 caracteres</small>
            </div>

            <div class="form-group">
                <label class="form-label">Confirmar Senha</label>
                <input type="password" class="form-control" id="confirmar_senha" required minlength="12" placeholder="Confirme a nova senha">
            </div>

            <button type="submit" class="btn-primary-custom">
                <i class="fas fa-user-plus me-2"></i> Criar Conta Admin
            </button>
        </form>

        <div class="text-center mt-3">
            <a href="logout.php" class="text-muted">
                <i class="fas fa-sign-out-alt me-1"></i> Sair
            </a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        document.getElementById('formCriarAdmin').addEventListener('submit', function(e) {
            e.preventDefault();

            const nome = document.getElementById('nome').value.trim();
            const email = document.getElementById('email').value.trim();
            const senha = document.getElementById('senha').value;
            const confirmarSenha = document.getElementById('confirmar_senha').value;

            // Validações
            if (!nome) {
                showAlert('Informe o nome completo', 'danger');
                return;
            }

            if (senha.length < 12 || !/[A-Z]/.test(senha) || !/[a-z]/.test(senha) || !/[0-9]/.test(senha) || !/[^A-Za-z0-9]/.test(senha)) {
                showAlert('A senha deve ter no mínimo 12 caracteres, com maiúscula, minúscula, número e símbolo', 'danger');
                return;
            }

            if (senha !== confirmarSenha) {
                showAlert('As senhas não coincidem', 'danger');
                return;
            }

            // Enviar dados
            fetch('api/criar_primeiro_admin.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'nome=' + encodeURIComponent(nome) +
                        '&email=' + encodeURIComponent(email) +
                        '&senha=' + encodeURIComponent(senha) +
                        '&_csrf=' + encodeURIComponent(<?php echo json_encode($csrfToken, JSON_UNESCAPED_UNICODE); ?>),
                    credentials: 'same-origin'
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showAlert(data.message, 'success');
                        setTimeout(() => {
                            window.location.href = 'dashboard.php';
                        }, 2000);
                    } else {
                        showAlert(data.message, 'danger');
                    }
                })
                .catch(error => {
                    showAlert('Erro ao processar solicitação', 'danger');
                });
        });

        function showAlert(message, type) {
            const alertContainer = document.getElementById('alertContainer');
            alertContainer.innerHTML = `
                <div class="alert alert-${type}" role="alert">
                    <i class="fas fa-info-circle me-2"></i>${escapeHtml(message)}
                </div>
            `;
        }
    </script>
    <script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
        (() => {
            const logoutLinks = document.querySelectorAll('a[href="logout.php"]');
            if (!logoutLinks.length) {
                return;
            }

            const markOffline = () => {
                const url = 'api/online_offline.php';

                try {
                    if (navigator.sendBeacon) {
                        const data = new FormData();
                        data.append('source', 'logout');
                        navigator.sendBeacon(url, data);
                        return;
                    }
                } catch (e) {}

                try {
                    fetch(url, {
                        method: 'POST',
                        credentials: 'same-origin',
                        keepalive: true,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                } catch (e) {}
            };

            logoutLinks.forEach((link) => {
                link.addEventListener('click', markOffline, {
                    passive: true
                });
            });
        })();
    </script>
</body>

</html>
