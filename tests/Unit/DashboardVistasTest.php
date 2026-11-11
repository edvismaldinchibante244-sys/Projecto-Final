<?php
declare(strict_types=1);

namespace Nivora\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Estrutura do dashboard depois da Fase C:
 * pagina curta -> controlador (dados) -> layout -> vista do perfil -> partes.
 */
final class DashboardVistasTest extends TestCase
{
    private const VISTAS = NIVORA_ROOT . '/app/views/dashboard';

    public function testPaginaContinuaCurta(): void
    {
        $linhas = count(file(NIVORA_ROOT . '/src/public/dashboard.php'));
        $this->assertLessThan(100, $linhas, 'dashboard.php deve so autenticar, preparar e escolher a vista; o HTML vive em app/views/dashboard.');
    }

    public function testCadaPerfilTemVistaEALayoutAEscolhe(): void
    {
        $pagina = (string)file_get_contents(NIVORA_ROOT . '/src/public/dashboard.php');
        foreach (['admin', 'gerente', 'garcom', 'caixa'] as $perfil) {
            $this->assertFileExists(self::VISTAS . "/{$perfil}.php");
            $this->assertStringContainsString("\$dashboardVista = '{$perfil}';", $pagina);
        }
        $this->assertStringContainsString("'/views/dashboard/' . \$dashboardVista . '.php'", (string)file_get_contents(self::VISTAS . '/layout.php'));
    }

    public function testPartesIncluidasExistemENenhumaFicaOrfa(): void
    {
        $usadas = [];
        foreach (glob(self::VISTAS . '/*.php') ?: [] as $vista) {
            preg_match_all("#/partes/([a-z_]+)\.php#", (string)file_get_contents($vista), $m);
            foreach ($m[1] as $parte) {
                $this->assertFileExists(self::VISTAS . "/partes/{$parte}.php", basename($vista) . " inclui uma parte que nao existe");
                $usadas[$parte] = true;
            }
        }
        foreach (glob(self::VISTAS . '/partes/*.php') ?: [] as $ficheiro) {
            $this->assertArrayHasKey(basename($ficheiro, '.php'), $usadas, basename($ficheiro) . ' nao e usada por nenhuma vista');
        }
    }

    public function testVistasNaoFazemConsultasSql(): void
    {
        $ficheiros = array_merge(glob(self::VISTAS . '/*.php') ?: [], glob(self::VISTAS . '/partes/*.php') ?: []);
        $this->assertNotEmpty($ficheiros);
        foreach ($ficheiros as $ficheiro) {
            $codigo = (string)file_get_contents($ficheiro);
            $this->assertDoesNotMatchRegularExpression('/->\s*(prepare|query|exec)\s*\(/', $codigo, basename($ficheiro) . ': consultas pertencem ao DashboardService.');
            $this->assertStringNotContainsString('__DIR__ . \'/includes', $codigo, basename($ficheiro) . ': use DASHBOARD_PUBLIC_DIR para ficheiros de src/public.');
        }
    }
}
