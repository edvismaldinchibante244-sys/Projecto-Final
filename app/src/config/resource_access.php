<?php

/**
 * Object-level access (anti-IDOR).
 *
 * Never trust client-supplied resource IDs alone. Always scope by the
 * session restaurante_id. Missing and cross-tenant resources share the
 * same generic 404 so existence is not disclosed.
 */

require_once __DIR__ . '/security.php';

if (!function_exists('resource_tenant_tables')) {
    /**
     * Allowlist of tables that have restaurante_id + id.
     *
     * @return array<string, true>
     */
    function resource_tenant_tables(): array
    {
        static $tables = [
            'pedidos' => true,
            'mesas' => true,
            'clientes' => true,
            'produtos' => true,
            'usuarios' => true,
            'vendas' => true,
            'categorias' => true,
            'reservas' => true,
            'estoque_insumos' => true,
            'estoque_lotes' => true,
            'compras_planos' => true,
            'support_tickets' => true,
        ];
        return $tables;
    }
}

if (!function_exists('resource_deny_not_found')) {
    /**
     * Generic 404 for missing or unauthorized object access (anti-enumeration).
     *
     * @return never
     */
    function resource_deny_not_found(?string $message = null, array $context = []): void
    {
        if (function_exists('security_log_event')) {
            security_log_event('idor_denied', $context);
        }
        $msg = $message !== null && trim($message) !== ''
            ? trim($message)
            : 'Recurso nao encontrado.';
        security_json(['success' => false, 'message' => $msg], 404);
    }
}

if (!function_exists('resource_sanitize_columns')) {
    /**
     * @param array<int, string> $cols
     */
    function resource_sanitize_columns(array $cols): string
    {
        if ($cols === [] || in_array('*', $cols, true)) {
            return '*';
        }
        $safe = [];
        foreach ($cols as $col) {
            $col = trim((string)$col);
            if ($col !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $col)) {
                $safe[] = $col;
            }
        }
        return $safe === [] ? '*' : implode(', ', $safe);
    }
}

if (!function_exists('resource_fetch_for_tenant')) {
    /**
     * Fetch a tenant-scoped row or null (no HTTP exit).
     *
     * @param array<int, string> $cols
     */
    function resource_fetch_for_tenant(
        PDO $db,
        string $table,
        int $id,
        int $restauranteId,
        array $cols = ['*'],
        string $idColumn = 'id',
        string $tenantColumn = 'restaurante_id'
    ): ?array {
        $table = strtolower(trim($table));
        if ($id <= 0 || $restauranteId <= 0 || !isset(resource_tenant_tables()[$table])) {
            return null;
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $idColumn)
            || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $tenantColumn)
        ) {
            return null;
        }

        $select = resource_sanitize_columns($cols);
        $sql = "SELECT {$select}
                FROM {$table}
                WHERE {$idColumn} = :id
                  AND {$tenantColumn} = :rid
                LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':rid', $restauranteId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('resource_require_for_tenant')) {
    /**
     * Fetch tenant-scoped row or exit with generic 404.
     *
     * @param array<int, string> $cols
     */
    function resource_require_for_tenant(
        PDO $db,
        string $table,
        int $id,
        int $restauranteId,
        array $cols = ['*'],
        string $idColumn = 'id',
        string $tenantColumn = 'restaurante_id'
    ): array {
        $row = resource_fetch_for_tenant($db, $table, $id, $restauranteId, $cols, $idColumn, $tenantColumn);
        if ($row === null) {
            resource_deny_not_found(null, [
                'table' => $table,
                'resource_id' => $id,
                'restaurante_id' => $restauranteId,
            ]);
        }
        return $row;
    }
}

if (!function_exists('resource_require_pedido')) {
    function resource_require_pedido(PDO $db, int $pedidoId, int $restauranteId, array $cols = ['*']): array
    {
        return resource_require_for_tenant($db, 'pedidos', $pedidoId, $restauranteId, $cols);
    }
}

if (!function_exists('resource_require_mesa')) {
    function resource_require_mesa(PDO $db, int $mesaId, int $restauranteId, array $cols = ['*']): array
    {
        return resource_require_for_tenant($db, 'mesas', $mesaId, $restauranteId, $cols);
    }
}

if (!function_exists('resource_require_cliente')) {
    function resource_require_cliente(PDO $db, int $clienteId, int $restauranteId, array $cols = ['*']): array
    {
        return resource_require_for_tenant($db, 'clientes', $clienteId, $restauranteId, $cols);
    }
}

if (!function_exists('resource_require_produto')) {
    function resource_require_produto(PDO $db, int $produtoId, int $restauranteId, array $cols = ['*']): array
    {
        return resource_require_for_tenant($db, 'produtos', $produtoId, $restauranteId, $cols);
    }
}

if (!function_exists('resource_require_usuario')) {
    function resource_require_usuario(PDO $db, int $usuarioId, int $restauranteId, array $cols = ['*']): array
    {
        return resource_require_for_tenant($db, 'usuarios', $usuarioId, $restauranteId, $cols);
    }
}

if (!function_exists('resource_require_venda')) {
    function resource_require_venda(PDO $db, int $vendaId, int $restauranteId, array $cols = ['*']): array
    {
        return resource_require_for_tenant($db, 'vendas', $vendaId, $restauranteId, $cols);
    }
}

if (!function_exists('resource_require_reserva')) {
    function resource_require_reserva(PDO $db, int $reservaId, int $restauranteId, array $cols = ['*']): array
    {
        return resource_require_for_tenant($db, 'reservas', $reservaId, $restauranteId, $cols);
    }
}

if (!function_exists('resource_require_support_ticket')) {
    function resource_require_support_ticket(PDO $db, int $ticketId, int $restauranteId, array $cols = ['*']): array
    {
        return resource_require_for_tenant($db, 'support_tickets', $ticketId, $restauranteId, $cols);
    }
}

if (!function_exists('resource_require_item_pedido')) {
    /**
     * Item de pedido scoped via join pedidos.restaurante_id.
     *
     * @return array<string, mixed>
     */
    function resource_require_item_pedido(PDO $db, int $itemId, int $restauranteId): array
    {
        if ($itemId <= 0 || $restauranteId <= 0) {
            resource_deny_not_found(null, [
                'table' => 'itens_pedido',
                'resource_id' => $itemId,
                'restaurante_id' => $restauranteId,
            ]);
        }

        $stmt = $db->prepare("
            SELECT ip.*, p.restaurante_id AS pedido_restaurante_id
            FROM itens_pedido ip
            INNER JOIN pedidos p ON p.id = ip.pedido_id
            WHERE ip.id = :item_id
              AND p.restaurante_id = :rid
            LIMIT 1
        ");
        $stmt->execute([
            ':item_id' => $itemId,
            ':rid' => $restauranteId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            resource_deny_not_found(null, [
                'table' => 'itens_pedido',
                'resource_id' => $itemId,
                'restaurante_id' => $restauranteId,
            ]);
        }
        return $row;
    }
}

if (!function_exists('resource_reject_client_restaurante_id')) {
    /**
     * Ignore/deny client-supplied restaurante_id that differs from session.
     * Returns the trusted session restaurante_id.
     */
    function resource_reject_client_restaurante_id(?int $requestedFromClient = null): int
    {
        if (function_exists('security_require_restaurante_context')) {
            return security_require_restaurante_context($requestedFromClient);
        }

        $sessao = (int)($_SESSION['restaurante_id'] ?? 0);
        if ($sessao <= 0) {
            security_json(['success' => false, 'message' => 'Contexto de restaurante invalido.'], 403);
        }
        if ($requestedFromClient !== null && $requestedFromClient > 0 && $requestedFromClient !== $sessao) {
            if (function_exists('security_log_event')) {
                security_log_event('tenant_violation', [
                    'session_restaurante_id' => $sessao,
                    'requested_restaurante_id' => $requestedFromClient,
                ]);
            }
            security_json(['success' => false, 'message' => 'Contexto de restaurante invalido.'], 403);
        }
        return $sessao;
    }
}
