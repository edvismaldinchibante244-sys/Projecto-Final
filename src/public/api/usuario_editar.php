<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Editar Usuário
 */

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/audit.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/plano_check.php';
require_once APP_BASE . '/src/config/restaurante_context.php';
require_once APP_BASE . '/src/config/usuario_perfil_helper.php';
require_once APP_BASE . '/src/config/password_policy.php';

header('Content-Type: application/json');

api_guard([
    'roles' => ['ADMIN'],
    'rate_key' => 'usuario_editar',
    'rate_max' => 60,
    'rate_window' => 60,
    'skip_turno' => true,
]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $restauranteId = session_restaurante_contexto_id();
    $restaurantePlanId = session_restaurante_capability_id();

    if ($restauranteId <= 0 || $_SESSION['perfil'] !== 'ADMIN') {
        echo json_encode(array("success" => false, "message" => "Sem permissão"));
        exit;
    }

    if (empty($_POST['usuario_id']) || empty($_POST['nome']) || empty($_POST['email']) || empty($_POST['perfil'])) {
        echo json_encode(array("success" => false, "message" => "Preencha todos os campos obrigatórios"));
        exit;
    }

    $perfil = usuario_perfil_normalizar($_POST['perfil'] ?? '');
    $planIdGate = $restaurantePlanId > 0 ? $restaurantePlanId : $restauranteId;

    if (!in_array($perfil, usuario_perfis_base(), true)) {
        echo json_encode(array("success" => false, "message" => "Perfil inválido"));
        exit;
    }

    $database = new Database();
    $db = $database->getConnection();
    usuarios_garantir_perfil_aceito($db, $perfil);

    // Bloqueia mudança para perfis de plano superior; permite manter perfil já existente.
    if (!usuario_perfil_disponivel_no_plano($planIdGate, $perfil)) {
        $usuarioIdCheck = (int)($_POST['usuario_id'] ?? 0);
        $perfilAtual = '';
        if ($usuarioIdCheck > 0) {
            $stmtPerfilAtual = $db->prepare("SELECT perfil FROM usuarios WHERE id = :id AND restaurante_id = :rid LIMIT 1");
            $stmtPerfilAtual->bindValue(':id', $usuarioIdCheck, PDO::PARAM_INT);
            $stmtPerfilAtual->bindValue(':rid', $restauranteId, PDO::PARAM_INT);
            $stmtPerfilAtual->execute();
            $perfilAtual = usuario_perfil_normalizar((string)($stmtPerfilAtual->fetchColumn() ?: ''));
        }
        if ($perfilAtual !== $perfil) {
            $upgrade = function_exists('plano_proximo_upgrade') ? plano_proximo_upgrade('BASICO') : null;
            if ($perfil === 'GERENTE') {
                $msg = 'Perfil Gerente disponível nos planos Profissional e Empresarial.';
            } else {
                $msg = 'Perfis Cozinha e Bar não estão disponíveis no plano Básico. Use Garçom, Caixa ou Admin.';
            }
            if (is_array($upgrade) && !empty($upgrade['nome'])) {
                $msg .= ' Faça upgrade para ' . $upgrade['nome'] . '.';
            }
            echo json_encode([
                "success" => false,
                "code" => "FEATURE_BLOQUEADA",
                "message" => $msg,
                "upgrade_url" => "configuracoes.php?secao=plano",
            ]);
            exit;
        }
    }

    // Verificar se a conexão foi estabelecida
    if (!$db) {
        echo json_encode(array("success" => false, "message" => "Erro de conexão com o banco de dados"));
        exit;
    }

    $ativo = isset($_POST['ativo']) ? 1 : 0;
    $id    = intval($_POST['usuario_id']);
    $sessionUserId = (int)($_SESSION['usuario_id'] ?? 0);
    $isSelfEdit = ($sessionUserId > 0 && $id === $sessionUserId);

    // Dono/admin não pode rebaixar nem desactivar a própria conta.
    if ($isSelfEdit) {
        $ativo = 1;
        $stmtSelf = $db->prepare("SELECT perfil FROM usuarios WHERE id = :id AND restaurante_id = :rid LIMIT 1");
        $stmtSelf->bindValue(':id', $id, PDO::PARAM_INT);
        $stmtSelf->bindValue(':rid', $restauranteId, PDO::PARAM_INT);
        $stmtSelf->execute();
        $perfilSelf = usuario_perfil_normalizar((string)($stmtSelf->fetchColumn() ?: ''));
        if ($perfilSelf !== '') {
            $perfil = $perfilSelf;
        }
    }

    $temColunaSuperAdmin = false;
    try {
        $colStmt = $db->query("SHOW COLUMNS FROM usuarios LIKE 'super_admin'");
        $temColunaSuperAdmin = $colStmt && $colStmt->fetch(PDO::FETCH_ASSOC) ? true : false;
    } catch (Throwable $e) {
        $temColunaSuperAdmin = false;
    }

    if ($temColunaSuperAdmin) {
        $stmtTarget = $db->prepare("SELECT COALESCE(super_admin, 0) AS super_admin FROM usuarios WHERE id = :id AND restaurante_id = :rid LIMIT 1");
        $stmtTarget->bindValue(':id', $id, PDO::PARAM_INT);
        $stmtTarget->bindValue(':rid', $restauranteId, PDO::PARAM_INT);
        $stmtTarget->execute();
        $target = $stmtTarget->fetch(PDO::FETCH_ASSOC);
        if (!$target) {
            echo json_encode(array("success" => false, "message" => "Usuario nao encontrado"));
            exit;
        }
        if ((int)($target['super_admin'] ?? 0) === 1) {
            echo json_encode(array("success" => false, "message" => "Super Admin/Fundador nao pode ser editado por este modulo"));
            exit;
        }
    }

    // Verificar se email já existe em outro usuário
    $email = trim((string)$_POST['email']);
    $emailLookup = personal_data_email_lookup($email);
    $emailEncrypted = personal_data_encrypt($email);
    $query_check = "SELECT id FROM usuarios WHERE email_lookup = :email_lookup AND id != :id LIMIT 1";
    $stmt_check  = $db->prepare($query_check);
    $stmt_check->bindValue(':email_lookup', $emailLookup, PDO::PARAM_STR);
    $stmt_check->bindParam(':id', $id);
    $stmt_check->execute();

    if ($stmt_check->rowCount() > 0) {
        echo json_encode(array("success" => false, "message" => "Este email já está em uso por outro usuário"));
        exit;
    }

    // Processar upload de foto - PNG e JPEG
    $foto = '';
    if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
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

    // Atualizar dados básicos
    if (!empty($foto)) {
        // Com foto nova
        $query = "UPDATE usuarios SET nome = :nome, email = :email, email_lookup = :email_lookup, two_factor_email = :two_factor_email, perfil = :perfil, ativo = :ativo, foto = :foto
                  WHERE id = :id AND restaurante_id = :rid" . ($temColunaSuperAdmin ? " AND COALESCE(super_admin, 0) = 0" : "");
        $stmt  = $db->prepare($query);
        $stmt->bindParam(':foto', $foto);
    } else {
        // Mantém foto atual
        $query = "UPDATE usuarios SET nome = :nome, email = :email, email_lookup = :email_lookup, two_factor_email = :two_factor_email, perfil = :perfil, ativo = :ativo
                  WHERE id = :id AND restaurante_id = :rid" . ($temColunaSuperAdmin ? " AND COALESCE(super_admin, 0) = 0" : "");
        $stmt  = $db->prepare($query);
    }

    $stmt->bindParam(':nome',   $_POST['nome']);
    $stmt->bindValue(':email', $emailEncrypted, PDO::PARAM_STR);
    $stmt->bindValue(':email_lookup', $emailLookup, PDO::PARAM_STR);
    $stmt->bindValue(':two_factor_email', $emailEncrypted, PDO::PARAM_STR);
    $stmt->bindValue(':perfil', $perfil);
    $stmt->bindParam(':ativo',  $ativo, PDO::PARAM_INT);
    $stmt->bindParam(':id',     $id, PDO::PARAM_INT);
    $stmt->bindValue(':rid',    $restauranteId, PDO::PARAM_INT);

    if ($stmt->execute()) {
        // Evita sucesso falso quando o banco usa ENUM antigo e grava perfil vazio.
        $stmtPerfil = $db->prepare("SELECT perfil FROM usuarios WHERE id = :id AND restaurante_id = :rid LIMIT 1");
        $stmtPerfil->bindValue(':id', $id, PDO::PARAM_INT);
        $stmtPerfil->bindValue(':rid', $restauranteId, PDO::PARAM_INT);
        $stmtPerfil->execute();
        $perfilSalvo = strtoupper(trim((string)($stmtPerfil->fetchColumn() ?: '')));

        if ($perfilSalvo !== $perfil) {
            echo json_encode(array(
                "success" => false,
                "message" => "Perfil não foi salvo corretamente no banco. Verifique se a coluna usuarios.perfil aceita este valor (ENUM/VARCHAR)."
            ));
            exit;
        }

        // Atualizar senha se fornecida
        if (!empty($_POST['senha'])) {
            $pwdCheck = password_policy_validate((string)$_POST['senha'], [
                'email' => (string)($_POST['email'] ?? ''),
                'nome' => (string)($_POST['nome'] ?? ''),
                'perfil' => (string)($_POST['perfil'] ?? ''),
            ]);
            if (!$pwdCheck['ok']) {
                echo json_encode(array("success" => false, "message" => $pwdCheck['message']));
                exit;
            }
            $senha_hash  = password_hash((string)$_POST['senha'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
            $query_senha = "UPDATE usuarios SET senha = :senha WHERE id = :id";
            $stmt_senha  = $db->prepare($query_senha);
            $stmt_senha->bindParam(':senha', $senha_hash);
            $stmt_senha->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt_senha->execute();
        }
        audit_log($db, 'usuario_atualizado', [
            'usuario_alvo_id' => $id,
            'perfil' => $perfil,
            'ativo' => $ativo,
            'senha_alterada' => !empty($_POST['senha']),
            'self_edit' => $isSelfEdit,
        ]);

        if ($isSelfEdit) {
            $_SESSION['nome'] = trim((string)$_POST['nome']);
            $_SESSION['email'] = trim((string)$_POST['email']);
            if (!empty($foto)) {
                $_SESSION['foto'] = $foto;
            }
        }

        echo json_encode(array("success" => true, "message" => $isSelfEdit ? "Perfil atualizado com sucesso!" : "Usuário atualizado com sucesso!"));
    } else {
        echo json_encode(array("success" => false, "message" => "Erro ao atualizar usuário"));
    }
} else {
    echo json_encode(array("success" => false, "message" => "Método não permitido"));
}
