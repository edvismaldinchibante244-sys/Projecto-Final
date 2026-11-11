<?php

/**
 * Política de senhas fortes (anti password fraca / credential stuffing).
 *
 * Uso:
 *   require_once .../password_policy.php;
 *   $check = password_policy_validate($senha, ['email' => $email, 'nome' => $nome, 'perfil' => 'ADMIN']);
 *   if (!$check['ok']) { ... $check['message'] ... }
 */

if (!function_exists('password_policy_min_length')) {
    function password_policy_min_length(?string $perfil = null, bool $privileged = false): int
    {
        $base = max(8, (int)(getenv('PASSWORD_MIN_LENGTH') ?: 10));
        $admin = max($base, (int)(getenv('PASSWORD_ADMIN_MIN_LENGTH') ?: 12));
        $p = strtoupper(trim((string)$perfil));
        if (
            $privileged
            || $p === 'ADMIN'
            || $p === 'GERENTE'
            || $p === 'SUPER_ADMIN'
            || $p === 'SUPERADMIN'
        ) {
            return $admin;
        }
        return $base;
    }
}

if (!function_exists('password_policy_requirements_text')) {
    function password_policy_requirements_text(?string $perfil = null, bool $privileged = false): string
    {
        $min = password_policy_min_length($perfil, $privileged);
        return "Mínimo {$min} caracteres, com maiúscula, minúscula, número e símbolo (!@#\$%...).";
    }
}

if (!function_exists('password_policy_common_list')) {
    /**
     * @return list<string>
     */
    function password_policy_common_list(): array
    {
        return [
            'password', 'password1', 'password123', '123456', '12345678', '123456789',
            'qwerty', 'abc123', 'admin', 'admin123', 'usuario', 'usuario123',
            'senha', 'senha123', 'restaurante', 'restaurante123', 'welcome', 'letmein',
            'iloveyou', 'monkey', 'dragon', 'master', 'login', 'passw0rd',
            'mozambique', 'maputo', 'moçambique',
        ];
    }
}

if (!function_exists('password_policy_validate')) {
    /**
     * @param array{
     *   email?:string,
     *   nome?:string,
     *   perfil?:string,
     *   privileged?:bool,
     *   username?:string
     * } $ctx
     * @return array{ok:bool,message:string,min_length:int}
     */
    function password_policy_validate(string $password, array $ctx = []): array
    {
        $perfil = isset($ctx['perfil']) ? (string)$ctx['perfil'] : null;
        $privileged = !empty($ctx['privileged']);
        $min = password_policy_min_length($perfil, $privileged);
        $max = 128;
        $len = strlen($password);

        if ($len < $min) {
            return [
                'ok' => false,
                'message' => "A senha deve ter pelo menos {$min} caracteres.",
                'min_length' => $min,
            ];
        }
        if ($len > $max) {
            return [
                'ok' => false,
                'message' => "A senha não pode ter mais de {$max} caracteres.",
                'min_length' => $min,
            ];
        }
        if (!preg_match('/[a-z]/u', $password)) {
            return [
                'ok' => false,
                'message' => 'A senha deve incluir pelo menos uma letra minúscula.',
                'min_length' => $min,
            ];
        }
        if (!preg_match('/[A-Z]/u', $password)) {
            return [
                'ok' => false,
                'message' => 'A senha deve incluir pelo menos uma letra maiúscula.',
                'min_length' => $min,
            ];
        }
        if (!preg_match('/[0-9]/u', $password)) {
            return [
                'ok' => false,
                'message' => 'A senha deve incluir pelo menos um número.',
                'min_length' => $min,
            ];
        }
        if (!preg_match('/[^a-zA-Z0-9]/u', $password)) {
            return [
                'ok' => false,
                'message' => 'A senha deve incluir pelo menos um símbolo (ex.: !@#$%).',
                'min_length' => $min,
            ];
        }

        $lower = function_exists('mb_strtolower')
            ? mb_strtolower($password, 'UTF-8')
            : strtolower($password);
        if (in_array($lower, password_policy_common_list(), true) || preg_match('/(\d)\1{5,}/', $password)) {
            return [
                'ok' => false,
                'message' => 'Essa senha é demasiado comum. Escolha outra mais forte.',
                'min_length' => $min,
            ];
        }
        if (preg_match('/^(012345|123456|234567|345678|456789|567890|678901|789012|890123|901234)+$/i', $password)) {
            return [
                'ok' => false,
                'message' => 'Essa senha é demasiado comum. Escolha outra mais forte.',
                'min_length' => $min,
            ];
        }

        $needles = [];
        $email = trim((string)($ctx['email'] ?? ''));
        if ($email !== '' && str_contains($email, '@')) {
            $local = strtolower((string)strstr($email, '@', true));
            if (strlen($local) >= 3) {
                $needles[] = $local;
            }
        }
        $nome = trim((string)($ctx['nome'] ?? $ctx['username'] ?? ''));
        if ($nome !== '') {
            $nomeLower = function_exists('mb_strtolower')
                ? mb_strtolower($nome, 'UTF-8')
                : strtolower($nome);
            $parts = preg_split('/[\s\-_.@]+/', $nomeLower) ?: [];
            foreach ($parts as $part) {
                if (strlen($part) >= 3) {
                    $needles[] = $part;
                }
            }
        }
        foreach (array_unique($needles) as $n) {
            if ($n !== '' && str_contains($lower, $n)) {
                return [
                    'ok' => false,
                    'message' => 'A senha não deve conter o seu nome ou email.',
                    'min_length' => $min,
                ];
            }
        }

        return [
            'ok' => true,
            'message' => 'OK',
            'min_length' => $min,
        ];
    }
}
