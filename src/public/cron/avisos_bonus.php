<?php
require_once __DIR__ . '/../app_base.php';

/**
 * Job diário: avisos da oferta de boas-vindas.
 *
 * Trata o lembrete do 7.º e do 12.º dia e o email de fim de oferta, para
 * saírem mesmo que ninguém abra o sistema. Cada aviso é dado uma só vez,
 * por isso correr duas vezes no mesmo dia não duplica nada.
 *
 * Exemplos:
 *   php src/public/cron/avisos_bonus.php
 *   php src/public/cron/avisos_bonus.php --dry-run
 *
 * Agendar uma vez por dia (de manhã). Sem SMTP configurado o aviso continua
 * a aparecer dentro do sistema, na faixa do painel.
 */

include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/plano_bonus.php';

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

$simular = in_array('--dry-run', $argv ?? [], true);

$database = new Database();
$db = $database->getConnection();
if (!$db) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Falha de conexao com banco'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}

$resumo = bonus_processar_avisos($db, $simular);

echo json_encode([
    'success' => true,
    'simulacao' => $simular,
    'lembretes_7_dias' => $resumo['aviso7'],
    'lembretes_12_dias' => $resumo['aviso12'],
    'fins_de_oferta' => $resumo['terminados'],
    'detalhes' => $resumo['restaurantes'],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
