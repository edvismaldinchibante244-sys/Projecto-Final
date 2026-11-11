<?php

$php = (string)file_get_contents(__DIR__ . '/../src/public/mesas.php');
$js = (string)file_get_contents(__DIR__ . '/../src/public/js/mesas.js');
$css = (string)file_get_contents(__DIR__ . '/../src/public/css/empresarial.css');

$checks = [];
$check = static function (bool $condition, string $name) use (&$checks): void {
    if (!$condition) {
        fwrite(STDERR, "FALHA: {$name}\n");
        exit(1);
    }
    $checks[] = $name;
};

$check(str_contains($php, "' mesa-create-modal'"), 'modal_condicional_empresarial');
$check(str_contains($php, 'class="mesa-create-form"'), 'formulario_empresarial_identificado');
$check(str_contains($php, 'name="tipo"'), 'campo_tipo_preservado');
$check(str_contains($php, 'name="numero"'), 'campo_numero_preservado');
$check(str_contains($php, 'name="capacidade"'), 'campo_capacidade_preservado');
$check(str_contains($php, 'data-mesa-capacidade='), 'atalhos_capacidade_presentes');
$check(str_contains($php, 'mesaCreatePreviewNumero'), 'preview_presente');
$check(str_contains($js, "formData.append('_csrf', getReservaToken())"), 'csrf_preservado');
$check(str_contains($js, "fetch('api/mesa_cadastrar.php'"), 'endpoint_cadastro_preservado');
$check(str_contains($js, "submitButton.innerHTML = submitButtonDefaultHtml"), 'botao_restaurado_apos_envio');
$check(str_contains($css, 'body.plano-empresarial .mesa-create-content'), 'css_limitado_ao_empresarial');
$check(str_contains($css, '@media (max-width: 767.98px)'), 'responsividade_mobile_presente');
$check(str_contains($php, 'mesas-emp-qr-reserva-btn'), 'botao_qr_empresarial_presente');
$check(str_contains($php, 'onclick="abrirModalReservaQr()"'), 'fluxo_qr_original_preservado');
$check(str_contains($php, 'Acesso temporário do cliente') || str_contains($php, 'Enviar acesso da mesa ao cliente'), 'objetivo_qr_explicado');
$check(str_contains($css, 'body.plano-empresarial .mesas-emp-qr-reserva-btn'), 'estilo_qr_limitado_ao_empresarial');
$check(str_contains($php, "' qr-reserva-emp-modal'"), 'modal_qr_condicional_empresarial');
$check(str_contains($php, 'class="qr-reserva-emp-form"'), 'formulario_qr_empresarial_identificado');
$check(str_contains($php, 'Configuração do acesso'), 'secao_configuracao_qr_presente');
$check(str_contains($php, 'Dados do cliente'), 'secao_cliente_qr_presente');
$check(str_contains($php, 'qr-reserva-emp-result'), 'resultado_qr_empresarial_presente');
$check(str_contains($css, 'body.plano-empresarial .qr-reserva-emp-content'), 'css_form_qr_limitado_ao_empresarial');
$check(str_contains($php, 'id="qr_mesa_id"'), 'campo_mesa_qr_preservado');
$check(str_contains($php, 'id="qr_expira_em_minutos"'), 'campo_expiracao_qr_preservado');
$check(str_contains($php, 'id="btnGerarReservaQr"'), 'botao_gerar_qr_preservado');
$check(str_contains($js, 'button.innerHTML = buttonDefaultHtml'), 'texto_botao_qr_restaurado');
$check(str_contains($php, 'type="tel"'), 'telefone_qr_otimizado_mobile');
$check(str_contains($js, "ctx.fillText('NIVORA RESTAURANT'"), 'imagem_qr_com_marca');
$check(str_contains($js, "ctx.fillText('CLIENTE CONFIRMADO'"), 'imagem_qr_com_cliente');
$check(str_contains($js, 'const width = 1080'), 'imagem_qr_largura_legivel');
$check(str_contains($js, 'const height = 1640'), 'imagem_qr_altura_legivel');
$check(str_contains($js, 'const qrArea = 560'), 'imagem_qr_area_ampliada');
$check(str_contains($js, "textoCurto(validade || 'Indicada no acesso'"), 'imagem_qr_com_validade');
$check(str_contains($js, 'Apresente este QR na recepcao para continuar.'), 'imagem_qr_instrucao_legivel');
$check(str_contains($js, "'Mesa: ' + mesaLabel"), 'whatsapp_com_mesa');
$check(!str_contains($js, "dados.telefone_cliente"), 'imagem_qr_sem_telefone');
$check(!str_contains($js, "dados.email_cliente"), 'imagem_qr_sem_email');

echo 'OK: formulario empresarial de mesa validado (' . count($checks) . " verificacoes).\n";
