<?php

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/migration_state.php';

if (!defined('PASSWORD_RESET_SCHEMA_MIGRATION_ID')) {
    define('PASSWORD_RESET_SCHEMA_MIGRATION_ID', '20260821_1745_password_resets');
}

if (!function_exists('password_reset_normalize_token')) {
    function password_reset_normalize_token(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            return '';
        }

        // Remove quebras/espaços inseridos por clientes de email.
        $token = preg_replace('/\s+/', '', $token) ?? $token;

        // Alguns clientes codificam o token duas vezes.
        if (str_contains($token, '%')) {
            $decoded = rawurldecode($token);
            if (is_string($decoded) && $decoded !== '') {
                $token = preg_replace('/\s+/', '', $decoded) ?? $decoded;
            }
        }

        return $token;
    }
}

if (!function_exists('password_reset_token_hashes')) {
    function password_reset_token_hashes(string $token): array
    {
        $token = password_reset_normalize_token($token);
        if ($token === '') {
            return [];
        }

        return array_values(array_unique([
            security_hash_secret($token),
            hash('sha256', $token),
            $token,
        ]));
    }
}

if (!function_exists('password_reset_ensure_table')) {
    function password_reset_ensure_table(PDO $db): void
    {
        if (migration_esta_aplicada($db, PASSWORD_RESET_SCHEMA_MIGRATION_ID)) {
            return;
        }

        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'password_resets'
            LIMIT 1
        ");
        $stmt->execute();

        $tableExists = (bool)$stmt->fetchColumn();
        if (!$tableExists) {
            // DDL dentro de transação MySQL provoca commit implícito.
            // Se chegamos aqui no fluxo de reset, a tabela já deveria existir.
            if ($db->inTransaction()) {
                throw new RuntimeException('Tabela password_resets ausente durante uma transacao ativa.');
            }

            $db->exec("CREATE TABLE IF NOT EXISTS password_resets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email TEXT NOT NULL,
                email_lookup CHAR(64) NOT NULL,
                user_id INT NULL,
                token VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NOT NULL,
                used TINYINT(1) DEFAULT 0,
                INDEX idx_token (token),
                INDEX idx_email_lookup (email_lookup),
                INDEX idx_user_id (user_id)
            )");
            return;
        }

        $stmtCols = $db->query("SHOW COLUMNS FROM password_resets");
        $columns = [];
        while ($column = $stmtCols->fetch(PDO::FETCH_ASSOC)) {
            $columns[] = $column['Field'];
        }

        if (!in_array('user_id', $columns, true)) {
            if ($db->inTransaction()) {
                throw new RuntimeException('Coluna user_id ausente em password_resets durante uma transacao ativa.');
            }

            $db->exec("ALTER TABLE password_resets ADD COLUMN user_id INT NULL AFTER email, ADD INDEX idx_user_id (user_id)");
        }
        if (!in_array('email_lookup', $columns, true)) {
            if ($db->inTransaction()) {
                throw new RuntimeException('Coluna email_lookup ausente em password_resets durante uma transacao ativa. Execute a migracao de dados pessoais.');
            }
            $db->exec("ALTER TABLE password_resets ADD COLUMN email_lookup CHAR(64) NULL AFTER email, ADD INDEX idx_email_lookup (email_lookup)");
        }
    }
}

if (!function_exists('password_reset_create_token')) {
    function password_reset_resolver_expiracao_minutos(string $expiresSpec): int
    {
        $expiresSpec = strtolower(trim($expiresSpec));

        return match ($expiresSpec) {
            '+30 minutes', '+30 minute', '30m', '30min', '30' => 30,
            '+45 minutes', '+45 minute', '45m', '45min', '45' => 45,
            '+1 hour', '+1 hours', '1h', '60m', '60min', '60' => 60,
            '+2 hours', '+2 hour', '2h', '120m', '120min', '120' => 120,
            '+6 hours', '+6 hour', '6h' => 360,
            '+24 hours', '+24 hour', '1 day', '+1 day', '24h' => 1440,
            default => 120,
        };
    }

    function password_reset_create_token(PDO $db, string $email, string $expiresSpec = '+2 hours', ?int $userId = null): array
    {
        $email = trim($email);
        if ($email === '') {
            throw new InvalidArgumentException('Email obrigatorio para criar token de senha.');
        }

        password_reset_ensure_table($db);

        // Nao invalidar tokens anteriores aqui: um novo pedido (ou email atrasado)
        // nao deve matar o link ainda valido que o utilizador ja recebeu.
        // Todos os tokens do email sao invalidados apos uso com sucesso.

        $token = bin2hex(random_bytes(32));
        $tokenHash = security_hash_secret($token);
        $expiresMinutos = max(1, password_reset_resolver_expiracao_minutos($expiresSpec));

        // INTERVAL com placeholder falha em alguns drivers PDO nativos; minutos ja sao int.
        $expiresAt = (string)($db->query(
            'SELECT DATE_ADD(NOW(), INTERVAL ' . $expiresMinutos . ' MINUTE) AS expires_at'
        )->fetchColumn() ?: '');
        if ($expiresAt === '') {
            throw new RuntimeException('Nao foi possivel calcular a expiracao do token de redefinicao.');
        }

        $stmt = $db->prepare("INSERT INTO password_resets (email, email_lookup, user_id, token, expires_at) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([personal_data_encrypt($email), personal_data_email_lookup($email), $userId, $tokenHash, $expiresAt]);

        return [
            'token' => $token,
            'expires_at' => $expiresAt,
        ];
    }
}

if (!function_exists('password_reset_build_link')) {
    function password_reset_build_link(string $token): string
    {
        $token = password_reset_normalize_token($token);
        $env = trim((string)(
            getenv('APP_URL')
            ?: ($_ENV['APP_URL'] ?? '')
            ?: getenv('PUBLIC_BASE_URL')
            ?: ($_ENV['PUBLIC_BASE_URL'] ?? '')
        ));
        if ($env !== '') {
            $base = rtrim($env, '/');
            if (preg_match('#^https://(localhost|127\.0\.0\.1)(:\d+)?(/|$)#i', $base) === 1) {
                $base = (string)preg_replace('#^https://#i', 'http://', $base);
            }
            return $base . '/nova_senha.php?token=' . rawurlencode($token);
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
        $protocol = $https ? 'https://' : 'http://';
        $host = trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost'));
        if (str_contains($host, ',')) {
            $host = trim(explode(',', $host)[0]);
        }
        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

        if (substr($basePath, -4) === '/api') {
            $basePath = substr($basePath, 0, -4);
        }

        if ($basePath === '' || $basePath === '.') {
            $basePath = '';
        }

        return $protocol . $host . $basePath . '/nova_senha.php?token=' . rawurlencode($token);
    }
}

if (!function_exists('password_reset_compose_email')) {
    /**
     * Email de redefinição / activação de senha — marca Nivora Restaurant.
     *
     * @param array{nome?:string,restaurante?:string,link:string,expires_hours?:int,modo?:string,email_conta?:string} $opts
     * @return array{subject:string,html:string}
     */
    function password_reset_compose_email(array $opts): array
    {
        $nome = trim((string)($opts['nome'] ?? ''));
        if ($nome === '' || strcasecmp($nome, 'Administrador') === 0 || strcasecmp($nome, 'Admin') === 0) {
            $saudacao = 'Olá';
        } else {
            $partes = preg_split('/\s+/', $nome) ?: [];
            $primeiro = (string)($partes[0] ?? $nome);
            $saudacao = 'Olá, ' . $primeiro;
        }

        $restaurante = trim((string)($opts['restaurante'] ?? ''));
        $link = trim((string)($opts['link'] ?? ''));
        $emailConta = trim((string)($opts['email_conta'] ?? ''));
        $expiresHours = max(1, (int)($opts['expires_hours'] ?? 2));
        $modo = strtolower(trim((string)($opts['modo'] ?? 'recuperacao')));
        $isAtivacao = ($modo === 'ativacao' || $modo === 'setup');

        $nomeEsc = htmlspecialchars($nome !== '' ? $nome : 'utilizador', ENT_QUOTES, 'UTF-8');
        $saudacaoEsc = htmlspecialchars($saudacao, ENT_QUOTES, 'UTF-8');
        $restEsc = htmlspecialchars($restaurante, ENT_QUOTES, 'UTF-8');
        $linkEsc = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
        $emailEsc = htmlspecialchars($emailConta, ENT_QUOTES, 'UTF-8');

        if ($isAtivacao) {
            $subject = $restaurante !== ''
                ? ('Nivora Restaurant — acesso aprovado para ' . $restaurante)
                : 'Nivora Restaurant — defina a sua senha de acesso';
            $titulo = 'Acesso aprovado';
            $subtitulo = $restaurante !== ''
                ? ('A conta do restaurante <strong style="color:#fff7ed;">' . $restEsc . '</strong> já está activa na Nivora Restaurant.')
                : 'A sua conta Nivora Restaurant já está pronta. Falta só criar a senha de acesso.';
            $cta = 'Criar a minha senha';
            $motivo = 'Para entrar no sistema, crie uma senha segura através do botão abaixo.';
        } else {
            $subject = $restaurante !== ''
                ? ('Nivora Restaurant — redefinir senha · ' . $restaurante)
                : 'Nivora Restaurant — redefinir a sua senha';
            $titulo = 'Redefinir senha';
            $subtitulo = $restaurante !== ''
                ? ('Pedido de nova senha para a conta associada a <strong style="color:#fff7ed;">' . $restEsc . '</strong>.')
                : 'Recebemos um pedido para criar uma nova senha na sua conta Nivora Restaurant.';
            $cta = 'Criar nova senha';
            $motivo = 'Este pedido só é válido se foi feito por si. Se não reconhece, ignore este email — a senha actual continua activa.';
        }

        $contaLinha = $emailConta !== ''
            ? '<p style="margin:0 0 14px;font-size:14px;color:#d6d3d1;line-height:1.5;">Conta: <strong style="color:#fff7ed;">' . $emailEsc . '</strong></p>'
            : '';

        $contextoRest = ($restaurante !== '' && !$isAtivacao)
            ? '<p style="margin:0 0 18px;font-size:13px;color:#a8a29e;">Unidade: ' . $restEsc . '</p>'
            : '';

        $html = '<!DOCTYPE html>
<html lang="pt">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . '</title></head>
<body style="margin:0;padding:0;background:#1c1917;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#1c1917;padding:28px 12px;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#140f0c;border:1px solid rgba(217,119,58,0.35);border-radius:18px;overflow:hidden;">
        <tr>
          <td style="padding:22px 28px 12px;background:linear-gradient(135deg,#292524,#431407);">
            <p style="margin:0;font-size:12px;letter-spacing:0.14em;text-transform:uppercase;color:#fdba74;font-weight:700;">NIVORA RESTAURANT</p>
            <h1 style="margin:8px 0 0;font-size:24px;line-height:1.25;color:#fff7ed;font-weight:800;">' . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . '</h1>
          </td>
        </tr>
        <tr>
          <td style="padding:24px 28px 8px;">
            <p style="margin:0 0 12px;font-size:16px;color:#fff7ed;font-weight:700;">' . $saudacaoEsc . ',</p>
            <p style="margin:0 0 14px;font-size:15px;color:#e7e5e4;line-height:1.55;">' . $subtitulo . '</p>
            ' . $contaLinha . '
            ' . $contextoRest . '
            <p style="margin:0 0 22px;font-size:14px;color:#d6d3d1;line-height:1.55;">' . htmlspecialchars($motivo, ENT_QUOTES, 'UTF-8') . '</p>
            <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 auto 22px;">
              <tr>
                <td style="border-radius:10px;background:#d9773a;">
                  <a href="' . $linkEsc . '" style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:700;color:#140f0c;text-decoration:none;">' . htmlspecialchars($cta, ENT_QUOTES, 'UTF-8') . '</a>
                </td>
              </tr>
            </table>
            <p style="margin:0 0 8px;font-size:12px;color:#a8a29e;text-transform:uppercase;letter-spacing:0.06em;font-weight:700;">Link alternativo</p>
            <p style="margin:0 0 18px;font-size:12px;line-height:1.5;word-break:break-all;color:#fdba74;">
              <a href="' . $linkEsc . '" style="color:#fdba74;text-decoration:underline;">' . $linkEsc . '</a>
            </p>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 8px;background:#1c1917;border:1px solid rgba(251,146,60,0.25);border-radius:12px;">
              <tr>
                <td style="padding:14px 16px;">
                  <p style="margin:0 0 6px;font-size:13px;color:#fdba74;font-weight:700;">Validade</p>
                  <p style="margin:0;font-size:13px;color:#e7e5e4;line-height:1.45;">Este link expira em <strong>' . (int)$expiresHours . ' hora' . ($expiresHours === 1 ? '' : 's') . '</strong>. Depois disso, peça um novo na página de login.</p>
                </td>
              </tr>
            </table>
            <p style="margin:16px 0 0;font-size:12px;color:#a8a29e;line-height:1.5;">Por segurança, nunca partilhe este email. A Nivora Restaurant nunca pede a sua senha por mensagem.</p>
          </td>
        </tr>
        <tr>
          <td style="padding:18px 28px 24px;border-top:1px solid rgba(255,255,255,0.06);">
            <p style="margin:0;font-size:11px;color:#78716c;line-height:1.45;">Email automático da Nivora Restaurant · não responda a esta mensagem.<br>Destinatário: ' . ($emailEsc !== '' ? $emailEsc : $nomeEsc) . '</p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>';

        return [
            'subject' => $subject,
            'html' => $html,
        ];
    }
}

if (!function_exists('password_reset_find_token_row')) {
    function password_reset_find_token_row(PDO $db, string $token, bool $forUpdate = false): ?array
    {
        $token = password_reset_normalize_token($token);
        if ($token === '') {
            return null;
        }

        password_reset_ensure_table($db);

        $tokenHashes = password_reset_token_hashes($token);
        if ($tokenHashes === []) {
            return null;
        }

        $sql = "
            SELECT *
            FROM password_resets
            WHERE token IN (" . implode(',', array_fill(0, count($tokenHashes), '?')) . ")
            ORDER BY id DESC
            LIMIT 1
        ";
        if ($forUpdate) {
            $sql .= " FOR UPDATE";
        }

        $stmt = $db->prepare($sql);
        $stmt->execute(array_values($tokenHashes));
        $reset = $stmt->fetch(PDO::FETCH_ASSOC);
        return $reset ?: null;
    }
}

if (!function_exists('password_reset_token_status')) {
    /**
     * @return 'valid'|'expired'|'used'|'missing'
     */
    function password_reset_token_status(PDO $db, string $token): string
    {
        $row = password_reset_find_token_row($db, $token);
        if (!$row) {
            return 'missing';
        }
        if ((int)($row['used'] ?? 0) === 1) {
            return 'used';
        }

        // Validacao usando apenas o relogio do MySQL (NOW()) para evitar
        // dessincronizacao de timezone entre PHP e o servidor de banco de dados.
        $stmt = $db->prepare("SELECT 1 FROM password_resets WHERE id = ? AND used = 0 AND expires_at > NOW() LIMIT 1");
        $stmt->execute([(int)$row['id']]);
        return $stmt->fetchColumn() ? 'valid' : 'expired';
    }
}

if (!function_exists('password_reset_find_valid_token')) {
    function password_reset_find_valid_token(PDO $db, string $token, bool $forUpdate = false): ?array
    {
        $row = password_reset_find_token_row($db, $token, $forUpdate);
        if (!$row) {
            return null;
        }
        if ((int)($row['used'] ?? 0) === 1) {
            return null;
        }

        // Validacao usando apenas o relogio do MySQL (NOW()) para evitar
        // dessincronizacao de timezone entre PHP e o servidor de banco de dados.
        $stmt = $db->prepare("SELECT 1 FROM password_resets WHERE id = ? AND used = 0 AND expires_at > NOW() LIMIT 1");
        $stmt->execute([(int)$row['id']]);
        if (!$stmt->fetchColumn()) {
            return null;
        }

        return $row;
    }
}

if (!function_exists('password_reset_mark_used')) {
    function password_reset_mark_used(PDO $db, int $resetId): bool
    {
        if ($resetId <= 0) {
            return false;
        }

        password_reset_ensure_table($db);
        $stmt = $db->prepare("UPDATE password_resets SET used = 1 WHERE id = ?");
        return $stmt->execute([$resetId]);
    }
}

if (!function_exists('password_reset_invalidate_email_tokens')) {
    function password_reset_invalidate_email_tokens(PDO $db, string $email): bool
    {
        $email = trim($email);
        if ($email === '') {
            return false;
        }

        password_reset_ensure_table($db);
        $stmt = $db->prepare("UPDATE password_resets SET used = 1 WHERE email_lookup = ? AND used = 0");
        return $stmt->execute([personal_data_email_lookup($email)]);
    }
}
