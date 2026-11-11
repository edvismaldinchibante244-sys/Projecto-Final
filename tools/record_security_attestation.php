<?php
declare(strict_types=1);

/**
 * Registra evidencias operacionais de seguranca com trilha auditavel.
 *
 * Uso seguro:
 *   php tools/record_security_attestation.php --confirm=EVIDENCE_REVIEWED --waf --pentest --ir-tabletop --reviewer="Nome" --reference="Ticket/URL/relatorio"
 *
 * Este script nao executa WAF, pentest ou tabletop. Ele apenas registra a
 * atestacao depois que a revisao real foi feita.
 */

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "root not found\n");
    exit(2);
}

$opts = getopt('', [
    'confirm:',
    'waf',
    'pentest',
    'ir-tabletop',
    'reviewer::',
    'reference::',
    'date::',
    'json',
]);

$asJson = isset($opts['json']);
$confirm = (string)($opts['confirm'] ?? '');
if ($confirm !== 'EVIDENCE_REVIEWED') {
    $message = 'Refused: use --confirm=EVIDENCE_REVIEWED only after real evidence has been reviewed.';
    if ($asJson) {
        echo json_encode(['ok' => false, 'message' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        echo $message . PHP_EOL;
    }
    exit(1);
}

$selected = [
    'waf' => isset($opts['waf']),
    'pentest' => isset($opts['pentest']),
    'ir_tabletop' => isset($opts['ir-tabletop']),
];
if (!in_array(true, $selected, true)) {
    $message = 'No evidence type selected. Use --waf, --pentest and/or --ir-tabletop.';
    if ($asJson) {
        echo json_encode(['ok' => false, 'message' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        echo $message . PHP_EOL;
    }
    exit(1);
}

$date = (string)($opts['date'] ?? date('Y-m-d'));
if (strtotime($date) === false) {
    $message = 'Invalid --date. Use YYYY-MM-DD.';
    if ($asJson) {
        echo json_encode(['ok' => false, 'message' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        echo $message . PHP_EOL;
    }
    exit(1);
}
$date = date('Y-m-d', (int)strtotime($date));
$reviewer = trim((string)($opts['reviewer'] ?? 'Manual reviewer'));
$reference = trim((string)($opts['reference'] ?? 'Manual evidence review'));

$docsDir = $root . '/docs/security';
$govFile = $docsDir . '/security_governance.json';
if (!is_file($govFile)) {
    fwrite(STDERR, "Missing docs/security/security_governance.json\n");
    exit(1);
}

$raw = file_get_contents($govFile);
$gov = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($gov)) {
    fwrite(STDERR, "Invalid docs/security/security_governance.json\n");
    exit(1);
}

if (!is_dir($docsDir)) {
    @mkdir($docsDir, 0775, true);
}

$changes = [];

if ($selected['waf']) {
    $gov['waf']['last_review_at'] = $date;
    $gov['waf']['evidence_file'] = 'docs/security/WAF_EVIDENCE.md';
    $waf = [];
    $waf[] = '# WAF Evidence';
    $waf[] = '';
    $waf[] = 'Status: APPROVED';
    $waf[] = '';
    $waf[] = 'Approved at: ' . $date;
    $waf[] = 'Reviewer: ' . $reviewer;
    $waf[] = 'Reference: ' . $reference;
    $waf[] = '';
    $waf[] = '## Required Evidence';
    $waf[] = '';
    $waf[] = '- Provider: ' . (string)($gov['waf']['provider'] ?? 'cloudflare');
    $waf[] = '- Mode: block/enforce';
    $waf[] = '- Last reviewed at: ' . $date;
    $waf[] = '- Reviewer: ' . $reviewer;
    $waf[] = '- Production domain: see deployment configuration';
    $waf[] = '- Managed rules enabled: verified by reviewer';
    $waf[] = '- Custom rules enabled: verified by reviewer';
    $waf[] = '- Rate limiting enabled: verified by reviewer';
    $waf[] = '- Bot/automation controls enabled: verified by reviewer';
    $waf[] = '- Screenshot/reference: ' . $reference;
    $waf[] = '';
    $waf[] = '## Minimum Rules Reviewed';
    $waf[] = '';
    $waf[] = '- SQL injection payload blocking.';
    $waf[] = '- XSS payload blocking.';
    $waf[] = '- Path traversal probe blocking.';
    $waf[] = '- Sensitive path blocking: `.env`, `app/`, `tools/`, `docs/`, backups and logs.';
    $waf[] = '- Rate limits for public menu, reservation/order APIs, login and password reset.';
    $waf[] = '- Alerting for blocked request spikes and 4xx/5xx spikes.';
    $waf[] = '';
    $waf[] = '## Review Log';
    $waf[] = '';
    $waf[] = '| Date | Reviewer | Result | Notes |';
    $waf[] = '|---|---|---|---|';
    $waf[] = '| ' . $date . ' | ' . $reviewer . ' | APPROVED | ' . $reference . ' |';
    file_put_contents($docsDir . '/WAF_EVIDENCE.md', implode(PHP_EOL, $waf) . PHP_EOL, LOCK_EX);
    $changes[] = 'waf';
}

if ($selected['pentest']) {
    $gov['pentest']['last_test_at'] = $date;
    $gov['pentest']['last_retest_at'] = $date;
    $gov['pentest']['evidence_file'] = 'docs/security/PENTEST_FINDINGS.json';
    $pentest = [
        'status' => 'APPROVED',
        'approved' => true,
        'approved_at' => $date,
        'reviewer' => $reviewer,
        'reference' => $reference,
        'scope' => 'Nivora / V00 public web app and authenticated restaurant admin flows',
        'summary' => 'No open CRITICAL or HIGH findings according to reviewed evidence.',
        'findings' => [],
    ];
    file_put_contents($docsDir . '/PENTEST_FINDINGS.json', json_encode($pentest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
    $changes[] = 'pentest';
}

if ($selected['ir_tabletop']) {
    $gov['dr_ir']['last_ir_tabletop_at'] = $date;
    $irFile = $docsDir . '/IR_TABLETOP_EVIDENCE.md';
    $ir = [];
    $ir[] = '# Incident Response Tabletop Evidence';
    $ir[] = '';
    $ir[] = 'Status: APPROVED';
    $ir[] = '';
    $ir[] = 'Performed at: ' . $date;
    $ir[] = 'Reviewer: ' . $reviewer;
    $ir[] = 'Reference: ' . $reference;
    $ir[] = '';
    $ir[] = '## Scenario';
    $ir[] = '';
    $ir[] = '- Account takeover / suspicious admin activity.';
    $ir[] = '- Public endpoint abuse / automated traffic spike.';
    $ir[] = '- Backup restore decision and customer communication path.';
    $ir[] = '';
    $ir[] = '## Result';
    $ir[] = '';
    $ir[] = 'APPROVED after manual tabletop review.';
    file_put_contents($irFile, implode(PHP_EOL, $ir) . PHP_EOL, LOCK_EX);
    $changes[] = 'ir_tabletop';
}

$gov['updated_at'] = $date;
file_put_contents($govFile, json_encode($gov, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);

$out = [
    'ok' => true,
    'date' => $date,
    'reviewer' => $reviewer,
    'reference' => $reference,
    'changes' => $changes,
];

if ($asJson) {
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo "Security attestation recorded: " . implode(', ', $changes) . PHP_EOL;
}
