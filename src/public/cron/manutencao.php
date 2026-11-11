<?php
require_once __DIR__ . '/../app_base.php';

/**
 * Rotina diaria de manutencao (desempenho com muitos restaurantes).
 *  1) Resumo diario de vendas: recalcula os ultimos 3 dias e preenche dias em falta (ate 60 por execucao).
 *  2) Limpeza de registos tecnicos antigos, em lotes pequenos para nao bloquear a base de dados:
 *     - auditoria (audit_logs): 365 dias;   - eventos de seguranca: 180 dias;
 *     - limites de tentativas expirados;    - codigos 2FA e links de recuperacao expirados (7 dias);
 *     - fila offline ja sincronizada: 30 dias.
 *
 *   php src/public/cron/manutencao.php --simular   (so mostra o que faria)
 *   php src/public/cron/manutencao.php
 * Agendar uma vez por dia, de madrugada (ver scripts/cron/run_manutencao_cron.bat).
 */
include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/vendas_resumo.php';
include_once APP_BASE . '/src/config/trabalhos_fila.php';
include_once APP_BASE . '/src/config/sessao_retomar.php';

$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    $token = trim((string)(getenv('CRON_HTTP_TOKEN') ?: ''));
    $given = trim((string)($_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? ''));
    if ($token === '' || !hash_equals($token, $given)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit(1);
    }
}
@set_time_limit(600);
$simular = $isCli ? in_array('--simular', $argv ?? [], true) : isset($_GET['simular']);
$db = (new Database())->getConnection();
if (!$db instanceof PDO) {
    fwrite(STDERR, "Sem ligacao a base de dados.\n");
    exit(1);
}
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$rel = ['resumo_dias' => [], 'apagados' => [], 'erros' => []];

// 1) Resumo diario.
if (vendas_resumo_disponivel($db)) {
    $hoje = (string)$db->query('SELECT CURDATE()')->fetchColumn();
    $dias = [];
    for ($i = 1; $i <= 3; $i++) {
        $dias[] = date('Y-m-d', strtotime("$hoje -$i day"));
    }
    $primeira = $db->query('SELECT DATE(MIN(criado_em)) FROM vendas')->fetchColumn();
    if ($primeira) {
        $inicio = max((string)$primeira, date('Y-m-d', strtotime("$hoje -400 day")));
        $feitos = array_flip($db->query("SELECT dia FROM vendas_resumo_estado WHERE dia >= " . $db->quote($inicio))->fetchAll(PDO::FETCH_COLUMN) ?: []);
        for ($d = date('Y-m-d', strtotime("$hoje -4 day")); $d >= $inicio && count($dias) < 63; $d = date('Y-m-d', strtotime("$d -1 day"))) {
            if (!isset($feitos[$d])) {
                $dias[] = $d;
            }
        }
    }
    foreach ($dias as $d) {
        try {
            $rel['resumo_dias'][$d] = $simular ? 'simulado' : vendas_resumo_calcular_dia($db, $d);
        } catch (Throwable $e) {
            $rel['erros'][] = "resumo $d: " . $e->getMessage();
        }
    }
} else {
    $rel['erros'][] = 'Tabelas do resumo em falta: aplique as migracoes (tools/db_migrate.php).';
}

// 2) Limpeza em lotes.
$limpezas = [
    'audit_logs' => 'created_at < NOW() - INTERVAL 365 DAY',
    'security_events' => 'created_at < NOW() - INTERVAL 180 DAY',
    'security_rate_limits' => 'expira_em < UNIX_TIMESTAMP()',
    'login_2fa_pendentes' => 'expira_em < NOW() - INTERVAL 7 DAY',
    'password_resets' => 'expires_at < NOW() - INTERVAL 7 DAY',
    'cliente_password_resets' => 'expires_at < NOW() - INTERVAL 7 DAY',
    'offline_sync_queue' => "status = 'DONE' AND updated_at < NOW() - INTERVAL 30 DAY",
];
foreach ($limpezas as $tabela => $onde) {
    try {
        if ($simular) {
            $rel['apagados'][$tabela] = (int)$db->query("SELECT COUNT(*) FROM `$tabela` WHERE $onde")->fetchColumn();
            continue;
        }
        $total = 0;
        for ($lote = 0; $lote < 200; $lote++) { // ate 200 000 linhas por noite
            $n = $db->exec("DELETE FROM `$tabela` WHERE $onde LIMIT 1000");
            $total += (int)$n;
            if ($n < 1000) {
                break;
            }
            usleep(50000);
        }
        $rel['apagados'][$tabela] = $total;
    } catch (Throwable $e) {
        $rel['erros'][] = "$tabela: " . $e->getMessage();
    }
}

// 3) Ficheiros de exportacao expirados (24 h).
try {
    if (fila_disponivel($db)) {
        $rel['apagados']['ficheiros_exportacao'] = $simular ? 0 : fila_limpar($db);
    }
    $rel['apagados']['sessoes_em_pausa_expiradas'] = $simular ? 0 : sr_limpar_expiradas($db);
} catch (Throwable $e) {
    $rel['erros'][] = 'fila: ' . $e->getMessage();
}

$saida = ['success' => !$rel['erros'], 'simulado' => $simular, 'resumo_dias' => count($rel['resumo_dias'])] + $rel;
if ($isCli) {
    echo ($simular ? "[SIMULACAO] " : '') . 'Resumo: ' . count($rel['resumo_dias']) . " dia(s) calculado(s).\n";
    foreach ($rel['apagados'] as $t => $n) {
        echo "  $t: $n registo(s) " . ($simular ? 'a apagar' : 'apagado(s)') . "\n";
    }
    foreach ($rel['erros'] as $e) {
        echo "  ERRO: $e\n";
    }
} else {
    echo json_encode($saida);
}
exit($rel['erros'] ? 1 : 0);
