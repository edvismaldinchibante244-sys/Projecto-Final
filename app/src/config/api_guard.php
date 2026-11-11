<?php

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/resource_access.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/turno_guard.php';

/**
 * Guard padrao para endpoints autenticados.
 *
 * Exemplo:
 * $ctx = api_guard([
 *     'roles' => ['ADMIN', 'GERENTE'],
 *     'rate_key' => 'produto_update',
 *     'rate_max' => 60,
 *     'rate_window' => 60,
 * ]);
 *
 * Object-level access (anti-IDOR): after api_guard, load resources with
 * resource_require_pedido / resource_require_cliente / etc. using
 * $ctx['restaurante_id'] — never trust client-supplied restaurante_id.
 */
function api_guard(array $options = []): array
{
    security_start_session();
    security_set_headers();

    $rateKey = (string)($options['rate_key'] ?? basename((string)($_SERVER['SCRIPT_NAME'] ?? 'api')));
    $rateMax = (int)($options['rate_max'] ?? 120);
    $rateWindow = (int)($options['rate_window'] ?? 60);
    if (!security_rate_limit($rateKey, $rateMax, $rateWindow)) {
        security_log_event('api_rate_limited', ['rate_key' => $rateKey]);
        security_json(['success' => false, 'message' => 'Muitas requisicoes. Tente novamente em instantes.'], 429);
    }

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && empty($options['skip_csrf'])) {
        csrf_validate_or_json();
    }

    if (!empty($options['roles'])) {
        security_require_roles((array)$options['roles']);
    }

    $restauranteId = (int)($_SESSION['restaurante_id'] ?? 0);
    if (empty($_SESSION['super_admin']) && $restauranteId <= 0) {
        security_json(['success' => false, 'message' => 'Sessao sem restaurante.'], 403);
    }

    if (empty($options['skip_turno'])) {
        turno_guard_require_active((array)($options['turno_options'] ?? []));
    }

    // Tempo real: se este pedido alterar a operacao (pedido, item, mesa), os ecras sao avisados no fim.
    if ($restauranteId > 0 && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        require_once __DIR__ . '/realtime.php';
        realtime_vigiar_pedido_http($restauranteId);
    }

    return [
        'usuario_id' => (int)($_SESSION['usuario_id'] ?? 0),
        'restaurante_id' => $restauranteId,
        'perfil' => strtoupper((string)($_SESSION['perfil'] ?? '')),
        'super_admin' => (int)($_SESSION['super_admin'] ?? 0) === 1,
    ];
}
