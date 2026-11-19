<?php
/**
 * O painel do Básico, numa folha só.
 *
 * O que estava antes: oito blocos independentes no mesmo ecrã — faixa, três
 * cartões brancos, quatro fichas de plano, aviso de caixa, mais vendidos,
 * últimas vendas, acções rápidas — cada um com o seu fundo, a sua sombra e a
 * sua cor. Nenhum dizia ao olho por onde começar.
 *
 * O que está agora: uma folha, três zonas separadas por um traço. O dinheiro
 * do dia em cima, a operação ao meio, o detalhe em baixo. Um acento só, o
 * laranja da marca, no número e na acção principal. A hierarquia é do tamanho
 * da letra, não da cor: nada aqui é verde ou azul por decoração.
 */

// Restaurante sem produtos nunca vendeu, e nao vai vender enquanto nao os
// criar. Mostrar-lhe "0,00" e um grafico vazio e um esqueleto: enquanto esta
// assim, a folha diz o que falta, pela ordem em que tem de ser feito.
if ((int)($total_produtos ?? 0) === 0):
    $bpPassos = [
        [1, false, 'Criar os produtos', 'O que a casa vende, com preço. Sem isto não há pedidos.', 'produtos.php', 'Criar produtos'],
        [2, !empty($caixa_aberto), 'Abrir a caixa', 'Marca o início do dia e o dinheiro com que começa.', 'caixa.php', 'Abrir caixa'],
        [3, false, 'Lançar o primeiro pedido', 'A partir daqui esta folha passa a mostrar o dia.', 'novo_pedido.php', 'Novo pedido'],
    ];
    ?>
    <div class="bas-folha bas-f-inicio">
        <div class="bas-f-topo">
            <div class="bas-f-numero">
                <p class="bas-f-etiqueta">Primeiros passos</p>
                <p class="bas-f-valor bas-f-valor-texto">Falta pouco para abrir portas</p>
                <p class="bas-f-linha">Três passos e esta folha passa a mostrar o movimento do dia.</p>
            </div>
        </div>
        <ol class="bas-f-passos">
            <?php foreach ($bpPassos as [$bpN, $bpFeito, $bpTit, $bpTxt, $bpLink, $bpAccao]): ?>
                <li class="bas-f-passo<?php echo $bpFeito ? ' is-feito' : ''; ?>">
                    <span class="bas-f-passo-n" aria-hidden="true"><?php echo $bpFeito ? '&#10003;' : (int)$bpN; ?></span>
                    <span class="bas-f-passo-txt">
                        <strong><?php echo htmlspecialchars($bpTit, ENT_QUOTES, 'UTF-8'); ?></strong>
                        <span><?php echo htmlspecialchars($bpTxt, ENT_QUOTES, 'UTF-8'); ?></span>
                    </span>
                    <?php if ($bpFeito): ?>
                        <span class="bas-f-passo-pronto">feito</span>
                    <?php else: ?>
                        <a class="bas-f-accao-principal" href="<?php echo htmlspecialchars($bpLink, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($bpAccao, ENT_QUOTES, 'UTF-8'); ?></a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
    <p class="bas-f-plano">
        <strong>Plano Básico</strong> — PDV, mesas, caixa, cardápio e estoque simples.
        <a href="solicitar_plano.php">Ver planos</a>
    </p>
    <?php
    return;
endif;

$bpHoras = $dashBasHoras ?? [];
$bpPico = (float)($dashBasPicoValor ?? 0);
$bpTemRitmo = !empty($bpHoras) && $bpPico > 0;
$bpVendas = (int)($qtd_hoje ?? 0);
$bpTicket = $bpVendas > 0 ? ((float)$total_hoje / $bpVendas) : 0.0;
$bpTop = $dashBasTopHoje ?? [];
$bpVendasRec = array_slice($ultimas_vendas ?? [], 0, 5);
$bpEstoque = array_slice($estoque_baixo ?? [], 0, 3);

// Data em português sem depender do locale do servidor, que no Railway vem
// em inglês e escrevia "Thursday" no painel de um restaurante moçambicano.
$bpDias = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
$bpMeses = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
$bpData = $bpDias[(int)date('w')] . ', ' . (int)date('j') . ' de ' . $bpMeses[(int)date('n') - 1];
?>
<div class="bas-folha">

    <?php /* Zona 1 — o dinheiro do dia. É a única pergunta que o dono faz. */ ?>
    <div class="bas-f-topo">
        <div class="bas-f-numero">
            <p class="bas-f-etiqueta"><?php echo htmlspecialchars($bpData, ENT_QUOTES, 'UTF-8'); ?></p>
            <p class="bas-f-valor">
                <?php echo number_format((float)$total_hoje, 2, ',', '.'); ?><span class="bas-f-moeda">MZN</span>
            </p>
            <p class="bas-f-linha">
                <?php echo $bpVendas; ?> venda<?php echo $bpVendas === 1 ? '' : 's'; ?>
                <?php if ($bpVendas > 0): ?>
                    <span class="bas-f-sep">·</span> ticket médio <?php echo number_format($bpTicket, 0, ',', '.'); ?>
                <?php endif; ?>
                <?php if (!empty($dashBasTemOntem)): ?>
                    <span class="bas-f-sep">·</span>
                    <?php if ((float)$total_hoje <= 0): ?>
                        <span class="bas-f-ontem">ontem a esta hora já tinha <?php echo number_format((float)($dashBasOntemTotal ?? 0), 0, ',', '.'); ?></span>
                    <?php else: $bpSobe = ((float)$dashBasDeltaOntem) >= 0; ?>
                        <?php /* A seta diz a direccao. Verde e vermelho aqui seriam a
                                 sexta e a setima cor do ecra, e o ecra tem uma so. */ ?>
                        <span class="bas-f-ontem">
                            <?php echo $bpSobe ? '&#8593;' : '&#8595;'; ?>
                            <?php echo number_format(abs((float)$dashBasDeltaOntem), 0, ',', '.'); ?>% face a ontem a esta hora
                        </span>
                    <?php endif; ?>
                <?php endif; ?>
            </p>
        </div>

        <?php if ($bpTemRitmo): ?>
            <div class="bas-f-ritmo">
                <p class="bas-f-etiqueta">Ritmo · mais forte às <?php echo htmlspecialchars((string)($dashBasPicoHora ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                <div class="bas-f-barras">
                    <?php foreach ($bpHoras as $bpH):
                        $bpAlt = max(2, (int)round(((float)$bpH['total'] / $bpPico) * 100));
                        $bpEhPico = ((string)$bpH['hora'] === (string)($dashBasPicoHora ?? ''));
                    ?>
                        <span class="bas-f-barra<?php echo $bpEhPico ? ' is-pico' : ''; ?><?php echo !empty($bpH['agora']) ? ' is-agora' : ''; ?>"
                              style="height: <?php echo $bpAlt; ?>%"
                              title="<?php echo htmlspecialchars((string)$bpH['hora'] . 'h · ' . number_format((float)$bpH['total'], 0, ',', '.') . ' MZN', ENT_QUOTES, 'UTF-8'); ?>"></span>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php /* Zona 2 — o estado da casa, em texto. Três números não precisam de
             três cartões: precisam de uma linha. */ ?>
    <div class="bas-f-estado">
        <a class="bas-f-estado-item" href="produtos.php">
            <strong><?php echo (int)($total_produtos ?? 0); ?></strong>
            <span>produto<?php echo ((int)($total_produtos ?? 0)) === 1 ? '' : 's'; ?> no cardápio</span>
        </a>
        <a class="bas-f-estado-item" href="mesas.php">
            <strong><?php echo (int)($mesas_ocupadas ?? 0); ?>/<?php echo (int)($total_mesas ?? 0); ?></strong>
            <span>mesas ocupadas</span>
        </a>
        <a class="bas-f-estado-item<?php echo empty($caixa_aberto) ? ' is-aviso' : ''; ?>" href="caixa.php">
            <strong><?php echo !empty($caixa_aberto) ? 'Aberta' : 'Fechada'; ?></strong>
            <span><?php
                // "Aberta caixa" nao e uma frase. O ponto medio separa dois factos.
                if (empty($caixa_aberto)) {
                    echo 'a caixa · abrir para começar o dia';
                } elseif (!empty($dashBasMinutosSemVenda) && (int)$dashBasMinutosSemVenda >= 90) {
                    echo 'a caixa · sem vendas há ' . (int)floor((int)$dashBasMinutosSemVenda / 60) . 'h' . str_pad((string)((int)$dashBasMinutosSemVenda % 60), 2, '0', STR_PAD_LEFT);
                } else {
                    echo 'a caixa';
                }
            ?></span>
        </a>
    </div>

    <?php /* Zona 3 — o detalhe, lado a lado na mesma folha. Eram duas caixas
             brancas com um buraco entre elas; agora é uma linha a separar. */ ?>
    <div class="bas-f-detalhe">
        <section class="bas-f-col">
            <h2 class="bas-f-titulo">Mais vendidos hoje <span>só vendas pagas</span></h2>
            <?php if (empty($bpTop)): ?>
                <p class="bas-f-vazio">Ainda não há vendas pagas hoje.</p>
            <?php else: $bpMaxQtd = max(1, (float)$bpTop[0]['qtd']); ?>
                <ol class="bas-f-top">
                    <?php foreach ($bpTop as $bpI => $bpP): ?>
                        <li>
                            <span class="bas-f-top-pos"><?php echo $bpI + 1; ?></span>
                            <span class="bas-f-top-nome"><?php echo htmlspecialchars((string)$bpP['nome'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="bas-f-top-barra"><i style="width: <?php echo number_format(((float)$bpP['qtd'] / $bpMaxQtd) * 100, 1, '.', ''); ?>%"></i></span>
                            <span class="bas-f-top-qtd"><?php echo rtrim(rtrim(number_format((float)$bpP['qtd'], 1, ',', '.'), '0'), ','); ?> un.</span>
                            <span class="bas-f-top-valor"><?php echo number_format((float)$bpP['valor'], 0, ',', '.'); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>

        <section class="bas-f-col">
            <h2 class="bas-f-titulo">Últimas vendas <a href="vendas.php">ver todas</a></h2>
            <?php if (empty($bpVendasRec)): ?>
                <p class="bas-f-vazio">Nenhuma venda registada ainda.</p>
            <?php else: ?>
                <ul class="bas-f-vendas">
                    <?php foreach ($bpVendasRec as $bpV): ?>
                        <li>
                            <span class="bas-f-v-hora"><?php echo htmlspecialchars(date('H:i', strtotime((string)$bpV['criado_em'])), ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="bas-f-v-ref">
                                <?php echo htmlspecialchars((string)$bpV['numero_fatura'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php if (!empty($bpV['mesa_numero'])): ?>
                                    <em>mesa <?php echo (int)$bpV['mesa_numero']; ?></em>
                                <?php endif; ?>
                            </span>
                            <span class="bas-f-v-total"><?php echo number_format((float)$bpV['total_final'], 0, ',', '.'); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>

    <?php if (!empty($bpEstoque)): ?>
        <div class="bas-f-estoque">
            <strong>Estoque baixo</strong>
            <?php foreach ($bpEstoque as $bpE): ?>
                <span><?php echo htmlspecialchars((string)$bpE['nome'], ENT_QUOTES, 'UTF-8'); ?>
                    <i><?php echo rtrim(rtrim(number_format((float)$bpE['estoque'], 1, ',', '.'), '0'), ','); ?></i></span>
            <?php endforeach; ?>
            <a href="produtos.php">repor</a>
        </div>
    <?php endif; ?>
</div>

<?php /* As acções. Uma é a que se faz o dia inteiro; as outras são atalhos. */ ?>
<div class="bas-f-accoes">
    <a class="bas-f-accao-principal" href="novo_pedido.php">Novo pedido</a>
    <a href="vendas.php">Vendas</a>
    <a href="produtos.php">Produtos</a>
    <a href="mesas.php">Mesas</a>
    <a href="caixa.php">Caixa</a>
</div>

<p class="bas-f-plano">
    <strong>Plano Básico</strong> — PDV, mesas, caixa, cardápio e estoque simples.
    Bar e Cozinha (KDS), pedidos por QR e relatórios avançados ficam no Profissional.
    <a href="solicitar_plano.php">Ver planos</a>
</p>
