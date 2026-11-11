<?php
/**
 * Flag + CSS do plano Empresarial (sem painéis extra).
 * Melhora as telas existentes; não adiciona barras novas.
 */

if (!isset($isEmpresarialUi)) {
    $planoNomeEmpUi = '';
    if (isset($plano_atual) && is_string($plano_atual) && $plano_atual !== '') {
        $planoNomeEmpUi = strtoupper(trim($plano_atual));
    } elseif (isset($planoAtualNome) && is_string($planoAtualNome) && $planoAtualNome !== '') {
        $planoNomeEmpUi = strtoupper(trim($planoAtualNome));
    } else {
        $ridEmpUi = 0;
        if (function_exists('session_restaurante_capability_id')) {
            $ridEmpUi = (int)session_restaurante_capability_id();
        }
        if ($ridEmpUi <= 0 && function_exists('session_restaurante_contexto_id')) {
            $ridEmpUi = (int)session_restaurante_contexto_id();
        }
        if ($ridEmpUi <= 0) {
            $ridEmpUi = (int)($_SESSION['restaurante_id'] ?? 0);
        }
        if ($ridEmpUi > 0 && function_exists('plano_get_dados') && function_exists('plano_normalizar_nome')) {
            $dadosEmpUi = plano_get_dados($ridEmpUi);
            $planoNomeEmpUi = plano_normalizar_nome($dadosEmpUi['plano_nome'] ?? 'BASICO');
        }
    }
    $isEmpresarialUi = ($planoNomeEmpUi === 'EMPRESARIAL');
}

if (!function_exists('empresarial_head_assets')) {
    function empresarial_head_assets(): void
    {
        global $isEmpresarialUi;
        if (empty($isEmpresarialUi)) {
            return;
        }
        echo '<link rel="stylesheet" href="css/empresarial.css?v=20260829o">' . "\n";
    }
}

if (!function_exists('empresarial_body_class')) {
    function empresarial_body_class(string $extra = ''): string
    {
        global $isEmpresarialUi;
        $parts = [];
        if (!empty($isEmpresarialUi)) {
            $parts[] = 'plano-empresarial';
        }
        $extra = trim($extra);
        if ($extra !== '') {
            $parts[] = $extra;
        }
        return implode(' ', $parts);
    }
}
