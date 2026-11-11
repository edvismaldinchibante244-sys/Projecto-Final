<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Atualizar Status do Pedido
 */

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/plano_check.php';
require_once APP_BASE . '/src/config/offline_idempotency.php';
require_once APP_BASE . '/src/config/pedido_schema.php';
require_once APP_BASE . '/src/config/autorizacao_gerente.php';
require_once APP_BASE . '/src/api/notificar_cliente_whatsapp.php';

header('Content-Type: application/json');

api_guard([
    'roles' => ['ADMIN', 'GERENTE', 'CAIXA', 'GARCOM', 'COZINHA', 'BAR'],
    'rate_key' => 'pedido_status',
    'rate_max' => 120,
    'rate_window' => 60,
]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['restaurante_id'])) {
        echo json_encode(array("success" => false, "message" => "Nao autenticado"));
        exit;
    }

    if (empty($_POST['id']) || empty($_POST['status'])) {
        echo json_encode(array("success" => false, "message" => "Dados incompletos"));
        exit;
    }

    $status_in = strtoupper(trim((string)($_POST['status'] ?? '')));
    $status_map = [
        'PENDENTE' => 'NOVO',
        'CONFIRMADO' => 'PREPARANDO',
        'NOVO' => 'NOVO',
        'PREPARANDO' => 'PREPARANDO',
        'PRONTO' => 'PRONTO',
        'ENTREGUE' => 'ENTREGUE',
        'CONTA' => 'CONTA',
        'CONTA_SOLICITADA' => 'CONTA',
        'PEDIR_CONTA' => 'CONTA',
        'CANCELADO' => 'CANCELADO',
    ];

    if (!isset($status_map[$status_in])) {
        if ($status_in === 'PAGO') {
            echo json_encode(array("success" => false, "message" => "Use o fecho de pagamento para marcar como PAGO"));
            exit;
        }
        echo json_encode(array("success" => false, "message" => "Status invalido"));
        exit;
    }

    $status_final = $status_map[$status_in];

    $perfil = strtoupper(trim((string)($_SESSION['perfil'] ?? '')));
    if ($perfil === 'GARÇOM' || $perfil === 'OPERADOR') {
        $perfil = 'GARCOM';
    }

    // Transições por perfil (fluxo profissional)
    $permitidosPorPerfil = [
        'ADMIN' => ['NOVO', 'PREPARANDO', 'PRONTO', 'ENTREGUE', 'CONTA', 'CANCELADO'],
        'GERENTE' => ['NOVO', 'PREPARANDO', 'PRONTO', 'ENTREGUE', 'CONTA', 'CANCELADO'],
        'COZINHA' => ['PREPARANDO', 'PRONTO', 'ENTREGUE', 'CONTA'],
        'BAR' => ['PREPARANDO', 'PRONTO', 'ENTREGUE', 'CONTA'],
        // Garcom/caixa podem cancelar, mas so com autorizacao do gerente (ver abaixo).
        'GARCOM' => ['ENTREGUE', 'CONTA', 'CANCELADO'],
        'CAIXA' => ['CONTA', 'CANCELADO'],
    ];
    $permitidos = $permitidosPorPerfil[$perfil] ?? [];
    if (!in_array($status_final, $permitidos, true)) {
        echo json_encode(array("success" => false, "message" => "Perfil sem permissao para este estado"));
        exit;
    }

    $database = new Database();
    $db = $database->getConnection();
    pedido_schema_garantir($db);
    offline_idempotency_prepare($db);
    $pedido_id = (int)$_POST['id'];
    $rid = (int)$_SESSION['restaurante_id'];

    $pedidoAtual = resource_require_pedido($db, $pedido_id, $rid, ['id', 'status']);
    $statusAtual = strtoupper(trim((string)($pedidoAtual['status'] ?? '')));
    if ($statusAtual === 'PAGO' || $statusAtual === 'CANCELADO') {
        if ($statusAtual === $status_final) {
            echo json_encode(array(
                "success" => true,
                "message" => "Pedido ja estava nesse estado.",
                "status" => $statusAtual
            ));
            exit;
        }
        echo json_encode(array("success" => false, "message" => "Pedido ja encerrado"));
        exit;
    }

    $transicoes = [
        'NOVO' => ['PREPARANDO', 'CANCELADO'],
        'PENDENTE' => ['PREPARANDO', 'CANCELADO'],
        'CONFIRMADO' => ['PREPARANDO', 'CANCELADO'],
        'PREPARANDO' => ['PRONTO', 'CANCELADO'],
        'PRONTO' => ['ENTREGUE', 'CONTA', 'CANCELADO'],
        'ENTREGUE' => ['CONTA', 'CANCELADO'],
        'CONTA' => ['CANCELADO'],
        'CONTA_SOLICITADA' => ['CANCELADO'],
    ];
    $prox = $transicoes[$statusAtual] ?? null;
    if ($prox !== null && !in_array($status_final, $prox, true) && $status_final !== $statusAtual) {
        // ADMIN/GERENTE pode corrigir estado em casos operacionais
        if (!in_array($perfil, ['ADMIN', 'GERENTE'], true)) {
            echo json_encode(array(
                "success" => false,
                "message" => "Transicao invalida de {$statusAtual} para {$status_final}"
            ));
            exit;
        }
    }

    if ($status_final === 'CANCELADO') {
        // Garcom/caixa: so com o PIN de um gerente (ou palavra-passe do administrador).
        autorizacao_gerente_exigir('cancelar_pedido', $pedido_id);
    }

    try {
        $db->beginTransaction();
        $idempotency = offline_idempotency_begin(
            $db,
            $rid,
            (int)($_SESSION['usuario_id'] ?? 0),
            'api/pedido_status.php',
            $_POST
        );
        offline_idempotency_replay($db, $idempotency);

        $timestampSql = '';
        if ($status_final === 'PREPARANDO') {
            $timestampSql = ", iniciado_preparo_em = COALESCE(iniciado_preparo_em, NOW())";
        } elseif ($status_final === 'PRONTO') {
            $timestampSql = ", iniciado_preparo_em = COALESCE(iniciado_preparo_em, NOW()), pronto_em = COALESCE(pronto_em, NOW())";
        } elseif ($status_final === 'ENTREGUE') {
            $timestampSql = ", entregue_em = COALESCE(entregue_em, NOW())";
        } elseif ($status_final === 'CONTA') {
            $timestampSql = ", entregue_em = COALESCE(entregue_em, NOW()), conta_em = COALESCE(conta_em, NOW())";
        } elseif ($status_final === 'CANCELADO') {
            $timestampSql = ", cancelado_em = COALESCE(cancelado_em, NOW())";
        }

    $query = "UPDATE pedidos SET status = :status, atualizado_em = NOW(){$timestampSql}
              WHERE id = :id AND restaurante_id = :rid";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':status', $status_final);
    $stmt->bindParam(':id', $pedido_id, PDO::PARAM_INT);
    $stmt->bindParam(':rid', $rid, PDO::PARAM_INT);

    if ($stmt->execute()) {
        // Pedido cancelado: se a mesa nao tiver mais nenhum pedido em aberto, fica livre.
        if ($status_final === 'CANCELADO') {
            $libertar = $db->prepare("UPDATE mesas m
                JOIN pedidos p ON p.mesa_id = m.id AND p.id = :pid AND p.restaurante_id = :rid
                SET m.status = 'LIVRE'
                WHERE m.restaurante_id = :rid2 AND UPPER(m.status) = 'OCUPADA'
                  AND NOT EXISTS (SELECT 1 FROM pedidos o WHERE o.mesa_id = m.id AND o.restaurante_id = :rid3 AND o.id <> :pid2
                                  AND UPPER(o.status) NOT IN ('PAGO', 'CANCELADO'))");
            $libertar->execute([':pid' => $pedido_id, ':rid' => $rid, ':rid2' => $rid, ':rid3' => $rid, ':pid2' => $pedido_id]);
        }
        $response = array("success" => true, "message" => "Pedido atualizado!", "status" => $status_final);
        offline_idempotency_complete($db, $idempotency, $response);
        $db->commit();

        // Notificar cliente de pedidos QR quando o plano tem notificações
        try {
            if (function_exists('plano_tem_funcionalidade_db')
                && plano_tem_funcionalidade_db($rid, 'notificacoes')
                && function_exists('notificar_cliente_whatsapp')
            ) {
                $notifyStatuses = ['PREPARANDO', 'PRONTO', 'ENTREGUE', 'CONTA', 'CANCELADO'];
                if (in_array($status_final, $notifyStatuses, true)) {
                    $stmtMeta = $db->prepare("
                        SELECT m.cliente_telefone, m.cliente_nome, p.numero_pedido, p.origem
                        FROM pedido_qr_meta m
                        INNER JOIN pedidos p ON p.id = m.pedido_id AND p.restaurante_id = m.restaurante_id
                        WHERE m.pedido_id = :pid AND m.restaurante_id = :rid
                        LIMIT 1
                    ");
                    $stmtMeta->execute([':pid' => $pedido_id, ':rid' => $rid]);
                    $meta = $stmtMeta->fetch(PDO::FETCH_ASSOC);
                    $telefone = trim((string)($meta['cliente_telefone'] ?? ''));
                    if ($telefone !== '') {
                        $numero = (string)($meta['numero_pedido'] ?? $pedido_id);
                        $labels = [
                            'PREPARANDO' => 'está em preparo',
                            'PRONTO' => 'está pronto',
                            'ENTREGUE' => 'foi entregue',
                            'CONTA' => '— a conta foi solicitada',
                            'CANCELADO' => 'foi cancelado',
                        ];
                        $label = $labels[$status_final] ?? ('atualizado para ' . $status_final);
                        $msg = 'Pedido #' . $numero . ' ' . $label . '.';
                        notificar_cliente_whatsapp($telefone, $msg);
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('[PEDIDO_STATUS][WA] ' . $e->getMessage());
        }

        echo json_encode($response);
    } else {
        throw new RuntimeException('Erro ao atualizar pedido');
    }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $isBusinessError = $e instanceof DomainException;
        http_response_code($isBusinessError ? 409 : 500);
        echo json_encode(array(
            "success" => false,
            "message" => security_exception_message(
                $e,
                "Nao foi possivel atualizar o pedido",
                'pedido_status_failed'
            ),
        ));
    }
} else {
    echo json_encode(array("success" => false, "message" => "Metodo nao permitido"));
}
