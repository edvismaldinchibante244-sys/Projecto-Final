<?php
declare(strict_types=1);

namespace Nivora\Tests\Integration;

use Nivora\Tests\Support\Cenario;
use Nivora\Tests\Support\TestDb;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Numeros do dashboard (app/src/Service/DashboardService.php).
 * Cada teste usa um restaurante ficticio proprio, por isso os totais sao exatos.
 */
final class DashboardServiceTest extends TestCase
{
    private PDO $db;
    private Cenario $c;
    private \DashboardService $servico;
    private int $rid;

    public static function setUpBeforeClass(): void
    {
        require_once APP_BASE . '/src/Service/DashboardService.php';
    }

    protected function setUp(): void
    {
        $this->db = TestDb::exigir($this);
        $this->c = new Cenario($this->db);
        $this->rid = $this->c->restauranteId;
        $this->servico = new \DashboardService($this->db);
    }

    private function venda(float $total, string $status = 'PAGO', string $quando = 'NOW()', ?int $rid = null): void
    {
        $this->db->prepare(
            "INSERT INTO vendas (restaurante_id, usuario_id, caixa_id, numero_fatura, total_final, status, criado_em)
             VALUES (?, 1, 1, ?, ?, ?, {$quando})"
        )->execute([$rid ?? $this->rid, 'T-' . random_int(1, PHP_INT_MAX), $total, $status]);
    }

    private function pedidoComStatus(string $status): void
    {
        $this->c->pedido([], $status);
    }

    private function entradaAdmin(): array
    {
        return [
            'rid' => $this->rid,
            'plano_atual' => 'BASICO',
            'qtd_hoje' => 0,
            'total_hoje' => 0.0,
            'total_produtos' => 0,
        ];
    }

    public function test_receita_do_mes_soma_so_vendas_pagas_deste_restaurante_neste_mes(): void
    {
        $this->venda(1500.00);
        $this->venda(250.50);
        $this->venda(999.00, 'CANCELADO');                                   // nao paga
        $this->venda(700.00, 'PAGO', 'DATE_SUB(NOW(), INTERVAL 1 MONTH)');  // mes anterior
        $this->venda(5000.00, 'PAGO', 'NOW()', $this->rid + 1);             // outro restaurante

        $dados = $this->servico->dadosAdmin($this->entradaAdmin());

        $this->assertSame(1750.50, $dados['receita_mes_atual']);
        $this->assertSame(700.00, $dados['receita_mes_anterior']);
    }

    public function test_resumo_de_pedidos_de_hoje_conta_por_estado(): void
    {
        foreach (['NOVO', 'PENDENTE', 'PREPARANDO', 'PRONTO', 'PRONTO', 'ENTREGUE'] as $status) {
            $this->pedidoComStatus($status);
        }

        foreach (['dadosAdmin' => $this->entradaAdmin(), 'dadosGerente' => ['rid' => $this->rid]] as $metodo => $entrada) {
            $resumo = $this->servico->{$metodo}($entrada)['pedidos_resumo'];
            // "pendentes" inclui os que estao em preparacao (regra do dashboard)
            $this->assertSame(
                ['pendentes' => 3, 'preparando' => 1, 'prontos' => 2, 'entregues' => 1],
                $resumo,
                $metodo
            );
        }
    }

    public function test_restaurante_sem_movimento_devolve_zeros_e_nao_falha(): void
    {
        $dados = $this->servico->dadosAdmin($this->entradaAdmin());

        $this->assertSame(0.0, $dados['receita_mes_atual']);
        $this->assertSame(0.0, $dados['receita_mes_anterior']);
        $this->assertSame(0, $dados['pedidos_hoje_total']);
        $this->assertSame(['pendentes' => 0, 'preparando' => 0, 'prontos' => 0, 'entregues' => 0], $dados['pedidos_resumo']);
    }

    public function test_cada_metodo_devolve_apenas_as_variaveis_declaradas(): void
    {
        $dados = $this->servico->dadosAdmin($this->entradaAdmin());
        $this->assertSame([], array_diff(array_keys($dados), \DashboardService::SAIDAS_DADOS_ADMIN));
        foreach (['receita_mes_atual', 'pedidos_resumo', 'by_canal', 'team_performance'] as $chave) {
            $this->assertArrayHasKey($chave, $dados);
        }
    }
}
