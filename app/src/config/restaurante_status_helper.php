<?php

/*
   Helpers para lidar com a coluna restaurantes.status em ambientes
   onde o schema pode nao incluir todos os valores esperados pelo codigo.
 */

if (!function_exists('restaurante_status_suportados')) {
    function restaurante_status_suportados(PDO $db): array
    {
        static $statuses = null;

        if (is_array($statuses)) {
            return $statuses;
        }

        $stmt = $db->query("SHOW COLUMNS FROM restaurantes LIKE 'status'");
        $coluna = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $tipo = (string)($coluna['Type'] ?? '');

        if ($tipo !== '' && preg_match_all("/'([^']+)'/", $tipo, $matches)) {
            $statuses = array_values(array_unique(array_map('strtoupper', $matches[1])));
        } else {
            $statuses = [];
        }

        return $statuses;
    }
}

if (!function_exists('restaurante_tem_compras_planos')) {
    function restaurante_tem_compras_planos(PDO $db): bool
    {
        static $hasTable = null;

        if (is_bool($hasTable)) {
            return $hasTable;
        }

        try {
            $stmt = $db->query("SHOW TABLES LIKE 'compras_planos'");
            $hasTable = $stmt->fetchColumn() !== false;
        } catch (Throwable $e) {
            $hasTable = false;
        }

        return $hasTable;
    }
}

if (!function_exists('restaurante_status_sql_campos')) {
    function restaurante_status_sql_campos(PDO $db, string $alias = 'r'): array
    {
        $alias = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'r';

        if (!restaurante_tem_compras_planos($db)) {
            return [
                'possui_compra_pendente' => '0 AS possui_compra_pendente',
                'status_exibicao' => "COALESCE({$alias}.status, '') AS status_exibicao",
            ];
        }

        $pendenteExpr = "EXISTS(
            SELECT 1
            FROM compras_planos cp
            WHERE cp.restaurante_id = {$alias}.id
              AND cp.status IN ('PENDENTE', 'AGUARDANDO_APROVACAO')
        )";

        return [
            'possui_compra_pendente' => "{$pendenteExpr} AS possui_compra_pendente",
            'status_exibicao' => "CASE WHEN {$pendenteExpr} THEN 'PENDENTE' ELSE COALESCE({$alias}.status, '') END AS status_exibicao",
        ];
    }
}

if (!function_exists('restaurante_status_resolver_inicial')) {
    function restaurante_status_resolver_inicial(PDO $db): ?string
    {
        $suportados = restaurante_status_suportados($db);

        foreach (['PENDENTE', 'BLOQUEADO', 'ATIVO'] as $status) {
            if (in_array($status, $suportados, true)) {
                return $status;
            }
        }

        return null;
    }
}

if (!function_exists('restaurante_status_normalizar')) {
    function restaurante_status_normalizar(PDO $db, $status, $fallback = 'ATIVO'): ?string
    {
        $status = strtoupper(trim((string)$status));
        $fallback = strtoupper(trim((string)$fallback));
        $suportados = restaurante_status_suportados($db);

        if (empty($suportados)) {
            if ($status !== '') {
                return $status;
            }

            return $fallback !== '' ? $fallback : null;
        }

        if ($status !== '' && in_array($status, $suportados, true)) {
            return $status;
        }

        if ($status === 'PENDENTE') {
            foreach (['PENDENTE', 'BLOQUEADO', 'ATIVO'] as $candidato) {
                if (in_array($candidato, $suportados, true)) {
                    return $candidato;
                }
            }
        }

        if ($fallback !== '' && in_array($fallback, $suportados, true)) {
            return $fallback;
        }

        return $suportados[0] ?? null;
    }
}
