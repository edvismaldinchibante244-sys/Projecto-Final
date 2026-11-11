                    <?php if ($alertaFuncionalidadeBloqueada): ?>
                        <div class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4" style="border-radius: 16px;" role="alert">
                            <div class="d-flex align-items-start gap-2">
                                <i class="fas fa-lock fs-4 mt-1"></i>
                                <div>
                                    <strong>Funcionalidade não disponível no seu plano.</strong>
                                    <div class="small text-muted mt-1">
                                        <?php echo htmlspecialchars($rotuloFuncionalidadeBloqueada, ENT_QUOTES, 'UTF-8'); ?> faz parte de um plano superior. Entre em contacto com o administrador ou faça upgrade para desbloquear.
                                    </div>
                                </div>
                            </div>
                            <?php if ($podeGerirPlanos): ?>
                                <a href="configuracoes.php?secao=plano&rid=<?php echo intval($rid); ?>" class="btn btn-primary btn-sm" style="border-radius: 10px; font-weight: 600;">Ver planos e upgrade</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
