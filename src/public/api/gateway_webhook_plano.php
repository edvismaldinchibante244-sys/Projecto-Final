<?php
require_once __DIR__ . '/../app_base.php';

/*
   Webhook de pagamento de planos.
   Aceita somente POST assinado por HMAC em PLANO_WEBHOOK_SECRET.
   Fallback por PLANO_WEBHOOK_TOKEN existe apenas para compatibilidade.
*/

require_once APP_BASE . '/src/config/security.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/plano_check.php';
require_once APP_BASE . '/src/config/plan_security.php';
require_once APP_BASE . '/src/config/audit.php';

security_set_headers();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    security_json(['success' => false, 'message' => 'Metodo nao permitido'], 405);
}

if (!security_rate_limit('plano_webhook', 120, 60)) {
    security_json(['success' => false, 'message' => 'Muitas requisicoes.'], 429);
}

$rawPayload = (string)file_get_contents('php://input');
if ($rawPayload === '') {
    $rawPayload = (string)(getenv('PLANO_WEBHOOK_RAW_PAYLOAD') ?: '');
}
if ($rawPayload === '' || strlen($rawPayload) > (1024 * 1024)) {
    security_json(['success' => false, 'message' => 'Payload invalido'], 422);
}

$configuredSecret = (string)(getenv('PLANO_WEBHOOK_SECRET') ?: '');
$incomingSignature = trim((string)($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? ''));
$configuredToken = (string)(getenv('PLANO_WEBHOOK_TOKEN') ?: '');
$incomingToken = (string)($_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? '');

if ($configuredSecret !== '') {
    $expectedSignature = 'sha256=' . hash_hmac('sha256', $rawPayload, $configuredSecret);
    if ($incomingSignature === '' || !hash_equals($expectedSignature, $incomingSignature)) {
        security_log_event('plano_webhook_invalid_signature');
        security_json(['success' => false, 'message' => 'Assinatura invalida'], 401);
    }
} elseif ($configuredToken !== '') {
    if (!hash_equals($configuredToken, $incomingToken)) {
        security_log_event('plano_webhook_invalid_token');
        security_json(['success' => false, 'message' => 'Token invalido'], 401);
    }
} else {
    error_log('PLANO_WEBHOOK_SECRET ausente; webhook recusado.');
    security_json(['success' => false, 'message' => 'Webhook nao configurado'], 503);
}

$payload = json_decode($rawPayload, true);
if (!is_array($payload)) {
    security_json(['success' => false, 'message' => 'Payload invalido'], 422);
}

$event = strtolower(trim((string)($payload['event'] ?? '')));
$compraId = filter_var($payload['compra_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?? 0;
$transactionId = security_clean_string($payload['transaction_id'] ?? '', 191);
$provider = security_clean_string($payload['provider'] ?? 'gateway', 100);
$provider = strtolower($provider);

if ($compraId <= 0 || $event === '') {
    security_json(['success' => false, 'message' => 'Parametros obrigatorios ausentes'], 422);
}

if ($transactionId === '' || strlen($transactionId) > 191) {
    security_json(['success' => false, 'message' => 'transaction_id obrigatorio'], 422);
}

try {
    $db = (new Database())->getConnection();
    $columns = plan_security_table_columns($db, 'compras_planos');

    $db->exec("
        CREATE TABLE IF NOT EXISTS webhook_idempotency (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            provider VARCHAR(100) NOT NULL,
            event VARCHAR(100) NOT NULL,
            transaction_id VARCHAR(191) NOT NULL,
            compra_id INT NOT NULL,
            processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_webhook_idempotency (provider, event, transaction_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->beginTransaction();

    try {
        $stmtIdempotency = $db->prepare("
            INSERT INTO webhook_idempotency (provider, event, transaction_id, compra_id)
            VALUES (?, ?, ?, ?)
        ");
        $stmtIdempotency->execute([$provider, $event, $transactionId, $compraId]);
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') {
            $db->rollBack();
            security_json([
                'success' => true,
                'message' => 'Webhook duplicado ignorado',
                'data' => [
                    'event' => $event,
                    'compra_id' => $compraId,
                    'transaction_id' => $transactionId,
                ],
            ]);
        }
        throw $e;
    }

    $stmt = $db->prepare('SELECT * FROM compras_planos WHERE id = ? FOR UPDATE');
    $stmt->execute([$compraId]);
    $compra = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$compra) {
        $db->rollBack();
        security_json(['success' => false, 'message' => 'Compra nao encontrada'], 404);
    }

    if (($compra['status'] ?? '') !== 'PENDENTE') {
        $db->commit();
        security_json(['success' => true, 'message' => 'Compra ja processada']);
    }

    $statusRejeitadoCompra = plan_security_purchase_status_value($db, 'REJECTED');

    $integrity = plan_security_purchase_integrity($compra);
    if (empty($integrity['ok'])) {
        $obs = 'Rejeitado por integridade: ' . ($integrity['message'] ?? 'valor/plano invalido');
        $stmtReject = $db->prepare(in_array('observacao', $columns, true)
            ? "UPDATE compras_planos SET status = ?, observacao = ? WHERE id = ?"
            : "UPDATE compras_planos SET status = ? WHERE id = ?");
        $stmtReject->execute(in_array('observacao', $columns, true) ? [$statusRejeitadoCompra, $obs, $compraId] : [$statusRejeitadoCompra, $compraId]);
        audit_log($db, 'plano_webhook_integridade_rejeitada', [
            'compra_id' => $compraId,
            'provider' => $provider,
            'transaction_id' => $transactionId,
            'integrity' => $integrity,
        ], 'CRITICAL');
        $db->commit();
        security_json(['success' => false, 'message' => 'Compra rejeitada por integridade'], 409);
    }

    $obs = 'Webhook ' . $provider . ' tx=' . ($transactionId !== '' ? $transactionId : '-');

    if ($event === 'payment.approved') {
        $cycle = plan_security_normalize_cycle($compra['ciclo'] ?? 'MENSAL');
        $newEndDate = (new DateTimeImmutable('today'))
            ->modify('+' . plan_security_days($cycle) . ' days')
            ->format('Y-m-d');

        $synced = plano_sincronizar_restaurante_plano(
            (int)$compra['restaurante_id'],
            (string)$compra['plano_novo'],
            $newEndDate,
            'PAGO',
            $obs,
            $db
        );

        if (!$synced) {
            throw new RuntimeException('Nao foi possivel sincronizar plano do restaurante.');
        }

        $setParts = ["status = 'APROVADO'"];
        $params = [];
        if (in_array('data_pagamento', $columns, true)) {
            $setParts[] = 'data_pagamento = NOW()';
        }
        if (in_array('observacao', $columns, true)) {
            $setParts[] = 'observacao = ?';
            $params[] = $obs;
        }
        $params[] = $compraId;
        $stmtUpdate = $db->prepare('UPDATE compras_planos SET ' . implode(', ', $setParts) . ' WHERE id = ?');
        $stmtUpdate->execute($params);

        audit_log($db, 'plano_webhook_aprovado', [
            'compra_id' => $compraId,
            'restaurante_id' => (int)$compra['restaurante_id'],
            'plano' => (string)$compra['plano_novo'],
            'ciclo' => $cycle,
            'data_fim' => $newEndDate,
            'provider' => $provider,
            'transaction_id' => $transactionId,
        ]);
    } elseif ($event === 'payment.rejected' || $event === 'payment.failed') {
        $stmtUpdate = $db->prepare(in_array('observacao', $columns, true)
            ? "UPDATE compras_planos SET status = ?, observacao = ? WHERE id = ?"
            : "UPDATE compras_planos SET status = ? WHERE id = ?");
        $stmtUpdate->execute(in_array('observacao', $columns, true) ? [$statusRejeitadoCompra, $obs, $compraId] : [$statusRejeitadoCompra, $compraId]);
        audit_log($db, 'plano_webhook_rejeitado', [
            'compra_id' => $compraId,
            'provider' => $provider,
            'transaction_id' => $transactionId,
        ]);
    } else {
        $db->rollBack();
        security_json(['success' => false, 'message' => 'Evento nao suportado'], 422);
    }

    $db->commit();

    security_json([
        'success' => true,
        'message' => 'Webhook processado',
        'data' => [
            'event' => $event,
            'compra_id' => $compraId,
        ],
    ]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Erro no webhook de plano: ' . $e->getMessage());
    security_json(['success' => false, 'message' => 'Erro interno no webhook'], 500);
}
