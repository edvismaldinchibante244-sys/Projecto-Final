<?php

/**
 * Impede que scripts internos de app/src/api sejam usados como endpoints HTTP.
 *
 * Includes feitos pelos endpoints reais em src/public/api continuam permitidos,
 * pois o SCRIPT_FILENAME permanece sendo o arquivo público.
 */
function internal_api_deny_direct_http_execution(string $internalFile): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    $script = (string)($_SERVER['SCRIPT_FILENAME'] ?? '');
    $scriptReal = $script !== '' ? realpath($script) : false;
    $internalReal = realpath($internalFile);

    if ($scriptReal === false || $internalReal === false || $scriptReal !== $internalReal) {
        return;
    }

    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Recurso nao encontrado.'], JSON_UNESCAPED_UNICODE);
    exit;
}
