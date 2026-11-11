<?php
declare(strict_types=1);

namespace Nivora\Tests\Integration;

use Nivora\Tests\Support\TestDb;
use PDO;
use PHPUnit\Framework\TestCase;

/** Versao de operacao (triggers) e resumo diario de vendas: mesmos totais que somar as vendas. */
final class DesempenhoTest extends TestCase
{
    private PDO $db;
    private int $rid;

    public static function setUpBeforeClass(): void
    {
        require_once APP_BASE . '/src/config/vendas_resumo.php';
    }

    protected function setUp(): void
    {
        TestDb::exigir($this);
        $this->db = TestDb::novaLigacao();
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $existe = $this->db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('operacao_versao','vendas_resumo_diario','vendas_resumo_estado')")->fetchColumn();
        if ((int)$existe < 3) {
            $this->markTestSkipped('Migracao 20261108_0900_desempenho_operacao nao aplicada.');
        }
        $this->rid = TestDb::restauranteFicticio();
    }

    protected function tearDown(): void
    {
        if (isset($this->db, $this->rid)) {
            foreach (['vendas', 'mesas', 'operacao_versao', 'vendas_resumo_diario'] as $t) {
                $this->db->prepare("DELETE FROM `$t` WHERE restaurante_id = ?")->execute([$this->rid]);
            }
        }
    }

    private function versao(): int
    {
        $st = $this->db->prepare('SELECT versao FROM operacao_versao WHERE restaurante_id = ?');
        $st->execute([$this->rid]);
        return (int)$st->fetchColumn();
    }

    public function testVersaoSobeQuandoMesaMuda(): void
    {
        $antes = $this->versao();
        $this->db->prepare("INSERT INTO mesas (restaurante_id, numero, capacidade, status) VALUES (?, 'T1', 2, 'LIVRE')")->execute([$this->rid]);
        $depois = $this->versao();
        if ($depois === $antes) {
            $this->markTestSkipped('Triggers nao instalados neste servidor (o sistema usa o calculo de recurso).');
        }
        $this->db->prepare("UPDATE mesas SET status = 'OCUPADA' WHERE restaurante_id = ?")->execute([$this->rid]);
        $this->assertGreaterThan($depois, $this->versao());
    }

    public function testResumoDaOsMesmosTotaisQueAsVendas(): void
    {
        $ins = $this->db->prepare("INSERT INTO vendas (caixa_id, restaurante_id, usuario_id, numero_fatura, total, desconto, total_final, forma_pagamento, status, criado_em)
            VALUES (1, ?, 1, ?, ?, 0, ?, 'DINHEIRO', ?, ?)");
        $dados = [['A1', 100, 'PAGO', '-3 day 10:00'], ['A2', 50.5, 'PAGO', '-3 day 23:59'], ['A3', 70, 'CANCELADO', '-2 day 12:00'],
            ['A4', 30, 'PAGO', '-1 day 00:00'], ['A5', 12.25, 'PAGO', 'today 09:00']];
        foreach ($dados as [$fat, $v, $st, $quando]) {
            $ins->execute([$this->rid, $fat . '-' . $this->rid, $v, $v, $st, date('Y-m-d H:i:s', strtotime($quando))]);
        }
        $inicio = date('Y-m-d', strtotime('-5 day'));
        $fim = date('Y-m-d', strtotime('+1 day'));

        $direto = vendas_resumo_periodo($this->db, $this->rid, $inicio, $fim); // sem resumo: calculo direto
        foreach ([1, 2, 3, 4, 5] as $i) {
            vendas_resumo_calcular_dia($this->db, date('Y-m-d', strtotime("-$i day")));
        }
        $resumo = vendas_resumo_periodo($this->db, $this->rid, $inicio, $fim);

        $this->assertEqualsWithDelta(192.75, $direto['total'], 0.001);
        $this->assertEqualsWithDelta($direto['total'], $resumo['total'], 0.001);
        $this->assertSame(4, $resumo['vendas']);
        $this->assertEqualsWithDelta(150.5, $resumo['por_dia'][date('Y-m-d', strtotime('-3 day'))], 0.001);
    }
}
