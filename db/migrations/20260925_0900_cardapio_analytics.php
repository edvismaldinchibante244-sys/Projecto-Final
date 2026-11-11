<?php
declare(strict_types=1);

return [
    'id' => '20260925_0900_cardapio_analytics',
    'description' => 'Regista eventos anonimizados do cardápio QR para métricas de utilização.',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS cardapio_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            restaurante_id INT NOT NULL,
            mesa_id INT NULL,
            sessao_hash CHAR(64) NULL,
            evento VARCHAR(32) NOT NULL,
            produto_id INT NULL,
            visitor_hash CHAR(64) NULL,
            user_agent_hash CHAR(64) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cardapio_events_restaurante_created (restaurante_id, created_at),
            INDEX idx_cardapio_events_evento (evento),
            INDEX idx_cardapio_events_produto (produto_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    },
];
