<?php

/**
 * Redirecionamento para pasta src/public/
 * Este arquivo redireciona automaticamente para a pasta correta
 */

// Obter a URI atual
$requestUri = $_SERVER['REQUEST_URI'];

// Se já estiver acessando src/public, não redirecionar
if (strpos($requestUri, '/src/public') === 0) {
    return false;
}

// Redirecionar para src/public/
$newUri = '/src/public' . ($requestUri === '/' ? '' : $requestUri);

// Preservar query string
if (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING']) {
    $newUri .= '?' . $_SERVER['QUERY_STRING'];
}

header('Location: ' . $newUri);
exit;
