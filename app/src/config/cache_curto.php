<?php
declare(strict_types=1);

/**
 * Cache curta (segundos a minutos) para coisas que mudam pouco e eram lidas/verificadas a cada pedido.
 *
 * Usa APCu quando existe (memoria partilhada do PHP, o mais rapido); senao, ficheiros pequenos na pasta
 * temporaria. Nunca guarda segredos nem dados pessoais. Se a cache falhar, a funcao corre normalmente.
 *
 *   $v = cache_curto_lembrar('menu:' . $rid, 120, fn() => carregar_menu($db, $rid));
 *   cache_curto_apagar('menu:' . $rid);
 */

if (!function_exists('cache_curto_chave')) {
    function cache_curto_chave(string $chave): string
    {
        // Separa ambientes (ex.: teste e producao no mesmo servidor).
        return 'nv:' . (getenv('DB_NAME') ?: 'db') . ':' . $chave;
    }

    function cache_curto_apcu(): bool
    {
        static $ok = null;
        if ($ok === null) {
            $ok = function_exists('apcu_fetch') && function_exists('apcu_enabled') && apcu_enabled()
                && (PHP_SAPI !== 'cli' || ini_get('apc.enable_cli'));
        }
        return $ok;
    }

    function cache_curto_ficheiro(string $chave): string
    {
        static $dir = null;
        if ($dir === null) {
            $dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'nivora_cache';
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
        }
        return $dir . DIRECTORY_SEPARATOR . sha1($chave) . '.cache';
    }

    /** @return array{0: bool, 1: mixed} [encontrou, valor] */
    function cache_curto_obter(string $chave): array
    {
        if (getenv('NV_SEM_CACHE') === '1') {
            return [false, null];
        }
        $k = cache_curto_chave($chave);
        if (cache_curto_apcu()) {
            $v = apcu_fetch($k, $ok);
            return [(bool)$ok, $ok ? $v : null];
        }
        $f = cache_curto_ficheiro($k);
        $raw = @file_get_contents($f);
        if ($raw === false) {
            return [false, null];
        }
        $d = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($d) || !isset($d[0]) || $d[0] < time()) {
            return [false, null];
        }
        return [true, $d[1]];
    }

    function cache_curto_guardar(string $chave, $valor, int $ttl): void
    {
        if (getenv('NV_SEM_CACHE') === '1' || $ttl <= 0) {
            return;
        }
        $k = cache_curto_chave($chave);
        if (cache_curto_apcu()) {
            apcu_store($k, $valor, $ttl);
            return;
        }
        $f = cache_curto_ficheiro($k);
        $tmp = $f . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, serialize([time() + $ttl, $valor]), LOCK_EX) !== false) {
            @rename($tmp, $f);
        }
    }

    function cache_curto_apagar(string $chave): void
    {
        $k = cache_curto_chave($chave);
        if (cache_curto_apcu()) {
            apcu_delete($k);
            return;
        }
        @unlink(cache_curto_ficheiro($k));
    }

    function cache_curto_lembrar(string $chave, int $ttl, callable $calcular)
    {
        [$achou, $v] = cache_curto_obter($chave);
        if ($achou) {
            return $v;
        }
        $v = $calcular();
        cache_curto_guardar($chave, $v, $ttl);
        return $v;
    }
}
