<?php
/**
 * Faixa da oferta de boas-vindas.
 *
 * Mostra, por esta ordem:
 *  - enquanto a oferta corre: que plano está a usar e quantos dias faltam
 *    (mais insistente a partir do 7.º e do 12.º dia);
 *  - quando acaba: o que fez com o plano de cima e o botão para ficar;
 *  - depois disso, se ficou acima do limite do plano pago: a explicação de
 *    que nada foi apagado e do que trava a criação de novos registos.
 *
 * Quem inclui tem de ter $db (PDO) e um id de restaurante em
 * $bonusFaixaRid (ou $restauranteId / $_SESSION['restaurante_id']).
 */

if (!isset($db) || !($db instanceof PDO)) {
    return;
}

require_once APP_BASE . '/src/config/plano_bonus.php';

$bfRid = (int)($bonusFaixaRid ?? $restauranteId ?? $_SESSION['restaurante_id'] ?? 0);
if ($bfRid <= 0) {
    return;
}

$bfEstado = bonus_estado($db, $bfRid);
if (!is_array($bfEstado) || ($bfEstado['estado'] ?? '') === 'PENDENTE') {
    return;
}
if (($bfEstado['tipo'] ?? 'UPGRADE') === 'DIAS_EXTRA') {
    return; // Dias extra não têm contagem a mostrar.
}

$bfNomes = ['BASICO' => 'Básico', 'PROFISSIONAL' => 'Profissional', 'EMPRESARIAL' => 'Empresarial'];
$bfPlanoBonus = strtoupper((string)($bfEstado['plano_bonus'] ?? ''));
$bfPlanoPago = strtoupper((string)($bfEstado['plano_pago'] ?? ''));
$bfNomeBonus = $bfNomes[$bfPlanoBonus] ?? $bfPlanoBonus;
$bfNomePago = $bfNomes[$bfPlanoPago] ?? $bfPlanoPago;
$bfLinkFicar = 'solicitar_plano.php?plano=' . urlencode($bfPlanoBonus);

if (!empty($bfEstado['ativo'])) {
    $bfDias = (int)($bfEstado['dias_restantes'] ?? 0);
    $bfUrgente = $bfDias <= 2;
    $bfAtencao = $bfDias <= 7;

    // Avisa (email, quando configurado) e marca o aviso do 7.º e do 12.º dia.
    // A tarefa agendada faz o mesmo para quem não abrir o sistema.
    if ($bfDias <= (BONUS_DIAS - 12) && empty($bfEstado['aviso12_em'])) {
        bonus_avisar($db, $bfRid, 'aviso12_em', $bfEstado);
    } elseif ($bfDias <= (BONUS_DIAS - 7) && empty($bfEstado['aviso7_em'])) {
        bonus_avisar($db, $bfRid, 'aviso7_em', $bfEstado);
    }
    ?>
    <div class="bonus-faixa<?php echo $bfUrgente ? ' is-urgente' : ($bfAtencao ? ' is-atencao' : ''); ?>" role="status">
        <span class="bonus-faixa-icone"><i class="fas fa-gift" aria-hidden="true"></i></span>
        <div class="bonus-faixa-texto">
            <strong>Está a usar o plano <?php echo htmlspecialchars($bfNomeBonus, ENT_QUOTES, 'UTF-8'); ?> por oferta de boas-vindas.</strong>
            <span>
                <?php if ($bfDias <= 0): ?>
                    Termina hoje.
                <?php elseif ($bfDias === 1): ?>
                    Falta 1 dia — depois volta ao <?php echo htmlspecialchars($bfNomePago, ENT_QUOTES, 'UTF-8'); ?>.
                <?php else: ?>
                    Faltam <?php echo $bfDias; ?> dias — depois volta ao <?php echo htmlspecialchars($bfNomePago, ENT_QUOTES, 'UTF-8'); ?>.
                <?php endif; ?>
                Nada se perde: o que criou continua lá.
            </span>
        </div>
        <a class="bonus-faixa-btn" href="<?php echo htmlspecialchars($bfLinkFicar, ENT_QUOTES, 'UTF-8'); ?>">Ficar com o <?php echo htmlspecialchars($bfNomeBonus, ENT_QUOTES, 'UTF-8'); ?></a>
    </div>
    <?php
    return;
}

// A oferta acabou: resumo do que usou, uma vez.
if (($bfEstado['estado'] ?? '') === 'TERMINADO' && empty($bfEstado['resumo_visto_em'])) {
    $bfUso = bonus_resumo_uso($db, $bfRid, $bfEstado);
    // O cartão mostra-se uma vez; o email de fim tem marca própria, para a
    // tarefa agendada e o painel não se anularem um ao outro.
    if (empty($bfEstado['aviso_fim_em'])) {
        bonus_avisar($db, $bfRid, 'aviso_fim_em', $bfEstado);
    }
    bonus_marcar_aviso($db, $bfRid, 'resumo_visto_em');
    ?>
    <div class="bonus-faixa is-fim" role="status">
        <span class="bonus-faixa-icone"><i class="fas fa-flag-checkered" aria-hidden="true"></i></span>
        <div class="bonus-faixa-texto">
            <strong>Os <?php echo (int)BONUS_DIAS; ?> dias no plano <?php echo htmlspecialchars($bfNomeBonus, ENT_QUOTES, 'UTF-8'); ?> terminaram.</strong>
            <span>
                Voltou ao <?php echo htmlspecialchars($bfNomePago, ENT_QUOTES, 'UTF-8'); ?>, que é o plano que paga. Nada foi apagado.
                <?php if ($bfUso): ?>
                    Durante a oferta:
                    <?php
                    $bfPartes = [];
                    foreach ($bfUso as $bfItem) {
                        $bfPartes[] = '<strong>' . (int)$bfItem['valor'] . '</strong> ' . htmlspecialchars((string)$bfItem['rotulo'], ENT_QUOTES, 'UTF-8');
                    }
                    echo implode(' · ', $bfPartes);
                    ?>.
                <?php endif; ?>
            </span>
        </div>
        <a class="bonus-faixa-btn" href="<?php echo htmlspecialchars($bfLinkFicar, ENT_QUOTES, 'UTF-8'); ?>">Voltar ao <?php echo htmlspecialchars($bfNomeBonus, ENT_QUOTES, 'UTF-8'); ?></a>
    </div>
    <?php
    return;
}

// Depois da oferta: avisa se ficou acima do limite do plano pago.
if (($bfEstado['estado'] ?? '') === 'TERMINADO') {
    $bfAcima = bonus_acima_do_limite($db, $bfRid, $bfPlanoPago);
    if ($bfAcima) {
        ?>
        <div class="bonus-faixa is-limite" role="status">
            <span class="bonus-faixa-icone"><i class="fas fa-circle-info" aria-hidden="true"></i></span>
            <div class="bonus-faixa-texto">
                <strong>Tem mais registos do que o plano <?php echo htmlspecialchars($bfNomePago, ENT_QUOTES, 'UTF-8'); ?> inclui.</strong>
                <span>
                    <?php
                    $bfPartesLim = [];
                    foreach ($bfAcima as $bfLim) {
                        $bfPartesLim[] = htmlspecialchars((string)$bfLim['rotulo'], ENT_QUOTES, 'UTF-8')
                            . ': <strong>' . (int)$bfLim['atual'] . '</strong> de ' . (int)$bfLim['limite'];
                    }
                    echo implode(' · ', $bfPartesLim);
                    ?>.
                    Nada foi apagado e continua a poder usar e editar tudo — só não pode criar mais enquanto estiver acima do limite.
                </span>
            </div>
            <a class="bonus-faixa-btn" href="<?php echo htmlspecialchars($bfLinkFicar, ENT_QUOTES, 'UTF-8'); ?>">Ver planos</a>
        </div>
        <?php
    }
}
