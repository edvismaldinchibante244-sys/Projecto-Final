<?php
declare(strict_types=1);

namespace Nivora\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regressao: sem APP_ENV valido a aplicacao decidia "producao ou nao" pelo
 * cabecalho Host. Agora APP_ENV e obrigatorio. Cada caso corre num processo
 * PHP separado para isolar as variaveis de ambiente.
 */
final class AppEnvTest extends TestCase
{
    private function ligar(string $appEnv): string
    {
        $codigo = 'require ' . var_export(APP_BASE . '/src/config/database.php', true) . ';'
            . 'try { (new Database())->getConnection(); echo "LIGOU"; }'
            . ' catch (Throwable $e) { echo "RECUSOU:" . $e->getMessage(); }';
        $env = array_merge(getenv(), ['APP_ENV' => $appEnv, 'DB_NAME' => 'base_inexistente_test']);
        $p = proc_open([PHP_BINARY, '-r', $codigo], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $saida = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);
        return $saida;
    }

    /** @dataProvider valoresInvalidos */
    public function testAppEnvInvalidoImpedeArranque(string $valor): void
    {
        $this->assertSame('RECUSOU:Servico temporariamente indisponivel.', $this->ligar($valor));
    }

    public static function valoresInvalidos(): array
    {
        return ['qualquer' => ['xpto'], 'teste' => ['testing'], 'espaco' => [' ']];
    }

    public function testAppEnvLocalTentaLigar(): void
    {
        // Em local a mensagem de erro e detalhada (base inexistente), o que
        // prova que passou a validacao do APP_ENV.
        $this->assertStringNotContainsString('Servico temporariamente indisponivel', $this->ligar('local'));
    }
}
