<?php

if (!function_exists('input_scalar_string')) {
    function input_scalar_string($value): ?string
    {
        return is_string($value) || is_int($value) ? trim((string)$value) : null;
    }
}

if (!function_exists('input_enum')) {
    function input_enum($value, array $allowed, bool $allowEmpty = false): ?string
    {
        $value = input_scalar_string($value);
        if ($value === null || (!$allowEmpty && $value === '')) {
            return null;
        }
        return in_array($value, $allowed, true) ? $value : null;
    }
}

if (!function_exists('input_positive_int')) {
    function input_positive_int($value, int $max = PHP_INT_MAX): ?int
    {
        $value = input_scalar_string($value);
        if ($value === null || !preg_match('/^[1-9]\d*$/D', $value)) {
            return null;
        }
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]);
        return $number === false ? null : $number;
    }
}

if (!function_exists('input_bounded_int')) {
    function input_bounded_int($value, int $min, int $max): ?int
    {
        $value = input_scalar_string($value);
        if ($value === null || !preg_match('/^-?\d+$/D', $value)) {
            return null;
        }
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        return $number === false ? null : $number;
    }
}

if (!function_exists('input_iso_date')) {
    function input_iso_date($value): ?string
    {
        $value = input_scalar_string($value);
        if ($value === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value ? $value : null;
    }
}

if (!function_exists('input_search_token')) {
    function input_search_token($value, int $maxLength = 80): ?string
    {
        $value = input_scalar_string($value);
        if ($value === null || strlen($value) > $maxLength || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return null;
        }
        return $value;
    }
}
