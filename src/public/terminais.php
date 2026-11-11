<?php
require_once __DIR__ . '/app_base.php';

include_once APP_BASE . '/src/config/auth_check.php';
include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/csrf.php';
include_once APP_BASE . '/src/config/personal_data.php';
include_once APP_BASE . '/src/config/terminal_pin.php';
include_once APP_BASE . '/src/config/plano_check.php';

$perfil = strtoupper(trim((string)($_SESSION['perfil'] ?? '')));
if ($perfil !== 'ADMIN' || !empty($_SESSION['super_admin'])) {
    header('Location: dashboard.php?erro=acesso_negado');
    exit;
}
$rid = (int)($_SESSION['restaurante_id'] ?? 0);
$db = (new Database())->getConnection();
$terminais = terminal_listar($db, $rid);
$atual = terminal_atual($db);
$esteId = $atual && (int)$atual['restaurante_id'] === $rid ? (int)$atual['id'] : 0;

// Equipa com perfil que usa PIN, para o admin ver quem ja tem PIN e poder apaga-lo.
$in = implode(',', array_fill(0, count(TERMINAL_PERFIS_PIN) + 1, '?'));
$stmt = $db->prepare("SELECT id, nome, perfil, pin_hash IS NOT NULL AS tem_pin, (pin_bloqueado_ate > NOW()) AS bloqueado
    FROM usuarios WHERE restaurante_id = ? AND COALESCE(ativo, 1) = 1 AND COALESCE(super_admin, 0) = 0 AND UPPER(perfil) IN ($in) ORDER BY nome");
$stmt->execute(array_merge([$rid], TERMINAL_PERFIS_PIN, ['GARÇOM']));
$equipa = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
// Até quando o PIN está bloqueado (item 17).
$bloqueios = [];
try {
    $stBloq = $db->prepare("SELECT id, pin_bloqueado_ate FROM usuarios
        WHERE restaurante_id = ? AND pin_bloqueado_ate IS NOT NULL AND pin_bloqueado_ate > NOW()");
    $stBloq->execute([$rid]);
    foreach ($stBloq->fetchAll(PDO::FETCH_ASSOC) as $linhaBloq) {
        $bloqueios[(int)$linhaBloq['id']] = (string)$linhaBloq['pin_bloqueado_ate'];
    }
} catch (Throwable $e) {
}

// Empresarial com filiais: terminais das outras unidades, só para consulta (item 19).
$terminaisRede = [];
$planoTerminais = 'BASICO';
try {
    if (function_exists('plano_get_dados') && function_exists('plano_normalizar_nome')) {
        $planoTerminais = plano_normalizar_nome(plano_get_dados($rid)['plano_nome'] ?? 'BASICO');
    }
} catch (Throwable $e) {
}
$terminaisEmpresarial = ($planoTerminais === 'EMPRESARIAL');
if ($terminaisEmpresarial) {
    try {
        $stFil = $db->prepare("SELECT id, nome FROM restaurantes WHERE filial_id = ? AND COALESCE(is_matriz, 0) = 0 ORDER BY nome");
        $stFil->execute([$rid]);
        $filiaisTerm = $stFil->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        foreach ($filiaisTerm as $filId => $filNome) {
            foreach (terminal_listar($db, (int)$filId) as $termFil) {
                $termFil['unidade'] = (string)$filNome;
                $terminaisRede[] = $termFil;
            }
        }
    } catch (Throwable $e) {
        error_log('[terminais] rede: ' . $e->getMessage());
    }
}

// Últimos desbloqueios nesta unidade (item 20).
$acessosTerminais = $terminaisEmpresarial ? terminal_acessos_recentes($db, $rid, 0, 20) : [];

// Profissional: quem foi o ultimo a desbloquear cada terminal (sem o historico completo).
$terminaisProfissional = ($planoTerminais === 'PROFISSIONAL');
$ultimoAcessoPorTerminal = [];
if ($terminaisProfissional) {
    try {
        $stUlt = $db->prepare("
            SELECT a.terminal_id, a.criado_em, a.resultado, u.nome AS usuario_nome
            FROM terminal_acessos a
            LEFT JOIN usuarios u ON u.id = a.usuario_id
            WHERE a.restaurante_id = :rid AND a.resultado = 'OK'
            ORDER BY a.criado_em DESC, a.id DESC
        ");
        $stUlt->execute([':rid' => $rid]);
        foreach ($stUlt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $linhaUlt) {
            $tidUlt = (int)$linhaUlt['terminal_id'];
            if ($tidUlt > 0 && !isset($ultimoAcessoPorTerminal[$tidUlt])) {
                $ultimoAcessoPorTerminal[$tidUlt] = $linhaUlt;
            }
        }
    } catch (Throwable $e) {
        error_log('[terminais] ultimo acesso: ' . $e->getMessage());
    }
}

// Resumo do topo (item 17): terminais parados e equipa sem PIN.
$termSemPin = 0;
foreach ($equipa as $uResumo) {
    if (!(int)$uResumo['tem_pin']) { $termSemPin++; }
}
$termParadosResumo = 0;
foreach ($terminais as $tResumo) {
    $usoResumo = !empty($tResumo['ultimo_uso_em']) ? strtotime((string)$tResumo['ultimo_uso_em']) : 0;
    $criadoResumo = !empty($tResumo['criado_em']) ? strtotime((string)$tResumo['criado_em']) : time();
    $paradoResumo = $usoResumo
        ? ((int)floor((time() - $usoResumo) / 86400) > 30)
        : ((time() - $criadoResumo) > 7 * 86400);
    if ($paradoResumo) { $termParadosResumo++; }
}

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$fmt = static fn($d) => $d ? date('d/m/Y H:i', strtotime((string)$d)) : '—';
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $h(csrf_get_token()) ?>">
    <title>Terminais - Nivora</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link href="lib/bootstrap-5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="lib/fontawesome-6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/premium.css">
    <link rel="stylesheet" href="css/tokens.css?v=<?= (int)@filemtime(__DIR__ . '/css/tokens.css') ?>">
    <style>
        /* O .row da grelha puxava o conteudo para debaixo da barra lateral. */
        .container-fluid > .row { margin-left: 0; margin-right: 0; }
        .container-fluid > .row > .main-content { flex: 1 1 auto; width: auto; min-width: 0; margin-left: 280px; max-width: calc(100% - 280px); }
        @media (max-width: 991px) {
            .container-fluid > .row > .main-content { margin-left: 0; max-width: 100%; }
        }

        .tm__resumo { display: flex; flex-wrap: wrap; gap: 10px; margin: 0 0 16px; }
        .tm__resumo span { padding: 6px 13px; border-radius: 999px; border: 1px solid #e2e8f0; background: #fff; font-size: 13px; color: #475569; }
        .tm__resumo span strong { color: #0f172a; }
        .tm__resumo span.is-aviso { border-color: rgba(245, 158, 11, .45); background: rgba(245, 158, 11, .1); color: #92400e; }

        /* Profissional e Empresarial: apresentacao mais cuidada. O Basico fica como esta. */
        .tm__etiqueta,.tm__ic{display:none}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .tm{max-width:none}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .tm__topo{border-radius:16px;padding:24px 28px;margin:14px 0 22px;background:var(--nv-plano-suave,#f1f5f9);border:1px solid color-mix(in srgb,var(--nv-plano) 18%,#fff)}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .tm__topo h1{margin-top:0!important;font-weight:700}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .tm__topo p{margin:0;color:#475569!important;line-height:1.6}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .tm__etiqueta{display:inline-flex;align-items:center;gap:6px;font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:4px 10px;border-radius:999px;margin-bottom:10px;background:#fff;color:var(--nv-plano);border:1px solid color-mix(in srgb,var(--nv-plano) 25%,#fff)}
        body.plan-theme-empresarial .tm__topo{background:linear-gradient(120deg,#0f172a 0%,#1e293b 60%,color-mix(in srgb,var(--nv-plano) 55%,#1e293b) 100%);border:0;box-shadow:0 12px 30px rgba(15,23,42,.18)}
        body.plan-theme-empresarial .tm__topo h1{color:#fff}
        body.plan-theme-empresarial .tm__topo p{color:#cbd5e1!important}
        body.plan-theme-empresarial .tm__etiqueta{background:rgba(255,255,255,.1);color:#fed7aa;border-color:rgba(255,255,255,.18)}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .tm .card{border:1px solid #e2e8f0;border-top:3px solid var(--nv-plano);border-radius:16px;box-shadow:0 1px 2px rgba(15,23,42,.04),0 10px 28px rgba(15,23,42,.06);overflow:hidden}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .tm .card-body{padding:26px 28px}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .tm .card h2{display:flex;align-items:center;gap:12px;font-size:1.2rem;font-weight:700;margin-bottom:12px}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .tm__ic{display:inline-flex;width:40px;height:40px;border-radius:10px;align-items:center;justify-content:center;font-size:1rem;background:var(--nv-plano-suave,#f1f5f9);color:var(--nv-plano);border:1px solid color-mix(in srgb,var(--nv-plano) 22%,#fff)}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .tm .form-control{border-radius:10px;padding:11px 14px;border-color:#cbd5e1}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .tm .form-control:focus{border-color:var(--nv-plano);box-shadow:0 0 0 3px color-mix(in srgb,var(--nv-plano) 20%,transparent)}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .tm .btn-primary{background:var(--nv-plano);border-color:var(--nv-plano);border-radius:10px;padding:11px 20px;font-weight:600;box-shadow:0 6px 16px color-mix(in srgb,var(--nv-plano) 28%,transparent)}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .tm .table{border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;border-collapse:separate;border-spacing:0}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .tm .table thead th{background:#f8fafc;color:#64748b;font-size:.75rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;border-bottom:1px solid #e2e8f0;padding:12px 16px}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .tm .table td{padding:14px 16px;border-color:#f1f5f9}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .tm .table tbody tr:hover td{background:#f8fafc}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .tm .badge{border-radius:999px;font-weight:600;font-size:.75rem;padding:5px 10px;text-transform:none;letter-spacing:0}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .tm .badge.bg-success{background:#ecfdf3!important;color:#067647;border:1px solid #abefc6}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .tm .badge.bg-warning{background:#fffaeb!important;color:#b54708!important;border:1px solid #fedf89}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .tm .btn-sm{border-radius:8px;padding:6px 12px;font-weight:600}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .tm .btn-outline-secondary{color:#334155;border-color:#cbd5e1;background:#fff}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .tm .btn-outline-secondary:hover{background:#f1f5f9;color:#0f172a}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .tm .btn-outline-danger{color:#b42318;border-color:#fecdca;background:#fff}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .tm .btn-outline-danger:hover{background:#fef3f2;color:#912018}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .tm .tm__vazio{display:flex;align-items:center;gap:10px;padding:14px 16px;border:1px dashed #cbd5e1;border-radius:12px;background:#f8fafc}
        .tm__parado td{background:#fffbeb}
        .tm__alerta{color:#b45308}
    </style>
</head>
<body class="premium-ui">
<div class="container-fluid">
    <div class="row">
        <?php include_once __DIR__ . '/includes/sidebar.php'; ?>
        <main class="main-content tm">
            <a href="dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Voltar</a>
            <header class="tm__topo">
            <span class="tm__etiqueta"><i class="fas fa-shield-halved" aria-hidden="true"></i> Acesso da equipa</span>
            <h1 class="h3 mt-3">Terminais do restaurante</h1>
            <p class="text-muted" style="max-width:720px">Um terminal é um computador ou tablet do restaurante (caixa, cozinha, bar). Nele, a equipa entra só com o seu PIN e, quando a sessão expira ou alguém sai, volta ao ecrã de bloqueio em vez do login. O administrador continua a entrar sempre com email e palavra-passe.</p>
            </header>

            <div class="tm__resumo" aria-label="Resumo dos terminais">
                <span><strong><?= count($terminais) ?></strong> terminal(is) activo(s)</span>
                <span class="<?= $termParadosResumo > 0 ? 'is-aviso' : '' ?>"><strong><?= (int)$termParadosResumo ?></strong> parado(s)</span>
                <span class="<?= $termSemPin > 0 ? 'is-aviso' : '' ?>"><strong><?= (int)$termSemPin ?></strong> sem PIN definido</span>
            </div>

            <div id="termAviso" class="alert d-none" role="alert"></div>

            <div class="card mb-4"><div class="card-body">
                <h2 class="h5"><span class="tm__ic"><i class="fas fa-laptop" aria-hidden="true"></i></span>Este dispositivo</h2>
                <?php if ($esteId): ?>
                    <p class="mb-2"><i class="fas fa-check-circle text-success"></i> Este dispositivo já é um terminal do restaurante.</p>
                    <a class="btn btn-outline-primary" href="terminal.php"><i class="fas fa-lock"></i> Abrir ecrã de bloqueio</a>
                <?php else: ?>
                    <form id="formRegistar" class="row g-2 align-items-end" autocomplete="off">
                        <div class="col-sm-6 col-lg-4">
                            <label class="form-label" for="termNome">Nome do terminal</label>
                            <input class="form-control" id="termNome" maxlength="80" placeholder="Identificação do terminal" required>
                        </div>
                        <div class="col-auto"><button class="btn btn-primary" type="submit"><i class="fas fa-desktop"></i> Tornar este dispositivo um terminal</button></div>
                    </form>
                    <p class="small text-muted mt-2 mb-0">Faça isto apenas em dispositivos que ficam no restaurante. Num computador pessoal, não.</p>
                <?php endif; ?>
            </div></div>

            <div class="card mb-4"><div class="card-body">
                <h2 class="h5"><span class="tm__ic"><i class="fas fa-desktop" aria-hidden="true"></i></span>Terminais ativos</h2>
                <?php if (!$terminais): ?>
                    <p class="text-muted mb-0 tm__vazio">Ainda não há terminais.</p>
                <?php else: ?>
                    <div class="table-responsive"><table class="table align-middle mb-0">
                        <thead><tr><th>Nome</th><th>Aparelho</th><th>Criado</th><th>Último uso</th><?php if ($terminaisProfissional): ?><th>Último a entrar</th><?php endif; ?><th></th></tr></thead>
                        <tbody>
                        <?php $paradosTerm = 0; foreach ($terminais as $t):
                            $usoTs = !empty($t['ultimo_uso_em']) ? strtotime((string)$t['ultimo_uso_em']) : 0;
                            $diasParado = $usoTs ? (int)floor((time() - $usoTs) / 86400) : null;
                            $criadoTs = !empty($t['criado_em']) ? strtotime((string)$t['criado_em']) : time();
                            // Um terminal acabado de criar ainda nao tem uso: so avisa passados 7 dias.
                            $parado = $diasParado === null
                                ? ((time() - $criadoTs) > 7 * 86400)
                                : $diasParado > 30;
                            if ($parado) { $paradosTerm++; }
                        ?>
                            <tr class="<?= $parado ? 'tm__parado' : '' ?>">
                                <td><?= $h($t['nome']) ?><?= (int)$t['id'] === $esteId ? ' <span class="badge bg-secondary">este</span>' : '' ?></td>
                                <td class="text-muted"><?= $h($t['dispositivo'] ?? '') !== '' ? $h($t['dispositivo']) : '<span class="text-muted">—</span>' ?></td>
                                <td><?= $h($fmt($t['criado_em'])) ?></td>
                                <td>
                                    <?= $h($fmt($t['ultimo_uso_em'])) ?>
                                    <?php if ($parado): ?>
                                        <span class="badge bg-warning text-dark d-block mt-1" title="Sem uso: considere revogar">
                                            <?= $diasParado === null ? 'Nunca usado' : 'Parado há ' . (int)$diasParado . ' dias' ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <?php if ($terminaisProfissional): ?>
                                    <td>
                                        <?php $acUlt = $ultimoAcessoPorTerminal[(int)$t['id']] ?? null; ?>
                                        <?php if ($acUlt): ?>
                                            <?= $h($acUlt['usuario_nome'] ? personal_data_decrypt_result($acUlt['usuario_nome'], 'nome') : '—') ?>
                                            <small class="d-block text-muted"><?= $h($fmt($acUlt['criado_em'])) ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">sem entradas por PIN</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td class="text-end">
                                    <?php if ($terminaisEmpresarial): ?>
                                        <button class="btn btn-sm btn-outline-secondary" data-acessos="<?= (int)$t['id'] ?>" data-nome="<?= $h($t['nome']) ?>" title="Ver quem entrou">Acessos</button>
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-outline-danger" data-revogar="<?= (int)$t['id'] ?>" data-nome="<?= $h($t['nome']) ?>">Revogar</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                    <?php if ($paradosTerm > 0): ?>
                        <p class="small mt-2 mb-0 tm__alerta"><i class="fas fa-triangle-exclamation"></i> <?= (int)$paradosTerm ?> terminal(is) sem uso há mais de 30 dias. Se já não são usados, revogue-os.</p>
                    <?php endif; ?>
                    <p class="small text-muted mt-2 mb-0">Revogue logo um terminal perdido, roubado ou que deixou de usar: deixa de aceitar PIN de imediato. Um traço em <strong>Aparelho</strong> quer dizer que o terminal foi criado antes de o sistema passar a guardar o aparelho: fica preenchido no próximo registo.</p>
                <?php endif; ?>
            </div></div>

            <div class="card mb-4"><div class="card-body">
                <h2 class="h5"><span class="tm__ic"><i class="fas fa-key" aria-hidden="true"></i></span>PIN da equipa</h2>
                <p class="text-muted">Cada funcionário define o seu PIN em <strong>Definir PIN</strong> no menu. Aqui pode apagar o PIN de alguém (por exemplo quando se esquece ou sai da equipa). Quem erra o PIN várias vezes fica <strong>bloqueado</strong> por alguns minutos — pode desbloquear sem mudar o PIN.</p>
                <?php if (!$equipa): ?>
                    <p class="text-muted mb-0">Sem funcionários da equipa.</p>
                <?php else: ?>
                    <div class="table-responsive"><table class="table align-middle mb-0">
                        <thead><tr><th>Nome</th><th>Perfil</th><th>PIN</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($equipa as $u): $nome = personal_data_decrypt_result($u['nome'], 'nome'); ?>
                            <tr>
                                <td><?= $h($nome) ?></td>
                                <td><?= $h(terminal_normalizar_perfil($u['perfil'])) ?></td>
                                <td>
                                    <?php if (!(int)$u['tem_pin']): ?>
                                        <span class="text-muted">sem PIN</span>
                                    <?php elseif ((int)$u['bloqueado']): ?>
                                        <?php
                                            $ateBloq = $bloqueios[(int)$u['id']] ?? '';
                                            $faltaMin = $ateBloq ? max(1, (int)ceil((strtotime($ateBloq) - time()) / 60)) : 0;
                                        ?>
                                        <span class="badge bg-warning text-dark">bloqueado</span>
                                        <?php if ($faltaMin > 0): ?>
                                            <small class="d-block text-muted">liberta em <?= (int)$faltaMin ?> min</small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge bg-success">definido</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if ((int)$u['tem_pin'] && (int)$u['bloqueado']): ?>
                                        <button class="btn btn-sm btn-outline-primary" data-desbloquear-pin="<?= (int)$u['id'] ?>" data-nome="<?= $h($nome) ?>" title="Mantém o mesmo PIN">Desbloquear</button>
                                    <?php endif; ?>
                                    <?php if ((int)$u['tem_pin']): ?>
                                        <button class="btn btn-sm btn-outline-secondary" data-limpar-pin="<?= (int)$u['id'] ?>" data-nome="<?= $h($nome) ?>">Apagar PIN</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
            </div></div>
            <?php if ($terminaisEmpresarial && $terminaisRede): ?>
            <div class="card mb-4"><div class="card-body">
                <h2 class="h5"><span class="tm__ic"><i class="fas fa-sitemap" aria-hidden="true"></i></span>Terminais das filiais</h2>
                <p class="text-muted">Só para consulta. Para revogar um terminal de uma filial, entre nessa unidade.</p>
                <div class="table-responsive"><table class="table align-middle mb-0">
                    <thead><tr><th>Unidade</th><th>Nome</th><th>Aparelho</th><th>Último uso</th></tr></thead>
                    <tbody>
                    <?php foreach ($terminaisRede as $tr):
                        $usoRedeTs = !empty($tr['ultimo_uso_em']) ? strtotime((string)$tr['ultimo_uso_em']) : 0;
                        $diasRede = $usoRedeTs ? (int)floor((time() - $usoRedeTs) / 86400) : null;
                        $criadoRedeTs = !empty($tr['criado_em']) ? strtotime((string)$tr['criado_em']) : time();
                        $paradoRede = $diasRede === null ? ((time() - $criadoRedeTs) > 7 * 86400) : $diasRede > 30;
                    ?>
                        <tr>
                            <td><i class="fas fa-building text-muted me-1"></i><?= $h($tr['unidade']) ?></td>
                            <td><?= $h($tr['nome']) ?></td>
                            <td class="text-muted"><?= $h($tr['dispositivo'] ?? '') !== '' ? $h($tr['dispositivo']) : '—' ?></td>
                            <td>
                                <?= $h($fmt($tr['ultimo_uso_em'])) ?>
                                <?php if ($paradoRede): ?>
                                    <span class="badge bg-warning text-dark d-block mt-1"><?= $diasRede === null ? 'Nunca usado' : 'Parado há ' . (int)$diasRede . ' dias' ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div></div>
            <?php endif; ?>

            <?php if ($terminaisEmpresarial): ?>
            <div class="card mb-4"><div class="card-body">
                <h2 class="h5"><span class="tm__ic"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i></span>Últimos acessos aos terminais</h2>
                <p class="text-muted">Quem desbloqueou cada terminal com o PIN, do mais recente para o mais antigo.</p>
                <?php if (!$acessosTerminais): ?>
                    <p class="text-muted mb-0 tm__vazio">Ainda não há acessos registados. A partir de agora, cada entrada por PIN fica aqui.</p>
                <?php else: ?>
                    <div class="table-responsive"><table class="table align-middle mb-0">
                        <thead><tr><th>Quando</th><th>Terminal</th><th>Quem</th><th>Resultado</th></tr></thead>
                        <tbody>
                        <?php foreach ($acessosTerminais as $ac): ?>
                            <tr>
                                <td><?= $h($fmt($ac['criado_em'])) ?></td>
                                <td><?= $h($ac['terminal_nome'] ?: '—') ?></td>
                                <td><?= $h($ac['usuario_nome'] ? personal_data_decrypt_result($ac['usuario_nome'], 'nome') : '—') ?></td>
                                <td>
                                    <?php if (strtoupper((string)$ac['resultado']) === 'OK'): ?>
                                        <span class="badge bg-success">entrou</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">PIN errado</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
            </div></div>
            <?php endif; ?>

        </main>
    </div>
</div>
<div class="modal fade" id="modalAcessosTerminal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-clock-rotate-left me-2 text-secondary"></i>Acessos de <span id="acessosTerminalNome"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Quando</th><th>Quem</th><th>Resultado</th></tr></thead>
                        <tbody id="acessosTerminalCorpo"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button></div>
        </div>
    </div>
</div>

<script src="lib/bootstrap-5.3.2/js/bootstrap.bundle.min.js"></script>
<script src="js/terminais.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/terminais.js'); ?>"></script>
</body>
</html>
