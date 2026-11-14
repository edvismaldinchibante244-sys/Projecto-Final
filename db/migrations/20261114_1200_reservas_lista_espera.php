<?php
declare(strict_types=1);

/*
 * Lista de espera de reservas.
 *
 * Hoje, quando nao ha mesa para a hora pedida, a reserva simplesmente nao se
 * faz e o cliente perde-se: ninguem fica a saber que ele quis vir. Esta
 * tabela guarda esses pedidos por atender, para que uma mesa que se liberte
 * por cancelamento possa ser oferecida a quem estava a espera, em vez de
 * ficar vazia.
 *
 * Estados:
 *   AGUARDA    - na fila, ainda sem mesa.
 *   AVISADO    - abriu uma vaga e o cliente foi contactado.
 *   CONVERTIDA - virou reserva (reserva_id aponta para ela).
 *   CANCELADA  - desistiu ou o restaurante retirou-o da fila.
 *
 * Idempotente.
 */
return [
    'id' => '20261114_1200_reservas_lista_espera',
    'description' => 'Cria a tabela reservas_espera (lista de espera de reservas).',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $existe = $db->query("SHOW TABLES LIKE 'reservas_espera'")->fetch(PDO::FETCH_NUM);
        if ($existe) {
            return;
        }

        $db->exec("
            CREATE TABLE reservas_espera (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurante_id INT NOT NULL,
                cliente_id INT NULL,
                nome_cliente VARCHAR(120) NOT NULL,
                email_cliente VARCHAR(180) NULL,
                telefone_cliente VARCHAR(40) NULL,
                data_reserva DATE NOT NULL,
                hora_reserva TIME NOT NULL,
                quantidade_pessoas INT NOT NULL DEFAULT 2,
                observacoes VARCHAR(255) NULL,
                status ENUM('AGUARDA','AVISADO','CONVERTIDA','CANCELADA') NOT NULL DEFAULT 'AGUARDA',
                avisado_em DATETIME NULL,
                reserva_id INT NULL,
                criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_espera_fila (restaurante_id, data_reserva, status, criado_em),
                INDEX idx_espera_telefone (restaurante_id, telefone_cliente)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
];
