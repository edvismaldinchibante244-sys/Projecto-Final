<?php
declare(strict_types=1);

/*
 * Estado de seguranca na base de dados, em vez de ficheiros temporarios:
 *  - security_rate_limits: contadores do rate limit (security_rate_limit)
 *  - login_2fa_pendentes: desafios 2FA pendentes (api/login_process.php)
 * Funciona com varios servidores e nao se perde quando o sistema limpa /tmp.
 * Os codigos OTP sao guardados so em hash; o token do desafio tambem.
 * Idempotente.
 */
return [
    'id' => '20261030_1300_estado_seguranca',
    'description' => 'Rate limit e desafios 2FA pendentes guardados na base de dados.',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS security_rate_limits (
            chave CHAR(64) NOT NULL,
            janela_inicio INT UNSIGNED NOT NULL,
            contador INT UNSIGNED NOT NULL,
            expira_em INT UNSIGNED NOT NULL,
            PRIMARY KEY (chave),
            KEY idx_rate_limits_expira (expira_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS login_2fa_pendentes (
            token_hash CHAR(64) NOT NULL,
            usuario_id INT NOT NULL,
            dados MEDIUMTEXT NOT NULL,
            tentativas TINYINT UNSIGNED NOT NULL DEFAULT 0,
            expira_em INT UNSIGNED NOT NULL,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (token_hash),
            KEY idx_2fa_usuario (usuario_id, expira_em),
            KEY idx_2fa_expira (expira_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    },
];
