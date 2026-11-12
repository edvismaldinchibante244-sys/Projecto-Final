<?php
require_once __DIR__ . '/../app_base.php';

/**
 * Cron: backups automáticos pendentes (diário/horário conforme backup_configuracoes).
 *
 * Exemplos (a partir da pasta V00):
 *   cd V00
 *   php src/public/cron/backup_automatico.php --json
 *
 * Ou a partir da raiz do repo:
 *   php V00/src/public/cron/backup_automatico.php --json
 *
 * Agendar (Windows Task Scheduler / cron): a cada hora.
 */

include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/plano_check.php';
include_once APP_BASE . '/src/config/backup_helper.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    // Proteção básica se chamado via HTTP: token opcional
    $token = trim((string)(getenv('CRON_HTTP_TOKEN') ?: ''));
    $given = trim((string)($_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? ''));
    if ($token === '' || !hash_equals($token, $given)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
        exit(1);
    }
}

function backup_cron_arg_value(string $name, $default = null)
{
    global $argv;
    foreach (($argv ?? []) as $arg) {
        if (strpos((string)$arg, $name . '=') === 0) {
            return substr((string)$arg, strlen($name) + 1);
        }
    }
    return $default;
}

function backup_cron_has_arg(string $name): bool
{
    global $argv;
    return in_array($name, $argv ?? [], true);
}

$dryRun = backup_cron_has_arg('--dry-run');
$asJson = backup_cron_has_arg('--json') || !$isCli;
$ridRaw = backup_cron_arg_value('--restaurante-id');
$apenasRid = $ridRaw !== null ? max(0, (int)$ridRaw) : null;
if ($apenasRid !== null && $apenasRid <= 0) {
    $apenasRid = null;
}

$started = microtime(true);
$db = (new Database())->getConnection();
if (!$db instanceof PDO) {
    $payload = ['success' => false, 'message' => 'Falha de conexao com banco'];
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

try {
    backup_ensure_tables_exist($db);

    if ($dryRun) {
        $payload = [
            'success' => true,
            'dry_run' => true,
            'message' => 'Dry-run: nenhuma geracao executada. Use sem --dry-run para processar pendentes.',
            'elapsed_ms' => (int)round((microtime(true) - $started) * 1000),
        ];
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
        exit(0);
    }

    $resultado = backup_processar_pendentes($db, $apenasRid, 'cron');
    $payload = [
        'success' => true,
        'generated_at' => date('c'),
        'executados' => (int)($resultado['executados'] ?? 0),
        'falhas' => (int)($resultado['falhas'] ?? 0),
        'ignorados' => (int)($resultado['ignorados'] ?? 0),
        'detalhes' => $resultado['detalhes'] ?? [],
        'elapsed_ms' => (int)round((microtime(true) - $started) * 1000),
    ];

    $logDir = nv_storage('logs');
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    @file_put_contents(
        $logDir . DIRECTORY_SEPARATOR . 'backup_cron.log',
        json_encode($payload, JSON_UNESCAPED_UNICODE) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(((int)($resultado['falhas'] ?? 0) > 0) ? 1 : 0);
} catch (Throwable $e) {
    $payload = [
        'success' => false,
        'message' => 'Erro no backup automatico',
        'error' => $e->getMessage(),
    ];
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}
