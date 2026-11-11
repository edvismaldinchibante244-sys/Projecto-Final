<?php

if (!function_exists('login_security_coluna_existe')) {
    function login_security_coluna_existe(PDO $db, string $tabela, string $coluna): bool
    {
        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :tabela
              AND COLUMN_NAME = :coluna
            LIMIT 1
        ");
        $stmt->bindValue(':tabela', $tabela, PDO::PARAM_STR);
        $stmt->bindValue(':coluna', $coluna, PDO::PARAM_STR);
        $stmt->execute();

        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('login_security_garantir_schema')) {
    function login_security_garantir_schema(PDO $db): bool
    {
        static $status = null;

        if ($status !== null) {
            return $status;
        }

        $alteracoes = [
            'tentativas_login' => "ALTER TABLE usuarios ADD COLUMN tentativas_login INT NOT NULL DEFAULT 0",
            'bloqueado_ate' => "ALTER TABLE usuarios ADD COLUMN bloqueado_ate DATETIME NULL DEFAULT NULL",
            'two_factor_enabled' => "ALTER TABLE usuarios ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0",
            'two_factor_email' => "ALTER TABLE usuarios ADD COLUMN two_factor_email VARCHAR(254) NULL DEFAULT NULL",
        ];

        foreach ($alteracoes as $coluna => $sql) {
            if (login_security_coluna_existe($db, 'usuarios', $coluna)) {
                continue;
            }

            try {
                $db->exec($sql);
            } catch (Throwable $e) {
                // Ignora concorrencia ou ambientes em que a migracao ja rodou.
            }
        }

        $status = login_security_coluna_existe($db, 'usuarios', 'tentativas_login')
            && login_security_coluna_existe($db, 'usuarios', 'bloqueado_ate')
            && login_security_coluna_existe($db, 'usuarios', 'two_factor_enabled')
            && login_security_coluna_existe($db, 'usuarios', 'two_factor_email');

        return $status;
    }
}

if (!function_exists('login_security_generate_otp')) {
    function login_security_generate_otp(): string
    {
        return (string)random_int(100000, 999999);
    }
}

if (!function_exists('login_security_hash_otp')) {
    function login_security_hash_otp(string $code): string
    {
        $pepper = (string)(getenv('APP_KEY') ?: getenv('SECURITY_KEY') ?: 'restaurante-saas-local-key');
        return hash_hmac('sha256', $code, $pepper);
    }
}

if (!function_exists('login_security_verify_otp')) {
    function login_security_verify_otp(string $code, string $hash): bool
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';
        return strlen($code) === 6 && hash_equals($hash, login_security_hash_otp($code));
    }
}

if (!function_exists('login_security_fingerprint_dir')) {
    function login_security_fingerprint_dir(): string
    {
        $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs'
            . DIRECTORY_SEPARATOR . 'login_fingerprints';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }
}

if (!function_exists('login_security_alert_if_new_context')) {
    /**
     * Alerta se login vier de IP novo (anti phishing / conta comprometida).
     *
     * @param array<string,mixed> $user
     * @return array{new_ip:bool,alerted:bool}
     */
    function login_security_alert_if_new_context(array $user, bool $isSuperAdmin = false): array
    {
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) {
            return ['new_ip' => false, 'alerted' => false];
        }

        $ip = function_exists('security_get_client_ip')
            ? security_get_client_ip()
            : (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $path = login_security_fingerprint_dir() . DIRECTORY_SEPARATOR . 'u_' . $userId . '.json';

        $known = [];
        if (is_file($path)) {
            $raw = file_get_contents($path);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded) && isset($decoded['ips']) && is_array($decoded['ips'])) {
                $known = array_values(array_filter(array_map('strval', $decoded['ips'])));
            }
        }

        $isNewIp = $ip !== '' && $ip !== '0.0.0.0' && !in_array($ip, $known, true);
        $ips = $known;
        if ($ip !== '' && !in_array($ip, $ips, true)) {
            array_unshift($ips, $ip);
            $ips = array_slice(array_values(array_unique($ips)), 0, 8);
        }

        @file_put_contents($path, json_encode([
            'usuario_id' => $userId,
            'ips' => $ips,
            'last_ip' => $ip,
            'last_ua' => $ua,
            'last_login_at' => date('c'),
            'super_admin' => $isSuperAdmin ? 1 : 0,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

        if (!$isNewIp || $known === []) {
            // Primeiro login conhecido: só grava fingerprint, sem alarme
            return ['new_ip' => $isNewIp && $known !== [], 'alerted' => false];
        }

        if (function_exists('security_log_event')) {
            security_log_event('login_new_ip', [
                'usuario_id' => $userId,
                'ip' => $ip,
                'perfil' => (string)($user['perfil'] ?? ''),
                'super_admin' => $isSuperAdmin,
            ]);
        }

        $notifyFile = __DIR__ . DIRECTORY_SEPARATOR . 'security_alert_notify.php';
        if (is_file($notifyFile)) {
            require_once $notifyFile;
        }

        $emailUser = trim((string)($user['email'] ?? ''));
        $nome = htmlspecialchars((string)($user['nome'] ?? 'Utilizador'), ENT_QUOTES, 'UTF-8');
        $ipSafe = htmlspecialchars($ip, ENT_QUOTES, 'UTF-8');
        $when = htmlspecialchars(date('d/m/Y H:i'), ENT_QUOTES, 'UTF-8');
        $html = '<p>Olá <strong>' . $nome . '</strong>,</p>'
            . '<p>Detectámos um <strong>login a partir de um IP novo</strong>.</p>'
            . '<ul>'
            . '<li>IP: <code>' . $ipSafe . '</code></li>'
            . '<li>Quando: ' . $when . '</li>'
            . '<li>Conta: ' . htmlspecialchars($emailUser, ENT_QUOTES, 'UTF-8') . '</li>'
            . '</ul>'
            . '<p>Se <strong>não foi você</strong>: altere a senha já, não clique em links de emails suspeitos '
            . 'e contacte o suporte. Nunca partilhamos a sua senha por email.</p>';

        $alerted = false;
        if (function_exists('security_alert_notify')) {
            $res = security_alert_notify(
                '[Segurança] Login a partir de IP novo',
                $html,
                [
                    'event' => 'login_new_ip',
                    'dedupe_key' => 'login_new_ip_' . $userId . '_' . md5($ip),
                    'cooldown' => 3600,
                    'force' => $isSuperAdmin,
                ]
            );
            $alerted = !empty($res['ok']) || !empty($res['skipped']);
        }

        // Email directo ao dono da conta (canal anti-phishing)
        if ($emailUser !== '' && filter_var($emailUser, FILTER_VALIDATE_EMAIL) && function_exists('saas_enviar_email')) {
            require_once __DIR__ . '/email_helper.php';
            @saas_enviar_email($emailUser, '[Segurança] Novo login na sua conta', $html);
        }

        return ['new_ip' => true, 'alerted' => $alerted];
    }
}
