<?php

require_once __DIR__ . '/restaurante_context.php';

if (!function_exists('plano_upgrade_normalizar_perfil')) {
    function plano_upgrade_normalizar_perfil($perfil): string
    {
        $perfil = strtoupper(trim((string)$perfil));
        return str_replace('GARÇOM', 'GARCOM', $perfil);
    }
}

if (!function_exists('plano_upgrade_tabela_colunas')) {
    function plano_upgrade_tabela_colunas(PDO $db, string $tabela): array
    {
        try {
            $stmt = $db->query('SHOW COLUMNS FROM `' . str_replace('`', '', $tabela) . '`');
            return array_map(static function ($col) {
                return (string)$col['Field'];
            }, $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('plano_upgrade_restaurante_matriz_id')) {
    function plano_upgrade_restaurante_matriz_id(PDO $db, int $restauranteId): int
    {
        if ($restauranteId <= 0) {
            return 0;
        }

        $cols = plano_upgrade_tabela_colunas($db, 'restaurantes');
        if (!in_array('filial_id', $cols, true)) {
            return $restauranteId;
        }

        $stmt = $db->prepare('SELECT id, filial_id FROM restaurantes WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $restauranteId, PDO::PARAM_INT);
        $stmt->execute();
        $restaurante = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$restaurante) {
            return $restauranteId;
        }

        $filialId = (int)($restaurante['filial_id'] ?? 0);
        return $filialId > 0 ? $filialId : (int)$restaurante['id'];
    }
}

if (!function_exists('plano_upgrade_usuario_pode_gerir')) {
    function plano_upgrade_usuario_pode_gerir(PDO $db, ?int $usuarioId = null, ?int $restauranteId = null): bool
    {
        $usuarioId = $usuarioId ?? (int)($_SESSION['usuario_id'] ?? 0);
        $restauranteSessaoId = $restauranteId ?? session_restaurante_auth_id();
        $perfilSessao = plano_upgrade_normalizar_perfil($_SESSION['perfil'] ?? '');

        if ($usuarioId <= 0 || $restauranteSessaoId <= 0 || $perfilSessao !== 'ADMIN') {
            return false;
        }

        $matrizId = plano_upgrade_restaurante_matriz_id($db, $restauranteSessaoId);
        if ($matrizId <= 0) {
            return false;
        }

        $stmtUsuario = $db->prepare('SELECT id, restaurante_id, email, perfil FROM usuarios WHERE id = :id LIMIT 1');
        $stmtUsuario->bindValue(':id', $usuarioId, PDO::PARAM_INT);
        $stmtUsuario->execute();
        $usuario = $stmtUsuario->fetch(PDO::FETCH_ASSOC);
        if (!$usuario || plano_upgrade_normalizar_perfil($usuario['perfil'] ?? '') !== 'ADMIN') {
            return false;
        }

        // Qualquer ADMIN da matriz (ou cuja conta pertence à mesma rede) pode gerir/upgradar planos.
        $userRestId = (int)($usuario['restaurante_id'] ?? 0);
        if ($userRestId <= 0) {
            return false;
        }
        $userMatrizId = plano_upgrade_restaurante_matriz_id($db, $userRestId);

        return $userMatrizId > 0 && $userMatrizId === $matrizId;
    }
}

if (!function_exists('plano_upgrade_restaurante_alvo_id')) {
    function plano_upgrade_restaurante_alvo_id(PDO $db): int
    {
        $restauranteId = session_restaurante_capability_id();
        if ($restauranteId <= 0) {
            $restauranteId = session_restaurante_auth_id();
        }

        return plano_upgrade_restaurante_matriz_id($db, (int)$restauranteId);
    }
}
