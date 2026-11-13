<?php
require_once __DIR__ . '/app_base.php';
require_once APP_BASE . '/src/config/super_admin_check.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/csrf.php';
require_once APP_BASE . '/src/config/audit.php';
require_once APP_BASE . '/src/config/restaurante_context.php';

/*
 * Registo de reembolsos (Termos de Uso, seccoes 2, 5, 8 e 12): o dinheiro e devolvido por fora
 * (M-Pesa, transferencia...), e aqui fica a prova: quem, quanto, porque, quando e por quem.
 * Os registos nao se apagam nem editam (so se corrige com um novo registo, com valor negativo).
 */
requireSuperAdminPermission('approve_plans');
$db = (new Database())->getConnection();
$privacyPendingCount = 0;
$csrfToken = csrf_get_token();
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$metodos = ['M-Pesa', 'e-Mola', 'Transferência bancária', 'Numerário', 'Cartão', 'Crédito no plano', 'Outro'];
$motivos = ['Funcionalidade essencial retirada (secção 2)', 'Alteração dos Termos (secção 12)', 'Paragem do serviço (secção 8)', 'Pagamento em duplicado', 'Pagamento por engano', 'Decisão comercial', 'Outro'];
$msg = null; $erro = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_is_valid((string)($_POST['_csrf'] ?? ''))) {
        $erro = 'Sessão expirada. Recarregue a página e tente de novo.';
    } else {
        $rid = (int)($_POST['restaurante_id'] ?? 0);
        $valor = round((float)str_replace([' ', ','], ['', '.'], (string)($_POST['valor'] ?? '0')), 2);
        $metodo = in_array($_POST['metodo'] ?? '', $metodos, true) ? $_POST['metodo'] : '';
        $motivoBase = in_array($_POST['motivo'] ?? '', $motivos, true) ? $_POST['motivo'] : '';
        $detalhe = trim(mb_substr((string)($_POST['detalhe'] ?? ''), 0, 300));
        $ref = trim(mb_substr((string)($_POST['referencia'] ?? ''), 0, 120));
        $data = (string)($_POST['data_reembolso'] ?? '');
        $nome = $db->prepare('SELECT nome FROM restaurantes WHERE id = ?');
        $nome->execute([$rid]);
        $nomeRest = $nome->fetchColumn();
        if (!$nomeRest) { $erro = 'Escolha um restaurante válido.'; }
        elseif ($valor == 0.0 || abs($valor) > 10000000) { $erro = 'Indique o valor reembolsado.'; }
        elseif ($metodo === '' || $motivoBase === '') { $erro = 'Escolha o método e o motivo.'; }
        elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || $data > date('Y-m-d')) { $erro = 'Data inválida (não pode ser no futuro).'; }
        else {
            $motivo = $motivoBase . ($detalhe !== '' ? ' — ' . $detalhe : '');
            $db->prepare('INSERT INTO plano_reembolsos (restaurante_id, restaurante_nome, valor, metodo, referencia, motivo, data_reembolso, registado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$rid, $nomeRest, $valor, $metodo, $ref !== '' ? $ref : null, $motivo, $data, (int)($_SESSION['usuario_id'] ?? 0)]);
            audit_log($db, 'plano_reembolso_registado', ['restaurante_id' => $rid, 'valor' => $valor, 'metodo' => $metodo], 'WARN');
            header('Location: admin_reembolsos.php?ok=1');
            exit;
        }
    }
}
if (isset($_GET['ok'])) { $msg = 'Reembolso registado.'; }
$restaurantes = $db->query("SELECT id, nome FROM restaurantes ORDER BY nome LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC);
$lista = $db->query("SELECT r.*, u.nome AS autor FROM plano_reembolsos r LEFT JOIN usuarios u ON u.id = r.registado_por ORDER BY r.data_reembolso DESC, r.id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
$total = array_sum(array_map(fn($r) => (float)$r['valor'], $lista));
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reembolsos - Nivora Restaurant Admin</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/adminpro-theme.css">
    <style>
        *{box-sizing:border-box}.sidebar{position:fixed;top:0;left:0;z-index:1000;transition:transform .25s ease}body{margin:0;min-height:100vh;overflow-x:hidden;background:linear-gradient(180deg,#f5f7ff 0%,#eef2fb 100%);color:#0f172a;font-family:'Poppins',sans-serif;font-size:13px}.audit-main{width:calc(100% - 260px);margin-left:260px;padding:18px;transition:.25s}.audit-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}.audit-stat,.audit-panel{background:#fff;border:1px solid #dbe3ef;border-radius:12px;box-shadow:0 8px 22px rgba(15,23,42,.06)}.audit-stat{padding:16px}.audit-stat strong{display:block;font-size:25px}.audit-stat span{color:#64748b;font-size:11px;font-weight:600}.audit-filters{display:grid;grid-template-columns:minmax(220px,1fr) 180px 150px auto;gap:10px;padding:14px}.audit-context{max-width:360px;overflow-wrap:anywhere;color:#475569;font-family:Consolas,monospace;font-size:11px}.audit-uri{max-width:240px;overflow-wrap:anywhere}.menu-toggle-btn{position:fixed;left:16px;top:16px;z-index:2100;width:56px;height:56px;border:0;border-radius:16px;color:#fff;background:linear-gradient(180deg,#ff9433,#ff7a1a);box-shadow:0 10px 20px rgba(255,122,26,.26)}.menu-toggle-btn.is-docked{left:202px;top:14px;width:46px;height:46px;border-radius:12px}.sidebar-backdrop{position:fixed;inset:0;z-index:1800;display:none;background:rgba(15,23,42,.44)}.sidebar-backdrop.show{display:block}.sidebar.sidebar-hidden{transform:translateX(-100%)}body.layout-sidebar-collapsed .audit-main{width:100%;margin-left:0;padding-left:92px}@media(max-width:991px){.audit-main,body.layout-sidebar-collapsed .audit-main{width:100%;margin-left:0;padding:98px 14px 40px}.audit-stats{grid-template-columns:1fr 1fr}.audit-filters{grid-template-columns:1fr}.sidebar{transform:translateX(-100%)}.sidebar:not(.sidebar-hidden){transform:translateX(0)}}@media(max-width:560px){.audit-stats{grid-template-columns:1fr}}
    .vp-resumo{display:flex;gap:10px;align-items:center;padding:14px 16px;border-radius:12px;margin-bottom:16px;font-size:14px}.vp-bom{background:#dcfce7;color:#14532d}.vp-mau{background:#fee2e2;color:#7f1d1d}.vp-aviso{background:#fef3c7;color:#78350f}.vp-lista{list-style:none;margin:0;padding:0 0 8px}.vp-lista li{display:flex;gap:12px;padding:10px 16px;border-top:1px solid #eef2f7}.vp-lista li>i{width:24px;height:24px;flex:0 0 24px;border-radius:50%;display:grid;place-items:center;color:#fff;font-size:12px}.vp-ok>i{background:#15803d}.vp-ko>i{background:#b91c1c}.vp-av>i{background:#b45309}.vp-lista small{display:block;color:#475569;margin-top:2px}
    </style>
    <link rel="stylesheet" href="css/super-admin-shell.css">
</head>
<body class="super-admin-ui">
<button type="button" class="menu-toggle-btn" id="btnMenuToggle" aria-label="Abrir menu"><i class="fas fa-bars"></i></button>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
<script src="js/super_admin_sidebar.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/super_admin_sidebar.js'); ?>" defer></script>
<?php
$superAdminActiveSection = 'reembolsos';
$superAdminSectionLinks = false;
$superAdminPrivacyPendingCount = $privacyPendingCount;
$superAdminOwnerName = $_SESSION['nome'] ?? 'Super Admin';
$superAdminBranchContext = session_contexto_filial_ativo();
include __DIR__ . '/includes/super_admin_sidebar.php';
?>
<main class="audit-main">
    <h1 class="h4 fw-bold mb-1"><i class="fas fa-rotate-left me-2"></i>Reembolsos</h1>
    <p class="text-muted mb-3">Registe aqui cada reembolso feito a um restaurante. Fica como prova (Termos de Uso). Os registos não se apagam: para corrigir, registe um valor negativo.</p>
    <?php if ($msg): ?><div class="alert alert-success" role="status"><?= $e($msg) ?></div><?php endif; ?>
    <?php if ($erro): ?><div class="alert alert-danger" role="alert"><?= $e($erro) ?></div><?php endif; ?>
    <section class="audit-panel p-3 mb-3">
        <h2 class="h6 fw-bold mb-3">Novo reembolso</h2>
        <form method="post" class="row g-2">
            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
            <div class="col-md-6"><label class="form-label" for="rRest">Restaurante</label>
                <select class="form-select" id="rRest" name="restaurante_id" required><option value="">Escolher…</option>
                <?php foreach ($restaurantes as $r): ?><option value="<?= (int)$r['id'] ?>">#<?= (int)$r['id'] ?> — <?= $e($r['nome']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label" for="rValor">Valor (MZN)</label><input class="form-control" id="rValor" name="valor" inputmode="decimal" required placeholder="1999,00"></div>
            <div class="col-md-3"><label class="form-label" for="rData">Data do reembolso</label><input class="form-control" type="date" id="rData" name="data_reembolso" required value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div>
            <div class="col-md-4"><label class="form-label" for="rMetodo">Método</label><select class="form-select" id="rMetodo" name="metodo" required><option value="">Escolher…</option><?php foreach ($metodos as $m): ?><option><?= $e($m) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label" for="rRef">Referência da transacção (opcional)</label><input class="form-control" id="rRef" name="referencia" maxlength="120"></div>
            <div class="col-md-4"><label class="form-label" for="rMotivo">Motivo</label><select class="form-select" id="rMotivo" name="motivo" required><option value="">Escolher…</option><?php foreach ($motivos as $m): ?><option><?= $e($m) ?></option><?php endforeach; ?></select></div>
            <div class="col-12"><label class="form-label" for="rDet">Detalhe (opcional)</label><input class="form-control" id="rDet" name="detalhe" maxlength="300"></div>
            <div class="col-12"><button class="btn btn-primary" type="submit"><i class="fas fa-check me-1"></i>Registar reembolso</button></div>
        </form>
    </section>
    <section class="audit-panel">
        <div class="d-flex justify-content-between p-3"><h2 class="h6 fw-bold m-0">Histórico</h2><span class="text-muted">Total listado: <strong><?= number_format($total, 2, ',', '.') ?> MZN</strong></span></div>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th class="ps-3">Data</th><th>Restaurante</th><th class="text-end">Valor (MZN)</th><th>Método</th><th>Motivo</th><th>Registado por</th></tr></thead>
            <tbody>
            <?php if (!$lista): ?><tr><td colspan="6" class="text-center text-muted py-4">Ainda não há reembolsos registados.</td></tr><?php endif; ?>
            <?php foreach ($lista as $r): ?><tr>
                <td class="ps-3 text-nowrap"><?= $e(date('d/m/Y', strtotime($r['data_reembolso']))) ?></td>
                <td>#<?= (int)$r['restaurante_id'] ?> <?= $e($r['restaurante_nome']) ?></td>
                <td class="text-end fw-semibold"><?= number_format((float)$r['valor'], 2, ',', '.') ?></td>
                <td><?= $e($r['metodo']) ?><?= $r['referencia'] ? '<div class="small text-muted">' . $e($r['referencia']) . '</div>' : '' ?></td>
                <td><?= $e($r['motivo']) ?></td>
                <td><?= $e($r['autor'] ?? ('#' . $r['registado_por'])) ?><div class="small text-muted"><?= $e(date('d/m/Y H:i', strtotime($r['criado_em']))) ?></div></td>
            </tr><?php endforeach; ?>
            </tbody></table></div>
    </section>
</main>
<script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
</script>
</body>
</html>
