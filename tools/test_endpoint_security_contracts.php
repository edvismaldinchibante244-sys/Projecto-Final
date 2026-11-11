<?php

$root = dirname(__DIR__);
$failures = [];

$expectContains = static function (string $relative, array $needles) use ($root, &$failures): void {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $source = is_file($path) ? (string)file_get_contents($path) : '';
    foreach ($needles as $needle) {
        if ($source === '' || strpos($source, $needle) === false) {
            $failures[] = $relative . ' nao contem contrato: ' . $needle;
        }
    }
};

$expectContains('src/public/api/operacoes_empresariais.php', [
    "'roles' => ['ADMIN', 'GARCOM', 'COZINHA', 'BAR', 'CAIXA', 'GERENTE']",
    'resource_require_item_pedido(',
    'resource_require_mesa(',
    'resource_require_produto(',
]);
$expectContains('src/public/api/turno_operacao.php', [
    "'roles' => ['ADMIN', 'GERENTE', 'CAIXA', 'GARCOM', 'COZINHA', 'BAR']",
    'resource_require_usuario(',
]);
foreach (['plano_comprar.php', 'plano_downgrade_solicitar.php', 'plano_downgrade_cancelar.php'] as $file) {
    $expectContains('src/public/api/' . $file, ["'roles' => ['ADMIN', 'GERENTE']"]);
}
$expectContains('src/public/api/support_ticket_message_send.php', [
    'resource_require_support_ticket(',
    'WHERE id = :id AND restaurante_id = :rid',
]);
$internalApiFiles = glob($root . '/app/src/api/*.php') ?: [];
foreach ($internalApiFiles as $path) {
    $file = basename($path);
    if ($file === '.gitkeep') {
        continue;
    }
    $expectContains('app/src/api/' . $file, [
        "require_once __DIR__ . '/../config/internal_api.php'",
        'internal_api_deny_direct_http_execution(__FILE__)',
    ]);
}
$expectContains('app/src/config/api_guard.php', [
    "in_array(\$method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)",
    'csrf_validate_or_json()',
    'security_require_roles(',
    "'restaurante_id' => \$restauranteId",
]);
$expectContains('app/src/config/resource_access.php', [
    'AND {$tenantColumn} = :rid',
    'resource_require_item_pedido(',
    'AND p.restaurante_id = :rid',
]);

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Endpoint security contracts: OK" . PHP_EOL;
