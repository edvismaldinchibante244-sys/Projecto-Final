<?php
declare(strict_types=1);

namespace Nivora\Tests\Integration;

use Nivora\Tests\Support\TestDb;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Reserva pelo QR e check-in (reserva_qr.php -> api/reserva_publica.php -> ReservaService).
 * Cada teste usa um restaurante Empresarial ficticio, com uma mesa ocupada e outra livre.
 */
final class ReservaQrTest extends TestCase
{
    private PDO $db;
    private int $rid;
    private int $mesaOcupada;
    private int $mesaLivre;
    private string $tzOriginal;

    public static function setUpBeforeClass(): void
    {
        require_once APP_BASE . '/src/config/database.php';
        require_once APP_BASE . '/src/config/plano_check.php';
        require_once APP_BASE . '/src/config/reserva_qr_schema.php';
        require_once APP_BASE . '/src/Model/Reserva.php';
        require_once APP_BASE . '/src/Service/ReservaService.php';
        require_once APP_BASE . '/src/api/reserva_publica_handler.php';
    }

    protected function setUp(): void
    {
        TestDb::exigir($this);
        $this->tzOriginal = date_default_timezone_get();
        // Ligacao propria COM chaves estrangeiras ativas, como em producao.
        $this->db = TestDb::novaLigacao();
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');

        $this->rid = TestDb::restauranteFicticio();
        $this->db->prepare("INSERT INTO restaurantes (id, nome, email, data_inicio, data_fim) VALUES (?, 'Teste QR', ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR))")
            ->execute([$this->rid, "qr{$this->rid}@teste.local"]);
        // Plano atribuido manualmente: SEM linha em compras_planos (o caso que falhava).
        $this->db->prepare("INSERT INTO restaurante_planos (restaurante_id, plano_id, data_inicio, data_fim, status, tipo_plano, valor_pago) VALUES (?, 3, DATE_SUB(CURDATE(), INTERVAL 1 DAY), DATE_ADD(CURDATE(), INTERVAL 1 YEAR), 'ATIVO', 'PAGO', 0)")
            ->execute([$this->rid]);
        $mesa = $this->db->prepare('INSERT INTO mesas (restaurante_id, numero, capacidade, status) VALUES (?, ?, ?, ?)');
        $mesa->execute([$this->rid, '1', 4, 'OCUPADA']);
        $this->mesaOcupada = (int)$this->db->lastInsertId();
        $mesa->execute([$this->rid, '2', 6, 'LIVRE']);
        $this->mesaLivre = (int)$this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tzOriginal);
        putenv('APP_TIMEZONE');
    }

    private function sessaoQr(?int $mesaId = null): string
    {
        $r = reserva_qr_create_session($this->db, $this->rid, [
            'mesa_id' => $mesaId,
            'expira_em_minutos' => 60,
            'observacoes' => 'reserva_qr_checkin',
        ]);
        $this->assertTrue((bool)($r['success'] ?? false), 'sessao QR: ' . json_encode($r));
        return (string)$r['token'];
    }

    private function amanha(): string
    {
        return (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');
    }

    private function dadosCliente(string $token, array $extra = []): array
    {
        return $extra + [
            'token' => $token,
            'nome_cliente' => 'Joana QR',
            // Sem telefone (a reserva so envia WhatsApp quando ha telefone) e email num
            // dominio reservado: o teste nunca contacta ninguem, mas cria o cliente.
            'telefone_cliente' => '',
            'email_cliente' => 'joana.qr@teste.invalid',
            'data_reserva' => $this->amanha(),
            'hora_reserva' => '20:00',
            'quantidade_pessoas' => 2,
        ];
    }

    public function test_reserva_pelo_qr_funciona_sem_compra_de_plano_e_grava_origem_qr(): void
    {
        // Regressao: clientes.restaurante_id apontava para compras_planos (erro 1452)
        // e reservas.origem nao aceitava 'qr' ("Data truncated").
        $r = criar_reserva_publica($this->dadosCliente($this->sessaoQr()), $this->db);

        $this->assertTrue((bool)($r['success'] ?? false), json_encode($r));
        $reserva = $this->db->query('SELECT origem, cliente_id, status FROM reservas WHERE id = ' . (int)$r['reserva_id'])->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('qr', $reserva['origem']);
        $this->assertSame('pendente', $reserva['status']);
        $this->assertGreaterThan(0, (int)$reserva['cliente_id']);
    }

    public function test_mesa_ocupada_agora_pode_ser_reservada_para_mais_tarde_mas_nao_ja(): void
    {
        $servico = new \App\Service\ReservaService($this->db, new \App\Model\Reserva($this->db));
        $ids = static fn(array $mesas): array => array_map(static fn($m) => (int)$m['id'], $mesas);

        $amanha = $ids($servico->validarDisponibilidade($this->rid, $this->amanha(), '20:00', 2));
        $this->assertContains($this->mesaOcupada, $amanha, 'ocupada agora nao deve impedir reservar para amanha');

        $dentroDeMeiaHora = new \DateTimeImmutable('+30 minutes');
        $ja = $ids($servico->validarDisponibilidade($this->rid, $dentroDeMeiaHora->format('Y-m-d'), $dentroDeMeiaHora->format('H:i'), 2));
        $this->assertNotContains($this->mesaOcupada, $ja, 'para daqui a meia hora a mesa ocupada nao esta disponivel');
        $this->assertContains($this->mesaLivre, $ja);
    }

    public function test_prebloqueio_dura_os_minutos_pedidos_mesmo_com_o_php_noutro_fuso(): void
    {
        // Regressao: com date.timezone = Europe/Berlin os "5 minutos" duravam 65.
        date_default_timezone_set('Europe/Berlin');
        $servico = new \App\Service\ReservaService($this->db, new \App\Model\Reserva($this->db));

        $r = $servico->criarPreBloqueio($this->rid, $this->sessaoQr(), $this->amanha(), '20:00', 2, null, 5);

        $this->assertTrue((bool)($r['success'] ?? false), json_encode($r));
        $segundos = (int)$this->db->query("SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), expires_at) FROM reserva_pre_bloqueios WHERE restaurante_id = {$this->rid} ORDER BY id DESC LIMIT 1")->fetchColumn();
        $this->assertGreaterThan(240, $segundos);
        $this->assertLessThanOrEqual(305, $segundos);
    }

    public function test_hora_de_validade_e_mostrada_no_fuso_local(): void
    {
        putenv('APP_TIMEZONE=Africa/Maputo');
        $this->assertSame('01/11/2026 12:23', reserva_qr_utc_para_local('2026-11-01 10:23:00'));
        $this->assertSame('', reserva_qr_utc_para_local(''));
    }

    public function test_acompanhar_sem_pre_pedido_nao_e_erro(): void
    {
        $r = acompanhar_pedido_qr_publico(['token' => $this->sessaoQr()], $this->db);

        $this->assertTrue($r['success']);
        $this->assertNull($r['pedido']);
        $this->assertSame(200, $r['http_code']);
    }

    public function test_falha_interna_nao_mostra_sql_ao_cliente(): void
    {
        $token = $this->sessaoQr();
        $c = TestDb::config();
        $falha = new class("mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4", $c['user'], $c['pass']) extends PDO {
            public function prepare($query, $options = []): \PDOStatement|false
            {
                if (stripos((string)$query, 'INSERT INTO reservas') !== false) {
                    throw new PDOException("SQLSTATE[42S02]: Base table or view not found: tabela_secreta");
                }
                return parent::prepare($query, $options);
            }
        };
        $falha->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $falha->exec("SET time_zone = '+00:00'");

        $r = criar_reserva_publica($this->dadosCliente($token), $falha);

        $this->assertFalse($r['success']);
        $this->assertSame(500, $r['http_code']);
        $this->assertArrayNotHasKey('error', $r);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($r));
    }
    private function produto(string $nome, float $preco): int
    {
        $this->db->prepare('INSERT INTO produtos (restaurante_id, nome, preco, ativo, estoque) VALUES (?, ?, ?, 1, 50)')
            ->execute([$this->rid, $nome, $preco]);
        return (int)$this->db->lastInsertId();
    }

    private function prePedido(string $token, int $mesaId, int $produtoId, string $nome = 'Joana QR', ?string $idem = null): array
    {
        $r = criar_pedido_qr_publico([
            'token' => $token,
            'mesa_id' => $mesaId,
            'idem_key' => $idem ?? ('teste_' . bin2hex(random_bytes(6))),
            'cliente_nome' => $nome,
            'itens' => [['produto_id' => $produtoId, 'qtd' => 2]],
        ], $this->db);
        $this->assertTrue((bool)($r['success'] ?? false), 'pre-pedido: ' . json_encode($r));
        return $r;
    }

    private function contar(string $sql): int
    {
        return (int)$this->db->query($sql)->fetchColumn();
    }

    public function test_pre_pedido_da_reserva_fica_em_espera_e_vai_para_a_cozinha_no_check_in(): void
    {
        $token = $this->sessaoQr();
        $reserva = criar_reserva_publica($this->dadosCliente($token, ['mesa_atribuida' => $this->mesaLivre]), $this->db);
        $this->assertTrue((bool)$reserva['success'], json_encode($reserva));
        $frango = $this->produto('Frango', 450);
        $idem = 'teste_' . bin2hex(random_bytes(6));
        $pre = $this->prePedido($token, $this->mesaLivre, $frango, 'Joana QR', $idem);

        // Antes do check-in: nada na cozinha, stock intacto, cliente ve "guardado".
        $this->assertTrue($pre['em_espera'] ?? false, json_encode($pre));
        $this->assertSame(0, $this->contar("SELECT COUNT(*) FROM pedidos WHERE restaurante_id = {$this->rid}"));
        $this->assertSame(50, $this->contar("SELECT estoque FROM produtos WHERE id = {$frango}"));
        $acompanhar = acompanhar_pedido_qr_publico(['token' => $token], $this->db);
        $this->assertNull($acompanhar['pedido']);
        $this->assertNotNull($acompanhar['pre_pedido_em_espera']);
        // Duplo clique no telemovel: continua a ser um so pre-pedido.
        $this->prePedido($token, $this->mesaLivre, $frango, 'Joana QR', $idem);
        $this->assertSame(1, $this->contar("SELECT COUNT(*) FROM reserva_pre_pedidos WHERE restaurante_id = {$this->rid}"));

        $servico = new \App\Service\ReservaService($this->db, new \App\Model\Reserva($this->db));
        $checkin = $servico->fazerCheckIn((int)$reserva['reserva_id'], $this->mesaLivre, $this->rid);

        // No check-in: o pedido nasce na mesa do cliente e vai para a cozinha.
        $this->assertTrue($checkin['success'], json_encode($checkin));
        $this->assertGreaterThan(0, (int)$checkin['pedido_id']);
        $this->assertStringContainsString('pre-pedido', $checkin['message']);
        $pedido = $this->db->query('SELECT mesa_id, status, total, origem FROM pedidos WHERE id = ' . (int)$checkin['pedido_id'])->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($this->mesaLivre, (int)$pedido['mesa_id']);
        $this->assertSame('PREPARANDO', $pedido['status']);
        $this->assertEquals(900.00, (float)$pedido['total']);
        $this->assertSame(2, $this->contar('SELECT SUM(quantidade) FROM itens_pedido WHERE status = \'em_preparo\' AND pedido_id = ' . (int)$checkin['pedido_id']));
        $this->assertSame((int)$checkin['pedido_id'], (int)acompanhar_pedido_qr_publico(['token' => $token], $this->db)['pedido']['id']);

        // Repetir o check-in nao cria outro pedido.
        $denovo = $servico->fazerCheckIn((int)$reserva['reserva_id'], $this->mesaLivre, $this->rid);
        $this->assertSame((int)$checkin['pedido_id'], $denovo['pedido_id']);
        $this->assertSame(1, $this->contar("SELECT COUNT(*) FROM pedidos WHERE restaurante_id = {$this->rid}"));
    }

    public function test_reserva_cancelada_nunca_manda_o_pre_pedido_para_a_cozinha(): void
    {
        $token = $this->sessaoQr();
        $reserva = criar_reserva_publica($this->dadosCliente($token, ['mesa_atribuida' => $this->mesaLivre]), $this->db);
        $this->prePedido($token, $this->mesaLivre, $this->produto('Peixe', 600));
        $servico = new \App\Service\ReservaService($this->db, new \App\Model\Reserva($this->db));
        $servico->cancelarReserva((int)$reserva['reserva_id'], $this->rid);

        $this->assertSame(0, $this->contar("SELECT COUNT(*) FROM pedidos WHERE restaurante_id = {$this->rid}"));
    }

    public function test_pre_pedido_feito_antes_da_reserva_tambem_fica_ligado_e_muda_para_a_mesa_do_check_in(): void
    {
        $token = $this->sessaoQr();
        $outraMesa = (int)$this->db->query("SELECT id FROM mesas WHERE restaurante_id = {$this->rid} AND numero = '1'")->fetchColumn();
        $this->db->exec("UPDATE mesas SET status = 'LIVRE' WHERE id = {$outraMesa}");
        $pre = $this->prePedido($token, $outraMesa, $this->produto('Matapa', 380));
        $reserva = criar_reserva_publica($this->dadosCliente($token, ['mesa_atribuida' => $this->mesaLivre]), $this->db);
        $this->assertTrue((bool)$reserva['success'], json_encode($reserva));

        $servico = new \App\Service\ReservaService($this->db, new \App\Model\Reserva($this->db));
        $checkin = $servico->fazerCheckIn((int)$reserva['reserva_id'], $this->mesaLivre, $this->rid);

        $this->assertSame((int)$pre['pedido_id'], $checkin['pedido_id']);
        $mesaDoPedido = (int)$this->db->query('SELECT mesa_id FROM pedidos WHERE id = ' . (int)$pre['pedido_id'])->fetchColumn();
        $this->assertSame($this->mesaLivre, $mesaDoPedido, 'o pre-pedido passa para a mesa onde o cliente se sentou');
    }

    public function test_reserva_sem_pre_pedido_abre_pedido_novo(): void
    {
        $reserva = criar_reserva_publica($this->dadosCliente($this->sessaoQr(), ['mesa_atribuida' => $this->mesaLivre]), $this->db);
        $servico = new \App\Service\ReservaService($this->db, new \App\Model\Reserva($this->db));
        $checkin = $servico->fazerCheckIn((int)$reserva['reserva_id'], $this->mesaLivre, $this->rid);

        $this->assertTrue($checkin['success']);
        $this->assertNull($checkin['pedido_id']);
    }

    public function test_reservas_antigas_sem_ligacao_encontram_o_pre_pedido_pelo_nome_na_mesa(): void
    {
        $token = $this->sessaoQr();
        $pre = $this->prePedido($token, $this->mesaLivre, $this->produto('Sumo', 120)); // ainda sem reserva: vai logo
        $reserva = criar_reserva_publica($this->dadosCliente($token, ['mesa_atribuida' => $this->mesaLivre]), $this->db);
        // Simula dados de antes da migracao: sem ligacao gravada.
        $this->db->exec('UPDATE pedido_qr_meta SET reserva_id = NULL WHERE pedido_id = ' . (int)$pre['pedido_id']);
        $this->db->exec('UPDATE reservas SET qr_sessao_id = NULL WHERE id = ' . (int)$reserva['reserva_id']);

        $servico = new \App\Service\ReservaService($this->db, new \App\Model\Reserva($this->db));
        $checkin = $servico->fazerCheckIn((int)$reserva['reserva_id'], $this->mesaLivre, $this->rid);

        $this->assertSame((int)$pre['pedido_id'], $checkin['pedido_id']);
    }
}
