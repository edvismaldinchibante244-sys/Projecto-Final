<?php
declare(strict_types=1);

namespace Nivora\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Os pares de cor dos tokens de design cumprem WCAG AA (contraste >= 4,5:1 para texto). */
final class TokensDesignTest extends TestCase
{
    private static array $tokens = [];
    private static string $css = '';

    public static function setUpBeforeClass(): void
    {
        self::$css = (string)file_get_contents(dirname(__DIR__, 2) . '/src/public/css/tokens.css');
        preg_match_all('/(--nv-[a-z0-9-]+):\s*(#[0-9a-f]{6})/i', self::$css, $m, PREG_SET_ORDER);
        foreach ($m as $x) {
            self::$tokens[$x[1]] = strtolower($x[2]);
        }
    }

    private static function luminancia(string $hex): float
    {
        $c = array_map(static fn($i) => hexdec(substr($hex, $i, 2)) / 255, [1, 3, 5]);
        $c = array_map(static fn($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    private static function contraste(string $a, string $b): float
    {
        $la = self::luminancia($a);
        $lb = self::luminancia($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    public static function paresAA(): array
    {
        return [
            'tinta/branco' => ['--nv-tinta', '#ffffff'],
            'tinta-2/fundo' => ['--nv-tinta-2', '--nv-fundo'],
            'tinta-suave/branco' => ['--nv-tinta-suave', '#ffffff'],
            'tinta-suave/fundo' => ['--nv-tinta-suave', '--nv-fundo'],
            'placeholder/branco' => ['--nv-placeholder', '#ffffff'],
            'laranja-texto/branco' => ['--nv-laranja-texto', '#ffffff'],
            'sucesso/branco' => ['--nv-sucesso', '#ffffff'],
            'sucesso/suave' => ['--nv-sucesso', '--nv-sucesso-suave'],
            'aviso/suave' => ['--nv-aviso', '--nv-aviso-suave'],
            'perigo/suave' => ['--nv-perigo', '--nv-perigo-suave'],
            'info/suave' => ['--nv-info', '--nv-info-suave'],
        ];
    }

    /** @dataProvider paresAA */
    public function testParTemContrasteAA(string $texto, string $fundo): void
    {
        $cor = static fn(string $t) => $t[0] === '#' ? $t : (self::$tokens[$t] ?? self::fail("Token $t em falta"));
        $r = self::contraste($cor($texto), $cor($fundo));
        $this->assertGreaterThanOrEqual(4.5, round($r, 2), "$texto sobre $fundo: " . round($r, 2) . ':1');
    }

    public function testCoresDosPlanosAceitamTextoBranco(): void
    {
        preg_match_all('/plan-theme-(\w+)\s*\{\s*--nv-plano:\s*(#[0-9a-f]{6})/i', self::$css, $m, PREG_SET_ORDER);
        $this->assertCount(3, $m);
        foreach ($m as $x) {
            $this->assertGreaterThanOrEqual(4.5, round(self::contraste($x[2], '#ffffff'), 2), "Plano {$x[1]}");
        }
    }
}
