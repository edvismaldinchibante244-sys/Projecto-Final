<?php

require_once __DIR__ . '/migration_state.php';

if (!defined('PRESENCA_SCHEMA_MIGRATION_ID')) {
    define('PRESENCA_SCHEMA_MIGRATION_ID', '20260814_1225_usuario_presenca_online');
}

if (!function_exists('presenca_perfis_monitorados')) {
    function presenca_perfis_monitorados(): array
    {
        return ['ADMIN', 'CAIXA', 'GARCOM', 'COZINHA', 'BAR'];
    }
}

if (!function_exists('presenca_coluna_existe')) {
    function presenca_coluna_existe(PDO $db, string $tabela, string $coluna): bool
    {
        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :tabela
              AND COLUMN_NAME = :coluna
            LIMIT 1
        ");
        $stmt->bindValue(':tabela', $tabela, PDO::PARAM_STR);
        $stmt->bindValue(':coluna', $coluna, PDO::PARAM_STR);
        $stmt->execute();

        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('presenca_garantir_coluna_ultimo_acesso')) {
    function presenca_garantir_coluna_ultimo_acesso(PDO $db): bool
    {
        static $status = null;

        if ($status !== null) {
            return $status;
        }

        if (migration_esta_aplicada($db, PRESENCA_SCHEMA_MIGRATION_ID)) {
            $status = true;
            return true;
        }

        if (presenca_coluna_existe($db, 'usuarios', 'ultimo_acesso')) {
            $status = true;
            return true;
        }

        try {
            $db->exec("ALTER TABLE usuarios ADD COLUMN ultimo_acesso DATETIME NULL DEFAULT NULL");
        } catch (Throwable $e) {
            // Ignora erro se outra requisição criou a coluna ao mesmo tempo.
        }

        $status = presenca_coluna_existe($db, 'usuarios', 'ultimo_acesso');
        return $status;
    }
}

if (!function_exists('presenca_ping_usuario')) {
    function presenca_ping_usuario(PDO $db, int $usuarioId, int $restauranteId): bool
    {
        if ($usuarioId <= 0 || $restauranteId <= 0) {
            return false;
        }

        if (!presenca_garantir_coluna_ultimo_acesso($db)) {
            return false;
        }

        $stmt = $db->prepare("UPDATE usuarios SET ultimo_acesso = NOW() WHERE id = :id AND restaurante_id = :rid");
        $stmt->bindValue(':id', $usuarioId, PDO::PARAM_INT);
        $stmt->bindValue(':rid', $restauranteId, PDO::PARAM_INT);

        return $stmt->execute();
    }
}

if (!function_exists('presenca_marcar_usuario_offline')) {
    function presenca_marcar_usuario_offline(PDO $db, int $usuarioId, int $restauranteId): bool
    {
        if ($usuarioId <= 0 || $restauranteId <= 0) {
            return false;
        }

        if (!presenca_garantir_coluna_ultimo_acesso($db)) {
            return false;
        }

        $stmt = $db->prepare("UPDATE usuarios SET ultimo_acesso = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE id = :id AND restaurante_id = :rid");
        $stmt->bindValue(':id', $usuarioId, PDO::PARAM_INT);
        $stmt->bindValue(':rid', $restauranteId, PDO::PARAM_INT);

        return $stmt->execute();
    }
}

if (!function_exists('presenca_buscar_equipa_online')) {
    function presenca_buscar_equipa_online(PDO $db, int $restauranteId, int $limite = 12): array
    {
        $temUltimoAcesso = presenca_garantir_coluna_ultimo_acesso($db);
        $temSuperAdmin = presenca_coluna_existe($db, 'usuarios', 'super_admin');
        $perfis = "'" . implode("','", presenca_perfis_monitorados()) . "'";
        $limite = max(1, $limite);
        $filtroSuperAdmin = $temSuperAdmin ? " AND COALESCE(super_admin, 0) = 0" : "";

        if ($temUltimoAcesso) {
            $sql = "
                SELECT
                    id,
                    nome,
                    perfil,
                    foto,
                    ultimo_acesso,
                    CASE
                        WHEN ultimo_acesso IS NOT NULL AND ultimo_acesso >= DATE_SUB(NOW(), INTERVAL 3 MINUTE) THEN 1
                        ELSE 0
                    END AS online
                FROM usuarios
                WHERE restaurante_id = :rid
                  AND UPPER(REPLACE(perfil, 'Ç', 'C')) IN ({$perfis})
                  {$filtroSuperAdmin}
                ORDER BY online DESC, ultimo_acesso DESC, nome ASC
                LIMIT {$limite}
            ";
        } else {
            $sql = "
                SELECT
                    id,
                    nome,
                    perfil,
                    foto,
                    NULL AS ultimo_acesso,
                    0 AS online
                FROM usuarios
                WHERE restaurante_id = :rid
                  AND UPPER(REPLACE(perfil, 'Ç', 'C')) IN ({$perfis})
                  {$filtroSuperAdmin}
                ORDER BY nome ASC
                LIMIT {$limite}
            ";
        }

        $stmt = $db->prepare($sql);
        $stmt->bindValue(':rid', $restauranteId, PDO::PARAM_INT);
        $stmt->execute();

        $equipa = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $online = 0;

        foreach ($equipa as &$membro) {
            $membro['online'] = intval($membro['online'] ?? 0) === 1;
            if ($membro['online']) {
                $online++;
            }
        }
        unset($membro);

        return [
            'online' => $online,
            'total' => count($equipa),
            'equipa' => $equipa,
            'tem_presenca' => $temUltimoAcesso,
        ];
    }
}

