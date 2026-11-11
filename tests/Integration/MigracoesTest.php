<?php
declare(strict_types=1);

namespace Nivora\Tests\Integration;

use Nivora\Tests\Support\TestDb;
use PHPUnit\Framework\TestCase;

/** As migracoes estao todas aplicadas na base de teste e sao idempotentes. */
final class MigracoesTest extends TestCase
{
    public function testDefinicoesValidas(): void
    {
        $saida = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(NIVORA_ROOT . '/tools/db_migrate.php') . ' --validate --json');
        $json = json_decode((string)$saida, true);
        $this->assertTrue($json['ok'] ?? false, (string)$saida);
    }

    public function testTodasAplicadasSemChecksumAlterado(): void
    {
        TestDb::exigir($this);
        $saida = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(NIVORA_ROOT . '/tools/db_migrate.php') . ' --status --json');
        $json = json_decode((string)$saida, true);
        $this->assertTrue($json['ok'] ?? false, (string)$saida);
        foreach ($json['migrations'] as $m) {
            $this->assertSame('applied', $m['status'], $m['id'] . ' nao esta aplicada (ou o ficheiro foi alterado depois de aplicado).');
        }
    }
}
