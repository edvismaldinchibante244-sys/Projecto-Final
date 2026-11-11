<?php
declare(strict_types=1);

namespace Nivora\Tests\Integration;

use Nivora\Tests\Support\TestDb;
use PDO;
use PHPUnit\Framework\TestCase;

/** Terminais do restaurante + PIN da equipa (app/src/config/terminal_pin.php). */
final class TerminalPinTest extends TestCase
{
    private PDO $db;
    private int $rid;

    public static function setUpBeforeClass(): void
    {
        require_once APP_BASE . '/src/config/database.php';
        require_once APP_BASE . '/src/config/terminal_pin.php';
    }

    protected function setUp(): void
    {
        TestDb::exigir($this);
        $this->db = TestDb::novaLigacao();
        $this->rid = $this->restaurante('Teste Terminal');
        $_COOKIE = [];
        $_SESSION = [];
        $_SERVER['REMOTE_ADDR'] = '10.9.' . random_int(0, 255) . '.' . random_int(1, 254);
    }

    private function restaurante(string $nome): int
    {
        $id = TestDb::restauranteFicticio();
        $this->db->prepare("INSERT INTO restaurantes (id, nome, email, status, data_inicio, data_fim) VALUES (?, ?, ?, 'ATIVO', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR))")
            ->execute([$id, $nome, "term{$id}@teste.local"]);
        return $id;
    }

    private function usuario(string $perfil, ?int $rid = null, ?string $pin = '2580'): int
    {
        $this->db->prepare('INSERT INTO usuarios (restaurante_id, nome, email, senha, perfil, ativo) VALUES (?, ?, ?, ?, ?, 1)')
            ->execute([$rid ?? $this->rid, 'Func ' . $perfil, uniqid('u', true) . '@teste.local', password_hash('Segredo#123', PASSWORD_DEFAULT), $perfil]);
        $id = (int)$this->db->lastInsertId();
        if ($pin !== null) {
            $this->db->prepare('UPDATE usuarios SET pin_hash = ? WHERE id = ?')->execute([password_hash($pin, PASSWORD_DEFAULT), $id]);
        }
        return $id;
    }

    private function terminal(?int $rid = null): array
    {
        terminal_registar($this->db, $rid ?? $this->rid, 'Caixa 1', 0);
        $t = terminal_atual($this->db);
        $this->assertNotNull($t);
        return $t;
    }

    public function testPinCertoEntraEDevolveODadosDaSessao(): void
    {
        $uid = $this->usuario('CAIXA');
        $r = pin_entrar($this->db, $this->terminal(), $uid, '2580');
        $this->assertTrue($r['ok']);
        $this->assertSame($uid, $r['usuario']['id']);
        $this->assertSame('CAIXA', $r['usuario']['perfil']);
        $this->assertSame($this->rid, $r['usuario']['restaurante_id']);
    }

    public function testCookieGuardaSoOHashNaBase(): void
    {
        $this->terminal();
        $token = $_COOKIE[TERMINAL_COOKIE];
        $n = (int)$this->db->query("SELECT COUNT(*) FROM terminais WHERE token_hash = " . $this->db->quote($token))->fetchColumn();
        $this->assertSame(0, $n);
        $n = (int)$this->db->query("SELECT COUNT(*) FROM terminais WHERE token_hash = " . $this->db->quote(hash('sha256', $token)))->fetchColumn();
        $this->assertSame(1, $n);
    }

    public function testCincoErrosBloqueiamMesmoComOPinCerto(): void
    {
        $uid = $this->usuario('GARCOM');
        $t = $this->terminal();
        for ($i = 0; $i < PIN_MAX_FALHAS; $i++) {
            $this->assertFalse(pin_entrar($this->db, $t, $uid, '9999')['ok']);
        }
        $r = pin_entrar($this->db, $t, $uid, '2580');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('bloqueado', $r['mensagem']);
    }

    public function testAdministradorNaoEntraComPin(): void
    {
        $uid = $this->usuario('ADMIN');
        $this->assertFalse(pin_entrar($this->db, $this->terminal(), $uid, '2580')['ok']);
        $this->assertNotContains($uid, array_column(terminal_funcionarios($this->db, $this->rid), 'id'));
    }

    public function testFuncionarioDeOutroRestauranteNaoEntra(): void
    {
        $outro = $this->restaurante('Outro');
        $uid = $this->usuario('CAIXA', $outro);
        $this->assertFalse(pin_entrar($this->db, $this->terminal(), $uid, '2580')['ok']);
    }

    public function testTerminalRevogadoDeixaDeValer(): void
    {
        $t = $this->terminal();
        $this->assertTrue(terminal_revogar($this->db, $this->rid, (int)$t['id']));
        $this->assertNull(terminal_atual($this->db));
    }

    public function testOutroRestauranteNaoRevogaOTerminal(): void
    {
        $t = $this->terminal();
        $this->assertFalse(terminal_revogar($this->db, $this->restaurante('Intruso'), (int)$t['id']));
        $this->assertNotNull(terminal_atual($this->db));
    }

    public function testFuncionarioInativoNaoEntra(): void
    {
        $uid = $this->usuario('BAR');
        $this->db->prepare('UPDATE usuarios SET ativo = 0 WHERE id = ?')->execute([$uid]);
        $this->assertFalse(pin_entrar($this->db, $this->terminal(), $uid, '2580')['ok']);
    }

    public function testPinsFaceisSaoRecusados(): void
    {
        foreach (['1111', '1234', '4321', '0000', '123', '1234567', 'abcd'] as $mau) {
            $this->assertNotNull(pin_problema($mau), $mau);
        }
        $this->assertNull(pin_problema('2580'));
        $this->assertNull(pin_problema('739164'));
    }

    public function testDefinirPinRepoeContadorEFuncionaNoTerminal(): void
    {
        $uid = $this->usuario('COZINHA', null, null);
        $this->db->prepare('UPDATE usuarios SET pin_falhas = 3 WHERE id = ?')->execute([$uid]);
        pin_definir($this->db, $uid, '8642');
        $this->assertSame(0, (int)$this->db->query("SELECT pin_falhas FROM usuarios WHERE id = $uid")->fetchColumn());
        $this->assertTrue(pin_entrar($this->db, $this->terminal(), $uid, '8642')['ok']);
        $this->assertTrue(pin_limpar($this->db, $this->rid, $uid));
        $this->assertFalse(pin_entrar($this->db, $this->terminal(), $uid, '8642')['ok']);
    }
}
