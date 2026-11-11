<?php
declare(strict_types=1);

/*
 * Autorizacao a distancia: o garcom/caixa pede, o gerente ou administrador aprova no seu proprio
 * telemovel ou computador. A aprovacao gera um codigo de uso unico (so o sha256 fica guardado),
 * ligado a ESTA acao, a ESTE alvo e a QUEM pediu; usa-se uma vez e expira.
 * Idempotente.
 */
return [
    'id' => '20261108_1700_autorizacoes_remotas',
    'description' => 'Autorizacao do gerente a distancia (pedido e aprovacao noutro dispositivo).',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS autorizacoes_remotas (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            restaurante_id INT NOT NULL,
            acao VARCHAR(40) NOT NULL,
            alvo BIGINT NOT NULL,
            descricao VARCHAR(255) NULL,
            codigo_hash CHAR(64) NOT NULL,
            solicitado_por INT NOT NULL,
            estado VARCHAR(12) NOT NULL DEFAULT 'PENDENTE',
            decidido_por INT NULL,
            decidido_em DATETIME NULL,
            usado_em DATETIME NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expira_em DATETIME NOT NULL,
            UNIQUE KEY uq_autorizacoes_remotas_codigo (codigo_hash),
            KEY idx_autorizacoes_remotas_pendentes (restaurante_id, estado, expira_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    },
];
