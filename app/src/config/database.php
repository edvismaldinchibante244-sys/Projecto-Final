<?php

require_once __DIR__ . '/personal_data.php';

/**
 * Conexao principal do app.
 * Usa PDO com prepared statements e nao expoe detalhes internos em producao.
 */
class Database
{
    private $conn;

    private static $envLoaded = false;
    private static $loadedEnvFiles = [];

    private function loadLocalEnv(): void
    {
        if (self::$envLoaded) {
            return;
        }

        self::$envLoaded = true;

        // Prioridade: raiz do projeto (V00) e depois app/ como fallback legado.
        // Nao sobrescreve variavel ja definida para evitar conflito entre multiplos .env.
        $candidates = [
            dirname(__DIR__, 3) . '/.env',
            dirname(__DIR__, 3) . '/.env.local',
            dirname(__DIR__, 2) . '/.env',
            dirname(__DIR__, 2) . '/.env.local',
        ];

        foreach ($candidates as $path) {
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }

            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                continue;
            }

            self::$loadedEnvFiles[] = $path;

            foreach ($lines as $line) {
                $line = trim((string)$line);
                if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                    continue;
                }

                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                if ($key === '') {
                    continue;
                }

                if ($value !== '') {
                    $first = substr($value, 0, 1);
                    $last = substr($value, -1);
                    if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                        $value = substr($value, 1, -1);
                    }
                }

                $current = getenv($key);
                if ($current !== false && $current !== '') {
                    continue;
                }

                if (function_exists('putenv')) {
                    @putenv($key . '=' . $value);
                }
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }

    private function env(string $key, string $default = ''): string
    {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return (string)$value;
        }

        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string)$_ENV[$key];
        }

        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return (string)$_SERVER[$key];
        }

        return $default;
    }

    private function isLocalRequest(): bool
    {
        $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
        return $host === ''
            || $host === 'localhost'
            || $host === '127.0.0.1'
            || str_starts_with($host, 'localhost:')
            || str_starts_with($host, '127.0.0.1:')
            || str_contains($host, '[::1]')
            || str_starts_with($host, '10.0.2.2:')
            || str_starts_with($host, '10.0.3.2:');
    }

    private function isProduction(): bool
    {
        $env = strtolower($this->env('APP_ENV'));
        if ($env === 'production' || $env === 'prod') {
            return true;
        }
        if ($env === 'local' || $env === 'development' || $env === 'dev') {
            return false;
        }
        // APP_ENV ausente ou invalido: assumir SEMPRE producao (fail-safe).
        // Antes decidia-se pelo cabecalho Host, que o cliente controla: um pedido
        // com "Host: localhost" activava credenciais root e mensagens de erro detalhadas.
        return true;
    }

    private function appEnvValido(): bool
    {
        $env = strtolower($this->env('APP_ENV'));
        return in_array($env, ['production', 'prod', 'local', 'development', 'dev'], true);
    }

    /** @var PDO|null Ligacao partilhada pelas verificacoes de infraestrutura (ver infra()). */
    private static $infra = null;

    /**
     * Ligacao partilhada, durante o pedido, pelas verificacoes que correm em TODOS os pedidos
     * (sessao, conta ativa, presenca, turno, limite de tentativas, plano). Antes cada uma abria
     * a sua propria ligacao (5 ligacoes por pedido); com muitos ecras abertos isso pesava muito.
     * Nao usar para operacoes com transacoes ou lastInsertId: essas continuam com new Database().
     */
    public static function infra(): PDO
    {
        if (!self::$infra instanceof PDO) {
            $c = (new self())->getConnection();
            if (!$c instanceof PDO) {
                throw new RuntimeException('Sem ligacao a base de dados.');
            }
            self::$infra = $c;
        }
        return self::$infra;
    }

    /** Objeto Database que usa a ligacao partilhada (para servicos que recebem um Database). */
    public static function comLigacaoInfra(): self
    {
        $d = new self();
        $d->conn = self::infra();
        return $d;
    }

    /** Fuso horario da aplicacao (APP_TIMEZONE; por omissao Africa/Maputo). Aplica-o tambem ao PHP. */
    public static function fusoHorario(): string
    {
        static $nome = null;
        if ($nome === null) {
            (new self())->loadLocalEnv();
            $nome = trim((string)(getenv('APP_TIMEZONE') ?: ($_ENV['APP_TIMEZONE'] ?? '') ?: 'Africa/Maputo'));
            if (!in_array($nome, timezone_identifiers_list(), true)) {
                $nome = 'Africa/Maputo';
            }
            date_default_timezone_set($nome);
        }
        return $nome;
    }

    /** Diferenca para UTC no formato do MySQL (ex.: +02:00). */
    public static function fusoOffset(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(self::fusoHorario())))->format('P');
    }

    public function getConnection()
    {
        if ($this->conn) {
            return $this->conn;
        }

        $this->loadLocalEnv();
        self::fusoHorario();

        // APP_ENV e obrigatorio: sem ele a aplicacao nao arranca.
        if (!$this->appEnvValido()) {
            error_log('APP_ENV ausente ou invalido. Defina APP_ENV=production (servidor) ou APP_ENV=local (desenvolvimento) no .env.');
            $this->fail('Servico temporariamente indisponivel.');
        }

        $host = $this->env('DB_HOST');
        $db   = $this->env('DB_NAME');
        $user = $this->env('DB_USER');
        $pass = $this->env('DB_PASS');
        $port = $this->env('DB_PORT', '3306');
        $isProduction = $this->isProduction();

        $this->debugConfig('before-validation', [
            'APP_ENV' => $this->env('APP_ENV'),
            'HTTP_HOST' => $_SERVER['HTTP_HOST'] ?? '',
            'env_files' => self::$loadedEnvFiles,
            'isProduction' => $isProduction,
            'isLocalRequest' => $this->isLocalRequest(),
            'DB_HOST' => $host,
            'DB_NAME' => $db,
            'DB_USER' => $user,
            'DB_PASS_SET' => $pass !== '',
            'DB_PORT' => $port,
        ]);

        if (!$isProduction) {
            $host = $host ?: 'localhost';
            $db   = $db ?: 'restaurante_saas';
            $user = $user ?: 'root';
            $pass = $pass ?: '';
        } else {
            if ($host === '' || $db === '' || $user === '' || $pass === '') {
                error_log('Database config incompleta em producao: DB_HOST/DB_NAME/DB_USER/DB_PASS sao obrigatorios.');
                $this->fail('Servico temporariamente indisponivel.');
            }

            if ($host === 'localhost' || $host === 'sqlXXX.infinityfree.com' || $host === '') {
                error_log('Database host invalido para producao: ' . $host);
                $message = 'Servico temporariamente indisponivel.';
                $this->fail($message);
            }
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
        $this->debugConfig('before-pdo', [
            'dsn' => $dsn,
            'user' => $user,
            'password_set' => $pass !== '',
        ]);

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            // Alojamentos economicos poem a base de dados a dormir quando nao
            // ha trafego, e a primeira ligacao depois disso e recusada enquanto
            // ela acorda. Insistir algumas vezes evita que o primeiro visitante
            // apanhe "Servico temporariamente indisponivel" e desista.
            // So para falhas de ligacao: credenciais erradas ou base inexistente
            // nao se resolvem esperando, e ai desiste-se de imediato.
            $esperas = $isProduction ? [0, 1500000, 2500000, 4000000] : [0];
            $erroLigacao = null;

            foreach ($esperas as $indice => $espera) {
                if ($espera > 0) {
                    usleep($espera);
                }

                try {
                    $this->conn = new PDO($dsn, $user, $pass, $options);
                    $erroLigacao = null;
                    break;
                } catch (PDOException $tentativaErro) {
                    $erroLigacao = $tentativaErro;
                    if (!self::erroDeLigacao($tentativaErro)) {
                        break;
                    }
                    error_log(sprintf(
                        'Database indisponivel (tentativa %d de %d): %s',
                        $indice + 1,
                        count($esperas),
                        $tentativaErro->getMessage()
                    ));
                }
            }

            if ($erroLigacao !== null) {
                throw $erroLigacao;
            }

            $this->conn->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PersonalDataPDOStatement::class]);
            $this->debugConfig('pdo-connected', [
                'server_version' => $this->conn->getAttribute(PDO::ATTR_SERVER_VERSION),
            ]);

            // PHP e MySQL usam o MESMO fuso horario: o do restaurante (APP_TIMEZONE, por omissao
            // Africa/Maputo). Assim as horas gravadas (NOW()) sao as que o restaurante ve no relogio,
            // o tempo de espera dos pedidos e certo e as expiracoes de tokens continuam coerentes.
            try {
                $this->conn->exec("SET time_zone = '" . self::fusoOffset() . "'");
            } catch (Throwable $e) {
                error_log('Database time_zone config falhou: ' . $e->getMessage());
            }

            return $this->conn;
        } catch (PDOException $e) {
            $localRoot = !$isProduction
                && strtolower(trim($user)) === 'root'
                && in_array(strtolower(trim($host)), ['localhost', '127.0.0.1'], true);
            $accessDenied = (string)$e->getCode() === '1045'
                || str_contains(strtolower($e->getMessage()), 'access denied for user');

            // XAMPP/WAMP normalmente cria root local sem senha. Se o .env ainda
            // tiver uma senha antiga, recupera apenas no ambiente local.
            if ($localRoot && $pass !== '' && $accessDenied) {
                try {
                    $this->conn = new PDO($dsn, $user, '', $options);
                    $this->conn->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PersonalDataPDOStatement::class]);
                    try {
                        $this->conn->exec("SET time_zone = '" . self::fusoOffset() . "'");
                    } catch (Throwable $timezoneError) {
                        error_log('Database time_zone config falhou: ' . $timezoneError->getMessage());
                    }
                    error_log('[DB_LOCAL] Credencial root com senha recusada; conexao local recuperada sem senha. Ajuste DB_PASS no .env.');
                    return $this->conn;
                } catch (PDOException $fallbackError) {
                    error_log('Database local empty-password fallback falhou: ' . $fallbackError->getMessage());
                }
            }

            error_log('Database connection error at ' . $e->getFile() . ':' . $e->getLine() . ' - ' . $e->getMessage());
            $message = $isProduction
                ? 'Servico temporariamente indisponivel.'
                : 'Erro de conexao: ' . $e->getMessage();
            $this->fail($message);
        }
    }

    private function debugConfig(string $stage, array $context): void
    {
        $debug = filter_var($this->env('DB_DEBUG'), FILTER_VALIDATE_BOOLEAN);
        $dump = filter_var($this->env('DB_DEBUG_DUMP'), FILTER_VALIDATE_BOOLEAN);

        if (!$debug && !$dump) {
            return;
        }

        // Nunca expor configuracao, DSN ou contexto interno fora do ambiente local.
        // Mesmo que uma variavel de debug seja ativada por engano no servidor,
        // a aplicacao ignora o pedido e continua com respostas genericas.
        if ($this->isProduction()) {
            error_log('[DB_DEBUG] DB_DEBUG/DB_DEBUG_DUMP ignorado em producao.');
            return;
        }

        if (array_key_exists('DB_PASS', $context)) {
            $context['DB_PASS'] = $context['DB_PASS'] === '' ? '' : '[set]';
        }

        error_log('[DB_DEBUG][' . $stage . '] ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if ($dump) {
            header('Content-Type: text/plain; charset=utf-8');
            var_dump($stage, $context);
            die('DB_DEBUG_DUMP interrompeu a execucao.');
        }
    }

    /**
     * Distingue uma base inacessivel (adormecida, a reiniciar, rede em baixo)
     * de um erro que nao se resolve com tempo (credenciais, base inexistente).
     * So a primeira justifica insistir.
     */
    private static function erroDeLigacao(PDOException $e): bool
    {
        $codigo = (string)$e->getCode();
        if (in_array($codigo, ['2002', '2003', '2006', '2013'], true)) {
            return true;
        }

        $msg = strtolower($e->getMessage());
        foreach (['connection refused', 'can\'t connect', 'gone away', 'timed out', 'lost connection', 'no such host', 'name or service not known'] as $pista) {
            if (str_contains($msg, $pista)) {
                return true;
            }
        }

        return false;
    }

    private function fail(string $message): void
    {
        if (PHP_SAPI === 'cli') {
            throw new RuntimeException($message);
        }

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
