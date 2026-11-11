<?php
require_once __DIR__ . '/app_base.php';
require_once APP_BASE . '/src/config/super_admin_check.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/csrf.php';
require_once APP_BASE . '/src/config/privacy_requests.php';
require_once APP_BASE . '/src/config/audit.php';
require_once APP_BASE . '/src/config/restaurante_context.php';

requireSuperAdminPermission('manage_privacy');

$db = (new Database())->getConnection();
privacy_request_ensure_schema($db);
$csrfToken = csrf_get_token();
$canManageRestaurants = super_admin_has_permission('manage_restaurants');
$canManageUsers = super_admin_has_permission('manage_users');
$canAccessPlans = super_admin_has_any_permission(['view_finance', 'approve_plans']);
$canManageSupport = super_admin_has_permission('manage_support');
$canManageSecurity = super_admin_has_permission('manage_security');
$canViewDashboard = super_admin_has_permission('view_dashboard');
$allowedStatuses = [
    'PENDENTE_VERIFICACAO' => 'Por verificar identidade',
    'EM_VERIFICACAO' => 'Em verificação',
    'EM_PROCESSAMENTO' => 'Em tratamento',
    'CONCLUIDO' => 'Concluído',
    'RECUSADO' => 'Recusado',
];
$typeLabels = [
    // Os mesmos nomes que a pessoa viu no formulario publico (direitos_dados.php).
    'ACESSO' => 'Ver os meus dados',
    'CORRECAO' => 'Corrigir dados',
    'ELIMINACAO' => 'Apagar os meus dados',
    'LIMITACAO' => 'Suspender o uso dos dados',
    'OPOSICAO' => 'Opor-se a um uso',
    'PORTABILIDADE' => 'Levar os dados para outro serviço',
];
// O que o proprietario deve fazer em cada tipo de pedido (passo 3, depois de confirmar a identidade).
$typeGuides = [
    'ACESSO' => ['Carregue em «Ver os dados (versão legível)» para confirmar o que vai ser entregue.', 'Carregue em «Enviar os dados ao titular»: ele recebe um link seguro (7 dias) para ver, imprimir ou guardar em PDF.'],
    'CORRECAO' => ['Use «Corrigir utilizador» ou «Corrigir restaurante» para alterar o que a pessoa pediu.', 'Se o dado pertence a um restaurante cliente (por exemplo, o telefone de um cliente dele), peça ao restaurante que o corrija e registe isso na nota.'],
    'ELIMINACAO' => ['Apague ou desative os dados pessoais pedidos (conta, contacto, cliente).', 'Não apague faturas, vendas e registos que a lei obriga a guardar: diga ao titular quais ficaram e porquê.', 'Escreva na nota exatamente o que foi apagado e o que ficou.'],
    'LIMITACAO' => ['Desative a conta ou o contacto (sem apagar) enquanto a situação se resolve.', 'Diga ao titular até quando fica suspenso e o que acontece a seguir.'],
    'OPOSICAO' => ['Pare o uso indicado (por exemplo, retire a pessoa das campanhas e mensagens promocionais).', 'Se não puder parar (obrigação legal ou contrato), explique o motivo ao recusar.'],
    'PORTABILIDADE' => ['Carregue em «Enviar os dados ao titular»: na página que ele recebe há o botão «Descarregar ficheiro», num formato que outro serviço consegue ler (JSON).'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['operation'] ?? '') === 'reveal_email') {
    header('Content-Type: application/json; charset=utf-8');
    $rid = (int)($_POST['request_id'] ?? 0);
    if (!csrf_is_valid((string)($_POST['_csrf'] ?? '')) || $rid <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Sessão expirada. Atualize a página.']);
        exit;
    }
    $q = $db->prepare('SELECT email FROM data_subject_requests WHERE id = ? LIMIT 1');
    $q->execute([$rid]);
    $em = personal_data_decrypt($q->fetchColumn() ?: null) ?? '';
    audit_log($db, 'data_subject_email_revealed', ['request_id' => $rid], 'INFO');
    echo json_encode(['success' => true, 'email' => $em]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['operation'] ?? '', ['send_verification', 'send_data'], true)) {
    // Enviar ao titular: link para confirmar que foi ele (prova de identidade) ou link para ver os seus dados.
    $op = (string)$_POST['operation'];
    $rid = (int)($_POST['request_id'] ?? 0);
    $flash = ['type' => 'danger', 'message' => 'Pedido inválido.'];
    if (csrf_is_valid((string)($_POST['_csrf'] ?? '')) && $rid > 0) {
        $q = $db->prepare('SELECT * FROM data_subject_requests WHERE id = ? LIMIT 1');
        $q->execute([$rid]);
        $req = $q->fetch(PDO::FETCH_ASSOC);
        $para = $req ? privacy_request_contact_email($db, $req) : '';
        $fechado = $req && in_array($req['status'], ['CONCLUIDO', 'RECUSADO'], true);
        if (!$req || $fechado) {
            $flash['message'] = 'Este pedido está encerrado.';
        } elseif ($op === 'send_data' && empty($req['identity_verified_at'])) {
            $flash['message'] = 'Confirme primeiro a identidade do titular.';
        } elseif ($para === '') {
            $flash['message'] = 'O pedido não tem um email válido.';
        } else {
            $base = privacy_request_base_url();
            $nome = (string)(personal_data_decrypt($req['nome'] ?? null) ?? '');
            if ($op === 'send_verification') {
                $token = privacy_request_new_token($db, $rid, 'verify', 72);
                $link = $base . '/direitos_confirmar.php?r=' . rawurlencode((string)$req['public_id']) . '&t=' . $token;
                $assunto = 'Confirme o seu pedido sobre dados pessoais';
                $corpo = '<p>Olá ' . htmlspecialchars($nome) . ',</p><p>Recebemos um pedido sobre os dados pessoais associados a este email. Para o proteger, confirme que foi você:</p>'
                    . '<p><a href="' . htmlspecialchars($link) . '" style="background:#f59e0b;color:#111;padding:12px 18px;border-radius:8px;text-decoration:none;font-weight:bold">Confirmar o meu pedido</a></p>'
                    . '<p>O link vale 3 dias. Se não fez este pedido, abra o link e escolha «Não fui eu».</p><p>Nivora Restaurant</p>';
                $evento = ['VERIFICATION_SENT', 'Link de confirmação enviado para o email do titular.'];
            } else {
                $token = privacy_request_new_token($db, $rid, 'delivery', 24 * 7);
                $link = $base . '/direitos_meus_dados.php?r=' . rawurlencode((string)$req['public_id']) . '&t=' . $token;
                $assunto = 'Os seus dados pessoais estão prontos';
                $corpo = '<p>Olá ' . htmlspecialchars($nome) . ',</p><p>Preparámos os dados pessoais que guardamos sobre si, como pediu. Pode vê-los, imprimi-los ou guardá-los em PDF:</p>'
                    . '<p><a href="' . htmlspecialchars($link) . '" style="background:#f59e0b;color:#111;padding:12px 18px;border-radius:8px;text-decoration:none;font-weight:bold">Ver os meus dados</a></p>'
                    . '<p>Por segurança, o link vale 7 dias e não deve ser partilhado.</p><p>Nivora Restaurant</p>';
                $evento = ['DATA_LINK_SENT', 'Link para ver os dados enviado ao titular (válido 7 dias).'];
            }
            $enviado = false;
            try {
                $enviado = function_exists('saas_enviar_email') && saas_enviar_email($para, $assunto, $corpo);
            } catch (Throwable $ex) {
                error_log('privacy send: ' . $ex->getMessage());
            }
            privacy_request_record_event($db, $rid, (int)($_SESSION['usuario_id'] ?? 0) ?: null, $evento[0], $evento[1] . ($enviado ? '' : ' (o email falhou; link mostrado ao administrador)'));
            audit_log($db, 'data_subject_' . $op, ['request_id' => $rid, 'email_enviado' => $enviado]);
            if ($enviado) {
                $flash = ['type' => 'success', 'message' => 'Email enviado para ' . privacy_mask_email($para) . '. ' . ($op === 'send_verification' ? 'Quando o titular confirmar, o pedido muda sozinho para «Identidade confirmada».' : 'Quando o titular abrir o link, fica registado aqui.')];
            } else {
                $flash = ['type' => 'warning', 'message' => 'O email não seguiu (verifique a configuração SMTP). Pode enviar este link ao titular por outra via, mas atenção: só serve de prova de identidade se for para o email da conta.', 'link' => $link];
            }
        }
    }
    $_SESSION['privacy_flash'] = $flash;
    header('Location: admin_privacidade.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestId = (int)($_POST['request_id'] ?? 0);
    $operation = strtolower(trim((string)($_POST['operation'] ?? 'save')));
    $notes = trim((string)($_POST['admin_notes'] ?? ''));
    $errors = [];
    $allowedOperations = ['save', 'verify_identity', 'start_processing', 'complete', 'reject'];

    if (!csrf_is_valid((string)($_POST['_csrf'] ?? ''))) {
        $errors[] = 'A sessao expirou. Atualize a pagina e tente novamente.';
    }
    if ($requestId <= 0 || !in_array($operation, $allowedOperations, true)) {
        $errors[] = 'Pedido ou estado invalido.';
    }
    if (strlen($notes) < 8 || strlen($notes) > 3000) {
        $errors[] = 'Registe uma nota administrativa com 8 a 3000 caracteres.';
    }

    if ($errors === []) {
        $find = $db->prepare('SELECT id, public_id, status, email, identity_verified_at FROM data_subject_requests WHERE id = ? LIMIT 1');
        $find->execute([$requestId]);
        $request = $find->fetch(PDO::FETCH_ASSOC);
        if (!$request) {
            $errors[] = 'Pedido nao encontrado.';
        } else {
            $currentStatus = strtoupper((string)($request['status'] ?? ''));
            $newStatus = $currentStatus;
            $identityVerified = !empty($request['identity_verified_at']);
            $requestClosed = in_array($currentStatus, ['CONCLUIDO', 'RECUSADO'], true);

            if ($operation === 'verify_identity') {
                if ($requestClosed || $identityVerified) {
                    $errors[] = 'A identidade deste pedido ja foi confirmada ou o pedido esta encerrado.';
                } else {
                    $newStatus = 'EM_VERIFICACAO';
                }
            } elseif ($operation === 'start_processing') {
                if (!$identityVerified) {
                    $errors[] = 'Confirme a identidade antes de iniciar o tratamento.';
                } elseif ($requestClosed || !in_array($currentStatus, ['PENDENTE_VERIFICACAO', 'EM_VERIFICACAO'], true)) {
                    $errors[] = 'O pedido nao esta num estado valido para iniciar o tratamento.';
                } else {
                    $newStatus = 'EM_PROCESSAMENTO';
                }
            } elseif (in_array($operation, ['complete', 'reject'], true)) {
                if (!$identityVerified) {
                    $errors[] = 'Confirme a identidade antes de encerrar o pedido.';
                } elseif ($currentStatus !== 'EM_PROCESSAMENTO') {
                    $errors[] = 'Apenas pedidos em processamento podem ser encerrados.';
                } else {
                    $newStatus = $operation === 'complete' ? 'CONCLUIDO' : 'RECUSADO';
                }
            } elseif ($operation === 'save' && $requestClosed) {
                $errors[] = 'O pedido esta encerrado e disponivel apenas para consulta.';
            }

            if ($errors !== []) {
                $_SESSION['privacy_flash'] = ['type' => 'danger', 'message' => implode(' ', $errors)];
                header('Location: admin_privacidade.php');
                exit;
            }

            $actorId = (int)($_SESSION['usuario_id'] ?? 0) ?: null;
            $resolved = in_array($newStatus, ['CONCLUIDO', 'RECUSADO'], true);
            $stmt = $db->prepare("UPDATE data_subject_requests
                SET status = ?, admin_notes = ?, handled_by = ?,
                    identity_verified_at = CASE WHEN ? = 1 THEN COALESCE(identity_verified_at, NOW()) ELSE identity_verified_at END,
                    identity_verified_by = CASE WHEN ? = 1 THEN COALESCE(identity_verified_by, ?) ELSE identity_verified_by END,
                    verified_via = CASE WHEN ? = 1 THEN 'MANUAL' ELSE verified_via END,
                    resolved_at = CASE WHEN ? = 1 THEN NOW() ELSE NULL END,
                    updated_at = NOW()
                WHERE id = ?");
            $isVerification = $operation === 'verify_identity';
            $stmt->execute([
                $newStatus,
                personal_data_encrypt($notes),
                $actorId,
                $isVerification ? 1 : 0,
                $isVerification ? 1 : 0,
                $actorId,
                $isVerification ? 1 : 0,
                $resolved ? 1 : 0,
                $requestId,
            ]);

            $eventType = match ($operation) {
                'verify_identity' => 'IDENTITY_VERIFIED_MANUAL',
                'start_processing' => 'PROCESSING_STARTED',
                'complete' => 'REQUEST_COMPLETED',
                'reject' => 'REQUEST_REJECTED',
                default => 'STATUS_UPDATED',
            };
            privacy_request_record_event($db, $requestId, $actorId, $eventType, $notes);

            audit_log($db, 'data_subject_request_status_changed', [
                'request_id' => $requestId,
                'public_id' => $request['public_id'],
                'previous_status' => $request['status'],
                'new_status' => $newStatus,
            ], $newStatus === 'RECUSADO' ? 'WARN' : 'INFO');

            $requestEmail = personal_data_decrypt($request['email'] ?? null) ?? '';
            $mailSent = false;
            if ($resolved && filter_var($requestEmail, FILTER_VALIDATE_EMAIL)) {
                $mailSent = saas_enviar_email(
                    $requestEmail,
                    'Atualizacao do pedido de dados - ' . $request['public_id'],
                    "O pedido {$request['public_id']} foi atualizado para: {$allowedStatuses[$newStatus]}.\n\nObservacao da equipa: {$notes}\n\nNao responda com palavras-passe, PIN ou dados bancarios."
                );
            }

            $operationMessage = match ($operation) {
                'verify_identity' => 'Identidade confirmada e pedido colocado em verificacao.',
                'start_processing' => 'Tratamento iniciado.',
                'complete' => 'Pedido concluido e encerrado.',
                'reject' => 'Pedido recusado e encerrado.',
                default => 'Pedido atualizado para ' . $allowedStatuses[$newStatus] . '.',
            };
            $_SESSION['privacy_flash'] = [
                'type' => 'success',
                'message' => $operationMessage . ($resolved ? ($mailSent ? ' Email enviado.' : ' O email nao foi enviado.') : '')
            ];
            header('Location: admin_privacidade.php');
            exit;
        }
    }

    if ($errors !== []) {
        $_SESSION['privacy_flash'] = ['type' => 'danger', 'message' => implode(' ', $errors)];
        header('Location: admin_privacidade.php');
        exit;
    }
}

$statusFilter = strtoupper(trim((string)($_GET['status'] ?? '')));
$typeFilter = strtoupper(trim((string)($_GET['tipo'] ?? '')));
$search = trim((string)($_GET['q'] ?? ''));
$sql = 'SELECT * FROM data_subject_requests WHERE 1=1';
$params = [];
if ($statusFilter === '') {
    $sql .= " AND status NOT IN ('CONCLUIDO','RECUSADO')";
} elseif ($statusFilter !== 'TODOS' && isset($allowedStatuses[$statusFilter])) {
    $sql .= ' AND status = ?';
    $params[] = $statusFilter;
}
if ($typeFilter !== '' && isset($typeLabels[$typeFilter])) {
    $sql .= ' AND request_type = ?';
    $params[] = $typeFilter;
}
$sql .= ' ORDER BY FIELD(status, \'PENDENTE_VERIFICACAO\',\'EM_VERIFICACAO\',\'EM_PROCESSAMENTO\',\'CONCLUIDO\',\'RECUSADO\'), id DESC LIMIT 200';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

foreach ($requests as &$request) {
    foreach (['nome', 'email', 'restaurante', 'descricao', 'admin_notes'] as $field) {
        $request[$field] = personal_data_decrypt($request[$field] ?? null) ?? '';
    }
    $request['subjects'] = privacy_request_find_subjects($db, (string)($request['email_lookup'] ?? ''));
    $request['operational_timing'] = privacy_request_operational_timing($request);
}
unset($request);

if ($search !== '') {
    $needle = strtolower($search);
    $requests = array_values(array_filter($requests, static function (array $request) use ($needle): bool {
        $haystack = strtolower(implode(' ', [
            $request['public_id'] ?? '',
            $request['nome'] ?? '',
            $request['email'] ?? '',
            $request['restaurante'] ?? '',
            $request['descricao'] ?? '',
        ]));
        return strpos($haystack, $needle) !== false;
    }));
}

$stats = [
    'total' => 0,
    'pending' => 0,
    'processing' => 0,
    'closed' => 0,
    'due_soon' => 0,
    'overdue' => 0,
    'deadline_days' => privacy_request_operational_deadline_days(),
];
$allRows = $db->query('SELECT status, COUNT(*) AS total FROM data_subject_requests GROUP BY status')->fetchAll(PDO::FETCH_ASSOC);
foreach ($allRows as $row) {
    $count = (int)$row['total'];
    $stats['total'] += $count;
    if ($row['status'] === 'PENDENTE_VERIFICACAO') $stats['pending'] += $count;
    if (in_array($row['status'], ['EM_VERIFICACAO', 'EM_PROCESSAMENTO'], true)) $stats['processing'] += $count;
    if (in_array($row['status'], ['CONCLUIDO', 'RECUSADO'], true)) $stats['closed'] += $count;
}
$openTimingRows = $db->query("SELECT status, created_at FROM data_subject_requests WHERE status NOT IN ('CONCLUIDO','RECUSADO')")->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($openTimingRows as $timingRow) {
    $timing = privacy_request_operational_timing($timingRow);
    if ($timing['state'] === 'VENCIDO') $stats['overdue']++;
    if ($timing['state'] === 'PROXIMO_PRAZO') $stats['due_soon']++;
}

$flash = $_SESSION['privacy_flash'] ?? null;
unset($_SESSION['privacy_flash']);

function privacy_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function privacy_mask_email(string $email): string
{
    if (!str_contains($email, '@')) {
        return $email === '' ? '' : mb_substr($email, 0, 2) . '••••';
    }
    [$u, $d] = explode('@', $email, 2);
    return mb_substr($u, 0, min(4, max(1, mb_strlen($u) - 2))) . '••••@' . $d;
}

function privacy_status_class(string $status): string
{
    return match ($status) {
        'PENDENTE_VERIFICACAO' => 'warning',
        'EM_VERIFICACAO' => 'info',
        'EM_PROCESSAMENTO' => 'primary',
        'CONCLUIDO' => 'success',
        'RECUSADO' => 'danger',
        default => 'secondary',
    };
}

function privacy_timing_class(string $state): string
{
    return match ($state) {
        'VENCIDO' => 'danger',
        'PROXIMO_PRAZO' => 'warning',
        'NO_PRAZO' => 'success',
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
    <title>Privacidade - Super Admin Nivora Restaurant</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/adminpro-theme.css">
    <link rel="stylesheet" href="css/premium.css">
    <style>
        :root { --nav:#0f172a; --accent:#ff7a1a; --ink:#0f172a; --muted:#64748b; --line:#dce3ed; --bg:#f4f7fb; }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; overflow-x:hidden; background:linear-gradient(180deg,#f5f7ff 0%,#eef2fb 100%); color:var(--ink); font-family:'Poppins',sans-serif; font-size:14px; }
        .privacy-layout { min-height:100vh; }
        .sidebar { position:fixed; top:0; left:0; z-index:1000; width:260px; min-height:100vh; overflow-y:auto; color:#fff; background:linear-gradient(180deg,#1e293b 0%,#0f172a 100%); border-right:1px solid rgba(255,255,255,.08); box-shadow:10px 0 24px rgba(2,6,23,.22); transition:transform .25s ease; }
        .sidebar.sidebar-hidden { transform:translateX(-100%); }
        .sidebar .brand { padding:22px 20px 18px; border-bottom:1px solid rgba(255,255,255,.1); }
        .sidebar .brand h3 { margin:0; color:#fff; font-size:18px; font-weight:800; line-height:1.2; }
        .sidebar .brand span { color:#a5b4fc; font-size:11px; letter-spacing:.03em; text-transform:uppercase; opacity:.9; }
        .sidebar .nav-item a { display:flex; align-items:center; gap:12px; min-width:0; margin:4px 10px; padding:10px 12px; color:rgba(255,255,255,.7); border-radius:10px; border-left:0; text-decoration:none; font-size:15px; font-weight:500; cursor:pointer; transition:all .25s; }
        .sidebar .nav-item a i { width:20px; text-align:center; opacity:.9; }
        .sidebar .nav-item a:hover,.sidebar .nav-item a.active { color:#fff; background:rgba(99,102,241,.3); box-shadow:inset 0 0 0 1px rgba(255,255,255,.12); transform:translateX(2px); }
        .sidebar .nav-item .badge { margin-left:auto; }
        .privacy-main { min-width:0; width:calc(100% - 260px); margin-left:260px; padding:24px 25px 60px; transition:margin-left .25s ease,width .25s ease,padding-left .25s ease; }
        body.layout-sidebar-collapsed .privacy-main { width:100%; margin-left:0; padding-left:92px; }
        .menu-toggle-btn { position:fixed; top:16px; left:16px; z-index:2100; display:inline-flex; width:56px; height:56px; align-items:center; justify-content:center; color:#fff; background:linear-gradient(180deg,#ff9433 0%,#ff7a1a 100%); border:0; border-radius:16px; box-shadow:0 10px 20px rgba(255,122,26,.26); font-size:22px; cursor:pointer; transition:opacity .2s ease,transform .2s ease,box-shadow .2s ease; }
        .menu-toggle-btn:focus { outline:none; box-shadow:0 0 0 4px rgba(255,122,26,.2),0 12px 26px rgba(255,122,26,.34); }
        .menu-toggle-btn.is-docked { top:14px; left:202px; width:46px; height:46px; border-radius:12px; font-size:18px; box-shadow:0 6px 14px rgba(255,122,26,.2); }
        .sidebar-backdrop { position:fixed; inset:0; z-index:1800; display:none; background:rgba(15,23,42,.44); }
        .sidebar-backdrop.show { display:block; }
        .privacy-header { display:flex; justify-content:space-between; gap:20px; align-items:center; margin-bottom:24px; }
        .privacy-header h1 { margin:0; font-size:28px; font-weight:700; }
        .privacy-header p { margin:5px 0 0; color:var(--muted); }
        .privacy-stats { display:grid; grid-template-columns:repeat(6,minmax(0,1fr)); gap:14px; margin-bottom:20px; }
        .privacy-stat { min-width:0; padding:18px; background:rgba(255,255,255,.94); border:1px solid var(--line); border-radius:8px; box-shadow:0 12px 30px rgba(20,33,58,.06); }
        .privacy-stat strong { display:block; font-size:27px; }
        .privacy-stat span { color:var(--muted); font-size:13px; font-weight:700; }
        .privacy-panel { min-width:0; background:#fff; border:1px solid var(--line); border-radius:8px; overflow:hidden; box-shadow:0 12px 30px rgba(20,33,58,.06); }
        .privacy-filters { display:grid; grid-template-columns:minmax(220px,1fr) 210px 190px auto; gap:10px; padding:16px; border-bottom:1px solid var(--line); }
        .privacy-list { display:grid; min-width:0; }
        .privacy-request { min-width:0; overflow:hidden; padding:20px; border-bottom:1px solid var(--line); }
        .privacy-request:last-child { border-bottom:0; }
        .privacy-request__top { display:flex; justify-content:space-between; gap:18px; align-items:start; }
        .privacy-request__top > div:first-child { min-width:0; }
        .privacy-reference { overflow-wrap:anywhere; margin:0 0 6px; font-size:16px; font-weight:800; }
        .privacy-meta { display:flex; flex-wrap:wrap; gap:7px 16px; color:var(--muted); font-size:13px; }
        .privacy-meta span { min-width:0; overflow-wrap:anywhere; }
        .privacy-description { max-width:100%; margin:16px 0; padding:14px; overflow-wrap:anywhere; word-break:break-word; background:#f7f9fc; border-left:3px solid var(--accent); white-space:pre-wrap; }
        .privacy-actions { display:grid; grid-template-columns:minmax(190px,220px) minmax(280px,1fr) minmax(130px,auto); gap:12px; align-items:end; min-width:0; }
        .privacy-actions > div { min-width:0; }
        .privacy-actions textarea { min-height:78px; }
        .privacy-actions .btn { min-height:42px; white-space:nowrap; }
        .privacy-case-tools { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:18px; align-items:center; margin:16px 0; padding:16px; background:#f8fafc; border:1px solid var(--line); border-radius:7px; }
        .privacy-case-tools h3 { margin:0 0 5px; font-size:14px; font-weight:700; }
        .privacy-case-tools p { margin:0; color:var(--muted); font-size:12px; }
        .privacy-subjects { display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }
        .privacy-subject { display:inline-flex; align-items:center; gap:7px; min-width:0; padding:7px 9px; color:#334155; background:#fff; border:1px solid #dbe3ee; border-radius:5px; font-size:11px; font-weight:600; }
        .privacy-subject span { overflow-wrap:anywhere; }
        .privacy-case-actions { display:flex; flex-wrap:wrap; justify-content:flex-end; gap:8px; }
        .privacy-case-actions form { margin:0; }
        .privacy-case-actions .btn { white-space:nowrap; }
        .privacy-verification { display:inline-flex; align-items:center; gap:6px; margin-top:8px; color:#087a5b; font-size:11px; font-weight:700; }
        .privacy-action-buttons { display:grid; gap:7px; }
        .privacy-action-buttons .btn { width:100%; }
        .privacy-note { margin-top:12px; padding:10px 12px; background:#eef4ff; border-radius:5px; font-size:13px; }
        .privacy-empty { padding:70px 20px; text-align:center; color:var(--muted); }
        .privacy-email-state { display:inline-flex; align-items:center; gap:5px; font-size:12px; font-weight:700; }
        @media(max-width:1400px){.privacy-stats{grid-template-columns:repeat(3,minmax(0,1fr))}}
        @media(max-width:1100px){.privacy-actions{grid-template-columns:200px minmax(220px,1fr)}.privacy-action-buttons{grid-column:1/-1;display:flex;justify-content:flex-end}.privacy-action-buttons .btn{width:auto}.privacy-case-tools{grid-template-columns:1fr}.privacy-case-actions{justify-content:flex-start}}
        @media(max-width:991px){.sidebar{z-index:2000;width:82vw;max-width:340px;min-width:230px;transform:translateX(-100%);box-shadow:2px 0 16px rgba(0,0,0,.08)}.privacy-main,body.layout-sidebar-collapsed .privacy-main{width:100%;margin-left:0;padding:98px 14px 50px}.menu-toggle-btn{top:16px;left:16px}.privacy-stats{grid-template-columns:1fr 1fr}.privacy-filters,.privacy-actions{grid-template-columns:1fr}.privacy-action-buttons{grid-column:auto;display:grid}.privacy-action-buttons .btn{width:100%}.privacy-request__top,.privacy-header{display:grid}}
        @media(max-width:560px){.sidebar{width:86vw!important;max-width:320px!important;min-height:100vh!important;display:block!important}.menu-toggle-btn{width:50px;height:50px;border-radius:14px}.privacy-stats{grid-template-columns:1fr}.privacy-header .btn{width:100%}.privacy-request{padding:16px}.privacy-meta{display:grid}.privacy-request__top .text-end{text-align:left!important}}
    </style>
    <link rel="stylesheet" href="css/super-admin-shell.css">
    <link rel="stylesheet" href="css/super-admin-secoes.css?v=<?php echo (int)@filemtime(__DIR__ . '/css/super-admin-secoes.css'); ?>">
    <style>
        .pv-caso{border-bottom:1px solid var(--line)}
        .pv-caso:last-child{border-bottom:0}
        .pv-linha{display:grid;grid-template-columns:110px 120px minmax(0,1.6fr) 170px 150px 130px;gap:12px;align-items:center;padding:12px 16px;cursor:pointer;list-style:none;font-size:13px}
        .pv-linha::-webkit-details-marker{display:none}
        .pv-linha:hover{background:#f8fafc}
        .pv-caso[open] > .pv-linha{background:#f1f5f9}
        .pv-linha:focus-visible{outline:3px solid #93c5fd;outline-offset:-3px}
        .pv-ref{font-family:Consolas,monospace;font-weight:700;color:#334155}
        .pv-tipo{font-weight:600}
        .pv-quem{min-width:0;overflow-wrap:anywhere}
        .pv-corpo{padding:4px 20px 20px}
        .pv-dados{display:flex;flex-wrap:wrap;gap:6px 18px;color:var(--muted);font-size:13px;margin:6px 0 10px}
        .pv-dados > span{min-width:0;overflow-wrap:anywhere}
        .pv-descricao{max-width:100%;padding:12px 14px;background:#f7f9fc;border-left:3px solid var(--accent);white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word}
        .pv-guia{background:#f0f7ff;border:1px solid #bfdbfe;border-radius:10px;padding:12px 14px;margin:0 0 12px;font-size:13px;color:#1e3a5f}
        .pv-guia ol{margin:6px 0 0;padding-left:20px}
        .pv-guia > ol > li{margin-bottom:5px;color:#475569}
        .pv-guia > ol > li.atual{color:#0f172a;font-weight:600}
        .pv-guia > ol > li.atual::marker{color:#2563eb}
        .pv-guia > ol > li.feito{color:#64748b;text-decoration:line-through;text-decoration-color:#94a3b8}
        .pv-guia ul{margin:4px 0 0;padding-left:18px;font-weight:400}
        .pv-descricao{line-height:1.5}.pv-descricao[data-recolhida="1"]{max-height:calc(4.5em + 24px);overflow:hidden}
        .privacy-actions{grid-template-columns:minmax(280px,1fr) minmax(130px,auto)!important}
        @media(max-width:991px){.pv-linha{grid-template-columns:1fr 1fr;gap:6px}.privacy-actions{grid-template-columns:1fr!important}}
    </style>
</head>
<body class="super-admin-ui">
<button type="button" class="menu-toggle-btn" id="btnMenuToggle" aria-label="Abrir menu"><i class="fas fa-bars"></i></button>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
<div class="privacy-layout">
    <?php
    $superAdminActiveSection = 'privacidade';
    $superAdminSectionLinks = false;
    $superAdminPrivacyPendingCount = $stats['pending'];
    $superAdminOwnerName = $_SESSION['nome'] ?? 'Super Admin';
    $superAdminBranchContext = session_contexto_filial_ativo();
    include __DIR__ . '/includes/super_admin_sidebar.php';
    ?>

    <main class="privacy-main">
        <div class="sa-sec-header">
            <div>
                <h1 class="sa-sec-title"><i class="fas fa-user-shield" aria-hidden="true"></i> Privacidade</h1>
                <p class="sa-sec-sub">Pedidos de pessoas sobre os seus dados pessoais (acesso, correção, eliminação…). Prazo interno: <?= (int)$stats['deadline_days'] ?> dias.</p>
            </div>
            <div class="sa-sec-tools">
                <a class="btn btn-outline-dark" href="direitos_dados.php" target="_blank" rel="noopener"><i class="fas fa-arrow-up-right-from-square me-1" aria-hidden="true"></i> Ver o formulário público</a>
            </div>
        </div>

        <?php if (is_array($flash)): ?>
            <div class="alert alert-<?= privacy_e($flash['type'] ?? 'info') ?>" role="alert"><?= privacy_e($flash['message'] ?? '') ?>
                <?php if (!empty($flash['link'])): ?><div class="input-group input-group-sm mt-2"><input class="form-control" readonly value="<?= privacy_e($flash['link']) ?>" id="pvLinkCopiar" aria-label="Link para enviar ao titular"><button class="btn btn-outline-dark" type="button" id="pvBtnCopiar">Copiar link</button></div><?php endif; ?>
            </div>
        <?php endif; ?>

        <section class="sa-kpis" aria-label="Resumo">
            <div class="sa-kpi"><span class="sa-kpi__rotulo">Por tratar</span><strong class="sa-kpi__valor"><?= $stats['pending'] + $stats['processing'] ?></strong><span class="sa-kpi__nota"><?= $stats['pending'] ?> por verificar identidade · <?= $stats['processing'] ?> em tratamento</span></div>
            <div class="sa-kpi <?= ($stats['overdue'] + $stats['due_soon']) > 0 ? 'sa-kpi--alerta' : '' ?>"><span class="sa-kpi__rotulo">Prazo a vencer ou vencido</span><strong class="sa-kpi__valor"><?= $stats['due_soon'] + $stats['overdue'] ?></strong><span class="sa-kpi__nota"><?= $stats['overdue'] ?> já fora do prazo</span></div>
            <div class="sa-kpi"><span class="sa-kpi__rotulo">Encerrados</span><strong class="sa-kpi__valor text-success"><?= $stats['closed'] ?></strong><span class="sa-kpi__nota">de <?= $stats['total'] ?> no total</span></div>
        </section>

        <section class="privacy-panel" aria-labelledby="privacy-cases-title">
            <h2 class="visually-hidden" id="privacy-cases-title">Pedidos</h2>
            <form class="privacy-filters" method="get">
                <label class="visually-hidden" for="pq">Pesquisar</label>
                <input class="form-control" id="pq" type="search" name="q" value="<?= privacy_e($search) ?>" placeholder="Referência, nome, email ou restaurante">
                <label class="visually-hidden" for="pst">Estado</label>
                <select class="form-select" id="pst" name="status">
                    <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>Por tratar</option>
                    <option value="TODOS" <?= $statusFilter === 'TODOS' ? 'selected' : '' ?>>Todos</option>
                    <?php foreach ($allowedStatuses as $value => $label): ?><option value="<?= $value ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= privacy_e($label) ?></option><?php endforeach; ?>
                </select>
                <label class="visually-hidden" for="ptp">Tipo</label>
                <select class="form-select" id="ptp" name="tipo">
                    <option value="">Todos os tipos</option>
                    <?php foreach ($typeLabels as $value => $label): ?><option value="<?= $value ?>" <?= $typeFilter === $value ? 'selected' : '' ?>><?= privacy_e($label) ?></option><?php endforeach; ?>
                </select>
                <button class="btn btn-dark" type="submit"><i class="fas fa-filter me-1" aria-hidden="true"></i> Filtrar</button>
            </form>

            <div class="pv-lista">
                <?php if ($requests === []): ?>
                    <div class="privacy-empty"><i class="fas fa-circle-check fa-2x mb-3 text-success" aria-hidden="true"></i><br><?= $statusFilter === '' ? 'Nenhum pedido por tratar.' : 'Nenhum pedido encontrado.' ?></div>
                <?php endif; ?>
                <?php foreach ($requests as $request):
                    $subjectUsers = $request['subjects']['users'] ?? [];
                    $subjectRestaurants = $request['subjects']['restaurants'] ?? [];
                    $identityVerified = !empty($request['identity_verified_at']);
                    $requestClosed = in_array($request['status'], ['CONCLUIDO', 'RECUSADO'], true);
                    $timing = $request['operational_timing'] ?? [];
                ?>
                    <details class="pv-caso">
                        <summary class="pv-linha">
                            <span class="pv-ref" title="<?= privacy_e($request['public_id']) ?>">#<?= privacy_e(substr((string)$request['public_id'], 0, 8)) ?></span>
                            <span class="pv-tipo"><?= privacy_e($typeLabels[$request['request_type']] ?? $request['request_type']) ?></span>
                            <span class="pv-quem"><strong><?= privacy_e($request['nome']) ?></strong><br><span class="text-muted small"><?= privacy_e(privacy_mask_email((string)$request['email'])) ?></span></span>
                            <span><span class="badge text-bg-<?= privacy_status_class((string)$request['status']) ?>"><?= privacy_e($allowedStatuses[$request['status']] ?? $request['status']) ?></span></span>
                            <span class="small"><?php if (($timing['state'] ?? '') !== 'ENCERRADO'): ?><span class="badge text-bg-<?= privacy_timing_class((string)($timing['state'] ?? '')) ?>"><?= privacy_e((string)($timing['label'] ?? '')) ?></span><br><span class="text-muted">até <?= !empty($timing['due_at']) ? privacy_e(date('d/m/Y', strtotime((string)$timing['due_at']))) : '' ?></span><?php else: ?><span class="text-muted">Encerrado</span><?php endif; ?></span>
                            <span class="small text-muted text-nowrap"><?= privacy_e(date('d/m/Y H:i', strtotime((string)$request['created_at']))) ?></span>
                        </summary>
                        <div class="pv-corpo">
                            <div class="pv-dados">
                                <span><i class="fas fa-fingerprint me-1" aria-hidden="true"></i>Referência: <?= privacy_e($request['public_id']) ?></span>
                                <span><i class="fas fa-envelope me-1" aria-hidden="true"></i><span class="pv-email" data-id="<?= (int)$request['id'] ?>"><?= privacy_e(privacy_mask_email((string)$request['email'])) ?></span>
                                    <button type="button" class="btn btn-link btn-sm p-0 ms-1 pv-revelar" data-id="<?= (int)$request['id'] ?>">Mostrar email completo</button></span>
                                <?php if ($request['restaurante'] !== ''): ?><span><i class="fas fa-building me-1" aria-hidden="true"></i><?= privacy_e($request['restaurante']) ?></span><?php endif; ?>
                                <span class="<?= $request['receipt_sent_at'] ? 'text-success' : 'text-warning-emphasis' ?>"><i class="fas <?= $request['receipt_sent_at'] ? 'fa-envelope-circle-check' : 'fa-triangle-exclamation' ?> me-1" aria-hidden="true"></i><?= $request['receipt_sent_at'] ? 'Recibo do pedido enviado ao titular' : 'Falta confirmar ao titular que o pedido foi recebido (o email de recibo não seguiu)' ?></span>
                            </div>

                            <?php if (!$requestClosed): ?>
                            <div class="pv-guia">
                                <strong><i class="fas fa-list-check me-1" aria-hidden="true"></i>O que fazer neste pedido</strong>
                                <ol>
                                    <li class="<?= $identityVerified ? 'feito' : 'atual' ?>">Confirmar a identidade: carregue em «Enviar link de confirmação». O titular recebe um email e carrega em «Sim, fui eu»; o pedido muda sozinho. (Só use «Confirmar manualmente» se confirmou de outra forma, por exemplo por telefone, e explique como na nota.)</li>
                                    <li class="<?= $request['status'] === 'EM_PROCESSAMENTO' ? 'feito' : ($identityVerified ? 'atual' : '') ?>">Carregue em «Começar a tratar» e escreva uma nota do que vai fazer.</li>
                                    <li class="<?= $request['status'] === 'EM_PROCESSAMENTO' ? 'atual' : '' ?>"><?= privacy_e($typeLabels[$request['request_type']] ?? 'Pedido') ?>:
                                        <ul><?php foreach (($typeGuides[$request['request_type']] ?? []) as $passo): ?><li><?= privacy_e($passo) ?></li><?php endforeach; ?></ul>
                                    </li>
                                    <li>Carregue em «Concluir pedido» (ou «Recusar», explicando porquê na nota). Prazo: até <?= !empty($timing['due_at']) ? privacy_e(date('d/m/Y', strtotime((string)$timing['due_at']))) : '30 dias' ?>.</li>
                                </ol>
                            </div>
                            <?php endif; ?>
                            <div class="pv-descricao" data-recolhida="1"><?= privacy_e($request['descricao']) ?></div>
                            <button type="button" class="btn btn-link btn-sm p-0 pv-ver-mais" hidden>Ver mais</button>
                            <?php if ($request['admin_notes'] !== ''): ?><div class="privacy-note"><strong>Última nota:</strong> <?= nl2br(privacy_e($request['admin_notes'])) ?></div><?php endif; ?>

                            <section class="privacy-case-tools" aria-label="Contas e dados desta pessoa">
                                <div>
                                    <h3><i class="fas fa-toolbox me-2 text-primary" aria-hidden="true"></i>Contas desta pessoa no sistema</h3>
                                    <p><?= count($subjectUsers) + count($subjectRestaurants) ?> registo(s) encontrado(s) pelo email.</p>
                                    <div class="privacy-subjects">
                                        <?php foreach ($subjectUsers as $subject): ?>
                                            <span class="privacy-subject"><i class="fas fa-user" aria-hidden="true"></i><span>Utilizador #<?= (int)$subject['id'] ?> · <?= privacy_e($subject['perfil']) ?> · <?= !empty($subject['ativo']) ? 'Ativo' : 'Inativo' ?></span></span>
                                        <?php endforeach; ?>
                                        <?php foreach ($subjectRestaurants as $subject): ?>
                                            <span class="privacy-subject"><i class="fas fa-building" aria-hidden="true"></i><span>Restaurante #<?= (int)$subject['id'] ?> · <?= privacy_e($subject['nome']) ?></span></span>
                                        <?php endforeach; ?>
                                        <?php if ($subjectUsers === [] && $subjectRestaurants === []): ?>
                                            <span class="privacy-subject text-warning"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i><span>Nenhuma conta encontrada com este email</span></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($identityVerified): ?>
                                        <span class="privacy-verification"><i class="fas fa-user-check" aria-hidden="true"></i><?= ($request['verified_via'] ?? '') === 'EMAIL_LINK' ? 'Confirmado pelo próprio titular (link no email)' : 'Identidade confirmada manualmente' ?> em <?= privacy_e(date('d/m/Y H:i', strtotime((string)$request['identity_verified_at']))) ?></span>
                                    <?php elseif (($request['verified_via'] ?? '') === 'NEGADO'): ?>
                                        <span class="privacy-verification text-danger"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i>O dono do email disse que NÃO fez este pedido. Recuse o pedido.</span>
                                    <?php elseif (!empty($request['verify_sent_at'])): ?>
                                        <span class="privacy-verification text-warning-emphasis"><i class="fas fa-hourglass-half" aria-hidden="true"></i>Link de confirmação enviado em <?= privacy_e(date('d/m/Y H:i', strtotime((string)$request['verify_sent_at']))) ?> — à espera do titular (válido até <?= privacy_e(date('d/m/Y H:i', strtotime((string)$request['verify_expires_at']))) ?>)</span>
                                    <?php endif; ?>
                                    <?php if (!empty($request['delivery_sent_at'])): ?>
                                        <span class="privacy-verification <?= !empty($request['delivery_opened_at']) ? '' : 'text-warning-emphasis' ?>"><i class="fas fa-envelope-open-text" aria-hidden="true"></i>Dados enviados em <?= privacy_e(date('d/m/Y H:i', strtotime((string)$request['delivery_sent_at']))) ?><?= !empty($request['delivery_opened_at']) ? ' · o titular abriu em ' . privacy_e(date('d/m/Y H:i', strtotime((string)$request['delivery_opened_at']))) : ' · ainda não abertos' ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="privacy-case-actions">
                                    <?php if (!$identityVerified && !$requestClosed): ?>
                                        <form method="post">
                                            <input type="hidden" name="_csrf" value="<?= privacy_e($csrfToken) ?>">
                                            <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                                            <button class="btn btn-sm btn-primary" type="submit" name="operation" value="send_verification"><i class="fas fa-paper-plane me-1" aria-hidden="true"></i><?= !empty($request['verify_sent_at']) ? 'Reenviar link de confirmação' : 'Enviar link de confirmação ao titular' ?></button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($identityVerified): ?>
                                        <form action="admin_privacidade_export.php" method="post" target="_blank">
                                            <input type="hidden" name="_csrf" value="<?= privacy_e($csrfToken) ?>">
                                            <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                                            <input type="hidden" name="formato" value="html">
                                            <button class="btn btn-sm btn-outline-dark" type="submit"><i class="fas fa-eye me-1" aria-hidden="true"></i>Ver os dados (versão legível)</button>
                                        </form>
                                        <?php if (!$requestClosed && in_array($request['request_type'], ['ACESSO', 'PORTABILIDADE'], true)): ?>
                                        <form method="post">
                                            <input type="hidden" name="_csrf" value="<?= privacy_e($csrfToken) ?>">
                                            <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                                            <button class="btn btn-sm btn-success" type="submit" name="operation" value="send_data"><i class="fas fa-envelope me-1" aria-hidden="true"></i><?= !empty($request['delivery_sent_at']) ? 'Reenviar os dados ao titular' : 'Enviar os dados ao titular' ?></button>
                                        </form>
                                        <?php endif; ?>
                                        <form action="admin_privacidade_export.php" method="post">
                                            <input type="hidden" name="_csrf" value="<?= privacy_e($csrfToken) ?>">
                                            <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                                            <button class="btn btn-sm btn-link" type="submit" title="Ficheiro técnico (JSON) para levar os dados para outro serviço">Descarregar JSON</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php foreach ($subjectUsers as $subject): ?>
                                        <?php if ($canManageUsers): ?><a class="btn btn-sm btn-outline-primary" href="admin.php?secao=usuarios&amp;rid=<?= (int)$subject['restaurante_id'] ?>&amp;uq=<?= rawurlencode((string)$request['email']) ?>"><i class="fas fa-user-pen me-1" aria-hidden="true"></i>Corrigir utilizador #<?= (int)$subject['id'] ?></a><?php endif; ?>
                                    <?php endforeach; ?>
                                    <?php foreach ($subjectRestaurants as $subject): ?>
                                        <?php if ($canManageRestaurants): ?><a class="btn btn-sm btn-outline-primary" href="admin.php?secao=restaurantes&amp;rq=<?= rawurlencode((string)$request['email']) ?>"><i class="fas fa-building me-1" aria-hidden="true"></i>Corrigir restaurante #<?= (int)$subject['id'] ?></a><?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </section>

                            <form class="privacy-actions mt-3" method="post">
                                <input type="hidden" name="_csrf" value="<?= privacy_e($csrfToken) ?>">
                                <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                                <div><label class="form-label fw-bold" for="nota<?= (int)$request['id'] ?>"><?= $requestClosed ? 'Nota final' : 'Nota sobre o que foi feito' ?></label><textarea class="form-control" id="nota<?= (int)$request['id'] ?>" name="admin_notes" maxlength="3000" <?= $requestClosed ? 'readonly' : 'required' ?> placeholder="O que verificou e decidiu (nunca escreva palavras-passe)"><?= privacy_e($request['admin_notes']) ?></textarea></div>
                                <div class="privacy-action-buttons">
                                    <?php if ($requestClosed): ?>
                                        <span class="btn btn-outline-secondary disabled" aria-disabled="true"><i class="fas fa-lock me-2" aria-hidden="true"></i>Pedido encerrado</span>
                                    <?php else: ?>
                                        <?php if (!$identityVerified): ?><button class="btn btn-outline-secondary" type="submit" name="operation" value="verify_identity" title="Use só se confirmou de outra forma (ex.: por telefone) e explique como na nota"><i class="fas fa-user-check me-2" aria-hidden="true"></i>Confirmar manualmente</button><?php endif; ?>
                                        <?php if ($identityVerified && $request['status'] !== 'EM_PROCESSAMENTO'): ?><button class="btn btn-outline-primary" type="submit" name="operation" value="start_processing"><i class="fas fa-play me-2" aria-hidden="true"></i>Começar a tratar</button><?php endif; ?>
                                        <?php if ($identityVerified && $request['status'] === 'EM_PROCESSAMENTO'): ?>
                                            <button class="btn btn-success" type="submit" name="operation" value="complete" data-confirm="Concluir e encerrar este pedido?"><i class="fas fa-circle-check me-2" aria-hidden="true"></i>Concluir pedido</button>
                                            <button class="btn btn-outline-danger" type="submit" name="operation" value="reject" data-confirm="Recusar e encerrar este pedido?"><i class="fas fa-ban me-2" aria-hidden="true"></i>Recusar pedido</button>
                                        <?php endif; ?>
                                        <button class="btn btn-primary" type="submit" name="operation" value="save"><i class="fas fa-floppy-disk me-2" aria-hidden="true"></i>Guardar nota</button>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/feedback.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/feedback.js'); ?>"></script>
<script src="js/nivora_dialogo.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nivora_dialogo.js'); ?>"></script>
<script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
    function privacyIsMobileViewport() {
        return window.matchMedia('(max-width: 991px)').matches;
    }

    function privacySetSidebarOpen(open) {
        const sidebar = document.querySelector('.sidebar');
        const backdrop = document.getElementById('sidebarBackdrop');
        const button = document.getElementById('btnMenuToggle');
        const icon = button ? button.querySelector('i') : null;
        if (!sidebar) return;

        sidebar.classList.toggle('sidebar-hidden', !open);
        if (privacyIsMobileViewport()) {
            document.body.classList.remove('layout-sidebar-collapsed');
            backdrop?.classList.toggle('show', open);
            button?.classList.remove('is-docked');
        } else {
            document.body.classList.toggle('layout-sidebar-collapsed', !open);
            backdrop?.classList.remove('show');
            button?.classList.toggle('is-docked', open);
        }
        if (icon) {
            icon.classList.toggle('fa-bars', !open);
            icon.classList.toggle('fa-times', open);
        }
        button?.setAttribute('aria-label', open ? 'Fechar menu' : 'Abrir menu');
    }

    document.addEventListener('DOMContentLoaded', () => {
        const sidebar = document.querySelector('.sidebar');
        privacySetSidebarOpen(!privacyIsMobileViewport());
        document.getElementById('btnMenuToggle')?.addEventListener('click', () => {
            privacySetSidebarOpen(sidebar?.classList.contains('sidebar-hidden') ?? true);
        });
        document.getElementById('sidebarBackdrop')?.addEventListener('click', () => privacySetSidebarOpen(false));
        document.querySelectorAll('.sidebar .nav-link').forEach((link) => {
            link.addEventListener('click', () => {
                if (privacyIsMobileViewport()) privacySetSidebarOpen(false);
            });
        });
        document.querySelectorAll('[data-confirm]').forEach((button) => {
            button.addEventListener('click', async (event) => {
                if (button.dataset.confirmado === '1') { button.dataset.confirmado = ''; return; }
                event.preventDefault();
                if (!(await nivoraConfirmar({ mensagem: button.dataset.confirm || 'Confirmar esta ação?', perigo: true }))) return;
                button.dataset.confirmado = '1';
                button.click();
            });
        });
        window.addEventListener('resize', () => privacySetSidebarOpen(!privacyIsMobileViewport()));
        // Texto comprido: 3 linhas e "Ver mais" (so aparece se o texto for mesmo maior).
        const ajustarVerMais = (caso) => caso.querySelectorAll('.pv-descricao').forEach((d) => {
            const btn = d.nextElementSibling;
            if (btn && btn.classList.contains('pv-ver-mais')) btn.hidden = d.dataset.recolhida !== '1' ? false : d.scrollHeight <= d.clientHeight + 2;
        });
        document.querySelectorAll('.pv-caso').forEach((caso) => caso.addEventListener('toggle', () => { if (caso.open) ajustarVerMais(caso); }));
        document.querySelectorAll('.pv-ver-mais').forEach((btn) => btn.addEventListener('click', () => {
            const d = btn.previousElementSibling;
            const recolhida = d.dataset.recolhida === '1';
            d.dataset.recolhida = recolhida ? '0' : '1';
            btn.textContent = recolhida ? 'Ver menos' : 'Ver mais';
        }));
        document.getElementById('pvBtnCopiar')?.addEventListener('click', async () => {
            const campo = document.getElementById('pvLinkCopiar');
            try { await navigator.clipboard.writeText(campo.value); } catch (e) { campo.select(); document.execCommand('copy'); }
            document.getElementById('pvBtnCopiar').textContent = 'Copiado ✓';
        });
        // Email completo so a pedido, e fica registado na auditoria.
        document.querySelectorAll('.pv-revelar').forEach((btn) => btn.addEventListener('click', async () => {
            const fd = new FormData();
            fd.append('operation', 'reveal_email');
            fd.append('request_id', btn.dataset.id);
            fd.append('_csrf', <?= json_encode($csrfToken) ?>);
            btn.disabled = true;
            try {
                const r = await fetch('admin_privacidade.php', { method: 'POST', body: fd, credentials: 'same-origin' });
                const j = await r.json();
                if (!j.success) throw new Error(j.message || 'Erro');
                const alvo = document.querySelector('.pv-email[data-id="' + btn.dataset.id + '"]');
                if (alvo) alvo.textContent = j.email;
                btn.remove();
            } catch (e) {
                btn.disabled = false;
                btn.textContent = 'Não foi possível mostrar — tentar de novo';
            }
        }));
    });
</script>
</body>
</html>
