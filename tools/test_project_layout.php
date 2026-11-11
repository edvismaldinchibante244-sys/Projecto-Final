<?php

$root = dirname(__DIR__);
$checks = 0;

function layout_assert(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "FALHA: {$message}\n");
        exit(1);
    }
}

$required = [
    'services/realtime/server.js',
    'services/realtime/package.json',
    'services/realtime/README.md',
    'scripts/cron/run_backup_cron.bat',
    'scripts/cron/run_security_ops_cron.bat',
    'scripts/cron/run_plano_lifecycle_cron.bat',
    'scripts/cron/run_websocket_server.bat',
    'src/public/favicon.ico',
    'README.md',
    '.env.example',
    '.gitignore',
];

foreach ($required as $relative) {
    layout_assert(is_file($root . '/' . $relative), "arquivo obrigatorio ausente: {$relative}");
}

$websocketWrapper = (string)file_get_contents($root . '/websocket-server.js');
layout_assert(
    strpos($websocketWrapper, "services/realtime/server.js") !== false,
    'wrapper WebSocket nao aponta para a implementacao organizada'
);

$cronWrapper = (string)file_get_contents($root . '/run_websocket_server.bat');
layout_assert(
    stripos($cronWrapper, 'scripts\\cron\\run_websocket_server.bat') !== false,
    'wrapper do cron WebSocket nao preserva o caminho antigo'
);

$manifest = json_decode((string)file_get_contents($root . '/services/realtime/package.json'), true);
layout_assert(is_array($manifest), 'package.json do realtime invalido');
layout_assert(($manifest['main'] ?? '') === 'server.js', 'entrada do realtime deve ser server.js');

$htaccess = (string)file_get_contents($root . '/.htaccess');
layout_assert(strpos($htaccess, 'services|scripts|db|apps|node_modules') !== false, 'pastas internas nao estao protegidas no Apache');

echo "OK: organizacao do projeto validada ({$checks} verificacoes).\n";
