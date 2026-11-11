<?php
declare(strict_types=1);

namespace Nivora\Tests\Integration;

use Nivora\Tests\Support\Cenario;
use Nivora\Tests\Support\TestDb;
use PDO;
use PHPUnit\Framework\TestCase;

/** Criacao de pedidos pelo staff (app/src/Service/PedidoService.php). */
final class PedidoServiceTest extends TestCase
{
    private PDO $db;
    private Cenario $c;
    private \PedidoService $servico;

    public static function setUpBeforeClass(): void
    {
        require_once APP_BASE . '/src/Service/PedidoService.php';
    }

    protected function setUp(): void
    {
        $this->db = TestDb::exigir($this);
        $this->c = new Cenario($this->db);
        $this->c->produto('Frango grelhado', 450.00);
        $this->c->produto('Cerveja 2M', 120.00);
        $this->servico = new \PedidoService($this->db);
        unset($_SERVER['HTTP_X_IDEMPOTENCY_KEY'], $_POST['_offline_op_id']);
    }

    private function criar(array $itens, array $ctx = [], ?int $mesaId = null, string $obs = ''): array
    {
        return $this->servico->criar($this->c->contexto($ctx), [
            'mesa_id' => $mesaId ?? $this->c->mesaId,
            'observacao' => $obs,
            'itens' => $itens,
        ]);
    }

    public function testCriaPedidoComPrecosDaBaseEDestinos(): void
    {
        $r = $this->criar([
            ['id' => $this->c->produtos['Frango grelhado'], 'qtd' => 2],
            ['produto_id' => $this->c->produtos['Cerveja 2M'], 'quantidade' => 3],
        ]);

        $this->assertSame(200, $r['http'], json_encode($r['body']));
        $this->assertTrue($r['body']['success']);
        $this->assertSame('0001', $r['body']['numero_pedido']);
        $this->assertSame('1.260,00', $r['body']['total']); // 2x450 + 3x120
        $this->assertSame('cozinha_bar', $r['body']['destino_resumo']);
        $this->assertSame('BALCAO', $r['body']['origem']);
        $this->assertSame('OCUPADA', $this->c->valor('SELECT status FROM mesas WHERE id = ?', [$this->c->mesaId]));
        $this->assertNotNull($r['evento'], 'Deve devolver dados para a notificacao do KDS.');

        $destinos = $this->db->query('SELECT produto_nome, destino FROM itens_pedido WHERE pedido_id = ' . (int)$r['body']['pedido_id'] . ' ORDER BY produto_nome')
            ->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame(['Cerveja 2M' => 'bar', 'Frango grelhado' => 'cozinha'], $destinos);
    }

    public function testPrecoEnviadoPeloClienteEIgnorado(): void
    {
        $r = $this->criar([[
            'id' => $this->c->produtos['Frango grelhado'], 'qtd' => 10,
            '_preco_override' => 0, '_subtotal_override' => 0,
        ]]);

        $this->assertTrue($r['body']['success']);
        $this->assertSame('4.500,00', $r['body']['total']);
        $this->assertEquals(4500, $this->c->valor('SELECT total FROM pedidos WHERE id = ?', [$r['body']['pedido_id']]));
    }

    public function testGarcomFicaComoOrigemEResponsavel(): void
    {
        $r = $this->criar([['id' => $this->c->produtos['Cerveja 2M'], 'qtd' => 1]], ['perfil' => 'GARÇOM', 'usuario_id' => 77]);
        $this->assertSame('GARCOM', $r['body']['origem']);
        $this->assertEquals(77, $this->c->valor('SELECT garcom_id FROM pedidos WHERE id = ?', [$r['body']['pedido_id']]));
    }

    public function testBalcaoContinuaLivre(): void
    {
        $r = $this->criar([['id' => $this->c->produtos['Cerveja 2M'], 'qtd' => 1]], [], $this->c->balcaoId);
        $this->assertTrue($r['body']['success']);
        $this->assertSame('LIVRE', $this->c->valor('SELECT status FROM mesas WHERE id = ?', [$this->c->balcaoId]));
    }

    public function testProdutoDeOutroRestauranteNaoEntra(): void
    {
        $alheio = $this->c->produto('Produto alheio', 10.0, TestDb::restauranteFicticio());
        $r = $this->criar([['id' => $alheio, 'qtd' => 1]]);

        $this->assertFalse($r['body']['success']);
        $this->assertSame(500, $r['http']);
        $this->assertEquals(0, $this->c->valor('SELECT COUNT(*) FROM pedidos WHERE restaurante_id = ?', [$this->c->restauranteId]));
    }

    public function testMesaDeOutroRestauranteERecusada(): void
    {
        $outro = new Cenario($this->db);
        $r = $this->criar([['id' => $this->c->produtos['Cerveja 2M'], 'qtd' => 1]], [], $outro->mesaId);
        $this->assertSame(['success' => false, 'message' => 'Mesa nao encontrada'], $r['body']);
    }

    /** @dataProvider entradasInvalidas */
    public function testEntradasInvalidas(array $dados, string $mensagem): void
    {
        $r = $this->servico->criar($this->c->contexto(), $dados + ['mesa_id' => $this->c->mesaId, 'itens' => []]);
        $this->assertSame($mensagem, $r['body']['message']);
    }

    public static function entradasInvalidas(): array
    {
        return [
            'sem mesa' => [['mesa_id' => 0, 'itens' => [['id' => 1, 'qtd' => 1]]], 'Mesa invalida'],
            'sem itens' => [['itens' => []], 'Itens invalidos'],
        ];
    }

    public function testDuploCliqueNaoCriaDoisPedidos(): void
    {
        $itens = [['id' => $this->c->produtos['Frango grelhado'], 'qtd' => 1]];
        $primeiro = $this->criar($itens, [], null, 'sem picante');
        $segundo = $this->criar($itens, [], null, 'sem picante');

        $this->assertTrue($segundo['body']['duplicado'] ?? false);
        $this->assertSame($primeiro['body']['pedido_id'], $segundo['body']['pedido_id']);
        $this->assertEquals(1, $this->c->valor('SELECT COUNT(*) FROM pedidos WHERE restaurante_id = ?', [$this->c->restauranteId]));
    }

    public function testFilaOfflineReenviadaDevolveAMesmaResposta(): void
    {
        $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] = 'op_' . bin2hex(random_bytes(8));
        $payload = ['mesa_id' => (string)$this->c->mesaId, 'itens' => '[{"id":1}]'];
        $itens = [['id' => $this->c->produtos['Cerveja 2M'], 'qtd' => 2]];

        $primeiro = $this->criar($itens, ['idempotencia_payload' => $payload]);
        sleep(0); // o reenvio pode chegar muito depois; a idempotencia offline nao depende da janela de 8 s
        $segundo = $this->criar($itens, ['idempotencia_payload' => $payload]);
        unset($_SERVER['HTTP_X_IDEMPOTENCY_KEY']);

        $this->assertSame($primeiro['body'], $segundo['body']);
        $this->assertEquals(1, $this->c->valor('SELECT COUNT(*) FROM pedidos WHERE restaurante_id = ?', [$this->c->restauranteId]));
    }
}
