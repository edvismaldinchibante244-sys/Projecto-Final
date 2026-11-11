<?php
declare(strict_types=1);

use Nivora\Infrastructure\Database\MigrationRunner;

require_once __DIR__ . '/../app/src/Infrastructure/Database/MigrationRunner.php';

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Nao foi possivel localizar a raiz do projeto.\n");
    exit(2);
}

$options = getopt('', ['validate', 'status', 'apply', 'confirm:', 'target:', 'allow-non-test', 'json']);
$modes = array_values(array_filter(['validate', 'status', 'apply'], static fn(string $mode): bool => isset($options[$mode])));
if (count($modes) > 1) {
    fwrite(STDERR, "Escolha apenas um modo: --validate, --status ou --apply.\n");
    exit(2);
}

$mode = $modes[0] ?? 'validate';
$asJson = isset($options['json']);
$directory = $root . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'migrations';
$databaseConnected = false;

try {
    if ($mode === 'validate') {
        $definitions = MigrationRunner::loadDefinitions($directory);
        $payload = [
            'ok' => true,
            'mode' => 'validate',
            'database_connected' => false,
            'migration_count' => count($definitions),
            'message' => 'Definicoes validas. Nenhuma ligacao ou alteracao no banco foi realizada.',
        ];
    } else {
        $confirmation = (string)($options['confirm'] ?? '');
        if ($mode === 'apply' && !hash_equals(MigrationRunner::APPLY_CONFIRMATION, $confirmation)) {
            throw new RuntimeException('Confirmacao invalida. Banco nao conectado e nenhuma migracao executada.');
        }
        $targetDatabase = trim((string)($options['target'] ?? ''));
        if ($mode === 'apply' && $targetDatabase === '') {
            throw new RuntimeException('Informe --target com o nome exacto do banco. Banco nao conectado.');
        }

        require_once __DIR__ . '/../app/src/config/database.php';
        $db = (new Database())->getConnection();
        if (!$db instanceof PDO) {
            throw new RuntimeException('Ligacao PDO indisponivel.');
        }
        $databaseConnected = true;

        $runner = new MigrationRunner($db, $directory);
        if ($mode === 'status') {
            $payload = [
                'ok' => true,
                'mode' => 'status',
                'database_connected' => true,
                'migrations' => $runner->status(),
            ];
        } else {
            $payload = [
                'ok' => true,
                'mode' => 'apply',
                'database_connected' => true,
                'migrations' => $runner->apply(
                    $confirmation,
                    $targetDatabase,
                    isset($options['allow-non-test'])
                ),
            ];
        }
    }

    if ($asJson) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        echo strtoupper((string)$payload['mode']) . ': OK' . PHP_EOL;
        echo ($payload['message'] ?? 'Operacao concluida.') . PHP_EOL;
    }
    exit(0);
} catch (Throwable $e) {
    $payload = [
        'ok' => false,
        'mode' => $mode,
        'database_connected' => $databaseConnected,
        'error' => $e->getMessage(),
    ];
    if ($asJson) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        fwrite(STDERR, 'ERRO: ' . $e->getMessage() . PHP_EOL);
    }
    exit(1);
}
