<?php
require_once __DIR__ . '/../app_base.php';

/**
 * Despachante das rotinas agendadas.
 *
 * Pensado para alojamentos onde so e possivel um agendamento (o Railway nao
 * aceita intervalos abaixo de 5 minutos). Corre de 5 em 5 minutos e decide o
 * que executar com base na marca da ultima execucao de cada tarefa, guardada
 * em STORAGE_PATH/cron. Assim, se uma execucao falhar ou o servico estiver
 * adormecido, a tarefa corre na primeira oportunidade seguinte em vez de se
 * perder ate ao dia seguinte.
 *
 * Chamada por HTTP:  /cron/dispatch.php  com o cabecalho X-Cron-Token
 * Chamada por CLI:   php src/public/cron/dispatch.php
 */
include_once APP_BASE . '/src/config/storage_path.php';

$porHttp = php_sapi_name() !== 'cli';
if ($porHttp) {
    header('Content-Type: text/plain; charset=utf-8');
    $token = trim((string)(getenv('CRON_HTTP_TOKEN') ?: ''));
    $dado = trim((string)($_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? ''));
    if ($token === '' || !hash_equals($token, $dado)) {
        http_response_code(403);
        echo "Forbidden\n";
        exit(1);
    }
}

$fuso = trim((string)(getenv('APP_TIMEZONE') ?: '')) ?: 'UTC';
try {
    $agora = new DateTimeImmutable('now', new DateTimeZone($fuso));
} catch (Throwable $e) {
    $agora = new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

$marcas = nv_storage('cron');
if (!is_dir($marcas)) {
    @mkdir($marcas, 0775, true);
}

/** Ultima execucao registada, ou null. */
$ultima = static function (string $nome) use ($marcas): ?DateTimeImmutable {
    $f = $marcas . '/' . $nome . '.txt';
    if (!is_file($f)) {
        return null;
    }
    $t = (int)trim((string)@file_get_contents($f));
    return $t > 0 ? (new DateTimeImmutable('@' . $t)) : null;
};

$marcar = static function (string $nome) use ($marcas): void {
    @file_put_contents($marcas . '/' . $nome . '.txt', (string)time(), LOCK_EX);
};

// Tarefa => intervalo minimo entre execucoes, em segundos.
// A hora a que a tarefa diaria corre e a primeira execucao do dia a partir
// das 6h locais; o intervalo minimo evita repeticoes no mesmo dia.
$tarefas = [
    'trabalhos'                   => ['intervalo' => 0,     'sodepois' => null],
    'backup_automatico'           => ['intervalo' => 3300,  'sodepois' => null],
    'manutencao'                  => ['intervalo' => 72000, 'sodepois' => 6],
    'encerramentos'               => ['intervalo' => 72000, 'sodepois' => 6],
    'avisos_bonus'                => ['intervalo' => 72000, 'sodepois' => 6],
    'notificar_vencimento_planos' => ['intervalo' => 72000, 'sodepois' => 6],
];

$executadas = [];
$falhadas = [];

foreach ($tarefas as $nome => $regra) {
    $script = __DIR__ . '/' . $nome . '.php';
    if (!is_file($script)) {
        continue;
    }

    if ($regra['sodepois'] !== null && (int)$agora->format('G') < $regra['sodepois']) {
        continue;
    }

    $anterior = $ultima($nome);
    if ($anterior !== null && (time() - $anterior->getTimestamp()) < $regra['intervalo']) {
        continue;
    }

    // Cada rotina corre no seu proprio processo: uma que falhe nao arrasta as
    // restantes nem deixa o despachante a meio.
    $saida = [];
    $codigo = 0;
    @exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $saida, $codigo);

    if ($codigo === 0) {
        $marcar($nome);
        $executadas[] = $nome;
    } else {
        $falhadas[] = $nome . ' (codigo ' . $codigo . ')';
        error_log('[cron dispatch] ' . $nome . ' falhou: ' . implode(' | ', array_slice($saida, -3)));
    }
}

echo 'Hora local: ' . $agora->format('Y-m-d H:i') . ' (' . $fuso . ")\n";
echo 'Executadas: ' . ($executadas ? implode(', ', $executadas) : 'nenhuma') . "\n";
if ($falhadas) {
    echo 'Falhadas: ' . implode(', ', $falhadas) . "\n";
    if (!$porHttp) {
        exit(1);
    }
}
