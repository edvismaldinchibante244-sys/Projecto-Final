<?php

require_once __DIR__ . '/security.php';

security_start_session();
security_set_headers();

include_once __DIR__ . '/super_admin_permissions.php';

if (!function_exists('super_admin_check_is_api_request')) {
    function super_admin_check_is_api_request(): bool
    {
        return strpos((string)($_SERVER['REQUEST_URI'] ?? ''), '/api/') !== false
            || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
            || isset($_SERVER['HTTP_X_REQUESTED_WITH']);
    }
}

if (!function_exists('super_admin_check_deny')) {
    function super_admin_check_deny(string $message, int $status = 403): void
    {
        http_response_code($status);
        if (super_admin_check_is_api_request()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
        } else {
            echo htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        exit;
    }
}

if (!isset($_SESSION['logado']) || $_SESSION['logado'] !== true) {
    if (super_admin_check_is_api_request()) {
        super_admin_check_deny('Sessao expirada. Faca login novamente.', 401);
    }
    $scriptDir = str_replace('\\', '/', (string)dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
    $scriptDir = rtrim($scriptDir, '/');
    if (substr($scriptDir, -4) === '/api') {
        $scriptDir = substr($scriptDir, 0, -4);
    }
    $loginPath = ($scriptDir !== '' ? $scriptDir : '') . '/index.php';
    header('Location: ' . $loginPath);
    exit;
}

if (!isset($_SESSION['super_admin']) || (int)$_SESSION['super_admin'] !== 1) {
    super_admin_check_deny('Acesso negado. Apenas Super Admin.', 403);
}

function isSuperAdmin()
{
    return isset($_SESSION['super_admin']) && (int)$_SESSION['super_admin'] === 1;
}

if (!function_exists('requireSuperAdminPermission')) {
    function requireSuperAdminPermission($permission)
    {
        if (!super_admin_has_permission($permission)) {
            super_admin_check_deny('Acesso negado. Sem permissao para esta area.', 403);
        }
    }
}
