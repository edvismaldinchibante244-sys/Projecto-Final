<?php

/**
 * Normalização leve de nomes de produtos/categorias no cadastro.
 */
if (!function_exists('catalogo_nome_normalizar')) {
    function catalogo_nome_normalizar(string $nome): array
    {
        $original = trim(preg_replace('/\s+/u', ' ', $nome) ?? $nome);
        $corrigido = $original;

        // Ortografia comum (MZ/PT)
        $mapa = [
            '/\balcolica\b/iu' => 'Alcoólica',
            '/\balcoolica\b/iu' => 'Alcoólica',
            '/\balco[oó]l+ica\b/iu' => 'Alcoólica',
            '/\bbebida\s+alcolica\b/iu' => 'Bebida Alcoólica',
            '/\bbebida\s+alcoolica\b/iu' => 'Bebida Alcoólica',
            '/\bbebida\s+alco[oó]l+ica\b/iu' => 'Bebida Alcoólica',
            '/\bprincipal\b/iu' => 'Principal',
            '/\bprato\s+principal\b/iu' => 'Prato Principal',
        ];
        foreach ($mapa as $pattern => $replacement) {
            $corrigido = preg_replace($pattern, $replacement, $corrigido) ?? $corrigido;
        }

        // "Coca-Colaa" / letras repetidas no fim da palavra (>2)
        $corrigido = preg_replace_callback(
            '/([\p{L}])\1{2,}(?=\b)/u',
            static function (array $m): string {
                return $m[1] . $m[1];
            },
            $corrigido
        ) ?? $corrigido;

        // Duplicação acidental no fim (ex.: Colaa → Cola), só se a palavra tiver ≥5 letras
        $corrigido = preg_replace_callback(
            '/(\p{L}{4,})(\p{L})\2\b/u',
            static function (array $m): string {
                return $m[1] . $m[2];
            },
            $corrigido
        ) ?? $corrigido;

        $corrigido = trim(preg_replace('/\s+/u', ' ', $corrigido) ?? $corrigido);
        if ($corrigido === '') {
            $corrigido = $original;
        }

        return [
            'nome' => $corrigido,
            'alterado' => $corrigido !== $original,
            'original' => $original,
        ];
    }
}

if (!function_exists('cardapio_categoria_icone')) {
    function cardapio_categoria_icone(string $nome): string
    {
        $n = function_exists('mb_strtolower')
            ? mb_strtolower($nome, 'UTF-8')
            : strtolower($nome);
        $n = strtr($n, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
            'Á' => 'a', 'À' => 'a', 'Ã' => 'a', 'Â' => 'a',
            'é' => 'e', 'ê' => 'e', 'É' => 'e', 'Ê' => 'e',
            'í' => 'i', 'Í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'Ó' => 'o', 'Ô' => 'o', 'Õ' => 'o',
            'ú' => 'u', 'ü' => 'u', 'Ú' => 'u', 'Ü' => 'u',
            'ç' => 'c', 'Ç' => 'c',
        ]);

        if (preg_match('/bebida|drink|sumo|suco|cerveja|vinho|alco|refri|agua|cocktail|bar|lata|garrafa/', $n)) {
            return 'fa-glass-water';
        }
        if (preg_match('/cafe|cha\b|espresso|latte|cappuccino|mug/', $n)) {
            return 'fa-mug-hot';
        }
        if (preg_match('/sobremesa|doce|bolo|gelado|sorvete|dessert|pudim|tarte/', $n)) {
            return 'fa-ice-cream';
        }
        if (preg_match('/entrada|aperitiv|snack|salada|starter/', $n)) {
            return 'fa-bowl-food';
        }
        if (preg_match('/pizza/', $n)) {
            return 'fa-pizza-slice';
        }
        if (preg_match('/burger|hambur|sanduiche|sandwich/', $n)) {
            return 'fa-burger';
        }
        if (preg_match('/peixe|marisco|camarao|frutos do mar|seafood/', $n)) {
            return 'fa-fish';
        }
        if (preg_match('/prato|principal|refei|grelhad|carne|frango|comida|massa|risotto/', $n)) {
            return 'fa-utensils';
        }
        if (preg_match('/ › /', $nome)) {
            return 'fa-angle-right';
        }
        return 'fa-utensils';
    }
}
