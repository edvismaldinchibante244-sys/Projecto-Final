<?php

/**
 * Dados de recebimento de planos mostrados ao cliente antes do envio do comprovativo.
 * Em producao, os valores podem ser alterados por variaveis de ambiente sem editar telas.
 */
if (!function_exists('plano_pagamento_env')) {
    function plano_pagamento_env(string $key, string $default): string
    {
        $value = getenv($key);
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }
}

if (!function_exists('plano_pagamento_instrucoes_publicas')) {
    function plano_pagamento_instrucoes_publicas(): array
    {
        return [
            'MPESA' => [
                'titulo' => 'M-Pesa',
                'mensagem' => 'Envie {valor} MZN para o numero {destino} e anexe o comprovativo.',
                'destino' => plano_pagamento_env('PLAN_PAYMENT_MPESA_NUMBER', '+258 84 9087533'),
            ],
            'EMOLA' => [
                'titulo' => 'e-Mola',
                'mensagem' => 'Envie {valor} MZN por e-Mola para o numero {destino} e anexe o comprovativo.',
                'destino' => plano_pagamento_env('PLAN_PAYMENT_EMOLA_NUMBER', '+258 87 9778409'),
            ],
            'TRANSFERENCIA' => [
                'titulo' => 'Transferencia bancaria',
                'mensagem' => 'Transfira {valor} MZN para {destino} e anexe o comprovativo da operacao.',
                'destino' => plano_pagamento_env('PLAN_PAYMENT_BANK_ACCOUNTS', 'a conta BCI - #3456789088 / Millennium - #123097654'),
            ],
            'DINHEIRO' => [
                'titulo' => 'Dinheiro',
                'mensagem' => 'Contacte o administrador para combinar a entrega de {valor} MZN em dinheiro.',
                'destino' => '',
            ],
        ];
    }
}
