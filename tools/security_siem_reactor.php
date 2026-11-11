<?php
declare(strict_types=1);

/**
 * SIEM reactor (one-shot, cron friendly).
 *
 * Exemplo:
 *   php V00/tools/security_siem_reactor.php --minutes=10 --invalid-threshold=30 --block-minutes=30
 */

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "root not found\n");
    exit(2);
}

require_once $root . '/app/src/config/security_ip_blocklist.php';

$opts = getopt('', ['minutes::', 'invalid-threshold::', 'block-minutes::', 'json']);
$minutes = max(1, (int)($opts['minutes'] ?? 10));
$invalidThreshold = max(1, (int)($opts['invalid-threshold'] ?? 30));
$blockMinutes = max(1, (int)($opts['block-minutes'] ?? 30));
$asJson = isset($opts['json']);

$securityLog = $root . '/app/storage/logs/security.log';
$alertsLog = $root . '/app/storage/logs/security_alerts.log';

if (!is_file($securityLog)) {
    $out = ['ok' => false, 'message' => 'security.log not found'];
    echo $asJson ? json_encode($out, JSON_PRETTY_PRINT) . PHP_EOL : "security.log not found\n";
    exit(1);
}

$cutoff = time() - ($minutes * 60);
$invalidEvents = ['login_failed', 'login_rate_limited', 'password_reset_rate_limited'];
$countByIp = [];

$fh = fopen($securityLog, 'rb');
if ($fh === false) {
    echo "cannot open security.log\n";
    exit(1);
}

while (($line = fgets($fh)) !== false) {
    $row = json_decode($line, true);
    if (!is_array($row)) {
        continue;
    }
    $event = (string)($row['event'] ?? '');
    if (!in_array($event, $invalidEvents, true)) {
        continue;
    }
    $ts = strtotime((string)($row['time'] ?? ''));
    if ($ts === false || $ts < $cutoff) {
        continue;
    }
    $ip = trim((string)($row['ip'] ?? ''));
    if ($ip === '') {
        continue;
    }
    $countByIp[$ip] = (int)($countByIp[$ip] ?? 0) + 1;
}
fclose($fh);

$blocklist = security_ip_blocklist_read();
$indexed = [];
foreach ($blocklist as $row) {
    $ip = (string)($row['ip'] ?? '');
    if ($ip !== '') {
        $indexed[$ip] = $row;
    }
}

$now = time();
$blockedNow = [];
foreach ($countByIp as $ip => $qty) {
    if ($qty < $invalidThreshold) {
        continue;
    }
    $until = $now + ($blockMinutes * 60);
    $indexed[$ip] = [
        'ip' => $ip,
        'reason' => 'siem_threshold_invalid_attempts',
        'count' => $qty,
        'window_minutes' => $minutes,
        'blocked_until' => $until,
        'blocked_at' => $now,
    ];
    $blockedNow[] = ['ip' => $ip, 'count' => $qty, 'blocked_until' => $until];
}

security_ip_blocklist_write(array_values($indexed));

$result = [
    'ok' => true,
    'generated_at' => date('c'),
    'window_minutes' => $minutes,
    'invalid_threshold' => $invalidThreshold,
    'block_minutes' => $blockMinutes,
    'blocked_count' => count($blockedNow),
    'blocked' => $blockedNow,
];

if (!empty($blockedNow)) {
    foreach ($blockedNow as $item) {
        $alert = [
            'time' => date('c'),
            'level' => 'CRITICAL',
            'event' => 'siem_auto_block_ip',
            'ip' => $item['ip'],
            'count' => $item['count'],
            'window_minutes' => $minutes,
            'blocked_minutes' => $blockMinutes,
            'contact' => getenv('INCIDENT_CONTACT') ?: null,
        ];
        @file_put_contents($alertsLog, json_encode($alert, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    $notifyHelper = $root . '/app/src/config/security_alert_notify.php';
    if (is_file($notifyHelper)) {
        require_once $notifyHelper;
        if (function_exists('security_alert_notify_from_siem_blocks')) {
            $result['notify'] = security_alert_notify_from_siem_blocks($blockedNow);
        }
    }
}

if ($asJson) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo "SIEM Reactor\n";
    echo "Blocked now: " . count($blockedNow) . "\n";
    foreach ($blockedNow as $b) {
        echo "- {$b['ip']} ({$b['count']} events)\n";
    }
}

exit(0);

