<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Solicitar Renovacao/Alteracao de Plano (manual guiada)
   Cria uma compra pendente para aprovacao do super admin.
*/

include_once APP_BASE . '/src/config/api_guard.php';
include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/plano_notificacoes.php';
include_once APP_BASE . '/src/config/plano_upgrade_guard.php';
include_once APP_BASE . '/src/config/plan_security.php';
include_once APP_BASE . '/src/config/audit.php';
include_once APP_BASE . '/src/config/plano_lifecycle.php';
$planos_config = require APP_BASE . '/src/config/planos.php';

header('Content-Type: application/json');
@ignore_user_abort(true);
@set_time_limit(90);

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    security_json(["success" => false, "message" => "Metodo nao permitido"], 405);
}

$ctx = api_guard([
    'rate_key' => 'plano_solicitar_renovacao',
    'rate_max' => 20,
    'rate_window' => 300,
    'skip_turno' => true,
]);

$sessaoUsuarioId = (int)$ctx['usuario_id'];

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$plano_novo = strtoupper(trim((string)($_POST['plano'] ?? '')));
$ciclo = strtoupper(trim((string)($_POST['ciclo'] ?? 'MENSAL')));
$metodo = strtoupper(trim((string)($_POST['metodo'] ?? '')));
$arquivo_comprovativo = $_FILES['comprovativo'] ?? null;
$extensao = '';
$caminho_relativo = '';
$hashArquivo = '';

$planos_validos = ['BASICO', 'PROFISSIONAL', 'EMPRESARIAL'];
$ciclos_validos = ['MENSAL', 'TRIMESTRAL', 'ANUAL'];
$metodos_validos = ['DINHEIRO', 'MPESA', 'EMOLA', 'CARTAO', 'TRANSFERENCIA', 'TRIAL'];

if (!in_array($plano_novo, $planos_validos, true)) {
    security_json(["success" => false, "message" => "Plano invalido"], 422);
}

if (!in_array($ciclo, $ciclos_validos, true)) {
    security_json(["success" => false, "message" => "Ciclo invalido"], 422);
}

if (!in_array($metodo, $metodos_validos, true)) {
    security_json(["success" => false, "message" => "Metodo de pagamento invalido"], 422);
}

$isTrialRequest = $metodo === 'TRIAL';
if ($isTrialRequest) {
    if ($plano_novo !== 'BASICO' || !plan_security_supports_trial($plano_novo)) {
        security_json(["success" => false, "message" => "Trial disponivel apenas para o plano Basico"], 422);
    }
    if ($ciclo !== 'MENSAL') {
        security_json(["success" => false, "message" => "Trial so esta disponivel para ciclo Mensal"], 422);
    }
    $valor = 0.0;
} else {
    $arquivo_ok = isset($arquivo_comprovativo['error']) && $arquivo_comprovativo['error'] === UPLOAD_ERR_OK;
    if (!$arquivo_ok) {
        security_json(["success" => false, "message" => "Comprovativo obrigatorio"], 422);
    }

    // O valor e calculado mais abaixo, assim que se sabe de que restaurante se
    // trata: o preco pode trazer o desconto de lancamento preso a esse cliente.
    $valor = 0.0;

    $extensoes_permitidas = ['jpg', 'jpeg', 'png', 'pdf'];
    $mimes_permitidos = ['image/jpeg', 'image/png', 'application/pdf'];
    $max_tamanho = 5 * 1024 * 1024;
    $extensao = strtolower(pathinfo($arquivo_comprovativo['name'] ?? '', PATHINFO_EXTENSION));

    if (!in_array($extensao, $extensoes_permitidas, true)) {
        security_json(["success" => false, "message" => "Comprovativo invalido. Use JPG, PNG ou PDF"], 422);
    }

    if (($arquivo_comprovativo['size'] ?? 0) > $max_tamanho) {
        security_json(["success" => false, "message" => "Comprovativo excede 5MB"], 422);
    }

    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $arquivo_comprovativo['tmp_name']);
            finfo_close($finfo);
        }
    }

    if ($mime === '' && function_exists('mime_content_type')) {
        $mime = mime_content_type($arquivo_comprovativo['tmp_name']) ?: '';
    }

    if ($mime !== '' && !in_array($mime, $mimes_permitidos, true)) {
        security_json(["success" => false, "message" => "Tipo de arquivo invalido para comprovativo"], 422);
    }
}

$database = new Database();
$db = $database->getConnection();
plan_security_ensure_purchase_hardening_columns($db);

if (!$db) {
    security_json(["success" => false, "message" => "Erro de conexao"], 500);
}

$restaurante_plano_id = plano_upgrade_restaurante_alvo_id($db);
if (!plano_upgrade_usuario_pode_gerir($db, $sessaoUsuarioId, $restaurante_plano_id)) {
    security_json(["success" => false, "message" => "Apenas o dono/administrador principal do restaurante pode fazer upgrade de planos."], 403);
}

// Preco a cobrar, ja com o desconto de lancamento deste restaurante (se o tiver).
if (!$isTrialRequest) {
    $valor = plan_security_price($plano_novo, $ciclo, $db, $restaurante_plano_id);
    if ($valor <= 0) {
        security_json(["success" => false, "message" => "Preco invalido para plano/ciclo selecionado"], 422);
    }
}

try {
    $stmtRest = $db->prepare("SELECT id, nome, email, telefone, plano FROM restaurantes WHERE id = ?");
    $stmtRest->execute([$restaurante_plano_id]);
    $restaurante = $stmtRest->fetch(PDO::FETCH_ASSOC);

    if (!$restaurante) {
        security_json(["success" => false, "message" => "Restaurante nao encontrado"], 404);
    }

    $stmtPend = $db->prepare("SELECT COUNT(*) FROM compras_planos WHERE restaurante_id = ? AND status IN ('AGUARDANDO_APROVACAO', 'PENDENTE')");
    $stmtPend->execute([$restaurante_plano_id]);
    if ((int)$stmtPend->fetchColumn() > 0) {
        security_json(["success" => false, "message" => "Ja existe uma solicitacao pendente. Aguarde a analise do super admin."], 409);
    }

    $colunasCompra = [];
    $stmtCols = $db->query('SHOW COLUMNS FROM compras_planos');
    while ($col = $stmtCols->fetch(PDO::FETCH_ASSOC)) {
        $colunasCompra[] = $col['Field'];
    }

    $statusPendenteCompra = plan_security_purchase_status_value($db, 'PENDING');

    if (!$isTrialRequest) {
        $dir_upload_absoluto = __DIR__ . '/../uploads/comprovativos';
        if (!is_dir($dir_upload_absoluto) && !mkdir($dir_upload_absoluto, 0755, true)) {
            security_json(["success" => false, "message" => "Nao foi possivel preparar diretorio de comprovativos"], 500);
        }

        $nome_comprovativo = sprintf(
            'comp_%s_%s.%s',
            date('YmdHis'),
            bin2hex(random_bytes(6)),
            $extensao
        );

        $caminho_absoluto = $dir_upload_absoluto . '/' . $nome_comprovativo;
        $caminho_relativo = 'uploads/comprovativos/' . $nome_comprovativo;

        $uploadError = null;
        if (!security_validate_upload(
            $arquivo_comprovativo,
            ['png', 'jpg', 'jpeg', 'pdf'],
            ['image/png', 'image/jpeg', 'application/pdf'],
            5 * 1024 * 1024,
            $uploadError
        )) {
            security_json(["success" => false, "message" => $uploadError ?: "Arquivo invalido."], 422);
        }

        if (!move_uploaded_file($arquivo_comprovativo['tmp_name'], $caminho_absoluto)) {
            security_json(["success" => false, "message" => "Falha ao salvar comprovativo"], 500);
        }

        $hashArquivo = plan_security_file_sha256($caminho_absoluto);
        if ($hashArquivo === '') {
            @unlink($caminho_absoluto);
            security_json(["success" => false, "message" => "Falha ao validar comprovativo."], 500);
        }

        $duplicado = plan_security_purchase_find_duplicate_proof($db, $hashArquivo, (int)$restaurante_plano_id);
        if ($duplicado) {
            @unlink($caminho_absoluto);
            audit_log($db, 'plano_renovacao_comprovativo_duplicado_bloqueado', [
                'restaurante_id' => (int)$restaurante_plano_id,
                'hash_arquivo' => $hashArquivo,
                'compra_existente_id' => (int)($duplicado['id'] ?? 0),
                'restaurante_existente_id' => (int)($duplicado['restaurante_id'] ?? 0),
            ], 'WARN');
            security_json(["success" => false, "message" => "Comprovativo ja utilizado em outra solicitacao."], 409);
        }
    }

    $insertCols = ['restaurante_id', 'plano_atual', 'plano_novo', 'valor', 'metodo_pagamento', 'status'];
    $insertVals = ['?', '?', '?', '?', '?', '?'];
    $params = [$restaurante_plano_id, $restaurante['plano'] ?? 'BASICO', $plano_novo, $valor, $metodo, $statusPendenteCompra];

    if (in_array('ciclo', $colunasCompra, true)) {
        $insertCols[] = 'ciclo';
        $insertVals[] = '?';
        $params[] = $ciclo;
    }

    if (!$isTrialRequest) {
        if (in_array('comprovativo_path', $colunasCompra, true)) {
            $insertCols[] = 'comprovativo_path';
            $insertVals[] = '?';
            $params[] = $caminho_relativo;
        }

        if (in_array('hash_arquivo', $colunasCompra, true)) {
            $insertCols[] = 'hash_arquivo';
            $insertVals[] = '?';
            $params[] = $hashArquivo;
        }
    }

    if (in_array('observacao', $colunasCompra, true)) {
        $insertCols[] = 'observacao';
        $insertVals[] = '?';
        $params[] = $isTrialRequest
            ? 'Solicitacao de trial Basico (30 dias)'
            : 'Renovacao manual guiada | Hash: ' . substr($hashArquivo, 0, 16);
    }

    if (in_array('created_at', $colunasCompra, true)) {
        $insertCols[] = 'created_at';
        $insertVals[] = 'NOW()';
    } elseif (in_array('criado_em', $colunasCompra, true)) {
        $insertCols[] = 'criado_em';
        $insertVals[] = 'NOW()';
    }

    $stmtIns = $db->prepare('INSERT INTO compras_planos (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $insertVals) . ')');
    $stmtIns->execute($params);
    $compraId = (int)$db->lastInsertId();

    audit_log($db, 'plano_renovacao_solicitada', [
        'compra_id' => $compraId,
        'restaurante_id' => $restaurante_plano_id,
        'plano' => $plano_novo,
        'ciclo' => $ciclo,
        'valor' => $valor,
        'metodo' => $metodo,
    ]);
    plano_lifecycle_log($db, (int)$restaurante_plano_id, 'tentativa_pagamento', [
        'compra_id' => $compraId,
        'tipo' => 'solicitacao_renovacao',
        'plano_novo' => $plano_novo,
        'ciclo' => $ciclo,
        'valor' => $valor,
        'metodo' => $metodo,
    ]);

    security_json([
        "success" => true,
        "message" => "Pedido de renovacao enviado com sucesso! Aguarde aprovacao do super admin.",
        "data" => [
            "plano" => $plano_novo,
            "ciclo" => $ciclo,
            "status" => $statusPendenteCompra
        ]
    ]);
} catch (Throwable $e) {
    error_log('plano_solicitar_renovacao: ' . $e->getMessage());
    security_json(["success" => false, "message" => "Erro ao solicitar renovacao."], 500);
}
