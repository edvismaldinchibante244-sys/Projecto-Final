<?php
require_once __DIR__ . '/app_base.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/security.php';
require_once APP_BASE . '/src/config/csrf.php';
require_once APP_BASE . '/src/config/legal_documents.php';

security_start_session();
security_set_headers();
security_regenerate_session(15);
$isSuperAdminSession = isset($_SESSION['logado'], $_SESSION['super_admin'])
    && $_SESSION['logado'] === true
    && (int)$_SESSION['super_admin'] === 1;

if (!function_exists('legal_text_length')) {
    function legal_text_length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}

$tiposPedido = [
    'ACESSO' => ['Ver os meus dados', 'Receber uma cópia dos dados pessoais que guardamos sobre si.'],
    'CORRECAO' => ['Corrigir dados', 'Corrigir dados errados ou desatualizados (nome, email, telefone…).'],
    'ELIMINACAO' => ['Apagar os meus dados', 'Apagar os seus dados, exceto o que a lei nos obriga a guardar (por exemplo, faturas).'],
    'LIMITACAO' => ['Suspender o uso dos dados', 'Pedir que os seus dados fiquem guardados mas não sejam usados, por exemplo enquanto se resolve uma correção.'],
    'OPOSICAO' => ['Opor-me a um uso', 'Recusar um uso concreto dos seus dados, por exemplo mensagens promocionais.'],
    'PORTABILIDADE' => ['Levar os dados para outro serviço', 'Receber os seus dados num ficheiro que possa usar noutro serviço.'],
];
$errors = [];
$fieldErrors = [];
$successReference = '';
$receiptSent = false;
$formStartedAt = (int)($_POST['form_started_at'] ?? time());
$values = [
    'request_type' => trim((string)($_POST['request_type'] ?? '')),
    'nome' => trim((string)($_POST['nome'] ?? '')),
    'email' => trim((string)($_POST['email'] ?? '')),
    'restaurante' => trim((string)($_POST['restaurante'] ?? '')),
    'descricao' => trim((string)($_POST['descricao'] ?? '')),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid((string)($_POST['_csrf'] ?? ''))) {
        $errors[] = 'O formulário esteve aberto muito tempo. Os seus dados continuam abaixo: carregue outra vez em «Enviar pedido».';
    }
    if (trim((string)($_POST['website'] ?? '')) !== '') {
        $errors[] = 'Não foi possível validar o formulário.';
    }
    if ((time() - $formStartedAt) < 3) {
        $errors[] = 'O formulário foi enviado demasiado depressa. Confirme os dados e envie de novo.';
    }
    if (!isset($tiposPedido[strtoupper($values['request_type'])])) {
        $fieldErrors['request_type'] = 'Escolha o que pretende fazer.';
    }
    if (legal_text_length($values['nome']) < 3 || legal_text_length($values['nome']) > 150) {
        $fieldErrors['nome'] = 'Escreva o seu nome completo.';
    }
    if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $fieldErrors['email'] = 'Escreva um email válido (por exemplo, nome@gmail.com).';
    }
    $tamanho = legal_text_length($values['descricao']);
    if ($tamanho < 20) {
        $fieldErrors['descricao'] = 'Explique o pedido com pelo menos 20 caracteres (tem ' . $tamanho . ').';
    } elseif ($tamanho > 3000) {
        $fieldErrors['descricao'] = 'O texto tem mais de 3000 caracteres.';
    }
    // So conta para o limite de pedidos quando o formulario esta certo (erros de escrita nao bloqueiam a pessoa).
    if ($errors === [] && $fieldErrors === [] && !security_rate_limit('data_subject_request', 4, 3600)) {
        $errors[] = 'Já recebemos vários pedidos deste dispositivo na última hora. Tente de novo mais tarde.';
    }

    if ($errors === [] && $fieldErrors === []) {
        try {
            $db = (new Database())->getConnection();
            legal_ensure_schema($db);
            $successReference = legal_create_data_request($db, [
                ...$values,
                'ip' => security_get_client_ip(),
                'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'),
            ]);

            // O pedido ja esta guardado: uma falha no email nao pode parecer falha do pedido.
            try {
            $config = legal_config();
            $subject = 'Novo pedido de titular - ' . $successReference;
            $internalMessage = "Foi registado um pedido de dados.\n\nReferencia: {$successReference}\nTipo: {$values['request_type']}\nEstado: PENDENTE_VERIFICACAO\n\nConsulte a base de dados administrativa. Nao execute eliminacao sem verificar identidade, obrigacoes de conservacao e impacto em terceiros.";
            $internalSent = filter_var($config['email'], FILTER_VALIDATE_EMAIL)
                ? saas_enviar_email($config['email'], $subject, $internalMessage)
                : false;

            $receipt = "Recebemos o seu pedido sobre os seus dados pessoais.\n\nReferência: {$successReference}\n\nAntes de responder, vamos confirmar que é mesmo você (respondemos em até 30 dias). Nunca lhe pediremos a palavra-passe. Não envie documentos por email sem lhe pedirmos.";
            $receiptSent = saas_enviar_email($values['email'], 'Recebemos o seu pedido de dados - ' . $successReference, $receipt);
            $notifyStmt = $db->prepare("UPDATE data_subject_requests
                SET internal_notified_at = CASE WHEN ? = 1 THEN NOW() ELSE internal_notified_at END,
                    receipt_sent_at = CASE WHEN ? = 1 THEN NOW() ELSE receipt_sent_at END
                WHERE public_id = ?");
            $notifyStmt->execute([$internalSent ? 1 : 0, $receiptSent ? 1 : 0, $successReference]);
            } catch (Throwable $mailError) {
                error_log('Data subject request email error: ' . $mailError->getMessage());
            }
            $values = ['request_type' => '', 'nome' => '', 'email' => '', 'restaurante' => '', 'descricao' => ''];
        } catch (Throwable $e) {
            error_log('Data subject request error: ' . $e->getMessage());
            $errors[] = 'Não foi possível registar o pedido neste momento. Tente de novo dentro de alguns minutos.';
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
    <title>Os seus dados - Nivora Restaurant</title>
    <meta name="description" content="Peça para ver, corrigir ou apagar os seus dados pessoais.">
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/legal.css?v=<?= (int)@filemtime(__DIR__ . '/css/legal.css') ?>">
</head>
<body class="legal-page legal-data-page">
    <header class="legal-topbar">
        <a class="legal-brand" href="index.php"><span class="legal-brand__mark">N</span><span>Nivora Restaurant</span></a>
        <a class="legal-back" href="<?= $isSuperAdminSession ? 'admin_privacidade.php' : 'privacidade.php' ?>"><i class="fas fa-arrow-left"></i> <?= $isSuperAdminSession ? 'Gerir pedidos' : 'Privacidade' ?></a>
    </header>

    <main class="legal-data-shell">
        <aside class="legal-data-story">
            <p class="legal-data-story__brand">Nivora Restaurant</p>
            <p class="legal-data-story__eyebrow">Os seus direitos</p>
            <h1>Os seus dados,<br><em>sob o seu controlo.</em></h1>
            <p class="legal-data-story__copy">Peça para ver, corrigir ou apagar os dados pessoais que guardamos sobre si. Respondemos em até 30 dias, depois de confirmar que é mesmo você.</p>
            <ol class="legal-data-passos">
                <li><strong>Envia o pedido</strong> neste formulário.</li>
                <li><strong>Recebe uma referência</strong> no ecrã e por email.</li>
                <li><strong>Confirmamos a sua identidade</strong> pelo email que indicar.</li>
                <li><strong>Respondemos</strong> em até 30 dias.</li>
            </ol>
            <?php if ($isSuperAdminSession): ?>
                <a class="legal-admin-shortcut" href="admin_privacidade.php"><i class="fas fa-gauge-high" aria-hidden="true"></i><span><strong>Modo Super Admin</strong>Voltar para tratar os pedidos</span><i class="fas fa-arrow-right" aria-hidden="true"></i></a>
            <?php endif; ?>
        </aside>

        <section class="legal-form-panel">
            <header class="legal-form-heading">
                <div class="legal-form-heading__icon"><i class="fas fa-user-shield" aria-hidden="true"></i></div>
                <div>
                    <p class="legal-eyebrow">Pedido sobre dados pessoais</p>
                    <h2><?= $successReference !== '' ? 'Pedido recebido' : 'O que pretende fazer?' ?></h2>
                    <p><?= $successReference !== '' ? 'Obrigado. Já estamos a tratar do seu pedido.' : 'Os campos com * são obrigatórios.' ?></p>
                </div>
            </header>

            <?php if ($successReference !== ''): ?>
                <div class="legal-status legal-status--success" role="status">
                    <p><strong>O seu pedido ficou registado.</strong></p>
                    <p>Referência: <strong class="legal-ref"><?= security_e($successReference) ?></strong></p>
                    <p>Guarde esta referência<?= $receiptSent ? ' (também a enviámos para o seu email)' : ' — tire uma fotografia ou copie-a' ?>. Nos próximos dias vamos contactá-lo por email para confirmar a sua identidade; depois disso respondemos em até 30 dias.</p>
                    <p class="mb-0"><a href="direitos_dados.php">Fazer outro pedido</a> · <a href="privacidade.php">Ler a Política de Privacidade</a></p>
                </div>
            <?php else: ?>

            <?php if ($errors !== [] || $fieldErrors !== []): ?>
                <div class="legal-status legal-status--error" role="alert" tabindex="-1" id="resumoErros">
                    <strong><?= $fieldErrors !== [] ? 'Falta corrigir ' . count($fieldErrors) . ($fieldErrors && count($fieldErrors) === 1 ? ' campo' : ' campos') . ' (assinalados abaixo).' : 'Não foi possível enviar.' ?></strong>
                    <?php if ($errors !== []): ?><ul><?php foreach ($errors as $error): ?><li><?= security_e($error) ?></li><?php endforeach; ?></ul><?php endif; ?>
                </div>
            <?php endif; ?>

            <form class="legal-form" method="post" action="direitos_dados.php" novalidate>
                <input type="hidden" name="_csrf" value="<?= security_e(csrf_get_token()) ?>">
                <input type="hidden" name="form_started_at" value="<?= time() ?>">
                <div class="legal-honeypot" aria-hidden="true">
                    <label for="website">Website</label>
                    <input id="website" name="website" type="text" tabindex="-1" autocomplete="off">
                </div>

                <fieldset class="legal-field legal-tipos<?= isset($fieldErrors['request_type']) ? ' tem-erro' : '' ?>">
                    <legend>O que pretende? *</legend>
                    <?php foreach ($tiposPedido as $value => [$rotulo, $ajuda]): ?>
                        <label class="legal-tipo">
                            <input type="radio" name="request_type" value="<?= $value ?>" <?= strtoupper($values['request_type']) === $value ? 'checked' : '' ?> required>
                            <span><strong><?= security_e($rotulo) ?></strong><small><?= security_e($ajuda) ?></small></span>
                        </label>
                    <?php endforeach; ?>
                    <?php if (isset($fieldErrors['request_type'])): ?><span class="legal-erro" role="alert"><?= security_e($fieldErrors['request_type']) ?></span><?php endif; ?>
                </fieldset>

                <div class="legal-form-row">
                    <div class="legal-field<?= isset($fieldErrors['nome']) ? ' tem-erro' : '' ?>">
                        <label for="nome">Nome completo *</label>
                        <input id="nome" name="nome" maxlength="150" autocomplete="name" required value="<?= security_e($values['nome']) ?>" <?= isset($fieldErrors['nome']) ? 'aria-invalid="true" aria-describedby="erroNome"' : '' ?>>
                        <?php if (isset($fieldErrors['nome'])): ?><span class="legal-erro" id="erroNome"><?= security_e($fieldErrors['nome']) ?></span><?php endif; ?>
                    </div>
                    <div class="legal-field<?= isset($fieldErrors['email']) ? ' tem-erro' : '' ?>">
                        <label for="email">O seu email *</label>
                        <input id="email" name="email" type="email" maxlength="190" autocomplete="email" required value="<?= security_e($values['email']) ?>" <?= isset($fieldErrors['email']) ? 'aria-invalid="true" aria-describedby="erroEmail"' : 'aria-describedby="ajudaEmail"' ?>>
                        <?php if (isset($fieldErrors['email'])): ?><span class="legal-erro" id="erroEmail"><?= security_e($fieldErrors['email']) ?></span><?php else: ?><small id="ajudaEmail">Use o email com que é conhecido pelo restaurante ou pelo sistema.</small><?php endif; ?>
                    </div>
                </div>

                <div class="legal-field">
                    <label for="restaurante">Restaurante (se souber)</label>
                    <input id="restaurante" name="restaurante" maxlength="180" placeholder="Nome do restaurante" value="<?= security_e($values['restaurante']) ?>">
                </div>

                <div class="legal-field<?= isset($fieldErrors['descricao']) ? ' tem-erro' : '' ?>">
                    <label for="descricao">Explique o seu pedido *</label>
                    <textarea id="descricao" name="descricao" maxlength="3000" required placeholder="Descreva o seu pedido com o maior detalhe possível" <?= isset($fieldErrors['descricao']) ? 'aria-invalid="true"' : '' ?> aria-describedby="contadorDescricao"><?= security_e($values['descricao']) ?></textarea>
                    <div class="legal-contador"><small>Nunca escreva palavras-passe, PIN, dados bancários ou números de documentos.</small><small id="contadorDescricao" aria-live="polite"></small></div>
                    <?php if (isset($fieldErrors['descricao'])): ?><span class="legal-erro"><?= security_e($fieldErrors['descricao']) ?></span><?php endif; ?>
                </div>

                <div class="legal-form-submit-row">
                    <button class="legal-submit" type="submit"><i class="fas fa-paper-plane" aria-hidden="true"></i> Enviar pedido</button>
                    <span><i class="fas fa-lock" aria-hidden="true"></i> Os dados são guardados cifrados</span>
                </div>
            </form>
            <?php endif; ?>
        </section>
    </main>

    <?php include __DIR__ . '/includes/legal_footer.php'; ?>
    <script nonce="<?= function_exists('security_csp_nonce') ? security_csp_nonce() : '' ?>">
    (function () {
        var t = document.getElementById('descricao'), c = document.getElementById('contadorDescricao');
        function contar() { if (!t || !c) return; var n = t.value.trim().length; c.textContent = n < 20 ? ('Faltam ' + (20 - n) + ' caracteres') : (n + ' / 3000'); c.classList.toggle('ok', n >= 20); }
        if (t) { t.addEventListener('input', contar); contar(); }
        var r = document.getElementById('resumoErros');
        if (r) { var campo = document.querySelector('.tem-erro input, .tem-erro textarea'); (campo || r).focus(); }
        var f = document.querySelector('.legal-form');
        if (f) f.addEventListener('submit', function () { var b = f.querySelector('.legal-submit'); if (b) { b.disabled = true; b.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> A enviar…'; } });
    })();
    </script>
</body>
</html>
