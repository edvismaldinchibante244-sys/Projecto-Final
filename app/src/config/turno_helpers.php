<?php

if (!function_exists('turno_normalizar_perfil')) {
    function turno_normalizar_perfil($perfil): string
    {
        $valor = strtoupper(trim((string)$perfil));
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

        return $aliases[$valor] ?? $valor;
    }
}

if (!function_exists('turno_perfis_operacionais')) {
    function turno_perfis_operacionais(): array
    {
        return ['ADMIN', 'GERENTE', 'GARCOM', 'CAIXA', 'COZINHA', 'BAR', 'OPERADOR', 'FUNCIONARIO'];
    }
}

if (!function_exists('turno_usuario_exige_turno_ativo')) {
    function turno_usuario_exige_turno_ativo($perfil): bool
    {
        return in_array(turno_normalizar_perfil($perfil), turno_perfis_operacionais(), true);
    }
}

if (!function_exists('turno_pode_intervir_em_outro_funcionario')) {
    function turno_pode_intervir_em_outro_funcionario($perfil): bool
    {
        return in_array(turno_normalizar_perfil($perfil), ['ADMIN', 'GERENTE', 'CAIXA'], true);
    }
}

if (!function_exists('turno_home_por_perfil')) {
    /**
     * Tela de trabalho após login/turno (fluxo tipo KDS/PDV).
     */
    function turno_home_por_perfil($perfil): string
    {
        $perfil = turno_normalizar_perfil($perfil);
        switch ($perfil) {
            case 'COZINHA':
                return 'cozinha.php';
            case 'BAR':
                return 'bar.php';
            case 'GARCOM':
                return 'garcom_mesas.php';
            case 'CAIXA':
                return 'caixa.php';
            case 'GERENTE':
            case 'ADMIN':
                return 'dashboard.php';
            default:
                return 'dashboard.php';
        }
    }
}

if (!function_exists('turno_pode_ver_auditoria')) {
    function turno_pode_ver_auditoria($perfil): bool
    {
        return in_array(turno_normalizar_perfil($perfil), ['ADMIN', 'GERENTE'], true);
    }
}

if (!function_exists('turno_detectar_tipo_atual')) {
    function turno_detectar_tipo_atual(?DateTimeInterface $agora = null): string
    {
        $agora = $agora ?: new DateTimeImmutable('now');
        $hora = (int)$agora->format('H');

        if ($hora >= 16) {
            return 'NOITE';
        }

        return 'MANHA';
    }
}
