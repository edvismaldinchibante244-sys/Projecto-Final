<?php

require_once __DIR__ . '/../config/internal_api.php';
internal_api_deny_direct_http_execution(__FILE__);

require_once __DIR__ . '/../config/reserva_qr_schema.php';
require_once __DIR__ . '/../config/email_helper.php';

if (!function_exists('reserva_qr_delivery_normalize_filename')) {
    function reserva_qr_delivery_normalize_filename(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9\-]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        return $value !== '' ? $value : 'qr-reserva';
    }
}

if (!function_exists('reserva_qr_delivery_storage_root')) {
    function reserva_qr_delivery_storage_root(): string
    {
        return dirname(__DIR__, 3) . '/src/public/uploads/reserva-qr';
    }
}

if (!function_exists('reserva_qr_delivery_store_image_data')) {
    function reserva_qr_delivery_store_image_data(string $dataUrl, string $baseName, int $restauranteId): array
    {
        $dataUrl = trim($dataUrl);
        if (!preg_match('#^data:(image/(png|jpeg));base64,(.+)$#', $dataUrl, $matches)) {
            throw new InvalidArgumentException('Imagem QR invalida ou em formato nao suportado.');
        }

        $mime = strtolower($matches[1]);
        $extension = $mime === 'image/jpeg' ? 'jpg' : 'png';
        $binary = base64_decode(str_replace(' ', '+', $matches[3]), true);

        if ($binary === false || $binary === '') {
            throw new InvalidArgumentException('Nao foi possivel decodificar a imagem do QR Code.');
        }

        $subdir = 'restaurante-' . max(1, $restauranteId) . '/' . date('Y/m');
        $root = reserva_qr_delivery_storage_root();
        $dir = $root . '/' . $subdir;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Nao foi possivel criar a pasta de QR Codes.');
        }

        $filename = reserva_qr_delivery_normalize_filename($baseName)
            . '-'
            . date('YmdHis')
            . '-'
            . substr(bin2hex(random_bytes(6)), 0, 12)
            . '.'
            . $extension;

        $absolutePath = $dir . '/' . $filename;
        if (file_put_contents($absolutePath, $binary) === false) {
            throw new RuntimeException('Nao foi possivel salvar a imagem do QR Code.');
        }

        $relativePath = 'uploads/reserva-qr/' . $subdir . '/' . $filename;

        return [
            'mime' => $mime,
            'absolute_path' => $absolutePath,
            'relative_path' => str_replace('\\', '/', $relativePath),
            'public_url' => reserva_qr_base_public_url() . '/' . str_replace('\\', '/', $relativePath),
        ];
    }
}

if (!function_exists('reserva_qr_delivery_host_is_public')) {
    function reserva_qr_delivery_host_is_public(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '' || $host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return false;
        }

        if (substr($host, -6) === '.local') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) !== false;
        }

        return true;
    }
}

if (!function_exists('reserva_qr_delivery_public_media_url')) {
    function reserva_qr_delivery_public_media_url(string $url): bool
    {
        $host = (string)(parse_url($url, PHP_URL_HOST) ?: '');
        return reserva_qr_delivery_host_is_public($host);
    }
}

if (!function_exists('reserva_qr_delivery_build_email_html')) {
    function reserva_qr_delivery_build_email_html(array $contexto, string $imageUrl = ''): string
    {
        $nomeCliente = htmlspecialchars((string)($contexto['nome_cliente'] ?? 'Cliente'), ENT_QUOTES, 'UTF-8');
        $restaurante = htmlspecialchars((string)($contexto['restaurante_nome'] ?? 'Restaurante'), ENT_QUOTES, 'UTF-8');
        $titulo = htmlspecialchars((string)($contexto['titulo'] ?? 'QR Code da reserva'), ENT_QUOTES, 'UTF-8');
        $codigo = htmlspecialchars((string)($contexto['codigo'] ?? ''), ENT_QUOTES, 'UTF-8');
        $mesa = htmlspecialchars((string)($contexto['mesa_label'] ?? ''), ENT_QUOTES, 'UTF-8');
        $dataHora = htmlspecialchars((string)($contexto['data_hora_label'] ?? ''), ENT_QUOTES, 'UTF-8');
        $observacoes = htmlspecialchars((string)($contexto['observacoes'] ?? ''), ENT_QUOTES, 'UTF-8');
        $link = htmlspecialchars((string)($contexto['link'] ?? ''), ENT_QUOTES, 'UTF-8');
        $imageUrlSafe = htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8');

        $html = '<div style="font-family:Segoe UI,Arial,sans-serif;color:#1f2937;line-height:1.6;">';
        $html .= '<h2 style="margin:0 0 12px;">' . $titulo . '</h2>';
        $html .= '<p>Ola <strong>' . $nomeCliente . '</strong>, segue o QR Code gerado para o <strong>' . $restaurante . '</strong>.</p>';

        if ($codigo !== '') {
            $html .= '<p><strong>Codigo:</strong> ' . $codigo . '</p>';
        }
        if ($dataHora !== '') {
            $html .= '<p><strong>Data/Hora:</strong> ' . $dataHora . '</p>';
        }
        if ($mesa !== '') {
            $html .= '<p><strong>Mesa:</strong> ' . $mesa . '</p>';
        }
        if ($observacoes !== '') {
            $html .= '<p><strong>Observacoes:</strong> ' . $observacoes . '</p>';
        }
        if ($link !== '') {
            $html .= '<p><a href="' . $link . '" style="display:inline-block;padding:10px 16px;background:#111827;color:#fff;text-decoration:none;border-radius:8px;">Abrir reserva</a></p>';
        }
        if ($imageUrlSafe !== '') {
            $html .= '<p><img src="' . $imageUrlSafe . '" alt="QR Code da reserva" style="max-width:280px;border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#fff;"></p>';
            $html .= '<p><a href="' . $imageUrlSafe . '">Baixar QR Code em PNG</a></p>';
        }

        $html .= '<p>Apresente esta imagem no restaurante para agilizar o atendimento.</p>';
        $html .= '</div>';

        return $html;
    }
}

if (!function_exists('reserva_qr_delivery_send_email')) {
    function reserva_qr_delivery_send_email(
        string $to,
        string $subject,
        string $html,
        string $attachmentPath,
        string $attachmentName,
        string $imageUrl = ''
    ): bool {
        saas_email_set_last_error(null);
        saas_email_carregar_phpmailer();

        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer', false)) {
            $fallbackBody = $html;
            if ($imageUrl !== '') {
                $fallbackBody .= '<p><a href="' . htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') . '">Baixar QR Code</a></p>';
            }
            return saas_enviar_email($to, $subject, $fallbackBody);
        }

        $emailConfig = saas_email_carregar_config();
        $envHost = saas_email_env_value('SMTP_HOST');
        if ($envHost !== null && saas_email_is_placeholder_config(['smtp_host' => $envHost])) {
            $envHost = null;
        }

        $smtpHost = $envHost ?? ($emailConfig['smtp_host'] ?? 'localhost');
        $smtpPort = saas_email_env_value('SMTP_PORT') ?? ($emailConfig['smtp_port'] ?? 587);
        $smtpUsername = saas_email_env_value('SMTP_USERNAME') ?? ($emailConfig['smtp_username'] ?? '');
        $smtpPassword = saas_email_env_value('SMTP_PASSWORD') ?? ($emailConfig['smtp_password'] ?? '');
        $smtpFrom = saas_email_env_value('SMTP_FROM') ?? ($emailConfig['smtp_from'] ?? 'noreply@localhost');
        $smtpSecure = saas_email_env_value('SMTP_SECURE') ?? ($emailConfig['smtp_secure'] ?? 'tls');
        $smtpTimeout = (int)(saas_email_env_value('SMTP_TIMEOUT') ?? ($emailConfig['smtp_timeout'] ?? 10));
        $smtpDebug = (int)(saas_email_env_value('SMTP_DEBUG') ?? ($emailConfig['smtp_debug'] ?? 0));
        $useSmtpEnv = saas_email_env_value('USE_SMTP');
        $useSmtp = $useSmtpEnv !== null
            ? filter_var($useSmtpEnv, FILTER_VALIDATE_BOOLEAN)
            : (bool)($emailConfig['use_smtp'] ?? false);

        if (function_exists('saas_email_is_gmail_host') && saas_email_is_gmail_host($smtpHost)) {
            $smtpPassword = preg_replace('/\s+/', '', (string)$smtpPassword);
            if ($smtpTimeout < 20) {
                $smtpTimeout = 20;
            }
        }

        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            if ($useSmtp && !saas_email_is_placeholder_config([
                'smtp_host' => $smtpHost,
                'smtp_username' => $smtpUsername,
                'smtp_password' => $smtpPassword,
                'smtp_from' => $smtpFrom,
            ])) {
                $mail->isSMTP();
                $mail->Host = $smtpHost;
                $mail->SMTPAuth = true;
                $mail->Username = $smtpUsername;
                $mail->Password = $smtpPassword;
                $mail->Port = (int)$smtpPort;
                $mail->Timeout = $smtpTimeout > 0 ? $smtpTimeout : 10;
                $mail->SMTPKeepAlive = false;
                if ($smtpDebug > 0) {
                    $mail->SMTPDebug = $smtpDebug;
                    $mail->Debugoutput = static function ($str): void {
                        saas_email_debug_log('SMTP QR: ' . trim((string)$str));
                    };
                }
                if (defined('PHPMailer\\PHPMailer\\PHPMailer::ENCRYPTION_STARTTLS')) {
                    $mail->SMTPSecure = $smtpSecure === 'ssl'
                        ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                        : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                } else {
                    $mail->SMTPSecure = $smtpSecure;
                }
            } else {
                $mail->isMail();
            }

            if (strpos($smtpFrom, '<') !== false) {
                [$fromName, $fromAddr] = explode('<', $smtpFrom, 2);
                $mail->setFrom(trim($fromAddr, '> '), trim($fromName));
            } else {
                $mail->setFrom($smtpFrom);
            }

            $mail->addAddress($to);
            $mail->addAttachment($attachmentPath, $attachmentName);
            if (is_file($attachmentPath)) {
                $mail->addEmbeddedImage($attachmentPath, 'qr_reserva_inline', $attachmentName);
                $html .= '<p><img src="cid:qr_reserva_inline" alt="QR Code da reserva" style="max-width:280px;"></p>';
            }

            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            $mail->Subject = $subject;
            $mail->Body = $html;
            $mail->AltBody = trim(preg_replace('/\s+/', ' ', strip_tags($html))) ?: $subject;
            $mail->send();

            saas_email_set_last_error(null);
            return true;
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if (stripos($message, 'Could not connect to SMTP host') !== false) {
                $message = 'Nao foi possivel conectar ao servidor SMTP. Verifique internet/firewall, SMTP_HOST, SMTP_PORT e SMTP_SECURE.';
            } elseif (function_exists('saas_email_is_gmail_host') && saas_email_is_gmail_host($smtpHost) && stripos($message, 'authenticate') !== false) {
                $message = 'O Gmail rejeitou a autenticacao SMTP. Confirme SMTP_USERNAME e use senha de app valida em SMTP_PASSWORD.';
            }
            saas_email_set_last_error($message);
            return false;
        }
    }
}
