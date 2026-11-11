<?php
declare(strict_types=1);

namespace Nivora\Tests\Integration;

use Nivora\Tests\Support\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Regressao: rate limit em ficheiro (pedidos em paralelo passavam todos) e
 * tentativas 2FA nao atomicas.
 */
final class EstadoSegurancaTest extends TestCase
{
    private const WORKER = __DIR__ . '/../Support/worker.php';

    public static function setUpBeforeClass(): void
    {
        require_once APP_BASE . '/src/config/security.php';
        require_once APP_BASE . '/src/config/login_security.php';
        require_once APP_BASE . '/src/config/login_2fa_store.php';
    }

    private function exigirMigracao(): \PDO
    {
        $db = TestDb::exigir($this);
        require_once APP_BASE . '/src/config/migration_state.php';
        if (!migration_esta_aplicada($db, '20261030_1300_estado_seguranca')) {
            $this->markTestSkipped('Migracao 20261030_1300_estado_seguranca nao aplicada na base de teste.');
        }
        return $db;
    }

    public function testRateLimitAguentaPedidosEmParalelo(): void
    {
        $this->exigirMigracao();
        $ip = random_int(1, 249);
        $chave = bin2hex(random_bytes(6));
        $cmds = array_fill(0, 25, ['ratelimit', (string)$ip, 'commit', $chave]);
        $saidas = TestDb::emParalelo(self::WORKER, $cmds);

        $contagem = array_count_values($saidas);
        $this->assertSame(10, $contagem['OK:permitido'] ?? 0, 'Exactamente 10 pedidos (o limite) devem passar. Saidas: ' . json_encode($contagem));
        $this->assertSame(15, $contagem['OK:bloqueado'] ?? 0);
    }

    public function testDesafio2faGuardadoSemSegredosEmClaro(): void
    {
        $db = $this->exigirMigracao();
        $token = bin2hex(random_bytes(24));
        $codigo = '123456';
        $uid = TestDb::restauranteFicticio();
        $this->assertTrue(login_api_write_pending_2fa([
            'token' => $token,
            'code_hash' => login_security_hash_otp($codigo),
            'expires_at' => time() + 300,
            'attempts' => 0,
            'user' => ['id' => $uid, 'email' => 'teste@example.test'],
        ]));

        $linha = $db->query('SELECT * FROM login_2fa_pendentes WHERE usuario_id = ' . (int)$uid)->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($linha);
        $this->assertStringNotContainsString($token, json_encode($linha));
        $this->assertStringNotContainsString('"' . $codigo . '"', (string)$linha['dados']);

        $lido = login_api_read_pending_2fa($token);
        $this->assertSame($uid, (int)$lido['user']['id']);
        $this->assertSame($token, $lido['token']);

        $encontrado = login_api_find_pending_for_user_code($uid, $codigo);
        $this->assertIsArray($encontrado);
        login_api_delete_pending_2fa($encontrado);
        $this->assertNull(login_api_read_pending_2fa($token));
    }

    public function testTentativas2faEmParaleloSaoContadasUmaAUma(): void
    {
        $this->exigirMigracao();
        $token = bin2hex(random_bytes(24));
        login_api_write_pending_2fa([
            'token' => $token, 'code_hash' => login_security_hash_otp('654321'),
            'expires_at' => time() + 300, 'attempts' => 0,
            'user' => ['id' => TestDb::restauranteFicticio()],
        ]);

        $saidas = TestDb::emParalelo(self::WORKER, array_fill(0, 20, ['2fa_falha', '0', 'commit', $token]));
        $numeros = array_map(static fn($s) => (int)substr($s, 3), $saidas);
        sort($numeros);

        // Cada tentativa recebe um numero unico: so as 5 primeiras chegam a verificar o codigo.
        $this->assertSame(range(1, 20), $numeros);
        $this->assertSame(20, (int)login_api_read_pending_2fa($token)['attempts']);
    }
}
