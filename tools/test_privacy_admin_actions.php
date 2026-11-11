<?php

require_once dirname(__DIR__) . '/src/public/app_base.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/privacy_requests.php';

$db = (new Database())->getConnection();
privacy_request_ensure_schema($db);

$checks = [];
$check = static function (string $name, bool $passed) use (&$checks): void {
    $checks[$name] = $passed;
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
};

$columns = [];
foreach ($db->query('SHOW COLUMNS FROM data_subject_requests')->fetchAll(PDO::FETCH_ASSOC) as $column) {
    $columns[(string)$column['Field']] = true;
}
$check('schema_has_identity_verified_at', isset($columns['identity_verified_at']));
$check('schema_has_identity_verified_by', isset($columns['identity_verified_by']));
$check('schema_has_export_generated_at', isset($columns['export_generated_at']));
$check('schema_has_event_table', privacy_request_table_exists($db, 'data_subject_request_events'));

$adminPrivacySource = file_get_contents(dirname(__DIR__) . '/src/public/admin_privacidade.php') ?: '';
$check('workflow_has_explicit_close_operations',
    str_contains($adminPrivacySource, "'complete', 'reject'")
    && str_contains($adminPrivacySource, 'value="complete"')
    && str_contains($adminPrivacySource, 'value="reject"')
);
$check('workflow_forces_terminal_status_server_side',
    str_contains($adminPrivacySource, '$operation === \'complete\' ? \'CONCLUIDO\' : \'RECUSADO\'')
);
$check('workflow_locks_closed_requests',
    str_contains($adminPrivacySource, 'Pedido encerrado')
    && str_contains($adminPrivacySource, 'disponivel apenas para consulta')
);

$db->beginTransaction();
try {
    $email = 'privacy-action-' . bin2hex(random_bytes(6)) . '@example.test';
    $publicId = legal_create_data_request($db, [
        'request_type' => 'PORTABILIDADE',
        'nome' => 'Teste Privacidade Admin',
        'email' => $email,
        'restaurante' => 'Restaurante Teste',
        'descricao' => 'Pedido automatico usado apenas no teste transacional.',
        'ip' => '127.0.0.1',
        'user_agent' => 'EDVISTRO privacy admin test',
    ]);

    $stmt = $db->prepare('SELECT * FROM data_subject_requests WHERE public_id = ? LIMIT 1');
    $stmt->execute([$publicId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    $requestId = (int)($request['id'] ?? 0);

    $subjects = privacy_request_find_subjects($db, (string)($request['email_lookup'] ?? ''));
    $check('unknown_subject_has_no_account_match', $subjects['users'] === [] && $subjects['restaurants'] === []);

    privacy_request_record_event($db, $requestId, 999999, 'IDENTITY_VERIFIED', 'Identidade confirmada no teste automatizado.');
    $rawEvent = $db->prepare("SELECT COUNT(*) FROM data_subject_request_events WHERE request_id = ? AND event_detail LIKE 'v1%'");
    $rawEvent->execute([$requestId]);
    $check('event_detail_encrypted_at_rest', (int)$rawEvent->fetchColumn() === 1);

    $export = privacy_request_build_export($db, $request);
    $collectKeys = static function (array $value) use (&$collectKeys): array {
        $keys = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $keys[] = strtolower($key);
            }
            if (is_array($item)) {
                $keys = array_merge($keys, $collectKeys($item));
            }
        }
        return $keys;
    };
    $exportKeys = $collectKeys($export);
    $check('export_has_request_reference', ($export['request']['reference'] ?? '') === $publicId);
    $check('export_has_machine_readable_schema', ($export['schema_version'] ?? '') === '1.0');
    $check('export_excludes_credentials', array_intersect($exportKeys, [
        'senha', 'password', 'senha_hash', 'token', 'two_factor_email', 'tentativas_login', 'bloqueado_ate'
    ]) === []);
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

$failed = array_filter($checks, static fn(bool $passed): bool => !$passed);
echo PHP_EOL . (count($checks) - count($failed)) . '/' . count($checks) . ' checks passed.' . PHP_EOL;
exit($failed === [] ? 0 : 1);
