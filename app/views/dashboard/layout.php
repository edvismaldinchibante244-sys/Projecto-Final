<?php
/** Estrutura da pagina do dashboard; o conteudo vem da vista do perfil ($dashboardVista). */
?>
<!DOCTYPE html>
<html lang="pt">

<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(dirname(APP_BASE) . '/src/public/js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - RestauranteSaaS</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link href="lib/bootstrap-5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="lib/fontawesome-6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/dashboard.css?v=<?php echo (int)@filemtime(DASHBOARD_PUBLIC_DIR . '/css/dashboard.css'); ?>">
    <link rel="stylesheet" href="css/premium.css">
    <?php if (function_exists('empresarial_head_assets')) { empresarial_head_assets(); } ?>
    <style>
        /* Important overrides to ensure compact dashboard visuals take effect */
        .chart-wrapper {
            position: relative;
            width: 100%;
            height: 280px !important;
            min-height: 280px !important;
            max-height: 320px !important;
            overflow: hidden !important;
            background: #fff;
        }

        #salesChartSvg {
            display: block;
            width: 100%;
            height: 100%;
        }

        .summary-metric {
            min-width: 140px !important;
            padding-top: 6px !important;
            padding-bottom: 6px !important;
        }

        .team-online-list {
            max-height: 220px !important;
            overflow: auto !important;
        }

        .team-status-online {
            background: linear-gradient(180deg, #10b981, #059669) !important;
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.08) !important;
        }
    </style>
</head>

<body class="premium-ui plan-theme-<?php echo strtolower(preg_replace('/[^A-Z]/', '', $plano_atual) ?: 'basico'); ?><?php echo function_exists('empresarial_body_class') && empresarial_body_class() !== '' ? ' ' . htmlspecialchars(empresarial_body_class()) : ''; ?>">
    <?php
    $top_nome_usuario = $_SESSION['nome'] ?? 'Usuário';
    $top_nome_curto = explode(' ', trim($top_nome_usuario))[0] ?: 'Usuário';
    $top_perfil_usuario = $_SESSION['perfil'] ?? 'USER';
    $top_perfil_exibicao = intval($_SESSION['super_admin'] ?? 0) === 1 ? 'SUPER ADMIN' : $top_perfil_usuario;
    $top_nome_partes = preg_split('/\s+/', trim($top_nome_usuario));
    $top_iniciais = mb_strtoupper(
        mb_substr((string)($top_nome_partes[0] ?? 'U'), 0, 1, 'UTF-8') . mb_substr((string)($top_nome_partes[1] ?? ''), 0, 1, 'UTF-8'),
        'UTF-8'
    );
    $top_foto_usuario = $_SESSION['foto'] ?? '';
    $public_base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    $resolve_foto_url = function ($path) use ($public_base) {
        if (empty($path)) {
            return '';
        }
        if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0 || strpos($path, '/') === 0) {
            return $path;
        }
        return $public_base . '/' . ltrim($path, '/');
    };
    $top_foto_url = $resolve_foto_url($top_foto_usuario);
    $perfil_menu = strtoupper(trim((string)($_SESSION['perfil'] ?? 'USER')));
    if ($perfil_menu === 'GARÇOM') {
        $perfil_menu = 'GARCOM';
    }
    ?>
    <div class="container-fluid">
        <div class="row">
            <!-- SIDEBAR -->
            <?php include_once DASHBOARD_PUBLIC_DIR . '/includes/sidebar.php'; ?>
            <?php
            if (isset($plano_atual) && is_string($plano_atual)) {
                $isEmpresarialUi = (plano_normalizar_nome($plano_atual) === 'EMPRESARIAL');
            }
            if (!empty($isEmpresarialUi)) {
                echo '<script nonce="' . (function_exists('security_csp_nonce') ? security_csp_nonce() : '') . '">document.body.classList.add("plano-empresarial","dash-emp-page");</script>';
                if ($perfil === 'GERENTE') {
                    echo '<script nonce="' . (function_exists('security_csp_nonce') ? security_csp_nonce() : '') . '">document.body.classList.add("dash-gerente-page");</script>';
                }
                echo '<link rel="stylesheet" href="css/empresarial.css?v=20260726aj">' . "\n";
            }
            ?>

            <!-- MAIN CONTENT -->
            <main class="main-content">
                <div class="top-bar">
                    <h1 class="page-title"><i class="fas fa-tachometer-alt"></i> Dashboard
                        <?php if ($plano_atual === 'BASICO'): ?>
                            <span class="badge ms-2" style="font-size:11px;font-weight:800;letter-spacing:.04em;background:#e2e8f0;color:#334155;border-radius:999px;padding:6px 10px;vertical-align:middle;">BÁSICO</span>
                        <?php elseif ($plano_atual === 'PROFISSIONAL'): ?>
                            <span class="badge ms-2" style="font-size:11px;font-weight:800;letter-spacing:.04em;background:linear-gradient(135deg,#0e7490,#0891b2);color:#fff;border-radius:999px;padding:6px 10px;vertical-align:middle;">PROFISSIONAL</span>
                        <?php elseif ($plano_atual === 'EMPRESARIAL'): ?>
                            <span class="badge ms-2" style="font-size:11px;font-weight:800;letter-spacing:.04em;background:linear-gradient(135deg,#c2410c,#ea580c);color:#fff;border-radius:999px;padding:6px 10px;vertical-align:middle;">EMPRESARIAL</span>
                        <?php endif; ?>
                    </h1>
                    <div class="top-bar-right">
                        <span class="top-bar-date"><i class="far fa-clock"></i><span id="topBarDateTime"><?php echo date('d/m/Y H:i'); ?></span></span>
                        <div class="top-bar-user-chip">
                            <?php if (!empty($top_foto_url)): ?>
                                <img src="<?php echo htmlspecialchars($top_foto_url); ?>" alt="<?php echo htmlspecialchars($top_nome_usuario); ?>">
                            <?php else: ?>
                                <span class="chip-avatar"><?php echo htmlspecialchars($top_iniciais); ?></span>
                            <?php endif; ?>
                            <span class="chip-info">
                                <span class="chip-name"><?php echo htmlspecialchars($top_nome_usuario); ?></span>
                                <span class="chip-role"><?php echo htmlspecialchars($top_perfil_exibicao); ?></span>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="content-area">
<?php require APP_BASE . '/views/dashboard/' . $dashboardVista . '.php'; ?>
                </div>
            </main>
        </div>
    </div>

    <?php if (!$temSuporte24h && $canalSuporteContato !== ''): ?>
        <div class="modal fade" id="modalSuporteCanal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-headset text-primary me-2"></i><?php echo $temSuporteWhatsapp ? 'Suporte via WhatsApp' : 'Suporte por email'; ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info py-2" style="font-size:13px;">
                            <?php if ($temSuporteWhatsapp): ?>
                                Plano Profissional com atendimento via WhatsApp e copia por email. O sistema registra o protocolo, envia a solicitacao por email e abre o canal oficial com a mensagem pronta.
                            <?php else: ?>
                                Plano Basico com atendimento por email. O sistema registra o protocolo e envia a solicitação para a equipa de suporte.
                            <?php endif; ?>
                        </div>
                        <form id="formSuporteCanal" class="row g-3">
                            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrfTicketToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="canal" value="<?php echo $temSuporteWhatsapp ? 'WHATSAPP' : 'EMAIL'; ?>">
                            <div class="col-md-8">
                                <label class="form-label">Assunto</label>
                                <input type="text" class="form-control" name="assunto" maxlength="160" placeholder="Assunto do pedido de suporte">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Prioridade</label>
                                <select class="form-select" name="prioridade">
                                    <option value="MEDIA">Media</option>
                                    <option value="ALTA" selected>Alta</option>
                                    <option value="URGENTE">Urgente</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Descricao</label>
                                <textarea class="form-control" name="descricao" rows="4" placeholder="Descreva o problema e o impacto"></textarea>
                            </div>
                            <div class="col-12 d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                <div id="supportChannelFeedback" class="small text-muted"></div>
                                <button type="submit" class="btn btn-primary" id="btnEnviarSuporteCanal">
                                    <i class="fas fa-paper-plane me-1"></i><?php echo $temSuporteWhatsapp ? 'Enviar e abrir WhatsApp' : 'Enviar solicitacao'; ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php // So administradores podem usar os tickets de suporte (api/support_ticket_list.php).
    if ($temSuporte24h && strtoupper((string)$perfil) === 'ADMIN'): ?>
        <div class="modal fade" id="modalSuporte247" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-headset text-primary me-2"></i>Suporte 24/7</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info py-2" style="font-size:13px;">
                            Plano Empresarial com fila prioritaria. Abra o ticket e acompanhe o retorno da equipa aqui mesmo.
                        </div>
                        <form id="formSuporte247" class="row g-3 mb-4">
                            <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrfTicketToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="col-md-8">
                                <label class="form-label">Assunto</label>
                                <input type="text" class="form-control" name="assunto" maxlength="160" placeholder="Assunto do problema">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Prioridade</label>
                                <select class="form-select" name="prioridade">
                                    <option value="MEDIA">Media</option>
                                    <option value="ALTA" selected>Alta</option>
                                    <option value="URGENTE">Urgente</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Descricao</label>
                                <textarea class="form-control" name="descricao" rows="4" placeholder="Descreva o problema e o que já tentou"></textarea>
                            </div>
                            <div class="col-12 d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                <div id="support247Feedback" class="small text-muted"></div>
                                <button type="submit" class="btn btn-primary" id="btnEnviarSuporte247">
                                    <i class="fas fa-paper-plane me-1"></i>Enviar Ticket
                                </button>
                            </div>
                        </form>

                        <div class="row g-2 mb-3">
                            <div class="col-md-4">
                                <div class="support247-summary-card">
                                    <span class="support247-summary-label">Abertos</span>
                                    <span class="support247-summary-value" id="support247CountAbertos">0</span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="support247-summary-card">
                                    <span class="support247-summary-label">Em andamento</span>
                                    <span class="support247-summary-value" id="support247CountAndamento">0</span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="support247-summary-card">
                                    <span class="support247-summary-label">Resolvidos</span>
                                    <span class="support247-summary-value" id="support247CountResolvidos">0</span>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0">Meus tickets recentes</h6>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnAtualizarTickets247">
                                <i class="fas fa-rotate-right me-1"></i>Atualizar
                            </button>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Assunto</th>
                                        <th>Prioridade</th>
                                        <th>Status</th>
                                        <th>Atualizado</th>
                                        <th class="text-end">Chat</th>
                                    </tr>
                                </thead>
                                <tbody id="support247TicketsBody">
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-3">Nenhum ticket carregado.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal fade" id="modalSuporte247Chat" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-comments text-primary me-2"></i>Chat Ticket <span id="supportChatTicketLabel">#0</span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <div id="support247ChatList" class="border rounded p-2 mb-3" style="min-height:240px;max-height:420px;overflow:auto;background:#fafbff;"></div>
                        <form id="formSuporte247Msg" enctype="multipart/form-data">
                            <input type="hidden" name="ticket_id" id="supportChatTicketId" value="0">
                            <div class="mb-2">
                                <textarea class="form-control" name="mensagem" id="supportChatMensagem" rows="3" placeholder="Escreva a sua mensagem"></textarea>
                            </div>
                            <div class="d-flex gap-2 align-items-center">
                                <input type="file" class="form-control" name="anexo" id="supportChatAnexo" accept=".jpg,.jpeg,.png,.webp,.pdf,image/*,application/pdf">
                                <button type="submit" class="btn btn-primary" id="btnEnviarSupportMsg">Enviar</button>
                            </div>
                            <div id="support247ChatFeedback" class="small text-muted mt-2"></div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div id="support247ToastContainer" class="support247-toast-container" aria-live="polite" aria-atomic="true"></div>

    <script src="lib/bootstrap-5.3.2/js/bootstrap.bundle.min.js"></script>
    <script id="dashboard-js-data" type="application/json">
        <?php
        echo json_encode([
            'dashboardMeta' => [
                'restauranteId' => intval($rid),
                'perfil' => $perfil,
                'usuarioId' => intval($usuario_logado_id),
                'plano' => $plano_atual,
                'temGarcons' => !empty($temGarcons),
                'temContasAbertas' => !empty($temContasAbertas),
                'temCaixa' => !empty($temCaixa),
            ],
            'supportConfig' => [
                'temSuporte24h' => $temSuporte24h ? true : false,
                'canalSuporteContato' => $canalSuporteContato,
                'csrfTicketToken' => $csrfTicketToken,
            ],
            // Tempo real: endereco + codigo assinado para a sala deste restaurante (null se desligado).
            'realtime' => function_exists('realtime_codigo_sala') ? realtime_codigo_sala(intval($rid)) : null,
            'grafico' => [
                'labels' => array_map(function ($d) {
                    return date('d/m', strtotime($d['data']));
                }, $dados_grafico),
                'data' => array_map(function ($d) {
                    return floatval($d['total']);
                }, $dados_grafico),
                'minPontos' => intval($grafico_min_pontos),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        ?>
    </script>
    <script src="js/dashboard.js?v=<?php echo (int)@filemtime(DASHBOARD_PUBLIC_DIR . '/js/dashboard.js'); ?>"></script>
    <script src="js/dashboard_admin.js?v=<?php echo (int)@filemtime(DASHBOARD_PUBLIC_DIR . '/js/dashboard_admin.js'); ?>"></script>
</body>

</html>
