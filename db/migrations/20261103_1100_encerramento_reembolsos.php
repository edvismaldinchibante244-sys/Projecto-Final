<?php
declare(strict_types=1);

/*
 * Encerramento de contas (Termos, seccoes 4, 5 e 7):
 *  - restaurantes: pedido de cancelamento, data em que produz efeito, data agendada para apagar
 *    e aviso enviado;
 *  - eliminacao_declaracoes: prova de que os dados foram apagados (sobrevive ao restaurante);
 *  - plano_reembolsos: registo de reembolsos feitos pelo Super Admin.
 * Idempotente.
 */
return [
    'id' => '20261103_1100_encerramento_reembolsos',
    'description' => 'Cancelamento pelo cliente, eliminacao agendada com declaracao, e registo de reembolsos.',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $cols = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurantes'")->fetchAll(PDO::FETCH_COLUMN);
        if (!$cols) {
            throw new RuntimeException('Tabela restaurantes inexistente; migracao interrompida.');
        }
        $novas = [
            'cancelamento_pedido_em' => 'DATETIME NULL',
            'cancelamento_pedido_por' => 'INT NULL',
            'cancelamento_motivo' => 'VARCHAR(500) NULL',
            'cancelamento_efetivo_em' => 'DATE NULL',
            'cancelado_em' => 'DATETIME NULL',
            'eliminacao_agendada_em' => 'DATE NULL',
            'eliminacao_aviso_em' => 'DATETIME NULL',
        ];
        foreach ($novas as $c => $def) {
            if (!in_array($c, $cols, true)) {
                $db->exec("ALTER TABLE restaurantes ADD COLUMN `$c` $def");
            }
        }
        $idx = $db->query("SHOW INDEX FROM restaurantes WHERE Key_name = 'idx_rest_encerramento'")->fetch();
        if (!$idx) {
            $db->exec('ALTER TABLE restaurantes ADD INDEX idx_rest_encerramento (cancelamento_efetivo_em, cancelado_em, eliminacao_agendada_em)');
        }

        $db->exec("CREATE TABLE IF NOT EXISTS eliminacao_declaracoes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            codigo CHAR(36) NOT NULL,
            restaurante_id INT NOT NULL,
            restaurante_nome VARCHAR(150) NOT NULL,
            email_lookup CHAR(64) NULL,
            cancelado_em DATETIME NULL,
            eliminado_em DATETIME NOT NULL,
            linhas_apagadas INT NOT NULL DEFAULT 0,
            detalhe_json TEXT NULL,
            hash CHAR(64) NOT NULL,
            UNIQUE KEY uq_eliminacao_codigo (codigo),
            KEY idx_eliminacao_rest (restaurante_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS plano_reembolsos (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            restaurante_id INT NOT NULL,
            restaurante_nome VARCHAR(150) NOT NULL,
            valor DECIMAL(12,2) NOT NULL,
            metodo VARCHAR(40) NOT NULL,
            referencia VARCHAR(120) NULL,
            motivo VARCHAR(500) NOT NULL,
            data_reembolso DATE NOT NULL,
            registado_por INT NOT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_reembolso_rest (restaurante_id, data_reembolso)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    },
];
