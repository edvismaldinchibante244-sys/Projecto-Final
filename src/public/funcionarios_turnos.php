<?php
require_once __DIR__ . '/app_base.php';

/*
 Gestão de Horários - Funcionários Turnos
 ADMIN apenas - PREMIUM UI
 */
include_once APP_BASE . '/src/config/auth_check.php';
include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/restaurante_context.php';
include_once APP_BASE . '/src/config/csrf.php';
include_once APP_BASE . '/src/config/plano_check.php';
include_once APP_BASE . '/src/Model/Turno.php';
include_once APP_BASE . '/src/Service/TurnoService.php';

requirePermissionOrRedirect(['ADMIN', 'GERENTE']);

// Verificar se plano permite funcionalidade GESTÃO DE FUNCIONÁRIOS (plano da matriz)
$ctxId = session_restaurante_contexto_id();
$planRidFunc = session_restaurante_capability_id();
if ($planRidFunc <= 0) {
    $planRidFunc = $ctxId;
}
plano_verificar_funcionalidade($planRidFunc, 'gestao_funcionarios');

$database = new Database();
$db = $database->getConnection();
$turnoService = new TurnoService($database);
$restauranteId = session_restaurante_contexto_id();
$ativos = $turnoService->ativosHojeArray($restauranteId);
$metricas = $turnoService->obterMetricasDashboard($restauranteId);
$auditoria = $turnoService->listarAuditoria($restauranteId, 12);

$stmt_usuarios = $db->prepare("SELECT id, nome, perfil FROM usuarios WHERE restaurante_id = :rid AND ativo = 1 ORDER BY nome");
$stmt_usuarios->execute(['rid' => $restauranteId]);
$usuarios = $stmt_usuarios->fetchAll(PDO::FETCH_ASSOC);
$csrfToken = csrf_get_token();

$planRidTurnos = function_exists('session_restaurante_capability_id')
    ? (int)session_restaurante_capability_id()
    : (int)$restauranteId;
$planRidTurnos = $planRidTurnos > 0 ? $planRidTurnos : (int)$restauranteId;
$dados_plano = plano_get_dados($planRidTurnos);
$plano_atual = plano_normalizar_nome($dados_plano['plano_nome'] ?? 'BASICO');
require_once __DIR__ . '/includes/empresarial_ui.php';


$turnosEmpEscala = null;
$turnosEmpHoras = [];
$turnosEmpVendas = [];
if ($plano_atual === 'EMPRESARIAL' && $db instanceof PDO) {
    $nomesDia = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
    $semanaOffset = max(-8, min(8, (int)($_GET['semana'] ?? 0)));
    $inicioSemana = new DateTime('monday this week');
    if ($semanaOffset !== 0) {
        $inicioSemana->modify(($semanaOffset > 0 ? '+' : '') . $semanaOffset . ' week');
    }
    $diasSemana = [];
    for ($i = 0; $i < 7; $i++) {
        $d = (clone $inicioSemana)->modify('+' . $i . ' day');
        $diasSemana[] = ['iso' => $d->format('Y-m-d'), 'rot' => $nomesDia[$i] . ' ' . $d->format('d/m')];
    }
    $fimSemanaIso = $diasSemana[6]['iso'];
    $inicioMesIso = date('Y-m-01');
    $hojeIso = date('Y-m-d');
    $duracaoSql = "GREATEST(0, TIMESTAMPDIFF(MINUTE,
            TIMESTAMP(t.data, t.hora_entrada),
            CASE
                WHEN UPPER(t.status) = 'ATIVO' AND t.hora_saida IS NULL THEN NOW()
                WHEN t.hora_saida IS NULL THEN TIMESTAMP(t.data, t.hora_entrada)
                WHEN t.data_saida IS NOT NULL THEN TIMESTAMP(t.data_saida, t.hora_saida)
                WHEN t.hora_saida < t.hora_entrada THEN TIMESTAMP(DATE_ADD(t.data, INTERVAL 1 DAY), t.hora_saida)
                ELSE TIMESTAMP(t.data, t.hora_saida)
            END))";
    try {
        $stEsc = $db->prepare("
            SELECT t.usuario_id, t.data, t.turno, t.status, u.nome, u.perfil
            FROM funcionarios_turnos t
            JOIN usuarios u ON u.id = t.usuario_id
            WHERE t.restaurante_id = :rid AND t.data BETWEEN :ini AND :fim
            ORDER BY u.nome, t.data
        ");
        $stEsc->execute([':rid' => (int)$restauranteId, ':ini' => $diasSemana[0]['iso'], ':fim' => $fimSemanaIso]);
        $linhasEsc = [];
        $porDia = array_fill_keys(array_column($diasSemana, 'iso'), 0);
        foreach ($stEsc->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $uid = (int)$r['usuario_id'];
            if (!isset($linhasEsc[$uid])) {
                $linhasEsc[$uid] = ['nome' => (string)$r['nome'], 'perfil' => turno_normalizar_perfil((string)$r['perfil']), 'dias' => []];
            }
            $stEscStatus = strtolower((string)$r['status']);
            $linhasEsc[$uid]['dias'][$r['data']][] = ['turno' => strtolower((string)$r['turno']), 'status' => $stEscStatus];
            if ($stEscStatus !== 'ausente' && isset($porDia[$r['data']])) {
                $porDia[$r['data']]++;
            }
        }
        $mediaDia = count($linhasEsc) > 0 ? array_sum($porDia) / 7 : 0;
        $turnosEmpEscala = [
            'dias' => $diasSemana,
            'linhas' => $linhasEsc,
            'por_dia' => $porDia,
            'media' => $mediaDia,
            'offset' => $semanaOffset,
            'hoje' => $hojeIso,
        ];

        $stH = $db->prepare("
            SELECT t.usuario_id, u.nome, u.perfil,
                   SUM(CASE WHEN t.data >= :ini_sem THEN {$duracaoSql} ELSE 0 END) AS min_semana,
                   SUM({$duracaoSql}) AS min_mes,
                   SUM(GREATEST(0, {$duracaoSql} - 480)) AS min_extra,
                   SUM(CASE WHEN t.hora_entrada IS NOT NULL AND t.hora_entrada > ADDTIME(CASE WHEN LOWER(t.turno) = 'noite' THEN '18:00:00' ELSE '08:00:00' END, '00:10:00') THEN 1 ELSE 0 END) AS atrasos,
                   SUM(CASE WHEN UPPER(t.status) = 'AUSENTE' THEN 1 ELSE 0 END) AS faltas,
                   COUNT(*) AS turnos
            FROM funcionarios_turnos t
            JOIN usuarios u ON u.id = t.usuario_id
            WHERE t.restaurante_id = :rid AND t.data BETWEEN :ini_mes AND :hoje
              AND t.hora_entrada IS NOT NULL
            GROUP BY t.usuario_id, u.nome, u.perfil
            ORDER BY min_mes DESC
        ");
        $stH->execute([':rid' => (int)$restauranteId, ':ini_sem' => (new DateTime('monday this week'))->format('Y-m-d'), ':ini_mes' => $inicioMesIso, ':hoje' => $hojeIso]);
        $turnosEmpHoras = $stH->fetchAll(PDO::FETCH_ASSOC);

        $stV = $db->prepare("
            SELECT u.id, u.nome, u.perfil,
                   COALESCE(v.qtd, 0) AS vendas_qtd, COALESCE(v.total, 0) AS vendas_total,
                   COALESCE(p.qtd, 0) AS pedidos_qtd
            FROM usuarios u
            LEFT JOIN (
                SELECT usuario_id, COUNT(*) AS qtd, SUM(total_final) AS total
                FROM vendas
                WHERE restaurante_id = :rid1 AND status = 'PAGO' AND criado_em >= :ini1
                GROUP BY usuario_id
            ) v ON v.usuario_id = u.id
            LEFT JOIN (
                SELECT garcom_id, COUNT(*) AS qtd
                FROM pedidos
                WHERE restaurante_id = :rid2 AND criado_em >= :ini2 AND UPPER(status) <> 'CANCELADO'
                GROUP BY garcom_id
            ) p ON p.garcom_id = u.id
            WHERE u.restaurante_id = :rid3 AND u.ativo = 1 AND (v.qtd IS NOT NULL OR p.qtd IS NOT NULL)
            ORDER BY vendas_total DESC, pedidos_qtd DESC
        ");
        $stV->execute([':rid1' => (int)$restauranteId, ':ini1' => $inicioMesIso . ' 00:00:00', ':rid2' => (int)$restauranteId, ':ini2' => $inicioMesIso . ' 00:00:00', ':rid3' => (int)$restauranteId]);
        $turnosEmpVendas = $stV->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[funcionarios_turnos] painel empresarial: ' . $e->getMessage());
    }
}

// Profissional: horas trabalhadas dos ultimos 7 dias (versao simples, sem extras nem faltas)
$turnosProHoras = [];
if ($plano_atual === 'PROFISSIONAL' && $db instanceof PDO) {
    try {
        $duracaoProSql = "GREATEST(0, TIMESTAMPDIFF(MINUTE,
            TIMESTAMP(t.data, t.hora_entrada),
            CASE
                WHEN UPPER(t.status) = 'ATIVO' AND t.hora_saida IS NULL THEN NOW()
                WHEN t.hora_saida IS NULL THEN TIMESTAMP(t.data, t.hora_entrada)
                WHEN t.data_saida IS NOT NULL THEN TIMESTAMP(t.data_saida, t.hora_saida)
                WHEN t.hora_saida < t.hora_entrada THEN TIMESTAMP(DATE_ADD(t.data, INTERVAL 1 DAY), t.hora_saida)
                ELSE TIMESTAMP(t.data, t.hora_saida)
            END))";
        $stProH = $db->prepare("
            SELECT u.nome, u.perfil,
                   SUM({$duracaoProSql}) AS min_total,
                   COUNT(*) AS turnos
            FROM funcionarios_turnos t
            JOIN usuarios u ON u.id = t.usuario_id
            WHERE t.restaurante_id = :rid
              AND t.data >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
              AND t.data <= CURDATE()
              AND t.hora_entrada IS NOT NULL
            GROUP BY t.usuario_id, u.nome, u.perfil
            ORDER BY min_total DESC
            LIMIT 8
        ");
        $stProH->execute([':rid' => (int)$restauranteId]);
        $turnosProHoras = $stProH->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $eProH) {
        error_log('[TURNOS][PRO_HORAS] ' . $eProH->getMessage());
    }
}

$turnosEmpIntel = null;
if ($plano_atual === 'EMPRESARIAL') {
    $naoEncerrados = (int)($metricas['turnos_nao_encerrados'] ?? 0);
    $ativosCount = (int)($metricas['funcionarios_ativos'] ?? count($ativos));
    $atrasos = (int)($metricas['atrasos'] ?? 0);
    $online = (int)($metricas['online'] ?? 0);
    $offline = (int)($metricas['offline'] ?? 0);

    $maisLongoNome = '—';
    $maisLongoMin = 0;
    $maisLongoFmt = '—';
    $tiposAtivos = ['MANHA' => 0, 'NOITE' => 0, 'INTEGRAL' => 0];
    foreach ($ativos as $ativoEmp) {
        $mins = (int)($ativoEmp['duracao_minutos'] ?? 0);
        if ($mins > $maisLongoMin) {
            $maisLongoMin = $mins;
            $maisLongoNome = trim((string)($ativoEmp['funcionario_nome'] ?? $ativoEmp['nome'] ?? 'Funcionário'));
            $maisLongoFmt = (string)($ativoEmp['duracao_formatada'] ?? ($mins . ' min'));
        }
        $tipoKey = strtoupper(trim((string)($ativoEmp['turno'] ?? 'MANHA')));
        if ($tipoKey === 'MANHÃ' || $tipoKey === 'MANHA') {
            $tiposAtivos['MANHA']++;
        } elseif ($tipoKey === 'NOITE') {
            $tiposAtivos['NOITE']++;
        } else {
            $tiposAtivos['INTEGRAL']++;
        }
    }

    $horaPicoEntrada = '—';
    $horaPicoQtd = 0;
    if ($db instanceof PDO) {
        try {
            $stmtPico = $db->prepare("
                SELECT LPAD(HOUR(hora_entrada), 2, '0') AS h, COUNT(*) AS qtd
                FROM funcionarios_turnos
                WHERE restaurante_id = :rid
                  AND data >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                  AND hora_entrada IS NOT NULL
                GROUP BY HOUR(hora_entrada)
                ORDER BY qtd DESC
                LIMIT 1
            ");
            $stmtPico->execute([':rid' => (int)$restauranteId]);
            $picoRow = $stmtPico->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($picoRow) {
                $horaPicoEntrada = ((string)($picoRow['h'] ?? '—')) . ':00';
                $horaPicoQtd = (int)($picoRow['qtd'] ?? 0);
            }
        } catch (Throwable $ePico) {
            error_log('[TURNOS][EMP_PICO] ' . $ePico->getMessage());
        }
    }

    $notasTurno = [];
    if ($naoEncerrados > 0) {
        $notasTurno[] = [
            'tom' => 'warn',
            'titulo' => $naoEncerrados . ' turno(s) de dias anteriores ainda abertos',
            'txt' => 'Encerre manualmente com motivo — distorce presença, tempo em turno e auditoria da unidade.',
        ];
    }
    if ($maisLongoMin >= 600) {
        $notasTurno[] = [
            'tom' => 'warn',
            'titulo' => $maisLongoNome . ' leva ' . $maisLongoFmt . ' em turno',
            'txt' => 'Passou de 10h. Confirme se a saída foi esquecida ou se há hora extra autorizada.',
        ];
    } elseif ($maisLongoMin >= 480) {
        $notasTurno[] = [
            'tom' => 'info',
            'titulo' => $maisLongoNome . ' já leva ' . $maisLongoFmt,
            'txt' => 'Turno longo. Planeie o encerramento e a passagem de serviço.',
        ];
    }
    if ($atrasos > 0) {
        $notasTurno[] = [
            'tom' => 'info',
            'titulo' => $atrasos . ' entrada(s) depois da hora prevista',
            'txt' => 'Compare com o pico do salão. Atrasos no início de turno atrasam abertura de mesas e cozinha.',
        ];
    }
    if ($horaPicoEntrada !== '—' && $horaPicoQtd > 0) {
        $notasTurno[] = [
            'tom' => 'info',
            'titulo' => 'Entradas concentram-se às ' . $horaPicoEntrada,
            'txt' => 'Nos últimos 30 dias: ' . $horaPicoQtd . ' entrada(s) nesse horário. Prepare briefing e caixa 15 minutos antes.',
        ];
    }
    $horaAgora = (int)date('G');
    if ($ativosCount > 0 && $tiposAtivos['NOITE'] === 0 && $tiposAtivos['INTEGRAL'] === 0 && $horaAgora >= 17) {
        $notasTurno[] = [
            'tom' => 'warn',
            'titulo' => 'Sem cobertura de noite activa',
            'txt' => 'Só há turno de manhã. Se o salão abre à noite, adicione ou confirme o turno nocturno.',
        ];
    }
    if ($online === 0 && $ativosCount > 0) {
        $notasTurno[] = [
            'tom' => 'info',
            'titulo' => 'Turno aberto, mas ninguém online',
            'txt' => 'Há ' . $ativosCount . ' em turno e 0 online. Peça ping/login na app ou verifique Wi‑Fi do salão.',
        ];
    }
    if (empty($notasTurno)) {
        $notasTurno[] = [
            'tom' => 'ok',
            'titulo' => 'Equipa em turno sob controlo',
            'txt' => 'Sem alertas fortes. Continue a encerrar turnos no fim do serviço e a confirmar presença.',
        ];
    }

    $turnosEmpIntel = [
        'ativos' => $ativosCount,
        'nao_encerrados' => $naoEncerrados,
        'hora_pico' => $horaPicoEntrada,
        'mais_longo' => $maisLongoMin > 0 ? $maisLongoFmt : '—',
        'online' => $online,
        'offline' => $offline,
        'tempo_total' => (string)($metricas['tempo_turno_formatado'] ?? '0 min'),
        'notas' => array_slice($notasTurno, 0, 3),
    ];
}
?>
<!DOCTYPE html>
<html lang="pt" class="premium-ui">

<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <script src="js/botao_atualizar.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/botao_atualizar.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestão de Turnos - Restaurante SaaS</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link href="lib/bootstrap-5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="lib/fontawesome-6.5.1/css/all.min.css">
    <link href="css/premium.css" rel="stylesheet">
    <?php empresarial_head_assets(); ?>
    <?php if (!empty($isEmpresarialUi)): ?>
        <link rel="stylesheet" href="css/turnos-empresarial.css?v=20260725ai">
    <?php endif; ?>
    <style>
        :root {
            --primary: #FF6B35;
            --secondary: #F7931E;
            --dark: #0f0f23;
            --dark-2: #1a1a2e;
            --dark-3: #16213e;
            --text: #1e293b;
            --text-muted: #94a3b8;
            --border: #e2e8f0;
            --bg: #f8fafc;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        html,
        body {
            overflow-x: hidden;
        }

        body.premium-ui {
            font-family: 'Outfit', 'Segoe UI', 'Noto Sans', system-ui, -apple-system, 'Helvetica Neue', Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
        }

        .sidebar {
            width: 280px;
            min-height: 100vh;
            background: linear-gradient(180deg, var(--dark) 0%, var(--dark-2) 50%, var(--dark-3) 100%);
            position: fixed;
            left: 0;
            top: 0;
            z-index: 1000;
            padding: 0;
            display: flex;
            flex-direction: column;
        }

        .sidebar-brand {
            padding: 28px 24px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            background: linear-gradient(135deg, rgba(255, 107, 53, 0.15), rgba(247, 147, 30, 0.05));
        }

        .sidebar-brand h2 {
            color: white;
            font-family: 'Space Grotesk', 'Segoe UI Semibold', 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
            font-size: 20px;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-brand span {
            display: block;
            color: var(--text-muted);
            font-size: 11px;
            margin-top: 4px;
            margin-left: 36px;
            letter-spacing: 0.5px;
        }

        .sidebar-menu {
            flex: 1;
            padding: 20px 12px;
            overflow-y: auto;
        }

        .menu-title {
            color: var(--text-muted);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            padding: 12px 16px 8px;
            font-weight: 600;
        }

        .menu-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 18px;
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            border-radius: 12px;
            margin-bottom: 4px;
            transition: all 0.3s;
            cursor: pointer;
            font-weight: 500;
            font-size: 14px;
        }

        .menu-item:hover {
            background: rgba(255, 255, 255, 0.08);
            color: white;
            transform: translateX(4px);
        }

        .menu-item.active {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            box-shadow: 0 4px 15px rgba(255, 107, 53, 0.4);
        }

        .menu-item i {
            width: 20px;
            text-align: center;
            font-size: 16px;
        }

        .sidebar-footer {
            padding: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
        }

        .user-avatar {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 16px;
        }

        .user-name {
            color: white;
            font-weight: 600;
            font-size: 14px;
        }

        .user-role {
            color: var(--text-muted);
            font-size: 12px;
        }

        .main-content {
            margin-left: 280px;
            padding: 24px;
            min-height: 100vh;
            background: var(--bg);
        }

        .top-bar {
            background: white;
            padding: 24px;
            border-radius: 20px;
            margin-bottom: 24px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card {
            border: 1px solid var(--border);
            border-radius: 20px;
            box-shadow: var(--shadow);
            overflow: hidden;
            background: #fff;
        }

        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #FF6B35, #F7931E);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
        }

        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
        }

        .btn {
            border-radius: 12px;
            font-weight: 600;
        }

        .turnos-topbar-main {
            min-width: 0;
        }

        @media (max-width: 992px) {
            .sidebar {
                width: 100%;
                position: relative;
                min-height: auto;
            }

            .main-content {
                margin-left: 0;
            }

            .top-bar {
                flex-direction: column;
                align-items: flex-start;
                gap: 14px;
            }

            .top-bar-right,
            .top-bar .btn {
                width: 100%;
            }
        }
        .te-emp-bloco { background:#fff; border:1px solid #e2e8f0; border-radius:14px; margin-bottom:18px; overflow:hidden; }
        .te-emp-head { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; padding:14px 18px; border-bottom:1px solid #f1f5f9; }
        .te-emp-head strong { color:#0f172a; }
        .te-emp-head span { display:block; font-size:12px; color:#64748b; margin-top:2px; }
        .te-emp-nav { display:flex; gap:6px; }
        .te-emp-vazio { padding:18px; color:#64748b; font-size:14px; }
        .te-emp-nota { display:block; padding:10px 18px 14px; color:#64748b; font-size:12px; }
        .te-emp-duplo { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
        .te-emp-duplo .te-emp-bloco { margin-bottom:18px; }
        @media (max-width: 1100px) { .te-emp-duplo { grid-template-columns:1fr; } }
        .te-escala th, .te-escala td { font-size:13px; vertical-align:middle; padding:8px 10px; white-space:nowrap; }
        .te-escala thead th { background:#f8fafc; color:#475569; font-size:12px; text-transform:uppercase; letter-spacing:.03em; }
        .te-escala .is-hoje { background:#fff7ed; }
        .te-escala tfoot th { background:#f8fafc; color:#0f172a; }
        .te-escala tfoot th.te-escala-falta { background:#fee2e2; color:#b91c1c; }
        .te-escala-pill { display:inline-block; font-size:11px; font-weight:700; padding:2px 8px; border-radius:999px; margin:1px 2px; background:#e2e8f0; color:#334155; }
        .te-escala-pill.t-manha { background:#fef3c7; color:#92400e; }
        .te-escala-pill.t-noite { background:#e0e7ff; color:#3730a3; }
        .te-escala-pill.t-integral { background:#dcfce7; color:#166534; }
        .te-escala-pill.s-ausente { background:#fee2e2; color:#b91c1c; text-decoration:line-through; }
        .te-escala-pill.s-ativo { box-shadow:0 0 0 2px #22c55e inset; }
        .te-mini th { font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#64748b; background:#f8fafc; }
        .te-mini th, .te-mini td { padding:8px 14px; font-size:13px; }
        tr.te-row-esquecido td { background:#fef2f2 !important; }
        .te-table-tools { display:flex; flex-wrap:wrap; align-items:center; gap:4px; }
    </style>
</head>

<body class="<?php echo htmlspecialchars(empresarial_body_class('premium-ui' . (!empty($isEmpresarialUi) ? ' turnos-emp-shell' : ''))); ?>">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content">
        <?php if (!empty($isEmpresarialUi) && is_array($turnosEmpIntel)): ?>
        <div class="te-hero">
            <div>
                <h1>
                    <i class="fas fa-user-clock"></i>
                    Gestão de Turnos
                    <?php include __DIR__ . '/includes/plan_page_badge.php'; ?>
                </h1>
                <p>Presença da unidade com alertas: turnos esquecidos, horas longas e pico de entrada da equipa.</p>
            </div>
            <div class="te-hero-actions">
                <button type="button" class="te-btn te-btn-light" onclick="carregarTurnos()" data-atualizar>
                    <i class="fas fa-sync-alt"></i>Atualizar
                </button>
                <a href="#formNovoTurno" class="te-btn te-btn-ghost"><i class="fas fa-plus"></i>Novo turno</a>
            </div>
        </div>

        <div class="te-kpis">
            <div class="te-kpi">
                <span class="te-kpi-label">Funcionários ativos</span>
                <span class="te-kpi-value" id="metricaAtivos"><?php echo (int)$turnosEmpIntel['ativos']; ?></span>
            </div>
            <div class="te-kpi<?php echo ((int)$turnosEmpIntel['nao_encerrados'] > 0) ? ' is-warn' : ''; ?>">
                <span class="te-kpi-label">Turnos não encerrados</span>
                <span class="te-kpi-value" id="metricaNaoEncerrados"><?php echo (int)$turnosEmpIntel['nao_encerrados']; ?></span>
            </div>
            <div class="te-kpi">
                <span class="te-kpi-label">Tempo total em turno</span>
                <span class="te-kpi-value" id="metricaTempoTurno"><?php echo htmlspecialchars((string)$turnosEmpIntel['tempo_total']); ?></span>
            </div>
            <div class="te-kpi">
                <span class="te-kpi-label">Online / Offline</span>
                <span class="te-kpi-value"><span id="metricaOnline"><?php echo (int)$turnosEmpIntel['online']; ?></span> / <span id="metricaOffline"><?php echo (int)$turnosEmpIntel['offline']; ?></span></span>
            </div>
        </div>

        <div class="te-intel">
            <div class="te-intel-head">
                <strong><i class="fas fa-brain me-2"></i>Leitura da equipa</strong>
                <span>Hoje + padrões dos últimos 30 dias</span>
            </div>
            <div class="te-intel-kpis">
                <div><span>Em turno</span><strong><?php echo (int)$turnosEmpIntel['ativos']; ?></strong></div>
                <div><span>Não encerrados</span><strong><?php echo (int)$turnosEmpIntel['nao_encerrados']; ?></strong></div>
                <div><span>Pico de entrada</span><strong><?php echo htmlspecialchars((string)$turnosEmpIntel['hora_pico']); ?></strong></div>
                <div><span>Mais longo</span><strong><?php echo htmlspecialchars((string)$turnosEmpIntel['mais_longo']); ?></strong></div>
            </div>
            <?php if (!empty($turnosEmpIntel['notas'])): ?>
            <ul class="te-intel-notes">
                <?php foreach ($turnosEmpIntel['notas'] as $tn): ?>
                    <li class="tom-<?php echo htmlspecialchars((string)($tn['tom'] ?? 'info')); ?>">
                        <strong><?php echo htmlspecialchars((string)($tn['titulo'] ?? '')); ?></strong>
                        <span><?php echo htmlspecialchars((string)($tn['txt'] ?? '')); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <!-- Top Bar -->
        <div class="top-bar">
            <div class="sidebar-topbar-main turnos-topbar-main">
                <h1 class="mb-1"><i class="fas fa-clock text-warning me-2"></i>Gestão de Turnos <?php include __DIR__ . '/includes/plan_page_badge.php'; ?></h1>
                <p class="text-muted mb-0">Planeje manhã/noite e veja quem está ativo na unidade.</p>
            </div>
            <div class="top-bar-right">
                <button type="button" class="btn btn-primary" onclick="carregarTurnos()" data-atualizar>
                    <i class="fas fa-sync-alt me-1"></i>Atualizar
                </button>
            </div>
        </div>
        <?php endif; ?>

<?php
if (!function_exists('turnos_fmt_min')) {
    function turnos_fmt_min($m): string { $m = (int)$m; return intdiv($m, 60) . 'h' . str_pad((string)($m % 60), 2, '0', STR_PAD_LEFT); }
}
$turnoRotulos = ['manha' => 'Manhã', 'noite' => 'Noite', 'integral' => 'Integral'];
$perfilRotulos = ['ADMIN' => 'Admin', 'GERENTE' => 'Gerente', 'CAIXA' => 'Caixa', 'GARCOM' => 'Garçom', 'COZINHA' => 'Cozinha', 'BAR' => 'Bar'];
?>
        <?php if (!empty($isEmpresarialUi) && is_array($turnosEmpEscala)): ?>
        <section class="te-emp-bloco" id="teEscala">
            <div class="te-emp-head">
                <div>
                    <strong><i class="fas fa-calendar-week me-2"></i>Escala da semana</strong>
                    <span><?php echo htmlspecialchars($turnosEmpEscala['dias'][0]['rot'] . ' a ' . $turnosEmpEscala['dias'][6]['rot']); ?></span>
                </div>
                <div class="te-emp-nav">
                    <a class="btn btn-sm btn-outline-secondary" href="?semana=<?php echo (int)$turnosEmpEscala['offset'] - 1; ?>#teEscala"><i class="fas fa-chevron-left"></i></a>
                    <a class="btn btn-sm btn-outline-secondary" href="?semana=0#teEscala">Esta semana</a>
                    <a class="btn btn-sm btn-outline-secondary" href="?semana=<?php echo (int)$turnosEmpEscala['offset'] + 1; ?>#teEscala"><i class="fas fa-chevron-right"></i></a>
                </div>
            </div>
            <?php if (empty($turnosEmpEscala['linhas'])): ?>
                <div class="te-emp-vazio">Nenhum turno escalado nesta semana. Use "Adicionar novo turno" para planear.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table te-escala mb-0">
                    <thead>
                        <tr>
                            <th>Funcionário</th>
                            <?php foreach ($turnosEmpEscala['dias'] as $dia): ?>
                                <th class="<?php echo $dia['iso'] === $turnosEmpEscala['hoje'] ? 'is-hoje' : ''; ?>"><?php echo htmlspecialchars($dia['rot']); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($turnosEmpEscala['linhas'] as $linha): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($linha['nome']); ?></strong><br><small class="text-muted"><?php echo htmlspecialchars($perfilRotulos[$linha['perfil']] ?? $linha['perfil']); ?></small></td>
                                <?php foreach ($turnosEmpEscala['dias'] as $dia): ?>
                                    <td class="<?php echo $dia['iso'] === $turnosEmpEscala['hoje'] ? 'is-hoje' : ''; ?>">
                                        <?php foreach (($linha['dias'][$dia['iso']] ?? []) as $tt): ?>
                                            <span class="te-escala-pill t-<?php echo htmlspecialchars($tt['turno']); ?> s-<?php echo htmlspecialchars($tt['status']); ?>"><?php echo htmlspecialchars($turnoRotulos[$tt['turno']] ?? $tt['turno']); ?><?php echo $tt['status'] === 'ausente' ? ' · falta' : ''; ?></span>
                                        <?php endforeach; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th>Pessoas no dia</th>
                            <?php foreach ($turnosEmpEscala['dias'] as $dia):
                                $qtdDia = (int)$turnosEmpEscala['por_dia'][$dia['iso']];
                                $falta = $qtdDia === 0 || ($turnosEmpEscala['media'] > 0 && $qtdDia < $turnosEmpEscala['media'] * 0.6);
                            ?>
                                <th class="<?php echo $falta ? 'te-escala-falta' : ''; ?>" title="<?php echo $falta ? 'Pouco pessoal escalado' : ''; ?>">
                                    <?php echo $qtdDia; ?><?php echo $falta ? ' <i class="fas fa-exclamation-triangle"></i>' : ''; ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <small class="te-emp-nota">Dias a vermelho têm pouco pessoal escalado face à média da semana.</small>
            <?php endif; ?>
        </section>

        <div class="te-emp-duplo">
            <section class="te-emp-bloco">
                <div class="te-emp-head"><div><strong><i class="fas fa-business-time me-2"></i>Horas trabalhadas</strong><span>Semana actual e mês (<?php echo date('m/Y'); ?>)</span></div></div>
                <?php if (empty($turnosEmpHoras)): ?>
                    <div class="te-emp-vazio">Ainda sem turnos com entrada registada este mês.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table te-mini mb-0">
                        <thead><tr><th>Funcionário</th><th class="text-end">Semana</th><th class="text-end">Mês</th><th class="text-end">Horas extra</th><th class="text-end">Atrasos</th><th class="text-end">Faltas</th></tr></thead>
                        <tbody>
                        <?php foreach ($turnosEmpHoras as $h): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$h['nome']); ?></td>
                                <td class="text-end"><?php echo turnos_fmt_min($h['min_semana']); ?></td>
                                <td class="text-end"><strong><?php echo turnos_fmt_min($h['min_mes']); ?></strong></td>
                                <td class="text-end<?php echo (int)$h['min_extra'] > 0 ? ' text-warning-emphasis fw-bold' : ''; ?>"><?php echo (int)$h['min_extra'] > 0 ? turnos_fmt_min($h['min_extra']) : '—'; ?></td>
                                <td class="text-end<?php echo (int)$h['atrasos'] > 0 ? ' text-danger fw-bold' : ''; ?>"><?php echo (int)$h['atrasos']; ?></td>
                                <td class="text-end"><?php echo (int)$h['faltas']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <small class="te-emp-nota">Hora extra = tempo acima de 8h num turno. Atraso = entrada mais de 10 min depois da hora prevista (manhã/integral 08:00, noite 18:00).</small>
                <?php endif; ?>
            </section>

            <section class="te-emp-bloco">
                <div class="te-emp-head"><div><strong><i class="fas fa-chart-line me-2"></i>Vendas por funcionário</strong><span>Este mês · só vendas pagas</span></div></div>
                <?php if (empty($turnosEmpVendas)): ?>
                    <div class="te-emp-vazio">Ainda sem vendas ou pedidos registados este mês.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table te-mini mb-0">
                        <thead><tr><th>Funcionário</th><th class="text-end">Vendas</th><th class="text-end">Total</th><th class="text-end">Ticket médio</th><th class="text-end">Pedidos</th></tr></thead>
                        <tbody>
                        <?php foreach ($turnosEmpVendas as $v): $vq = (int)$v['vendas_qtd']; ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$v['nome']); ?> <small class="text-muted"><?php echo htmlspecialchars($perfilRotulos[turno_normalizar_perfil((string)$v['perfil'])] ?? (string)$v['perfil']); ?></small></td>
                                <td class="text-end"><?php echo $vq; ?></td>
                                <td class="text-end"><strong>MZN <?php echo number_format((float)$v['vendas_total'], 2, ',', '.'); ?></strong></td>
                                <td class="text-end"><?php echo $vq > 0 ? 'MZN ' . number_format((float)$v['vendas_total'] / $vq, 2, ',', '.') : '—'; ?></td>
                                <td class="text-end"><?php echo (int)$v['pedidos_qtd']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <small class="te-emp-nota">Vendas = cobranças feitas pelo funcionário (caixa/PDV). Pedidos = pedidos lançados como garçom (sem cancelados).</small>
                <?php endif; ?>
            </section>
        </div>
        <?php endif; ?>

        <?php if ($plano_atual === 'PROFISSIONAL' && $turnosProHoras): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-hourglass-half me-1"></i>Horas trabalhadas nos últimos 7 dias</h6>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead class="table-light">
                        <tr><th>Funcionário</th><th>Perfil</th><th class="text-end">Turnos</th><th class="text-end">Horas</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($turnosProHoras as $linhaHora): ?>
                        <?php
                        $minProH = (int)($linhaHora['min_total'] ?? 0);
                        $horasProH = intdiv($minProH, 60);
                        $restoProH = $minProH % 60;
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string)($linhaHora['nome'] ?? '—')); ?></td>
                            <td class="text-muted small"><?php echo htmlspecialchars((string)($linhaHora['perfil'] ?? '—')); ?></td>
                            <td class="text-end"><?php echo (int)($linhaHora['turnos'] ?? 0); ?></td>
                            <td class="text-end fw-semibold"><?php echo $horasProH . 'h' . ($restoProH > 0 ? ' ' . $restoProH . 'min' : ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-body py-2">
                <small class="text-muted">Soma do tempo entre entrada e saída. Turnos ainda abertos contam até agora.</small>
            </div>
        </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-12">
                <div id="turnoAdminAlert" class="alert" style="display:none;"></div>
            </div>

            <?php if (empty($isEmpresarialUi)): ?>
            <div class="col-md-3">
                <div class="card h-100">
                    <div class="card-body p-4">
                        <div class="text-muted small mb-2">Funcionários ativos</div>
                        <h2 class="mb-0" id="metricaAtivos"><?php echo (int)($metricas['funcionarios_ativos'] ?? 0); ?></h2>
                        <?php $teAtrasosHoje = (int)($metricas['atrasos'] ?? 0); ?>
                        <small class="<?php echo $teAtrasosHoje > 0 ? 'text-danger' : 'text-muted'; ?>" id="metricaAtrasos"><?php echo $teAtrasosHoje > 0 ? ($teAtrasosHoje . ' com entrada atrasada') : 'nenhuma entrada atrasada'; ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card h-100">
                    <div class="card-body p-4">
                        <div class="text-muted small mb-2">Turnos não encerrados</div>
                        <h2 class="mb-0 text-danger" id="metricaNaoEncerrados"><?php echo (int)($metricas['turnos_nao_encerrados'] ?? 0); ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card h-100">
                    <div class="card-body p-4">
                        <div class="text-muted small mb-2">Tempo total em turno</div>
                        <h2 class="mb-0" id="metricaTempoTurno"><?php echo htmlspecialchars((string)($metricas['tempo_turno_formatado'] ?? '0 min')); ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card h-100">
                    <div class="card-body p-4">
                        <div class="text-muted small mb-2">Online / Offline</div>
                        <h2 class="mb-0"><span id="metricaOnline"><?php echo (int)($metricas['online'] ?? 0); ?></span> / <span class="text-muted" id="metricaOffline"><?php echo (int)($metricas['offline'] ?? 0); ?></span></h2>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Widget Ativos Hoje -->
            <div class="col-lg-4">
                <div class="card h-100<?php echo !empty($isEmpresarialUi) ? ' te-panel te-panel-ativos' : ''; ?>">
                    <div class="card-header d-flex justify-content-between align-items-center<?php echo !empty($isEmpresarialUi) ? ' te-panel-head' : ''; ?>">
                        <div>
                            <h6 class="mb-0"><i class="fas fa-users me-1"></i><?php echo !empty($isEmpresarialUi) ? 'Quem está ativo hoje' : '👥 Quem está ativo hoje'; ?></h6>
                            <?php if (!empty($isEmpresarialUi)): ?>
                                <small class="te-panel-sub">Presença em tempo real na unidade</small>
                            <?php endif; ?>
                        </div>
                        <span class="badge<?php echo !empty($isEmpresarialUi) ? ' te-count-badge' : ' bg-success'; ?>" id="activeTodayCount"><?php echo count($ativos); ?></span>
                    </div>
                    <div class="card-body p-0">
                        <div id="listaAtivos" class="<?php echo !empty($isEmpresarialUi) ? 'te-ativos-list' : ''; ?>">
                            <?php if (count($ativos) === 0): ?>
                                <div class="text-center p-4 text-muted te-empty">
                                    <i class="fas fa-user-slash fa-2x mb-2"></i>
                                    <p class="mb-0">Nenhum funcionário ativo</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($ativos as $ativo): ?>
                                    <?php
                                    $nomeAtivo = trim((string)($ativo['funcionario_nome'] ?? $ativo['nome'] ?? 'Sem nome'));
                                    $onlineAtivo = !empty($ativo['online']);
                                    $durAtivo = (string)($ativo['duracao_formatada'] ?? '');
                                    $turnoAtivo = strtoupper((string)($ativo['turno'] ?? ''));
                                    $horaAtivo = !empty($ativo['hora_entrada']) ? date('H:i', strtotime((string)$ativo['hora_entrada'])) : '--:--';
                                    ?>
                                    <?php if (!empty($isEmpresarialUi)): ?>
                                    <div class="te-active-row">
                                        <div class="avatar"><?php echo htmlspecialchars(mb_strtoupper(mb_substr(trim((string)$nomeAtivo), 0, 2, 'UTF-8'), 'UTF-8')); ?></div>
                                        <div class="te-active-meta">
                                            <strong><?php echo htmlspecialchars($nomeAtivo); ?></strong>
                                            <span><?php echo htmlspecialchars($turnoAtivo); ?> · entrada <?php echo htmlspecialchars($horaAtivo); ?><?php if ($durAtivo !== ''): ?> · <?php echo htmlspecialchars($durAtivo); ?><?php endif; ?></span>
                                        </div>
                                        <span class="te-pill <?php echo $onlineAtivo ? 'is-on' : 'is-off'; ?>"><?php echo $onlineAtivo ? 'Online' : 'Offline'; ?></span>
                                    </div>
                                    <?php else: ?>
                                    <div class="d-flex align-items-center p-3 border-bottom">
                                        <div class="avatar me-3"><?php echo htmlspecialchars(mb_strtoupper(mb_substr(trim((string)$nomeAtivo), 0, 2, 'UTF-8'), 'UTF-8')); ?></div>
                                        <div class="flex-grow-1">
                                            <div class="fw-bold"><?php echo htmlspecialchars($nomeAtivo); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($turnoAtivo); ?> • <?php echo htmlspecialchars($horaAtivo); ?><?php if ($durAtivo !== ''): ?> • <?php echo htmlspecialchars($durAtivo); ?><?php endif; ?></small>
                                        </div>
                                        <?php if (!empty($ativo['atrasado'])): ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Entrada atrasada</span>
                                        <?php elseif ($onlineAtivo): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle">Online</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Adicionar Turno -->
            <div class="col-lg-8">
                <div class="card<?php echo !empty($isEmpresarialUi) ? ' te-panel te-panel-form' : ''; ?>">
                    <div class="card-header<?php echo !empty($isEmpresarialUi) ? ' te-panel-head' : ''; ?>">
                        <h6 class="mb-0"><i class="fas fa-plus me-1"></i><?php echo !empty($isEmpresarialUi) ? 'Adicionar novo turno' : 'Adicionar Novo Turno'; ?></h6>
                        <?php if (!empty($isEmpresarialUi)): ?>
                            <small class="te-panel-sub">Abertura manual com motivo — fica registado na auditoria</small>
                        <?php endif; ?>
                    </div>
                    <div class="card-body<?php echo !empty($isEmpresarialUi) ? ' te-form-body' : ''; ?>">
                        <form id="formNovoTurno">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label fw-600"><?php echo !empty($isEmpresarialUi) ? 'Data' : '📅 Data'; ?></label>
                                    <input type="date" name="data" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-600"><?php echo !empty($isEmpresarialUi) ? 'Funcionário' : '👤 Funcionário'; ?></label>
                                    <select name="usuario_id" class="form-select" required>
                                        <option value="">Selecione...</option>
                                        <?php foreach ($usuarios as $u): ?>
                                            <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['nome'] . ' • ' . strtoupper($u['perfil'])); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label fw-600"><?php echo !empty($isEmpresarialUi) ? 'Turno' : '🕐 Turno'; ?></label>
                                    <select name="turno" id="novoTurnoTipo" class="form-select" required>
                                        <option value="manha"><?php echo !empty($isEmpresarialUi) ? 'Manhã' : 'Manhã ☀️'; ?></option>
                                        <option value="noite"><?php echo !empty($isEmpresarialUi) ? 'Noite' : 'Noite 🌙'; ?></option>
                                        <option value="integral"><?php echo !empty($isEmpresarialUi) ? 'Integral' : 'Integral 🕛'; ?></option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-600"><?php echo !empty($isEmpresarialUi) ? 'Entrada' : '⏰ Entrada'; ?></label>
                                    <input type="time" name="hora_entrada" id="novoTurnoHoraEntrada" class="form-control" value="08:00">
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label fw-600"><?php echo !empty($isEmpresarialUi) ? 'Motivo / observação' : '📝 Motivo / Observação'; ?></label>
                                    <input type="text" name="motivo" class="form-control" placeholder="Motivo da intervenção">
                                </div>
                                <div class="col-md-3 pt-2">
                                    <button type="submit" class="btn btn-primary w-100<?php echo !empty($isEmpresarialUi) ? ' te-btn-add' : ''; ?>">
                                        <?php echo !empty($isEmpresarialUi) ? '<i class="fas fa-plus me-1"></i>Adicionar' : '➕ Adicionar'; ?>
                                    </button>
                                </div>
                                <div class="col-md-3 pt-2">
                                    <button type="button" class="btn btn-outline-danger w-100" onclick="fecharTurnoManualSelecionado()">
                                        <i class="fas fa-user-slash me-1"></i>Encerrar Manualmente
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Tabela Turnos -->
            <div class="col-12">
                <div class="card<?php echo !empty($isEmpresarialUi) ? ' te-panel te-panel-table' : ''; ?>">
                    <div class="card-header d-flex justify-content-between align-items-center<?php echo !empty($isEmpresarialUi) ? ' te-panel-head' : ''; ?>">
                        <div>
                            <h6 class="mb-0"><i class="fas fa-list me-1"></i><?php echo !empty($isEmpresarialUi) ? 'Todos os turnos' : '📋 Todos os Turnos'; ?></h6>
                            <?php if (!empty($isEmpresarialUi)): ?>
                                <small class="te-panel-sub">Histórico da unidade — filtre e exporte</small>
                            <?php endif; ?>
                        </div>
                        <div class="te-table-tools">
                            <select id="filtroData" class="form-select form-select-sm d-inline-block w-auto">
                                <option value="">Todos</option>
                                <option value="hoje">Hoje</option>
                                <option value="amanha">Amanhã</option>
                                <option value="semana">Semana</option>
                            </select>
                            <?php if ($plano_atual === 'PROFISSIONAL'): ?>
                            <select id="filtroFuncionario" class="form-select form-select-sm d-inline-block w-auto ms-2" aria-label="Funcionário">
                                <option value="">Todos os funcionários</option>
                                <?php foreach ($usuarios as $u): ?>
                                    <option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars((string)$u['nome']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" id="filtroLimpar" class="btn btn-sm btn-outline-secondary ms-1" title="Limpar filtros"><i class="fas fa-times"></i></button>
                            <span class="badge text-bg-light ms-2" id="filtroContagem"></span>
                            <?php endif; ?>
                            <?php if (!empty($isEmpresarialUi)): ?>
                            <select id="filtroFuncionario" class="form-select form-select-sm d-inline-block w-auto ms-2" aria-label="Funcionário">
                                <option value="">Todos os funcionários</option>
                                <?php foreach ($usuarios as $u): ?>
                                    <option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars((string)$u['nome']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="filtroPerfil" class="form-select form-select-sm d-inline-block w-auto ms-2" aria-label="Perfil">
                                <option value="">Todos os perfis</option>
                                <option value="GARCOM">Garçom</option>
                                <option value="CAIXA">Caixa</option>
                                <option value="COZINHA">Cozinha</option>
                                <option value="BAR">Bar</option>
                                <option value="GERENTE">Gerente</option>
                                <option value="ADMIN">Admin</option>
                            </select>
                            <input type="date" id="filtroDe" class="form-control form-control-sm d-inline-block w-auto ms-2" aria-label="De" title="De">
                            <input type="date" id="filtroAte" class="form-control form-control-sm d-inline-block w-auto ms-1" aria-label="Até" title="Até">
                            <button type="button" id="filtroLimpar" class="btn btn-sm btn-outline-secondary ms-1" title="Limpar filtros"><i class="fas fa-times"></i></button>
                            <span class="badge text-bg-light ms-2" id="filtroContagem"></span>
                            <?php endif; ?>
                            <button type="button" class="btn btn-sm<?php echo !empty($isEmpresarialUi) ? ' te-btn-export' : ' btn-outline-secondary'; ?> ms-2" onclick="exportarTurnos()" title="Exportar">
                                <i class="fas fa-file-export"></i>
                            </button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0<?php echo !empty($isEmpresarialUi) ? ' te-table' : ''; ?>">
                            <thead class="<?php echo !empty($isEmpresarialUi) ? 'te-thead' : 'table-light'; ?>">
                                <tr>
                                    <th>Data</th>
                                    <th>Funcionário</th>
                                    <th>Turno</th>
                                    <th>Entrada/Saída</th>
                                    <th>Status</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody id="turnosLista">
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">
                                        <i class="fas fa-clock fa-2x mb-2 opacity-50"></i>
                                        <div>Carregando turnos...</div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <div class="modal fade" id="encerrarTurnoModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <form id="formEncerrarTurno" novalidate>
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-user-slash text-danger me-2"></i>Encerrar turno</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-2">Vai encerrar o turno de <strong id="encerrarTurnoNome">este funcionário</strong>. Fica registado na auditoria com o seu nome.</p>
                        <label class="form-label fw-semibold" for="encerrarTurnoMotivo">Motivo (obrigatório)</label>
                        <textarea id="encerrarTurnoMotivo" class="form-control" rows="3" maxlength="255" placeholder="Descreva o motivo do encerramento"></textarea>
                        <div class="invalid-feedback">Escreva o motivo (pelo menos 3 letras).</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-danger"><i class="fas fa-check me-1"></i>Encerrar turno</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editarTurnoModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-pen text-info me-2"></i>Editar Turno</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <form id="formEditarTurno">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="editarTurnoId">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-600">📅 Data</label>
                                <input type="date" name="data" id="editarTurnoData" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-600">👤 Funcionário</label>
                                <select name="usuario_id" id="editarTurnoUsuario" class="form-select" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($usuarios as $u): ?>
                                        <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['nome'] . ' • ' . strtoupper($u['perfil'])); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-600">📌 Status</label>
                                <select name="status" id="editarTurnoStatus" class="form-select" required>
                                    <option value="planejado">Planejado</option>
                                    <option value="ativo">Ativo</option>
                                    <option value="finalizado">Finalizado</option>
                                    <option value="ausente">Ausente</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-600">🕐 Turno</label>
                                <select name="turno" id="editarTurnoTipo" class="form-select" required>
                                    <option value="manha">Manhã ☀️</option>
                                    <option value="noite">Noite 🌙</option>
                                    <option value="integral">Integral 🕛</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-600">⏰ Entrada</label>
                                <input type="time" name="hora_entrada" id="editarTurnoHoraEntrada" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-600">⏱️ Saída</label>
                                <input type="time" name="hora_saida" id="editarTurnoHoraSaida" class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-600">📝 Observações</label>
                                <textarea name="observacoes" id="editarTurnoObservacoes" class="form-control" rows="3" placeholder="Observações do turno"></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-600">🛡️ Motivo da correção</label>
                                <input type="text" name="motivo" id="editarTurnoMotivo" class="form-control" placeholder="Motivo da correção">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary" id="btnSalvarEdicaoTurno">
                            <i class="fas fa-save me-1"></i>Salvar alterações
                        </button>
                    </div>
                </form>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-shield-alt text-danger me-1"></i>Auditoria de Turnos</h6>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Quando</th>
                                    <th>Responsável</th>
                                    <th>Funcionário</th>
                                    <th>Ação</th>
                                    <th>Motivo</th>
                                </tr>
                            </thead>
                            <tbody id="auditoriaLista">
                                <?php if (empty($auditoria)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">Nenhum log de auditoria</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($auditoria as $item): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars((string)$item['criado_em']); ?></td>
                                            <td><?php echo htmlspecialchars((string)($item['responsavel_nome'] ?? '—')); ?></td>
                                            <td><?php echo htmlspecialchars((string)($item['funcionario_nome'] ?? '—')); ?></td>
                                            <td><?php echo htmlspecialchars((string)$item['tipo_acao']); ?></td>
                                            <td><?php echo htmlspecialchars((string)$item['motivo']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="lib/bootstrap-5.3.2/js/bootstrap.bundle.min.js"></script>
    <script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">const CSRF_TOKEN = <?php echo json_encode($csrfToken, JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="funcionarios_turnos.js?v=<?php echo (int)@filemtime(__DIR__ . '/funcionarios_turnos.js'); ?>"></script>
    <script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
        setInterval(function() {
            fetch('api/online_ping.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-Token': CSRF_TOKEN,
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
        }, 60000);
    </script>
</body>

</html>



