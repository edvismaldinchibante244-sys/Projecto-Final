<?php
/**
 * Tempo real (Socket.IO, services/realtime/server.js) - configuracao do lado PHP.
 *
 * So fica ativo quando o .env define:
 *   REALTIME_URL=http://localhost:3001        (endereco que o BROWSER usa)
 *   REALTIME_SECRET=<32+ caracteres aleatorios>  (o mesmo no processo Node)
 *   REALTIME_INTERNAL_URL=http://127.0.0.1:3001  (opcional: endereco que o PHP usa)
 * Sem isto (ex.: alojamento sem Node), nada tenta ligar e as paginas usam o modo de recurso.
 */

if (!function_exists('realtime_config')) {
    /** @return array{url:string, interno:string, segredo:string}|null */
    function realtime_config(): ?array
    {
        $url = rtrim(trim((string)(getenv('REALTIME_URL') ?: '')), '/');
        $segredo = (string)(getenv('REALTIME_SECRET') ?: '');
        if ($url === '' || strlen($segredo) < 32 || !preg_match('#^https?://[^/\s]+$#i', $url)) {
            return null;
        }
        $interno = rtrim(trim((string)(getenv('REALTIME_INTERNAL_URL') ?: '')), '/');
        return ['url' => $url, 'interno' => $interno !== '' ? $interno : $url, 'segredo' => $segredo];
    }
}

if (!function_exists('realtime_origens_csp')) {
    /** Origens a acrescentar a CSP (script-src e connect-src), ou [] se desligado. */
    function realtime_origens_csp(): array
    {
        $cfg = realtime_config();
        if (!$cfg) {
            return [];
        }
        $ws = preg_replace('#^http#i', 'ws', $cfg['url']);
        return ['script' => $cfg['url'], 'connect' => [$cfg['url'], $ws]];
    }
}

if (!function_exists('realtime_codigo_sala')) {
    /**
     * Codigo assinado para o browser entrar na sala do SEU restaurante (valido $ttl segundos).
     * @return array{url:string, room:string, exp:int, sig:string}|null
     */
    function realtime_codigo_sala(int $restauranteId, int $ttl = 43200): ?array
    {
        $cfg = realtime_config();
        if (!$cfg || $restauranteId <= 0) {
            return null;
        }
        $sala = 'restaurante_' . $restauranteId;
        $exp = time() + $ttl;
        return [
            'url' => $cfg['url'],
            'room' => $sala,
            'exp' => $exp,
            'sig' => hash_hmac('sha256', $sala . '|' . $exp, $cfg['segredo']),
        ];
    }
}

if (!function_exists('realtime_notificar')) {
    /** Avisa o servidor de tempo real de um pedido novo. Nunca falha nem demora mais de 1 s. */
    function realtime_notificar(int $restauranteId, array $pedido): bool
    {
        $cfg = realtime_config();
        if (!$cfg || $restauranteId <= 0 || !function_exists('curl_init')) {
            return false;
        }
        $payload = json_encode(['restaurante_id' => $restauranteId, 'pedido' => $pedido], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return false;
        }
        $ch = curl_init($cfg['interno'] . '/notify');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Realtime-Secret: ' . $cfg['segredo']],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 1,
            CURLOPT_CONNECTTIMEOUT => 1,
        ]);
        curl_exec($ch);
        $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $codigo >= 200 && $codigo < 300;
    }
}

if (!function_exists('realtime_avisar_mudanca')) {
    /**
     * Avisa os ecras do restaurante de que algo mudou na operacao (pedido, item, mesa).
     * Os ecras recarregam logo, em vez de esperarem pela proxima verificacao. Nunca falha nem demora mais de 1 s.
     */
    function realtime_avisar_mudanca(int $restauranteId): bool
    {
        $cfg = realtime_config();
        if (!$cfg || $restauranteId <= 0 || !function_exists('curl_init')) {
            return false;
        }
        $ch = curl_init($cfg['interno'] . '/notify');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['restaurante_id' => $restauranteId, 'tipo' => 'mudou']),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Realtime-Secret: ' . $cfg['segredo']],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 1,
            CURLOPT_CONNECTTIMEOUT => 1,
        ]);
        curl_exec($ch);
        $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $codigo >= 200 && $codigo < 300;
    }

    /** Numero da versao de operacao do restaurante (tabela operacao_versao), ou null se indisponivel. */
    function realtime_versao_operacao(int $restauranteId): ?string
    {
        try {
            if (!class_exists('Database')) {
                require_once __DIR__ . '/database.php';
            }
            $db = (new Database())->getConnection();
            $st = $db->prepare('SELECT versao FROM operacao_versao WHERE restaurante_id = ?');
            $st->execute([$restauranteId]);
            $v = $st->fetchColumn();
            return $v === false ? '0' : (string)$v;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Chamado no inicio de cada pedido que altera dados (POST/PUT/PATCH/DELETE) nas APIs.
     * No fim do pedido, se a versao da operacao mudou, avisa o servidor de tempo real.
     * Sem tempo real configurado nao faz nada (nem consulta a base de dados).
     */
    function realtime_vigiar_pedido_http(int $restauranteId): void
    {
        static $registado = false;
        if ($registado || $restauranteId <= 0 || !realtime_config()) {
            return;
        }
        $antes = realtime_versao_operacao($restauranteId);
        if ($antes === null) {
            return;
        }
        $registado = true;
        register_shutdown_function(static function () use ($restauranteId, $antes): void {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request(); // a resposta segue ja para o browser; o aviso vai a seguir
            }
            $depois = realtime_versao_operacao($restauranteId);
            if ($depois !== null && $depois !== $antes) {
                realtime_avisar_mudanca($restauranteId);
            }
        });
    }
}
