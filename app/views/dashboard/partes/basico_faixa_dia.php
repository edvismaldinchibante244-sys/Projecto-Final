<?php
/**
 * A faixa do dia — o ecrã do Básico.
 *
 * Porque é escura: a marca Nivora já tem carácter, mas só do lado de fora.
 * O email de verificação tem cabeçalho escuro e laranja quente; o login tem
 * painel escuro e Cormorant Garamond. Lá dentro, o painel era branco sobre
 * cinzento com Outfit — a tipografia da marca parava à porta. Esta faixa traz
 * essa linguagem para onde o dono passa o dia.
 *
 * Porque junta tudo: o dono de uma lanchonete faz uma pergunta só, e é sempre
 * a mesma — "como está a correr hoje?". Antes essa resposta estava repartida
 * por um cumprimento, um cartão de vendas, outro de movimento e um rodapé de
 * comparação. Agora é um bloco: o dinheiro, o ritmo e a referência de ontem.
 */

// Restaurante que ainda nao tem produtos nunca vendeu nada, e nunca vai
// vender enquanto nao os criar. Mostrar-lhe "0,00 MZN" e um grafico de barras
// vazio nao e informacao: e um esqueleto. Enquanto esta assim, a faixa diz-lhe
// o que falta fazer, pela ordem em que tem de ser feito.
if ((int)($total_produtos ?? 0) === 0):
    $fdPassos = [
        ['n' => 1, 'feito' => false, 'titulo' => 'Criar os produtos',
         'texto' => 'O que a casa vende, com preco. Sem isto nao ha pedidos.',
         'link' => 'produtos.php', 'accao' => 'Criar produtos'],
        ['n' => 2, 'feito' => !empty($caixa_aberto), 'titulo' => 'Abrir a caixa',
         'texto' => 'Marca o inicio do dia e o dinheiro com que comeca.',
         'link' => 'caixa.php', 'accao' => 'Abrir caixa'],
        ['n' => 3, 'feito' => false, 'titulo' => 'Lancar o primeiro pedido',
         'texto' => 'A partir daqui este espaco passa a mostrar o dia.',
         'link' => 'novo_pedido.php', 'accao' => 'Novo pedido'],
    ];
    ?>
    <section class="bas-dia bas-inicio" aria-labelledby="bas-inicio-titulo">
        <p class="bas-dia-sobre">Primeiros passos</p>
        <h2 class="bas-inicio-titulo" id="bas-inicio-titulo">Falta pouco para abrir portas</h2>
        <p class="bas-inicio-sub">Tres passos e o painel passa a mostrar o movimento do dia.</p>
        <ol class="bas-inicio-passos">
            <?php foreach ($fdPassos as $fdP): ?>
                <li class="bas-inicio-passo<?php echo $fdP['feito'] ? ' is-feito' : ''; ?>">
                    <span class="bas-inicio-n" aria-hidden="true"><?php echo $fdP['feito'] ? '&#10003;' : (int)$fdP['n']; ?></span>
                    <span class="bas-inicio-txt">
                        <strong><?php echo htmlspecialchars($fdP['titulo'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        <span><?php echo htmlspecialchars($fdP['texto'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </span>
                    <?php if (!$fdP['feito']): ?>
                        <a class="bas-inicio-accao" href="<?php echo htmlspecialchars($fdP['link'], ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($fdP['accao'], ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    <?php else: ?>
                        <span class="bas-inicio-pronto">feito</span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>
    <?php
    return;
endif;

$fdHoras = $dashBasHoras ?? [];
$fdTemDados = !empty($fdHoras) && ($dashBasPicoValor ?? 0) > 0;
$fdMax = max(0.01, (float)($dashBasPicoValor ?? 0));
$fdTicket = ($qtd_hoje ?? 0) > 0 ? ((float)$total_hoje / max(1, (int)$qtd_hoje)) : 0.0;

// Data em portugues sem depender do locale do servidor, que no Railway vem em
// ingles e escrevia "Thursday" no painel de um restaurante mocambicano.
$fdDias = ['domingo', 'segunda', 'terca', 'quarta', 'quinta', 'sexta', 'sabado'];
$fdDiasAcent = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
$fdMeses = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
$fdData = $fdDiasAcent[(int)date('w')] . ', ' . (int)date('j') . ' de ' . $fdMeses[(int)date('n') - 1];
?>
<section class="bas-dia" aria-labelledby="bas-dia-valor">
    <div class="bas-dia-corpo">
        <div class="bas-dia-numero">
            <p class="bas-dia-sobre">
                Hoje · <?php echo htmlspecialchars($fdData, ENT_QUOTES, 'UTF-8'); ?>
            </p>
            <p class="bas-dia-valor" id="bas-dia-valor">
                <?php echo number_format((float)$total_hoje, 2, ',', '.'); ?><span class="bas-dia-moeda">MZN</span>
            </p>
            <p class="bas-dia-detalhe">
                <?php echo (int)($qtd_hoje ?? 0); ?> venda<?php echo ((int)($qtd_hoje ?? 0)) === 1 ? '' : 's'; ?>
                <?php if (($qtd_hoje ?? 0) > 0): ?>
                    · ticket médio <?php echo number_format($fdTicket, 2, ',', '.'); ?>
                <?php endif; ?>
            </p>
            <?php if (!empty($dashBasTemOntem) && (float)$total_hoje <= 0): ?>
                <?php /* Com zero vendas, "-100%" e exacto e inutil. O que serve e o
                         numero de ontem: diz ao dono o que esta a faltar. */ ?>
                <p class="bas-dia-ontem is-neutro">
                    Ontem a esta hora já tinha <strong><?php echo number_format($dashBasOntemTotal, 0, ',', '.'); ?> MZN</strong>
                </p>
            <?php elseif (!empty($dashBasTemOntem)): ?>
                <?php $fdSobe = ((float)$dashBasDeltaOntem) >= 0; ?>
                <p class="bas-dia-ontem <?php echo $fdSobe ? 'is-sobe' : 'is-desce'; ?>">
                    <i class="fas fa-arrow-<?php echo $fdSobe ? 'up' : 'down'; ?>" aria-hidden="true"></i>
                    <?php echo ($fdSobe ? '+' : '') . number_format((float)$dashBasDeltaOntem, 0, ',', '.'); ?>%
                    face a ontem a esta hora
                </p>
            <?php else: ?>
                <p class="bas-dia-ontem is-neutro">Ontem a esta hora não havia vendas para comparar</p>
            <?php endif; ?>
        </div>

        <div class="bas-dia-ritmo">
            <div class="bas-dia-ritmo-topo">
                <span class="bas-dia-ritmo-rotulo">Ritmo do dia</span>
                <?php if ($fdTemDados && ($dashBasPicoHora ?? null) !== null): ?>
                    <span class="bas-dia-ritmo-pico">
                        mais forte às <strong><?php echo str_pad((string)(int)$dashBasPicoHora, 2, '0', STR_PAD_LEFT); ?>h</strong>
                    </span>
                <?php endif; ?>
            </div>
            <div class="bas-dia-barras" role="img"
                 aria-label="<?php echo $fdTemDados
                    ? 'Vendas por hora. Mais forte às ' . (int)$dashBasPicoHora . ' horas.'
                    : 'Ainda sem vendas pagas hoje.'; ?>">
                <?php foreach ($fdHoras as $fdH):
                    $fdAlt = $fdTemDados ? max(3, (int)round(((float)$fdH['total'] / $fdMax) * 100)) : 3;
                    $fdPico = $fdTemDados && ($dashBasPicoHora ?? null) !== null && (int)$fdH['hora'] === (int)$dashBasPicoHora;
                    $fdTit = str_pad((string)(int)$fdH['hora'], 2, '0', STR_PAD_LEFT) . 'h — '
                        . number_format((float)$fdH['total'], 0, ',', '.') . ' MZN em ' . (int)$fdH['vendas'] . ' venda(s)';
                ?>
                    <div class="bas-dia-barra<?php echo $fdPico ? ' is-pico' : ''; ?><?php echo !empty($fdH['agora']) ? ' is-agora' : ''; ?>"
                         title="<?php echo htmlspecialchars($fdTit, ENT_QUOTES, 'UTF-8'); ?>">
                        <span class="bas-dia-barra-cheio" style="height: <?php echo $fdAlt; ?>%;"></span>
                        <span class="bas-dia-barra-hora"><?php echo str_pad((string)(int)$fdH['hora'], 2, '0', STR_PAD_LEFT); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>
