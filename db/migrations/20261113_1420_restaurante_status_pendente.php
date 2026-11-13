<?php
declare(strict_types=1);

/*
 * Acrescenta o estado PENDENTE aos restaurantes.
 *
 * O codigo ja preferia PENDENTE para uma inscricao nova
 * (restaurante_status_resolver_inicial), mas a coluna so aceitava
 * ATIVO/BLOQUEADO/CANCELADO, pelo que toda a inscricao caia em BLOQUEADO.
 *
 * Isso misturava duas situacoes diferentes: "ainda nao foi aprovado" e
 * "deixou de pagar". Alem de falsear os contadores do painel, punha as
 * inscricoes por aprovar no caminho das regras de inadimplencia, que dao
 * acesso em modo de exportacao a quem ja foi cliente.
 *
 * Idempotente.
 */
return [
    'id' => '20261113_1420_restaurante_status_pendente',
    'description' => "Acrescenta PENDENTE ao enum restaurantes.status.",
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $coluna = $db->query("SHOW COLUMNS FROM restaurantes LIKE 'status'")->fetch(PDO::FETCH_ASSOC) ?: [];
        $tipo = (string)($coluna['Type'] ?? '');
        if ($tipo === '') {
            return;
        }

        if (stripos($tipo, "'PENDENTE'") !== false) {
            return;
        }

        $db->exec("ALTER TABLE restaurantes
            MODIFY COLUMN status enum('ATIVO','PENDENTE','BLOQUEADO','CANCELADO') DEFAULT 'ATIVO'");
    },
];
