<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/super_admin_permissions.php';
require_once APP_BASE . '/src/config/support_tickets.php';

header('Content-Type: application/json; charset=utf-8');

api_guard([
    'rate_key' => 'super_admin_support_tickets',
    'rate_max' => 80,
    'rate_window' => 60,
    'skip_turno' => true,
    'skip_csrf' => true,
]);

if (!super_admin_is_authenticated()) {
    security_json(['success' => false, 'message' => 'Acesso negado'], 403);
}

super_admin_require_permission_json('manage_support');

$db = (new Database())->getConnection();
support_tickets_ensure_tables($db);

$status = trim((string)($_GET['status'] ?? ''));
$search = trim((string)($_GET['q'] ?? ''));

$sql = "
    SELECT t.*,
           r.nome AS restaurante_nome,
           r.email AS restaurante_email,
           r.plano AS restaurante_plano,
           u.nome AS usuario_nome
    FROM support_tickets t
    INNER JOIN restaurantes r ON r.id = t.restaurante_id
    LEFT JOIN usuarios u ON u.id = t.usuario_id
    WHERE 1=1
";
$params = [];

if (strtoupper($status) === 'ABERTOS') {
    // Por defeito o painel mostra so o que precisa de accao.
    $sql .= " AND t.status IN ('ABERTO','EM_ANDAMENTO')";
} elseif ($status !== '') {
    $sql .= " AND t.status = :status";
    $params[':status'] = support_tickets_normalize_status($status);
}
if ($search !== '') {
    $sql .= " AND (r.nome LIKE :q OR t.assunto LIKE :q OR t.descricao LIKE :q)";
    $params[':q'] = '%' . $search . '%';
}

$sql .= " ORDER BY FIELD(t.status, 'ABERTO','EM_ANDAMENTO','RESOLVIDO','FECHADO'), FIELD(t.prioridade, 'URGENTE','ALTA','MEDIA','BAIXA'), t.id DESC LIMIT 120";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$stats = [
    'total' => 0,
    'abertos' => 0,
    'urgentes' => 0,
    'resolvidos' => 0,
    'sla_no_prazo' => 0,
    'sla_em_risco' => 0,
    'sla_vencidos' => 0,
];
foreach ($rows as &$row) {
    $stats['total']++;
    if (in_array($row['status'], ['ABERTO', 'EM_ANDAMENTO'], true)) {
        $stats['abertos']++;
    }
    if ($row['prioridade'] === 'URGENTE') {
        $stats['urgentes']++;
    }
    if (in_array($row['status'], ['RESOLVIDO', 'FECHADO'], true)) {
        $stats['resolvidos']++;
    }
    $row['prioridade_label'] = support_tickets_priority_label((string)$row['prioridade']);
    $row['status_label'] = support_tickets_status_label((string)$row['status']);
    $row['sla'] = support_tickets_sla_metrics($row);
    if (in_array($row['status'], ['ABERTO', 'EM_ANDAMENTO'], true)) {
        if ($row['sla']['state'] === 'VENCIDO') {
            $stats['sla_vencidos']++;
        } elseif ($row['sla']['state'] === 'EM_RISCO') {
            $stats['sla_em_risco']++;
        } else {
            $stats['sla_no_prazo']++;
        }
    }
}
unset($row);

// Ordem por urgencia: abertos primeiro, SLA vencido > em risco > no prazo, depois prioridade e antiguidade.
$pesoSla = ['VENCIDO' => 0, 'EM_RISCO' => 1];
$pesoPri = ['URGENTE' => 0, 'ALTA' => 1, 'MEDIA' => 2, 'BAIXA' => 3];
usort($rows, static function (array $a, array $b) use ($pesoSla, $pesoPri): int {
    $abertoA = in_array($a['status'], ['ABERTO', 'EM_ANDAMENTO'], true) ? 0 : 1;
    $abertoB = in_array($b['status'], ['ABERTO', 'EM_ANDAMENTO'], true) ? 0 : 1;
    return [$abertoA, $pesoSla[$a['sla']['state'] ?? ''] ?? 2, $pesoPri[$a['prioridade']] ?? 4, (int)$a['id']]
        <=> [$abertoB, $pesoSla[$b['sla']['state'] ?? ''] ?? 2, $pesoPri[$b['prioridade']] ?? 4, (int)$b['id']];
});

// Cartoes do topo: sempre sobre todos os tickets (nao mudam com o filtro).
$resumo = ['abertos' => 0, 'sla_vencidos' => 0, 'resolvidos_mes' => 0];
$todos = $db->query("SELECT * FROM support_tickets WHERE status IN ('ABERTO','EM_ANDAMENTO')")->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($todos as $t) {
    $resumo['abertos']++;
    if ((support_tickets_sla_metrics($t)['state'] ?? '') === 'VENCIDO') {
        $resumo['sla_vencidos']++;
    }
}
$resumo['resolvidos_mes'] = (int)$db->query("SELECT COUNT(*) FROM support_tickets WHERE status IN ('RESOLVIDO','FECHADO')
    AND COALESCE(atualizado_em, criado_em) >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();
$stats = array_merge($stats, $resumo);

security_json(['success' => true, 'data' => $rows, 'stats' => $stats]);
