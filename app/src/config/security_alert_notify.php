<?php

/**
 * Notificações outbound de segurança (email + WhatsApp/SMS-like).
 * Reutiliza saas_enviar_email e notificar_cliente_whatsapp.
 */

require_once __DIR__ . '/email_helper.php';

if (!function_exists('security_alert_notify_log_dir')) {
    function security_alert_notify_log_dir(): string
    {
        $baseDir = dirname(__DIR__, 2);
        $logDir = $baseDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        return $logDir;
    }
}

if (!function_exists('security_alert_notify_recipients_email')) {
    function security_alert_notify_recipients_email(): array
    {
        $raw = trim((string)(
            getenv('SECURITY_ALERT_EMAIL')
            ?: getenv('SUPER_ADMIN_EMAIL')
            ?: getenv('INCIDENT_CONTACT')
            ?: ''
        ));
        if ($raw === '') {
            $from = trim((string)(getenv('SMTP_FROM') ?: ''));
            if (preg_match('/<([^>]+)>/', $from, $m)) {
                $raw = trim($m[1]);
            } elseif (strpos($from, '@') !== false) {
                $raw = $from;
            }
        }
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[;,]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '' && filter_var($p, FILTER_VALIDATE_EMAIL)) {
                $out[] = $p;
            }
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('security_alert_notify_phone')) {
    function security_alert_notify_phone(): string
    {
        return trim((string)(
            getenv('SECURITY_ALERT_PHONE')
            ?: getenv('SECURITY_ALERT_WHATSAPP')
            ?: ''
        ));
    }
}

if (!function_exists('security_siem_enabled')) {
    function security_siem_enabled(): bool
    {
        $v = strtolower(trim((string)(getenv('SIEM_ENABLED') ?: '')));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('security_siem_forward')) {
    /**
     * Envia evento para SIEM_ENDPOINT (HTTP JSON). Ignora placeholders.
     *
     * @param array<string,mixed> $payload
     * @return array{ok:bool,skipped:bool,detail:string,http_code?:int}
     */
    function security_siem_forward(array $payload): array
    {
        if (!security_siem_enabled()) {
            return ['ok' => false, 'skipped' => true, 'detail' => 'SIEM_ENABLED_off'];
        }
        $url = trim((string)(getenv('SIEM_ENDPOINT') ?: ''));
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'skipped' => true, 'detail' => 'SIEM_ENDPOINT_invalid'];
        }
        if (preg_match('/seudominio\.com|example\.com|localhost/i', $url)) {
            return ['ok' => false, 'skipped' => true, 'detail' => 'SIEM_ENDPOINT_placeholder'];
        }

        $body = json_encode(array_merge([
            'source' => 'restaurante_saas',
            'sent_at' => date('c'),
        ], $payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            return ['ok' => false, 'skipped' => false, 'detail' => 'json_encode_failed'];
        }

        $token = trim((string)(getenv('SIEM_TOKEN') ?: getenv('SIEM_INGEST_TOKEN') ?: ''));
        $timeout = max(1, min(5, (int)(getenv('SIEM_TIMEOUT') ?: 2)));
        $headers = "Content-Type: application/json\r\nAccept: application/json\r\n";
        if ($token !== '') {
            $headers .= 'Authorization: Bearer ' . $token . "\r\n";
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headers,
                'content' => $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('/^HTTP\/\S+\s+(\d+)/', $h, $m)) {
                    $code = (int)$m[1];
                    break;
                }
            }
        }

        $ok = $raw !== false && $code >= 200 && $code < 300;
        $line = json_encode([
            'time' => date('c'),
            'event' => 'security_siem_forward',
            'ok' => $ok,
            'http_code' => $code,
            'url_host' => (string)(parse_url($url, PHP_URL_HOST) ?: ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @file_put_contents(
            security_alert_notify_log_dir() . DIRECTORY_SEPARATOR . 'security_siem.log',
            $line . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        return [
            'ok' => $ok,
            'skipped' => false,
            'detail' => $ok ? 'sent' : ('http_' . $code),
            'http_code' => $code,
        ];
    }
}

if (!function_exists('security_alert_notify_should_send')) {
    /**
     * Dedupe por chave + janela (segundos). Evita flood de emails.
     */
    function security_alert_notify_should_send(string $dedupeKey, int $cooldownSeconds = 900): bool
    {
        $dedupeKey = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $dedupeKey) ?: 'alert';
        $path = security_alert_notify_log_dir() . DIRECTORY_SEPARATOR . 'alert_notify_' . $dedupeKey . '.stamp';
        $now = time();
        if (is_file($path)) {
            $last = (int)@file_get_contents($path);
            if ($last > 0 && ($now - $last) < $cooldownSeconds) {
                return false;
            }
        }
        @file_put_contents($path, (string)$now, LOCK_EX);
        return true;
    }
}

if (!function_exists('security_alert_notify')) {
    /**
     * Envia alerta por email e, se configurado, WhatsApp (Twilio/Cloud).
     *
     * @param array<string,mixed> $meta
     * @return array{ok:bool,email:bool,whatsapp:bool,skipped:bool,detail:string}
     */
    function security_alert_notify(string $subject, string $htmlBody, array $meta = []): array
    {
        $dedupeKey = (string)($meta['dedupe_key'] ?? md5($subject));
        $cooldown = max(60, (int)($meta['cooldown'] ?? 900));
        if (empty($meta['force']) && !security_alert_notify_should_send($dedupeKey, $cooldown)) {
            return [
                'ok' => true,
                'email' => false,
                'whatsapp' => false,
                'skipped' => true,
                'detail' => 'cooldown',
            ];
        }

        $emails = security_alert_notify_recipients_email();
        $phone = security_alert_notify_phone();
        $emailOk = false;
        $waOk = false;
        $details = [];

        if ($emails === [] && $phone === '') {
            $line = json_encode([
                'time' => date('c'),
                'event' => 'security_alert_notify_no_channel',
                'subject' => $subject,
                'meta' => $meta,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            @file_put_contents(
                security_alert_notify_log_dir() . DIRECTORY_SEPARATOR . 'security_alerts.log',
                $line . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
            return [
                'ok' => false,
                'email' => false,
                'whatsapp' => false,
                'skipped' => false,
                'detail' => 'no_SECURITY_ALERT_EMAIL_or_PHONE',
            ];
        }

        $footer = '<hr><p style="font-size:12px;color:#64748b">RestauranteSaaS Security Alert'
            . ' · ' . htmlspecialchars(date('c'), ENT_QUOTES, 'UTF-8')
            . (isset($meta['event']) ? ' · evento: ' . htmlspecialchars((string)$meta['event'], ENT_QUOTES, 'UTF-8') : '')
            . '</p>';
        $html = $htmlBody . $footer;

        foreach ($emails as $to) {
            $sent = @saas_enviar_email($to, $subject, $html);
            if ($sent) {
                $emailOk = true;
            } else {
                $details[] = 'email_fail:' . $to;
            }
        }

        if ($phone !== '') {
            $waHelper = dirname(__DIR__) . '/api/notificar_cliente_whatsapp.php';
            if (is_file($waHelper)) {
                require_once $waHelper;
            }
            if (function_exists('notificar_cliente_whatsapp')) {
                $plain = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody)), ENT_QUOTES, 'UTF-8'));
                $plain = preg_replace("/\n{3,}/", "\n\n", $plain) ?: $plain;
                $msg = "[Segurança] " . $subject . "\n\n" . substr($plain, 0, 900);
                $waOk = (bool)@notificar_cliente_whatsapp($phone, $msg);
                if (!$waOk) {
                    $details[] = 'whatsapp_fail';
                }
            } else {
                $details[] = 'whatsapp_helper_missing';
            }
        }

        $line = json_encode([
            'time' => date('c'),
            'event' => 'security_alert_notified',
            'subject' => $subject,
            'email' => $emailOk,
            'whatsapp' => $waOk,
            'meta' => $meta,
            'detail' => implode(',', $details),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @file_put_contents(
            security_alert_notify_log_dir() . DIRECTORY_SEPARATOR . 'security_alerts.log',
            $line . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        return [
            'ok' => $emailOk || $waOk,
            'email' => $emailOk,
            'whatsapp' => $waOk,
            'skipped' => false,
            'detail' => implode(',', $details) ?: 'sent',
            'siem' => function_exists('security_siem_forward')
                ? security_siem_forward([
                    'event' => (string)($meta['event'] ?? 'security_alert'),
                    'subject' => $subject,
                    'channels' => ['email' => $emailOk, 'whatsapp' => $waOk],
                    'meta' => $meta,
                ])
                : null,
        ];
    }
}

if (!function_exists('security_alert_notify_from_monitor')) {
    /**
     * @param array<string,mixed> $report
     */
    function security_alert_notify_from_monitor(array $report): array
    {
        $suspicious = (int)($report['suspicious_total'] ?? 0);
        $threshold = (int)($report['threshold'] ?? 0);
        $window = (int)($report['window_minutes'] ?? 0);
        $top = $report['top_events'] ?? [];
        $lis = '';
        if (is_array($top)) {
            foreach ($top as $ev => $qty) {
                $lis .= '<li>' . htmlspecialchars((string)$ev, ENT_QUOTES, 'UTF-8') . ': ' . (int)$qty . '</li>';
            }
        }
        $html = '<p><strong>Limite de eventos suspeitos excedido.</strong></p>'
            . '<ul>'
            . '<li>Suspeitos: ' . $suspicious . '</li>'
            . '<li>Limite: ' . $threshold . '</li>'
            . '<li>Janela: ' . $window . ' min</li>'
            . '</ul>'
            . ($lis !== '' ? '<p>Top eventos:</p><ul>' . $lis . '</ul>' : '');

        return security_alert_notify(
            '[Segurança] Limite de actividade suspeita excedido',
            $html,
            [
                'event' => 'security_monitor_threshold_exceeded',
                'dedupe_key' => 'monitor_threshold',
                'cooldown' => max(300, $window * 60),
            ]
        );
    }
}

if (!function_exists('security_alert_notify_from_siem_blocks')) {
    /**
     * @param array<int,array<string,mixed>> $blocked
     */
    function security_alert_notify_from_siem_blocks(array $blocked): array
    {
        if ($blocked === []) {
            return ['ok' => true, 'email' => false, 'whatsapp' => false, 'skipped' => true, 'detail' => 'none'];
        }
        $lis = '';
        foreach ($blocked as $item) {
            $lis .= '<li>IP ' . htmlspecialchars((string)($item['ip'] ?? ''), ENT_QUOTES, 'UTF-8')
                . ' · tentativas: ' . (int)($item['count'] ?? 0)
                . ' · até: ' . htmlspecialchars(date('c', (int)($item['blocked_until'] ?? time())), ENT_QUOTES, 'UTF-8')
                . '</li>';
        }
        $html = '<p><strong>SIEM bloqueou IP(s) automaticamente.</strong></p><ul>' . $lis . '</ul>';
        return security_alert_notify(
            '[Segurança] Autoblock de IP (' . count($blocked) . ')',
            $html,
            [
                'event' => 'siem_auto_block_ip',
                'dedupe_key' => 'siem_autoblock',
                'cooldown' => 600,
            ]
        );
    }
}
