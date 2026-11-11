<?php
declare(strict_types=1);

namespace Nivora\Tests\Integration;

use Nivora\Tests\Support\TestDb;
use PDO;
use PHPUnit\Framework\TestCase;

/** Autorizacao do gerente para acoes sensiveis (app/src/config/autorizacao_gerente.php). */
final class AutorizacaoGerenteTest extends TestCase
{
    private PDO $db;
    private int $rid;
    private int $gerente;
    private int $admin;
    private int $garcom;

    public static function setUpBeforeClass(): void
    {
        require_once APP_BASE . '/src/config/database.php';
        require_once APP_BASE . '/src/config/autorizacao_gerente.php';
    }

    protected function setUp(): void
    {
        TestDb::exigir($this);
        $this->db = TestDb::novaLigacao();
        $this->rid = TestDb::restauranteFicticio();
        $this->db->prepare("INSERT INTO restaurantes (id, nome, email, status, data_inicio, data_fim) VALUES (?, 'Aut', ?, 'ATIVO', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR))")
            ->execute([$this->rid, "aut{$this->rid}@teste.local"]);
        $this->gerente = $this->usuario('GERENTE', '3691');
        $this->admin = $this->usuario('ADMIN', null);
        $this->garcom = $this->usuario('GARCOM', '2580');
        $_SESSION = ['usuario_id' => $this->garcom, 'restaurante_id' => $this->rid, 'perfil' => 'GARCOM'];
        $_SERVER['REMOTE_ADDR'] = '10.8.' . random_int(0, 255) . '.' . random_int(1, 254);
        unset($_SERVER['HTTP_X_AUTORIZACAO_GERENTE']);
    }

    private function usuario(string $perfil, ?string $pin): int
    {
        $this->db->prepare('INSERT INTO usuarios (restaurante_id, nome, email, senha, perfil, ativo, pin_hash) VALUES (?, ?, ?, ?, ?, 1, ?)')
            ->execute([$this->rid, 'U ' . $perfil, uniqid('a', true) . '@teste.local', password_hash('Segredo#123', PASSWORD_DEFAULT), $perfil, $pin ? password_hash($pin, PASSWORD_DEFAULT) : null]);
        return (int)$this->db->lastInsertId();
    }

    private function usar(string $codigo): void
    {
        $_SERVER['HTTP_X_AUTORIZACAO_GERENTE'] = $codigo;
    }

    public function testGerenteComPinAutorizaUmaVezSo(): void
    {
        $r = autorizacao_gerente_emitir($this->db, $this->rid, 'cancelar_pedido', 55, $this->gerente, '3691');
        $this->assertTrue($r['ok']);
        $this->usar($r['codigo']);
        $aut = autorizacao_gerente_consumir('cancelar_pedido', 55);
        $this->assertSame($this->gerente, $aut['autorizador_id']);
        $this->assertNull(autorizacao_gerente_consumir('cancelar_pedido', 55), 'o codigo e de uso unico');
    }

    public function testCodigoNaoServeParaOutroPedidoNemOutraAcao(): void
    {
        $r = autorizacao_gerente_emitir($this->db, $this->rid, 'cancelar_pedido', 55, $this->gerente, '3691');
        $this->usar($r['codigo']);
        $this->assertNull(autorizacao_gerente_consumir('cancelar_pedido', 56));
        $r = autorizacao_gerente_emitir($this->db, $this->rid, 'cancelar_pedido', 55, $this->gerente, '3691');
        $this->usar($r['codigo']);
        $this->assertNull(autorizacao_gerente_consumir('cancelar_pagamento', 55));
    }

    public function testCodigoExpira(): void
    {
        $r = autorizacao_gerente_emitir($this->db, $this->rid, 'remover_item', 9, $this->gerente, '3691');
        foreach ($_SESSION['autorizacoes_gerente'] as $k => $v) {
            $_SESSION['autorizacoes_gerente'][$k]['exp'] = time() - 1;
        }
        $this->usar($r['codigo']);
        $this->assertNull(autorizacao_gerente_consumir('remover_item', 9));
    }

    public function testAdministradorAutorizaComPalavraPasse(): void
    {
        $this->assertFalse(autorizacao_gerente_emitir($this->db, $this->rid, 'cancelar_pagamento', 7, $this->admin, 'errada')['ok']);
        $this->assertTrue(autorizacao_gerente_emitir($this->db, $this->rid, 'cancelar_pagamento', 7, $this->admin, 'Segredo#123')['ok']);
    }

    public function testGarcomNaoPodeAutorizarNemComOProprioPin(): void
    {
        $this->assertFalse(autorizacao_gerente_emitir($this->db, $this->rid, 'cancelar_pedido', 1, $this->garcom, '2580')['ok']);
    }

    public function testPinErradoDoGerenteNaoAutoriza(): void
    {
        $this->assertFalse(autorizacao_gerente_emitir($this->db, $this->rid, 'cancelar_pedido', 1, $this->gerente, '0000')['ok']);
    }

    public function testGerenteDeOutroRestauranteNaoAutoriza(): void
    {
        $outro = TestDb::restauranteFicticio();
        $this->assertFalse(autorizacao_gerente_emitir($this->db, $outro, 'cancelar_pedido', 1, $this->gerente, '3691')['ok']);
    }

    public function testAdminEGerenteDispensados(): void
    {
        $this->assertTrue(autorizacao_gerente_dispensada('ADMIN'));
        $this->assertTrue(autorizacao_gerente_dispensada('GERENTE'));
        $this->assertFalse(autorizacao_gerente_dispensada('CAIXA'));
        $this->assertFalse(autorizacao_gerente_dispensada('GARCOM'));
    }

    public function testListaDeAutorizadores(): void
    {
        $ids = array_column(autorizacao_gerente_autorizadores($this->db, $this->rid), 'id');
        $this->assertContains($this->gerente, $ids);
        $this->assertContains($this->admin, $ids);
        $this->assertNotContains($this->garcom, $ids);
    }
}
