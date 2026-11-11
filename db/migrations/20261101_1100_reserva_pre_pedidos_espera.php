<?php
declare(strict_types=1);

/*
 * Pre-pedidos de reservas em espera ate ao check-in (app/src/api/pre_pedido_reserva.php).
 * O carrinho validado (itens, precos, total) fica aqui e so passa a pedido real
 * no check-in; ate la nao aparece na cozinha/bar/caixa nem mexe no stock.
 * Idempotente.
 */
return [
    'id' => '20261101_1100_reserva_pre_pedidos_espera',
    'description' => 'Tabela reserva_pre_pedidos: pre-pedido da reserva so vai para a cozinha no check-in.',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS reserva_pre_pedidos (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            restaurante_id INT NOT NULL,
            reserva_id INT NOT NULL,
            token VARCHAR(128) NOT NULL,
            idem_key VARCHAR(128) NOT NULL,
            dados LONGTEXT NOT NULL,
            total DECIMAL(10,2) NOT NULL DEFAULT 0,
            cliente_nome VARCHAR(160) NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            enviado_em DATETIME NULL,
            pedido_id BIGINT UNSIGNED NULL,
            UNIQUE KEY uq_pre_pedido_idem (restaurante_id, token, idem_key),
            KEY idx_pre_pedido_reserva (restaurante_id, reserva_id, enviado_em),
            KEY idx_pre_pedido_token (token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    },
];
