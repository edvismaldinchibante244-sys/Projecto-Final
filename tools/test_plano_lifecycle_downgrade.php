<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/src/config/database.php';
require_once __DIR__ . '/../app/src/config/plano_lifecycle.php';

$db = (new Database())->getConnection();
if (!$db instanceof PDO) {
    throw new RuntimeException('Conexao PDO indisponivel.');
}

$checks = 0;
$failures = [];
$notes = [];

$check = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
};

$tableExists = static function (PDO $db, string $table): bool {
    if (!preg_match('/^[a-z_]+$/', $table)) {
        return false;
    }
    $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table LIMIT 1');
    $stmt->execute(['table' => $table]);
    return (bool)$stmt->fetchColumn();
};

$tableColumns = static function (PDO $db, string $table) use ($tableExists): array {
    if (!$tableExists($db, $table)) {
        return [];
    }
    $columns = [];
    foreach ($db->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[] = (string)$column['Field'];
    }
    return $columns;
};

$businessSnapshot = static function (PDO $db, int $restaurantId) use ($tableColumns): array {
    $snapshot = [];
    $tables = [
        'produtos',
        'usuarios',
        'mesas',
        'categorias',
        'subcategorias',
        'clientes',
        'pedidos',
        'vendas',
    ];
    foreach ($tables as $table) {
        $columns = $tableColumns($db, $table);
        if (!in_array('restaurante_id', $columns, true)) {
            continue;
        }
        $idExpression = in_array('id', $columns, true) ? 'COALESCE(SUM(id), 0)' : '0';
        $stmt = $db->prepare("SELECT COUNT(*) AS total, {$idExpression} AS id_sum FROM `{$table}` WHERE restaurante_id = :rid");
        $stmt->execute(['rid' => $restaurantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $snapshot[$table] = [
            'total' => (int)($row['total'] ?? 0),
            'id_sum' => (int)($row['id_sum'] ?? 0),
        ];
    }

    $restaurantColumns = $tableColumns($db, 'restaurantes');
    if (in_array('filial_id', $restaurantColumns, true)) {
        $stmt = $db->prepare('SELECT COUNT(*) AS total, COALESCE(SUM(id), 0) AS id_sum FROM restaurantes WHERE filial_id = :rid');
        $stmt->execute(['rid' => $restaurantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $snapshot['filiais'] = [
            'total' => (int)($row['total'] ?? 0),
            'id_sum' => (int)($row['id_sum'] ?? 0),
        ];
    }

    ksort($snapshot);
    return $snapshot;
};

$planSnapshot = static function (PDO $db, int $restaurantId) use ($tableColumns): array {
    $restaurantColumns = $tableColumns($db, 'restaurantes');
    $wanted = array_values(array_intersect(
        ['id', 'plano', 'plano_id', 'status', 'status_plano', 'data_inicio', 'data_inicio_plano', 'data_fim', 'data_fim_plano'],
        $restaurantColumns
    ));
    $restaurant = [];
    if ($wanted) {
        $quoted = implode(', ', array_map(static fn(string $column): string => "`{$column}`", $wanted));
        $stmt = $db->prepare("SELECT {$quoted} FROM restaurantes WHERE id = :rid LIMIT 1");
        $stmt->execute(['rid' => $restaurantId]);
        $restaurant = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    $history = [];
    if ($tableColumns($db, 'restaurante_planos')) {
        $stmt = $db->prepare('SELECT id, plano_id, data_inicio, data_fim, status, tipo_plano, valor_pago FROM restaurante_planos WHERE restaurante_id = :rid ORDER BY id');
        $stmt->execute(['rid' => $restaurantId]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    return ['restaurante' => $restaurant, 'historico' => $history];
};

$today = new DateTimeImmutable('today');
$policyCases = [
    ['days' => 8, 'state' => 'ATIVO', 'level' => 'NONE'],
    ['days' => 7, 'state' => 'ALERTA_7D', 'level' => 'NOTICE_ONLY'],
    ['days' => 3, 'state' => 'ALERTA_3D', 'level' => 'LIMITED_NON_CRITICAL'],
    ['days' => 1, 'state' => 'ALERTA_1D', 'level' => 'NOTICE_ONLY'],
    ['days' => 0, 'state' => 'ALERTA_1D', 'level' => 'NOTICE_ONLY'],
    ['days' => -1, 'state' => 'VENCIDO_GRACE', 'level' => 'READ_ONLY'],
    ['days' => -7, 'state' => 'VENCIDO_GRACE', 'level' => 'READ_ONLY'],
    ['days' => -8, 'state' => 'BLOQUEADO_INADIMPLENTE', 'level' => 'FULL_BLOCK'],
];
foreach ($policyCases as $case) {
    $date = $today->modify(sprintf('%+d days', $case['days']))->format('Y-m-d');
    $policy = plano_lifecycle_compute_policy(['data_fim' => $date]);
    $check(($policy['estado'] ?? '') === $case['state'], "Estado incorreto para {$case['days']} dia(s): esperado {$case['state']}.");
    $check(($policy['limite_nivel'] ?? '') === $case['level'], "Restricao incorreta para {$case['days']} dia(s): esperado {$case['level']}.");
}

plano_downgrade_schema_garantir($db);

$candidate = null;
$restaurantIds = $db->query("SELECT id FROM restaurantes WHERE UPPER(COALESCE(status, 'ATIVO')) = 'ATIVO' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
foreach ($restaurantIds as $candidateId) {
    $candidateId = (int)$candidateId;
    $currentPlan = plano_normalizar_nome(plano_get_dados($candidateId)['plano_nome'] ?? 'BASICO');
    $destinations = $currentPlan === 'EMPRESARIAL'
        ? ['PROFISSIONAL', 'BASICO']
        : ($currentPlan === 'PROFISSIONAL' ? ['BASICO'] : []);
    foreach ($destinations as $destination) {
        $pending = $db->prepare("SELECT COUNT(*) FROM plano_downgrades WHERE restaurante_id = :rid AND status IN ('AGENDADO', 'BLOQUEADO_LIMITE')");
        $pending->execute(['rid' => $candidateId]);
        if ((int)$pending->fetchColumn() > 0) {
            continue 2;
        }
        $evaluation = plano_downgrade_avaliar_limites($db, $candidateId, $destination);
        if (!empty($evaluation['compativel'])) {
            $candidate = [
                'id' => $candidateId,
                'origin' => $currentPlan,
                'destination' => $destination,
            ];
            break 2;
        }
    }
}

if ($candidate === null) {
    $notes[] = 'Teste transacional de downgrade ignorado: nenhum restaurante compativel sem downgrade pendente.';
} else {
    $restaurantId = (int)$candidate['id'];
    $beforeBusiness = $businessSnapshot($db, $restaurantId);
    $beforePlan = $planSnapshot($db, $restaurantId);

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("INSERT INTO plano_downgrades
            (restaurante_id, plano_origem, plano_destino, aplicar_em, status, limites_json, criado_por)
            VALUES (:rid, :origin, :destination, CURDATE(), 'AGENDADO', :limits, NULL)");
        $stmt->execute([
            'rid' => $restaurantId,
            'origin' => $candidate['origin'],
            'destination' => $candidate['destination'],
            'limits' => json_encode(['test' => true], JSON_UNESCAPED_SLASHES),
        ]);

        $result = plano_downgrade_processar_pendentes($db, $restaurantId);
        $check((int)($result['aplicados'] ?? 0) === 1, 'O downgrade transacional nao foi aplicado exatamente uma vez.');
        $check($businessSnapshot($db, $restaurantId) === $beforeBusiness, 'O downgrade alterou ou removeu dados operacionais dentro da transacao.');

        $stmt = $db->prepare('SELECT plano FROM restaurantes WHERE id = :rid LIMIT 1');
        $stmt->execute(['rid' => $restaurantId]);
        $appliedPlan = plano_normalizar_nome((string)$stmt->fetchColumn());
        $check($appliedPlan === $candidate['destination'], "Plano transacional incorreto: esperado {$candidate['destination']}, encontrado {$appliedPlan}.");
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }

    $check($businessSnapshot($db, $restaurantId) === $beforeBusiness, 'ROLLBACK nao restaurou os dados operacionais originais.');
    $check($planSnapshot($db, $restaurantId) === $beforePlan, 'ROLLBACK nao restaurou integralmente o plano original.');
    $notes[] = "Downgrade {$candidate['origin']} -> {$candidate['destination']} validado com ROLLBACK no restaurante #{$restaurantId}.";
}

$statusCandidate = (int)($db->query('SELECT id FROM restaurantes ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
if ($statusCandidate <= 0) {
    $notes[] = 'Teste transacional de status ignorado: nenhum restaurante encontrado.';
} else {
    $beforeBusiness = $businessSnapshot($db, $statusCandidate);
    $beforePlan = $planSnapshot($db, $statusCandidate);
    $originalStatus = (string)($beforePlan['restaurante']['status'] ?? '');

    $db->beginTransaction();
    try {
        plano_lifecycle_apply_status($db, $statusCandidate, 'ALERTA_1D');
        $stmt = $db->prepare('SELECT status FROM restaurantes WHERE id = :rid');
        $stmt->execute(['rid' => $statusCandidate]);
        $check(strtoupper((string)$stmt->fetchColumn()) === 'ATIVO', 'Restaurante foi bloqueado antes do vencimento.');

        plano_lifecycle_apply_status($db, $statusCandidate, 'VENCIDO_GRACE');
        $stmt->execute(['rid' => $statusCandidate]);
        $check(strtoupper((string)$stmt->fetchColumn()) === 'ATIVO', 'Modo limitado deve manter o login possivel (status ATIVO).');

        plano_lifecycle_apply_status($db, $statusCandidate, 'BLOQUEADO_INADIMPLENTE');
        $stmt->execute(['rid' => $statusCandidate]);
        $check(strtoupper((string)$stmt->fetchColumn()) === 'BLOQUEADO', 'Restaurante vencido ha mais de 7 dias nao foi bloqueado.');
        $check($businessSnapshot($db, $statusCandidate) === $beforeBusiness, 'Expiracao alterou ou removeu dados operacionais dentro da transacao.');
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }

    $check($businessSnapshot($db, $statusCandidate) === $beforeBusiness, 'ROLLBACK da expiracao nao preservou os dados operacionais.');
    $check($planSnapshot($db, $statusCandidate) === $beforePlan, 'ROLLBACK da expiracao nao restaurou o estado original.');
    $notes[] = "Expiracao validada com ROLLBACK no restaurante #{$statusCandidate} (status original {$originalStatus}).";
}

foreach ($notes as $note) {
    echo "INFO: {$note}\n";
}

if ($failures) {
    fwrite(STDERR, "FALHA: lifecycle/downgrade inconsistente.\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    fwrite(STDERR, "Verificacoes: {$checks}; falhas: " . count($failures) . ".\n");
    exit(1);
}

echo "OK: downgrade, expiracao e preservacao de dados validados ({$checks} verificacoes).\n";

