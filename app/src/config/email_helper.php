<?php

/*
   Helper de envio de email — reutilizável em toda a aplicação.
   Inclua este arquivo onde precisar enviar emails.
*/

if (!function_exists('saas_email_project_root')) {
    function saas_email_project_root(): string
    {
        return dirname(__DIR__, 2);
    }
}

if (!function_exists('saas_email_debug_log')) {
    function saas_email_debug_log(string $mensagem): void
    {
        $logPath = saas_email_project_root() . '/debug_email.log';
        @file_put_contents($logPath, date('Y-m-d H:i:s') . ' | ' . $mensagem . PHP_EOL, FILE_APPEND);
    }
}

if (!function_exists('saas_email_set_last_error')) {
    function saas_email_set_last_error(?string $mensagem): void
    {
        $GLOBALS['saas_email_last_error'] = $mensagem !== null ? trim($mensagem) : null;
    }
}

if (!function_exists('saas_email_get_last_error')) {
    function saas_email_get_last_error(): ?string
    {
        $valor = $GLOBALS['saas_email_last_error'] ?? null;
        return is_string($valor) && trim($valor) !== '' ? trim($valor) : null;
    }
}

if (!function_exists('saas_email_classify_error')) {
    function saas_email_classify_error(?string $erro, string $smtpHost = '', $smtpPort = null, string $smtpSecure = ''): array
    {
        $erro = trim((string)$erro);
        $lower = strtolower($erro);
        $host = trim($smtpHost) !== '' ? trim($smtpHost) : 'SMTP_HOST';
        $port = $smtpPort !== null && trim((string)$smtpPort) !== '' ? trim((string)$smtpPort) : 'SMTP_PORT';
        $secure = trim($smtpSecure) !== '' ? strtoupper(trim($smtpSecure)) : 'TLS/SSL';

        if ($erro === '') {
            return [
                'code' => 'SMTP_UNKNOWN',
                'message' => 'Nao foi possivel enviar o email neste momento.',
                'detail' => '',
            ];
        }

        if (
            strpos($lower, 'could not connect to smtp host') !== false
            || strpos($lower, 'failed to connect to server') !== false
            || strpos($lower, 'connection timed out') !== false
            || strpos($lower, 'network is unreachable') !== false
            || strpos($lower, 'connection refused') !== false
            || strpos($lower, 'hospedagem bloqueia smtp') !== false
        ) {
            $blockedHint = function_exists('saas_email_host_blocks_smtp') && saas_email_host_blocks_smtp();
            $message = $blockedHint
                ? 'Esta hospedagem bloqueia SMTP externo (Gmail nas portas 587/465). Use EMAIL_PROVIDER=brevo com BREVO_API_KEY ou EMAIL_PROVIDER=resend com RESEND_API_KEY (envio por HTTPS).'
                : 'O servidor nao conseguiu ligar ao SMTP. Verifique internet/firewall do servidor e confirme SMTP_HOST=' . $host . ', SMTP_PORT=' . $port . ' e SMTP_SECURE=' . $secure . '.';

            return [
                'code' => 'SMTP_CONNECT_FAILED',
                'message' => $message,
                'detail' => $erro,
            ];
        }

        if (
            strpos($lower, 'authenticate') !== false
            || strpos($lower, 'authentication') !== false
            || strpos($lower, 'username and password not accepted') !== false
        ) {
            return [
                'code' => 'SMTP_AUTH_FAILED',
                'message' => 'O provedor recusou a autenticacao SMTP. Confirme o email remetente e use uma senha de app nova em SMTP_PASSWORD.',
                'detail' => $erro,
            ];
        }

        if (
            strpos($lower, 'resend api falhou') !== false
            || strpos($lower, 'resend sdk falhou') !== false
        ) {
            return [
                'code' => 'RESEND_API_FAILED',
                'message' => 'A Resend recusou o envio. Confirme RESEND_API_KEY e use em RESEND_FROM um remetente de dominio verificado.',
                'detail' => $erro,
            ];
        }

        if (strpos($lower, 'brevo api falhou') !== false) {
            return [
                'code' => 'BREVO_API_FAILED',
                'message' => 'A Brevo recusou o envio. Confirme BREVO_API_KEY e o remetente verificado.',
                'detail' => $erro,
            ];
        }

        return [
            'code' => 'SMTP_SEND_FAILED',
            'message' => 'Nao foi possivel enviar o email neste momento. Verifique a configuracao SMTP e tente novamente.',
            'detail' => $erro,
        ];
    }
}

if (!function_exists('saas_email_env_file_candidates')) {
    function saas_email_env_file_candidates(): array
    {
        $projectRoot = saas_email_project_root();

        return [
            $projectRoot . '/.env',
            $projectRoot . '/.env.local',
            dirname($projectRoot) . '/.env',
            dirname($projectRoot) . '/.env.local',
            $projectRoot . '/src/.env',
            $projectRoot . '/src/.env.local',
        ];
    }
}

if (!function_exists('saas_email_parse_env_file')) {
    function saas_email_parse_env_file(string $path): array
    {
        $result = [];

        if (!is_file($path) || !is_readable($path)) {
            return $result;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return $result;
        }

        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }

            if (strpos($line, 'export ') === 0) {
                $line = trim(substr($line, 7));
            }

            $separator = strpos($line, '=');
            if ($separator === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separator));
            $key = trim($key, " \t\n\r\0\x0B*");
            $value = trim(substr($line, $separator + 1));

            if ($key === '') {
                continue;
            }

            if ($value !== '') {
                $firstChar = substr($value, 0, 1);
                $lastChar = substr($value, -1);
                if (
                    ($firstChar === '"' && $lastChar === '"')
                    || ($firstChar === "'" && $lastChar === "'")
                ) {
                    $value = substr($value, 1, -1);
                    if ($firstChar === '"') {
                        $value = stripcslashes($value);
                    }
                } else {
                    $commentPos = strpos($value, ' #');
                    if ($commentPos !== false) {
                        $value = rtrim(substr($value, 0, $commentPos));
                    }
                }

                if (preg_match('/^\[([^\]]+)\]\(\1\)$/', $value, $matches)) {
                    $value = $matches[1];
                }

                $value = str_replace(['\\@', '\\<', '\\>'], ['@', '<', '>'], $value);
            }

            $result[$key] = $value;
        }

        return $result;
    }
}

if (!function_exists('saas_email_load_local_env')) {
    function saas_email_load_local_env(): array
    {
        static $loaded = false;
        static $values = [];

        if ($loaded) {
            return $values;
        }

        $loaded = true;

        foreach (saas_email_env_file_candidates() as $path) {
            if (!is_file($path)) {
                continue;
            }

            $values = array_merge($values, saas_email_parse_env_file($path));
        }

        foreach ($values as $key => $value) {
            // Configuracao injectada pelo servidor/processo tem prioridade.
            // O .env local serve apenas como fallback para valores ausentes.
            $processValue = getenv($key);
            $envValue = $_ENV[$key] ?? null;
            $serverValue = $_SERVER[$key] ?? null;
            $alreadyDefined = ($processValue !== false && $processValue !== '')
                || ($envValue !== null && $envValue !== '')
                || ($serverValue !== null && $serverValue !== '');
            if ($alreadyDefined) {
                continue;
            }

            if (function_exists('putenv')) {
                putenv($key . '=' . $value);
            }
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        return $values;
    }
}

if (!function_exists('saas_email_is_placeholder_config')) {
    function saas_email_is_placeholder_config(array $config): bool
    {
        $smtpHost = strtolower(trim((string)($config['smtp_host'] ?? '')));
        $smtpUsername = (string)($config['smtp_username'] ?? '');
        $smtpPassword = (string)($config['smtp_password'] ?? '');
        $smtpFrom = (string)($config['smtp_from'] ?? '');

        return $smtpHost === 'smtp.test'
            || $smtpHost === ''
            || substr($smtpHost, -5) === '.test'
            || strpos($smtpHost, 'example') !== false
            || trim($smtpUsername) === ''
            || strpos($smtpUsername, 'seu-email') !== false
            || trim($smtpPassword) === ''
            || strpos($smtpPassword, 'sua-senha') !== false
            || trim($smtpFrom) === ''
            || strpos($smtpFrom, 'seusite.com') !== false;
    }
}

if (!function_exists('saas_email_env_value')) {
    function saas_email_is_production_environment(): bool
    {
        $environment = getenv('APP_ENV');
        if ($environment === false && array_key_exists('APP_ENV', $_ENV)) {
            $environment = $_ENV['APP_ENV'];
        }
        if ($environment === false && array_key_exists('APP_ENV', $_SERVER)) {
            $environment = $_SERVER['APP_ENV'];
        }

        return in_array(strtolower(trim((string)$environment)), ['production', 'prod'], true);
    }

    function saas_email_env_value(string $key): ?string
    {
        $localValues = saas_email_load_local_env();

        // Durante o desenvolvimento, o .env do projeto deve controlar o SMTP.
        // Alguns servidores locais/IDEs injetam SMTP_HOST antigo no processo e,
        // se misturado com as demais chaves do .env, o envio falha sem uma causa
        // visivel para quem esta a testar. Em producao, segredos do ambiente
        // continuam a ter prioridade sobre qualquer arquivo local.
        $isSmtpSetting = $key === 'USE_SMTP' || strpos($key, 'SMTP_') === 0;
        if ($isSmtpSetting && !saas_email_is_production_environment()) {
            $localValue = $localValues[$key] ?? null;
            if (is_string($localValue) && trim($localValue) !== '') {
                return trim($localValue);
            }
        }

        $value = getenv($key);
        if ($value === false && array_key_exists($key, $_ENV)) {
            $value = $_ENV[$key];
        }
        if ($value === false && array_key_exists($key, $_SERVER)) {
            $value = $_SERVER[$key];
        }

        if ($value === false) {
            return null;
        }

        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }
}

if (!function_exists('saas_email_host_blocks_smtp')) {
    function saas_email_host_blocks_smtp(): bool
    {
        $flag = saas_email_env_value('SMTP_OUTBOUND_BLOCKED');
        if ($flag !== null && filter_var($flag, FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        $httpHost = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
        $serverName = strtolower(trim((string)($_SERVER['SERVER_NAME'] ?? '')));
        $combined = $httpHost . ' ' . $serverName;

        foreach (['infinityfree', 'page.gd', 'rf.gd', '42web.io', 'epizy.com', 'great-site.net', 'unaux.com', 'free.nf'] as $marker) {
            if ($combined !== ' ' && strpos($combined, $marker) !== false) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('saas_email_auto_api_provider')) {
    function saas_email_auto_api_provider(): ?string
    {
        $brevo = trim((string)(saas_email_env_value('BREVO_API_KEY') ?? ''));
        if ($brevo !== '') {
            return 'brevo';
        }

        $resend = trim((string)(saas_email_env_value('RESEND_API_KEY') ?? ''));
        $mailHost = strtolower(trim((string)(saas_email_env_value('MAIL_HOST') ?? '')));
        if ($resend === '' && $mailHost === 'smtp.resend.com') {
            $mailPassword = trim((string)(saas_email_env_value('MAIL_PASSWORD') ?? ''));
            if (strpos($mailPassword, 're_') === 0) {
                $resend = $mailPassword;
            }
        }
        if ($resend !== '') {
            return 'resend';
        }

        return null;
    }
}

if (!function_exists('saas_email_is_gmail_host')) {
    function saas_email_is_gmail_host(string $host): bool
    {
        $host = strtolower(trim($host));
        return $host === 'smtp.gmail.com' || substr($host, -10) === '.gmail.com';
    }
}

if (!function_exists('saas_email_parse_from')) {
    function saas_email_parse_from(string $from): array
    {
        $from = trim($from);
        if (preg_match('/^(.*?)<([^>]+)>$/', $from, $matches) === 1) {
            return [
                'name' => trim((string)$matches[1], " \t\n\r\0\x0B\"'"),
                'email' => trim((string)$matches[2]),
            ];
        }

        return [
            'name' => '',
            'email' => $from,
        ];
    }
}

if (!function_exists('saas_email_api_request')) {
    function saas_email_api_request(string $url, array $headers, array $payload, int $timeout = 20): array
    {
        if (!function_exists('curl_init')) {
            return [
                'ok' => false,
                'status' => 0,
                'body' => '',
                'error' => 'Extensao PHP cURL indisponivel no servidor.',
            ];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
        ]);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'ok' => $error === '' && $status >= 200 && $status < 300,
            'status' => $status,
            'body' => is_string($body) ? $body : '',
            'error' => $error,
        ];
    }
}

if (!function_exists('saas_email_api_error_message')) {
    function saas_email_api_error_message(array $result): string
    {
        $body = trim((string)($result['body'] ?? ''));
        if ($body === '') {
            return '';
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return '';
        }

        foreach (['message', 'error', 'name'] as $key) {
            $value = $decoded[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return substr(trim($value), 0, 300);
            }
        }

        return '';
    }
}

if (!function_exists('saas_email_send_resend_sdk')) {
    function saas_email_send_resend_sdk(string $apiKey, array $payload): ?bool
    {
        $autoload = saas_email_obter_autoload();
        if ($autoload === null) {
            return null;
        }

        require_once $autoload;
        if (!class_exists('Resend')) {
            return null;
        }

        try {
            $client = \Resend::client($apiKey);
            $email = $client->emails->send($payload);
            $emailId = is_object($email) && isset($email->id) ? trim((string)$email->id) : '';
            saas_email_set_last_error(null);
            saas_email_debug_log(
                'SUCESSO: Email enviado via SDK Resend.'
                . ($emailId !== '' ? ' ID: ' . $emailId : '')
            );
            return true;
        } catch (Throwable $e) {
            saas_email_set_last_error('Resend SDK falhou: ' . substr($e->getMessage(), 0, 500));
            saas_email_debug_log('Resend SDK error: ' . substr($e->getMessage(), 0, 500));
            return false;
        }
    }
}

if (!function_exists('saas_email_send_via_api')) {
    function saas_email_send_via_api(string $para, string $assunto, string $mensagem, string $smtpFrom, ?string $forceProvider = null): ?bool
    {
        $provider = strtolower(trim((string)($forceProvider ?? saas_email_env_value('EMAIL_PROVIDER') ?? '')));
        if ($provider === '' || $provider === 'smtp') {
            return null;
        }

        $providerFrom = $smtpFrom;
        if ($provider === 'resend') {
            $providerFrom = (string)(saas_email_env_value('RESEND_FROM') ?? '');
            if ($providerFrom === '') {
                $mailFromAddress = (string)(saas_email_env_value('MAIL_FROM_ADDRESS') ?? '');
                $mailFromName = (string)(saas_email_env_value('MAIL_FROM_NAME') ?? '');
                $providerFrom = $mailFromAddress !== ''
                    ? ($mailFromName !== '' ? ($mailFromName . ' <' . $mailFromAddress . '>') : $mailFromAddress)
                    : $smtpFrom;
            }
        } elseif ($provider === 'brevo') {
            $providerFrom = (string)(saas_email_env_value('BREVO_FROM') ?? $smtpFrom);
        }

        $from = saas_email_parse_from($providerFrom);
        if (!filter_var($from['email'], FILTER_VALIDATE_EMAIL)) {
            saas_email_set_last_error('EMAIL_FROM/SMTP_FROM invalido para envio por API.');
            return false;
        }

        if ($provider === 'brevo') {
            $apiKey = (string)(saas_email_env_value('BREVO_API_KEY') ?? '');
            if ($apiKey === '') {
                saas_email_set_last_error('EMAIL_PROVIDER=brevo, mas BREVO_API_KEY nao esta configurado.');
                return false;
            }

            $payload = [
                'sender' => array_filter([
                    'name' => $from['name'],
                    'email' => $from['email'],
                ]),
                'to' => [['email' => $para]],
                'subject' => $assunto,
                'htmlContent' => $mensagem,
            ];
            $result = saas_email_api_request('https://api.brevo.com/v3/smtp/email', [
                'Accept: application/json',
                'Content-Type: application/json',
                'api-key: ' . $apiKey,
            ], $payload, (int)(saas_email_env_value('EMAIL_API_TIMEOUT') ?? 20));

            if ($result['ok']) {
                saas_email_set_last_error(null);
                saas_email_debug_log('SUCESSO: Email enviado para ' . $para . ' via Brevo API.');
                return true;
            }

            saas_email_set_last_error('Brevo API falhou. HTTP ' . $result['status'] . ($result['error'] ? ' - ' . $result['error'] : ''));
            saas_email_debug_log('Brevo API error: HTTP ' . $result['status'] . ' ' . substr($result['body'], 0, 500));
            return false;
        }

        if ($provider === 'resend') {
            $apiKey = (string)(saas_email_env_value('RESEND_API_KEY') ?? '');
            if ($apiKey === '' && strtolower((string)(saas_email_env_value('MAIL_HOST') ?? '')) === 'smtp.resend.com') {
                $apiKey = (string)(saas_email_env_value('MAIL_PASSWORD') ?? '');
            }
            if ($apiKey === '') {
                saas_email_set_last_error('EMAIL_PROVIDER=resend, mas RESEND_API_KEY/MAIL_PASSWORD nao esta configurado.');
                return false;
            }

            $fromText = $from['name'] !== '' ? ($from['name'] . ' <' . $from['email'] . '>') : $from['email'];
            $payload = [
                'from' => $fromText,
                'to' => [$para],
                'subject' => $assunto,
                'html' => $mensagem,
            ];

            $sdkSent = saas_email_send_resend_sdk($apiKey, $payload);
            if ($sdkSent !== null) {
                return $sdkSent;
            }

            saas_email_debug_log('AVISO: SDK Resend indisponivel; usando compatibilidade HTTP direta.');
            $result = saas_email_api_request('https://api.resend.com/emails', [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ], $payload, (int)(saas_email_env_value('EMAIL_API_TIMEOUT') ?? 20));

            if ($result['ok']) {
                saas_email_set_last_error(null);
                saas_email_debug_log('SUCESSO: Email enviado para ' . $para . ' via Resend API.');
                return true;
            }

            $apiMessage = saas_email_api_error_message($result);
            saas_email_set_last_error(
                'Resend API falhou. HTTP ' . $result['status']
                . ($result['error'] ? ' - ' . $result['error'] : '')
                . ($apiMessage !== '' ? ' - ' . $apiMessage : '')
            );
            saas_email_debug_log('Resend API error: HTTP ' . $result['status'] . ' ' . substr($result['body'], 0, 500));
            return false;
        }

        saas_email_set_last_error('EMAIL_PROVIDER nao suportado: ' . $provider . '. Use smtp, brevo ou resend.');
        return false;
    }
}

if (!function_exists('saas_email_carregar_config')) {
    function saas_email_carregar_config(): array
    {
        saas_email_load_local_env();

        $projectRoot = saas_email_project_root();
        // Fonte activa: app/src/config/email.php (+ overrides do .env).
        // Paths antigos ficam só como fallback de compatibilidade.
        $paths = [
            __DIR__ . '/email.php',
            $projectRoot . '/config/email.php',
            $projectRoot . '/config_legacy_backup/email.php',
        ];
        $fallbackConfig = [];

        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }

            $config = require $path;
            if (!is_array($config)) {
                continue;
            }

            if (!$fallbackConfig) {
                $fallbackConfig = $config;
            }

            if (!saas_email_is_placeholder_config($config)) {
                return $config;
            }
        }

        return $fallbackConfig;
    }
}

if (!function_exists('saas_email_autoload_candidates')) {
    function saas_email_autoload_candidates(): array
    {
        $projectRoot = saas_email_project_root();

        return [
            $projectRoot . '/vendor/autoload.php',
            dirname($projectRoot) . '/vendor/autoload.php',
        ];
    }
}

if (!function_exists('saas_email_obter_autoload')) {
    function saas_email_obter_autoload(): ?string
    {
        foreach (saas_email_autoload_candidates() as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}

if (!function_exists('saas_email_phpmailer_candidates')) {
    function saas_email_phpmailer_candidates(): array
    {
        $projectRoot = saas_email_project_root();

        return [
            $projectRoot . '/vendor/phpmailer/phpmailer/src',
            dirname($projectRoot) . '/vendor/phpmailer/phpmailer/src',
        ];
    }
}

if (!function_exists('saas_email_carregar_phpmailer')) {
    function saas_email_carregar_phpmailer(): ?string
    {
        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer', false)) {
            return 'loaded';
        }

        foreach (saas_email_phpmailer_candidates() as $basePath) {
            $exceptionFile = $basePath . '/Exception.php';
            $phpmailerFile = $basePath . '/PHPMailer.php';
            $smtpFile = $basePath . '/SMTP.php';

            if (!is_file($exceptionFile) || !is_file($phpmailerFile) || !is_file($smtpFile)) {
                continue;
            }

            require_once $exceptionFile;
            require_once $phpmailerFile;
            require_once $smtpFile;

            if (class_exists('PHPMailer\\PHPMailer\\PHPMailer', false)) {
                return $basePath;
            }
        }

        $autoload = saas_email_obter_autoload();
        if ($autoload !== null) {
            require_once $autoload;
            if (class_exists('PHPMailer\\PHPMailer\\PHPMailer', false)) {
                return $autoload;
            }
        }

        return null;
    }
}

if (!function_exists('saas_enviar_email')) {
    /**
     * Envia um email HTML.
     *
     * @param string $para       Endereço de destino
     * @param string $assunto    Assunto do email
     * @param string $mensagem   Corpo HTML
     * @return bool              true se enviado com sucesso
     */
    function saas_enviar_email(string $para, string $assunto, string $mensagem): bool
    {
        saas_email_set_last_error(null);
        $emailConfig = saas_email_carregar_config();

        $envHost = saas_email_env_value('SMTP_HOST') ?? saas_email_env_value('MAIL_HOST');
        if ($envHost !== null && saas_email_is_placeholder_config(['smtp_host' => $envHost])) {
            $envHost = null;
        }

        $smtp_host = $envHost ?? ($emailConfig['smtp_host'] ?? 'localhost');
        $smtp_port = saas_email_env_value('SMTP_PORT') ?? saas_email_env_value('MAIL_PORT') ?? ($emailConfig['smtp_port'] ?? 587);
        $smtp_username = saas_email_env_value('SMTP_USERNAME') ?? saas_email_env_value('MAIL_USERNAME') ?? ($emailConfig['smtp_username'] ?? '');
        $smtp_password = saas_email_env_value('SMTP_PASSWORD') ?? saas_email_env_value('MAIL_PASSWORD') ?? ($emailConfig['smtp_password'] ?? '');
        $smtp_from = saas_email_env_value('SMTP_FROM');
        if ($smtp_from === null) {
            $mailFromAddress = (string)(saas_email_env_value('MAIL_FROM_ADDRESS') ?? '');
            $mailFromName = (string)(saas_email_env_value('MAIL_FROM_NAME') ?? '');
            $smtp_from = $mailFromAddress !== ''
                ? ($mailFromName !== '' ? ($mailFromName . ' <' . $mailFromAddress . '>') : $mailFromAddress)
                : ($emailConfig['smtp_from'] ?? 'noreply@localhost');
        }
        $smtp_secure = saas_email_env_value('SMTP_SECURE') ?? saas_email_env_value('MAIL_ENCRYPTION') ?? ($emailConfig['smtp_secure'] ?? 'tls');
        $smtp_timeout = (int)(saas_email_env_value('SMTP_TIMEOUT') ?? ($emailConfig['smtp_timeout'] ?? 5));
        $smtp_debug = (int)(saas_email_env_value('SMTP_DEBUG') ?? ($emailConfig['smtp_debug'] ?? 0));
        $useSmtpEnv = saas_email_env_value('USE_SMTP');
        if ($useSmtpEnv === null) {
            $mailMailer = strtolower((string)(saas_email_env_value('MAIL_MAILER') ?? ''));
            $useSmtpEnv = $mailMailer !== '' ? ($mailMailer === 'smtp' ? 'true' : 'false') : null;
        }
        $use_smtp = $useSmtpEnv !== null
            ? filter_var($useSmtpEnv, FILTER_VALIDATE_BOOLEAN)
            : (bool)($emailConfig['use_smtp'] ?? false);

        $effectiveConfig = [
            'smtp_host' => $smtp_host,
            'smtp_username' => $smtp_username,
            'smtp_password' => $smtp_password,
            'smtp_from' => $smtp_from,
        ];
        $hasPlaceholderConfig = saas_email_is_placeholder_config($effectiveConfig);

        if (saas_email_is_gmail_host($smtp_host)) {
            $smtp_password = preg_replace('/\s+/', '', $smtp_password);
            // Gmail pode levar mais tempo na negociacao STARTTLS em ambientes locais.
            if ($smtp_timeout < 20) {
                $smtp_timeout = 20;
            }
        }

        if ($use_smtp && $hasPlaceholderConfig) {
            saas_email_debug_log('AVISO: Configuracao SMTP ainda esta com placeholders. Usando fallback mail().');
            error_log('[saas_enviar_email] configuracao SMTP padrao/placeholders ainda nao foi ajustada; usando fallback mail().');
            saas_email_set_last_error('Configuracao SMTP ainda esta com placeholders.');
            $use_smtp = false;
        }

        $smtpOutboundBlocked = saas_email_host_blocks_smtp();
        if ($smtpOutboundBlocked) {
            $use_smtp = false;
            saas_email_debug_log('AVISO: Hospedagem bloqueia SMTP de saida. A saltar Gmail/SMTP e a usar API HTTP ou mail().');
        }

        $apiProvider = strtolower(trim((string)(saas_email_env_value('EMAIL_PROVIDER') ?? '')));
        if (($apiProvider === '' || $apiProvider === 'smtp') && $smtpOutboundBlocked) {
            $apiProvider = (string)(saas_email_auto_api_provider() ?? '');
        }
        $apiSent = saas_email_send_via_api(
            $para,
            $assunto,
            $mensagem,
            (string)$smtp_from,
            ($apiProvider !== '' && $apiProvider !== 'smtp') ? $apiProvider : null
        );
        if ($apiSent !== null) {
            return $apiSent;
        }

        $phpMailerLoaded = false;
        $phpMailerSource = null;

        // Tentar PHPMailer primeiro quando SMTP estiver habilitado.
        if ($use_smtp) {
            $phpMailerSource = saas_email_carregar_phpmailer();
            $phpMailerLoaded = class_exists('PHPMailer\\PHPMailer\\PHPMailer', false);

            if ($phpMailerLoaded) {
                try {
                    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host     = $smtp_host;
                    $mail->SMTPAuth = true;
                    $mail->Username = $smtp_username;
                    $mail->Password = $smtp_password;
                    if (defined('PHPMailer\\PHPMailer\\PHPMailer::ENCRYPTION_STARTTLS')) {
                        $mail->SMTPSecure = $smtp_secure === 'ssl'
                            ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                            : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                    } else {
                        $mail->SMTPSecure = $smtp_secure;
                    }
                    $mail->Port = (int) $smtp_port;
                    $mail->Timeout = $smtp_timeout > 0 ? $smtp_timeout : 5;
                    $mail->SMTPKeepAlive = false;
                    if ($smtp_debug > 0) {
                        $mail->SMTPDebug = $smtp_debug;
                        $mail->Debugoutput = static function ($str): void {
                            saas_email_debug_log('SMTP: ' . trim((string)$str));
                        };
                    }

                    // Remetente
                    if (strpos($smtp_from, '<') !== false) {
                        [$fromName, $fromAddr] = explode('<', $smtp_from, 2);
                        $mail->setFrom(trim($fromAddr, '> '), trim($fromName));
                    } else {
                        $mail->setFrom($smtp_from);
                    }

                    $mail->addAddress($para);
                    $mail->isHTML(true);
                    $mail->CharSet = 'UTF-8';
                    $mail->Subject = $assunto;
                    $mail->Body    = $mensagem;
                    $mail->send();
                    saas_email_set_last_error(null);
                    saas_email_debug_log('SUCESSO: Email enviado para ' . $para . ' via PHPMailer.');
                    return true;
                } catch (Throwable $e) {
                    saas_email_set_last_error($e->getMessage());
                    saas_email_debug_log('PHPMailer error: ' . $e->getMessage());
                    if (saas_email_is_gmail_host($smtp_host) && stripos($e->getMessage(), 'authenticate') !== false) {
                        saas_email_debug_log('DICA: O Gmail rejeitou a autenticacao. Verifique se SMTP_USERNAME esta correto e se SMTP_PASSWORD e uma senha de app valida de 16 caracteres.');
                        saas_email_set_last_error('O Gmail rejeitou a autenticacao SMTP. Gere uma nova senha de app e atualize SMTP_PASSWORD.');
                    }
                    error_log('[saas_enviar_email] PHPMailer: ' . $e->getMessage());

                    $retryProvider = saas_email_auto_api_provider();
                    if ($retryProvider !== null) {
                        $retryApi = saas_email_send_via_api($para, $assunto, $mensagem, (string)$smtp_from, $retryProvider);
                        if ($retryApi === true) {
                            return true;
                        }
                    }
                }
            } else {
                saas_email_debug_log(
                    'AVISO: PHPMailer nao encontrado. Caminhos testados: ' .
                        implode(' | ', saas_email_phpmailer_candidates()) .
                        ' | autoload: ' . implode(' | ', saas_email_autoload_candidates()) .
                        '. Tentando fallback mail().'
                );
                error_log('[saas_enviar_email] PHPMailer nao carregado; usando fallback mail().');
                saas_email_set_last_error('PHPMailer nao encontrado para envio SMTP.');
            }
        }

        // Fallback: função mail() nativa do PHP
        if ($smtpOutboundBlocked) {
            saas_email_set_last_error(
                'Esta hospedagem bloqueia SMTP externo. Configure EMAIL_PROVIDER=resend com RESEND_API_KEY ou EMAIL_PROVIDER=brevo com BREVO_API_KEY.'
            );
            saas_email_debug_log('ERRO: SMTP externo bloqueado e nenhum provedor API configurado.');
            return false;
        }

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: {$smtp_from}\r\n";
        $headers .= "Reply-To: {$smtp_from}\r\n";
        $fromEmail = saas_email_parse_from((string)$smtp_from)['email'];
        $envelope = filter_var($fromEmail, FILTER_VALIDATE_EMAIL) ? ('-f' . $fromEmail) : '';
        $enviado = $envelope !== ''
            ? @mail($para, $assunto, $mensagem, $headers, $envelope)
            : @mail($para, $assunto, $mensagem, $headers);
        saas_email_debug_log('Resultado mail(): ' . ($enviado ? 'OK' : 'FALHOU'));
        if ($enviado) {
            saas_email_set_last_error(null);
            saas_email_debug_log('SUCESSO: Email enviado para ' . $para . ' via mail().');
        } else {
            $erroAtual = saas_email_get_last_error();
            if ($smtpOutboundBlocked) {
                saas_email_set_last_error(
                    'Esta hospedagem bloqueia SMTP externo. Configure EMAIL_PROVIDER=brevo e BREVO_API_KEY (ou resend) no .env. mail() tambem falhou.'
                );
            } else {
                saas_email_set_last_error($erroAtual ?: 'mail() falhou no servidor local.');
            }
        }
        return $enviado;
    }
}
