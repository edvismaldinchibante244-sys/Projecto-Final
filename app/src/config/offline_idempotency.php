<?php

require_once __DIR__ . '/migration_state.php';

if (!defined('OFFLINE_OPERATIONS_SCHEMA_MIGRATION_ID')) {
    define('OFFLINE_OPERATIONS_SCHEMA_MIGRATION_ID', '20260814_1400_offline_operations');
}

/**
 * Idempotencia para mutacoes reenviadas pela fila offline.
 * A reserva e a conclusao devem ocorrer na mesma transacao da operacao de negocio.
 */

function offline_operation_id(): string
{
    $header = trim((string)($_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? ''));
    $body = trim((string)($_POST['_offline_op_id'] ?? ''));
    $id = $header !== '' ? $header : $body;

    return preg_match('/^[A-Za-z0-9_-]{8,100}$/', $id) ? $id : '';
}

function offline_idempotency_prepare(PDO $db): void
{
    static $prepared = [];
    $key = spl_object_id($db);
    if (isset($prepared[$key])) {
        return;
    }

    if (migration_esta_aplicada($db, OFFLINE_OPERATIONS_SCHEMA_MIGRATION_ID)) {
        $prepared[$key] = true;
        return;
    }

    $db->exec("CREATE TABLE IF NOT EXISTS offline_operations (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        op_id VARCHAR(100) NOT NULL,
        restaurante_id INT NOT NULL,
        usuario_id INT NULL,
        endpoint VARCHAR(190) NOT NULL,
        request_hash CHAR(64) NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'PROCESSING',
        response_json LONGTEXT NULL,
        http_status SMALLINT NULL,
        attempts INT NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        completed_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_offline_operation (restaurante_id, op_id),
        KEY idx_offline_operations_status (status, updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $prepared[$key] = true;
}

function offline_idempotency_hash(array $payload): string
{
    unset($payload['_csrf'], $payload['_offline_op_id']);
    $normalize = static function (&$value) use (&$normalize): void {
        if (!is_array($value)) {
            return;
        }
        $isList = $value === [] || array_keys($value) === range(0, count($value) - 1);
        if (!$isList) {
            ksort($value);
        }
        foreach ($value as &$item) {
            $normalize($item);
        }
    };
    $normalize($payload);
    return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function offline_idempotency_begin(PDO $db, int $restauranteId, int $usuarioId, string $endpoint, array $payload): array
{
    $opId = offline_operation_id();
    if ($opId === '') {
        return ['active' => false, 'replay' => false];
    }
    if (!$db->inTransaction()) {
        throw new LogicException('A idempotencia deve iniciar dentro da transacao de negocio.');
    }

    $hash = offline_idempotency_hash($payload);
    $select = $db->prepare(
        'SELECT id, request_hash, status, response_json, http_status
         FROM offline_operations
         WHERE restaurante_id = :rid AND op_id = :op_id
         LIMIT 1 FOR UPDATE'
    );
    $select->execute(['rid' => $restauranteId, 'op_id' => $opId]);
    $existing = $select->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        if (!hash_equals((string)$existing['request_hash'], $hash)) {
            throw new DomainException('O identificador offline ja foi usado com dados diferentes.');
        }
        if ((string)$existing['status'] === 'COMPLETED') {
            $response = json_decode((string)$existing['response_json'], true);
            return [
                'active' => true,
                'replay' => true,
                'id' => (int)$existing['id'],
                'response' => is_array($response) ? $response : ['success' => true],
                'http_status' => (int)($existing['http_status'] ?: 200),
            ];
        }
        $update = $db->prepare('UPDATE offline_operations SET attempts = attempts + 1, updated_at = NOW() WHERE id = :id');
        $update->execute(['id' => (int)$existing['id']]);
        return ['active' => true, 'replay' => false, 'id' => (int)$existing['id']];
    }

    $insert = $db->prepare(
        "INSERT INTO offline_operations
            (op_id, restaurante_id, usuario_id, endpoint, request_hash, status)
         VALUES (:op_id, :rid, :uid, :endpoint, :request_hash, 'PROCESSING')"
    );
    $insert->execute([
        'op_id' => $opId,
        'rid' => $restauranteId,
        'uid' => $usuarioId > 0 ? $usuarioId : null,
        'endpoint' => substr($endpoint, 0, 190),
        'request_hash' => $hash,
    ]);

    return ['active' => true, 'replay' => false, 'id' => (int)$db->lastInsertId()];
}

function offline_idempotency_complete(PDO $db, array $context, array $response, int $httpStatus = 200): void
{
    if (empty($context['active']) || empty($context['id'])) {
        return;
    }
    $stmt = $db->prepare(
        "UPDATE offline_operations
         SET status = 'COMPLETED', response_json = :response, http_status = :http_status,
             completed_at = NOW(), updated_at = NOW()
         WHERE id = :id"
    );
    $stmt->execute([
        'response' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'http_status' => $httpStatus,
        'id' => (int)$context['id'],
    ]);
}

function offline_idempotency_replay(PDO $db, array $context): void
{
    if (empty($context['replay'])) {
        return;
    }
    if ($db->inTransaction()) {
        $db->commit();
    }
    http_response_code((int)($context['http_status'] ?? 200));
    echo json_encode($context['response'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
