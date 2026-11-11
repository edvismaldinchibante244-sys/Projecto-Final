<?php

/*
   Auth Check - Verify if user is logged in
 */

include_once __DIR__ . '/security.php';
include_once __DIR__ . '/terminal_pin.php';
security_start_session();
security_set_headers();
// Marca (cookie sem dados) que este navegador tem uma sessao da equipa: o js/nv_acoes.js so vigia a
// sessao nas paginas internas quando esta marca existe. Nao contem nada sobre o utilizador.
if (!function_exists('auth_marca_sessao')) {
    function auth_marca_sessao(bool $ativa): void
    {
        if (headers_sent() || (isset($_COOKIE['nv_sessao']) === $ativa)) {
            return;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie('nv_sessao', $ativa ? '1' : '', ['expires' => $ativa ? 0 : time() - 3600, 'path' => '/', 'secure' => $https, 'httponly' => false, 'samesite' => 'Lax']);
    }
}
$authCheckIsApiRequest = strpos((string)($_SERVER['REQUEST_URI'] ?? ''), '/api/') !== false;
// Sem uso: 45 min; com "Manter sessao iniciada neste computador": 12 h.
// TESTE TEMPORARIO: 2 minutos (repor 720 : 45 depois do teste).
if (!security_enforce_idle_timeout(!empty($_SESSION['manter_sessao']) ? 2 : 2)) {
    auth_marca_sessao(false);
    if (
        $authCheckIsApiRequest ||
        isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
        (isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], 'api') !== false)
    ) {
        header('Content-Type: application/json');
        header('X-Nv-Sessao: expirada');
        header('X-Nv-Redirect: ' . terminal_destino_sem_sessao());
        echo json_encode([
            'success' => false,
            'message' => 'Sessao expirada. Faça login novamente.',
            'redirect' => terminal_destino_sem_sessao()
        ]);
        exit;
    }
    header('Location: ' . terminal_destino_sem_sessao('index.php?erro=sessao_expirada'));
    exit;
}
security_regenerate_session(15);

include_once __DIR__ . '/presenca_online.php';
include_once __DIR__ . '/restaurante_context.php';
include_once __DIR__ . '/turno_guard.php';
include_once __DIR__ . '/plano_lifecycle.php';

// Check if user is logged in
if (!isset($_SESSION['logado']) || $_SESSION['logado'] !== true) {
    auth_marca_sessao(false);
    // Return JSON error for API calls
    if (
        $authCheckIsApiRequest ||
        isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
        (isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], 'api') !== false)
    ) {
        header('Content-Type: application/json');
        header('X-Nv-Sessao: expirada');
        header('X-Nv-Redirect: ' . terminal_destino_sem_sessao());
        echo json_encode([
            'success' => false,
            'message' => 'Acesso não autorizado. Faça login primeiro.',
            'redirect' => terminal_destino_sem_sessao()
        ]);
        exit;
    }

    // Redirect to login for regular page requests (ou ao ecra de bloqueio num terminal)
    header('Location: ' . terminal_destino_sem_sessao());
    exit;
}

auth_marca_sessao(true);
// Gerentes e administradores recebem os pedidos de autorizacao a distancia (js/autorizacoes_pendentes.js).
if (!headers_sent()) {
    $podeAprovar = empty($_SESSION['super_admin']) && in_array(strtoupper(str_replace('Ç', 'C', (string)($_SESSION['perfil'] ?? ''))), ['ADMIN', 'GERENTE'], true);
    // Sem turno aberto as APIs respondem 403: nao vale a pena o ecra ficar a pedir autorizacoes.
    if ($podeAprovar
        && function_exists('turno_usuario_exige_turno_ativo')
        && function_exists('turno_guard_usuario_tem_turno')
        && turno_usuario_exige_turno_ativo((string)($_SESSION['perfil'] ?? ''))
        && !turno_guard_usuario_tem_turno((int)($_SESSION['usuario_id'] ?? 0), (int)($_SESSION['restaurante_id'] ?? 0))) {
        $podeAprovar = false;
    }
    if ($podeAprovar !== isset($_COOKIE['nv_aprova'])) {
        setcookie('nv_aprova', $podeAprovar ? '1' : '', ['expires' => $podeAprovar ? 0 : time() - 3600, 'path' => '/', 'httponly' => false, 'samesite' => 'Lax',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')]);
    }
}

// Bloqueia imediatamente contas inativas removidas por soft delete.
$userIdOnline = intval($_SESSION['usuario_id'] ?? 0);
$restauranteIdOnline = session_restaurante_auth_id();
$ehSuperAdminSessao = intval($_SESSION['super_admin'] ?? 0) === 1;
$isApiRequest = strpos((string)($_SERVER['REQUEST_URI'] ?? ''), '/api/') !== false;

// Defesa global contra CSRF em endpoints legados que ainda nao usam token.
// Endpoints novos devem continuar usando csrf_validate_or_json().
if ($isApiRequest && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $source = $origin !== '' ? $origin : $referer;

    if ($host !== '' && $source !== '') {
        $sourceHost = strtolower((string)(parse_url($source, PHP_URL_HOST) ?: ''));
        $sourcePort = (string)(parse_url($source, PHP_URL_PORT) ?: '');
        $hostParts = explode(':', $host, 2);
        $hostName = strtolower($hostParts[0]);
        $hostPort = $hostParts[1] ?? '';

        if ($sourceHost !== '' && ($sourceHost !== $hostName || ($sourcePort !== '' && $hostPort !== '' && $sourcePort !== $hostPort))) {
            security_log_event('csrf_origin_denied', [
                'host' => $host,
                'source_host' => $sourceHost,
                'source_port' => $sourcePort,
                'uri' => $_SERVER['REQUEST_URI'] ?? '',
            ]);
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Origem da requisicao invalida.']);
            exit;
        }
    }
}

if (!$ehSuperAdminSessao && $userIdOnline > 0 && $restauranteIdOnline > 0) {
    try {
        if (!class_exists('Database')) {
            include_once __DIR__ . '/database.php';
        }
        if (class_exists('Database')) {
            $dbStatus = Database::infra();
            if ($dbStatus instanceof PDO) {
                $stmtStatus = $dbStatus->prepare('SELECT ativo, perfil FROM usuarios WHERE id = :id AND restaurante_id = :rid LIMIT 1');
                $stmtStatus->bindValue(':id', $userIdOnline, PDO::PARAM_INT);
                $stmtStatus->bindValue(':rid', $restauranteIdOnline, PDO::PARAM_INT);
                $stmtStatus->execute();
                $usuarioAtivo = $stmtStatus->fetch(PDO::FETCH_ASSOC);

                if (!$usuarioAtivo || intval($usuarioAtivo['ativo'] ?? 0) !== 1) {
                    $_SESSION = [];
                    session_unset();
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        session_destroy();
                    }

                    if ($isApiRequest || isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => false,
                            'message' => 'Sua conta foi inativada. Faça login novamente.',
                            'redirect' => 'index.php'
                        ]);
                        exit;
                    }

                    header('Location: index.php?erro=conta_inativada');
                    exit;
                }

                $perfilAtual = trim((string)($usuarioAtivo['perfil'] ?? ''));
                if ($perfilAtual !== '') {
                    $_SESSION['perfil'] = function_exists('turno_normalizar_perfil')
                        ? turno_normalizar_perfil($perfilAtual)
                        : strtoupper($perfilAtual);
                }
            }
        }
    } catch (Throwable $e) {
        // A validacao de status nao deve bloquear a aplicacao em caso de falha temporaria.
    }
}

// Atualiza presença online do usuário logado com throttle para reduzir escrita no banco.
$lastPresencePing = intval($_SESSION['last_presence_ping'] ?? 0);

if ($userIdOnline > 0 && $restauranteIdOnline > 0 && (time() - $lastPresencePing) >= 45) {
    try {
        if (!class_exists('Database')) {
            include_once __DIR__ . '/database.php';
        }
        if (class_exists('Database')) {
            $dbPresence = Database::infra();
            if ($dbPresence instanceof PDO) {
                if (presenca_ping_usuario($dbPresence, $userIdOnline, $restauranteIdOnline)) {
                    $_SESSION['last_presence_ping'] = time();
                }
            }
        }
    } catch (Throwable $e) {
        // Falha de presença não deve bloquear autenticação/páginas.
    }
}

// Restrição global de rotas por perfil
$perfilSessao = strtoupper(trim((string)($_SESSION['perfil'] ?? '')));
$aliasesPerfil = [
    'GARÇOM' => 'GARCOM',
    'OPERADOR' => 'GARCOM',
    'FUNCIONARIO' => 'GARCOM',
    'COZINHEIRO' => 'COZINHA',
    'CHEF' => 'COZINHA',
    'BARMAN' => 'BAR',
    'BARTENDER' => 'BAR',
];
$perfilSessao = $aliasesPerfil[$perfilSessao] ?? $perfilSessao;
$scriptAtual = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$isApiRequest = strpos((string)($_SERVER['REQUEST_URI'] ?? ''), '/api/') !== false;

if (!$isApiRequest) {
    $rotasPorPerfil = [
        'CAIXA' => [
            'permitidas' => ['dashboard.php', 'vendas.php', 'caixa.php', 'caixa_fecho_imprimir.php', 'caixa_mesas.php', 'pedidos.php', 'iniciar_turno.php', 'logout.php'],
            'redirect' => 'caixa.php'
        ],
        'GARCOM' => [
            'permitidas' => ['dashboard.php', 'pedidos.php', 'novo_pedido.php', 'mesas.php', 'garcom_mesas.php', 'iniciar_turno.php', 'logout.php'],
            'redirect' => 'pedidos.php'
        ],
        'COZINHA' => [
            'permitidas' => ['cozinha.php', 'novo_pedido.php', 'iniciar_turno.php', 'finalizar_turno.php', 'logout.php', 'atualizar_status.php', 'api/setor_itens.php', 'api/pedido_item_status.php', 'api/pedido_sse.php'],
            'redirect' => 'cozinha.php'
        ],
        'BAR' => [
            'permitidas' => ['bar.php', 'novo_pedido.php', 'iniciar_turno.php', 'finalizar_turno.php', 'logout.php', 'atualizar_status.php', 'api/setor_itens.php', 'api/pedido_item_status.php', 'api/pedido_sse.php'],
            'redirect' => 'bar.php'
        ],
    ];

    // Plano Básico: sem telas Bar/Cozinha — fluxo no PDV
    $authPlanRid = 0;
    if (function_exists('session_restaurante_capability_id')) {
        $authPlanRid = (int)session_restaurante_capability_id();
    }
    if ($authPlanRid <= 0) {
        $authPlanRid = (int)($_SESSION['restaurante_id'] ?? 0);
    }
    $temTelasBarCozinhaAuth = false;
    $temGarconsAuth = false;
    $temContasAbertasAuth = false;
    if ($authPlanRid > 0) {
        include_once __DIR__ . '/plano_check.php';
        $temTelasBarCozinhaAuth = function_exists('plano_tem_funcionalidade_db')
            && plano_tem_funcionalidade_db($authPlanRid, 'telas_bar_cozinha');
        $temGarconsAuth = function_exists('plano_tem_funcionalidade_db')
            && plano_tem_funcionalidade_db($authPlanRid, 'garcons');
        $temContasAbertasAuth = function_exists('plano_tem_funcionalidade_db')
            && plano_tem_funcionalidade_db($authPlanRid, 'contas_abertas');
    }
    if (!$temTelasBarCozinhaAuth) {
        $rotasPorPerfil['COZINHA'] = [
            'permitidas' => ['novo_pedido.php', 'vendas.php', 'dashboard.php', 'iniciar_turno.php', 'finalizar_turno.php', 'logout.php'],
            'redirect' => 'novo_pedido.php'
        ];
        $rotasPorPerfil['BAR'] = [
            'permitidas' => ['novo_pedido.php', 'vendas.php', 'dashboard.php', 'iniciar_turno.php', 'finalizar_turno.php', 'logout.php'],
            'redirect' => 'novo_pedido.php'
        ];
    }
    if (!$temContasAbertasAuth) {
        $rotasPorPerfil['CAIXA']['permitidas'] = array_values(array_filter(
            $rotasPorPerfil['CAIXA']['permitidas'],
            static fn($p) => $p !== 'caixa_mesas.php'
        ));
        $rotasPorPerfil['CAIXA']['redirect'] = 'caixa.php';
    }
    if (!$temGarconsAuth) {
        $rotasPorPerfil['GARCOM']['permitidas'] = array_values(array_filter(
            $rotasPorPerfil['GARCOM']['permitidas'],
            static fn($p) => $p !== 'garcom_mesas.php'
        ));
        $rotasPorPerfil['GARCOM']['redirect'] = 'novo_pedido.php';
    }

    // Qualquer funcionario pode definir o seu PIN de terminal.
    foreach ($rotasPorPerfil as $perfilRotas => $cfgRotas) {
        $rotasPorPerfil[$perfilRotas]['permitidas'][] = 'definir_pin.php';
    }

    if (isset($rotasPorPerfil[$perfilSessao]) && !in_array($scriptAtual, $rotasPorPerfil[$perfilSessao]['permitidas'], true)) {
        $redirectDestino = $rotasPorPerfil[$perfilSessao]['redirect'];
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Sem permissão para acessar esta página.',
                'redirect' => $redirectDestino
            ]);
            exit;
        }

        $qs = '?erro=sem_permissao';
        if (!$temTelasBarCozinhaAuth && in_array($perfilSessao, ['COZINHA', 'BAR'], true)) {
            $qs = '?aviso=fluxo_basico_pdv';
        }
        header('Location: ' . $redirectDestino . $qs);
        exit;
    }
}

plano_lifecycle_enforce_for_session($isApiRequest);

turno_guard_require_active();

// Backward-compatible helper used by legacy pages.
if (!function_exists('checkPermission')) {
    function normalizePerfil($perfil)
    {
        $p = strtoupper(trim((string)$perfil));
        $aliases = [
            'GARÇOM' => 'GARCOM',
            'OPERADOR' => 'GARCOM',
            'FUNCIONARIO' => 'GARCOM',
            'COZINHEIRO' => 'COZINHA',
            'CHEF' => 'COZINHA',
            'BARMAN' => 'BAR',
            'BARTENDER' => 'BAR',
            'MANAGER' => 'GERENTE',
            'GESTOR' => 'GERENTE',
        ];
        return $aliases[$p] ?? $p;
    }

    function checkPermission($allowedPerfis = ['ADMIN'])
    {
        if (!isset($_SESSION['logado']) || $_SESSION['logado'] !== true) {
            return false;
        }

        if (!is_array($allowedPerfis)) {
            $allowedPerfis = [$allowedPerfis];
        }

        $perfil = normalizePerfil($_SESSION['perfil'] ?? '');
        $allowed = array_map('normalizePerfil', $allowedPerfis);
        return in_array($perfil, $allowed, true);
    }

    function requirePermissionOrRedirect($allowedPerfis = ['ADMIN'], $redirect = 'dashboard.php')
    {
        if (!checkPermission($allowedPerfis)) {
            header('Location: ' . $redirect . '?erro=sem_permissao');
            exit;
        }
    }
}
