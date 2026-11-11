<?php

date_default_timezone_set('Africa/Maputo');
require_once __DIR__ . '/../app/src/config/reserva_qr_schema.php';

$checks = [];
$check = static function (bool $condition, string $name) use (&$checks): void {
    if (!$condition) {
        fwrite(STDERR, "FALHA: {$name}\n");
        exit(1);
    }
    $checks[] = $name;
};

$now = reserva_qr_now_string();
$parsed = DateTimeImmutable::createFromFormat(
    'Y-m-d H:i:s',
    $now,
    new DateTimeZone('UTC')
);

$check($parsed instanceof DateTimeImmutable, 'agora_qr_formato_valido');
$check(abs($parsed->getTimestamp() - time()) <= 2, 'agora_qr_em_utc');

$schemaSource = (string)file_get_contents(__DIR__ . '/../app/src/config/reserva_qr_schema.php');
$apiSource = (string)file_get_contents(__DIR__ . '/../src/public/api/reservas_qr_enviar.php');
$jsSource = (string)file_get_contents(__DIR__ . '/../src/public/js/mesas.js');

$check(str_contains($schemaSource, "new DateTimeZone('UTC')"), 'expiracao_criada_em_utc');
$check(str_contains($apiSource, "'QR_REVOKED' : 'QR_EXPIRED'"), 'api_distingue_qr_expirado');
$check(str_contains($apiSource, '], 410);'), 'api_retorna_http_410');
$check(str_contains($jsSource, "'Z'"), 'frontend_interpreta_datetime_como_utc');
$check(str_contains($jsSource, '(hora local).'), 'frontend_informa_hora_local');
$check(str_contains($jsSource, 'exigirReservaQrValido()'), 'frontend_bloqueia_compartilhamento_expirado');

echo 'OK: expiracao do QR de reserva validada (' . count($checks) . " verificacoes).\n";
