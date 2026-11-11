<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/pedido_schema.php';
require_once APP_BASE . '/src/config/security.php';
require_once APP_BASE . '/src/config/input_validation.php';

security_start_session();
security_set_headers();

header('Content-Type: application/json; charset=utf-8');

function operacoes_empresariais_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function operacoes_empresariais_normalizar_texto($texto): string
{
    $txt = strtolower(trim((string)$texto));
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $txt);
    if ($ascii !== false) {
        $txt = strtolower($ascii);
    }
    return $txt;
}

function operacoes_empresariais_mobile_payload(array $data, string $message = 'OK', bool $success = true, int $status = 200): void
{
    operacoes_empresariais_json([
        'success' => $success,
        'message' => $message,
        'mode' => 'mobile',
        'data' => $data,
    ], $status);
}

function operacoes_empresariais_inferir_destino(?string $categoria, ?string $nome): string
{
    $base = operacoes_empresariais_normalizar_texto(($categoria ?? '') . ' ' . ($nome ?? ''));
    $bebidaKeywords = [
        'bebida',
        'bar',
        'drink',
        'coquetel',
        'cocktail',
        'sumo',
        'suco',
        'refrigerante',
        'agua',
        'agua mineral',
        'cerveja',
        'vinho',
        'whisky',
        'rum',
        'vodka',
        'gin',
        'licor',
        'cha',
        'te',
        'cafe',
        'espresso',
        'capuccino',
        'cappuccino',
        'latte',
        'milkshake',
        'batido',
        'smoothie',
        'energetico',
        'red bull',
        'guarana',
        'fanta',
        'sprite',
        'pepsi',
        'coca',
        'coca-cola',
        'schweppes',
        'tonica',
        'cocktail'
    ];

    foreach ($bebidaKeywords as $kw) {
        if (strpos($base, $kw) !== false) {
            return 'bar';
        }
    }

    return 'cozinha';
}

$metodo = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

function operacoes_empresariais_parse_request_body(): array
{
    $body = $_POST;
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (strpos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $body = array_merge($body, $decoded);
        }
    }
    return $body;
}

$body = operacoes_empresariais_parse_request_body();
$allowedActions = [
    'dashboard', 'fila', 'atualizar_status_item', 'criar_pedido', 'stream', 'login_mobile',
    'mobile_dashboard', 'app_dashboard', 'mobile_fila', 'app_fila',
    'mobile_status_update', 'mobile_atualizar_status', 'mobile_create_order',
    'mobile_criar_pedido', 'mobile_stream',
];
$action = input_enum($_GET['action'] ?? ($body['action'] ?? 'dashboard'), $allowedActions);
if ($action === null) {
    operacoes_empresariais_json(['success' => false, 'message' => 'Parametro action invalido.'], 400);
}

if ($metodo === 'POST' && $action === 'login_mobile') {
    $email = trim((string)($body['email'] ?? $body['usuario'] ?? ''));
    $senha = (string)($body['senha'] ?? '');
    if ($email === '' || $senha === '') {
        operacoes_empresariais_json(['success' => false, 'message' => 'Email e senha sao obrigatorios.'], 422);
    }

    require_once APP_BASE . '/src/Controller/AuthController.php';
    $controller = new AuthController();
    $resultado = $controller->login($email, $senha);

    if (!empty($resultado['success'])) {
        $dadosUsuario = $resultado['data'] ?? $resultado['usuario'] ?? null;
        $user = is_array($dadosUsuario) ? $dadosUsuario : [];
        $perfilSessao = strtoupper((string)($user['perfil'] ?? ($_SESSION['perfil'] ?? 'USER')));
        if ($perfilSessao === 'GARCOM' || $perfilSessao === 'GARÇOM' || $perfilSessao === 'GARÃ‡OM') {
            $perfilSessao = 'GARCOM';
        }
        session_regenerate_id(true);
        $_SESSION['logado'] = true;
        $_SESSION['usuario_id'] = (int)($user['id'] ?? 0);
        $_SESSION['restaurante_id'] = (int)($user['restaurante_id'] ?? 0);
        $_SESSION['nome'] = (string)($user['nome'] ?? $email);
        $_SESSION['email'] = (string)($user['email'] ?? $email);
        $_SESSION['perfil'] = $perfilSessao;
        $_SESSION['super_admin'] = !empty($user['super_admin']) ? 1 : 0;

        operacoes_empresariais_json([
            'success' => true,
            'message' => 'Login realizado com sucesso.',
            'mode' => 'mobile',
            'data' => [
                'user' => [
                    'id' => (int)($user['id'] ?? 0),
                    'nome' => (string)($user['nome'] ?? $email),
                    'email' => (string)($user['email'] ?? $email),
                    'perfil' => $perfilSessao,
                    'restaurante_id' => (int)($user['restaurante_id'] ?? 0),
                ],
                'session' => [
                    'restaurante_id' => (int)($_SESSION['restaurante_id'] ?? 0),
                    'perfil' => $perfilSessao,
                ],
            ],
        ]);
    }

    operacoes_empresariais_json(['success' => false, 'message' => (string)($resultado['message'] ?? 'Falha no login.')], 401);
}

require_once APP_BASE . '/src/config/api_guard.php';
$ctx = api_guard([
    'roles' => ['ADMIN', 'GARCOM', 'COZINHA', 'BAR', 'CAIXA', 'GERENTE'],
    'rate_key' => 'operacoes_empresariais',
    'rate_max' => 120,
    'rate_window' => 60,
]);

$restauranteId = (int)($ctx['restaurante_id'] ?? 0);
$perfil = strtoupper((string)($ctx['perfil'] ?? ''));
$actionAliases = [
    'mobile_dashboard' => 'dashboard',
    'app_dashboard' => 'dashboard',
    'mobile_fila' => 'fila',
    'app_fila' => 'fila',
    'mobile_status_update' => 'atualizar_status_item',
    'mobile_atualizar_status' => 'atualizar_status_item',
    'mobile_create_order' => 'criar_pedido',
    'mobile_criar_pedido' => 'criar_pedido',
    'mobile_stream' => 'stream',
];
$action = $actionAliases[$action] ?? $action;

// Recursos exclusivos do app mobile Empresarial (KDS web usa atualizar_status_item em todos os planos)
require_once APP_BASE . '/src/config/plano_check.php';
$dadosPlano = plano_get_dados($restauranteId ?? ($_SESSION['restaurante_id'] ?? 0));
$planoNomeAtual = strtoupper((string)($dadosPlano['plano_nome'] ?? ($dadosPlano['nome_display'] ?? 'BASICO')));
$planoNomeAtual = function_exists('plano_normalizar_nome')
    ? plano_normalizar_nome($planoNomeAtual)
    : $planoNomeAtual;
$mobileActionsEmpresarial = ['dashboard', 'fila', 'criar_pedido', 'stream'];
if (in_array($action, $mobileActionsEmpresarial, true) && $planoNomeAtual !== 'EMPRESARIAL') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Recurso disponivel apenas para o Plano Empresarial. Atualize seu plano para continuar.',
        'code' => 'PLANO_RESTRITO',
        'plano_atual' => $planoNomeAtual,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = (new Database())->getConnection();
pedido_schema_garantir($db);

if ($metodo === 'GET' && $action === 'dashboard') {
    $stmtPedidos = $db->prepare(
        "SELECT p.id, p.numero_pedido, p.status, p.total, p.observacao, p.origem, p.criado_em, p.atualizado_em,
                m.numero AS mesa_numero,
                (SELECT COUNT(*) FROM itens_pedido ip WHERE ip.pedido_id = p.id) AS total_itens
         FROM pedidos p
         LEFT JOIN mesas m ON m.id = p.mesa_id
         WHERE p.restaurante_id = :rid
           AND (p.criado_em >= CURDATE() AND p.criado_em < CURDATE() + INTERVAL 1 DAY)
         ORDER BY p.criado_em DESC
         LIMIT 20"
    );
    $stmtPedidos->execute([':rid' => $restauranteId]);
    $pedidos = $stmtPedidos->fetchAll(PDO::FETCH_ASSOC);

    $stmtBar = $db->prepare(
        "SELECT COUNT(*) AS total
         FROM itens_pedido ip
         JOIN pedidos p ON p.id = ip.pedido_id
         WHERE p.restaurante_id = :rid
           AND ip.destino = 'bar'
           AND COALESCE(ip.status, 'pendente') IN ('pendente', 'em_preparo', 'pronto')"
    );
    $stmtBar->execute([':rid' => $restauranteId]);
    $barPendentes = (int)$stmtBar->fetchColumn();

    $stmtCozinha = $db->prepare(
        "SELECT COUNT(*) AS total
         FROM itens_pedido ip
         JOIN pedidos p ON p.id = ip.pedido_id
         WHERE p.restaurante_id = :rid
           AND ip.destino = 'cozinha'
           AND COALESCE(ip.status, 'pendente') IN ('pendente', 'em_preparo', 'pronto')"
    );
    $stmtCozinha->execute([':rid' => $restauranteId]);
    $cozinhaPendentes = (int)$stmtCozinha->fetchColumn();

    $stmtAbertos = $db->prepare(
        "SELECT COUNT(*) AS total
         FROM pedidos
         WHERE restaurante_id = :rid
           AND (criado_em >= CURDATE() AND criado_em < CURDATE() + INTERVAL 1 DAY)
           AND status IN ('NOVO', 'PENDENTE', 'PREPARANDO', 'PRONTO')"
    );
    $stmtAbertos->execute([':rid' => $restauranteId]);
    $pedidosAbertos = (int)$stmtAbertos->fetchColumn();

    $notificacoes = [];
    foreach ($pedidos as $pedido) {
        $status = (string)($pedido['status'] ?? 'NOVO');
        $mesa = $pedido['mesa_numero'] ? 'Mesa ' . $pedido['mesa_numero'] : 'Mesa sem número';
        $notificacoes[] = [
            'tipo' => 'pedido',
            'titulo' => 'Pedido #' . ($pedido['numero_pedido'] ?? $pedido['id']),
            'mensagem' => $mesa . ' · ' . $status,
            'timestamp' => (string)($pedido['atualizado_em'] ?? $pedido['criado_em'] ?? ''),
        ];
    }

    $mobilePayload = [
        'summary' => [
            'pedidos_abertos' => $pedidosAbertos,
            'fila_bar' => $barPendentes,
            'fila_cozinha' => $cozinhaPendentes,
            'pedidos_hoje' => count($pedidos),
        ],
        'queues' => [
            'bar' => ['pending' => $barPendentes],
            'cozinha' => ['pending' => $cozinhaPendentes],
        ],
        'orders' => $pedidos,
        'notifications' => array_slice($notificacoes, 0, 10),
        'stream_url' => '/src/public/api/pedido_sse.php',
    ];

    operacoes_empresariais_json([
        'success' => true,
        'mode' => 'mobile',
        'restaurante_id' => $restauranteId,
        'pedidos_abertos' => $pedidosAbertos,
        'fila_bar' => $barPendentes,
        'fila_cozinha' => $cozinhaPendentes,
        'pedidos' => $pedidos,
        'notificacoes' => array_slice($notificacoes, 0, 10),
        'stream_url' => '/src/public/api/pedido_sse.php',
        'data' => $mobilePayload,
    ]);
}

if ($metodo === 'GET' && $action === 'fila') {
    $destino = input_enum($_GET['destino'] ?? 'bar', ['bar', 'cozinha']);
    if ($destino === null) {
        operacoes_empresariais_json(['success' => false, 'message' => 'Parametro destino invalido.'], 400);
    }

    $stmt = $db->prepare(
        "SELECT ip.id, ip.pedido_id, ip.produto_id, ip.quantidade,
                COALESCE(ip.status, 'pendente') AS status,
                COALESCE(p.nome, CONCAT('Produto #', ip.produto_id)) AS produto_nome,
                ped.numero_pedido,
                m.numero AS mesa_numero,
                ped.status AS status_pedido,
                ped.observacao
         FROM itens_pedido ip
         JOIN pedidos ped ON ped.id = ip.pedido_id
         LEFT JOIN produtos p ON p.id = ip.produto_id
         LEFT JOIN mesas m ON m.id = ped.mesa_id
         WHERE ped.restaurante_id = :rid
           AND ip.destino = :destino
           AND COALESCE(ip.status, 'pendente') IN ('pendente', 'em_preparo', 'pronto')
         ORDER BY FIELD(COALESCE(ip.status, 'pendente'), 'pendente', 'em_preparo', 'pronto'), ped.criado_em ASC"
    );
    $stmt->execute([':rid' => $restauranteId, ':destino' => $destino]);
    $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

    operacoes_empresariais_json([
        'success' => true,
        'mode' => 'mobile',
        'destino' => $destino,
        'itens' => $itens,
        'data' => [
            'destino' => $destino,
            'items' => $itens,
            'count' => count($itens),
        ],
    ]);
}

if ($metodo === 'POST' && $action === 'atualizar_status_item') {
    $body = $_POST;
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (strpos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (is_array($payload)) {
            $body = $payload;
        }
    }

    $itemId = input_positive_int($body['item_id'] ?? null);
    $novoStatus = strtolower(trim((string)($body['status'] ?? '')));

    if ($itemId === null || !in_array($novoStatus, ['pendente', 'em_preparo', 'pronto', 'entregue'], true)) {
        operacoes_empresariais_json(['success' => false, 'message' => 'Dados invalidos.'], 400);
    }

    if (!in_array($perfil, ['ADMIN', 'COZINHA', 'BAR'], true)) {
        operacoes_empresariais_json(['success' => false, 'message' => 'Sem permissao.'], 403);
    }

    $db->beginTransaction();

    try {
        $item = resource_require_item_pedido($db, $itemId, $restauranteId);
        $item['status_atual'] = $item['status'] ?? 'pendente';
        $item['destino'] = $item['destino'] ?? 'cozinha';

        $destinoItem = strtolower((string)($item['destino'] ?? 'cozinha'));
        if ($perfil === 'COZINHA' && $destinoItem !== 'cozinha') {
            throw new Exception('Sem permissao para esse item');
        }
        if ($perfil === 'BAR' && $destinoItem !== 'bar') {
            throw new Exception('Sem permissao para esse item');
        }

        $statusAtual = strtolower((string)$item['status_atual']);
        $transicoes = [
            'pendente' => ['em_preparo'],
            'em_preparo' => ['pronto'],
            'pronto' => ['entregue'],
            'entregue' => [],
        ];

        if ($perfil !== 'ADMIN') {
            $next = $transicoes[$statusAtual] ?? [];
            if (!in_array($novoStatus, $next, true)) {
                throw new Exception('Transicao de status invalida');
            }
        }

        $sets = ['status = :status'];
        if ($novoStatus === 'em_preparo') {
            $sets[] = 'iniciado_preparo_em = COALESCE(iniciado_preparo_em, NOW())';
        }
        if ($novoStatus === 'pronto') {
            $sets[] = 'pronto_em = COALESCE(pronto_em, NOW())';
        }
        if ($novoStatus === 'entregue') {
            $sets[] = 'entregue_em = COALESCE(entregue_em, NOW())';
        }

        $stmtUpdate = $db->prepare('UPDATE itens_pedido SET ' . implode(', ', $sets) . ' WHERE id = :item_id');
        $stmtUpdate->execute([':status' => $novoStatus, ':item_id' => $itemId]);

        $pedidoId = (int)$item['pedido_id'];
        $stmtResumo = $db->prepare(
            "SELECT SUM(CASE WHEN COALESCE(status, 'pendente') = 'pendente' THEN 1 ELSE 0 END) AS pendentes,
                    SUM(CASE WHEN COALESCE(status, 'pendente') = 'em_preparo' THEN 1 ELSE 0 END) AS preparo,
                    SUM(CASE WHEN COALESCE(status, 'pendente') = 'pronto' THEN 1 ELSE 0 END) AS prontos,
                    SUM(CASE WHEN COALESCE(status, 'pendente') = 'entregue' THEN 1 ELSE 0 END) AS entregues,
                    COUNT(*) AS total
             FROM itens_pedido
             WHERE pedido_id = :pedido_id"
        );
        $stmtResumo->execute([':pedido_id' => $pedidoId]);
        $r = $stmtResumo->fetch(PDO::FETCH_ASSOC) ?: ['pendentes' => 0, 'preparo' => 0, 'prontos' => 0, 'entregues' => 0, 'total' => 0];

        $pedidoStatus = 'NOVO';
        if ((int)$r['entregues'] === (int)$r['total'] && (int)$r['total'] > 0) {
            $pedidoStatus = 'ENTREGUE';
        } elseif ((int)$r['preparo'] > 0 || (int)$r['prontos'] > 0) {
            $pedidoStatus = 'PREPARANDO';
            if ((int)$r['pendentes'] === 0 && (int)$r['preparo'] === 0 && (int)$r['prontos'] > 0) {
                $pedidoStatus = 'PRONTO';
            }
        }

        $stmtPedido = $db->prepare(
            "UPDATE pedidos
             SET status = :status,
                 atualizado_em = NOW(),
                 iniciado_preparo_em = CASE WHEN :marcar_preparo = 1 THEN COALESCE(iniciado_preparo_em, NOW()) ELSE iniciado_preparo_em END,
                 pronto_em = CASE WHEN :marcar_pronto = 1 THEN COALESCE(pronto_em, NOW()) ELSE pronto_em END,
                 entregue_em = CASE WHEN :marcar_entregue = 1 THEN COALESCE(entregue_em, NOW()) ELSE entregue_em END
             WHERE id = :pedido_id AND restaurante_id = :rid"
        );
        $stmtPedido->execute([
            ':status' => $pedidoStatus,
            ':marcar_preparo' => $pedidoStatus === 'PREPARANDO' ? 1 : 0,
            ':marcar_pronto' => $pedidoStatus === 'PRONTO' ? 1 : 0,
            ':marcar_entregue' => $pedidoStatus === 'ENTREGUE' ? 1 : 0,
            ':pedido_id' => $pedidoId,
            ':rid' => $restauranteId,
        ]);

        $db->commit();

        operacoes_empresariais_json([
            'success' => true,
            'mode' => 'mobile',
            'message' => 'Status atualizado com sucesso.',
            'pedido_status' => $pedidoStatus,
            'item_id' => $itemId,
            'status' => $novoStatus,
            'data' => [
                'pedido_status' => $pedidoStatus,
                'item_id' => $itemId,
                'status' => $novoStatus,
            ],
        ]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('operacoes_empresariais update error: ' . $e->getMessage());
        operacoes_empresariais_json(['success' => false, 'message' => 'Nao foi possivel atualizar o item.'], 500);
    }
}

if ($metodo === 'GET' && $action === 'stream') {
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    ob_implicit_flush(true);
    $lastHash = '';
    for ($i = 0; $i < 60; $i++) {
        if (connection_aborted()) {
            break;
        }
        $stmt = $db->prepare(
            "SELECT COUNT(*) AS total
             FROM itens_pedido ip
             JOIN pedidos p ON p.id = ip.pedido_id
             WHERE p.restaurante_id = :rid
               AND ip.destino = 'bar'
               AND COALESCE(ip.status, 'pendente') IN ('pendente', 'em_preparo', 'pronto')"
        );
        $stmt->execute([':rid' => $restauranteId]);
        $bar = (int)$stmt->fetchColumn();
        $stmt2 = $db->prepare(
            "SELECT COUNT(*) AS total
             FROM itens_pedido ip
             JOIN pedidos p ON p.id = ip.pedido_id
             WHERE p.restaurante_id = :rid
               AND ip.destino = 'cozinha'
               AND COALESCE(ip.status, 'pendente') IN ('pendente', 'em_preparo', 'pronto')"
        );
        $stmt2->execute([':rid' => $restauranteId]);
        $cozinha = (int)$stmt2->fetchColumn();
        $payload = ['bar' => $bar, 'cozinha' => $cozinha, 'updated_at' => date('c')];
        $hash = md5(json_encode($payload, JSON_UNESCAPED_UNICODE));
        if ($hash !== $lastHash) {
            echo "event: update\ndata: " . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
            $lastHash = $hash;
        } else {
            echo ": heartbeat\n\n";
        }
        @flush();
        sleep(2);
    }
    exit;
}

if ($metodo === 'POST' && $action === 'criar_pedido') {
    $body = $_POST;
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (strpos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        $body = is_array($body) ? $body : [];
    }

    $mesaId = input_positive_int($body['mesa_id'] ?? $body['mesa'] ?? null);
    $itens = is_array($body['itens'] ?? null) ? $body['itens'] : [];
    $observacao = trim((string)($body['observacao'] ?? ''));

    if ($mesaId === null || empty($itens) || count($itens) > 100) {
        operacoes_empresariais_json(['success' => false, 'message' => 'Mesa e itens sao obrigatorios.'], 400);
    }

    resource_require_mesa($db, $mesaId, $restauranteId, ['id']);

    pedido_sequencia_preparar($db, $restauranteId); // fora da transacao
    $db->beginTransaction();
    try {
        // Numero atomico dentro da transacao: pedidos simultaneos nunca repetem numero.
        $numeroPedido = pedido_proximo_numero($db, $restauranteId);
        $stmtPedido = $db->prepare(
            "INSERT INTO pedidos (numero_pedido, mesa_id, garcom_id, status, total, observacao, origem, criado_em, atualizado_em, restaurante_id)
             VALUES (:numero, :mesa_id, :garcom_id, 'PREPARANDO', 0, :observacao, :origem, NOW(), NOW(), :rid)"
        );
        $stmtPedido->execute([
            ':numero' => $numeroPedido,
            ':mesa_id' => $mesaId,
            ':garcom_id' => (int)($ctx['usuario_id'] ?? 0),
            ':observacao' => $observacao,
            ':origem' => 'GARCOM',
            ':rid' => $restauranteId,
        ]);
        $pedidoId = (int)$db->lastInsertId();

        $total = 0.0;
        $stmtProduto = $db->prepare('SELECT id, nome, preco, categoria_id FROM produtos WHERE id = :id AND restaurante_id = :rid AND ativo = 1 LIMIT 1');
        $stmtItemInsert = $db->prepare(
            "INSERT INTO itens_pedido (pedido_id, produto_id, quantidade, destino, status, preco_unitario, subtotal, criado_em)
             VALUES (:pedido_id, :produto_id, :quantidade, :destino, 'pendente', :preco, :subtotal, NOW())"
        );

        foreach ($itens as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Item invalido');
            }
            $produtoId = input_positive_int($item['id'] ?? $item['produto_id'] ?? null);
            $quantidade = input_bounded_int($item['qtd'] ?? $item['qty'] ?? $item['quantidade'] ?? null, 1, 999);
            if ($produtoId === null || $quantidade === null) {
                throw new InvalidArgumentException('Produto ou quantidade invalida');
            }
            resource_require_produto($db, $produtoId, $restauranteId, ['id']);
            $stmtProduto->execute([':id' => $produtoId, ':rid' => $restauranteId]);
            $produto = $stmtProduto->fetch(PDO::FETCH_ASSOC);
            if (!$produto) {
                continue;
            }
            $preco = (float)($produto['preco'] ?? 0);
            $subtotal = $preco * $quantidade;
            $destino = operacoes_empresariais_inferir_destino(null, (string)($produto['nome'] ?? ''));
            $stmtItemInsert->execute([
                ':pedido_id' => $pedidoId,
                ':produto_id' => $produtoId,
                ':quantidade' => $quantidade,
                ':destino' => $destino,
                ':preco' => $preco,
                ':subtotal' => $subtotal,
            ]);
            $total += $subtotal;
        }

        $stmtUpdatePedido = $db->prepare('UPDATE pedidos SET total = :total, atualizado_em = NOW() WHERE id = :pedido_id AND restaurante_id = :rid');
        $stmtUpdatePedido->execute([':total' => $total, ':pedido_id' => $pedidoId, ':rid' => $restauranteId]);

        $db->commit();

        operacoes_empresariais_json([
            'success' => true,
            'mode' => 'mobile',
            'message' => 'Pedido criado com sucesso.',
            'pedido_id' => $pedidoId,
            'numero_pedido' => $numeroPedido,
            'restaurante_id' => $restauranteId,
            'data' => [
                'pedido_id' => $pedidoId,
                'numero_pedido' => $numeroPedido,
                'restaurante_id' => $restauranteId,
                'mesa_id' => $mesaId,
                'itens_count' => count($itens),
            ],
        ]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('operacoes_empresariais create error: ' . $e->getMessage());
        if ($e instanceof InvalidArgumentException) {
            operacoes_empresariais_json(['success' => false, 'message' => 'Itens invalidos.'], 400);
        }
        operacoes_empresariais_json(['success' => false, 'message' => 'Nao foi possivel criar o pedido.'], 500);
    }
}

operacoes_empresariais_json(['success' => false, 'message' => 'Ação nao suportada.'], 405);
