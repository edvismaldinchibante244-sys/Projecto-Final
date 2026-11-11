<?php
declare(strict_types=1);

namespace Nivora\Tests\Integration;

use Nivora\Tests\Support\TestDb;
use PDO;
use PHPUnit\Framework\TestCase;

/** Cancelamento, modo limitado, exportacao ZIP e eliminacao com declaracao (Termos, seccoes 4, 5 e 7). */
final class EncerramentoTest extends TestCase
{
    private PDO $db;
    private int $rid;

    public static function setUpBeforeClass(): void
    {
        require_once APP_BASE . '/src/config/database.php';
        require_once APP_BASE . '/src/config/plano_lifecycle.php';
        require_once APP_BASE . '/src/config/plano_encerramento.php';
    }

    protected function setUp(): void
    {
        TestDb::exigir($this);
        $this->db = TestDb::novaLigacao();
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->rid = TestDb::restauranteFicticio();
        $this->db->prepare("INSERT INTO restaurantes (id, nome, email, status, data_inicio, data_fim) VALUES (?, 'Enc Teste', 'x', 'ATIVO', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 10 DAY))")
            ->execute([$this->rid]);
        $this->db->prepare("INSERT INTO produtos (restaurante_id, nome, preco, ativo) VALUES (?, 'Prato; com \"aspas\"', 100, 1)")->execute([$this->rid]);
        $this->db->prepare("INSERT INTO mesas (restaurante_id, numero, capacidade, status) VALUES (?, 'B1', 1, 'LIVRE')")->execute([$this->rid]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db, $this->rid)) {
            enc_apagar_dados($this->db, [$this->rid]);
            $this->db->prepare('DELETE FROM eliminacao_declaracoes WHERE restaurante_id = ?')->execute([$this->rid]);
        }
    }

    private function estado(): array
    {
        return enc_estado($this->db, $this->rid);
    }

    public function testCancelarSoProduzEfeitoNoFimDoPeriodoPago(): void
    {
        $r = enc_pedir_cancelamento($this->db, $this->rid, 0, 'Fechei o restaurante');
        $this->assertSame(date('Y-m-d', strtotime('+10 days')), $r['efeito_em']);
        $this->assertSame('ATIVO', $this->estado()['status']);
        // A rotina de hoje nao encerra (a data ainda nao chegou).
        $this->assertSame([], array_filter(enc_rotina_diaria($this->db, true), fn($l) => str_contains($l, "#{$this->rid} ")));
        enc_desistir_cancelamento($this->db, $this->rid);
        $this->assertNull($this->estado()['cancelamento_efetivo_em']);
    }

    public function testDataDeEfeitoComPlanoJaVencidoEHoje(): void
    {
        $this->assertSame('2026-05-10', enc_data_efeito('2026-05-01', new \DateTimeImmutable('2026-05-10')));
        $this->assertSame('2026-05-20', enc_data_efeito('2026-05-20', new \DateTimeImmutable('2026-05-10')));
    }

    public function testCicloCompletoAteADeclaracao(): void
    {
        enc_pedir_cancelamento($this->db, $this->rid, 0);
        $efeito = $this->estado()['cancelamento_efetivo_em'];
        $diaSeguinte = date('Y-m-d', strtotime($efeito . ' +1 day'));

        enc_rotina_diaria($this->db, false, $diaSeguinte);
        $e = $this->estado();
        $this->assertSame('CANCELADO', $e['status']);
        $this->assertSame(date('Y-m-d', strtotime($diaSeguinte . ' +120 days')), $e['eliminacao_agendada_em']);

        // Conta cancelada: o calculo automatico nao a reactiva, e so permite exportar.
        $pol = plano_lifecycle_compute_policy(['status' => 'CANCELADO', 'data_fim' => date('Y-m-d', strtotime('+1 year'))]);
        $this->assertSame('FULL_BLOCK', $pol['limite_nivel']);
        plano_lifecycle_apply_status($this->db, $this->rid, 'ATIVO');
        $this->assertSame('CANCELADO', $this->estado()['status']);

        // Aviso 14 dias antes, uma so vez.
        $diaAviso = date('Y-m-d', strtotime($e['eliminacao_agendada_em'] . ' -10 days'));
        enc_rotina_diaria($this->db, false, $diaAviso);
        $this->assertNotNull($this->estado()['eliminacao_aviso_em']);

        // Eliminacao com declaracao.
        $log = enc_rotina_diaria($this->db, false, $e['eliminacao_agendada_em']);
        $this->assertNotEmpty(array_filter($log, fn($l) => str_contains($l, 'declaracao')));
        $this->assertNull(enc_estado($this->db, $this->rid));
        $c = $this->db->prepare('SELECT COUNT(*) FROM produtos WHERE restaurante_id = ?');
        $c->execute([$this->rid]);
        $this->assertSame(0, (int)$c->fetchColumn());
        $d = $this->db->prepare('SELECT restaurante_nome, linhas_apagadas, hash FROM eliminacao_declaracoes WHERE restaurante_id = ?');
        $d->execute([$this->rid]);
        $dec = $d->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('Enc Teste', $dec['restaurante_nome']);
        $this->assertGreaterThanOrEqual(3, (int)$dec['linhas_apagadas']);
        $this->assertSame(64, strlen($dec['hash']));
    }

    public function testExportarZipTemCsvSemSegredos(): void
    {
        $this->db->prepare("INSERT INTO usuarios (restaurante_id, nome, email, senha, perfil, ativo) VALUES (?, 'Dono', 'dono@x.co', 'HASH-SECRETO', 'ADMIN', 1)")->execute([$this->rid]);
        $zip = enc_exportar_zip($this->db, $this->rid);
        $z = new \ZipArchive();
        $this->assertTrue($z->open($zip) === true);
        $nomes = [];
        for ($i = 0; $i < $z->numFiles; $i++) {
            $nomes[] = $z->getNameIndex($i);
        }
        $this->assertContains('tecnico/produtos.csv', $nomes);
        $this->assertContains('tecnico/mesas.csv', $nomes);
        $this->assertContains('LEIA-ME.html', $nomes);
        $xlsx = array_values(array_filter($nomes, fn($n) => str_ends_with($n, '.xlsx')));
        $this->assertCount(1, $xlsx);
        $this->assertStringNotContainsString('HASH-SECRETO', (string)$z->getFromName($xlsx[0]));
        $produtos = (string)$z->getFromName('tecnico/produtos.csv');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $produtos);
        $this->assertStringContainsString('"Prato; com ""aspas"""', $produtos);
        $equipa = (string)$z->getFromName('tecnico/equipa.csv');
        $this->assertStringContainsString('Preço (MZN)', $produtos);
        $this->assertStringNotContainsString('restaurante_id', $equipa);
        $this->assertStringContainsString('Dono', $equipa);
        $this->assertStringNotContainsString('HASH-SECRETO', $equipa);
        $this->assertStringNotContainsString('senha', strtolower(strtok($equipa, "\n")));
        $z->close();
        @unlink($zip);
    }

    public function testModoLimitadoSeteDiasDepoisBloqueio(): void
    {
        $f = fn(int $d) => plano_lifecycle_compute_policy(['data_fim' => date('Y-m-d', strtotime("$d days"))]);
        $this->assertSame('READ_ONLY', $f(-1)['limite_nivel']);
        $this->assertSame('READ_ONLY', $f(-7)['limite_nivel']);
        $this->assertSame('FULL_BLOCK', $f(-8)['limite_nivel']);

        // Modo limitado: consultar sim, vender nao; o turno pode abrir.
        $this->assertTrue(plano_lifecycle_can_access('READ_ONLY', '/vendas.php', false, 'GET'));
        $this->assertFalse(plano_lifecycle_can_access('READ_ONLY', '/api/venda_criar.php', true, 'POST'));
        $this->assertTrue(plano_lifecycle_can_access('READ_ONLY', '/iniciar_turno.php', false, 'POST'));
        // Bloqueado/cancelado: so os dados e o plano.
        $this->assertFalse(plano_lifecycle_can_access('FULL_BLOCK', '/vendas.php', false, 'GET'));
        $this->assertTrue(plano_lifecycle_can_access('FULL_BLOCK', '/meus_dados.php', false, 'GET'));
        $this->assertTrue(plano_lifecycle_can_access('FULL_BLOCK', '/api/plano_exportar_dados.php', true, 'POST'));
    }

    public function testZipSimplesSemExtensaoEValido(): void
    {
        // O XAMPP vem com a extensao zip desligada: o ZIP proprio tem de abrir em qualquer programa.
        $z = new \EncZipSimples();
        $z->addFromString('a.csv', "id;nome\n1;Hambúrguer\n" . str_repeat("2;x\n", 300));
        $z->addFromString('b.txt', 'olá');
        $f = tempnam(sys_get_temp_dir(), 'zt');
        $z->gravar($f);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($f) === true);
        $this->assertSame(2, $zip->numFiles);
        $this->assertStringContainsString('Hambúrguer', (string)$zip->getFromName('a.csv'));
        $this->assertSame('olá', $zip->getFromName('b.txt'));
        $zip->close();
        @unlink($f);
    }
}
