<?php
declare(strict_types=1);

/*
 * Relatorio de imagens/anexos orfaos (nao referidos pela base de dados nem
 * pelo codigo) e de ficheiros duplicados (mesmo conteudo).
 *
 * SO LEITURA por defeito:
 *   php tools\imagens_orfas.php
 *   php tools\imagens_orfas.php --json
 *
 * Mover os orfaos para V00\_to_delete\ (reversivel; nunca apaga):
 *   php tools\imagens_orfas.php --mover --confirm=MOVER_ORFAOS
 *
 * Correr contra a base de dados REAL do ambiente (a do .env), porque e la que
 * estao os nomes das imagens. Em producao, correr no servidor.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Apenas linha de comando.');
}

$raiz = dirname(__DIR__);
require_once $raiz . '/app/src/config/database.php';

$opts = getopt('', ['json', 'mover', 'confirm:']);
$mover = isset($opts['mover']);
if ($mover && ($opts['confirm'] ?? '') !== 'MOVER_ORFAOS') {
    fwrite(STDERR, "Para mover use: --mover --confirm=MOVER_ORFAOS\n");
    exit(2);
}

// Pastas analisadas (relativas a V00).
$pastas = [
    'src/public/images',
    'src/public/uploads',
    'app/src/public/images',
];
// Colunas que guardam nomes/caminhos de ficheiros.
$colunas = [
    'cardapio_imagens.caminho_imagem', 'compras_planos.comprovante_path', 'compras_planos.comprovativo_path',
    'produtos.imagem', 'promocoes.imagem', 'restaurantes.documento_titularidade_path', 'restaurantes.logo',
    'super_admins.foto', 'support_ticket_messages.anexo_path', 'support_ticket_messages.anexo_nome',
    'uploads_comprovantes.caminho', 'uploads_comprovantes.nome_arquivo', 'usuarios.foto',
    'backup_historico.arquivo_caminho', 'backup_historico.arquivo_nome',
];

// 1) Nomes referidos na base de dados (por nome de ficheiro, ignorando a pasta).
$db = (new Database())->getConnection();
$referidos = [];
$colunasLidas = 0;
foreach ($colunas as $tc) {
    [$t, $c] = explode('.', $tc);
    $existe = $db->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $existe->execute([$t, $c]);
    if ((int)$existe->fetchColumn() === 0) {
        continue;
    }
    $colunasLidas++;
    foreach ($db->query("SELECT `{$c}` FROM `{$t}` WHERE `{$c}` IS NOT NULL AND `{$c}` <> ''") as $row) {
        $nome = strtolower(basename(str_replace('\\', '/', (string)$row[$c])));
        if ($nome !== '') {
            $referidos[$nome] = true;
        }
    }
}

// 2) Nomes referidos no codigo (php/js/css/html), ex.: imagens da landing.
$texto = '';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz . '/src/public', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = str_replace('\\', '/', $f->getPathname());
    if (strpos($p, '/uploads/') !== false || strpos($p, '/images/') !== false) {
        continue;
    }
    if (preg_match('/\.(php|js|css|html|webmanifest)$/i', $p)) {
        $texto .= strtolower((string)file_get_contents($p)) . "\n";
    }
}

$estaReferido = static function (string $nome) use ($referidos, $texto): bool {
    $n = strtolower($nome);
    if (isset($referidos[$n]) || strpos($texto, $n) !== false) {
        return true;
    }
    // Variante gerada pelo cardapio: p_xxx.mobile.webp pertence a p_xxx.jpg
    if (preg_match('/^(.+)\.mobile\.webp$/', $n, $m) && isset($referidos[$m[1] . '.jpg'])) {
        return true;
    }
    return false;
};

// 3) Percorrer ficheiros.
$orfaos = [];
$porHash = [];
$total = 0;
foreach ($pastas as $pasta) {
    $abs = $raiz . '/' . $pasta;
    if (!is_dir($abs)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) {
            continue;
        }
        $nome = $f->getFilename();
        if ($nome === '.htaccess' || $nome === '.gitkeep' || $nome === 'index.html') {
            continue;
        }
        $rel = ltrim(str_replace([str_replace('\\', '/', $raiz), '\\'], ['', '/'], str_replace('\\', '/', $f->getPathname())), '/');
        $total++;
        $ref = $estaReferido($nome);
        $porHash[hash_file('sha256', $f->getPathname())][] = ['rel' => $rel, 'ref' => $ref, 'bytes' => $f->getSize()];
        if (!$ref) {
            $orfaos[] = ['rel' => $rel, 'bytes' => $f->getSize()];
        }
    }
}

$duplicados = [];
foreach ($porHash as $grupo) {
    if (count($grupo) > 1) {
        $duplicados[] = $grupo;
    }
}
$bytesOrfaos = array_sum(array_column($orfaos, 'bytes'));

$movidos = [];
if ($mover) {
    foreach ($orfaos as $o) {
        $destino = $raiz . '/_to_delete/' . $o['rel'];
        if (!is_dir(dirname($destino))) {
            mkdir(dirname($destino), 0755, true);
        }
        if (rename($raiz . '/' . $o['rel'], $destino)) {
            $movidos[] = $o['rel'];
        }
    }
}

$resultado = [
    'colunas_lidas' => $colunasLidas,
    'nomes_referidos_na_bd' => count($referidos),
    'ficheiros_analisados' => $total,
    'orfaos' => count($orfaos),
    'orfaos_mb' => round($bytesOrfaos / 1048576, 2),
    'grupos_duplicados' => count($duplicados),
    'movidos' => count($movidos),
    'lista_orfaos' => array_column($orfaos, 'rel'),
];

if (isset($opts['json'])) {
    echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

echo "Colunas da BD lidas: {$resultado['colunas_lidas']} | nomes referidos: {$resultado['nomes_referidos_na_bd']}\n";
echo "Ficheiros analisados: {$total}\n";
echo "Orfaos (nao referidos): " . count($orfaos) . " ({$resultado['orfaos_mb']} MB)\n";
foreach ($orfaos as $o) {
    echo '  - ' . $o['rel'] . ' (' . round($o['bytes'] / 1024) . " KB)\n";
}
echo "Grupos de ficheiros com conteudo identico: " . count($duplicados) . "\n";
foreach ($duplicados as $g) {
    echo '  * ' . count($g) . 'x ' . round($g[0]['bytes'] / 1024) . ' KB: ';
    echo implode(', ', array_map(static fn($d) => $d['rel'] . ($d['ref'] ? '' : ' [orfao]'), $g)) . "\n";
}
if ($mover) {
    echo "Movidos para _to_delete: " . count($movidos) . "\n";
} elseif ($orfaos) {
    echo "\nNada foi alterado. Para mover os orfaos: php tools\\imagens_orfas.php --mover --confirm=MOVER_ORFAOS\n";
}
