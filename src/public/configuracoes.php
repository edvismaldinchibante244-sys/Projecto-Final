<?php
require_once __DIR__ . '/app_base.php';
// Proteção da página - apenas ADMIN
include_once APP_BASE . '/src/config/auth_check.php';
include_once APP_BASE . '/src/config/csrf.php';

// Verificar restaurante_id (com fallback robusto para links de upgrade)
$restaurante_id = session_restaurante_contexto_id();
$restauranteCapabilityId = session_restaurante_capability_id();
$ridQuery = isset($_GET['rid']) ? (int)$_GET['rid'] : 0;
if ($restaurante_id <= 0) {
    if ($ridQuery > 0 && $restauranteCapabilityId > 0 && $ridQuery === $restauranteCapabilityId) {
        $restaurante_id = $ridQuery;
        $_SESSION['restaurante_id'] = $ridQuery;
    } elseif ($restauranteCapabilityId > 0) {
        $restaurante_id = $restauranteCapabilityId;
        $_SESSION['restaurante_id'] = $restauranteCapabilityId;
    }
}
if ($restaurante_id <= 0) {
    header("Location: index.php?erro=sem_restaurante");
    exit;
}

// Verificar se é admin
if (($_SESSION['perfil'] ?? '') !== 'ADMIN') {
    header("Location: dashboard.php");
    exit;
}

include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/plano_check.php';
include_once APP_BASE . '/src/config/plano_upgrade_guard.php';
include_once APP_BASE . '/src/config/brand_theme_helper.php';
require_once APP_BASE . '/src/config/plano_pagamentos.php';

$database = new Database();
$db = $database->getConnection();
brand_theme_ensure_columns($db);
$restaurantePlanoUpgradeId = plano_upgrade_restaurante_alvo_id($db);
$podeGerirPlanos = plano_upgrade_usuario_pode_gerir($db, (int)($_SESSION['usuario_id'] ?? 0), $restaurantePlanoUpgradeId);
$csrfToken = csrf_get_token();
$planoPaymentInstructions = plano_pagamento_instrucoes_publicas();

$query = "SELECT * FROM restaurantes WHERE id = :id LIMIT 1";
$stmt  = $db->prepare($query);
$stmt->bindValue(':id', $restaurante_id, PDO::PARAM_INT);
$stmt->execute();
$restaurante = $stmt->fetch(PDO::FETCH_ASSOC);

// Garantir que $restaurante é um array válido para evitar warnings
if (!$restaurante || !is_array($restaurante)) {
    $restaurante = [
        'nome' => '',
        'telefone' => '',
        'endereco' => '',
        'cidade' => '',
        'nuit' => '',
        'logo' => '',
        'plano' => 'BASICO',
        'status' => 'INATIVO',
        'data_fim' => date('Y-m-d', strtotime('+30 days'))
    ];
}

$mensagem = '';
$tipo_msg = '';

// Verificar se há uma seção específica para mostrar
$secao = $_GET['secao'] ?? '';
$erro = $_GET['erro'] ?? '';
$planoRestrito = in_array($erro, ['plano_restrito', 'plano_modo_limitado'], true);
$planoModoLimitado = ($erro === 'plano_modo_limitado') || (($_SESSION['plano_lifecycle']['limite_nivel'] ?? '') === 'READ_ONLY');
$planoAlertaSessao = is_array($_SESSION['plano_alerta'] ?? null) ? $_SESSION['plano_alerta'] : [];

// A pagina das Filiais manda para aqui quando o plano deixa de ter
// multi-filial -- tipicamente no fim da oferta de boas-vindas. Ate agora
// ninguem lia este parametro: o cliente era atirado para outra pagina sem
// explicacao nenhuma, e e dai que vem a reclamacao de que "perdeu" a filial.
$planoSemMultiFilial = ($erro === 'plano_sem_multi_filial');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome     = trim($_POST['nome'] ?? '');
    $telefone = trim($_POST['telefone'] ?? '');
    $endereco = trim($_POST['endereco'] ?? '');
    $cidade   = trim($_POST['cidade'] ?? '');
    $nuit     = trim($_POST['nuit'] ?? '');
    $corPrimaria = brand_theme_normalize_hex($_POST['cor_primaria'] ?? '');
    $corSecundaria = brand_theme_normalize_hex($_POST['cor_secundaria'] ?? '');
    $logoAtual = trim((string)($restaurante['logo'] ?? ''));
    $logoNovo = $logoAtual;
    $logoTmpAbsolute = null;

    if (empty($nome)) {
        $mensagem = 'O nome do restaurante é obrigatório.';
        $tipo_msg = 'danger';
    } else {
        if (isset($_FILES['logo']) && (int)($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $logoError = (int)($_FILES['logo']['error'] ?? UPLOAD_ERR_OK);
            if ($logoError !== UPLOAD_ERR_OK) {
                $mensagem = 'Falha no envio do logotipo. Tente novamente.';
                $tipo_msg = 'danger';
            } else {
                $logoExt = strtolower(pathinfo((string)($_FILES['logo']['name'] ?? ''), PATHINFO_EXTENSION));
                $logoAllowedExt = ['jpg', 'jpeg', 'png', 'webp'];
                $logoAllowedMime = ['image/jpeg', 'image/png', 'image/webp'];
                $logoMaxSize = 4 * 1024 * 1024;

                if (!in_array($logoExt, $logoAllowedExt, true)) {
                    $mensagem = 'Logotipo inválido. Envie JPG, PNG ou WEBP.';
                    $tipo_msg = 'danger';
                } elseif ((int)($_FILES['logo']['size'] ?? 0) > $logoMaxSize) {
                    $mensagem = 'Logotipo excede 4MB.';
                    $tipo_msg = 'danger';
                } else {
                    $logoMime = '';
                    if (function_exists('finfo_open')) {
                        $finfoLogo = finfo_open(FILEINFO_MIME_TYPE);
                        if ($finfoLogo) {
                            $logoMime = finfo_file($finfoLogo, $_FILES['logo']['tmp_name']);
                            finfo_close($finfoLogo);
                        }
                    }

                    if ($logoMime === '' && function_exists('mime_content_type')) {
                        $logoMime = mime_content_type($_FILES['logo']['tmp_name']) ?: '';
                    }

                    if ($logoMime !== '' && !in_array($logoMime, $logoAllowedMime, true)) {
                        $mensagem = 'Tipo de arquivo inválido para o logotipo.';
                        $tipo_msg = 'danger';
                    } else {
                        $logoDirAbsolute = __DIR__ . '/uploads/restaurantes/logos';
                        if (!is_dir($logoDirAbsolute) && !mkdir($logoDirAbsolute, 0755, true)) {
                            $mensagem = 'Não foi possível preparar o diretório do logotipo.';
                            $tipo_msg = 'danger';
                        } else {
                            try {
                                $logoFilename = sprintf(
                                    'logo_%s_%s.%s',
                                    date('YmdHis'),
                                    bin2hex(random_bytes(6)),
                                    $logoExt
                                );
                            } catch (Throwable $e) {
                                $logoFilename = '';
                            }

                            if ($logoFilename === '') {
                                $mensagem = 'Não foi possível gerar um nome seguro para o logotipo.';
                                $tipo_msg = 'danger';
                            } else {
                                $logoTmpAbsolute = $logoDirAbsolute . '/' . $logoFilename;
                                $logoNovo = 'uploads/restaurantes/logos/' . $logoFilename;

                                $uploadError = null;
                                $allowedExt = ['png', 'jpg', 'jpeg', 'webp'];
                                $allowedMime = ['image/png', 'image/jpeg', 'image/webp'];
                                if (!security_validate_upload($_FILES['logo'], $allowedExt, $allowedMime, 4 * 1024 * 1024, $uploadError)) {
                                    $mensagem = $uploadError ?: 'Arquivo inválido.';
                                    $tipo_msg = 'danger';
                                    $logoTmpAbsolute = null;
                                    $logoNovo = $logoAtual;
                                } elseif (!move_uploaded_file($_FILES['logo']['tmp_name'], $logoTmpAbsolute)) {
                                    $mensagem = 'Falha ao salvar o logotipo.';
                                    $tipo_msg = 'danger';
                                    $logoTmpAbsolute = null;
                                    $logoNovo = $logoAtual;
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    if ($tipo_msg !== 'danger') {
        $query_upd = "UPDATE restaurantes SET nome = :nome, telefone = :telefone, endereco = :endereco, cidade = :cidade, nuit = :nuit, nuit_lookup = :nuit_lookup, logo = :logo, cor_primaria = :cor_primaria, cor_secundaria = :cor_secundaria WHERE id = :id";
        $stmt_upd  = $db->prepare($query_upd);
        $stmt_upd->bindParam(':nome',     $nome);
        $stmt_upd->bindValue(':telefone', $telefone === '' ? null : personal_data_encrypt($telefone));
        $stmt_upd->bindValue(':endereco', $endereco === '' ? null : personal_data_encrypt($endereco));
        $stmt_upd->bindParam(':cidade',   $cidade);
        $stmt_upd->bindValue(':nuit', $nuit === '' ? null : personal_data_encrypt($nuit));
        $stmt_upd->bindValue(':nuit_lookup', personal_data_nuit_lookup($nuit));
        $stmt_upd->bindParam(':logo',     $logoNovo);
        $stmt_upd->bindValue(':cor_primaria', $corPrimaria !== '' ? $corPrimaria : null, $corPrimaria !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt_upd->bindValue(':cor_secundaria', $corSecundaria !== '' ? $corSecundaria : null, $corSecundaria !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt_upd->bindValue(':id',       $restaurante_id, PDO::PARAM_INT);

        if ($stmt_upd->execute()) {
            if ($logoTmpAbsolute && $logoAtual && $logoAtual !== $logoNovo) {
                $logoAtualAbsolute = __DIR__ . '/' . ltrim(str_replace('\\', '/', $logoAtual), '/');
                if (is_file($logoAtualAbsolute)) {
                    @unlink($logoAtualAbsolute);
                }
            }
            $mensagem = 'Configurações salvas com sucesso!';
            $tipo_msg = 'success';
            $stmt->execute();
            $restaurante = $stmt->fetch(PDO::FETCH_ASSOC);

            // Garantir que $restaurante continua sendo um array válido
            if (!$restaurante || !is_array($restaurante)) {
                $restaurante = [
                    'nome' => $nome,
                    'telefone' => $telefone,
                    'endereco' => $endereco,
                    'cidade' => $cidade,
                    'nuit' => $nuit,
                    'logo' => $logoNovo,
                    'plano' => 'BASICO',
                    'status' => 'INATIVO',
                    'data_fim' => date('Y-m-d', strtotime('+30 days'))
                ];
            }
        } else {
            if ($logoTmpAbsolute && is_file($logoTmpAbsolute)) {
                @unlink($logoTmpAbsolute);
            }
            $mensagem = 'Erro ao salvar configurações.';
            $tipo_msg = 'danger';
        }
    }
}

// Valores padrão seguros - buscar do banco de dados
$nome_restaurante = $restaurante['nome'] ?? '';
$telefone_restaurante = $restaurante['telefone'] ?? '';
$endereco_restaurante = $restaurante['endereco'] ?? '';
$cidade_restaurante = $restaurante['cidade'] ?? '';
$nuit_restaurante = $restaurante['nuit'] ?? '';
$logo_restaurante = trim((string)($restaurante['logo'] ?? ''));
$brandThemeConfig = brand_theme_resolve($restaurante);
$cor_primaria_restaurante = brand_theme_normalize_hex($restaurante['cor_primaria'] ?? '') ?: ($brandThemeConfig['primary'] ?? '#E8893A');
$cor_secundaria_restaurante = brand_theme_normalize_hex($restaurante['cor_secundaria'] ?? '') ?: ($brandThemeConfig['accent'] ?? '#FFC078');

// Usar o plano efetivo do sistema de planos
$dados_plano = plano_get_dados($restauranteCapabilityId);
$plano_atual = plano_normalizar_nome($dados_plano['plano_nome'] ?? ($restaurante['plano'] ?? 'BASICO'));

// Carregar configuração dos planos
$planos_config = require APP_BASE . '/src/config/planos.php';

// Preco de lancamento deste restaurante (o que ficou preso ao aderir ou, se
// ainda nao aderiu, o da campanha a decorrer). O catalogo passa a trazer os
// precos ja com desconto, e guarda a tabela em 'precos_tabela' para a mostrar
// rasurada ao lado. Assim nenhuma parte da pagina mostra um valor diferente
// daquele que a cobranca vai usar.
require_once APP_BASE . '/src/config/plano_lancamento.php';
$lancamento_desconto = lancamento_desconto_restaurante($db, $restaurante_id);
$lancamento_fim_data = lancamento_fim();
$lancamento_a_decorrer = $lancamento_desconto > 0;

if ($lancamento_a_decorrer) {
    foreach (['BASICO', 'PROFISSIONAL', 'EMPRESARIAL'] as $planoCodigo) {
        if (!isset($planos_config[$planoCodigo]['precos'])) {
            continue;
        }
        $calculo = lancamento_precos_plano($planos_config[$planoCodigo]['precos'], $lancamento_desconto);
        $planos_config[$planoCodigo]['precos_tabela'] = $calculo['tabela'];
        $planos_config[$planoCodigo]['precos'] = $calculo['final'];
    }
}

$basico_e_gratis = (bool)($planos_config['BASICO']['e_gratis'] ?? false);
$basico_preco_mensal = (float)($planos_config['BASICO']['precos']['mensal'] ?? 0);
$basico_preco_trimestral = (float)($planos_config['BASICO']['precos']['trimestral'] ?? 0);
$basico_preco_anual = (float)($planos_config['BASICO']['precos']['anual'] ?? 0);
$status_atual = $dados_plano['status'] ?? ($restaurante['status'] ?? 'INATIVO');
$data_fim = $dados_plano['data_fim'] ?? ($restaurante['data_fim'] ?? date('Y-m-d', strtotime('+30 days')));
// Dias de calendário até à validade (igual ao dashboard / plano_lifecycle).
try {
    $hojePlano = new DateTimeImmutable('today');
    $fimPlano = new DateTimeImmutable(date('Y-m-d', strtotime((string)$data_fim)));
    $dias_restantes = (int)$hojePlano->diff($fimPlano)->format('%r%a');
} catch (Throwable $e) {
    $dias_restantes = 0;
}
// CTA de upgrade/gerir plano: só nos últimos 7 dias (ou já expirado)
$mostrarBotaoUpgradePlano = $podeGerirPlanos && (int)$dias_restantes <= 7;

// Atualizar sessão com o plano do banco
$_SESSION['plano'] = $plano_atual;
$plano_nome = $planos_config[$plano_atual]['nome'] ?? $plano_atual;
$recursos_plano_atual = plano_get_resumo_recursos($plano_atual);
$recursos_planos_catalogo = [
    'BASICO' => plano_get_resumo_recursos('BASICO'),
    'PROFISSIONAL' => plano_get_resumo_recursos('PROFISSIONAL'),
    'EMPRESARIAL' => plano_get_resumo_recursos('EMPRESARIAL'),
];

// Verificar se o plano atual permite recursos de backup
$tem_backup = plano_tem_funcionalidade_db($restauranteCapabilityId, 'backup_automatico')
    || plano_tem_funcionalidade_db($restauranteCapabilityId, 'backup_diario')
    || plano_tem_funcionalidade_db($restauranteCapabilityId, 'backup_manual')
    || plano_tem_funcionalidade_db($restauranteCapabilityId, 'download_banco');

// ---- Empresarial: uso face aos limites + configuracoes por filial ----
$cfgEmpresarial = ($plano_atual === 'EMPRESARIAL');
// Profissional tem limites reais: mostra o uso em versao simples (sem barras nem filiais).
$cfgProfissional = ($plano_atual === 'PROFISSIONAL');
$cfgProUso = [];
if ($cfgProfissional) {
    $contagensPro = [
        'usuarios' => ['Utilizadores activos', "SELECT COUNT(*) FROM usuarios WHERE restaurante_id = :rid AND ativo = 1"],
        'produtos' => ['Produtos activos', "SELECT COUNT(*) FROM produtos WHERE restaurante_id = :rid AND ativo = 1"],
        'mesas' => ['Mesas', "SELECT COUNT(*) FROM mesas WHERE restaurante_id = :rid"],
    ];
    foreach ($contagensPro as $chavePro => $parPro) {
        try {
            $stPro = $db->prepare($parPro[1]);
            $stPro->execute([':rid' => (int)$restaurante_id]);
            $qtdPro = (int)$stPro->fetchColumn();
        } catch (Throwable $e) {
            continue;
        }
        $limiteProInfo = plano_verificar_limite_db($restauranteCapabilityId, $chavePro, $qtdPro);
        $limitePro = (int)($limiteProInfo['limite'] ?? -1);
        $pctPro = $limitePro > 0 ? min(100, ($qtdPro / $limitePro) * 100) : 0.0;
        $cfgProUso[] = [
            'rotulo' => $parPro[0],
            'usado' => $qtdPro,
            'limite' => $limitePro,
            'ilimitado' => $limitePro < 0,
            'pct' => $pctPro,
        ];
    }
}
$cfgUso = [];
$cfgFiliaisConfig = [];
$cfgTemMultiFilial = plano_tem_funcionalidade_db($restauranteCapabilityId, 'multi_filial');
if ($cfgEmpresarial) {
    $contagens = [
        'usuarios' => "SELECT COUNT(*) FROM usuarios WHERE restaurante_id = :rid AND ativo = 1",
        'produtos' => "SELECT COUNT(*) FROM produtos WHERE restaurante_id = :rid AND ativo = 1",
        'mesas' => "SELECT COUNT(*) FROM mesas WHERE restaurante_id = :rid",
        'filiais' => "SELECT COUNT(*) FROM restaurantes WHERE filial_id = :rid AND COALESCE(is_matriz, 0) = 0",
    ];
    $rotulosUso = [
        'usuarios' => ['Utilizadores activos', 'fa-users'],
        'produtos' => ['Produtos activos', 'fa-utensils'],
        'mesas' => ['Mesas', 'fa-chair'],
        'filiais' => ['Filiais', 'fa-sitemap'],
    ];
    foreach ($contagens as $chave => $sqlUso) {
        try {
            $stUso = $db->prepare($sqlUso);
            $stUso->execute([':rid' => (int)$restaurante_id]);
            $qtdUso = (int)$stUso->fetchColumn();
        } catch (Throwable $e) {
            continue;
        }
        $limiteInfo = plano_verificar_limite_db($restauranteCapabilityId, $chave, $qtdUso);
        $limiteUso = (int)($limiteInfo['limite'] ?? -1);
        $cfgUso[] = [
            'chave' => $chave,
            'rotulo' => $rotulosUso[$chave][0],
            'icone' => $rotulosUso[$chave][1],
            'usado' => $qtdUso,
            'limite' => $limiteUso,
            'pct' => $limiteUso > 0 ? min(100, ($qtdUso / $limiteUso) * 100) : 0.0,
            'ilimitado' => $limiteUso < 0,
        ];
    }

    if ($cfgTemMultiFilial && !empty($restaurante['is_matriz'])) {
        try {
            $stCfgFil = $db->prepare("
                SELECT id, nome, logo, cor_primaria, cor_secundaria, telefone, endereco, cidade, nuit
                FROM restaurantes
                WHERE filial_id = :rid AND COALESCE(is_matriz, 0) = 0
                ORDER BY nome
            ");
            $stCfgFil->execute([':rid' => (int)$restaurante_id]);
            foreach ($stCfgFil->fetchAll(PDO::FETCH_ASSOC) as $linhaFil) {
                $proprias = [];
                $campos = [
                    'logo' => 'Logótipo',
                    'cor_primaria' => 'Cor principal',
                    'cor_secundaria' => 'Cor secundária',
                    'telefone' => 'Telefone',
                    'endereco' => 'Endereço',
                    'nuit' => 'NUIT',
                ];
                foreach ($campos as $campoFil => $rotuloFil) {
                    $valFil = trim((string)($linhaFil[$campoFil] ?? ''));
                    $valMat = trim((string)($restaurante[$campoFil] ?? ''));
                    if ($valFil !== '' && strcasecmp($valFil, $valMat) !== 0) {
                        $proprias[] = $rotuloFil;
                    }
                }
                $cfgFiliaisConfig[] = [
                    'id' => (int)$linhaFil['id'],
                    'nome' => (string)$linhaFil['nome'],
                    'cidade' => (string)($linhaFil['cidade'] ?? ''),
                    'proprias' => $proprias,
                ];
            }
        } catch (Throwable $e) {
            error_log('[configuracoes] filiais: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt">

<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações - RestauranteSaaS</title>

    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link href="lib/bootstrap-5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="lib/fontawesome-6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/premium.css">
    <style>
        /* Botão de menu mobile */
        .menu-toggle-btn {
            display: none;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 8px 14px;
            font-size: 22px;
            margin-right: 12px;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 2001;
        }

        @media (max-width: 992px) {
            .menu-toggle-btn {
                display: inline-flex;
            }

            .sidebar {
                transition: left 0.3s;
                left: 0;
            }

            .sidebar.hide-mobile {
                left: -320px;
            }

            .main-content.menu-closed {
                margin-left: 0 !important;
            }
        }

        /* Fallback para quando Font Awesome não carregar */
        .fas,
        .far,
        .fal,
        .fab {
            font-family: 'Font Awesome 6 Free', 'Font Awesome 6 Pro', 'FontAwesome' !important;
            font-weight: 900;
            display: inline-block;
            width: 1.25em;
            text-align: center;
        }

        /* Garante que ícones apareçam mesmo com cache antigo */
        .menu-item i::before,
        .sidebar-brand i::before {
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
        }

        /* Corporate consistency without changing palette */
        .card {
            border-radius: 12px;
            border: 1px solid #dbe3ef;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
        }

        .card-header {
            padding: 12px 16px;
        }

        .card-body {
            padding: 14px 16px;
        }

        .table thead th {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .02em;
        }

        .table tbody td {
            font-size: 13px;
        }

        .btn {
            border-radius: 10px;
            font-size: 14px;
            padding: 9px 14px;
        }

        .btn-sm {
            font-size: 12px;
            padding: 7px 10px;
            border-radius: 9px;
        }

        .form-control,
        .form-select {
            min-height: 40px;
            font-size: 14px;
            border-radius: 10px;
        }

        .modal-content {
            border-radius: 14px;
            border: 1px solid #dbe3ef;
            box-shadow: 0 16px 36px rgba(15, 23, 42, 0.18);
        }

        @media (min-width: 1200px) {
            .main-content {
                padding-top: 14px;
            }

            .card {
                margin-bottom: 12px;
            }

            .card-header {
                padding: 10px 14px;
            }

            .card-body {
                padding: 12px 14px;
            }

            .row.g-4 {
                --bs-gutter-y: 1rem;
            }

            .table tbody td {
                padding-top: .55rem;
                padding-bottom: .55rem;
            }

            .plan-option {
                padding: 14px 12px;
            }
        }
        .cfg-emp-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; overflow: hidden; }
        .cfg-emp-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; padding: 14px 18px; border-bottom: 1px solid #f1f5f9; }
        .cfg-emp-head strong { color: #0f172a; }
        .cfg-emp-head span { display: block; font-size: 12px; color: #64748b; margin-top: 2px; }
        .cfg-emp-validade { text-align: right; }
        .cfg-emp-validade span { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #64748b; }
        .cfg-emp-validade strong { display: block; font-size: 16px; color: #0f172a; }
        .cfg-emp-validade small { color: #64748b; }
        .cfg-emp-uso { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; padding: 16px 18px; }
        @media (max-width: 900px) { .cfg-emp-uso { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        .cfg-emp-uso-item { background: #f8fafc; border: 1px solid #eef2f7; border-radius: 12px; padding: 12px 14px; }
        .cfg-emp-uso-rot { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #64748b; margin-bottom: 4px; }
        .cfg-emp-uso-item strong { font-size: 20px; color: #0f172a; }
        .cfg-emp-uso-item strong small { font-size: 12px; font-weight: 500; color: #64748b; }
        .cfg-emp-barra { height: 6px; background: #e2e8f0; border-radius: 999px; overflow: hidden; margin-top: 8px; }
        .cfg-emp-barra span { display: block; height: 100%; background: #10b981; border-radius: 999px; }
        .cfg-emp-uso-item.is-quase .cfg-emp-barra span { background: #f59e0b; }
        .cfg-emp-uso-item.is-cheio .cfg-emp-barra span { background: #ef4444; }
        .cfg-emp-aviso { display: block; margin-top: 6px; font-size: 11px; font-weight: 700; color: #b45309; }
        .cfg-emp-uso-item.is-cheio .cfg-emp-aviso { color: #b91c1c; }
        .cfg-emp-recursos { padding: 0 18px 14px; }
        .cfg-emp-recursos summary { cursor: pointer; font-size: 13px; font-weight: 600; color: #334155; }
        .cfg-emp-recursos ul { list-style: none; padding: 10px 0 0; margin: 0; font-size: 13px; color: #475569; columns: 2; }
        @media (max-width: 760px) { .cfg-emp-recursos ul { columns: 1; } }
        .cfg-emp-tabela th { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #64748b; background: #f8fafc; }
        .cfg-emp-tabela th, .cfg-emp-tabela td { padding: 10px 18px; font-size: 13px; vertical-align: middle; }
        .cfg-emp-nota { display: block; padding: 10px 18px 14px; color: #64748b; font-size: 12px; }
    </style>
    <style>
        :root {
            --primary: #FF6B35;
            --primary-dark: #e55a2b;
            --secondary: #F7931E;
            --dark: #0f0f23;
            --dark-2: #1a1a2e;
            --dark-3: #16213e;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --text: #1e293b;
            --text-light: #64748b;
            --text-muted: #94a3b8;
            --bg: #f8fafc;
            --border: #e2e8f0;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            overflow-x: hidden;
        }

        .sidebar {
            width: 280px;
            min-height: 100vh;
            background: linear-gradient(180deg, var(--dark) 0%, var(--dark-2) 50%, var(--dark-3) 100%);
            position: fixed;
            left: 0;
            top: 0;
            z-index: 1000;
            padding: 0;
            display: flex;
            flex-direction: column;
        }

        .sidebar-brand {
            padding: 28px 24px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            background: linear-gradient(135deg, rgba(255, 107, 53, 0.15), rgba(247, 147, 30, 0.05));
        }

        .sidebar-brand h2 {
            color: white;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 20px;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-brand h2 i {
            color: var(--primary);
            font-size: 24px;
        }

        .sidebar-brand span {
            display: block;
            color: var(--text-muted);
            font-size: 11px;
            margin-top: 4px;
            margin-left: 36px;
            letter-spacing: 0.5px;
        }

        .sidebar-menu {
            flex: 1;
            padding: 20px 12px;
            overflow-y: auto;
        }

        .menu-title {
            color: var(--text-muted);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            padding: 12px 16px 8px;
            font-weight: 600;
        }

        .menu-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 18px;
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            border-radius: 12px;
            margin-bottom: 4px;
            transition: all 0.3s;
            cursor: pointer;
            font-weight: 500;
            font-size: 14px;
        }

        .menu-item:hover {
            background: rgba(255, 255, 255, 0.08);
            color: white;
            transform: translateX(4px);
        }

        .menu-item.active {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            box-shadow: 0 4px 15px rgba(255, 107, 53, 0.4);
        }

        .menu-item i {
            width: 20px;
            text-align: center;
            font-size: 16px;
        }

        .sidebar-footer {
            padding: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
        }

        .user-avatar {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 16px;
        }

        .user-details {
            flex: 1;
        }

        .user-name {
            color: white;
            font-weight: 600;
            font-size: 14px;
        }

        .user-role {
            color: var(--text-muted);
            font-size: 12px;
        }

        .main-content {
            margin-left: 280px;
            padding: 0;
            min-height: 100vh;
            background: var(--bg);
        }

        .top-bar {
            background: white;
            padding: 20px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .page-title {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 24px;
            font-weight: 700;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-title i {
            color: var(--primary);
        }

        .content-area {
            padding: 32px;
        }

        .plan-card {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 20px;
            padding: 24px;
            color: white;
            position: relative;
            overflow: hidden;
            isolation: isolate;
        }

        .plan-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            pointer-events: none;
            z-index: 0;
        }

        .plan-card>* {
            position: relative;
            z-index: 1;
        }

        .plan-icon {
            width: 60px;
            height: 60px;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin-bottom: 16px;
        }

        .plan-name {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .plan-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 12px;
        }

        .plan-details {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
        }

        .plan-detail {
            display: flex;
            flex-direction: column;
        }

        .plan-detail-label {
            font-size: 12px;
            opacity: 0.8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .plan-detail-value {
            font-size: 18px;
            font-weight: 700;
        }

        .plan-days {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 42px;
            font-weight: 700;
        }

        .plan-days-label {
            font-size: 14px;
            opacity: 0.8;
        }

        .card {
            background: white;
            border-radius: 20px;
            box-shadow: var(--shadow);
            border: none;
            overflow: hidden;
            transition: all 0.3s;
        }

        .card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        .card-header {
            background: white;
            border-bottom: 1px solid var(--border);
            padding: 20px 24px;
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 700;
            font-size: 18px;
        }

        .card-body {
            padding: 24px;
        }

        .upgrade-section .card-header {
            background: linear-gradient(180deg, #fff, #fff8f4);
            border-bottom: 1px solid #ffe2d2;
        }

        .plans-grid {
            row-gap: 20px;
            align-items: flex-start !important;
        }

        .plan-col {
            display: block;
        }

        .plan-option {
            border: 2px solid var(--border);
            border-radius: 20px;
            padding: 24px;
            text-align: center;
            transition: all 0.35s;
            width: 100%;
            min-height: 0;
            display: flex;
            flex-direction: column;
            background: #fff;
        }

        .plan-option:hover {
            border-color: var(--primary);
            transform: translateY(-6px);
            box-shadow: 0 16px 30px rgba(15, 23, 42, 0.12);
        }

        .plan-option.selected {
            border-color: var(--primary);
            background: linear-gradient(180deg, #fff, #fff8f3);
            box-shadow: 0 12px 26px rgba(255, 107, 53, 0.18);
        }

        .plan-option.current {
            border-color: var(--success);
            background: linear-gradient(180deg, #ffffff, #f4fffb);
        }

        .plan-option-icon {
            width: 74px;
            height: 74px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            margin: 0 auto 16px;
        }

        .plan-option-name {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 8px;
            line-height: 1;
        }

        .plan-option-price {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 42px;
            font-weight: 700;
            color: var(--primary);
            line-height: 1;
            margin-bottom: 14px;
        }

        .plan-option-price span {
            font-size: 26px;
            color: var(--text-muted);
            font-family: 'Outfit', sans-serif;
            font-weight: 500;
            margin-left: 4px;
        }

        .plan-option-features {
            list-style: none;
            padding: 0;
            margin: 14px 0 18px;
            flex: 0 0 auto;
            display: flex;
            flex-direction: column;
            gap: 8px;
            text-align: left;
        }

        .plan-option-features li {
            padding: 8px 10px;
            font-size: 14px;
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 10px;
            border-radius: 10px;
            border: 1px solid #edf2f7;
            background: #f8fafc;
        }

        .plan-option-features li i {
            color: var(--success);
        }

        .plan-option-features li.is-locked {
            color: #94a3b8;
            border-style: dashed;
            background: #f8fafc;
        }

        .plan-option-features li.is-locked i {
            color: #f59e0b;
        }

        .badge-custom {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .badge-warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .badge-primary {
            background: rgba(255, 107, 53, 0.1);
            color: var(--primary);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(255, 107, 53, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 107, 53, 0.4);
        }

        .btn-outline-primary {
            border: 2px solid var(--primary);
            color: var(--primary);
            background: transparent;
            padding: 10px 20px;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-outline-primary:hover {
            background: var(--primary);
            color: white;
        }

        .btn-outline-secondary {
            border: 2px solid var(--border);
            color: var(--text-light);
            background: transparent;
            padding: 10px 20px;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-outline-secondary:hover {
            border-color: var(--text-light);
            color: var(--text);
            background: var(--bg);
        }

        .form-label {
            font-weight: 600;
            font-size: 13px;
            color: var(--text);
            margin-bottom: 8px;
        }

        .form-control {
            padding: 12px 16px;
            border-radius: 12px;
            border: 2px solid var(--border);
            font-size: 14px;
            transition: all 0.3s;
            font-family: 'Outfit', sans-serif;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(255, 107, 53, 0.1);
        }

        .alert {
            border-radius: 12px;
            padding: 16px 20px;
            border: none;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .alert-danger {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .table {
            margin: 0;
        }

        .table thead th {
            background: var(--bg);
            border: none;
            padding: 14px 16px;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
        }

        .table tbody td {
            padding: 16px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }

        .table tbody tr:hover {
            background: var(--bg);
        }

        .modal-content {
            border: none;
            border-radius: 24px;
            box-shadow: var(--shadow-lg);
        }

        .modal-header {
            padding: 24px 28px;
            border-bottom: 1px solid var(--border);
            border-radius: 24px 24px 0 0;
        }

        .modal-title {
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 700;
            font-size: 20px;
        }

        .modal-body {
            padding: 28px;
        }

        .modal-footer {
            padding: 20px 28px;
            border-top: 1px solid var(--border);
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid var(--border);
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            color: var(--text-light);
            font-size: 14px;
        }

        .info-value {
            font-weight: 600;
            font-size: 14px;
            text-align: right;
            min-width: 0;
            overflow-wrap: anywhere;
        }

        .info-row {
            gap: 12px;
            padding-left: 20px;
            padding-right: 20px;
        }

        .info-label {
            flex: 0 0 auto;
        }

        /* O .row da grelha puxava o conteudo para debaixo da barra lateral. */
        .container-fluid > .row {
            margin-left: 0;
            margin-right: 0;
        }

        /* Sem as colunas do bootstrap, o main tem de caber no que sobra do ecra. */
        .container-fluid > .row > .main-content {
            flex: 1 1 auto;
            width: auto;
            max-width: calc(100% - 280px);
            min-width: 0;
        }

        @media (max-width: 768px) {
            .container-fluid > .row > .main-content {
                max-width: 100%;
            }
        }

        /* item 4: a lista de recursos fica dentro do cartao azul do plano */
        .plan-card .plan-recursos {
            color: rgba(255, 255, 255, 0.92);
        }

        .plan-card .plan-recursos i {
            color: #86efac !important;
        }

        .cfg-plano-lado {
            flex: 1 1 320px;
            min-width: 280px;
        }

        .cfg-pro-uso {
            display: grid;
            gap: 10px;
            margin-top: 18px;
        }

        .cfg-pro-uso-item {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 12px;
            padding: 8px 12px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.12);
            color: #fff;
            font-size: 13.5px;
        }

        .cfg-pro-uso-item strong { font-size: 15px; }
        .cfg-pro-uso-item small { opacity: .85; }
        .cfg-pro-uso-item.is-quase { background: rgba(245, 158, 11, 0.28); }
        .cfg-pro-uso-item.is-cheio { background: rgba(220, 38, 38, 0.32); }
        .cfg-pro-uso-titulo {
            font-size: 11px;
            letter-spacing: .08em;
            text-transform: uppercase;
            opacity: .85;
            margin-bottom: 2px;
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                position: relative;
                min-height: auto;
            }

            .main-content {
                margin-left: 0;
            }

            .content-area {
                padding: 20px;
            }

            .plan-option {
                min-height: auto;
            }

            .plan-option-name {
                font-size: 30px;
            }

            .plan-option-price {
                font-size: 34px;
            }

            .plan-option-price span {
                font-size: 20px;
            }
        }

        @media (max-width: 991px) {
            .top-bar {
                padding: 16px 20px;
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .page-title {
                font-size: 20px;
            }

            .content-area {
                padding: 20px;
            }

            .plan-card {
                padding: 20px;
            }

            .plan-details {
                gap: 16px;
            }

            .plan-col {
                flex: 0 0 100%;
                max-width: 100%;
            }

            .plan-option {
                min-height: auto;
                padding: 20px;
            }

            .plan-option-name {
                font-size: 30px;
            }

            .plan-option-price {
                font-size: 34px;
            }

            .plan-option-price span {
                font-size: 20px;
            }

            .row.g-4.mt-2>.col-md-6 {
                flex: 0 0 100%;
                max-width: 100%;
            }

            .card-header {
                padding: 18px 20px;
            }

            .card-body {
                padding: 20px;
            }

            .modal-dialog.modal-lg {
                max-width: calc(100% - 20px);
                margin: 10px;
            }
        }

        @media (max-width: 576px) {
            .top-bar {
                padding: 12px 16px;
                gap: 8px;
            }

            .page-title {
                font-size: 18px;
                gap: 8px;
            }

            .content-area {
                padding: 12px;
            }

            .plan-card,
            .card,
            .plan-option {
                border-radius: 18px;
            }

            .plan-card {
                padding: 16px;
            }

            .plan-icon {
                width: 52px;
                height: 52px;
                font-size: 24px;
            }

            .plan-name {
                font-size: 20px;
            }

            .plan-details {
                gap: 12px;
            }

            .plan-detail-value {
                font-size: 16px;
            }

            .plan-days {
                font-size: 32px;
            }

            .card-header {
                padding: 16px;
            }

            .card-body {
                padding: 16px;
            }

            .plan-option {
                padding: 16px;
            }

            .plan-option-icon {
                width: 60px;
                height: 60px;
                font-size: 26px;
            }

            .plan-option-name {
                font-size: 24px;
            }

            .plan-option-price {
                font-size: 30px;
            }

            .plan-option-price span {
                font-size: 16px;
            }

            .plan-option-features li {
                font-size: 13px;
                padding: 7px 9px;
            }

            .btn-primary,
            .btn-outline-primary,
            .btn-outline-secondary {
                width: 100%;
            }

            .modal-header,
            .modal-body,
            .modal-footer {
                padding-left: 16px;
                padding-right: 16px;
            }

            .modal-footer {
                flex-direction: column;
                align-items: stretch;
            }

            .modal-footer .btn {
                width: 100%;
            }

            .table thead th,
            .table tbody td {
                padding: 12px 14px;
                font-size: 12px;
                white-space: normal;
            }
        }
    </style>
    <link rel="stylesheet" href="css/bonus_faixa.css?v=<?php echo (int)@filemtime(__DIR__ . '/css/bonus_faixa.css'); ?>">
</head>

<body class="premium-ui">
    <div class="container-fluid">
        <div class="row">
            <!-- SIDEBAR -->
            <?php include_once __DIR__ . '/includes/sidebar.php'; ?>

            <!-- MAIN CONTENT -->
            <main class="main-content">
                <div class="top-bar">
                    <h1 class="page-title"><i class="fas fa-cog"></i> Configurações <?php include __DIR__ . '/includes/plan_page_badge.php'; ?></h1>
                </div>

                <div class="content-area">
<?php
// Oferta de boas-vindas: dias que faltam, resumo final e aviso de limite.
$bonusFaixaRid = (int)$restaurante_id;
include __DIR__ . '/includes/bonus_faixa.php';

if (!empty($planoSemMultiFilial)):
    $pmfFiliais = 0;
    try {
        $stmtPmf = $db->prepare('SELECT COUNT(*) FROM restaurantes WHERE filial_id = :rid');
        $stmtPmf->execute([':rid' => (int)($restauranteId ?? $_SESSION['restaurante_id'] ?? 0)]);
        $pmfFiliais = (int)$stmtPmf->fetchColumn();
    } catch (Throwable $ePmf) {
        $pmfFiliais = 0;
    }
?>
    <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2" role="alert" style="margin:16px;">
        <i class="fas fa-circle-info"></i>
        <div class="flex-grow-1">
            <strong>A gestao de filiais faz parte do plano Empresarial.</strong>
            <div class="small mt-1">
                <?php if ($pmfFiliais > 0): ?>
                    <?php echo $pmfFiliais === 1 ? 'A filial que criou continua guardada' : 'As ' . $pmfFiliais . ' filiais que criou continuam guardadas'; ?>,
                    com todos os dados. Nada foi apagado &mdash; volta a ter acesso assim que subir de plano.
                <?php else: ?>
                    Suba para Empresarial para criar e gerir filiais.
                <?php endif; ?>
            </div>
        </div>
        <a class="btn btn-sm btn-warning" href="solicitar_plano.php?plano=EMPRESARIAL">Ver o plano Empresarial</a>
    </div>
<?php
endif;
?>
                    <?php if ($mensagem): ?>
                        <div class="alert alert-<?php echo $tipo_msg; ?> alert-dismissible fade show" role="alert">
                            <i class="fas fa-<?php echo $tipo_msg == 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
                            <?php echo htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8'); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- PLANO ATUAL -->
                    <?php if ($secao !== 'plano'): ?>
                        <div class="plan-card mb-4">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                                <div>
                                    <div class="plan-icon">👑</div>
                                    <div class="plan-name">Plano <?php echo htmlspecialchars($plano_nome); ?></div>
                                    <div class="plan-status">
                                        <i class="fas fa-<?php echo $status_atual == 'ATIVO' ? 'check-circle' : 'clock'; ?>"></i>
                                        <?php echo htmlspecialchars($status_atual); ?>
                                    </div>
                                    <div class="plan-details mt-3">
                                        <div class="plan-detail">
                                            <span class="plan-detail-label">Validade</span>
                                            <span class="plan-detail-value"><?php echo date('d/m/Y', strtotime($data_fim)); ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-md-end cfg-plano-lado">
                                    <div class="plan-days"><?php echo max(0, $dias_restantes); ?></div>
                                    <div class="plan-days-label">dias restantes</div>
                                    <?php if (!empty($cfgProUso)): ?>
                                        <div class="cfg-pro-uso">
                                            <div class="cfg-pro-uso-titulo">Uso do plano</div>
                                            <?php foreach ($cfgProUso as $usoPro):
                                                $classePro = '';
                                                if (!$usoPro['ilimitado'] && $usoPro['limite'] > 0) {
                                                    if ($usoPro['pct'] >= 100) { $classePro = 'is-cheio'; }
                                                    elseif ($usoPro['pct'] >= 80) { $classePro = 'is-quase'; }
                                                }
                                            ?>
                                                <div class="cfg-pro-uso-item <?php echo $classePro; ?>">
                                                    <span><?php echo htmlspecialchars($usoPro['rotulo']); ?></span>
                                                    <strong><?php echo (int)$usoPro['usado']; ?><small><?php echo $usoPro['ilimitado'] ? ' de ilimitado' : ' de ' . (int)$usoPro['limite']; ?></small><?php
                                                        if (!$usoPro['ilimitado'] && $usoPro['pct'] >= 80) {
                                                            echo '<small> · ' . ($usoPro['pct'] >= 100 ? 'limite atingido' : 'perto do limite') . '</small>';
                                                        }
                                                    ?></strong>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($mostrarBotaoUpgradePlano && $plano_atual !== 'EMPRESARIAL'): ?>
                                        <a href="configuracoes.php?secao=plano" class="btn btn-light mt-3">
                                            <i class="fas fa-arrow-up me-2"></i><?php echo ((int)$dias_restantes <= 0) ? 'Renovar / Upgrade' : 'Upgrade'; ?>
                                        </a>
                                    <?php elseif ($mostrarBotaoUpgradePlano && $plano_atual === 'EMPRESARIAL'): ?>
                                        <a href="configuracoes.php?secao=plano" class="btn btn-light mt-3">
                                            <i class="fas fa-crown me-2"></i><?php echo ((int)$dias_restantes <= 0) ? 'Renovar plano' : 'Gerir plano'; ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="mt-4">
                                <div class="plan-detail-label mb-2">Recursos do Plano</div>
                                <ul class="list-unstyled mb-0 plan-recursos" style="font-size: 14px;">
                                    <?php foreach ($recursos_plano_atual as $recurso): ?>
                                        <li class="mb-1"><i class="fas fa-check text-success me-2"></i><?php echo htmlspecialchars($recurso); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($cfgEmpresarial && $secao !== 'plano' && !empty($cfgUso)): ?>
                        <div class="cfg-emp-card mb-4">
                            <div class="cfg-emp-head">
                                <div>
                                    <strong><i class="fas fa-gauge-high me-2"></i>Uso do plano <?php echo htmlspecialchars($plano_nome); ?></strong>
                                    <span>Quanto já está a usar face aos limites do plano.</span>
                                </div>
                                <div class="cfg-emp-validade">
                                    <span>Renova em</span>
                                    <strong><?php echo date('d/m/Y', strtotime((string)$data_fim)); ?></strong>
                                    <small><?php echo max(0, (int)$dias_restantes); ?> dia(s)</small>
                                </div>
                            </div>
                            <div class="cfg-emp-uso">
                                <?php foreach ($cfgUso as $uso):
                                    $classeUso = '';
                                    if (!$uso['ilimitado'] && $uso['limite'] > 0) {
                                        if ($uso['pct'] >= 100) { $classeUso = 'is-cheio'; }
                                        elseif ($uso['pct'] >= 80) { $classeUso = 'is-quase'; }
                                    }
                                ?>
                                    <div class="cfg-emp-uso-item <?php echo $classeUso; ?>">
                                        <span class="cfg-emp-uso-rot"><i class="fas <?php echo htmlspecialchars($uso['icone']); ?> me-1"></i><?php echo htmlspecialchars($uso['rotulo']); ?></span>
                                        <strong><?php echo (int)$uso['usado']; ?><?php echo $uso['ilimitado'] ? ' <small>de ilimitado</small>' : ' <small>de ' . (int)$uso['limite'] . '</small>'; ?></strong>
                                        <div class="cfg-emp-barra"><span style="width: <?php echo $uso['ilimitado'] ? 8 : number_format((float)$uso['pct'], 1, '.', ''); ?>%"></span></div>
                                        <?php if (!$uso['ilimitado'] && $uso['pct'] >= 80): ?>
                                            <small class="cfg-emp-aviso"><?php echo $uso['pct'] >= 100 ? 'Limite atingido' : 'Perto do limite'; ?></small>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if (!empty($recursos_plano_atual)): ?>
                                <details class="cfg-emp-recursos">
                                    <summary>O que o plano inclui (<?php echo count($recursos_plano_atual); ?>)</summary>
                                    <ul>
                                        <?php foreach ($recursos_plano_atual as $recursoEmp): ?>
                                            <li><i class="fas fa-check text-success me-2"></i><?php echo htmlspecialchars($recursoEmp); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($cfgFiliaisConfig)): ?>
                            <div class="cfg-emp-card mb-4">
                                <div class="cfg-emp-head">
                                    <div>
                                        <strong><i class="fas fa-sitemap me-2"></i>Configurações por filial</strong>
                                        <span>Unidades que seguem a matriz e as que têm definições próprias.</span>
                                    </div>
                                    <a href="filiais.php" class="btn btn-sm btn-outline-secondary">Abrir filiais</a>
                                </div>
                                <div class="table-responsive">
                                    <table class="table mb-0 cfg-emp-tabela">
                                        <thead><tr><th>Unidade</th><th>Segue a matriz?</th><th>Definições próprias</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($cfgFiliaisConfig as $filCfg): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($filCfg['nome']); ?></strong>
                                                        <?php if ($filCfg['cidade'] !== ''): ?><small class="d-block text-muted"><?php echo htmlspecialchars($filCfg['cidade']); ?></small><?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (empty($filCfg['proprias'])): ?>
                                                            <span class="badge bg-success">Segue a matriz</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-warning text-dark">Definições próprias</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-muted">
                                                        <?php echo empty($filCfg['proprias']) ? 'Usa tudo da matriz' : htmlspecialchars(implode(', ', $filCfg['proprias'])); ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <small class="cfg-emp-nota">Compara logótipo, cores, telefone, endereço e NUIT de cada filial com os da matriz.</small>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- SEÇÃO PLANO (quando acessar via upgrade) -->
                    <?php if ($secao === 'plano' && $planoRestrito): ?>
                        <div class="alert alert-danger d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                            <div>
                                <div class="fw-bold"><i class="fas fa-lock me-2"></i><?php echo $planoModoLimitado ? 'Plano vencido: modo limitado (sem vendas)' : 'Plano expirado ou restrito'; ?></div>
                                <div class="small">
                                    <?php echo $planoModoLimitado ? 'Durante 7 dias pode consultar e exportar os dados, mas nao registar vendas.' : 'Regularize o plano para liberar todas as funcionalidades.'; ?> Envie agora a renovacao com comprovativo.
                                    <a href="meus_dados.php" class="alert-link">Exportar os meus dados</a>.
                                    <?php if (array_key_exists('dias_restantes', $planoAlertaSessao)): ?>
                                        <span class="ms-1">(Dias restantes: <?php echo (int)$planoAlertaSessao['dias_restantes']; ?>)</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <a href="configuracoes.php?secao=plano" class="btn btn-light">
                                <i class="fas fa-sync-alt me-2"></i>Renovar Agora
                            </a>
                        </div>
                    <?php endif; ?>

                    <?php if ($secao === 'plano' && !$podeGerirPlanos): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-lock me-2"></i>
                            Apenas administradores do restaurante (matriz) podem gerir os planos.
                        </div>
                    <?php endif; ?>

                    <?php if ($secao === 'plano' && $podeGerirPlanos): ?>
                        <div class="card mb-4 upgrade-section" id="plano">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <div><i class="fas fa-crown me-2 text-warning"></i>Planos de Assinatura <a href="meus_dados.php" class="small ms-2">Exportar dados ou cancelar o plano</a></div>
                                <a href="configuracoes.php" class="btn btn-outline-secondary btn-sm">
                                    <i class="fas fa-times me-1"></i> Fechar
                                </a>
                            </div>
                            <div class="card-body">
                                <div class="row g-4 plans-grid align-items-stretch">
                                    <!-- Plano Básico -->
                                    <div class="col-lg-4 col-md-4 plan-col">
                                        <div class="plan-option <?php echo $plano_atual === 'BASICO' ? 'current selected' : ''; ?>">
                                            <div class="plan-option-icon" style="background: linear-gradient(135deg, #6c757d, #343a40); color: white;">
                                                <i class="fas fa-user"></i>
                                            </div>
                                            <div class="plan-option-name">Básico</div>
                                            <div class="plan-option-price">
                                                <?php if ($basico_e_gratis || $basico_preco_mensal <= 0): ?>
                                                    Grátis<span>/mês</span>
                                                <?php else: ?>
                                                    <?php echo number_format($basico_preco_mensal, 0, ',', '.'); ?><span> MZN/mês</span>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-muted d-block mt-1">
                                                Tri: <?php echo number_format($basico_preco_trimestral, 0, ',', '.'); ?> MZN | Anual: <?php echo number_format($basico_preco_anual, 0, ',', '.'); ?> MZN
                                            </small>
                                            <div class="text-success small mt-2">Inclui 30 dias de teste grátis para o plano Básico.</div>

                                            <ul class="plan-option-features">
                                                <?php foreach (($recursos_planos_catalogo['BASICO'] ?? []) as $recurso): ?>
                                                    <li class="text-success"><i class="fas fa-check"></i> <?php echo htmlspecialchars($recurso); ?></li>
                                                <?php endforeach; ?>
                                                <?php
                                                $recursosBloqueadosBasico = array_values(array_diff(
                                                    $recursos_planos_catalogo['EMPRESARIAL'] ?? [],
                                                    $recursos_planos_catalogo['BASICO'] ?? []
                                                ));
                                                foreach ($recursosBloqueadosBasico as $recursoBloqueado):
                                                ?>
                                                    <li class="is-locked"><i class="fas fa-lock"></i> <?php echo htmlspecialchars($recursoBloqueado); ?></li>
                                                <?php endforeach; ?>
                                            </ul>

                                            <?php if ($plano_atual === 'BASICO'): ?>
                                                <span class="badge-custom badge-success">Plano Atual</span>
                                                <button type="button" class="btn btn-primary btn-sm mt-2" data-bs-toggle="modal" data-bs-target="#modalCompraPlano" onclick="selecionarPlano('BASICO', true)">
                                                    <i class="fas fa-rotate-right me-1"></i> Renovar Agora
                                                </button>
                                            <?php elseif ($plano_atual === 'PROFISSIONAL' || $plano_atual === 'EMPRESARIAL'): ?>
                                                <button type="button" class="btn btn-outline-secondary btn-sm mt-2" disabled>
                                                    <i class="fas fa-arrow-down me-1"></i> Plano Inferior
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-primary btn-sm mt-2" data-bs-toggle="modal" data-bs-target="#modalCompraPlano" onclick="selecionarPlano('BASICO')">
                                                    <i class="fas fa-check me-1"></i> Ativar
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Plano Profissional -->
                                    <div class="col-lg-4 col-md-4 plan-col">
                                        <div class="plan-option <?php echo $plano_atual === 'PROFISSIONAL' ? 'current selected' : ''; ?>" style="border-color: #17a2b8;">
                                            <div class="plan-option-icon" style="background: linear-gradient(135deg, #17a2b8, #0dcaf0); color: white;">
                                                <i class="fas fa-star"></i>
                                            </div>
                                            <div class="plan-option-name">Profissional</div>
                                            <div class="plan-option-price"><?php echo number_format((float)($planos_config['PROFISSIONAL']['precos']['mensal'] ?? 0), 0, ',', '.'); ?><span> MZN/mês</span></div>
                                            <small class="text-muted d-block mt-1">
                                                Tri: <?php echo number_format((float)($planos_config['PROFISSIONAL']['precos']['trimestral'] ?? 0), 0, ',', '.'); ?> MZN | Anual: <?php echo number_format((float)($planos_config['PROFISSIONAL']['precos']['anual'] ?? 0), 0, ',', '.'); ?> MZN
                                            </small>

                                            <ul class="plan-option-features">
                                                <?php foreach (($recursos_planos_catalogo['PROFISSIONAL'] ?? []) as $recurso): ?>
                                                    <li class="text-success"><i class="fas fa-check"></i> <?php echo htmlspecialchars($recurso); ?></li>
                                                <?php endforeach; ?>
                                                <?php
                                                $recursosBloqueadosProfissional = array_values(array_diff(
                                                    $recursos_planos_catalogo['EMPRESARIAL'] ?? [],
                                                    $recursos_planos_catalogo['PROFISSIONAL'] ?? []
                                                ));
                                                foreach ($recursosBloqueadosProfissional as $recursoBloqueado):
                                                ?>
                                                    <li class="is-locked"><i class="fas fa-lock"></i> <?php echo htmlspecialchars($recursoBloqueado); ?></li>
                                                <?php endforeach; ?>
                                            </ul>

                                            <?php if ($plano_atual === 'PROFISSIONAL'): ?>
                                                <span class="badge-custom badge-success">Plano Atual</span>
                                                <button type="button" class="btn btn-primary btn-sm mt-2" data-bs-toggle="modal" data-bs-target="#modalCompraPlano" onclick="selecionarPlano('PROFISSIONAL', true)">
                                                    <i class="fas fa-rotate-right me-1"></i> Renovar Agora
                                                </button>
                                            <?php elseif ($plano_atual === 'EMPRESARIAL'): ?>
                                                <button type="button" class="btn btn-outline-secondary btn-sm mt-2" disabled>
                                                    <i class="fas fa-arrow-down me-1"></i> Plano Inferior
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-primary btn-sm mt-2" data-bs-toggle="modal" data-bs-target="#modalCompraPlano" onclick="selecionarPlano('PROFISSIONAL')">
                                                    <i class="fas fa-shopping-cart me-1"></i> Comprar
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Plano Empresarial -->
                                    <div class="col-lg-4 col-md-4 plan-col">
                                        <div class="plan-option <?php echo $plano_atual === 'EMPRESARIAL' ? 'current selected' : ''; ?>" style="border-color: #FF6B35;">
                                            <div class="plan-option-icon" style="background: linear-gradient(135deg, #FF6B35, #F7931E); color: white;">
                                                <i class="fas fa-building"></i>
                                            </div>
                                            <div class="plan-option-name">Empresarial</div>
                                            <div class="plan-option-price"><?php echo number_format((float)($planos_config['EMPRESARIAL']['precos']['mensal'] ?? 0), 0, ',', '.'); ?><span> MZN/mês</span></div>
                                            <small class="text-muted d-block mt-1">
                                                Tri: <?php echo number_format((float)($planos_config['EMPRESARIAL']['precos']['trimestral'] ?? 0), 0, ',', '.'); ?> MZN | Anual: <?php echo number_format((float)($planos_config['EMPRESARIAL']['precos']['anual'] ?? 0), 0, ',', '.'); ?> MZN
                                            </small>

                                            <ul class="plan-option-features">
                                                <?php foreach (($recursos_planos_catalogo['EMPRESARIAL'] ?? []) as $recurso): ?>
                                                    <li class="text-success"><i class="fas fa-check"></i> <?php echo htmlspecialchars($recurso); ?></li>
                                                <?php endforeach; ?>
                                            </ul>

                                            <?php if ($plano_atual === 'EMPRESARIAL'): ?>
                                                <span class="badge-custom badge-success">Plano Atual</span>
                                                <button type="button" class="btn btn-primary btn-sm mt-2" data-bs-toggle="modal" data-bs-target="#modalCompraPlano" onclick="selecionarPlano('EMPRESARIAL', true)">
                                                    <i class="fas fa-rotate-right me-1"></i> Renovar Agora
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-primary btn-sm mt-2" data-bs-toggle="modal" data-bs-target="#modalCompraPlano" onclick="selecionarPlano('EMPRESARIAL')">
                                                    <i class="fas fa-rocket me-1"></i> Comprar
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                </div>

                                <div class="card mt-4 border-0 shadow-sm">
                                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                                        <div><i class="fas fa-clock-rotate-left me-2 text-primary"></i>Histórico de Compras</div>
                                        <button type="button" class="btn btn-outline-primary btn-sm" id="btnExportarHistoricoCsv">
                                            <i class="fas fa-file-csv me-1"></i> Exportar CSV
                                        </button>
                                    </div>
                                    <div class="card-body">
                                        <div class="row g-3 mb-3">
                                            <div class="col-lg-5">
                                                <input type="text" class="form-control" id="filtroHistoricoTexto" placeholder="Pesquisar plano, método ou status…">
                                            </div>
                                            <div class="col-lg-3 col-md-6">
                                                <select class="form-select" id="filtroHistoricoCiclo">
                                                    <option value="">Todos os ciclos</option>
                                                    <option value="MENSAL">Mensal</option>
                                                    <option value="TRIMESTRAL">Trimestral</option>
                                                    <option value="ANUAL">Anual</option>
                                                </select>
                                            </div>
                                            <div class="col-lg-4 col-md-6">
                                                <select class="form-select" id="filtroHistoricoStatus">
                                                    <option value="">Todos os status</option>
                                                    <option value="PENDENTE">Pendente</option>
                                                    <option value="APROVADO">Aprovado</option>
                                                    <option value="REJEITADO">Rejeitado</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="table-responsive" id="historicoCompras">
                                            <p class="text-center text-muted py-4 mb-0">Carregando...</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="row g-4 mt-2">
                        <!-- DADOS DO RESTAURANTE -->
                        <div class="col-md-6">
                            <div class="card h-100">
                                <div class="card-header">
                                    <i class="fas fa-store me-2"></i>Dados do Restaurante
                                </div>
                                <div class="card-body">
                                    <form method="POST" action="configuracoes.php" enctype="multipart/form-data">
                                        <div class="mb-3">
                                            <label class="form-label">Nome do Restaurante *</label>
                                            <input type="text" name="nome" class="form-control" value="<?php echo htmlspecialchars($nome_restaurante); ?>" required placeholder="Nome do restaurante">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Telefone</label>
                                            <input type="text" name="telefone" class="form-control" value="<?php echo htmlspecialchars($telefone_restaurante); ?>" placeholder="Telefone">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Endereço</label>
                                            <input type="text" name="endereco" class="form-control" value="<?php echo htmlspecialchars($endereco_restaurante); ?>" placeholder="Endereço">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Cidade</label>
                                            <input type="text" name="cidade" class="form-control" value="<?php echo htmlspecialchars($cidade_restaurante); ?>" placeholder="Cidade">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">NUIT</label>
                                            <input type="text" name="nuit" class="form-control" value="<?php echo htmlspecialchars($nuit_restaurante); ?>" placeholder="NUIT">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Logotipo do Restaurante</label>
                                            <input
                                                type="file"
                                                name="logo"
                                                id="logoRestauranteInput"
                                                class="form-control"
                                                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                                onchange="previewLogoRestauranteConfig(this)">
                                            <small class="text-muted d-block mt-2">Formatos aceites: JPG, PNG ou WEBP. Tamanho máximo: 4MB.</small>
                                            <div class="mt-3 d-flex flex-column gap-2">
                                                <img
                                                    id="logoPreviewImage"
                                                    src="<?php echo htmlspecialchars($logo_restaurante !== '' ? $logo_restaurante : '', ENT_QUOTES, 'UTF-8'); ?>"
                                                    alt="Logotipo do restaurante"
                                                    style="<?php echo $logo_restaurante !== '' ? 'display:block;' : 'display:none;'; ?>max-width: 180px; max-height: 180px; border-radius: 18px; border: 1px solid #e2e8f0; background: #fff; object-fit: contain; padding: 10px; box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);">
                                                <div class="small text-muted" id="logoPreviewInfo">
                                                    <?php echo $logo_restaurante !== '' ? htmlspecialchars(basename($logo_restaurante), ENT_QUOTES, 'UTF-8') : 'Nenhum logotipo enviado para este restaurante.'; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Identidade visual</label>
                                            <div class="row g-3">
                                                <div class="col-6">
                                                    <label class="form-label small text-muted mb-1">Cor principal</label>
                                                    <input type="color" name="cor_primaria" class="form-control form-control-color w-100" value="<?php echo htmlspecialchars($cor_primaria_restaurante, ENT_QUOTES, 'UTF-8'); ?>" title="Cor principal do restaurante">
                                                </div>
                                                <div class="col-6">
                                                    <label class="form-label small text-muted mb-1">Cor de destaque</label>
                                                    <input type="color" name="cor_secundaria" class="form-control form-control-color w-100" value="<?php echo htmlspecialchars($cor_secundaria_restaurante, ENT_QUOTES, 'UTF-8'); ?>" title="Cor de destaque">
                                                </div>
                                            </div>
                                            <small class="text-muted d-block mt-2">Estas cores personalizam o dashboard, o menu e o cardápio do seu restaurante.</small>
                                        </div>
                                        <button type="submit" class="btn btn-primary w-100">
                                            <i class="fas fa-save me-2"></i>Salvar Alterações
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- MINHA CONTA -->
                        <div class="col-md-6">
                            <div class="card mb-4">
                                <div class="card-header">
                                    <i class="fas fa-user me-2"></i>Minha Conta
                                </div>
                                <div class="card-body">
                                    <div class="d-flex align-items-center mb-4">
                                        <div class="user-avatar me-3" style="width: 60px; height: 60px; font-size: 22px;">
                                            <?php echo htmlspecialchars(mb_strtoupper(mb_substr(trim((string)($_SESSION['nome'] ?? '')), 0, 2, 'UTF-8'), 'UTF-8')); ?>
                                        </div>
                                        <div>
                                            <div class="fw-semibold" style="font-size: 18px;"><?php echo htmlspecialchars($_SESSION['nome']); ?></div>
                                            <span class="badge-custom <?php echo $_SESSION['perfil'] == 'ADMIN' ? 'badge-danger' : 'badge-primary'; ?>">
                                                <?php echo htmlspecialchars($_SESSION['perfil'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Restaurante ID</span>
                                        <span class="info-value">#<?php echo $_SESSION['restaurante_id']; ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Plano Atual</span>
                                        <span class="info-value"><?php echo htmlspecialchars($plano_nome); ?></span>
                                    </div>
                                    <a href="usuarios.php" class="btn btn-outline-primary w-100 mt-4">
                                        <i class="fas fa-users me-2"></i>Gerenciar Usuários
                                    </a>
                                </div>
                            </div>

                            <div class="card">
                                <div class="card-header">
                                    <i class="fas fa-info-circle me-2"></i>Informações do Sistema
                                </div>
                                <div class="card-body p-0">
                                    <div class="info-row">
                                        <span class="info-label">Versão</span>
                                        <span class="info-value">1.0.0</span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">PHP</span>
                                        <span class="info-value"><?php echo phpversion(); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Suporte</span>
                                        <span class="info-value">edvisjoaochibante2002@gmail.com</span>

                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal de Compra de Plano -->
    <div class="modal fade" id="modalCompraPlano" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalPlanoTitulo"><i class="fas fa-shopping-cart me-2"></i>Confirmar Compra de Plano</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="alertModalPlano" class="alert" style="display: none;"></div>

                    <div class="text-center mb-4">
                        <div class="plan-option-icon d-inline-flex" style="background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; width: 80px; height: 80px; font-size: 36px;">
                            <i class="fas fa-crown"></i>
                        </div>
                        <h5 class="mt-3" id="planoSelecionadoNome"></h5>
                        <div class="plan-option-price" id="planoSelecionadoValor"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Periodicidade *</label>
                        <select class="form-select" id="periodicidadePlano" onchange="atualizarValorPlanoSelecionado()">
                            <option value="MENSAL">Mensal</option>
                            <option value="TRIMESTRAL">Trimestral</option>
                            <option value="ANUAL">Anual</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Método de Pagamento *</label>
                        <select class="form-select" id="metodoPagamento">
                            <option value="">Selecione...</option>
                            <option value="TRIAL">🎁 Trial gratuito de 30 dias</option>
                            <option value="DINHEIRO">💵 Dinheiro</option>
                            <option value="MPESA">📱 M-Pesa</option>
                            <option value="EMOLA">📱 e-Mola</option>
                            <option value="TRANSFERENCIA">🏦 Transferência</option>
                        </select>
                    </div>

                    <div class="alert alert-info py-2 mb-3" id="instrucoesPagamentoPlano" role="status" hidden>
                        <i class="fas fa-circle-info me-2"></i><strong id="instrucoesPagamentoPlanoTitulo"></strong><br>
                        <span id="instrucoesPagamentoPlanoTexto"></span>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Comprovativo de Pagamento *</label>
                        <input type="file" class="form-control" id="comprovativoPagamento" accept=".jpg,.jpeg,.png,.pdf">
                        <small class="text-muted">Formatos: JPG, PNG ou PDF (máx. 5MB)</small>
                    </div>

                    <div class="alert alert-secondary py-2 mb-3" id="infoValorPeriodicidade"></div>

                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <span id="modalPlanoMensagemInfo">Após confirmar, aguarde a verificação do pagamento. O plano será ativado após aprovação do administrador.</span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="btnConfirmarCompraPlano" onclick="confirmarCompraPlano()">
                        <i class="fas fa-check me-1"></i> Confirmar Upgrade
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalDowngradePlano" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-arrow-down me-2 text-warning"></i>Agendar Downgrade</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <div id="downgradePlanoAlert" class="alert" hidden></div>
                    <p class="mb-2">Plano de destino: <strong id="downgradePlanoDestino"></strong></p>
                    <p class="mb-0 text-muted">O plano atual continua ativo ate ao fim do ciclo pago. Na data de aplicacao, o sistema verifica os limites antes de reduzir os recursos. Nenhum dado sera apagado.</p>
                    <input type="hidden" id="downgradePlanoCodigo" value="">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-warning" id="btnConfirmarDowngrade" onclick="confirmarDowngradePlano()"><i class="fas fa-calendar-check me-1"></i> Agendar downgrade</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Preview Comprovativo -->
    <div class="modal fade" id="modalComprovativo" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-file-invoice me-2"></i>Comprovativo de Pagamento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center" id="comprovativoModalBody" style="min-height:300px;">
                    <div class="d-flex justify-content-center align-items-center" style="height:300px;">
                        <div class="spinner-border text-primary" role="status"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <a id="comprovativoDownloadLink" href="#" target="_blank" rel="noopener" class="btn btn-outline-primary"><i class="fas fa-external-link-alt me-1"></i>Abrir em nova aba</a>
                </div>
            </div>
        </div>
    </div>

    <script src="lib/bootstrap-5.3.2/js/bootstrap.bundle.min.js"></script>
    <script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
        var planoAtual = '';
        var planoModoRenovacao = false;
        var planoBasicoTrial = false;
        var valorAtual = 0;
        var planoPaymentInstructions = <?php echo json_encode($planoPaymentInstructions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        var precosPlanos = <?php
                            echo json_encode([
                                'BASICO' => [
                                    'MENSAL' => (float)($planos_config['BASICO']['precos']['mensal'] ?? 0),
                                    'TRIMESTRAL' => (float)($planos_config['BASICO']['precos']['trimestral'] ?? 0),
                                    'ANUAL' => (float)($planos_config['BASICO']['precos']['anual'] ?? 0),
                                ],
                                'PROFISSIONAL' => [
                                    'MENSAL' => (float)($planos_config['PROFISSIONAL']['precos']['mensal'] ?? 0),
                                    'TRIMESTRAL' => (float)($planos_config['PROFISSIONAL']['precos']['trimestral'] ?? 0),
                                    'ANUAL' => (float)($planos_config['PROFISSIONAL']['precos']['anual'] ?? 0),
                                ],
                                'EMPRESARIAL' => [
                                    'MENSAL' => (float)($planos_config['EMPRESARIAL']['precos']['mensal'] ?? 0),
                                    'TRIMESTRAL' => (float)($planos_config['EMPRESARIAL']['precos']['trimestral'] ?? 0),
                                    'ANUAL' => (float)($planos_config['EMPRESARIAL']['precos']['anual'] ?? 0),
                                ]
                            ], JSON_UNESCAPED_UNICODE);
                            ?>;

        function formatarValorMzn(valor) {
            return Number(valor || 0).toLocaleString('pt-BR') + ' MZN';
        }

        function rotuloConfirmacaoPlano() {
            return planoModoRenovacao ?
                '<i class="fas fa-check me-1"></i> Confirmar Renovacao' :
                '<i class="fas fa-check me-1"></i> Confirmar Upgrade';
        }

        function rotuloProcessandoPlano() {
            return planoModoRenovacao ?
                '<i class="fas fa-spinner fa-spin me-1"></i> A processar renovacao...' :
                '<i class="fas fa-spinner fa-spin me-1"></i> A processar upgrade...';
        }

        function mensagemProcessamentoPlano() {
            return planoModoRenovacao ?
                'A enviar pedido de renovacao. Aguarde e nao clique novamente.' :
                'A enviar pedido de upgrade. Aguarde e nao clique novamente.';
        }

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function descreverPrecoPlano(valor, periodicidade) {
            var periodicidadeLabel = periodicidade.charAt(0) + periodicidade.slice(1).toLowerCase();
            if (Number(valor || 0) <= 0) {
                return 'Grátis<span>/' + periodicidadeLabel.toLowerCase() + '</span>';
            }

            return formatarValorMzn(valor) + '<span>/' + periodicidadeLabel.toLowerCase() + '</span>';
        }

        function atualizarValorPlanoSelecionado() {
            var periodicidade = document.getElementById('periodicidadePlano').value || 'MENSAL';
            var trialOption = document.querySelector('#metodoPagamento option[value="TRIAL"]');
            var trialDisponivel = planoAtual === 'BASICO' && !planoModoRenovacao && periodicidade === 'MENSAL';
            var eraTrial = planoBasicoTrial;
            planoBasicoTrial = trialDisponivel;
            if (trialOption) {
                trialOption.hidden = !trialDisponivel;
                trialOption.disabled = !trialDisponivel;
            }
            if (eraTrial !== trialDisponivel) {
                var metodoField = document.getElementById('metodoPagamento');
                var comprovativoField = document.getElementById('comprovativoPagamento');
                if (metodoField) {
                    metodoField.value = trialDisponivel ? 'TRIAL' : '';
                    metodoField.disabled = trialDisponivel;
                }
                if (comprovativoField) {
                    if (trialDisponivel) comprovativoField.value = '';
                    comprovativoField.disabled = trialDisponivel;
                }
            }
            var valores = precosPlanos[planoAtual] || {};
            valorAtual = planoBasicoTrial ? 0 : Number(valores[periodicidade] || 0);

            var periodicidadeLabel = periodicidade.charAt(0) + periodicidade.slice(1).toLowerCase();
            document.getElementById('planoSelecionadoValor').innerHTML = descreverPrecoPlano(valorAtual, periodicidade);

            var info = document.getElementById('infoValorPeriodicidade');
            info.innerHTML = '<strong>Ciclo selecionado:</strong> ' + periodicidadeLabel + ' | <strong>Valor:</strong> ' + (valorAtual <= 0 ? 'Grátis' : formatarValorMzn(valorAtual));
            atualizarInstrucoesPagamentoPlano();
        }

        function atualizarInstrucoesPagamentoPlano() {
            var box = document.getElementById('instrucoesPagamentoPlano');
            var titulo = document.getElementById('instrucoesPagamentoPlanoTitulo');
            var texto = document.getElementById('instrucoesPagamentoPlanoTexto');
            var metodoField = document.getElementById('metodoPagamento');
            if (!box || !titulo || !texto || !metodoField) return;

            var metodo = String(metodoField.value || '').toUpperCase();
            if (metodo === 'TRIAL') {
                titulo.textContent = 'Trial gratuito';
                texto.textContent = 'Nao e necessario pagamento nem comprovativo neste momento.';
                box.hidden = false;
                return;
            }

            var dadosMetodo = planoPaymentInstructions[metodo];
            if (!dadosMetodo) {
                box.hidden = true;
                titulo.textContent = '';
                texto.textContent = '';
                return;
            }

            titulo.textContent = String(dadosMetodo.titulo || 'Instrucoes de pagamento');
            texto.textContent = String(dadosMetodo.mensagem || '')
                .replace('{valor}', Number(valorAtual || 0).toLocaleString('pt-MZ'))
                .replace('{destino}', String(dadosMetodo.destino || ''));
            box.hidden = false;
        }

        document.getElementById('metodoPagamento').addEventListener('change', atualizarInstrucoesPagamentoPlano);

        // Selecionar plano
        function selecionarPlano(plano, renovar) {
            planoAtual = plano;
            planoModoRenovacao = renovar === true;
            planoBasicoTrial = plano === 'BASICO' && renovar !== true;
            document.getElementById('planoSelecionadoNome').textContent = plano;
            document.getElementById('periodicidadePlano').value = 'MENSAL';
            if (planoBasicoTrial) {
                document.getElementById('metodoPagamento').value = 'TRIAL';
                document.getElementById('metodoPagamento').disabled = true;
                document.getElementById('comprovativoPagamento').value = '';
                document.getElementById('comprovativoPagamento').disabled = true;
            } else {
                document.getElementById('metodoPagamento').value = '';
                document.getElementById('metodoPagamento').disabled = false;
                document.getElementById('comprovativoPagamento').disabled = false;
            }
            atualizarValorPlanoSelecionado();
            document.getElementById('alertModalPlano').style.display = 'none';
            var titulo = document.getElementById('modalPlanoTitulo');
            var info = document.getElementById('modalPlanoMensagemInfo');
            var btnConfirmar = document.getElementById('btnConfirmarCompraPlano');
            if (titulo) {
                titulo.innerHTML = planoModoRenovacao ?
                    '<i class="fas fa-rotate-right me-2"></i>Confirmar Renovacao do Plano' :
                    '<i class="fas fa-shopping-cart me-2"></i>Confirmar Upgrade de Plano';
            }
            if (info) {
                info.textContent = planoModoRenovacao ?
                    'Após confirmar, o pedido de renovação ficará pendente de aprovação do administrador.' :
                    (planoAtual === 'BASICO' ?
                        'O plano Básico tem período de teste de 30 dias. Após confirmar, aguarde a aprovação do administrador.' :
                        'Após confirmar, aguarde a verificação do pagamento. O plano será ativado após aprovação do administrador.');
            }
            if (btnConfirmar) {
                btnConfirmar.disabled = false;
                btnConfirmar.innerHTML = rotuloConfirmacaoPlano();
            }
        }

        function abrirDowngradePlano(plano) {
            document.getElementById('downgradePlanoCodigo').value = plano;
            document.getElementById('downgradePlanoDestino').textContent = plano.charAt(0) + plano.slice(1).toLowerCase();
            var alertDiv = document.getElementById('downgradePlanoAlert');
            alertDiv.hidden = true;
            alertDiv.textContent = '';
            document.getElementById('btnConfirmarDowngrade').disabled = false;
            new bootstrap.Modal(document.getElementById('modalDowngradePlano')).show();
        }

        function confirmarDowngradePlano() {
            var plano = document.getElementById('downgradePlanoCodigo').value;
            var alertDiv = document.getElementById('downgradePlanoAlert');
            var button = document.getElementById('btnConfirmarDowngrade');
            if (!plano || button.disabled) return;
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> A agendar...';
            fetch('api/plano_downgrade_solicitar.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'X-CSRF-Token': <?= json_encode($csrfToken) ?>},
                body: new URLSearchParams({plano: plano, _csrf: <?= json_encode($csrfToken) ?>})
            })
            .then(lerRespostaJsonSegura)
            .then(function(data) {
                alertDiv.hidden = false;
                alertDiv.className = 'alert alert-' + (data.success ? 'success' : 'danger');
                alertDiv.textContent = data.message || 'Nao foi possivel concluir o pedido.';
                if (data.success) setTimeout(function() { window.location.reload(); }, 1800);
            })
            .catch(function() {
                alertDiv.hidden = false;
                alertDiv.className = 'alert alert-danger';
                alertDiv.textContent = 'Falha de comunicacao ao agendar o downgrade.';
            })
            .finally(function() {
                button.disabled = false;
                button.innerHTML = '<i class="fas fa-calendar-check me-1"></i> Agendar downgrade';
            });
        }

        async function cancelarDowngradePlano(id) {
            if (!(await nivoraConfirmar('Cancelar o downgrade agendado?'))) return;
            fetch('api/plano_downgrade_cancelar.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'X-CSRF-Token': <?= json_encode($csrfToken) ?>},
                body: new URLSearchParams({id: id, _csrf: <?= json_encode($csrfToken) ?>})
            })
            .then(lerRespostaJsonSegura)
            .then(function(data) {
                if (!data.success) throw new Error(data.message || 'Falha ao cancelar.');
                window.location.reload();
            })
            .catch(function(error) { NivoraFeedback.error(error.message || 'Nao foi possivel cancelar o downgrade.'); });
        }

        function carregarDowngradeAgendado() {
            fetch('api/plano_downgrade_status.php', {credentials: 'same-origin'})
            .then(lerRespostaJsonSegura)
            .then(function(response) {
                var item = response.data;
                if (!response.success || !item) return;
                var host = document.querySelector('.upgrade-section .card-body');
                if (!host) return;
                var data = new Date(item.aplicar_em + 'T00:00:00').toLocaleDateString('pt-MZ');
                var estado = item.status === 'BLOQUEADO_LIMITE'
                    ? 'A verificacao de limites precisa de ajuste antes da aplicacao.'
                    : 'O plano atual permanece ativo ate essa data.';
                var banner = document.createElement('div');
                banner.className = 'alert alert-warning d-flex justify-content-between align-items-center gap-3';
                banner.innerHTML = '<div><strong>Downgrade agendado para ' + data + '</strong><br><small>Destino: ' + String(item.plano_destino || '') + '. ' + estado + '</small></div>';
                var cancel = document.createElement('button');
                cancel.type = 'button';
                cancel.className = 'btn btn-outline-dark btn-sm';
                cancel.textContent = 'Cancelar downgrade';
                cancel.onclick = function() { cancelarDowngradePlano(Number(item.id)); };
                banner.appendChild(cancel);
                host.insertBefore(banner, host.firstChild);
            })
            .catch(function() {});
        }

        function normalizarNomePlano(nome) {
            return String(nome || '')
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .trim()
                .toUpperCase();
        }

        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.plan-option').forEach(function(card) {
                var nome = (card.querySelector('.plan-option-name') || {}).textContent || '';
                var button = Array.from(card.querySelectorAll('button')).find(function(item) {
                    return item.disabled && /Plano Inferior/i.test(item.textContent || '');
                });
                if (!button || !nome) return;
                button.disabled = false;
                button.classList.remove('btn-outline-secondary');
                button.classList.add('btn-outline-warning');
                button.innerHTML = '<i class="fas fa-arrow-down me-1"></i> Solicitar downgrade';
                button.onclick = function() { abrirDowngradePlano(normalizarNomePlano(nome)); };
            });
            carregarDowngradeAgendado();
        });

        function obterHistoricoPlano() {
            return fetch('api/plano_listar.php', {
                    credentials: 'same-origin'
                })
                .then(function(r) {
                    return r.json();
                })
                .then(function(data) {
                    if (!data.success || !Array.isArray(data.data)) {
                        throw new Error(data.message || 'Falha ao consultar histórico');
                    }
                    return data.data;
                });
        }

        function reconciliarSolicitacaoPendente(plano, ciclo) {
            return obterHistoricoPlano()
                .then(function(itens) {
                    return itens.some(function(item) {
                        return String(item.status || '').toUpperCase() === 'PENDENTE' &&
                            String(item.plano_novo || '').toUpperCase() === String(plano || '').toUpperCase() &&
                            String(item.ciclo || 'MENSAL').toUpperCase() === String(ciclo || 'MENSAL').toUpperCase();
                    });
                })
                .catch(function(err) {
                    console.error('Falha ao reconciliar solicitação pendente:', err);
                    return false;
                });
        }

        function mostrarStatusPlano(alertDiv, tipo, mensagem) {
            if (!alertDiv) return;
            alertDiv.className = 'alert alert-' + tipo;
            alertDiv.textContent = mensagem;
            alertDiv.style.display = 'block';
        }

        function lerRespostaJsonSegura(response) {
            return response.text().then(function(raw) {
                try {
                    return JSON.parse(raw);
                } catch (error) {
                    var preview = String(raw || '').replace(/\s+/g, ' ').trim().slice(0, 180);
                    throw new Error(preview ? 'Resposta inesperada do servidor: ' + preview : 'Resposta vazia do servidor.');
                }
            });
        }

        // Confirmar compra
        function confirmarCompraPlano() {
            var metodo = document.getElementById('metodoPagamento').value;
            var periodicidade = document.getElementById('periodicidadePlano').value;
            var comprovativoInput = document.getElementById('comprovativoPagamento');
            var comprovativo = comprovativoInput && comprovativoInput.files ? comprovativoInput.files[0] : null;
            var alertDiv = document.getElementById('alertModalPlano');
            var btnConfirmar = document.getElementById('btnConfirmarCompraPlano');

            if (btnConfirmar && btnConfirmar.disabled) {
                return;
            }

            if (valorAtual > 0 && !metodo) {
                alertDiv.className = 'alert alert-danger';
                alertDiv.textContent = 'Selecione o método de pagamento!';
                alertDiv.style.display = 'block';
                return;
            }

            if (valorAtual > 0 && !comprovativo) {
                alertDiv.className = 'alert alert-danger';
                alertDiv.textContent = 'Envie o comprovativo de pagamento!';
                alertDiv.style.display = 'block';
                return;
            }

            if (comprovativo && comprovativo.size > 5 * 1024 * 1024) {
                alertDiv.className = 'alert alert-danger';
                alertDiv.textContent = 'Comprovativo excede 5MB.';
                alertDiv.style.display = 'block';
                return;
            }

            // Criar form data
            var formData = new FormData();
            formData.append('plano', planoAtual);
            formData.append('ciclo', planoBasicoTrial ? 'MENSAL' : periodicidade);
            formData.append('_csrf', <?php echo json_encode($csrfToken, JSON_UNESCAPED_UNICODE); ?>);
            if (planoBasicoTrial) {
                formData.append('metodo', 'TRIAL');
            } else {
                if (metodo) {
                    formData.append('metodo', metodo);
                }
                if (comprovativo) {
                    formData.append('comprovativo', comprovativo);
                }
            }

            if (btnConfirmar) {
                btnConfirmar.disabled = true;
                btnConfirmar.innerHTML = rotuloProcessandoPlano();
            }
            mostrarStatusPlano(alertDiv, 'info', mensagemProcessamentoPlano());

            var timeoutId = setTimeout(function() {
                mostrarStatusPlano(alertDiv, 'warning', 'Ainda estamos a processar. Nao feche esta janela para evitar pedido duplicado.');
                return;
                if (btnConfirmar) {
                    btnConfirmar.disabled = false;
                    btnConfirmar.innerHTML = rotuloConfirmacaoPlano();
                }
                alertDiv.className = 'alert alert-warning';
                alertDiv.textContent = 'Tempo limite atingido. Verificando status real do pedido...';
                alertDiv.style.display = 'block';

                reconciliarSolicitacaoPendente(planoAtual, periodicidade).then(function(existePendente) {
                    if (existePendente) {
                        alertDiv.className = 'alert alert-success';
                        alertDiv.textContent = 'O pedido já foi registado e está pendente de aprovação.';
                        alertDiv.style.display = 'block';
                        setTimeout(function() {
                            var modal = bootstrap.Modal.getInstance(document.getElementById('modalCompraPlano'));
                            if (modal) modal.hide();
                            window.location.reload();
                        }, 1200);
                    } else {
                        alertDiv.className = 'alert alert-danger';
                        alertDiv.textContent = 'Timeout: A operação demorou muito. Tente novamente.';
                        alertDiv.style.display = 'block';
                    }
                });
            }, 30000);

            // Chamar API
            fetch('api/plano_solicitar_renovacao.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(function(response) {
                    clearTimeout(timeoutId);
                    return lerRespostaJsonSegura(response);
                })
                .then(function(data) {
                    if (btnConfirmar) {
                        btnConfirmar.disabled = false;
                        btnConfirmar.innerHTML = rotuloConfirmacaoPlano();
                    }

                    if (data.success) {
                        alertDiv.className = 'alert alert-success';
                        alertDiv.textContent = data.message;
                        alertDiv.style.display = 'block';

                        // Fechar modal e recarregar após 2 segundos
                        setTimeout(function() {
                            var modal = bootstrap.Modal.getInstance(document.getElementById('modalCompraPlano'));
                            modal.hide();
                            window.location.reload();
                        }, 2000);
                    } else {
                        if (String(data.message || '').toLowerCase().indexOf('já existe uma solicitação pendente') !== -1) {
                            reconciliarSolicitacaoPendente(planoAtual, periodicidade).then(function(existePendente) {
                                alertDiv.className = existePendente ? 'alert alert-success' : 'alert alert-warning';
                                alertDiv.textContent = existePendente ?
                                    'Já existe um pedido pendente para este plano/ciclo. Aguardando aprovação.' :
                                    data.message;
                                alertDiv.style.display = 'block';
                                if (existePendente) {
                                    setTimeout(function() {
                                        var modal = bootstrap.Modal.getInstance(document.getElementById('modalCompraPlano'));
                                        if (modal) modal.hide();
                                        window.location.reload();
                                    }, 1200);
                                }
                            });
                        } else {
                            alertDiv.className = 'alert alert-danger';
                            alertDiv.textContent = data.message;
                            alertDiv.style.display = 'block';
                        }
                    }
                })
                .catch(function(err) {
                    clearTimeout(timeoutId);
                    if (btnConfirmar) {
                        btnConfirmar.disabled = false;
                        btnConfirmar.innerHTML = rotuloConfirmacaoPlano();
                    }

                    if (err.name === 'AbortError') {
                        return;
                    }

                    mostrarStatusPlano(alertDiv, 'warning', 'Nao conseguimos confirmar a resposta. A verificar se o pedido foi registado...');
                    reconciliarSolicitacaoPendente(planoAtual, periodicidade).then(function(existePendente) {
                        if (existePendente) {
                            mostrarStatusPlano(alertDiv, 'success', 'O pedido foi registado e esta pendente de aprovacao.');
                            setTimeout(function() {
                                var modal = bootstrap.Modal.getInstance(document.getElementById('modalCompraPlano'));
                                if (modal) modal.hide();
                                window.location.reload();
                            }, 1200);
                            return;
                        }

                        mostrarStatusPlano(alertDiv, 'danger', 'Nao foi possivel concluir agora. Verifique a ligacao e tente novamente. Detalhe: ' + (err.message || 'falha de rede'));
                    });
                });
        }

        function abrirComprovativoConf(pathEncoded) {
            var path = decodeURIComponent(pathEncoded || '');
            var bodyEl = document.getElementById('comprovativoModalBody');
            var dlLink = document.getElementById('comprovativoDownloadLink');
            if (!bodyEl || !dlLink) {
                return;
            }

            var fileUrl = path.indexOf('comprovativo_arquivo.php') !== -1 ?
                path :
                'comprovativo_arquivo.php?path=' + encodeURIComponent(path);
            var url;
            try {
                url = new URL(fileUrl, window.location.origin);
            } catch (e) {
                bodyEl.textContent = 'Comprovativo inválido.';
                return;
            }

            if (!/^https?:$/.test(url.protocol)) {
                bodyEl.textContent = 'Comprovativo inválido.';
                return;
            }

            dlLink.href = url.href;
            bodyEl.textContent = '';
            var ext = (url.pathname.split('.').pop() || '').toLowerCase();
            if (ext === 'pdf') {
                var iframe = document.createElement('iframe');
                iframe.src = url.href;
                iframe.style.width = '100%';
                iframe.style.height = '500px';
                iframe.style.border = 'none';
                bodyEl.appendChild(iframe);
            } else {
                var img = document.createElement('img');
                img.src = url.href;
                img.className = 'img-fluid rounded';
                img.alt = 'Comprovativo';
                img.style.maxHeight = '500px';
                img.onerror = function() {
                    bodyEl.textContent = 'Não foi possível carregar o ficheiro.';
                };
                bodyEl.appendChild(img);
            }
            var modal = new bootstrap.Modal(document.getElementById('modalComprovativo'));
            modal.show();
        }

        // Carregar histórico de compras
        <?php if ($secao === 'plano'): ?>
            var historicoComprasDados = [];

            function normalizarTextoHistorico(valor) {
                return String(valor || '').toLowerCase();
            }

            function obterHistoricoComprasFiltrado() {
                var texto = normalizarTextoHistorico((document.getElementById('filtroHistoricoTexto') || {}).value || '');
                var cicloSelecionado = normalizarTextoHistorico((document.getElementById('filtroHistoricoCiclo') || {}).value || '').toUpperCase();
                var statusSelecionado = normalizarTextoHistorico((document.getElementById('filtroHistoricoStatus') || {}).value || '').toUpperCase();

                return historicoComprasDados.filter(function(c) {
                    var ciclo = c.ciclo ? String(c.ciclo).toUpperCase() : (((c.metodo_pagamento || '').indexOf('-') !== -1 ? (c.metodo_pagamento || '').split('-').pop().trim() : 'MENSAL').toUpperCase());
                    var status = String(c.status || '').toUpperCase();
                    if (cicloSelecionado && ciclo !== cicloSelecionado) {
                        return false;
                    }
                    if (statusSelecionado && status !== statusSelecionado) {
                        return false;
                    }
                    if (!texto) {
                        return true;
                    }
                    var hay = [c.plano_atual, c.plano_novo, c.metodo_pagamento, c.status, ciclo].map(normalizarTextoHistorico).join(' ');
                    return hay.indexOf(texto) !== -1;
                });
            }

            function renderHistoricoComprasFiltrado() {
                var container = document.getElementById('historicoCompras');
                var dadosFiltrados = obterHistoricoComprasFiltrado();

                if (!dadosFiltrados.length) {
                    container.innerHTML = '<p class="text-center text-muted py-4">Nenhuma compra encontrada</p>';
                    return;
                }

                var html = '<table class="table"><thead><tr><th>ID</th><th>De</th><th>Para</th><th>Valor</th><th>Método</th><th>Ciclo</th><th>Comprovativo</th><th>Status</th><th>Data</th></tr></thead><tbody>';
                dadosFiltrados.forEach(function(c) {
                    var statusClass = c.status === 'APROVADO' ? 'success' : (c.status === 'PENDENTE' ? 'warning' : 'danger');
                    var dataRef = c.data_compra || c.criado_em || c.created_at;
                    var ciclo = c.ciclo ? c.ciclo : ((c.metodo_pagamento || '').indexOf('-') !== -1 ? (c.metodo_pagamento || '').split('-').pop().trim() : 'MENSAL');
                    var comprovativo = c.comprovativo_path ? '<button class="btn btn-sm btn-outline-primary btn-comprovativo-hist" onclick="abrirComprovativoConf(\'' + encodeURIComponent(c.comprovativo_path) + '\')" title="Ver comprovativo"><i class="fas fa-file-invoice"></i></button>' : '-';
                    html += '<tr><td>#' + escapeHtml(c.id) + '</td><td>' + escapeHtml(c.plano_atual) + '</td><td>' + escapeHtml(c.plano_novo) + '</td><td>' + parseFloat(c.valor).toFixed(2) + ' MZN</td><td>' + escapeHtml(c.metodo_pagamento) + '</td><td>' + escapeHtml(ciclo) + '</td><td class="text-center">' + comprovativo + '</td><td><span class="badge-custom badge-' + statusClass + '">' + escapeHtml(c.status) + '</span></td><td>' + (dataRef ? new Date(dataRef).toLocaleDateString('pt-BR') : '-') + '</td></tr>';
                });
                html += '</tbody></table>';
                container.innerHTML = html;
            }

            function exportarHistoricoComprasCsv() {
                var dadosFiltrados = obterHistoricoComprasFiltrado();
                if (!dadosFiltrados.length) {
                    NivoraFeedback.info('Nenhum registo para exportar.');
                    return;
                }

                var linhas = [];
                linhas.push(['ID', 'Plano De', 'Plano Para', 'Valor (MZN)', 'Metodo', 'Ciclo', 'Status', 'Data'].join(';'));

                dadosFiltrados.forEach(function(c) {
                    var dataRef = c.data_compra || c.criado_em || c.created_at;
                    var dataFormatada = dataRef ? new Date(dataRef).toLocaleDateString('pt-BR') : '-';
                    var ciclo = c.ciclo ? c.ciclo : ((c.metodo_pagamento || '').indexOf('-') !== -1 ? (c.metodo_pagamento || '').split('-').pop().trim() : 'MENSAL');
                    var valor = (parseFloat(c.valor) || 0).toFixed(2);

                    var linha = [
                        String(c.id || ''),
                        String(c.plano_atual || ''),
                        String(c.plano_novo || ''),
                        String(valor),
                        String(c.metodo_pagamento || ''),
                        String(ciclo || ''),
                        String(c.status || ''),
                        String(dataFormatada)
                    ].map(function(v) {
                        return '"' + v.replace(/"/g, '""') + '"';
                    }).join(';');

                    linhas.push(linha);
                });

                var csv = '\uFEFF' + linhas.join('\n');
                var blob = new Blob([csv], {
                    type: 'text/csv;charset=utf-8;'
                });
                var url = window.URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = 'historico_compras_' + new Date().toISOString().slice(0, 10) + '.csv';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(url);
            }

            fetch('api/plano_listar.php', {
                    credentials: 'same-origin'
                })
                .then(function(r) {
                    return r.json();
                })
                .then(function(data) {
                    if (data.success && data.data && data.data.length > 0) {
                        historicoComprasDados = data.data;
                        renderHistoricoComprasFiltrado();
                    } else {
                        document.getElementById('historicoCompras').innerHTML = '<p class="text-center text-muted py-4">Nenhuma compra encontrada</p>';
                    }
                })
                .catch(function(err) {
                    console.error('Erro ao carregar compras:', err);
                    document.getElementById('historicoCompras').innerHTML = '<p class="text-center text-danger py-4">Erro ao carregar histórico</p>';
                });

            ['filtroHistoricoTexto', 'filtroHistoricoCiclo', 'filtroHistoricoStatus'].forEach(function(id) {
                var el = document.getElementById(id);
                if (!el) {
                    return;
                }
                var ev = id === 'filtroHistoricoTexto' ? 'input' : 'change';
                el.addEventListener(ev, renderHistoricoComprasFiltrado);
            });

            var btnExportar = document.getElementById('btnExportarHistoricoCsv');
            if (btnExportar) {
                btnExportar.addEventListener('click', exportarHistoricoComprasCsv);
            }
        <?php endif; ?>

        function previewLogoRestauranteConfig(input) {
            var previewImage = document.getElementById('logoPreviewImage');
            var previewInfo = document.getElementById('logoPreviewInfo');

            if (!previewImage || !previewInfo) {
                return;
            }

            if (!input.files || !input.files.length) {
                <?php if ($logo_restaurante !== ''): ?>
                    previewImage.src = <?php echo json_encode($logo_restaurante, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
                    previewImage.style.display = 'block';
                    previewInfo.textContent = <?php echo json_encode(basename($logo_restaurante), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
                <?php else: ?>
                    previewImage.removeAttribute('src');
                    previewImage.style.display = 'none';
                    previewInfo.textContent = 'Nenhum logotipo enviado para este restaurante.';
                <?php endif; ?>
                return;
            }

            var file = input.files[0];
            previewInfo.textContent = file.name;

            if (typeof FileReader === 'undefined') {
                return;
            }

            var reader = new FileReader();
            reader.onload = function(event) {
                previewImage.src = event.target.result;
                previewImage.style.display = 'block';
            };
            reader.readAsDataURL(file);
        }
    </script>
</body>

</html>
