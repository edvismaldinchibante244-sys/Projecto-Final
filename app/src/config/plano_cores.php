<?php

/*
 * Cor do plano nas notificacoes.
 *
 * A FONTE DESTAS CORES E O src/public/css/tokens.css, no bloco
 * body.plan-theme-<plano>. E a cor que o restaurante ve todos os dias no
 * painel: Basico ardosia, Profissional azul-petroleo, Empresarial laranja.
 * Foram escolhidas para passarem AA com texto branco por cima.
 *
 * NAO use o 'cor' do planos.php para isto. Esse campo tem valores antigos
 * (#6c757d, #17a2b8, #6f42c1) que ja nao sao o tema do sistema -- o
 * Empresarial la e roxo e no painel e laranja. Ja mandamos emails assinados
 * com a cor errada por causa disso.
 *
 * Os valores estao repetidos aqui porque o PHP nao vai ler CSS a cada email.
 * Para a repeticao nao se separar outra vez, o tests/Unit/PlanoCoresTest.php
 * le o tokens.css e compara. Se mudar uma cor la, o teste falha aqui.
 *
 * ATENCAO: esta cor diz de QUE PLANO e o restaurante. Nao a use no titulo dos
 * emails: la o verde quer dizer aprovado, o vermelho rejeitado e o laranja a
 * vencer, e quem recebe percebe isso antes de ler a primeira palavra.
 */

if (!function_exists('plano_cor_info')) {
    /**
     * Aceita codigo ou nome, com ou sem acentos ("EMPRESARIAL", "Empresarial",
     * "Básico", "ENTERPRISE"). Em caso de duvida devolve o Basico.
     *
     * @return array{codigo:string,nome:string,cor:string,suave:string}
     */
    function plano_cor_info($plano): array
    {
        // Tem de ser igual ao body.plan-theme-<plano> do tokens.css.
        static $tema = [
            'BASICO' => ['nome' => 'Básico', 'cor' => '#475569', 'suave' => '#f1f5f9'],
            'PROFISSIONAL' => ['nome' => 'Profissional', 'cor' => '#0e7490', 'suave' => '#ecfeff'],
            'EMPRESARIAL' => ['nome' => 'Empresarial', 'cor' => '#c2410c', 'suave' => '#fff7ed'],
        ];

        $chave = strtoupper(trim((string)$plano));
        $chave = strtr($chave, [
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A',
            'É' => 'E', 'Ê' => 'E', 'Í' => 'I',
            'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ú' => 'U', 'Ç' => 'C',
        ]);
        if ($chave === 'ENTERPRISE') {
            $chave = 'EMPRESARIAL';
        }

        $dados = $tema[$chave] ?? $tema['BASICO'];

        return [
            'codigo' => isset($tema[$chave]) ? $chave : 'BASICO',
            'nome' => $dados['nome'],
            'cor' => $dados['cor'],
            'suave' => $dados['suave'],
        ];
    }
}

if (!function_exists('plano_cor')) {
    function plano_cor($plano): string
    {
        return plano_cor_info($plano)['cor'];
    }
}

if (!function_exists('plano_cor_suave')) {
    function plano_cor_suave($plano): string
    {
        return plano_cor_info($plano)['suave'];
    }
}
