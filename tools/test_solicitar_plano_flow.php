<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/src/config/plan_security.php';

$requestSource = file_get_contents($root . '/src/public/solicitar_plano.php');
$approvalSource = file_get_contents($root . '/src/public/api/super_admin_plano_aprovar.php');
$checks = 0;

$check = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
};

try {
    $check(is_string($requestSource) && $requestSource !== '', 'fonte da solicitacao disponivel');
    $check(is_string($approvalSource) && $approvalSource !== '', 'fonte da aprovacao disponivel');

    $trial = plan_security_validate_request('BASICO', 'BASICO', 'MENSAL', 'TRIAL', true);
    $check(!empty($trial['ok']), 'trial Basico mensal aceito');
    $check((float)($trial['price'] ?? -1) === 0.0, 'trial tem valor zero');
    $check(plan_security_trial_days('BASICO') === 30, 'trial dura 30 dias');

    $trialProfissional = plan_security_validate_request('BASICO', 'PROFISSIONAL', 'MENSAL', 'TRIAL', true);
    $check(empty($trialProfissional['ok']), 'trial Profissional bloqueado');

    $pago = plan_security_validate_request('BASICO', 'PROFISSIONAL', 'MENSAL', 'MPESA', true);
    $check(!empty($pago['ok']) && (float)($pago['price'] ?? 0) > 0, 'plano pago usa preco oficial');

    $compraManipulada = plan_security_purchase_integrity([
        'plano_novo' => 'PROFISSIONAL',
        'ciclo' => 'MENSAL',
        'metodo_pagamento' => 'MPESA',
        'valor' => 1,
    ]);
    $check(empty($compraManipulada['ok']), 'valor manipulado bloqueado na aprovacao');

    $check(str_contains($requestSource, "plan_security_validate_request('BASICO', \$plano, \$ciclo, \$metodo, true)"), 'solicitacao usa validacao central');
    $check(str_contains($requestSource, "INSERT INTO usuarios") && str_contains($requestSource, "'ADMIN', 0, NOW()"), 'administrador nasce inativo');
    $check(str_contains($requestSource, "cp.metodo_pagamento = 'TRIAL'") && str_contains($requestSource, 'r.nuit_lookup = ?'), 'trial anterior verificado pelo NUIT');
    $check(str_contains($requestSource, '$db->beginTransaction()') && str_contains($requestSource, '$db->commit()'), 'cadastro protegido por transacao');
    $check(str_contains($requestSource, 'legal_record_acceptance(') && str_contains($requestSource, "audit_log(\$db, 'plano_solicitacao_publica_recebida'"), 'termos e auditoria registrados');

    $check(str_contains($approvalSource, 'plan_security_purchase_integrity($compra)'), 'aprovacao revalida integridade');
    $check(str_contains($approvalSource, "UPDATE usuarios SET ativo = 1"), 'aprovacao ativa administrador');
    $check(str_contains($approvalSource, "\$compraIsTrial ? 'TRIAL' : 'PAGO'"), 'aprovacao distingue trial e pagamento');
    $check(str_contains($approvalSource, 'plan_security_purchase_trial_days($compra) ?: 30'), 'aprovacao aplica prazo do trial');

    $inicio = new DateTimeImmutable('2026-08-26');
    $fim = $inicio->modify('+' . plan_security_trial_days('BASICO') . ' days');
    $check($fim->format('Y-m-d') === '2026-09-25', 'prazo inicia na aprovacao e termina apos 30 dias');

    echo "OK: fluxo solicitar_plano validado sem alterar a base ({$checks} verificacoes)." . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
