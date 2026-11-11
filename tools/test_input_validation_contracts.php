<?php

$root = dirname(__DIR__);
require_once $root . '/src/config/input_validation.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(input_enum('pendentes', ['', 'pendentes'], true) === 'pendentes', 'enum valido');
$assert(input_enum('todos', ['', 'pendentes'], true) === null, 'enum fora da allowlist');
$assert(input_positive_int('12') === 12, 'id positivo');
$assert(input_positive_int('12x') === null, 'id com sufixo');
$assert(input_positive_int('-1') === null, 'id negativo');
$assert(input_bounded_int('999', 1, 999) === 999, 'inteiro no limite');
$assert(input_bounded_int('1000', 1, 999) === null, 'inteiro acima do limite');
$assert(input_iso_date('2026-02-28') === '2026-02-28', 'data valida');
$assert(input_iso_date('2026-02-30') === null, 'data inexistente');
$assert(input_search_token("pedido.criado", 80) === 'pedido.criado', 'evento valido');
$assert(input_search_token("evento\nforjado", 80) === null, 'evento com controle');

$contracts = [
    'src/public/pedidos.php' => ["input_enum(\$filtroRaw", "header('Location: pedidos.php')"],
    'src/public/relatorio_reservas.php' => ['input_iso_date(', "header('Location: relatorio_reservas.php')"],
    'src/public/admin_auditoria.php' => ['input_search_token(', 'input_bounded_int('],
    'src/public/backup.php' => ['input_positive_int(', 'AND restaurante_id = :rid'],
    'src/public/api/operacoes_empresariais.php' => ['Parametro action invalido.', 'input_positive_int(', 'input_bounded_int('],
    'src/public/api/pedidos_snapshot.php' => ['Parametro filter invalido.'],
    'src/public/api/pedido_sse.php' => ['http_response_code(400)'],
];
foreach ($contracts as $relative => $needles) {
    $source = (string)file_get_contents($root . '/' . $relative);
    foreach ($needles as $needle) {
        $assert(strpos($source, $needle) !== false, $relative . ' sem contrato ' . $needle);
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Input validation contracts: OK" . PHP_EOL;
