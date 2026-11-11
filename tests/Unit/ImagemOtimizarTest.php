<?php
declare(strict_types=1);

namespace Nivora\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Reducao automatica de imagens enviadas (app/src/config/imagem_otimizar.php). */
final class ImagemOtimizarTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/app/src/config/imagem_otimizar.php';
    }

    protected function setUp(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('Extensao GD indisponivel.');
        }
    }

    private function foto(string $tipo, int $l, int $a): string
    {
        $im = imagecreatetruecolor($l, $a);
        for ($i = 0; $i < 4000; $i++) { // ruido: simula uma foto real (comprime mal)
            imagesetpixel($im, random_int(0, $l - 1), random_int(0, $a - 1), random_int(0, 0xFFFFFF));
        }
        $f = tempnam(sys_get_temp_dir(), 'img') . '.' . $tipo;
        $tipo === 'png' ? imagepng($im, $f, 0) : imagejpeg($im, $f, 100);
        imagedestroy($im);
        return $f;
    }

    public function testFotoGrandeEncolheEFicaMaisLeve(): void
    {
        $f = $this->foto('jpg', 3000, 2000);
        $antes = filesize($f);
        imagem_otimizar($f, 1600);
        clearstatcache();
        [$l, $a] = getimagesize($f);
        $this->assertSame([1600, 1067], [$l, $a]);
        $this->assertLessThan($antes, filesize($f));
        @unlink($f);
    }

    public function testPngMantemFormato(): void
    {
        $f = $this->foto('png', 1200, 1200);
        imagem_otimizar($f, 512);
        $this->assertSame(IMAGETYPE_PNG, getimagesize($f)[2]);
        $this->assertSame(512, getimagesize($f)[0]);
        @unlink($f);
    }

    public function testPdfFicaIgual(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($f, "%PDF-1.4\n%teste\n");
        $antes = md5_file($f);
        $this->assertTrue(imagem_otimizar($f, 512));
        $this->assertSame($antes, md5_file($f));
        @unlink($f);
    }
}
