<?php

require_once dirname(__DIR__) . '/app/src/config/email_otp_template.php';

$email = nivora_email_compose_login_otp([
    'nome' => 'Administrador',
    'code' => '261415',
    'expires_minutes' => 5,
]);

if (PHP_SAPI === 'cli') {
    echo $email['html'];
    exit(0);
}

header('Content-Type: text/html; charset=UTF-8');
echo $email['html'];
