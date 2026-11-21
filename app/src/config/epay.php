<?php

/** Integração servidor-a-servidor com a API de pagamentos ePay. */
if (!function_exists('epay_env')) {
    function epay_env(string $key): string
    {
        return trim((string)(getenv($key) ?: ($_ENV[$key] ?? '')));
    }
}

if (!function_exists('epay_is_configured')) {
    function epay_is_configured(): bool
    {
        return epay_env('EPAY_SECRET_KEY') !== '';
    }
}

if (!function_exists('epay_create_plan_payment')) {
    /** @return array{id:string,payment_url:string} */
    function epay_create_plan_payment(
        int $compraId,
        float $amount,
        string $description,
        string $customerName,
        string $customerEmail
    ): array {
        $apiKey = epay_env('EPAY_SECRET_KEY');
        if ($apiKey === '') {
            throw new RuntimeException('EPAY_SECRET_KEY nao configurada.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Extensao cURL indisponivel no servidor.');
        }
        $appUrl = trim((string)(getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? '')));
        $appUrl = rtrim($appUrl, '/');
        $appScheme = strtolower((string)parse_url($appUrl, PHP_URL_SCHEME));
        $appHost = parse_url($appUrl, PHP_URL_HOST);
        if (filter_var($appUrl, FILTER_VALIDATE_URL) === false || $appScheme !== 'https' || !is_string($appHost)) {
            throw new RuntimeException('URL publica da aplicacao invalida.');
        }

        $baseUrl = 'https://checkout.epay.co.mz/api/v1';
        $payload = json_encode([
            'amount' => round($amount, 2),
            'currency' => 'MZN',
            'description' => $description,
            'external_reference' => (string)$compraId,
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
            'return_url' => $appUrl . '/solicitar_plano.php?pagamento=retorno',
            'cancel_url' => $appUrl . '/solicitar_plano.php?pagamento=cancelado',
            'callback_url' => $appUrl . '/api/gateway_webhook_plano.php',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            throw new RuntimeException('Nao foi possivel preparar o pagamento ePay.');
        }

        $curl = curl_init($baseUrl . '/payments');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
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
        $paymentId = is_array($body) ? trim((string)($body['id'] ?? '')) : '';
        $paymentUrl = is_array($body) ? trim((string)($body['payment_url'] ?? '')) : '';
        if ($http < 200 || $http >= 300 || $paymentId === '' || filter_var($paymentUrl, FILTER_VALIDATE_URL) === false) {
            $message = is_array($body) ? trim((string)($body['message'] ?? '')) : '';
            throw new RuntimeException($message !== '' ? $message : ('ePay recusou o pagamento (' . ($http ?: $error ?: 'erro') . ').'));
        }

        $paymentHost = parse_url($paymentUrl, PHP_URL_HOST);
        $paymentScheme = strtolower((string)parse_url($paymentUrl, PHP_URL_SCHEME));
        if ($paymentScheme !== 'https' || !is_string($paymentHost) || strtolower($paymentHost) !== 'checkout.epay.co.mz') {
            throw new RuntimeException('ePay devolveu um endereco de pagamento invalido.');
        }

        return ['id' => $paymentId, 'payment_url' => $paymentUrl];
    }
}

if (!function_exists('epay_verify_payment_callback')) {
    function epay_verify_payment_callback(string $rawPayload, string $signature): bool
    {
        $secret = epay_env('EPAY_SECRET_KEY');
        return $secret !== ''
            && preg_match('/^[a-f0-9]{64}$/i', $signature) === 1
            && hash_equals(hash_hmac('sha256', $rawPayload, $secret), strtolower($signature));
    }
}
