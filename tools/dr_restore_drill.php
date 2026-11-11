<?php
declare(strict_types=1);

/**
 * DR restore drill — valida o último backup com sucesso (integridade + parse).
 * Não restaura na base de produção. Opcionalmente actualiza evidência e DR_LAST_TEST_AT.
 *
 * Uso (a partir da pasta V00):
 *   cd V00
 *   php tools/dr_restore_drill.php --json --stamp-env
 *
 * Ou a partir da raiz do repo:
 *   php V00/tools/dr_restore_drill.php --json --stamp-env
 */

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "root not found\n");
    exit(2);
}

require_once $root . '/app/src/config/database.php';
require_once $root . '/app/src/config/backup_helper.php';

$opts = getopt('', ['json', 'restaurante-id::', 'stamp-env', 'min-tables::']);
$asJson = isset($opts['json']);
$stampEnv = isset($opts['stamp-env']);
$minTables = max(1, (int)($opts['min-tables'] ?? 3));
$onlyRid = isset($opts['restaurante-id']) ? max(1, (int)$opts['restaurante-id']) : null;

/**
 * @return array{ok:bool,tables:array<string,int>,inserts:int,message:string}
 */
function dr_drill_validate_sql(string $sql, int $expectedRid = 0): array
{
    $tables = [];
    $inserts = 0;
    $foreignRid = 0;
    foreach (explode("\n", $sql) as $linha) {
        $linha = trim($linha);
        if ($linha === '' || ($linha[0] ?? '') === '-' || ($linha[0] ?? '') === '/' || ($linha[0] ?? '') === '#') {
            continue;
        }
        if (stripos($linha, 'INSERT INTO') !== 0) {
            continue;
        }
        if (!preg_match('/^INSERT\s+INTO\s+`?(\w+)`?\s*\(([^)]+)\)\s+VALUES\s*\(/i', $linha, $m)) {
            continue;
        }
        $tabela = strtolower($m[1]);
        $cols = array_map('trim', explode(',', strtolower(str_replace('`', '', $m[2]))));
        $tables[$tabela] = (int)($tables[$tabela] ?? 0) + 1;
        $inserts++;

        if ($expectedRid > 0) {
            $ridIdx = array_search('restaurante_id', $cols, true);
            if ($ridIdx !== false && preg_match('/VALUES\s*\((.+)\)\s*;?\s*$/i', $linha, $vm)) {
                // contagem grosseira de valores — só verifica se o número do tenant aparece
                if (strpos($vm[1], (string)$expectedRid) === false) {
                    $foreignRid++;
                }
            }
        }
    }

    if ($inserts <= 0) {
        return ['ok' => false, 'tables' => $tables, 'inserts' => 0, 'message' => 'Nenhum INSERT encontrado no dump.'];
    }
    if (count($tables) < $GLOBALS['dr_min_tables'] ?? 3) {
        return ['ok' => false, 'tables' => $tables, 'inserts' => $inserts, 'message' => 'Poucas tabelas no dump.'];
    }
    if ($foreignRid > max(2, (int)floor($inserts * 0.5))) {
        return ['ok' => false, 'tables' => $tables, 'inserts' => $inserts, 'message' => 'Dump parece misturar outros restaurantes.'];
    }
    return ['ok' => true, 'tables' => $tables, 'inserts' => $inserts, 'message' => 'OK'];
}

$GLOBALS['dr_min_tables'] = $minTables;

$db = (new Database())->getConnection();
if (!$db instanceof PDO) {
    $out = ['ok' => false, 'message' => 'DB connection failed'];
    echo $asJson ? json_encode($out, JSON_PRETTY_PRINT) . PHP_EOL : "DB connection failed\n";
    exit(1);
}

backup_ensure_tables_exist($db);

$sqlHist = "
    SELECT id, restaurante_id, arquivo_nome, arquivo_caminho, hash_arquivo, total_tabelas, total_registros, executado_em, tipo, status
    FROM backup_historico
    WHERE status = 'SUCESSO'
";
$params = [];
if ($onlyRid !== null) {
    $sqlHist .= ' AND restaurante_id = :rid';
    $params['rid'] = $onlyRid;
}
$sqlHist .= ' ORDER BY executado_em DESC, id DESC LIMIT 1';

$stmt = $db->prepare($sqlHist);
$stmt->execute($params);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    $out = ['ok' => false, 'message' => 'Nenhum backup SUCESSO encontrado no historico.'];
    echo $asJson ? json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL : ($out['message'] . "\n");
    exit(1);
}

$rid = (int)$row['restaurante_id'];
$path = backup_resolver_caminho((string)$row['arquivo_caminho'], (string)$row['arquivo_nome'], $rid);
if ($path === '' || !is_file($path)) {
    $out = [
        'ok' => false,
        'message' => 'Arquivo de backup em falta no disco.',
        'backup_id' => (int)$row['id'],
        'path' => $path,
    ];
    echo $asJson ? json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL : ($out['message'] . "\n");
    exit(1);
}

$sql = (string)file_get_contents($path);
$hash = md5($sql);
$hashExpected = trim((string)($row['hash_arquivo'] ?? ''));
$hashOk = ($hashExpected === '' || hash_equals($hashExpected, $hash));
$validation = dr_drill_validate_sql($sql, $rid);

$ok = $hashOk && !empty($validation['ok']);
$today = date('Y-m-d');
$result = [
    'ok' => $ok,
    'generated_at' => date('c'),
    'drill_date' => $today,
    'backup_id' => (int)$row['id'],
    'restaurante_id' => $rid,
    'arquivo' => (string)$row['arquivo_nome'],
    'path' => $path,
    'hash_ok' => $hashOk,
    'hash' => $hash,
    'inserts' => (int)$validation['inserts'],
    'tables' => $validation['tables'],
    'message' => $ok ? 'Drill de integridade OK (sem restore em producao).' : ($validation['message'] ?: 'Falha no drill.'),
    'type' => 'integrity_parse',
];

$logDir = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$stampPath = $logDir . DIRECTORY_SEPARATOR . 'dr_last_test.json';
if ($ok) {
    @file_put_contents($stampPath, json_encode([
        'DR_LAST_TEST_AT' => $today,
        'result' => $result,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

    $evidence = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'security' . DIRECTORY_SEPARATOR . 'DR_DRILL_EVIDENCE.md';
    if (is_dir(dirname($evidence))) {
        $block = "\n\n## Execucao automatica\n"
            . '- Data: ' . $today . "\n"
            . '- Tipo: integrity_parse (sem restore em producao)' . "\n"
            . '- Backup ID: ' . (int)$row['id'] . "\n"
            . '- Restaurante: ' . $rid . "\n"
            . '- Arquivo: ' . (string)$row['arquivo_nome'] . "\n"
            . '- Hash OK: ' . ($hashOk ? 'sim' : 'nao') . "\n"
            . '- INSERTs: ' . (int)$validation['inserts'] . "\n"
            . '- Tabelas: ' . count($validation['tables']) . "\n"
            . '- Resultado: OK' . "\n";
        @file_put_contents($evidence, $block, FILE_APPEND | LOCK_EX);
    }

    if ($stampEnv) {
        $envFile = $root . DIRECTORY_SEPARATOR . '.env';
        if (is_file($envFile) && is_writable($envFile)) {
            $env = (string)file_get_contents($envFile);
            if (preg_match('/^DR_LAST_TEST_AT=.*$/m', $env)) {
                $env = preg_replace('/^DR_LAST_TEST_AT=.*$/m', 'DR_LAST_TEST_AT=' . $today, $env, 1);
            } else {
                $env = rtrim($env) . "\nDR_LAST_TEST_AT=" . $today . "\n";
            }
            @file_put_contents($envFile, $env, LOCK_EX);
            $result['env_stamped'] = true;
        } else {
            $result['env_stamped'] = false;
        }

        $govFile = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'security' . DIRECTORY_SEPARATOR . 'security_governance.json';
        if (is_file($govFile) && is_writable($govFile)) {
            $govRaw = file_get_contents($govFile);
            $gov = is_string($govRaw) ? json_decode($govRaw, true) : null;
            if (is_array($gov)) {
                if (!isset($gov['dr_ir']) || !is_array($gov['dr_ir'])) {
                    $gov['dr_ir'] = [];
                }
                $gov['dr_ir']['last_dr_drill_at'] = $today;
                $gov['updated_at'] = $today;
                @file_put_contents(
                    $govFile,
                    json_encode($gov, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
                    LOCK_EX
                );
                $result['governance_stamped'] = true;
            }
        }
    }
}

@file_put_contents(
    $logDir . DIRECTORY_SEPARATOR . 'dr_restore_drill.log',
    json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL,
    FILE_APPEND | LOCK_EX
);

if ($asJson) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo "DR Restore Drill\n";
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $result['message'] . "\n";
    echo 'Backup #' . $result['backup_id'] . ' · restaurante ' . $rid . "\n";
    echo 'INSERTs: ' . $result['inserts'] . ' · tabelas: ' . count($result['tables']) . "\n";
}

exit($ok ? 0 : 1);
