<?php
declare(strict_types=1);

namespace Nivora\Tests\Legacy;

use Nivora\Tests\Support\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Corre os scripts antigos tools/test_*.php (convencao: exit 0 = passou).
 * Alguns escrevem na base, por isso so correm contra a base de TESTE.
 *
 * Excluidos (precisam de preparacao propria):
 *  - *_migration_integration.php: usam sandboxes criados por tools/setup_*_sandbox.php
 *  - ver PRECISAM_DE_DADOS: dependem de restaurantes/produtos/planos reais
 */
final class ScriptsLegadosTest extends TestCase
{
    private const PRECISAM_DE_DADOS = [
        'test_promocao_atribuicao.php',
        'test_promocao_fluxo.php',
        'test_promocao_inteligencia.php',
        'test_personal_data_encryption.php',
    ];

    public static function scripts(): array
    {
        $casos = [];
        foreach (glob(dirname(__DIR__, 2) . '/tools/test_*.php') ?: [] as $f) {
            $nome = basename($f);
            if (str_ends_with($nome, '_integration.php') || in_array($nome, self::PRECISAM_DE_DADOS, true)) {
                continue;
            }
            $casos[$nome] = [$f];
        }
        return $casos;
    }

    /** @dataProvider scripts */
    public function testScriptPassa(string $script): void
    {
        TestDb::exigir($this); // garante base de teste antes de correr scripts que escrevem

        $p = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname($script), getenv());
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $codigo = proc_close($p);

        $this->assertSame(0, $codigo, basename($script) . " falhou:\n" . trim($out . "\n" . $err));
    }
}
