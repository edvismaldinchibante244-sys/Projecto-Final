<?php
declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Unable to resolve project root.\n");
    exit(2);
}

function run_endpoint_case(string $label, string $scriptPath, array $serverOverrides, array $postOverrides, string $expectedSnippet, ?string $stdin = null, array $envOverrides = [], string $mode = 'single'): array
{
    global $root;

    $serverCode = [];
    foreach ($serverOverrides as $key => $value) {
        $serverCode[] = "\$_SERVER['" . str_replace("'", "\\'", $key) . "'] = " . var_export($value, true) . ";";
    }

    $postCode = [];
    foreach ($postOverrides as $key => $value) {
        $postCode[] = "\$_POST['" . str_replace("'", "\\'", $key) . "'] = " . var_export($value, true) . ";";
    }

    $code = <<<'PHP'
<?php
ob_start();
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/api/test';
$_SERVER['SCRIPT_NAME'] = '/api/test';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTPS'] = 'off';
$_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [];
putenv('APP_ENV=local');
putenv('APP_WAF_ENABLED=0');
__SERVER_OVERRIDES__
__POST_OVERRIDES__
include '__SCRIPT_PATH__';
PHP;

    $code = str_replace('__SERVER_OVERRIDES__', implode("\n", $serverCode), $code);
    $code = str_replace('__POST_OVERRIDES__', implode("\n", $postCode), $code);
    $code = str_replace('__SCRIPT_PATH__', str_replace('\\', '/', $scriptPath), $code);

    $tempFile = tempnam(sys_get_temp_dir(), 'regression_');
    if ($tempFile === false) {
        return ['label' => $label, 'passed' => false, 'reason' => 'Unable to create temporary PHP file.'];
    }

    if (@file_put_contents($tempFile, $code) === false) {
        @unlink($tempFile);
        return ['label' => $label, 'passed' => false, 'reason' => 'Unable to write temporary PHP file.'];
    }

    $env = $_ENV;
    $env['APP_ENV'] = 'local';
    $env['APP_WAF_ENABLED'] = '0';
    if ($stdin !== null) {
        $env['PLANO_WEBHOOK_RAW_PAYLOAD'] = $stdin;
    }
    foreach ($envOverrides as $key => $value) {
        $env[$key] = (string)$value;
    }

    $cmd = 'php -d display_errors=1 ' . escapeshellarg($tempFile);
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $output = '';
    $stderr = '';
    $exitCode = 255;
    $passed = false;

    if ($mode === 'rate_limit') {
        $rateFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rate_login_api|127.0.0.1.json';
        @unlink($rateFile);
        for ($i = 0; $i < 10; $i++) {
            $process = proc_open($cmd, $descriptorSpec, $pipes, $root, $env);
            if (!is_resource($process)) {
                break;
            }
            if ($stdin !== null) {
                fwrite($pipes[0], $stdin);
            }
            fclose($pipes[0]);
            $stdoutChunk = stream_get_contents($pipes[1]);
            $stderrChunk = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            $output = trim((string)$stdoutChunk);
            $stderr = trim((string)$stderrChunk);
            if (str_contains($output, $expectedSnippet)) {
                $passed = true;
                break;
            }
        }
    } else {
        $process = proc_open($cmd, $descriptorSpec, $pipes, $root, $env);
        if (!is_resource($process)) {
            @unlink($tempFile);
            return ['label' => $label, 'passed' => false, 'reason' => 'Unable to start PHP process.'];
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $stdoutChunk = stream_get_contents($pipes[1]);
        $stderrChunk = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $output = trim((string)$stdoutChunk);
        $stderr = trim((string)$stderrChunk);
        $passed = $exitCode === 0 && str_contains($output, $expectedSnippet);
    }
    @unlink($tempFile);

    return [
        'label' => $label,
        'passed' => $passed,
        'exit_code' => $exitCode,
        'output' => $output,
        'stderr' => trim((string)$stderr),
        'reason' => $passed ? 'ok' : 'Output did not contain expected snippet.',
    ];
}

$cases = [
    [
        'label' => 'Login process rejeita payload grande',
        'script' => $root . '/src/public/api/login_process.php',
        'server' => ['CONTENT_LENGTH' => '200000', 'SCRIPT_FILENAME' => $root . '/src/public/api/login_process.php'],
        'post' => ['email' => 'teste@example.com', 'senha' => '123456'],
        'snippet' => 'Payload muito grande.',
    ],
    [
        'label' => 'Esqueci senha rejeita email vazio',
        'script' => $root . '/src/public/api/esqueci_senha.php',
        'server' => ['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => '0', 'SCRIPT_FILENAME' => $root . '/src/public/api/esqueci_senha.php'],
        'post' => ['email' => ''],
        'snippet' => 'Por favor, insira seu email v\u00e1lido.',
    ],
    [
        'label' => 'Reserva pública rejeita ação inválida',
        'script' => $root . '/src/public/api/reserva_publica.php',
        'server' => ['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => '0', 'SCRIPT_FILENAME' => $root . '/src/public/api/reserva_publica.php'],
        'post' => ['action' => 'acao_invalida'],
        'snippet' => 'Restaurante invalido para a reserva.',
    ],
    [
        'label' => 'Login process aplica rate limit',
        'script' => $root . '/src/public/api/login_process.php',
        'server' => ['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => '0', 'SCRIPT_FILENAME' => $root . '/src/public/api/login_process.php'],
        'post' => ['email' => 'teste@example.com', 'senha' => '123456'],
        'snippet' => 'Muitas tentativas',
        'mode' => 'rate_limit',
    ],
    [
        'label' => 'Webhook rejeita assinatura inválida',
        'script' => $root . '/src/public/api/gateway_webhook_plano.php',
        'server' => ['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => '2', 'SCRIPT_FILENAME' => $root . '/src/public/api/gateway_webhook_plano.php', 'CONTENT_TYPE' => 'application/json'],
        'post' => [],
        'snippet' => 'Assinatura invalida',
        'stdin' => '{}',
        'env' => ['PLANO_WEBHOOK_SECRET' => 'secret-test'],
    ],
    [
        'label' => 'Webhook rejeita método não permitido',
        'script' => $root . '/src/public/api/gateway_webhook_plano.php',
        'server' => ['REQUEST_METHOD' => 'GET', 'CONTENT_LENGTH' => '0', 'SCRIPT_FILENAME' => $root . '/src/public/api/gateway_webhook_plano.php'],
        'post' => [],
        'snippet' => 'Metodo nao permitido',
    ],
];

$results = [];
foreach ($cases as $case) {
    $results[] = run_endpoint_case(
        $case['label'],
        $case['script'],
        $case['server'],
        $case['post'],
        $case['snippet'],
        $case['stdin'] ?? null,
        $case['env'] ?? [],
        $case['mode'] ?? 'single'
    );
}

$passed = 0;
foreach ($results as $result) {
    echo '[' . ($result['passed'] ? 'PASS' : 'FAIL') . '] ' . $result['label'] . PHP_EOL;
    if (!$result['passed']) {
        echo '  reason: ' . $result['reason'] . PHP_EOL;
        echo '  exit_code: ' . $result['exit_code'] . PHP_EOL;
        if ($result['stderr'] !== '') {
            echo '  stderr: ' . $result['stderr'] . PHP_EOL;
        }
        if ($result['output'] !== '') {
            echo '  output: ' . $result['output'] . PHP_EOL;
        }
    }
    if ($result['passed']) {
        $passed++;
    }
}

echo PHP_EOL . 'Resumo: ' . $passed . '/' . count($results) . ' casos passaram.' . PHP_EOL;
exit($passed === count($results) ? 0 : 1);
