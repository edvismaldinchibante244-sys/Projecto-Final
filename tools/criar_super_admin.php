<?php
declare(strict_types=1);
/*
 * Cria o PRIMEIRO Super Admin numa instalacao nova (o banco novo nao tem nenhum utilizador).
 *
 *   php tools/criar_super_admin.php --nome="O seu nome" --email=voce@dominio.com
 *
 * A senha e pedida no teclado (nao aparece no ecra nem fica no historico).
 * Recusa correr se ja existir um Super Admin (use --mais-um para acrescentar outro de proposito).
 * A verificacao em 2 passos por email fica ligada logo de inicio.
 */
require __DIR__ . '/_cli_base.php';

$o = getopt('', ['nome:', 'email:', 'mais-um']);
$nome = trim((string)($o['nome'] ?? ''));
$email = trim((string)($o['email'] ?? ''));
if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Uso: php tools/criar_super_admin.php --nome=\"Nome\" --email=email@dominio.com\n");
    exit(1);
}
$db = cli_db();
$existentes = (int)$db->query('SELECT COUNT(*) FROM usuarios WHERE super_admin = 1')->fetchColumn();
if ($existentes > 0 && !isset($o['mais-um'])) {
    fwrite(STDERR, "Ja existe {$existentes} Super Admin. Nada foi feito. (Para criar outro de proposito: --mais-um)\n");
    exit(1);
}
$s = $db->prepare('SELECT COUNT(*) FROM usuarios WHERE email_lookup = ?');
$s->execute([personal_data_email_lookup($email)]);
if ((int)$s->fetchColumn() > 0) {
    fwrite(STDERR, "Esse email ja esta em uso.\n");
    exit(1);
}
$senha = cli_ler_senha('Senha do Super Admin (min. 12, com maiusculas, minusculas e numeros): ');
if (($erro = cli_senha_forte($senha)) !== null) {
    fwrite(STDERR, $erro . "\n");
    exit(1);
}
if (getenv('NIVORA_SENHA_INICIAL') === false || getenv('NIVORA_SENHA_INICIAL') === '') {
    if (cli_ler_senha('Repita a senha: ') !== $senha) {
        fwrite(STDERR, "As senhas nao coincidem.\n");
        exit(1);
    }
}

$db->beginTransaction();
$rid = (int)($db->query("SELECT r.id FROM restaurantes r JOIN usuarios u ON u.restaurante_id = r.id AND u.super_admin = 1 LIMIT 1")->fetchColumn() ?: 0);
if ($rid === 0) {
    $rid = cli_criar_restaurante($db, 'Nivora (Plataforma)', $email, 'EMPRESARIAL');
}
$uid = cli_criar_usuario($db, $rid, $nome, $email, $senha, 'ADMIN', 1);
$db->commit();
echo "Super Admin criado (utilizador #{$uid}) no banco " . cli_nome_banco($db) . ".\n";
echo "Entre no site com este email. A verificacao em 2 passos por email ja esta ligada.\n";
