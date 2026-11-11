<?php
declare(strict_types=1);

/*
 * "Sessao em pausa": quando a sessao expira, o navegador onde a pessoa entrou (com email,
 * palavra-passe e 2FA) pode continuar so com a palavra-passe durante 12 horas.
 * Guarda-se apenas o sha256 de um codigo aleatorio (o codigo fica num cookie HttpOnly).
 * Idempotente.
 */
return [
    'id' => '20261108_1600_sessao_retomar',
    'description' => 'Sessao em pausa: retomar com a palavra-passe no mesmo navegador (12 h).',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS sessoes_retomar (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            token_hash CHAR(64) NOT NULL,
            usuario_id INT NOT NULL,
            restaurante_id INT NOT NULL,
            manter TINYINT(1) NOT NULL DEFAULT 0,
            falhas TINYINT UNSIGNED NOT NULL DEFAULT 0,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expira_em DATETIME NOT NULL,
            ultimo_uso_em DATETIME NULL,
            UNIQUE KEY uq_sessoes_retomar_token (token_hash),
            KEY idx_sessoes_retomar_usuario (usuario_id),
            KEY idx_sessoes_retomar_expira (expira_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    },
];
