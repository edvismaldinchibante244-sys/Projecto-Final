<?php
declare(strict_types=1);
/*
 * Cria um restaurante de DEMONSTRACAO pronto a mostrar a clientes:
 * categorias, pratos com preco, 8 mesas + balcao, ingredientes com quantidades e receitas.
 *
 *   php tools/criar_demo.php --email=demo@seudominio.com [--plano=BASICO|PROFISSIONAL|EMPRESARIAL] [--nome="Restaurante Demo"]
 *
 * A senha do administrador e pedida no teclado. Pode correr de novo: se o restaurante
 * com esse nome ja existir, nao cria outro.
 */
require __DIR__ . '/_cli_base.php';
require_once APP_BASE . '/src/config/ingredientes_simples.php';

$o = getopt('', ['email:', 'plano:', 'nome:']);
$email = trim((string)($o['email'] ?? ''));
$plano = strtoupper(trim((string)($o['plano'] ?? 'PROFISSIONAL')));
$nome = trim((string)($o['nome'] ?? 'Restaurante Demo Nivora'));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($plano, ['BASICO', 'PROFISSIONAL', 'EMPRESARIAL'], true)) {
    fwrite(STDERR, "Uso: php tools/criar_demo.php --email=demo@dominio.com [--plano=BASICO|PROFISSIONAL|EMPRESARIAL]\n");
    exit(1);
}
$db = cli_db();
$s = $db->prepare('SELECT id FROM restaurantes WHERE nome = ? LIMIT 1');
$s->execute([$nome]);
if ($s->fetchColumn()) {
    fwrite(STDERR, "Ja existe um restaurante chamado \"{$nome}\". Nada foi feito.\n");
    exit(1);
}
$s = $db->prepare('SELECT COUNT(*) FROM usuarios WHERE email_lookup = ?');
$s->execute([personal_data_email_lookup($email)]);
if ((int)$s->fetchColumn() > 0) {
    fwrite(STDERR, "Esse email ja esta em uso.\n");
    exit(1);
}
$senha = cli_ler_senha('Senha do administrador da demo (min. 12): ');
if (($erro = cli_senha_forte($senha)) !== null) {
    fwrite(STDERR, $erro . "\n");
    exit(1);
}

$db->beginTransaction();
$rid = cli_criar_restaurante($db, $nome, $email, $plano);
$uid = cli_criar_usuario($db, $rid, 'Administrador Demo', $email, $senha, 'ADMIN');

$cardapio = [
    'Pratos' => [
        ['Frango grelhado com batata frita', 450, 'Meio frango grelhado, batata frita e salada.'],
        ['Matapa com arroz', 380, 'Matapa tradicional com amendoim e coco.'],
        ['Hamburguer da casa', 350, 'Pao, carne 150 g, queijo, alface e tomate.'],
        ['Prego no pao', 220, 'Bife de vaca no pao com molho da casa.'],
        ['Peixe frito com xima', 420, 'Carapau frito, xima e salada.'],
    ],
    'Bebidas' => [
        ['Refrigerante lata', 80, ''],
        ['Agua 500 ml', 50, ''],
        ['Sumo natural', 120, 'Laranja ou manga, conforme a epoca.'],
        ['Cafe expresso', 70, ''],
    ],
    'Sobremesas' => [
        ['Pudim caseiro', 150, ''],
        ['Salada de fruta', 130, ''],
    ],
];
$produtoIds = [];
foreach ($cardapio as $cat => $itens) {
    $db->prepare('INSERT INTO categorias (restaurante_id, nome, ativo) VALUES (?, ?, 1)')->execute([$rid, $cat]);
    $cid = (int)$db->lastInsertId();
    foreach ($itens as [$pn, $preco, $desc]) {
        $db->prepare('INSERT INTO produtos (restaurante_id, categoria_id, categoria, nome, descricao, preco, estoque, estoque_minimo, ativo) VALUES (?, ?, ?, ?, ?, ?, 100, 5, 1)')
            ->execute([$rid, $cid, $cat, $pn, $desc ?: null, $preco]);
        $produtoIds[$pn] = (int)$db->lastInsertId();
    }
}
$db->prepare("INSERT INTO mesas (restaurante_id, numero, capacidade, status) VALUES (?, 'B1', 1, 'LIVRE')")->execute([$rid]);
for ($m = 1; $m <= 8; $m++) {
    $db->prepare("INSERT INTO mesas (restaurante_id, numero, capacidade, status) VALUES (?, ?, ?, 'LIVRE')")->execute([$rid, (string)$m, $m <= 4 ? 4 : 6]);
}
$db->commit();

// Ingredientes (ing_criar usa a sua propria transacao).
$planoRid = $rid;
$ingredientes = [
    'Frango' => ['g', 10000, 2000], 'Batata' => ['g', 15000, 3000], 'Arroz' => ['g', 20000, 5000],
    'Oleo' => ['ml', 10000, 2000], 'Pao' => ['un', 60, 15], 'Carne de vaca' => ['g', 8000, 2000],
    'Queijo (fatia)' => ['un', 80, 20], 'Tomate' => ['g', 5000, 1000], 'Carapau' => ['g', 6000, 1500],
    'Farinha de milho' => ['g', 10000, 2000], 'Matapa' => ['g', 5000, 1000],
];
$ingIds = [];
foreach ($ingredientes as $n => [$u, $q, $min]) {
    $ingIds[$n] = ing_criar($db, $rid, $planoRid, $n, $u, (float)$q, (float)$min, $uid);
}
$receitas = [
    'Frango grelhado com batata frita' => ['Frango' => 400, 'Batata' => 250, 'Oleo' => 60, 'Tomate' => 50],
    'Matapa com arroz' => ['Matapa' => 250, 'Arroz' => 150],
    'Hamburguer da casa' => ['Pao' => 1, 'Carne de vaca' => 150, 'Queijo (fatia)' => 1, 'Tomate' => 40],
    'Prego no pao' => ['Pao' => 1, 'Carne de vaca' => 120],
    'Peixe frito com xima' => ['Carapau' => 350, 'Farinha de milho' => 200, 'Oleo' => 50],
];
foreach ($receitas as $prato => $itens) {
    $lista = [];
    foreach ($itens as $ing => $q) {
        $lista[] = ['insumo_id' => $ingIds[$ing], 'quantidade' => $q, 'unidade' => $ingredientes[$ing][0]];
    }
    ing_receita_salvar($db, $rid, $produtoIds[$prato], $lista);
}

echo "Demo criada: \"{$nome}\" (restaurante #{$rid}, plano {$plano}).\n";
echo "  11 pratos/bebidas, 8 mesas + balcao, " . count($ingIds) . " ingredientes, " . count($receitas) . " receitas.\n";
echo "  Entre com {$email} e a senha que escolheu.\n";
