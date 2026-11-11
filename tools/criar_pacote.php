<?php
declare(strict_types=1);

/**
 * Cria o pacote de publicacao do Nivora: dist/nivora-AAAAMMDD-HHMM.zip
 *
 * Uso (na pasta V00):   php tools/criar_pacote.php            -> cria o .zip
 *                       php tools/criar_pacote.php --listar   -> so mostra o que entraria
 *
 * Regra: LISTA BRANCA. So entra o que o servidor precisa para correr. Fica de fora tudo o que
 * tem dados reais ou nao faz falta em producao: .env, backups .sql, uploads de clientes,
 * logs/sessoes, testes, node_modules, .bat/.ps1/.cmd, documentacao, imagens de QA, etc.
 *
 * No servidor: a pasta publica (DocumentRoot) deve ser  src/public
 */

$raiz = realpath(__DIR__ . '/..');
if ($raiz === false) {
    fwrite(STDERR, "Raiz do projeto nao encontrada.\n");
    exit(2);
}
$so_listar = in_array('--listar', $argv, true);

// O que entra (pastas inteiras ou ficheiros), relativo a raiz.
$incluir = [
    'index.php',
    '.htaccess',
    '.env.example',
    'app',
    'src',
    'db',
    'scripts/cron',
    'services/realtime',
    'tools/db_migrate.php',
    'tools/dr_restore_drill.php',
    'tools/_cli_base.php',
    'tools/criar_super_admin.php',
    'tools/criar_demo.php',
    'tools/limpar_testes.php',
    'tools/security_ops_runner.php',
    'tools/security_monitor.php',
    'tools/security_siem_reactor.php',
    'tools/check_automation_health.php',
];

/** true = fica de fora. $rel usa sempre "/" */
function pacote_excluir(string $rel): bool
{
    $nome = basename($rel);
    $padroes = [
        '#(^|/)node_modules(/|$)#',
        '#(^|/)\.git(/|$)#',
        '#(^|/)\.(vscode|idea|github)(/|$)#',
        '#(^|/)tests?(/|$)#',
        '#\.(sql|log|bak|tmp|swp|phar|bat|cmd|ps1|sh|zip|gz|7z|rar)$#i',
        '#(^|/)\.env$#',
        '#(^|/)\.env\.(local|production|prod|backup)#i',
        '#\.backup-ia\.#i',
        '#(^|/)__e2e#',
        '#^src/public/funcionarios_turnos_fixed\.php$#',
        '#^app/debug_[^/]*$#',
        '#\.md$#i',
        '#(^|/)design-qa#i',
        '#(^|/)Thumbs\.db$#i',
        '#(^|/)\.DS_Store$#',
    ];
    foreach ($padroes as $p) {
        if (preg_match($p, $rel)) {
            // db/schema_base.sql e necessario no servidor para criar uma base nova.
            return $rel !== 'db/schema_base.sql';
        }
    }
    // Dados de clientes e de execucao: so a estrutura (.htaccess / pastas), nunca os ficheiros.
    $soEstrutura = ['app/storage/', 'app/uploads/', 'src/public/uploads/', 'src/storage/', 'app/src/storage/'];
    foreach ($soEstrutura as $pasta) {
        if (strpos($rel, $pasta) === 0 && $nome !== '.htaccess' && $nome !== 'index.html') {
            return true;
        }
    }
    // Imagens enviadas por restaurantes (produtos/logos) ficam na pasta images com nomes gerados.
    if (strpos($rel, 'src/public/images/') === 0
        && preg_match('/^(p_[0-9a-f]+\.[0-9a-z]+|[0-9a-f]{32})\.(png|jpe?g|webp|gif)$/i', $nome)) {
        return true;
    }
    return false;
}

$ficheiros = [];
$pastasVazias = [];
foreach ($incluir as $item) {
    $abs = $raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $item);
    if (is_file($abs)) {
        if (!pacote_excluir($item)) {
            $ficheiros[$item] = $abs;
        }
        continue;
    }
    if (!is_dir($abs)) {
        echo "  (aviso) nao existe: $item\n";
        continue;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($raiz) + 1));
        if ($f->isDir()) {
            // Pastas de dados: criadas vazias no pacote (o sistema precisa delas).
            if (preg_match('#^(app/storage|src/public/uploads)(/|$)#', $rel) && strpos($rel, 'node_modules') === false) {
                $pastasVazias[$rel] = true;
            }
            continue;
        }
        if (!pacote_excluir($rel)) {
            $ficheiros[$rel] = $f->getPathname();
        }
    }
}
ksort($ficheiros);

// Verificacoes de seguranca: nada disto pode ir no pacote.
$proibidos = array_filter(array_keys($ficheiros), static fn($r) => preg_match('#(^|/)\.env$|\.sql$|node_modules|__e2e|(^|/)tests?/#', $r) && $r !== 'db/schema_base.sql');
if ($proibidos) {
    fwrite(STDERR, "ERRO: ficheiros proibidos no pacote:\n  " . implode("\n  ", $proibidos) . "\n");
    exit(1);
}

$total = 0;
foreach ($ficheiros as $abs) {
    $total += (int)filesize($abs);
}
printf("Ficheiros: %d  |  Tamanho: %.1f MB  |  Pastas de dados (vazias): %d\n", count($ficheiros), $total / 1048576, count($pastasVazias));

if ($so_listar) {
    foreach (array_keys($ficheiros) as $r) {
        echo "  $r\n";
    }
    exit(0);
}

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "A extensao zip do PHP nao esta ativa. No XAMPP: php.ini -> retire o ';' de extension=zip e repita.\n");
    exit(1);
}

$dist = $raiz . DIRECTORY_SEPARATOR . 'dist';
if (!is_dir($dist) && !mkdir($dist, 0775, true)) {
    fwrite(STDERR, "Nao foi possivel criar a pasta dist.\n");
    exit(1);
}
$destino = $dist . DIRECTORY_SEPARATOR . 'nivora-' . date('Ymd-Hi') . '.zip';
$zip = new ZipArchive();
if ($zip->open($destino, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Nao foi possivel criar $destino\n");
    exit(1);
}
foreach (array_keys($pastasVazias) as $p) {
    $zip->addEmptyDir($p);
}
foreach ($ficheiros as $rel => $abs) {
    $zip->addFile($abs, $rel);
}
$zip->addFromString('LEIA-ME-PUBLICACAO.txt', implode("\n", [
    'Nivora - pacote de publicacao gerado em ' . date('Y-m-d H:i'),
    '',
    '1. Pasta publica (DocumentRoot) do dominio: src/public',
    '2. Criar o .env a partir do .env.example (APP_ENV=production, segredos NOVOS).',
    '3. Base nova: importar db/schema_base.sql e depois:',
    '     php tools/db_migrate.php --apply --confirm=APPLY_MIGRATIONS --target=NOME_DA_BASE --allow-non-test',
    '   Base existente: fazer backup e correr so o comando acima.',
    '4. Base nova: criar o primeiro Super Admin (a senha e pedida no teclado):',
    '     php tools/criar_super_admin.php --nome="O seu nome" --email=voce@dominio.com',
    '5. (Opcional) Restaurante de demonstracao para mostrar a clientes:',
    '     php tools/criar_demo.php --email=demo@dominio.com --plano=PROFISSIONAL',
    '6. Entrar como Super Admin -> Verificacao de producao: tudo a verde antes de abrir.',
    '7. Antes de abrir: php tools/limpar_testes.php (so lista; apagar pede --ids e --confirmar).',
    '',
]));
$zip->close();
printf("Pacote criado: %s (%.1f MB)\n", $destino, filesize($destino) / 1048576);
