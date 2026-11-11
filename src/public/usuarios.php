<?php
require_once __DIR__ . '/app_base.php';
// Proteção da página - apenas ADMIN
include_once APP_BASE . '/src/config/auth_check.php';
include_once APP_BASE . '/src/config/csrf.php';
checkPermission(['ADMIN']);

include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/restaurante_context.php';
include_once APP_BASE . '/src/config/plano_check.php';
include_once APP_BASE . '/src/Model/Auth.php';
include_once APP_BASE . '/src/config/password_policy.php';

if (!function_exists('usuario_iniciais')) {
    function usuario_iniciais(string $nome): string
    {
        $partes = preg_split('/\s+/u', trim($nome)) ?: [];
        $ini = '';
        foreach ($partes as $parte) {
            if ($parte === '') {
                continue;
            }
            $ini .= mb_substr($parte, 0, 1, 'UTF-8');
            if (mb_strlen($ini, 'UTF-8') >= 2) {
                break;
            }
        }
        return mb_strtoupper($ini !== '' ? $ini : '?', 'UTF-8');
    }
}
if (!function_exists('usuario_img_iniciais')) {
    function usuario_img_iniciais(string $nome, string $cor = '#FF6B35', int $tam = 100): string
    {
        $ini = htmlspecialchars(usuario_iniciais($nome), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $fs = (int)round($tam * 0.4);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $tam . '" height="' . $tam . '" viewBox="0 0 ' . $tam . ' ' . $tam . '">'
            . '<rect width="100%" height="100%" rx="' . (int)($tam / 2) . '" fill="' . htmlspecialchars($cor, ENT_QUOTES) . '"/>'
            . '<text x="50%" y="50%" dy=".35em" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="' . $fs . '" font-weight="700" fill="#fff">' . $ini . '</text></svg>';
        return 'data:image/svg+xml;charset=UTF-8,' . rawurlencode($svg);
    }
}

// Conectar ao banco
$database = new Database();
$db = $database->getConnection();

// calcular base URL (sem barra no final)
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');


$restauranteId = session_restaurante_contexto_id();
$csrfToken = csrf_get_token();
$usuariosCapabilityId = function_exists('session_restaurante_capability_id')
    ? (int)session_restaurante_capability_id()
    : (int)$restauranteId;
$usuariosCapabilityId = $usuariosCapabilityId > 0 ? $usuariosCapabilityId : (int)$restauranteId;
$podePerfisBarCozinha = $usuariosCapabilityId > 0
    && function_exists('plano_tem_funcionalidade_db')
    && plano_tem_funcionalidade_db($usuariosCapabilityId, 'telas_bar_cozinha');
$podePerfilGerente = $usuariosCapabilityId > 0
    && function_exists('plano_tem_funcionalidade_db')
    && plano_tem_funcionalidade_db($usuariosCapabilityId, 'gestao_funcionarios');
$dadosPlanoUsuarios = plano_get_dados($usuariosCapabilityId);
$plano_atual = plano_normalizar_nome($dadosPlanoUsuarios['plano_nome'] ?? 'BASICO');
$temGestaoFuncionarios = plano_tem_funcionalidade_db($usuariosCapabilityId, 'gestao_funcionarios');
$temMultiFilialUsuarios = plano_tem_funcionalidade_db($usuariosCapabilityId, 'multi_filial');
$isEmpresarialUsuarios = ($plano_atual === 'EMPRESARIAL');
require_once __DIR__ . '/includes/empresarial_ui.php';

$senhaRegrasPorPerfil = [];
foreach (['ADMIN', 'GERENTE', 'CAIXA', 'GARCOM', 'COZINHA', 'BAR'] as $pfSenha) {
    $senhaRegrasPorPerfil[$pfSenha] = [
        'min' => password_policy_min_length($pfSenha),
        'texto' => function_exists('password_policy_requirements_text') ? password_policy_requirements_text($pfSenha) : '',
    ];
}
$senhaRegraAlterar = [
    'min' => password_policy_min_length(null, true),
    'texto' => function_exists('password_policy_requirements_text') ? password_policy_requirements_text(null, true) : '',
];

// Buscar usuários do restaurante
$temColunaSuperAdmin = false;
$temColunaUltimoAcesso = false;
try {
    $colStmt = $db->query("SHOW COLUMNS FROM usuarios LIKE 'super_admin'");
    $temColunaSuperAdmin = $colStmt && $colStmt->fetch(PDO::FETCH_ASSOC) ? true : false;
} catch (Throwable $e) {
    $temColunaSuperAdmin = false;
}
// Profissional tambem precisa de saber quem anda a entrar, para desactivar acessos esquecidos.
$usrPlanoMedio = ($plano_atual === 'PROFISSIONAL');
if ($isEmpresarialUsuarios || $usrPlanoMedio) {
    try {
        require_once APP_BASE . '/src/config/presenca_online.php';
        $temColunaUltimoAcesso = function_exists('presenca_garantir_coluna_ultimo_acesso')
            && presenca_garantir_coluna_ultimo_acesso($db);
    } catch (Throwable $e) {
        $temColunaUltimoAcesso = false;
    }
}
$usrMostraUltimoAcesso = $usrPlanoMedio && $temColunaUltimoAcesso;

$query = "SELECT id, nome, email, perfil, ativo, foto, criado_em"
    . ($temColunaUltimoAcesso ? ", ultimo_acesso" : "")
    . "
          FROM usuarios
          WHERE restaurante_id = :rid";
if ($temColunaSuperAdmin) {
    $query .= " AND COALESCE(super_admin, 0) = 0";
}
$query .= " ORDER BY nome ASC";
$stmt = $db->prepare($query);
$stmt->bindValue(':rid', $restauranteId, PDO::PARAM_INT);
$stmt->execute();
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
$nomeUnidadeAtual = 'Esta unidade';
try {
    $stUn = $db->prepare('SELECT nome FROM restaurantes WHERE id = :id');
    $stUn->execute([':id' => (int)$restauranteId]);
    $nomeUnidadeAtual = (string)($stUn->fetchColumn() ?: 'Esta unidade');
} catch (Throwable $e) {
}
foreach ($usuarios as &$uUn) {
    $uUn['_unidade_id'] = (int)$restauranteId;
    $uUn['_unidade_nome'] = $nomeUnidadeAtual;
    $uUn['_de_filial'] = false;
}
unset($uUn);

// Empresarial na matriz: mostrar também a equipa das filiais (só leitura)
$unidadesUsuarios = [(int)$restauranteId => $nomeUnidadeAtual];
if ($isEmpresarialUsuarios && $temMultiFilialUsuarios) {
    try {
        $stFil = $db->prepare("SELECT id, nome FROM restaurantes WHERE filial_id = :rid AND COALESCE(is_matriz, 0) = 0 ORDER BY nome");
        $stFil->execute([':rid' => (int)$restauranteId]);
        $filiaisUsr = $stFil->fetchAll(PDO::FETCH_KEY_PAIR);
        if ($filiaisUsr) {
            $idsFil = array_map('intval', array_keys($filiaisUsr));
            $phFil = implode(',', array_fill(0, count($idsFil), '?'));
            $qFil = "SELECT id, nome, email, perfil, ativo, foto, criado_em, restaurante_id"
                . ($temColunaUltimoAcesso ? ", ultimo_acesso" : "")
                . " FROM usuarios WHERE restaurante_id IN ($phFil)"
                . ($temColunaSuperAdmin ? " AND COALESCE(super_admin, 0) = 0" : "")
                . " ORDER BY nome ASC";
            $stUF = $db->prepare($qFil);
            $stUF->execute($idsFil);
            foreach ($stUF->fetchAll(PDO::FETCH_ASSOC) as $uf) {
                $uf['_unidade_id'] = (int)$uf['restaurante_id'];
                $uf['_unidade_nome'] = (string)($filiaisUsr[$uf['restaurante_id']] ?? 'Filial');
                $uf['_de_filial'] = true;
                $usuarios[] = $uf;
            }
            foreach ($filiaisUsr as $fid => $fnome) {
                $unidadesUsuarios[(int)$fid] = (string)$fnome;
            }
        }
    } catch (Throwable $e) {
        error_log('[usuarios] equipa das filiais: ' . $e->getMessage());
    }
}

// Cockpit Empresarial — métricas da equipa (dados já existentes)
$usrEmpStats = [
    'total' => count($usuarios),
    'ativos' => 0,
    'inativos' => 0,
    'online' => 0,
    'por_perfil' => [],
    'sem_foto' => 0,
    'notas' => [],
];
$agoraTs = time();
foreach ($usuarios as &$uRef) {
    $perfilKey = strtoupper(trim((string)($uRef['perfil'] ?? '')));
    $ativo = ((int)($uRef['ativo'] ?? 0) === 1 || ($uRef['ativo'] ?? '') === '1' || ($uRef['ativo'] ?? false) === true);
    $uRef['_ativo'] = $ativo;
    $uRef['_perfil'] = $perfilKey;
    $uRef['_online'] = false;
    if ($ativo) {
        $usrEmpStats['ativos']++;
    } else {
        $usrEmpStats['inativos']++;
    }
    if (!isset($usrEmpStats['por_perfil'][$perfilKey])) {
        $usrEmpStats['por_perfil'][$perfilKey] = 0;
    }
    $usrEmpStats['por_perfil'][$perfilKey]++;
    if (empty($uRef['foto'])) {
        $usrEmpStats['sem_foto']++;
    }
    $uRef['_dias_sem_acesso'] = null;
    if ($temColunaUltimoAcesso && !empty($uRef['ultimo_acesso'])) {
        $ua = strtotime((string)$uRef['ultimo_acesso']);
        if ($ua) {
            $uRef['_dias_sem_acesso'] = (int)floor(($agoraTs - $ua) / 86400);
        }
        if ($ua && ($agoraTs - $ua) <= 180) {
            $uRef['_online'] = true;
            if ($ativo) {
                $usrEmpStats['online']++;
            }
        }
    }
}
unset($uRef);
arsort($usrEmpStats['por_perfil']);
$usrParados = [];
foreach ($usuarios as $uPar) {
    if (!empty($uPar['_ativo']) && empty($uPar['_de_filial']) && $temColunaUltimoAcesso
        && ($uPar['_dias_sem_acesso'] === null ? true : $uPar['_dias_sem_acesso'] > 30)) {
        $usrParados[] = (string)$uPar['nome'];
    }
}

if ($isEmpresarialUsuarios) {
    if (empty($usrEmpStats['por_perfil']['GERENTE']) && $podePerfilGerente) {
        $usrEmpStats['notas'][] = [
            'tom' => 'warn',
            'titulo' => 'Sem gerente na unidade',
            'txt' => 'Crie um utilizador GERENTE para supervisão do salão sem partilhar a conta ADMIN.',
        ];
    }
    if ($podePerfisBarCozinha) {
        $temCozinha = !empty($usrEmpStats['por_perfil']['COZINHA']);
        $temBar = !empty($usrEmpStats['por_perfil']['BAR']);
        if (!$temCozinha || !$temBar) {
            $falta = [];
            if (!$temCozinha) $falta[] = 'Cozinha';
            if (!$temBar) $falta[] = 'Bar';
            $usrEmpStats['notas'][] = [
                'tom' => 'info',
                'titulo' => 'KDS: falta ' . implode(' e ', $falta),
                'txt' => 'Perfis de cozinha/bar permitem ecrãs KDS separados no Empresarial.',
            ];
        }
    }
    if ($usrParados) {
        array_unshift($usrEmpStats['notas'], [
            'tom' => 'warn',
            'titulo' => count($usrParados) . ' conta(s) activa(s) sem entrar há mais de 30 dias',
            'txt' => implode(', ', array_slice($usrParados, 0, 3)) . (count($usrParados) > 3 ? '…' : '') . '. Desactive acessos que já não são usados.',
        ]);
    }
    if ($usrEmpStats['inativos'] > 0) {
        $usrEmpStats['notas'][] = [
            'tom' => 'info',
            'titulo' => $usrEmpStats['inativos'] . ' conta(s) inactiva(s)',
            'txt' => 'Revise acessos antigos para manter a equipa limpa.',
        ];
    }
    $usrEmpStats['notas'] = array_slice($usrEmpStats['notas'], 0, 2);
}
?>
<!DOCTYPE html>
<html lang="pt">

<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usuarios - Sistema de Restaurante</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

    <!-- Bootstrap 5 CSS -->
    <link href="lib/bootstrap-5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
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
            font-family: 'Outfit', sans-serif;
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
            font-family: 'Space Grotesk', sans-serif;
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
            overflow-x: hidden;
            min-height: 0;
            padding-bottom: 12px;
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
            padding: 32px;
            min-height: 100vh;
            background: var(--bg);
        }

        /* O .row da grelha puxa 12px para a esquerda e cortava o texto do cabecalho. */
        .container-fluid > .row {
            margin-left: 0;
            margin-right: 0;
        }

        /* Sem as colunas do bootstrap, o main tem de caber no que sobra do ecra. */
        .container-fluid > .row > .main-content {
            flex: 1 1 auto;
            width: auto;
            max-width: calc(100% - 280px);
            min-width: 0;
        }

        @media (max-width: 768px) {
            .container-fluid > .row > .main-content {
                max-width: 100%;
            }
        }

        .usr-topo {
            gap: 16px;
        }

        .usr-topo > div:first-child {
            min-width: 0;
        }

        .usr-topo p {
            overflow-wrap: anywhere;
        }

        .usr-pro-resumo {
            font-size: 13.5px;
            color: var(--text-light, #64748b);
            margin: -8px 0 16px;
        }

        .usr-pro-resumo strong { color: #0f172a; }
        .usr-pro-falta { color: #b45309; font-weight: 600; }
        .usr-ultimo { font-size: 13px; color: #334155; font-variant-numeric: tabular-nums; }
        .usr-ultimo-aviso { font-size: 12px; color: #b45309; }

        .usuario-row td:last-child { white-space: nowrap; }
        .usuario-row td:last-child .btn { margin-right: 4px; }

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
            margin: -32px -32px 32px -32px;
        }

        .page-title {
            font-family: 'Space Grotesk', sans-serif;
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
            font-family: 'Space Grotesk', sans-serif;
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

        .avatar-lg {
            width: 52px;
            height: 52px;
            flex: 0 0 auto;
            object-fit: cover;
            border-radius: 16px;
            border: 3px solid white;
            box-shadow: var(--shadow);
        }

        .avatar-sm {
            width: 80px;
            height: 80px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 28px;
            overflow: hidden;
        }

        .avatar-sm img {
            width: 100%;
            height: 100%;
            object-fit: cover;
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

        .badge-warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .badge-danger {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .badge-info {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .badge-secondary {
            background: rgba(100, 116, 139, 0.1);
            color: var(--text-light);
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

        .btn-info {
            background: var(--info);
            border: none;
            padding: 8px 16px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 13px;
        }

        .btn-warning {
            background: var(--warning);
            border: none;
            padding: 8px 16px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 13px;
            color: white;
        }

        .btn-sm {
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 12px;
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
            font-family: 'Space Grotesk', sans-serif;
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

        .form-control,
        .form-select {
            padding: 12px 16px;
            border-radius: 12px;
            border: 2px solid var(--border);
            font-size: 14px;
            transition: all 0.3s;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(255, 107, 53, 0.1);
        }

        .foto-preview {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--border);
            margin: 0 auto;
            display: block;
        }

        .foto-upload-wrapper {
            text-align: center;
            margin-bottom: 20px;
        }

        @media (max-width: 991px) {
            .sidebar {
                width: 80vw;
                max-width: 340px;
                min-width: 220px;
                position: fixed;
                left: 0;
                top: 0;
                z-index: 2000;
                height: 100vh;
                transition: left 0.3s cubic-bezier(.4, 0, .2, 1);
                box-shadow: 2px 0 16px rgba(0, 0, 0, 0.08);
                display: flex;
            }

            .sidebar.sidebar-hidden {
                left: -100vw !important;
                transition: left 0.3s cubic-bezier(.4, 0, .2, 1);
            }

            .main-content {
                margin-left: 0 !important;
            }

            .top-bar {
                margin: 0 0 20px 0;
            }
        }

        @media (max-width: 576px) {
            .main-content {
                padding: 12px;
            }

            .top-bar {
                padding: 12px 16px;
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
                margin: 0 0 16px 0;
            }

            .page-title {
                font-size: 18px;
                gap: 8px;
            }

            .card-body.d-flex.flex-wrap.gap-2.justify-content-between.align-items-center {
                flex-direction: column;
                align-items: stretch !important;
            }

            .card-body.d-flex.flex-wrap.gap-2.justify-content-between.align-items-center .d-flex.align-items-center.gap-2 {
                width: 100%;
                justify-content: space-between;
                flex-wrap: wrap;
            }

            #filtroPerfil {
                min-width: 0 !important;
                width: 100% !important;
            }

            .table-card .table th,
            .table-card .table td {
                padding: 10px 12px;
                font-size: 12px;
                white-space: normal;
            }

            .avatar-lg {
                width: 42px;
                height: 42px;
            }

            .avatar-sm {
                width: 34px;
                height: 34px;
            }

            .btn-sm {
                width: 100%;
            }

            .table-card .card-body.p-0 {
                overflow-x: auto;
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
        /* Corporate consistency without changing palette */
        .card {
            border-radius: 12px;
            border: 1px solid #dbe3ef;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
        }
        .card-header {
            padding: 12px 16px;
        }
        .card-body {
            padding: 14px 16px;
        }
        .table thead th {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .02em;
        }
        .table tbody td {
            font-size: 13px;
        }
        .btn {
            border-radius: 10px;
            font-size: 14px;
            padding: 9px 14px;
        }
        .btn-sm {
            font-size: 12px;
            padding: 7px 10px;
            border-radius: 9px;
        }
        .form-control,
        .form-select {
            min-height: 40px;
            font-size: 14px;
            border-radius: 10px;
        }
        .modal-content {
            border-radius: 14px;
            border: 1px solid #dbe3ef;
            box-shadow: 0 16px 36px rgba(15, 23, 42, 0.18);
        }
        @media (min-width: 1200px) {
            .main-content { padding-top: 14px; }
            .card { margin-bottom: 12px; }
            .card-header { padding: 10px 14px; }
            .card-body { padding: 12px 14px; }
            .table tbody td { padding-top: .55rem; padding-bottom: .55rem; }
            .user-avatar { width: 52px !important; height: 52px !important; }
        }

        .usr-emp-intro {
            margin: 0 0 14px;
            padding: 12px 14px;
            border-radius: 12px;
            background: #0f172a;
            border: 1px solid #1e293b;
            color: #cbd5e1;
            font-size: 13px;
            line-height: 1.45;
        }
        .usr-emp-intro strong { color: #fff; }
        .usr-emp-kpis {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 14px;
        }
        @media (min-width: 768px) {
            .usr-emp-kpis { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        }
        .usr-emp-kpi {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px 14px;
        }
        .usr-emp-kpi .k { font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #64748b; }
        .usr-emp-kpi .v { font-size: 22px; font-weight: 800; color: #0f172a; margin-top: 2px; font-variant-numeric: tabular-nums; }
        .usr-emp-kpi .h { font-size: 11px; color: #64748b; margin-top: 2px; }
        .usr-emp-kpi.is-ok { border-color: #a7f3d0; background: #ecfdf5; }
        .usr-emp-kpi.is-warn { border-color: #fde68a; background: #fffbeb; }
        .usr-emp-chips {
            display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px;
        }
        .usr-emp-chip {
            border: 1px solid #e2e8f0;
            background: #fff;
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 700;
            color: #334155;
            cursor: pointer;
        }
        .usr-emp-chip.is-active {
            border-color: #fdba74;
            background: #fff7ed;
            color: #c2410c;
        }
        .usr-emp-chip .n {
            margin-left: 6px;
            padding: 1px 7px;
            border-radius: 999px;
            background: #f1f5f9;
            font-variant-numeric: tabular-nums;
        }
        .usr-emp-chip.is-active .n { background: #ffedd5; }
        .usr-emp-notes {
            list-style: none; margin: 0 0 14px; padding: 0;
        }
        .usr-emp-notes li {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 10px 12px;
            margin-bottom: 8px;
            background: #fff;
            font-size: 13px;
        }
        .usr-emp-notes li.tom-warn { border-color: #fde68a; background: #fffbeb; }
        .usr-emp-notes li.tom-info { border-color: #bfdbfe; background: #eff6ff; }
        .usr-emp-notes strong { display: block; color: #0f172a; margin-bottom: 2px; }
        .usr-emp-notes span { color: #475569; }
        .usr-presenca {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 12px; font-weight: 700;
        }
        .usr-presenca .dot {
            width: 8px; height: 8px; border-radius: 50%; background: #cbd5e1;
        }
        .usr-presenca.is-on { color: #047857; }
        .usr-filtros { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
        .usr-filtros .form-select { width: auto; min-width: 130px; max-width: 190px; }
        tr.usr-row-filial td { background: #fcfcfd; }
        tr.usr-row-filial td:first-child { box-shadow: inset 3px 0 0 #e2e8f0; }
        .usr-presenca.is-on .dot { background: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,.18); }
        .usr-presenca.is-off { color: #64748b; }
    </style>
    <?php empresarial_head_assets(); ?>
</head>

<body class="<?php echo htmlspecialchars(empresarial_body_class('premium-ui')); ?>">

    <div class="container-fluid">
        <div class="row">
            <style>
                @media (max-width: 991px) {
                    .sidebar-toggle-btn {
                        display: block !important;
                    }

                    .sidebar {
                        position: fixed !important;
                        top: 0;
                        left: 0;
                        height: 100vh;
                        z-index: 2000;
                        transition: left 0.3s;
                    }

                    .sidebar.sidebar-hidden {
                        left: -100vw !important;
                    }

                    .main-content-blur {
                        filter: blur(2px) grayscale(0.1);
                        pointer-events: none;
                    }
                }
            </style>
            <!-- SIDEBAR -->
            <?php include_once __DIR__ . '/includes/sidebar.php'; ?>

            <!-- CONTEÚDO PRINCIPAL -->
            <main class="main-content">

                <!-- TOP BAR -->
                <div class="d-flex justify-content-between align-items-center mb-4 usr-topo">
                    <div>
                        <h4 class="mb-0"><i class="fas fa-users text-primary me-2"></i>Gestão de Usuários <?php include __DIR__ . '/includes/plan_page_badge.php'; ?></h4>
                        <?php if (($plano_atual ?? '') === 'BASICO'): ?>
                            <p class="text-muted mb-0" style="font-size: 14px;">Crie e edite acessos da equipa. Perfis Cozinha e Bar no plano Profissional.</p>
                        <?php elseif (!empty($isEmpresarialUsuarios)): ?>
                            <p class="text-muted mb-0" style="font-size: 14px;">Cockpit de equipa — acessos, perfis e presença na unidade (ilimitado).</p>
                        <?php else: ?>
                            <p class="text-muted mb-0" style="font-size: 14px;">Gerencie os acessos da unidade.</p>
                        <?php endif; ?>
                    </div>
                    <?php
                    $foto_atual = !empty($_SESSION['foto']) ? $_SESSION['foto'] : '';
                    $img_iniciais_sessao = usuario_img_iniciais((string)($_SESSION['nome'] ?? ''), '#FF6B35', 50);
                    ?>
                    <img src="<?php echo htmlspecialchars(!empty($foto_atual) ? $foto_atual : $img_iniciais_sessao); ?>" class="avatar-lg" alt="" data-fallback-src="<?php echo htmlspecialchars($img_iniciais_sessao); ?>">
                </div>

                <?php if (!empty($usrPlanoMedio)): ?>
                    <?php
                    $usrFaltamPerfis = [];
                    if (empty($usrEmpStats['por_perfil']['GERENTE']) && !empty($podePerfilGerente)) { $usrFaltamPerfis[] = 'gerente'; }
                    if (empty($usrEmpStats['por_perfil']['CAIXA'])) { $usrFaltamPerfis[] = 'caixa'; }
                    ?>
                    <p class="usr-pro-resumo">
                        <strong><?php echo (int)$usrEmpStats['ativos']; ?></strong> activo(s) ·
                        <strong><?php echo (int)$usrEmpStats['inativos']; ?></strong> inactivo(s)
                        <?php if ($usrFaltamPerfis): ?>
                            · <span class="usr-pro-falta">sem <?php echo htmlspecialchars(implode(' nem ', $usrFaltamPerfis)); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($usrParados)): ?>
                            · <span class="usr-pro-falta"><?php echo count($usrParados); ?> sem entrar há mais de 30 dias</span>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>

                <?php if (!empty($isEmpresarialUsuarios)): ?>
                    <div class="usr-emp-intro">
                        <strong>Equipa da unidade</strong> — veja quem está activo, quem está online (últimos 3 min) e se faltam perfis críticos (gerente, cozinha, bar).
                    </div>
                    <div class="usr-emp-kpis">
                        <div class="usr-emp-kpi is-ok">
                            <div class="k">Activos</div>
                            <div class="v"><?php echo (int)$usrEmpStats['ativos']; ?></div>
                            <div class="h"><?php echo (int)$usrEmpStats['total']; ?> no total</div>
                        </div>
                        <div class="usr-emp-kpi <?php echo $temColunaUltimoAcesso ? '' : 'is-warn'; ?>">
                            <div class="k">Online agora</div>
                            <div class="v"><?php echo $temColunaUltimoAcesso ? (int)$usrEmpStats['online'] : '—'; ?></div>
                            <div class="h"><?php echo $temColunaUltimoAcesso ? 'últimos 3 minutos' : 'presença indisponível'; ?></div>
                        </div>
                        <div class="usr-emp-kpi <?php echo $usrEmpStats['inativos'] > 0 ? 'is-warn' : ''; ?>">
                            <div class="k">Inactivos</div>
                            <div class="v"><?php echo (int)$usrEmpStats['inativos']; ?></div>
                            <div class="h">contas desactivadas</div>
                        </div>
                        <div class="usr-emp-kpi">
                            <div class="k">Perfis em uso</div>
                            <div class="v"><?php echo count($usrEmpStats['por_perfil']); ?></div>
                            <div class="h">papéis distintos</div>
                        </div>
                    </div>
                    <?php if (!empty($usrEmpStats['notas'])): ?>
                        <ul class="usr-emp-notes">
                            <?php foreach ($usrEmpStats['notas'] as $nota): ?>
                                <li class="tom-<?php echo htmlspecialchars((string)($nota['tom'] ?? 'info')); ?>">
                                    <strong><?php echo htmlspecialchars((string)($nota['titulo'] ?? '')); ?></strong>
                                    <span><?php echo htmlspecialchars((string)($nota['txt'] ?? '')); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- AÇÕES -->
                <div class="card mb-4 <?php echo !empty($isEmpresarialUsuarios) ? 'dash-emp-card' : ''; ?>">
                    <div class="card-body">
                        <?php
                        $usuariosPlanRid = function_exists('session_restaurante_capability_id')
                            ? (int)session_restaurante_capability_id()
                            : (int)$restauranteId;
                        $usuariosPlanRid = $usuariosPlanRid > 0 ? $usuariosPlanRid : (int)$restauranteId;
                        $totalUsuariosAtivosPagina = (int)$usrEmpStats['ativos'];
                        $planUsage = plano_verificar_limite_db($usuariosPlanRid, 'usuarios', $totalUsuariosAtivosPagina);
                        $planUsageLabel = 'Utilizadores activos';
                        include __DIR__ . '/includes/plan_usage_banner.php';
                        $bloquearNovoUsuario = !empty($planUsageBloqueado);
                        ?>
                        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                        <?php if (!empty($bloquearNovoUsuario)): ?>
                            <?php if (!empty($isEmpresarialUsuarios)): ?>
                                <button class="btn emp-split-action is-locked" type="button" disabled title="Limite do plano atingido">
                                    <span class="emp-split-action-icon"><i class="fas fa-lock"></i></span>
                                    <span class="emp-split-action-copy">
                                        <strong>Limite de utilizadores</strong>
                                        <small>Plano sem vagas de acesso</small>
                                    </span>
                                </button>
                            <?php else: ?>
                                <button class="btn btn-secondary" type="button" disabled title="Limite do plano atingido">
                                    <i class="fas fa-lock me-2"></i>Limite de utilizadores
                                </button>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php if (!empty($isEmpresarialUsuarios)): ?>
                                <button type="button" class="btn emp-split-action" onclick="abrirModal()">
                                    <span class="emp-split-action-icon"><i class="fas fa-user-plus"></i></span>
                                    <span class="emp-split-action-copy">
                                        <strong>Adicionar utilizador</strong>
                                        <small>Criar acesso da equipa</small>
                                    </span>
                                    <i class="fas fa-arrow-right emp-split-action-arrow" aria-hidden="true"></i>
                                </button>
                            <?php else: ?>
                                <button class="btn btn-primary" onclick="abrirModal()"><i class="fas fa-plus me-2"></i>Novo Usuário</button>
                            <?php endif; ?>
                        <?php endif; ?>
                        <div class="usr-filtros">
                            <label for="filtroPerfil" class="mb-0 text-muted" style="font-size: 13px;">Filtrar:</label>
                            <select id="filtroPerfil" class="form-select form-select-sm" aria-label="Perfil">
                                <option value="">Todos</option>
                                <option value="ADMIN">Administrador</option>
                                <?php if (!empty($podePerfilGerente)): ?>
                                    <option value="GERENTE">Gerente</option>
                                <?php endif; ?>
                                <option value="CAIXA">Caixa</option>
                                <option value="GARCOM">Garçom</option>
                                <?php if (!empty($podePerfisBarCozinha)): ?>
                                    <option value="COZINHA">Cozinha</option>
                                    <option value="BAR">Bar</option>
                                <?php endif; ?>
                            </select>
                            <?php if (!empty($usrPlanoMedio)): ?>
                                <select id="filtroEstado" class="form-select form-select-sm" aria-label="Estado">
                                    <option value="">Todos os estados</option>
                                    <option value="ativo">Activos</option>
                                    <option value="inativo">Inactivos</option>
                                </select>
                                <span class="badge text-bg-light" id="usrContagem"></span>
                            <?php endif; ?>
                            <?php if (!empty($isEmpresarialUsuarios)): ?>
                                <select id="filtroEstado" class="form-select form-select-sm" aria-label="Estado">
                                    <option value="">Todos os estados</option>
                                    <option value="ativo">Activos</option>
                                    <option value="inativo">Inactivos</option>
                                </select>
                                <?php if (count($unidadesUsuarios) > 1): ?>
                                    <select id="filtroUnidade" class="form-select form-select-sm" aria-label="Unidade">
                                        <option value="">Todas as unidades</option>
                                        <?php foreach ($unidadesUsuarios as $unId => $unNome): ?>
                                            <option value="<?php echo (int)$unId; ?>"><?php echo htmlspecialchars($unNome); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                                <span class="badge text-bg-light" id="usrContagem"></span>
                            <?php endif; ?>
                        </div>
                        </div>
                        <?php if (!empty($isEmpresarialUsuarios) && !empty($usrEmpStats['por_perfil'])): ?>
                            <div class="usr-emp-chips mt-3 mb-0" id="usrEmpChips" role="group" aria-label="Distribuição por perfil">
                                <button type="button" class="usr-emp-chip is-active" data-perfil-filter="">
                                    Todos<span class="n"><?php echo (int)$usrEmpStats['total']; ?></span>
                                </button>
                                <?php foreach ($usrEmpStats['por_perfil'] as $pNome => $pQtd): ?>
                                    <button type="button" class="usr-emp-chip" data-perfil-filter="<?php echo htmlspecialchars($pNome); ?>">
                                        <?php echo htmlspecialchars($pNome); ?><span class="n"><?php echo (int)$pQtd; ?></span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- TABELA -->
                <div class="card table-card <?php echo !empty($isEmpresarialUsuarios) ? 'dash-emp-card' : ''; ?>">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Usuário</th>
                                        <th>Email</th>
                                        <th>Perfil</th>
                                        <th>Status</th>
                                        <?php if (!empty($isEmpresarialUsuarios) && $temColunaUltimoAcesso): ?>
                                            <th>Presença</th>
                                        <?php elseif (!empty($usrMostraUltimoAcesso)): ?>
                                            <th>Último acesso</th>
                                        <?php endif; ?>
                                        <th>Cadastro</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $perfilAvatarBg = [
                                        'ADMIN' => '#dc3545',
                                        'GERENTE' => '#0d9488',
                                        'CAIXA' => '#17a2b8',
                                        'COZINHA' => '#6c757d',
                                        'BAR' => '#3b82f6',
                                        'GARCOM' => '#ffc107',
                                    ];
                                    $perfilBadgeClass = [
                                        'ADMIN' => 'danger',
                                        'GERENTE' => 'success',
                                        'CAIXA' => 'info',
                                        'COZINHA' => 'secondary',
                                        'BAR' => 'primary',
                                        'GARCOM' => 'warning',
                                    ];
                                    $colspanEmpty = ((!empty($isEmpresarialUsuarios) && $temColunaUltimoAcesso) || !empty($usrMostraUltimoAcesso)) ? 7 : 6;
                                    foreach ($usuarios as $u):
                                        $perfilU = (string)($u['_perfil'] ?? strtoupper((string)($u['perfil'] ?? '')));
                                        $avatarBg = $perfilAvatarBg[$perfilU] ?? '#ffc107';
                                        $badgeClass = $perfilBadgeClass[$perfilU] ?? 'warning';
                                        $fotoUrl = '';
                                        if (!empty($u['foto'])) {
                                            $fotoUrl = (strpos($u['foto'], 'http') === 0)
                                                ? $u['foto']
                                                : $baseUrl . '/' . ltrim($u['foto'], '/');
                                        }
                                        $isOnline = !empty($u['_online']);
                                        $ultimoAcessoTxt = '';
                                        if (!empty($u['ultimo_acesso'])) {
                                            $uaTs = strtotime((string)$u['ultimo_acesso']);
                                            if ($uaTs) {
                                                $ultimoAcessoTxt = date('d/m H:i', $uaTs);
                                            }
                                        }
                                        ?>
                                        <?php $ativoRow = !empty($u['_ativo']) || !empty($u['ativo']); $diasSA = $u['_dias_sem_acesso'] ?? null; ?>
                                        <tr class="usuario-row<?php echo !empty($u['_de_filial']) ? ' usr-row-filial' : ''; ?>" data-perfil="<?php echo htmlspecialchars($perfilU); ?>" data-estado="<?php echo $ativoRow ? 'ativo' : 'inativo'; ?>" data-unidade="<?php echo (int)($u['_unidade_id'] ?? 0); ?>">
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <?php if ($fotoUrl): ?>
                                                        <a href="<?php echo htmlspecialchars($fotoUrl); ?>" target="_blank" class="me-3" title="Abrir foto em nova aba">
                                                            <div class="avatar-sm" style="background: transparent;">
                                                                <img src="<?php echo htmlspecialchars($fotoUrl); ?>" alt="" data-fallback-src="<?php echo htmlspecialchars(usuario_img_iniciais((string)$u['nome'], $avatarBg, 40)); ?>">
                                                            </div>
                                                        </a>
                                                    <?php else: ?>
                                                        <div class="avatar-sm me-3" style="background: <?php echo htmlspecialchars($avatarBg); ?>;">
                                                            <?php echo htmlspecialchars(usuario_iniciais((string)$u['nome'])); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($u['nome']); ?></strong>
                                                        <?php if (count($unidadesUsuarios) > 1): ?>
                                                            <small class="d-block text-muted"><i class="fas <?php echo !empty($u['_de_filial']) ? 'fa-building' : 'fa-crown text-warning'; ?> me-1"></i><?php echo htmlspecialchars((string)($u['_unidade_nome'] ?? '')); ?></small>
                                                        <?php endif; ?>
                                                        <?php if (empty($isEmpresarialUsuarios)): ?>
                                                            <br>
                                                            <span class="badge bg-<?php echo htmlspecialchars($badgeClass); ?>">
                                                                <?php echo htmlspecialchars((string)$u['perfil']); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($u['email']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo htmlspecialchars($badgeClass); ?>">
                                                    <?php echo htmlspecialchars((string)$u['perfil']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (!empty($u['_ativo']) || !empty($u['ativo'])): ?>
                                                    <span class="badge bg-success">Ativo</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Inativo</span>
                                                <?php endif; ?>
                                            </td>
                                            <?php if (!empty($isEmpresarialUsuarios) && $temColunaUltimoAcesso): ?>
                                                <td>
                                                    <?php if ($isOnline): ?>
                                                        <span class="usr-presenca is-on" title="<?php echo htmlspecialchars($ultimoAcessoTxt ? ('Visto ' . $ultimoAcessoTxt) : 'Online'); ?>">
                                                            <span class="dot"></span> Online
                                                        </span>
                                                    <?php elseif ($ultimoAcessoTxt): ?>
                                                        <span class="usr-presenca is-off" title="Último acesso: <?php echo htmlspecialchars($ultimoAcessoTxt); ?>">
                                                            <span class="dot"></span> <?php echo $diasSA === 0 ? 'Hoje ' . htmlspecialchars(substr($ultimoAcessoTxt, -5)) : ($diasSA === 1 ? 'Ontem' : 'Há ' . (int)$diasSA . ' dias'); ?>
                                                        </span>
                                                        <?php if ($ativoRow && $diasSA !== null && $diasSA > 30): ?>
                                                            <span class="badge bg-warning text-dark d-block mt-1" title="Conta activa sem uso">Parada</span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="usr-presenca is-off"><span class="dot"></span> Nunca entrou</span>
                                                    <?php endif; ?>
                                                </td>
                                            <?php elseif (!empty($usrMostraUltimoAcesso)): ?>
                                                <td>
                                                    <?php if ($ultimoAcessoTxt !== ''): ?>
                                                        <span class="usr-ultimo"><?php echo htmlspecialchars($ultimoAcessoTxt); ?></span>
                                                        <?php if ($diasSA !== null && $diasSA > 30): ?>
                                                            <small class="usr-ultimo-aviso d-block">sem entrar há <?php echo (int)$diasSA; ?> dias</small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="usr-ultimo-aviso">Nunca entrou</span>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endif; ?>
                                            <td><?php echo date('d/m/Y', strtotime($u['criado_em'])); ?></td>
                                            <td>
                                                <?php if (!empty($u['_de_filial'])): ?>
                                                    <span class="badge bg-light text-dark border" title="Para editar, entre na filial">Gerido na filial</span>
                                                <?php elseif (isset($u['id']) && is_numeric($u['id']) && $u['id'] > 0): ?>
                                                    <button type="button" class="btn btn-sm btn-info" onclick="editarUsuario(<?php echo (int)$u['id']; ?>)" title="<?php echo $u['id'] == $_SESSION['usuario_id'] ? 'Editar meu perfil' : 'Editar'; ?>"><i class="fas fa-edit"></i></button>
                                                    <button type="button" class="btn btn-sm btn-warning" onclick="alterarSenha(<?php echo (int)$u['id']; ?>)" title="Alterar senha"><i class="fas fa-key"></i></button>
                                                    <?php if (!empty($isEmpresarialUsuarios)): ?>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="verHistoricoUsuario(<?php echo (int)$u['id']; ?>)" title="Histórico de alterações"><i class="fas fa-clock-rotate-left"></i></button>
                                                    <?php endif; ?>
                                                    <?php if ($u['id'] != $_SESSION['usuario_id']): ?>
                                                        <?php if ($ativoRow): ?>
                                                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="deletarUsuario(<?php echo (int)$u['id']; ?>)" title="Desactivar acesso"><i class="fas fa-user-slash"></i></button>
                                                        <?php else: ?>
                                                            <button type="button" class="btn btn-sm btn-outline-success" onclick="reativarUsuario(<?php echo (int)$u['id']; ?>)" title="Reactivar acesso"><i class="fas fa-user-check"></i></button>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary ms-1">Você</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="badge bg-warning">ID inválido</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($usuarios)): ?>
                                        <tr>
                                            <td colspan="<?php echo (int)$colspanEmpty; ?>" class="text-center py-4 text-muted">
                                                <i class="fas fa-users fa-2x mb-2 d-block"></i>
                                                Nenhum usuário encontrado
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <!-- MODAL CADASTRO/EDIÇÃO -->
    <div class="modal fade<?php echo !empty($isEmpresarialUsuarios) ? ' emp-form-modal' : ''; ?>" id="modalUsuario" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered<?php echo !empty($isEmpresarialUsuarios) ? ' modal-lg emp-form-dialog' : ''; ?>">
            <div class="modal-content<?php echo !empty($isEmpresarialUsuarios) ? ' emp-form-content' : ''; ?>">
                <div class="modal-header">
                    <?php if (!empty($isEmpresarialUsuarios)): ?>
                        <div class="emp-form-heading">
                            <span class="emp-form-heading-icon"><i class="fas fa-user-plus"></i></span>
                            <div>
                                <span class="emp-form-kicker"><i class="fas fa-crown"></i> Acesso da equipa</span>
                                <h5 class="modal-title" id="tituloModal">Novo Usuário</h5>
                                <p>Crie o acesso do colaborador ao salão, caixa ou cozinha.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <h5 class="modal-title" id="tituloModal"><i class="fas fa-plus me-2"></i>Novo Usuário</h5>
                    <?php endif; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert" id="alertModal" style="display: none;"></div>
                    <form id="formUsuario" enctype="multipart/form-data"<?php echo !empty($isEmpresarialUsuarios) ? ' class="emp-form-fields"' : ''; ?>>
                        <input type="hidden" id="usuario_id" name="usuario_id">
                        <input type="hidden" id="csrf_token_form" name="_csrf" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                        <!-- Foto do usuário (apenas PNG) -->
                        <div class="foto-upload-wrapper">
                            <input type="file" id="foto" name="foto" accept="image/png,image/jpeg" style="display: none;" onchange="previewFoto(this)">
                            <img id="fotoPreview" class="foto-preview" src="<?php echo htmlspecialchars(usuario_img_iniciais('?', '#FF6B35', 100)); ?>" alt="Foto do usuário">
                            <p class="text-muted small mb-2">Foto do colaborador no sistema</p>
                            <button type="button" class="btn btn-outline-secondary btn-sm mt-1" onclick="document.getElementById('foto').click()">
                                <i class="fas fa-camera me-1"></i> Escolher Foto
                            </button>
                            <button type="button" class="btn btn-outline-danger btn-sm mt-1" onclick="removerFoto()">
                                <i class="fas fa-times me-1"></i> Remover
                            </button>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="nome">Nome do colaborador *</label>
                            <input type="text" id="nome" name="nome" class="form-control" required placeholder="Nome que aparece na equipa">
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="email">Email de acesso *</label>
                            <input type="email" id="email" name="email" class="form-control" required placeholder="Email para entrar no sistema">
                        </div>

                        <div class="mb-3" id="senhaGroup">
                            <label class="form-label" for="senha">Senha de acesso *</label>
                            <input type="password" id="senha" name="senha" class="form-control" placeholder="Senha para o primeiro acesso" minlength="<?php echo (int)$senhaRegrasPorPerfil['ADMIN']['min']; ?>">
                            <small class="text-muted" id="senhaHint"></small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="perfil">Função no salão *</label>
                            <select id="perfil" name="perfil" class="form-select" required>
                                <option value="ADMIN">Administrador</option>
                                <?php if (!empty($podePerfilGerente)): ?>
                                    <option value="GERENTE">Gerente</option>
                                <?php endif; ?>
                                <option value="CAIXA">Caixa</option>
                                <option value="GARCOM">Garçom</option>
                                <?php if (!empty($podePerfisBarCozinha)): ?>
                                    <option value="COZINHA">Cozinha</option>
                                    <option value="BAR">Bar</option>
                                <?php endif; ?>
                            </select>
                            <?php if (empty($podePerfisBarCozinha) || empty($podePerfilGerente)): ?>
                                <small class="text-muted">
                                    <?php if (empty($podePerfilGerente)): ?>
                                        Gerente disponível no Profissional/Empresarial.
                                    <?php endif; ?>
                                    <?php if (empty($podePerfisBarCozinha)): ?>
                                        Cozinha e Bar disponíveis no plano Profissional (telas KDS).
                                    <?php endif; ?>
                                </small>
                            <?php endif; ?>
                        </div>

                        <div class="form-check">
                            <input type="checkbox" id="ativo" name="ativo" class="form-check-input" checked>
                            <label class="form-check-label">Conta activa no sistema</label>
                        </div>
                    </form>
                    <?php if (!empty($isEmpresarialUsuarios)): ?>
                        <aside class="emp-form-preview" aria-live="polite">
                            <div class="emp-form-preview-top">
                                <span>Pré-visualização do acesso</span>
                            </div>
                            <div class="emp-form-preview-card">
                                <span class="emp-form-heading-icon" style="width:42px;height:42px;flex-basis:42px;font-size:16px;background:#fff7ed;color:#9a3412;border-color:#fed7aa;"><i class="fas fa-user"></i></span>
                                <div>
                                    <strong id="userPreviewNome">Nome do colaborador</strong>
                                    <small id="userPreviewPerfil">Função no salão</small>
                                </div>
                            </div>
                            <p>O colaborador entra com o email e a senha definidos aqui.</p>
                        </aside>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" form="formUsuario" class="btn<?php echo !empty($isEmpresarialUsuarios) ? ' emp-form-submit' : ' btn-primary'; ?>"><i class="fas fa-save me-2"></i>Salvar utilizador</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL ALTERAR SENHA -->
    <div class="modal fade<?php echo !empty($isEmpresarialUsuarios) ? ' emp-form-modal' : ''; ?>" id="modalSenha" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered<?php echo !empty($isEmpresarialUsuarios) ? ' emp-form-dialog' : ''; ?>">
            <div class="modal-content<?php echo !empty($isEmpresarialUsuarios) ? ' emp-form-content' : ''; ?>">
                <div class="modal-header">
                    <?php if (!empty($isEmpresarialUsuarios)): ?>
                        <div class="emp-form-heading">
                            <span class="emp-form-heading-icon"><i class="fas fa-key"></i></span>
                            <div>
                                <span class="emp-form-kicker"><i class="fas fa-crown"></i> Segurança do acesso</span>
                                <h5 class="modal-title">Alterar senha</h5>
                                <p>Defina a nova senha com que o colaborador entra no sistema.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <h5 class="modal-title"><i class="fas fa-key me-2"></i>Alterar Senha</h5>
                    <?php endif; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert" id="alertSenha" style="display: none;"></div>
                    <form id="formSenha"<?php echo !empty($isEmpresarialUsuarios) ? ' class="emp-form-fields"' : ''; ?>>
                        <input type="hidden" id="senha_usuario_id" name="usuario_id">

                        <div class="mb-3">
                            <label class="form-label">Nova Senha *</label>
                            <input type="password" id="nova_senha" name="nova_senha" class="form-control" required placeholder="Nova senha de acesso" minlength="<?php echo (int)$senhaRegraAlterar['min']; ?>">
                            <small class="text-muted"><?php echo htmlspecialchars($senhaRegraAlterar['texto'] ?: ('Mínimo de ' . (int)$senhaRegraAlterar['min'] . ' caracteres.')); ?></small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Confirmar Senha *</label>
                            <input type="password" id="confirmar_senha" name="confirmar_senha" class="form-control" required placeholder="Repetir a nova senha de acesso">
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" form="formSenha" class="btn<?php echo !empty($isEmpresarialUsuarios) ? ' emp-form-submit' : ' btn-warning'; ?>"><i class="fas fa-key me-2"></i>Guardar senha</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="historicoUsuarioModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-clock-rotate-left me-2 text-secondary"></i>Histórico de <span id="historicoUsuarioNome"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light"><tr><th>Quando</th><th>Alteração</th><th>Feito por</th><th>Detalhe</th></tr></thead>
                            <tbody id="historicoUsuarioCorpo"></tbody>
                        </table>
                    </div>
                    <small class="text-muted d-block mt-2">Mostra as 30 alterações mais recentes registadas na auditoria.</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="lib/bootstrap-5.3.2/js/bootstrap.bundle.min.js"></script>
    <script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
        const BASE_URL = <?php echo json_encode($baseUrl, JSON_UNESCAPED_UNICODE); ?>;
        const CSRF_TOKEN = <?php echo json_encode($csrfToken, JSON_UNESCAPED_UNICODE); ?>;
        const PODE_PERFIS_BAR_COZINHA = <?php echo !empty($podePerfisBarCozinha) ? 'true' : 'false'; ?>;
        const PODE_PERFIL_GERENTE = <?php echo !empty($podePerfilGerente) ? 'true' : 'false'; ?>;
        const SESSION_USUARIO_ID = <?php echo (int)($_SESSION['usuario_id'] ?? 0); ?>;
        const EDIT_ME_ON_LOAD = <?php echo !empty($_GET['edit_me']) ? 'true' : 'false'; ?>;
        const SENHA_REGRAS = <?php echo json_encode($senhaRegrasPorPerfil, JSON_UNESCAPED_UNICODE); ?>;
        const AVATAR_VAZIO = <?php echo json_encode(usuario_img_iniciais('?', '#FF6B35', 100)); ?>;
    </script>
    <script src="js/usuarios.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/usuarios.js'); ?>"></script>
    <script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
        // Preview da foto
        function previewFoto(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('fotoPreview').src = e.target.result;
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Remover foto
        function removerFoto() {
            document.getElementById('foto').value = '';
            document.getElementById('fotoPreview').src = AVATAR_VAZIO;
        }
    </script>
</body>

</html>



