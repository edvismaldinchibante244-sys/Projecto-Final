<?php
require_once __DIR__ . '/../app_base.php';

/**
 * Trabalhos em segundo plano (exportacao de dados, ...).
 * Agendar a cada minuto (Hostinger: Tarefas Cron; Windows: scripts/cron/run_trabalhos_cron.bat).
 * Sem esta tarefa o sistema continua a funcionar: a pagina trata o trabalho quando pergunta o estado.
 *   php src/public/cron/trabalhos.php
 */
include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/trabalhos_fila.php';
include_once APP_BASE . '/src/config/plano_encerramento.php';

if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
    $token = trim((string)(getenv('CRON_HTTP_TOKEN') ?: ''));
    $given = trim((string)($_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? ''));
    if ($token === '' || !hash_equals($token, $given)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit(1);
    }
}
$db = (new Database())->getConnection();
if (!$db instanceof PDO || !fila_disponivel($db)) {
    fwrite(STDERR, "Fila indisponivel (aplique as migracoes).\n");
    exit(1);
}
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$feitos = fila_processar($db, 5, true);
$limpos = fila_limpar($db);
echo "Trabalhos processados: $feitos. Ficheiros expirados apagados: $limpos.\n";
