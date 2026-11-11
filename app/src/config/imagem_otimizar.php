<?php
/**
 * Reduz automaticamente as imagens enviadas (logotipos, fotos de produtos e de utilizadores,
 * comprovativos em imagem). Telemoveis tiram fotos de 3-5 MB; para o sistema chega bem menos.
 *
 * - Se a imagem for maior do que $maxLado, encolhe mantendo a proporcao.
 * - Volta a gravar no MESMO formato (o nome do ficheiro nao muda): JPEG q82, PNG com transparencia,
 *   WebP q80.
 * - So substitui o ficheiro se o resultado ficar mais pequeno. PDFs e outros formatos ficam iguais.
 * - Sem a extensao GD do PHP, nao faz nada (o envio continua a funcionar).
 */

if (!function_exists('imagem_otimizar')) {
    /** @return bool true sempre (para poder ser usado dentro de condicoes) */
    function imagem_otimizar(string $caminho, int $maxLado = 1600): bool
    {
        if (!is_file($caminho) || !function_exists('imagecreatetruecolor')) {
            return true;
        }
        $info = @getimagesize($caminho);
        if (!$info || empty($info[0]) || empty($info[1])) {
            return true; // nao e imagem (ex.: PDF)
        }
        [$larg, $alt, $tipo] = [(int)$info[0], (int)$info[1], (int)$info[2]];
        // Imagens enormes (> 40 megapixeis) nao sao abertas, para nao esgotar a memoria.
        if ($larg * $alt > 40000000) {
            return true;
        }
        $abrir = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp'];
        if (!isset($abrir[$tipo]) || !function_exists($abrir[$tipo])) {
            return true;
        }
        $orig = @$abrir[$tipo]($caminho);
        if (!$orig) {
            return true;
        }

        // Fotos de telemovel: respeitar a rotacao EXIF antes de redimensionar.
        if ($tipo === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($caminho);
            $rot = [3 => 180, 6 => -90, 8 => 90][(int)($exif['Orientation'] ?? 1)] ?? 0;
            if ($rot !== 0) {
                $girada = imagerotate($orig, $rot, 0);
                if ($girada) {
                    imagedestroy($orig);
                    $orig = $girada;
                    [$larg, $alt] = [imagesx($orig), imagesy($orig)];
                }
            }
        }

        $escala = min(1, $maxLado / max($larg, $alt));
        $nl = max(1, (int)round($larg * $escala));
        $na = max(1, (int)round($alt * $escala));
        $nova = imagecreatetruecolor($nl, $na);
        if ($tipo !== IMAGETYPE_JPEG) {
            imagealphablending($nova, false);
            imagesavealpha($nova, true);
            imagefill($nova, 0, 0, imagecolorallocatealpha($nova, 0, 0, 0, 127));
        }
        imagecopyresampled($nova, $orig, 0, 0, 0, 0, $nl, $na, $larg, $alt);
        imagedestroy($orig);

        $tmp = $caminho . '.otimizar';
        $ok = false;
        if ($tipo === IMAGETYPE_JPEG) {
            imageinterlace($nova, true);
            $ok = imagejpeg($nova, $tmp, 82);
        } elseif ($tipo === IMAGETYPE_PNG) {
            $ok = imagepng($nova, $tmp, 9);
        } elseif ($tipo === IMAGETYPE_WEBP) {
            $ok = imagewebp($nova, $tmp, 80);
        }
        imagedestroy($nova);

        clearstatcache();
        if ($ok && is_file($tmp) && filesize($tmp) > 0 && filesize($tmp) < filesize($caminho)) {
            @rename($tmp, $caminho);
            @chmod($caminho, 0644);
        } else {
            @unlink($tmp);
        }
        return true;
    }

    /** move_uploaded_file + reducao. Devolve false so se o envio falhar. */
    function imagem_mover_e_otimizar(string $origem, string $destino, int $maxLado = 1600): bool
    {
        if (!move_uploaded_file($origem, $destino)) {
            return false;
        }
        return imagem_otimizar($destino, $maxLado);
    }
}
