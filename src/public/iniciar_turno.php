<?php
require_once __DIR__ . '/app_base.php';
require_once APP_BASE . '/src/config/auth_check.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/csrf.php';
require_once APP_BASE . '/src/config/restaurante_context.php';
require_once APP_BASE . '/src/Service/TurnoService.php';
require_once APP_BASE . '/src/config/turno_helpers.php';
require_once APP_BASE . '/src/config/plano_check.php';

if (intval($_SESSION['super_admin'] ?? 0) === 1) {
    header('Location: dashboard.php');
    exit;
}

function turno_redirect_destino($perfil): string
{
    return function_exists('turno_home_por_perfil')
        ? turno_home_por_perfil($perfil)
        : 'dashboard.php';
}

$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$restauranteId = function_exists('session_restaurante_auth_id')
    ? session_restaurante_auth_id()
    : (int)($_SESSION['restaurante_base_id'] ?? ($_SESSION['restaurante_id'] ?? 0));
$perfil = turno_normalizar_perfil($_SESSION['perfil'] ?? '');
$perfilExibicao = intval($_SESSION['super_admin'] ?? 0) === 1 ? 'SUPER ADMIN' : $perfil;
$nomeUsuario = trim((string)($_SESSION['nome'] ?? 'Utilizador'));
$nomeCurto = explode(' ', $nomeUsuario)[0] ?: 'Utilizador';
$partesNome = preg_split('/\s+/', $nomeUsuario);
$iniciais = mb_strtoupper(
    mb_substr((string)($partesNome[0] ?? 'U'), 0, 1, 'UTF-8') . mb_substr((string)($partesNome[1] ?? ''), 0, 1, 'UTF-8'),
    'UTF-8'
);
$fotoUsuario = trim((string)($_SESSION['foto'] ?? ''));
$publicBase = rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')), '/\\');
$fotoUrl = '';
if ($fotoUsuario !== '') {
    if (strpos($fotoUsuario, 'http://') === 0 || strpos($fotoUsuario, 'https://') === 0 || strpos($fotoUsuario, '/') === 0) {
        $fotoUrl = $fotoUsuario;
    } else {
        $fotoUrl = $publicBase . '/' . ltrim($fotoUsuario, '/');
    }
}

$database = new Database();
$turnoService = new TurnoService($database);

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    $acaoPost = strtolower(trim((string)($_POST['acao'] ?? '')));
    if (!csrf_is_valid()) {
        $_SESSION['turno_flash_msg'] = 'Sessão expirada ou token inválido. Recarregue a página.';
        $_SESSION['turno_flash_type'] = 'danger';
        header('Location: iniciar_turno.php');
        exit;
    }

    try {
        if ($usuarioId <= 0 || $restauranteId <= 0) {
            throw new RuntimeException('Contexto de utilizador/restaurante inválido.');
        }
        if ($acaoPost === 'iniciar') {
            $tipoPedido = strtolower(trim((string)($_POST['turno'] ?? '')));
            $turnoService->iniciarTurno(
                $usuarioId,
                $restauranteId,
                in_array($tipoPedido, ['manha', 'noite', 'integral'], true) ? $tipoPedido : null
            );
            $_SESSION['turno_flash_msg'] = 'Turno iniciado com sucesso.';
            $_SESSION['turno_flash_type'] = 'success';
            header('Location: ' . turno_redirect_destino($perfil));
            exit;
        }
        if ($acaoPost === 'encerrar') {
            $turnoService->encerrarTurno($usuarioId, $restauranteId);
            $_SESSION['turno_flash_msg'] = 'Turno encerrado com sucesso.';
            $_SESSION['turno_flash_type'] = 'success';
            header('Location: iniciar_turno.php');
            exit;
        }
        $_SESSION['turno_flash_msg'] = 'Acção de turno inválida.';
        $_SESSION['turno_flash_type'] = 'danger';
        header('Location: iniciar_turno.php');
        exit;
    } catch (Throwable $e) {
        $_SESSION['turno_flash_msg'] = security_exception_message(
            $e,
            'Erro ao processar a operação de turno.',
            'turno_operacao_page_failed'
        );
        $_SESSION['turno_flash_type'] = 'danger';
        header('Location: iniciar_turno.php');
        exit;
    }
}

$turnoAtivo = null;
if ($usuarioId > 0 && $restauranteId > 0) {
    $turnoAtivo = $turnoService->obterTurnoAtivoUsuario($usuarioId, $restauranteId);
}

// Após login: se o turno já está activo, saltar esta página e ir à tela de trabalho
if (!empty($turnoAtivo) && !empty($_SESSION['login_goto_home'])) {
    unset($_SESSION['login_goto_home']);
    header('Location: ' . turno_redirect_destino($perfil));
    exit;
}

$alertaInicial = '';
$alertaTipo = 'warning';
if (($_GET['erro'] ?? '') === 'turno_obrigatorio') {
    $alertaInicial = 'Inicie o seu turno para aceder ao sistema.';
}
if (!empty($_SESSION['turno_flash_msg'])) {
    $alertaInicial = (string)$_SESSION['turno_flash_msg'];
    $alertaTipo = (string)($_SESSION['turno_flash_type'] ?? 'warning');
    unset($_SESSION['turno_flash_msg'], $_SESSION['turno_flash_type']);
}

// ---- Contexto da unidade e leitura da casa (Empresarial) ----
$db = $database->getConnection();
$unidadeNome = '';
$planoTurno = 'BASICO';
try {
    $stUn = $db->prepare('SELECT nome, COALESCE(is_matriz, 0) AS is_matriz, plano FROM restaurantes WHERE id = :id LIMIT 1');
    $stUn->execute([':id' => $restauranteId]);
    $linhaUn = $stUn->fetch(PDO::FETCH_ASSOC) ?: [];
    $unidadeNome = (string)($linhaUn['nome'] ?? '');
    if (function_exists('plano_get_dados') && function_exists('plano_normalizar_nome')) {
        $planoTurno = plano_normalizar_nome(plano_get_dados($restauranteId)['plano_nome'] ?? ($linhaUn['plano'] ?? 'BASICO'));
    } else {
        $planoTurno = strtoupper((string)($linhaUn['plano'] ?? 'BASICO'));
    }
    $unidadeEhMatriz = (int)($linhaUn['is_matriz'] ?? 0) === 1;
} catch (Throwable $e) {
    $unidadeEhMatriz = false;
}
$turnoEmpresarial = ($planoTurno === 'EMPRESARIAL');

// Turno de um dia anterior ainda aberto (item 11)
$turnoAtrasado = null;
if ($turnoAtivo && !empty($turnoAtivo['data']) && (string)$turnoAtivo['data'] < date('Y-m-d')) {
    $turnoAtrasado = $turnoAtivo;
}

// Hora prevista do turno e atraso (item 9)
$turnoTipoAgora = function_exists('turno_detectar_tipo_atual')
    ? strtolower(turno_detectar_tipo_atual(new DateTimeImmutable('now')))
    : 'manha';
if (!in_array($turnoTipoAgora, ['manha', 'noite', 'integral'], true)) {
    $turnoTipoAgora = 'manha';
}
$horasPrevistas = ['manha' => '08:00', 'integral' => '08:00', 'noite' => '18:00'];
$turnoAtrasoMin = 0;
if ($turnoAtivo && !empty($turnoAtivo['hora_entrada']) && !$turnoAtrasado) {
    $tipoAtivo = strtolower((string)($turnoAtivo['turno'] ?? 'manha'));
    $prevista = $horasPrevistas[$tipoAtivo] ?? '08:00';
    $tsEntrada = strtotime((string)$turnoAtivo['data'] . ' ' . substr((string)$turnoAtivo['hora_entrada'], 0, 5));
    $tsPrevista = strtotime((string)$turnoAtivo['data'] . ' ' . $prevista);
    if ($tsEntrada && $tsPrevista && $tsEntrada > $tsPrevista) {
        $turnoAtrasoMin = (int)round(($tsEntrada - $tsPrevista) / 60);
    }
}

// Leitura da casa: o que espera o operador (item 7)
$turnoCasa = null;
if ($turnoEmpresarial && $restauranteId > 0) {
    $turnoCasa = ['caixa_aberto' => false, 'contas_abertas' => 0, 'pedidos_cozinha' => 0, 'colegas' => 0];
    $consultas = [
        'caixa_aberto' => "SELECT COUNT(*) FROM caixas WHERE restaurante_id = :rid AND UPPER(status) = 'ABERTO'",
        'contas_abertas' => "SELECT COUNT(*) FROM pedidos WHERE restaurante_id = :rid AND status IN ('CONTA', 'CONTA_SOLICITADA', 'ENTREGUE')",
        'pedidos_cozinha' => "SELECT COUNT(*) FROM pedidos WHERE restaurante_id = :rid AND status IN ('NOVO', 'PENDENTE', 'CONFIRMADO', 'PREPARANDO', 'EM_ANDAMENTO')",
        'colegas' => "SELECT COUNT(DISTINCT usuario_id) FROM funcionarios_turnos WHERE restaurante_id = :rid AND UPPER(status) = 'ATIVO' AND usuario_id <> :uid",
    ];
    foreach ($consultas as $chaveCasa => $sqlCasa) {
        try {
            $stCasa = $db->prepare($sqlCasa);
            $paramsCasa = [':rid' => $restauranteId];
            if (strpos($sqlCasa, ':uid') !== false) {
                $paramsCasa[':uid'] = $usuarioId;
            }
            $stCasa->execute($paramsCasa);
            $valorCasa = (int)$stCasa->fetchColumn();
            $turnoCasa[$chaveCasa] = $chaveCasa === 'caixa_aberto' ? $valorCasa > 0 : $valorCasa;
        } catch (Throwable $e) {
            error_log('[iniciar_turno][casa][' . $chaveCasa . '] ' . $e->getMessage());
        }
    }
}

// Profissional: leitura simples da casa e ultimos turnos do proprio utilizador.
$turnoProfissional = ($planoTurno === 'PROFISSIONAL');
$turnoCasaPro = null;
$turnoHistoricoPro = [];
$turnoSemanaMin = 0;
if ($turnoProfissional && $restauranteId > 0) {
    $turnoCasaPro = ['caixa_aberto' => false, 'contas_abertas' => 0, 'colegas' => 0];
    try {
        $stCx = $db->prepare("SELECT COUNT(*) FROM caixas WHERE restaurante_id = :rid AND UPPER(status) = 'ABERTO'");
        $stCx->execute([':rid' => $restauranteId]);
        $turnoCasaPro['caixa_aberto'] = (int)$stCx->fetchColumn() > 0;

        $stCt = $db->prepare("SELECT COUNT(*) FROM pedidos WHERE restaurante_id = :rid AND status IN ('CONTA', 'CONTA_SOLICITADA', 'ENTREGUE')");
        $stCt->execute([':rid' => $restauranteId]);
        $turnoCasaPro['contas_abertas'] = (int)$stCt->fetchColumn();

        $stCo = $db->prepare("SELECT COUNT(DISTINCT usuario_id) FROM funcionarios_turnos WHERE restaurante_id = :rid AND UPPER(status) = 'ATIVO' AND usuario_id <> :uid");
        $stCo->execute([':rid' => $restauranteId, ':uid' => $usuarioId]);
        $turnoCasaPro['colegas'] = (int)$stCo->fetchColumn();
    } catch (Throwable $e) {
        error_log('[TURNO][PRO_CASA] ' . $e->getMessage());
    }

    try {
        $duracaoProTurno = "GREATEST(0, TIMESTAMPDIFF(MINUTE,
            TIMESTAMP(t.data, t.hora_entrada),
            CASE
                WHEN UPPER(t.status) = 'ATIVO' AND t.hora_saida IS NULL THEN NOW()
                WHEN t.hora_saida IS NULL THEN TIMESTAMP(t.data, t.hora_entrada)
                WHEN t.data_saida IS NOT NULL THEN TIMESTAMP(t.data_saida, t.hora_saida)
                WHEN t.hora_saida < t.hora_entrada THEN TIMESTAMP(DATE_ADD(t.data, INTERVAL 1 DAY), t.hora_saida)
                ELSE TIMESTAMP(t.data, t.hora_saida)
            END))";
        $stHist = $db->prepare("
            SELECT t.data, t.hora_entrada, t.hora_saida, t.turno, t.status, {$duracaoProTurno} AS minutos
            FROM funcionarios_turnos t
            WHERE t.usuario_id = :uid AND t.restaurante_id = :rid AND t.hora_entrada IS NOT NULL
            ORDER BY t.data DESC, t.hora_entrada DESC, t.id DESC
            LIMIT 3
        ");
        $stHist->execute([':uid' => $usuarioId, ':rid' => $restauranteId]);
        $turnoHistoricoPro = $stHist->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stSem = $db->prepare("
            SELECT COALESCE(SUM({$duracaoProTurno}), 0)
            FROM funcionarios_turnos t
            WHERE t.usuario_id = :uid AND t.restaurante_id = :rid AND t.hora_entrada IS NOT NULL
              AND t.data >= :ini
        ");
        $stSem->execute([
            ':uid' => $usuarioId,
            ':rid' => $restauranteId,
            ':ini' => (new DateTimeImmutable('monday this week'))->format('Y-m-d'),
        ]);
        $turnoSemanaMin = (int)$stSem->fetchColumn();
    } catch (Throwable $e) {
        error_log('[TURNO][PRO_HIST] ' . $e->getMessage());
    }
}

// Resumo do ultimo turno encerrado hoje (item 10)
$turnoResumo = null;
if ($turnoEmpresarial && !$turnoAtivo && $usuarioId > 0 && $restauranteId > 0) {
    try {
        $stFim = $db->prepare("
            SELECT data, data_saida, hora_entrada, hora_saida, turno
            FROM funcionarios_turnos
            WHERE usuario_id = :uid AND restaurante_id = :rid
              AND UPPER(status) IN ('ENCERRADO', 'FINALIZADO')
              AND hora_saida IS NOT NULL
              AND COALESCE(data_saida, data) = CURDATE()
            ORDER BY COALESCE(data_saida, data) DESC, hora_saida DESC, id DESC
            LIMIT 1
        ");
        $stFim->execute([':uid' => $usuarioId, ':rid' => $restauranteId]);
        $linhaFim = $stFim->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($linhaFim) {
            $inicioTs = strtotime((string)$linhaFim['data'] . ' ' . (string)$linhaFim['hora_entrada']);
            $fimTs = strtotime((string)($linhaFim['data_saida'] ?: $linhaFim['data']) . ' ' . (string)$linhaFim['hora_saida']);
            $minutos = ($inicioTs && $fimTs && $fimTs > $inicioTs) ? (int)round(($fimTs - $inicioTs) / 60) : 0;

            $stVendasFim = $db->prepare("
                SELECT COUNT(*) AS qtd, COALESCE(SUM(total_final), 0) AS total
                FROM vendas
                WHERE restaurante_id = :rid AND usuario_id = :uid AND status = 'PAGO'
                  AND criado_em BETWEEN :ini AND :fim
            ");
            $stVendasFim->execute([
                ':rid' => $restauranteId,
                ':uid' => $usuarioId,
                ':ini' => date('Y-m-d H:i:s', $inicioTs ?: time()),
                ':fim' => date('Y-m-d H:i:s', $fimTs ?: time()),
            ]);
            $vendasFim = $stVendasFim->fetch(PDO::FETCH_ASSOC) ?: ['qtd' => 0, 'total' => 0];

            $pedidosFim = 0;
            try {
                $stPedFim = $db->prepare("
                    SELECT COUNT(*) FROM pedidos
                    WHERE restaurante_id = :rid AND garcom_id = :uid
                      AND criado_em BETWEEN :ini AND :fim AND UPPER(status) <> 'CANCELADO'
                ");
                $stPedFim->execute([
                    ':rid' => $restauranteId,
                    ':uid' => $usuarioId,
                    ':ini' => date('Y-m-d H:i:s', $inicioTs ?: time()),
                    ':fim' => date('Y-m-d H:i:s', $fimTs ?: time()),
                ]);
                $pedidosFim = (int)$stPedFim->fetchColumn();
            } catch (Throwable $e) {
            }

            $turnoResumo = [
                'minutos' => $minutos,
                'duracao' => intdiv($minutos, 60) . 'h' . str_pad((string)($minutos % 60), 2, '0', STR_PAD_LEFT),
                'entrada' => substr((string)$linhaFim['hora_entrada'], 0, 5),
                'saida' => substr((string)$linhaFim['hora_saida'], 0, 5),
                'vendas_qtd' => (int)$vendasFim['qtd'],
                'vendas_total' => (float)$vendasFim['total'],
                'pedidos' => $pedidosFim,
            ];
        }
    } catch (Throwable $e) {
        error_log('[iniciar_turno][resumo] ' . $e->getMessage());
    }
}

$destinoPosInicio = turno_redirect_destino($perfil);
$csrfToken = csrf_get_token();
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meu Turno - RestauranteSaaS</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link href="lib/bootstrap-5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="lib/fontawesome-6.5.1/css/all.min.css">
    <script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">const CSRF_TOKEN = <?php echo json_encode($csrfToken, JSON_UNESCAPED_UNICODE); ?>;</script>
    <style>
        :root {
            --surface: #ffffff;
            --surface-soft: #f8fafc;
            --line: rgba(15, 23, 42, 0.08);
            --ink: #102542;
            --muted: #64748b;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
        }

        body {
            background: #f3f6fb;
            color: var(--ink);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .main-content {
            margin-left: 280px;
            padding: 24px;
        }

        /* O .row da grelha puxava o conteudo para debaixo da barra lateral. */
        .container-fluid > .row {
            margin-left: 0;
            margin-right: 0;
        }

        .container-fluid > .row > .main-content {
            flex: 1 1 auto;
            width: auto;
            max-width: calc(100% - 280px);
            min-width: 0;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }

            .container-fluid > .row > .main-content {
                max-width: 100%;
            }
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
        }

        .page-title {
            margin: 0;
            font-size: 2rem;
            font-weight: 800;
            color: #17325c;
        }

        .page-subtitle {
            color: var(--muted);
            margin-top: 6px;
        }

        .top-bar-right {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .top-bar-date,
        .top-bar-user-chip {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 999px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.05);
        }

        .top-bar-date {
            padding: 12px 18px;
            font-weight: 700;
            color: #334155;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .top-bar-user-chip {
            display: inline-flex;
            align-items: center;
            gap: 14px;
            padding: 10px 16px 10px 12px;
        }

        .top-bar-user-chip img,
        .chip-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            object-fit: cover;
        }

        .chip-avatar {
            background: linear-gradient(135deg, #f97316, #d97706);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
        }

        .chip-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .chip-name {
            font-weight: 800;
            font-size: 1.05rem;
        }

        .chip-role {
            display: inline-flex;
            align-items: center;
            width: fit-content;
            padding: 4px 12px;
            border-radius: 999px;
            background: #eef2ff;
            color: #1d4ed8;
            font-size: 0.85rem;
            font-weight: 800;
        }

        .turno-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 28px;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.08);
            overflow: hidden;
        }

        .turno-card-header {
            padding: 24px 28px;
            border-bottom: 1px solid var(--line);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .turno-card-title {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            gap: 12px;
        }

        .turno-card-body {
            padding: 28px;
        }

        .turno-summary {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 24px;
            align-items: center;
        }

        .turno-meta {
            display: grid;
            gap: 14px;
        }

        .turno-line {
            font-size: 1.05rem;
        }

        .turno-line strong {
            color: #0f172a;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            padding: 7px 14px;
            border-radius: 999px;
            font-size: 0.9rem;
            font-weight: 800;
        }

        .status-pill.active {
            background: rgba(16, 185, 129, 0.12);
            color: #059669;
        }

        .status-pill.inactive {
            background: rgba(148, 163, 184, 0.14);
            color: #475569;
        }

        .turno-empty {
            color: var(--muted);
            font-size: 1rem;
            max-width: 580px;
        }

        .turno-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        .turno-actions .btn {
            min-width: 220px;
            border: 0;
            border-radius: 18px;
            padding: 16px 22px;
            font-size: 1.05rem;
            font-weight: 800;
            box-shadow: 0 14px 30px rgba(15, 23, 42, 0.10);
        }

        .btn-turno-start {
            background: linear-gradient(135deg, #10b981, #22c55e);
            color: #fff;
        }

        .btn-turno-end {
            background: linear-gradient(135deg, #ef4444, #f97316);
            color: #fff;
        }

        .btn-turno-start:disabled,
        .btn-turno-end:disabled,
        .btn-turno-start[disabled],
        .btn-turno-end[disabled] {
            background: #e2e8f0;
            color: #94a3b8;
            box-shadow: none;
            cursor: not-allowed;
            opacity: 1;
        }

        .btn-turno-secondary {
            background: #fff;
            border: 1px solid var(--line);
            color: #17325c;
            box-shadow: none;
        }

        .turno-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-top: 24px;
            flex-wrap: wrap;
        }

        .turno-helper {
            color: var(--muted);
            margin: 0;
        }

        .turno-casa-pro {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
            font-size: 13.5px;
            color: #475569;
        }

        .turno-casa-pro strong { color: #0f172a; }

        .turno-casa-pro span {
            padding: 5px 11px;
            border-radius: 999px;
            border: 1px solid #e2e8f0;
            background: #fff;
        }

        .turno-casa-pro span.is-aviso {
            border-color: rgba(245, 158, 11, .45);
            background: rgba(245, 158, 11, .1);
            color: #92400e;
            font-weight: 600;
        }

        .turno-hist-pro {
            margin-top: 18px;
            padding: 14px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #f8fafc;
        }

        .turno-hist-topo {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 12px;
            margin-bottom: 8px;
            font-size: 13.5px;
            color: #475569;
        }

        .turno-hist-topo strong { color: #0f172a; }

        .turno-hist-pro ul {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 6px;
        }

        .turno-hist-pro li {
            display: flex;
            gap: 14px;
            align-items: baseline;
            font-size: 13px;
            color: #334155;
        }

        .turno-prevista { font-size: 13px; color: #64748b; margin-top: 6px; }
        .turno-hist-data { font-weight: 600; min-width: 46px; }
        .turno-hist-dur { margin-left: auto; font-variant-numeric: tabular-nums; font-weight: 600; }

        @media (max-width: 992px) {
            .main-content {
                padding: 16px;
            }

            .top-bar,
            .turno-summary,
            .turno-footer {
                grid-template-columns: 1fr;
                flex-direction: column;
                align-items: stretch;
            }

            .turno-actions {
                justify-content: stretch;
            }

            .turno-actions .btn {
                width: 100%;
                min-width: 0;
            }
        }
        .turno-unidade { display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px; border-radius: 999px; background: rgba(255, 255, 255, .08); border: 1px solid rgba(255, 255, 255, .16); font-size: 13px; font-weight: 600; }
        .turno-unidade small { opacity: .7; font-weight: 500; }
        .turno-aviso { display: flex; gap: 12px; align-items: flex-start; padding: 14px 16px; border-radius: 12px; margin-bottom: 16px; }
        .turno-aviso i { margin-top: 3px; }
        .turno-aviso strong { display: block; }
        .turno-aviso span { font-size: 13px; opacity: .9; }
        .turno-aviso--grave { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .turno-resumo { border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px 16px; margin-bottom: 16px; background: #f8fafc; }
        .turno-resumo-head { font-weight: 700; color: #0f172a; margin-bottom: 10px; }
        .turno-resumo-head i { color: #16a34a; margin-right: 6px; }
        .turno-resumo-grid, .turno-casa-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
        .turno-resumo-grid > div, .turno-casa-grid > div { background: #fff; border: 1px solid #eef2f7; border-radius: 10px; padding: 10px 12px; }
        .turno-resumo-grid span, .turno-casa-grid span { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #64748b; }
        .turno-resumo-grid strong, .turno-casa-grid strong { display: block; font-size: 18px; color: #0f172a; }
        .turno-resumo-grid small { color: #64748b; }
        .turno-casa { border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px 16px; margin-bottom: 16px; }
        .turno-casa-head { font-weight: 700; color: #0f172a; margin-bottom: 10px; }
        .turno-casa-head i { color: #ea580c; margin-right: 6px; }
        .turno-casa-grid > div.is-ok { border-color: #bbf7d0; background: #f0fdf4; }
        .turno-casa-grid > div.is-ok strong { color: #15803d; }
        .turno-casa-grid > div.is-aviso { border-color: #fed7aa; background: #fff7ed; }
        .turno-casa-grid > div.is-aviso strong { color: #c2410c; }
        .turno-line--atraso .turno-atraso { color: #b45309; font-weight: 600; }
        .turno-tipo { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
        .turno-tipo-rot { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #64748b; font-weight: 700; }
        .turno-tipo-opcao { display: inline-flex; flex-direction: column; align-items: center; gap: 2px; padding: 8px 16px; border: 1px solid #cbd5e1; border-radius: 10px; cursor: pointer; font-weight: 600; font-size: 13px; color: #334155; background: #fff; }
        .turno-tipo-opcao small { font-weight: 500; font-size: 11px; color: #94a3b8; }
        .turno-tipo-opcao input { position: absolute; opacity: 0; width: 0; height: 0; }
        .turno-tipo-opcao.is-ativo { border-color: #ea580c; background: #fff7ed; color: #c2410c; }
        .turno-tipo-opcao.is-ativo small { color: #ea580c; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <?php include_once __DIR__ . '/includes/sidebar.php'; ?>

            <main class="main-content">
                <div class="top-bar">
                    <div>
                        <h1 class="page-title"><i class="fas fa-user-clock me-2"></i>Meu Turno</h1>
                        <div class="page-subtitle">Todos os perfis operacionais iniciam e encerram o turno neste painel.</div>
                    </div>
                    <div class="top-bar-right">
                        <span class="top-bar-date"><i class="far fa-clock"></i><span id="topBarDateTime"><?php echo date('d/m/Y H:i'); ?></span></span>
                        <div class="top-bar-user-chip">
                            <?php if ($fotoUrl !== ''): ?>
                                <img src="<?php echo htmlspecialchars($fotoUrl); ?>" alt="<?php echo htmlspecialchars($nomeUsuario); ?>">
                            <?php else: ?>
                                <span class="chip-avatar"><?php echo htmlspecialchars($iniciais); ?></span>
                            <?php endif; ?>
                            <span class="chip-info">
                                <span class="chip-name"><?php echo htmlspecialchars($nomeUsuario); ?></span>
                                <span class="chip-role"><?php echo htmlspecialchars($perfilExibicao); ?></span>
                            </span>
                        </div>
                    </div>
                </div>

                <section class="turno-card">
                    <div class="turno-card-header">
                        <h2 class="turno-card-title"><i class="fas fa-id-badge text-warning"></i> Turno do Operador</h2>
                        <?php if ($unidadeNome !== ''): ?>
                            <span class="turno-unidade" title="Unidade onde o turno é aberto">
                                <i class="fas <?php echo !empty($unidadeEhMatriz) ? 'fa-crown' : 'fa-building'; ?>"></i>
                                <?php echo htmlspecialchars($unidadeNome); ?>
                                <small><?php echo !empty($unidadeEhMatriz) ? 'Matriz' : 'Filial'; ?></small>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="turno-card-body">
                        <div id="turnoOperadorAlert" class="alert<?php echo $alertaInicial !== '' ? ' alert-' . $alertaTipo : ''; ?>" style="<?php echo $alertaInicial !== '' ? '' : 'display:none;'; ?>">
                            <?php echo htmlspecialchars($alertaInicial); ?>
                        </div>

                        <?php if ($turnoAtrasado): ?>
                            <div class="turno-aviso turno-aviso--grave">
                                <i class="fas fa-triangle-exclamation"></i>
                                <div>
                                    <strong>Tem um turno de <?php echo htmlspecialchars(date('d/m/Y', strtotime((string)$turnoAtrasado['data']))); ?> ainda aberto.</strong>
                                    <span>Encerre-o antes de começar o turno de hoje, para as horas e a presença ficarem certas.</span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($turnoResumo): ?>
                            <div class="turno-resumo">
                                <div class="turno-resumo-head"><i class="fas fa-clipboard-check"></i> Resumo do turno que acabou de encerrar</div>
                                <div class="turno-resumo-grid">
                                    <div><span>Horas trabalhadas</span><strong><?php echo htmlspecialchars($turnoResumo['duracao']); ?></strong><small><?php echo htmlspecialchars($turnoResumo['entrada'] . ' às ' . $turnoResumo['saida']); ?></small></div>
                                    <div><span>Vendas cobradas</span><strong><?php echo (int)$turnoResumo['vendas_qtd']; ?></strong><small>MZN <?php echo number_format((float)$turnoResumo['vendas_total'], 2, ',', '.'); ?></small></div>
                                    <div><span>Pedidos lançados</span><strong><?php echo (int)$turnoResumo['pedidos']; ?></strong><small>sem cancelados</small></div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($turnoCasa && !$turnoAtivo): ?>
                            <div class="turno-casa">
                                <div class="turno-casa-head"><i class="fas fa-store"></i> Como está a casa agora</div>
                                <div class="turno-casa-grid">
                                    <div class="<?php echo $turnoCasa['caixa_aberto'] ? 'is-ok' : 'is-aviso'; ?>">
                                        <span>Caixa</span><strong><?php echo $turnoCasa['caixa_aberto'] ? 'Aberto' : 'Fechado'; ?></strong>
                                    </div>
                                    <div class="<?php echo (int)$turnoCasa['contas_abertas'] > 0 ? 'is-aviso' : ''; ?>">
                                        <span>Contas por fechar</span><strong><?php echo (int)$turnoCasa['contas_abertas']; ?></strong>
                                    </div>
                                    <div class="<?php echo (int)$turnoCasa['pedidos_cozinha'] > 0 ? 'is-aviso' : ''; ?>">
                                        <span>Pedidos à espera</span><strong><?php echo (int)$turnoCasa['pedidos_cozinha']; ?></strong>
                                    </div>
                                    <div>
                                        <span>Colegas em turno</span><strong><?php echo (int)$turnoCasa['colegas']; ?></strong>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="turno-summary">
                            <div class="turno-meta">
                                <?php if ($turnoAtivo): ?>
                                    <div class="turno-line">
                                        <strong>Status:</strong>
                                        <span class="status-pill active">ATIVO</span>
                                    </div>
                                    <div class="turno-line"><strong>Cargo:</strong> <?php echo htmlspecialchars((string)($turnoAtivo['cargo'] ?? $perfil)); ?></div>
                                    <div class="turno-line"><strong>Início:</strong> <?php echo htmlspecialchars((string)($turnoAtivo['data'] ?? '') . ' ' . substr((string)($turnoAtivo['hora_entrada'] ?? ''), 0, 5)); ?></div>
                                    <div class="turno-line"><strong>Tempo de turno:</strong> <span id="turnoOperadorDuracao" data-inicio="<?php echo htmlspecialchars((string)($turnoAtivo['data'] ?? '') . 'T' . substr((string)($turnoAtivo['hora_entrada'] ?? '00:00:00'), 0, 8)); ?>"><?php echo htmlspecialchars((string)($turnoAtivo['duracao_formatada'] ?? '0 min')); ?></span></div>
                                    <?php if ($turnoAtrasoMin > 0): ?>
                                        <div class="turno-line turno-line--atraso">
                                            <strong>Entrada:</strong>
                                            <span class="turno-atraso"><i class="fas fa-clock"></i> <?php echo $turnoAtrasoMin >= 60 ? intdiv($turnoAtrasoMin, 60) . 'h' . str_pad((string)($turnoAtrasoMin % 60), 2, '0', STR_PAD_LEFT) : $turnoAtrasoMin . ' min'; ?> depois da hora prevista</span>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="turno-line">
                                        <strong>Status:</strong>
                                        <span class="status-pill inactive">INATIVO</span>
                                    </div>
                                    <div class="turno-empty">
                                        Nenhum turno activo para este utilizador. Inicie o turno antes de trabalhar em pedidos, mesas, cozinha, bar ou caixa.
                                    </div>
                                    <?php if ($turnoProfissional):
                                        $previstaAgora = $horasPrevistas[$turnoTipoAgora] ?? '08:00';
                                        $tsPrevistaAgora = strtotime(date('Y-m-d') . ' ' . $previstaAgora);
                                        $atrasoAgora = ($tsPrevistaAgora && time() > $tsPrevistaAgora) ? (int)round((time() - $tsPrevistaAgora) / 60) : 0;
                                    ?>
                                        <div class="turno-line turno-prevista">
                                            <strong>Hora prevista:</strong>
                                            <span><?php echo htmlspecialchars($previstaAgora); ?></span>
                                            <?php if ($atrasoAgora > 0): ?>
                                                <span class="turno-atraso"><i class="fas fa-clock"></i> já passaram <?php echo $atrasoAgora >= 60 ? intdiv($atrasoAgora, 60) . 'h' . str_pad((string)($atrasoAgora % 60), 2, '0', STR_PAD_LEFT) : $atrasoAgora . ' min'; ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>

                            <div class="turno-actions">
                                <?php if (!$turnoAtivo): ?>
                                    <div class="turno-tipo" role="group" aria-label="Tipo de turno">
                                        <span class="turno-tipo-rot">Turno</span>
                                        <?php foreach (['manha' => 'Manhã', 'noite' => 'Noite', 'integral' => 'Integral'] as $tipoVal => $tipoRot): ?>
                                            <label class="turno-tipo-opcao<?php echo $tipoVal === $turnoTipoAgora ? ' is-ativo' : ''; ?>">
                                                <input type="radio" name="turnoTipo" value="<?php echo $tipoVal; ?>"<?php echo $tipoVal === $turnoTipoAgora ? ' checked' : ''; ?>>
                                                <?php echo $tipoRot; ?>
                                                <small><?php echo htmlspecialchars($horasPrevistas[$tipoVal] ?? ''); ?></small>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <button id="btnTurnoIniciar" class="btn btn-turno-start" type="button" onclick="operarTurno('iniciar')" <?php echo $turnoAtivo ? 'disabled' : ''; ?>>
                                    <i class="fas fa-play me-2"></i>Iniciar Turno
                                </button>
                                <button id="btnTurnoEncerrar" class="btn btn-turno-end" type="button" onclick="operarTurno('encerrar')" <?php echo $turnoAtivo ? '' : 'disabled'; ?>>
                                    <i class="fas fa-stop me-2"></i><?php echo $turnoAtrasado ? 'Encerrar o turno de ' . htmlspecialchars(date('d/m', strtotime((string)$turnoAtrasado['data']))) : 'Encerrar Turno'; ?>
                                </button>
                            </div>
                        </div>

                        <?php if ($turnoCasaPro !== null && !$turnoAtivo): ?>
                            <div class="turno-casa-pro">
                                <strong>Como está a casa agora:</strong>
                                <span class="<?php echo $turnoCasaPro['caixa_aberto'] ? '' : 'is-aviso'; ?>">Caixa <?php echo $turnoCasaPro['caixa_aberto'] ? 'aberto' : 'fechado'; ?></span>
                                <span class="<?php echo (int)$turnoCasaPro['contas_abertas'] > 0 ? 'is-aviso' : ''; ?>"><?php echo (int)$turnoCasaPro['contas_abertas']; ?> conta(s) por fechar</span>
                                <span><?php echo (int)$turnoCasaPro['colegas']; ?> colega(s) em turno</span>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($turnoHistoricoPro)): ?>
                            <div class="turno-hist-pro">
                                <div class="turno-hist-topo">
                                    <strong>Os seus últimos turnos</strong>
                                    <span><?php echo intdiv($turnoSemanaMin, 60); ?>h<?php echo $turnoSemanaMin % 60 > 0 ? ' ' . ($turnoSemanaMin % 60) . 'min' : ''; ?> esta semana</span>
                                </div>
                                <ul>
                                    <?php foreach ($turnoHistoricoPro as $linhaHist): ?>
                                        <?php
                                        $minHist = (int)($linhaHist['minutos'] ?? 0);
                                        $duracaoHist = intdiv($minHist, 60) . 'h' . ($minHist % 60 > 0 ? ' ' . ($minHist % 60) . 'min' : '');
                                        $abertoHist = strtoupper((string)($linhaHist['status'] ?? '')) === 'ATIVO' && empty($linhaHist['hora_saida']);
                                        ?>
                                        <li>
                                            <span class="turno-hist-data"><?php echo htmlspecialchars(date('d/m', strtotime((string)$linhaHist['data']))); ?></span>
                                            <span><?php echo htmlspecialchars(substr((string)$linhaHist['hora_entrada'], 0, 5)); ?> – <?php echo $abertoHist ? 'em curso' : htmlspecialchars(substr((string)($linhaHist['hora_saida'] ?? ''), 0, 5)); ?></span>
                                            <span class="turno-hist-dur"><?php echo htmlspecialchars($duracaoHist); ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <div class="turno-footer">
                            <p class="turno-helper">Depois de iniciar o turno, o sistema leva-o para a área de trabalho do seu perfil.</p>
                            <?php if ($turnoAtivo): ?>
                                <a href="<?php echo htmlspecialchars($destinoPosInicio); ?>" class="btn btn-turno-secondary">
                                    <i class="fas fa-arrow-right me-2"></i>Entrar no sistema
                                </a>
                            <?php else: ?>
                                <a href="logout.php" class="btn btn-turno-secondary">
                                    <i class="fas fa-sign-out-alt me-2"></i>Sair
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            </main>
        </div>
    </div>

    <script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
        const turnoRedirectDestino = <?php echo json_encode($destinoPosInicio, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

        function turnoTipoEscolhido() {
            const marcado = document.querySelector('input[name="turnoTipo"]:checked');
            return marcado ? marcado.value : null;
        }

        function atualizarDuracaoTurno() {
            const alvo = document.getElementById('turnoOperadorDuracao');
            if (!alvo || !alvo.dataset.inicio) {
                return;
            }
            const inicio = new Date(alvo.dataset.inicio.replace(' ', 'T'));
            if (isNaN(inicio.getTime())) {
                return;
            }
            let minutos = Math.max(0, Math.floor((Date.now() - inicio.getTime()) / 60000));
            const horas = Math.floor(minutos / 60);
            minutos = minutos % 60;
            alvo.textContent = horas > 0
                ? horas + 'h' + String(minutos).padStart(2, '0')
                : minutos + ' min';
        }

        function atualizarRelogioTurno() {
            const target = document.getElementById('topBarDateTime');
            if (!target) {
                return;
            }

            const now = new Date();
            const pad = (value) => String(value).padStart(2, '0');
            target.textContent = [
                pad(now.getDate()),
                pad(now.getMonth() + 1),
                now.getFullYear()
            ].join('/') + ' ' + pad(now.getHours()) + ':' + pad(now.getMinutes());
        }

        function showTurnoAlert(message, type) {
            const alertDiv = document.getElementById('turnoOperadorAlert');
            if (!alertDiv) {
                return;
            }

            alertDiv.textContent = message;
            alertDiv.className = 'alert alert-' + type;
            alertDiv.style.display = 'block';
        }

        function setTurnoBusy(isBusy) {
            const btnStart = document.getElementById('btnTurnoIniciar');
            const btnEnd = document.getElementById('btnTurnoEncerrar');
            if (btnStart) {
                btnStart.disabled = isBusy || btnStart.hasAttribute('data-locked');
            }
            if (btnEnd) {
                btnEnd.disabled = isBusy || btnEnd.hasAttribute('data-locked');
            }
        }

        function enviarFallbackTurno(acao) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'iniciar_turno.php';

            const acaoInput = document.createElement('input');
            acaoInput.type = 'hidden';
            acaoInput.name = 'acao';
            acaoInput.value = acao;
            form.appendChild(acaoInput);

            const tipoEscolhido = acao === 'iniciar' ? turnoTipoEscolhido() : null;
            if (tipoEscolhido) {
                const tipoInput = document.createElement('input');
                tipoInput.type = 'hidden';
                tipoInput.name = 'turno';
                tipoInput.value = tipoEscolhido;
                form.appendChild(tipoInput);
            }

            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = '_csrf';
            csrfInput.value = CSRF_TOKEN;
            form.appendChild(csrfInput);

            document.body.appendChild(form);
            form.submit();
        }

        async function operarTurno(acao) {
            const btnStart = document.getElementById('btnTurnoIniciar');
            const btnEnd = document.getElementById('btnTurnoEncerrar');
            if ((acao === 'iniciar' && btnStart && btnStart.disabled) || (acao === 'encerrar' && btnEnd && btnEnd.disabled)) {
                return;
            }

            setTurnoBusy(true);

            try {
                const basePath = window.location.pathname.replace(/\/[^/]*$/, '/');
                // Sem caminhos fixos: usa o caminho relativo e o caminho real desta pagina.
                const candidates = Array.from(new Set([
                    'api/turno_operacao.php',
                    basePath + 'api/turno_operacao.php'
                ]));
                const doRequest = async (idx = 0) => {
                    if (idx >= candidates.length) {
                        throw new Error('Não foi possível falar com o sistema de turnos.');
                    }
                    try {
                        return await fetch(candidates[idx], {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-Token': CSRF_TOKEN,
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: JSON.stringify(acao === 'iniciar' ? { acao, turno: turnoTipoEscolhido() } : { acao })
                        });
                    } catch (err) {
                        if (/Failed to fetch|NetworkError|conexao/i.test(String(err?.message || ''))) {
                            return doRequest(idx + 1);
                        }
                        throw err;
                    }
                };

                const response = await doRequest(0);
                const raw = await response.text();
                let data = null;
                try {
                    data = JSON.parse(raw);
                } catch (parseErr) {
                    throw new Error('O sistema de turnos devolveu uma resposta inválida.');
                }

                if (!data.success) {
                    showTurnoAlert(data.message || 'Não foi possível concluir a operação do turno.', 'danger');
                    return;
                }

                showTurnoAlert(data.message || 'Operação concluída com sucesso.', 'success');

                setTimeout(() => {
                    if (acao === 'iniciar') {
                        window.location.href = turnoRedirectDestino;
                        return;
                    }
                    window.location.reload();
                }, 900);
            } catch (error) {
                showTurnoAlert('Sem ligação ao sistema de turnos. A tentar pelo caminho alternativo…', 'warning');
                setTimeout(() => enviarFallbackTurno(acao), 250);
            } finally {
                setTimeout(() => setTurnoBusy(false), 500);
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            const btnStart = document.getElementById('btnTurnoIniciar');
            const btnEnd = document.getElementById('btnTurnoEncerrar');

            if (btnStart && btnStart.disabled) {
                btnStart.setAttribute('data-locked', '1');
            }
            if (btnEnd && btnEnd.disabled) {
                btnEnd.setAttribute('data-locked', '1');
            }

            atualizarRelogioTurno();
            setInterval(atualizarRelogioTurno, 30000);
            atualizarDuracaoTurno();
            setInterval(atualizarDuracaoTurno, 30000);

            document.querySelectorAll('input[name="turnoTipo"]').forEach(function (radio) {
                radio.addEventListener('change', function () {
                    document.querySelectorAll('.turno-tipo-opcao').forEach(function (op) {
                        op.classList.toggle('is-ativo', op.contains(radio) && radio.checked);
                    });
                });
            });
        });
    </script>
</body>
</html>
