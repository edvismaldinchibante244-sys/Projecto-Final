<?php

require_once dirname(__DIR__) . '/app/src/config/support_tickets.php';
require_once dirname(__DIR__) . '/app/src/config/privacy_requests.php';
require_once dirname(__DIR__) . '/app/src/config/security_monitor.php';
require_once dirname(__DIR__) . '/app/src/config/audit.php';
require_once dirname(__DIR__) . '/app/src/config/database.php';

$checks = [];
$check = static function (string $name, bool $passed) use (&$checks): void {
    $checks[$name] = $passed;
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
};

$now = new DateTimeImmutable('2026-08-12 12:00:00');
$urgentOverdue = support_tickets_sla_metrics([
    'prioridade' => 'URGENTE',
    'status' => 'ABERTO',
    'criado_em' => '2026-08-12 09:00:00',
    'respondido_em' => null,
], $now);
$highRisk = support_tickets_sla_metrics([
    'prioridade' => 'ALTA',
    'status' => 'ABERTO',
    'criado_em' => '2026-08-12 05:30:00',
    'respondido_em' => null,
], $now);
$mediumOk = support_tickets_sla_metrics([
    'prioridade' => 'MEDIA',
    'status' => 'ABERTO',
    'criado_em' => '2026-08-12 10:00:00',
    'respondido_em' => null,
], $now);
$respondedOk = support_tickets_sla_metrics([
    'prioridade' => 'BAIXA',
    'status' => 'EM_ANDAMENTO',
    'criado_em' => '2026-08-12 08:00:00',
    'respondido_em' => '2026-08-12 09:00:00',
], $now);

$check('support_sla_priority_targets', support_tickets_sla_hours('URGENTE') === 2 && support_tickets_sla_hours('BAIXA') === 48);
$check('support_sla_detects_overdue', $urgentOverdue['state'] === 'VENCIDO');
$check('support_sla_detects_risk', $highRisk['state'] === 'EM_RISCO');
$check('support_sla_detects_on_time', $mediumOk['state'] === 'NO_PRAZO');
$check('support_sla_records_compliance', $respondedOk['state'] === 'CUMPRIDO');

$deadlineDays = privacy_request_operational_deadline_days();
$warningDays = privacy_request_operational_warning_days();
$privacyOverdue = privacy_request_operational_timing([
    'status' => 'EM_PROCESSAMENTO',
    'created_at' => $now->modify('-' . ($deadlineDays + 1) . ' days')->format('Y-m-d H:i:s'),
], $now);
$privacyDueSoon = privacy_request_operational_timing([
    'status' => 'EM_PROCESSAMENTO',
    'created_at' => $now->modify('-' . ($deadlineDays - max(1, $warningDays - 1)) . ' days')->format('Y-m-d H:i:s'),
], $now);
$privacyClosed = privacy_request_operational_timing([
    'status' => 'CONCLUIDO',
    'created_at' => $now->modify('-' . ($deadlineDays + 10) . ' days')->format('Y-m-d H:i:s'),
], $now);

$check('privacy_deadline_is_configurable_and_bounded', $deadlineDays >= 7 && $deadlineDays <= 90 && $warningDays >= 1 && $warningDays <= 30);
$check('privacy_timing_detects_overdue', $privacyOverdue['state'] === 'VENCIDO');
$check('privacy_timing_detects_due_soon', $privacyDueSoon['state'] === 'PROXIMO_PRAZO');
$check('privacy_timing_keeps_closed_requests_closed', $privacyClosed['state'] === 'ENCERRADO');

$statsSource = file_get_contents(dirname(__DIR__) . '/src/public/api/super_admin_estatisticas.php') ?: '';
$dashboardSource = file_get_contents(dirname(__DIR__) . '/src/public/admin_dashboard.php') ?: '';
$auditSource = file_get_contents(dirname(__DIR__) . '/src/public/admin_auditoria.php') ?: '';
$sidebarSource = file_get_contents(dirname(__DIR__) . '/src/public/includes/super_admin_sidebar.php') ?: '';

foreach ([
    'restaurantes_em_avaliacao',
    'receita_anual_projetada',
    'utilizadores_ativos',
    'suporte_sla',
    'privacidade_prazos',
    'saude_operacional',
] as $metricKey) {
    $check('dashboard_api_has_' . $metricKey, str_contains($statsSource, '"' . $metricKey . '"'));
}
$check('dashboard_has_security_summary', str_contains($statsSource, 'alertas_24h') && str_contains($dashboardSource, 'statSecurityAlerts'));
$check('dashboard_has_health_methodology', str_contains($statsSource, 'metodologia') && str_contains($dashboardSource, 'statOperationalHealth'));
$check('audit_center_is_permission_protected', str_contains($auditSource, "requireSuperAdminPermission('manage_security')"));
$check('audit_center_masks_sensitive_context', str_contains($auditSource, 'blockedPattern') && str_contains($auditSource, 'audit_admin_mask_ip'));
$check('audit_center_is_in_governance_navigation', str_contains($sidebarSource, 'admin_auditoria.php'));

$criticalApiFiles = [
    'restaurante_editar.php',
    'restaurante_deletar.php',
    'super_admin_support_ticket_update.php',
    'super_admin_support_ticket_message_send.php',
];
foreach ($criticalApiFiles as $file) {
    $source = file_get_contents(dirname(__DIR__) . '/src/public/api/' . $file) ?: '';
    $check($file . '_writes_central_audit', str_contains($source, 'audit_log('));
}

try {
    $db = (new Database())->getConnection();
    support_tickets_ensure_tables($db);
    privacy_request_ensure_schema($db);
    security_monitor_ensure_tables($db);
    audit_ensure_table($db);

    $db->query("SELECT COUNT(*) FROM restaurantes r WHERE r.status IN ('PENDENTE','AGUARDANDO_APROVACAO') OR EXISTS (SELECT 1 FROM compras_planos cp WHERE cp.restaurante_id = r.id AND cp.status = 'PENDENTE')")->fetchColumn();
    $db->query("SELECT COUNT(*) FROM usuarios WHERE ativo = 1")->fetchColumn();
    $db->query("SELECT prioridade, status, criado_em, respondido_em FROM support_tickets WHERE status IN ('ABERTO','EM_ANDAMENTO') LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $db->query("SELECT status, created_at FROM data_subject_requests WHERE status NOT IN ('CONCLUIDO','RECUSADO') LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $db->query("SELECT severity FROM security_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $db->query("SELECT severity FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $check('dashboard_metric_queries_execute_on_current_schema', true);
} catch (Throwable $e) {
    $check('dashboard_metric_queries_execute_on_current_schema', false);
    echo '[INFO] dashboard_metric_query_error=' . $e->getMessage() . PHP_EOL;
}

$failed = array_filter($checks, static fn(bool $passed): bool => !$passed);
echo PHP_EOL . (count($checks) - count($failed)) . '/' . count($checks) . ' checks passed.' . PHP_EOL;
exit($failed === [] ? 0 : 1);
