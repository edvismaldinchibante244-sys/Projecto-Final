<?php

$root = dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $content = file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($content) ? $content : '';
};

$checks = [];
$check = static function (string $name, bool $passed) use (&$checks): void {
    $checks[$name] = $passed;
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $name . PHP_EOL;
};

$printModule = $read('src/public/js/receipt_print.js');
$offline = $read('src/public/js/offline_sync.js');
$serviceWorker = $read('src/public/service-worker.js');
$receipt = $read('src/public/comprovante.php');

$check('hidden_iframe_print_module', str_contains($printModule, "document.createElement('iframe')"));
$check('duplicate_receipt_guard', str_contains($printModule, 'wasPrintedInThisSession'));
$check('offline_completion_listener', str_contains($printModule, 'offline-sync-operation-done'));
$check('offline_completion_event', str_contains($offline, 'offline-sync-operation-done'));
$check('thermal_80_or_58', str_contains($receipt, '$paper_width') && str_contains($receipt, '@page'));
$check('literal_crlf_removed', !str_contains($receipt, '`r`n'));
$check('service_worker_caches_print_module', str_contains($serviceWorker, "'./js/receipt_print.js'"));

$offlinePages = [
    'admin.php', 'caixa.php', 'bar.php', 'caixa_mesas.php',
    'garcom_mesas.php', 'cozinha.php', 'novo_pedido.php', 'pedidos.php',
];
foreach ($offlinePages as $page) {
    $source = $read('src/public/' . $page);
    $printPos = strpos($source, 'js/receipt_print.js');
    $offlinePos = strpos($source, 'js/offline_sync.js');
    $check('print_before_offline_' . $page, $printPos !== false && $offlinePos !== false && $printPos < $offlinePos);
}

$vendas = $read('src/public/vendas.php');
$check('pdv_print_module_loaded', strpos($vendas, 'js/receipt_print.js') < strpos($vendas, 'js/pdv.js'));
$check('history_reprint_uses_module', substr_count($vendas, 'ReceiptPrint.reprint(') >= 2);
$check('caixa_mesas_uses_module', str_contains($read('src/public/caixa_mesas.php'), 'ReceiptPrint.print(data.venda_id)'));
// O JavaScript de pedidos.php vive em js/pedidos/ (Fase D do plano de divisao).
$pedidosJs = $read('src/public/pedidos.php');
foreach (glob($root . '/src/public/js/pedidos/*.js') ?: [] as $f) {
    $pedidosJs .= "\n" . (string)file_get_contents($f);
}
$check('pedidos_uses_module', str_contains($pedidosJs, 'ReceiptPrint.print(data.venda_id)'));
$check('pdv_uses_module', str_contains($read('src/public/js/pdv.js'), 'window.ReceiptPrint.print(id)'));

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
echo PHP_EOL . (count($checks) - count($failed)) . '/' . count($checks) . ' checks passed.' . PHP_EOL;
exit($failed === [] ? 0 : 1);
