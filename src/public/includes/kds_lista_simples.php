<?php
// Este ficheiro e um fragmento: so faz sentido incluido por uma pagina, que e
// quem verifica a sessao. Pedido directamente pelo navegador, nao responde.
// Hoje o reescritor de URLs ja devolve 404, mas isso e um efeito secundario
// das regras de reescrita, nao uma decisao: se mudarem, o fragmento ficava a
// descoberto. Esta linha torna a recusa deliberada.
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Not found');
}

/**
 * Lista simples de preparo (plano Básico).
 * Variáveis: $kdsTituloArea, $grupos, $kdsPlanLabel, $perfil, $kdsSlaCrit
 */
$listaPreparo = $grupos['em_preparo'] ?? [];
$listaPronto = $grupos['pronto'] ?? [];
?>
<div class="kds-simple">
    <header class="kds-simple-top">
        <div>
            <h1><?php echo htmlspecialchars($kdsTituloArea, ENT_QUOTES, 'UTF-8'); ?>
                <span class="kds-plan-badge"><?php echo htmlspecialchars($kdsPlanLabel, ENT_QUOTES, 'UTF-8'); ?></span>
            </h1>
            <p>Lista simples de itens — marque pronto e entregue.</p>
        </div>
        <div class="kds-simple-actions">
            <span id="kds-clock" class="kds-simple-clock"></span>
            <?php if (in_array(strtoupper((string)$perfil), ['ADMIN', 'GERENTE', 'GARCOM', 'SUPER_ADMIN'], true)): ?>
                <a class="btn-simple primary" href="novo_pedido.php">+ Novo Pedido</a>
            <?php endif; ?>
            <a class="btn-simple" href="<?php echo in_array(strtoupper((string)$perfil), ['BAR', 'COZINHA'], true) ? 'iniciar_turno.php' : 'dashboard.php'; ?>">Voltar</a>
        </div>
    </header>

    <div class="kds-refresh-root">
        <section class="kds-simple-section">
            <div class="kds-simple-section-head">
                <strong>A preparar</strong>
                <span class="kds-simple-count"><?php echo count($listaPreparo); ?></span>
            </div>
            <?php if (empty($listaPreparo)): ?>
                <div class="kds-simple-empty">Nenhum item a preparar.</div>
            <?php else: ?>
                <ul class="kds-simple-list">
                    <?php foreach ($listaPreparo as $item):
                        $criado = strtotime($item['data_status'] ?? '');
                        $minutos = $criado ? (int)floor((time() - $criado) / 60) : 0;
                        $tempoTexto = $minutos <= 0 ? 'agora' : ($minutos . ' min');
                        $mesaTexto = !empty($item['mesa_numero']) ? ('Mesa ' . (int)$item['mesa_numero']) : 'Balcão';
                        $qtd = (int)($item['quantidade'] ?? 1);
                        ?>
                        <li class="kds-simple-row card-item" data-id="<?php echo (int)$item['id']; ?>" data-status="<?php echo htmlspecialchars((string)$item['status'], ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="kds-simple-main">
                                <div class="kds-simple-meta"><?php echo htmlspecialchars($mesaTexto, ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($tempoTexto, ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="kds-simple-name"><strong><?php echo $qtd; ?>x</strong> <?php echo htmlspecialchars((string)$item['produto_nome'], ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                            <button class="btn-status btn-ready btn-simple-action" type="button" data-next="pronto">Pronto</button>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="kds-simple-section is-ready">
            <div class="kds-simple-section-head">
                <strong>Prontos para entregar</strong>
                <span class="kds-simple-count"><?php echo count($listaPronto); ?></span>
            </div>
            <?php if (empty($listaPronto)): ?>
                <div class="kds-simple-empty">Nada pronto no momento.</div>
            <?php else: ?>
                <ul class="kds-simple-list">
                    <?php foreach ($listaPronto as $item):
                        $mesaTexto = !empty($item['mesa_numero']) ? ('Mesa ' . (int)$item['mesa_numero']) : 'Balcão';
                        $qtd = (int)($item['quantidade'] ?? 1);
                        ?>
                        <li class="kds-simple-row card-item" data-id="<?php echo (int)$item['id']; ?>" data-status="<?php echo htmlspecialchars((string)$item['status'], ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="kds-simple-main">
                                <div class="kds-simple-meta"><?php echo htmlspecialchars($mesaTexto, ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="kds-simple-name"><strong><?php echo $qtd; ?>x</strong> <?php echo htmlspecialchars((string)$item['produto_nome'], ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                            <button class="btn-status btn-delivered btn-simple-action" type="button" data-next="entregue">Entregar</button>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</div>
