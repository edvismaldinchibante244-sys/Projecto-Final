<?php
declare(strict_types=1);

namespace Nivora\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regressao: o cabecalho Host e controlado pelo cliente. Antes, um pedido com
 * "Host: localhost" era tratado como local: confiava em X-Forwarded-For
 * (contornando rate limit e bloqueio de IP) e devolvia o codigo 2FA.
 */
final class PedidoLocalTest extends TestCase
{
    private array $serverOriginal;

    public static function setUpBeforeClass(): void
    {
        require_once APP_BASE . '/src/config/security.php';
    }

    protected function setUp(): void
    {
        $this->serverOriginal = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverOriginal;
    }

    /** @dataProvider casos */
    public function testDeteccaoDePedidoLocal(string $remoteAddr, string $host, bool $esperado): void
    {
        $_SERVER['REMOTE_ADDR'] = $remoteAddr;
        $_SERVER['HTTP_HOST'] = $host;
        $this->assertSame($esperado, security_is_local_request());
    }

    public static function casos(): array
    {
        return [
            'loopback IPv4 + localhost' => ['127.0.0.1', 'localhost', true],
            'loopback IPv6 + localhost:8080' => ['::1', 'localhost:8080', true],
            'ATAQUE: IP externo + Host localhost' => ['203.0.113.7', 'localhost', false],
            'ATAQUE: IP externo + Host 127.0.0.1' => ['203.0.113.7', '127.0.0.1', false],
            'loopback mas Host de producao' => ['127.0.0.1', 'nivora.co.mz', false],
            'IP externo normal' => ['203.0.113.7', 'nivora.co.mz', false],
        ];
    }

    public function testIpFalsoEmXForwardedForEIgnoradoQuandoHostEFalsificado(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
        $this->assertSame('203.0.113.7', security_get_client_ip());
    }
}
