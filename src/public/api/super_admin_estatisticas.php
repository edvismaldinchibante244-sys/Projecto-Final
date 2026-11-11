<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Super Admin Estatisticas
 */

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/super_admin_permissions.php';
require_once APP_BASE . '/src/config/support_tickets.php';
require_once APP_BASE . '/src/config/privacy_requests.php';
require_once APP_BASE . '/src/config/security_monitor.php';
require_once APP_BASE . '/src/config/audit.php';

header('Content-Type: application/json');

api_guard([
    'rate_key' => 'super_admin_estatisticas',
    'rate_max' => 120,
    'rate_window' => 60,
    'skip_turno' => true,
    'skip_csrf' => true,
]);

if (!isset($_SESSION['super_admin']) || $_SESSION['super_admin'] != 1) {
    echo json_encode(["success" => false, "message" => "Acesso negado"]);
    exit;
}

super_admin_require_permission_json('view_dashboard');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $database = new Database();
    $db = $database->getConnection();

    if (!$db) {
        echo json_encode(["success" => false, "message" => "Erro de conexao"]);
        exit;
    }

    try {
        $stmt = $db->query("SELECT COUNT(*) as total FROM restaurantes");
        $totalRestaurantes = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        $stmt = $db->query("SELECT COUNT(*) as total FROM restaurantes WHERE criado_em < DATE_FORMAT(CURDATE(), '%Y-%m-01')");
        $totalRestaurantesMesAnterior = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        $stmt = $db->query("SELECT COUNT(*) as total FROM restaurantes WHERE status = 'ATIVO'");
        $restaurantesAtivos = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        $stmt = $db->query("SELECT COUNT(*) as total FROM restaurantes WHERE status = 'ATIVO' AND criado_em < DATE_FORMAT(CURDATE(), '%Y-%m-01')");
        $restaurantesAtivosMesAnterior = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        $stmt = $db->query("SELECT COUNT(*) as total FROM restaurantes WHERE status = 'BLOQUEADO' OR status = 'CANCELADO'");
        $restaurantesSuspensos = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        $stmt = $db->query("
            SELECT COUNT(*) AS total
            FROM restaurantes r
            WHERE r.status IN ('PENDENTE','AGUARDANDO_APROVACAO')
               OR EXISTS (
                    SELECT 1
                    FROM compras_planos cp
                    WHERE cp.restaurante_id = r.id
                      AND cp.status = 'PENDENTE'
               )
        ");
        $restaurantesEmAvaliacao = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        $stmt = $db->query("SELECT COUNT(*) as total FROM restaurantes WHERE status = 'ATIVO' AND data_fim BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
        $assinaturasExpirando = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        $stmt = $db->query("SELECT COUNT(*) as total FROM restaurantes WHERE status = 'ATIVO' AND data_fim BETWEEN DATE_SUB(CURDATE(), INTERVAL 1 MONTH) AND DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), INTERVAL 7 DAY)");
        $assinaturasExpirandoMesAnterior = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        $stmt = $db->query("SELECT COUNT(*) as total FROM restaurantes WHERE status = 'ATIVO' AND data_fim >= CURDATE() AND plano = 'BASICO'");
        $restaurantesEmTeste = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        $stmt = $db->query("
            SELECT
                SUM(CASE
                    WHEN plano = 'BASICO' THEN 799
                    WHEN plano = 'PROFISSIONAL' THEN 1999
                    WHEN plano IN ('ENTERPRISE', 'EMPRESARIAL') THEN 3999
                    ELSE 799
                END) as receita_mensal
            FROM restaurantes
            WHERE status = 'ATIVO'
        ");
        $receitaMensal = $stmt->fetch(PDO::FETCH_ASSOC)['receita_mensal'] ?? 0;
        $receitaAnualProjetada = (float)$receitaMensal * 12;

        $stmt = $db->query("SELECT COUNT(*) as total FROM usuarios WHERE ativo = 1");
        $utilizadoresAtivos = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $stmt = $db->query("
            SELECT
                SUM(CASE
                    WHEN plano_novo = 'BASICO' THEN 799
                    WHEN plano_novo = 'PROFISSIONAL' THEN 1999
                    WHEN plano_novo IN ('ENTERPRISE', 'EMPRESARIAL') THEN 3999
                    ELSE 799
                END) as receita_mensal
            FROM compras_planos
            WHERE status = 'APROVADO'
              AND criado_em >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
              AND criado_em < DATE_FORMAT(CURDATE(), '%Y-%m-01')
        ");
        $receitaMensalMesAnterior = $stmt->fetch(PDO::FETCH_ASSOC)['receita_mensal'] ?? 0;

        $supportSla = ['authorized' => super_admin_has_permission('manage_support'), 'no_prazo' => 0, 'em_risco' => 0, 'vencidos' => 0, 'abertos' => 0];
        if ($supportSla['authorized']) {
            support_tickets_ensure_tables($db);
            $supportRows = $db->query("SELECT prioridade, status, criado_em, respondido_em FROM support_tickets WHERE status IN ('ABERTO','EM_ANDAMENTO')")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $supportSla['abertos'] = count($supportRows);
            foreach ($supportRows as $supportRow) {
                $sla = support_tickets_sla_metrics($supportRow);
                if ($sla['state'] === 'VENCIDO') {
                    $supportSla['vencidos']++;
                } elseif ($sla['state'] === 'EM_RISCO') {
                    $supportSla['em_risco']++;
                } else {
                    $supportSla['no_prazo']++;
                }
            }
        }

        $privacyTiming = ['authorized' => super_admin_has_permission('manage_privacy'), 'proximos_prazo' => 0, 'vencidos' => 0, 'abertos' => 0];
        if ($privacyTiming['authorized'] && privacy_request_table_exists($db, 'data_subject_requests')) {
            $privacyRows = $db->query("SELECT status, created_at FROM data_subject_requests WHERE status NOT IN ('CONCLUIDO','RECUSADO')")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $privacyTiming['abertos'] = count($privacyRows);
            foreach ($privacyRows as $privacyRow) {
                $timing = privacy_request_operational_timing($privacyRow);
                if ($timing['state'] === 'VENCIDO') {
                    $privacyTiming['vencidos']++;
                } elseif ($timing['state'] === 'PROXIMO_PRAZO') {
                    $privacyTiming['proximos_prazo']++;
                }
            }
        }

        $securityAuthorized = super_admin_has_permission('manage_security');
        $securityCounts = ['criticos' => 0, 'altos' => 0];
        $blockedActive = 0;
        if ($securityAuthorized) {
            security_monitor_ensure_tables($db);
            $securityStmt = $db->query("
                SELECT
                    SUM(CASE WHEN severity = 'CRITICAL' THEN 1 ELSE 0 END) AS criticos,
                    SUM(CASE WHEN severity = 'HIGH' THEN 1 ELSE 0 END) AS altos
                FROM security_events
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $securityCounts = $securityStmt->fetch(PDO::FETCH_ASSOC) ?: $securityCounts;
            $blockedActive = (int)$db->query("SELECT COUNT(*) FROM blocked_ips WHERE ativo = 1 AND (bloqueado_ate IS NULL OR bloqueado_ate > NOW())")->fetchColumn();
        }
        $securityRiskScore = min(100,
            ((int)($securityCounts['criticos'] ?? 0) * 10)
            + ((int)($securityCounts['altos'] ?? 0) * 5)
            + ($blockedActive * 2)
        );
        $securityRiskLevel = $securityRiskScore >= 75 ? 'CRITICO' : ($securityRiskScore >= 45 ? 'ALTO' : ($securityRiskScore >= 20 ? 'MEDIO' : 'BAIXO'));
        $securityAlerts = (int)($securityCounts['criticos'] ?? 0) + (int)($securityCounts['altos'] ?? 0);

        $auditAlerts24h = 0;
        if ($securityAuthorized) {
            audit_ensure_table($db);
            $auditAlerts24h = (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE severity IN ('WARN','ERROR','CRITICAL') AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
        }

        $operationalHealthScore = 100;
        $operationalHealthScore -= min(35, (int)round($securityRiskScore * 0.35));
        $operationalHealthScore -= min(25, $supportSla['vencidos'] * 5);
        $operationalHealthScore -= min(20, $privacyTiming['vencidos'] * 5);
        $operationalHealthScore -= min(10, (int)$assinaturasExpirando * 2);
        $operationalHealthScore -= min(10, (int)$restaurantesSuspensos * 2);
        $operationalHealthScore = max(0, min(100, $operationalHealthScore));
        $operationalHealthLabel = $operationalHealthScore >= 85 ? 'SAUDAVEL' : ($operationalHealthScore >= 70 ? 'ATENCAO' : ($operationalHealthScore >= 50 ? 'RISCO' : 'CRITICO'));

        $calcDelta = function ($current, $previous) {
            $current = floatval($current);
            $previous = floatval($previous);
            if ($previous <= 0) {
                return $current > 0 ? 100.0 : 0.0;
            }
            return (($current - $previous) / $previous) * 100.0;
        };

        echo json_encode([
            "success" => true,
            "data" => [
                "total_restaurantes" => intval($totalRestaurantes),
                "restaurantes_ativos" => intval($restaurantesAtivos),
                "restaurantes_suspensos" => intval($restaurantesSuspensos),
                "restaurantes_em_avaliacao" => intval($restaurantesEmAvaliacao),
                "assinaturas_expirando" => intval($assinaturasExpirando),
                "restaurantes_em_teste" => intval($restaurantesEmTeste),
                "receita_mensal" => floatval($receitaMensal),
                "receita_anual_projetada" => floatval($receitaAnualProjetada),
                "utilizadores_ativos" => $utilizadoresAtivos,
                "suporte_sla" => $supportSla,
                "privacidade_prazos" => $privacyTiming + [
                    'prazo_dias' => privacy_request_operational_deadline_days(),
                    'alerta_dias' => privacy_request_operational_warning_days(),
                ],
                "seguranca" => [
                    'authorized' => $securityAuthorized,
                    'alertas_24h' => $securityAlerts,
                    'criticos_24h' => (int)($securityCounts['criticos'] ?? 0),
                    'altos_24h' => (int)($securityCounts['altos'] ?? 0),
                    'ips_bloqueados' => $blockedActive,
                    'risk_score' => $securityRiskScore,
                    'risk_level' => $securityRiskLevel,
                ],
                "auditoria_alertas_24h" => $auditAlerts24h,
                "saude_operacional" => [
                    'score' => $operationalHealthScore,
                    'label' => $operationalHealthLabel,
                    'metodologia' => 'Seguranca 35%, SLA 25%, privacidade 20%, vencimentos 10%, suspensoes 10%',
                ],
                "comparativos" => [
                    "total_restaurantes" => [
                        "atual" => intval($totalRestaurantes),
                        "anterior" => intval($totalRestaurantesMesAnterior),
                        "variacao_percentual" => round($calcDelta($totalRestaurantes, $totalRestaurantesMesAnterior), 2)
                    ],
                    "restaurantes_ativos" => [
                        "atual" => intval($restaurantesAtivos),
                        "anterior" => intval($restaurantesAtivosMesAnterior),
                        "variacao_percentual" => round($calcDelta($restaurantesAtivos, $restaurantesAtivosMesAnterior), 2)
                    ],
                    "receita_mensal" => [
                        "atual" => floatval($receitaMensal),
                        "anterior" => floatval($receitaMensalMesAnterior),
                        "variacao_percentual" => round($calcDelta($receitaMensal, $receitaMensalMesAnterior), 2)
                    ],
                    "assinaturas_expirando" => [
                        "atual" => intval($assinaturasExpirando),
                        "anterior" => intval($assinaturasExpirandoMesAnterior),
                        "variacao_percentual" => round($calcDelta($assinaturasExpirando, $assinaturasExpirandoMesAnterior), 2)
                    ]
                ]
            ]
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => security_exception_message(
                $e,
                'Erro ao carregar estatisticas.',
                'super_admin_estatisticas_failed'
            ),
        ]);
    }
} else {
    echo json_encode(["success" => false, "message" => "Metodo nao permitido"]);
}
