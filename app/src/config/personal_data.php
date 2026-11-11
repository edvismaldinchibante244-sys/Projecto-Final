<?php

require_once __DIR__ . '/crypto.php';

if (!function_exists('personal_data_is_encrypted')) {
    function personal_data_is_encrypted(?string $value): bool
    {
        return is_string($value)
            && (str_starts_with($value, 'v1sodium:') || str_starts_with($value, 'v1gcm:'));
    }
}

if (!function_exists('personal_data_encrypt')) {
    function personal_data_encrypt(?string $value): ?string
    {
        if ($value === null || $value === '' || personal_data_is_encrypted($value)) {
            return $value;
        }

        return security_encrypt_value($value);
    }
}

if (!function_exists('personal_data_decrypt')) {
    function personal_data_decrypt(?string $value): ?string
    {
        if ($value === null || $value === '' || !personal_data_is_encrypted($value)) {
            return $value;
        }

        $plain = security_decrypt_value($value);
        if ($plain === null) {
            throw new RuntimeException('Nao foi possivel descriptografar um dado pessoal. Verifique APP_KEY.');
        }

        return $plain;
    }
}

if (!function_exists('personal_data_normalize_email')) {
    function personal_data_normalize_email(string $email): string
    {
        $email = trim($email);
        return function_exists('mb_strtolower')
            ? mb_strtolower($email, 'UTF-8')
            : strtolower($email);
    }
}

if (!function_exists('personal_data_email_lookup')) {
    function personal_data_email_lookup(string $email): string
    {
        return security_hash_secret('email:' . personal_data_normalize_email($email));
    }
}

if (!function_exists('personal_data_normalize_nuit')) {
    function personal_data_normalize_nuit(string $nuit): string
    {
        return strtoupper((string)(preg_replace('/[^A-Za-z0-9]/', '', trim($nuit)) ?? ''));
    }
}

if (!function_exists('personal_data_nuit_lookup')) {
    function personal_data_nuit_lookup(string $nuit): ?string
    {
        $normalized = personal_data_normalize_nuit($nuit);
        return $normalized === '' ? null : security_hash_secret('nuit:' . $normalized);
    }
}

if (!function_exists('personal_data_normalize_phone')) {
    function personal_data_normalize_phone(string $phone): string
    {
        return (string)(preg_replace('/\D+/', '', trim($phone)) ?? '');
    }
}

if (!function_exists('personal_data_phone_lookup')) {
    function personal_data_phone_lookup(string $phone): ?string
    {
        $normalized = personal_data_normalize_phone($phone);
        return $normalized === '' ? null : security_hash_secret('phone:' . $normalized);
    }
}

if (!function_exists('personal_data_sensitive_field')) {
    function personal_data_sensitive_field(?string $field): bool
    {
        if ($field === null) {
            return false;
        }

        return in_array(strtolower($field), [
            'email',
            'two_factor_email',
            'admin_email',
            'contato_email',
            'restaurante_email',
            'usuario_email',
            'telefone',
            'contato_telefone',
            'restaurante_telefone',
            'endereco',
            'nuit',
        ], true);
    }
}

if (!function_exists('personal_data_decrypt_result')) {
    function personal_data_decrypt_result($value, ?string $field = null)
    {
        if (is_string($value)) {
            if (!personal_data_is_encrypted($value)) {
                return $value;
            }

            $plain = security_decrypt_value($value);
            if ($plain === null && personal_data_sensitive_field($field)) {
                throw new RuntimeException('Nao foi possivel descriptografar um dado pessoal. Verifique APP_KEY.');
            }
            return $plain ?? $value;
        }
        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = personal_data_decrypt_result($item, is_string($key) ? $key : null);
        }
        return $value;
    }
}

if (!class_exists('PersonalDataPDOStatement', false)) {
    final class PersonalDataPDOStatement extends PDOStatement
    {
        protected function __construct()
        {
        }

        public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
        {
            return personal_data_decrypt_result(parent::fetch($mode, $cursorOrientation, $cursorOffset));
        }

        public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
        {
            return personal_data_decrypt_result(parent::fetchAll($mode, ...$args));
        }

        public function fetchColumn(int $column = 0): mixed
        {
            return personal_data_decrypt_result(parent::fetchColumn($column));
        }
    }
}
