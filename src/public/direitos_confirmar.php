<?php
require_once __DIR__ . '/app_base.php';
/*
 * O titular confirma que foi ele que fez o pedido de dados (link enviado para o email da conta).
 * Abrir o link nao confirma nada: e preciso carregar no botao (os filtros de email abrem links sozinhos).
 */
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/security.php';
require_once APP_BASE . '/src/config/csrf.php';
require_once APP_BASE . '/src/config/legal_documents.php';
require_once APP_BASE . '/src/config/privacy_requests.php';
require_once APP_BASE . '/src/config/audit.php';

security_start_session();
security_set_headers();
header('X-Robots-Tag: noindex');
header('Referrer-Policy: no-referrer');

$ref = (string)($_GET['r'] ?? $_POST['r'] ?? '');
$tok = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$db = (new Database())->getConnection();
privacy_request_ensure_schema($db);
$req = privacy_request_find_by_token($db, $ref, $tok, 'verify');
$estado = $req ? 'pergunta' : 'invalido';
$tipos = ['ACESSO' => 'Ver os meus dados', 'CORRECAO' => 'Corrigir dados', 'ELIMINACAO' => 'Apagar os meus dados', 'LIMITACAO' => 'Suspender o uso dos dados', 'OPOSICAO' => 'Opor-se a um uso', 'PORTABILIDADE' => 'Levar os dados para outro serviço'];

if ($req && $_SERVER['REQUEST_METHOD'] === 'POST' && csrf_is_valid((string)($_POST['_csrf'] ?? ''))) {
    $resposta = (string)($_POST['resposta'] ?? '');
    if ($resposta === 'sim') {
        $db->prepare("UPDATE data_subject_requests SET identity_verified_at = NOW(), verified_via = 'EMAIL_LINK', verify_token_hash = NULL,
            status = CASE WHEN status = 'PENDENTE_VERIFICACAO' THEN 'EM_VERIFICACAO' ELSE status END, updated_at = NOW() WHERE id = ?")->execute([(int)$req['id']]);
        privacy_request_record_event($db, (int)$req['id'], null, 'IDENTITY_VERIFIED', 'Confirmado pelo titular através do link enviado para o seu email.');
        audit_log($db, 'data_subject_identity_confirmed_by_link', ['request_id' => (int)$req['id']]);
        $estado = 'confirmado';
    } elseif ($resposta === 'nao') {
        $db->prepare("UPDATE data_subject_requests SET verified_via = 'NEGADO', verify_token_hash = NULL, updated_at = NOW() WHERE id = ?")->execute([(int)$req['id']]);
        privacy_request_record_event($db, (int)$req['id'], null, 'IDENTITY_DENIED', 'O dono do email disse que NÃO fez este pedido.');
        audit_log($db, 'data_subject_identity_denied_by_link', ['request_id' => (int)$req['id']], 'WARN');
        $estado = 'negado';
    }
}
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Confirmar pedido de dados - Nivora Restaurant</title>
    <style>
        body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#f6f3ee;color:#1f2937;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:20px}
        .caixa{background:#fff;max-width:480px;width:100%;border-radius:16px;padding:28px;box-shadow:0 12px 30px rgba(0,0,0,.08)}
        h1{font-size:22px;margin:0 0 10px}p{line-height:1.55;margin:0 0 12px}
        .dados{background:#faf7f2;border-radius:10px;padding:12px 14px;margin:14px 0;font-size:14px}
        .botoes{display:grid;gap:10px;margin-top:18px}
        button{font-size:16px;padding:13px;border-radius:10px;border:0;cursor:pointer;font-weight:700}
        .sim{background:#f59e0b;color:#111}.nao{background:#fff;border:1px solid #d1d5db;color:#374151}
        .ok{color:#15803d}.mau{color:#b91c1c}
    </style>
</head>
<body>
<main class="caixa">
<?php if ($estado === 'pergunta'): ?>
    <h1>Foi você que fez este pedido?</h1>
    <p>Recebemos um pedido sobre os dados pessoais associados a este email. Para o proteger, só o tratamos depois de confirmar que foi mesmo você.</p>
    <div class="dados">
        <strong><?= $e($tipos[$req['request_type']] ?? $req['request_type']) ?></strong><br>
        Enviado a <?= $e(date('d/m/Y \à\s H:i', strtotime((string)$req['created_at']))) ?><br>
        Referência: <?= $e(substr((string)$req['public_id'], 0, 8)) ?>
    </div>
    <form method="post" class="botoes">
        <input type="hidden" name="_csrf" value="<?= $e(csrf_get_token()) ?>">
        <input type="hidden" name="r" value="<?= $e($ref) ?>">
        <input type="hidden" name="t" value="<?= $e($tok) ?>">
        <button class="sim" name="resposta" value="sim">Sim, fui eu</button>
        <button class="nao" name="resposta" value="nao">Não fui eu</button>
    </form>
<?php elseif ($estado === 'confirmado'): ?>
    <h1 class="ok">Obrigado, confirmado ✓</h1>
    <p>Vamos tratar do seu pedido e responder para este email em até 30 dias.</p>
<?php elseif ($estado === 'negado'): ?>
    <h1 class="mau">Obrigado por nos avisar</h1>
    <p>Não vamos fazer nada com os seus dados por causa deste pedido. Se suspeita que alguém está a usar o seu email, mude a palavra-passe da sua conta.</p>
<?php else: ?>
    <h1>Este link já não é válido</h1>
    <p>Pode ter expirado (vale 3 dias) ou já ter sido usado. Se ainda precisa de confirmar o pedido, responda ao email que recebeu da Nivora Restaurant.</p>
<?php endif; ?>
</main>
</body>
</html>
