<?php
declare(strict_types=1);

namespace Nivora\Tests\Integration;

use Nivora\Tests\Support\TestDb;
use PDO;
use PHPUnit\Framework\TestCase;

/** Ingredientes do plano Basico (app/src/config/ingredientes_simples.php). */
final class IngredientesSimplesTest extends TestCase
{
    private PDO $db;
    private int $rid;

    public static function setUpBeforeClass(): void
    {
        require_once APP_BASE . '/src/config/database.php';
        require_once APP_BASE . '/src/config/ingredientes_simples.php';
    }

    protected function setUp(): void
    {
        TestDb::exigir($this);
        $this->db = TestDb::novaLigacao();
        estoque_inteligente_schema_garantir($this->db);
        $this->rid = TestDb::restauranteFicticio();
        $this->db->prepare("INSERT INTO restaurantes (id, nome, email, status, data_inicio, data_fim) VALUES (?, 'Ing', ?, 'ATIVO', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR))")
            ->execute([$this->rid, "ing{$this->rid}@teste.local"]);
    }

    private function saldo(int $id): float
    {
        $s = $this->db->prepare('SELECT COALESCE(SUM(saldo_base),0) FROM estoque_saldos WHERE restaurante_id = ? AND insumo_id = ?');
        $s->execute([$this->rid, $id]);
        return (float)$s->fetchColumn();
    }

    private function prato(string $nome): int
    {
        $this->db->prepare('INSERT INTO produtos (restaurante_id, nome, preco, ativo) VALUES (?, ?, 100, 1)')->execute([$this->rid, $nome]);
        return (int)$this->db->lastInsertId();
    }

    public function testCriarEmKgGuardaEmGramasEMostraLegivel(): void
    {
        $id = ing_criar($this->db, $this->rid, $this->rid, 'Carne', 'kg', 2.5, 1, 0);
        $this->assertSame(2500.0, $this->saldo($id));
        $i = ing_listar($this->db, $this->rid)[0];
        $this->assertSame('2,5 kg', $i['saldo_txt']);
        $this->assertSame('ok', $i['estado']);
    }

    public function testEntradaEContagem(): void
    {
        $id = ing_criar($this->db, $this->rid, $this->rid, 'Óleo', 'L', 1, 2, 0);
        $this->assertSame('a_acabar', ing_listar($this->db, $this->rid)[0]['estado']);
        ing_entrada($this->db, $this->rid, $id, 500, 'ml', 0);
        $this->assertSame(1500.0, $this->saldo($id));
        ing_acertar($this->db, $this->rid, $id, 0, 'L', null, 0);
        $this->assertSame('acabou', ing_listar($this->db, $this->rid)[0]['estado']);
    }

    public function testUnidadeErradaERecusada(): void
    {
        $id = ing_criar($this->db, $this->rid, $this->rid, 'Pão', 'un', 10, 0, 0);
        $this->expectException(\InvalidArgumentException::class);
        ing_entrada($this->db, $this->rid, $id, 1, 'kg', 0);
    }

    public function testReceitaBaixaIngredientesAoVender(): void
    {
        $pao = ing_criar($this->db, $this->rid, $this->rid, 'Pão', 'un', 10, 0, 0);
        $carne = ing_criar($this->db, $this->rid, $this->rid, 'Carne', 'kg', 2, 0, 0);
        $burger = $this->prato('Hambúrguer');
        ing_receita_salvar($this->db, $this->rid, $burger, [
            ['insumo_id' => $pao, 'quantidade' => 1, 'unidade' => 'un'],
            ['insumo_id' => $carne, 'quantidade' => 150, 'unidade' => 'g'],
        ]);
        estoque_baixa_automatica_por_itens_venda($this->db, $this->rid, null, [['produto_id' => $burger, 'quantidade' => 3]]);
        $this->assertSame(7.0, $this->saldo($pao));
        $this->assertSame(1550.0, $this->saldo($carne));
        $this->assertCount(2, ing_receita($this->db, $this->rid, $burger));
    }

    public function testReceitaVaziaDeixaDeBaixar(): void
    {
        $pao = ing_criar($this->db, $this->rid, $this->rid, 'Pão', 'un', 10, 0, 0);
        $p = $this->prato('Sande');
        ing_receita_salvar($this->db, $this->rid, $p, [['insumo_id' => $pao, 'quantidade' => 1, 'unidade' => 'un']]);
        ing_receita_salvar($this->db, $this->rid, $p, []);
        estoque_baixa_automatica_por_itens_venda($this->db, $this->rid, null, [['produto_id' => $p, 'quantidade' => 2]]);
        $this->assertSame(10.0, $this->saldo($pao));
    }

    public function testNomeRepetidoERecusado(): void
    {
        ing_criar($this->db, $this->rid, $this->rid, 'Arroz', 'kg', 1, 0, 0);
        $this->expectException(\InvalidArgumentException::class);
        ing_criar($this->db, $this->rid, $this->rid, 'arroz', 'kg', 1, 0, 0);
    }

    public function testOutroRestauranteNaoVeNemMexe(): void
    {
        $id = ing_criar($this->db, $this->rid, $this->rid, 'Sal', 'g', 500, 0, 0);
        $outro = TestDb::restauranteFicticio();
        $this->assertSame([], ing_listar($this->db, $outro));
        $this->expectException(\InvalidArgumentException::class);
        ing_entrada($this->db, $outro, $id, 1, 'g', 0);
    }

    public function testPlanoBasicoTemLimiteDe30(): void
    {
        $planos = require APP_BASE . '/src/config/planos.php';
        $this->assertSame(30, $planos['BASICO']['limites']['insumos']);
        $this->assertTrue($planos['BASICO']['funcionalidades']['estoque_ingredientes']);
        $this->assertFalse($planos['BASICO']['funcionalidades']['estoque_avancado']);
    }

    public function testFormatacao(): void
    {
        $this->assertSame('20 un', ing_formatar(20, 'un'));
        $this->assertSame('100 g', ing_formatar(100, 'g'));
        $this->assertSame('1,5 kg', ing_formatar(1500, 'g'));
        $this->assertSame('10 kg', ing_formatar(10000, 'g'));
        $this->assertSame('0 L', ing_formatar(0, 'ml'));
        $this->assertSame('1.250 un', ing_formatar(1250, 'un'));
    }

    public function testPrevisaoSoComDadosSuficientes(): void
    {
        $this->assertSame('sem_vendas', ing_previsao(29850, 0, 0, 'g')['estado']);
        // 1 prato de teste em 1 dia: nao inventa "2786 dias".
        $p = ing_previsao(29850, 150, 1, 'g');
        $this->assertSame('a_aprender', $p['estado']);
        $this->assertStringNotContainsString('2786', $p['texto']);
        // 14 dias a 3 kg/dia: ~9 dias.
        $p = ing_previsao(29850, 42000, 14, 'g');
        $this->assertSame(9, $p['dias']);
        $this->assertStringContainsString('dá para ~9 dias', $p['texto']);
        $this->assertStringContainsString('gasta ~3 kg/dia', $p['texto']);
        // Muito stock: limita a "mais de 2 meses".
        $this->assertStringContainsString('mais de 2 meses', ing_previsao(29850, 1400, 7, 'g')['texto']);
        $this->assertStringContainsString('não chega para hoje', ing_previsao(0, 1400, 7, 'g')['texto']);
    }
}
