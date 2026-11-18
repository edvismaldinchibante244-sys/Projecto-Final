<?php
declare(strict_types=1);

namespace Nivora\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regressao: os emails de notificacao assinavam com as cores do planos.php
 * (Empresarial roxo) enquanto o painel usava as do tokens.css (Empresarial
 * laranja). O cliente via uma cor no sistema e recebia outra no email.
 *
 * O PHP nao le CSS a cada email, por isso as cores estao escritas nos dois
 * sitios. Este teste e o que impede os dois sitios de se separarem: le o
 * tokens.css e compara com o plano_cores.php.
 */
final class PlanoCoresTest extends TestCase
{
    /** @return array<string,array{0:string}> */
    public static function planos(): array
    {
        return [
            'basico' => ['basico'],
            'profissional' => ['profissional'],
            'empresarial' => ['empresarial'],
        ];
    }

    private function doCss(string $plano): array
    {
        $css = (string)file_get_contents(APP_BASE . '/../src/public/css/tokens.css');
        $padrao = '/body\.plan-theme-' . preg_quote($plano, '/')
            . '\s*\{[^}]*--nv-plano:\s*(#[0-9a-fA-F]{3,8})\s*;'
            . '[^}]*--nv-plano-suave:\s*(#[0-9a-fA-F]{3,8})\s*;/';
        $this->assertSame(1, preg_match($padrao, $css, $m), "Nao encontrei o tema do plano {$plano} no tokens.css");

        return ['cor' => strtolower($m[1]), 'suave' => strtolower($m[2])];
    }

    /** @dataProvider planos */
    public function testCorDoEmailIgualAoTemaDoPainel(string $plano): void
    {
        require_once APP_BASE . '/src/config/plano_cores.php';

        $css = $this->doCss($plano);
        $php = plano_cor_info($plano);

        $this->assertSame(
            $css['cor'],
            strtolower($php['cor']),
            "A cor do plano {$plano} nos emails nao e a mesma do painel. "
            . 'Mudou o tokens.css? Entao mude tambem o app/src/config/plano_cores.php.'
        );
        $this->assertSame(
            $css['suave'],
            strtolower($php['suave']),
            "A cor suave do plano {$plano} nos emails nao e a mesma do painel."
        );
    }

    /**
     * O planos.php tem um campo 'cor' por plano que hoje ninguem le, mas que
     * esteve anos desalinhado e enganou quem foi la busca-lo. Enquanto existir,
     * tem de dizer a verdade.
     *
     * @dataProvider planos
     */
    public function testCorDoPlanosPhpIgualAoTema(string $plano): void
    {
        $def = include APP_BASE . '/src/config/planos.php';
        $chave = strtoupper($plano);
        $this->assertArrayHasKey($chave, $def);

        $this->assertSame(
            $this->doCss($plano)['cor'],
            strtolower((string)($def[$chave]['cor'] ?? '')),
            "A cor do plano {$chave} no planos.php nao e a do painel. "
            . 'Alinhe-a com o tokens.css, ou apague o campo se ja nao servir para nada.'
        );
    }

    public function testPlanoDesconhecidoNaoRebenta(): void
    {
        require_once APP_BASE . '/src/config/plano_cores.php';

        foreach (['', 'lixo', 'ENTERPRISE', 'Básico'] as $entrada) {
            $info = plano_cor_info($entrada);
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $info['cor']);
            $this->assertNotSame('', $info['nome']);
        }

        $this->assertSame('EMPRESARIAL', plano_cor_info('ENTERPRISE')['codigo']);
        $this->assertSame('BASICO', plano_cor_info('Básico')['codigo']);
        $this->assertSame('BASICO', plano_cor_info('lixo')['codigo']);
    }
}
