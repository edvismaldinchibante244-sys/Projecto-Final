<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$plans = require $root . '/app/src/config/planos.php';
require_once $root . '/app/src/config/plan_security.php';
require_once $root . '/app/src/config/promocao_schema.php';
require_once $root . '/app/src/config/mesa_display_helper.php';
require_once $root . '/app/src/config/plano_bonus.php';

$checks = 0;
$failures = [];

$check = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
};

$feature = static function (array $catalog, string $plan, string $name): bool {
    return !empty($catalog[$plan]['funcionalidades'][$name]);
};

$expectedPrices = [
    'BASICO' => ['mensal' => 799, 'trimestral' => 2157, 'anual' => 7670],
    'PROFISSIONAL' => ['mensal' => 1999, 'trimestral' => 5397, 'anual' => 19190],
    'EMPRESARIAL' => ['mensal' => 3999, 'trimestral' => 10797, 'anual' => 38390],
];

$expectedLimits = [
    'BASICO' => [
        'produtos' => 150,
        'usuarios' => 6,
        'mesas' => 30,
        'balcoes' => 1,
        'categorias' => 10,
        'subcategorias' => 0,
        'filiais' => 0,
    ],
    'PROFISSIONAL' => [
        'produtos' => 500,
        'usuarios' => 10,
        'mesas' => 50,
        'balcoes' => 1,
        'categorias' => 25,
        'subcategorias' => 50,
        'filiais' => 0,
    ],
    'EMPRESARIAL' => [
        'produtos' => -1,
        'usuarios' => -1,
        'mesas' => -1,
        'balcoes' => 2,
        'categorias' => -1,
        'subcategorias' => -1,
        'filiais' => 5,
    ],
];

$expectedFeatures = [
    'BASICO' => [
        'enabled' => [
            'pdv',
            'caixa',
            'estoque_simples',
            'cardapio_digital',
            'relatorio_diario',
            'clientes',
            'impressao_recibos',
            'backup_manual',
            'suporte_email',
        ],
        'disabled' => [
            'contas_abertas',
            'garcons',
            'estoque_avancado',
            'pedidos_online',
            'telas_bar_cozinha',
            'relatorios_avancados',
            'gestao_funcionarios',
            'historico_clientes',
            'multi_filial',
            'reservas_qr',
            'dashboard_inteligente',
            'suporte_whatsapp',
            'suporte_24h',
        ],
    ],
    'PROFISSIONAL' => [
        'enabled' => [
            'contas_abertas',
            'garcons',
            'estoque_avancado',
            'pedidos_online',
            'telas_bar_cozinha',
            'subcategorias',
            'relatorios_avancados',
            'relatorio_financeiro',
            'exportacao_relatorios',
            'gestao_funcionarios',
            'historico_clientes',
            'backup_diario',
            'suporte_whatsapp',
        ],
        'disabled' => [
            'transferencia_estoque',
            'reservas_qr',
            'dashboard_executivo',
            'dashboard_inteligente',
            'analise_abc_vendas',
            'recomendacoes_automaticas',
            'previsao_demanda',
            'multi_filial',
            'backup_automatico',
            'suporte_24h',
        ],
    ],
    'EMPRESARIAL' => [
        'enabled' => [
            'transferencia_estoque',
            'reservas_qr',
            'relatorios_inteligentes',
            'dashboard_executivo',
            'comparativo_filiais',
            'dashboard_inteligente',
            'analise_abc_vendas',
            'recomendacoes_automaticas',
            'previsao_demanda',
            'multi_filial',
            'backup_automatico',
            'backup_hora',
            'download_banco',
            'recuperacao_automatica',
            'notificacoes',
            'suporte_24h',
            'suporte_prioritario',
        ],
        'disabled' => [
            'api_integracao',
        ],
    ],
];

$check(array_keys($plans) === ['BASICO', 'PROFISSIONAL', 'EMPRESARIAL'], 'O catalogo deve conter apenas os tres planos canonicos e na ordem comercial.');

foreach ($expectedPrices as $plan => $prices) {
    $check(isset($plans[$plan]), "Plano {$plan} ausente do catalogo.");
    foreach ($prices as $cycle => $price) {
        $actual = (float)($plans[$plan]['precos'][$cycle] ?? 0);
        $check($actual === (float)$price, "Preco {$cycle} do plano {$plan} divergente: esperado {$price}, encontrado {$actual}.");
    }

    $monthly = (float)$plans[$plan]['precos']['mensal'];
    $quarterly = (float)$plans[$plan]['precos']['trimestral'];
    $annual = (float)$plans[$plan]['precos']['anual'];
    $check($quarterly < $monthly * 3, "Plano {$plan} deve conceder desconto trimestral.");
    $check($annual < $monthly * 12, "Plano {$plan} deve conceder desconto anual.");
}

foreach ($expectedLimits as $plan => $limits) {
    foreach ($limits as $resource => $limit) {
        $actual = (int)($plans[$plan]['limites'][$resource] ?? PHP_INT_MIN);
        $check($actual === $limit, "Limite {$resource} do plano {$plan} divergente: esperado {$limit}, encontrado {$actual}.");
    }
}

foreach ($expectedFeatures as $plan => $rules) {
    foreach ($rules['enabled'] as $name) {
        $check($feature($plans, $plan, $name), "Funcionalidade {$name} deveria estar ativa no plano {$plan}.");
    }
    foreach ($rules['disabled'] as $name) {
        $check(!$feature($plans, $plan, $name), "Funcionalidade {$name} deveria estar bloqueada no plano {$plan}.");
    }
}

// Um upgrade nunca pode retirar uma funcionalidade que ja existia no plano inferior.
$hierarchy = ['BASICO', 'PROFISSIONAL', 'EMPRESARIAL'];
for ($index = 0; $index < count($hierarchy) - 1; $index++) {
    $lower = $hierarchy[$index];
    $higher = $hierarchy[$index + 1];
    foreach ($plans[$lower]['funcionalidades'] as $name => $enabled) {
        if ($enabled) {
            $check($feature($plans, $higher, (string)$name), "Upgrade {$lower} -> {$higher} remove a funcionalidade {$name}.");
        }
    }
}

// -1 representa ilimitado. Nos demais casos, o plano superior nao pode reduzir o limite.
foreach (array_keys($expectedLimits['BASICO']) as $resource) {
    for ($index = 0; $index < count($hierarchy) - 1; $index++) {
        $lower = (int)$plans[$hierarchy[$index]]['limites'][$resource];
        $higher = (int)$plans[$hierarchy[$index + 1]]['limites'][$resource];
        $check($higher === -1 || $higher >= $lower, "Upgrade reduz o limite de {$resource} entre {$hierarchy[$index]} e {$hierarchy[$index + 1]}.");
    }
}

$check(plan_security_trial_days('BASICO') === 0, 'O periodo gratuito do Basico foi descontinuado.');
$check(!plan_security_supports_trial('BASICO'), 'O Basico nao pode aceitar trial.');
$check(!plan_security_supports_trial('PROFISSIONAL'), 'O plano Profissional nao pode aceitar trial.');
$check(!plan_security_supports_trial('EMPRESARIAL'), 'O plano Empresarial nao pode aceitar trial.');
$check(plan_security_normalize_plan('enterprise') === 'EMPRESARIAL', 'O nome legado ENTERPRISE deve normalizar para EMPRESARIAL.');

// Oferta de boas-vindas no primeiro pagamento
$check(bonus_plano_acima('BASICO') === 'PROFISSIONAL', 'Quem paga Basico usa o Profissional na oferta.');
$check(bonus_plano_acima('PROFISSIONAL') === 'EMPRESARIAL', 'Quem paga Profissional usa o Empresarial na oferta.');
$check(bonus_plano_acima('EMPRESARIAL') === null, 'Nao ha plano acima do Empresarial: a oferta sao dias extra.');
$check(BONUS_DIAS > 0 && BONUS_DIAS <= 30, 'A oferta tem de ter uma duracao util.');
$check(BONUS_ESPERA_DIAS > 0 && BONUS_ESPERA_DIAS < BONUS_DIAS, 'A espera pela primeira venda tem de ser menor que a oferta.');
foreach (['BASICO', 'PROFISSIONAL'] as $planoOferta) {
    $acima = bonus_plano_acima($planoOferta);
    $niveis = ['BASICO' => 1, 'PROFISSIONAL' => 2, 'EMPRESARIAL' => 3];
    $check($niveis[$acima] === $niveis[$planoOferta] + 1, "A oferta de {$planoOferta} sobe exactamente um degrau.");
}

$trial = plan_security_validate_request('BASICO', 'BASICO', 'MENSAL', 'TRIAL', true);
$check(empty($trial['ok']), 'Solicitacao de trial Basico deve ser recusada.');
$invalidTrial = plan_security_validate_request('BASICO', 'PROFISSIONAL', 'MENSAL', 'TRIAL');
$check(empty($invalidTrial['ok']), 'Trial Profissional deve ser rejeitado no backend.');
$downgrade = plan_security_validate_request('EMPRESARIAL', 'PROFISSIONAL', 'MENSAL', 'MPESA');
$check(empty($downgrade['ok']), 'Downgrade nao pode passar pelo fluxo direto de compra.');
$upgrade = plan_security_validate_request('BASICO', 'EMPRESARIAL', 'ANUAL', 'TRANSFERENCIA');
$check(!empty($upgrade['ok']) && (float)($upgrade['price'] ?? 0) === 38390.0, 'Upgrade Empresarial anual deve usar o preco oficial.');
$tampered = plan_security_purchase_integrity([
    'plano_novo' => 'EMPRESARIAL',
    'ciclo' => 'ANUAL',
    'metodo_pagamento' => 'TRANSFERENCIA',
    'valor' => 1,
]);
$check(empty($tampered['ok']), 'Integridade da compra deve rejeitar preco adulterado.');

$check(!promocao_plano_permitido('BASICO'), 'Promocoes devem estar bloqueadas no Basico.');
$check(promocao_plano_permitido('PROFISSIONAL'), 'Promocoes manuais devem estar disponiveis no Profissional.');
$check(promocao_plano_permitido('EMPRESARIAL'), 'Promocoes devem estar disponiveis no Empresarial.');
$check(mesa_rotulo('0') === 'Balcão / Take-away', 'Mesa legada 0 deve ser exibida como Balcão / Take-away.');
$check(mesa_rotulo('B2') === 'Balcão 2', 'Segundo balcão sem nome usa o rótulo numerado.');
$check(mesa_rotulo('B2', 'Take-away') === 'Take-away', 'Segundo balcão com nome usa o nome configurado.');
$check(mesa_rotulo('B3') === 'Balcão 3', 'Balcões adicionais usam o rótulo numerado.');
$mesasOperacionaisTeste = mesa_filtrar_operacionais([
    ['id' => 10, 'numero' => '0'],
    ['id' => 11, 'numero' => 'B2'],
    ['id' => 12, 'numero' => '1'],
]);
$check(
    array_column($mesasOperacionaisTeste, 'id') === [10, 12],
    'A lista operacional deve preservar só o primeiro balcão e todas as mesas físicas.'
);

$guardContracts = [
    'src/public/bar.php' => ['telas_bar_cozinha', 'plano_tem_funcionalidade_db'],
    'src/public/cozinha.php' => ['telas_bar_cozinha', 'plano_tem_funcionalidade_db'],
    'src/public/caixa_mesas.php' => ['contas_abertas', 'plano_verificar_funcionalidade'],
    'src/public/pedidos.php' => ['pedidos_online', 'plano_verificar_funcionalidade'],
    'src/public/estoque_inteligente.php' => ['estoque_avancado', 'plano_tem_funcionalidade_db'],
    'src/public/funcionarios_turnos.php' => ['gestao_funcionarios', 'plano_verificar_funcionalidade'],
    'src/public/filiais.php' => ['tem_multi_filial'],
    'src/public/api/caixa_mesas_abertas.php' => ['contas_abertas', 'plano_verificar_funcionalidade'],
    'src/public/api/garcom_mesas.php' => ['garcons', 'plano_verificar_funcionalidade'],
    'src/public/api/estoque_transferir.php' => ['transferencia_estoque', 'plano_tem_funcionalidade_db'],
    'src/public/api/backup_restaurar.php' => ['recuperacao_automatica', 'plano_tem_funcionalidade_db'],
    'src/public/api/support_ticket_create.php' => ['suporte_24h', 'plano_tem_funcionalidade_db'],
    'src/public/api/promocao_salvar.php' => ['promocao_plano_permitido'],
    'app/src/api/reserva_publica_handler.php' => ['reservas_qr', 'plano_tem_funcionalidade_db'],
    'app/src/Service/ProdutoService.php' => ["plano_verificar_limite_db", "'produtos'"],
    'app/src/Service/MesaService.php' => ["plano_verificar_limite_db", "'mesas'", "mesa_garantir_balcao_canonico"],
    'app/src/Service/ReservaService.php' => ["mesa_eh_balcao"],
    'src/public/mesas.php' => ["mesa_garantir_balcao_canonico", "Balcão / Take-away"],
    'src/public/vendas.php' => ["mesa_garantir_balcao_canonico"],
    'src/public/novo_pedido.php' => ["mesa_garantir_balcao_canonico"],
    'app/src/Service/PedidoService.php' => ["mesa_eh_balcao", "status = 'LIVRE'"],
    'src/public/api/usuario_cadastrar.php' => ["plano_verificar_limite_db", "'usuarios'"],
];

foreach ($guardContracts as $relativePath => $needles) {
    $path = $root . '/' . $relativePath;
    $source = is_file($path) ? file_get_contents($path) : false;
    $check(is_string($source), "Arquivo protegido ausente: {$relativePath}.");
    if (!is_string($source)) {
        continue;
    }
    foreach ($needles as $needle) {
        $check(strpos($source, $needle) !== false, "Contrato de plano ausente em {$relativePath}: {$needle}.");
    }
}

if ($failures) {
    fwrite(STDERR, "FALHA: matriz de planos inconsistente.\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    fwrite(STDERR, "Verificacoes: {$checks}; falhas: " . count($failures) . ".\n");
    exit(1);
}

echo "OK: matriz, precos, limites, trial e bloqueios criticos validados ({$checks} verificacoes).\n";
