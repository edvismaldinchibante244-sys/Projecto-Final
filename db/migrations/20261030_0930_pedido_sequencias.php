<?php
declare(strict_types=1);

return [
    'id' => '20261030_0930_pedido_sequencias',
    'description' => 'Contador atomico por restaurante e dia para numeros de pedido sem duplicados.',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS pedido_sequencias (
            restaurante_id INT NOT NULL,
            dia DATE NOT NULL,
            ultimo INT UNSIGNED NOT NULL,
            atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (restaurante_id, dia)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    },
];
