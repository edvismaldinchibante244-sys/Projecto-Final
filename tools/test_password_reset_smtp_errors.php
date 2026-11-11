<?php

require_once __DIR__ . '/../app/src/config/email_helper.php';

$checks = 0;
$check = static function (bool $condition, string $name) use (&$checks): void {
    if (!$condition) {
        fwrite(STDERR, "FALHA: {$name}\n");
        exit(1);
    }
    $checks++;
};

$connect = saas_email_classify_error(
    'SMTP Error: Could not connect to SMTP host. Failed to connect to server',
    'smtp.gmail.com',
    587,
    'tls'
);
$check($connect['code'] === 'SMTP_CONNECT_FAILED', 'classifica_falha_conexao');
$connectHasDetails = str_contains($connect['message'], 'SMTP_HOST=smtp.gmail.com')
    && str_contains($connect['message'], 'SMTP_PORT=587')
    && str_contains($connect['message'], 'SMTP_SECURE=TLS');
$connectHasBlockedGuidance = str_contains($connect['message'], 'hospedagem bloqueia SMTP');
$check($connectHasDetails || $connectHasBlockedGuidance, 'mensagem_orienta_conexao_smtp');
$check(str_contains($connect['detail'], 'Could not connect'), 'mantem_detalhe_tecnico');

$auth = saas_email_classify_error(
    'SMTP Error: Could not authenticate.',
    'smtp.gmail.com',
    587,
    'tls'
);
$check($auth['code'] === 'SMTP_AUTH_FAILED', 'classifica_falha_autenticacao');
$check(str_contains($auth['message'], 'senha de app'), 'mensagem_orienta_senha_app');

$unknown = saas_email_classify_error('Erro inesperado', 'smtp.gmail.com', 587, 'tls');
$check($unknown['code'] === 'SMTP_SEND_FAILED', 'classifica_erro_generico');

$resendError = saas_email_classify_error('Resend API falhou. HTTP 403 - domain is not verified');
$check($resendError['code'] === 'RESEND_API_FAILED', 'classifica_falha_resend');
$check(str_contains($resendError['message'], 'RESEND_FROM'), 'resend_orienta_remetente');
$resendSdkError = saas_email_classify_error('Resend SDK falhou: validation_error');
$check($resendSdkError['code'] === 'RESEND_API_FAILED', 'classifica_falha_sdk_resend');

$api = (string)file_get_contents(__DIR__ . '/../src/public/api/esqueci_senha.php');
$loginApi = (string)file_get_contents(__DIR__ . '/../src/public/api/login_process.php');
$page = (string)file_get_contents(__DIR__ . '/../src/public/esqueci_senha.php');
$check(str_contains($api, "'error_code' => \$smtpIssue['code']"), 'api_retorna_codigo_erro');
$check(str_contains($api, 'saas_email_classify_error'), 'api_usa_classificacao_smtp');
$check(str_contains($api, "\$publicMessage = 'Nao foi possivel enviar o email de recuperacao neste momento. ' . \$smtpIssue['message'];"), 'api_mostra_mensagem_classificada_segura');
$check(str_contains($api, "\$smtpIssue['message']"), 'api_inclui_orientacao_classificada');
$check(!str_contains($api, 'Detalhe: ' . '$emailError'), 'api_nao_expoe_erro_bruto_no_message');
$check(str_contains($page, 'id="recuperarAlert"'), 'pagina_exibe_alerta_inline');
$check(str_contains($page, 'function showMessage'), 'pagina_tem_mensagem_inline');
$check(str_contains($page, 'Link de recuperacao local'), 'pagina_exibe_link_local');
$check(str_contains($page, 'escapeHtml(data.debug_link)'), 'pagina_escapa_link_local');
$check(str_contains($loginApi, 'only send testing emails to your own email'), 'login_detecta_limite_resend_teste');
$check(str_contains($loginApi, 'Verifique um dominio na Resend'), 'login_orienta_dominio_resend');
$check(str_contains($loginApi, 'configuracao do provedor de email'), 'login_nao_limita_erro_a_smtp');

$helper = (string)file_get_contents(__DIR__ . '/../app/src/config/email_helper.php');
$envExample = (string)file_get_contents(__DIR__ . '/../.env.example');
$check(str_contains($helper, 'function saas_email_send_via_api'), 'helper_tem_envio_por_api');
$check(str_contains($helper, 'EMAIL_PROVIDER'), 'helper_le_provider_email');
$check(str_contains($helper, 'BREVO_API_KEY'), 'helper_suporta_brevo');
$check(str_contains($helper, 'RESEND_API_KEY'), 'helper_suporta_resend');
$check(str_contains($helper, "saas_email_env_value('RESEND_FROM')"), 'helper_usa_remetente_resend');
$check(str_contains($helper, "saas_email_env_value('MAIL_PASSWORD')"), 'helper_aceita_chave_resend_mail_password');
$check(str_contains($helper, "saas_email_env_value('MAIL_FROM_ADDRESS')"), 'helper_aceita_remetente_mail');
$check(str_contains($helper, "saas_email_env_value('MAIL_HOST')"), 'helper_aceita_host_mail');
$check(str_contains($helper, 'saas_email_api_error_message'), 'helper_regista_detalhe_api_seguro');
$check(str_contains($helper, 'function saas_email_send_resend_sdk'), 'helper_tem_envio_sdk_resend');
$check(str_contains($helper, '\\Resend::client($apiKey)'), 'helper_cria_cliente_sdk_resend');
$check(str_contains($helper, '$client->emails->send($payload)'), 'helper_envia_com_sdk_resend');
$check(str_contains($helper, 'SDK Resend indisponivel'), 'helper_preserva_fallback_http');
$check(str_contains($helper, 'https://api.brevo.com/v3/smtp/email'), 'endpoint_brevo_configurado');
$check(str_contains($helper, 'https://api.resend.com/emails'), 'endpoint_resend_configurado');
$check(str_contains($helper, '$apiSent = saas_email_send_via_api'), 'api_tem_prioridade_sobre_smtp');
$check(str_contains($helper, 'function saas_email_host_blocks_smtp'), 'helper_detecta_host_sem_smtp');
$check(str_contains($helper, 'function saas_email_auto_api_provider'), 'helper_escolhe_api_automatica');
$check(str_contains($envExample, 'EMAIL_PROVIDER=smtp'), 'env_example_documenta_provider');
$check(str_contains($envExample, 'SMTP_OUTBOUND_BLOCKED'), 'env_example_documenta_smtp_bloqueado');
$check(str_contains($envExample, 'RESEND_FROM='), 'env_example_documenta_remetente_resend');
$check(str_contains($envExample, 'MAIL_HOST=smtp.resend.com'), 'env_example_documenta_resend_smtp');

$previousHost = $_SERVER['HTTP_HOST'] ?? null;
$_SERVER['HTTP_HOST'] = 'nivorarestaurant.page.gd';
$check(saas_email_host_blocks_smtp() === true, 'detecta_infinityfree_page_gd');
if ($previousHost === null) {
    unset($_SERVER['HTTP_HOST']);
} else {
    $_SERVER['HTTP_HOST'] = $previousHost;
}

echo 'OK: erros SMTP de recuperacao classificados (' . $checks . " verificacoes).\n";
