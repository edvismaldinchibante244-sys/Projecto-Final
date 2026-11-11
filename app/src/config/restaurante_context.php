<?php

if (!function_exists('session_restaurante_contexto_id')) {
    function session_restaurante_contexto_id(): int
    {
        return (int)($_SESSION['restaurante_id'] ?? 0);
    }
}

if (!function_exists('session_restaurante_auth_id')) {
    function session_restaurante_auth_id(): int
    {
        $authId = (int)($_SESSION['restaurante_base_id'] ?? 0);
        if ($authId > 0) {
            return $authId;
        }

        $matrizId = (int)($_SESSION['matriz_id'] ?? 0);
        if ($matrizId > 0) {
            return $matrizId;
        }

        return session_restaurante_contexto_id();
    }
}

if (!function_exists('session_restaurante_capability_id')) {
    function session_restaurante_capability_id(): int
    {
        $matrizId = (int)($_SESSION['matriz_id'] ?? 0);
        if ($matrizId > 0) {
            return $matrizId;
        }

        return session_restaurante_auth_id();
    }
}

if (!function_exists('session_contexto_filial_ativo')) {
    function session_contexto_filial_ativo(): bool
    {
        $contextoId = session_restaurante_contexto_id();
        $capabilityId = session_restaurante_capability_id();

        return $contextoId > 0 && $capabilityId > 0 && $contextoId !== $capabilityId;
    }
}
