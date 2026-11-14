<?php
// Este ficheiro e um fragmento: so faz sentido incluido por uma pagina, que e
// quem verifica a sessao. Pedido directamente pelo navegador, nao responde.
// Hoje o reescritor de URLs ja devolve 404, mas isso e um efeito secundario
// das regras de reescrita, nao uma decisao: se mudarem, o fragmento ficava a
// descoberto. Esta linha torna a recusa deliberada.
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Not found');
}

require_once dirname(__DIR__) . '/app_base.php';
require_once APP_BASE . '/src/config/security.php';
require_once APP_BASE . '/src/config/legal_documents.php';

security_start_session();
security_set_headers();
security_regenerate_session(15);

$document = legal_document((string)$legalDocumentKey);
$config = legal_config();
$effectiveDate = DateTimeImmutable::createFromFormat('Y-m-d', $config['effective_date']);
$effectiveLabel = $effectiveDate ? $effectiveDate->format('d/m/Y') : $config['effective_date'];
$documentPresentation = [
    'privacy' => [
        'icon' => 'fa-shield-halved',
        'label' => 'Protecao de dados',
        'statement' => 'Privacidade que acompanha cada operacao.',
    ],
    'terms' => [
        'icon' => 'fa-file-signature',
        'label' => 'Relacao transparente',
        'statement' => 'Regras claras para uma operacao segura.',
    ],
    'cookies' => [
        'icon' => 'fa-cookie-bite',
        'label' => 'Preferencias e seguranca',
        'statement' => 'Tecnologia essencial, explicada sem rodeios.',
    ],
];
$presentation = $documentPresentation[$legalDocumentKey] ?? $documentPresentation['privacy'];
$sectionCount = count($document['sections']);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/../js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= security_e($document['title']) ?> - Nivora Restaurant</title>
    <meta name="description" content="<?= security_e($document['summary']) ?>">
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/legal.css?v=20260808c">
</head>
<body class="legal-page legal-document-page legal-document-page--<?= security_e((string)$legalDocumentKey) ?>">
    <header class="legal-topbar">
        <a class="legal-brand" href="index.php" aria-label="Nivora Restaurant - pagina inicial">
            <span class="legal-brand__mark">E</span>
            <span>Nivora Restaurant</span>
        </a>
        <a class="legal-back" href="index.php"><i class="fas fa-arrow-left" aria-hidden="true"></i> Voltar</a>
    </header>

    <main class="legal-shell legal-document-shell">
        <aside class="legal-nav" aria-label="Documentos legais">
            <div class="legal-nav__identity">
                <span class="legal-nav__icon"><i class="fas <?= security_e($presentation['icon']) ?>" aria-hidden="true"></i></span>
                <p class="legal-nav__label"><?= security_e($presentation['label']) ?></p>
                <h2><?= security_e($presentation['statement']) ?></h2>
                <p>O centro de confianca da Nivora Restaurant reune os compromissos que protegem restaurantes, equipas e clientes.</p>
            </div>

            <nav class="legal-nav__links" aria-label="Centro legal">
                <span>Centro legal</span>
                <a class="<?= $legalDocumentKey === 'privacy' ? 'is-active' : '' ?>" href="privacidade.php"><i class="fas fa-shield-halved"></i><span>Privacidade<small>Dados e protecao</small></span></a>
                <a class="<?= $legalDocumentKey === 'terms' ? 'is-active' : '' ?>" href="termos.php"><i class="fas fa-file-contract"></i><span>Termos de Uso<small>Regras da plataforma</small></span></a>
                <a class="<?= $legalDocumentKey === 'cookies' ? 'is-active' : '' ?>" href="cookies.php"><i class="fas fa-cookie-bite"></i><span>Cookies<small>Sessao e preferencias</small></span></a>
                <a href="direitos_dados.php"><i class="fas fa-user-shield"></i><span>Os seus dados<small>Exercer um direito</small></span></a>
            </nav>

            <div class="legal-nav__assurance">
                <i class="fas fa-lock" aria-hidden="true"></i>
                <span><strong>Canal protegido</strong>Pedidos de dados recebem uma referencia individual.</span>
            </div>
        </aside>

        <article class="legal-document">
            <div class="legal-document__hero">
                <div class="legal-document__heading">
                    <span class="legal-document__icon"><i class="fas <?= security_e($presentation['icon']) ?>" aria-hidden="true"></i></span>
                    <div>
                        <p class="legal-eyebrow">Governanca e conformidade</p>
                        <h1><?= security_e($document['title']) ?></h1>
                    </div>
                </div>
                <p class="legal-document__summary"><?= security_e($document['summary']) ?></p>
                <div class="legal-document__meta">
                    <span><small>Documento</small><strong>Versao <?= security_e($document['version']) ?></strong></span>
                    <span><small>Vigencia</small><strong><?= security_e($effectiveLabel) ?></strong></span>
                    <span><small>Estrutura</small><strong><?= $sectionCount ?> secoes</strong></span>
                    <a href="direitos_dados.php"><small>Privacidade</small><strong>Exercer um direito <i class="fas fa-arrow-right" aria-hidden="true"></i></strong></a>
                </div>
            </div>

            <?php if (!legal_identity_ready()): ?>
                <div class="legal-draft-alert" role="alert">
                    <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
                    <div><strong>Documento preliminar.</strong> A identificacao legal do fornecedor ainda deve ser preenchida antes da publicacao.</div>
                </div>
            <?php endif; ?>

            <div class="legal-document__body">
                <?php foreach ($document['sections'] as $section): ?>
                    <section>
                        <h2><?= security_e($section['title']) ?></h2>
                        <?php foreach (($section['paragraphs'] ?? []) as $paragraph): ?>
                            <p><?= security_e($paragraph) ?></p>
                        <?php endforeach; ?>
                        <?php if (!empty($section['items'])): ?>
                            <ul>
                                <?php foreach ($section['items'] as $item): ?>
                                    <li><?= security_e($item) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <?php if (!empty($section['link'])): ?>
                            <a class="legal-action" href="<?= security_e($section['link']['href']) ?>"><?= security_e($section['link']['label']) ?> <i class="fas fa-arrow-right"></i></a>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </div>
        </article>
    </main>

    <?php include __DIR__ . '/legal_footer.php'; ?>
</body>
</html>
