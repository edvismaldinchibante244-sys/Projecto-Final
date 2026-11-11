<?php

/*
 * Armazenamento dos desafios 2FA pendentes do login.
 * Extraido de src/public/api/login_process.php para poder ser testado
 * (tests/Integration/Login2faStoreTest.php).
 *
 * Requer: APP_BASE definido, security.php e login_security.php carregados.
 */

function login_api_2fa_storage_dir(): string
{
    $dir = APP_BASE . '/storage/2fa';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function login_api_2fa_storage_path(string $token): string
{
    $safeToken = preg_replace('/[^a-f0-9]/i', '', $token) ?: 'invalid';
    return login_api_2fa_storage_dir() . '/' . $safeToken . '.json';
}

/*
 * Armazenamento do desafio 2FA pendente.
 * Com a migracao 20261030_1300_estado_seguranca aplicada, fica na tabela
 * login_2fa_pendentes (token guardado so em hash; tentativas incrementadas de
 * forma atomica). Sem a migracao, mantem o armazenamento antigo em ficheiros.
 */
function login_api_2fa_db(): ?PDO
{
    return function_exists('security_state_db') ? security_state_db('20261030_1300_estado_seguranca') : null;
}

function login_api_2fa_token_hash(string $token): string
{
    return hash('sha256', 'login_2fa:' . $token);
}

function login_api_2fa_from_row(array $row, string $token): ?array
{
    $dados = json_decode((string)($row['dados'] ?? ''), true);
    if (!is_array($dados)) {
        return null;
    }
    $dados['token'] = $token;
    $dados['attempts'] = (int)($row['tentativas'] ?? 0);
    $dados['expires_at'] = (int)($row['expira_em'] ?? 0);
    return $dados;
}

function login_api_write_pending_2fa(array $pending): bool
{
    $token = (string)($pending['token'] ?? '');
    if ($token === '') {
        return false;
    }

    $db = login_api_2fa_db();
    if ($db instanceof PDO) {
        try {
            $dados = $pending;
            unset($dados['token'], $dados['attempts'], $dados['expires_at']);
            $stmt = $db->prepare('
                INSERT INTO login_2fa_pendentes (token_hash, usuario_id, dados, tentativas, expira_em)
                VALUES (:hash, :uid, :dados, :tentativas, :expira)
                ON DUPLICATE KEY UPDATE dados = VALUES(dados), expira_em = VALUES(expira_em)
            ');
            $stmt->execute([
                ':hash' => login_api_2fa_token_hash($token),
                ':uid' => (int)($pending['user']['id'] ?? 0),
                ':dados' => json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':tentativas' => (int)($pending['attempts'] ?? 0),
                ':expira' => (int)($pending['expires_at'] ?? 0),
            ]);
            if (random_int(1, 50) === 1) {
                $db->prepare('DELETE FROM login_2fa_pendentes WHERE expira_em < :agora LIMIT 500')
                    ->execute([':agora' => time() - 3600]);
            }
            return true;
        } catch (Throwable $e) {
            error_log('[login_2fa] escrita na base falhou: ' . $e->getMessage());
            return false;
        }
    }

    $bytes = @file_put_contents(
        login_api_2fa_storage_path($token),
        json_encode($pending, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
    return is_int($bytes) && $bytes > 0;
}

/**
 * Regista mais uma tentativa falhada de forma atomica e devolve o novo total.
 * (Antes: ler ficheiro, somar 1, regravar - pedidos em paralelo podiam
 * ultrapassar o limite de 5 tentativas.)
 */
function login_api_increment_pending_2fa_attempts(array $pending): int
{
    $token = (string)($pending['token'] ?? '');
    $db = login_api_2fa_db();
    if ($token !== '' && $db instanceof PDO) {
        try {
            $stmt = $db->prepare('
                UPDATE login_2fa_pendentes
                SET tentativas = LAST_INSERT_ID(tentativas + 1)
                WHERE token_hash = :hash
            ');
            $stmt->execute([':hash' => login_api_2fa_token_hash($token)]);
            if ($stmt->rowCount() > 0) {
                return (int)$db->query('SELECT LAST_INSERT_ID()')->fetchColumn();
            }
        } catch (Throwable $e) {
            error_log('[login_2fa] incremento falhou: ' . $e->getMessage());
        }
        return 99; // desafio inexistente: tratar como bloqueado
    }

    $pending['attempts'] = (int)($pending['attempts'] ?? 0) + 1;
    login_api_write_pending_2fa($pending);
    return (int)$pending['attempts'];
}

function login_api_read_pending_2fa(string $token): ?array
{
    if ($token === '') {
        return null;
    }

    $db = login_api_2fa_db();
    if ($db instanceof PDO) {
        try {
            $stmt = $db->prepare('SELECT dados, tentativas, expira_em FROM login_2fa_pendentes WHERE token_hash = :hash LIMIT 1');
            $stmt->execute([':hash' => login_api_2fa_token_hash($token)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? login_api_2fa_from_row($row, $token) : null;
        } catch (Throwable $e) {
            error_log('[login_2fa] leitura falhou: ' . $e->getMessage());
            return null;
        }
    }

    $path = login_api_2fa_storage_path($token);
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : null;
}

function login_api_find_pending_for_user_code(int $userId, string $code): ?array
{
    if ($userId <= 0 || $code === '') {
        return null;
    }

    $db = login_api_2fa_db();
    if ($db instanceof PDO) {
        try {
            $stmt = $db->prepare('
                SELECT token_hash, dados, tentativas, expira_em
                FROM login_2fa_pendentes
                WHERE usuario_id = :uid AND expira_em >= :agora AND tentativas < 5
                ORDER BY expira_em DESC
            ');
            $stmt->execute([':uid' => $userId, ':agora' => time()]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $dados = json_decode((string)$row['dados'], true);
                if (!is_array($dados) || !login_security_verify_otp($code, (string)($dados['code_hash'] ?? ''))) {
                    continue;
                }
                // O token original nao e guardado; o desafio encontrado e
                // identificado pelo hash e removido apos login bem-sucedido.
                $pending = login_api_2fa_from_row($row, '');
                $pending['token_hash'] = (string)$row['token_hash'];
                return $pending;
            }
            return null;
        } catch (Throwable $e) {
            error_log('[login_2fa] procura falhou: ' . $e->getMessage());
            return null;
        }
    }

    $dir = login_api_2fa_storage_dir();
    $files = glob($dir . '/*.json');
    if (!is_array($files)) {
        return null;
    }

    $match = null;
    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }
        $raw = @file_get_contents($file);
        $pending = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($pending)) {
            continue;
        }
        if ((int)($pending['expires_at'] ?? 0) < time()) {
            continue;
        }
        if ((int)($pending['attempts'] ?? 0) >= 5) {
            continue;
        }
        $pendingUser = is_array($pending['user'] ?? null) ? $pending['user'] : [];
        if ((int)($pendingUser['id'] ?? 0) !== $userId) {
            continue;
        }
        if (!login_security_verify_otp($code, (string)($pending['code_hash'] ?? ''))) {
            continue;
        }
        if ($match === null || (int)($pending['expires_at'] ?? 0) > (int)($match['expires_at'] ?? 0)) {
            $match = $pending;
        }
    }

    return $match;
}

function login_api_delete_all_pending_for_user(int $userId): void
{
    if ($userId <= 0) {
        return;
    }

    $db = login_api_2fa_db();
    if ($db instanceof PDO) {
        try {
            $db->prepare('DELETE FROM login_2fa_pendentes WHERE usuario_id = :uid')->execute([':uid' => $userId]);
        } catch (Throwable $e) {
            error_log('[login_2fa] remocao por utilizador falhou: ' . $e->getMessage());
        }
        return;
    }

    $dir = login_api_2fa_storage_dir();
    $files = glob($dir . '/*.json');
    if (!is_array($files)) {
        return;
    }

    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }
        $raw = @file_get_contents($file);
        $pending = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($pending)) {
            continue;
        }
        $pendingUser = is_array($pending['user'] ?? null) ? $pending['user'] : [];
        if ((int)($pendingUser['id'] ?? 0) !== $userId) {
            continue;
        }
        @unlink((string)$file);
    }
}

/** Remove um desafio pelo token (ou pelo array do desafio, que pode trazer token_hash). */
function login_api_delete_pending_2fa($tokenOrPending): void
{
    $token = is_array($tokenOrPending) ? (string)($tokenOrPending['token'] ?? '') : (string)$tokenOrPending;
    $hash = is_array($tokenOrPending) ? (string)($tokenOrPending['token_hash'] ?? '') : '';

    $db = login_api_2fa_db();
    if ($db instanceof PDO) {
        if ($hash === '' && $token !== '') {
            $hash = login_api_2fa_token_hash($token);
        }
        if ($hash !== '') {
            try {
                $db->prepare('DELETE FROM login_2fa_pendentes WHERE token_hash = :hash')->execute([':hash' => $hash]);
            } catch (Throwable $e) {
                error_log('[login_2fa] remocao falhou: ' . $e->getMessage());
            }
        }
        return;
    }

    if ($token !== '') {
        @unlink(login_api_2fa_storage_path($token));
    }
}

