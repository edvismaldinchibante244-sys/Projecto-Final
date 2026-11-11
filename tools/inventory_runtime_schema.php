<?php
declare(strict_types=1);

/**
 * Inventario somente leitura de DDL ainda presente no codigo de execucao.
 * Uso: php tools/inventory_runtime_schema.php [--json]
 */

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Nao foi possivel localizar a raiz do projeto.\n");
    exit(2);
}

$asJson = in_array('--json', $argv ?? [], true);
$scanRoots = [
    $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'src',
    $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'api',
];
$patterns = [
    'create_table' => '/\bCREATE\s+TABLE\b/i',
    'alter_table' => '/\bALTER\s+TABLE\b/i',
    'create_index' => '/\bCREATE\s+(?:UNIQUE\s+)?INDEX\b/i',
    'add_column' => '/\bADD\s+COLUMN\b/i',
];

$occurrences = [];
foreach ($scanRoots as $scanRoot) {
    if (!is_dir($scanRoot)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($scanRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $normalized = str_replace('\\', '/', $file->getPathname());
        if (str_contains($normalized, '/Infrastructure/Database/')) {
            continue;
        }
        $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            continue;
        }
        foreach ($lines as $index => $line) {
            foreach ($patterns as $type => $pattern) {
                $matches = preg_match_all($pattern, $line);
                for ($i = 0; $i < $matches; $i++) {
                    $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
                    $occurrences[] = [
                        'file' => $relative,
                        'line' => $index + 1,
                        'type' => $type,
                    ];
                }
            }
        }
    }
}

$byFile = [];
$byType = array_fill_keys(array_keys($patterns), 0);
foreach ($occurrences as $item) {
    $byFile[$item['file']] = ($byFile[$item['file']] ?? 0) + 1;
    $byType[$item['type']]++;
}
arsort($byFile);

$payload = [
    'generated_at' => date(DATE_ATOM),
    'read_only' => true,
    'total' => count($occurrences),
    'by_type' => $byType,
    'by_file' => $byFile,
    'occurrences' => $occurrences,
];

if ($asJson) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

echo "Inventario de DDL em runtime (somente leitura)\n";
echo 'Total: ' . count($occurrences) . PHP_EOL;
foreach ($byType as $type => $count) {
    echo '- ' . $type . ': ' . $count . PHP_EOL;
}
echo "\nArquivos com mais ocorrencias:\n";
foreach (array_slice($byFile, 0, 15, true) as $file => $count) {
    echo '- ' . $file . ': ' . $count . PHP_EOL;
}
