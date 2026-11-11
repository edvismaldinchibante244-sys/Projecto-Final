<?php
declare(strict_types=1);

/*
 * Fila de trabalhos em segundo plano (exportacao de dados e outros trabalhos pesados).
 * O pedido do utilizador so regista o trabalho; o trabalho corre a seguir (no proprio servidor,
 * ou pela tarefa agendada src/public/cron/trabalhos.php) e o utilizador e avisado quando esta pronto.
 * Idempotente.
 */
return [
    'id' => '20261108_1300_trabalhos_fila',
    'description' => 'Fila de trabalhos em segundo plano (exportacao de dados).',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS trabalhos_fila (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            restaurante_id INT NOT NULL,
            usuario_id INT NULL,
            tipo VARCHAR(40) NOT NULL,
            estado VARCHAR(16) NOT NULL DEFAULT 'PENDENTE',
            parametros TEXT NULL,
            ficheiro VARCHAR(255) NULL,
            nome_download VARCHAR(160) NULL,
            bytes BIGINT UNSIGNED NULL,
            erro VARCHAR(500) NULL,
            tentativas TINYINT UNSIGNED NOT NULL DEFAULT 0,
            reserva VARCHAR(32) NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            iniciado_em DATETIME NULL,
            concluido_em DATETIME NULL,
            expira_em DATETIME NULL,
            descarregado_em DATETIME NULL,
            KEY idx_trabalhos_estado (estado, criado_em),
            KEY idx_trabalhos_rest (restaurante_id, tipo, criado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    },
];
