<?php
declare(strict_types=1);

/**
 * Security Operations preflight (Level 4).
 *
 * Usage:
 *   php V00/tools/security_ops_preflight.php
 *   php V00/tools/security_ops_preflight.php --json
 */

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Unable to resolve root.\n");
    exit(2);
}

$opts = getopt('', ['json']);
$asJson = isset($opts['json']);

function env_value_ops(string $file, string $key): string
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
        if (count($parts) !== 2) {
            continue;
        }
        if (trim($parts[0]) !== $key) {
            continue;
        }
        return trim($parts[1], " \t\n\r\0\x0B\"'");
    }
    return '';
}

function row(string $status, string $name, string $detail): array
{
    return ['status' => $status, 'name' => $name, 'detail' => $detail];
}

$envFile = $root . DIRECTORY_SEPARATOR . '.env';
$results = [];
$critical = 0;

$checks = [
    'APP_WAF_PROVIDER' => 'WAF provider',
    'APP_WAF_MODE' => 'WAF mode',
    'INCIDENT_CONTACT' => 'Incident contact',
    'DR_LAST_TEST_AT' => 'DR last test date',
    'PENTEST_LAST_AT' => 'Pentest last date',
];

foreach ($checks as $key => $label) {
    $v = env_value_ops($envFile, $key);
    if ($v === '') {
        $results[] = row('FAIL', $label, $key . ' missing in .env');
        $critical++;
    } else {
        $results[] = row('PASS', $label, $key . '=' . $v);
    }
}

$wafMode = strtolower(env_value_ops($envFile, 'APP_WAF_MODE'));
if ($wafMode !== '' && !in_array($wafMode, ['block', 'active', 'enforce'], true)) {
    $results[] = row('WARN', 'WAF enforcement', 'APP_WAF_MODE should be block/active/enforce in production.');
}

/**
 * @return int|null
 */
function days_since(?string $dateRaw): ?int
{
    if (!is_string($dateRaw) || trim($dateRaw) === '') {
        return null;
    }
    $ts = strtotime($dateRaw);
    if ($ts === false) {
        return null;
    }
    $diff = time() - $ts;
    if ($diff < 0) {
        return 0;
    }
    return (int)floor($diff / 86400);
}

$pentestDays = days_since(env_value_ops($envFile, 'PENTEST_LAST_AT'));
if ($pentestDays === null) {
    $results[] = row('FAIL', 'Pentest recency', 'PENTEST_LAST_AT missing or invalid date format.');
    $critical++;
} elseif ($pentestDays > 120) {
    $results[] = row('FAIL', 'Pentest recency', 'Last pentest is older than 120 days (' . $pentestDays . 'd).');
    $critical++;
} elseif ($pentestDays > 90) {
    $results[] = row('WARN', 'Pentest recency', 'Last pentest is older than 90 days (' . $pentestDays . 'd).');
} else {
    $results[] = row('PASS', 'Pentest recency', 'Last pentest within policy (' . $pentestDays . 'd).');
}

$drDate = env_value_ops($envFile, 'DR_LAST_TEST_AT');
$drStampFile = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'dr_last_test.json';
if (is_file($drStampFile)) {
    $stampRaw = file_get_contents($drStampFile);
    $stamp = is_string($stampRaw) ? json_decode($stampRaw, true) : null;
    if (is_array($stamp) && !empty($stamp['DR_LAST_TEST_AT'])) {
        $stampDate = (string)$stamp['DR_LAST_TEST_AT'];
        // Preferir o mais recente entre .env e stamp automático
        if ($drDate === '' || $stampDate > $drDate) {
            $drDate = $stampDate;
        }
    }
}
$drDays = days_since($drDate);
if ($drDays === null) {
    $results[] = row('FAIL', 'DR drill recency', 'DR_LAST_TEST_AT missing (env or app/storage/logs/dr_last_test.json). Run: php tools/dr_restore_drill.php --stamp-env');
    $critical++;
} elseif ($drDays > 45) {
    $results[] = row('FAIL', 'DR drill recency', 'Last DR drill is older than 45 days (' . $drDays . 'd).');
    $critical++;
} elseif ($drDays > 30) {
    $results[] = row('WARN', 'DR drill recency', 'Last DR drill is older than 30 days (' . $drDays . 'd).');
} else {
    $results[] = row('PASS', 'DR drill recency', 'Last DR drill within policy (' . $drDays . 'd).');
}

$wafEvidenceFile = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'security' . DIRECTORY_SEPARATOR . 'WAF_EVIDENCE.md';
if (is_file($wafEvidenceFile) && filesize($wafEvidenceFile) > 64) {
    $results[] = row('PASS', 'WAF evidence', 'WAF evidence file exists: docs/security/WAF_EVIDENCE.md');
} else {
    $results[] = row('WARN', 'WAF evidence', 'Create/update docs/security/WAF_EVIDENCE.md with active rules and screenshots.');
}

$govFile = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'security' . DIRECTORY_SEPARATOR . 'security_governance.json';
if (!is_file($govFile)) {
    $results[] = row('FAIL', 'Security governance file', 'Missing docs/security/security_governance.json');
    $critical++;
} else {
    $govRaw = file_get_contents($govFile);
    $gov = is_string($govRaw) ? json_decode($govRaw, true) : null;
    if (!is_array($gov)) {
        $results[] = row('FAIL', 'Security governance file', 'Invalid JSON in docs/security/security_governance.json');
        $critical++;
    } else {
        $results[] = row('PASS', 'Security governance file', 'Governance file loaded.');

        // $criticalOnOverdue=false → WARN (não falha o cron a cada 10–15 min)
        $checkGovernance = function (string $label, ?string $date, int $cadenceDays, bool $criticalOnOverdue = true) use (&$results, &$critical): void {
            $days = days_since($date);
            if ($days === null) {
                $results[] = row('FAIL', $label, 'Missing/invalid date.');
                $critical++;
                return;
            }
            if ($days > $cadenceDays) {
                $msg = 'Overdue by ' . ($days - $cadenceDays) . ' day(s).';
                if ($criticalOnOverdue) {
                    $results[] = row('FAIL', $label, $msg);
                    $critical++;
                } else {
                    $results[] = row('WARN', $label, $msg . ' Agende o exercício; não bloqueia o runner operacional.');
                }
                return;
            }
            $results[] = row('PASS', $label, 'Within cadence (' . $days . 'd / ' . $cadenceDays . 'd).');
        };

        $wafLast = (string)($gov['waf']['last_review_at'] ?? '');
        $wafCadence = (int)($gov['waf']['cadence_days'] ?? 30);
        $checkGovernance('WAF review cadence', $wafLast, max(1, $wafCadence));

        $pentestLast = (string)($gov['pentest']['last_test_at'] ?? '');
        $pentestCadence = (int)($gov['pentest']['cadence_days'] ?? 90);
        $checkGovernance('Pentest cadence', $pentestLast, max(1, $pentestCadence));

        $retestLast = (string)($gov['pentest']['last_retest_at'] ?? '');
        $checkGovernance('Pentest retest cadence', $retestLast, max(1, $pentestCadence));

        $drLast = (string)($gov['dr_ir']['last_dr_drill_at'] ?? '');
        $drCadence = (int)($gov['dr_ir']['dr_cadence_days'] ?? 30);
        $checkGovernance('DR drill cadence', $drLast, max(1, $drCadence));

        // IR tabletop é reunião de governança — atraso = WARN, não derruba o cron
        $irLast = (string)($gov['dr_ir']['last_ir_tabletop_at'] ?? '');
        $irCadence = (int)($gov['dr_ir']['ir_cadence_days'] ?? 15);
        $checkGovernance('IR tabletop cadence', $irLast, max(1, $irCadence), false);
    }
}

$siemEnabled = strtolower(env_value_ops($envFile, 'SIEM_ENABLED'));
$siemEndpoint = env_value_ops($envFile, 'SIEM_ENDPOINT');
$siemActive = in_array($siemEnabled, ['1', 'true', 'yes', 'on'], true);
if ($siemEnabled === '') {
    $results[] = row('FAIL', 'SIEM enabled', 'SIEM_ENABLED ausente no .env.');
    $critical++;
} elseif ($siemActive) {
    $results[] = row('PASS', 'SIEM enabled', 'SIEM esta ativado.');
} else {
    $results[] = row('WARN', 'SIEM enabled', 'SIEM esta desativado; eventos permanecem apenas nos canais locais configurados.');
}

$siemUrl = $siemEndpoint !== '' ? filter_var($siemEndpoint, FILTER_VALIDATE_URL) : false;
$siemHost = is_string($siemUrl) ? (string)(parse_url($siemUrl, PHP_URL_HOST) ?? '') : '';
$siemUser = is_string($siemUrl) ? parse_url($siemUrl, PHP_URL_USER) : null;
$siemPassword = is_string($siemUrl) ? parse_url($siemUrl, PHP_URL_PASS) : null;
$siemMalformed = $siemEndpoint !== '' && (
    $siemUrl === false
    || $siemHost === ''
    || $siemUser !== null
    || $siemPassword !== null
);
if ($siemActive) {
    if ($siemEndpoint === '') {
        $results[] = row('FAIL', 'SIEM endpoint live', 'SIEM_ENABLED=true but SIEM_ENDPOINT empty');
        $critical++;
    } elseif ($siemMalformed) {
        $results[] = row('FAIL', 'SIEM endpoint live', 'SIEM_ENDPOINT nao e um endpoint HTTP valido.');
        $critical++;
    } elseif (preg_match('/seudominio\.com|example\.com/i', $siemEndpoint)) {
        $results[] = row('WARN', 'SIEM endpoint live', 'SIEM_ENDPOINT still looks like a placeholder — alerts stay local/email only.');
    } else {
        $results[] = row('PASS', 'SIEM endpoint live', 'SIEM_ENDPOINT looks real (not placeholder).');
    }
} elseif ($siemMalformed) {
    $results[] = row('WARN', 'SIEM endpoint format', 'SIEM_ENABLED=false, mas SIEM_ENDPOINT configurado nao e um endpoint HTTP valido.');
}

$passwordPolicyFile = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'password_policy.php';
if (is_file($passwordPolicyFile)) {
    $results[] = row('PASS', 'Password policy helper', 'password_policy.php present.');
} else {
    $results[] = row('FAIL', 'Password policy helper', 'Missing app/src/config/password_policy.php');
    $critical++;
}

$payload = [
    'generated_at' => date('c'),
    'critical_failures' => $critical,
    'results' => $results,
];

if ($asJson) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($critical > 0 ? 1 : 0);
}

echo "Security Ops Preflight\n";
echo "Generated: " . date('c') . "\n\n";
foreach ($results as $r) {
    $tag = '[' . $r['status'] . ']';
    echo $tag . ' ' . $r['name'] . "\n";
    echo '  - ' . $r['detail'] . "\n";
}
echo "\nCritical failures: {$critical}\n";
exit($critical > 0 ? 1 : 0);
