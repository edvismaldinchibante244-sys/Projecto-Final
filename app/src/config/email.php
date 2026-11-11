<?php

/*
   Configuracoes de email (SMTP) - fallback seguro.
   As credenciais activas devem existir apenas em `V00/.env` ou nas
   variaveis de ambiente do servidor. Nunca grave senhas neste arquivo.
 */
return [
    // Habilitar envio via SMTP (PHPMailer). Se false, usa mail() do PHP.
    'use_smtp' => true,

    // Configurações SMTP
    'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => 587,
    'smtp_timeout' => 5,
    'smtp_username' => '',
    'smtp_password' => '',
    'smtp_secure' => 'tls', // 'tls' ou 'ssl'

    // Endereço remetente
    'smtp_from' => '',
];
