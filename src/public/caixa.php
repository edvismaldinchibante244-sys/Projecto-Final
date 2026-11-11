<?php
require_once __DIR__ . '/app_base.php';
// Proteção da página
include_once APP_BASE . '/src/config/auth_check.php';
include_once APP_BASE . '/src/config/csrf.php';
include_once APP_BASE . '/src/config/plano_check.php';
include_once APP_BASE . '/src/config/restaurante_context.php';
requirePermissionOrRedirect(['ADMIN', 'GERENTE', 'CAIXA']);

// Verificar se plano permite funcionalidade CAIXA (plano da matriz)
include_once APP_BASE . '/src/config/database.php';
$turnoRestauranteIdTemp = function_exists('session_restaurante_auth_id') ? session_restaurante_auth_id() : (int)($_SESSION['restaurante_id'] ?? 0);
$planRidCaixa = session_restaurante_capability_id();
if ($planRidCaixa <= 0) {
    $planRidCaixa = $turnoRestauranteIdTemp;
}
plano_verificar_funcionalidade($planRidCaixa, 'caixa');
include_once APP_BASE . '/src/config/turno_helpers.php';
include_once APP_BASE . '/src/Model/Caixa.php';
require_once APP_BASE . '/src/config/caixa_movimentos.php';
include_once APP_BASE . '/src/Model/Venda.php';
include_once APP_BASE . '/src/Service/TurnoService.php';

$database = new Database();
$db = $database->getConnection();
$turnoService = new TurnoService($database);
$turnoRestauranteId = function_exists('session_restaurante_auth_id') ? session_restaurante_auth_id() : (int)($_SESSION['restaurante_id'] ?? 0);
$turnoAtivoOperador = $turnoService->obterTurnoAtivoUsuario((int)$_SESSION['usuario_id'], $turnoRestauranteId);

$perfilCaixa = strtoupper(trim((string)($_SESSION['perfil'] ?? '')));
if ($perfilCaixa === 'GARÇOM' || $perfilCaixa === 'GARÃ‡OM') {
    $perfilCaixa = 'GARCOM';
}
$isGerenteCaixa = ($perfilCaixa === 'GERENTE');

$caixa = new Caixa($db);
$caixa_aberto = $caixa->buscarAberto($_SESSION['restaurante_id']);

$total_vendas = 0.0;
if ($caixa_aberto) {
    $total_vendas = (float)$caixa->totalVendas($caixa_aberto['id']);
}
$saldo_inicial_caixa = $caixa_aberto ? (float)($caixa_aberto['saldo_inicial'] ?? 0) : 0.0;
$saldo_esperado_caixa = $saldo_inicial_caixa + $total_vendas;

$dadosPlanoCaixa = plano_get_dados($planRidCaixa);
$plano_atual = plano_normalizar_nome($dadosPlanoCaixa['plano_nome'] ?? 'BASICO');
$temMultiFilialCaixa = plano_tem_funcionalidade_db($planRidCaixa, 'multi_filial');
$temRelatorioFinanceiroCaixa = plano_tem_funcionalidade_db($planRidCaixa, 'relatorio_financeiro');
require_once __DIR__ . '/includes/empresarial_ui.php';

$formasPagamentoCaixa = [];
$empCaixa = [
    'qtd_vendas' => 0,
    'ticket_medio' => 0.0,
    'total_dinheiro' => 0.0,
    'total_digital' => 0.0,
    'esperado_gaveta' => 0.0,
    'contas_abertas' => 0,
    'valor_aberto' => 0.0,
    'ultimas_vendas' => [],
    'minutos_aberto' => null,
    'alerta_turno' => empty($turnoAtivoOperador) && !empty($caixa_aberto),
    'alerta_contas' => false,
];

if (!empty($isEmpresarialUi)) {
    $ridCtx = (int)($_SESSION['restaurante_id'] ?? 0);

    if (!empty($caixa_aberto['id'])) {
        try {
            $stmtFormas = $db->prepare("
                SELECT COALESCE(forma_pagamento, 'OUTRO') AS forma, COALESCE(SUM(total_final), 0) AS total, COUNT(*) AS qtd
                FROM vendas
                WHERE caixa_id = :cid AND restaurante_id = :rid
                  AND UPPER(TRIM(COALESCE(status, ''))) = 'PAGO'
                GROUP BY COALESCE(forma_pagamento, 'OUTRO')
                ORDER BY total DESC
            ");
            $stmtFormas->execute([
                ':cid' => (int)$caixa_aberto['id'],
                ':rid' => $ridCtx,
            ]);
            $formasPagamentoCaixa = $stmtFormas->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $formasPagamentoCaixa = [];
        }

        try {
            $stmtAgg = $db->prepare("
                SELECT
                    COUNT(*) AS qtd,
                    COALESCE(SUM(total_final), 0) AS total,
                    COALESCE(SUM(CASE WHEN UPPER(TRIM(COALESCE(forma_pagamento, ''))) = 'DINHEIRO' THEN total_final ELSE 0 END), 0) AS dinheiro,
                    COALESCE(SUM(CASE WHEN UPPER(TRIM(COALESCE(forma_pagamento, ''))) <> 'DINHEIRO' THEN total_final ELSE 0 END), 0) AS digital
                FROM vendas
                WHERE caixa_id = :cid AND restaurante_id = :rid
                  AND UPPER(TRIM(COALESCE(status, ''))) = 'PAGO'
            ");
            $stmtAgg->execute([
                ':cid' => (int)$caixa_aberto['id'],
                ':rid' => $ridCtx,
            ]);
            $agg = $stmtAgg->fetch(PDO::FETCH_ASSOC) ?: [];
            $empCaixa['qtd_vendas'] = (int)($agg['qtd'] ?? 0);
            $empCaixa['total_dinheiro'] = (float)($agg['dinheiro'] ?? 0);
            $empCaixa['total_digital'] = (float)($agg['digital'] ?? 0);
            $empCaixa['ticket_medio'] = $empCaixa['qtd_vendas'] > 0
                ? ((float)($agg['total'] ?? 0) / $empCaixa['qtd_vendas'])
                : 0.0;
            $empCaixa['esperado_gaveta'] = $saldo_inicial_caixa + $empCaixa['total_dinheiro'];
        } catch (Throwable $e) {
            // mantém defaults
        }

        try {
            $stmtUlt = $db->prepare("
                SELECT id, numero_fatura, total_final, forma_pagamento, criado_em
                FROM vendas
                WHERE caixa_id = :cid AND restaurante_id = :rid
                  AND UPPER(TRIM(COALESCE(status, ''))) = 'PAGO'
                ORDER BY criado_em DESC
                LIMIT 8
            ");
            $stmtUlt->execute([
                ':cid' => (int)$caixa_aberto['id'],
                ':rid' => $ridCtx,
            ]);
            $empCaixa['ultimas_vendas'] = $stmtUlt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $empCaixa['ultimas_vendas'] = [];
        }

        if (!empty($caixa_aberto['data_abertura'])) {
            $tsAbertura = strtotime((string)$caixa_aberto['data_abertura']);
            if ($tsAbertura) {
                $empCaixa['minutos_aberto'] = (int)max(0, floor((time() - $tsAbertura) / 60));
            }
        }
    }

    try {
        $stmtContas = $db->prepare("
            SELECT COUNT(*) AS qtd, COALESCE(SUM(total), 0) AS valor
            FROM pedidos
            WHERE restaurante_id = :rid
              AND UPPER(TRIM(COALESCE(status, ''))) NOT IN ('PAGO', 'CANCELADO')
        ");
        $stmtContas->execute([':rid' => $ridCtx]);
        $contas = $stmtContas->fetch(PDO::FETCH_ASSOC) ?: [];
        $empCaixa['contas_abertas'] = (int)($contas['qtd'] ?? 0);
        $empCaixa['valor_aberto'] = (float)($contas['valor'] ?? 0);
        $empCaixa['alerta_contas'] = $empCaixa['contas_abertas'] > 0;
    } catch (Throwable $e) {
        // ok
    }
}

// Sangrias / reforços do caixa aberto e opção de fecho às cegas
$movCaixa = ['sangria' => 0.0, 'reforco' => 0.0, 'lista' => []];
if (!empty($caixa_aberto['id'])) {
    $movCaixa = caixa_mov_resumo($db, (int)$caixa_aberto['id'], (int)($_SESSION['restaurante_id'] ?? 0));
    if (!empty($isEmpresarialUi)) {
        $empCaixa['esperado_gaveta'] = round((float)$empCaixa['esperado_gaveta'] + $movCaixa['reforco'] - $movCaixa['sangria'], 2);
    }
}
$caixaMovDisponivel = caixa_mov_tabela_existe($db);
$fechoCegoAtivo = caixa_fecho_cego_ativo($db, (int)($_SESSION['restaurante_id'] ?? 0));
$podeConfigCaixa = in_array($perfilCaixa, ['ADMIN', 'GERENTE'], true) || !empty($_SESSION['super_admin']);
// Fecho às cegas: o operador de caixa só vê o esperado depois de contar.
$ocultarEsperadoFecho = $fechoCegoAtivo && !$podeConfigCaixa;

// Contas abertas também para o gerente fora do layout EMP.
$isProfissionalCaixa = (($plano_atual ?? '') === 'PROFISSIONAL');
// Contas abertas: antes so o gerente via; o operador de caixa tambem precisa.
if (($isGerenteCaixa || $isProfissionalCaixa) && empty($isEmpresarialUi)) {
    try {
        $ridCtxGer = (int)($_SESSION['restaurante_id'] ?? 0);
        $stmtContasGer = $db->prepare("
            SELECT COUNT(*) AS qtd, COALESCE(SUM(total), 0) AS valor
            FROM pedidos
            WHERE restaurante_id = :rid
              AND UPPER(TRIM(COALESCE(status, ''))) NOT IN ('PAGO', 'CANCELADO')
        ");
        $stmtContasGer->execute([':rid' => $ridCtxGer]);
        $contasGer = $stmtContasGer->fetch(PDO::FETCH_ASSOC) ?: [];
        $empCaixa['contas_abertas'] = (int)($contasGer['qtd'] ?? 0);
        $empCaixa['valor_aberto'] = (float)($contasGer['valor'] ?? 0);
        $empCaixa['alerta_contas'] = $empCaixa['contas_abertas'] > 0;
    } catch (Throwable $e) {
        // ok
    }
    if (!empty($caixa_aberto['data_abertura'])) {
        $tsAberturaGer = strtotime((string)$caixa_aberto['data_abertura']);
        if ($tsAberturaGer) {
            $empCaixa['minutos_aberto'] = (int)max(0, floor((time() - $tsAberturaGer) / 60));
        }
    }
}

// Profissional: total por forma de pagamento neste caixa, para conferir a gaveta.
$caixaFormasPro = [];
if ($isProfissionalCaixa && empty($isEmpresarialUi) && !empty($caixa_aberto['id'])) {
    try {
        $stFormasPro = $db->prepare("
            SELECT UPPER(COALESCE(NULLIF(TRIM(forma_pagamento), ''), 'OUTRO')) AS forma,
                   COALESCE(SUM(total_final), 0) AS total, COUNT(*) AS qtd
            FROM vendas
            WHERE caixa_id = :cid AND restaurante_id = :rid
              AND UPPER(TRIM(COALESCE(status, ''))) = 'PAGO'
            GROUP BY forma
            ORDER BY total DESC
        ");
        $stFormasPro->execute([
            ':cid' => (int)$caixa_aberto['id'],
            ':rid' => (int)($_SESSION['restaurante_id'] ?? 0),
        ]);
        $caixaFormasPro = $stFormasPro->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $caixaFormasPro = [];
    }
}

$historico = [];
if (empty($isGerenteCaixa)) {
    $stmt_historico = $caixa->listar($_SESSION['restaurante_id'], 15);
    $historico = $stmt_historico->fetchAll(PDO::FETCH_ASSOC);
}

// Preparar dados do usuário para exibição no topo
$public_base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$resolve_foto_url = function ($path) use ($public_base) {
    if (empty($path)) return '';
    if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0 || strpos($path, '/') === 0) return $path;
    return $public_base . '/' . ltrim($path, '/');
};
$top_foto_url = $resolve_foto_url($_SESSION['foto'] ?? '');
$top_nome_usuario = $_SESSION['nome'] ?? 'Usuário';
$top_perfil_usuario = $_SESSION['perfil'] ?? 'USER';
$top_nome_partes = preg_split('/\s+/', trim($top_nome_usuario));
$csrfToken = csrf_get_token();
$top_iniciais = mb_strtoupper(
    mb_substr((string)($top_nome_partes[0] ?? 'U'), 0, 1, 'UTF-8') . mb_substr((string)($top_nome_partes[1] ?? ''), 0, 1, 'UTF-8'),
    'UTF-8'
);
?>
<!DOCTYPE html>
<html lang="pt">

<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Caixa - RestauranteSaaS</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link href="lib/bootstrap-5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="lib/fontawesome-6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/premium.css">
    <style>
        :root {
            --primary: #FF6B35;
            --primary-dark: #e55a2b;
            --secondary: #F7931E;
            --dark: #0f0f23;
            --dark-2: #1a1a2e;
            --dark-3: #16213e;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --text: #1e293b;
            --text-light: #64748b;
            --text-muted: #94a3b8;
            --bg: #f8fafc;
            --border: #e2e8f0;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Outfit', 'Segoe UI', 'Noto Sans', system-ui, -apple-system, 'Helvetica Neue', Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            overflow-x: hidden;
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

        .sidebar-brand h2 i {
            color: var(--primary);
            font-size: 24px;
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

        .user-details {
            flex: 1;
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
            padding: 0;
            min-height: 100vh;
            background: var(--bg);
        }

        .top-bar {
            background: white;
            padding: 20px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .page-title {
            font-family: 'Space Grotesk', 'Segoe UI Semibold', 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
            font-size: 24px;
            font-weight: 700;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-title i {
            color: var(--primary);
        }

        .top-bar-right {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .top-bar-date {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-size: 13px;
            color: #334155;
            font-weight: 700;
            white-space: nowrap;
            padding: 7px 12px;
            border-radius: 999px;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
        }

        .top-bar-user-chip {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 8px 14px;
            border: 1px solid var(--border);
            border-radius: 999px;
            background: #fff;
            box-shadow: 0 5px 14px rgba(15, 23, 42, 0.06);
        }

        .top-bar-user-chip img,
        .top-bar-user-chip .chip-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            object-fit: cover;
            flex-shrink: 0;
        }

        .top-bar-user-chip .chip-avatar {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #c2410c, #b45309);
            color: #fff;
            font-weight: 700;
            font-size: 12px;
        }

        .top-bar-user-chip .chip-name {
            font-size: 15px;
            color: var(--text);
            font-weight: 700;
            line-height: 1;
        }

        .top-bar-user-chip .chip-role {
            font-size: 11px;
            color: #1d4ed8;
            font-weight: 800;
            text-transform: uppercase;
            padding: 4px 8px;
            border-radius: 999px;
            background: rgba(29, 78, 216, 0.12);
        }

        .top-bar-user-chip .chip-info {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .content-area {
            padding: 32px;
        }

        .card {
            background: white;
            border-radius: 20px;
            box-shadow: var(--shadow);
            border: none;
            overflow: hidden;
            margin-bottom: 24px;
        }

        .card-header {
            background: white;
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title {
            font-family: 'Space Grotesk', 'Segoe UI Semibold', 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
            font-size: 18px;
            font-weight: 700;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-title i {
            color: var(--primary);
        }

        .card-body {
            padding: 0;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            box-shadow: var(--shadow);
            text-align: center;
        }

        .stat-value {
            font-family: 'Space Grotesk', 'Segoe UI Semibold', 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
            font-size: 32px;
            font-weight: 700;
            color: var(--text);
        }

        .stat-label {
            color: var(--text-light);
            font-size: 14px;
        }

        /* Sobrepõe premium.css (.stat-card { background:white !important }) */
        .premium-ui .stat-card.stat-card-highlight,
        .stat-card.stat-card-highlight {
            background: linear-gradient(135deg, var(--primary), var(--secondary)) !important;
            color: #fff !important;
            border-color: transparent !important;
            box-shadow: 0 12px 28px rgba(255, 107, 53, 0.28) !important;
        }

        .premium-ui .stat-card.stat-card-highlight .stat-value,
        .stat-card.stat-card-highlight .stat-value {
            color: #fff !important;
        }

        .premium-ui .stat-card.stat-card-highlight .stat-label,
        .stat-card.stat-card-highlight .stat-label {
            color: rgba(255, 255, 255, 0.9) !important;
        }

        .table {
            margin: 0;
        }

        .table thead th {
            background: var(--bg);
            padding: 16px 20px;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-light);
            border: none;
        }

        .table tbody td {
            padding: 16px 20px;
            vertical-align: middle;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
        }

        .table tbody tr:hover {
            background: rgba(255, 107, 53, 0.02);
        }

        .badge-custom {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .badge-danger {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(255, 107, 53, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 107, 53, 0.4);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), #20c997);
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #dc2626);
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
        }

        .btn-secondary {
            background: var(--text-light);
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
        }

        .modal-content {
            border: none;
            border-radius: 24px;
            box-shadow: var(--shadow-lg);
        }

        .modal-header {
            padding: 24px 28px;
            border-bottom: 1px solid var(--border);
            border-radius: 24px 24px 0 0;
        }

        .modal-title {
            font-family: 'Space Grotesk', 'Segoe UI Semibold', 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
            font-weight: 700;
            font-size: 20px;
        }

        .modal-body {
            padding: 28px;
        }

        .modal-footer {
            padding: 20px 28px;
            border-top: 1px solid var(--border);
        }

        .form-label {
            font-weight: 600;
            font-size: 13px;
            color: var(--text);
            margin-bottom: 8px;
        }

        .form-control {
            padding: 12px 16px;
            border-radius: 12px;
            border: 2px solid var(--border);
            font-size: 14px;
            transition: all 0.3s;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(255, 107, 53, 0.1);
        }

        .caixa-status-card,
        .caixa-turno-actions {
            align-items: stretch;
        }

        .caixa-status-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                position: relative;
                min-height: auto;
            }

            .main-content {
                margin-left: 0;
            }

            .top-bar {
                padding: 12px 16px;
                flex-wrap: wrap;
                gap: 10px;
            }

            .page-title {
                font-size: 18px;
            }

            .top-bar-right {
                width: 100%;
                justify-content: space-between;
            }

            .top-bar-date {
                font-size: 12px;
            }

            .top-bar-user-chip {
                padding: 7px 10px;
                gap: 8px;
            }

            .top-bar-user-chip .chip-name {
                font-size: 13px;
            }

            .top-bar-user-chip .chip-role {
                font-size: 10px;
            }

            .content-area {
                padding: 20px;
            }

            .caixa-turno-actions {
                justify-content: stretch !important;
            }

            .caixa-turno-actions .btn {
                width: 100%;
            }
        }

        @media (max-width: 576px) {
            .main-content {
                padding: 12px;
            }

            .top-bar {
                padding: 12px 14px;
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
                margin-bottom: 16px;
            }

            .page-title {
                font-size: 17px;
                gap: 8px;
            }

            .top-bar-right {
                width: 100%;
                justify-content: space-between;
                flex-wrap: wrap;
                gap: 8px;
            }

            .top-bar-date {
                width: 100%;
                justify-content: flex-start;
                white-space: normal;
            }

            .top-bar-user-chip {
                width: 100%;
                padding: 7px 10px;
            }

            .content-area {
                padding: 12px;
            }

            .caixa-status-header,
            .caixa-turno-actions {
                flex-direction: column;
                align-items: stretch !important;
            }

            .caixa-status-header .btn,
            .caixa-turno-actions .btn {
                width: 100%;
            }

            .card {
                border-radius: 18px;
            }

            .card-header {
                padding: 16px 18px;
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .card-title {
                font-size: 16px;
            }

            .stat-card {
                padding: 16px;
            }

            .stat-value {
                font-size: 24px;
            }

            .stat-label {
                font-size: 12px;
            }

            .table thead th,
            .table tbody td {
                padding: 12px 14px;
                font-size: 12px;
                white-space: normal;
            }

            .badge-custom {
                white-space: normal;
            }

            .modal-dialog {
                margin: 10px;
            }

            .modal-content {
                border-radius: 18px;
            }

            .modal-header,
            .modal-body,
            .modal-footer {
                padding-left: 16px;
                padding-right: 16px;
            }

            .modal-footer {
                flex-direction: column;
                align-items: stretch;
            }

            .modal-footer .btn {
                width: 100%;
            }
        }

        /* Corporate density pass (keep palette) */
        @media (min-width: 1200px) {
            .main-content { padding-top: 14px; }
            .card { border-radius: 12px; border: 1px solid #dbe3ef; box-shadow: 0 8px 20px rgba(15,23,42,.08); }
            .card-header { padding: 10px 14px; }
            .card-body { padding: 12px 14px; }
            .btn { border-radius: 10px; }
            .btn-sm { font-size: 12px; padding: 7px 10px; border-radius: 9px; }
            .form-control, .form-select { min-height: 40px; font-size: 14px; border-radius: 10px; }
            .table thead th { font-size: 12px; text-transform: uppercase; letter-spacing: .02em; }
            .table tbody td { font-size: 13px; padding-top: .55rem; padding-bottom: .55rem; }
        }
        .caixa-pro-formas-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 10px;
        }

        .caixa-pro-forma {
            background: #f8fafc;
            border: 1px solid #eef2f7;
            border-radius: 10px;
            padding: 10px 12px;
        }

        .caixa-pro-forma.is-dinheiro { background: #ecfdf5; border-color: #a7f3d0; }

        .caixa-pro-forma span {
            display: block;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #64748b;
        }

        .caixa-pro-forma strong { display: block; font-size: 16px; color: #0f172a; }
        .caixa-pro-forma small { color: #64748b; }

    </style>
    <?php empresarial_head_assets(); ?>
    <style>
        /* ---- Caixa Empresarial (nov 2026) ---- */
        @media (min-width: 992px) {
            body.plano-empresarial:not(.sidebar-desktop-collapsed) main.main-content { margin-left: 280px !important; width: calc(100% - 280px) !important; max-width: none !important; flex: none !important; }
        }
        body.plano-empresarial main.main-content { padding-left: 24px !important; padding-right: 24px !important; }
        .caixa-gaveta-agora { background: #fffbeb; border: 1px solid #fde68a; border-radius: 12px; padding: 10px 12px; margin-bottom: 10px; }
        .caixa-gaveta-agora span { display: block; font-size: 12px; color: #92400e; }
        .caixa-gaveta-agora strong { font-size: 1.35rem; color: #b45309; }
        .caixa-gaveta-btns { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .caixa-mov-btn { font-weight: 700; border-radius: 12px !important; padding: 10px 8px !important; font-size: 14px; }
        .caixa-mov-btn.is-out { background: #fef2f2 !important; color: #b91c1c !important; border: 1px solid #fecaca !important; }
        .caixa-mov-btn.is-in { background: #ecfdf5 !important; color: #047857 !important; border: 1px solid #a7f3d0 !important; }
        .caixa-gaveta-ajuda { font-size: 12px; color: #64748b; margin: 8px 0 0; line-height: 1.4; }
        .caixa-gaveta-lista { margin-top: 12px; border-top: 1px solid #f1f5f9; }
        .caixa-gaveta-item { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; padding: 8px 0; border-bottom: 1px solid #f1f5f9; }
        .caixa-gaveta-item strong { display: block; font-size: 13px; }
        .caixa-gaveta-item small { display: block; font-size: 11.5px; color: #64748b; }
        .caixa-gaveta-item em { font-style: normal; font-weight: 800; white-space: nowrap; }
        .caixa-gaveta-item em.is-out { color: #b91c1c; } .caixa-gaveta-item em.is-in { color: #047857; }
        .caixa-gaveta-talao { display: block; margin-top: 12px; padding: 9px 12px; border-radius: 10px; background: #f8fafc; border: 1px dashed #cbd5e1; color: #334155; font-weight: 600; font-size: 13px; text-decoration: none; text-align: center; }
        .caixa-cego-toggle { display: flex; gap: 10px; align-items: flex-start; margin-top: 12px; padding: 10px 12px; border-radius: 12px; background: #f8fafc; border: 1px solid #e2e8f0; cursor: pointer; }
        .caixa-cego-toggle input { margin-top: 3px; width: 18px; height: 18px; accent-color: #ea580c; }
        .caixa-cego-toggle strong { display: block; font-size: 13px; }
        .caixa-cego-toggle small { display: block; font-size: 11.5px; color: #64748b; }
        .caixa-cego-aviso { margin-top: 10px; padding: 9px 12px; border-radius: 10px; background: #eff6ff; color: #1e40af; font-size: 13px; font-weight: 600; }
        .caixa-hist-print { margin-left: 8px; color: #475569; }
    </style>
</head>

<body class="<?php echo htmlspecialchars(empresarial_body_class('premium-ui')); ?>">
    <div class="container-fluid">
        <div class="row">
            <!-- SIDEBAR -->
            <?php include_once __DIR__ . '/includes/sidebar.php'; ?>
            <?php
            // Sidebar pode repor $plano_atual — re-sincroniza flag Empresarial.
            if (isset($plano_atual) && is_string($plano_atual)) {
                $isEmpresarialUi = (plano_normalizar_nome($plano_atual) === 'EMPRESARIAL');
            }
            if (!empty($isEmpresarialUi)) {
                echo '<script nonce="' . (function_exists('security_csp_nonce') ? security_csp_nonce() : '') . '">document.body.classList.add("plano-empresarial");</script>';
                echo '<link rel="stylesheet" href="css/empresarial.css?v=20260829c">' . "\n";
            }
            ?>

            <!-- MAIN CONTENT -->
            <main class="main-content col-md-9 ms-sm-auto col-lg-10">
                <div class="top-bar">
                    <div>
                        <h1 class="page-title mb-0"><i class="fas fa-money-bill-wave"></i> Controle de Caixa <?php include __DIR__ . '/includes/plan_page_badge.php'; ?></h1>
                        <?php if (!empty($isGerenteCaixa)): ?>
                            <div class="small text-muted mt-1">Visão do salão: estado do caixa, contas abertas e avisos de turno.</div>
                        <?php elseif (($plano_atual ?? '') === 'BASICO'): ?>
                            <div class="small text-muted mt-1">Abertura, fecho e total do turno.</div>
                        <?php elseif (($plano_atual ?? '') === 'PROFISSIONAL'): ?>
                            <div class="small text-muted mt-1">Caixa da unidade com acompanhamento operacional.</div>
                        <?php elseif (!empty($isEmpresarialUi)): ?>
                            <div class="small text-muted mt-1">Comando do caixa: mix, gaveta, contas abertas e fecho inteligente.</div>
                        <?php endif; ?>
                    </div>
                    <div class="top-bar-right">
                        <span class="top-bar-date"><i class="far fa-clock"></i><span id="topBarDateTime"><?php echo date('d/m/Y H:i'); ?></span></span>
                        <div class="top-bar-user-chip">
                            <?php if (!empty($top_foto_url)): ?>
                                <img src="<?php echo htmlspecialchars($top_foto_url); ?>" alt="<?php echo htmlspecialchars($top_nome_usuario); ?>">
                            <?php else: ?>
                                <span class="chip-avatar"><?php echo htmlspecialchars($top_iniciais); ?></span>
                            <?php endif; ?>
                            <span class="chip-info">
                                <span class="chip-name"><?php echo htmlspecialchars($top_nome_usuario); ?></span>
                                <span class="chip-role"><?php echo htmlspecialchars($top_perfil_usuario); ?></span>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="content-area">
                    <?php
                    $minsAbertoGer = $empCaixa['minutos_aberto'] ?? null;
                    $lblAbertoGer = $minsAbertoGer === null
                        ? '—'
                        : ($minsAbertoGer >= 60
                            ? (int)floor($minsAbertoGer / 60) . 'h ' . ($minsAbertoGer % 60) . 'm'
                            : $minsAbertoGer . ' min');
                    $faltaTurnoGer = empty($turnoAtivoOperador);
                    ?>

                    <?php if (!empty($isGerenteCaixa)): ?>
                        <div class="card mb-3" style="border:1px solid #fed7aa;background:linear-gradient(135deg,#fff7ed,#fff);">
                            <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
                                <div>
                                    <div class="text-uppercase fw-bold small" style="color:#c2410c;letter-spacing:.04em;">Visão do gerente</div>
                                    <h2 class="h4 mb-1" style="font-family:'Space Grotesk',sans-serif;">Estado do caixa e salão</h2>
                                    <p class="text-muted mb-0 small">Sem abrir/fechar gaveta — só acompanhar e destravar a operação.</p>
                                </div>
                                <div class="d-flex flex-wrap gap-2">
                                    <a href="caixa_mesas.php" class="btn btn-sm btn-primary"><i class="fas fa-receipt me-1"></i>Contas</a>
                                    <a href="pedidos.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-utensils me-1"></i>Pedidos</a>
                                    <a href="mesas.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-chair me-1"></i>Mesas</a>
                                </div>
                            </div>
                        </div>

                        <?php if ($faltaTurnoGer): ?>
                            <div class="alert alert-warning py-2 px-3 mb-3">
                                <i class="fas fa-exclamation-triangle me-2"></i>Sem turno ativo —
                                <a href="iniciar_turno.php" class="alert-link">iniciar turno</a> para operar no salão.
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($empCaixa['alerta_contas'])): ?>
                            <div class="alert alert-info py-2 px-3 mb-3">
                                <i class="fas fa-info-circle me-2"></i>
                                Existem <strong><?php echo (int)$empCaixa['contas_abertas']; ?></strong> contas em aberto
                                (<?php echo number_format((float)$empCaixa['valor_aberto'], 2, ',', '.'); ?> MZN).
                                <a href="caixa_mesas.php" class="alert-link ms-1">Ver contas</a>
                            </div>
                        <?php endif; ?>

                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <div class="card h-100" style="border-left:4px solid <?php echo $caixa_aberto ? 'var(--success)' : 'var(--danger)'; ?>;">
                                    <div class="card-body">
                                        <div class="text-muted small mb-1">Estado do caixa</div>
                                        <div class="fw-bold fs-4" style="color:<?php echo $caixa_aberto ? 'var(--success)' : 'var(--danger)'; ?>;">
                                            <?php echo $caixa_aberto ? 'ABERTO' : 'FECHADO'; ?>
                                        </div>
                                        <?php if ($caixa_aberto): ?>
                                            <?php
                                            $opNomeGer = (string)($caixa_aberto['usuario_nome'] ?? '');
                                            if ($opNomeGer === '' && !empty($caixa_aberto['usuario_id'])) {
                                                try {
                                                    $stOp = $db->prepare('SELECT nome FROM usuarios WHERE id = :id LIMIT 1');
                                                    $stOp->execute([':id' => (int)$caixa_aberto['usuario_id']]);
                                                    $opNomeGer = (string)($stOp->fetchColumn() ?: '');
                                                } catch (Throwable $e) {
                                                    $opNomeGer = '';
                                                }
                                            }
                                            if ($opNomeGer === '') {
                                                $opNomeGer = 'Operador';
                                            }
                                            ?>
                                            <div class="small text-muted mt-2">
                                                <?php echo date('d/m/Y H:i', strtotime($caixa_aberto['data_abertura'])); ?>
                                                · <?php echo htmlspecialchars($opNomeGer); ?>
                                                · há <?php echo htmlspecialchars($lblAbertoGer); ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="small text-muted mt-2">Peça ao caixa/admin para abrir se o salão precisar vender.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card h-100">
                                    <div class="card-body">
                                        <div class="text-muted small mb-1">Contas abertas</div>
                                        <div class="fw-bold fs-4"><?php echo (int)$empCaixa['contas_abertas']; ?></div>
                                        <div class="small text-muted mt-2"><?php echo number_format((float)$empCaixa['valor_aberto'], 2, ',', '.'); ?> MZN em aberto</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card h-100">
                                    <div class="card-body">
                                        <div class="text-muted small mb-1">Turno</div>
                                        <div class="fw-bold fs-4" style="color:<?php echo $faltaTurnoGer ? 'var(--warning)' : 'var(--success)'; ?>;">
                                            <?php echo $faltaTurnoGer ? 'INACTIVO' : 'ACTIVO'; ?>
                                        </div>
                                        <div class="small text-muted mt-2">
                                            <?php if ($faltaTurnoGer): ?>
                                                <a href="iniciar_turno.php">Abrir Meu Turno</a>
                                            <?php else: ?>
                                                Operação liberada para o salão
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-body d-flex flex-wrap gap-2">
                                <a href="caixa_mesas.php" class="btn btn-primary"><i class="fas fa-receipt me-2"></i>Contas abertas</a>
                                <a href="pedidos.php" class="btn btn-outline-primary"><i class="fas fa-utensils me-2"></i>Pedidos</a>
                                <a href="mesas.php" class="btn btn-outline-secondary"><i class="fas fa-chair me-2"></i>Mesas</a>
                                <?php if ($faltaTurnoGer): ?>
                                    <a href="iniciar_turno.php" class="btn btn-warning"><i class="fas fa-user-clock me-2"></i>Iniciar turno</a>
                                <?php endif; ?>
                            </div>
                        </div>

                    <?php else: ?>

                    <?php if (!empty($isEmpresarialUi)): ?>
                        <?php
                        $minsAberto = $empCaixa['minutos_aberto'];
                        $lblAberto = $minsAberto === null
                            ? '—'
                            : ($minsAberto >= 60
                                ? (int)floor($minsAberto / 60) . 'h ' . ($minsAberto % 60) . 'm'
                                : $minsAberto . ' min');
                        ?>
                        <div class="caixa-emp-hero mb-3">
                            <div>
                                <h2>Caixa inteligente</h2>
                                <p>Controlo de turno, gaveta e contas em aberto — plano Empresarial.</p>
                            </div>
                            <div class="caixa-emp-hero-actions">
                                <a href="caixa_mesas.php" class="btn btn-sm btn-light"><i class="fas fa-receipt me-1"></i>Contas</a>
                                <a href="vendas.php" class="btn btn-sm btn-light"><i class="fas fa-cash-register me-1"></i>Vendas</a>
                                <a href="pedidos.php" class="btn btn-sm btn-light"><i class="fas fa-utensils me-1"></i>Pedidos</a>
                            </div>
                        </div>

                        <?php if (!empty($empCaixa['alerta_turno'])): ?>
                            <div class="alert alert-warning py-2 px-3 mb-3">
                                <i class="fas fa-exclamation-triangle me-2"></i>Caixa aberto sem turno ativo —
                                <a href="iniciar_turno.php" class="alert-link">iniciar turno</a> antes de operar.
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($caixa_aberto) && !empty($empCaixa['alerta_contas'])): ?>
                            <div class="alert alert-info py-2 px-3 mb-3">
                                <i class="fas fa-info-circle me-2"></i>
                                Existem <strong><?php echo (int)$empCaixa['contas_abertas']; ?></strong> contas em aberto
                                (<?php echo number_format((float)$empCaixa['valor_aberto'], 2, ',', '.'); ?> MZN).
                                <a href="caixa_mesas.php" class="alert-link ms-1">Ver contas</a>
                            </div>
                        <?php endif; ?>

                        <div class="caixa-emp-kpis mb-3">
                            <div class="caixa-emp-kpi kpi-orange">
                                <span class="label">Abertura</span>
                                <strong><?php echo number_format($saldo_inicial_caixa, 2, ',', '.'); ?></strong>
                                <small>MZN</small>
                            </div>
                            <div class="caixa-emp-kpi kpi-green">
                                <span class="label">Vendas do caixa</span>
                                <strong><?php echo number_format($total_vendas, 2, ',', '.'); ?></strong>
                                <small><?php echo (int)$empCaixa['qtd_vendas']; ?> vendas</small>
                            </div>
                            <div class="caixa-emp-kpi kpi-amber">
                                <span class="label">Gaveta esperada</span>
                                <strong><?php echo number_format((float)$empCaixa['esperado_gaveta'], 2, ',', '.'); ?></strong>
                                <small>abertura + dinheiro<?php echo ($movCaixa['sangria'] > 0 || $movCaixa['reforco'] > 0) ? ' ± sangrias' : ''; ?></small>
                            </div>
                            <div class="caixa-emp-kpi kpi-blue">
                                <span class="label">Digital</span>
                                <strong><?php echo number_format((float)$empCaixa['total_digital'], 2, ',', '.'); ?></strong>
                                <small>M-Pesa / e-Mola / cartão…</small>
                            </div>
                            <div class="caixa-emp-kpi">
                                <span class="label">Ticket médio</span>
                                <strong><?php echo number_format((float)$empCaixa['ticket_medio'], 2, ',', '.'); ?></strong>
                                <small>MZN / venda</small>
                            </div>
                            <div class="caixa-emp-kpi kpi-meta">
                                <span class="label">Contas abertas</span>
                                <strong><?php echo (int)$empCaixa['contas_abertas']; ?></strong>
                                <small><?php echo number_format((float)$empCaixa['valor_aberto'], 2, ',', '.'); ?> MZN</small>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (empty($isEmpresarialUi) && empty($turnoAtivoOperador) && !empty($caixa_aberto)): ?>
                        <div class="alert alert-warning py-2 px-3 mb-3">
                            <i class="fas fa-exclamation-triangle me-2"></i>Caixa aberto sem turno ativo —
                            <a href="iniciar_turno.php" class="alert-link">iniciar turno</a> antes de operar.
                        </div>
                    <?php endif; ?>

                    <?php if (empty($isEmpresarialUi) && !empty($caixa_aberto) && !empty($empCaixa['alerta_contas'])): ?>
                        <div class="alert alert-info py-2 px-3 mb-3">
                            <i class="fas fa-info-circle me-2"></i>
                            Existem <strong><?php echo (int)$empCaixa['contas_abertas']; ?></strong> conta(s) em aberto
                            (<?php echo number_format((float)$empCaixa['valor_aberto'], 2, ',', '.'); ?> MZN).
                            <a href="caixa_mesas.php" class="alert-link ms-1">Ver contas</a>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($caixaFormasPro)): ?>
                        <div class="card mb-3 caixa-pro-formas">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                    <strong><i class="fas fa-wallet me-2 text-success"></i>Entrou neste caixa, por forma de pagamento</strong>
                                    <small class="text-muted">confira a gaveta com o dinheiro</small>
                                </div>
                                <div class="caixa-pro-formas-grid">
                                    <?php
                                    $rotulosFormaPro = ['DINHEIRO' => 'Dinheiro', 'MPESA' => 'M-Pesa', 'EMOLA' => 'e-Mola', 'CARTAO' => 'Cartão', 'TRANSFERENCIA' => 'Transferência', 'OUTRO' => 'Outro'];
                                    foreach ($caixaFormasPro as $fPro):
                                        $chaveFPro = (string)$fPro['forma'];
                                    ?>
                                        <div class="caixa-pro-forma<?php echo $chaveFPro === 'DINHEIRO' ? ' is-dinheiro' : ''; ?>">
                                            <span><?php echo htmlspecialchars($rotulosFormaPro[$chaveFPro] ?? ucfirst(strtolower($chaveFPro)), ENT_QUOTES, 'UTF-8'); ?></span>
                                            <strong><?php echo number_format((float)$fPro['total'], 2, ',', '.'); ?> MZN</strong>
                                            <small><?php echo (int)$fPro['qtd']; ?> venda(s)</small>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($isEmpresarialUi)): ?>
                        <div class="caixa-emp-layout mb-4">
                            <div class="caixa-emp-main">
                                <?php if ($caixa_aberto): ?>
                                    <div class="caixa-emp-status is-open">
                                        <div class="caixa-emp-status-head">
                                            <div>
                                                <h4><i class="fas fa-check-circle me-2"></i>Caixa Aberto</h4>
                                                <p>
                                                    <?php echo date('d/m/Y H:i', strtotime($caixa_aberto['data_abertura'])); ?>
                                                    · <?php echo htmlspecialchars($caixa_aberto['usuario_nome'] ?? $_SESSION['nome']); ?>
                                                    · Aberto há <?php echo htmlspecialchars($lblAberto); ?>
                                                </p>
                                            </div>
                                            <button type="button" class="btn caixa-emp-action-btn is-close" onclick="abrirModalFechar()">
                                                <span class="caixa-emp-action-icon"><i class="fas fa-lock"></i></span>
                                                <span class="caixa-emp-action-copy">
                                                    <strong>Fechar caixa</strong>
                                                    <small>Conferir gaveta e encerrar</small>
                                                </span>
                                                <i class="fas fa-arrow-right caixa-emp-action-arrow" aria-hidden="true"></i>
                                            </button>
                                        </div>
                                        <div class="caixa-emp-mix">
                                            <h6>Mix de pagamento</h6>
                                            <?php if (empty($formasPagamentoCaixa)): ?>
                                                <p class="text-muted small mb-0">Ainda sem vendas neste caixa.</p>
                                            <?php else: ?>
                                                <?php $fpPcts = caixa_percentagens(array_map(static fn($x) => (float)($x['total'] ?? 0), $formasPagamentoCaixa)); ?>
                                                <?php foreach ($formasPagamentoCaixa as $fpIdx => $fp): ?>
                                                    <?php
                                                    $fpTotal = (float)($fp['total'] ?? 0);
                                                    $fpPct = (int)($fpPcts[$fpIdx] ?? 0);
                                                    ?>
                                                    <div class="caixa-emp-mix-row">
                                                        <span><?php echo htmlspecialchars(caixa_forma_label((string)($fp['forma'] ?? ''))); ?> · <?php echo (int)($fp['qtd'] ?? 0); ?></span>
                                                        <strong><?php echo number_format($fpTotal, 2, ',', '.'); ?> (<?php echo $fpPct; ?>%)</strong>
                                                    </div>
                                                    <div class="caixa-emp-mix-bar"><i style="width:<?php echo max(4, min(100, $fpPct)); ?>%"></i></div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="caixa-emp-recent mt-3">
                                            <h6>Últimas vendas do caixa</h6>
                                            <?php if (empty($empCaixa['ultimas_vendas'])): ?>
                                                <p class="text-muted small mb-0">Nenhuma venda registada ainda.</p>
                                            <?php else: ?>
                                                <div class="table-responsive">
                                                    <table class="table mb-0">
                                                        <thead>
                                                            <tr>
                                                                <th>Fatura</th>
                                                                <th>Pagamento</th>
                                                                <th>Total</th>
                                                                <th>Hora</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($empCaixa['ultimas_vendas'] as $uv): ?>
                                                                <tr>
                                                                    <td class="fw-bold"><?php echo htmlspecialchars((string)($uv['numero_fatura'] ?? '—')); ?></td>
                                                                    <td><?php echo htmlspecialchars(caixa_forma_label((string)($uv['forma_pagamento'] ?? ''))); ?></td>
                                                                    <td><?php echo number_format((float)($uv['total_final'] ?? 0), 2, ',', '.'); ?></td>
                                                                    <td class="text-muted small"><?php echo !empty($uv['criado_em']) ? date('H:i', strtotime($uv['criado_em'])) : '—'; ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="caixa-emp-status is-closed">
                                        <div class="caixa-emp-status-head">
                                            <div>
                                                <h4><i class="fas fa-lock me-2"></i>Caixa Fechado</h4>
                                                <p>Abra o caixa para iniciar vendas e receber contas do salão.</p>
                                            </div>
                                            <button type="button" class="btn caixa-emp-action-btn is-open" onclick="abrirModalAbrir()">
                                                <span class="caixa-emp-action-icon"><i class="fas fa-lock-open"></i></span>
                                                <span class="caixa-emp-action-copy">
                                                    <strong>Abrir caixa</strong>
                                                    <small>Definir fundo de gaveta</small>
                                                </span>
                                                <i class="fas fa-arrow-right caixa-emp-action-arrow" aria-hidden="true"></i>
                                            </button>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <aside class="caixa-emp-side">
                                <div class="caixa-emp-side-card caixa-gaveta-card">
                                    <h6><i class="fas fa-money-bill-wave me-1"></i>Gaveta</h6>
                                    <?php if ($caixa_aberto): ?>
                                        <?php if (!$ocultarEsperadoFecho): ?>
                                            <div class="caixa-gaveta-agora">
                                                <span>Deve estar na gaveta agora</span>
                                                <strong><?php echo number_format((float)$empCaixa['esperado_gaveta'], 2, ',', '.'); ?> MZN</strong>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($caixaMovDisponivel): ?>
                                            <div class="caixa-gaveta-btns">
                                                <button type="button" class="btn caixa-mov-btn is-out" data-caixa-mov="SANGRIA"><i class="fas fa-arrow-up me-1"></i>Sangria</button>
                                                <button type="button" class="btn caixa-mov-btn is-in" data-caixa-mov="REFORCO"><i class="fas fa-arrow-down me-1"></i>Reforço</button>
                                            </div>
                                            <p class="caixa-gaveta-ajuda">Sangria: dinheiro que sai da gaveta (ex.: para o cofre). Reforço: dinheiro que entra (ex.: troco).</p>
                                        <?php else: ?>
                                            <p class="caixa-gaveta-ajuda">Para registar sangrias e reforços, aplique a migração 20261108_1900_caixa_movimentos.</p>
                                        <?php endif; ?>
                                        <?php if (!empty($movCaixa['lista'])): ?>
                                            <div class="caixa-gaveta-lista">
                                                <?php foreach ($movCaixa['lista'] as $mv): $ehSang = strtoupper((string)$mv['tipo']) === 'SANGRIA'; ?>
                                                    <div class="caixa-gaveta-item">
                                                        <div>
                                                            <strong><?php echo $ehSang ? 'Sangria' : 'Reforço'; ?></strong>
                                                            <small><?php echo htmlspecialchars(date('H:i', strtotime((string)$mv['criado_em']))); ?> · <?php echo htmlspecialchars((string)($mv['motivo'] ?: '—')); ?> · <?php echo htmlspecialchars((string)($mv['usuario_nome'] ?? '—')); ?></small>
                                                        </div>
                                                        <em class="<?php echo $ehSang ? 'is-out' : 'is-in'; ?>"><?php echo ($ehSang ? '−' : '+') . number_format((float)$mv['valor'], 2, ',', '.'); ?></em>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <p class="caixa-gaveta-ajuda">Caixa fechado.</p>
                                    <?php endif; ?>
                                    <?php
                                    $ultFechado = null;
                                    foreach ($historico as $hx) { if (strtoupper((string)$hx['status']) === 'FECHADO') { $ultFechado = $hx; break; } }
                                    ?>
                                    <?php if ($ultFechado): ?>
                                        <a class="caixa-gaveta-talao" href="caixa_fecho_imprimir.php?id=<?php echo (int)$ultFechado['id']; ?>" target="_blank" rel="noopener">
                                            <i class="fas fa-print me-1"></i>Talão do último fecho (<?php echo htmlspecialchars(date('d/m H:i', strtotime((string)($ultFechado['data_fechamento'] ?: $ultFechado['data_abertura'])))); ?>)
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($podeConfigCaixa && $caixaMovDisponivel): ?>
                                        <label class="caixa-cego-toggle">
                                            <input type="checkbox" id="caixaFechoCego" <?php echo $fechoCegoAtivo ? 'checked' : ''; ?>>
                                            <span><strong>Fecho às cegas</strong><small>O operador conta o dinheiro sem ver quanto devia estar na gaveta.</small></span>
                                        </label>
                                    <?php endif; ?>
                                </div>
                            </aside>
                        </div>
                    <?php else: ?>
                        <?php if ($caixa_aberto): ?>
                            <div class="card mb-4" style="border-left: 5px solid var(--success);">
                                <div class="card-body">
                                    <div class="caixa-status-header mb-4">
                                        <div>
                                            <h4 style="color: var(--success);"><i class="fas fa-check-circle me-2"></i>Caixa Aberto</h4>
                                            <p class="text-muted mb-0">
                                                Aberto em: <?php echo date('d/m/Y H:i', strtotime($caixa_aberto['data_abertura'])); ?>
                                                &bull; Operador: <?php echo htmlspecialchars($caixa_aberto['usuario_nome'] ?? $_SESSION['nome']); ?>
                                            </p>
                                        </div>
                                        <button class="btn btn-danger" onclick="abrirModalFechar()"><i class="fas fa-lock me-2"></i>Fechar Caixa</button>
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <div class="stat-card">
                                                <div class="stat-value"><?php echo number_format($saldo_inicial_caixa, 2, ',', '.'); ?></div>
                                                <div class="stat-label">Valor de Abertura (MZN)</div>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="stat-card">
                                                <div class="stat-value" style="color: var(--success);"><?php echo number_format($total_vendas, 2, ',', '.'); ?></div>
                                                <div class="stat-label">Total em Vendas (MZN)</div>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="stat-card stat-card-highlight">
                                                <div class="stat-value"><?php echo number_format($saldo_esperado_caixa, 2, ',', '.'); ?></div>
                                                <div class="stat-label">Saldo Esperado (MZN)</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="card mb-4" style="border-left: 5px solid var(--danger);">
                                <div class="card-body">
                                    <div class="caixa-status-header">
                                        <div>
                                            <h4 style="color: var(--danger);"><i class="fas fa-lock me-2"></i>Caixa Fechado</h4>
                                            <p class="text-muted mb-0">Abra o caixa para iniciar as vendas do dia.</p>
                                        </div>
                                        <button class="btn btn-success" onclick="abrirModalAbrir()"><i class="fas fa-lock-open me-2"></i>Abrir Caixa</button>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- HISTÓRICO -->
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <h3 class="card-title mb-0"><i class="fas fa-history"></i> Histórico de Caixas</h3>
                            <?php if (!empty($isEmpresarialUi)): ?>
                                <span class="badge-custom badge-success"><?php echo count($historico); ?> registos</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table" id="caixaHistoricoTable">
                                    <thead>
                                        <tr>
                                            <th>Data</th>
                                            <th>Operador</th>
                                            <th>Abertura</th>
                                            <th>Fechamento</th>
                                            <th>Total Vendas</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($historico as $h): ?>
                                            <tr>
                                                <td><?php echo date('d/m/Y', strtotime($h['data_abertura'])); ?></td>
                                                <td><?php echo htmlspecialchars($h['usuario_nome'] ?? '—'); ?></td>
                                                <td><?php echo number_format($h['saldo_inicial'], 2, ',', '.'); ?> MZN</td>
                                                <td><?php echo (strtoupper((string)$h['status']) === 'FECHADO' && $h['saldo_final'] !== null) ? number_format((float)$h['saldo_final'], 2, ',', '.') . ' MZN' : '<span class="text-muted">—</span>'; ?></td>
                                                <td><?php echo number_format($caixa->totalVendas($h['id']), 2, ',', '.'); ?> MZN</td>
                                                <td><span class="badge-custom badge-<?php echo $h['status'] == 'ABERTO' ? 'success' : 'danger'; ?>"><?php echo $h['status']; ?></span>
                                                    <?php if (strtoupper((string)$h['status']) === 'FECHADO'): ?>
                                                        <a class="caixa-hist-print" href="caixa_fecho_imprimir.php?id=<?php echo (int)$h['id']; ?>" target="_blank" rel="noopener" title="Imprimir talão de fecho"><i class="fas fa-print"></i></a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($historico)): ?>
                                            <tr>
                                                <td colspan="6" class="text-center py-4 text-muted"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>Nenhum registro</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; /* operador caixa/admin */ ?>
                </div>
            </main>
        </div>
    </div>

    <?php if (empty($isGerenteCaixa)): ?>
    <!-- MODAL ABRIR CAIXA -->
    <div class="modal fade<?php echo !empty($isEmpresarialUi) ? ' caixa-emp-modal' : ''; ?>" id="modalAbrir" tabindex="-1" aria-labelledby="modalAbrirTitulo" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered<?php echo !empty($isEmpresarialUi) ? ' modal-lg caixa-emp-dialog' : ''; ?>">
            <div class="modal-content<?php echo !empty($isEmpresarialUi) ? ' caixa-emp-content' : ''; ?>">
                <div class="modal-header">
                    <?php if (!empty($isEmpresarialUi)): ?>
                        <div class="caixa-emp-heading">
                            <span class="caixa-emp-heading-icon"><i class="fas fa-lock-open"></i></span>
                            <div>
                                <span class="caixa-emp-kicker"><i class="fas fa-crown"></i> Operação de caixa</span>
                                <h5 class="modal-title" id="modalAbrirTitulo">Abrir caixa</h5>
                                <p>Registe o fundo em dinheiro da gaveta para iniciar as vendas do turno.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <h5 class="modal-title" id="modalAbrirTitulo"><i class="fas fa-lock-open me-2"></i>Abrir Caixa</h5>
                    <?php endif; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <?php if (empty($isEmpresarialUi)): ?>
                        <p class="text-muted">Informe o valor inicial em dinheiro no caixa.</p>
                    <?php endif; ?>
                    <div class="alert" id="alertAbrir" style="display: none;"></div>
                    <form id="formAbrir"<?php echo !empty($isEmpresarialUi) ? ' class="caixa-emp-form"' : ''; ?>>
                        <div class="mb-3">
                            <label class="form-label" for="valor_abertura">Dinheiro inicial na gaveta (MZN) *</label>
                            <input type="number" id="valor_abertura" class="form-control" step="0.01" min="0" required placeholder="Dinheiro inicial na gaveta para troco">
                            <div class="form-text">Este valor fica como fundo de caixa e entra no esperado da gaveta no fecho.</div>
                        </div>
                    </form>
                    <?php if (!empty($isEmpresarialUi)): ?>
                        <aside class="caixa-emp-preview" aria-live="polite">
                            <div class="caixa-emp-preview-top">
                                <span>Pré-visualização da abertura</span>
                                <em><i class="fas fa-circle"></i> A iniciar</em>
                            </div>
                            <div class="caixa-emp-preview-card">
                                <span class="caixa-emp-preview-icon"><i class="fas fa-coins"></i></span>
                                <div>
                                    <strong id="caixaOpenPreviewValor">Fundo de gaveta</strong>
                                    <small>Dinheiro físico para troco</small>
                                </div>
                            </div>
                            <p><i class="fas fa-circle-info"></i> O caixa só abre depois de confirmar este fundo. Digital não entra nesta contagem.</p>
                        </aside>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" form="formAbrir" class="btn<?php echo !empty($isEmpresarialUi) ? ' caixa-emp-submit is-open' : ' btn-success'; ?>"><i class="fas fa-lock-open me-2"></i>Abrir caixa</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL FECHAR CAIXA -->
    <div class="modal fade<?php echo !empty($isEmpresarialUi) ? ' caixa-emp-modal' : ''; ?>" id="modalFechar" tabindex="-1" aria-labelledby="modalFecharTitulo" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered<?php echo !empty($isEmpresarialUi) ? ' modal-lg caixa-emp-dialog' : ''; ?>">
            <div class="modal-content<?php echo !empty($isEmpresarialUi) ? ' caixa-emp-content is-close' : ''; ?>">
                <div class="modal-header">
                    <?php if (!empty($isEmpresarialUi)): ?>
                        <div class="caixa-emp-heading">
                            <span class="caixa-emp-heading-icon"><i class="fas fa-lock"></i></span>
                            <div>
                                <span class="caixa-emp-kicker"><i class="fas fa-crown"></i> Conferência de gaveta</span>
                                <h5 class="modal-title" id="modalFecharTitulo">Fechar caixa</h5>
                                <p>Conte o dinheiro físico da gaveta e compare com o esperado do turno.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <h5 class="modal-title" id="modalFecharTitulo"><i class="fas fa-lock me-2"></i>Fechar Caixa</h5>
                    <?php endif; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <div class="alert" id="alertFechar" style="display: none;"></div>
                    <form id="formFechar"<?php echo !empty($isEmpresarialUi) ? ' class="caixa-emp-form"' : ''; ?>>
                        <div class="mb-3">
                            <label class="form-label" for="valor_fechamento">Dinheiro contado na gaveta (MZN) *</label>
                            <input type="text" id="valor_fechamento" class="form-control" inputmode="decimal" autocomplete="off" required placeholder="Dinheiro contado na gaveta agora" aria-describedby="fechoHint">
                            <small id="fechoHint" class="form-text d-block mt-1">Aceita 8983 ou 8.983,00. Conte só o dinheiro físico.</small>
                            <?php if ($ocultarEsperadoFecho): ?>
                                <div class="caixa-cego-aviso"><i class="fas fa-eye-slash me-1"></i>Fecho às cegas: conte o dinheiro e escreva o valor. A diferença só aparece depois.</div>
                            <?php elseif (!empty($isEmpresarialUi) && (float)$empCaixa['esperado_gaveta'] > 0): ?>
                                <button type="button" class="caixa-emp-expected-chip" id="btnUsarEsperadoGaveta">
                                    Usar esperado na gaveta (<?php echo number_format((float)$empCaixa['esperado_gaveta'], 2, ',', '.'); ?> MZN)
                                </button>
                            <?php endif; ?>
                        </div>
                        <?php if (empty($isEmpresarialUi)): ?>
                        <div style="background: var(--bg); border-radius: 16px; padding: 20px;">
                            <div class="d-flex justify-content-between mb-2"><span>Abertura:</span><strong><?php echo number_format($saldo_inicial_caixa, 2, ',', '.'); ?> MZN</strong></div>
                            <div class="d-flex justify-content-between mb-2"><span>Vendas:</span><strong><?php echo number_format($total_vendas, 2, ',', '.'); ?> MZN</strong></div>
                            <hr>
                            <div class="d-flex justify-content-between"><span>Esperado:</span><strong style="color: var(--success);"><?php echo number_format($saldo_esperado_caixa, 2, ',', '.'); ?> MZN</strong></div>
                        </div>
                        <?php endif; ?>
                    </form>
                    <?php if (!empty($isEmpresarialUi) && !$ocultarEsperadoFecho): ?>
                        <aside class="caixa-emp-preview" aria-live="polite">
                            <div class="caixa-emp-preview-top">
                                <span>Resumo do fecho</span>
                                <em><i class="fas fa-circle"></i> Gaveta</em>
                            </div>
                            <div class="caixa-emp-close-rows">
                                <div><span>Abertura</span><strong><?php echo number_format($saldo_inicial_caixa, 2, ',', '.'); ?> MZN</strong></div>
                                <div><span>Vendas</span><strong><?php echo number_format($total_vendas, 2, ',', '.'); ?> MZN</strong></div>
                                <div><span>Dinheiro (vendas)</span><strong><?php echo number_format((float)$empCaixa['total_dinheiro'], 2, ',', '.'); ?> MZN</strong></div>
                                <div><span>Digital</span><strong><?php echo number_format((float)$empCaixa['total_digital'], 2, ',', '.'); ?> MZN</strong></div>
                                <?php if ($movCaixa['sangria'] > 0 || $movCaixa['reforco'] > 0): ?>
                                    <div><span>Sangrias / reforços</span><strong>−<?php echo number_format($movCaixa['sangria'], 2, ',', '.'); ?> / +<?php echo number_format($movCaixa['reforco'], 2, ',', '.'); ?></strong></div>
                                <?php endif; ?>
                                <div class="is-expected"><span>Esperado na gaveta</span><strong><?php echo number_format((float)$empCaixa['esperado_gaveta'], 2, ',', '.'); ?> MZN</strong></div>
                                <div class="is-diff"><span>Diferença</span><strong id="caixaDiffFecho">0,00 MZN</strong></div>
                            </div>
                            <p><i class="fas fa-circle-info"></i> A diferença compara o valor contado com abertura + vendas em dinheiro + reforços − sangrias.</p>
                        </aside>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" form="formFechar" class="btn<?php echo !empty($isEmpresarialUi) ? ' caixa-emp-submit is-close' : ' btn-danger'; ?>"><i class="fas fa-lock me-2"></i>Fechar caixa</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; /* modais só operador */ ?>

    <script src="lib/bootstrap-5.3.2/js/bootstrap.bundle.min.js"></script>
    <?php if (empty($isGerenteCaixa)): ?>
    <script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
        const CSRF_TOKEN = <?php echo json_encode($csrfToken, JSON_UNESCAPED_UNICODE); ?>;
        const IS_EMPRESARIAL_CAIXA = <?php echo !empty($isEmpresarialUi) ? 'true' : 'false'; ?>;
        const CAIXA_ESPERADO_GAVETA = <?php echo json_encode((float)$empCaixa['esperado_gaveta']); ?>;
        const CAIXA_FECHO_CEGO = <?php echo $ocultarEsperadoFecho ? 'true' : 'false'; ?>;
    </script>
    <script src="js/receipt_print.js?v=20260821a"></script>
    <script src="js/offline_sync.js?v=20261101b"></script>
    <script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">OfflineSync.init({ csrfToken: CSRF_TOKEN, syncIntervalMs: 10000 });</script>
    <script src="js/caixa.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/caixa.js'); ?>"></script>
    <?php endif; ?>
    <script src="js/nivora_dialogo.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nivora_dialogo.js'); ?>"></script>
    <script src="js/autorizacao_gerente.js?v=20261108d" data-csrf="<?php echo htmlspecialchars((string)csrf_get_token(), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <?php if (!empty($isEmpresarialUi)): ?>
    <script src="js/caixa_gaveta.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/caixa_gaveta.js'); ?>" data-csrf="<?php echo htmlspecialchars((string)csrf_get_token(), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <?php endif; ?>
</body>

</html>
