<?php
require_once __DIR__ . '/../app_base.php';
require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/plano_check.php';
require_once APP_BASE . '/src/config/restaurante_context.php';
require_once APP_BASE . '/src/config/promocao_schema.php';

header('Content-Type: application/json; charset=utf-8');
api_guard(['roles' => ['ADMIN', 'GERENTE'], 'rate_key' => 'promocao_salvar', 'rate_max' => 40, 'rate_window' => 60]);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo nao permitido']);
    exit;
}

$rid = session_restaurante_contexto_id();
$planRid = session_restaurante_capability_id() ?: $rid;
$plano = plano_normalizar_nome((plano_get_dados($planRid)['plano_nome'] ?? 'BASICO'));
if (!promocao_plano_permitido($plano)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Promocoes disponiveis nos planos Profissional e Empresarial.']);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$nome = trim((string)($_POST['nome'] ?? ''));
$descricao = trim((string)($_POST['descricao'] ?? ''));
$preco = (float)str_replace(',', '.', (string)($_POST['preco_promocional'] ?? '0'));
$inicio = trim((string)($_POST['inicio_em'] ?? ''));
$fim = trim((string)($_POST['fim_em'] ?? ''));
$ativo = (int)($_POST['ativo'] ?? 0) === 1 ? 1 : 0;
$itens = json_decode((string)($_POST['itens'] ?? '[]'), true);
$itens = is_array($itens) ? $itens : [];

$nomeLength = function_exists('mb_strlen') ? mb_strlen($nome, 'UTF-8') : strlen($nome);
if ($nome === '' || $nomeLength > 150 || $preco <= 0 || count($itens) < 2) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Informe nome, preco e pelo menos dois produtos.']);
    exit;
}
try {
    $inicioUtc = promocao_data_local_para_utc($inicio);
    $fimUtc = promocao_data_local_para_utc($fim);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Periodo de validade invalido.']);
    exit;
}
if ($inicioUtc !== null && $fimUtc !== null && $fimUtc <= $inicioUtc) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'O fim deve ser posterior ao inicio.']);
    exit;
}

$normalizados = [];
foreach ($itens as $item) {
    $produtoId = (int)($item['produto_id'] ?? 0);
    $qtd = max(1, min(100, (int)($item['quantidade'] ?? 1)));
    if ($produtoId > 0) $normalizados[$produtoId] = $qtd;
}
if (count($normalizados) < 2) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Selecione pelo menos dois produtos diferentes.']);
    exit;
}

$db = (new Database())->getConnection();
promocao_schema_garantir($db);
try {
    $in = implode(',', array_fill(0, count($normalizados), '?'));
    $check = $db->prepare("SELECT id FROM produtos WHERE restaurante_id = ? AND ativo = 1 AND id IN ($in)");
    $check->execute(array_merge([$rid], array_keys($normalizados)));
    if (count($check->fetchAll(PDO::FETCH_COLUMN)) !== count($normalizados)) {
        throw new DomainException('Um dos produtos nao pertence ao restaurante ou esta inativo.');
    }
    // Uma promoção tem de ficar mais barata do que comprar os produtos em separado.
    $stSoma = $db->prepare("SELECT id, preco FROM produtos WHERE restaurante_id = ? AND id IN ($in)");
    $stSoma->execute(array_merge([$rid], array_keys($normalizados)));
    $somaSeparado = 0.0;
    foreach ($stSoma->fetchAll(PDO::FETCH_ASSOC) as $rowSoma) {
        $somaSeparado += (float)$rowSoma['preco'] * (int)($normalizados[(int)$rowSoma['id']] ?? 1);
    }
    if ($preco >= round($somaSeparado, 2) - 0.001) {
        throw new DomainException(sprintf('O preço promocional (%s MZN) tem de ser menor do que comprar os produtos em separado (%s MZN).',
            number_format($preco, 2, ',', '.'), number_format($somaSeparado, 2, ',', '.')));
    }

    $db->beginTransaction();
    $params = [
        'rid' => $rid, 'nome' => $nome, 'descricao' => $descricao !== '' ? $descricao : null,
        'preco' => round($preco, 2), 'inicio' => $inicioUtc,
        'fim' => $fimUtc, 'ativo' => $ativo,
    ];
    if ($id > 0) {
        $params['id'] = $id;
        $stmt = $db->prepare("UPDATE promocoes SET nome=:nome, descricao=:descricao, preco_promocional=:preco,
            inicio_em=:inicio, fim_em=:fim, ativo=:ativo, horarios_utc=1 WHERE id=:id AND restaurante_id=:rid");
        $stmt->execute($params);
        if ($stmt->rowCount() === 0) {
            $exists = $db->prepare('SELECT id FROM promocoes WHERE id=:id AND restaurante_id=:rid');
            $exists->execute(['id' => $id, 'rid' => $rid]);
            if (!$exists->fetchColumn()) throw new DomainException('Promocao nao encontrada.');
        }
        $db->prepare('DELETE FROM promocao_itens WHERE promocao_id = :id')->execute(['id' => $id]);
    } else {
        $stmt = $db->prepare("INSERT INTO promocoes
            (restaurante_id,nome,descricao,preco_promocional,inicio_em,fim_em,ativo)
            VALUES (:rid,:nome,:descricao,:preco,:inicio,:fim,:ativo)");
        $stmt->execute($params);
        $id = (int)$db->lastInsertId();
    }
    $insert = $db->prepare('INSERT INTO promocao_itens (promocao_id,produto_id,quantidade) VALUES (:promo,:produto,:qtd)');
    foreach ($normalizados as $produtoId => $qtd) {
        $insert->execute(['promo' => $id, 'produto' => $produtoId, 'qtd' => $qtd]);
    }
    $db->commit();
    echo json_encode(['success' => true, 'message' => 'Promocao guardada.', 'id' => $id], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('[promocao_salvar] ' . $e->getMessage());
    http_response_code($e instanceof DomainException ? 422 : 500);
    echo json_encode(['success' => false, 'message' => $e instanceof DomainException ? $e->getMessage() : 'Nao foi possivel guardar a promocao.'], JSON_UNESCAPED_UNICODE);
}
