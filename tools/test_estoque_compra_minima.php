<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/src/config/estoque_inteligente.php';

$checks = 0;

$assert = static function (bool $cond, string $msg) use (&$checks): void {
    $checks++;
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
};

$assert(estoque_compra_minima_relevante_base('g') === 100.0, 'minimo para g deve ser 100');
$assert(estoque_compra_minima_relevante_base('ml') === 100.0, 'minimo para ml deve ser 100');
$assert(estoque_compra_minima_relevante_base('un') === 1.0, 'minimo para unidade deve ser 1');

$assert(estoque_normalizar_sugestao_compra(0.238, 'g') === 0.0, '0,238 g nao deve virar compra');
$assert(estoque_normalizar_sugestao_compra(99.999, 'g') === 0.0, 'menos de 100 g nao deve virar compra');
$assert(estoque_normalizar_sugestao_compra(100.0, 'g') === 100.0, '100 g deve virar compra');
$assert(estoque_normalizar_sugestao_compra(0.5, 'un') === 0.0, 'meia unidade nao deve virar compra');
$assert(estoque_normalizar_sugestao_compra(1.0, 'un') === 1.0, 'uma unidade deve virar compra');

$ui = (string)file_get_contents(__DIR__ . '/../src/public/estoque_inteligente.php');
// O JavaScript da pagina vive em js/estoque_inteligente/ (Fase D do plano de divisao).
foreach (glob(__DIR__ . '/../src/public/js/estoque_inteligente/*.js') ?: [] as $f) {
    $ui .= "\n" . (string)file_get_contents($f);
}
$assert(is_string($ui) && str_contains($ui, 'function fmtCompraSugerida'), 'UI deve ter formatador de compra sugerida');
$assert(is_string($ui) && str_contains($ui, 'Stock suficiente'), 'UI deve mostrar stock suficiente quando nao ha compra');
$assert(is_string($ui) && str_contains($ui, 'media_prevista_diaria'), 'UI deve usar media prevista diaria quando disponivel');

echo "OK: compra minima de estoque validada ({$checks} verificacoes).\n";
