<?php
declare(strict_types=1);

/**
 * Gera relatorio mensal de readiness para 9.5+ em docs/security.
 *
 * Uso:
 *   php tools/security_readiness_report.php
 *   php tools/security_readiness_report.php --json
 */

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "root not found\n");
    exit(2);
}

$asJson = isset(getopt('', ['json'])['json']);
$docsDir = $root . '/docs/security';
$govFile = $docsDir . '/security_governance.json';
$outFile = $docsDir . '/SECURITY_MONTHLY_READINESS.md';

if (!is_file($govFile)) {
    $out = ['ok' => false, 'message' => 'security_governance.json not found'];
    echo $asJson ? json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL : "security_governance.json not found\n";
    exit(1);
}

$raw = file_get_contents($govFile);
$gov = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($gov)) {
    $out = ['ok' => false, 'message' => 'invalid security_governance.json'];
    echo $asJson ? json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL : "invalid security_governance.json\n";
    exit(1);
}

$nowTs = time();
$items = [
    ['name' => 'WAF Review', 'date' => (string)($gov['waf']['last_review_at'] ?? ''), 'cadence' => (int)($gov['waf']['cadence_days'] ?? 30)],
    ['name' => 'Pentest Externo', 'date' => (string)($gov['pentest']['last_test_at'] ?? ''), 'cadence' => (int)($gov['pentest']['cadence_days'] ?? 90)],
    ['name' => 'Reteste Pentest', 'date' => (string)($gov['pentest']['last_retest_at'] ?? ''), 'cadence' => (int)($gov['pentest']['cadence_days'] ?? 90)],
    ['name' => 'DR Drill', 'date' => (string)($gov['dr_ir']['last_dr_drill_at'] ?? ''), 'cadence' => (int)($gov['dr_ir']['dr_cadence_days'] ?? 30)],
    ['name' => 'IR Tabletop', 'date' => (string)($gov['dr_ir']['last_ir_tabletop_at'] ?? ''), 'cadence' => (int)($gov['dr_ir']['ir_cadence_days'] ?? 15)],
];

$rows = [];
$ok = 0;
foreach ($items as $it) {
    $ts = strtotime($it['date']);
    $daysSince = ($ts === false || $it['date'] === '') ? null : max(0, (int)floor(($nowTs - $ts) / 86400));
    $overdue = $daysSince === null || $daysSince > $it['cadence'];
    if (!$overdue) {
        $ok++;
    }
    $rows[] = [
        'name' => $it['name'],
        'last_at' => $it['date'],
        'cadence_days' => $it['cadence'],
        'days_since' => $daysSince,
        'status' => $overdue ? 'OVERDUE' : 'OK',
    ];
}

$score = count($rows) > 0 ? (int)round(($ok / count($rows)) * 100) : 0;
$status = $score >= 95 ? 'PRONTO_95+' : ($score >= 80 ? 'PARCIAL' : 'CRITICO');

$md = [];
$md[] = '# Security Monthly Readiness';
$md[] = '';
$md[] = '- Generated at: ' . date('c');
$md[] = '- Owner: ' . (string)($gov['owner'] ?? '-');
$md[] = '- Score: **' . $score . '/100**';
$md[] = '- Status: **' . $status . '**';
$md[] = '';
$md[] = '| Controle | Ultima Execucao | Cadencia (dias) | Dias desde ultima | Status |';
$md[] = '|---|---:|---:|---:|---|';
foreach ($rows as $r) {
    $md[] = sprintf(
        '| %s | %s | %d | %s | %s |',
        $r['name'],
        $r['last_at'] !== '' ? $r['last_at'] : '-',
        (int)$r['cadence_days'],
        $r['days_since'] === null ? '-' : (string)$r['days_since'],
        $r['status']
    );
}
$md[] = '';

if (!is_dir($docsDir)) {
    @mkdir($docsDir, 0775, true);
}
@file_put_contents($outFile, implode(PHP_EOL, $md) . PHP_EOL, LOCK_EX);

$out = [
    'ok' => true,
    'generated_at' => date('c'),
    'score' => $score,
    'status' => $status,
    'output_file' => $outFile,
    'rows' => $rows,
];

if ($asJson) {
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo "Security Monthly Readiness generated\n";
    echo "Score: {$score} | Status: {$status}\n";
    echo "File: {$outFile}\n";
}

exit(0);

