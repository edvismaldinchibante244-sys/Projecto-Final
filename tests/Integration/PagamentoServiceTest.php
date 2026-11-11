<?php
declare(strict_types=1);

namespace Nivora\Tests\Integration;

use Nivora\Tests\Support\Cenario;
use Nivora\Tests\Support\TestDb;
use PDO;
use PHPUnit\Framework\TestCase;

/** Pagamento de pedidos (app/src/Service/PagamentoService.php). */
final class PagamentoServiceTest extends TestCase
{
    private const WORKER = __DIR__ . '/../Support/worker.php';

    private PDO $db;
    private Cenario $c;
    private \PagamentoService $servico;

    public static function setUpBeforeClass(): void
    {
        require_once APP_BASE . '/src/Service/PagamentoService.php';
    }

    protected function setUp(): void
    {
        $this->db = TestDb::exigir($this);
        $this->c = new Cenario($this->db);
        $this->c->produto('Prato do dia', 300.00);
        $this->c->produto('Sumo natural', 75.00);
        $this->servico = new \PagamentoService($this->db);
        unset($_SERVER['HTTP_X_IDEMPOTENCY_KEY']);
    }

    private function pedidoPadrao(string $statusItem = 'pronto', string $statusPedido = 'PRONTO'): int
    {
        // 2 x 300 + 2 x 75 = 750 MZN
        return $this->c->pedido([
            [$this->c->produtos['Prato do dia'], 2, 300.00, $statusItem],
            [$this->c->produtos['Sumo natural'], 2, 75.00, $statusItem],
        ], $statusPedido);
    }

    private function pagar(int $pedidoId, array $dados = [], array $ctx = []): array
    {
        return $this->servico->pagarPedido($this->c->contexto($ctx), ['pedido_id' => $pedidoId] + $dados);
    }

    public function testPagamentoEmDinheiroComTroco(): void
    {
        $this->c->abrirCaixa();
        $pedido = $this->pedidoPadrao();

        $r = $this->pagar($pedido, ['forma_pagamento' => 'DINHEIRO', 'valor_recebido' => '1000']);

        $this->assertTrue($r['body']['success'], json_encode($r['body']));
        $this->assertSame(250.0, $r['body']['troco']);
        $this->assertMatchesRegularExpression('/^FT\d{10}$/', $r['body']['numero_fatura']);

        $venda = $this->db->query('SELECT * FROM vendas WHERE id = ' . (int)$r['body']['venda_id'])->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals(750, $venda['total_final']);
        $this->assertSame('PAGO', $venda['status']);
        $this->assertEquals(2, $this->c->valor('SELECT COUNT(*) FROM itens_venda WHERE venda_id = ?', [$venda['id']]));
        $this->assertSame('PAGO', $this->c->valor('SELECT status FROM pedidos WHERE id = ?', [$pedido]));
        $this->assertSame('LIVRE', $this->c->valor('SELECT status FROM mesas WHERE id = ?', [$this->c->mesaId]));
        $this->assertEquals(98, $this->c->valor('SELECT estoque FROM produtos WHERE id = ?', [$this->c->produtos['Prato do dia']]));
    }

    public function testDescontoPercentual(): void
    {
        $this->c->abrirCaixa();
        $r = $this->pagar($this->pedidoPadrao(), ['forma_pagamento' => 'CARTAO', 'desconto_percent' => 10]);

        $venda = $this->db->query('SELECT total, desconto, total_final FROM vendas WHERE id = ' . (int)$r['body']['venda_id'])->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals([750, 75, 675], [(float)$venda['total'], (float)$venda['desconto'], (float)$venda['total_final']]);
    }

    public function testDinheiroInsuficienteERecusado(): void
    {
        $this->c->abrirCaixa();
        $pedido = $this->pedidoPadrao();

        $r = $this->pagar($pedido, ['forma_pagamento' => 'DINHEIRO', 'valor_recebido' => '500']);

        $this->assertFalse($r['body']['success']);
        $this->assertStringContainsString('Valor recebido inferior ao total', $r['body']['message']);
        $this->assertSame('PRONTO', $this->c->valor('SELECT status FROM pedidos WHERE id = ?', [$pedido]));
        $this->assertEquals(0, $this->c->valor('SELECT COUNT(*) FROM vendas WHERE restaurante_id = ?', [$this->c->restauranteId]));
    }

    public function testDinheiroSemValorInformadoContinuaAceite(): void
    {
        $this->c->abrirCaixa();
        $r = $this->pagar($this->pedidoPadrao(), ['forma_pagamento' => 'DINHEIRO', 'valor_recebido' => '0']);
        $this->assertTrue($r['body']['success'], json_encode($r['body']));
        $this->assertNull($r['body']['troco']);
    }

    public function testMpesaRecebeReferenciaAutomatica(): void
    {
        $this->c->abrirCaixa();
        $pedido = $this->pedidoPadrao();
        $this->pagar($pedido, ['forma_pagamento' => 'MPESA']);
        $this->assertStringContainsString('Ref. MPESA: MPESA-', (string)$this->c->valor('SELECT observacao FROM pedidos WHERE id = ?', [$pedido]));
    }

    public function testSemCaixaAbertoNaoRecebe(): void
    {
        $r = $this->pagar($this->pedidoPadrao(), ['forma_pagamento' => 'CARTAO']);
        $this->assertSame('Nao ha caixa aberto. Abra o caixa para receber pagamentos.', $r['body']['message']);
    }

    public function testPedidoDeOutroRestauranteDa404(): void
    {
        $outro = new Cenario($this->db);
        $outro->produto('X', 10);
        $pedidoAlheio = $outro->pedido([[$outro->produtos['X'], 1, 10.0]]);
        $this->c->abrirCaixa();

        $r = $this->pagar($pedidoAlheio, ['forma_pagamento' => 'CARTAO']);
        $this->assertSame(404, $r['http']);
        $this->assertSame('PRONTO', $this->c->valor('SELECT status FROM pedidos WHERE id = ?', [$pedidoAlheio]));
    }

    public function testItensEmPreparoDependemDoPlano(): void
    {
        $this->c->abrirCaixa();
        $pedido = $this->pedidoPadrao('em_preparo', 'PREPARANDO');

        $comKds = $this->pagar($pedido, ['forma_pagamento' => 'CARTAO'], ['telas_bar_cozinha' => true, 'contas_abertas' => false]);
        $this->assertFalse($comKds['body']['success']);
        $this->assertStringContainsString('Somente pedidos PRONTO', $comKds['body']['message']);

        $basico = $this->pagar($pedido, ['forma_pagamento' => 'CARTAO'], ['telas_bar_cozinha' => false]);
        $this->assertTrue($basico['body']['success'], 'Plano sem KDS paga pedidos abertos no PDV.');
    }

    public function testPedidoCanceladoNaoEPago(): void
    {
        $this->c->abrirCaixa();
        $r = $this->pagar($this->pedidoPadrao('pronto', 'CANCELADO'), ['forma_pagamento' => 'CARTAO']);
        $this->assertSame('Pedido cancelado nao pode ser pago', $r['body']['message']);
    }

    /**
     * Regressao: o estado do pedido era lido antes da transaccao e sem
     * bloqueio - dois caixas a cobrar o mesmo pedido criavam duas vendas.
     */
    public function testDoisCaixasAoMesmoTempoSoGeramUmaVenda(): void
    {
        $this->c->abrirCaixa();
        $pedido = $this->pedidoPadrao();

        $saidas = TestDb::emParalelo(self::WORKER, array_fill(0, 10, ['pagar', (string)$this->c->restauranteId, 'commit', (string)$pedido]));

        $this->assertEquals(1, $this->c->valor('SELECT COUNT(*) FROM vendas WHERE restaurante_id = ?', [$this->c->restauranteId]),
            'Mais de uma venda para o mesmo pedido. Saidas: ' . implode(' | ', $saidas));
        $contagem = array_count_values($saidas);
        $this->assertSame(1, $contagem['OK:pago'] ?? 0, json_encode($contagem));
        $this->assertSame(9, $contagem['OK:ja_pago'] ?? 0, json_encode($contagem));
    }

    /** @dataProvider calculos */
    public function testCalculoDeTotais(array $itens, float $desconto, array $esperado): void
    {
        $this->assertSame($esperado, \PagamentoService::calcularTotais($itens, $desconto));
    }

    public static function calculos(): array
    {
        return [
            'sem desconto' => [[['subtotal' => 100.5], ['quantidade' => 2, 'preco_unitario' => 10]], 0.0,
                ['total_bruto' => 120.5, 'desconto' => 0.0, 'total_final' => 120.5]],
            'desconto 15%' => [[['subtotal' => 200]], 15.0, ['total_bruto' => 200.0, 'desconto' => 30.0, 'total_final' => 170.0]],
            '100% nunca negativo' => [[['subtotal' => 80]], 100.0, ['total_bruto' => 80.0, 'desconto' => 80.0, 'total_final' => 0.0]],
        ];
    }
}
