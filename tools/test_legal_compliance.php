<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/src/config/database.php';
require_once __DIR__ . '/../app/src/config/legal_documents.php';

$db = (new Database())->getConnection();
legal_ensure_schema($db);
$checks = [];

$schemaColumns = $db->query('SHOW COLUMNS FROM data_subject_requests')->fetchAll(PDO::FETCH_COLUMN);
foreach (['admin_notes', 'handled_by', 'internal_notified_at', 'receipt_sent_at', 'resolved_at'] as $requiredColumn) {
    legal_test($checks, 'schema_has_' . $requiredColumn, in_array($requiredColumn, $schemaColumns, true));
}

function legal_test(array &$checks, string $name, bool $condition): void
{
    $checks[$name] = $condition;
}

foreach (['privacy', 'terms', 'cookies'] as $key) {
    $document = legal_document($key);
    legal_test($checks, $key . '_has_version', trim((string)($document['version'] ?? '')) !== '');
    legal_test($checks, $key . '_has_sections', count($document['sections'] ?? []) >= 5);
    legal_test($checks, $key . '_hash_valid', preg_match('/^[a-f0-9]{64}$/', legal_document_hash($key)) === 1);
}

$testEmail = 'legal-test-' . bin2hex(random_bytes(6)) . '@example.invalid';
$requestId = '';
$db->beginTransaction();

try {
    $acceptanceId = legal_record_acceptance($db, [
        'email' => $testEmail,
        'contexto' => 'automated_test',
        'ip' => '192.0.2.10',
        'user_agent' => 'EDVISTRO legal test',
    ]);
    legal_test($checks, 'acceptance_inserted', $acceptanceId > 0);

    $requestId = legal_create_data_request($db, [
        'request_type' => 'ACESSO',
        'nome' => 'Utilizador de Teste',
        'email' => $testEmail,
        'restaurante' => 'Restaurante Teste',
        'descricao' => 'Pedido automatizado usado apenas para validar o fluxo legal.',
        'ip' => '192.0.2.10',
        'user_agent' => 'EDVISTRO legal test',
    ]);
    legal_test($checks, 'request_public_id_valid', preg_match('/^[a-f0-9-]{36}$/', $requestId) === 1);

    $stmt = $db->prepare('SELECT HEX(email) AS stored_email_hex, status FROM data_subject_requests WHERE public_id = ?');
    $stmt->execute([$requestId]);
    $stored = $stmt->fetch(PDO::FETCH_ASSOC);
    $storedCiphertext = hex2bin((string)($stored['stored_email_hex'] ?? '')) ?: '';
    legal_test($checks, 'request_email_encrypted_at_rest', personal_data_is_encrypted($storedCiphertext));
    legal_test($checks, 'request_pending_verification', ($stored['status'] ?? '') === 'PENDENTE_VERIFICACAO');

    $adminNote = 'Identidade verificada apenas no teste transacional.';
    $update = $db->prepare("UPDATE data_subject_requests SET status = 'EM_VERIFICACAO', admin_notes = ?, handled_by = ? WHERE public_id = ?");
    $update->execute([personal_data_encrypt($adminNote), 999999, $requestId]);
    $verify = $db->prepare('SELECT HEX(admin_notes) AS note_hex, status, handled_by FROM data_subject_requests WHERE public_id = ?');
    $verify->execute([$requestId]);
    $updated = $verify->fetch(PDO::FETCH_ASSOC);
    $storedNote = hex2bin((string)($updated['note_hex'] ?? '')) ?: '';
    legal_test($checks, 'admin_note_encrypted_at_rest', personal_data_is_encrypted($storedNote));
    legal_test($checks, 'admin_status_workflow_saved', ($updated['status'] ?? '') === 'EM_VERIFICACAO' && (int)($updated['handled_by'] ?? 0) === 999999);
} finally {
    $db->rollBack();
}

if ($requestId !== '') {
    $stmt = $db->prepare('SELECT COUNT(*) FROM data_subject_requests WHERE public_id = ?');
    $stmt->execute([$requestId]);
    legal_test($checks, 'test_data_rolled_back', (int)$stmt->fetchColumn() === 0);
}

$passed = count(array_filter($checks));
$total = count($checks);
foreach ($checks as $name => $ok) {
    echo '[' . ($ok ? 'PASS' : 'FAIL') . '] ' . $name . PHP_EOL;
}
echo PHP_EOL . $passed . '/' . $total . ' checks passed.' . PHP_EOL;

exit($passed === $total ? 0 : 1);
