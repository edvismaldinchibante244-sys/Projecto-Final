<?php
declare(strict_types=1);

namespace Nivora\Tests\Integration;

use Nivora\Tests\Support\TestDb;
use PDO;
use PHPUnit\Framework\TestCase;

/** Fila de trabalhos: exportacao em segundo plano, sem duplicados, uma so execucao, limpeza. */
final class FilaTrabalhosTest extends TestCase
{
    private PDO $db;
    private const RID = 990001;

    public static function setUpBeforeClass(): void
    {
        require_once APP_BASE . '/src/config/database.php';
        require_once APP_BASE . '/src/config/plano_lifecycle.php';
        require_once APP_BASE . '/src/config/plano_encerramento.php';
        require_once APP_BASE . '/src/config/trabalhos_fila.php';
    }

    protected function setUp(): void
    {
        TestDb::exigir($this);
        $this->db = TestDb::novaLigacao();
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (!fila_disponivel($this->db)) {
            $this->markTestSkipped('Migracao 20261108_1300_trabalhos_fila nao aplicada.');
        }
        $this->db->prepare('DELETE FROM trabalhos_fila WHERE restaurante_id = ?')->execute([self::RID]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            foreach ($this->db->query('SELECT ficheiro FROM trabalhos_fila WHERE restaurante_id = ' . self::RID . ' AND ficheiro IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $f) {
                @unlink(fila_pasta() . DIRECTORY_SEPARATOR . $f);
            }
            $this->db->prepare('DELETE FROM trabalhos_fila WHERE restaurante_id = ?')->execute([self::RID]);
        }
    }

    public function testPedidoRepetidoNaoDuplica(): void
    {
        $a = fila_criar($this->db, self::RID, 990101, 'exportar_dados');
        $b = fila_criar($this->db, self::RID, 990101, 'exportar_dados');
        $this->assertSame((int)$a['id'], (int)$b['id']);
        $this->assertSame('PENDENTE', $a['estado']);
    }

    public function testReservaSoUmaVezEExecutaAteFicarPronto(): void
    {
        $t = fila_criar($this->db, self::RID, 990101, 'exportar_dados');
        $r1 = fila_reservar($this->db, (int)$t['id']);
        $r2 = fila_reservar($this->db, (int)$t['id']);
        $this->assertNotNull($r1);
        $this->assertNull($r2, 'Um trabalho nao pode ser reservado duas vezes.');

        $fim = fila_executar($this->db, $r1);
        $this->assertSame('PRONTO', $fim['estado']);
        $ficheiro = fila_pasta() . DIRECTORY_SEPARATOR . $fim['ficheiro'];
        $this->assertFileExists($ficheiro);
        $this->assertSame("PK\x03\x04", (string)file_get_contents($ficheiro, false, null, 0, 4));
        $pub = fila_publico($fim);
        $this->assertTrue($pub['pronto']);
        $this->assertArrayNotHasKey('ficheiro', $pub, 'O caminho no servidor nunca vai para o browser.');
        $this->assertGreaterThan(time(), (int)$pub['expira_ts']);
    }

    public function testTarefaAgendadaProcessaELimpaExpirados(): void
    {
        $t = fila_criar($this->db, self::RID, 990101, 'exportar_dados');
        $this->assertGreaterThanOrEqual(1, fila_processar($this->db, 5, false));
        $fim = fila_obter($this->db, (int)$t['id']);
        $this->assertSame('PRONTO', $fim['estado']);

        $this->db->prepare('UPDATE trabalhos_fila SET expira_em = NOW() - INTERVAL 1 HOUR WHERE id = ?')->execute([(int)$t['id']]);
        $this->assertGreaterThanOrEqual(1, fila_limpar($this->db));
        $this->assertFileDoesNotExist(fila_pasta() . DIRECTORY_SEPARATOR . $fim['ficheiro']);
        $this->assertSame('EXPIRADO', fila_obter($this->db, (int)$t['id'])['estado']);
    }

    public function testTrabalhoPresoVoltaAFila(): void
    {
        $t = fila_criar($this->db, self::RID, 990101, 'exportar_dados');
        $this->db->prepare("UPDATE trabalhos_fila SET estado = 'A_PROCESSAR', tentativas = 1, iniciado_em = NOW() - INTERVAL 1 HOUR WHERE id = ?")->execute([(int)$t['id']]);
        fila_recuperar_presos($this->db);
        $this->assertSame('PENDENTE', fila_obter($this->db, (int)$t['id'])['estado']);
    }

    public function testZipEmDiscoEValidoComFicheiroGrande(): void
    {
        $origem = tempnam(sys_get_temp_dir(), 'nvz');
        $f = fopen($origem, 'wb');
        for ($i = 0; $i < 30000; $i++) {
            fwrite($f, "linha $i;" . str_repeat('x', 100) . "\n");
        }
        fclose($f);
        $destino = sys_get_temp_dir() . '/nvz_' . bin2hex(random_bytes(4)) . '.zip';
        $z = new \EncZipSimples($destino);
        $z->addFile($origem, 'grande.csv');
        $z->addFromString('pequeno.txt', 'olá');
        $z->gravar($destino);
        if (class_exists('ZipArchive')) {
            $za = new \ZipArchive();
            $this->assertTrue($za->open($destino, \ZipArchive::CHECKCONS) === true);
            $this->assertSame((string)file_get_contents($origem), $za->getFromName('grande.csv'));
            $this->assertSame('olá', $za->getFromName('pequeno.txt'));
            $za->close();
        } else {
            $this->assertSame("PK\x03\x04", (string)file_get_contents($destino, false, null, 0, 4));
        }
        @unlink($origem);
        @unlink($destino);
    }
}
