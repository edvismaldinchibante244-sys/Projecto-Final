<?php
/**
 * Ingredientes (versao simples). Acoes por POST "acao":
 *  listar | criar | entrada | acertar | arquivar | pratos | receita | receita_salvar
 * Leitura: ADMIN, GERENTE, CAIXA, COZINHA, BAR. Alteracoes: ADMIN, GERENTE.
 */
require_once __DIR__ . '/../app_base.php';
require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/audit.php';
require_once APP_BASE . '/src/config/ingredientes_simples.php';

header('Content-Type: application/json; charset=utf-8');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    security_json(['success' => false, 'message' => 'Método não permitido.'], 405);
}
$ctx = api_guard([
    'roles' => ['ADMIN', 'GERENTE', 'CAIXA', 'COZINHA', 'BAR'],
    'rate_key' => 'ingredientes',
    'rate_max' => 120,
    'rate_window' => 60,
    'skip_turno' => true,
]);
$rid = (int)$ctx['restaurante_id'];
$planoRid = function_exists('session_restaurante_capability_id') ? (int)session_restaurante_capability_id() : $rid;
$planoRid = $planoRid > 0 ? $planoRid : $rid;
if (!plano_tem_ingredientes($planoRid)) {
    security_json(['success' => false, 'message' => 'O controlo de ingredientes não está disponível no seu plano.'], 403);
}

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) {
    $in = $_POST;
}
$acao = (string)($in['acao'] ?? 'listar');
$num = static fn($v) => (float)str_replace(',', '.', (string)($v ?? 0));
$podeAlterar = in_array($ctx['perfil'], ['ADMIN', 'GERENTE'], true) || $ctx['super_admin'];
if (!in_array($acao, ['listar', 'pratos', 'receita'], true) && !$podeAlterar) {
    security_json(['success' => false, 'message' => 'Só o administrador ou o gerente podem alterar ingredientes.'], 403);
}

try {
    $db = (new Database())->getConnection();
    estoque_inteligente_schema_garantir($db);
    $uid = (int)$ctx['usuario_id'];
    $lista = static function () use ($db, $rid, $planoRid) {
        return ['ingredientes' => ing_listar($db, $rid), 'limite' => ing_limite($planoRid)];
    };

    switch ($acao) {
        case 'listar':
            security_json(['success' => true] + $lista());
            break;
        case 'criar':
            $id = ing_criar($db, $rid, $planoRid, (string)($in['nome'] ?? ''), (string)($in['unidade'] ?? ''), $num($in['quantidade'] ?? 0), $num($in['minimo'] ?? 0), $uid);
            audit_log($db, 'ingrediente_criado', ['insumo_id' => $id, 'restaurante_id' => $rid]);
            security_json(['success' => true, 'message' => 'Ingrediente adicionado.', 'id' => $id] + $lista());
            break;
        case 'entrada':
            ing_entrada($db, $rid, (int)($in['id'] ?? 0), $num($in['quantidade'] ?? 0), (string)($in['unidade'] ?? ''), $uid);
            security_json(['success' => true, 'message' => 'Entrada registada.'] + $lista());
            break;
        case 'acertar':
            $minimo = isset($in['minimo']) && $in['minimo'] !== '' ? $num($in['minimo']) : null;
            ing_acertar($db, $rid, (int)($in['id'] ?? 0), $num($in['quantidade'] ?? 0), (string)($in['unidade'] ?? ''), $minimo, $uid);
            audit_log($db, 'ingrediente_acertado', ['insumo_id' => (int)($in['id'] ?? 0), 'restaurante_id' => $rid]);
            security_json(['success' => true, 'message' => 'Quantidade actualizada.'] + $lista());
            break;
        case 'arquivar':
            ing_arquivar($db, $rid, (int)($in['id'] ?? 0));
            audit_log($db, 'ingrediente_arquivado', ['insumo_id' => (int)($in['id'] ?? 0), 'restaurante_id' => $rid]);
            security_json(['success' => true, 'message' => 'Ingrediente arquivado.'] + $lista());
            break;
        case 'pratos':
            security_json(['success' => true, 'pratos' => ing_pratos($db, $rid)]);
            break;
        case 'receita':
            security_json(['success' => true, 'itens' => ing_receita($db, $rid, (int)($in['produto_id'] ?? 0))]);
            break;
        case 'receita_salvar':
            $itens = $in['itens'] ?? [];
            if (is_string($itens)) {
                $itens = json_decode($itens, true) ?: [];
            }
            ing_receita_salvar($db, $rid, (int)($in['produto_id'] ?? 0), is_array($itens) ? $itens : []);
            audit_log($db, 'receita_salva', ['produto_id' => (int)($in['produto_id'] ?? 0), 'restaurante_id' => $rid]);
            security_json(['success' => true, 'message' => 'Receita guardada.', 'pratos' => ing_pratos($db, $rid)]);
            break;
        default:
            security_json(['success' => false, 'message' => 'Ação inválida.'], 400);
    }
} catch (InvalidArgumentException $e) {
    security_json(['success' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('api/ingredientes: ' . $e->getMessage());
    security_json(['success' => false, 'message' => 'Não foi possível concluir. Tente novamente.'], 500);
}
