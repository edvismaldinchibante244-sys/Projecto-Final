<?php

require_once __DIR__ . '/security.php';

if (!function_exists('security_crypto_raw_key')) {
    function security_crypto_raw_key(): string
    {
        $key = (string)(
            getenv('APP_KEY')
            ?: ($_ENV['APP_KEY'] ?? '')
            ?: getenv('SECURITY_KEY')
            ?: ($_ENV['SECURITY_KEY'] ?? '')
            ?: ''
        );
        if ($key === '' || $key === 'gere_uma_chave_aleatoria_com_64_caracteres') {
            if (security_is_production()) {
                throw new RuntimeException('APP_KEY forte obrigatoria em producao.');
            }
            $key = 'local-development-key-change-me';
        }

        return hash('sha256', $key, true);
    }
}

if (!function_exists('security_encrypt_value')) {
    function security_encrypt_value(?string $plainText): ?string
    {
        if ($plainText === null || $plainText === '') {
            return $plainText;
        }

        $key = security_crypto_raw_key();

        if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plainText, '', $nonce, $key);
            return 'v1sodium:' . base64_encode($nonce . $cipher);
        }

        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plainText, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) {
            throw new RuntimeException('Falha ao criptografar valor.');
        }

        return 'v1gcm:' . base64_encode($nonce . $tag . $cipher);
    }
}

if (!function_exists('security_decrypt_value')) {
    function security_decrypt_value(?string $cipherText): ?string
    {
        if ($cipherText === null || $cipherText === '') {
            return $cipherText;
        }

        $key = security_crypto_raw_key();

        if (str_starts_with($cipherText, 'v1sodium:')) {
            if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
                throw new RuntimeException('Libsodium indisponivel para descriptografar valor.');
            }
            $raw = base64_decode(substr($cipherText, 9), true);
            if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
                return null;
            }
            $nonceSize = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
            $nonce = substr($raw, 0, $nonceSize);
            $cipher = substr($raw, $nonceSize);
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher, '', $nonce, $key);
            return $plain === false ? null : $plain;
        }

        if (str_starts_with($cipherText, 'v1gcm:')) {
            $raw = base64_decode(substr($cipherText, 6), true);
            if ($raw === false || strlen($raw) <= 28) {
                return null;
            }
            $nonce = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $cipher = substr($raw, 28);
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
            return $plain === false ? null : $plain;
        }

        return null;
    }
}

if (!function_exists('security_hash_secret')) {
    function security_hash_secret(string $value): string
    {
        return hash_hmac('sha256', $value, security_crypto_raw_key());
    }
}

