<?php

require_once __DIR__ . '/plano_cores.php';
require_once __DIR__ . '/email_layout.php';

/*
   Notificacoes de plano (email + ponto de extensao para WhatsApp).
*/

include_once __DIR__ . '/email_helper.php';

if (!function_exists('plano_notif_escape')) {
    function plano_notif_escape($valor)
    {
        return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('plano_notif_ciclo_label')) {
    function plano_notif_ciclo_label($ciclo)
    {
        $c = strtoupper(trim((string)$ciclo));
        return [
            'MENSAL' => 'Mensal',
            'TRIMESTRAL' => 'Trimestral',
            'ANUAL' => 'Anual'
        ][$c] ?? $c;
    }
}

if (!function_exists('plano_notif_tentar_whatsapp')) {
    function plano_notif_tentar_whatsapp($telefone, $mensagem)
    {
        if (empty($telefone)) {
            return false;
        }

        // Ponto de extensao: integrar gateway WhatsApp futuramente.
        error_log('[plano_notif_whatsapp_stub] telefone=' . $telefone . ' msg=' . substr((string)$mensagem, 0, 120));
        return false;
    }
}

if (!function_exists('plano_notif_system_name')) {
    function plano_notif_system_name(): string
    {
        // Uma fonte so para o nome do sistema. Antes tinha aqui o seu proprio
        // valor de recurso ('RestaurantESA') e as paginas tinham outro
        // ('RestauranteSaaS'): dois nomes errados, nenhum deles a marca.
        require_once __DIR__ . '/marca.php';

        return nv_nome_sistema();
    }
}

if (!function_exists('plano_notif_support_email')) {
    function plano_notif_support_email(): string
    {
        if (function_exists('saas_email_env_value')) {
            $env = saas_email_env_value('SUPPORT_EMAIL');
            if (is_string($env) && trim($env) !== '') {
                return trim($env);
            }
        }
        return 'edvismaldinchibante244@gmail.com';
    }
}

if (!function_exists('plano_notif_send_support_copy')) {
    function plano_notif_send_support_copy(string $assunto, string $corpo): void
    {
        $support = plano_notif_support_email();
        if ($support === '') {
            return;
        }
        @saas_enviar_email($support, '[Copia] ' . $assunto, $corpo);
    }
}

if (!function_exists('plano_notificar_solicitacao_recebida')) {
    function plano_notificar_solicitacao_recebida($email, $telefone, $restauranteNome, $planoNovo, $ciclo, $valor, $metodo)
    {
        if (empty($email)) {
            return false;
        }

        $valorFmt = number_format((float)$valor, 2, ',', '.');

        // Assunto com o nome em bruto: aqui nao se escapa HTML, senao um
        // restaurante chamado "Ze & Cia" aparecia como "Ze &amp; Cia".
        $nomePlano = plano_cor_info($planoNovo)['nome'];
        $assunto = 'Pedido de plano recebido - ' . $restauranteNome;
        $corpo = nivora_email_layout([
            'sobretitulo' => 'Pedido recebido',
            'sobretitulo_cor' => '#0369a1',
            'titulo' => 'Recebemos o seu pedido de plano',
            'saudacao' => $restauranteNome,
            'paragrafos' => ['O seu pedido está em análise. Avisamos assim que houver decisão.'],
            'dados' => [
                'Plano' => $nomePlano,
                'Ciclo' => plano_notif_ciclo_label($ciclo),
                'Valor' => $valorFmt . ' MZN',
                'Método' => $metodo,
            ],
            'plano' => $planoNovo,
        ]);

        $enviado = saas_enviar_email($email, $assunto, $corpo);
        plano_notif_send_support_copy($assunto, $corpo);
        plano_notif_tentar_whatsapp($telefone, 'Recebemos a sua solicitacao de plano. Em breve enviaremos o resultado.');
        return $enviado;
    }
}

if (!function_exists('plano_notificar_aprovado')) {
    function plano_notificar_aprovado($email, $telefone, $restauranteNome, $planoNovo, $ciclo, $dataFim)
    {
        if (empty($email)) {
            return false;
        }


        $nomePlano = plano_cor_info($planoNovo)['nome'];
        $assunto = 'Plano activo - ' . $restauranteNome;
        $corpo = nivora_email_layout([
            'sobretitulo' => 'Plano aprovado',
            'sobretitulo_cor' => '#15803d',
            'titulo' => 'O seu plano está activo',
            'saudacao' => $restauranteNome,
            'paragrafos' => ['O pagamento foi confirmado e o plano já está a funcionar.'],
            'dados' => [
                'Plano' => $nomePlano,
                'Ciclo' => plano_notif_ciclo_label($ciclo),
                'Válido até' => date('d/m/Y', strtotime((string)$dataFim)),
            ],
            'plano' => $planoNovo,
        ]);

        $enviado = saas_enviar_email($email, $assunto, $corpo);
        plano_notif_send_support_copy($assunto, $corpo);
        plano_notif_tentar_whatsapp($telefone, 'Seu plano foi aprovado e ja esta ativo.');
        return $enviado;
    }
}

if (!function_exists('plano_notificar_rejeitado')) {
    function plano_notificar_rejeitado($email, $telefone, $restauranteNome, $planoNovo, $motivo)
    {
        if (empty($email)) {
            return false;
        }


        $nomePlano = plano_cor_info($planoNovo)['nome'];
        $assunto = 'Pedido de plano nao aprovado - ' . $restauranteNome;
        $corpo = nivora_email_layout([
            'sobretitulo' => 'Pedido rejeitado',
            'sobretitulo_cor' => '#b91c1c',
            'titulo' => 'O seu pedido não foi aprovado',
            'saudacao' => $restauranteNome,
            'paragrafos' => ['O pedido para o plano ' . $nomePlano . ' não foi aprovado.'],
            'destaque' => $motivo ?: 'Não indicado',
            'destaque_titulo' => 'Motivo',
            'nota' => 'Pode corrigir os dados e enviar um pedido novo pelo painel.',
            'plano' => $planoNovo,
        ]);

        $enviado = saas_enviar_email($email, $assunto, $corpo);
        plano_notif_send_support_copy($assunto, $corpo);
        plano_notif_tentar_whatsapp($telefone, 'Seu pedido de plano foi rejeitado. Verifique o motivo e reenviar.');
        return $enviado;
    }
}

if (!function_exists('plano_notificar_vencimento_proximo')) {
    function plano_notificar_vencimento_proximo($email, $telefone, $restauranteNome, $planoAtual, $dataFim, $diasRestantes, $renovacaoUrl = null)
    {
        if (empty($email)) {
            return false;
        }

        $dias = (int)$diasRestantes;

        $tag = $dias === 1 ? 'URGENTE' : ($dias === 3 ? 'IMPORTANTE' : 'AVISO');
        $nomePlano = plano_cor_info($planoAtual)['nome'];
        $assunto = '[' . $tag . '] O plano vence em ' . $dias . ' dia(s) - ' . $restauranteNome;
        $textoAlerta = '';
        if ($dias === 7) {
            $textoAlerta = 'Faltam 7 dias. Planeie a renovação com antecedência.';
        } elseif ($dias === 3) {
            $textoAlerta = 'Faltam apenas 3 dias. Garanta a renovação hoje.';
        } elseif ($dias === 1) {
            $textoAlerta = 'O plano expira amanhã.';
        }
        $urlRenovacao = is_string($renovacaoUrl) ? trim($renovacaoUrl) : '';

        $corpo = nivora_email_layout([
            'sobretitulo' => $tag,
            'sobretitulo_cor' => $dias === 1 ? '#b91c1c' : '#c2410c',
            'titulo' => $dias === 1
                ? 'O seu plano expira amanhã'
                : 'O seu plano vence em ' . $dias . ' dias',
            'saudacao' => $restauranteNome,
            'paragrafos' => ['Renove antes da data para o serviço não parar.'],
            'dados' => [
                'Plano' => $nomePlano,
                'Vence a' => date('d/m/Y', strtotime((string)$dataFim)),
                'Faltam' => $dias . ($dias === 1 ? ' dia' : ' dias'),
            ],
            'destaque' => $textoAlerta,
            'destaque_titulo' => $textoAlerta !== '' ? 'Atenção' : '',
            'botao_texto' => $urlRenovacao !== '' ? 'Renovar e enviar comprovativo' : '',
            'botao_url' => $urlRenovacao,
            'plano' => $planoAtual,
        ]);

        $enviado = saas_enviar_email($email, $assunto, $corpo);
        plano_notif_send_support_copy($assunto, $corpo);
        plano_notif_tentar_whatsapp($telefone, 'Seu plano vence em ' . $dias . ' dia(s). Inicie a renovacao para evitar bloqueio.');
        return $enviado;
    }
}

if (!function_exists('plano_notificar_bonus_lembrete')) {
    /**
     * Lembrete a meio da oferta de boas-vindas (7.º e 12.º dia).
     */
    /**
     * @param array $uso Lista de ['rotulo' => string, 'valor' => int] com o que
     *                   o restaurante construiu durante a oferta. Dito agora,
     *                   enquanto ainda da para decidir, e nao so no fim.
     */
    function plano_notificar_bonus_lembrete($email, $telefone, $restauranteNome, $planoBonus, $planoPago, $diasRestantes, $url = null, array $uso = [])
    {
        if (empty($email)) {
            return false;
        }

        $nomeBonus = plano_cor_info($planoBonus)['nome'];
        $nomePago = plano_cor_info($planoPago)['nome'];
        $dias = max(0, (int)$diasRestantes);
        $link = plano_notif_escape($url ?: '');

        $quando = $dias <= 0 ? 'Termina hoje' : ($dias === 1 ? 'Falta 1 dia' : 'Faltam ' . $dias . ' dias');
        $assunto = $quando . ' no plano ' . $nomeBonus . ' - ' . $restauranteNome;

        // O que construiu com o plano de cima. No fim da oferta isto ja era
        // dito; dize-lo agora e que muda alguma coisa, porque ainda da tempo
        // de decidir.
        // O que construiu com o plano de cima, dito enquanto ainda da para decidir.
        $dadosUso = [];
        foreach ($uso as $item) {
            $valorUso = (int)($item['valor'] ?? 0);
            $rotuloUso = trim((string)($item['rotulo'] ?? ''));
            if ($valorUso > 0 && $rotuloUso !== '') {
                $dadosUso[$rotuloUso] = (string)$valorUso;
            }
        }

        $corpo = nivora_email_layout([
            'sobretitulo' => $quando . ' de oferta',
            'sobretitulo_cor' => '#0369a1',
            'titulo' => $quando . ' no plano ' . $nomeBonus,
            'saudacao' => $restauranteNome,
            'paragrafos' => [
                'Está a usar o ' . $nomeBonus . ' por oferta de boas-vindas. Quando acabar, o restaurante volta ao ' . $nomePago . ', que é o plano que paga.',
                'Nada se perde: tudo o que criou continua lá. Deixa é de o poder gerir pelo ' . $nomePago . ' até voltar a subir de plano.',
            ],
            'dados' => $dadosUso,
            'botao_texto' => $link !== '' ? 'Ficar com o ' . $nomeBonus : '',
            'botao_url' => $link,
            'plano' => $planoBonus,
        ]);

        $enviado = saas_enviar_email($email, $assunto, $corpo);
        plano_notif_tentar_whatsapp($telefone, $quando . ' no plano ' . $planoBonus . ' por oferta de boas-vindas.');
        return $enviado;
    }
}

if (!function_exists('plano_notificar_bonus_terminado')) {
    /**
     * Fim da oferta: o que o restaurante fez com o plano de cima.
     *
     * @param array $uso lista de ['rotulo' => string, 'valor' => int]
     */
    function plano_notificar_bonus_terminado($email, $telefone, $restauranteNome, $planoBonus, $planoPago, array $uso = [], $url = null)
    {
        if (empty($email)) {
            return false;
        }

        $nomeBonus = plano_cor_info($planoBonus)['nome'];
        $nomePago = plano_cor_info($planoPago)['nome'];
        $link = plano_notif_escape($url ?: '');

        $dadosUso = [];
        foreach ($uso as $item) {
            $valorUso = (int)($item['valor'] ?? 0);
            $rotuloUso = trim((string)($item['rotulo'] ?? ''));
            if ($valorUso > 0 && $rotuloUso !== '') {
                $dadosUso[$rotuloUso] = (string)$valorUso;
            }
        }

        $assunto = 'A oferta no plano ' . $nomeBonus . ' terminou - ' . $restauranteNome;
        $corpo = nivora_email_layout([
            'sobretitulo' => 'A oferta terminou',
            'sobretitulo_cor' => '#475569',
            'titulo' => 'Voltou ao plano ' . $nomePago,
            'saudacao' => $restauranteNome,
            'paragrafos' => [
                'Os dias no ' . $nomeBonus . ' terminaram e o restaurante voltou ao ' . $nomePago . '.',
                'Nada foi apagado: tudo o que criou continua acessível.',
            ],
            'dados' => $dadosUso,
            'botao_texto' => $link !== '' ? 'Voltar ao ' . $nomeBonus : '',
            'botao_url' => $link,
            'plano' => $planoPago,
        ]);

        $enviado = saas_enviar_email($email, $assunto, $corpo);
        plano_notif_tentar_whatsapp($telefone, 'A oferta no plano ' . $planoBonus . ' terminou. Nada foi apagado.');
        return $enviado;
    }
}
