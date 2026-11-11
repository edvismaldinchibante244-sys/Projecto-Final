<?php
declare(strict_types=1);

namespace Nivora\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tempo real (app/src/config/realtime.php + services/realtime/server.js).
 * Regressao: o servidor Node servia os ficheiros do projeto, mandava os pedidos de todos os
 * restaurantes a todos os browsers e aceitava qualquer um em qualquer sala.
 */
final class TempoRealTest extends TestCase
{
    private const SEGREDO = 'segredo_de_teste_com_mais_de_32_caracteres_123';

    public static function setUpBeforeClass(): void
    {
        require_once APP_BASE . '/src/config/realtime.php';
    }

    protected function tearDown(): void
    {
        putenv('REALTIME_URL');
        putenv('REALTIME_SECRET');
    }

    public function testDesligadoSemConfiguracao(): void
    {
        putenv('REALTIME_URL');
        putenv('REALTIME_SECRET');
        $this->assertNull(realtime_config());
        $this->assertSame([], realtime_origens_csp());
        $this->assertNull(realtime_codigo_sala(777003));
        $this->assertFalse(realtime_notificar(777003, ['x' => 1]));
    }

    public function testSegredoCurtoNaoLiga(): void
    {
        putenv('REALTIME_URL=http://localhost:3001');
        putenv('REALTIME_SECRET=curto');
        $this->assertNull(realtime_config());
    }

    public function testCodigoDaSalaEAssinadoPorRestaurante(): void
    {
        putenv('REALTIME_URL=http://localhost:3001');
        putenv('REALTIME_SECRET=' . self::SEGREDO);

        $c = realtime_codigo_sala(777003, 600);
        $this->assertSame('restaurante_777003', $c['room']);
        $this->assertSame(hash_hmac('sha256', 'restaurante_777003|' . $c['exp'], self::SEGREDO), $c['sig']);
        $this->assertGreaterThan(time(), $c['exp']);
        $this->assertNotSame($c['sig'], realtime_codigo_sala(777001, 600)['sig']);
        $this->assertNull(realtime_codigo_sala(0));
    }

    public function testCspSoAbreAOrigemConfigurada(): void
    {
        putenv('REALTIME_URL=http://localhost:3001');
        putenv('REALTIME_SECRET=' . self::SEGREDO);
        $o = realtime_origens_csp();
        $this->assertSame('http://localhost:3001', $o['script']);
        $this->assertSame(['http://localhost:3001', 'ws://localhost:3001'], $o['connect']);
    }

    public function testServidorNodeNaoServeFicheirosEExigeSegredo(): void
    {
        $js = (string)file_get_contents(NIVORA_ROOT . '/services/realtime/server.js');
        $js = (string)preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $js); // so codigo, sem comentarios
        $this->assertStringNotContainsString('express.static', $js, 'o servidor de tempo real nao pode servir ficheiros do projeto');
        $this->assertStringNotContainsString('io.emit(', $js, 'pedidos so podem ir para a sala do proprio restaurante');
        $this->assertStringContainsString('X-Realtime-Secret', $js);
        $this->assertStringContainsString('createHmac', $js);
    }
}
