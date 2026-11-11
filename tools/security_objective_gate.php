<?php
declare(strict_types=1);

/**
 * Gate objetivo de seguranca Nivora.
 *
 * Criterios:
 * - 0 falhas criticas no preflight de seguranca/backend/ops
 * - readiness >= 85/100
 * - WAF dentro da cadencia e em modo ativo/bloqueio
 * - restore/DR drill comprovado dentro da cadencia
 * - monitoramento/alertas ativos
 * - pentest sem vulnerabilidades CRITICAL/HIGH pendentes
 *
 * Uso:
 *   php tools/security_objective_gate.php
 *   php tools/security_objective_gate.php --json
 */

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "root not found\n");
    exit(2);
}

$opts = getopt('', ['json']);
$asJson = isset($opts['json']);
$php = PHP_BINARY;

function gate_run_json(string $cmd): array
{
    $out = [];
    $code = 1;
    @exec($cmd, $out, $code);
    $raw = trim(implode("\n", $out));
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = ['raw_output' => $raw];
    }
    return ['ok' => $code === 0, 'exit_code' => $code, 'data' => $data, 'raw' => $raw];
}

function gate_env_value(string $file, string $key): string
{
    if (!is_file($file)) {
        return '';
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return '';
    }
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) === 2 && trim($parts[0]) === $key) {
            return trim($parts[1], " \t\n\r\0\x0B\"'");
        }
    }
    return '';
}

function gate_days_since(?string $dateRaw): ?int
{
    $dateRaw = trim((string)$dateRaw);
    if ($dateRaw === '') {
        return null;
    }
    $ts = strtotime($dateRaw);
    if ($ts === false) {
        return null;
    }
    return max(0, (int)floor((time() - $ts) / 86400));
}

function gate_control(bool $ok, string $name, string $detail, string $severity = 'critical'): array
{
    return [
        'ok' => $ok,
        'status' => $ok ? 'PASS' : strtoupper($severity),
        'name' => $name,
        'detail' => $detail,
    ];
}

function gate_load_governance(string $root): array
{
    $file = $root . '/docs/security/security_governance.json';
    if (!is_file($file)) {
        return [];
    }
    $raw = file_get_contents($file);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($json) ? $json : [];
}

function gate_pentest_open_findings(string $root): array
{
    $files = [
        $root . '/docs/security/pentest_findings.json',
        $root . '/docs/security/PENTEST_FINDINGS.json',
        $root . '/app/storage/logs/pentest_findings.json',
    ];
    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }
        $raw = file_get_contents($file);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            continue;
        }
        $evidenceStatus = strtoupper((string)($data['status'] ?? $data['attestation_status'] ?? ''));
        $approved = in_array($evidenceStatus, ['APPROVED', 'VALIDATED', 'PASS', 'PASSED', 'RETESTED_OK'], true)
            || (($data['approved'] ?? false) === true)
            || (($data['attestation']['approved'] ?? false) === true);
        $items = isset($data['findings']) && is_array($data['findings']) ? $data['findings'] : $data;
        $open = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $sev = strtoupper((string)($item['severity'] ?? $item['risk'] ?? ''));
            $status = strtoupper((string)($item['status'] ?? 'OPEN'));
            if (in_array($sev, ['CRITICAL', 'CRITICA', 'CRITICO', 'HIGH', 'ALTA', 'ALTO'], true)
                && !in_array($status, ['CLOSED', 'FIXED', 'RESOLVED', 'REMEDIATED', 'FECHADO', 'CORRIGIDO', 'RESOLVIDO'], true)) {
                $open[] = $item;
            }
        }
        return ['file' => $file, 'open' => $open, 'approved' => $approved, 'evidence_status' => $evidenceStatus];
    }
    return ['file' => null, 'open' => null, 'approved' => false, 'evidence_status' => ''];
}

function gate_markdown_status(string $file): string
{
    if (!is_file($file)) {
        return '';
    }
    $raw = file_get_contents($file);
    if (!is_string($raw)) {
        return '';
    }
    if (preg_match('/^\s*Status\s*:\s*([A-Z0-9_\-]+)/mi', $raw, $m) === 1) {
        return strtoupper((string)$m[1]);
    }
    return '';
}

$envFile = $root . '/.env';
$controls = [];

$securityPreflight = gate_run_json(escapeshellarg($php) . ' ' . escapeshellarg($root . '/tools/security_preflight.php') . ' --json');
$backendPreflight = gate_run_json(escapeshellarg($php) . ' ' . escapeshellarg($root . '/tools/backend_quality_preflight.php') . ' --json');
$opsPreflight = gate_run_json(escapeshellarg($php) . ' ' . escapeshellarg($root . '/tools/security_ops_preflight.php') . ' --json');
$readiness = gate_run_json(escapeshellarg($php) . ' ' . escapeshellarg($root . '/tools/security_readiness_report.php') . ' --json');
$automation = gate_run_json(escapeshellarg($php) . ' ' . escapeshellarg($root . '/tools/check_automation_health.php'));

$secCrit = (int)($securityPreflight['data']['critical_failures'] ?? 999);
$backCrit = (int)($backendPreflight['data']['critical_failures'] ?? 999);
$opsCrit = (int)($opsPreflight['data']['critical_failures'] ?? 999);
$controls[] = gate_control($secCrit === 0, 'Security preflight', 'critical_failures=' . $secCrit);
$controls[] = gate_control($backCrit === 0, 'Backend quality preflight', 'critical_failures=' . $backCrit);
$controls[] = gate_control($opsCrit === 0, 'Security ops preflight', 'critical_failures=' . $opsCrit);

$score = (int)($readiness['data']['score'] ?? 0);
$controls[] = gate_control($score >= 85, 'Readiness >= 85/100', 'score=' . $score . '/100');

$gov = gate_load_governance($root);
$wafMode = strtolower(gate_env_value($envFile, 'APP_WAF_MODE'));
$wafActive = in_array($wafMode, ['block', 'active', 'enforce'], true);
$wafLast = (string)($gov['waf']['last_review_at'] ?? '');
$wafCadence = max(1, (int)($gov['waf']['cadence_days'] ?? 30));
$wafDays = gate_days_since($wafLast);
$wafFresh = $wafDays !== null && $wafDays <= $wafCadence;
$wafEvidenceFile = $root . '/docs/security/WAF_EVIDENCE.md';
$wafEvidenceStatus = gate_markdown_status($wafEvidenceFile);
$wafEvidenceApproved = in_array($wafEvidenceStatus, ['APPROVED', 'VALIDATED', 'PASS', 'PASSED'], true);
$controls[] = gate_control($wafActive, 'WAF active/enforce', 'APP_WAF_MODE=' . ($wafMode !== '' ? $wafMode : '(missing)'));
$controls[] = gate_control($wafFresh, 'WAF review in cadence', 'last_review_at=' . ($wafLast ?: '-') . ', days_since=' . ($wafDays === null ? '-' : $wafDays));
$controls[] = gate_control($wafEvidenceApproved, 'WAF evidence approved', 'status=' . ($wafEvidenceStatus !== '' ? $wafEvidenceStatus : 'MISSING') . ', file=' . $wafEvidenceFile);

$drLast = (string)($gov['dr_ir']['last_dr_drill_at'] ?? '');
$drCadence = max(1, (int)($gov['dr_ir']['dr_cadence_days'] ?? 30));
$drStamp = $root . '/app/storage/logs/dr_last_test.json';
if (is_file($drStamp)) {
    $raw = file_get_contents($drStamp);
    $stamp = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($stamp) && !empty($stamp['DR_LAST_TEST_AT']) && (string)$stamp['DR_LAST_TEST_AT'] > $drLast) {
        $drLast = (string)$stamp['DR_LAST_TEST_AT'];
    }
}
$drDays = gate_days_since($drLast);
$controls[] = gate_control($drDays !== null && $drDays <= $drCadence, 'Restore backup comprovado', 'last_dr_drill_at=' . ($drLast ?: '-') . ', days_since=' . ($drDays === null ? '-' : $drDays));

$autoOk = !empty($automation['data']['success']);
$autoWarnings = isset($automation['data']['warnings']) && is_array($automation['data']['warnings']) ? count($automation['data']['warnings']) : 0;
$controls[] = gate_control($autoOk, 'Backup/cron automation health', 'success=' . ($autoOk ? 'true' : 'false') . ', warnings=' . $autoWarnings);

$opsStatusFile = $root . '/app/storage/logs/security_ops_status.json';
$opsStatus = [];
if (is_file($opsStatusFile)) {
    $raw = file_get_contents($opsStatusFile);
    $opsStatus = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
}
$opsAgeMin = isset($opsStatus['generated_at']) ? (int)floor((time() - strtotime((string)$opsStatus['generated_at'])) / 60) : null;
$monitorOk = !empty($opsStatus['monitor']['ok']) && $opsAgeMin !== null && $opsAgeMin <= 90;
$controls[] = gate_control($monitorOk, 'Monitoramento ativo', 'last_status_minutes_ago=' . ($opsAgeMin === null ? '-' : $opsAgeMin));

$notify = $opsStatus['notify'] ?? null;
$alertsActive = $notify !== null || gate_env_value($envFile, 'SIEM_ENABLED') !== '';
$controls[] = gate_control($alertsActive, 'Alertas ativos', $alertsActive ? 'notify/SIEM configured' : 'no notify/SIEM evidence');

$pentestLast = (string)($gov['pentest']['last_test_at'] ?? gate_env_value($envFile, 'PENTEST_LAST_AT'));
$pentestDays = gate_days_since($pentestLast);
$pentestCadence = max(1, (int)($gov['pentest']['cadence_days'] ?? 90));
$controls[] = gate_control($pentestDays !== null && $pentestDays <= $pentestCadence, 'Pentest in cadence', 'last_test_at=' . ($pentestLast ?: '-') . ', days_since=' . ($pentestDays === null ? '-' : $pentestDays));

$findings = gate_pentest_open_findings($root);
if ($findings['open'] === null) {
    $controls[] = gate_control(false, 'Pentest sem criticas/altas pendentes', 'missing pentest_findings.json evidence');
} else {
    $findingsOk = !empty($findings['approved']) && count($findings['open']) === 0;
    $controls[] = gate_control(
        $findingsOk,
        'Pentest sem criticas/altas pendentes',
        'status=' . (($findings['evidence_status'] ?? '') !== '' ? $findings['evidence_status'] : 'MISSING')
            . ', open_critical_high=' . count($findings['open'])
            . ', file=' . $findings['file']
    );
}

$failed = array_values(array_filter($controls, static fn($c) => empty($c['ok']) && $c['status'] !== 'WARN'));
$warnings = array_values(array_filter($controls, static fn($c) => empty($c['ok']) && $c['status'] === 'WARN'));
$ok = count($failed) === 0;

$out = [
    'ok' => $ok,
    'generated_at' => date('c'),
    'objective' => '0 critical preflight, readiness >= 85, WAF current, restore proven, monitoring/alerts active, no open critical/high pentest findings',
    'failed_count' => count($failed),
    'warning_count' => count($warnings),
    'controls' => $controls,
    'artifacts' => [
        'readiness_file' => $readiness['data']['output_file'] ?? null,
        'ops_status_file' => is_file($opsStatusFile) ? $opsStatusFile : null,
    ],
];

$statusDir = $root . '/app/storage/logs';
if (!is_dir($statusDir)) {
    @mkdir($statusDir, 0775, true);
}
@file_put_contents($statusDir . '/security_objective_gate.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);

if ($asJson) {
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo "Nivora Security Objective Gate\n";
    echo "OK: " . ($ok ? 'YES' : 'NO') . "\n";
    echo "Failed: " . count($failed) . " | Warnings: " . count($warnings) . "\n\n";
    foreach ($controls as $c) {
        echo '[' . $c['status'] . '] ' . $c['name'] . ': ' . $c['detail'] . "\n";
    }
}

exit($ok ? 0 : 1);
