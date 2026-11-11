<?php
require_once __DIR__ . '/../app_base.php';

/**
 * Job diario: encerramento de contas (Termos de Uso, seccao 5).
 *  1) cancelamentos cuja data chegou -> conta CANCELADA, email com prazo para exportar;
 *  2) aviso 14 dias antes de apagar;
 *  3) apaga os dados e guarda/envia a declaracao de eliminacao.
 *
 *   php src/public/cron/encerramentos.php --simular    (so mostra o que faria)
 *   php src/public/cron/encerramentos.php
 */
include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/email_helper.php';
include_once APP_BASE . '/src/config/plano_encerramento.php';

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
$simular = $isCli ? in_array('--simular', $argv ?? [], true) : isset($_GET['simular']);
$db = (new Database())->getConnection();
if (!$db instanceof PDO) {
    fwrite(STDERR, "Sem ligacao a base de dados.\n");
    exit(2);
}
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
try {
    $log = enc_rotina_diaria($db, $simular);
} catch (Throwable $e) {
    error_log('[CRON_ENCERRAMENTOS] ' . $e->getMessage());
    if ($isCli) { fwrite(STDERR, 'Erro: ' . $e->getMessage() . "\n"); exit(1); }
    echo json_encode(['success' => false]);
    exit(1);
}
if ($isCli) {
    echo ($simular ? "[SIMULACAO] " : '') . (count($log) ? implode("\n", $log) : 'Nada a fazer hoje.') . "\n";
} else {
    echo json_encode(['success' => true, 'simulacao' => $simular, 'accoes' => count($log)]);
}
