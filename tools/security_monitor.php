<?php
declare(strict_types=1);

/**
 * Security monitor (SIEM-ready).
 *
 * Usage:
 *   php V00/tools/security_monitor.php
 *   php V00/tools/security_monitor.php --json
 *   php V00/tools/security_monitor.php --minutes=15 --threshold=20
 */

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Unable to resolve project root.\n");
    exit(2);
}

$opts = getopt('', ['json', 'minutes::', 'threshold::']);
$asJson = isset($opts['json']);
$minutes = max(1, (int)($opts['minutes'] ?? 10));
$threshold = max(1, (int)($opts['threshold'] ?? 25));

$logFile = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'security.log';
$alertFile = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'security_alerts.log';

if (!is_file($logFile)) {
    $out = ['ok' => false, 'message' => 'security.log not found', 'path' => $logFile];
    echo $asJson ? json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL : "security.log not found\n";
    exit(1);
}

$cutoff = time() - ($minutes * 60);
$counts = [];
$recent = 0;

$fh = fopen($logFile, 'rb');
if ($fh === false) {
    echo $asJson ? json_encode(['ok' => false, 'message' => 'cannot open security.log'], JSON_PRETTY_PRINT) . PHP_EOL : "cannot open security.log\n";
    exit(1);
}

while (($line = fgets($fh)) !== false) {
    $decoded = json_decode($line, true);
    if (!is_array($decoded)) {
        continue;
    }
    $event = (string)($decoded['event'] ?? 'unknown');
    $timeRaw = (string)($decoded['time'] ?? '');
    $ts = strtotime($timeRaw);
    if ($ts === false || $ts < $cutoff) {
        continue;
    }
    $recent++;
    $counts[$event] = (int)($counts[$event] ?? 0) + 1;
}
fclose($fh);

arsort($counts);
$top = array_slice($counts, 0, 10, true);
$suspiciousKeys = [
    'waf_blocked_request',
    'authorization_denied',
    'tenant_violation',
    'api_rate_limited',
    'login_rate_limited',
    'login_failed',
    'csrf_origin_denied',
    'password_reset_rate_limited',
];

$suspiciousTotal = 0;
foreach ($suspiciousKeys as $k) {
    $suspiciousTotal += (int)($counts[$k] ?? 0);
}

$isAlert = $suspiciousTotal >= $threshold;
$report = [
    'ok' => !$isAlert,
    'generated_at' => date('c'),
    'window_minutes' => $minutes,
    'threshold' => $threshold,
    'recent_events' => $recent,
    'suspicious_total' => $suspiciousTotal,
    'top_events' => $top,
];

if ($isAlert) {
    $line = json_encode([
        'time' => date('c'),
        'level' => 'CRITICAL',
        'event' => 'security_monitor_threshold_exceeded',
        'window_minutes' => $minutes,
        'threshold' => $threshold,
        'suspicious_total' => $suspiciousTotal,
        'top_events' => $top,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @file_put_contents($alertFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);

    $notifyHelper = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'security_alert_notify.php';
    if (is_file($notifyHelper)) {
        require_once $notifyHelper;
        if (function_exists('security_alert_notify_from_monitor')) {
            $report['notify'] = security_alert_notify_from_monitor($report);
        }
    }
}

if ($asJson) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo "Security Monitor\n";
    echo "Window: {$minutes} min | Suspicious: {$suspiciousTotal} | Threshold: {$threshold}\n";
    foreach ($top as $event => $qty) {
        echo "- {$event}: {$qty}\n";
    }
    if ($isAlert) {
        echo "ALERT: threshold exceeded\n";
    }
}

exit($isAlert ? 1 : 0);

