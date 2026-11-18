<?php

/*
 * O nome do sistema, num sitio so.
 *
 * Estava escrito a mao em 18 lugares como "RestauranteSaaS": nos separadores
 * do browser de 14 paginas, no talao que o restaurante imprime e entrega ao
 * cliente, e no rodape dos alertas de seguranca. Nenhum deles seguia a marca.
 *
 * Vem do APP_NAME. Se a variavel faltar, o nome cai para "Nivora Restaurant" e
 * nao para um nome antigo -- um valor de recurso errado e pior do que nenhum,
 * porque ninguem repara nele.
 */

if (!function_exists('nv_nome_sistema')) {
    function nv_nome_sistema(): string
    {
        static $nome = null;
        if ($nome !== null) {
            return $nome;
        }

        $valor = '';
        if (function_exists('saas_email_env_value')) {
            $valor = trim((string)(saas_email_env_value('APP_NAME') ?? ''));
        }
        if ($valor === '') {
            $valor = trim((string)(getenv('APP_NAME') ?: ''));
        }
        if ($valor === '' && isset($_ENV['APP_NAME'])) {
            $valor = trim((string)$_ENV['APP_NAME']);
        }

        return $nome = ($valor !== '' ? $valor : 'Nivora Restaurant');
    }
}

if (!function_exists('nv_nome_sistema_html')) {
    /** Para usar dentro de HTML, ja escapado. */
    function nv_nome_sistema_html(): string
    {
        return htmlspecialchars(nv_nome_sistema(), ENT_QUOTES, 'UTF-8');
    }
}
