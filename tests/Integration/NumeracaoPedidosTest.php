<?php
declare(strict_types=1);

namespace Nivora\Tests\Integration;

use Nivora\Tests\Support\TestDb;
use PHPUnit\Framework\TestCase;

/** Regressao: numeros de pedido duplicados (MAX()+1 sem bloqueio). */
final class NumeracaoPedidosTest extends TestCase
{
    private const WORKER = __DIR__ . '/../Support/worker.php';

    public static function setUpBeforeClass(): void
    {
        require_once APP_BASE . '/src/config/pedido_schema.php';
    }

    public function testContinuaAPartirDoMaiorNumeroDeHoje(): void
    {
        $db = TestDb::exigir($this);
        $rid = TestDb::restauranteFicticio();
        $db->prepare("INSERT INTO pedidos (restaurante_id, numero_pedido, criado_em) VALUES
            (?, '0003', UTC_TIMESTAMP()), (?, '0041', UTC_TIMESTAMP()), (?, '0999', UTC_TIMESTAMP() - INTERVAL 2 DAY)")
            ->execute([$rid, $rid, $rid]);

        pedido_sequencia_preparar($db, $rid);
        $db->beginTransaction();
        $numero = pedido_proximo_numero($db, $rid);
        $db->rollBack();

        $this->assertSame('0042', $numero, 'Deve ignorar dias anteriores e continuar do maior de hoje.');
    }

    public function testRollbackDevolveONumero(): void
    {
        $db = TestDb::exigir($this);
        $rid = TestDb::restauranteFicticio();
        pedido_sequencia_preparar($db, $rid);

        $db->beginTransaction();
        $primeiro = pedido_proximo_numero($db, $rid);
        $db->rollBack();

        $db->beginTransaction();
        $segundo = pedido_proximo_numero($db, $rid);
        $db->rollBack();

        $this->assertSame($primeiro, $segundo);
    }

    public function testPedidosSimultaneosNuncaRepetemNumero(): void
    {
        $db = TestDb::exigir($this);
        $rid = TestDb::restauranteFicticio();
        $cmds = [];
        for ($i = 0; $i < 30; $i++) {
            $cmds[] = ['pedido', (string)$rid, $i % 6 === 0 ? 'rollback' : 'commit'];
        }
        $saidas = TestDb::emParalelo(self::WORKER, $cmds);

        $erros = array_values(array_filter($saidas, static fn($s) => !preg_match('/^(OK|RB):\d+$/', $s)));
        $this->assertSame([], $erros, 'Nenhum processo deve falhar.');

        $stmt = $db->prepare('SELECT COUNT(*), COUNT(DISTINCT numero_pedido) FROM pedidos WHERE restaurante_id = ?');
        $stmt->execute([$rid]);
        [$total, $distintos] = $stmt->fetch(\PDO::FETCH_NUM);
        $this->assertSame(25, (int)$total);
        $this->assertSame((int)$total, (int)$distintos, 'Encontrados numeros de pedido duplicados.');
    }
}
