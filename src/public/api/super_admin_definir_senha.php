<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Super Admin Definir Senha do Restaurante
 */

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/csrf.php';
require_once APP_BASE . '/src/config/audit.php';
require_once APP_BASE . '/src/config/super_admin_permissions.php';
require_once APP_BASE . '/src/config/password_policy.php';

header('Content-Type: application/json');

api_guard([
    'rate_key' => 'super_admin_definir_senha',
    'rate_max' => 20,
    'rate_window' => 60,
    'skip_turno' => true,
]);

if (!(isset($_SESSION['logado'], $_SESSION['super_admin'])
    && $_SESSION['logado'] === true
    && intval($_SESSION['super_admin']) === 1)) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Acesso negado"]);
    exit;
}

super_admin_require_permission_json('manage_restaurants');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $restaurante_id = intval($_POST['restaurante_id'] ?? 0);
    $senha = trim($_POST['senha'] ?? '');

    if ($restaurante_id <= 0) {
        echo json_encode(["success" => false, "message" => "ID do restaurante invalido"]);
        exit;
    }

    $pwdCheck = password_policy_validate($senha, ['privileged' => true, 'perfil' => 'ADMIN']);
    if (!$pwdCheck['ok']) {
        echo json_encode(["success" => false, "message" => $pwdCheck['message']]);
        exit;
    }

    $database = new Database();
    $db = $database->getConnection();

    if (!$db) {
        echo json_encode(["success" => false, "message" => "Erro de conexao"]);
        exit;
    }

    try {
        $stmt = $db->prepare("SELECT nome, senha_admin, senha_criada_em, status FROM restaurantes WHERE id = ?");
        $stmt->execute([$restaurante_id]);
        $restaurante = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$restaurante) {
            echo json_encode(["success" => false, "message" => "Restaurante nao encontrado"]);
            exit;
        }

        if ($restaurante['status'] !== 'ATIVO') {
            $novaDataFim = (new DateTime())->modify('+1 hour')->format('Y-m-d H:i:s');
            $stmt = $db->prepare("UPDATE restaurantes SET status = 'ATIVO', data_fim = :data_fim WHERE id = :id");
            $stmt->bindValue(':data_fim', $novaDataFim);
            $stmt->bindValue(':id', $restaurante_id, PDO::PARAM_INT);
            $stmt->execute();
        }

        if ($restaurante['senha_admin'] && $restaurante['senha_criada_em']) {
            $data_criacao = new DateTime($restaurante['senha_criada_em']);
            $agora = new DateTime();
            $diferenca = $agora->diff($data_criacao);
            $minutos_passados = ($diferenca->days * 24 * 60) + ($diferenca->h * 60) + $diferenca->i;

            if ($minutos_passados < 20) {
                echo json_encode([
                    "success" => false,
                    "message" => "A senha foi criada recentemente. Aguarde " . (20 - $minutos_passados) . " minutos para alterar novamente."
                ]);
                exit;
            }
        }

        $senha_hash = password_hash((string)$senha, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);

        $stmt = $db->prepare("UPDATE restaurantes SET senha_admin = ?, senha_criada_em = NOW(), senha_pode_alterar = 1 WHERE id = ?");
        $stmt->execute([$senha_hash, $restaurante_id]);
        audit_log($db, 'super_admin_senha_restaurante_definida', [
            'restaurante_id' => $restaurante_id,
        ]);

        echo json_encode([
            "success" => true,
            "message" => "Senha definida com sucesso para " . $restaurante['nome'] . "! O restaurante foi ativado e pode fazer login.",
            "data" => [
                "restaurante" => $restaurante['nome'],
                "proxima_alteracao" => date('H:i', strtotime('+20 minutes'))
            ]
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => security_exception_message(
                $e,
                'Erro ao definir senha.',
                'super_admin_definir_senha_failed'
            ),
        ]);
    }
} else {
    echo json_encode(["success" => false, "message" => "Metodo nao permitido"]);
}
