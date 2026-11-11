<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/src/config/legal_documents.php';
require_once __DIR__ . '/../app/src/config/database.php';

$asJson = in_array('--json', $argv, true);
$root = realpath(__DIR__ . '/..');
$checks = [];

function legal_preflight_add(array &$checks, string $name, bool $ok, string $detail): void
{
    $checks[] = ['status' => $ok ? 'PASS' : 'FAIL', 'name' => $name, 'detail' => $detail];
}

$missingIdentity = legal_identity_missing_fields();
legal_preflight_add(
    $checks,
    'Identidade legal configurada',
    $missingIdentity === [],
    $missingIdentity === [] ? 'Nome legal, NUIT, endereco e contactos presentes.' : 'Falta: ' . implode(', ', $missingIdentity) . '.'
);

$config = legal_config();
foreach (['privacy_version', 'terms_version', 'cookie_version'] as $key) {
    legal_preflight_add($checks, 'Versao ' . $key, trim((string)$config[$key]) !== '', (string)$config[$key]);
}

foreach (['privacy', 'terms', 'cookies'] as $key) {
    try {
        $document = legal_document($key);
        $hash = legal_document_hash($key);
        $ok = !empty($document['sections']) && preg_match('/^[a-f0-9]{64}$/', $hash) === 1;
        legal_preflight_add($checks, 'Documento ' . $key, $ok, 'Versao ' . ($document['version'] ?? '') . '; hash ' . substr($hash, 0, 12) . '...');
    } catch (Throwable $e) {
        legal_preflight_add($checks, 'Documento ' . $key, false, $e->getMessage());
    }
}

$requiredFiles = [
    'Politica de Privacidade' => 'src/public/privacidade.php',
    'Termos de Uso' => 'src/public/termos.php',
    'Politica de Cookies' => 'src/public/cookies.php',
    'Canal de direitos' => 'src/public/direitos_dados.php',
    'Procedimento de incidente' => 'docs/security/INCIDENT_RESPONSE_AND_DATA_BREACH.md',
    'Modelo de contrato' => 'docs/legal/CONTRATO_SAAS_MODELO.md',
];
foreach ($requiredFiles as $name => $relative) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    legal_preflight_add($checks, $name, is_file($path) && filesize($path) > 20, $relative);
}

try {
    $db = (new Database())->getConnection();
    foreach (['legal_acceptances', 'data_subject_requests'] as $table) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([$table]);
        legal_preflight_add($checks, 'Tabela ' . $table, (int)$stmt->fetchColumn() === 1, $table);
    }
} catch (Throwable $e) {
    legal_preflight_add($checks, 'Ligacao ao banco para auditoria legal', false, $e->getMessage());
}

$failed = count(array_filter($checks, static fn(array $check): bool => $check['status'] === 'FAIL'));
$result = ['ready' => $failed === 0, 'failed' => $failed, 'checks' => $checks];

if ($asJson) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    foreach ($checks as $check) {
        echo '[' . $check['status'] . '] ' . $check['name'] . ': ' . $check['detail'] . PHP_EOL;
    }
    echo PHP_EOL . ($failed === 0 ? 'LEGAL READY' : 'LEGAL NOT READY') . ' - ' . $failed . ' falha(s).' . PHP_EOL;
}

exit($failed === 0 ? 0 : 1);
