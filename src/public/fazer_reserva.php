<?php
require_once __DIR__ . '/app_base.php';
require_once APP_BASE . '/src/config/security.php';
// Pagina publica para clientes fazerem reservas
// Endpoint: /src/public/fazer_reserva.php
require_once APP_BASE . '/src/api/reserva_publica_handler.php';

security_set_headers();

$db = (new Database())->getConnection();
$stmt = $db->query("SELECT id, nome, cidade FROM restaurantes ORDER BY nome ASC");
$restaurantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$dados = [
    'restaurante_id' => $_POST['restaurante_id'] ?? '',
    'nome_cliente' => trim($_POST['nome_cliente'] ?? ''),
    'email_cliente' => trim($_POST['email_cliente'] ?? ''),
    'telefone_cliente' => trim($_POST['telefone_cliente'] ?? ''),
    'data_reserva' => $_POST['data_reserva'] ?? '',
    'hora_reserva' => $_POST['hora_reserva'] ?? '',
    'quantidade_pessoas' => (int)($_POST['quantidade_pessoas'] ?? 1),
    'observacoes' => trim($_POST['observacoes'] ?? ''),
];

$mensagem = null;
$erro = null;
$semRestaurantes = empty($restaurantes);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!security_rate_limit('fazer_reserva_criar', 90, 60)) {
        http_response_code(429);
        $erro = 'Muitas tentativas de reserva. Tente novamente em instantes.';
    } elseif (!empty($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > (256 * 1024)) {
        http_response_code(413);
        $erro = 'Formulario muito grande.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $erro === null) {
    $resultado = criar_reserva_publica($dados, $db);

    if (!empty($resultado['success'])) {
        $mensagem = $resultado['message'] ?? 'Reserva enviada com sucesso! Aguarde confirmacao.';
        $dados = [
            'restaurante_id' => '',
            'nome_cliente' => '',
            'email_cliente' => '',
            'telefone_cliente' => '',
            'data_reserva' => '',
            'hora_reserva' => '',
            'quantidade_pessoas' => 1,
            'observacoes' => '',
        ];
    } else {
        $erro = $resultado['message'] ?? 'Erro ao enviar reserva.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt">

<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <title>Reservar Mesa | RestauranteSaaS</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --primary: #FF7A29;
            /* Laranja mais vibrante */
            --secondary: #FFB347;
            /* Amarelo alaranjado */
            --glass-bg: rgba(34, 40, 49, 0.68);
            --glass-border: rgba(255, 255, 255, 0.22);
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: radial-gradient(ellipse at 60% 40%, #23243a 0%, #1a1a2e 60%, #0f3460 100%);
            min-height: 100vh;
            background-attachment: fixed;
            overflow-x: hidden;
        }

        .reserva-section {
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            position: relative;
            gap: 28px;
            flex-wrap: wrap;
            padding: 40px 16px 28px;
        }

        .reserva-card {
            background: var(--glass-bg);
            border: 1.5px solid var(--glass-border);
            backdrop-filter: blur(22px) saturate(120%);
            -webkit-backdrop-filter: blur(22px) saturate(120%);
            border-radius: 32px;
            box-shadow: 0 12px 48px 0 rgba(255, 122, 41, 0.18), 0 2px 12px 0 rgba(0, 0, 0, 0.13);
            padding: 54px 38px 36px 38px;
            max-width: 440px;
            width: 100%;
            margin: 0 auto;
            animation: fadeInUp 0.7s cubic-bezier(.23, 1.02, .32, 1) 0s;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(40px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .brand-logo {
            width: 84px;
            height: 84px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            color: white;
            margin: 0 auto 24px;
            box-shadow: 0 6px 28px 0 rgba(255, 122, 41, 0.22);
        }

        .main-title {
            font-size: 2.2rem;
            font-weight: 700;
            background: linear-gradient(135deg, #fff, var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 8px;
            text-align: center;
            letter-spacing: 0.5px;
            position: relative;
        }

        .main-title::after {
            content: '';
            display: block;
            width: 60px;
            height: 3px;
            margin: 10px auto 0 auto;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            border-radius: 2px;
            animation: underlineGrow 1.2s cubic-bezier(.23, 1.02, .32, 1);
        }

        @keyframes underlineGrow {
            from {
                width: 0;
            }

            to {
                width: 60px;
            }
        }

        .subtitle {
            color: rgba(255, 255, 255, 0.78);
            font-size: 1.13rem;
            margin-bottom: 28px;
            text-align: center;
        }

        .form-label,
        label {
            color: #fff;
            font-weight: 500;
            margin-bottom: 4px;
        }

        .input-group-text {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: #fff;
            border: none;
            border-radius: 8px 0 0 8px;
            font-size: 1.1rem;
        }

        .form-control,
        .form-select,
        textarea {
            border-radius: 0 10px 10px 0;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            background: rgba(255, 255, 255, 0.13);
            color: #fff;
            transition: box-shadow 0.2s, border-color 0.2s;
        }

        /* Corrige visual do dropdown do select */
        .form-select option {
            background: #fff;
            color: #222831;
        }

        .form-select:focus option:checked {
            background: var(--primary);
            color: #fff;
        }

        .form-control:focus,
        .form-select:focus,
        textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.13);
            background: rgba(255, 255, 255, 0.22);
            color: #fff;
        }

        .input-group {
            margin-bottom: 18px;
        }

        .btn-reservar {
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            border: none;
            border-radius: 16px;
            padding: 16px 0;
            font-weight: 700;
            width: 100%;
            color: #fff;
            margin-top: 12px;
            font-size: 1.13rem;
            box-shadow: 0 3px 16px 0 rgba(255, 122, 41, 0.16);
            transition: background 0.3s, box-shadow 0.2s, transform 0.2s;
            letter-spacing: 0.5px;
        }

        .btn-reservar:hover {
            background: linear-gradient(90deg, var(--secondary), var(--primary));
            box-shadow: 0 6px 28px 0 rgba(255, 122, 41, 0.24);
            transform: translateY(-2px) scale(1.04);
        }

        .alert {
            border-radius: 10px;
            font-size: 1rem;
        }

        .text-center.mt-3 a {
            color: var(--primary);
            text-decoration: underline;
            font-size: 0.97rem;
            transition: color 0.2s;
        }

        .text-center.mt-3 a:hover {
            color: var(--secondary);
        }

        .availability-hint {
            min-height: 24px;
            margin: -4px 0 18px;
            font-size: 0.95rem;
            color: rgba(255, 255, 255, 0.8);
        }

        .menu-preview-card {
            background: rgba(15, 23, 42, 0.82);
            border: 1.5px solid rgba(255, 255, 255, 0.16);
            border-radius: 28px;
            margin: 0 auto;
            padding: 28px 24px 24px;
            max-width: 440px;
            width: 100%;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.22);
            max-height: min(78vh, 760px);
            overflow: hidden;
            position: sticky;
            top: 24px;
            align-self: flex-start;
            display: flex;
            flex-direction: column;
        }

        @media (max-width: 1100px) {
            body {
                background-attachment: scroll;
            }

            .reserva-section {
                flex-direction: column;
                align-items: stretch;
                padding: 28px 14px 22px;
            }

            .menu-preview-card,
            .reserva-card {
                margin: 0 auto;
                max-width: 640px;
            }

            .reserva-card {
                padding: 42px 28px 28px;
            }

            .menu-preview-card {
                padding: 24px 20px 20px;
            }

            .menu-preview-card {
                position: static;
                max-height: none;
            }
        }

        @media (max-width: 768px) {
            .reserva-section {
                padding: 24px 12px 20px;
                gap: 20px;
            }

            .reserva-card,
            .menu-preview-card {
                max-width: 100%;
                padding-left: 20px;
                padding-right: 20px;
            }

            .brand-logo {
                width: 76px;
                height: 76px;
                font-size: 36px;
                margin-bottom: 20px;
            }

            .main-title {
                font-size: 1.55rem;
            }

            .subtitle {
                font-size: 0.95rem;
            }

            .menu-preview-title {
                font-size: 1rem;
            }

            .menu-preview-subtitle {
                font-size: 0.9rem;
            }

            .menu-item-thumb {
                width: 68px;
                height: 68px;
                flex-basis: 68px;
            }
        }

        @media (max-width: 600px) {
            .menu-preview-card,
            .reserva-card {
                padding: 18px 6vw;
                max-width: 98vw;
            }

            .reserva-section {
                padding: 12px 0 18px;
                gap: 18px;
            }

            .menu-preview-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .menu-preview-link {
                white-space: normal;
            }

            .menu-item {
                gap: 10px;
            }

            .menu-item-top {
                flex-direction: column;
                gap: 4px;
            }

            .menu-item-price {
                white-space: normal;
            }
        }

        .menu-preview-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 18px;
            padding-bottom: 16px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            flex: 0 0 auto;
        }

        .menu-preview-title {
            color: #fff;
            font-size: 1.15rem;
            font-weight: 700;
            margin: 0;
        }

        .menu-preview-subtitle {
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.95rem;
            margin: 4px 0 0;
        }

        .menu-preview-link {
            color: #ffd8a8;
            font-size: 0.92rem;
            text-decoration: none;
            white-space: nowrap;
        }

        .menu-preview-link:hover {
            color: #fff;
        }

        .menu-preview-state {
            color: rgba(255, 255, 255, 0.72);
            font-size: 0.95rem;
            margin: 0;
        }

        .menu-preview-body {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            padding-right: 6px;
            scroll-behavior: smooth;
            scrollbar-width: thin;
            scrollbar-color: rgba(255, 179, 71, 0.42) transparent;
        }

        .menu-preview-body::-webkit-scrollbar {
            width: 8px;
        }

        .menu-preview-body::-webkit-scrollbar-track {
            background: transparent;
        }

        .menu-preview-body::-webkit-scrollbar-thumb {
            background: rgba(255, 179, 71, 0.36);
            border-radius: 999px;
        }

        .menu-preview-body::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 179, 71, 0.58);
        }

        .menu-category {
            margin-top: 18px;
        }

        .menu-category-title {
            color: #ffd8a8;
            font-size: 0.98rem;
            font-weight: 700;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .menu-item {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 12px 14px;
            margin-bottom: 10px;
            display: flex;
            align-items: flex-start;
            gap: 14px;
        }

        .menu-item-thumb {
            position: relative;
            width: 76px;
            height: 76px;
            flex: 0 0 76px;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.12);
            background: rgba(255, 255, 255, 0.06);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.06);
        }

        .menu-item-thumb-placeholder,
        .menu-item-thumb img {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
        }

        .menu-item-thumb img {
            object-fit: cover;
            display: block;
            z-index: 2;
        }

        .menu-item-thumb-placeholder {
            display: flex;
            align-items: center;
            justify-content: center;
            color: rgba(255, 255, 255, 0.88);
            font-size: 1.15rem;
            background: radial-gradient(circle at top, rgba(255, 107, 53, 0.55), rgba(15, 23, 42, 0.92));
        }

        .menu-item-body {
            flex: 1;
            min-width: 0;
        }

        .menu-item-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }

        .menu-item-name {
            color: #fff;
            font-weight: 600;
            margin: 0;
            line-height: 1.3;
        }

        .menu-item-price {
            color: #ffe4c4;
            font-weight: 700;
            white-space: nowrap;
        }

        .menu-item-desc {
            color: rgba(255, 255, 255, 0.68);
            font-size: 0.9rem;
            margin: 6px 0 0;
            line-height: 1.45;
        }

        @media (max-width: 600px) {
            .reserva-card {
                padding: 22px 16px 14px;
            }

            .main-title {
                font-size: 1.35rem;
            }

            .menu-preview-card {
                padding: 20px 16px;
            }

            .menu-item {
                gap: 12px;
            }

            .menu-item-thumb {
                width: 64px;
                height: 64px;
                flex-basis: 64px;
            }

            .menu-preview-title {
                font-size: 1.02rem;
            }

            .menu-preview-subtitle {
                font-size: 0.88rem;
            }
        }
    </style>
</head>

<body>
    <div class="reserva-section">
        <div class="reserva-card">
            <div class="brand-logo mb-2"><i class="fas fa-calendar-check"></i></div>
            <h2 class="main-title">Reservar Mesa</h2>
            <div class="subtitle">Faça sua reserva online em qualquer restaurante da plataforma</div>
            <?php if ($mensagem): ?>
                <div class="alert alert-success text-center mb-3"><i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($mensagem) ?></div>
            <?php elseif ($erro): ?>
                <div class="alert alert-danger text-center mb-3"><i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($erro) ?></div>
            <?php endif; ?>
            <?php if ($semRestaurantes): ?>
                <div class="alert alert-warning text-center mb-3"><i class="fas fa-store-slash me-2"></i>Nenhum restaurante disponivel para reserva no momento.</div>
            <?php endif; ?>
            <form method="post" id="formReservaPublica">
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-store"></i></span>
                    <select id="restaurante_id" name="restaurante_id" class="form-select" required <?= $semRestaurantes ? 'disabled' : '' ?>>
                        <option value="">Selecione o restaurante...</option>
                        <?php foreach ($restaurantes as $r): ?>
                            <option value="<?= htmlspecialchars((string)$r['id']) ?>" <?= (string)$dados['restaurante_id'] === (string)$r['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($r['nome']) ?> (<?= htmlspecialchars($r['cidade']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="reserva_disponibilidade_publica" class="availability-hint">Selecione restaurante, data, hora e quantidade para ver a disponibilidade.</div>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-user"></i></span>
                    <input type="text" name="nome_cliente" class="form-control" placeholder="Nome completo" required value="<?= htmlspecialchars($dados['nome_cliente']) ?>">
                </div>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                    <input type="email" name="email_cliente" class="form-control" placeholder="Email" value="<?= htmlspecialchars($dados['email_cliente']) ?>">
                </div>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-phone"></i></span>
                    <input type="text" name="telefone_cliente" class="form-control" placeholder="Telefone" value="<?= htmlspecialchars($dados['telefone_cliente']) ?>">
                </div>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-calendar-day"></i></span>
                    <input type="date" id="data_reserva" name="data_reserva" class="form-control" required min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($dados['data_reserva']) ?>">
                </div>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-clock"></i></span>
                    <input type="time" id="hora_reserva" name="hora_reserva" class="form-control" required value="<?= htmlspecialchars($dados['hora_reserva']) ?>">
                </div>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-users"></i></span>
                    <input type="number" id="quantidade_pessoas" name="quantidade_pessoas" class="form-control" min="1" max="20" value="<?= htmlspecialchars((string)$dados['quantidade_pessoas']) ?>" required placeholder="N.º de pessoas">
                </div>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-comment-dots"></i></span>
                    <textarea name="observacoes" class="form-control" placeholder="Observações da reserva"><?= htmlspecialchars($dados['observacoes']) ?></textarea>
                </div>
                <button id="btnReservarPublico" class="btn btn-reservar mt-2" <?= $semRestaurantes ? 'disabled' : '' ?>><i class="fas fa-calendar-plus me-2"></i>Reservar</button>
            </form>
            <div class="text-center mt-3">
                <a href="index.php" style="color: var(--primary); text-decoration: underline; font-size: 0.95rem;"><i class="fas fa-arrow-left me-1"></i>Voltar para início</a>
            </div>
        </div>
        <div id="cardapioPreviewCard" class="menu-preview-card">
            <div class="menu-preview-header">
                <div>
                    <h3 class="menu-preview-title">Cardapio Em Destaque</h3>
                    <p id="cardapioPreviewSubtitle" class="menu-preview-subtitle">Escolha um restaurante para visualizar alguns pratos e bebidas.</p>
                </div>
                <a id="cardapioPreviewLink" class="menu-preview-link d-none" href="#" target="_blank" rel="noopener noreferrer">Ver completo</a>
            </div>
            <div id="cardapioPreviewScroller" class="menu-preview-body">
                <div id="cardapioPreviewContent">
                    <p class="menu-preview-state">Nenhum restaurante selecionado.</p>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('formReservaPublica');
            const restauranteField = document.getElementById('restaurante_id');
            const dataField = document.getElementById('data_reserva');
            const horaField = document.getElementById('hora_reserva');
            const quantidadeField = document.getElementById('quantidade_pessoas');
            const hint = document.getElementById('reserva_disponibilidade_publica');
            const submitButton = document.getElementById('btnReservarPublico');
            const cardapioSubtitle = document.getElementById('cardapioPreviewSubtitle');
            const cardapioLink = document.getElementById('cardapioPreviewLink');
            const cardapioContent = document.getElementById('cardapioPreviewContent');
            const cardapioScroller = document.getElementById('cardapioPreviewScroller');
            const semRestaurantes = <?= $semRestaurantes ? 'true' : 'false' ?>;
            const apiBase = 'api/reserva_publica.php';
            const preferePoucoMovimento = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            let podeSubmeter = !semRestaurantes;
            let requestSeq = 0;
            let cardapioSeq = 0;
            let autoScrollTimer = null;

            if (!form || !restauranteField || !dataField || !horaField || !quantidadeField || !hint || !submitButton || !cardapioSubtitle || !cardapioLink || !cardapioContent || !cardapioScroller) {
                return;
            }

            function pararAutoScrollCardapio() {
                if (autoScrollTimer !== null) {
                    window.clearInterval(autoScrollTimer);
                    autoScrollTimer = null;
                }
            }

            function atualizarAutoScrollCardapio() {
                pararAutoScrollCardapio();

                if (preferePoucoMovimento) {
                    return;
                }

                const excesso = cardapioScroller.scrollHeight - cardapioScroller.clientHeight;
                if (excesso <= 24) {
                    return;
                }

                autoScrollTimer = window.setInterval(function() {
                    const limite = cardapioScroller.scrollHeight - cardapioScroller.clientHeight;
                    if (limite <= 0) {
                        pararAutoScrollCardapio();
                        return;
                    }

                    if (cardapioScroller.scrollTop >= limite - 4) {
                        cardapioScroller.scrollTo({
                            top: 0,
                            behavior: 'smooth'
                        });
                        return;
                    }

                    cardapioScroller.scrollBy({
                        top: Math.min(96, limite),
                        behavior: 'smooth'
                    });
                }, 3200);
            }

            function redefinirScrollCardapio() {
                pararAutoScrollCardapio();
                cardapioScroller.scrollTop = 0;
                window.requestAnimationFrame(atualizarAutoScrollCardapio);
            }

            function atualizarHint(mensagem, tom) {
                const classes = {
                    success: 'text-success',
                    warning: 'text-warning',
                    danger: 'text-danger',
                    info: 'text-info',
                    muted: ''
                };

                hint.textContent = mensagem;
                hint.className = 'availability-hint ' + (classes[tom] || '');
            }

            function atualizarBotao() {
                submitButton.disabled = semRestaurantes || !podeSubmeter;
            }

            function formatarPreco(valor) {
                return Number(valor || 0).toLocaleString('pt-PT', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }) + ' MZN';
            }

            function escapeHtml(valor) {
                return String(valor || '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            function normalizarImagemCardapio(valor) {
                const imagem = String(valor || '').trim();
                if (!imagem) {
                    return '';
                }

                if (/^https?:\/\//i.test(imagem)) {
                    return imagem;
                }

                if (!/^[a-z0-9_./-]+$/i.test(imagem)) {
                    return '';
                }

                return imagem.replace(/^\/+/, '');
            }

            function definirEstadoCardapio(subtitulo, html, link) {
                cardapioSubtitle.textContent = subtitulo;
                cardapioContent.innerHTML = html;

                if (link) {
                    cardapioLink.href = link;
                    cardapioLink.classList.remove('d-none');
                } else {
                    cardapioLink.href = '#';
                    cardapioLink.classList.add('d-none');
                }

                redefinirScrollCardapio();
            }

            function carregarCardapioPreview() {
                const restauranteId = restauranteField.value.trim();

                if (!restauranteId) {
                    definirEstadoCardapio(
                        'Escolha um restaurante para visualizar alguns pratos e bebidas.',
                        '<p class="menu-preview-state">Nenhum restaurante selecionado.</p>',
                        ''
                    );
                    return;
                }

                const requestId = ++cardapioSeq;
                definirEstadoCardapio(
                    'A carregar itens do cardapio selecionado...',
                    '<p class="menu-preview-state">A carregar preview do cardapio...</p>',
                    ''
                );

                const params = new URLSearchParams({
                    route: 'cardapio',
                    restaurante_id: restauranteId
                });

                fetch(apiBase + '?' + params.toString(), {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                    .then(async function(response) {
                        try {
                            return await response.json();
                        } catch (error) {
                            return {
                                success: false,
                                message: 'Resposta invalida ao carregar o cardapio.'
                            };
                        }
                    })
                    .then(function(payload) {
                        if (requestId !== cardapioSeq) {
                            return;
                        }

                        if (!payload.success) {
                            definirEstadoCardapio(
                                'Nao foi possivel carregar o cardapio agora.',
                                '<p class="menu-preview-state">' + escapeHtml(payload.message || 'Erro ao carregar o cardapio.') + '</p>',
                                ''
                            );
                            return;
                        }

                        const categorias = Array.isArray(payload.cardapio_preview) ? payload.cardapio_preview : [];
                        const restaurante = payload.restaurante || {};

                        if (categorias.length === 0) {
                            definirEstadoCardapio(
                                (restaurante.nome || 'Restaurante') + (restaurante.cidade ? ' • ' + restaurante.cidade : ''),
                                '<p class="menu-preview-state">' + escapeHtml(payload.message || 'Este restaurante ainda nao publicou itens no cardapio.') + '</p>',
                                payload.cardapio_url || ''
                            );
                            return;
                        }

                        const html = categorias.map(function(categoria) {
                            const itens = Array.isArray(categoria.itens) ? categoria.itens : [];
                            const itensHtml = itens.map(function(item) {
                                const imagemUrl = normalizarImagemCardapio(item.imagem);
                                const imagemHtml = imagemUrl
                                    ? '<img src="' + escapeHtml(imagemUrl) + '" alt="' + escapeHtml(item.nome) + '" loading="lazy" onerror="this.style.display=\'none\'">'
                                    : '';
                                const descricao = item.descricao
                                    ? '<p class="menu-item-desc">' + escapeHtml(item.descricao) + '</p>'
                                    : '';

                                return ''
                                    + '<div class="menu-item">'
                                    + '  <div class="menu-item-thumb">'
                                    + '      <div class="menu-item-thumb-placeholder"><i class="fas fa-utensils"></i></div>'
                                    +        imagemHtml
                                    + '  </div>'
                                    + '  <div class="menu-item-body">'
                                    + '      <div class="menu-item-top">'
                                    + '          <p class="menu-item-name">' + escapeHtml(item.nome) + '</p>'
                                    + '          <span class="menu-item-price">' + formatarPreco(item.preco) + '</span>'
                                    + '      </div>'
                                    +        descricao
                                    + '  </div>'
                                    + '</div>';
                            }).join('');

                            return ''
                                + '<div class="menu-category">'
                                + '  <div class="menu-category-title">' + escapeHtml(categoria.nome) + '</div>'
                                +      itensHtml
                                + '</div>';
                        }).join('');

                        const subtitulo = (restaurante.nome || 'Restaurante')
                            + (restaurante.cidade ? ' • ' + restaurante.cidade : '')
                            + ' • ' + (payload.total_produtos || 0) + ' item(ns) ativos';

                        definirEstadoCardapio(subtitulo, html, payload.cardapio_url || '');
                    })
                    .catch(function() {
                        if (requestId !== cardapioSeq) {
                            return;
                        }

                        definirEstadoCardapio(
                            'Nao foi possivel carregar o cardapio agora.',
                            '<p class="menu-preview-state">Tente novamente em instantes.</p>',
                            ''
                        );
                    });
            }

            function verificarDisponibilidade() {
                const restauranteId = restauranteField.value.trim();
                const dataReserva = dataField.value.trim();
                const horaReserva = horaField.value.trim();
                const quantidade = Number(quantidadeField.value || 0);

                if (!restauranteId || !dataReserva || !horaReserva || quantidade <= 0) {
                    podeSubmeter = !semRestaurantes;
                    atualizarBotao();
                    atualizarHint('Selecione restaurante, data, hora e quantidade para ver a disponibilidade.', 'muted');
                    return;
                }

                const requestId = ++requestSeq;
                podeSubmeter = false;
                atualizarBotao();
                atualizarHint('A verificar disponibilidade...', 'info');

                const params = new URLSearchParams({
                    route: 'disponibilidade',
                    restaurante_id: restauranteId,
                    data: dataReserva,
                    hora: horaReserva,
                    quantidade: String(quantidade)
                });

                fetch(apiBase + '?' + params.toString(), {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                    .then(async function(response) {
                        let payload;
                        try {
                            payload = await response.json();
                        } catch (error) {
                            payload = {
                                success: false,
                                message: 'Resposta invalida ao consultar disponibilidade.'
                            };
                        }

                        return {
                            status: response.status,
                            payload: payload
                        };
                    })
                    .then(function(result) {
                        if (requestId !== requestSeq) {
                            return;
                        }

                        const payload = result.payload || {};
                        if (!payload.success) {
                            podeSubmeter = result.status >= 500;
                            atualizarBotao();
                            atualizarHint(
                                payload.message || (podeSubmeter
                                    ? 'Nao foi possivel verificar a disponibilidade agora.'
                                    : 'Nao ha disponibilidade para os dados informados.'),
                                result.status >= 500 ? 'warning' : 'danger'
                            );
                            return;
                        }

                        const mesas = Array.isArray(payload.mesas_disponiveis) ? payload.mesas_disponiveis : [];
                        if (mesas.length === 0) {
                            podeSubmeter = false;
                            atualizarBotao();
                            atualizarHint(payload.message || 'Nenhuma mesa disponivel para este horario e quantidade.', 'danger');
                            return;
                        }

                        podeSubmeter = true;
                        atualizarBotao();
                        atualizarHint(
                            mesas.length + ' mesa(s) disponivel(is). Melhor encaixe: Mesa ' + mesas[0].numero + '.',
                            'success'
                        );
                    })
                    .catch(function() {
                        if (requestId !== requestSeq) {
                            return;
                        }

                        podeSubmeter = true;
                        atualizarBotao();
                        atualizarHint('Nao foi possivel verificar a disponibilidade agora. Voce ainda pode tentar enviar a reserva.', 'warning');
                    });
            }

            ['change', 'input'].forEach(function(eventName) {
                restauranteField.addEventListener(eventName, verificarDisponibilidade);
                dataField.addEventListener(eventName, verificarDisponibilidade);
                horaField.addEventListener(eventName, verificarDisponibilidade);
                quantidadeField.addEventListener(eventName, verificarDisponibilidade);
            });

            restauranteField.addEventListener('change', carregarCardapioPreview);
            restauranteField.addEventListener('input', carregarCardapioPreview);

            ['mouseenter', 'focusin', 'touchstart', 'wheel'].forEach(function(eventName) {
                cardapioScroller.addEventListener(eventName, pararAutoScrollCardapio, {
                    passive: eventName === 'touchstart' || eventName === 'wheel'
                });
            });

            ['mouseleave', 'focusout'].forEach(function(eventName) {
                cardapioScroller.addEventListener(eventName, atualizarAutoScrollCardapio);
            });

            window.addEventListener('resize', atualizarAutoScrollCardapio);

            form.addEventListener('submit', function(event) {
                if (semRestaurantes || !podeSubmeter) {
                    event.preventDefault();
                    atualizarHint('Nao ha disponibilidade para concluir a reserva com os dados informados.', 'danger');
                }
            });

            atualizarBotao();
            verificarDisponibilidade();
            carregarCardapioPreview();
        });
    </script>
</body>

</html>


