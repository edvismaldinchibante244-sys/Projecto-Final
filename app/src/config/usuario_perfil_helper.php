<?php

/**
 * Helpers de perfil de utilizador (validação + ENUM legado).
 */

if (!function_exists('usuario_perfil_normalizar')) {
    function usuario_perfil_normalizar($perfil): string
    {
        $valor = strtoupper(trim((string)$perfil));
        $aliases = [
            'GARÇOM' => 'GARCOM',
            'GARÃ‡OM' => 'GARCOM',
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

if (!function_exists('usuario_perfis_base')) {
    function usuario_perfis_base(): array
    {
        return ['ADMIN', 'CAIXA', 'GARCOM', 'COZINHA', 'BAR', 'GERENTE'];
    }
}

if (!function_exists('usuario_perfil_disponivel_no_plano')) {
    /**
     * Gerente: Profissional + Empresarial (flag gestao_funcionarios).
     * Cozinha/Bar: flag telas_bar_cozinha.
     */
    function usuario_perfil_disponivel_no_plano(int $restaurantePlanId, string $perfil): bool
    {
        $perfil = usuario_perfil_normalizar($perfil);
        if (!in_array($perfil, usuario_perfis_base(), true)) {
            return false;
        }

        if ($restaurantePlanId <= 0 || !function_exists('plano_tem_funcionalidade_db')) {
            return in_array($perfil, ['ADMIN', 'CAIXA', 'GARCOM'], true);
        }

        if (in_array($perfil, ['COZINHA', 'BAR'], true)) {
            return (bool)plano_tem_funcionalidade_db($restaurantePlanId, 'telas_bar_cozinha');
        }

        if ($perfil === 'GERENTE') {
            return (bool)plano_tem_funcionalidade_db($restaurantePlanId, 'gestao_funcionarios');
        }

        return true;
    }
}

if (!function_exists('usuarios_garantir_perfil_aceito')) {
    /**
     * Se `usuarios.perfil` for ENUM legado, acrescenta o valor em falta.
     */
    function usuarios_garantir_perfil_aceito(PDO $db, string $perfil): void
    {
        $perfil = usuario_perfil_normalizar($perfil);
        if ($perfil === '' || !preg_match('/^[A-Z0-9_]+$/', $perfil)) {
            return;
        }

        try {
            $colStmt = $db->query("SHOW COLUMNS FROM usuarios LIKE 'perfil'");
            $col = $colStmt ? $colStmt->fetch(PDO::FETCH_ASSOC) : null;
            if (!$col || empty($col['Type'])) {
                return;
            }

            $type = (string)$col['Type'];
            if (stripos($type, 'enum(') !== 0) {
                return;
            }

            if (!preg_match_all("/'([^']*)'/", $type, $matches) || empty($matches[1])) {
                return;
            }

            $vals = array_map('strval', $matches[1]);
            $valsUpper = array_map('strtoupper', $vals);
            if (in_array($perfil, $valsUpper, true)) {
                return;
            }

            $vals[] = $perfil;
            $enumSql = implode(',', array_map(
                static fn($v) => "'" . str_replace(["'", "\\"], '', (string)$v) . "'",
                $vals
            ));
            $nullSql = (!empty($col['Null']) && strtoupper((string)$col['Null']) === 'YES') ? 'NULL' : 'NOT NULL';
            $defaultSql = '';
            if (array_key_exists('Default', $col) && $col['Default'] !== null) {
                $defaultSql = " DEFAULT '" . str_replace(["'", "\\"], '', (string)$col['Default']) . "'";
            }

            $db->exec("ALTER TABLE usuarios MODIFY COLUMN perfil ENUM({$enumSql}) {$nullSql}{$defaultSql}");
        } catch (Throwable $e) {
            error_log('usuarios_garantir_perfil_aceito: ' . $e->getMessage());
        }
    }
}
