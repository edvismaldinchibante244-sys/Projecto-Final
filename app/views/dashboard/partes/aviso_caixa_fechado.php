                    <!-- AVISO CAIXA FECHADO -->
                    <?php if (!$caixa_aberto && $temCaixa): ?>
                        <div class="alert alert-warning d-flex align-items-center mb-4" style="border-radius: 16px;">
                            <i class="fas fa-exclamation-triangle me-3 fs-4"></i>
                            <div class="flex-grow-1"><strong>Atenção!</strong> O caixa ainda não foi aberto hoje.</div>
                            <a href="caixa.php" class="btn btn-warning btn-sm">Abrir Caixa</a>
                        </div>
                    <?php endif; ?>

