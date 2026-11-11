<?php
declare(strict_types=1);

namespace Nivora\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Fase A (plano de divisao dos ficheiros gigantes): CSS e JavaScript destas
 * paginas vivem em css/ e js/, com cache do browser. Este teste impede que
 * voltem a ser colados dentro do PHP e garante que os ficheiros existem.
 */
final class AssetsExtraidosTest extends TestCase
{
    public static function paginas(): array
    {
        return [
            'sidebar' => ['includes/sidebar.php', 'sidebar'],
            'dashboard' => ['dashboard.php', 'dashboard'],
            'admin' => ['admin.php', 'admin'],
            'relatorios' => ['relatorios.php', 'relatorios'],
            'pedidos' => ['pedidos.php', 'pedidos'],
            'estoque_inteligente' => ['estoque_inteligente.php', 'estoque_inteligente'],
        ];
    }

    /**
     * JavaScript da pagina: js/<nome>.js ou, depois da Fase D, os ficheiros de js/<nome>/.
     * @return list<string> caminhos relativos a src/public
     */
    private static function ficheirosJs(string $nome): array
    {
        $publico = NIVORA_ROOT . '/src/public/';
        if (is_dir($publico . "js/{$nome}")) {
            return array_map(static fn($f) => substr($f, strlen($publico)), glob($publico . "js/{$nome}/*.js") ?: []);
        }
        return ["js/{$nome}.js"];
    }

    /** @dataProvider paginas */
    public function testSemBlocosGrandesEmbutidos(string $pagina, string $nome): void
    {
        $publico = NIVORA_ROOT . '/src/public/';
        $html = (string)file_get_contents($publico . $pagina);
        // O HTML do dashboard vive em app/views/dashboard (Fase C): verificar tambem as vistas.
        $vistas = array_merge(
            glob(NIVORA_ROOT . "/app/views/{$nome}/*.php") ?: [],
            glob(NIVORA_ROOT . "/app/views/{$nome}/partes/*.php") ?: []
        );
        foreach ($vistas as $vista) {
            $html .= "\n" . (string)file_get_contents($vista);
        }

        foreach (['/<style[^>]*>(.*?)<\/style>/s' => 'CSS', '/<script(?![^>]*\bsrc=)(?![^>]*application\/json)[^>]*>(.*?)<\/script>/s' => 'JavaScript'] as $padrao => $tipo) {
            preg_match_all($padrao, $html, $m);
            foreach ($m[1] as $bloco) {
                $this->assertLessThan(3000, strlen($bloco), "$pagina voltou a ter um bloco de $tipo embutido grande; use css/$nome.css ou js/$nome.js.");
            }
        }

        $this->assertStringContainsString("css/{$nome}.css?v=", $html);
        $this->assertFileExists($publico . "css/{$nome}.css");
        $js = self::ficheirosJs($nome);
        $this->assertNotEmpty($js);
        foreach ($js as $ficheiro) {
            $this->assertFileExists($publico . $ficheiro);
            $this->assertStringContainsString($ficheiro . '?v=', $html, "$pagina nao carrega $ficheiro");
        }
    }

    /** @dataProvider paginas */
    public function testJavaScriptSemErrosDeSintaxe(string $pagina, string $nome): void
    {
        // Regressao: faltava fechar um try em refreshTeamOnline e o browser
        // recusava os 47 KB de JavaScript do dashboard ("Unexpected end of input").
        $node = trim((string)shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('Node.js nao instalado: verificacao de sintaxe JS ignorada (corre no CI).');
        }
        foreach (self::ficheirosJs($nome) as $rel) {
            $saida = [];
            exec('node --check ' . escapeshellarg(NIVORA_ROOT . '/src/public/' . $rel) . ' 2>&1', $saida, $codigo);
            $this->assertSame(0, $codigo, "$rel tem um erro de sintaxe:\n" . implode("\n", array_slice($saida, -4)));
        }
    }

    /** Fase D: JavaScript dividido por area, nenhum ficheiro acima de 600 linhas. */
    public function testFicheirosJsDivididosSaoPequenos(): void
    {
        $ficheiros = glob(NIVORA_ROOT . '/src/public/js/*/*.js') ?: [];
        $this->assertNotEmpty($ficheiros);
        foreach ($ficheiros as $f) {
            $this->assertLessThanOrEqual(600, count(file($f)), basename(dirname($f)) . '/' . basename($f) . ' passou de 600 linhas; divida por area.');
        }
    }
}
