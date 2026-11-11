<?php
/**
 * Banner de uso de limite do plano.
 * Espera: $planUsage (retorno de plano_verificar_limite_db) e opcionalmente $planUsageLabel.
 */
if (empty($planUsage) || !is_array($planUsage)) {
    return;
}
$__usoAtual = (int)($planUsage['atual'] ?? 0);
$__usoLimite = (int)($planUsage['limite'] ?? -1);
$__usoLabel = (string)($planUsageLabel ?? ($planUsage['recurso'] ?? 'itens'));
$__usoPlano = (string)($planUsage['plano'] ?? '');
$__atingiu = $__usoLimite >= 0 && $__usoAtual >= $__usoLimite;
$__perto = $__usoLimite > 0 && !$__atingiu && ($__usoAtual / $__usoLimite) >= 0.8;
$__upgrade = function_exists('plano_proximo_upgrade')
    ? plano_proximo_upgrade($planUsage['plano_codigo'] ?? '')
    : null;

if ($__usoLimite < 0) {
    return;
}
?>
<div class="alert <?php echo $__atingiu ? 'alert-warning' : ($__perto ? 'alert-info' : 'alert-light'); ?> border d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3" style="border-radius:12px;">
    <div>
        <strong><?php echo htmlspecialchars($__usoLabel, ENT_QUOTES, 'UTF-8'); ?>:</strong>
        <?php echo $__usoAtual; ?> / <?php echo $__usoLimite; ?>
        <span class="text-muted small ms-1">(plano <?php echo htmlspecialchars($__usoPlano, ENT_QUOTES, 'UTF-8'); ?>)</span>
        <?php if ($__atingiu): ?>
            <div class="small mt-1 mb-0">Limite atingido. Remova itens ou faça upgrade para continuar a cadastrar.</div>
        <?php elseif ($__perto): ?>
            <div class="small mt-1 mb-0">Está perto do limite do plano.</div>
        <?php endif; ?>
    </div>
    <?php if (($__atingiu || $__perto) && is_array($__upgrade)): ?>
        <a href="<?php echo htmlspecialchars($__upgrade['url'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-primary" style="border-radius:10px;font-weight:700;">
            Upgrade <?php echo htmlspecialchars($__upgrade['nome'], ENT_QUOTES, 'UTF-8'); ?>
        </a>
    <?php endif; ?>
</div>
<?php
$planUsageBloqueado = $__atingiu;
?>
