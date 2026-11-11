<?php
require_once __DIR__ . '/app_base.php';
require_once APP_BASE . '/src/config/super_admin_check.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/audit.php';
require_once APP_BASE . '/src/config/privacy_requests.php';
require_once APP_BASE . '/src/config/restaurante_context.php';
require_once APP_BASE . '/src/config/input_validation.php';

requireSuperAdminPermission('manage_security');

$db = (new Database())->getConnection();
audit_ensure_table($db);

$allowedSeverities = ['DEBUG', 'INFO', 'WARN', 'ERROR', 'CRITICAL'];
$severityRaw = strtoupper((string)(input_scalar_string($_GET['severity'] ?? '') ?? ''));
$severityFilter = input_enum($severityRaw, array_merge([''], $allowedSeverities), true);
$eventFilter = input_search_token($_GET['event'] ?? '', 80);
$period = input_enum($_GET['period'] ?? '7d', ['24h', '7d', '30d']);
$page = input_bounded_int($_GET['page'] ?? 1, 1, 100000);
if ($severityFilter === null || $eventFilter === null || $period === null || $page === null) {
    header('Location: admin_auditoria.php');
    exit;
}
$periodDays = ['24h' => 1, '7d' => 7, '30d' => 30][$period] ?? 7;
$limit = 50;
$offset = ($page - 1) * $limit;

$where = ['created_at >= DATE_SUB(NOW(), INTERVAL ' . $periodDays . ' DAY)'];
$params = [];
if (in_array($severityFilter, $allowedSeverities, true)) {
    $where[] = 'severity = :severity';
    $params[':severity'] = $severityFilter;
}
if ($eventFilter !== '') {
    $where[] = 'event LIKE :event';
    $params[':event'] = '%' . $eventFilter . '%';
}
$whereSql = implode(' AND ', $where);

$countStmt = $db->prepare('SELECT COUNT(*) FROM audit_logs WHERE ' . $whereSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

$stmt = $db->prepare('SELECT id, event, severity, usuario_id, restaurante_id, ip, method, uri, context_json, created_at FROM audit_logs WHERE ' . $whereSql . ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset);
$stmt->execute($params);
$auditRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$stats = $db->query("
    SELECT
        COUNT(*) AS total_24h,
        SUM(CASE WHEN severity = 'CRITICAL' THEN 1 ELSE 0 END) AS critical_24h,
        SUM(CASE WHEN severity IN ('WARN','ERROR') THEN 1 ELSE 0 END) AS warnings_24h,
        COUNT(DISTINCT usuario_id) AS actors_24h
    FROM audit_logs
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
")->fetch(PDO::FETCH_ASSOC) ?: [];

$privacyPendingCount = 0;
if (super_admin_has_permission('manage_privacy') && privacy_request_table_exists($db, 'data_subject_requests')) {
    $privacyPendingCount = (int)$db->query("SELECT COUNT(*) FROM data_subject_requests WHERE status IN ('PENDENTE_VERIFICACAO','EM_VERIFICACAO','EM_PROCESSAMENTO')")->fetchColumn();
}

function audit_admin_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function audit_admin_mask_ip(string $ip): string
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $parts = explode('.', $ip);
        $parts[3] = '***';
        return implode('.', $parts);
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return substr($ip, 0, 12) . ':****';
    }
    return $ip !== '' ? '***' : '-';
}

function audit_admin_context_summary(?string $json): string
{
    $decoded = json_decode((string)$json, true);
    if (!is_array($decoded)) {
        return '-';
    }

    $blockedPattern = '/senha|password|token|secret|email|telefone|nuit|payload|cookie|authorization/i';
    $safe = [];
    foreach ($decoded as $key => $value) {
        if (preg_match($blockedPattern, (string)$key)) {
            continue;
        }
        if (is_scalar($value) || $value === null) {
            $safe[(string)$key] = $value;
        }
        if (count($safe) >= 8) {
            break;
        }
    }

    $summary = json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $summary !== false && $summary !== '[]' ? substr($summary, 0, 500) : '-';
}

function audit_admin_severity_class(string $severity): string
{
    return match ($severity) {
        'CRITICAL', 'ERROR' => 'danger',
        'WARN' => 'warning',
        'INFO' => 'primary',
        default => 'secondary',
    };
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auditoria - Nivora Restaurant Admin</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/adminpro-theme.css">
    <style>
        *{box-sizing:border-box}.sidebar{position:fixed;top:0;left:0;z-index:1000;transition:transform .25s ease}body{margin:0;min-height:100vh;overflow-x:hidden;background:linear-gradient(180deg,#f5f7ff 0%,#eef2fb 100%);color:#0f172a;font-family:'Poppins',sans-serif;font-size:13px}.audit-main{width:calc(100% - 260px);margin-left:260px;padding:18px;transition:.25s}.audit-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}.audit-stat,.audit-panel{background:#fff;border:1px solid #dbe3ef;border-radius:12px;box-shadow:0 8px 22px rgba(15,23,42,.06)}.audit-stat{padding:16px}.audit-stat strong{display:block;font-size:25px}.audit-stat span{color:#64748b;font-size:11px;font-weight:600}.audit-filters{display:grid;grid-template-columns:minmax(220px,1fr) 180px 150px auto;gap:10px;padding:14px}.audit-context{max-width:360px;overflow-wrap:anywhere;color:#475569;font-family:Consolas,monospace;font-size:11px}.audit-uri{max-width:240px;overflow-wrap:anywhere}.menu-toggle-btn{position:fixed;left:16px;top:16px;z-index:2100;width:56px;height:56px;border:0;border-radius:16px;color:#fff;background:linear-gradient(180deg,#ff9433,#ff7a1a);box-shadow:0 10px 20px rgba(255,122,26,.26)}.menu-toggle-btn.is-docked{left:202px;top:14px;width:46px;height:46px;border-radius:12px}.sidebar-backdrop{position:fixed;inset:0;z-index:1800;display:none;background:rgba(15,23,42,.44)}.sidebar-backdrop.show{display:block}.sidebar.sidebar-hidden{transform:translateX(-100%)}body.layout-sidebar-collapsed .audit-main{width:100%;margin-left:0;padding-left:92px}@media(max-width:991px){.audit-main,body.layout-sidebar-collapsed .audit-main{width:100%;margin-left:0;padding:98px 14px 40px}.audit-stats{grid-template-columns:1fr 1fr}.audit-filters{grid-template-columns:1fr}.sidebar{transform:translateX(-100%)}}@media(max-width:560px){.audit-stats{grid-template-columns:1fr}}
    </style>
    <link rel="stylesheet" href="css/super-admin-shell.css">
</head>
<body class="super-admin-ui">
<button type="button" class="menu-toggle-btn" id="btnMenuToggle" aria-label="Abrir menu"><i class="fas fa-bars"></i></button>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
<?php
$superAdminActiveSection = 'auditoria';
$superAdminSectionLinks = false;
$superAdminPrivacyPendingCount = $privacyPendingCount;
$superAdminOwnerName = $_SESSION['nome'] ?? 'Super Admin';
$superAdminBranchContext = session_contexto_filial_ativo();
include __DIR__ . '/includes/super_admin_sidebar.php';
?>
<main class="audit-main">
    <header class="sa-command-header">
        <div class="sa-command-copy"><p class="sa-command-kicker">Governa&ccedil;&atilde;o</p><h1>Central de Auditoria</h1><p>A&ccedil;&otilde;es administrativas e eventos cr&iacute;ticos com contexto seguro.</p></div>
        <span class="sa-command-role"><i class="fas fa-lock"></i> Acesso restrito</span>
    </header>

    <section class="audit-stats" aria-label="Resumo das ultimas 24 horas">
        <div class="audit-stat"><strong><?= (int)($stats['total_24h'] ?? 0) ?></strong><span>Eventos nas &uacute;ltimas 24h</span></div>
        <div class="audit-stat"><strong class="text-danger"><?= (int)($stats['critical_24h'] ?? 0) ?></strong><span>Cr&iacute;ticos</span></div>
        <div class="audit-stat"><strong class="text-warning"><?= (int)($stats['warnings_24h'] ?? 0) ?></strong><span>Alertas e erros</span></div>
        <div class="audit-stat"><strong class="text-primary"><?= (int)($stats['actors_24h'] ?? 0) ?></strong><span>Atores identificados</span></div>
    </section>

    <section class="audit-panel" aria-labelledby="audit-events-title">
        <div class="sa-panel-heading">
            <div><span class="sa-section-eyebrow">Rastreabilidade</span><h2 id="audit-events-title">Registo de eventos</h2><p>Consulte a origem, o ator e o contexto seguro das a&ccedil;&otilde;es administrativas.</p></div>
            <span class="sa-section-meta"><?= $totalRows ?> evento(s) no per&iacute;odo</span>
        </div>
        <form class="audit-filters" method="get">
            <input class="form-control" type="search" name="event" value="<?= audit_admin_e($eventFilter) ?>" placeholder="Pesquisar evento">
            <select class="form-select" name="severity"><option value="">Todas as severidades</option><?php foreach ($allowedSeverities as $severity): ?><option value="<?= $severity ?>" <?= $severityFilter === $severity ? 'selected' : '' ?>><?= $severity ?></option><?php endforeach; ?></select>
            <select class="form-select" name="period"><option value="24h" <?= $period === '24h' ? 'selected' : '' ?>>&Uacute;ltimas 24h</option><option value="7d" <?= $period === '7d' ? 'selected' : '' ?>>&Uacute;ltimos 7 dias</option><option value="30d" <?= $period === '30d' ? 'selected' : '' ?>>&Uacute;ltimos 30 dias</option></select>
            <button class="btn btn-dark" type="submit"><i class="fas fa-filter me-2"></i>Filtrar</button>
        </form>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th class="ps-3">Data</th><th>Evento</th><th>Severidade</th><th>Ator</th><th>Restaurante</th><th>Origem</th><th>Contexto seguro</th></tr></thead>
                <tbody>
                    <?php if ($auditRows === []): ?><tr><td colspan="7" class="text-center text-muted py-5">Nenhum evento encontrado.</td></tr><?php endif; ?>
                    <?php foreach ($auditRows as $row): ?><tr>
                        <td class="ps-3 text-nowrap"><?= audit_admin_e($row['created_at']) ?></td>
                        <td><strong><?= audit_admin_e($row['event']) ?></strong><div class="audit-uri text-muted"><?= audit_admin_e(($row['method'] ?: '-') . ' ' . ($row['uri'] ?: '-')) ?></div></td>
                        <td><span class="badge text-bg-<?= audit_admin_severity_class((string)$row['severity']) ?>"><?= audit_admin_e($row['severity']) ?></span></td>
                        <td>#<?= (int)($row['usuario_id'] ?? 0) ?></td><td>#<?= (int)($row['restaurante_id'] ?? 0) ?></td>
                        <td><?= audit_admin_e(audit_admin_mask_ip((string)($row['ip'] ?? ''))) ?></td>
                        <td><div class="audit-context"><?= audit_admin_e(audit_admin_context_summary($row['context_json'] ?? null)) ?></div></td>
                    </tr><?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="d-flex justify-content-between align-items-center gap-3 p-3 border-top">
            <span class="text-muted"><?= $totalRows ?> evento(s) · p&aacute;gina <?= $page ?> de <?= $totalPages ?></span>
            <div class="d-flex gap-2"><?php if ($page > 1): ?><a class="btn btn-sm btn-outline-secondary" href="?<?= http_build_query(['event'=>$eventFilter,'severity'=>$severityFilter,'period'=>$period,'page'=>$page-1]) ?>">Anterior</a><?php endif; ?><?php if ($page < $totalPages): ?><a class="btn btn-sm btn-outline-secondary" href="?<?= http_build_query(['event'=>$eventFilter,'severity'=>$severityFilter,'period'=>$period,'page'=>$page+1]) ?>">Pr&oacute;xima</a><?php endif; ?></div>
        </div>
    </section>
</main>
<script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
const auditMobile=()=>window.matchMedia('(max-width:991px)').matches;
function auditSetSidebar(open){const sidebar=document.querySelector('.sidebar');const backdrop=document.getElementById('sidebarBackdrop');const button=document.getElementById('btnMenuToggle');const icon=button?.querySelector('i');if(!sidebar)return;sidebar.classList.toggle('sidebar-hidden',!open);if(auditMobile()){document.body.classList.remove('layout-sidebar-collapsed');backdrop?.classList.toggle('show',open);button?.classList.remove('is-docked')}else{document.body.classList.toggle('layout-sidebar-collapsed',!open);backdrop?.classList.remove('show');button?.classList.toggle('is-docked',open)}icon?.classList.toggle('fa-bars',!open);icon?.classList.toggle('fa-times',open)}
document.addEventListener('DOMContentLoaded',()=>{const sidebar=document.querySelector('.sidebar');auditSetSidebar(!auditMobile());document.getElementById('btnMenuToggle')?.addEventListener('click',()=>auditSetSidebar(sidebar?.classList.contains('sidebar-hidden')??true));document.getElementById('sidebarBackdrop')?.addEventListener('click',()=>auditSetSidebar(false));window.addEventListener('resize',()=>auditSetSidebar(!auditMobile()))});
</script>
</body>
</html>
