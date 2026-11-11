<?php
declare(strict_types=1);

/**
 * Preflight nao destrutivo da qualidade estrutural do backend.
 *
 * Uso:
 *   php tools/backend_quality_preflight.php
 *   php tools/backend_quality_preflight.php --json
 */

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Nao foi possivel localizar a raiz do projeto.\n");
    exit(2);
}

$asJson = in_array('--json', $argv ?? [], true);
$results = [];
$critical = 0;

$add = static function (string $status, string $name, string $detail) use (&$results, &$critical): void {
    $results[] = ['status' => $status, 'name' => $name, 'detail' => $detail];
    if ($status === 'FAIL') {
        $critical++;
    }
};

$required = [
    'app/src/config/database.php',
    'app/src/config/auth_check.php',
    'app/src/config/security.php',
    'app/src/Infrastructure/Database/MigrationRunner.php',
    'src/public/api',
    'app/composer.lock',
    'db/migrations',
    'services/realtime/package-lock.json',
    'tools/db_migrate.php',
    'tools/inventory_runtime_schema.php',
    'tools/setup_category_migration_sandbox.php',
    'tools/test_categoria_migration_integration.php',
    'tools/setup_brand_theme_migration_sandbox.php',
    'tools/test_brand_theme_migration_integration.php',
    'tools/setup_presence_migration_sandbox.php',
    'tools/test_presence_migration_integration.php',
    'tools/setup_cart_migration_sandbox.php',
    'tools/test_cart_migration_integration.php',
    'tools/test_audit_migration_integration.php',
    'tools/test_plano_downgrade_migration_integration.php',
    'tools/setup_plan_catalog_migration_sandbox.php',
    'tools/test_plano_catalogo_migration_integration.php',
    'tools/test_plano_lifecycle_migration_integration.php',
    'tools/test_offline_operations_migration_integration.php',
    'tools/setup_reserva_qr_migration_sandbox.php',
    'tools/test_reserva_qr_migration_integration.php',
    'tools/test_security_monitor_migration_integration.php',
    'tools/setup_pedido_qr_migration_sandbox.php',
    'tools/test_pedido_qr_migration_integration.php',
    'tools/test_email_env_precedence.php',
];

foreach ($required as $relative) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $add(file_exists($path) ? 'PASS' : 'FAIL', 'Caminho obrigatorio', $relative);
}

/** @return list<string> */
$phpFiles = static function (string $directory): array {
    if (!is_dir($directory)) {
        return [];
    }
    $found = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $found[] = $file->getPathname();
        }
    }
    return $found;
};

$backendFiles = array_merge(
    $phpFiles($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'src'),
    $phpFiles($root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api')
);

$runtimeDdl = 0;
$todoCount = 0;
foreach ($backendFiles as $file) {
    $source = file_get_contents($file);
    if (!is_string($source)) {
        continue;
    }
    $normalizedFile = str_replace('\\', '/', $file);
    if (!str_contains($normalizedFile, '/Infrastructure/Database/')) {
        $runtimeDdl += preg_match_all('/\b(?:CREATE\s+TABLE|ALTER\s+TABLE|CREATE\s+INDEX|ADD\s+COLUMN)\b/i', $source);
    }
    $todoCount += preg_match_all('/\b(?:TODO|FIXME|HACK)\b/i', $source);
}

$add('PASS', 'Arquivos PHP analisados', (string)count($backendFiles));
$add($runtimeDdl > 0 ? 'WARN' : 'PASS', 'DDL durante requisicoes', $runtimeDdl . ' ocorrencia(s); mover gradualmente para db/migrations.');
$add($todoCount > 0 ? 'WARN' : 'PASS', 'Marcadores pendentes', $todoCount . ' ocorrencia(s) TODO/FIXME/HACK.');

$appApi = glob($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . '*.php') ?: [];
$publicApi = glob($root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . '*.php') ?: [];
$appNames = array_map('basename', $appApi);
$publicNames = array_map('basename', $publicApi);
$duplicates = array_values(array_intersect($appNames, $publicNames));
sort($duplicates);
$add(
    $duplicates === [] ? 'PASS' : 'WARN',
    'APIs com nomes duplicados',
    $duplicates === [] ? 'Nenhuma.' : implode(', ', $duplicates)
);

$databaseSource = file_get_contents($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php');
$productionDebugGuard = is_string($databaseSource)
    && str_contains($databaseSource, 'DB_DEBUG/DB_DEBUG_DUMP ignorado em producao')
    && str_contains($databaseSource, 'if ($this->isProduction())');
$add(
    $productionDebugGuard ? 'PASS' : 'FAIL',
    'Bloqueio de debug do banco em producao',
    $productionDebugGuard ? 'Ativo.' : 'Protecao nao encontrada.'
);

$testScripts = glob($root . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'test_*.php') ?: [];
$add(
    count($testScripts) > 0 ? 'PASS' : 'WARN',
    'Scripts de teste existentes',
    count($testScripts) . ' script(s); ainda e recomendada uma suite integrada com banco isolado.'
);

$payload = [
    'ok' => $critical === 0,
    'generated_at' => date(DATE_ATOM),
    'critical_failures' => $critical,
    'summary' => [
        'php_files' => count($backendFiles),
        'runtime_ddl' => $runtimeDdl,
        'todo_markers' => $todoCount,
        'duplicate_api_names' => count($duplicates),
    ],
    'results' => $results,
];

if ($asJson) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo "Backend Quality Preflight\n\n";
    foreach ($results as $row) {
        echo '[' . $row['status'] . '] ' . $row['name'] . ': ' . $row['detail'] . PHP_EOL;
    }
    echo PHP_EOL . 'Falhas criticas: ' . $critical . PHP_EOL;
}

exit($critical === 0 ? 0 : 1);
