<?php
declare(strict_types=1);
/*
 * Lista (e, so com confirmacao, apaga) restaurantes de TESTE e tudo o que lhes pertence.
 *
 *   php tools/limpar_testes.php                      -> so LISTA, nao apaga nada
 *   php tools/limpar_testes.php --ids=12,15          -> lista estes restaurantes
 *   php tools/limpar_testes.php --ids=12,15 --apagar --confirmar=APAGAR_TESTES
 *
 * Por seguranca:
 *  - sem --ids so mostra sugestoes (nomes com teste/test/e2e/demo/exemplo); para apagar e
 *    sempre preciso indicar os numeros (--ids), depois de os ver na lista;
 *  - nunca apaga o restaurante onde esta um Super Admin;
 *  - faca um backup do banco ANTES (mysqldump), porque isto nao se desfaz.
 */
require __DIR__ . '/_cli_base.php';

$o = getopt('', ['ids:', 'apagar', 'confirmar:']);
$db = cli_db();
$banco = cli_nome_banco($db);

$protegidos = array_map('intval', $db->query('SELECT DISTINCT restaurante_id FROM usuarios WHERE super_admin = 1')->fetchAll(PDO::FETCH_COLUMN));
if (isset($o['ids'])) {
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$o['ids'])), fn($i) => $i > 0)));
    if (!$ids) {
        fwrite(STDERR, "--ids sem numeros validos.\n");
        exit(1);
    }
    $in = implode(',', $ids);
    $lista = $db->query("SELECT id, nome, criado_em FROM restaurantes WHERE id IN ($in) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $lista = $db->query("SELECT id, nome, criado_em FROM restaurantes
        WHERE LOWER(nome) REGEXP '(^|[^a-z])(teste?s?|e2e|demo|exemplo|qa|dummy|fake)([^a-z]|$)'
        ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
}

$contaUsr = $db->prepare('SELECT COUNT(*) FROM usuarios WHERE restaurante_id = ?');
$contaPed = null;
try { $contaPed = $db->prepare('SELECT COUNT(*) FROM pedidos WHERE restaurante_id = ?'); } catch (Throwable $e) {}

echo "Banco: {$banco}\n";
if (!$lista) {
    echo "Nenhum restaurante encontrado.\n";
    exit(0);
}
printf("%-8s %-40s %-10s %-9s %s\n", 'ID', 'Nome', 'Utiliz.', 'Pedidos', 'Criado em');
$apagaveis = [];
foreach ($lista as $r) {
    $rid = (int)$r['id'];
    $contaUsr->execute([$rid]);
    $u = (int)$contaUsr->fetchColumn();
    $p = '-';
    if ($contaPed) { $contaPed->execute([$rid]); $p = (string)$contaPed->fetchColumn(); }
    $prot = in_array($rid, $protegidos, true);
    printf("%-8d %-40s %-10d %-9s %s%s\n", $rid, mb_strimwidth((string)$r['nome'], 0, 40, '…'), $u, $p, (string)$r['criado_em'], $prot ? '  [PROTEGIDO: Super Admin]' : '');
    if (!$prot) { $apagaveis[] = $rid; }
}
echo count($lista) . " restaurante(s).\n";

if (!isset($o['apagar'])) {
    echo "\nNada foi apagado. Para apagar, confirme os numeros e corra:\n";
    echo "  php tools/limpar_testes.php --ids=" . implode(',', array_slice($apagaveis, 0, 10)) . (count($apagaveis) > 10 ? ',...' : '') . " --apagar --confirmar=APAGAR_TESTES\n";
    exit(0);
}
if (!isset($o['ids'])) {
    fwrite(STDERR, "Para apagar indique os restaurantes com --ids (depois de os ver na lista).\n");
    exit(1);
}
if (($o['confirmar'] ?? '') !== 'APAGAR_TESTES') {
    fwrite(STDERR, "Falta --confirmar=APAGAR_TESTES. Nada foi apagado.\n");
    exit(1);
}
if (!$apagaveis) {
    echo "Nada a apagar.\n";
    exit(0);
}

// Todas as tabelas com restaurante_id (ordem nao importa: FK desligadas so nesta sessao).
$tabelas = $db->query("SELECT TABLE_NAME FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'restaurante_id' AND TABLE_NAME <> 'restaurantes'")->fetchAll(PDO::FETCH_COLUMN);
$in = implode(',', $apagaveis);
$total = 0;
$db->exec('SET FOREIGN_KEY_CHECKS = 0');
$db->beginTransaction();
try {
    // Linhas filhas sem restaurante_id (itens de pedidos/vendas) primeiro.
    $filhos = [
        ['itens_pedido', 'pedido_id', 'pedidos'], ['pedido_itens', 'pedido_id', 'pedidos'],
        ['itens_venda', 'venda_id', 'vendas'], ['estoque_ficha_itens', 'ficha_id', 'estoque_fichas_tecnicas'],
        ['support_ticket_messages', 'ticket_id', 'support_tickets'],
    ];
    $temCol = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    foreach ($filhos as [$t, $fk, $pai]) {
        $temCol->execute([$t, $fk]);
        if (!(int)$temCol->fetchColumn() || in_array($t, $tabelas, true)) continue;
        $total += $db->exec("DELETE FROM `$t` WHERE `$fk` IN (SELECT id FROM `$pai` WHERE restaurante_id IN ($in))");
    }
    foreach ($tabelas as $t) {
        $n = $db->exec("DELETE FROM `$t` WHERE restaurante_id IN ($in)");
        if ($n) { echo "  {$t}: {$n}\n"; }
        $total += $n;
    }
    $total += $db->exec("DELETE FROM restaurantes WHERE id IN ($in)");
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    fwrite(STDERR, "Erro, nada foi apagado: " . $e->getMessage() . "\n");
    exit(1);
}
$db->exec('SET FOREIGN_KEY_CHECKS = 1');
echo "Apagados " . count($apagaveis) . " restaurante(s) e {$total} linha(s) no total.\n";
