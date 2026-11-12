<?php
declare(strict_types=1);

/*
 * Bónus de boas-vindas: quem paga pela primeira vez usa o plano de cima
 * durante alguns dias (Básico -> Profissional, Profissional -> Empresarial)
 * e quem já paga Empresarial recebe dias extra de plano.
 *
 * Uma vez por restaurante e uma vez por dono (chave de NUIT ou email, já
 * guardada em hash nas colunas *_lookup de `restaurantes`), para ninguém
 * repetir a oferta criando restaurantes novos ou cancelando e voltando.
 * Idempotente.
 */
return [
    'id' => '20261112_1400_plano_bonus_boas_vindas',
    'description' => 'Cria a tabela plano_bonus (oferta de boas-vindas no primeiro pagamento).',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS plano_bonus (
            id INT(11) NOT NULL AUTO_INCREMENT,
            restaurante_id INT(11) NOT NULL,
            dono_chave CHAR(64) DEFAULT NULL,
            plano_pago VARCHAR(20) NOT NULL,
            plano_bonus VARCHAR(20) NOT NULL,
            tipo ENUM('UPGRADE','DIAS_EXTRA') NOT NULL DEFAULT 'UPGRADE',
            dias INT(11) NOT NULL DEFAULT 14,
            estado ENUM('PENDENTE','ATIVO','TERMINADO','CANCELADO') NOT NULL DEFAULT 'PENDENTE',
            compra_id INT(11) DEFAULT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            inicio_em DATE DEFAULT NULL,
            fim_em DATE DEFAULT NULL,
            terminado_em DATETIME DEFAULT NULL,
            aviso7_em DATETIME DEFAULT NULL,
            aviso12_em DATETIME DEFAULT NULL,
            resumo_visto_em DATETIME DEFAULT NULL,
            observacoes VARCHAR(255) DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_restaurante (restaurante_id),
            KEY idx_dono (dono_chave),
            KEY idx_estado_fim (estado, fim_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    },
];
