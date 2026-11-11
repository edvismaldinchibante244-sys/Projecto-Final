<?php
declare(strict_types=1);

namespace Nivora\Tests\Integration;

use Nivora\Tests\Support\TestDb;
use PDOException;
use PHPUnit\Framework\TestCase;

/** Regressao: faturas duplicadas (COUNT(*)+1). Documentos fiscais. */
final class NumeracaoFaturasTest extends TestCase
{
    private const WORKER = __DIR__ . '/../Support/worker.php';

    public static function setUpBeforeClass(): void
    {
        require_once APP_BASE . '/src/Model/Venda.php';
    }

    public function testContinuaAPartirDaMaiorFaturaDoAno(): void
    {
        $db = TestDb::exigir($this);
        $rid = TestDb::restauranteFicticio();
        $ano = date('Y');
        $anterior = (string)((int)$ano - 1);
        $db->prepare('INSERT INTO vendas (restaurante_id, usuario_id, caixa_id, numero_fatura) VALUES (?,1,1,?), (?,1,1,?), (?,1,1,?)')
            ->execute([$rid, "FT{$ano}000149", $rid, "FT{$ano}000150", $rid, "FT{$anterior}000900"]);

        $venda = new \Venda($db);
        $venda->prepararNumeracaoFatura($rid);
        $db->beginTransaction();
        $numero = $venda->gerarNumeroFatura($rid);
        $db->rollBack();

        $this->assertSame("FT{$ano}000151", $numero);
    }

    public function testBaseDeDadosRecusaFaturaRepetida(): void
    {
        $db = TestDb::exigir($this);
        $rid = TestDb::restauranteFicticio();
        $ins = $db->prepare('INSERT INTO vendas (restaurante_id, usuario_id, caixa_id, numero_fatura) VALUES (?, 1, 1, ?)');
        $ins->execute([$rid, 'FT2026999999']);

        $this->expectException(PDOException::class);
        $ins->execute([$rid, 'FT2026999999']);
    }

    /** @dataProvider modos */
    public function testVendasSimultaneasNuncaRepetemFatura(string $modo): void
    {
        $db = TestDb::exigir($this);
        $rid = TestDb::restauranteFicticio();
        $cmds = [];
        for ($i = 0; $i < 30; $i++) {
            $cmds[] = ['fatura', (string)$rid, $i % 5 === 0 ? 'rollback' : 'commit', $modo];
        }
        $saidas = TestDb::emParalelo(self::WORKER, $cmds);

        $erros = array_values(array_filter($saidas, static fn($s) => !preg_match('/^(OK|RB):FT\d{10}$/', $s)));
        $stmt = $db->prepare('SELECT COUNT(*), COUNT(DISTINCT numero_fatura) FROM vendas WHERE restaurante_id = ?');
        $stmt->execute([$rid]);
        [$total, $distintos] = $stmt->fetch(\PDO::FETCH_NUM);

        $this->assertSame((int)$total, (int)$distintos, 'Encontradas faturas duplicadas.');
        if ($modo === 'preparado') {
            $this->assertSame([], $erros, 'No fluxo normal nenhuma venda pode falhar.');
            $this->assertSame(24, (int)$total);
        }
    }

    public static function modos(): array
    {
        return ['fluxo normal (preparado)' => ['preparado'], 'primeira fatura sem preparacao' => ['sem_preparar']];
    }
}
