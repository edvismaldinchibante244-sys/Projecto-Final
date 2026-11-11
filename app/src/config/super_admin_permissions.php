<?php

/**
   Permissões granulares para o painel AdminPro.
   A ausência de configuração explícita mantém o padrão: acesso total do super admin.
 */

if (!function_exists('super_admin_permission_list')) {
    function super_admin_permission_list()
    {
        return [
            'manage_restaurants',
            'manage_users',
            'approve_plans',
            'view_finance',
            'view_dashboard',
            'manage_security',
            'manage_support',
            'manage_privacy'
        ];
    }
}

if (!function_exists('super_admin_default_permissions')) {
    function super_admin_default_permissions()
    {
        $defaults = [];
        foreach (super_admin_permission_list() as $permission) {
            $defaults[$permission] = true;
        }
        return $defaults;
    }
}

if (!function_exists('super_admin_is_authenticated')) {
    function super_admin_is_authenticated()
    {
        return isset($_SESSION['logado'], $_SESSION['super_admin'])
            && $_SESSION['logado'] === true
            && intval($_SESSION['super_admin']) === 1;
    }
}

if (!function_exists('super_admin_get_permissions')) {
    function super_admin_get_permissions()
    {
        if (!super_admin_is_authenticated()) {
            return [];
        }

        $allowed = super_admin_permission_list();
        $raw = $_SESSION['super_admin_permissions'] ?? null;

        if ($raw === null || $raw === '' || $raw === '*') {
            return super_admin_default_permissions();
        }

        // Legacy-friendly behavior: start with defaults enabled and only override
        // explicitly configured keys. This prevents accidental lockout after updates.
        $resolved = super_admin_default_permissions();

        if (is_string($raw)) {
            $raw = array_filter(array_map('trim', explode(',', $raw)));
        }

        if (is_array($raw)) {
            foreach ($raw as $key => $value) {
                if (is_int($key)) {
                    $permission = strtolower(trim((string)$value));
                    if (in_array($permission, $allowed, true)) {
                        $resolved[$permission] = true;
                    }
                    continue;
                }

                $permission = strtolower(trim((string)$key));
                if (in_array($permission, $allowed, true)) {
                    $resolved[$permission] = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    $resolved[$permission] = $resolved[$permission] === null ? (bool)$value : $resolved[$permission];
                }
            }
        }

        return $resolved;
    }
}

if (!function_exists('super_admin_has_permission')) {
    function super_admin_has_permission($permission)
    {
        $permission = strtolower(trim((string)$permission));
        $permissions = super_admin_get_permissions();
        return !empty($permissions[$permission]);
    }
}

if (!function_exists('super_admin_has_any_permission')) {
    function super_admin_has_any_permission(array $permissions)
    {
        foreach ($permissions as $permission) {
            if (super_admin_has_permission($permission)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('super_admin_require_permission_json')) {
    function super_admin_require_permission_json($permission)
    {
        if (!super_admin_has_permission($permission)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Sem permissão para executar esta ação'
            ]);
            exit;
        }
    }
}

if (!function_exists('super_admin_require_any_permission_json')) {
    function super_admin_require_any_permission_json(array $permissions)
    {
        if (!super_admin_has_any_permission($permissions)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Sem permissão para executar esta ação'
            ]);
            exit;
        }
    }
}
