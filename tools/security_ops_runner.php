<?php
declare(strict_types=1);

/**
 * Security Ops Runner (cron friendly)
 *
 * Executa pipeline operacional:
 * 1) Monitor de eventos
 * 2) Reator SIEM (autoblock)
 * 3) Preflight de seguranca
 * 4) Preflight de operacoes nivel 4
 * 5) Gate objetivo de seguranca/readiness
 *
 * Uso (a partir da pasta V00):
 *   cd V00
 *   php tools/security_ops_runner.php --json
 *
 * Ou a partir da raiz do repo:
 *   php V00/tools/security_ops_runner.php --json
 */

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "root not found\n");
    exit(2);
}

$opts = getopt('', [
    'json',
    'minutes::',
    'threshold::',
    'invalid-threshold::',
    'block-minutes::',
    'with-backup',
    'with-dr-drill',
]);

$asJson = isset($opts['json']);
$minutes = max(1, (int)($opts['minutes'] ?? 10));
$threshold = max(1, (int)($opts['threshold'] ?? 25));
$invalidThreshold = max(1, (int)($opts['invalid-threshold'] ?? 30));
$blockMinutes = max(1, (int)($opts['block-minutes'] ?? 30));
$withBackup = isset($opts['with-backup']);
$withDrDrill = isset($opts['with-dr-drill']);

$logDir = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$statusFile = $logDir . DIRECTORY_SEPARATOR . 'security_ops_status.json';
$runnerLog = $logDir . DIRECTORY_SEPARATOR . 'security_ops_runner.log';

/**
 * @return array{ok:bool,exit_code:int,raw:string,data:array<string,mixed>}
 */
function run_json_tool(string $cmd): array
{
    $out = [];
    $code = 1;
    @exec($cmd, $out, $code);
    $raw = trim(implode("\n", $out));
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = ['raw_output' => $raw];
    }

    return [
        'ok' => $code === 0,
        'exit_code' => $code,
        'raw' => $raw,
        'data' => $data,
    ];
}

$php = PHP_BINARY;
$monitorCmd = escapeshellarg($php) . ' ' . escapeshellarg($root . '/tools/security_monitor.php')
    . ' --json --minutes=' . $minutes . ' --threshold=' . $threshold;
$reactorCmd = escapeshellarg($php) . ' ' . escapeshellarg($root . '/tools/security_siem_reactor.php')
    . ' --json --minutes=' . $minutes . ' --invalid-threshold=' . $invalidThreshold . ' --block-minutes=' . $blockMinutes;
$preflightCmd = escapeshellarg($php) . ' ' . escapeshellarg($root . '/tools/security_preflight.php') . ' --json';
$opsPreflightCmd = escapeshellarg($php) . ' ' . escapeshellarg($root . '/tools/security_ops_preflight.php') . ' --json';
$readinessCmd = escapeshellarg($php) . ' ' . escapeshellarg($root . '/tools/security_readiness_report.php') . ' --json';
$objectiveGateCmd = escapeshellarg($php) . ' ' . escapeshellarg($root . '/tools/security_objective_gate.php') . ' --json';

$monitor = run_json_tool($monitorCmd);
$reactor = run_json_tool($reactorCmd);
$preflight = run_json_tool($preflightCmd);
$opsPreflight = run_json_tool($opsPreflightCmd);
$readiness = run_json_tool($readinessCmd);
$objectiveGate = run_json_tool($objectiveGateCmd);

$backupRun = null;
$drDrill = null;
if ($withBackup) {
    $backupCmd = escapeshellarg($php) . ' ' . escapeshellarg($root . '/src/public/cron/backup_automatico.php') . ' --json';
    $backupRun = run_json_tool($backupCmd);
}
if ($withDrDrill) {
    $drCmd = escapeshellarg($php) . ' ' . escapeshellarg($root . '/tools/dr_restore_drill.php') . ' --json --stamp-env';
    $drDrill = run_json_tool($drCmd);
}

$critical = 0;
if (($preflight['data']['critical_failures'] ?? 0) > 0) {
    $critical++;
}
if (($opsPreflight['data']['critical_failures'] ?? 0) > 0) {
    $critical++;
}
if (($objectiveGate['data']['failed_count'] ?? 0) > 0) {
    $critical++;
}

$summary = [
    'generated_at' => date('c'),
    'ok' => $critical === 0 && $reactor['ok'] && isset($monitor['data']['recent_events']),
    'critical_failures' => $critical,
    'config' => [
        'minutes' => $minutes,
        'threshold' => $threshold,
        'invalid_threshold' => $invalidThreshold,
        'block_minutes' => $blockMinutes,
    ],
    'monitor' => [
        'ok' => $monitor['ok'],
        'exit_code' => $monitor['exit_code'],
        'recent_events' => $monitor['data']['recent_events'] ?? null,
        'suspicious_total' => $monitor['data']['suspicious_total'] ?? null,
        'threshold' => $monitor['data']['threshold'] ?? null,
    ],
    'reactor' => [
        'ok' => $reactor['ok'],
        'exit_code' => $reactor['exit_code'],
        'blocked_count' => $reactor['data']['blocked_count'] ?? 0,
        'blocked' => $reactor['data']['blocked'] ?? [],
    ],
    'preflight' => [
        'ok' => $preflight['ok'],
        'exit_code' => $preflight['exit_code'],
        'critical_failures' => $preflight['data']['critical_failures'] ?? null,
    ],
    'ops_preflight' => [
        'ok' => $opsPreflight['ok'],
        'exit_code' => $opsPreflight['exit_code'],
        'critical_failures' => $opsPreflight['data']['critical_failures'] ?? null,
    ],
    'readiness' => [
        'ok' => $readiness['ok'],
        'exit_code' => $readiness['exit_code'],
        'score' => $readiness['data']['score'] ?? null,
        'status' => $readiness['data']['status'] ?? null,
        'output_file' => $readiness['data']['output_file'] ?? null,
    ],
    'objective_gate' => [
        'ok' => $objectiveGate['ok'],
        'exit_code' => $objectiveGate['exit_code'],
        'failed_count' => $objectiveGate['data']['failed_count'] ?? null,
        'warning_count' => $objectiveGate['data']['warning_count'] ?? null,
        'objective' => $objectiveGate['data']['objective'] ?? null,
    ],
    'backup_cron' => $backupRun === null ? null : [
        'ok' => $backupRun['ok'],
        'exit_code' => $backupRun['exit_code'],
        'executados' => $backupRun['data']['executados'] ?? null,
        'falhas' => $backupRun['data']['falhas'] ?? null,
    ],
    'dr_drill' => $drDrill === null ? null : [
        'ok' => $drDrill['ok'],
        'exit_code' => $drDrill['exit_code'],
        'message' => $drDrill['data']['message'] ?? null,
        'backup_id' => $drDrill['data']['backup_id'] ?? null,
    ],
    'notify' => [
        'monitor' => $monitor['data']['notify'] ?? null,
        'reactor' => $reactor['data']['notify'] ?? null,
    ],
];

@file_put_contents($statusFile, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
@file_put_contents($runnerLog, json_encode($summary, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);

if ($asJson) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo "Security Ops Runner\n";
    echo "Generated: " . $summary['generated_at'] . "\n";
    echo "Critical failures: " . $summary['critical_failures'] . "\n";
    echo "Suspicious: " . (string)($summary['monitor']['suspicious_total'] ?? '-') . "\n";
    echo "Auto-blocked: " . (string)($summary['reactor']['blocked_count'] ?? 0) . "\n";
    echo "Status file: {$statusFile}\n";
}

exit($summary['ok'] ? 0 : 1);
