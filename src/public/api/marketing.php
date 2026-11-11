<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';

spl_autoload_register(static function ($class) {
    if (strpos($class, 'App\\') !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, 4));
    $file = APP_BASE . '/src/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

header('Content-Type: application/json; charset=utf-8');

$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$ctx = api_guard([
    'roles' => ['ADMIN', 'GERENTE'],
    'rate_key' => 'marketing_api',
    'rate_max' => 120,
    'rate_window' => 60,
    'skip_csrf' => $requestMethod === 'GET',
]);

$db = (new Database())->getConnection();
$restauranteId = (int)$ctx['restaurante_id'];
$requestPath = trim((string)($_GET['route'] ?? ''), '/');

$modelMarketing = new \App\Model\CampanhaMarketing($db);
$serviceMarketing = new \App\Service\MarketingService($db, $modelMarketing);

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

try {
    if ($requestMethod === 'POST' && $requestPath === 'validar-cupom') {
        $codigo = security_clean_string($input['codigo'] ?? '', 60);
        $valorVenda = (float)($input['valor_venda'] ?? 0);

        if ($codigo === '' || $valorVenda < 0) {
            security_json(['success' => false, 'message' => 'Dados invalidos.'], 422);
        }

        security_json($serviceMarketing->processarCupom($restauranteId, $codigo, $valorVenda));
    }

    if ($requestMethod === 'POST' && $requestPath === 'usar-pontos') {
        $clienteId = (int)($input['cliente_id'] ?? 0);
        $pontos = (int)($input['pontos'] ?? 0);

        if ($clienteId <= 0 || $pontos <= 0) {
            security_json(['success' => false, 'message' => 'Dados invalidos.'], 422);
        }

        $desconto = $serviceMarketing->converterPontosEmDesconto($pontos);
        $usado = $modelMarketing->usarPontos($clienteId, $restauranteId, $pontos, 'Resgatado no POS');
        if ($usado) {
            security_audit_event('pontos_utilizados', ['cliente_id' => $clienteId, 'pontos' => $pontos]);
        }

        security_json([
            'success' => (bool)$usado,
            'desconto_value' => $desconto,
            'message' => $usado ? "MZN {$desconto} em desconto" : 'Saldo insuficiente',
        ], $usado ? 200 : 422);
    }

    if ($requestMethod === 'GET' && preg_match('/^pontos\/(\d+)$/', $requestPath, $matches)) {
        $clienteId = (int)$matches[1];
        $saldo = $modelMarketing->obterSaldoPontos($clienteId, $restauranteId);

        security_json([
            'success' => (bool)$saldo,
            'data' => $saldo ?: ['saldo_pontos' => 0, 'nivel_cliente' => 'bronze'],
        ]);
    }

    if ($requestMethod === 'POST' && $requestPath === 'gerar-cupom') {
        $clienteId = (int)($input['cliente_id'] ?? 0);
        $tipo = security_clean_string($input['tipo'] ?? 'promocao', 40);

        if ($clienteId <= 0) {
            security_json(['success' => false, 'message' => 'cliente_id invalido.'], 422);
        }

        $codigo = $serviceMarketing->gerarCupomPersonalizado($restauranteId, $clienteId, $tipo);
        security_audit_event('cupom_gerado', ['cliente_id' => $clienteId, 'tipo' => $tipo]);
        security_json([
            'success' => true,
            'codigo_cupom' => $codigo,
            'tipo' => $tipo,
        ]);
    }

    if ($requestMethod === 'GET' && $requestPath === 'campanhas-ativas') {
        $campanhas = $modelMarketing->obterCampanhasAtivas($restauranteId);
        security_json([
            'success' => true,
            'data' => $campanhas,
            'total' => count($campanhas),
        ]);
    }

    if ($requestMethod === 'POST' && $requestPath === 'nova-campanha') {
        $dados = [
            'restaurante_id' => $restauranteId,
            'nome' => security_clean_string($input['nome'] ?? '', 120),
            'descricao' => security_clean_string($input['descricao'] ?? '', 255) ?: null,
            'tipo' => security_clean_string($input['tipo'] ?? 'cupom', 40),
            'data_inicio' => !empty($input['data_inicio']) ? (string)$input['data_inicio'] : date('Y-m-d'),
            'data_fim' => !empty($input['data_fim']) ? (string)$input['data_fim'] : null,
            'desconto_valor' => (float)($input['desconto_valor'] ?? 0),
            'desconto_percentual' => (float)($input['desconto_percentual'] ?? 0),
        ];

        if ($dados['nome'] === '' || $dados['data_fim'] === null) {
            security_json(['success' => false, 'message' => 'Dados obrigatorios ausentes.'], 422);
        }

        $criado = $modelMarketing->criarCampanha($dados);
        if ($criado) {
            security_audit_event('campanha_criada', ['nome' => $dados['nome'], 'tipo' => $dados['tipo']]);
        }

        security_json([
            'success' => (bool)$criado,
            'message' => $criado ? 'Campanha criada com sucesso.' : 'Erro ao criar campanha.',
        ], $criado ? 200 : 500);
    }

    security_json(['success' => false, 'message' => 'Endpoint nao encontrado.'], 404);
} catch (Throwable $e) {
    error_log('marketing_api: ' . $e->getMessage());
    security_json(['success' => false, 'message' => 'Erro interno na API de marketing.'], 500);
}
