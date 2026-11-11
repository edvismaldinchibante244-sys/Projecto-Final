<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';

api_guard([
    'roles' => ['ADMIN', 'GERENTE', 'GARCOM'],
    'rate_key' => 'reservas_cliente',
    'rate_max' => 60,
    'rate_window' => 60,
]);

require_once APP_BASE . '/src/api/reservas_cliente.php';
