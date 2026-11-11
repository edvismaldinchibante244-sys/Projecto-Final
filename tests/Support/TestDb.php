<?php
declare(strict_types=1);

namespace Nivora\Tests\Support;

use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/** Acesso a base de dados de teste, com protecao contra a base real. */
final class TestDb
{
    private static ?PDO $pdo = null;
    private static ?string $motivoIndisponivel = null;

    public static function nomeBase(): string
    {
        return (string)(getenv('DB_NAME') ?: '');
    }

    public static function eBaseDeTeste(string $nome): bool
    {
        return preg_match('/(?:^|_)(test|testing|sandbox|staging)(?:_|$)/i', $nome) === 1;
    }

    /** DSN/credenciais para processos filhos (workers de concorrencia). */
    public static function config(): array
    {
        return [
            'host' => (string)(getenv('DB_HOST') ?: '127.0.0.1'),
            'port' => (string)(getenv('DB_PORT') ?: '3306'),
            'name' => self::nomeBase(),
            'user' => (string)(getenv('DB_USER') ?: 'root'),
            'pass' => (string)(getenv('DB_PASS') ?: ''),
        ];
    }

    public static function novaLigacao(): PDO
    {
        $c = self::config();
        $pdo = new PDO(
            "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4",
            $c['user'],
            $c['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
        );
        $pdo->exec("SET time_zone = '+00:00'");
        // Os testes de numeracao nao dependem de restaurantes/utilizadores reais.
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        return $pdo;
    }

    /** Ligacao partilhada ou marca o teste como ignorado se nao houver base de teste. */
    public static function exigir(TestCase $teste): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        if (self::$motivoIndisponivel === null) {
            $nome = self::nomeBase();
            if (!self::eBaseDeTeste($nome)) {
                self::$motivoIndisponivel = "DB_NAME='{$nome}' nao e uma base de teste (precisa de conter test/testing/sandbox/staging).";
            } else {
                try {
                    self::$pdo = self::novaLigacao();
                    $existe = (int)self::$pdo->query(
                        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'pedidos'"
                    )->fetchColumn();
                    if ($existe !== 1) {
                        self::$pdo = null;
                        self::$motivoIndisponivel = 'Base de teste sem schema: carregue db/schema_base.sql e aplique as migracoes.';
                    }
                } catch (Throwable $e) {
                    self::$motivoIndisponivel = 'Base de teste indisponivel: ' . $e->getMessage();
                }
            }
        }
        if (!self::$pdo instanceof PDO) {
            $teste->markTestSkipped((string)self::$motivoIndisponivel);
        }
        return self::$pdo;
    }

    /** Restaurante ficticio exclusivo deste teste (evita colisoes entre testes). */
    public static function restauranteFicticio(): int
    {
        return random_int(900000, 2000000000);
    }

    /**
     * Corre N processos PHP em paralelo e devolve a saida (stdout) de cada um.
     * @param list<list<string>> $comandos argumentos para php <script> ...
     * @return list<string>
     */
    public static function emParalelo(string $script, array $comandos): array
    {
        $c = self::config();
        $env = array_merge(getenv(), [
            'DB_HOST' => $c['host'], 'DB_PORT' => $c['port'], 'DB_NAME' => $c['name'],
            'DB_USER' => $c['user'], 'DB_PASS' => $c['pass'], 'APP_ENV' => 'local',
        ]);
        $procs = [];
        foreach ($comandos as $args) {
            $cmd = array_merge([PHP_BINARY, $script], $args);
            $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            $procs[] = [$p, $pipes];
        }
        $saidas = [];
        foreach ($procs as [$p, $pipes]) {
            $saidas[] = trim((string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($p);
        }
        return $saidas;
    }
}
