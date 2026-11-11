<?php
/**
 * "Sessao em pausa": um so ecra para retomar, com o PIN (equipa) ou a palavra-passe.
 *
 * Quando a sessao expira, em vez do login completo (email + palavra-passe + codigo 2FA), o mesmo
 * navegador mostra quem estava ligado e pede so a palavra-passe. Valido 12 horas desde o ultimo
 * login completo ou retoma; so no navegador onde se fez o login (cookie HttpOnly, aleatorio).
 * 5 palavras-passe erradas apagam a "pausa" e obrigam ao login completo.
 * Super Admin e terminais (PIN) ficam de fora.
 */

require_once __DIR__ . '/security.php';

if (!defined('SR_COOKIE')) {
    define('SR_COOKIE', 'nivora_retomar');
    define('SR_HORAS', 12);
    define('SR_MAX_FALHAS', 5);
    define('SR_MINUTOS_MANTER', 720); // "Manter sessao iniciada": 12 h sem uso
}

if (!function_exists('sr_tem_cookie')) {
    /** Verificacao barata (sem base de dados), para decidir para onde redirecionar. */
    function sr_tem_cookie(): bool
    {
        return (bool)preg_match('/^[a-f0-9]{64}$/', (string)($_COOKIE[SR_COOKIE] ?? ''));
    }

    function sr_gravar_cookie(string $valor, int $expira): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(SR_COOKIE, $valor, [
            'expires' => $expira,
            'path' => '/',
            'secure' => security_is_production() || security_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if ($valor === '') {
            unset($_COOKIE[SR_COOKIE]);
        } else {
            $_COOKIE[SR_COOKIE] = $valor;
        }
    }

    function sr_disponivel(PDO $db): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                $db->query('SELECT 1 FROM sessoes_retomar LIMIT 1');
                $ok = true;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    /** Depois de um login completo (ou de uma retoma): cria o codigo deste navegador. */
    function sr_criar(PDO $db, int $uid, int $rid, bool $manter = false): void
    {
        if ($uid <= 0 || $rid <= 0 || !sr_disponivel($db)) {
            return;
        }
        sr_apagar_atual($db);
        $token = bin2hex(random_bytes(32));
        $db->prepare('INSERT INTO sessoes_retomar (token_hash, usuario_id, restaurante_id, manter, expira_em) VALUES (?, ?, ?, ?, NOW() + INTERVAL ' . SR_HORAS . ' HOUR)')
            ->execute([hash('sha256', $token), $uid, $rid, $manter ? 1 : 0]);
        sr_gravar_cookie($token, time() + SR_HORAS * 3600);
    }

    /** A "pausa" deste navegador, com o nome de quem estava ligado, ou null. */
    function sr_obter(PDO $db): ?array
    {
        if (!sr_tem_cookie() || !sr_disponivel($db)) {
            return null;
        }
        $st = $db->prepare("SELECT s.id, s.usuario_id, s.restaurante_id, s.falhas, s.manter, u.nome, u.email, u.perfil, u.ativo, u.super_admin, (u.pin_hash IS NOT NULL AND u.pin_hash <> '') AS tem_pin, r.nome AS restaurante_nome
            FROM sessoes_retomar s
            JOIN usuarios u ON u.id = s.usuario_id AND u.restaurante_id = s.restaurante_id
            LEFT JOIN restaurantes r ON r.id = s.restaurante_id
            WHERE s.token_hash = ? AND s.expira_em > NOW() LIMIT 1");
        $st->execute([hash('sha256', (string)$_COOKIE[SR_COOKIE])]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || (int)($r['ativo'] ?? 1) !== 1 || !empty($r['super_admin'])) {
            return null;
        }
        foreach (['nome', 'email', 'restaurante_nome'] as $c) {
            if (isset($r[$c]) && function_exists('personal_data_decrypt')) {
                $r[$c] = (string)personal_data_decrypt((string)$r[$c]);
            }
        }
        return $r;
    }

    /** Apaga a "pausa" deste navegador (sair, entrar com outra conta, erros a mais). */
    function sr_apagar_atual(?PDO $db): void
    {
        if (sr_tem_cookie() && $db instanceof PDO && sr_disponivel($db)) {
            try {
                $db->prepare('DELETE FROM sessoes_retomar WHERE token_hash = ?')->execute([hash('sha256', (string)$_COOKIE[SR_COOKIE])]);
            } catch (Throwable $e) {
            }
        }
        if (isset($_COOKIE[SR_COOKIE])) {
            sr_gravar_cookie('', time() - 3600);
        }
    }

    /** Palavra-passe errada: conta a falha; ao fim de SR_MAX_FALHAS apaga a pausa. Devolve as tentativas que restam. */
    function sr_falhou(PDO $db, array $pausa): int
    {
        $falhas = (int)$pausa['falhas'] + 1;
        if ($falhas >= SR_MAX_FALHAS) {
            sr_apagar_atual($db);
            return 0;
        }
        $db->prepare('UPDATE sessoes_retomar SET falhas = ? WHERE id = ?')->execute([$falhas, (int)$pausa['id']]);
        return SR_MAX_FALHAS - $falhas;
    }

    /** Pagina para onde voltar depois de retomar (so paginas internas deste sistema). */
    function sr_voltar_seguro(?string $v): string
    {
        $v = trim((string)$v);
        if ($v === '' || !preg_match('#^[a-z0-9_]+\.php(\?[A-Za-z0-9_=&%.\-]*)?$#', $v)) {
            return '';
        }
        if (preg_match('#^(index|terminal|retomar|logout|login_process|__e2e_login)\.php#', $v)) {
            return '';
        }
        return $v;
    }

    /** Pagina atual (para voltar a ela depois de retomar), so em pedidos de pagina (GET, fora de /api/). */
    function sr_pagina_atual(): string
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET' || strpos($uri, '/api/') !== false) {
            return '';
        }
        $caminho = (string)parse_url($uri, PHP_URL_PATH);
        $q = (string)parse_url($uri, PHP_URL_QUERY);
        return sr_voltar_seguro(basename($caminho) . ($q !== '' ? '?' . $q : ''));
    }

    function sr_limpar_expiradas(PDO $db): int
    {
        if (!sr_disponivel($db)) {
            return 0;
        }
        return (int)$db->exec('DELETE FROM sessoes_retomar WHERE expira_em < NOW() - INTERVAL 1 DAY');
    }
}
