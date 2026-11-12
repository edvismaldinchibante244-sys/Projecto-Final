<?php
require_once __DIR__ . '/app_base.php';

/**
 * ============================================
 * FORMULÁRIO PÚBLICO - SOLICITAR PLANO
 * Cliente solicita um plano antes de fazer login
 * ============================================
 */

include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/security.php';
require_once APP_BASE . '/src/config/imagem_otimizar.php';
include_once APP_BASE . '/src/config/plano_notificacoes.php';
include_once APP_BASE . '/src/config/csrf.php';
include_once APP_BASE . '/src/config/restaurante_status_helper.php';
include_once APP_BASE . '/src/config/plan_security.php';
require_once APP_BASE . '/src/config/plano_bonus.php';
// O periodo gratuito foi descontinuado: o que ha agora e a oferta de
// boas-vindas no primeiro pagamento (14 dias no plano de cima).
$trialAtivo = plan_security_supports_trial('BASICO');
include_once APP_BASE . '/src/config/audit.php';
require_once APP_BASE . '/src/config/plano_pagamentos.php';
require_once APP_BASE . '/src/config/legal_documents.php';

$planos_catalogo = require APP_BASE . '/src/config/planos.php';
$planoPaymentInstructions = plano_pagamento_instrucoes_publicas();

security_start_session();
security_set_headers();
security_regenerate_session(15);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    @ignore_user_abort(true);
    @set_time_limit(90);
}

if (!function_exists('format_mzn')) {
    function format_mzn($valor)
    {
        return number_format((float)$valor, 0, ',', '.');
    }
}

if (!function_exists('solicitar_plano_escape')) {
    function solicitar_plano_escape($valor)
    {
        return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('solicitar_plano_default_form')) {
    function solicitar_plano_default_form()
    {
        return [
            'nome' => '',
            'email' => '',
            'telefone' => '',
            'endereco' => '',
            'cidade' => '',
            'nuit' => '',
            'plano' => '',
            'ciclo' => 'MENSAL',
            'metodo' => '',
        ];
    }
}

if (!function_exists('solicitar_plano_clean_text')) {
    function solicitar_plano_clean_text($value, int $maxLength = 160): string
    {
        $value = function_exists('security_clean_string')
            ? security_clean_string($value, $maxLength)
            : trim((string)$value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength, 'UTF-8');
        }
        return substr($value, 0, $maxLength);
    }
}

if (!function_exists('solicitar_plano_has_unsafe_text')) {
    function solicitar_plano_has_unsafe_text(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        return (bool)preg_match(
            '/<[^>]*>|javascript:|data:text|on[a-z]+\s*=|\b(union|select|insert|update|delete|drop|sleep|benchmark)\b|(?:\.\.\/)|(?:%2e%2e)/i',
            $value
        );
    }
}

if (!function_exists('solicitar_plano_valid_name')) {
    function solicitar_plano_valid_name(string $value): bool
    {
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        return $length >= 3
            && $length <= 120
            && (bool)preg_match('/^[\p{L}\p{N}\s.\'&()\-]+$/u', $value);
    }
}

if (!function_exists('solicitar_plano_valid_location')) {
    function solicitar_plano_valid_location(string $value, int $min, int $max): bool
    {
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        return $length >= $min
            && $length <= $max
            && !solicitar_plano_has_unsafe_text($value);
    }
}

if (!function_exists('solicitar_plano_risk_score')) {
    function solicitar_plano_risk_score(array $form, array $files): array
    {
        $score = 0;
        $flags = [];

        $email = strtolower(trim((string)($form['email'] ?? '')));
        $nome = trim((string)($form['nome'] ?? ''));
        $telefone = preg_replace('/\D+/', '', (string)($form['telefone'] ?? ''));
        $raw = $nome . ' ' . $email . ' ' . (string)($form['endereco'] ?? '') . ' ' . (string)($form['cidade'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $score += 20;
            $flags[] = 'email_invalido';
        }
        if ($nome === '' || (function_exists('mb_strlen') ? mb_strlen($nome) : strlen($nome)) < 3) {
            $score += 15;
            $flags[] = 'nome_curto';
        }
        if ($telefone !== '' && (strlen($telefone) < 8 || strlen($telefone) > 15)) {
            $score += 10;
            $flags[] = 'telefone_invalido';
        }

        if (solicitar_plano_has_unsafe_text($raw)) {
            $score += 55;
            $flags[] = 'unsafe_text_probe';
        }

        $logo = $files['logo'] ?? null;
        $comp = $files['comprovativo'] ?? null;
        if (is_array($logo) && (($logo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) && (($logo['size'] ?? 0) > (4 * 1024 * 1024))) {
            $score += 15;
            $flags[] = 'logo_oversize';
        }
        if (is_array($comp) && (($comp['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) && (($comp['size'] ?? 0) > (5 * 1024 * 1024))) {
            $score += 15;
            $flags[] = 'comprovativo_oversize';
        }

        return ['score' => max(0, min(100, $score)), 'flags' => $flags];
    }
}

$preco_basico = $planos_catalogo['BASICO']['precos'] ?? ['mensal' => 799, 'trimestral' => 2157, 'anual' => 7670];
$preco_profissional = $planos_catalogo['PROFISSIONAL']['precos'] ?? ['mensal' => 1999, 'trimestral' => 5397, 'anual' => 19190];
$preco_empresarial = $planos_catalogo['EMPRESARIAL']['precos'] ?? ['mensal' => 3999, 'trimestral' => 10797, 'anual' => 38390];
$recursos_planos_catalogo = function_exists('plano_get_recursos_catalogo') ? [
    'BASICO' => plano_get_recursos_catalogo('BASICO'),
    'PROFISSIONAL' => plano_get_recursos_catalogo('PROFISSIONAL'),
    'EMPRESARIAL' => plano_get_recursos_catalogo('EMPRESARIAL'),
] : [
    'BASICO' => [],
    'PROFISSIONAL' => [],
    'EMPRESARIAL' => [],
];

$form = solicitar_plano_default_form();
$mensagem = '';
$tipo_msg = '';
$csrf_token = csrf_get_token();
if (!isset($_SESSION['solicitar_plano_form_started_at']) || !is_int($_SESSION['solicitar_plano_form_started_at'])) {
    $_SESSION['solicitar_plano_form_started_at'] = time();
}
$logo_caminho_absoluto = null;
$logo_caminho_relativo = null;
$documento_caminho_absoluto = null;
$documento_caminho_relativo = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ipSolicitacao = function_exists('security_get_client_ip') ? (string)security_get_client_ip() : 'unknown';
    $emailSolicitacao = strtolower(trim((string)($_POST['email'] ?? '')));
    $fingerprint = hash('sha256', $ipSolicitacao . '|' . $emailSolicitacao);
    if (!security_rate_limit('solicitar_plano:' . preg_replace('/[^a-zA-Z0-9_:\.\-]/', '_', $ipSolicitacao), 8, 300)) {
        $mensagem = 'Muitas tentativas. Aguarde alguns minutos e tente novamente.';
        $tipo_msg = 'danger';
        security_log_event('plano_solicitacao_block_ip_rate', [
            'ip' => $ipSolicitacao,
            'email' => $emailSolicitacao,
        ]);
    } elseif (!security_rate_limit('solicitar_plano_fp:' . $fingerprint, 4, 900)) {
        $mensagem = 'Solicitacoes temporariamente limitadas para este email. Aguarde alguns minutos.';
        $tipo_msg = 'danger';
        security_log_event('plano_solicitacao_block_fingerprint_rate', [
            'ip' => $ipSolicitacao,
            'email' => $emailSolicitacao,
            'fingerprint' => substr($fingerprint, 0, 16),
        ]);
    }

    if ($mensagem !== '') {
        $form = [
            'nome' => solicitar_plano_clean_text($_POST['nome'] ?? '', 120),
            'email' => strtolower(solicitar_plano_clean_text($_POST['email'] ?? '', 254)),
            'telefone' => solicitar_plano_clean_text($_POST['telefone'] ?? '', 24),
            'endereco' => solicitar_plano_clean_text($_POST['endereco'] ?? '', 180),
            'cidade' => solicitar_plano_clean_text($_POST['cidade'] ?? '', 80),
            'nuit' => preg_replace('/\D+/', '', (string)($_POST['nuit'] ?? '')),
            'plano' => strtoupper(trim((string)($_POST['plano'] ?? ''))),
            'ciclo' => strtoupper(trim((string)($_POST['ciclo'] ?? 'MENSAL'))),
            'metodo' => strtoupper(trim((string)($_POST['metodo'] ?? ''))),
        ];
    } else {
        $form = [
            'nome' => solicitar_plano_clean_text($_POST['nome'] ?? '', 120),
            'email' => strtolower(solicitar_plano_clean_text($_POST['email'] ?? '', 254)),
            'telefone' => solicitar_plano_clean_text($_POST['telefone'] ?? '', 24),
            'endereco' => solicitar_plano_clean_text($_POST['endereco'] ?? '', 180),
            'cidade' => solicitar_plano_clean_text($_POST['cidade'] ?? '', 80),
            'nuit' => preg_replace('/\D+/', '', (string)($_POST['nuit'] ?? '')),
            'plano' => strtoupper(trim((string)($_POST['plano'] ?? ''))),
            'ciclo' => strtoupper(trim((string)($_POST['ciclo'] ?? 'MENSAL'))),
            'metodo' => strtoupper(trim((string)($_POST['metodo'] ?? ''))),
        ];

        $nome = $form['nome'];
        $email = $form['email'];
        $telefone = $form['telefone'];
        $endereco = $form['endereco'];
        $cidade = $form['cidade'];
        $nuit = $form['nuit'];
        $plano = $form['plano'];
        $ciclo = $form['ciclo'];
        $metodo = $form['metodo'];
        $arquivo_logo = $_FILES['logo'] ?? null;
    $arquivo_comprovativo = $_FILES['comprovativo'] ?? null;
    $arquivo_documento = $_FILES['documento_titularidade'] ?? null;
        $honeypot = trim((string)($_POST['website'] ?? ''));
        $aceiteLegal = (string)($_POST['aceite_legal'] ?? '') === '1';
        $formStartedAt = (int)($_SESSION['solicitar_plano_form_started_at'] ?? 0);
        $elapsedSeconds = $formStartedAt > 0 ? (time() - $formStartedAt) : 0;
        $ciclos_validos = ['MENSAL', 'TRIMESTRAL', 'ANUAL'];
        $metodos_validos = ['MPESA', 'EMOLA', 'CARTAO', 'TRANSFERENCIA', 'TRIAL'];
        $erros = [];
        $logoUploadError = $arquivo_logo['error'] ?? UPLOAD_ERR_NO_FILE;
    $uploadError = $arquivo_comprovativo['error'] ?? UPLOAD_ERR_NO_FILE;
    $documentoUploadError = $arquivo_documento['error'] ?? UPLOAD_ERR_NO_FILE;
        $telefoneDigitos = preg_replace('/\D+/', '', $telefone);
        $nuitDigitos = preg_replace('/\D+/', '', $nuit);

        if (!csrf_is_valid()) {
            $erros[] = 'Sessao expirada ou formulario invalido. Recarregue a pagina e tente novamente.';
        }
        if (!$aceiteLegal) {
            $erros[] = 'Leia e aceite os Termos de Uso e confirme que tomou conhecimento da Politica de Privacidade.';
        }
        if ($honeypot !== '') {
            $erros[] = 'Solicitacao invalida.';
            security_log_event('waf_block_solicitar_plano_honeypot', [
                'ip' => $ipSolicitacao,
                'email' => $email,
                'fingerprint' => substr($fingerprint, 0, 16),
            ]);
        }
        if ($elapsedSeconds > 0 && $elapsedSeconds < 3) {
            $erros[] = 'Solicitacao muito rapida. Aguarde alguns segundos e tente novamente.';
            security_log_event('waf_block_solicitar_plano_fast_submit', [
                'ip' => $ipSolicitacao,
                'email' => $email,
                'elapsed_seconds' => $elapsedSeconds,
                'fingerprint' => substr($fingerprint, 0, 16),
            ]);
        }

        if (!solicitar_plano_valid_name($nome)) {
            $erros[] = 'Informe um nome de restaurante valido, sem codigo ou caracteres suspeitos.';
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erros[] = 'Informe um email valido.';
        }

        if ($telefone === '' || strlen($telefoneDigitos) < 8 || strlen($telefoneDigitos) > 15) {
            $erros[] = 'Informe um telefone valido para contacto.';
        }

        if (!solicitar_plano_valid_location($cidade, 2, 80)) {
            $erros[] = 'Informe uma cidade valida.';
        }

        if (!solicitar_plano_valid_location($endereco, 4, 180)) {
            $erros[] = 'Informe um endereco valido do restaurante.';
        }

        if ($nuitDigitos === '' || strlen($nuitDigitos) !== 9) {
            $erros[] = 'NUIT invalido. Informe os 9 digitos do restaurante.';
        }

        foreach (['nome' => $nome, 'cidade' => $cidade, 'endereco' => $endereco] as $campo => $valorCampo) {
            if (solicitar_plano_has_unsafe_text((string)$valorCampo)) {
                $erros[] = 'Remova codigo, links suspeitos ou caracteres invalidos dos dados do restaurante.';
                security_log_event('waf_block_solicitar_plano_unsafe_field', [
                    'campo' => $campo,
                    'ip' => $ipSolicitacao,
                    'email' => $email,
                    'fingerprint' => substr($fingerprint, 0, 16),
                ]);
                break;
            }
        }

        if ($plano === '' || !isset($planos_catalogo[$plano])) {
            $erros[] = 'Selecione um plano valido.';
        }

        if (!in_array($ciclo, $ciclos_validos, true)) {
            $erros[] = 'Selecione um ciclo valido.';
        }

        if (!in_array($metodo, $metodos_validos, true)) {
            $erros[] = 'Selecione um metodo de pagamento valido.';
        }

        if ($metodo === 'TRIAL' && ($plano !== 'BASICO' || $ciclo !== 'MENSAL')) {
            $erros[] = 'Trial disponivel apenas para o plano Basico com ciclo Mensal.';
        }

        $validacaoPlano = null;
        if (
            isset($planos_catalogo[$plano])
            && in_array($ciclo, $ciclos_validos, true)
            && in_array($metodo, $metodos_validos, true)
        ) {
            $validacaoPlano = plan_security_validate_request('BASICO', $plano, $ciclo, $metodo, true);
            if (empty($validacaoPlano['ok'])) {
                $erros[] = (string)($validacaoPlano['message'] ?? 'Plano, ciclo ou metodo de pagamento invalido.');
            }
        }

        if ($logoUploadError !== UPLOAD_ERR_OK) {
            $erros[] = $logoUploadError === UPLOAD_ERR_NO_FILE
                ? 'Anexe o logotipo do restaurante.'
                : 'Falha no envio do logotipo. Tente novamente.';
        }

        if ($uploadError !== UPLOAD_ERR_OK && $metodo !== 'TRIAL') {
            $erros[] = $uploadError === UPLOAD_ERR_NO_FILE
                ? 'Anexe o comprovativo de pagamento.'
                : 'Falha no envio do comprovativo. Tente novamente.';
        }

        if ($documentoUploadError !== UPLOAD_ERR_OK) {
            $erros[] = $documentoUploadError === UPLOAD_ERR_NO_FILE
                ? 'Anexe um documento que comprove a titularidade ou representação do restaurante.'
                : 'Falha no envio do documento de titularidade. Tente novamente.';
        }

        $risk = solicitar_plano_risk_score($form, [
            'logo' => $arquivo_logo,
            'comprovativo' => $arquivo_comprovativo,
        ]);

        if ((int)($risk['score'] ?? 0) >= 70) {
            $mensagem = 'Solicitacao bloqueada por politica de seguranca. Verifique os dados e tente novamente.';
            $tipo_msg = 'danger';
            security_log_event('waf_block_solicitar_plano_high_risk', [
                'risk_score' => (int)($risk['score'] ?? 0),
                'flags' => $risk['flags'] ?? [],
                'email' => $email,
                'ip' => $ipSolicitacao,
            ]);
        } elseif ((int)($risk['score'] ?? 0) >= 40) {
            security_log_event('waf_alert_solicitar_plano_medium_risk', [
                'risk_score' => (int)($risk['score'] ?? 0),
                'flags' => $risk['flags'] ?? [],
                'email' => $email,
                'ip' => $ipSolicitacao,
            ]);
        }

        if ($mensagem !== '') {
            $tipo_msg = 'danger';
        } elseif (!empty($erros)) {
            $mensagem = implode("\n", $erros);
            $tipo_msg = 'danger';
        } else {
            $database = new Database();
            $db = $database->getConnection();
            plan_security_ensure_purchase_hardening_columns($db);
            legal_ensure_schema($db);

            $stmt = $db->prepare("
            SELECT
                r.id,
                r.status,
                EXISTS(
                    SELECT 1
                    FROM compras_planos cp
                    WHERE cp.restaurante_id = r.id
                      AND cp.status IN ('PENDENTE', 'AGUARDANDO_APROVACAO')
                ) AS possui_compra_pendente
            FROM restaurantes r
            WHERE r.email_lookup = ?
            ORDER BY r.id DESC
            LIMIT 1
        ");
            $emailLookup = personal_data_email_lookup($email);
            $emailEncrypted = personal_data_encrypt($email);
            $stmt->execute([$emailLookup]);
            $restauranteExistente = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($restauranteExistente) {
                $mensagem = ((int)($restauranteExistente['possui_compra_pendente'] ?? 0) === 1)
                    ? 'Ja existe uma solicitacao pendente para este email. Aguarde a analise do super admin.'
                    : 'Este email ja esta cadastrado. Faca login para continuar.';
                $tipo_msg = 'danger';
            } else {
                $trialAnterior = null;
                $nuitLookup = personal_data_nuit_lookup($nuit);
                if (is_string($nuitLookup) && $nuitLookup !== '') {
                    $stmtNuit = $db->prepare('SELECT id FROM restaurantes WHERE nuit_lookup = ? LIMIT 1');
                    $stmtNuit->execute([$nuitLookup]);
                    if ($stmtNuit->fetch(PDO::FETCH_ASSOC)) {
                        $mensagem = 'Este NUIT ja esta cadastrado. Use a conta existente ou contacte o suporte.';
                        $tipo_msg = 'danger';
                    }
                }
                $telefoneLookup = function_exists('personal_data_phone_lookup')
                    ? personal_data_phone_lookup($telefone)
                    : null;
                if ($mensagem === '' && is_string($telefoneLookup) && $telefoneLookup !== '') {
                    $restCols = $db->query('SHOW COLUMNS FROM restaurantes')->fetchAll(PDO::FETCH_COLUMN);
                    if (in_array('telefone_lookup', $restCols, true)) {
                        $stmtTelefone = $db->prepare('SELECT id FROM restaurantes WHERE telefone_lookup = ? LIMIT 1');
                        $stmtTelefone->execute([$telefoneLookup]);
                        if ($stmtTelefone->fetch(PDO::FETCH_ASSOC)) {
                            $mensagem = 'Este telefone ja esta associado a um restaurante. Use o acesso existente ou contacte o suporte.';
                            $tipo_msg = 'danger';
                        }
                    }
                }
                if ($metodo === 'TRIAL' && is_string($nuitLookup) && $nuitLookup !== '') {
                    $stmtTrial = $db->prepare("
                        SELECT cp.id, cp.status
                          FROM compras_planos cp
                          INNER JOIN restaurantes r ON r.id = cp.restaurante_id
                         WHERE r.nuit_lookup = ?
                           AND cp.metodo_pagamento = 'TRIAL'
                           AND cp.status IN ('PENDENTE', 'AGUARDANDO_APROVACAO', 'APROVADO')
                         ORDER BY cp.id DESC
                         LIMIT 1
                    ");
                    $stmtTrial->execute([$nuitLookup]);
                    $trialAnterior = $stmtTrial->fetch(PDO::FETCH_ASSOC) ?: null;
                }

                if ($trialAnterior) {
                    $mensagem = 'Ja existe um trial Basico solicitado para este NUIT. Entre na conta existente ou contacte o suporte.';
                    $tipo_msg = 'danger';
                }

                $stmt = $db->prepare("SELECT id FROM usuarios WHERE email_lookup = ? LIMIT 1");
                $stmt->execute([$emailLookup]);

                if ($mensagem !== '') {
                    // Mantem o bloqueio de reutilizacao do trial definido acima.
                } elseif ($stmt->fetch()) {
                    $mensagem = 'Este email ja esta vinculado a um usuario existente. Utilize outro email ou recupere o acesso.';
                    $tipo_msg = 'danger';
                } else {
                    $valor = (float)($validacaoPlano['price'] ?? 0.0);

                    if ($metodo !== 'TRIAL' && $valor <= 0) {
                        $mensagem = 'Plano ou ciclo invalido.';
                        $tipo_msg = 'danger';
                    } else {
                        $extensoes_logo_permitidas = ['jpg', 'jpeg', 'png', 'webp'];
                        $mimes_logo_permitidos = ['image/jpeg', 'image/png', 'image/webp'];
                        $max_tamanho_logo = 4 * 1024 * 1024;
                        $logo_extensao = strtolower(pathinfo($arquivo_logo['name'] ?? '', PATHINFO_EXTENSION));
                        $extensoes_permitidas = ['jpg', 'jpeg', 'png', 'pdf'];
                        $mimes_permitidos = ['image/jpeg', 'image/png', 'application/pdf'];
                        $max_tamanho = 5 * 1024 * 1024;
                        $extensao = strtolower(pathinfo($arquivo_comprovativo['name'] ?? '', PATHINFO_EXTENSION));
                        $documento_extensao = strtolower(pathinfo($arquivo_documento['name'] ?? '', PATHINFO_EXTENSION));
                        $documento_extensoes = ['jpg', 'jpeg', 'png', 'pdf'];
                        $documento_mimes = ['image/jpeg', 'image/png', 'application/pdf'];

                        if (!in_array($logo_extensao, $extensoes_logo_permitidas, true)) {
                            $mensagem = 'Logotipo invalido. Envie JPG, PNG ou WEBP.';
                            $tipo_msg = 'danger';
                        } elseif (($arquivo_logo['size'] ?? 0) > $max_tamanho_logo) {
                            $mensagem = 'Logotipo excede 4MB.';
                            $tipo_msg = 'danger';
                        } elseif ($metodo !== 'TRIAL' && !in_array($extensao, $extensoes_permitidas, true)) {
                            $mensagem = 'Comprovativo invalido. Envie JPG, PNG ou PDF.';
                            $tipo_msg = 'danger';
                        } elseif ($metodo !== 'TRIAL' && ($arquivo_comprovativo['size'] ?? 0) > $max_tamanho) {
                            $mensagem = 'Comprovativo excede 5MB.';
                            $tipo_msg = 'danger';
                        } elseif (!in_array($documento_extensao, $documento_extensoes, true)) {
                            $mensagem = 'Documento de titularidade invalido. Envie JPG, PNG ou PDF.';
                            $tipo_msg = 'danger';
                        } elseif (($arquivo_documento['size'] ?? 0) > $max_tamanho) {
                            $mensagem = 'Documento de titularidade excede 5MB.';
                            $tipo_msg = 'danger';
                        } else {
                            $logoMime = '';
                            if (function_exists('finfo_open')) {
                                $finfoLogo = finfo_open(FILEINFO_MIME_TYPE);
                                if ($finfoLogo) {
                                    $logoMime = finfo_file($finfoLogo, $arquivo_logo['tmp_name']);
                                    finfo_close($finfoLogo);
                                }
                            }

                            if ($logoMime === '' && function_exists('mime_content_type')) {
                                $logoMime = mime_content_type($arquivo_logo['tmp_name']) ?: '';
                            }

                            $mime = '';
                            if ($metodo !== 'TRIAL') {
                                if (function_exists('finfo_open')) {
                                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                                    if ($finfo) {
                                        $mime = finfo_file($finfo, $arquivo_comprovativo['tmp_name']);
                                        finfo_close($finfo);
                                    }
                                }

                                if ($mime === '' && function_exists('mime_content_type')) {
                                    $mime = mime_content_type($arquivo_comprovativo['tmp_name']) ?: '';
                                }
                            }

                            $documentoMime = '';
                            if (function_exists('finfo_open')) {
                                $finfoDocumento = finfo_open(FILEINFO_MIME_TYPE);
                                if ($finfoDocumento) {
                                    $documentoMime = finfo_file($finfoDocumento, $arquivo_documento['tmp_name']);
                                    finfo_close($finfoDocumento);
                                }
                            }

                            if ($logoMime !== '' && !in_array($logoMime, $mimes_logo_permitidos, true)) {
                                $mensagem = 'Tipo de arquivo invalido para o logotipo.';
                                $tipo_msg = 'danger';
                            } elseif ($metodo !== 'TRIAL' && $mime !== '' && !in_array($mime, $mimes_permitidos, true)) {
                                $mensagem = 'Tipo de arquivo invalido para comprovativo.';
                                $tipo_msg = 'danger';
                            } elseif ($documentoMime !== '' && !in_array($documentoMime, $documento_mimes, true)) {
                                $mensagem = 'Tipo de arquivo invalido para documento de titularidade.';
                                $tipo_msg = 'danger';
                            } else {
                                $dir_logo_absoluto = __DIR__ . '/uploads/restaurantes/logos';
                                $dir_upload_absoluto = __DIR__ . '/uploads/comprovativos';
                                $dir_documento_absoluto = __DIR__ . '/uploads/documentos_titularidade';
                                if ((!is_dir($dir_logo_absoluto) && !mkdir($dir_logo_absoluto, 0755, true))
                                    || (!is_dir($dir_upload_absoluto) && !mkdir($dir_upload_absoluto, 0755, true))
                                    || (!is_dir($dir_documento_absoluto) && !mkdir($dir_documento_absoluto, 0755, true))
                                ) {
                                    $mensagem = 'Nao foi possivel preparar os diretorios de upload.';
                                    $tipo_msg = 'danger';
                                } else {
                                    try {
                                        $nome_logo = sprintf(
                                            'logo_%s_%s.%s',
                                            date('YmdHis'),
                                            bin2hex(random_bytes(6)),
                                            $logo_extensao
                                        );
                                    } catch (Throwable $e) {
                                        $nome_logo = '';
                                    }

                                    try {
                                        $nome_comprovativo = sprintf(
                                            'comp_%s_%s.%s',
                                            date('YmdHis'),
                                            bin2hex(random_bytes(6)),
                                            $extensao
                                        );
                                    } catch (Throwable $e) {
                                        $nome_comprovativo = '';
                                    }
                                    try {
                                        $nome_documento = sprintf('titularidade_%s_%s.%s', date('YmdHis'), bin2hex(random_bytes(6)), $documento_extensao);
                                    } catch (Throwable $e) {
                                        $nome_documento = '';
                                    }

                                    if ($nome_logo === '' || $nome_documento === '' || ($metodo !== 'TRIAL' && $nome_comprovativo === '')) {
                                        $mensagem = 'Nao foi possivel gerar identificadores seguros para os uploads.';
                                        $tipo_msg = 'danger';
                                    } else {
                                        $logo_caminho_absoluto = $dir_logo_absoluto . '/' . $nome_logo;
                                        $logo_caminho_relativo = 'uploads/restaurantes/logos/' . $nome_logo;
                                        $documento_caminho_absoluto = $dir_documento_absoluto . '/' . $nome_documento;
                                        $documento_caminho_relativo = 'uploads/documentos_titularidade/' . $nome_documento;
                                        if ($metodo !== 'TRIAL') {
                                            $caminho_absoluto = $dir_upload_absoluto . '/' . $nome_comprovativo;
                                            $caminho_relativo = 'uploads/comprovativos/' . $nome_comprovativo;
                                            $hash_arquivo = plan_security_file_sha256((string)$arquivo_comprovativo['tmp_name']);
                                        } else {
                                            $caminho_absoluto = '';
                                            $caminho_relativo = '';
                                            $hash_arquivo = '';
                                        }

                                        $uploadError = null;
                                        $logoOk = security_validate_upload(
                                            $arquivo_logo,
                                            ['png', 'jpg', 'jpeg', 'webp'],
                                            ['image/png', 'image/jpeg', 'image/webp'],
                                            4 * 1024 * 1024,
                                            $uploadError
                                        );
                                        $compOk = $metodo !== 'TRIAL'
                                            ? security_validate_upload(
                                                $arquivo_comprovativo,
                                                ['png', 'jpg', 'jpeg', 'pdf'],
                                                ['image/png', 'image/jpeg', 'application/pdf'],
                                                5 * 1024 * 1024,
                                                $uploadError
                                            )
                                            : true;
                                        $documentoError = null;
                                        $documentoOk = security_validate_upload($arquivo_documento, ['png', 'jpg', 'jpeg', 'pdf'], ['image/png', 'image/jpeg', 'application/pdf'], 5 * 1024 * 1024, $documentoError);

                                        if (!$logoOk) {
                                            $mensagem = $uploadError ?: 'Falha ao validar o logotipo.';
                                            $tipo_msg = 'danger';
                                        } elseif (!$compOk) {
                                            $mensagem = $uploadError ?: 'Falha ao validar comprovativo.';
                                            $tipo_msg = 'danger';
                                        } elseif (!$documentoOk) {
                                            $mensagem = $documentoError ?: 'Falha ao validar documento de titularidade.';
                                            $tipo_msg = 'danger';
                                        } elseif (!imagem_mover_e_otimizar($arquivo_logo['tmp_name'], $logo_caminho_absoluto, 512)) {
                                            $mensagem = 'Falha ao salvar o logotipo.';
                                            $tipo_msg = 'danger';
                                        } elseif (!move_uploaded_file($arquivo_documento['tmp_name'], $documento_caminho_absoluto)) {
                                            if (is_file($logo_caminho_absoluto)) @unlink($logo_caminho_absoluto);
                                            $mensagem = 'Falha ao salvar documento de titularidade.';
                                            $tipo_msg = 'danger';
                                        } elseif ($metodo !== 'TRIAL' && !imagem_mover_e_otimizar($arquivo_comprovativo['tmp_name'], $caminho_absoluto, 1600)) {
                                            if (is_file($logo_caminho_absoluto)) {
                                                @unlink($logo_caminho_absoluto);
                                            }
                                            $logo_caminho_absoluto = null;
                                            $logo_caminho_relativo = null;
                                            $mensagem = 'Falha ao salvar comprovativo.';
                                            $tipo_msg = 'danger';
                                        } else {
                                            $senha_hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
                                            $status_inicial = restaurante_status_resolver_inicial($db);
                                            $diasPlanoSolicitado = $metodo === 'TRIAL'
                                                ? plan_security_trial_days($plano)
                                                : plan_security_days($ciclo);
                                            $data_fim = date('Y-m-d', strtotime('+' . max(1, $diasPlanoSolicitado) . ' days'));
                                            $colunasRestaurante = [];
                                            $stmt_cols_rest = $db->query('SHOW COLUMNS FROM restaurantes');
                                            while ($col_rest = $stmt_cols_rest->fetch(PDO::FETCH_ASSOC)) {
                                                $colunasRestaurante[] = $col_rest['Field'];
                                            }

                                            try {
                                                $db->beginTransaction();

                                                $restauranteCols = ['nome', 'email', 'email_lookup', 'telefone', 'endereco', 'cidade', 'nuit', 'nuit_lookup', 'plano', 'data_fim', 'created_at'];
                                                $restauranteVals = ['?', '?', '?', '?', '?', '?', '?', '?', '?', '?', 'NOW()'];
                                                $restauranteParams = [
                                                    $nome,
                                                    $emailEncrypted,
                                                    $emailLookup,
                                                    $telefone === '' ? null : personal_data_encrypt($telefone),
                                                    $endereco === '' ? null : personal_data_encrypt($endereco),
                                                    $cidade,
                                                    $nuit === '' ? null : personal_data_encrypt($nuit),
                                                    personal_data_nuit_lookup($nuit),
                                                    $plano,
                                                    $data_fim,
                                                ];

                                                if (in_array('documento_titularidade_path', $colunasRestaurante, true)) {
                                                    $restauranteCols[] = 'documento_titularidade_path';
                                                    $restauranteVals[] = '?';
                                                    $restauranteParams[] = $documento_caminho_relativo;
                                                }

                                                if (in_array('telefone_lookup', $colunasRestaurante, true)) {
                                                    $idxTelefone = array_search('telefone', $restauranteCols, true);
                                                    if ($idxTelefone !== false) {
                                                        array_splice($restauranteCols, $idxTelefone + 1, 0, ['telefone_lookup']);
                                                        array_splice($restauranteVals, $idxTelefone + 1, 0, ['?']);
                                                        array_splice($restauranteParams, $idxTelefone + 1, 0, [
                                                            function_exists('personal_data_phone_lookup')
                                                                ? personal_data_phone_lookup($telefone)
                                                                : null,
                                                        ]);
                                                    }
                                                }

                                                if (in_array('logo', $colunasRestaurante, true)) {
                                                    $restauranteCols[] = 'logo';
                                                    $restauranteVals[] = '?';
                                                    $restauranteParams[] = $logo_caminho_relativo;
                                                }

                                                if ($status_inicial !== null) {
                                                    $restauranteCols[] = 'status';
                                                    $restauranteVals[] = '?';
                                                    $restauranteParams[] = $status_inicial;
                                                }

                                                $stmt = $db->prepare(
                                                    'INSERT INTO restaurantes (' . implode(', ', $restauranteCols) . ') VALUES (' . implode(', ', $restauranteVals) . ')'
                                                );
                                                $stmt->execute($restauranteParams);
                                                $restaurante_id = $db->lastInsertId();

                                                $stmt_user = $db->prepare("
                                                INSERT INTO usuarios (restaurante_id, nome, email, email_lookup, senha, perfil, ativo, created_at)
                                                VALUES (?, ?, ?, ?, ?, 'ADMIN', 0, NOW())
                                            ");
                                                $stmt_user->execute([$restaurante_id, 'Administrador', $emailEncrypted, $emailLookup, $senha_hash]);
                                                $usuario_id = (int)$db->lastInsertId();

                                                $colunas_compra = [];
                                                $stmt_cols = $db->query('SHOW COLUMNS FROM compras_planos');
                                                while ($col = $stmt_cols->fetch(PDO::FETCH_ASSOC)) {
                                                    $colunas_compra[] = $col['Field'];
                                                }

                                                if ($metodo !== 'TRIAL') {
                                                    if ($hash_arquivo === '') {
                                                        throw new RuntimeException('Nao foi possivel calcular hash do comprovativo.');
                                                    }
                                                    $duplicado = plan_security_purchase_find_duplicate_proof($db, $hash_arquivo);
                                                    if ($duplicado) {
                                                        audit_log($db, 'plano_comprovativo_duplicado_bloqueado', [
                                                            'hash_arquivo' => $hash_arquivo,
                                                            'restaurante_email' => $email,
                                                            'compra_existente_id' => (int)($duplicado['id'] ?? 0),
                                                            'restaurante_existente_id' => (int)($duplicado['restaurante_id'] ?? 0),
                                                        ], 'WARN');
                                                        throw new RuntimeException('Comprovativo ja utilizado em outra solicitacao. Envie um comprovativo valido.');
                                                    }
                                                }

                                                $statusPendenteCompra = plan_security_purchase_status_value($db, 'PENDING');
                                                $insert_cols = ['restaurante_id', 'plano_atual', 'plano_novo', 'valor', 'metodo_pagamento', 'status'];
                                                $insert_vals = ['?', '?', '?', '?', '?', '?'];
                                                $insert_params = [$restaurante_id, 'BASICO', $plano, $valor, $metodo, $statusPendenteCompra];

                                                if (in_array('ciclo', $colunas_compra, true)) {
                                                    $insert_cols[] = 'ciclo';
                                                    $insert_vals[] = '?';
                                                    $insert_params[] = $ciclo;
                                                }

                                                if ($metodo !== 'TRIAL') {
                                                    if (in_array('comprovativo_path', $colunas_compra, true)) {
                                                        $insert_cols[] = 'comprovativo_path';
                                                        $insert_vals[] = '?';
                                                        $insert_params[] = $caminho_relativo;
                                                    }

                                                    if (in_array('hash_arquivo', $colunas_compra, true)) {
                                                        $insert_cols[] = 'hash_arquivo';
                                                        $insert_vals[] = '?';
                                                        $insert_params[] = $hash_arquivo;
                                                    }

                                                    if (in_array('observacao', $colunas_compra, true)) {
                                                        $insert_cols[] = 'observacao';
                                                        $insert_vals[] = '?';
                                                        $insert_params[] = 'Ciclo: ' . $ciclo . ' | Comprovativo: ' . $caminho_relativo . ' | Hash: ' . substr($hash_arquivo, 0, 16);
                                                    }
                                                } else {
                                                    if (in_array('observacao', $colunas_compra, true)) {
                                                        $insert_cols[] = 'observacao';
                                                        $insert_vals[] = '?';
                                                        $insert_params[] = 'Solicitacao de trial Basico (' . $diasPlanoSolicitado . ' dias)';
                                                    }
                                                }

                                                if (in_array('created_at', $colunas_compra, true)) {
                                                    $insert_cols[] = 'created_at';
                                                    $insert_vals[] = 'NOW()';
                                                } elseif (in_array('criado_em', $colunas_compra, true)) {
                                                    $insert_cols[] = 'criado_em';
                                                    $insert_vals[] = 'NOW()';
                                                }

                                                $sql_compra = 'INSERT INTO compras_planos (' . implode(', ', $insert_cols) . ') VALUES (' . implode(', ', $insert_vals) . ')';
                                                $stmt_compra = $db->prepare($sql_compra);
                                                $stmt_compra->execute($insert_params);

                                                audit_log($db, 'plano_solicitacao_publica_recebida', [
                                                    'restaurante_id' => (int)$restaurante_id,
                                                    'plano' => $plano,
                                                    'ciclo' => $ciclo,
                                                    'valor' => $valor,
                                                    'risk_score' => (int)($risk['score'] ?? 0),
                                                    'risk_flags' => $risk['flags'] ?? [],
                                                    'fingerprint' => substr($fingerprint, 0, 16),
                                                ], ((int)($risk['score'] ?? 0) >= 40 ? 'WARN' : 'INFO'));

                                                legal_record_acceptance($db, [
                                                    'usuario_id' => $usuario_id,
                                                    'restaurante_id' => (int)$restaurante_id,
                                                    'email' => $email,
                                                    'contexto' => 'solicitar_plano',
                                                    'ip' => $ipSolicitacao,
                                                    'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'),
                                                ]);

                                                $db->commit();

                                                plano_notificar_solicitacao_recebida(
                                                    $email,
                                                    $telefone,
                                                    $nome,
                                                    $plano,
                                                    $ciclo,
                                                    $valor,
                                                    $metodo
                                                );

                                                $mensagem = 'Solicitacao enviada com sucesso! Aguarde a aprovacao do administrador.';
                                                $tipo_msg = 'success';
                                                $form = solicitar_plano_default_form();
                                                $_SESSION['solicitar_plano_form_started_at'] = time();
                                                $logo_caminho_absoluto = null;
                                                $logo_caminho_relativo = null;
                                            } catch (Throwable $e) {
                                                if ($db->inTransaction()) {
                                                    $db->rollBack();
                                                }
                                                if (is_file($caminho_absoluto)) {
                                                    @unlink($caminho_absoluto);
                                                }
                                                if ($logo_caminho_absoluto && is_file($logo_caminho_absoluto)) {
                                                    @unlink($logo_caminho_absoluto);
                                                }
                                                error_log('[SOLICITAR_PLANO] ' . $e->getMessage());
                                                $mensagem = (stripos($e->getMessage(), 'Comprovativo ja utilizado') !== false)
                                                    ? 'Comprovativo ja utilizado em outra solicitacao. Envie um comprovativo diferente.'
                                                    : 'Erro ao processar a solicitacao. Tente novamente em instantes.';
                                                $tipo_msg = 'danger';
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
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
    <title>Começar — Nivora Restaurant</title>
    <meta name="description" content="Escolha o plano Nivora Restaurant e leve o seu restaurante ao melhor momento.">
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,600;0,700;1,500&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/tokens.css?v=<?php echo (int)@filemtime(__DIR__ . '/css/tokens.css'); ?>">
    <link rel="stylesheet" href="css/auth-landing.css?v=<?php echo (int)@filemtime(__DIR__ . '/css/auth-landing.css'); ?>">
    <link rel="stylesheet" href="css/solicitar-plano.css?v=20260724b">
    <link rel="stylesheet" href="css/legal.css?v=20260808a">
    <link rel="stylesheet" href="css/feedback.css?v=<?php echo (int)filemtime(__DIR__ . '/css/feedback.css'); ?>">
    <script src="js/feedback.js?v=<?php echo (int)filemtime(__DIR__ . '/js/feedback.js'); ?>"></script>
</head>

<body class="edvistro-auth edvistro-plan">
    <div class="public-sidebar-backdrop" id="publicSidebarBackdrop" aria-hidden="true"></div>

    <header class="ed-plan-top">
        <a href="index.php" class="ed-plan-brand">NIVORA RESTAURANT</a>
        <nav class="ed-plan-nav" aria-label="Navegação">
            <a href="index.php">Início</a>
            <a href="index.php">Já tenho conta — Entrar</a>
            <button type="button" class="public-sidebar-toggle d-lg-none" id="publicSidebarToggleBtn" aria-label="Abrir menu" aria-expanded="false" aria-controls="sidebarMenu">
                <i class="fas fa-bars"></i>
            </button>
        </nav>
    </header>

    <div class="public-page-shell">
        <div class="container main-content" id="mainContent">
            <nav class="public-sidebar-menu" id="sidebarMenu" tabindex="-1" aria-label="Menu principal">
                <div class="public-sidebar-header">
                    <div class="public-sidebar-title">
                        <span class="public-sidebar-kicker">NIVORA RESTAURANT</span>
                        <strong>Menu</strong>
                        <span>Volte ao início ou fale connosco.</span>
                    </div>
                    <button type="button" class="public-sidebar-close" id="publicSidebarCloseBtn" aria-label="Fechar menu">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <ul class="public-sidebar-links">
                    <li><a href="index.php" class="public-sidebar-link"><i class="fas fa-home"></i><span>Início</span></a></li>
                    <li><a href="solicitar_plano.php" class="public-sidebar-link"><i class="fas fa-crown"></i><span>Começar</span></a></li>
                    <li><a href="https://wa.me/258840000000" class="public-sidebar-link"><i class="fab fa-whatsapp"></i><span>Suporte WhatsApp</span></a></li>
                </ul>
            </nav>

            <div class="ed-plan-intro">
                <h1>O seu restaurante,<br><em>no seu melhor momento.</em></h1>
                <p>Escolha o plano e comece — a presença que o cliente sente, a operação que o dono controla.</p>
            </div>

            <div class="row justify-content-center">
                <div class="col-lg-10">
                    <div class="card-plans">
                        <div class="card-header-custom">
                            <h3 class="mb-1">Começar com a Nivora Restaurant</h3>
                            <p class="mb-0 opacity-75">Dados do restaurante, plano e pagamento — em poucos minutos.</p>
                        </div>

                        <div class="card-body p-4">
                            <?php if ($mensagem): ?>
                                <div class="alert alert-<?php echo $tipo_msg; ?> alert-dismissible fade show" role="alert">
                                    <?php echo nl2br(solicitar_plano_escape($mensagem)); ?>
                                    <?php if ($tipo_msg === 'success'): ?>
                                        <br><small>Voce recebera um email quando sua solicitacao for aprovada.</small>
                                    <?php endif; ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                            <?php endif; ?>

                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="_csrf" value="<?php echo solicitar_plano_escape($csrf_token); ?>">
                                <div style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;" aria-hidden="true">
                                    <label for="website_hp">Website</label>
                                    <input type="text" id="website_hp" name="website" value="" tabindex="-1" autocomplete="off">
                                </div>
                                <!-- Seção: Dados do Restaurante -->
                                <div class="section-card">
                                    <div class="section-title"><i class="fas fa-store me-2 text-primary"></i>Dados do Restaurante</div>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Nome do Restaurante *</label>
                                            <input type="text" name="nome" class="form-control" placeholder="Nome do restaurante" value="<?php echo solicitar_plano_escape($form['nome']); ?>" minlength="3" maxlength="120" autocomplete="organization" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Email *</label>
                                            <input type="email" name="email" class="form-control" placeholder="Email de contacto" value="<?php echo solicitar_plano_escape($form['email']); ?>" autocomplete="email" maxlength="254" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Telefone *</label>
                                            <input type="tel" name="telefone" class="form-control" placeholder="Telefone com indicativo" value="<?php echo solicitar_plano_escape($form['telefone']); ?>" autocomplete="tel" minlength="8" maxlength="24" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Cidade *</label>
                                            <input type="text" name="cidade" class="form-control" placeholder="Cidade" value="<?php echo solicitar_plano_escape($form['cidade']); ?>" autocomplete="address-level2" minlength="2" maxlength="80" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Endereço *</label>
                                            <input type="text" name="endereco" class="form-control" placeholder="Endereço do restaurante" value="<?php echo solicitar_plano_escape($form['endereco']); ?>" autocomplete="street-address" minlength="4" maxlength="180" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">NUIT *</label>
                                            <input type="text" name="nuit" class="form-control" placeholder="9 dígitos" value="<?php echo solicitar_plano_escape($form['nuit']); ?>" inputmode="numeric" pattern="[0-9]{9}" maxlength="9" required>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Logotipo do Restaurante *</label>
                                            <div class="input-group">
                                                <input type="file" name="logo" class="form-control" id="logo_restaurante" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" onchange="previewLogoRestaurante(this)">
                                            </div>
                                            <small class="text-muted">Formatos aceitos: JPG, PNG ou WEBP (máx. 4MB)</small>
                                            <div id="preview_logo_restaurante" class="mt-2" style="display: none;">
                                                <img id="img_logo_restaurante" src="" alt="Preview do logotipo" style="max-width: 180px; max-height: 180px; border-radius: 14px; border: 2px solid #dee2e6; background: #fff; object-fit: contain; padding: 8px;">
                                                <div id="arquivo_logo_info" class="small text-muted mt-2"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Seção: Escolha do Plano -->
                                <div class="section-title"><i class="fas fa-crown me-2 text-warning"></i>Escolha o Plano</div>
                                <div class="row g-3 mb-4 plans-grid align-items-stretch">
                                    <!-- Plano Básico -->
                                    <div class="col-md-4 plan-col">
                                        <div class="plan-option plan-basic" onclick="selecionarPlano('BASICO')" role="button" tabindex="0">
                                            <div class="plan-option-head">
                                                <div class="plan-icon"><i class="fas fa-user"></i></div>
                                                <div class="plan-name">Básico</div>
                                                <div class="plan-price"><?php echo format_mzn($preco_basico['mensal']); ?><span>MZN/mês</span></div>
                                                <?php if ($trialAtivo): ?>
                                                    <div class="plan-trial-note">Trial gratuito de <?php echo (int)plan_security_trial_days('BASICO'); ?> dias</div>
                                                <?php else: ?>
                                                    <div class="plan-trial-note"><i class="fas fa-gift me-1"></i>1.º pagamento: <?php echo (int)BONUS_DIAS; ?> dias de Profissional</div>
                                                <?php endif; ?>
                                                <div class="plan-cycle">Tri <?php echo format_mzn($preco_basico['trimestral']); ?> · Anual <?php echo format_mzn($preco_basico['anual']); ?></div>
                                            </div>
                                            <ul class="plan-option-features">
                                                <?php foreach (($recursos_planos_catalogo['BASICO'] ?? []) as $recurso): ?>
                                                    <li><i class="fas fa-check"></i><span><?php echo solicitar_plano_escape($recurso); ?></span></li>
                                                <?php endforeach; ?>
                                            </ul>
                                            <div class="plan-select-indicator"><span class="plan-select-dot"></span><span>Selecionar</span></div>
                                            <input type="radio" name="plano_selecionado" id="plano_basico" value="BASICO" class="d-none">
                                        </div>
                                    </div>

                                    <!-- Plano Profissional -->
                                    <div class="col-md-4 plan-col">
                                        <div class="plan-option plan-pro" onclick="selecionarPlano('PROFISSIONAL')" role="button" tabindex="0">
                                            <span class="plan-badge">Mais escolhido</span>
                                            <div class="plan-option-head">
                                                <div class="plan-icon"><i class="fas fa-star"></i></div>
                                                <div class="plan-name">Profissional</div>
                                                <div class="plan-price"><?php echo format_mzn($preco_profissional['mensal']); ?><span>MZN/mês</span></div>
                                                <?php if (!$trialAtivo): ?><div class="plan-trial-note"><i class="fas fa-gift me-1"></i>1.º pagamento: <?php echo (int)BONUS_DIAS; ?> dias de Empresarial</div><?php endif; ?>
                                                <div class="plan-cycle">Tri <?php echo format_mzn($preco_profissional['trimestral']); ?> · Anual <?php echo format_mzn($preco_profissional['anual']); ?></div>
                                            </div>
                                            <ul class="plan-option-features">
                                                <?php foreach (($recursos_planos_catalogo['PROFISSIONAL'] ?? []) as $recurso): ?>
                                                    <li><i class="fas fa-check"></i><span><?php echo solicitar_plano_escape($recurso); ?></span></li>
                                                <?php endforeach; ?>
                                            </ul>
                                            <div class="plan-select-indicator"><span class="plan-select-dot"></span><span>Selecionar</span></div>
                                            <input type="radio" name="plano_selecionado" id="plano_profissional" value="PROFISSIONAL" class="d-none">
                                        </div>
                                    </div>

                                    <!-- Plano Empresarial -->
                                    <div class="col-md-4 plan-col">
                                        <div class="plan-option plan-enterprise" onclick="selecionarPlano('EMPRESARIAL')" role="button" tabindex="0">
                                            <div class="plan-option-head">
                                                <div class="plan-icon"><i class="fas fa-crown"></i></div>
                                                <div class="plan-name">Empresarial</div>
                                                <div class="plan-price"><?php echo format_mzn($preco_empresarial['mensal']); ?><span>MZN/mês</span></div>
                                                <?php if (!$trialAtivo): ?><div class="plan-trial-note"><i class="fas fa-gift me-1"></i>1.º pagamento: <?php echo (int)BONUS_DIAS; ?> dias extra de plano</div><?php endif; ?>
                                                <div class="plan-cycle">Tri <?php echo format_mzn($preco_empresarial['trimestral']); ?> · Anual <?php echo format_mzn($preco_empresarial['anual']); ?></div>
                                            </div>
                                            <ul class="plan-option-features">
                                                <?php foreach (($recursos_planos_catalogo['EMPRESARIAL'] ?? []) as $recurso): ?>
                                                    <li><i class="fas fa-check"></i><span><?php echo solicitar_plano_escape($recurso); ?></span></li>
                                                <?php endforeach; ?>
                                            </ul>
                                            <div class="plan-select-indicator"><span class="plan-select-dot"></span><span>Selecionar</span></div>
                                            <input type="radio" name="plano_selecionado" id="plano_empresarial" value="EMPRESARIAL" class="d-none">
                                        </div>
                                    </div>
                                </div>

                                <!-- Campo oculto para o plano selecionado -->
                                <input type="hidden" name="plano" id="plano_escolhido" value="<?php echo solicitar_plano_escape($form['plano']); ?>">
                                <input type="hidden" name="ciclo" id="ciclo_escolhido" value="<?php echo solicitar_plano_escape($form['ciclo']); ?>">
                                <input type="hidden" name="metodo" id="metodo_pagamento" value="<?php echo solicitar_plano_escape($form['metodo']); ?>">

                                <!-- Seção: Ciclo e pagamento -->
                                <div id="secao_pagamento" style="display: none;">
                                    <div class="section-card">
                                        <div class="section-title"><i class="fas fa-credit-card me-2 text-success"></i>Informações de Pagamento</div>
                                        <div class="row g-3">
                                            <div class="col-12">
                                                <label class="form-label">Período da Assinatura *</label>
                                                <div class="choice-grid" id="ciclo_grid">
                                                    <button type="button" class="choice-option active" data-ciclo="MENSAL" onclick="selecionarCiclo('MENSAL')">
                                                        <div class="title">Mensal</div>
                                                        <div class="subtitle">Cobrança a cada 30 dias</div>
                                                    </button>
                                                    <button type="button" class="choice-option" data-ciclo="TRIMESTRAL" onclick="selecionarCiclo('TRIMESTRAL')">
                                                        <div class="title">Trimestral</div>
                                                        <div class="subtitle">Melhor custo-benefício</div>
                                                    </button>
                                                    <button type="button" class="choice-option" data-ciclo="ANUAL" onclick="selecionarCiclo('ANUAL')">
                                                        <div class="title">Anual</div>
                                                        <div class="subtitle">Maior economia</div>
                                                    </button>
                                                </div>
                                            </div>

                                            <div class="col-12" id="plano_trial_info" style="display: none;">
                                                <div class="alert alert-success mb-0">
                                                    <i class="fas fa-gift me-2"></i>
                                                    Plano Básico mensal: <strong>trial gratuito de <?php echo (int)plan_security_trial_days('BASICO'); ?> dias</strong>. Não precisa de pagamento agora. Se preferir pagar já, escolha Trimestral ou Anual.
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="payment-summary">
                                                    <div>Resumo da cobrança</div>
                                                    <strong id="resumo_valor">0 MZN</strong>
                                                    <div id="resumo_plano" class="small">Selecione um plano para ver o valor final</div>
                                                </div>
                                            </div>

                                            <div id="pagamento_campos_pagos" class="col-12">
                                                <div class="row g-3">
                                                    <div class="col-12">
                                                        <label class="form-label">Método de Pagamento *</label>
                                                        <div class="choice-grid" id="metodo_grid">
                                                            <button type="button" class="choice-option" data-metodo="MPESA" onclick="selecionarMetodo('MPESA')">
                                                                <div class="title">M-Pesa</div>
                                                                <div class="subtitle">Pagamento móvel instantâneo</div>
                                                            </button>
                                                            <button type="button" class="choice-option" data-metodo="EMOLA" onclick="selecionarMetodo('EMOLA')">
                                                                <div class="title">e-Mola</div>
                                                                <div class="subtitle">Pagamento móvel instantâneo</div>
                                                            </button>
                                                            <button type="button" class="choice-option" data-metodo="TRANSFERENCIA" onclick="selecionarMetodo('TRANSFERENCIA')">
                                                                <div class="title">Transferência</div>
                                                                <div class="subtitle">Pagamento Bancario</div>
                                                            </button>
                                                        </div>
                                                    </div>

                                                    <div class="col-12">
                                                        <div class="alert alert-info mb-0">
                                                            <i class="fas fa-info-circle me-2"></i>
                                                            <strong>Instruções de pagamento:</strong><br>
                                                            <span id="instrucoes_pagamento"></span>
                                                        </div>
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label">Foto do Comprovativo *</label>
                                                        <div class="input-group">
                                                            <input type="file" name="comprovativo" class="form-control" id="comprovativo" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" onchange="previewComprovativo(this)">
                                                        </div>
                                                        <small class="text-muted">Formatos aceitos: JPG, PNG, PDF (máx. 5MB)</small>
                                                        <div id="preview_comprovativo" class="mt-2" style="display: none;">
                                                            <img id="img_comprovativo" src="" alt="Preview" style="max-width: 200px; border-radius: 8px; border: 2px solid #dee2e6;">
                                                            <div id="arquivo_comprovativo_info" class="small text-muted mt-2"></div>
                                                        </div>
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label">Documento de titularidade/representação *</label>
                                                        <input type="file" name="documento_titularidade" class="form-control" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" required>
                                                        <small class="text-muted">JPG, PNG ou PDF (máx. 5MB). Será analisado pelo super administrador.</small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="alert alert-warning alert-premium">
                                    <i class="fas fa-clock me-2"></i>
                                    <strong>Atenção:</strong> A sua solicitação será analisada. Quando for aprovada, receberá um email com um link seguro para definir a senha e ativar o acesso.
                                </div>

                                <div class="form-check legal-plan-acceptance">
                                    <input class="form-check-input" type="checkbox" value="1" id="aceite_legal" name="aceite_legal" required <?= !empty($_POST['aceite_legal']) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="aceite_legal">
                                        Li e aceito os <a href="termos.php" target="_blank" rel="noopener">Termos de Uso</a> e confirmo que tomei conhecimento da <a href="privacidade.php" target="_blank" rel="noopener">Politica de Privacidade</a>.
                                    </label>
                                </div>

                                <div class="text-center">
                                    <button type="submit" class="btn btn-primary-custom btn-lg">
                                        Quero o meu restaurante assim
                                    </button>
                                </div>
                            </form>

                            <hr class="my-4" style="border-color: rgba(255,246,234,0.08);">

                            <div class="text-center">
                                <p class="text-muted mb-2">Já tem conta?</p>
                                <a href="index.php" class="btn btn-outline-primary">Já tenho conta — Entrar</a>
                            </div>
                        </div>
                    </div>

                    <?php $legalFooterCompact = true; include __DIR__ . '/includes/legal_footer.php'; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
        const publicSidebar = document.getElementById('sidebarMenu');
        const publicSidebarToggleBtn = document.getElementById('publicSidebarToggleBtn');
        const publicSidebarCloseBtn = document.getElementById('publicSidebarCloseBtn');
        const publicSidebarBackdrop = document.getElementById('publicSidebarBackdrop');
        const publicMainContent = document.getElementById('mainContent');
        const publicMobileQuery = window.matchMedia('(max-width: 991px)');

        function closeSidebar() {
            document.body.classList.remove('public-menu-open');
            publicSidebar.classList.remove('is-open');
            publicMainContent.classList.remove('main-content-blur');
            if (publicSidebarToggleBtn) {
                publicSidebarToggleBtn.setAttribute('aria-expanded', 'false');
            }
        }

        function openSidebar() {
            if (!publicMobileQuery.matches) {
                return;
            }

            document.body.classList.add('public-menu-open');
            publicSidebar.classList.add('is-open');
            publicMainContent.classList.add('main-content-blur');
            if (publicSidebarToggleBtn) {
                publicSidebarToggleBtn.setAttribute('aria-expanded', 'true');
            }
        }

        function toggleSidebar() {
            if (document.body.classList.contains('public-menu-open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        }

        if (publicSidebarToggleBtn) {
            publicSidebarToggleBtn.addEventListener('click', toggleSidebar);
        }

        if (publicSidebarCloseBtn) {
            publicSidebarCloseBtn.addEventListener('click', closeSidebar);
        }

        if (publicSidebarBackdrop) {
            publicSidebarBackdrop.addEventListener('click', closeSidebar);
        }

        publicSidebar.querySelectorAll('a').forEach(function(link) {
            link.addEventListener('click', closeSidebar);
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeSidebar();
            }
        });

        window.addEventListener('resize', function() {
            if (!publicMobileQuery.matches) {
                closeSidebar();
            }
        });
    </script>
    <script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
        var formElement = document.querySelector('form');
        var submitButton = formElement.querySelector('button[type="submit"]');
        var submitStatus = document.createElement('div');
        submitStatus.className = 'alert alert-info mt-3';
        submitStatus.style.display = 'none';
        submitStatus.setAttribute('role', 'status');
        submitButton.parentNode.appendChild(submitStatus);
        var planoSelecionado = <?php echo json_encode($form['plano'], JSON_UNESCAPED_UNICODE); ?>;
        var cicloSelecionado = <?php echo json_encode($form['ciclo'], JSON_UNESCAPED_UNICODE); ?>;
        var valorPlano = 0;
        var metodoSelecionado = <?php echo json_encode($form['metodo'], JSON_UNESCAPED_UNICODE); ?>;
        var TRIAL_ATIVO = <?php echo $trialAtivo ? 'true' : 'false'; ?>;
        var planoBasicoTrial = false;
        var planoPaymentInstructions = <?php echo json_encode($planoPaymentInstructions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

        var precosPorPlano = <?php echo json_encode([
                                    'BASICO' => [
                                        'MENSAL' => (int)$preco_basico['mensal'],
                                        'TRIMESTRAL' => (int)$preco_basico['trimestral'],
                                        'ANUAL' => (int)$preco_basico['anual'],
                                    ],
                                    'PROFISSIONAL' => [
                                        'MENSAL' => (int)$preco_profissional['mensal'],
                                        'TRIMESTRAL' => (int)$preco_profissional['trimestral'],
                                        'ANUAL' => (int)$preco_profissional['anual'],
                                    ],
                                    'EMPRESARIAL' => [
                                        'MENSAL' => (int)$preco_empresarial['mensal'],
                                        'TRIMESTRAL' => (int)$preco_empresarial['trimestral'],
                                        'ANUAL' => (int)$preco_empresarial['anual'],
                                    ],
                                ], JSON_UNESCAPED_UNICODE); ?>;

        function atualizarEstadoPagamento() {
            var secaoPagamento = document.getElementById('secao_pagamento');
            var camposPagos = document.getElementById('pagamento_campos_pagos');
            var campoComprovativo = document.getElementById('comprovativo');

            planoBasicoTrial = TRIAL_ATIVO && planoSelecionado === 'BASICO' && cicloSelecionado === 'MENSAL';

            if (!planoSelecionado) {
                secaoPagamento.style.display = 'none';
                if (camposPagos) camposPagos.style.display = 'none';
                campoComprovativo.required = false;
                metodoSelecionado = '';
                document.getElementById('metodo_pagamento').value = '';
                return;
            }

            // Sempre mostra ciclo + resumo quando há plano (inclui trial Básico mensal)
            secaoPagamento.style.display = 'block';

            if (planoBasicoTrial) {
                if (camposPagos) camposPagos.style.display = 'none';
                campoComprovativo.required = false;
                metodoSelecionado = 'TRIAL';
                document.getElementById('metodo_pagamento').value = 'TRIAL';
                document.querySelectorAll('#metodo_grid .choice-option').forEach(function(btn) {
                    btn.classList.remove('active');
                });
            } else {
                if (camposPagos) camposPagos.style.display = 'block';
                campoComprovativo.required = true;
                if (metodoSelecionado === 'TRIAL') {
                    metodoSelecionado = '';
                }
                document.getElementById('metodo_pagamento').value = metodoSelecionado || '';
            }
        }

        function atualizarPlanoTrialInfo() {
            var infoEl = document.getElementById('plano_trial_info');
            if (!infoEl) {
                return;
            }

            if (planoSelecionado === 'BASICO' && cicloSelecionado === 'MENSAL') {
                infoEl.style.display = 'block';
            } else {
                infoEl.style.display = 'none';
            }
        }

        function formatarPlano(plano) {
            if (!plano) {
                return 'Nao selecionado';
            }

            return plano.charAt(0) + plano.slice(1).toLowerCase();
        }

        function selecionarPlano(plano) {
            planoSelecionado = plano;
            valorPlano = (precosPorPlano[plano] && precosPorPlano[plano][cicloSelecionado]) ? precosPorPlano[plano][cicloSelecionado] : 0;

            document.querySelectorAll('.plan-option').forEach(function(el) {
                el.classList.remove('selected');
            });

            if (plano === 'BASICO') {
                document.getElementById('plano_basico').closest('.plan-option').classList.add('selected');
                document.getElementById('plano_basico').checked = true;
            } else if (plano === 'PROFISSIONAL') {
                document.getElementById('plano_profissional').closest('.plan-option').classList.add('selected');
                document.getElementById('plano_profissional').checked = true;
            } else if (plano === 'EMPRESARIAL') {
                document.getElementById('plano_empresarial').closest('.plan-option').classList.add('selected');
                document.getElementById('plano_empresarial').checked = true;
            }

            document.getElementById('plano_escolhido').value = plano;
            document.getElementById('ciclo_escolhido').value = cicloSelecionado;
            atualizarEstadoPagamento();
            atualizarResumoPagamento();
            atualizarPlanoTrialInfo();
            atualizarInstrucoes();
        }

        function selecionarCiclo(ciclo) {
            cicloSelecionado = ciclo;
            document.getElementById('ciclo_escolhido').value = ciclo;

            document.querySelectorAll('#ciclo_grid .choice-option').forEach(function(btn) {
                btn.classList.remove('active');
            });
            var alvo = document.querySelector('#ciclo_grid .choice-option[data-ciclo="' + ciclo + '"]');
            if (alvo) alvo.classList.add('active');

            if (planoSelecionado) {
                valorPlano = precosPorPlano[planoSelecionado][cicloSelecionado] || valorPlano;
                atualizarEstadoPagamento();
                atualizarResumoPagamento();
                atualizarPlanoTrialInfo();
                atualizarInstrucoes();
            }
        }

        function selecionarMetodo(metodo) {
            if (planoBasicoTrial) {
                return;
            }
            metodoSelecionado = metodo;
            document.getElementById('metodo_pagamento').value = metodo;

            document.querySelectorAll('#metodo_grid .choice-option').forEach(function(btn) {
                btn.classList.remove('active');
            });
            var alvo = document.querySelector('#metodo_grid .choice-option[data-metodo="' + metodo + '"]');
            if (alvo) alvo.classList.add('active');

            atualizarInstrucoes();
        }

        function atualizarResumoPagamento() {
            var elValor = document.getElementById('resumo_valor');
            var elPlano = document.getElementById('resumo_plano');
            var planoLabel = formatarPlano(planoSelecionado);
            var cicloLabel = cicloSelecionado.charAt(0) + cicloSelecionado.slice(1).toLowerCase();

            if (planoBasicoTrial) {
                elValor.textContent = '0 MZN';
                elPlano.textContent = 'Plano Básico | Trial gratuito de 30 dias';
                return;
            }

            elValor.textContent = (valorPlano || 0).toLocaleString('pt-PT') + ' MZN';
            elPlano.textContent = 'Plano ' + planoLabel + ' | Ciclo ' + cicloLabel;
        }

        function atualizarInstrucoes() {
            var el = document.getElementById('instrucoes_pagamento');
            if (!el) {
                return;
            }
            var instrucoes = '';
            var metodo = metodoSelecionado;

            if (planoBasicoTrial) {
                instrucoes = 'Trial gratuito de 30 dias — sem comprovativo neste momento.';
            } else if (planoPaymentInstructions[metodo]) {
                var dadosMetodo = planoPaymentInstructions[metodo];
                instrucoes = String(dadosMetodo.mensagem || '')
                    .replace('{valor}', Number(valorPlano || 0).toLocaleString('pt-MZ'))
                    .replace('{destino}', String(dadosMetodo.destino || ''));
            } else if (metodo === 'MPESA') {
                instrucoes = 'Envie ' + valorPlano + ' MZN para o número +258 84 9087533 e anexe o comprovativo.';
            } else if (metodo === 'EMOLA') {
                instrucoes = 'Envie ' + valorPlano + ' MZN por e-Mola para o número +258 87 9778409 e anexe o comprovativo.';
            } else if (metodo === 'TRANSFERENCIA') {
                instrucoes = 'Transfira para a conta BCI - #3456789088 / Millennium - #123097654 e anexe o comprovativo da operação.';
            } else {
                instrucoes = 'Selecione o método de pagamento para ver as instruções detalhadas.';
            }

            el.textContent = instrucoes;
        }

        formElement.addEventListener('submit', function(e) {
            var comprovativoInput = document.getElementById('comprovativo');

            if (!planoSelecionado) {
                e.preventDefault();
                NivoraFeedback.warning('Selecione um plano para continuar.');
                return;
            }
            if (!metodoSelecionado && !planoBasicoTrial) {
                e.preventDefault();
                NivoraFeedback.warning('Selecione um método de pagamento para continuar.');
                return;
            }
            if (!planoBasicoTrial && (!comprovativoInput.files || !comprovativoInput.files[0])) {
                e.preventDefault();
                NivoraFeedback.warning('Anexe o comprovativo para continuar.');
                return;
            }

            submitButton.disabled = true;
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Enviando...';
            submitStatus.textContent = 'A enviar solicitacao. Aguarde e nao clique novamente.';
            submitStatus.style.display = 'block';
            setTimeout(function() {
                submitStatus.className = 'alert alert-warning mt-3';
                submitStatus.textContent = 'Ainda estamos a processar. Se o comprovativo for pesado, pode levar alguns segundos.';
            }, 10000);
            setTimeout(function() {
                submitStatus.className = 'alert alert-warning mt-3';
                submitStatus.textContent = 'O servidor ainda esta a responder. Nao feche esta janela para evitar pedido duplicado.';
            }, 25000);
        });

        function previewComprovativo(input) {
            var preview = document.getElementById('preview_comprovativo');
            var img = document.getElementById('img_comprovativo');
            var info = document.getElementById('arquivo_comprovativo_info');

            if (input.files && input.files[0]) {
                var file = input.files[0];
                var tamanhoKb = Math.max(1, Math.round(file.size / 1024));
                info.textContent = file.name + ' (' + tamanhoKb + ' KB)';
                preview.style.display = 'block';

                if (file.type.startsWith('image/')) {
                    img.style.display = 'block';
                    var reader = new FileReader();
                    reader.onload = function(e) {
                        img.src = e.target.result;
                    };
                    reader.readAsDataURL(file);
                } else {
                    img.src = '';
                    img.style.display = 'none';
                }
            }

            if (!input.files || !input.files[0]) {
                img.src = '';
                img.style.display = 'none';
                info.textContent = '';
                preview.style.display = 'none';
            }
        }

        function previewLogoRestaurante(input) {
            var preview = document.getElementById('preview_logo_restaurante');
            var img = document.getElementById('img_logo_restaurante');
            var info = document.getElementById('arquivo_logo_info');

            if (input.files && input.files[0]) {
                var file = input.files[0];
                var tamanhoKb = Math.max(1, Math.round(file.size / 1024));
                info.textContent = file.name + ' (' + tamanhoKb + ' KB)';
                preview.style.display = 'block';

                if (file.type.startsWith('image/')) {
                    var reader = new FileReader();
                    reader.onload = function(e) {
                        img.src = e.target.result;
                    };
                    reader.readAsDataURL(file);
                } else {
                    img.src = '';
                }
                return;
            }

            img.src = '';
            info.textContent = '';
            preview.style.display = 'none';
        }

        document.addEventListener('DOMContentLoaded', function() {
            atualizarEstadoPagamento();
            selecionarCiclo(cicloSelecionado || 'MENSAL');

            if (planoSelecionado) {
                selecionarPlano(planoSelecionado);
            } else {
                atualizarResumoPagamento();
                atualizarInstrucoes();
            }

            if (metodoSelecionado) {
                selecionarMetodo(metodoSelecionado);
            }
        });
    </script>
</body>

</html>
