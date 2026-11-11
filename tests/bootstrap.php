<?php
declare(strict_types=1);

/*
 * Bootstrap dos testes. Define APP_BASE como a aplicacao real (V00/app) e
 * oferece helpers para os testes de integracao.
 */

define('NIVORA_ROOT', dirname(__DIR__));
if (!defined('APP_BASE')) {
    define('APP_BASE', NIVORA_ROOT . '/app');
}

// Ambiente de pedido HTTP neutro (os testes alteram o que precisarem).
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '203.0.113.10';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'nivora.test';

// O PHPUnit define <env> em $_ENV; a aplicacao le getenv(). Sincronizar.
foreach (['APP_ENV', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'APP_KEY'] as $k) {
    if (getenv($k) === false && isset($_ENV[$k])) {
        putenv($k . '=' . $_ENV[$k]);
    }
}

require_once __DIR__ . '/Support/TestDb.php';
require_once __DIR__ . '/Support/Cenario.php';
