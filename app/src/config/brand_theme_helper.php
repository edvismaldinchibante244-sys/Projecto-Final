<?php

require_once __DIR__ . '/migration_state.php';

if (!defined('BRAND_THEME_SCHEMA_MIGRATION_ID')) {
    define('BRAND_THEME_SCHEMA_MIGRATION_ID', '20260814_1210_restaurante_brand_theme');
}
/**
 * Cores de identidade por restaurante (dashboard, sidebar, cardápio).
 * Prioridade: DB (cor_primaria/cor_secundaria) → paleta do logo → hash do nome.
 */

if (!function_exists('brand_theme_normalize_hex')) {
    function brand_theme_normalize_hex($value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        if ($value[0] !== '#') {
            $value = '#' . $value;
        }
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
            return '';
        }
        return strtoupper($value);
    }
}

if (!function_exists('brand_theme_hex_to_rgb')) {
    function brand_theme_hex_to_rgb(string $hex): array
    {
        $hex = ltrim(brand_theme_normalize_hex($hex), '#');
        if (strlen($hex) !== 6) {
            return [232, 137, 58];
        }
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }
}

if (!function_exists('brand_theme_rgb_to_hex')) {
    function brand_theme_rgb_to_hex(int $r, int $g, int $b): string
    {
        return sprintf('#%02X%02X%02X', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
    }
}

if (!function_exists('brand_theme_mix')) {
    function brand_theme_mix(string $hex, string $with, float $ratio = 0.35): string
    {
        $a = brand_theme_hex_to_rgb($hex);
        $b = brand_theme_hex_to_rgb($with);
        $ratio = max(0, min(1, $ratio));
        return brand_theme_rgb_to_hex(
            (int)round($a[0] + ($b[0] - $a[0]) * $ratio),
            (int)round($a[1] + ($b[1] - $a[1]) * $ratio),
            (int)round($a[2] + ($b[2] - $a[2]) * $ratio)
        );
    }
}

if (!function_exists('brand_theme_rgba')) {
    function brand_theme_rgba(string $hex, float $alpha = 0.18): string
    {
        [$r, $g, $b] = brand_theme_hex_to_rgb($hex);
        $alpha = max(0, min(1, $alpha));
        return sprintf('rgba(%d, %d, %d, %.3f)', $r, $g, $b, $alpha);
    }
}

if (!function_exists('brand_theme_luminance')) {
    function brand_theme_luminance(string $hex): float
    {
        [$r, $g, $b] = brand_theme_hex_to_rgb($hex);
        return (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
    }
}

if (!function_exists('brand_theme_from_name')) {
    function brand_theme_from_name(string $nome): string
    {
        $hash = md5($nome !== '' ? $nome : 'edvistro');
        // HSL estável a partir do hash → cores vivas (não cinzentas)
        $h = hexdec(substr($hash, 0, 2)) / 255 * 360;
        $s = 0.58 + (hexdec(substr($hash, 2, 2)) / 255) * 0.22;
        $l = 0.42 + (hexdec(substr($hash, 4, 2)) / 255) * 0.10;
        return brand_theme_hsl_to_hex($h, $s, $l);
    }
}

if (!function_exists('brand_theme_hsl_to_hex')) {
    function brand_theme_hsl_to_hex(float $h, float $s, float $l): string
    {
        $h = fmod(($h + 360), 360) / 360;
        $s = max(0, min(1, $s));
        $l = max(0, min(1, $l));
        if ($s < 0.0001) {
            $v = (int)round($l * 255);
            return brand_theme_rgb_to_hex($v, $v, $v);
        }
        $q = $l < 0.5 ? $l * (1 + $s) : ($l + $s - $l * $s);
        $p = 2 * $l - $q;
        $r = brand_theme_hue_to_rgb($p, $q, $h + 1 / 3);
        $g = brand_theme_hue_to_rgb($p, $q, $h);
        $b = brand_theme_hue_to_rgb($p, $q, $h - 1 / 3);
        return brand_theme_rgb_to_hex((int)round($r * 255), (int)round($g * 255), (int)round($b * 255));
    }
}

if (!function_exists('brand_theme_hue_to_rgb')) {
    function brand_theme_hue_to_rgb(float $p, float $q, float $t): float
    {
        if ($t < 0) {
            $t += 1;
        }
        if ($t > 1) {
            $t -= 1;
        }
        if ($t < 1 / 6) {
            return $p + ($q - $p) * 6 * $t;
        }
        if ($t < 1 / 2) {
            return $q;
        }
        if ($t < 2 / 3) {
            return $p + ($q - $p) * (2 / 3 - $t) * 6;
        }
        return $p;
    }
}

if (!function_exists('brand_theme_extract_from_logo')) {
    function brand_theme_extract_from_logo(string $logoPath): string
    {
        $logoPath = trim(str_replace('\\', '/', $logoPath));
        if ($logoPath === '' || preg_match('#^https?://#i', $logoPath)) {
            return '';
        }
        $logoPath = preg_replace('#^src/public/#i', '', $logoPath);
        $logoPath = ltrim($logoPath, '/');

        $candidates = [];
        // V00/app/src/config -> V00/src/public
        $fromConfig = realpath(__DIR__ . '/../../../src/public');
        if ($fromConfig) {
            $candidates[] = $fromConfig . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $logoPath);
        }
        if (defined('APP_BASE')) {
            $fromApp = realpath(APP_BASE . '/../src/public');
            if ($fromApp) {
                $candidates[] = $fromApp . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $logoPath);
            }
        }

        $absolute = '';
        foreach ($candidates as $cand) {
            if (is_file($cand)) {
                $absolute = $cand;
                break;
            }
        }
        if ($absolute === '' || !function_exists('imagecreatefromstring')) {
            return '';
        }

        $raw = @file_get_contents($absolute);
        if ($raw === false || $raw === '') {
            return '';
        }
        $img = @imagecreatefromstring($raw);
        if (!$img) {
            return '';
        }

        $w = imagesx($img);
        $h = imagesy($img);
        if ($w < 2 || $h < 2) {
            return '';
        }

        $tw = 48;
        $th = 48;
        $sample = imagecreatetruecolor($tw, $th);
        imagealphablending($sample, false);
        imagesavealpha($sample, true);
        imagecopyresampled($sample, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
        unset($img); // GD libera no GC; imagedestroy() está deprecated no PHP 8.5

        $buckets = [];
        for ($y = 0; $y < $th; $y++) {
            for ($x = 0; $x < $tw; $x++) {
                $rgb = imagecolorat($sample, $x, $y);
                $a = ($rgb & 0x7F000000) >> 24;
                if ($a > 40) {
                    continue; // quase transparente
                }
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $max = max($r, $g, $b);
                $min = min($r, $g, $b);
                // Ignora quase branco / quase preto / cinza fraco
                if ($max > 245 && $min > 230) {
                    continue;
                }
                if ($max < 28) {
                    continue;
                }
                if (($max - $min) < 18) {
                    continue;
                }
                $key = sprintf('%02X%02X%02X', (int)($r / 24) * 24, (int)($g / 24) * 24, (int)($b / 24) * 24);
                if (!isset($buckets[$key])) {
                    $buckets[$key] = ['c' => 0, 'r' => 0, 'g' => 0, 'b' => 0];
                }
                $buckets[$key]['c']++;
                $buckets[$key]['r'] += $r;
                $buckets[$key]['g'] += $g;
                $buckets[$key]['b'] += $b;
            }
        }
        unset($sample);

        if ($buckets === []) {
            return '';
        }
        uasort($buckets, static function ($a, $b) {
            return $b['c'] <=> $a['c'];
        });
        $top = reset($buckets);
        $c = max(1, (int)$top['c']);
        return brand_theme_rgb_to_hex(
            (int)round($top['r'] / $c),
            (int)round($top['g'] / $c),
            (int)round($top['b'] / $c)
        );
    }
}

if (!function_exists('brand_theme_ensure_columns')) {
    function brand_theme_ensure_columns(PDO $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        if (migration_esta_aplicada($db, BRAND_THEME_SCHEMA_MIGRATION_ID)) {
            $done = true;
            return;
        }
        $done = true;
        try {
            $cols = [];
            $stmt = $db->query('SHOW COLUMNS FROM restaurantes');
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $cols[strtolower((string)($row['Field'] ?? ''))] = true;
            }
            if (empty($cols['cor_primaria'])) {
                $db->exec("ALTER TABLE restaurantes ADD COLUMN cor_primaria VARCHAR(7) NULL DEFAULT NULL");
            }
            if (empty($cols['cor_secundaria'])) {
                $db->exec("ALTER TABLE restaurantes ADD COLUMN cor_secundaria VARCHAR(7) NULL DEFAULT NULL");
            }
        } catch (Throwable $e) {
            // Silencioso: ambientes sem permissão ALTER continuam com fallback.
        }
    }
}

if (!function_exists('brand_theme_resolve')) {
    /**
     * @return array{
     *   primary:string,accent:string,primary_dark:string,primary_soft:string,
     *   accent_soft:string,on_primary:string,source:string,css:string
     * }
     */
    function brand_theme_resolve(array $restaurante, string $logoAbsoluteHint = ''): array
    {
        $nome = trim((string)($restaurante['nome'] ?? ''));
        $primary = brand_theme_normalize_hex($restaurante['cor_primaria'] ?? $restaurante['cor_primary'] ?? '');
        $accent = brand_theme_normalize_hex($restaurante['cor_secundaria'] ?? $restaurante['cor_accent'] ?? '');
        $source = 'default';

        if ($primary !== '') {
            $source = 'db';
        } else {
            $logo = trim((string)($logoAbsoluteHint !== '' ? $logoAbsoluteHint : ($restaurante['logo'] ?? '')));
            $fromLogo = brand_theme_extract_from_logo($logo);
            if ($fromLogo !== '') {
                $primary = $fromLogo;
                $source = 'logo';
            } else {
                $primary = brand_theme_from_name($nome);
                $source = 'name';
            }
        }

        if ($accent === '') {
            // Accent: mistura com ouro quente para manter contraste legível
            $accent = brand_theme_mix($primary, '#F7931E', 0.45);
            if (brand_theme_normalize_hex($accent) === $primary) {
                $accent = brand_theme_mix($primary, '#FFFFFF', 0.28);
            }
        }

        $primaryDark = brand_theme_mix($primary, '#000000', 0.28);
        $onPrimary = brand_theme_luminance($primary) > 0.62 ? '#1A1008' : '#FFF8F0';

        $theme = [
            'primary' => $primary,
            'accent' => $accent,
            'primary_dark' => $primaryDark,
            'primary_soft' => brand_theme_rgba($primary, 0.14),
            'accent_soft' => brand_theme_rgba($accent, 0.16),
            'on_primary' => $onPrimary,
            'source' => $source,
        ];

        $theme['css'] = brand_theme_css_vars($theme);
        return $theme;
    }
}

if (!function_exists('brand_theme_css_vars')) {
    function brand_theme_css_vars(array $theme): string
    {
        $p = $theme['primary'] ?? '#E8893A';
        $a = $theme['accent'] ?? '#FFC078';
        $pd = $theme['primary_dark'] ?? brand_theme_mix($p, '#000000', 0.28);
        $ps = $theme['primary_soft'] ?? brand_theme_rgba($p, 0.14);
        $as = $theme['accent_soft'] ?? brand_theme_rgba($a, 0.16);
        $on = $theme['on_primary'] ?? '#FFF8F0';

        return sprintf(
            '--brand-primary:%1$s;--brand-primary-strong:%2$s;--brand-secondary:%3$s;'
            . '--brand-primary-soft:%4$s;--brand-accent-soft:%5$s;--brand-on-primary:%6$s;'
            . '--primary:%1$s;--primary-dark:%2$s;--secondary:%3$s;'
            . '--plan-accent:%1$s;--plan-accent-2:%3$s;--plan-accent-soft:%4$s;'
            . '--ed-copper:%1$s;--ed-gold:%3$s;',
            $p,
            $pd,
            $a,
            $ps,
            $as,
            $on
        );
    }
}

if (!function_exists('brand_theme_for_restaurante_id')) {
    function brand_theme_for_restaurante_id(PDO $db, int $restauranteId): array
    {
        if ($restauranteId <= 0) {
            return brand_theme_resolve(['nome' => 'Nivora Restaurant']);
        }
        brand_theme_ensure_columns($db);
        try {
            $stmt = $db->prepare('SELECT nome, logo, cor_primaria, cor_secundaria FROM restaurantes WHERE id = :id LIMIT 1');
            $stmt->bindValue(':id', $restauranteId, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['nome' => 'Nivora Restaurant'];
            return brand_theme_resolve($row);
        } catch (Throwable $e) {
            try {
                $stmt = $db->prepare('SELECT nome, logo FROM restaurantes WHERE id = :id LIMIT 1');
                $stmt->bindValue(':id', $restauranteId, PDO::PARAM_INT);
                $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['nome' => 'Nivora Restaurant'];
                return brand_theme_resolve($row);
            } catch (Throwable $e2) {
                return brand_theme_resolve(['nome' => 'Nivora Restaurant']);
            }
        }
    }
}
