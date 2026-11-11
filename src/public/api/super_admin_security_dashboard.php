<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/super_admin_permissions.php';
require_once APP_BASE . '/src/config/security_monitor.php';

header('Content-Type: application/json; charset=utf-8');

api_guard([
    'rate_key' => 'super_admin_security_dashboard',
    'rate_max' => 90,
    'rate_window' => 60,
    'skip_turno' => true,
    'skip_csrf' => true,
]);

if (!isset($_SESSION['super_admin']) || (int)$_SESSION['super_admin'] !== 1) {
    security_json(['success' => false, 'message' => 'Acesso negado.'], 403);
}

super_admin_require_permission_json('manage_security');

try {
    $db = (new Database())->getConnection();
    security_monitor_ensure_tables($db);

    $baseDir = dirname(APP_BASE, 1);
    $logDir = APP_BASE . '/storage/logs';
    $securityLog = $logDir . '/security.log';
    $alertLog = $logDir . '/security_alerts.log';

    try {
        security_monitor_ingest_log_file($db, $securityLog, 'security.log', 1500);
    } catch (Throwable $ingestErr) {
        security_log_event('security_dashboard_ingest_failed', [
            'source' => 'security.log',
            'error' => $ingestErr->getMessage(),
        ]);
    }
    try {
        security_monitor_ingest_log_file($db, $alertLog, 'security_alerts.log', 1500);
    } catch (Throwable $ingestErr) {
        security_log_event('security_dashboard_ingest_failed', [
            'source' => 'security_alerts.log',
            'error' => $ingestErr->getMessage(),
        ]);
    }

    $db->exec("
        UPDATE blocked_ips
           SET ativo = 0
         WHERE ativo = 1
           AND bloqueio_tipo = 'TEMPORARIO'
           AND bloqueado_ate IS NOT NULL
           AND bloqueado_ate < NOW()
    ");

    $windowHours = max(1, min(72, (int)($_GET['hours'] ?? 24)));

    $statsStmt = $db->prepare("
        SELECT
            COUNT(*) AS total_eventos,
            SUM(CASE WHEN severity = 'CRITICAL' THEN 1 ELSE 0 END) AS criticos,
            SUM(CASE WHEN severity = 'HIGH' THEN 1 ELSE 0 END) AS altos,
            SUM(CASE WHEN attack_type = 'SQL_INJECTION' THEN 1 ELSE 0 END) AS sqli,
            SUM(CASE WHEN attack_type = 'XSS' THEN 1 ELSE 0 END) AS xss,
            SUM(CASE WHEN attack_type = 'BRUTE_FORCE' THEN 1 ELSE 0 END) AS brute,
            SUM(CASE WHEN attack_type = 'BOT' THEN 1 ELSE 0 END) AS bots,
            SUM(CASE WHEN attack_type = 'PATH_TRAVERSAL' THEN 1 ELSE 0 END) AS traversal
        FROM security_events
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL :window_hours HOUR)
    ");
    $statsStmt->bindValue(':window_hours', $windowHours, PDO::PARAM_INT);
    $statsStmt->execute();
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $blockedStmt = $db->query("
        SELECT COUNT(*) AS total
        FROM blocked_ips
        WHERE ativo = 1
          AND (bloqueio_tipo = 'PERMANENTE' OR bloqueado_ate IS NULL OR bloqueado_ate >= NOW())
    ");
    $blockedActive = (int)($blockedStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

    $ipsStmt = $db->prepare("
        SELECT ip, COUNT(*) AS ataques
        FROM security_events
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL :window_hours HOUR)
          AND ip IS NOT NULL
          AND ip <> ''
        GROUP BY ip
        ORDER BY ataques DESC
        LIMIT 10
    ");
    $ipsStmt->bindValue(':window_hours', $windowHours, PDO::PARAM_INT);
    $ipsStmt->execute();
    $topIps = $ipsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $endpointsStmt = $db->prepare("
        SELECT endpoint, COUNT(*) AS ataques
        FROM security_events
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL :window_hours HOUR)
          AND endpoint IS NOT NULL
          AND endpoint <> ''
        GROUP BY endpoint
        ORDER BY ataques DESC
        LIMIT 10
    ");
    $endpointsStmt->bindValue(':window_hours', $windowHours, PDO::PARAM_INT);
    $endpointsStmt->execute();
    $topEndpoints = $endpointsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $timelineStmt = $db->prepare("
        SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') AS bucket, COUNT(*) AS total
        FROM security_events
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL :window_hours HOUR)
        GROUP BY bucket
        ORDER BY bucket ASC
    ");
    $timelineStmt->bindValue(':window_hours', $windowHours, PDO::PARAM_INT);
    $timelineStmt->execute();
    $timeline = $timelineStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $recentStmt = $db->query("
        SELECT id, event_name, attack_type, severity, ip, endpoint, method, payload_excerpt, source_log, created_at
        FROM security_events
        ORDER BY id DESC
        LIMIT 80
    ");
    $recent = $recentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $blockedListStmt = $db->query("
        SELECT ip, motivo, origem, bloqueio_tipo, bloqueado_ate, ativo, criado_em
        FROM blocked_ips
        WHERE ativo = 1
          AND (bloqueio_tipo = 'PERMANENTE' OR bloqueado_ate IS NULL OR bloqueado_ate >= NOW())
        ORDER BY criado_em DESC
        LIMIT 100
    ");
    $blockedList = $blockedListStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $riskScore = min(
        100,
        ((int)($stats['criticos'] ?? 0) * 10)
        + ((int)($stats['altos'] ?? 0) * 5)
        + ($blockedActive * 2)
    );

    $riskLevel = 'BAIXO';
    if ($riskScore >= 75) {
        $riskLevel = 'CRITICO';
    } elseif ($riskScore >= 45) {
        $riskLevel = 'ALTO';
    } elseif ($riskScore >= 20) {
        $riskLevel = 'MEDIO';
    }

    $expiredActiveBlocksStmt = $db->query("
        SELECT COUNT(*) AS total
        FROM blocked_ips
        WHERE ativo = 1
          AND bloqueio_tipo = 'TEMPORARIO'
          AND bloqueado_ate IS NOT NULL
          AND bloqueado_ate < NOW()
    ");
    $expiredActiveBlocks = (int)($expiredActiveBlocksStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

    $docsDir = $baseDir . '/docs/security';
    $govFile = $docsDir . '/security_governance.json';
    $checklist = [];
    $addCheck = static function (string $key, string $label, string $status, string $detail) use (&$checklist): void {
        $checklist[] = [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'detail' => $detail,
        ];
    };

    $securityLogExists = is_file($securityLog);
    $securityAlertsExists = is_file($alertLog);
    $docsWafExists = is_file($docsDir . '/WAF_CHECKLIST_PRODUCAO.md');
    $docsSiemExists = is_file($docsDir . '/SIEM_PARSER_LOGS.md');
    $docsIrDrExists = is_file($docsDir . '/PLAYBOOK_IR_DR.md');
    $docsWafEvidenceExists = is_file($docsDir . '/WAF_EVIDENCE.md');
    $docsPentestEvidenceExists = is_file($docsDir . '/PENTEST_EVIDENCE.md');
    $docsDrEvidenceExists = is_file($docsDir . '/DR_DRILL_EVIDENCE.md');
    $appEnv = strtolower(trim((string)(getenv('APP_ENV') ?: '')));
    $isProd = ($appEnv === 'production');
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
    );

    $addCheck(
        'logs_security',
        'Logs security.log disponivel',
        $securityLogExists ? 'ok' : 'critico',
        $securityLogExists ? 'Arquivo de seguranca encontrado para ingestao.' : 'Arquivo ausente: app/storage/logs/security.log'
    );
    $addCheck(
        'logs_alerts',
        'Logs security_alerts.log disponivel',
        $securityAlertsExists ? 'ok' : 'pendente',
        $securityAlertsExists ? 'Arquivo de alertas encontrado.' : 'Arquivo ainda nao gerado; criar alerta de teste para validar pipeline.'
    );
    $addCheck(
        'siem_ingest',
        'SIEM ingestao de eventos',
        ((int)($stats['total_eventos'] ?? 0) > 0) ? 'ok' : 'pendente',
        ((int)($stats['total_eventos'] ?? 0) > 0)
            ? 'Eventos ingeridos em security_events.'
            : 'Sem eventos ingeridos na janela selecionada.'
    );
    $addCheck(
        'block_expiry',
        'Expiracao automatica de bloqueios',
        $expiredActiveBlocks === 0 ? 'ok' : 'critico',
        $expiredActiveBlocks === 0
            ? 'Nenhum bloqueio temporario expirado ativo.'
            : 'Existem bloqueios temporarios expirados ainda ativos: ' . $expiredActiveBlocks
    );
    $addCheck(
        'app_env_production',
        'APP_ENV em production',
        $isProd ? 'ok' : 'critico',
        $isProd ? 'Ambiente em modo producao.' : 'APP_ENV atual diferente de production.'
    );
    $addCheck(
        'https_active',
        'HTTPS ativo na sessao atual',
        $isHttps ? 'ok' : 'pendente',
        $isHttps ? 'Conexao HTTPS detectada.' : 'Conexao atual sem HTTPS (normal em localhost).'
    );
    $addCheck(
        'waf_checklist_doc',
        'Checklist WAF de producao',
        $docsWafExists ? 'ok' : 'pendente',
        $docsWafExists ? 'Documento WAF_CHECKLIST_PRODUCAO.md encontrado.' : 'Documento WAF_CHECKLIST_PRODUCAO.md ausente.'
    );
    $addCheck(
        'siem_parser_doc',
        'Guia SIEM parser',
        $docsSiemExists ? 'ok' : 'pendente',
        $docsSiemExists ? 'Documento SIEM_PARSER_LOGS.md encontrado.' : 'Documento SIEM_PARSER_LOGS.md ausente.'
    );
    $addCheck(
        'ir_dr_playbook_doc',
        'Playbook IR/DR',
        $docsIrDrExists ? 'ok' : 'pendente',
        $docsIrDrExists ? 'Documento PLAYBOOK_IR_DR.md encontrado.' : 'Documento PLAYBOOK_IR_DR.md ausente.'
    );
    $addCheck(
        'critical_pressure',
        'Pressao de eventos criticos',
        ((int)($stats['criticos'] ?? 0) >= 15) ? 'critico' : (((int)($stats['criticos'] ?? 0) >= 5) ? 'pendente' : 'ok'),
        ((int)($stats['criticos'] ?? 0) >= 15)
            ? 'Volume critico elevado na janela atual.'
            : (((int)($stats['criticos'] ?? 0) >= 5)
                ? 'Volume critico moderado; reforcar monitoramento.'
                : 'Volume critico sob controle.')
    );
    $addCheck(
        'waf_evidence_doc',
        'Evidencia recorrente WAF',
        $docsWafEvidenceExists ? 'ok' : 'pendente',
        $docsWafEvidenceExists ? 'Documento WAF_EVIDENCE.md encontrado.' : 'Documento WAF_EVIDENCE.md ausente.'
    );
    $addCheck(
        'pentest_evidence_doc',
        'Pentest externo + reteste documentado',
        $docsPentestEvidenceExists ? 'ok' : 'pendente',
        $docsPentestEvidenceExists ? 'Documento PENTEST_EVIDENCE.md encontrado.' : 'Documento PENTEST_EVIDENCE.md ausente.'
    );
    $addCheck(
        'dr_evidence_doc',
        'Exercicios DR/IR com metricas',
        $docsDrEvidenceExists ? 'ok' : 'pendente',
        $docsDrEvidenceExists ? 'Documento DR_DRILL_EVIDENCE.md encontrado.' : 'Documento DR_DRILL_EVIDENCE.md ausente.'
    );

    $governance = [
        'loaded' => false,
        'owner' => null,
        'updated_at' => null,
        'items' => [],
    ];
    $readiness95 = [
        'score' => 0,
        'status' => 'PENDENTE',
        'pending_items' => [],
    ];

    if (is_file($govFile)) {
        $rawGov = file_get_contents($govFile);
        $gov = is_string($rawGov) ? json_decode($rawGov, true) : null;
        if (is_array($gov)) {
            $governance['loaded'] = true;
            $governance['owner'] = (string)($gov['owner'] ?? '');
            $governance['updated_at'] = (string)($gov['updated_at'] ?? '');

            $nowTs = time();
            $calcItem = static function (string $name, string $lastAt, int $cadenceDays, int $nowTs): array {
                $lastTs = strtotime($lastAt);
                if ($lastAt === '' || $lastTs === false) {
                    return [
                        'name' => $name,
                        'last_at' => $lastAt,
                        'cadence_days' => $cadenceDays,
                        'days_since' => null,
                        'overdue' => true,
                    ];
                }
                $daysSince = max(0, (int)floor(($nowTs - $lastTs) / 86400));
                return [
                    'name' => $name,
                    'last_at' => $lastAt,
                    'cadence_days' => $cadenceDays,
                    'days_since' => $daysSince,
                    'overdue' => $daysSince > $cadenceDays,
                ];
            };

            $items = [];
            $items[] = $calcItem('WAF Review', (string)($gov['waf']['last_review_at'] ?? ''), (int)($gov['waf']['cadence_days'] ?? 30), $nowTs);
            $items[] = $calcItem('Pentest Externo', (string)($gov['pentest']['last_test_at'] ?? ''), (int)($gov['pentest']['cadence_days'] ?? 90), $nowTs);
            $items[] = $calcItem('Reteste Pentest', (string)($gov['pentest']['last_retest_at'] ?? ''), (int)($gov['pentest']['cadence_days'] ?? 90), $nowTs);
            $items[] = $calcItem('DR Drill', (string)($gov['dr_ir']['last_dr_drill_at'] ?? ''), (int)($gov['dr_ir']['dr_cadence_days'] ?? 30), $nowTs);
            $items[] = $calcItem('IR Tabletop', (string)($gov['dr_ir']['last_ir_tabletop_at'] ?? ''), (int)($gov['dr_ir']['ir_cadence_days'] ?? 15), $nowTs);
            $governance['items'] = $items;

            $total = count($items);
            $okItems = 0;
            $pendingItems = [];
            foreach ($items as $it) {
                if (!empty($it['overdue'])) {
                    $pendingItems[] = $it['name'];
                } else {
                    $okItems++;
                }
            }
            $score = $total > 0 ? (int)round(($okItems / $total) * 100) : 0;
            $readiness95['score'] = $score;
            $readiness95['pending_items'] = $pendingItems;
            $readiness95['status'] = $score >= 95 ? 'PRONTO_95+' : ($score >= 80 ? 'PARCIAL' : 'CRITICO');
        }
    }

    $checkSummary = ['ok' => 0, 'pendente' => 0, 'critico' => 0];
    foreach ($checklist as $item) {
        $status = (string)($item['status'] ?? 'pendente');
        if (!isset($checkSummary[$status])) {
            $checkSummary[$status] = 0;
        }
        $checkSummary[$status]++;
    }

    $offlineSummaryStmt = $db->prepare("
        SELECT
            SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN status = 'DONE' THEN 1 ELSE 0 END) AS done_count,
            SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) AS failed_count,
            COUNT(*) AS total_count,
            COUNT(DISTINCT restaurante_id) AS restaurantes_com_fila,
            COUNT(DISTINCT device_id) AS dispositivos_com_fila
        FROM offline_sync_queue
        WHERE updated_at >= DATE_SUB(NOW(), INTERVAL :window_hours HOUR)
    ");
    $offlineSummaryStmt->bindValue(':window_hours', $windowHours, PDO::PARAM_INT);
    $offlineSummaryStmt->execute();
    $offlineSummary = $offlineSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    security_json([
        'success' => true,
        'data' => [
            'window_hours' => $windowHours,
            'stats' => [
                'total_eventos' => (int)($stats['total_eventos'] ?? 0),
                'criticos' => (int)($stats['criticos'] ?? 0),
                'altos' => (int)($stats['altos'] ?? 0),
                'sqli' => (int)($stats['sqli'] ?? 0),
                'xss' => (int)($stats['xss'] ?? 0),
                'brute' => (int)($stats['brute'] ?? 0),
                'bots' => (int)($stats['bots'] ?? 0),
                'traversal' => (int)($stats['traversal'] ?? 0),
                'ips_bloqueados_ativos' => $blockedActive,
                'risk_score' => $riskScore,
                'risk_level' => $riskLevel,
            ],
            'top_ips' => $topIps,
            'top_endpoints' => $topEndpoints,
            'timeline' => $timeline,
            'recent_events' => $recent,
            'blocked_ips' => $blockedList,
            'siem_ready' => [
                'security_log_path' => $securityLog,
                'security_alerts_path' => $alertLog,
            ],
            'production_checklist' => $checklist,
            'production_checklist_summary' => $checkSummary,
            'offline_queue_summary' => [
                'pending_count' => (int)($offlineSummary['pending_count'] ?? 0),
                'done_count' => (int)($offlineSummary['done_count'] ?? 0),
                'failed_count' => (int)($offlineSummary['failed_count'] ?? 0),
                'total_count' => (int)($offlineSummary['total_count'] ?? 0),
                'restaurantes_com_fila' => (int)($offlineSummary['restaurantes_com_fila'] ?? 0),
                'dispositivos_com_fila' => (int)($offlineSummary['dispositivos_com_fila'] ?? 0),
            ],
            'governance' => $governance,
            'readiness_95' => $readiness95,
            'current_client_ip' => security_get_client_ip(),
        ],
    ]);
} catch (Throwable $e) {
    security_json([
        'success' => false,
        'message' => security_exception_message(
            $e,
            'Falha ao carregar monitor de seguranca.',
            'security_dashboard_failed'
        ),
    ], 500);
}
