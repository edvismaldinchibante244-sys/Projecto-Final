<?php
declare(strict_types=1);

namespace Nivora\Tests\Integration;

use Nivora\Tests\Support\Cenario;
use Nivora\Tests\Support\TestDb;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Numeros dos relatorios (app/src/Service/RelatorioService.php).
 * Cada teste usa um restaurante ficticio proprio, por isso os totais sao exatos.
 */
final class RelatorioServiceTest extends TestCase
{
    private PDO $db;
    private int $rid;
    private \RelatorioService $servico;

    public static function setUpBeforeClass(): void
    {
        require_once APP_BASE . '/src/Service/RelatorioService.php';
    }

    protected function setUp(): void
    {
        $this->db = TestDb::exigir($this);
        $this->rid = (new Cenario($this->db))->restauranteId;
        $this->servico = new \RelatorioService($this->db);
    }

    private function venda(float $total, string $forma, string $quando, ?int $rid = null): void
    {
        $this->db->prepare(
            "INSERT INTO vendas (restaurante_id, usuario_id, caixa_id, numero_fatura, total, desconto, total_final, forma_pagamento, status, criado_em)
             VALUES (?, 1, 1, ?, ?, 0, ?, ?, 'PAGO', ?)"
        )->execute([$rid ?? $this->rid, 'R-' . random_int(1, PHP_INT_MAX), $total, $total, $forma, $quando]);
    }

    private function dados(string $tipo, string $inicio = '2026-10-10', string $fim = '2026-10-20'): array
    {
        return $this->servico->carregarDados([
            'tipo' => $tipo,
            'restaurante_id' => $this->rid,
            'data_inicio' => $inicio,
            'data_fim' => $fim,
        ]);
    }

    public function test_vendas_do_periodo_com_total_e_ticket_medio(): void
    {
        $this->venda(600.00, 'DINHEIRO', '2026-10-10 09:00:00');           // primeiro dia (inclusivo)
        $this->venda(300.00, 'MPESA', '2026-10-15 13:30:00');
        $this->venda(150.00, 'MPESA', '2026-10-20 23:59:00');              // ultimo dia (inclusivo)
        $this->venda(999.00, 'DINHEIRO', '2026-10-09 23:59:00');           // antes do periodo
        $this->venda(999.00, 'DINHEIRO', '2026-10-21 00:00:00');           // depois do periodo
        $this->venda(999.00, 'DINHEIRO', '2026-10-15 12:00:00', $this->rid + 1); // outro restaurante

        $d = $this->dados('vendas');

        $this->assertArrayNotHasKey('erro_dados', $d);
        $this->assertSame(3, $d['total_vendas']);
        $this->assertEqualsWithDelta(1050.00, $d['valor_total'], 0.001);
        $this->assertEqualsWithDelta(350.00, $d['ticket_medio'], 0.001);
    }

    public function test_financeiro_agrupa_por_forma_de_pagamento(): void
    {
        $this->venda(600.00, 'DINHEIRO', '2026-10-11 10:00:00');
        $this->venda(300.00, 'mpesa', '2026-10-12 10:00:00');               // normalizado para maiusculas
        $this->venda(150.00, 'MPESA', '2026-10-13 10:00:00');

        $d = $this->dados('financeiro');

        $this->assertSame(['qtd' => 1, 'total' => 600.0], $d['por_pagamento']['DINHEIRO']);
        $this->assertSame(['qtd' => 2, 'total' => 450.0], $d['por_pagamento']['MPESA']);
        $this->assertEqualsWithDelta(1050.00, $d['grand_total'], 0.001);
    }

    public function test_periodo_sem_vendas_devolve_zeros(): void
    {
        $d = $this->dados('vendas');

        $this->assertSame(0, $d['total_vendas']);
        $this->assertEquals(0, $d['valor_total']);
        $this->assertEquals(0, $d['ticket_medio']);
    }

    public function test_devolve_apenas_as_variaveis_declaradas(): void
    {
        $d = $this->dados('financeiro');
        $this->assertSame([], array_diff(array_keys($d), \RelatorioService::SAIDAS_CARREGAR_DADOS));
    }
    /** Pedido com um item, criado na data indicada (expressao SQL). */
    private function pedidoProduto(int $produtoId, int $qtd, float $preco, string $quando, string $status = 'ENTREGUE'): void
    {
        $this->db->prepare("INSERT INTO pedidos (restaurante_id, numero_pedido, status, total, criado_em, atualizado_em) VALUES (?, ?, ?, ?, {$quando}, {$quando})")
            ->execute([$this->rid, 'T' . random_int(1, 99999999), $status, $qtd * $preco]);
        $pedidoId = (int)$this->db->lastInsertId();
        $this->db->prepare("INSERT INTO itens_pedido (pedido_id, produto_id, quantidade, preco_unitario, subtotal, status) VALUES (?, ?, ?, ?, ?, 'entregue')")
            ->execute([$pedidoId, $produtoId, $qtd, $preco, $qtd * $preco]);
    }

    public function test_relatorio_de_produtos_conta_so_o_periodo_e_ignora_cancelados(): void
    {
        $c = new Cenario($this->db);
        $frango = $c->produto('Frango', 400.00, $this->rid);
        $sumo = $c->produto('Sumo', 100.00, $this->rid);

        $this->pedidoProduto($frango, 2, 400.00, "'2026-10-12 12:00:00'");
        $this->pedidoProduto($frango, 1, 400.00, "'2026-10-20 20:00:00'");
        $this->pedidoProduto($frango, 7, 400.00, "'2026-10-15 12:00:00'", 'CANCELADO'); // cancelado
        $this->pedidoProduto($frango, 9, 400.00, "'2026-09-01 12:00:00'");             // fora do periodo
        $this->pedidoProduto($sumo, 5, 100.00, "'2026-09-01 12:00:00'");               // so fora do periodo

        $d = $this->dados('produtos');

        $this->assertCount(1, $d['produtos'], 'o Sumo nao vendeu nada no periodo');
        $this->assertSame('Frango', $d['produtos'][0]['nome']);
        $this->assertEquals(3, $d['produtos'][0]['total_vendido']);
        $this->assertEquals(1200.00, $d['produtos'][0]['receita']);

        $vazio = $this->dados('produtos', '2020-01-01', '2020-01-31');
        $this->assertSame([], $vazio['produtos']);
    }

    public function test_inteligencia_empresarial_detecta_produto_em_queda(): void
    {
        $c = new Cenario($this->db);
        $prato = $c->produto('Prato em queda', 300.00, $this->rid);
        $this->pedidoProduto($prato, 8, 300.00, 'DATE_SUB(NOW(), INTERVAL 20 DAY)'); // 14-28 dias atras
        $this->pedidoProduto($prato, 2, 300.00, 'DATE_SUB(NOW(), INTERVAL 2 DAY)');  // ultimos 14 dias

        $inicio = date('Y-m-d', strtotime('-30 days'));
        $fim = date('Y-m-d');
        $dados = $this->dados('produtos', $inicio, $fim);
        $intel = $this->servico->inteligenciaEmpresarial($dados + [
            'tipo' => 'produtos',
            'restaurante_id' => $this->rid,
            'data_inicio' => $inicio,
            'data_fim' => $fim,
            'tem_analise_abc_vendas' => false,
            'tem_recomendacoes_automaticas' => true,
            'tem_previsao_demanda' => false,
            'relatorios_formatar_duracao' => static fn($s): string => (string)$s,
        ]);

        $queda = $intel['relEmpIntel']['ia_produtos']['queda'] ?? [];
        $this->assertNotEmpty($queda, 'a analise de tendencia devia encontrar o produto em queda');
        $this->assertSame('Prato em queda', $queda[0]['nome']);
        $this->assertSame(2.0, $queda[0]['qtd_rec']);
        $this->assertSame(8.0, $queda[0]['qtd_ant']);
        $this->assertSame(-75.0, $queda[0]['delta_pct']);
    }
}
