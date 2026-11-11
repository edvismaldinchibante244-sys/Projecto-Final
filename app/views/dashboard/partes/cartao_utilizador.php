                    <!-- CARD USUÁRIO PROFISSIONAL -->
                    <?php
                    $nome_usuario_completo = $_SESSION['nome'] ?? 'Usuário';
                    $perfil_usuario_card = $_SESSION['perfil'] ?? 'USER';
                    $usuario_id = $_SESSION['usuario_id'] ?? 0;
                    $foto_usuario = $_SESSION['foto'] ?? '';
                    $foto_usuario_url = $resolve_foto_url($foto_usuario);
                    $data_cadastro = $_SESSION['criado_em'] ?? date('Y-m-d');
                    ?>
                    <?php
                    // Adiciona botão de iniciar turno para perfis operacionais sem turno ativo
                    include_once DASHBOARD_PUBLIC_DIR . '/includes/turno_dashboard_helper.php';
                    $perfil_operacional = !$isSuperAdmin && in_array($perfil, ['ADMIN', 'GERENTE', 'GARCOM', 'CAIXA', 'BAR', 'COZINHA'], true);
                    $sem_turno_ativo = false;
                    if ($perfil_operacional && $usuario_id > 0 && $rid > 0) {
                        $sem_turno_ativo = !dashboard_usuario_possui_turno_ativo($db, $usuario_id, $rid);
                    }
                    ?>
