<?php
declare(strict_types=1);
/* Base comum das ferramentas de linha de comando (nao e servido pela web). */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$GLOBALS['NV_ROOT'] = realpath(__DIR__ . '/..');
if (!defined('APP_BASE')) {
    define('APP_BASE', $GLOBALS['NV_ROOT'] . '/app');
}
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/personal_data.php';

function cli_db(): PDO
{
    $db = (new Database())->getConnection();
    if (!$db instanceof PDO) {
        fwrite(STDERR, "Sem ligacao a base de dados. Verifique o .env.\n");
        exit(2);
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $db;
}

function cli_nome_banco(PDO $db): string
{
    return (string)$db->query('SELECT DATABASE()')->fetchColumn();
}

/** Le uma senha do teclado sem a mostrar (quando o terminal permite). */
function cli_ler_senha(string $pergunta): string
{
    $env = getenv('NIVORA_SENHA_INICIAL');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    fwrite(STDOUT, $pergunta);
    $escondeu = false;
    if (DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec') && @shell_exec('stty -echo 2>/dev/null') !== null) {
        $escondeu = true;
    }
    $linha = trim((string)fgets(STDIN));
    if ($escondeu) {
        @shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, "\n");
    }
    return $linha;
}

function cli_senha_forte(string $s): ?string
{
    if (strlen($s) < 12) return 'A senha precisa de pelo menos 12 caracteres.';
    if (!preg_match('/[A-Z]/', $s) || !preg_match('/[a-z]/', $s) || !preg_match('/\d/', $s)) {
        return 'Use maiusculas, minusculas e numeros.';
    }
    return null;
}

function cli_hash(string $senha): string
{
    return password_hash($senha, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
}

/** Cria um restaurante com as colunas que existirem na tabela. */
function cli_criar_restaurante(PDO $db, string $nome, string $email, string $plano): int
{
    $cols = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurantes'")->fetchAll(PDO::FETCH_COLUMN);
    $planoId = (int)($db->query("SELECT id FROM planos_disponiveis WHERE nome = " . $db->quote($plano) . " LIMIT 1")->fetchColumn() ?: 0);
    $hoje = date('Y-m-d');
    $fim = date('Y-m-d', strtotime('+1 year'));
    $dados = [
        'nome' => $nome,
        'email' => personal_data_encrypt($email),
        'email_lookup' => personal_data_email_lookup($email),
        'plano' => $plano,
        'plano_id' => $planoId ?: null,
        'status' => 'ATIVO',
        'status_plano' => 'ATIVO',
        'data_inicio' => $hoje,
        'data_inicio_plano' => $hoje,
        'data_fim' => $fim,
        'data_fim_plano' => $fim,
    ];
    $dados = array_intersect_key($dados, array_flip($cols));
    $sql = 'INSERT INTO restaurantes (' . implode(', ', array_keys($dados)) . ') VALUES (' . implode(', ', array_fill(0, count($dados), '?')) . ')';
    $db->prepare($sql)->execute(array_values($dados));
    return (int)$db->lastInsertId();
}

function cli_criar_usuario(PDO $db, int $rid, string $nome, string $email, string $senha, string $perfil, int $superAdmin = 0): int
{
    $enc = personal_data_encrypt($email);
    $db->prepare('INSERT INTO usuarios (restaurante_id, nome, email, email_lookup, senha, perfil, ativo, super_admin, two_factor_enabled, two_factor_email, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, NOW())')
        ->execute([$rid, $nome, $enc, personal_data_email_lookup($email), cli_hash($senha), $perfil, $superAdmin, $superAdmin, $enc]);
    return (int)$db->lastInsertId();
}
