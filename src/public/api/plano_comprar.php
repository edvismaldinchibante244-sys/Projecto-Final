<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Solicitar compra de plano.
   Seguranca: nao ativa plano pago diretamente pelo navegador.
   A ativacao ocorre somente por aprovacao do Super Admin ou webhook assinado.
*/

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/plano_check.php';
require_once APP_BASE . '/src/config/plano_upgrade_guard.php';
require_once APP_BASE . '/src/config/plan_security.php';
require_once APP_BASE . '/src/config/audit.php';
require_once APP_BASE . '/src/config/plano_lifecycle.php';
require_once APP_BASE . '/src/config/zumbopay.php';

header('Content-Type: application/json; charset=utf-8');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    security_json(['success' => false, 'message' => 'Metodo nao permitido'], 405);
}

$ctx = api_guard([
    'roles' => ['ADMIN', 'GERENTE'],
    'rate_key' => 'plano_comprar',
    'rate_max' => 20,
    'rate_window' => 300,
    'skip_turno' => true,
]);

try {
    $database = new Database();
    $db = $database->getConnection();

    $restaurantePlanoId = plano_upgrade_restaurante_alvo_id($db);
    if (!plano_upgrade_usuario_pode_gerir($db, (int)$ctx['usuario_id'], $restaurantePlanoId)) {
        audit_log($db, 'plano_compra_negada', ['motivo' => 'usuario_sem_permissao'], 'WARN');
        security_json(['success' => false, 'message' => 'Apenas o dono/administrador principal do restaurante pode solicitar planos.'], 403);
    }

    $stmt = $db->prepare('SELECT id, nome, email, telefone, plano FROM restaurantes WHERE id = ? LIMIT 1');
    $stmt->execute([$restaurantePlanoId]);
    $restaurante = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$restaurante) {
        security_json(['success' => false, 'message' => 'Restaurante nao encontrado.'], 404);
    }

    $validation = plan_security_validate_request(
        (string)($restaurante['plano'] ?? 'BASICO'),
        (string)($_POST['plano'] ?? ''),
        (string)($_POST['ciclo'] ?? 'MENSAL'),
        (string)($_POST['metodo'] ?? ''),
        false,
        $db,
        $restaurantePlanoId
    );

    if (empty($validation['ok'])) {
        audit_log($db, 'plano_compra_invalida', [
            'motivo' => $validation['message'] ?? 'invalid',
            'plano_recebido' => $_POST['plano'] ?? null,
            'ciclo_recebido' => $_POST['ciclo'] ?? null,
            'metodo_recebido' => $_POST['metodo'] ?? null,
        ], 'WARN');
        security_json(['success' => false, 'message' => $validation['message']], 422);
    }

    $stmtPend = $db->prepare("SELECT COUNT(*) FROM compras_planos WHERE restaurante_id = ? AND status IN ('AGUARDANDO_APROVACAO', 'PENDENTE')");
    $stmtPend->execute([$restaurantePlanoId]);
    if ((int)$stmtPend->fetchColumn() > 0) {
        security_json(['success' => false, 'message' => 'Ja existe uma solicitacao pendente. Aguarde a analise do super admin.'], 409);
    }

    $columns = plan_security_table_columns($db, 'compras_planos');

    $statusPendenteCompra = plan_security_purchase_status_value($db, 'PENDING');

    $insertCols = ['restaurante_id', 'plano_atual', 'plano_novo', 'valor', 'metodo_pagamento', 'status'];
    $insertVals = ['?', '?', '?', '?', '?', '?'];
    $params = [
        $restaurantePlanoId,
        (string)($restaurante['plano'] ?? 'BASICO'),
        $validation['plan'],
        $validation['price'],
        $validation['method'],
        $statusPendenteCompra,
    ];

    if (in_array('ciclo', $columns, true)) {
        $insertCols[] = 'ciclo';
        $insertVals[] = '?';
        $params[] = $validation['cycle'];
    }
    if (in_array('observacao', $columns, true)) {
        $insertCols[] = 'observacao';
        $insertVals[] = '?';
        $params[] = 'Solicitacao criada pelo painel do restaurante; aguardando validacao segura.';
    }
    if (in_array('created_at', $columns, true)) {
        $insertCols[] = 'created_at';
        $insertVals[] = 'NOW()';
    } elseif (in_array('criado_em', $columns, true)) {
        $insertCols[] = 'criado_em';
        $insertVals[] = 'NOW()';
    }

    $stmtIns = $db->prepare('INSERT INTO compras_planos (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $insertVals) . ')');
    $stmtIns->execute($params);
    $compraId = (int)$db->lastInsertId();

    $checkoutUrl = '';
    // Quando ZumboPay esta configurado, M-Pesa abre o checkout hospedado e a
    // aprovacao continua exclusivamente pelo webhook assinado.
    if ($validation['method'] === 'MPESA' && zumbopay_is_configured()) {
        try {
            $checkout = zumbopay_create_plan_checkout(
                $compraId,
                (float)$validation['price'],
                'Plano ' . $validation['plan'] . ' - ' . $validation['cycle']
            );
            $refColumn = in_array('referencia_pagamento', $columns, true) ? 'referencia_pagamento' : 'referencia';
            $stmtRef = $db->prepare('UPDATE compras_planos SET ' . $refColumn . ' = ?, observacao = ? WHERE id = ?');
            $stmtRef->execute([
                $checkout['reference'],
                'Checkout ZumboPay criado; aguardando confirmacao segura.',
                $compraId,
            ]);
            $checkoutUrl = $checkout['checkout_url'];
        } catch (Throwable $gatewayError) {
            error_log('plano_comprar ZumboPay: ' . $gatewayError->getMessage());
            security_json(['success' => false, 'message' => 'Nao foi possivel iniciar o pagamento ZumboPay. Tente novamente mais tarde.'], 502);
        }
    }

    audit_log($db, 'plano_compra_solicitada', [
        'compra_id' => $compraId,
        'restaurante_id' => $restaurantePlanoId,
        'plano_atual' => (string)($restaurante['plano'] ?? 'BASICO'),
        'plano_novo' => $validation['plan'],
        'ciclo' => $validation['cycle'],
        'valor' => $validation['price'],
        'metodo' => $validation['method'],
    ]);
    plano_lifecycle_log($db, (int)$restaurantePlanoId, 'tentativa_pagamento', [
        'compra_id' => $compraId,
        'tipo' => 'solicitacao_compra',
        'plano_novo' => $validation['plan'],
        'ciclo' => $validation['cycle'],
        'valor' => $validation['price'],
        'metodo' => $validation['method'],
    ]);

    security_json([
        'success' => true,
        'message' => 'Solicitacao enviada com sucesso. O plano sera ativado apos confirmacao segura do pagamento.',
        'data' => [
            'compra_id' => $compraId,
            'plano' => $validation['plan'],
            'valor' => $validation['price'],
            'ciclo' => $validation['cycle'],
            'status' => $statusPendenteCompra,
            'checkout_url' => $checkoutUrl,
        ],
    ]);
} catch (Throwable $e) {
    error_log('plano_comprar: ' . $e->getMessage());
    security_json(['success' => false, 'message' => 'Erro interno ao solicitar plano.'], 500);
}
