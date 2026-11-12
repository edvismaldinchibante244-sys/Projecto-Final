<?php
require_once __DIR__ . '/storage_path.php';

/*
   Security bootstrap helpers.
   Centraliza protecoes de sessao, headers, HTTPS, rate limit, logs,
   validacao de entrada e upload seguro.
*/
require_once __DIR__ . '/security_ip_blocklist.php';

if (!function_exists('security_is_local_request')) {
    function security_is_local_request(): bool
    {
        // O cabecalho Host e controlado pelo cliente. So consideramos "local"
        // quando a ligacao TCP vem realmente desta maquina (loopback).
        // Em CLI sem REMOTE_ADDR (crons, ferramentas) mantem-se a regra antiga
        // baseada apenas no Host (vazio = local).
        $remoteAddr = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        $remoteIsLoopback = ($remoteAddr === '' && PHP_SAPI === 'cli')
            || $remoteAddr === '::1'
            || $remoteAddr === '0:0:0:0:0:0:0:1'
            || str_starts_with($remoteAddr, '127.')
            || str_starts_with($remoteAddr, '::ffff:127.');
        if (!$remoteIsLoopback) {
            return false;
        }

        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        return $host === ''
            || str_contains($host, 'localhost')
            || str_contains($host, '127.0.0.1')
            || str_contains($host, '[::1]')
            || str_contains($host, '10.0.2.2')
            || str_contains($host, '10.0.3.2');
    }
}

if (!function_exists('security_is_production')) {
    function security_is_production(): bool
    {
        $env = strtolower((string)(getenv('APP_ENV') ?: ''));
        if ($env === 'production' || $env === 'prod') {
            return true;
        }
        if ($env === 'local' || $env === 'development' || $env === 'dev') {
            return false;
        }
        return !security_is_local_request();
    }
}

if (!function_exists('security_is_https')) {
    function security_is_https(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        $remote = security_normalize_ip((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        $forwardedByTrustedProxy = $remote !== ''
            && (security_is_trusted_proxy($remote) || security_is_local_request());
        if (
            $forwardedByTrustedProxy
            && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        ) {
            return true;
        }
        if (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        return false;
    }
}

if (!function_exists('security_start_session')) {
    function security_start_session(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secureCookie = security_is_production() ? true : security_is_https();

        if (headers_sent()) {
            @session_start();
            return;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', $secureCookie ? '1' : '0');
        // O PHP costuma limpar sessoes apos 24 min; a aplicacao exige 45 min de inatividade (ou 12 h com "Manter sessao").
        ini_set('session.gc_maxlifetime', '43200'); // 12 h: "Manter sessao iniciada" (a aplicacao controla a inatividade)

        // Isola os ficheiros de sessao da pasta temporaria partilhada da hospedagem.
        $sessionStorageDir = trim((string)(getenv('SESSION_SAVE_PATH') ?: ''));
        if ($sessionStorageDir === '') {
            $sessionStorageDir = nv_storage('sessions');
        }
        if ((is_dir($sessionStorageDir) || @mkdir($sessionStorageDir, 0700, true)) && is_writable($sessionStorageDir)) {
            session_save_path($sessionStorageDir);
        } else {
            error_log('Nivora: pasta privada de sessoes indisponivel; usando session.save_path do PHP.');
        }

        $configuredSessionName = trim((string)(getenv('SESSION_NAME') ?: ''));
        if ($configuredSessionName !== '') {
            session_name($configuredSessionName);
        } elseif (!empty($_COOKIE['RESTSAASSESSID']) && empty($_COOKIE[session_name()])) {
            session_name('RESTSAASSESSID');
        }

        if (PHP_VERSION_ID >= 70300) {
            // Produção: Strict (ou Lax). Never SameSite=None — enfraquece CSRF.
            $sameSite = 'Lax';
            if ($secureCookie && security_is_production()) {
                $sameSite = 'Strict';
            }
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => $secureCookie,
                'httponly' => true,
                'samesite' => $sameSite,
            ]);
        }

        // Nunca aceitar session_id do cliente (GET/POST/header) — previne session fixation.
        // Apps móveis devem usar cookie HttpOnly; o ID só é emitido pelo servidor.
        if (!empty($_SERVER['HTTP_X_SESSION_ID']) || isset($_GET['session_id']) || isset($_REQUEST['session_id'])) {
            if (function_exists('security_log_event')) {
                security_log_event('session_id_client_rejected', [
                    'has_header' => !empty($_SERVER['HTTP_X_SESSION_ID']),
                    'has_get' => isset($_GET['session_id']),
                ]);
            }
        }

        session_start();
        security_session_verificar_obsoleta();
    }
}

if (!function_exists('security_enforce_https')) {
    function security_enforce_https(): void
    {
        if (security_is_local_request()) {
            return;
        }
        if (!security_is_production() || security_is_https() || headers_sent()) {
            return;
        }

        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        if ($host === '') {
            return;
        }

        header('Location: https://' . $host . $uri, true, 301);
        exit;
    }
}

if (!function_exists('security_csp_nonce')) {
    /**
     * Nonce da CSP para este pedido (o mesmo em toda a pagina). Os <script> escritos dentro
     * das paginas levam nonce="<?= security_csp_nonce() ?>"; sem ele o browser nao os executa.
     */
    function security_csp_nonce(): string
    {
        static $nonce = null;
        if ($nonce === null) {
            $nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        }
        return $nonce;
    }
}

if (!function_exists('security_set_headers')) {
    function security_set_headers(bool $allowSameOriginFrame = false): void
    {
        security_waf_inspect_request();
        if (headers_sent()) {
            return;
        }
        security_enforce_https();
        if (security_is_production()) {
            ini_set('display_errors', '0');
            ini_set('log_errors', '1');
        }

        header('X-Frame-Options: ' . ($allowSameOriginFrame ? 'SAMEORIGIN' : 'DENY'));
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        if (security_is_https() && !security_is_local_request()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        $cspReportOnly = strtolower(trim((string)(getenv('CSP_REPORT_ONLY') ?: '0')));
        $allowInlineRaw = strtolower(trim((string)(getenv('CSP_ALLOW_UNSAFE_INLINE') ?: '1')));
        $allowInline = !security_is_production()
            || in_array($allowInlineRaw, ['1', 'true', 'yes', 'on'], true);

        // Tempo real (Socket.IO): so a origem configurada em REALTIME_URL, e so se estiver ligado.
        require_once __DIR__ . '/realtime.php';
        $realtime = realtime_origens_csp();
        $realtimeScript = $realtime ? ' ' . $realtime['script'] : '';
        $realtimeConnect = $realtime ? ' ' . implode(' ', $realtime['connect']) : '';

        // Scripts: SEM 'unsafe-inline'. Os <script> das paginas levam o nonce deste pedido e os
        // onclick="..." sao executados por js/nv_acoes.js (interpretador seguro, sem eval).
        // Escape de emergencia (nao usar normalmente): CSP_SCRIPT_UNSAFE_INLINE=1 volta ao antigo.
        $scriptInlineRaw = strtolower(trim((string)(getenv('CSP_SCRIPT_UNSAFE_INLINE') ?: '0')));
        $scriptInlineEmergencia = in_array($scriptInlineRaw, ['1', 'true', 'yes', 'on'], true);
        $scriptSrc = $scriptInlineEmergencia
            ? "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com{$realtimeScript}; "
            : "script-src 'self' 'nonce-" . security_csp_nonce() . "' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com{$realtimeScript}; "
                . "script-src-attr 'none'; ";
        // Estilos: os style="..." ainda existem nas paginas, por isso 'unsafe-inline' fica (risco baixo).
        $styleSrc = $allowInline
            ? "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com https://cdnjs.cloudflare.com; "
            : "style-src 'self' https://cdn.jsdelivr.net https://fonts.googleapis.com https://cdnjs.cloudflare.com; ";

        $csp = "default-src 'self'; "
            . "base-uri 'self'; "
            . "form-action 'self'; "
            . ($allowSameOriginFrame ? "frame-ancestors 'self'; " : "frame-ancestors 'none'; ")
            . "object-src 'none'; "
            . "img-src 'self' data: https:; "
            // Sons de alerta da cozinha/bar/garcom sao data:audio/wav (antes ficavam bloqueados).
            . "media-src 'self' data:; "
            . "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com data:; "
            . $styleSrc
            . $scriptSrc
            . "connect-src 'self' https: wss:{$realtimeConnect};";
        if (security_is_production()) {
            $csp .= " upgrade-insecure-requests;";
        }
        $cspHeader = (in_array($cspReportOnly, ['1', 'true', 'yes', 'on'], true))
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';
        header($cspHeader . ': ' . $csp);
    }
}

if (!function_exists('security_waf_enabled')) {
    function security_waf_enabled(): bool
    {
        $raw = strtolower(trim((string)(getenv('APP_WAF_ENABLED') ?: '1')));
        if (in_array($raw, ['0', 'false', 'off', 'no'], true)) {
            return false;
        }
        return true;
    }
}

if (!function_exists('security_waf_auto_block_threshold')) {
    function security_waf_auto_block_threshold(): int
    {
        $raw = (int)(getenv('APP_WAF_AUTO_BLOCK_THRESHOLD') ?: 30);
        return max(5, $raw);
    }
}

if (!function_exists('security_waf_auto_block_window')) {
    function security_waf_auto_block_window(): int
    {
        $raw = (int)(getenv('APP_WAF_AUTO_BLOCK_WINDOW') ?: 300);
        return max(60, $raw);
    }
}

if (!function_exists('security_waf_auto_block_ttl')) {
    function security_waf_auto_block_ttl(): int
    {
        $raw = (int)(getenv('APP_WAF_AUTO_BLOCK_TTL') ?: 3600);
        return max(300, $raw);
    }
}

if (!function_exists('security_waf_patterns')) {
    function security_waf_patterns(): array
    {
        return [
            'sqli_union' => '/\bunion\b\s+\bselect\b/i',
            'sqli_sleep' => '/\bsleep\s*\(/i',
            'sqli_benchmark' => '/\bbenchmark\s*\(/i',
            'sqli_or_true' => "/(?:\bor\b|\|\|)\s+['\"]?1['\"]?\s*=\s*['\"]?1['\"]?/i",
            'xss_script' => '/<\s*script\b/i',
            'xss_js_proto' => '/javascript\s*:/i',
            'path_traversal' => '/\.\.\//',
            'sensitive_probe' => '/(?:\.env|\/\.git|wp-admin|phpmyadmin)/i',
        ];
    }
}

if (!function_exists('security_waf_match_reason')) {
    function security_waf_match_reason(string $payload): ?string
    {
        $candidates = [$payload];
        $decoded = $payload;
        for ($i = 0; $i < 2; $i++) {
            $next = urldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $candidates[] = $next;
            $decoded = $next;
        }

        foreach ($candidates as $candidate) {
            foreach (security_waf_patterns() as $reason => $pattern) {
                if (preg_match($pattern, $candidate) === 1) {
                    return $reason;
                }
            }
        }
        return null;
    }
}

if (!function_exists('security_waf_inspect_request')) {
    function security_waf_inspect_request(): void
    {
        static $checked = false;
        if ($checked || !security_waf_enabled()) {
            return;
        }
        $checked = true;

        $ip = security_get_client_ip();
        if (security_ip_is_blocked($ip)) {
            security_log_event('waf_blocked_ip', ['ip' => $ip, 'source' => 'siem_auto_block']);
            $uriBlocked = (string)($_SERVER['REQUEST_URI'] ?? '');
            $isApiBlocked = str_contains($uriBlocked, '/api/');
            if ($isApiBlocked) {
                security_json(['success' => false, 'message' => 'Acesso temporariamente bloqueado por seguranca.'], 403);
            }
            if (!headers_sent()) {
                http_response_code(403);
                header('Content-Type: text/plain; charset=utf-8');
            }
            echo 'Acesso temporariamente bloqueado por seguranca.';
            exit;
        }

        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $query = (string)($_SERVER['QUERY_STRING'] ?? '');
        $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

        $reason = security_waf_match_reason($uri . ' ' . $query . ' ' . $userAgent);
        if ($reason === null) {
            return;
        }

        security_log_event('waf_blocked_request', [
            'reason' => $reason,
            'uri' => substr($uri, 0, 500),
        ]);

        $threshold = security_waf_auto_block_threshold();
        $window = security_waf_auto_block_window();
        $allowed = security_rate_limit('waf_violation_' . preg_replace('/[^a-zA-Z0-9_:\.\-]/', '_', $ip), $threshold, $window);
        if (!$allowed) {
            $rows = security_ip_blocklist_read();
            $rows[] = [
                'ip' => $ip,
                'reason' => 'auto_threshold_' . $reason,
                'source' => 'waf_auto_block',
                'blocked_until' => time() + security_waf_auto_block_ttl(),
                'permanent' => 0,
                'created_at' => time(),
            ];
            security_ip_blocklist_write($rows);
            security_log_event('waf_auto_blocked_ip', [
                'reason' => $reason,
                'ip' => $ip,
                'window_sec' => $window,
                'threshold' => $threshold,
            ]);
        }

        $isApi = str_contains($uri, '/api/');
        if ($isApi) {
            security_json(['success' => false, 'message' => 'Requisicao bloqueada por seguranca.'], 403);
        }

        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo 'Requisicao bloqueada por seguranca.';
        exit;
    }
}

if (!function_exists('security_enforce_idle_timeout')) {
    function security_enforce_idle_timeout(int $minutes = 45): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return true;
        }
        $now = time();
        $last = (int)($_SESSION['last_activity'] ?? 0);
        // Atualizacoes automaticas (ecras de cozinha, bar, caixa...) nao contam como uso: o browser
        // envia ha quantos segundos ninguem mexe (cabecalho X-Nv-Inativo, js/nv_acoes.js). Assim um
        // terminal parado bloqueia aos 45 min mesmo com o ecra a atualizar-se sozinho.
        $inativo = $_SERVER['HTTP_X_NV_INATIVO'] ?? null;
        if ($inativo !== null && preg_match('/^\d{1,7}$/', (string)$inativo)) {
            $ultimoUso = $now - (int)$inativo;
            if ($last > 0) {
                $ultimoUso = max($last, $ultimoUso);
            }
            $last = min($now, $ultimoUso);
            $_SESSION['last_activity'] = $last;
            $now = time();
            if (($now - $last) <= ($minutes * 60)) {
                return true;
            }
        }
        if ($last > 0 && ($now - $last) > ($minutes * 60)) {
            $_SESSION = [];
            session_unset();
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }
            return false;
        }
        $_SESSION['last_activity'] = $now;
        return true;
    }
}

if (!function_exists('security_regenerate_session')) {
    function security_regenerate_session(int $minutes = 15): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        if (isset($_SESSION['__sessao_obsoleta_em'])) {
            // Pedido que saiu com o ID antigo enquanto outro o renovava: nao renovar outra vez.
            return;
        }
        $now = time();
        $last = (int)($_SESSION['last_regen'] ?? 0);
        if ($last === 0 || ($now - $last) > ($minutes * 60)) {
            // Antes: session_regenerate_id(true) apagava logo a sessao antiga e os pedidos que
            // estavam a correr em paralelo (dashboards, Super Admin) ficavam com sessao vazia,
            // o que desligava o utilizador. Agora a antiga fica marcada como obsoleta e so
            // serve durante SECURITY_SESSAO_GRACA_SEGUNDOS (ver security_session_verificar_obsoleta).
            $_SESSION['__sessao_obsoleta_em'] = $now;
            session_regenerate_id(false);
            unset($_SESSION['__sessao_obsoleta_em']);
            $_SESSION['last_regen'] = $now;
        }
    }
}

if (!defined('SECURITY_SESSAO_GRACA_SEGUNDOS')) {
    define('SECURITY_SESSAO_GRACA_SEGUNDOS', 60);
}

if (!function_exists('security_session_verificar_obsoleta')) {
    /**
     * Sessao antiga depois de uma renovacao: aceite so durante a graca (pedidos em paralelo);
     * depois disso fica vazia (utilizador desligado), para um cookie antigo roubado nao servir.
     */
    function security_session_verificar_obsoleta(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION['__sessao_obsoleta_em'])) {
            return;
        }
        if ((time() - (int)$_SESSION['__sessao_obsoleta_em']) > SECURITY_SESSAO_GRACA_SEGUNDOS) {
            $_SESSION = [];
            if (function_exists('security_log_event')) {
                security_log_event('session_obsoleta_rejeitada', []);
            }
        }
    }
}

if (!function_exists('security_trusted_proxies')) {
    /**
     * Lista de proxies de confiança (IPs ou CIDR), via TRUSTED_PROXIES=1.2.3.4,10.0.0.0/8
     * Em Cloudflare, REMOTE_ADDR costuma ser o edge; use também CF-Connecting-IP.
     */
    function security_trusted_proxies(): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }
        $raw = trim((string)(getenv('TRUSTED_PROXIES') ?: ''));
        if ($raw === '') {
            $cache = [];
            return $cache;
        }
        $parts = array_filter(array_map('trim', explode(',', $raw)));
        $cache = array_values($parts);
        return $cache;
    }
}

if (!function_exists('security_ip_in_cidr')) {
    function security_ip_in_cidr(string $ip, string $cidr): bool
    {
        if ($cidr === '*' || $cidr === $ip) {
            return true;
        }
        if (strpos($cidr, '/') === false) {
            return hash_equals($cidr, $ip);
        }
        [$subnet, $mask] = explode('/', $cidr, 2);
        $mask = (int)$mask;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if ($mask < 0 || $mask > 32) {
                return false;
            }
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            if ($ipLong === false || $subnetLong === false) {
                return false;
            }
            $maskLong = $mask === 0 ? 0 : (~0 << (32 - $mask));
            return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
        }
        return false;
    }
}

if (!function_exists('security_is_trusted_proxy')) {
    function security_is_trusted_proxy(string $remoteAddr): bool
    {
        $proxies = security_trusted_proxies();
        if ($proxies === []) {
            return false;
        }
        foreach ($proxies as $proxy) {
            if (security_ip_in_cidr($remoteAddr, $proxy)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('security_normalize_ip')) {
    function security_normalize_ip(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        // "client, proxy1, proxy2" → primeiro = cliente (quando o proxy acrescenta à esquerda)
        if (strpos($raw, ',') !== false) {
            $parts = array_map('trim', explode(',', $raw));
            $raw = (string)($parts[0] ?? '');
        }
        if (filter_var($raw, FILTER_VALIDATE_IP)) {
            return $raw;
        }
        return '';
    }
}

if (!function_exists('security_get_client_ip')) {
    function security_get_client_ip(): string
    {
        $remote = security_normalize_ip((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($remote === '') {
            $remote = '0.0.0.0';
        }

        $behindCloudflare = security_is_trusted_proxy($remote)
            && !empty($_SERVER['HTTP_CF_RAY'])
            && !empty($_SERVER['HTTP_CF_CONNECTING_IP']);
        $trustForwarded = security_is_trusted_proxy($remote)
            || security_is_local_request();

        // Sem proxy de confiança: nunca usar X-Forwarded-For / X-Real-IP (spoofável).
        if (!$trustForwarded) {
            return $remote;
        }

        // Cloudflare (CF-Ray presente)
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $cf = security_normalize_ip((string)$_SERVER['HTTP_CF_CONNECTING_IP']);
            if ($cf !== '') {
                return $cf;
            }
        }

        $candidates = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
        ];
        foreach ($candidates as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = security_normalize_ip((string)$_SERVER[$key]);
                if ($ip !== '') {
                    return $ip;
                }
            }
        }

        return $remote;
    }
}

if (!function_exists('security_rate_limit')) {
    function security_rate_limit(string $actionKey, int $maxAttempts, int $windowSeconds): bool
    {
        $ip = security_get_client_ip();
        $keyRaw = $actionKey . '|' . $ip;
        $windowSeconds = max(1, $windowSeconds);

        // Preferencia: contador atomico na base de dados (funciona com varios
        // servidores e nao se perde quando o sistema limpa a pasta temporaria).
        $db = security_state_db('20261030_1300_estado_seguranca');
        if ($db instanceof PDO) {
            try {
                return security_rate_limit_db($db, $keyRaw, $maxAttempts, $windowSeconds);
            } catch (Throwable $e) {
                error_log('[security_rate_limit] base de dados indisponivel, a usar ficheiro: ' . $e->getMessage());
            }
        }

        // Fallback legado: ficheiro temporario (antes da migracao ser aplicada).
        $key = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $keyRaw);
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'rate_' . $key . '.json';
        $now = time();
        $data = ['start' => $now, 'count' => 0];

        if (is_file($path)) {
            $raw = @file_get_contents($path);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['start'], $decoded['count'])) {
                    $data = $decoded;
                }
            }
        }

        if (($now - (int)$data['start']) > $windowSeconds) {
            $data = ['start' => $now, 'count' => 0];
        }

        $data['count'] = (int)$data['count'] + 1;
        @file_put_contents($path, json_encode($data), LOCK_EX);

        return $data['count'] <= $maxAttempts;
    }
}

if (!function_exists('security_rate_limit_db')) {
    /**
     * Incrementa o contador da janela numa unica instrucao atomica e devolve
     * se o pedido ainda esta dentro do limite.
     */
    function security_rate_limit_db(PDO $db, string $keyRaw, int $maxAttempts, int $windowSeconds): bool
    {
        $now = time();
        $limiteJanela = $now - $windowSeconds; // janelas iniciadas antes disto expiraram
        $stmt = $db->prepare('
            INSERT INTO security_rate_limits (chave, janela_inicio, contador, expira_em)
            VALUES (:chave, :agora, LAST_INSERT_ID(1), :expira)
            ON DUPLICATE KEY UPDATE
                contador = LAST_INSERT_ID(IF(janela_inicio < :limite1, 1, contador + 1)),
                expira_em = IF(janela_inicio < :limite2, :expira2, expira_em),
                janela_inicio = IF(janela_inicio < :limite3, :agora2, janela_inicio)
        ');
        $stmt->execute([
            ':chave' => hash('sha256', $keyRaw),
            ':agora' => $now,
            ':expira' => $now + $windowSeconds,
            ':limite1' => $limiteJanela,
            ':limite2' => $limiteJanela,
            ':expira2' => $now + $windowSeconds,
            ':limite3' => $limiteJanela,
            ':agora2' => $now,
        ]);
        $contador = (int)$db->query('SELECT LAST_INSERT_ID()')->fetchColumn();

        // Limpeza ocasional de janelas expiradas (cerca de 1 em cada 200 pedidos).
        if (random_int(1, 200) === 1) {
            try {
                $db->prepare('DELETE FROM security_rate_limits WHERE expira_em < :agora LIMIT 500')
                    ->execute([':agora' => $now]);
            } catch (Throwable $e) {
                // limpeza nao e critica
            }
        }

        return $contador <= $maxAttempts;
    }
}

if (!function_exists('security_state_db')) {
    /**
     * Ligacao PDO dedicada ao estado de seguranca (rate limit, 2FA pendente),
     * ou null se a migracao indicada ainda nao estiver aplicada / sem base.
     * Ligacao propria: nunca participa nas transacoes da aplicacao.
     */
    function security_state_db(string $migrationId): ?PDO
    {
        static $db = false;
        static $aplicada = [];

        if ($db === false) {
            $db = null;
            try {
                if (!class_exists('Database')) {
                    require_once __DIR__ . '/database.php';
                }
                require_once __DIR__ . '/migration_state.php';
                $conn = Database::infra();
                $db = $conn instanceof PDO ? $conn : null;
            } catch (Throwable $e) {
                $db = null;
            }
        }
        if (!$db instanceof PDO) {
            return null;
        }
        if (!array_key_exists($migrationId, $aplicada)) {
            $aplicada[$migrationId] = migration_esta_aplicada($db, $migrationId);
        }
        return $aplicada[$migrationId] ? $db : null;
    }
}

if (!function_exists('security_csrf_token')) {
    function security_csrf_token(int $ttlSeconds = 7200): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        $now = time();
        $token = (string)($_SESSION['csrf_token'] ?? '');
        $issuedAt = (int)($_SESSION['csrf_token_time'] ?? 0);
        if ($token === '' || $issuedAt === 0 || ($now - $issuedAt) > $ttlSeconds) {
            $token = bin2hex(random_bytes(32));
            $_SESSION['csrf_token'] = $token;
            $_SESSION['csrf_token_time'] = $now;
        }
        return $token;
    }
}

if (!function_exists('security_get_request_csrf')) {
    function security_get_request_csrf(): ?string
    {
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!empty($header)) {
            return (string)$header;
        }
        if (isset($_POST['csrf_token'])) {
            return (string)$_POST['csrf_token'];
        }
        return null;
    }
}

if (!function_exists('security_validate_csrf')) {
    function security_validate_csrf(?string $token): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }
        $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
        if ($sessionToken === '' || $token === null || $token === '') {
            return false;
        }
        return hash_equals($sessionToken, $token);
    }
}

if (!function_exists('security_validate_upload')) {
    function security_validate_upload(array $file, array $allowedExt, array $allowedMime, int $maxBytes, ?string &$error = null): bool
    {
        $error = null;
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            $error = 'Upload inválido.';
            return false;
        }
        if (!isset($file['size']) || (int)$file['size'] > $maxBytes) {
            $error = 'Arquivo muito grande.';
            return false;
        }
        $name = (string)($file['name'] ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowedExt, true)) {
            $error = 'Extensão não permitida.';
            return false;
        }
        if (!isset($file['tmp_name']) || !is_file($file['tmp_name'])) {
            $error = 'Arquivo temporário inválido.';
            return false;
        }
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
            if ($finfo) finfo_close($finfo);
            if ($mime && !in_array($mime, $allowedMime, true)) {
                $error = 'Tipo de arquivo não permitido.';
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('security_random_filename')) {
    function security_random_filename(string $extension): string
    {
        $extension = strtolower(preg_replace('/[^a-z0-9]/', '', $extension));
        return bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . $extension : '');
    }
}

if (!function_exists('security_save_uploaded_image')) {
    function security_save_uploaded_image(array $file, string $targetDir, ?string &$error = null, int $maxBytes = 2097152): ?string
    {
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
        $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
        if (!security_validate_upload($file, $allowedExt, $allowedMime, $maxBytes, $error)) {
            return null;
        }

        $realTargetDir = rtrim($targetDir, DIRECTORY_SEPARATOR);
        if (!is_dir($realTargetDir) && !@mkdir($realTargetDir, 0755, true)) {
            $error = 'Nao foi possivel criar o diretorio seguro de upload.';
            return null;
        }

        $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        $filename = security_random_filename($extension);
        $path = $realTargetDir . DIRECTORY_SEPARATOR . $filename;

        if (!move_uploaded_file((string)$file['tmp_name'], $path)) {
            $error = 'Nao foi possivel salvar o arquivo.';
            return null;
        }

        @chmod($path, 0644);
        // Reduz fotos grandes (ex.: 4 MB do telemovel) sem mudar o nome nem o formato.
        require_once __DIR__ . '/imagem_otimizar.php';
        imagem_otimizar($path, 1600);
        return $filename;
    }
}

if (!function_exists('security_clean_string')) {
    function security_clean_string($value, int $maxLength = 255): string
    {
        $value = trim((string)$value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength, 'UTF-8');
        }
        return substr($value, 0, $maxLength);
    }
}

if (!function_exists('security_validate_email')) {
    function security_validate_email($email): ?string
    {
        $email = security_clean_string($email, 254);
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}

if (!function_exists('security_e')) {
    function security_e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('security_json')) {
    function security_json(array $payload, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('security_exception_message')) {
    /**
     * Registra detalhes técnicos internamente e devolve uma mensagem segura.
     * DomainException e InvalidArgumentException representam erros de negócio
     * ou validação que podem ser exibidos.
     */
    function security_exception_message(
        Throwable $exception,
        string $productionMessage = 'Erro interno ao processar a solicitacao.',
        string $event = 'application_exception',
        bool $preserveDomainException = true
    ): string {
        security_log_event($event, [
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ]);

        if (
            $preserveDomainException
            && ($exception instanceof DomainException || $exception instanceof InvalidArgumentException)
        ) {
            $businessMessage = trim($exception->getMessage());
            if ($businessMessage !== '') {
                return $businessMessage;
            }
        }

        if (!security_is_production()) {
            $developmentMessage = trim($exception->getMessage());
            if ($developmentMessage !== '') {
                return $developmentMessage;
            }
        }

        return $productionMessage;
    }
}

if (!function_exists('security_log_key_is_secret')) {
    function security_log_key_is_secret(string $key): bool
    {
        $normalized = strtolower(trim($key));
        return preg_match(
            '/(^|[_\-.])(token|password|senha|otp|key|chave|session|session_id|authorization|cookie)([_\-.]|$)/i',
            $normalized
        ) === 1;
    }
}

if (!function_exists('security_sanitize_url_for_log')) {
    /**
     * Mantém somente o caminho e os nomes dos parâmetros. Todos os valores
     * de query são mascarados, inclusive em URLs relativas.
     */
    function security_sanitize_url_for_log(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $fragmentPos = strpos($url, '#');
        if ($fragmentPos !== false) {
            $url = substr($url, 0, $fragmentPos);
        }
        $queryPos = strpos($url, '?');
        $pathPart = $queryPos === false ? $url : substr($url, 0, $queryPos);
        $queryPart = $queryPos === false ? '' : substr($url, $queryPos + 1);
        $parsedPath = parse_url($pathPart, PHP_URL_PATH);
        $path = is_string($parsedPath) && $parsedPath !== '' ? $parsedPath : '/';

        if ($queryPart === '') {
            return $path;
        }

        $masked = [];
        foreach (preg_split('/[&;]/', $queryPart) ?: [] as $pair) {
            if ($pair === '') {
                continue;
            }
            $key = explode('=', $pair, 2)[0];
            $masked[] = $key . '=[REDACTED]';
        }

        return $path . ($masked !== [] ? '?' . implode('&', $masked) : '');
    }
}

if (!function_exists('security_redact_log_data')) {
    /**
     * Redação central e recursiva para qualquer contexto antes da persistência.
     */
    function security_redact_log_data($value, ?string $key = null)
    {
        if ($key !== null && security_log_key_is_secret($key)) {
            return '[REDACTED]';
        }
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $childKey => $childValue) {
                $safe[$childKey] = security_redact_log_data($childValue, (string)$childKey);
            }
            return $safe;
        }
        if (is_object($value)) {
            return security_redact_log_data(get_object_vars($value), $key);
        }
        if (is_string($value) && (preg_match('#^(?:https?://|/)#i', $value) === 1) && str_contains($value, '?')) {
            return security_sanitize_url_for_log($value);
        }
        return $value;
    }
}

if (!function_exists('security_require_roles')) {
    function security_require_roles(array $roles): void
    {
        if (!empty($_SESSION['super_admin']) && (int)$_SESSION['super_admin'] === 1) {
            return;
        }
        $perfil = strtoupper(trim((string)($_SESSION['perfil'] ?? '')));
        $perfil = str_replace(['GARÇOM', 'GARÃ‡OM'], 'GARCOM', $perfil);
        if (function_exists('normalizePerfil')) {
            $perfil = normalizePerfil($perfil);
        } elseif (function_exists('usuario_perfil_normalizar')) {
            $perfil = usuario_perfil_normalizar($perfil);
        } elseif (function_exists('turno_normalizar_perfil')) {
            $perfil = turno_normalizar_perfil($perfil);
        }
        $roles = array_map(static function ($role) {
            $r = strtoupper(trim((string)$role));
            $r = str_replace(['GARÇOM', 'GARÃ‡OM'], 'GARCOM', $r);
            if (function_exists('normalizePerfil')) {
                return normalizePerfil($r);
            }
            if (function_exists('usuario_perfil_normalizar')) {
                return usuario_perfil_normalizar($r);
            }
            if (function_exists('turno_normalizar_perfil')) {
                return turno_normalizar_perfil($r);
            }
            return $r;
        }, $roles);
        if (!in_array($perfil, $roles, true)) {
            security_log_event('authorization_denied', ['perfil' => $perfil, 'roles' => $roles]);
            if (str_contains((string)($_SERVER['REQUEST_URI'] ?? ''), '/api/')) {
                security_json(['success' => false, 'message' => 'Sem permissao.'], 403);
            }
            header('Location: dashboard.php?erro=sem_permissao');
            exit;
        }
    }
}

if (!function_exists('security_require_restaurante_context')) {
    /**
     * Ensures the active session has a restaurante and rejects a client-supplied
     * restaurante_id that does not match the session (anti-IDOR / tenant hop).
     * Super-admin may omit a matching session restaurant when $restauranteId is null.
     */
    function security_require_restaurante_context(?int $restauranteId = null): int
    {
        $isSuperAdmin = !empty($_SESSION['super_admin']) && (int)$_SESSION['super_admin'] === 1;
        $sessaoRestaurante = (int)($_SESSION['restaurante_id'] ?? 0);

        if ($isSuperAdmin && $sessaoRestaurante <= 0 && ($restauranteId === null || $restauranteId <= 0)) {
            return 0;
        }

        if ($sessaoRestaurante <= 0) {
            security_log_event('tenant_violation', [
                'session_restaurante_id' => $sessaoRestaurante,
                'requested_restaurante_id' => $restauranteId,
            ]);
            security_json(['success' => false, 'message' => 'Contexto de restaurante invalido.'], 403);
        }

        if ($restauranteId !== null && $restauranteId > 0 && $restauranteId !== $sessaoRestaurante && !$isSuperAdmin) {
            security_log_event('tenant_violation', [
                'session_restaurante_id' => $sessaoRestaurante,
                'requested_restaurante_id' => $restauranteId,
            ]);
            security_json(['success' => false, 'message' => 'Contexto de restaurante invalido.'], 403);
        }

        return $sessaoRestaurante;
    }
}

if (!function_exists('security_log_event')) {
    function security_log_event(string $event, array $context = []): void
    {
        $baseDir = dirname(__DIR__, 2);
        $logDir = nv_storage('logs');
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $ip = security_get_client_ip();
        $safeUri = security_sanitize_url_for_log((string)($_SERVER['REQUEST_URI'] ?? ''));
        $line = [
            'time' => date('c'),
            'event' => $event,
            'ip' => $ip,
            'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'uri' => $safeUri !== '' ? $safeUri : null,
            'usuario_id' => $_SESSION['usuario_id'] ?? null,
            'restaurante_id' => $_SESSION['restaurante_id'] ?? null,
            'context' => security_redact_log_data($context),
        ];
        $path = $logDir . DIRECTORY_SEPARATOR . 'security.log';
        $encoded = json_encode($line, JSON_UNESCAPED_UNICODE);
        @file_put_contents($path, $encoded . PHP_EOL, FILE_APPEND | LOCK_EX);

        $eventUpper = strtoupper($event);
        $isCritical = str_contains($eventUpper, 'WAF')
            || str_contains($eventUpper, 'BLOCK')
            || str_contains($eventUpper, 'SQLI')
            || str_contains($eventUpper, 'XSS')
            || str_contains($eventUpper, 'BRUTE')
            || str_contains($eventUpper, 'TENANT_VIOLATION')
            || str_contains($eventUpper, 'AUTHORIZATION_DENIED')
            || str_contains($eventUpper, 'LOGIN_NEW_IP')
            || str_contains($eventUpper, 'LOGIN_FAILED');

        if ($isCritical) {
            $alertPath = $logDir . DIRECTORY_SEPARATOR . 'security_alerts.log';
            @file_put_contents($alertPath, $encoded . PHP_EOL, FILE_APPEND | LOCK_EX);

            // Alerta outbound imediato (com cooldown) para eventos críticos
            $notifyFile = __DIR__ . DIRECTORY_SEPARATOR . 'security_alert_notify.php';
            if (is_file($notifyFile)) {
                require_once $notifyFile;
                if (function_exists('security_siem_forward')) {
                    @security_siem_forward([
                        'event' => $event,
                        'severity' => 'critical',
                        'record' => $line,
                    ]);
                }
                if (
                    function_exists('security_alert_notify')
                    && !str_contains($eventUpper, 'LOGIN_FAILED')
                    && !str_contains($eventUpper, 'LOGIN_NEW_IP')
                ) {
                    $html = '<p><strong>Evento de segurança:</strong> '
                        . htmlspecialchars($event, ENT_QUOTES, 'UTF-8') . '</p>'
                        . '<ul>'
                        . '<li>IP: ' . htmlspecialchars($ip, ENT_QUOTES, 'UTF-8') . '</li>'
                        . '<li>URI: ' . htmlspecialchars($safeUri, ENT_QUOTES, 'UTF-8') . '</li>'
                        . '</ul>';
                    @security_alert_notify(
                        '[Segurança] ' . $event,
                        $html,
                        [
                            'event' => $event,
                            'dedupe_key' => 'evt_' . preg_replace('/[^a-z0-9_]+/i', '_', $event),
                            'cooldown' => 1800,
                        ]
                    );
                }
            }
        }
    }
}

if (!function_exists('security_audit_event')) {
    function security_audit_event(string $action, array $context = []): void
    {
        $context['usuario_id'] = $_SESSION['usuario_id'] ?? null;
        $context['restaurante_id'] = $_SESSION['restaurante_id'] ?? null;
        security_log_event('audit_' . $action, $context);
    }
}
