<?php
/**
 * Resolve APP_BASE (pasta app) com validacao de estrutura.
 */
if (!defined('APP_BASE')) {
    $candidates = [
        __DIR__ . '/../../app', // padrao esperado: V00/src/public -> V00/app
        __DIR__ . '/../app',    // fallback legado
    ];

    foreach ($candidates as $cand) {
        $real = realpath($cand);
        if ($real && is_dir($real) && is_file($real . '/src/config/database.php')) {
            define('APP_BASE', $real);
            break;
        }
    }

    if (!defined('APP_BASE')) {
        die('APP_BASE nao encontrado');
    }
}


require_once APP_BASE . '/src/config/storage_path.php';

// Fuso horario da aplicacao (o mesmo que a base de dados usa: ver Database::fusoHorario()).
if (!defined('NV_FUSO_DEFINIDO')) {
    define('NV_FUSO_DEFINIDO', true);
    $nvFuso = trim((string)(getenv('APP_TIMEZONE') ?: 'Africa/Maputo'));
    @date_default_timezone_set(in_array($nvFuso, timezone_identifiers_list(), true) ? $nvFuso : 'Africa/Maputo');
    unset($nvFuso);
}
