<?php
require_once __DIR__ . '/app_base.php';
// Painel legado de reservas redirecionado para a tela atual de mesas.
include_once APP_BASE . '/src/config/auth_check.php';

header('Location: mesas.php');
exit;

