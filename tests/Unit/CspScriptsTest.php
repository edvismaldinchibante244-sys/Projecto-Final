<?php
declare(strict_types=1);

namespace Nivora\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Scripts sem 'unsafe-inline': cada <script> escrito numa pagina tem de levar o nonce. */
final class CspScriptsTest extends TestCase
{
    private static function paginas(): array
    {
        $raiz = dirname(__DIR__, 2);
        $f = array_merge(glob($raiz . '/src/public/*.php') ?: [], glob($raiz . '/src/public/includes/*.php') ?: []);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz . '/app/views', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $x) {
            if (substr((string)$x, -4) === '.php') {
                $f[] = (string)$x;
            }
        }
        return $f;
    }

    public function testTodosOsScriptsEmbutidosTemNonce(): void
    {
        $falta = [];
        foreach (self::paginas() as $f) {
            preg_match_all('/<script\b[^>]*>/i', (string)file_get_contents($f), $m);
            foreach ($m[0] as $tag) {
                if (stripos($tag, 'src=') !== false || stripos($tag, 'application/json') !== false) {
                    continue;
                }
                if (stripos($tag, 'nonce=') === false) {
                    $falta[] = basename($f) . ': ' . $tag;
                }
            }
        }
        $this->assertSame([], $falta, "Scripts embutidos sem nonce (nao correm com a CSP):\n" . implode("\n", $falta));
    }

    public function testPaginasComHeadCarregamNvAcoes(): void
    {
        $falta = [];
        foreach (self::paginas() as $f) {
            $s = (string)file_get_contents($f);
            if (preg_match('/^\s*<head(\s[^>]*)?>\s*$/mi', $s) && strpos($s, 'js/nv_acoes.js') === false) {
                $falta[] = basename($f);
            }
        }
        $this->assertSame([], $falta, 'Paginas sem js/nv_acoes.js (os onclick nao funcionariam): ' . implode(', ', $falta));
    }

    public function testCabecalhoCspSemUnsafeInlineNosScripts(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/app/src/config/security.php');
        $this->assertStringContainsString("'nonce-\" . security_csp_nonce() . \"'", $src);
        $this->assertStringContainsString("script-src-attr 'none'", $src);
    }
}
