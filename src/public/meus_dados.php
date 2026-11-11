<?php
require_once __DIR__ . '/app_base.php';
/*
 * Meus dados e plano (Termos de Uso, seccoes 4 e 5):
 *  - exportar todos os dados num ZIP (sempre disponivel, mesmo com o plano bloqueado ou cancelado);
 *  - cancelar o plano (produz efeito no fim do periodo pago) ou desistir do cancelamento.
 * So o administrador que gere o plano.
 */
include_once APP_BASE . '/src/config/auth_check.php';
include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/csrf.php';
include_once APP_BASE . '/src/config/plano_upgrade_guard.php';
include_once APP_BASE . '/src/config/plano_encerramento.php';
include_once APP_BASE . '/src/config/trabalhos_fila.php';
include_once APP_BASE . '/src/config/plano_check.php';

$db = (new Database())->getConnection();
$rid = plano_upgrade_restaurante_alvo_id($db);
$podeGerir = $rid > 0 && plano_upgrade_usuario_pode_gerir($db, (int)($_SESSION['usuario_id'] ?? 0), $rid);
$estado = $podeGerir ? enc_estado($db, $rid) : null;
$lifecycle = (array)($_SESSION['plano_lifecycle'] ?? []);
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$data = static fn($v) => $v ? date('d/m/Y', strtotime((string)$v)) : '';

$cancelado = $estado && strtoupper((string)$estado['status']) === 'CANCELADO';
$pendente = $estado && !$cancelado && !empty($estado['cancelamento_efetivo_em']);
$bloqueado = ($lifecycle['estado'] ?? '') === 'BLOQUEADO_INADIMPLENTE';
$limitado = ($lifecycle['limite_nivel'] ?? '') === 'READ_ONLY';
// Ultima exportacao (fila de trabalhos): mostra se ainda esta a ser preparada ou pronta a descarregar.
$ultimaExp = null;
if ($podeGerir && fila_disponivel($db)) {
    $st = $db->prepare("SELECT *, UNIX_TIMESTAMP(expira_em) AS expira_ts FROM trabalhos_fila WHERE restaurante_id = ? AND tipo = 'exportar_dados'
        AND (estado IN ('PENDENTE', 'A_PROCESSAR') OR (estado = 'PRONTO' AND expira_em > NOW())) ORDER BY id DESC LIMIT 1");
    $st->execute([$rid]);
    $ultimaExp = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
// Empresarial com filiais: exportar a rede inteira e ver quem exportou antes.
$mdUnidadesRede = [];
$mdHistExport = [];
if ($podeGerir) {
    try {
        if (function_exists('plano_tem_funcionalidade_db')
            && plano_tem_funcionalidade_db($rid, 'multi_filial')
            && function_exists('enc_unidades_rede')) {
            $mdUnidadesRede = enc_unidades_rede($db, $rid);
        }
    } catch (Throwable $e) {
        error_log('[meus_dados] rede: ' . $e->getMessage());
    }
    if (fila_disponivel($db)) {
        try {
            $stHist = $db->prepare("
                SELECT t.criado_em, t.estado, t.bytes, t.parametros, t.nome_download, u.nome AS autor
                FROM trabalhos_fila t
                LEFT JOIN usuarios u ON u.id = t.usuario_id
                WHERE t.restaurante_id = ? AND t.tipo = 'exportar_dados'
                ORDER BY t.id DESC
                LIMIT 10
            ");
            $stHist->execute([$rid]);
            $mdHistExport = $stHist->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('[meus_dados] historico: ' . $e->getMessage());
        }
    }
}

// Plano actual, para quem chega aqui nao ter de ir as configuracoes confirmar.
$mdPlanoNome = '';
$mdPlanoFim = '';
$mdPlanoDias = null;
if ($podeGerir && $estado) {
    try {
        $dadosPlanoMd = function_exists('plano_get_dados') ? plano_get_dados($rid) : [];
        $mdPlanoNome = function_exists('plano_normalizar_nome')
            ? plano_normalizar_nome($dadosPlanoMd['plano_nome'] ?? '')
            : (string)($dadosPlanoMd['plano_nome'] ?? '');
        $mdPlanoNome = ucfirst(mb_strtolower($mdPlanoNome, 'UTF-8'));
        $mdPlanoFim = (string)($estado['data_fim'] ?? '');
        if ($mdPlanoFim !== '') {
            $hojeMd = new DateTimeImmutable('today');
            $fimMd = new DateTimeImmutable($mdPlanoFim);
            $mdPlanoDias = (int)$hojeMd->diff($fimMd)->format('%r%a');
        }
    } catch (Throwable $e) {
        error_log('[meus_dados] plano: ' . $e->getMessage());
    }
}
// Ultima exportacao concluida: serve para dizer o que esperar desta.
$mdUltimaPronta = null;
foreach ($mdHistExport as $linhaPronta) {
    if (strtoupper((string)$linhaPronta['estado']) === 'PRONTO' && !empty($linhaPronta['bytes'])) {
        $mdUltimaPronta = $linhaPronta;
        break;
    }
}

$nonce = function_exists('security_csp_nonce') ? security_csp_nonce() : '';
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $h(csrf_get_token()) ?>">
    <title>Meus dados e plano - Nivora</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link href="lib/bootstrap-5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="lib/fontawesome-6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/premium.css">
    <link rel="stylesheet" href="css/tokens.css?v=<?= (int)@filemtime(__DIR__ . '/css/tokens.css') ?>">
    <link rel="stylesheet" href="css/feedback.css?v=<?= (int)@filemtime(__DIR__ . '/css/feedback.css') ?>">
    <style>
        .md{max-width:none;padding:28px 28px 60px}
        .md h1{font-size:1.6rem;font-weight:700;margin:0 0 4px}
        .md__sub{color:#475569;margin:0 0 22px}
        .md__aviso{display:flex;gap:12px;align-items:flex-start;padding:14px 16px;border-radius:12px;margin-bottom:18px;font-size:.95rem}
        .md__aviso i{margin-top:3px}
        .md__aviso--vermelho{background:#fee2e2;color:#7f1d1d}.md__aviso--amarelo{background:#fef3c7;color:#78350f}.md__aviso--azul{background:#e0f2fe;color:#0c4a6e}
                .md__plano{display:flex;flex-wrap:wrap;gap:28px;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:16px 22px;margin-bottom:18px;box-shadow:0 4px 14px rgba(15,23,42,.05)}
        .md__plano>div{display:flex;flex-direction:column;gap:2px}
        .md__plano-rot{font-size:.7rem;letter-spacing:.08em;text-transform:uppercase;color:#64748b;font-weight:700}
        .md__plano strong{font-size:1.05rem;color:#0f172a}
        .md__plano .md__plano-aviso{color:#b91c1c}
        .md__espera{font-size:.86rem;color:#64748b;margin:0 0 12px}
        .md__cartao{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:22px;margin-bottom:18px;box-shadow:0 4px 14px rgba(15,23,42,.05)}
        .md__rede{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:14px 0}
        @media (max-width:640px){.md__rede{grid-template-columns:1fr}}
        .md__rede-opcao{display:flex;gap:10px;align-items:flex-start;border:1px solid #e2e8f0;border-radius:12px;padding:12px 14px;cursor:pointer}
        .md__rede-opcao:has(input:checked){border-color:#ea580c;background:#fff7ed}
        .md__rede-opcao input{margin-top:3px}
        .md__rede-opcao strong{display:block;font-size:.95rem}
        .md__rede-opcao small{display:block;color:#64748b}
        .md__hist{margin-top:16px}
        .md__hist summary{cursor:pointer;font-weight:600;color:#334155}
        .md__hist-tabela{width:100%;border-collapse:collapse;margin-top:10px;font-size:.9rem}
        .md__hist-tabela th{text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;color:#64748b;border-bottom:1px solid #e2e8f0;padding:6px 8px}
        .md__hist-tabela td{padding:6px 8px;border-bottom:1px solid #f1f5f9}
        .md__hist small{display:block;margin-top:8px;color:#64748b}
        .md__cartao h2{font-size:1.15rem;font-weight:700;margin:0 0 6px;display:flex;gap:10px;align-items:center}
        .md__cartao p{color:#334155;margin:0 0 12px}
        .md__lista{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 16px;padding:0;list-style:none}
        .md__lista li{background:#f1f5f9;border-radius:999px;padding:4px 12px;font-size:.85rem;color:#334155}
        .md__btn{display:inline-flex;gap:8px;align-items:center;border:0;border-radius:10px;padding:11px 18px;font-weight:600;min-height:44px;cursor:pointer}
        .md__btn--principal{background:var(--nv-plano,#0f172a);color:#fff}
        .md__btn--perigo{background:#fff;color:#b91c1c;border:1.5px solid #fca5a5}
        .md__btn--perigo:hover{background:#fef2f2}
        .md__btn:disabled{opacity:.6;cursor:wait}
        .md__exp{margin-top:14px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;padding:12px 14px;border-radius:10px;border:1px solid #e2e8f0;background:#f8fafc;font-size:.92rem;color:#334155}
        .md__exp--pronto{background:#ecfdf3;border-color:#abefc6;color:#05603a}
        .md__exp--erro{background:#fef3f2;border-color:#fecdca;color:#912018}
        .md__exp a.md__btn{min-height:38px;padding:8px 14px;text-decoration:none}
        .md__exp small{display:block;color:inherit;opacity:.8}
        .md__passos{margin:0 0 16px;padding-left:20px;color:#334155}
        .md__passos li{margin-bottom:4px}
        .md label{font-weight:600;font-size:.9rem;margin-bottom:4px;display:block}
        .md textarea,.md input[type=text]{width:100%;border:1px solid #cbd5e1;border-radius:10px;padding:10px 12px;font:inherit;margin-bottom:12px}
        .md textarea:focus,.md input:focus{outline:3px solid #93c5fd;border-color:#3b82f6}

        /* Profissional e Empresarial: apresentacao mais cuidada. O Basico fica como esta. */
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .md{max-width:none;padding-top:32px}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .md__topo{border-radius:16px;padding:24px 28px;margin-bottom:22px;background:var(--nv-plano-suave,#f1f5f9);border:1px solid color-mix(in srgb,var(--nv-plano) 18%,#fff)}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .md__topo .md__sub{margin:0}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .md__etiqueta{display:inline-flex}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .md__cartao{border-radius:16px;padding:26px 28px;border-top:3px solid var(--nv-plano);box-shadow:0 1px 2px rgba(15,23,42,.04),0 10px 28px rgba(15,23,42,.06)}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .md__cartao h2{font-size:1.2rem;gap:12px;margin-bottom:10px}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .md__cartao h2 i{width:40px;height:40px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;font-size:1rem;background:var(--nv-plano-suave,#f1f5f9);color:var(--nv-plano);border:1px solid color-mix(in srgb,var(--nv-plano) 22%,#fff)}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .md__cartao:has(#tCancelar){border-top-color:#b42318}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) #tCancelar i{background:#fef3f2;color:#b42318;border-color:#fecdca}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .md__cartao > p{color:#475569;line-height:1.6}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .md__lista{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px;margin-bottom:20px}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .md__lista li{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:9px 12px;font-size:.88rem;color:#1e293b;display:flex;align-items:center;gap:8px}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .md__lista li::before{content:"\f00c";font-family:"Font Awesome 6 Free";font-weight:900;font-size:.75rem;color:var(--nv-plano)}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .md__btn{border-radius:10px;padding:11px 22px}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .md__btn--principal{box-shadow:0 6px 16px color-mix(in srgb,var(--nv-plano) 28%,transparent)}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .md__btn--principal:hover{filter:brightness(.94)}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .md__passos{list-style:none;padding:0;counter-reset:passo;display:grid;gap:10px;margin-bottom:22px}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .md__passos li{counter-increment:passo;position:relative;margin:0;padding:12px 14px 12px 52px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc;line-height:1.5}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .md__passos li::before{content:counter(passo);position:absolute;left:14px;top:11px;width:26px;height:26px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700;background:#fff;color:#0f172a;border:1px solid #cbd5e1}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .md textarea,
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .md input[type=text]{border-radius:10px;padding:11px 14px;background:#fff}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .md textarea:focus,
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .md input:focus{outline:none;border-color:var(--nv-plano);box-shadow:0 0 0 3px color-mix(in srgb,var(--nv-plano) 20%,transparent)}
        body:is(.plan-theme-profissional,.plan-theme-empresarial) .md__aviso{border-radius:12px;border:1px solid rgba(15,23,42,.06)}
        /* Empresarial: cabecalho escuro, mais institucional. */
        body.plan-theme-empresarial .md__topo{background:linear-gradient(120deg,#0f172a 0%,#1e293b 60%,color-mix(in srgb,var(--nv-plano) 55%,#1e293b) 100%);border:0;color:#fff;box-shadow:0 12px 30px rgba(15,23,42,.18)}
        body.plan-theme-empresarial .md__topo h1{color:#fff}
        body.plan-theme-empresarial .md__topo .md__sub{color:#cbd5e1}
        .md__etiqueta{display:none;align-items:center;gap:6px;font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:4px 10px;border-radius:999px;margin-bottom:10px;background:#fff;color:var(--nv-plano);border:1px solid color-mix(in srgb,var(--nv-plano) 25%,#fff)}
        body.plan-theme-empresarial .md__etiqueta{background:rgba(255,255,255,.1);color:#fed7aa;border-color:rgba(255,255,255,.18)}
    </style>
</head>
<body class="premium-ui">
<div class="container-fluid">
    <div class="row">
        <?php include_once __DIR__ . '/includes/sidebar.php'; ?>
        <main class="main-content md" id="conteudo">
            <header class="md__topo">
                <span class="md__etiqueta"><i class="fas fa-shield-halved" aria-hidden="true"></i> Conta e dados</span>
                <h1>Meus dados e plano</h1>
                <p class="md__sub">Exporte os dados do seu restaurante a qualquer momento, ou cancele o plano.</p>
            </header>

            <?php if (!$podeGerir): ?>
                <div class="md__aviso md__aviso--amarelo" role="alert"><i class="fas fa-lock" aria-hidden="true"></i>
                    <div>Só o administrador do restaurante (matriz) pode exportar os dados ou cancelar o plano.</div></div>
            <?php else: ?>

                <?php if ($cancelado): ?>
                    <div class="md__aviso md__aviso--vermelho" role="status"><i class="fas fa-circle-xmark" aria-hidden="true"></i>
                        <div><strong>Esta conta foi encerrada a <?= $h($data($estado['cancelado_em'])) ?>.</strong>
                        Exporte os seus dados agora: serão apagados definitivamente a <strong><?= $h($data($estado['eliminacao_agendada_em'])) ?></strong>.
                        Para reabrir a conta antes disso, contacte o suporte.</div></div>
                <?php elseif ($bloqueado): ?>
                    <div class="md__aviso md__aviso--vermelho" role="status"><i class="fas fa-lock" aria-hidden="true"></i>
                        <div><strong>O plano venceu há mais de 7 dias e o sistema está bloqueado.</strong>
                        Pode exportar os seus dados aqui. Para voltar a usar o sistema, <a href="configuracoes.php?secao=plano">renove o plano</a>.</div></div>
                <?php elseif ($limitado): ?>
                    <div class="md__aviso md__aviso--amarelo" role="status"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
                        <div><strong>O plano venceu: está em modo limitado.</strong> Pode consultar e exportar, mas não registar vendas.
                        <a href="configuracoes.php?secao=plano">Renove o plano</a> para voltar a vender.</div></div>
                <?php elseif ($pendente): ?>
                    <div class="md__aviso md__aviso--azul" role="status"><i class="fas fa-calendar-xmark" aria-hidden="true"></i>
                        <div><strong>Cancelamento agendado para <?= $h($data($estado['cancelamento_efetivo_em'])) ?>.</strong>
                        Até lá o sistema funciona normalmente. Pode desistir do cancelamento abaixo.</div></div>
                <?php endif; ?>

                <?php if ($mdPlanoNome !== ''): ?>
                    <div class="md__plano">
                        <div>
                            <span class="md__plano-rot">Plano actual</span>
                            <strong><?= $h($mdPlanoNome) ?></strong>
                        </div>
                        <?php if ($mdPlanoFim !== ''): ?>
                            <div>
                                <span class="md__plano-rot">Válido até</span>
                                <strong><?= $h($data($mdPlanoFim)) ?></strong>
                            </div>
                            <div>
                                <span class="md__plano-rot">Dias restantes</span>
                                <strong class="<?= ($mdPlanoDias !== null && $mdPlanoDias <= 7) ? 'md__plano-aviso' : '' ?>"><?= $mdPlanoDias !== null ? max(0, $mdPlanoDias) : '—' ?></strong>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <section class="md__cartao" aria-labelledby="tExportar">
                    <h2 id="tExportar"><i class="fas fa-file-zipper" aria-hidden="true"></i> Exportar todos os dados</h2>
                    <p>Recebe um ficheiro ZIP com um <b>ficheiro Excel</b> (um separador por assunto: vendas, pedidos, caixa, produtos, clientes, equipa…), um <b>resumo</b> para abrir no navegador e uma cópia técnica em CSV para levar para outro sistema. Senhas e PIN nunca são exportados.</p>
                    <ul class="md__lista" aria-label="O que vai no ficheiro">
                        <li>Produtos e categorias</li><li>Vendas e itens</li><li>Pedidos</li><li>Clientes</li><li>Mesas e reservas</li>
                        <li>Ingredientes e receitas</li><li>Equipa e turnos</li><li>Caixa</li>
                    </ul>
                    <?php if (count($mdUnidadesRede) > 1): ?>
                        <div class="md__rede">
                            <label class="md__rede-opcao">
                                <input type="radio" name="mdAmbito" value="unidade" checked>
                                <span><strong>Só esta unidade</strong><small>Dados da matriz.</small></span>
                            </label>
                            <label class="md__rede-opcao">
                                <input type="radio" name="mdAmbito" value="rede">
                                <span><strong>Toda a rede</strong><small>Matriz e as <?= count($mdUnidadesRede) - 1 ?> filial(is), cada uma no seu ficheiro dentro do ZIP.</small></span>
                            </label>
                        </div>
                    <?php endif; ?>
                    <p class="md__espera">
                        <?php if ($mdUltimaPronta !== null): $bytesUlt = (int)$mdUltimaPronta['bytes']; ?>
                            A última exportação ficou com <?= $h($bytesUlt >= 1048576 ? number_format($bytesUlt / 1048576, 1, ',', '.') . ' MB' : max(1, (int)round($bytesUlt / 1024)) . ' KB') ?> e foi preparada a <?= $h(date('d/m/Y H:i', strtotime((string)$mdUltimaPronta['criado_em']))) ?>. O ficheiro é preparado em segundo plano — costuma demorar um a dois minutos.
                        <?php else: ?>
                            O ficheiro é preparado em segundo plano e costuma demorar um a dois minutos. Pode sair desta página: o botão volta a mostrar a ligação quando estiver pronto.
                        <?php endif; ?>
                    </p>
                    <button type="button" class="md__btn md__btn--principal" id="mdExportar"><i class="fas fa-download" aria-hidden="true"></i> Exportar agora</button>
                    <div id="mdExpEstado" class="md__exp" role="status" aria-live="polite" hidden></div>
                    <script type="application/json" id="mdExpInicial"><?= json_encode($ultimaExp ? fila_publico($ultimaExp) : null, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

                    <?php if (!empty($mdHistExport)): ?>
                        <details class="md__hist">
                            <summary>Quem exportou e quando (<?= count($mdHistExport) ?>)</summary>
                            <table class="md__hist-tabela">
                                <thead><tr><th>Quando</th><th>Quem</th><th>Âmbito</th><th>Estado</th></tr></thead>
                                <tbody>
                                <?php foreach ($mdHistExport as $linhaHist):
                                    $paramsHist = json_decode((string)($linhaHist['parametros'] ?? ''), true) ?: [];
                                    $estadoHist = strtoupper((string)$linhaHist['estado']);
                                    $rotulosHist = ['PRONTO' => 'Pronto', 'PENDENTE' => 'Na fila', 'A_PROCESSAR' => 'A preparar', 'ERRO' => 'Falhou', 'EXPIRADO' => 'Expirado'];
                                ?>
                                    <tr>
                                        <td><?= $h(date('d/m/Y H:i', strtotime((string)$linhaHist['criado_em']))) ?></td>
                                        <td><?= $h($linhaHist['autor'] ?: 'Conta removida') ?></td>
                                        <td><?= empty($paramsHist['rede']) ? 'Esta unidade' : 'Toda a rede' ?></td>
                                        <td><?= $h($rotulosHist[$estadoHist] ?? $estadoHist) ?><?php if (!empty($linhaHist['bytes'])): $bytesHist = (int)$linhaHist['bytes']; ?>
                                             · <?= $h($bytesHist >= 1048576 ? number_format($bytesHist / 1048576, 1, ',', '.') . ' MB' : max(1, (int)round($bytesHist / 1024)) . ' KB') ?>
                                        <?php endif; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <small>Guardado porque a exportação inclui dados de clientes e de vendas.</small>
                        </details>
                    <?php endif; ?>
                </section>

                <?php if (!$cancelado): ?>
                <section class="md__cartao" aria-labelledby="tCancelar">
                    <h2 id="tCancelar"><i class="fas fa-door-open" aria-hidden="true"></i> Cancelar o plano</h2>
                    <?php if ($pendente): ?>
                        <p>O cancelamento produz efeito a <strong><?= $h($data($estado['cancelamento_efetivo_em'])) ?></strong>. Mudou de ideias?</p>
                        <button type="button" class="md__btn md__btn--principal" id="mdDesistir"><i class="fas fa-rotate-left" aria-hidden="true"></i> Desistir do cancelamento</button>
                    <?php else: ?>
                        <p>Pode cancelar quando quiser. O que acontece:</p>
                        <ol class="md__passos">
                            <?php if (!empty($estado['data_fim']) && $estado['data_fim'] >= date('Y-m-d')): ?>
                            <li>O sistema continua a funcionar até ao fim do período já pago (<?= $h($data($estado['data_fim'])) ?>). Esse período não é reembolsado.</li>
                            <?php else: ?>
                            <li>Como o plano já venceu, o cancelamento produz efeito hoje.</li>
                            <?php endif; ?>
                            <li>Depois disso, tem <strong><?= ENC_DIAS_EXPORTAR ?> dias</strong> para exportar os dados.</li>
                            <li>Os dados são apagados <?= ENC_DIAS_ELIMINAR ?> dias depois. Avisamos por email <?= ENC_DIAS_AVISO ?> dias antes e enviamos a declaração de eliminação.</li>
                        </ol>
                        <label for="mdMotivo">Porque vai cancelar? (opcional, ajuda-nos a melhorar)</label>
                        <textarea id="mdMotivo" rows="2" maxlength="500"></textarea>
                        <label for="mdConfirma">Para confirmar, escreva <strong>CANCELAR</strong></label>
                        <input type="text" id="mdConfirma" autocomplete="off" maxlength="12">
                        <button type="button" class="md__btn md__btn--perigo" id="mdCancelar"><i class="fas fa-xmark" aria-hidden="true"></i> Cancelar o plano</button>
                    <?php endif; ?>
                </section>
                <?php endif; ?>
            <?php endif; ?>
        </main>
    </div>
</div>
<script src="js/feedback.js?v=<?= (int)@filemtime(__DIR__ . '/js/feedback.js') ?>"></script>
<script src="js/nivora_dialogo.js?v=<?= (int)@filemtime(__DIR__ . '/js/nivora_dialogo.js') ?>"></script>
<script nonce="<?= $h($nonce) ?>">
(function () {
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    function avisar(msg, tipo) {
        if (window.nivoraAviso) { window.nivoraAviso(msg, { tipo: tipo }); return; }
        if (window.NivoraFeedback) {
            var f = { erro: 'error', aviso: 'warning', sucesso: 'success' }[tipo] || 'info';
            if (typeof NivoraFeedback[f] === 'function') { NivoraFeedback[f](msg); return; }
        }
        var caixa = document.getElementById('mdAvisoSimples');
        if (!caixa) {
            caixa = document.createElement('div');
            caixa.id = 'mdAvisoSimples';
            caixa.className = 'md__aviso md__aviso--azul';
            caixa.setAttribute('role', 'status');
            var alvo = document.querySelector('.md') || document.body;
            alvo.insertBefore(caixa, alvo.firstChild);
        }
        caixa.className = 'md__aviso ' + (tipo === 'erro' ? 'md__aviso--vermelho' : (tipo === 'aviso' ? 'md__aviso--amarelo' : 'md__aviso--azul'));
        caixa.textContent = String(msg == null ? '' : msg);
        caixa.scrollIntoView({ block: 'nearest' });
    }
    function enviar(url, dados) {
        var fd = new FormData();
        Object.keys(dados).forEach(function (k) { fd.append(k, dados[k]); });
        fd.append('_csrf', csrf);
        return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf } });
    }
    var bExp = document.getElementById('mdExportar');
    var caixaEstado = document.getElementById('mdExpEstado');
    var esc = function (t) { var d = document.createElement('div'); d.textContent = String(t == null ? '' : t); return d.innerHTML; };
    var tamanho = function (b) { return b > 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; };
    var hora = function (ts) { if (!ts) { return ''; } var x = new Date(ts * 1000); return x.toLocaleString('pt-PT', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }); };
    var descarregado = {};
    function mostrar(t) {
        if (!caixaEstado) { return; }
        caixaEstado.hidden = false;
        caixaEstado.className = 'md__exp';
        if (!t) { caixaEstado.hidden = true; return; }
        if (t.pronto) {
            var url = 'api/trabalho_descarregar.php?id=' + encodeURIComponent(t.id);
            caixaEstado.classList.add('md__exp--pronto');
            caixaEstado.innerHTML = '<i class="fas fa-circle-check" aria-hidden="true"></i><div><strong>O ficheiro está pronto.</strong>'
                + '<small>' + (t.tamanho ? esc(tamanho(t.tamanho)) + ' · ' : '') + 'disponível até ' + esc(hora(t.expira_ts)) + '</small></div>'
                + '<a class="md__btn md__btn--principal" href="' + url + '"><i class="fas fa-download" aria-hidden="true"></i> Descarregar</a>';
            if (!descarregado[t.id] && t._novo) { descarregado[t.id] = true; location.href = url; }
            if (bExp) { bExp.disabled = false; }
            return;
        }
        if (t.estado === 'ERRO') {
            caixaEstado.classList.add('md__exp--erro');
            caixaEstado.innerHTML = '<i class="fas fa-circle-xmark" aria-hidden="true"></i><div><strong>Não foi possível preparar o ficheiro.</strong><small>' + esc(t.erro || 'Tente de novo ou contacte o suporte.') + '</small></div>';
            if (bExp) { bExp.disabled = false; }
            return;
        }
        caixaEstado.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i><div><strong>A preparar o ficheiro…</strong>'
            + '<small>Pode continuar a usar o sistema ou sair desta página: enviamos um email quando estiver pronto.</small></div>';
        if (bExp) { bExp.disabled = true; }
    }
    function acompanhar(id, novo) {
        fetch('api/trabalho_estado.php?id=' + encodeURIComponent(id), { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok || !j.success) { throw new Error(j.message || 'Não foi possível saber o estado.'); } return j.trabalho; }); })
            .then(function (t) {
                t._novo = novo;
                mostrar(t);
                if (!t.pronto && t.estado !== 'ERRO' && t.estado !== 'EXPIRADO') { setTimeout(function () { acompanhar(id, novo); }, 2500); }
            })
            .catch(function () { setTimeout(function () { acompanhar(id, novo); }, 8000); });
    }
    try {
        var inicial = JSON.parse((document.getElementById('mdExpInicial') || {}).textContent || 'null');
        if (inicial) { mostrar(inicial); if (!inicial.pronto) { acompanhar(inicial.id, true); } }
    } catch (e) {}
    if (bExp) {
        bExp.addEventListener('click', function () {
            bExp.disabled = true;
            mostrar({ estado: 'PENDENTE' });
            var ambitoEl = document.querySelector('input[name="mdAmbito"]:checked');
            var corpoExp = (ambitoEl && ambitoEl.value === 'rede') ? { rede: '1' } : {};
            enviar('api/plano_exportar_dados.php', corpoExp).then(function (r) {
                var tipo = r.headers.get('Content-Type') || '';
                if (r.ok && tipo.indexOf('zip') !== -1) {
                    // Servidor sem fila de trabalhos: o ficheiro vem logo.
                    var nome = ((r.headers.get('Content-Disposition') || '').match(/filename="([^"]+)"/) || [])[1] || 'dados.zip';
                    return r.blob().then(function (b) {
                        var a = document.createElement('a');
                        a.href = URL.createObjectURL(b); a.download = nome;
                        document.body.appendChild(a); a.click(); a.remove();
                        setTimeout(function () { URL.revokeObjectURL(a.href); }, 4000);
                        mostrar(null); bExp.disabled = false;
                        avisar('Ficheiro descarregado: ' + nome, 'sucesso');
                    });
                }
                return r.json().catch(function () { return {}; }).then(function (j) {
                    if (!r.ok || !j.success || !j.trabalho) { throw new Error(j.message || 'Não foi possível exportar.'); }
                    acompanhar(j.trabalho.id, true);
                });
            }).catch(function (e) { mostrar(null); bExp.disabled = false; avisar(e.message, 'erro'); });
        });
    }
    function tratar(r) {
        return r.json().catch(function () { return {}; }).then(function (j) {
            if (!r.ok || !j.success) { throw new Error(j.message || 'Não foi possível concluir.'); }
            if (window.NivoraFeedback) { window.NivoraFeedback.reloadWithMessage(j.message, 'success'); } else { location.reload(); }
        });
    }
    var bCan = document.getElementById('mdCancelar');
    if (bCan) {
        bCan.addEventListener('click', function () {
            var conf = (document.getElementById('mdConfirma').value || '').trim().toUpperCase();
            if (conf !== 'CANCELAR') { avisar('Escreva CANCELAR no campo de confirmação.', 'aviso'); document.getElementById('mdConfirma').focus(); return; }
            var pergunta = window.nivoraConfirmar
                ? window.nivoraConfirmar({ titulo: 'Cancelar o plano?', mensagem: 'O sistema funciona até ao fim do período pago. Depois tem <?= ENC_DIAS_EXPORTAR ?> dias para exportar os dados.', confirmar: 'Sim, cancelar', cancelar: 'Voltar', perigo: true, icone: 'fas fa-file-circle-xmark' })
                : Promise.resolve(window.confirm('Cancelar o plano? O sistema funciona até ao fim do período já pago.'));
            pergunta.then(function (ok) {
                if (!ok) { return; }
                bCan.disabled = true;
                enviar('api/plano_cancelar.php', { acao: 'cancelar', confirmacao: conf, motivo: document.getElementById('mdMotivo').value || '' })
                    .then(tratar).catch(function (e) { avisar(e.message, 'erro'); bCan.disabled = false; });
            });
        });
    }
    var bDes = document.getElementById('mdDesistir');
    if (bDes) {
        bDes.addEventListener('click', function () {
            bDes.disabled = true;
            enviar('api/plano_cancelar.php', { acao: 'desistir' }).then(tratar).catch(function (e) { avisar(e.message, 'erro'); bDes.disabled = false; });
        });
    }
})();
</script>
</body>
</html>
