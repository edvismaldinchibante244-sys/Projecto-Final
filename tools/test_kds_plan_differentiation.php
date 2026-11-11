<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$plan = (string)file_get_contents($root . '/src/public/includes/kds_plan.php');
$cozinha = (string)file_get_contents($root . '/src/public/cozinha.php');
$bar = (string)file_get_contents($root . '/src/public/bar.php');
$intel = (string)file_get_contents($root . '/src/public/includes/kds_empresarial_intel.php');
$css = (string)file_get_contents($root . '/src/public/css/kds-command.css');

$checks = 0;
$assert = static function (bool $cond, string $msg) use (&$checks): void {
    $checks++;
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
};

$assert(str_contains($plan, "\$plano_atual === 'EMPRESARIAL'"), 'deve existir bloco empresarial');
$assert(str_contains($plan, "\$kdsIntelEnabled = true;"), 'empresarial deve ativar inteligencia KDS');
$assert(str_contains($plan, "\$plano_atual === 'PROFISSIONAL'"), 'deve existir bloco profissional');
$assert(str_contains($plan, "\$kdsIntelEnabled = false;"), 'profissional nao deve usar faixa empresarial');
$assert(str_contains($plan, "\$kdsShowSummary = true;"), 'profissional deve manter resumo operacional');
$assert(str_contains($plan, "'intelligent-command'"), 'deve declarar layout empresarial explicito');
$assert(str_contains($plan, "'operational-kds'"), 'deve declarar layout profissional explicito');
$assert(str_contains($plan, "'simple-list'"), 'deve preservar layout basico explicito');
$assert(str_contains($plan, "\$kdsHasCriticalColumn = \$kdsLayout === 'intelligent-command';"), 'coluna critica deve ser exclusiva do empresarial');
$assert(str_contains($cozinha, 'Modo <strong>Operacional</strong>'), 'cozinha profissional deve mostrar modo operacional');
$assert(str_contains($bar, 'Modo <strong>Operacional</strong>'), 'bar profissional deve mostrar modo operacional');
$assert(str_contains($cozinha, 'Comando Inteligente · Cozinha'), 'cozinha empresarial deve ter identidade de comando inteligente');
$assert(str_contains($bar, 'Comando Inteligente · Bar'), 'bar empresarial deve ter identidade de comando inteligente');
$assert(str_contains($cozinha, "!empty(\$kdsHasCriticalColumn) ? \$preparoRegular : (\$grupos['em_preparo'] ?? [])"), 'cozinha profissional deve manter criticos em preparo');
$assert(str_contains($bar, "!empty(\$kdsHasCriticalColumn) ? \$preparoRegular : (\$grupos['em_preparo'] ?? [])"), 'bar profissional deve manter criticos em preparo');
$assert(str_contains($cozinha, '!empty($kdsShowItemId)'), 'cozinha deve condicionar ID do item por plano');
$assert(str_contains($bar, '!empty($kdsShowItemId)'), 'bar deve condicionar ID do item por plano');
$assert(str_contains($cozinha, 'include __DIR__ . \'/includes/kds_empresarial_intel.php\''), 'cozinha deve manter include empresarial condicional');
$assert(str_contains($bar, 'include __DIR__ . \'/includes/kds_empresarial_intel.php\''), 'bar deve manter include empresarial condicional');
$assert(str_contains($intel, "(\$plano_atual ?? '') !== 'EMPRESARIAL'"), 'faixa inteligente deve recusar planos nao empresariais');
$assert(str_contains($intel, 'kds-emp-intel-slim'), 'empresarial deve usar faixa fina (uma frase) no topo');
$assert(str_contains($intel, '$kdsRecommendation'), 'empresarial deve recomendar proxima acao na faixa');
$assert(str_contains($cozinha, 'pedido_observacao') && str_contains($cozinha, 'kds-card-obs'), 'cozinha deve mostrar observacoes do pedido e do item');
$assert(str_contains($bar, 'pedido_observacao') && str_contains($bar, 'kds-card-obs'), 'bar deve mostrar observacoes do pedido e do item');
$assert(str_contains($intel, 'Na fila'), 'empresarial deve mostrar em grande o numero de itens na fila');
$assert(!str_contains($intel, 'Painel executivo'), 'painel executivo repetido foi substituido pela faixa fina');
$assert(str_contains($intel, 'data-kds-locate-priority'), 'empresarial deve oferecer CTA para localizar prioridade');
$assert(str_contains($intel, "card.scrollIntoView({ behavior: 'smooth', block: 'center' })"), 'CTA deve rolar ate o card prioritario');
$assert(str_contains($intel, ".card-item[data-id=\"' + itemId + '\"]"), 'CTA deve localizar card pelo ID do item');
$assert(str_contains($css, '.kds-tier-enterprise:not(.kds-mode-simple) [data-kds-intel]'), 'novo CSS inteligente deve estar restrito ao empresarial e data-kds-intel');
$assert(!str_contains($css, 'body.kds-command-v2:not(.kds-mode-simple) .kds-risk'), 'barra de risco nao pode ter CSS fora do gate empresarial');
$assert(!str_contains($css, 'body.kds-command-v2:not(.kds-mode-simple) .kds-executive'), 'painel executivo nao pode ter CSS fora do gate empresarial');
$assert(str_contains($css, '.kds-layout-operational-kds'), 'CSS deve declarar layout profissional de duas colunas');
$assert(str_contains($css, '.kds-layout-intelligent-command'), 'CSS deve declarar layout empresarial de tres colunas');
$assert(!str_contains($cozinha, 'setInterval(() => atualizarBoard(), 2000)'), 'cozinha nao deve usar polling de 2s');
$assert(!str_contains($bar, 'setInterval(() => atualizarBoard(), 2000)'), 'bar nao deve usar polling de 2s');
$assert(str_contains($cozinha, 'NvOperacao.vigiar({ intervalo: 4000'), 'cozinha deve verificar novidades a cada 4s (verificacao leve)');
$assert(str_contains($bar, 'NvOperacao.vigiar({ intervalo: 4000'), 'bar deve verificar novidades a cada 4s (verificacao leve)');

echo "OK: diferenciacao KDS por plano validada ({$checks} verificacoes).\n";
