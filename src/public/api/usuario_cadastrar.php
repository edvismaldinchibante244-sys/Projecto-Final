<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Cadastrar Usuário
 */

ob_start();

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/audit.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/plano_check.php';
require_once APP_BASE . '/src/config/restaurante_context.php';
require_once APP_BASE . '/src/config/login_security.php';
require_once APP_BASE . '/src/config/usuario_perfil_helper.php';
require_once APP_BASE . '/src/config/password_policy.php';
require_once APP_BASE . '/src/Model/Auth.php';

header('Content-Type: application/json; charset=utf-8');
ob_start();

function usuario_cadastrar_json(array $payload, int $status = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    security_json($payload, $status);
}

api_guard([
    'roles' => ['ADMIN'],
    'rate_key' => 'usuario_cadastrar',
    'rate_max' => 30,
    'rate_window' => 60,
    'skip_turno' => true,
]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $restauranteId = session_restaurante_contexto_id();
    $restaurantePlanId = session_restaurante_capability_id();

    if ($restauranteId <= 0 || $_SESSION['perfil'] !== 'ADMIN') {
        usuario_cadastrar_json(["success" => false, "message" => "Sem permissão"]);
    }

    if (empty($_POST['nome']) || empty($_POST['email']) || empty($_POST['senha']) || empty($_POST['perfil'])) {
        usuario_cadastrar_json(["success" => false, "message" => "Preencha todos os campos obrigatórios"]);
    }

    $pwdCheck = password_policy_validate((string)$_POST['senha'], [
        'email' => (string)$_POST['email'],
        'nome' => (string)$_POST['nome'],
        'perfil' => (string)$_POST['perfil'],
    ]);
    if (!$pwdCheck['ok']) {
        usuario_cadastrar_json(["success" => false, "message" => $pwdCheck['message']]);
    }

    $perfil = usuario_perfil_normalizar($_POST['perfil'] ?? '');
    $planIdGate = $restaurantePlanId > 0 ? $restaurantePlanId : $restauranteId;

    if (!in_array($perfil, usuario_perfis_base(), true)) {
        usuario_cadastrar_json(["success" => false, "message" => "Perfil inválido"]);
    }

    if (!usuario_perfil_disponivel_no_plano($planIdGate, $perfil)) {
        $upgrade = function_exists('plano_proximo_upgrade') ? plano_proximo_upgrade('BASICO') : null;
        if ($perfil === 'GERENTE') {
            $msg = 'Perfil Gerente disponível nos planos Profissional e Empresarial.';
        } else {
            $msg = 'Perfis Cozinha e Bar não estão disponíveis no plano Básico. Use Garçom, Caixa ou Admin.';
        }
        if (is_array($upgrade) && !empty($upgrade['nome'])) {
            $msg .= ' Faça upgrade para ' . $upgrade['nome'] . '.';
        }
        usuario_cadastrar_json([
            "success" => false,
            "code" => "FEATURE_BLOQUEADA",
            "message" => $msg,
            "upgrade_url" => "configuracoes.php?secao=plano",
        ]);
    }

    $database = new Database();
    $db = $database->getConnection();
    login_security_garantir_schema($db);
    usuarios_garantir_perfil_aceito($db, $perfil);

    // Verificar se email já existe
    $email = trim((string)$_POST['email']);
    $emailLookup = personal_data_email_lookup($email);
    $emailEncrypted = personal_data_encrypt($email);
    $query_check = "SELECT id FROM usuarios WHERE email_lookup = :email_lookup LIMIT 1";
    $stmt_check = $db->prepare($query_check);
    $stmt_check->bindValue(':email_lookup', $emailLookup, PDO::PARAM_STR);
    $stmt_check->execute();

    if ($stmt_check->rowCount() > 0) {
        usuario_cadastrar_json(["success" => false, "message" => "Este email já está em uso"]);
    }

    $temMultiFilial = $restaurantePlanId > 0 && plano_tem_funcionalidade_db($restaurantePlanId, 'multi_filial');
    if ($temMultiFilial) {
        $stmt_limit = $db->prepare("
            SELECT COUNT(*)
            FROM usuarios u
            INNER JOIN restaurantes r ON r.id = u.restaurante_id
            WHERE u.ativo = 1
              AND (u.restaurante_id = :base_restaurante_id OR r.filial_id = :filial_base_id)
        ");
        $stmt_limit->bindValue(':base_restaurante_id', $restaurantePlanId, PDO::PARAM_INT);
        $stmt_limit->bindValue(':filial_base_id', $restaurantePlanId, PDO::PARAM_INT);
    } else {
        $stmt_limit = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE restaurante_id = :rid AND ativo = 1");
        $stmt_limit->bindValue(':rid', $restauranteId, PDO::PARAM_INT);
    }
    $stmt_limit->execute();
    $totalUsuariosAtivos = (int)$stmt_limit->fetchColumn();

    $verificacaoPlano = plano_verificar_limite_db($restaurantePlanId > 0 ? $restaurantePlanId : $restauranteId, 'usuarios', $totalUsuariosAtivos);
    if (!$verificacaoPlano['permitido']) {
        usuario_cadastrar_json([
            "success" => false,
            "code" => "LIMITE_EXCEDIDO",
            "message" => function_exists('plano_mensagem_limite_atingido')
                ? plano_mensagem_limite_atingido($verificacaoPlano, 'utilizadores')
                : "Limite do plano atingido. O plano {$verificacaoPlano['plano']} permite até {$verificacaoPlano['limite']} usuários.",
            "upgrade_url" => "configuracoes.php?secao=plano",
            "limite" => $verificacaoPlano['limite'] ?? null,
            "atual" => $verificacaoPlano['atual'] ?? null,
        ]);
    }

    // Processar upload de foto
    $foto = '';
    if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
        // novo diretório público de imagens (relative à pasta api)
        $upload_dir = __DIR__ . '/../images/';

        // Criar diretório se não existir
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $allowed_ext = ['png', 'jpg', 'jpeg'];
        $allowed_mime = ['image/png', 'image/jpeg'];
        $upload_error = null;
        if (security_validate_upload($_FILES['foto'], $allowed_ext, $allowed_mime, 2 * 1024 * 1024, $upload_error)) {
            $safe_name = security_random_filename(pathinfo((string)$_FILES['foto']['name'], PATHINFO_EXTENSION));
            if (move_uploaded_file($_FILES['foto']['tmp_name'], $upload_dir . $safe_name)) {
                require_once APP_BASE . '/src/config/imagem_otimizar.php';
                imagem_otimizar($upload_dir . $safe_name, 512); // foto de perfil
                $foto = 'images/' . $safe_name;
            }
        }
    }

    // Hash da senha
    $senha_hash = password_hash((string)$_POST['senha'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);

    // Inserir usuário
    $query = "INSERT INTO usuarios (restaurante_id, nome, email, email_lookup, senha, perfil, ativo, foto, two_factor_email) 
              VALUES (:rid, :nome, :email, :email_lookup, :senha, :perfil, 1, :foto, :two_factor_email)";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':rid', $restauranteId, PDO::PARAM_INT);
    $stmt->bindParam(':nome', $_POST['nome']);
    $stmt->bindValue(':email', $emailEncrypted, PDO::PARAM_STR);
    $stmt->bindValue(':email_lookup', $emailLookup, PDO::PARAM_STR);
    $stmt->bindParam(':senha', $senha_hash);
    $stmt->bindValue(':perfil', $perfil);
    $stmt->bindParam(':foto', $foto);
    $stmt->bindValue(':two_factor_email', $emailEncrypted, PDO::PARAM_STR);

    try {
        if ($stmt->execute() === false) {
            $errorInfo = $stmt->errorInfo();
            throw new RuntimeException('Erro ao executar insert de usuario: ' . ($errorInfo[2] ?? json_encode($errorInfo)));
        }

        $novoId = (int)$db->lastInsertId();

        // Evita sucesso falso quando o banco usa ENUM antigo e grava perfil vazio.
        $stmtPerfil = $db->prepare("SELECT perfil FROM usuarios WHERE id = :id LIMIT 1");
        $stmtPerfil->bindValue(':id', $novoId, PDO::PARAM_INT);
        $stmtPerfil->execute();
        $perfilSalvo = strtoupper(trim((string)($stmtPerfil->fetchColumn() ?: '')));

        if ($perfilSalvo !== $perfil) {
            usuario_cadastrar_json(["success" => false, "message" => "Perfil não foi salvo corretamente no banco. Verifique se a coluna usuarios.perfil aceita este valor (ENUM/VARCHAR)."], 500);
        }

        audit_log($db, 'usuario_cadastrado', [
            'novo_usuario_id' => $novoId,
            'perfil' => $perfil,
            'restaurante_id' => $restauranteId,
        ]);
        usuario_cadastrar_json(["success" => true, "message" => "Usuário cadastrado com sucesso!"]);
    } catch (Throwable $e) {
        error_log('usuario_cadastrar: ' . $e->getMessage());
        $message = 'Erro ao cadastrar usuário.';
        if (function_exists('security_is_production') && !security_is_production()) {
            $message .= ' ' . $e->getMessage();
        }
        usuario_cadastrar_json(["success" => false, "message" => $message], 500);
    }
} else {
    usuario_cadastrar_json(["success" => false, "message" => "Método não permitido"], 405);
}
