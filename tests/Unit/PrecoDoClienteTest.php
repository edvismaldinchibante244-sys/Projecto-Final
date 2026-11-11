<?php
declare(strict_types=1);

namespace Nivora\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Regressao: o cliente nao pode definir o preco de um pedido.
 * Antes, _preco_override/_subtotal_override enviados no pedido HTTP eram
 * aceites (pedido_novo.php e o cardapio QR) - era possivel pedir por 0 MZN.
 */
final class PrecoDoClienteTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once APP_BASE . '/src/config/promocao_schema.php';
    }

    public function testCamposInternosEnviadosPeloClienteSaoRemovidos(): void
    {
        // Linhas sem promocao nao tocam na base: basta um PDO que nunca liga.
        $db = new class extends PDO {
            public function __construct()
            {
            }
        };
        $linhas = promocao_expandir_linhas($db, 1, [
            ['id' => 5, 'qtd' => 10, '_preco_override' => 0, '_subtotal_override' => 0, '_promocao_nome' => 'X'],
            ['produto_id' => 7, 'quantidade' => 2, 'observacao' => 'sem sal', '_promocao_id' => 99],
        ]);

        $this->assertSame([
            ['id' => 5, 'qtd' => 10],
            ['produto_id' => 7, 'quantidade' => 2, 'observacao' => 'sem sal'],
        ], $linhas);
    }

    /** @dataProvider linhasInvalidas */
    public function testLimpezaToleraEntradasEstranhas($entrada, array $esperado): void
    {
        $this->assertSame($esperado, pedido_linha_entrada_limpar($entrada));
    }

    public static function linhasInvalidas(): array
    {
        return [
            'nao e array' => ['texto', []],
            'null' => [null, []],
            'chave numerica' => [[0 => 'x', 'id' => 1], ['id' => 1]],
            'chave vazia' => [['' => 1, 'qtd' => 2], ['qtd' => 2]],
        ];
    }
}
