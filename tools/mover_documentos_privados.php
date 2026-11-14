<?php

declare(strict_types=1);

/**
 * Move comprovativos e documentos de titularidade para fora da pasta publica.
 *
 * Estes ficheiros sao documentos bancarios e de identidade de clientes. Viviam
 * em src/public/uploads, de onde o Apache os servia directamente, sem passar
 * pela verificacao de sessao do comprovativo_arquivo.php: quem tivesse o
 * endereco abria o documento sem estar autenticado.
 *
 * O caminho guardado na base de dados nao muda. Muda so onde o ficheiro esta.
 *
 * Uso:
 *   php tools/mover_documentos_privados.php            (so mostra o que faria)
 *   php tools/mover_documentos_privados.php --aplicar  (move mesmo)
 *
 * E seguro correr duas vezes: o que ja foi movido e ignorado.
 */

$base = dirname(__DIR__);
if (!defined('APP_BASE')) {
    define('APP_BASE', is_dir($base . '/app') ? $base . '/app' : $base);
}
require_once APP_BASE . '/src/config/storage_path.php';

$aplicar = in_array('--aplicar', $argv, true);
$publico = $base . '/src/public';
$pastas = ['uploads/comprovativos', 'uploads/documentos_titularidade'];

$movidos = 0;
$jaLa = 0;
$falhas = 0;
$total = 0;

foreach ($pastas as $pasta) {
    $origemDir = $publico . '/' . $pasta;
    if (!is_dir($origemDir)) {
        echo "  (sem pasta $pasta)\n";
        continue;
    }

    $destinoDir = nv_ficheiro_privado($pasta);
    if (!is_dir($destinoDir) && !@mkdir($destinoDir, 0700, true) && !is_dir($destinoDir)) {
        echo "  ERRO: nao consegui criar $destinoDir\n";
        $falhas++;
        continue;
    }

    foreach (scandir($origemDir) ?: [] as $nome) {
        if ($nome === '.' || $nome === '..' || $nome === '.htaccess') {
            continue;
        }
        $origem = $origemDir . '/' . $nome;
        if (!is_file($origem)) {
            continue;
        }

        $total++;
        $destino = $destinoDir . '/' . $nome;

        if (is_file($destino)) {
            // Ja existe no sitio novo: apagar a copia exposta.
            if ($aplicar) {
                if (@unlink($origem)) {
                    $jaLa++;
                    echo "  ja estava no privado, copia publica removida: $pasta/$nome\n";
                } else {
                    $falhas++;
                    echo "  ERRO ao remover a copia publica: $pasta/$nome\n";
                }
            } else {
                $jaLa++;
                echo "  [simulacao] removeria a copia publica de $pasta/$nome\n";
            }
            continue;
        }

        if (!$aplicar) {
            echo "  [simulacao] moveria $pasta/$nome\n";
            $movidos++;
            continue;
        }

        if (@rename($origem, $destino)) {
            @chmod($destino, 0600);
            $movidos++;
            echo "  movido: $pasta/$nome\n";
        } else {
            // rename falha entre sistemas de ficheiros diferentes (o volume do
            // servidor costuma ser outro disco): copiar e so depois apagar.
            if (@copy($origem, $destino) && is_file($destino) && filesize($destino) === filesize($origem)) {
                @chmod($destino, 0600);
                if (@unlink($origem)) {
                    $movidos++;
                    echo "  copiado e removido: $pasta/$nome\n";
                } else {
                    $falhas++;
                    echo "  ERRO: copiado mas nao consegui remover o original: $pasta/$nome\n";
                }
            } else {
                $falhas++;
                echo "  ERRO ao mover: $pasta/$nome\n";
            }
        }
    }
}

echo "\n";
echo $aplicar ? "Concluido.\n" : "Simulacao (use --aplicar para mover).\n";
echo "  ficheiros encontrados: $total\n";
echo "  movidos: $movidos\n";
echo "  ja estavam no privado: $jaLa\n";
echo "  falhas: $falhas\n";

exit($falhas > 0 ? 1 : 0);
