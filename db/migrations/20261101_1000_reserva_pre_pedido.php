<?php
declare(strict_types=1);

/*
 * Liga a reserva feita pelo QR ao pre-pedido (carrinho) do mesmo QR.
 *  - reservas.qr_sessao_id: sessao QR (reserva_qr_sessoes.id) que criou a reserva;
 *  - pedido_qr_meta.reserva_id: reserva a que o pre-pedido pertence.
 * Sem esta ligacao o check-in nao sabia que o cliente ja tinha pedido e abria
 * sempre um pedido novo. Colunas opcionais (NULL): o codigo funciona sem elas.
 * Idempotente.
 */
return [
    'id' => '20261101_1000_reserva_pre_pedido',
    'description' => 'Liga reservas por QR ao respetivo pre-pedido (reservas.qr_sessao_id, pedido_qr_meta.reserva_id).',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $coluna = static function (string $tabela, string $col) use ($db): bool {
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
            );
            $stmt->execute([':t' => $tabela, ':c' => $col]);
            return (int)$stmt->fetchColumn() > 0;
        };
        $tabela = static function (string $nome) use ($db): bool {
            $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
            $stmt->execute([':t' => $nome]);
            return (int)$stmt->fetchColumn() > 0;
        };

        if (!$tabela('reservas') || !$tabela('pedido_qr_meta')) {
            throw new RuntimeException('Tabelas reservas/pedido_qr_meta inexistentes; migracao interrompida.');
        }
        if (!$coluna('reservas', 'qr_sessao_id')) {
            $db->exec('ALTER TABLE reservas ADD COLUMN qr_sessao_id BIGINT UNSIGNED NULL, ADD KEY idx_reservas_qr_sessao (qr_sessao_id)');
        }
        if (!$coluna('pedido_qr_meta', 'reserva_id')) {
            $db->exec('ALTER TABLE pedido_qr_meta ADD COLUMN reserva_id INT NULL, ADD KEY idx_qr_meta_reserva (reserva_id)');
        }
    },
];
