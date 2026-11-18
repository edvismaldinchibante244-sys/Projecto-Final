<?php

/*
 * Cor do plano nas notificacoes.
 *
 * Cada plano tem a sua cor em planos.php (Basico cinzento, Profissional
 * azul-petroleo, Empresarial roxo). Este ficheiro serve para a trazer para os
 * emails sem arrastar o plano_check.php, que precisa de base de dados.
 *
 * ATENCAO a quem mexer nisto: a faixa e a etiqueta dizem de QUE PLANO e o
 * restaurante. NAO use estas cores nos titulos dos emails: la o verde quer
 * dizer aprovado, o vermelho rejeitado e o laranja a vencer, e quem recebe
 * percebe isso antes de ler a primeira palavra. Pintar um "Pedido rejeitado"
 * de roxo so porque o restaurante e Empresarial apaga esse aviso.
 */

if (!function_exists('plano_cor_info')) {
    /**
     * Aceita o codigo ou o nome do plano, com ou sem acentos ("EMPRESARIAL",
     * "Empresarial", "Basico", "Básico", "ENTERPRISE"). Devolve sempre algo
     * utilizavel: em caso de duvida, o Basico.
     *
     * @return array{codigo:string,nome:string,cor:string}
     */
    function plano_cor_info($plano): array
    {
        static $tabela = null;
        if ($tabela === null) {
            $tabela = [];
            $def = @include __DIR__ . '/planos.php';
            if (is_array($def)) {
                foreach ($def as $codigo => $dados) {
                    $tabela[(string)$codigo] = [
                        'codigo' => (string)$codigo,
                        'nome' => (string)($dados['nome'] ?? $codigo),
                        'cor' => (string)($dados['cor'] ?? '#6c757d'),
                    ];
                }
            }
        }

        $chave = strtoupper(trim((string)$plano));
        // Tirar acentos para que "Básico" encontre "BASICO".
        $chave = strtr($chave, [
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A',
            'É' => 'E', 'Ê' => 'E', 'Í' => 'I',
            'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ú' => 'U', 'Ç' => 'C',
        ]);
        if ($chave === 'ENTERPRISE') {
            $chave = 'EMPRESARIAL';
        }

        if (isset($tabela[$chave])) {
            return $tabela[$chave];
        }

        return $tabela['BASICO'] ?? ['codigo' => 'BASICO', 'nome' => 'Básico', 'cor' => '#6c757d'];
    }
}

if (!function_exists('plano_cor')) {
    function plano_cor($plano): string
    {
        return plano_cor_info($plano)['cor'];
    }
}

if (!function_exists('plano_email_etiqueta')) {
    /**
     * Etiqueta com o nome do plano, na cor do plano. Vai ao lado do nome do
     * sistema, no topo do cartao do email.
     */
    function plano_email_etiqueta($plano): string
    {
        $info = plano_cor_info($plano);
        $nome = htmlspecialchars($info['nome'], ENT_QUOTES, 'UTF-8');
        $cor = htmlspecialchars($info['cor'], ENT_QUOTES, 'UTF-8');

        return "<span style='display:inline-block;padding:3px 10px;border-radius:999px;"
            . "background:{$cor};color:#ffffff;font-size:11px;font-weight:bold;"
            . "letter-spacing:.06em;text-transform:uppercase;'>{$nome}</span>";
    }
}
