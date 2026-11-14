<?php
require_once __DIR__ . '/app_base.php';

require_once APP_BASE . '/src/config/security.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/resource_access.php';

security_start_session();
security_set_headers(true);

// Verificação leve de autenticação para servir o arquivo (imagem/PDF).
// Não usa auth_check.php completo (que aplica redirects, turno e lifecycle do plano),
// pois isso poderia bloquear o carregamento do comprovativo no navegador.
if (!isset($_SESSION['logado']) || $_SESSION['logado'] !== true) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Nao autorizado.';
    exit;
}

$path = trim((string)($_GET['path'] ?? ''));
$path = rawurldecode($path);
$path = str_replace('\\', '/', $path);
$path = ltrim($path, '/');

$deny = static function (): void {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Recurso nao encontrado.';
    exit;
};

if ($path === '' || str_contains($path, '..') || !preg_match('#^uploads/(comprovativos|documentos_titularidade)/[A-Za-z0-9_.-]+\.(pdf|jpg|jpeg|png)$#i', $path)) {
    if (function_exists('security_log_event')) {
        security_log_event('idor_denied', ['resource' => 'comprovativo', 'reason' => 'path_invalid']);
    }
    $deny();
}

$isSuperAdmin = !empty($_SESSION['super_admin']) && (int)$_SESSION['super_admin'] === 1;
$restauranteId = (int)($_SESSION['restaurante_id'] ?? 0);

try {
    $db = (new Database())->getConnection();

    if ($isSuperAdmin) {
        // Super admin pode ver qualquer comprovativo.
        $stmt = $db->prepare("
            SELECT id
            FROM compras_planos
            WHERE comprovativo_path = :path
               OR restaurante_id IN (SELECT id FROM restaurantes WHERE documento_titularidade_path = :path_doc)
            LIMIT 1
        ");
        $stmt->execute([':path' => $path, ':path_doc' => $path]);
    } else {
        if ($restauranteId <= 0) {
            if (function_exists('security_log_event')) {
                security_log_event('idor_denied', ['resource' => 'comprovativo', 'reason' => 'no_tenant']);
            }
            $deny();
        }

        // Resolve o tenant autorizado: se a sessão estiver numa filial, permite
        // aceder aos comprovativos da matriz vinculada (via session_restaurante_auth_id).
        $tenantIds = [(int)$restauranteId];
        if (function_exists('session_restaurante_auth_id')) {
            $authId = (int)session_restaurante_auth_id();
            if ($authId > 0 && !in_array($authId, $tenantIds, true)) {
                $tenantIds[] = $authId;
            }
        }
        if (function_exists('session_restaurante_capability_id')) {
            $capId = (int)session_restaurante_capability_id();
            if ($capId > 0 && !in_array($capId, $tenantIds, true)) {
                $tenantIds[] = $capId;
            }
        }

        $placeholders = implode(',', array_fill(0, count($tenantIds), '?'));
        $stmt = $db->prepare("
            SELECT id
            FROM compras_planos
            WHERE (comprovativo_path = ?
               OR restaurante_id IN (SELECT id FROM restaurantes WHERE documento_titularidade_path = ?))
              AND restaurante_id IN ($placeholders)
            LIMIT 1
        ");
        $stmt->execute(array_merge([$path, $path], $tenantIds));
    }

    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        if (function_exists('security_log_event')) {
            security_log_event('idor_denied', [
                'resource' => 'comprovativo',
                'restaurante_id' => $restauranteId,
            ]);
        }
        $deny();
    }
} catch (Throwable $e) {
    error_log('[comprovativo_arquivo] ' . $e->getMessage());
    $deny();
}

// O ficheiro vive agora fora da pasta publica. Os enviados antes desta
// mudanca ainda estao em src/public/uploads, por isso procura-se primeiro no
// sitio novo e so depois no antigo -- assim nada deixa de abrir.
require_once APP_BASE . '/src/config/storage_path.php';
$absolute = nv_ficheiro_privado($path);
if (!is_file($absolute)) {
    $legado = __DIR__ . '/' . $path;
    if (is_file($legado)) {
        $absolute = $legado;
    } else {
        $deny();
    }
}

$ext = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));
$mimeByExt = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
];

header('Content-Type: ' . ($mimeByExt[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($absolute));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . basename($absolute) . '"');
readfile($absolute);
exit;
