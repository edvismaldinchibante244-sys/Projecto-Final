<?php
require_once __DIR__ . '/../app_base.php';

/**
 * Job diario: notificar restaurantes com plano perto do vencimento.
 *
 * Exemplos:
 * php src/public/cron/notificar_vencimento_planos.php
 * php src/public/cron/notificar_vencimento_planos.php --dry-run
 * php src/public/cron/notificar_vencimento_planos.php --force --days=7
 */

include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/plano_notificacoes.php';
include_once APP_BASE . '/src/config/plano_lifecycle.php';
include_once APP_BASE . '/src/config/plano_downgrade.php';

$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    $token = trim((string)(getenv('CRON_HTTP_TOKEN') ?: ''));
    $given = trim((string)($_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? ''));
    if ($token === '' || !hash_equals($token, $given)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
        exit(1);
    }
}

function plano_cron_arg_value(string $name, $default = null)
{
    global $argv;
    foreach (($argv ?? []) as $arg) {
        if (strpos($arg, $name . '=') === 0) {
            return substr($arg, strlen($name) + 1);
        }
    }
    return $default;
}

function plano_cron_has_arg(string $name): bool
{
    global $argv;
    return in_array($name, $argv ?? [], true);
}

function plano_cron_out(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
}

$days = (int)plano_cron_arg_value('--days', 7);
$days = max(0, min(30, $days));
$dryRun = plano_cron_has_arg('--dry-run');
$force = plano_cron_has_arg('--force');
$restauranteId = plano_cron_arg_value('--restaurante-id');
$restauranteId = $restauranteId !== null ? max(1, (int)$restauranteId) : null;

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    http_response_code(500);
    plano_cron_out([
        'success' => false,
        'message' => 'Falha de conexao com banco'
    ]);
    exit;
}

try {
    plano_lifecycle_ensure_tables($db);
    $downgradesProcessados = $dryRun
        ? ['aplicados' => 0, 'bloqueados' => 0, 'cancelados' => 0]
        : plano_downgrade_processar_pendentes($db);
    $diasNotificacao = array_values(array_filter([7, 3, 1], static function ($d) use ($days) {
        return $d <= $days;
    }));
    if (!$diasNotificacao) {
        $diasNotificacao = [1];
    }

    $colunasRest = [];
    $stmtCols = $db->query('SHOW COLUMNS FROM restaurantes');
    while ($col = $stmtCols->fetch(PDO::FETCH_ASSOC)) {
        $colunasRest[] = $col['Field'];
    }

    $temDataFimPlano = in_array('data_fim_plano', $colunasRest, true);
    $temDataFim = in_array('data_fim', $colunasRest, true);
    if ($temDataFimPlano && $temDataFim) {
        $colunaFim = 'COALESCE(r.data_fim_plano, r.data_fim)';
    } elseif ($temDataFimPlano) {
        $colunaFim = 'r.data_fim_plano';
    } else {
        $colunaFim = 'r.data_fim';
    }

    $hasRestaurantePlanos = (bool)$db->query("SHOW TABLES LIKE 'restaurante_planos'")->fetchColumn();
    $joinPlano = '';
    $dataFimExpr = $colunaFim;
    if ($hasRestaurantePlanos) {
        $joinPlano = "
            LEFT JOIN (
                SELECT restaurante_id, MAX(data_fim) AS data_fim
                FROM restaurante_planos
                WHERE status = 'ATIVO'
                GROUP BY restaurante_id
            ) rp ON rp.restaurante_id = r.id
        ";
        $dataFimExpr = "COALESCE(rp.data_fim, {$colunaFim})";
    }

    $whereExtra = '';
    $params = [];
    if ($restauranteId !== null) {
        $whereExtra .= ' AND r.id = :restaurante_id';
        $params[':restaurante_id'] = $restauranteId;
    }

    $joinLog = $force || $dryRun ? '' : "
        LEFT JOIN plano_lifecycle_notificacoes pvn
          ON pvn.restaurante_id = r.id
         AND pvn.data_fim = DATE({$dataFimExpr})
         AND pvn.dias_restantes = DATEDIFF(DATE({$dataFimExpr}), CURDATE())
         AND pvn.canal = 'email'
    ";
    $whereLog = $force || $dryRun ? '' : ' AND pvn.id IS NULL';

    $diasInSql = implode(',', array_map('intval', $diasNotificacao));
    $filtroDiasSql = $force
        ? "DATEDIFF(DATE({$dataFimExpr}), CURDATE()) BETWEEN 0 AND :dias"
        : "DATEDIFF(DATE({$dataFimExpr}), CURDATE()) IN ({$diasInSql})";
    if ($force) {
        $params[':dias'] = $days;
    }
    $sql = "
        SELECT
            r.id,
            r.nome,
            r.email,
            r.telefone,
            r.plano,
            DATE({$dataFimExpr}) AS data_fim,
            DATEDIFF(DATE({$dataFimExpr}), CURDATE()) AS dias_restantes,
            (
                SELECT u.email
                FROM usuarios u
                WHERE u.restaurante_id = r.id
                  AND UPPER(u.perfil) = 'ADMIN'
                  AND u.email IS NOT NULL
                  AND u.email <> ''
                ORDER BY u.id ASC
                LIMIT 1
            ) AS admin_email
        FROM restaurantes r
        {$joinPlano}
        {$joinLog}
        WHERE {$dataFimExpr} IS NOT NULL
          AND UPPER(r.status) = 'ATIVO'
          AND {$filtroDiasSql}
          {$whereLog}
          {$whereExtra}
        ORDER BY dias_restantes ASC, r.id ASC
    ";

    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $enviadas = 0;
    $falhas = 0;
    $ignoradas = 0;
    $detalhes = [];
    $stmtLog = $db->prepare("
        INSERT IGNORE INTO plano_lifecycle_notificacoes
            (restaurante_id, data_fim, dias_restantes, canal, destinatario)
        VALUES
            (:restaurante_id, :data_fim, :dias_restantes, 'email', :destinatario)
    ");

    foreach ($rows as $r) {
        $emailDestino = trim((string)(!empty($r['admin_email']) ? $r['admin_email'] : ($r['email'] ?? '')));
        $diasRestantes = (int)($r['dias_restantes'] ?? 0);
        $item = [
            'restaurante_id' => (int)($r['id'] ?? 0),
            'restaurante' => $r['nome'] ?? 'Restaurante',
            'email' => $emailDestino,
            'data_fim' => $r['data_fim'] ?? null,
            'dias_restantes' => $diasRestantes,
        ];

        if ($emailDestino === '' || !filter_var($emailDestino, FILTER_VALIDATE_EMAIL)) {
            $falhas++;
            $item['status'] = 'falhou';
            $item['erro'] = 'Email de destino invalido ou vazio';
            $detalhes[] = $item;
            continue;
        }

        if ($dryRun) {
            $ignoradas++;
            $item['status'] = 'dry_run';
            $detalhes[] = $item;
            continue;
        }

        $ok = plano_notificar_vencimento_proximo(
            $emailDestino,
            (string)($r['telefone'] ?? ''),
            (string)($r['nome'] ?? 'Restaurante'),
            (string)($r['plano'] ?? 'BASICO'),
            (string)($r['data_fim'] ?? ''),
            $diasRestantes,
            plano_lifecycle_payment_url((int)$r['id'])
        );

        if ($ok) {
            $stmtLog->execute([
                ':restaurante_id' => (int)$r['id'],
                ':data_fim' => $r['data_fim'],
                ':dias_restantes' => $diasRestantes,
                ':destinatario' => $emailDestino,
            ]);
            $enviadas++;
            $item['status'] = 'enviado';
        } else {
            $falhas++;
            $item['status'] = 'falhou';
            $item['erro'] = function_exists('saas_email_get_last_error')
                ? (saas_email_get_last_error() ?: 'Falha desconhecida no envio')
                : 'Falha desconhecida no envio';
        }

        $detalhes[] = $item;
    }

    plano_cron_out([
        'success' => true,
        'message' => $dryRun ? 'Job simulado' : 'Job executado',
        'modo' => [
            'dry_run' => $dryRun,
            'force' => $force,
            'days' => $days,
            'restaurante_id' => $restauranteId,
        ],
        'total_alvos' => count($rows),
        'emails_enviados' => $enviadas,
        'emails_falharam' => $falhas,
        'ignorados_simulacao' => $ignoradas,
        'downgrades' => $downgradesProcessados,
        'detalhes' => $detalhes,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[notificar_vencimento_planos] ' . $e->getMessage());
    plano_cron_out([
        'success' => false,
        'message' => $isCli ? ('Erro no job: ' . $e->getMessage()) : 'Erro no job de notificacao'
    ]);
}

