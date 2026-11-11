<?php
declare(strict_types=1);

$root = dirname(__DIR__);
// O dashboard esta dividido em pagina, preparacao, servico e vistas (Fases B e C).
$dashboard = '';
foreach (array_merge(
    [$root . '/src/public/dashboard.php', $root . '/app/src/Controller/dashboard_preparar.php', $root . '/app/src/Service/DashboardService.php'],
    glob($root . '/app/views/dashboard/*.php') ?: [],
    glob($root . '/app/views/dashboard/partes/*.php') ?: []
) as $ficheiroDashboard) {
    $dashboard .= (string)file_get_contents($ficheiroDashboard) . "\n";
}
$estoque = (string)file_get_contents($root . '/src/public/estoque_inteligente.php');
foreach (glob($root . '/src/public/js/estoque_inteligente/*.js') ?: [] as $f) {
    $estoque .= "\n" . (string)file_get_contents($f);
}

$checks = 0;
$assert = static function (bool $cond, string $msg) use (&$checks): void {
    $checks++;
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
};

$assert(str_contains($dashboard, '$empEstoqueResumo'), 'dashboard deve calcular resumo humano do estoque');
$assert(str_contains($dashboard, 'Sem risco crítico no momento'), 'dashboard deve ter estado claro sem risco');
$assert(str_contains($dashboard, 'htmlspecialchars($empEstoqueResumo'), 'dashboard deve renderizar resumo escapado');
$assert(str_contains($estoque, 'function resumoSaudeEstoque'), 'estoque deve ter resumo humano do score');
$assert(str_contains($estoque, 'Estavel por cerca de'), 'estoque deve explicar cobertura em linguagem simples');
$assert(str_contains($estoque, 'precisam de compra agora'), 'estoque deve orientar acao quando ha criticos');
$assert(!str_contains($estoque, "criticos + ' críticos · '"), 'estoque nao deve montar subtitulo tecnico antigo');

$assert(str_contains($estoque, 'Estado do estoque'), 'estoque deve usar rotulo compreensivel no card');
$assert(str_contains($estoque, "String(score) + '/100'"), 'estoque deve mostrar score com contexto /100');
$assert(str_contains($estoque, 'Complete ficha tecnica de'), 'estoque deve orientar completar fichas tecnicas');

echo "OK: texto de saude do estoque validado ({$checks} verificacoes).\n";
