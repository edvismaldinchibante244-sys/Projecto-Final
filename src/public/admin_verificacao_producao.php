<?php
require_once __DIR__ . '/app_base.php';
require_once APP_BASE . '/src/config/super_admin_check.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/restaurante_context.php';

requireSuperAdminPermission('manage_security');
$db = (new Database())->getConnection();
$privacyPendingCount = 0;

function vp_env(string $k): string { $v = getenv($k); if ($v === false) { $v = $_ENV[$k] ?? $_SERVER[$k] ?? ''; } return trim((string)$v); }
function vp_e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function vp_segredo_ok(string $v): bool {
    if (strlen($v) < 32) return false;
    return !preg_match('/^(x+|change|changeme|secret|segredo|base64:?|test|teste|exemplo|example|default)/i', $v);
}

$checks = [];
$add = static function (string $grupo, string $nome, bool $ok, string $como, bool $aviso = false) use (&$checks) {
    $checks[] = compact('grupo', 'nome', 'ok', 'como', 'aviso');
};

// Ambiente
$appEnv = strtolower(vp_env('APP_ENV'));
$add('Ambiente', 'APP_ENV = production (agora: ' . ($appEnv ?: 'vazio') . ')', $appEnv === 'production', 'No .env do servidor: APP_ENV=production');
$errosVisiveis = filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN);
if (!$errosVisiveis) {
    $add('Ambiente', 'Erros técnicos escondidos dos visitantes', true, '');
} elseif ($appEnv !== 'production') {
    // Em local (XAMPP) e normal ver os erros; com APP_ENV=production o sistema desliga-os sozinho (security.php).
    $add('Ambiente', 'Erros técnicos visíveis (normal neste computador de desenvolvimento)', false, 'Nada a fazer aqui: com APP_ENV=production o sistema esconde os erros automaticamente.', true);
} else {
    $add('Ambiente', 'Erros técnicos escondidos dos visitantes', false, 'No painel PHP do alojamento ponha display_errors = Off (o sistema tenta desligar, mas o alojamento está a forçar).');
}
$add('Ambiente', 'Versão do PHP ≥ 8.1 (agora: ' . PHP_VERSION . ')', version_compare(PHP_VERSION, '8.1.0', '>='), 'Escolha PHP 8.2 ou superior no painel do alojamento');

// Desempenho (muitos restaurantes ao mesmo tempo)
$opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
$add('Desempenho', 'OPcache ligado (PHP 2 a 3 vezes mais rápido)', is_array($opcache) && !empty($opcache['opcache_enabled']),
    'No painel PHP do alojamento ative "opcache" (opcache.enable=1). Em VPS: opcache.enable=1, opcache.memory_consumption=128, opcache.validate_timestamps=1, opcache.revalidate_freq=60');
$add('Desempenho', 'APCu disponível para a cache curta', function_exists('apcu_enabled') && apcu_enabled(),
    'Opcional: ative a extensão APCu. Sem ela a cache curta usa ficheiros temporários (funciona, um pouco mais lento).', true);
$triggersOk = false;
try {
    $triggersOk = (int)$db->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'trg_nv_ov_%'")->fetchColumn() >= 9;
} catch (Throwable $e) {}
$add('Desempenho', 'Atualização leve dos ecrãs (triggers da versão de operação)', $triggersOk,
    'Aplique as migrações (tools/db_migrate.php). Se o alojamento não permitir triggers, o sistema funciona com um cálculo de recurso, um pouco mais pesado.', true);
$resumoOk = false;
try {
    $resumoOk = (int)$db->query('SELECT COUNT(*) FROM vendas_resumo_estado WHERE dia >= CURDATE() - INTERVAL 3 DAY')->fetchColumn() > 0;
} catch (Throwable $e) {}
$add('Desempenho', 'Rotina diária de manutenção a correr (resumo de vendas e limpeza)', $resumoOk,
    'Agende uma vez por dia: php src/public/cron/manutencao.php (no Windows: scripts/cron/run_manutencao_cron.bat; na Hostinger: Tarefas Cron).', true);

// Segredos (nunca mostramos o valor, só o tamanho)
foreach (['APP_KEY' => true, 'REALTIME_SECRET' => false] as $k => $obrig) {
    $v = vp_env($k);
    if ($v === '' && !$obrig) { $add('Segredos', $k . ' (não configurado — tempo real desligado)', true, '', true); continue; }
    $add('Segredos', $k . ' longo e único (' . strlen($v) . ' caracteres)', vp_segredo_ok($v), 'Gere um valor novo com: php -r "echo bin2hex(random_bytes(32));" e cole no .env');
}

// Ligação
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https' || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
$add('Ligação', 'Página aberta por HTTPS (cadeado)', $https, 'Ative o certificado SSL grátis no painel e force HTTPS');
$add('Ligação', 'Cookies de sessão só por HTTPS', $https ? filter_var(ini_get('session.cookie_secure'), FILTER_VALIDATE_BOOLEAN) || (session_get_cookie_params()['secure'] ?? false) : false, 'Fica certo automaticamente com APP_ENV=production e HTTPS');
$cspInline = vp_env('CSP_SCRIPT_UNSAFE_INLINE');
$add('Ligação', 'Proteção de scripts (CSP) ativa', !in_array(strtolower($cspInline), ['1', 'true', 'yes', 'on'], true), 'Retire CSP_SCRIPT_UNSAFE_INLINE do .env (só serve para emergências)');

// Base de dados
$dbUser = '';
try { $dbUser = (string)$db->query('SELECT CURRENT_USER()')->fetchColumn(); } catch (Throwable $e) {}
$dbNome = strtolower((string)strtok($dbUser, '@'));
$add('Base de dados', 'Utilizador da BD não é root (' . vp_e($dbNome ?: '?') . ')', $dbNome !== '' && $dbNome !== 'root', 'Crie um utilizador próprio no painel MySQL e use-o no .env');
$add('Base de dados', 'Senha da BD definida', vp_env('DB_PASS') !== '' || vp_env('DB_PASSWORD') !== '', 'Defina DB_PASS no .env com uma senha forte');

// Contas
$sem2fa = null;
try {
    $sem2fa = (int)$db->query("SELECT COUNT(*) FROM usuarios WHERE super_admin = 1 AND COALESCE(two_factor_enabled,0) = 0 AND COALESCE(ativo,1) = 1")->fetchColumn();
} catch (Throwable $e) {
    try { $sem2fa = (int)$db->query("SELECT COUNT(*) FROM usuarios WHERE super_admin = 1 AND COALESCE(two_factor_enabled,0) = 0")->fetchColumn(); } catch (Throwable $e2) {}
}
$add('Contas', 'Todos os Super Admin com verificação em 2 passos' . ($sem2fa ? ' (' . $sem2fa . ' sem)' : ''), $sem2fa === 0, 'Ative a verificação por email no perfil de cada Super Admin');
$testes = null;
try { $testes = (int)$db->query("SELECT COUNT(*) FROM restaurantes WHERE nome LIKE '%E2E%' OR nome LIKE '%teste%' OR nome LIKE '%test %'")->fetchColumn(); } catch (Throwable $e) {}
$add('Contas', 'Sem restaurantes de teste' . ($testes ? ' (' . $testes . ' encontrados)' : ''), $testes === 0, 'Liste e apague com: php tools/limpar_testes.php', true);

// Ficheiros perigosos
$add('Ficheiros', 'Sem ficheiro de login de testes (__e2e_login.php)', !is_file(__DIR__ . '/__e2e_login.php'), 'Apague src/public/__e2e_login.php do servidor');
$debug = glob(__DIR__ . '/{debug_*,phpinfo*,info.php,test*.php}', GLOB_BRACE) ?: [];
$add('Ficheiros', 'Sem páginas de diagnóstico públicas' . ($debug ? ' (' . count($debug) . ')' : ''), $debug === [], 'Apague: ' . implode(', ', array_map('basename', $debug)));
$sqlPub = glob(__DIR__ . '/*.{sql,zip,bak,env,log}', GLOB_BRACE) ?: [];
$add('Ficheiros', 'Sem cópias .sql/.zip/.bak/.env na pasta pública', $sqlPub === [], 'Mova para fora da pasta pública: ' . implode(', ', array_map('basename', $sqlPub)));
$docRootOk = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')) === realpath(__DIR__);
$add('Ficheiros', 'Pasta pública do site = src/public', $docRootOk, 'No painel do alojamento, aponte o domínio para a pasta src/public (o .env fica assim inacessível)', !$docRootOk);
$envPublico = is_file(__DIR__ . '/.env');
$add('Ficheiros', 'Ficheiro .env fora da pasta pública', !$envPublico, 'Mova o .env para a raiz do projeto (acima de src/public)');

$falhas = count(array_filter($checks, fn($c) => !$c['ok'] && !$c['aviso']));
$avisos = count(array_filter($checks, fn($c) => !$c['ok'] && $c['aviso']));
$grupos = [];
foreach ($checks as $c) { $grupos[$c['grupo']][] = $c; }
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificação de produção - Nivora Restaurant Admin</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/adminpro-theme.css?v=<?php echo (int)@filemtime(__DIR__ . '/css/adminpro-theme.css'); ?>">
    <style>
        *{box-sizing:border-box}.sidebar{position:fixed;top:0;left:0;z-index:1000;transition:transform .25s ease}body{margin:0;min-height:100vh;overflow-x:hidden;background:linear-gradient(180deg,#f5f7ff 0%,#eef2fb 100%);color:#0f172a;font-family:'Poppins',sans-serif;font-size:13px}.audit-main{width:calc(100% - 260px);margin-left:260px;padding:18px;transition:.25s}.audit-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}.audit-stat,.audit-panel{background:#fff;border:1px solid #dbe3ef;border-radius:12px;box-shadow:0 8px 22px rgba(15,23,42,.06)}.audit-stat{padding:16px}.audit-stat strong{display:block;font-size:25px}.audit-stat span{color:#64748b;font-size:11px;font-weight:600}.audit-filters{display:grid;grid-template-columns:minmax(220px,1fr) 180px 150px auto;gap:10px;padding:14px}.audit-context{max-width:360px;overflow-wrap:anywhere;color:#475569;font-family:Consolas,monospace;font-size:11px}.audit-uri{max-width:240px;overflow-wrap:anywhere}.menu-toggle-btn{position:fixed;left:16px;top:16px;z-index:2100;width:56px;height:56px;border:0;border-radius:16px;color:#fff;background:linear-gradient(180deg,#ff9433,#ff7a1a);box-shadow:0 10px 20px rgba(255,122,26,.26)}.menu-toggle-btn.is-docked{left:202px;top:14px;width:46px;height:46px;border-radius:12px}.sidebar-backdrop{position:fixed;inset:0;z-index:1800;display:none;background:rgba(15,23,42,.44)}.sidebar-backdrop.show{display:block}.sidebar.sidebar-hidden{transform:translateX(-100%)}body.layout-sidebar-collapsed .audit-main{width:100%;margin-left:0;padding-left:92px}@media(max-width:991px){.audit-main,body.layout-sidebar-collapsed .audit-main{width:100%;margin-left:0;padding:98px 14px 40px}.audit-stats{grid-template-columns:1fr 1fr}.audit-filters{grid-template-columns:1fr}.sidebar{transform:translateX(-100%)}.sidebar:not(.sidebar-hidden){transform:translateX(0)}}@media(max-width:560px){.audit-stats{grid-template-columns:1fr}}
    .vp-resumo{display:flex;gap:10px;align-items:center;padding:14px 16px;border-radius:12px;margin-bottom:16px;font-size:14px}.vp-bom{background:#dcfce7;color:#14532d}.vp-mau{background:#fee2e2;color:#7f1d1d}.vp-aviso{background:#fef3c7;color:#78350f}.vp-lista{list-style:none;margin:0;padding:0 0 8px}.vp-lista li{display:flex;gap:12px;padding:10px 16px;border-top:1px solid #eef2f7}.vp-lista li>i{width:24px;height:24px;flex:0 0 24px;border-radius:50%;display:grid;place-items:center;color:#fff;font-size:12px}.vp-ok>i{background:#15803d}.vp-ko>i{background:#b91c1c}.vp-av>i{background:#b45309}.vp-lista small{display:block;color:#475569;margin-top:2px}
    </style>
    <link rel="stylesheet" href="css/super-admin-shell.css?v=<?php echo (int)@filemtime(__DIR__ . '/css/super-admin-shell.css'); ?>">
</head>
<body class="super-admin-ui">
<button type="button" class="menu-toggle-btn" id="btnMenuToggle" aria-label="Abrir menu"><i class="fas fa-bars"></i></button>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
<script src="js/super_admin_sidebar.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/super_admin_sidebar.js'); ?>" defer></script>
<?php
$superAdminActiveSection = 'producao';
$superAdminSectionLinks = false;
$superAdminPrivacyPendingCount = $privacyPendingCount;
$superAdminOwnerName = $_SESSION['nome'] ?? 'Super Admin';
$superAdminBranchContext = session_contexto_filial_ativo();
include __DIR__ . '/includes/super_admin_sidebar.php';
?>
<main class="audit-main">
    <h1 class="h4 fw-bold mb-1"><i class="fas fa-clipboard-check me-2"></i>Verifica&ccedil;&atilde;o de produ&ccedil;&atilde;o</h1>
    <p class="text-muted mb-3">Abra esta p&aacute;gina no servidor real antes de abrir ao p&uacute;blico. Tudo deve ficar verde. Nenhum segredo &eacute; mostrado aqui.</p>
    <div class="vp-resumo <?= $falhas ? 'vp-mau' : ($avisos ? 'vp-aviso' : 'vp-bom') ?>" role="status">
        <i class="fas <?= $falhas ? 'fa-circle-xmark' : ($avisos ? 'fa-triangle-exclamation' : 'fa-circle-check') ?>"></i>
        <?php if ($falhas): ?><strong><?= $falhas ?> ponto(s) a corrigir</strong> antes de publicar.<?php elseif ($avisos): ?><strong>Pronto</strong>, com <?= $avisos ?> aviso(s) a rever.<?php else: ?><strong>Tudo verde.</strong> Pode publicar.<?php endif; ?>
    </div>
    <?php foreach ($grupos as $g => $lista): ?>
    <section class="audit-panel mb-3">
        <h2 class="h6 fw-bold px-3 pt-3 mb-2"><?= vp_e($g) ?></h2>
        <ul class="vp-lista">
        <?php foreach ($lista as $c): $cls = $c['ok'] ? 'ok' : ($c['aviso'] ? 'av' : 'ko'); ?>
            <li class="vp-<?= $cls ?>">
                <i class="fas <?= $c['ok'] ? 'fa-check' : ($c['aviso'] ? 'fa-exclamation' : 'fa-xmark') ?>" aria-hidden="true"></i>
                <div><span class="visually-hidden"><?= $c['ok'] ? 'OK:' : ($c['aviso'] ? 'Aviso:' : 'Falha:') ?></span>&nbsp;<?= vp_e($c['nome']) ?>
                <?php if (!$c['ok'] && $c['como'] !== ''): ?><small>Como corrigir: <?= vp_e($c['como']) ?></small><?php endif; ?></div>
            </li>
        <?php endforeach; ?>
        </ul>
    </section>
    <?php endforeach; ?>
    <p class="text-muted small">Verifique tamb&eacute;m de fora (no telem&oacute;vel, com dados m&oacute;veis): <code>https://SEU-DOMINIO/.env</code> e <code>https://SEU-DOMINIO/db/schema_base.sql</code> devem dar erro 403 ou 404.</p>
</main>
<script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
</script>
</body>
</html>
