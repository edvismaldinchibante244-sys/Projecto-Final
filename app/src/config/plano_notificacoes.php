<?php

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
        if (function_exists('saas_email_env_value')) {
            $envName = saas_email_env_value('APP_NAME');
            if (is_string($envName) && trim($envName) !== '') {
                return trim($envName);
            }
        }
        return 'RestaurantESA';
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

        $nome = plano_notif_escape($restauranteNome);
        $plano = plano_notif_escape($planoNovo);
        $cicloLabel = plano_notif_escape(plano_notif_ciclo_label($ciclo));
        $met = plano_notif_escape($metodo);
        $valorFmt = number_format((float)$valor, 2, ',', '.');

        $systemName = plano_notif_escape(plano_notif_system_name());
        $assunto = 'Solicitacao de plano recebida - ' . $nome;
        $corpo = "
        <html><body style='font-family:Arial,sans-serif;line-height:1.6;color:#333;'>
        <div style='max-width:620px;margin:0 auto;padding:20px;border:1px solid #e0e0e0;border-radius:8px;'>
            <div style='font-size:12px;color:#888;margin-bottom:10px;letter-spacing:.08em;text-transform:uppercase;'>{$systemName}</div>
            <h2 style='color:#0d6efd;'>Solicitacao recebida</h2>
            <p>Ola, equipa <strong>{$nome}</strong>!</p>
            <p>Recebemos a sua solicitacao de plano e ela esta em analise.</p>
            <table style='border-collapse:collapse;width:100%;margin:16px 0;'>
                <tr><td style='padding:8px;background:#f8f9fa;font-weight:bold;width:40%;'>Plano:</td><td style='padding:8px;'>{$plano}</td></tr>
                <tr><td style='padding:8px;background:#f8f9fa;font-weight:bold;'>Ciclo:</td><td style='padding:8px;'>{$cicloLabel}</td></tr>
                <tr><td style='padding:8px;background:#f8f9fa;font-weight:bold;'>Valor:</td><td style='padding:8px;'>{$valorFmt} MZN</td></tr>
                <tr><td style='padding:8px;background:#f8f9fa;font-weight:bold;'>Metodo:</td><td style='padding:8px;'>{$met}</td></tr>
            </table>
            <p>Voce recebera nova notificacao quando for aprovada ou rejeitada.</p>
            <hr>
            <p style='font-size:12px;color:#888;'>Este e um email automatico.</p>
        </div></body></html>";

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

        $nome = plano_notif_escape($restauranteNome);
        $plano = plano_notif_escape($planoNovo);
        $cicloLabel = plano_notif_escape(plano_notif_ciclo_label($ciclo));
        $dataFimFmt = plano_notif_escape(date('d/m/Y', strtotime((string)$dataFim)));

        $systemName = plano_notif_escape(plano_notif_system_name());
        $assunto = 'Plano ativado com sucesso - ' . $nome;
        $corpo = "
        <html><body style='font-family:Arial,sans-serif;line-height:1.6;color:#333;'>
        <div style='max-width:620px;margin:0 auto;padding:20px;border:1px solid #e0e0e0;border-radius:8px;'>
            <div style='font-size:12px;color:#888;margin-bottom:10px;letter-spacing:.08em;text-transform:uppercase;'>{$systemName}</div>
            <h2 style='color:#28a745;'>Plano aprovado</h2>
            <p>Ola, equipa <strong>{$nome}</strong>!</p>
            <p>Seu plano foi ativado com sucesso.</p>
            <table style='border-collapse:collapse;width:100%;margin:16px 0;'>
                <tr><td style='padding:8px;background:#f8f9fa;font-weight:bold;width:40%;'>Plano:</td><td style='padding:8px;'>{$plano}</td></tr>
                <tr><td style='padding:8px;background:#f8f9fa;font-weight:bold;'>Ciclo:</td><td style='padding:8px;'>{$cicloLabel}</td></tr>
                <tr><td style='padding:8px;background:#f8f9fa;font-weight:bold;'>Valido ate:</td><td style='padding:8px;'>{$dataFimFmt}</td></tr>
            </table>
            <hr>
            <p style='font-size:12px;color:#888;'>Este e um email automatico.</p>
        </div></body></html>";

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

        $nome = plano_notif_escape($restauranteNome);
        $plano = plano_notif_escape($planoNovo);
        $mot = plano_notif_escape($motivo ?: 'Nao informado');

        $systemName = plano_notif_escape(plano_notif_system_name());
        $assunto = 'Pedido de plano rejeitado - ' . $nome;
        $corpo = "
        <html><body style='font-family:Arial,sans-serif;line-height:1.6;color:#333;'>
        <div style='max-width:620px;margin:0 auto;padding:20px;border:1px solid #e0e0e0;border-radius:8px;'>
            <div style='font-size:12px;color:#888;margin-bottom:10px;letter-spacing:.08em;text-transform:uppercase;'>{$systemName}</div>
            <h2 style='color:#dc3545;'>Pedido rejeitado</h2>
            <p>Ola, equipa <strong>{$nome}</strong>!</p>
            <p>Seu pedido para o plano <strong>{$plano}</strong> foi rejeitado.</p>
            <p><strong>Motivo:</strong> {$mot}</p>
            <p>Voce pode corrigir os dados e enviar nova solicitacao.</p>
            <hr>
            <p style='font-size:12px;color:#888;'>Este e um email automatico.</p>
        </div></body></html>";

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

        $nome = plano_notif_escape($restauranteNome);
        $plano = plano_notif_escape($planoAtual);
        $dataFimFmt = plano_notif_escape(date('d/m/Y', strtotime((string)$dataFim)));
        $dias = (int)$diasRestantes;

        $systemName = plano_notif_escape(plano_notif_system_name());
        $tag = $dias === 1 ? 'URGENTE' : ($dias === 3 ? 'IMPORTANTE' : 'AVISO');
        $assunto = '[' . $tag . '] Vencimento do plano em ' . $dias . ' dia(s) - ' . $nome;
        $alertaExtra = '';
        if ($dias === 7) {
            $alertaExtra = "<p><strong>Aviso:</strong> faltam 7 dias. Planeie a renovacao com antecedencia.</p>";
        } elseif ($dias === 3) {
            $alertaExtra = "<p><strong>Aviso:</strong> faltam apenas 3 dias. Garanta a renovacao hoje.</p>";
        } elseif ($dias === 1) {
            $alertaExtra = "<p><strong>Urgente:</strong> seu plano expira amanha.</p>";
        }
        $ctaRenovacao = '';
        if (is_string($renovacaoUrl) && trim($renovacaoUrl) !== '') {
            $urlSafe = plano_notif_escape(trim($renovacaoUrl));
            $ctaRenovacao = "<p><a href='{$urlSafe}' style='display:inline-block;background:#0d6efd;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;'>Renovar Plano e Enviar Comprovativo</a></p>"
                . "<p>Se o botao nao abrir, copie e cole no navegador:<br><span style='word-break:break-all;'>{$urlSafe}</span></p>";
        }

        $corpo = "
        <html><body style='font-family:Arial,sans-serif;line-height:1.6;color:#333;'>
        <div style='max-width:620px;margin:0 auto;padding:20px;border:1px solid #e0e0e0;border-radius:8px;'>
            <div style='font-size:12px;color:#888;margin-bottom:10px;letter-spacing:.08em;text-transform:uppercase;'>{$systemName}</div>
            <h2 style='color:#fd7e14;'>Vencimento proximo</h2>
            <p>Ola, equipa <strong>{$nome}</strong>!</p>
            <p>Seu plano <strong>{$plano}</strong> vence em <strong>{$dias} dia(s)</strong>.</p>
            <p><strong>Data de vencimento:</strong> {$dataFimFmt}</p>
            {$alertaExtra}
            <p>Recomendamos iniciar a renovacao para evitar interrupcao.</p>
            {$ctaRenovacao}
            <hr>
            <p style='font-size:12px;color:#888;'>Este e um email automatico.</p>
        </div></body></html>";

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
    function plano_notificar_bonus_lembrete($email, $telefone, $restauranteNome, $planoBonus, $planoPago, $diasRestantes, $url = null)
    {
        if (empty($email)) {
            return false;
        }

        $nome = plano_notif_escape($restauranteNome);
        $bonus = plano_notif_escape($planoBonus);
        $pago = plano_notif_escape($planoPago);
        $dias = max(0, (int)$diasRestantes);
        $systemName = plano_notif_escape(plano_notif_system_name());
        $link = plano_notif_escape($url ?: '');

        $quando = $dias <= 0 ? 'Termina hoje' : ($dias === 1 ? 'Falta 1 dia' : 'Faltam ' . $dias . ' dias');
        $assunto = $quando . ' no plano ' . $bonus . ' - ' . $nome;
        $corpo = "
        <html><body style='font-family:Arial,sans-serif;line-height:1.6;color:#333;'>
        <div style='max-width:620px;margin:0 auto;padding:20px;border:1px solid #e0e0e0;border-radius:8px;'>
            <div style='font-size:12px;color:#888;margin-bottom:10px;letter-spacing:.08em;text-transform:uppercase;'>{$systemName}</div>
            <h2 style='color:#0284c7;'>{$quando} de oferta</h2>
            <p>Ola, equipa <strong>{$nome}</strong>!</p>
            <p>Esta a usar o plano <strong>{$bonus}</strong> por oferta de boas-vindas. {$quando} — depois o restaurante volta ao plano <strong>{$pago}</strong>, que e o que paga.</p>
            <p>Nada se perde: tudo o que criou continua la. Se quiser ficar com o {$bonus}, pode mudar de plano no sistema.</p>"
            . ($link !== '' ? "<p><a href='{$link}' style='display:inline-block;padding:10px 18px;background:#0284c7;color:#fff;border-radius:6px;text-decoration:none;'>Ficar com o {$bonus}</a></p>" : '') . "
            <hr>
            <p style='font-size:12px;color:#888;'>Este e um email automatico.</p>
        </div></body></html>";

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

        $nome = plano_notif_escape($restauranteNome);
        $bonus = plano_notif_escape($planoBonus);
        $pago = plano_notif_escape($planoPago);
        $systemName = plano_notif_escape(plano_notif_system_name());
        $link = plano_notif_escape($url ?: '');

        $linhasUso = '';
        foreach ($uso as $item) {
            $linhasUso .= "<tr><td style='padding:8px;background:#f8f9fa;font-weight:bold;width:40%;'>"
                . (int)($item['valor'] ?? 0) . "</td><td style='padding:8px;'>"
                . plano_notif_escape((string)($item['rotulo'] ?? '')) . "</td></tr>";
        }

        $assunto = 'A oferta no plano ' . $bonus . ' terminou - ' . $nome;
        $corpo = "
        <html><body style='font-family:Arial,sans-serif;line-height:1.6;color:#333;'>
        <div style='max-width:620px;margin:0 auto;padding:20px;border:1px solid #e0e0e0;border-radius:8px;'>
            <div style='font-size:12px;color:#888;margin-bottom:10px;letter-spacing:.08em;text-transform:uppercase;'>{$systemName}</div>
            <h2 style='color:#475569;'>A oferta terminou</h2>
            <p>Ola, equipa <strong>{$nome}</strong>!</p>
            <p>Os dias no plano <strong>{$bonus}</strong> terminaram e o restaurante voltou ao plano <strong>{$pago}</strong>, que e o que paga. <strong>Nada foi apagado</strong>: tudo o que criou continua acessivel.</p>"
            . ($linhasUso !== '' ? "<p>Durante a oferta:</p><table style='border-collapse:collapse;width:100%;margin:16px 0;'>{$linhasUso}</table>" : '')
            . ($link !== '' ? "<p><a href='{$link}' style='display:inline-block;padding:10px 18px;background:#0284c7;color:#fff;border-radius:6px;text-decoration:none;'>Voltar ao {$bonus}</a></p>" : '') . "
            <hr>
            <p style='font-size:12px;color:#888;'>Este e um email automatico.</p>
        </div></body></html>";

        $enviado = saas_enviar_email($email, $assunto, $corpo);
        plano_notif_tentar_whatsapp($telefone, 'A oferta no plano ' . $planoBonus . ' terminou. Nada foi apagado.');
        return $enviado;
    }
}
