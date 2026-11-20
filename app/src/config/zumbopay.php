<?php

/** Integração servidor-a-servidor com a API ZumboPay. */
if (!function_exists('zumbopay_env')) {
    function zumbopay_env(string $key): string
    {
        return trim((string)(getenv($key) ?: ''));
    }
}

if (!function_exists('zumbopay_is_configured')) {
    function zumbopay_is_configured(): bool
    {
        return zumbopay_env('ZUMBOPAY_API_KEY') !== ''
            && zumbopay_env('ZUMBOPAY_MERCHANT_ID') !== ''
            && zumbopay_env('ZUMBOPAY_WALLET_MPESA') !== '';
    }
}

if (!function_exists('zumbopay_create_plan_checkout')) {
    /** @return array{reference:string,checkout_url:string} */
    function zumbopay_create_plan_checkout(int $compraId, float $amount, string $title): array
    {
        if (!zumbopay_is_configured()) {
            throw new RuntimeException('ZumboPay nao configurado.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Extensao cURL indisponivel no servidor.');
        }

        $payload = json_encode([
            'title' => $title,
            'amount' => round($amount, 2),
            'currency' => 'MZN',
            'channels' => ['mpesa'],
            'wallet_id' => zumbopay_env('ZUMBOPAY_WALLET_MPESA'),
            'description' => 'Plano do restaurante #' . $compraId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            throw new RuntimeException('Nao foi possivel preparar a cobranca ZumboPay.');
        }

        $baseUrl = rtrim(zumbopay_env('ZUMBOPAY_API_URL') ?: 'https://zumbopay.com/api/public/v1', '/');
        $curl = curl_init($baseUrl . '/payments');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . zumbopay_env('ZUMBOPAY_API_KEY'),
                'X-Merchant-Id: ' . zumbopay_env('ZUMBOPAY_MERCHANT_ID'),
                'Content-Type: application/json',
                'Idempotency-Key: plano-' . $compraId,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
        ]);
        $raw = curl_exec($curl);
        $http = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        $body = is_string($raw) ? json_decode($raw, true) : null;
        $data = is_array($body) && is_array($body['data'] ?? null) ? $body['data'] : [];
        $reference = trim((string)($data['reference'] ?? ''));
        $checkoutUrl = trim((string)($data['checkout_url'] ?? ''));
        if ($http < 200 || $http >= 300 || $reference === '' || $checkoutUrl === '') {
            $message = is_array($body) ? (string)($body['error']['message'] ?? '') : '';
            throw new RuntimeException($message !== '' ? $message : ('ZumboPay recusou a cobranca (' . ($http ?: $error ?: 'erro') . ').'));
        }

        return ['reference' => $reference, 'checkout_url' => $checkoutUrl];
    }
}
