<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');
if (ob_get_level() === 0) {
    ob_start();
}

require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/plano_check.php';
require_once APP_BASE . '/src/config/super_admin_permissions.php';
require_once APP_BASE . '/src/config/plan_security.php';
require_once APP_BASE . '/src/config/audit.php';
require_once APP_BASE . '/src/config/plano_notificacoes.php';
require_once APP_BASE . '/src/config/email_helper.php';
require_once APP_BASE . '/src/config/password_reset_helper.php';
require_once APP_BASE . '/src/config/plano_lifecycle.php';
require_once APP_BASE . '/src/config/filiais_helper.php';

if (file_exists(APP_BASE . '/vendor/autoload.php')) {
    require_once APP_BASE . '/vendor/autoload.php';
}

header('Content-Type: application/json; charset=utf-8');

function super_admin_plano_json(array $payload, int $statusCode = 200): void
{
    if (ob_get_length()) {
        ob_clean();
    }

    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function super_admin_plano_log(string $nivel, string $mensagem, array $ctx = []): void
{
    error_log('[PLANO_APROVACAO][' . $nivel . '] ' . $mensagem . ' | ' . json_encode($ctx, JSON_UNESCAPED_UNICODE));
}

$isSuperAdmin = isset($_SESSION['logado'], $_SESSION['super_admin'])
    && $_SESSION['logado'] === true
    && (int)$_SESSION['super_admin'] === 1;

if (!$isSuperAdmin) {
    super_admin_plano_json(['success' => false, 'message' => 'Acesso negado'], 403);
}

if (!super_admin_has_permission('approve_plans')) {
    super_admin_plano_json(['success' => false, 'message' => 'Sem permissão para executar esta ação'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    super_admin_plano_json(['success' => false, 'message' => 'Método não permitido'], 405);
}

api_guard([
    'rate_key' => 'super_admin_plano_aprovar',
    'rate_max' => 30,
    'rate_window' => 60,
    'skip_turno' => true,
]);

$requestId = 'approve_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
$log = static function ($nivel, $mensagem, array $ctx = []) use ($requestId): void {
    $ctx['request_id'] = $requestId;
    super_admin_plano_log($nivel, $mensagem, $ctx);
};

$compraId = (int)($_POST['compra_id'] ?? 0);
$acao = trim((string)($_POST['acao'] ?? ''));
$observacao = trim((string)($_POST['observacao'] ?? ''));

if ($compraId <= 0) {
    super_admin_plano_json(['success' => false, 'message' => 'ID inválido'], 422);
}

if (!in_array($acao, ['aprovar', 'rejeitar'], true)) {
    super_admin_plano_json(['success' => false, 'message' => 'Ação inválida'], 422);
}

try {
    $database = new Database();
    $db = $database->getConnection();
    plan_security_ensure_purchase_hardening_columns($db);

    $colunasCompra = [];
    $stmtCols = $db->query('SHOW COLUMNS FROM compras_planos');
    while ($col = $stmtCols->fetch(PDO::FETCH_ASSOC)) {
        $colunasCompra[] = (string)$col['Field'];
    }

    $colunasRestaurante = [];
    $stmtColsRest = $db->query('SHOW COLUMNS FROM restaurantes');
    while ($col = $stmtColsRest->fetch(PDO::FETCH_ASSOC)) {
        $colunasRestaurante[] = (string)$col['Field'];
    }

    $campoTelefone = in_array('telefone', $colunasRestaurante, true)
        ? 'r.telefone'
        : "''";

    $db->beginTransaction();
    $stmt = $db->prepare("
        SELECT cp.*, r.nome as restaurante_nome, r.email as restaurante_email, {$campoTelefone} as restaurante_telefone,
               (SELECT u.email FROM usuarios u WHERE u.restaurante_id = cp.restaurante_id AND u.perfil = 'ADMIN' ORDER BY u.id ASC LIMIT 1) AS admin_email
        FROM compras_planos cp
        INNER JOIN restaurantes r ON cp.restaurante_id = r.id
        WHERE cp.id = ?
        FOR UPDATE
    ");
    $stmt->execute([$compraId]);
    $compra = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$compra) {
        $db->rollBack();
        super_admin_plano_json(['success' => false, 'message' => 'Compra não encontrada'], 404);
    }

    $stmtAdmin = $db->prepare("
        SELECT id, nome, email, ativo, senha
        FROM usuarios
        WHERE restaurante_id = ? AND perfil = 'ADMIN'
        ORDER BY id ASC
        LIMIT 1
    ");
    $stmtAdmin->execute([(int)$compra['restaurante_id']]);
    $adminUser = $stmtAdmin->fetch(PDO::FETCH_ASSOC) ?: null;

    $compra['admin_email'] = trim((string)($adminUser['email'] ?? $compra['admin_email'] ?? ''));
    $compra['admin_nome'] = trim((string)($adminUser['nome'] ?? 'Administrador'));
    $compra['restaurante_email'] = trim((string)($compra['restaurante_email'] ?? ''));
    $compra['contato_email'] = $compra['restaurante_email'] !== '' ? $compra['restaurante_email'] : $compra['admin_email'];
    $compra['password_setup_email'] = $compra['admin_email'] !== '' ? $compra['admin_email'] : $compra['contato_email'];

    $passwordSetupRequiredPreview = $adminUser
        && (((int)($adminUser['ativo'] ?? 0) !== 1) || trim((string)($adminUser['senha'] ?? '')) === '');

    if ($acao === 'aprovar' && $passwordSetupRequiredPreview) {
        password_reset_ensure_table($db);
    }

    if (!in_array((string)($compra['status'] ?? ''), ['PENDENTE', 'AGUARDANDO_APROVACAO'], true)) {
        $db->rollBack();
        super_admin_plano_json(['success' => false, 'message' => 'Esta compra já foi processada'], 409);
    }

    $integridadeCompra = plan_security_purchase_integrity($compra);
    if (empty($integridadeCompra['ok'])) {
        audit_log($db, 'super_admin_plano_integridade_rejeitada', [
            'compra_id' => $compraId,
            'integridade' => $integridadeCompra,
            'request_id' => $requestId,
        ], 'CRITICAL');
        $db->rollBack();
        super_admin_plano_json([
            'success' => false,
            'message' => 'Compra bloqueada: valor/plano nao confere com a tabela oficial.',
            'request_id' => $requestId,
        ], 409);
    }

    $compraIsTrial = plan_security_is_trial_purchase($compra);
    if ($acao === 'aprovar' && in_array('hash_arquivo', $colunasCompra, true) && !$compraIsTrial) {
        $hashArquivo = strtolower(trim((string)($compra['hash_arquivo'] ?? '')));
        if ($hashArquivo === '') {
            $db->rollBack();
            super_admin_plano_json([
                'success' => false,
                'message' => 'Compra bloqueada: comprovativo sem hash de integridade.',
                'request_id' => $requestId,
            ], 409);
        }

        $stmtDup = $db->prepare("
            SELECT id, restaurante_id, status
              FROM compras_planos
             WHERE hash_arquivo = :hash
               AND id <> :id
               AND status IN ('AGUARDANDO_APROVACAO', 'PENDENTE', 'APROVADO')
             ORDER BY id DESC
             LIMIT 1
        ");
        $stmtDup->execute([
            ':hash' => $hashArquivo,
            ':id' => $compraId,
        ]);
        $dup = $stmtDup->fetch(PDO::FETCH_ASSOC);
        if ($dup) {
            audit_log($db, 'super_admin_plano_fraude_comprovativo_duplicado', [
                'compra_id' => $compraId,
                'restaurante_id' => (int)$compra['restaurante_id'],
                'hash_arquivo' => $hashArquivo,
                'compra_conflitante_id' => (int)($dup['id'] ?? 0),
                'restaurante_conflitante_id' => (int)($dup['restaurante_id'] ?? 0),
                'request_id' => $requestId,
            ], 'CRITICAL');
            $db->rollBack();
            super_admin_plano_json([
                'success' => false,
                'message' => 'Compra bloqueada: comprovativo duplicado detectado.',
                'request_id' => $requestId,
            ], 409);
        }
    }

    if ($acao === 'rejeitar') {
        $statusRejeitadoCompra = plan_security_purchase_status_value($db, 'REJECTED');
        $obsFinal = $observacao !== '' ? $observacao : 'Rejeitado pelo Super Admin';

        if (in_array('observacao', $colunasCompra, true)) {
            $stmt = $db->prepare("UPDATE compras_planos SET status = ?, observacao = ? WHERE id = ? AND status IN ('PENDENTE', 'AGUARDANDO_APROVACAO')");
            $stmt->execute([$statusRejeitadoCompra, $obsFinal, $compraId]);
        } else {
            $stmt = $db->prepare("UPDATE compras_planos SET status = ? WHERE id = ? AND status IN ('PENDENTE', 'AGUARDANDO_APROVACAO')");
            $stmt->execute([$statusRejeitadoCompra, $compraId]);
        }
        if ($stmt->rowCount() < 1) {
            $db->rollBack();
            super_admin_plano_json(['success' => false, 'message' => 'Esta compra jÃ¡ foi processada'], 409);
        }

        $emailRejeicaoEnviado = false;
        $warningMessage = null;
        if (!empty($compra['contato_email'])) {
            $emailRejeicaoEnviado = plano_notificar_rejeitado(
                (string)$compra['contato_email'],
                (string)($compra['restaurante_telefone'] ?? ''),
                (string)($compra['restaurante_nome'] ?? 'Restaurante'),
                (string)($compra['plano_novo'] ?? ''),
                $obsFinal
            );

            if (!$emailRejeicaoEnviado) {
                $emailErrorDetail = function_exists('saas_email_get_last_error') ? saas_email_get_last_error() : null;
                $warningMessage = 'Compra rejeitada, mas não foi possível enviar o email de notificação.';
                if (is_string($emailErrorDetail) && $emailErrorDetail !== '') {
                    $warningMessage .= ' Detalhe técnico: ' . $emailErrorDetail;
                }
            }
        } else {
            $warningMessage = 'Compra rejeitada, mas o restaurante não possui email para notificação.';
        }

        $response = [
            'success' => true,
            'message' => 'Compra rejeitada',
            'data' => [
                'email_enviado' => $emailRejeicaoEnviado === true,
                'request_id' => $requestId,
            ],
        ];

        if ($warningMessage !== null) {
            $response['warning'] = $warningMessage;
        }

        $log('INFO', 'fluxo de rejeição processado', [
            'compra_id' => $compraId,
            'email' => (string)($compra['contato_email'] ?? ''),
            'email_enviado' => $emailRejeicaoEnviado,
            'warning' => $warningMessage,
        ]);
        audit_log($db, 'super_admin_plano_rejeitado', [
            'compra_id' => $compraId,
            'restaurante_id' => (int)$compra['restaurante_id'],
            'observacao' => $obsFinal,
            'request_id' => $requestId,
        ]);
        plano_lifecycle_log($db, (int)$compra['restaurante_id'], 'pagamento_rejeitado', [
            'compra_id' => $compraId,
            'motivo' => $obsFinal,
        ], 'WARN');

        $db->commit();
        super_admin_plano_json($response);
    }

    $passwordSetupLink = null;
    $passwordSetupExpiresAt = null;
    $passwordSetupRequired = $passwordSetupRequiredPreview;

    $obsFinal = $observacao !== '' ? $observacao : 'Aprovado pelo Super Admin';
    $setParts = ["status = 'APROVADO'"];
    $paramsCompra = [];

    if (in_array('data_pagamento', $colunasCompra, true)) {
        $setParts[] = 'data_pagamento = NOW()';
    }

    if (in_array('observacao', $colunasCompra, true)) {
        $setParts[] = 'observacao = ?';
        $paramsCompra[] = $obsFinal;
    }

    $paramsCompra[] = $compraId;
    $stmt = $db->prepare("UPDATE compras_planos SET " . implode(', ', $setParts) . " WHERE id = ? AND status IN ('PENDENTE', 'AGUARDANDO_APROVACAO')");
    $stmt->execute($paramsCompra);
    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('Compra ja processada por outra operacao.');
    }

    $cicloCompra = strtoupper((string)($compra['ciclo'] ?? 'MENSAL'));
    if (!in_array($cicloCompra, ['MENSAL', 'TRIMESTRAL', 'ANUAL'], true) && !empty($compra['metodo_pagamento'])) {
        $partes = explode('-', (string)$compra['metodo_pagamento']);
        $possivelCiclo = strtoupper(trim((string)end($partes)));
        if (in_array($possivelCiclo, ['MENSAL', 'TRIMESTRAL', 'ANUAL'], true)) {
            $cicloCompra = $possivelCiclo;
        }
    }

    if ($compraIsTrial) {
        $diasPlano = plan_security_purchase_trial_days($compra) ?: 30;
    } else {
        $diasPlano = ['MENSAL' => 30, 'TRIMESTRAL' => 90, 'ANUAL' => 365][$cicloCompra] ?? 30;
    }

    $baseDate = new DateTimeImmutable('today');
    $novaDataFim = $baseDate->modify('+' . $diasPlano . ' days')->format('Y-m-d');

    $sincronizado = plano_sincronizar_restaurante_plano(
        (int)$compra['restaurante_id'],
        (string)$compra['plano_novo'],
        $novaDataFim,
        $compraIsTrial ? 'TRIAL' : 'PAGO',
        'Aprovado pelo Super Admin',
        $db
    );

    $filiaisSincronizadas = filiais_sincronizar_plano_status_matriz_para_filiais(
        $db,
        (int)$compra['restaurante_id']
    );

    if (!$sincronizado) {
        $log('WARN', 'falha ao sincronizar plano auxiliar, aprovacao principal mantida', [
            'compra_id' => $compraId,
            'restaurante_id' => (int)$compra['restaurante_id'],
        ]);
    }

    $stmt = $db->prepare("UPDATE usuarios SET ativo = 1 WHERE restaurante_id = ? AND perfil = 'ADMIN'");
    $stmt->execute([(int)$compra['restaurante_id']]);

    if ($passwordSetupRequired && $compra['password_setup_email'] !== '') {
        $resetData = password_reset_create_token(
            $db,
            (string)$compra['password_setup_email'],
            '+2 hours',
            $adminUser ? (int)$adminUser['id'] : null
        );
        $passwordSetupLink = password_reset_build_link($resetData['token']);
        $passwordSetupExpiresAt = $resetData['expires_at'];
    }

    $db->commit();

    $emailEnviado = null;
    $warningMessage = null;

    if ($passwordSetupRequired) {
        if (empty($compra['password_setup_email'])) {
            $warningMessage = 'Plano aprovado, mas o administrador do restaurante não possui email para enviar o link de definição de senha.';
        } elseif ($passwordSetupLink === null) {
            $warningMessage = 'Plano aprovado, mas não foi possível preparar o link seguro para definir a senha.';
        } else {
            $nomeRestaurante = (string)($compra['restaurante_nome'] ?? 'Seu Restaurante');
            $nomeAdmin = (string)($compra['admin_nome'] ?: '');
            $emailAdmin = (string)$compra['password_setup_email'];

            $emailParts = password_reset_compose_email([
                'nome' => $nomeAdmin,
                'restaurante' => $nomeRestaurante,
                'email_conta' => $emailAdmin,
                'link' => (string)$passwordSetupLink,
                'expires_hours' => 2,
                'modo' => 'ativacao',
            ]);
            $mensagemEmail = $emailParts['html'];

            $emailEnviado = saas_enviar_email($emailAdmin, $emailParts['subject'], $mensagemEmail);
            if (!$emailEnviado) {
                $emailErrorDetail = function_exists('saas_email_get_last_error') ? saas_email_get_last_error() : null;
                $warningMessage = 'Plano aprovado, mas não foi possível enviar o email com o link seguro para definir a senha.';
                if (is_string($emailErrorDetail) && $emailErrorDetail !== '') {
                    $warningMessage .= ' Detalhe técnico: ' . $emailErrorDetail;
                }
            }
        }
    }

    $mensagemResposta = 'Plano ativado com sucesso para ' . (string)$compra['restaurante_nome'] . '!';
    if ($passwordSetupRequired && $emailEnviado) {
        $mensagemResposta .= ' Um link seguro para definir a senha foi enviado ao email do administrador.';
    }

    $responseData = [
        'success' => true,
        'message' => $mensagemResposta,
        'data' => [
            'plano_ativado' => (string)$compra['plano_novo'],
            'restaurante' => (string)$compra['restaurante_nome'],
            'restaurante_id' => (int)$compra['restaurante_id'],
            'request_id' => $requestId,
            'requer_senha' => $passwordSetupRequired,
            'email_enviado' => $emailEnviado === true,
            'password_setup_email' => $passwordSetupRequired ? (string)($compra['password_setup_email'] ?? '') : '',
            'filiais_sincronizadas' => (int)$filiaisSincronizadas,
        ],
    ];

    if ($warningMessage !== null) {
        $responseData['warning'] = $warningMessage;
    }

    if ($passwordSetupLink !== null) {
        $responseData['data']['password_setup_url'] = $passwordSetupLink;
    }

    if ($passwordSetupExpiresAt !== null) {
        $responseData['data']['password_setup_expires_at'] = $passwordSetupExpiresAt;
    }

    audit_log($db, 'super_admin_plano_aprovado', [
        'compra_id' => $compraId,
        'restaurante_id' => (int)$compra['restaurante_id'],
        'plano' => (string)$compra['plano_novo'],
        'valor' => (float)$compra['valor'],
        'request_id' => $requestId,
    ]);
    plano_lifecycle_log($db, (int)$compra['restaurante_id'], 'pagamento_confirmado', [
        'compra_id' => $compraId,
        'plano' => (string)$compra['plano_novo'],
        'valor' => (float)$compra['valor'],
    ], 'INFO');
    plano_lifecycle_evaluate_restaurante($db, (int)$compra['restaurante_id']);
    plano_lifecycle_log($db, (int)$compra['restaurante_id'], 'desbloqueio_automatico', [
        'motivo' => 'pagamento_confirmado',
        'compra_id' => $compraId,
    ], 'INFO');

    super_admin_plano_json($responseData);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }

    $log('ERROR', 'falha ao aprovar plano', [
        'compra_id' => $compraId,
        'acao' => $acao,
        'erro' => $e->getMessage(),
        'request_id' => $requestId,
    ]);

    super_admin_plano_json([
        'success' => false,
        'message' => 'Erro ao processar aprovação de plano.',
        'request_id' => $requestId,
    ], 500);
}
