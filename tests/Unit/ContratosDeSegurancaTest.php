<?php
declare(strict_types=1);

namespace Nivora\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contratos de codigo para as correcoes de 30/10/2026. Falham se alguem
 * reintroduzir o padrao inseguro (ex.: copiar codigo antigo de um backup).
 */
final class ContratosDeSegurancaTest extends TestCase
{
    private static function ler(string $relativo): string
    {
        $c = file_get_contents(NIVORA_ROOT . '/' . $relativo);
        self::assertIsString($c, "Ficheiro em falta: $relativo");
        return $c;
    }

    public function testPedidoNovoNaoAceitaRestauranteDoBody(): void
    {
        $c = self::ler('src/public/api/pedido_novo.php');
        $this->assertDoesNotMatchRegularExpression('/\$restaurante_id\s*=\s*\(int\)\s*\$body\[/', $c,
            'pedido_novo.php voltou a ler o restaurante do body (pedido publico sem token QR).');
        $this->assertStringContainsString("'code' => 'QR_TOKEN_REQUIRED'", $c);
    }

    /** @dataProvider criadoresDeNumeros */
    public function testNumerosSaoGeradosPeloContadorAtomico(string $ficheiro, string $funcao): void
    {
        $c = self::ler($ficheiro);
        $this->assertStringContainsString($funcao, $c);
        $this->assertStringNotContainsString("LPAD(COALESCE(MAX(CAST(numero_pedido", $c,
            "$ficheiro voltou a usar MAX()+1 para numerar pedidos.");
    }

    public static function criadoresDeNumeros(): array
    {
        return [
            'pedido staff' => ['app/src/Service/PedidoService.php', 'pedido_proximo_numero('],
            'pedido QR' => ['app/src/api/pre_pedido_reserva.php', 'pedido_proximo_numero('],
            'pedido empresarial' => ['src/public/api/operacoes_empresariais.php', 'pedido_proximo_numero('],
        ];
    }

    public function testPagamentoBloqueiaOPedidoNaTransacao(): void
    {
        $c = self::ler('app/src/Service/PagamentoService.php');
        $this->assertMatchesRegularExpression('/FROM pedidos WHERE id = :id AND restaurante_id = :rid\s+LIMIT 1 FOR UPDATE/', $c,
            'O pagamento deixou de bloquear o pedido: dois caixas podiam cobrar o mesmo pedido.');
        $this->assertStringContainsString('PagamentoService', self::ler('src/public/api/pedido_pagar.php'));
        $this->assertStringContainsString('PedidoService', self::ler('src/public/api/pedido_novo.php'));
    }

    public function testFaturaNaoUsaContagem(): void
    {
        $c = self::ler('app/src/Model/Venda.php');
        $this->assertStringNotContainsString("\$row['total'] + 1", $c, 'gerarNumeroFatura voltou a usar COUNT(*)+1.');
        $this->assertStringContainsString('UPDATE fatura_sequencias', $c);
    }

    public function testCodigo2faSoEDevolvidoEmDesenvolvimentoReal(): void
    {
        $c = self::ler('src/public/api/login_process.php');
        $this->assertStringContainsString(
            "'debug_code' => (!security_is_production() && security_is_local_request()) ? \$code : null",
            $c
        );
        $this->assertStringNotContainsString("'debug_code' => login_api_is_local_request()", $c);
    }

    public function testScriptComSenhaFixaFoiDesativado(): void
    {
        $f = NIVORA_ROOT . '/tools/reset_login_temp_password.php';
        if (!is_file($f)) {
            $this->assertFalse(is_file($f)); // apagado: melhor ainda
            return;
        }
        $this->assertStringNotContainsString('@gmail.com', (string)file_get_contents($f));
    }
}
