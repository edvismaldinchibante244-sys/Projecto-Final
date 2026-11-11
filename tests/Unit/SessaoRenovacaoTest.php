<?php
declare(strict_types=1);

namespace Nivora\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Renovacao do ID de sessao (security_regenerate_session) com pedidos em paralelo.
 * Regressao: session_regenerate_id(true) apagava a sessao antiga e o pedido que estava a
 * correr ao mesmo tempo ficava sem sessao -> "Acesso nao autorizado" e utilizador desligado.
 */
final class SessaoRenovacaoTest extends TestCase
{
    public function testPedidoEmParaleloNaRenovacaoNaoDesligaOUtilizador(): void
    {
        $pasta = sys_get_temp_dir() . '/nivora_sessoes_' . bin2hex(random_bytes(4));
        mkdir($pasta, 0700, true);
        try {
            $saida = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(NIVORA_ROOT . '/tests/Support/sessao_worker.php') . ' ' . escapeshellarg($pasta) . ' 2>&1');
            $r = json_decode((string)$saida, true);
            $this->assertIsArray($r, (string)$saida);

            $this->assertTrue($r['renovou'], 'a sessao continua a ser renovada a cada 15 minutos');
            $this->assertTrue($r['b_continua_logado'], 'pedido em paralelo com o cookie antigo continua autenticado');
            $this->assertTrue($r['b_nao_renovou_outra_vez'], 'o pedido com o cookie antigo nao renova outra vez');
            $this->assertTrue($r['novo_logado']);
            $this->assertTrue($r['novo_nao_obsoleto']);
            $this->assertFalse($r['antigo_depois_da_graca_logado'], 'cookie antigo usado depois da graca nao serve');
        } finally {
            array_map('unlink', glob($pasta . '/*') ?: []);
            @rmdir($pasta);
        }
    }
}
