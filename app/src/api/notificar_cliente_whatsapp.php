<?php

require_once __DIR__ . '/../config/internal_api.php';
internal_api_deny_direct_http_execution(__FILE__);

$autoloadPath = __DIR__ . '/../../vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

if (!class_exists(\Twilio\Rest\Client::class)) {
    $twilioSrc = __DIR__ . '/../../vendor/twilio/sdk/src';
    if (is_dir($twilioSrc)) {
        spl_autoload_register(static function (string $class) use ($twilioSrc): void {
            $prefix = 'Twilio\\';
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            $file = $twilioSrc . '/Twilio/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }
}

$emailHelperPath = __DIR__ . '/../config/email_helper.php';
if (file_exists($emailHelperPath)) {
    require_once $emailHelperPath;
    if (function_exists('saas_email_load_local_env')) {
        saas_email_load_local_env();
    }
}

use Twilio\Rest\Client;

function notificar_cliente_whatsapp($telefone, $mensagem, ?string $mediaUrl = null): bool
{
    notificar_cliente_whatsapp_set_last_error(null);
    notificar_cliente_whatsapp_set_last_result(null);

    if (notificar_cliente_whatsapp_cloud_api_configurada()) {
        return notificar_cliente_whatsapp_cloud_api($telefone, $mensagem, $mediaUrl);
    }

    if (!class_exists(Client::class)) {
        notificar_cliente_whatsapp_set_last_error('Biblioteca Twilio indisponivel.');
        return false;
    }

    $sid = notificar_cliente_whatsapp_env('TWILIO_ACCOUNT_SID') ?: notificar_cliente_whatsapp_env('TWILIO_SID') ?: '';
    $token = notificar_cliente_whatsapp_env('TWILIO_AUTH_TOKEN') ?: notificar_cliente_whatsapp_env('TWILIO_TOKEN') ?: '';
    $twilioNumber = notificar_cliente_whatsapp_env('TWILIO_WHATSAPP_FROM') ?: 'whatsapp:+14155238886';
    $destino = notificar_cliente_whatsapp_normalizar_destino($telefone);
    $mensagem = trim((string)$mensagem);

    if ($destino === null || $mensagem === '') {
        notificar_cliente_whatsapp_set_last_error('Telefone ou mensagem invalida.');
        return false;
    }

    if ($sid === '' || $token === '') {
        notificar_cliente_whatsapp_set_last_error('Credenciais Twilio nao configuradas.');
        return false;
    }

    $twilioNumber = trim((string)$twilioNumber);
    if (stripos($twilioNumber, 'whatsapp:') !== 0) {
        $normalizadoFrom = notificar_cliente_whatsapp_normalizar_destino($twilioNumber);
        if ($normalizadoFrom === null) {
            notificar_cliente_whatsapp_set_last_error('TWILIO_WHATSAPP_FROM invalido.');
            return false;
        }
        $twilioNumber = 'whatsapp:' . $normalizadoFrom;
    }

    if (!extension_loaded('curl')) {
        return notificar_cliente_whatsapp_twilio_http($sid, $token, $twilioNumber, $destino, $mensagem, $mediaUrl);
    }

    try {
        $client = new Client($sid, $token);

        $payload = [
            'from' => $twilioNumber,
            'body' => $mensagem,
        ];

        if ($mediaUrl !== null && trim($mediaUrl) !== '') {
            $payload['mediaUrl'] = [trim($mediaUrl)];
        }

        $message = $client->messages->create(
            'whatsapp:' . $destino,
            $payload
        );

        notificar_cliente_whatsapp_set_last_result([
            'provider' => 'twilio',
            'sid' => $message->sid ?? null,
            'status' => $message->status ?? null,
            'from' => $twilioNumber,
            'to' => 'whatsapp:' . $destino,
            'sandbox' => notificar_cliente_whatsapp_is_twilio_sandbox($twilioNumber),
        ]);

        return true;
    } catch (\Throwable $e) {
        notificar_cliente_whatsapp_set_last_error($e->getMessage());
        return false;
    }
}

function notificar_cliente_whatsapp_cloud_api_configurada(): bool
{
    $accessToken = notificar_cliente_whatsapp_env('WHATSAPP_CLOUD_ACCESS_TOKEN') ?: notificar_cliente_whatsapp_env('META_WHATSAPP_ACCESS_TOKEN');
    $phoneNumberId = notificar_cliente_whatsapp_env('WHATSAPP_CLOUD_PHONE_NUMBER_ID') ?: notificar_cliente_whatsapp_env('META_WHATSAPP_PHONE_NUMBER_ID');

    return is_string($accessToken)
        && trim($accessToken) !== ''
        && is_string($phoneNumberId)
        && trim($phoneNumberId) !== '';
}

function notificar_cliente_whatsapp_twilio_http(
    string $sid,
    string $token,
    string $from,
    string $destino,
    string $mensagem,
    ?string $mediaUrl = null
): bool {
    if (!ini_get('allow_url_fopen')) {
        notificar_cliente_whatsapp_set_last_error('allow_url_fopen desativado e cURL indisponivel.');
        return false;
    }

    $postFields = [
        'From' => $from,
        'To' => 'whatsapp:' . $destino,
        'Body' => $mensagem,
    ];

    if ($mediaUrl !== null && trim($mediaUrl) !== '') {
        $postFields['MediaUrl'] = trim($mediaUrl);
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => [
                'Authorization: Basic ' . base64_encode($sid . ':' . $token),
                'Content-Type: application/x-www-form-urlencoded',
            ],
            'content' => http_build_query($postFields),
            'ignore_errors' => true,
            'timeout' => 25,
        ],
    ]);

    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json';
    $response = @file_get_contents($url, false, $context);
    $status = 0;

    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $matches)) {
            $status = (int)$matches[1];
            break;
        }
    }

    if ($response === false || $status < 200 || $status >= 300) {
        $detail = '';
        $decoded = is_string($response) ? json_decode($response, true) : null;
        if (is_array($decoded)) {
            $detail = (string)($decoded['message'] ?? $decoded['detail'] ?? '');
        }

        notificar_cliente_whatsapp_set_last_error(
            'Twilio HTTP ' . ($status ?: 'sem_status') . ($detail !== '' ? ': ' . $detail : '')
        );
        return false;
    }

    $decoded = json_decode((string)$response, true);
    notificar_cliente_whatsapp_set_last_result([
        'provider' => 'twilio',
        'sid' => is_array($decoded) ? ($decoded['sid'] ?? null) : null,
        'status' => is_array($decoded) ? ($decoded['status'] ?? null) : null,
        'from' => $from,
        'to' => 'whatsapp:' . $destino,
        'sandbox' => notificar_cliente_whatsapp_is_twilio_sandbox($from),
    ]);

    return true;
}

function notificar_cliente_whatsapp_cloud_api($telefone, $mensagem, ?string $mediaUrl = null): bool
{
    $accessToken = notificar_cliente_whatsapp_env('WHATSAPP_CLOUD_ACCESS_TOKEN') ?: notificar_cliente_whatsapp_env('META_WHATSAPP_ACCESS_TOKEN');
    $phoneNumberId = notificar_cliente_whatsapp_env('WHATSAPP_CLOUD_PHONE_NUMBER_ID') ?: notificar_cliente_whatsapp_env('META_WHATSAPP_PHONE_NUMBER_ID');
    $destino = notificar_cliente_whatsapp_normalizar_destino($telefone);
    $mensagem = trim((string)$mensagem);
    $mediaUrl = trim((string)$mediaUrl);

    if ($accessToken === false || $accessToken === '' || $phoneNumberId === false || $phoneNumberId === '') {
        return false;
    }

    if ($destino === null || $mensagem === '') {
        return false;
    }

    $payload = [
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => ltrim($destino, '+'),
    ];

    if ($mediaUrl !== '') {
        $payload['type'] = 'image';
        $uploadedMediaId = null;

        if (is_file($mediaUrl)) {
            $uploadedMediaId = notificar_cliente_whatsapp_cloud_api_upload_media($phoneNumberId, $accessToken, $mediaUrl);
            if ($uploadedMediaId === null) {
                return false;
            }
        }

        if ($uploadedMediaId !== null) {
            $payload['image'] = [
                'id' => $uploadedMediaId,
                'caption' => $mensagem,
            ];
        } else {
            $payload['image'] = [
                'link' => $mediaUrl,
                'caption' => $mensagem,
            ];
        }
    } else {
        $payload['type'] = 'text';
        $payload['text'] = [
            'preview_url' => true,
            'body' => $mensagem,
        ];
    }

    if (!function_exists('curl_init')) {
        return notificar_cliente_whatsapp_cloud_api_http($phoneNumberId, $accessToken, $payload, ltrim($destino, '+'));
    }

    $ch = curl_init('https://graph.facebook.com/v20.0/' . rawurlencode($phoneNumberId) . '/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        notificar_cliente_whatsapp_set_last_error('WhatsApp Cloud HTTP ' . $httpCode . ' ' . ($error ?: (string)$response));
        return false;
    }

    $decoded = json_decode((string)$response, true);
    notificar_cliente_whatsapp_set_last_result([
        'provider' => 'whatsapp_cloud',
        'sid' => is_array($decoded) ? ($decoded['messages'][0]['id'] ?? null) : null,
        'status' => 'accepted',
        'from' => $phoneNumberId,
        'to' => ltrim($destino, '+'),
        'sandbox' => false,
    ]);

    return true;
}

function notificar_cliente_whatsapp_cloud_api_upload_media(string $phoneNumberId, string $accessToken, string $filePath): ?string
{
    if (!is_file($filePath) || !is_readable($filePath)) {
        notificar_cliente_whatsapp_set_last_error('Arquivo de imagem do QR Code nao encontrado para upload no WhatsApp.');
        return null;
    }

    if (!function_exists('curl_init')) {
        notificar_cliente_whatsapp_set_last_error('cURL indisponivel para upload da imagem no WhatsApp Cloud API.');
        return null;
    }

    $mime = function_exists('mime_content_type') ? (string)(mime_content_type($filePath) ?: 'image/png') : 'image/png';
    $name = basename($filePath);
    $curlFile = new CURLFile($filePath, $mime, $name);

    $ch = curl_init('https://graph.facebook.com/v20.0/' . rawurlencode($phoneNumberId) . '/media');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
        ],
        CURLOPT_POSTFIELDS => [
            'messaging_product' => 'whatsapp',
            'file' => $curlFile,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        notificar_cliente_whatsapp_set_last_error(
            'Falha no upload da imagem para o WhatsApp Cloud API. HTTP ' . $httpCode . ' ' . ($error ?: (string)$response)
        );
        return null;
    }

    $decoded = json_decode((string)$response, true);
    $mediaId = is_array($decoded) ? (string)($decoded['id'] ?? '') : '';
    if ($mediaId === '') {
        notificar_cliente_whatsapp_set_last_error('WhatsApp Cloud API nao retornou media_id para a imagem enviada.');
        return null;
    }

    return $mediaId;
}

function notificar_cliente_whatsapp_cloud_api_http(string $phoneNumberId, string $accessToken, array $payload, string $destino): bool
{
    if (!ini_get('allow_url_fopen')) {
        notificar_cliente_whatsapp_set_last_error('allow_url_fopen desativado e cURL indisponivel.');
        return false;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ignore_errors' => true,
            'timeout' => 25,
        ],
    ]);

    $url = 'https://graph.facebook.com/v20.0/' . rawurlencode($phoneNumberId) . '/messages';
    $response = @file_get_contents($url, false, $context);
    $status = 0;

    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $matches)) {
            $status = (int)$matches[1];
            break;
        }
    }

    if ($response === false || $status < 200 || $status >= 300) {
        notificar_cliente_whatsapp_set_last_error(notificar_cliente_whatsapp_cloud_error_message($status, (string)$response));
        return false;
    }

    $decoded = json_decode((string)$response, true);
    notificar_cliente_whatsapp_set_last_result([
        'provider' => 'whatsapp_cloud',
        'sid' => is_array($decoded) ? ($decoded['messages'][0]['id'] ?? null) : null,
        'status' => 'accepted',
        'from' => $phoneNumberId,
        'to' => $destino,
        'sandbox' => false,
    ]);

    return true;
}

function notificar_cliente_whatsapp_cloud_error_message(int $status, string $response): string
{
    $decoded = json_decode($response, true);
    $error = is_array($decoded) ? ($decoded['error'] ?? null) : null;
    $code = is_array($error) ? (int)($error['code'] ?? 0) : 0;

    if ($code === 131030) {
        return 'WhatsApp Cloud API esta em modo de teste. O numero do cliente nao esta na lista de destinatarios permitidos. Adicione esse numero em Meta Developers > WhatsApp > API Setup > Manage phone number list, ou configure um numero WhatsApp Business real/aprovado para producao.';
    }

    $message = is_array($error) ? (string)($error['message'] ?? '') : '';
    $details = is_array($error) && isset($error['error_data']['details'])
        ? (string)$error['error_data']['details']
        : '';

    return 'WhatsApp Cloud HTTP ' . ($status ?: 'sem_status')
        . ($message !== '' ? ': ' . $message : '')
        . ($details !== '' ? ' Detalhe: ' . $details : '');
}

function notificar_cliente_whatsapp_set_last_error(?string $message): void
{
    $message = $message !== null ? trim($message) : '';
    $GLOBALS['notificar_cliente_whatsapp_last_error'] = $message !== '' ? $message : null;

    if ($message !== '') {
        error_log('[WHATSAPP][ERROR] ' . $message);
    }
}

function notificar_cliente_whatsapp_last_error(): ?string
{
    $message = $GLOBALS['notificar_cliente_whatsapp_last_error'] ?? null;
    return is_string($message) && trim($message) !== '' ? trim($message) : null;
}

function notificar_cliente_whatsapp_set_last_result(?array $result): void
{
    $GLOBALS['notificar_cliente_whatsapp_last_result'] = $result;
}

function notificar_cliente_whatsapp_last_result(): ?array
{
    $result = $GLOBALS['notificar_cliente_whatsapp_last_result'] ?? null;
    return is_array($result) ? $result : null;
}

function notificar_cliente_whatsapp_is_twilio_sandbox(string $from): bool
{
    $from = strtolower(trim($from));
    return $from === 'whatsapp:+14155238886' || $from === '+14155238886';
}

function notificar_cliente_whatsapp_env(string $key): ?string
{
    if (function_exists('saas_email_env_value')) {
        return saas_email_env_value($key);
    }

    $value = getenv($key);
    if ($value === false && array_key_exists($key, $_ENV)) {
        $value = $_ENV[$key];
    }
    if ($value === false && array_key_exists($key, $_SERVER)) {
        $value = $_SERVER[$key];
    }

    if ($value === false) {
        return null;
    }

    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function notificar_cliente_whatsapp_normalizar_destino($telefone): ?string
{
    $telefone = trim((string)$telefone);
    if ($telefone === '') {
        return null;
    }

    $digitos = preg_replace('/\D+/', '', $telefone);
    if ($digitos === '' || strlen($digitos) < 8) {
        return null;
    }

    if (str_starts_with($digitos, '00')) {
        $digitos = substr($digitos, 2);
    }

    if (str_starts_with($telefone, '+')) {
        return '+' . $digitos;
    }

    if (strlen($digitos) === 9) {
        $digitos = '258' . $digitos;
    }

    return '+' . $digitos;
}

function notificar_cliente_whatsapp_link($telefone, $mensagem): ?string
{
    $destino = notificar_cliente_whatsapp_normalizar_destino($telefone);
    $mensagem = trim((string)$mensagem);

    if ($destino === null || $mensagem === '') {
        return null;
    }

    return 'https://wa.me/' . ltrim($destino, '+') . '?text=' . rawurlencode($mensagem);
}
