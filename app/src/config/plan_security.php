<?php

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/migration_state.php';

if (!defined('PLAN_PURCHASE_HARDENING_MIGRATION_ID')) {
    define('PLAN_PURCHASE_HARDENING_MIGRATION_ID', '20260821_1715_compras_planos_hardening');
}

if (!function_exists('plan_security_normalize_plan')) {
    function plan_security_normalize_plan($plan): string
    {
        $plan = strtoupper(trim((string)$plan));
        return $plan === 'ENTERPRISE' ? 'EMPRESARIAL' : $plan;
    }
}

if (!function_exists('plan_security_normalize_cycle')) {
    function plan_security_normalize_cycle($cycle): string
    {
        return strtoupper(trim((string)$cycle ?: 'MENSAL'));
    }
}

if (!function_exists('plan_security_normalize_method')) {
    function plan_security_normalize_method($method): string
    {
        return strtoupper(trim((string)$method));
    }
}

if (!function_exists('plan_security_price')) {
    function plan_security_price(string $plan, string $cycle): float
    {
        $plans = require __DIR__ . '/planos.php';
        $plan = plan_security_normalize_plan($plan);
        $cycle = plan_security_normalize_cycle($cycle);
        $cycleKey = ['MENSAL' => 'mensal', 'TRIMESTRAL' => 'trimestral', 'ANUAL' => 'anual'][$cycle] ?? '';

        return (float)($plans[$plan]['precos'][$cycleKey] ?? 0);
    }
}

if (!function_exists('plan_security_days')) {
    function plan_security_days(string $cycle): int
    {
        return ['MENSAL' => 30, 'TRIMESTRAL' => 90, 'ANUAL' => 365][plan_security_normalize_cycle($cycle)] ?? 30;
    }
}

if (!function_exists('plan_security_trial_days')) {
    function plan_security_trial_days(string $plan): int
    {
        $plans = require __DIR__ . '/planos.php';
        $plan = plan_security_normalize_plan($plan);
        return max(0, (int)($plans[$plan]['trial_dias'] ?? 0));
    }
}

if (!function_exists('plan_security_supports_trial')) {
    function plan_security_supports_trial(string $plan): bool
    {
        return plan_security_trial_days($plan) > 0;
    }
}

if (!function_exists('plan_security_is_trial_purchase')) {
    function plan_security_is_trial_purchase(array $purchase): bool
    {
        $plan = plan_security_normalize_plan($purchase['plano_novo'] ?? '');
        $method = plan_security_normalize_method($purchase['metodo_pagamento'] ?? '');
        $cycle = plan_security_normalize_cycle($purchase['ciclo'] ?? 'MENSAL');
        $valor = (float)($purchase['valor'] ?? 0);

        if ($plan !== 'BASICO' || !plan_security_supports_trial($plan)) {
            return false;
        }

        if ($method === 'TRIAL') {
            return true;
        }

        return $valor === 0.0 && $cycle === 'MENSAL';
    }
}

if (!function_exists('plan_security_purchase_trial_days')) {
    function plan_security_purchase_trial_days(array $purchase): int
    {
        return plan_security_is_trial_purchase($purchase)
            ? plan_security_trial_days((string)$purchase['plano_novo'])
            : 0;
    }
}

if (!function_exists('plan_security_validate_request')) {
    function plan_security_validate_request(string $currentPlan, string $newPlan, string $cycle, string $method, bool $allowSamePlan = false): array
    {
        $newPlan = plan_security_normalize_plan($newPlan);
        $cycle = plan_security_normalize_cycle($cycle);
        $method = plan_security_normalize_method($method);

        $validPlans = ['BASICO', 'PROFISSIONAL', 'EMPRESARIAL'];
        $validCycles = ['MENSAL', 'TRIMESTRAL', 'ANUAL'];
        $validMethods = ['DINHEIRO', 'MPESA', 'EMOLA', 'CARTAO', 'TRANSFERENCIA', 'TRIAL'];

        if (!in_array($newPlan, $validPlans, true)) {
            return ['ok' => false, 'message' => 'Plano invalido.'];
        }
        if (!in_array($cycle, $validCycles, true)) {
            return ['ok' => false, 'message' => 'Ciclo invalido.'];
        }
        if (!in_array($method, $validMethods, true)) {
            return ['ok' => false, 'message' => 'Metodo de pagamento invalido.'];
        }

        $hierarchy = ['BASICO' => 1, 'PROFISSIONAL' => 2, 'EMPRESARIAL' => 3];
        $currentLevel = $hierarchy[plan_security_normalize_plan($currentPlan)] ?? 1;
        $newLevel = $hierarchy[$newPlan] ?? 1;

        if ($newLevel < $currentLevel) {
            return ['ok' => false, 'message' => 'Downgrade de plano nao e permitido por solicitacao direta.'];
        }
        if (!$allowSamePlan && $newLevel === $currentLevel) {
            return ['ok' => false, 'message' => 'O restaurante ja possui este plano.'];
        }

        if ($method === 'TRIAL') {
            if ($newPlan !== 'BASICO' || !plan_security_supports_trial($newPlan)) {
                return ['ok' => false, 'message' => 'Trial disponivel apenas para o plano Basico.'];
            }
            if ($cycle !== 'MENSAL') {
                return ['ok' => false, 'message' => 'Trial so esta disponivel para ciclo Mensal.'];
            }
            return [
                'ok' => true,
                'plan' => $newPlan,
                'cycle' => $cycle,
                'method' => $method,
                'price' => 0.0,
            ];
        }

        $price = plan_security_price($newPlan, $cycle);
        if ($price <= 0) {
            return ['ok' => false, 'message' => 'Preco invalido para plano/ciclo selecionado.'];
        }

        return [
            'ok' => true,
            'plan' => $newPlan,
            'cycle' => $cycle,
            'method' => $method,
            'price' => $price,
        ];
    }
}

if (!function_exists('plan_security_purchase_integrity')) {
    function plan_security_purchase_integrity(array $purchase): array
    {
        $plan = plan_security_normalize_plan($purchase['plano_novo'] ?? '');
        $cycle = plan_security_normalize_cycle($purchase['ciclo'] ?? 'MENSAL');
        $storedValue = (float)($purchase['valor'] ?? 0);

        if ($cycle !== 'MENSAL' && $cycle !== 'TRIMESTRAL' && $cycle !== 'ANUAL') {
            return ['ok' => false, 'message' => 'Ciclo invalido na compra.'];
        }

        if (plan_security_is_trial_purchase($purchase)) {
            if (abs($storedValue - 0.0) > 0.01) {
                return [
                    'ok' => false,
                    'message' => 'Valor da compra de trial deve ser 0.',
                    'expected' => 0.0,
                    'stored' => $storedValue,
                ];
            }
            return ['ok' => true, 'expected' => 0.0, 'stored' => $storedValue];
        }

        $expectedValue = plan_security_price($plan, $cycle);
        if ($expectedValue <= 0) {
            return ['ok' => false, 'message' => 'Plano ou ciclo invalido na compra.'];
        }

        if (abs($storedValue - $expectedValue) > 0.01) {
            return [
                'ok' => false,
                'message' => 'Valor da compra nao confere com a tabela oficial de planos.',
                'expected' => $expectedValue,
                'stored' => $storedValue,
            ];
        }

        return ['ok' => true, 'expected' => $expectedValue, 'stored' => $storedValue];
    }
}

if (!function_exists('plan_security_file_sha256')) {
    function plan_security_file_sha256(string $path): string
    {
        if ($path === '' || !is_file($path)) {
            return '';
        }
        $hash = @hash_file('sha256', $path);
        return is_string($hash) ? strtolower(trim($hash)) : '';
    }
}

if (!function_exists('plan_security_purchase_has_hash_column')) {
    function plan_security_purchase_has_hash_column(PDO $db): bool
    {
        $cols = plan_security_table_columns($db, 'compras_planos');
        return in_array('hash_arquivo', $cols, true);
    }
}

if (!function_exists('plan_security_purchase_find_duplicate_proof')) {
    function plan_security_purchase_find_duplicate_proof(PDO $db, string $hashArquivo, int $restauranteId = 0): ?array
    {
        $hashArquivo = strtolower(trim($hashArquivo));
        if ($hashArquivo === '' || !plan_security_purchase_has_hash_column($db)) {
            return null;
        }

        $sql = "
            SELECT id, restaurante_id, status, plano_novo, valor
              FROM compras_planos
             WHERE hash_arquivo = :hash
               AND status IN ('AGUARDANDO_APROVACAO', 'PENDENTE', 'APROVADO')
        ";
        $params = [':hash' => $hashArquivo];
        if ($restauranteId > 0) {
            $sql .= " AND restaurante_id <> :restaurante_id";
            $params[':restaurante_id'] = $restauranteId;
        }
        $sql .= " ORDER BY id DESC LIMIT 1";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('plan_security_table_columns')) {
    function plan_security_table_columns(PDO $db, string $table): array
    {
        $columns = [];
        $safeTable = str_replace('`', '', $table);
        $stmt = $db->query("SHOW COLUMNS FROM `{$safeTable}`");
        while ($col = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[] = (string)$col['Field'];
        }
        return $columns;
    }
}

if (!function_exists('plan_security_purchase_status_value')) {
    function plan_security_purchase_status_value(PDO $db, string $semanticStatus): string
    {
        static $cache = [];

        $semanticStatus = strtoupper(trim($semanticStatus));
        $cacheKey = spl_object_hash($db);
        if (!isset($cache[$cacheKey])) {
            $stmt = $db->query("SHOW COLUMNS FROM compras_planos LIKE 'status'");
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
            $cache[$cacheKey] = strtolower((string)($row['Type'] ?? ''));
        }

        $type = $cache[$cacheKey];
        $map = [
            'PENDING' => strpos($type, 'aguardando_aprovacao') !== false ? 'AGUARDANDO_APROVACAO' : 'PENDENTE',
            'APPROVED' => 'APROVADO',
            'REJECTED' => strpos($type, 'rejeitado') !== false ? 'REJEITADO' : 'CANCELADO',
        ];

        return $map[$semanticStatus] ?? 'PENDENTE';
    }
}

if (!function_exists('plan_security_ensure_purchase_hardening_columns')) {
    function plan_security_ensure_purchase_hardening_columns(PDO $db): void
    {
        if (migration_esta_aplicada($db, PLAN_PURCHASE_HARDENING_MIGRATION_ID)) {
            return;
        }

        $cols = plan_security_table_columns($db, 'compras_planos');
        if (!in_array('hash_arquivo', $cols, true)) {
            $db->exec("ALTER TABLE compras_planos ADD COLUMN hash_arquivo CHAR(64) NULL AFTER comprovativo_path");
            $db->exec("CREATE INDEX idx_compras_hash_arquivo ON compras_planos (hash_arquivo)");
        }

        $stmt = $db->query("SHOW COLUMNS FROM compras_planos LIKE 'metodo_pagamento'");
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        if (is_array($row)) {
            $type = strtolower((string)($row['Type'] ?? ''));
            if (strpos($type, 'trial') === false) {
                $db->exec("ALTER TABLE compras_planos MODIFY COLUMN metodo_pagamento ENUM('DINHEIRO','MPESA','CARTAO','TRANSFERENCIA','TRIAL') NOT NULL");
            }
        }
    }
}
