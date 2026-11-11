(function () {
    const config = window.FILIAIS_CONFIG || {};
    const csrfToken = config.csrfToken || '';
    const createForm = document.getElementById('formNovaFilial');
    const editForm = document.getElementById('formEditarFilial');
    const createAlert = document.getElementById('alertFilialCriacao');
    const editAlert = document.getElementById('alertFilialEdicao');
    const createBtn = document.getElementById('btnCriarFilial');
    const editBtn = document.getElementById('btnSalvarFilial');
    const createModalEl = document.getElementById('modalAdicionarFilial');
    const editModalEl = document.getElementById('modalEditarFilial');
    const editModal = editModalEl ? new bootstrap.Modal(editModalEl) : null;

    const setAlert = (element, message, type) => {
        if (!element) return;
        element.className = 'alert alert-' + type + ' mx-3 mt-3 mb-0';
        element.textContent = message;
        element.classList.remove('d-none');
    };

    const clearAlert = (element) => {
        if (!element) return;
        element.className = 'alert d-none mx-3 mt-3 mb-0';
        element.textContent = '';
    };

    const setBusy = (button, isBusy, busyHtml, idleHtml) => {
        if (!button) return;
        button.disabled = isBusy;
        button.innerHTML = isBusy ? busyHtml : idleHtml;
    };

    const postForm = async (url, formElement) => {
        const body = new URLSearchParams(new FormData(formElement));
        body.set('_csrf', csrfToken);

        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken
            },
            body
        });

        let data = null;
        try {
            data = await response.json();
        } catch (error) {
            throw new Error('Resposta invalida do servidor.');
        }

        if (!response.ok && (!data || !data.message)) {
            throw new Error('Nao foi possivel concluir a operacao.');
        }

        return data;
    };

    if (createForm) {
        createForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            clearAlert(createAlert);
            setBusy(
                createBtn,
                true,
                '<i class="fas fa-spinner fa-spin me-1"></i>Criando...',
                '<i class="fas fa-plus me-1"></i> Criar Filial'
            );

            try {
                const data = await postForm(config.createUrl, createForm);
                if (!data.success) {
                    setAlert(createAlert, data.message || 'Nao foi possivel criar a filial.', 'danger');
                    return;
                }

                window.location.href = 'filiais.php?secao=inicio&tipo=success&msg=' + encodeURIComponent(data.message || 'Filial criada com sucesso.');
            } catch (error) {
                setAlert(createAlert, error.message || 'Erro ao criar filial.', 'danger');
            } finally {
                setBusy(
                    createBtn,
                    false,
                    '<i class="fas fa-spinner fa-spin me-1"></i>Criando...',
                    '<i class="fas fa-plus me-1"></i> Criar Filial'
                );
            }
        });
    }

    if (editForm) {
        editForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            clearAlert(editAlert);
            setBusy(
                editBtn,
                true,
                '<i class="fas fa-spinner fa-spin me-1"></i>Salvando...',
                '<i class="fas fa-save me-1"></i> Salvar Alteracoes'
            );

            try {
                const data = await postForm(config.updateUrl, editForm);
                if (!data.success) {
                    setAlert(editAlert, data.message || 'Nao foi possivel atualizar a filial.', 'danger');
                    return;
                }

                window.location.href = 'filiais.php?secao=inicio&tipo=success&msg=' + encodeURIComponent(data.message || 'Filial atualizada com sucesso.');
            } catch (error) {
                setAlert(editAlert, error.message || 'Erro ao atualizar filial.', 'danger');
            } finally {
                setBusy(
                    editBtn,
                    false,
                    '<i class="fas fa-spinner fa-spin me-1"></i>Salvando...',
                    '<i class="fas fa-save me-1"></i> Salvar Alteracoes'
                );
            }
        });
    }

    document.querySelectorAll('[data-edit-filial]').forEach((button) => {
        button.addEventListener('click', () => {
            clearAlert(editAlert);
            document.getElementById('edit_filial_id').value = button.dataset.filialId || '';
            document.getElementById('edit_nome_filial').value = button.dataset.filialNome || '';
            document.getElementById('edit_email_filial').value = button.dataset.filialEmail || '';
            document.getElementById('edit_telefone_filial').value = button.dataset.filialTelefone || '';
            document.getElementById('edit_endereco_filial').value = button.dataset.filialEndereco || '';
            document.getElementById('edit_cidade_filial').value = button.dataset.filialCidade || '';
            document.getElementById('edit_status_filial').value = button.dataset.filialStatus || 'ATIVO';

            if (editModal) {
                editModal.show();
            }
        });
    });

    if (createModalEl && createForm) {
        createModalEl.addEventListener('hidden.bs.modal', () => {
            clearAlert(createAlert);
            createForm.reset();
        });
    }

    if (editModalEl && editForm) {
        editModalEl.addEventListener('hidden.bs.modal', () => {
            clearAlert(editAlert);
            editForm.reset();
        });
    }

    const transferForm = document.getElementById('formTransferenciaEstoque');
    const transferAlert = document.getElementById('alertTransferenciaEstoque');
    const transferBtn = document.getElementById('btnTransferirEstoque');
    const transferInsumo = document.getElementById('trfInsumo');

    const setTransferAlert = (message, type) => {
        if (!transferAlert) return;
        transferAlert.className = 'alert alert-' + type + ' mb-3';
        transferAlert.textContent = message;
        transferAlert.classList.remove('d-none');
    };

    const loadInsumosTransferencia = async () => {
        if (!transferInsumo || !config.transferEnabled) return;
        try {
            const response = await fetch(config.insumosUrl || 'api/estoque_insumo_listar.php', {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await response.json();
            const lista = Array.isArray(data.data) ? data.data : [];
            if (!lista.length) {
                transferInsumo.innerHTML = '<option value="">Sem insumos — cadastre em Estoque Inteligente</option>';
                return;
            }
            transferInsumo.innerHTML = '<option value="">Selecione o insumo</option>' + lista.map((item) => {
                const id = String(item.id || '');
                const nome = String(item.nome || ('Insumo #' + id));
                const unidade = String(item.unidade_base || item.unidade || 'un');
                return '<option value="' + id + '" data-unidade="' + unidade + '">' + nome + ' (' + unidade + ')</option>';
            }).join('');
        } catch (error) {
            transferInsumo.innerHTML = '<option value="">Erro ao carregar insumos</option>';
        }
    };

    if (transferInsumo) {
        transferInsumo.addEventListener('change', () => {
            const selected = transferInsumo.options[transferInsumo.selectedIndex];
            const unidade = selected ? selected.getAttribute('data-unidade') : '';
            const unidadeEl = document.getElementById('trfUnidade');
            if (unidade && unidadeEl) {
                const exists = Array.from(unidadeEl.options).some((opt) => opt.value === unidade);
                if (exists) unidadeEl.value = unidade;
            }
        });
    }

    if (transferForm && config.transferEnabled) {
        loadInsumosTransferencia();
        transferForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (transferAlert) {
                transferAlert.className = 'alert d-none mb-3';
                transferAlert.textContent = '';
            }

            const origem = document.getElementById('trfOrigem')?.value || '0';
            const destino = document.getElementById('trfDestino')?.value || '0';
            if (String(origem) === String(destino)) {
                setTransferAlert('Origem e destino devem ser diferentes.', 'warning');
                return;
            }

            setBusy(
                transferBtn,
                true,
                '<i class="fas fa-spinner fa-spin me-1"></i>A transferir...',
                '<i class="fas fa-right-left me-1"></i> Transferir agora'
            );

            try {
                const body = new FormData(transferForm);
                body.set('_csrf', csrfToken);
                const response = await fetch(config.transferUrl || 'api/estoque_transferir.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': csrfToken
                    },
                    body
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok || !data.success) {
                    setTransferAlert(data.message || 'Nao foi possivel transferir o estoque.', 'danger');
                    return;
                }
                setTransferAlert(data.message || 'Transferencia registada com sucesso.', 'success');
                transferForm.reset();
                loadInsumosTransferencia();
            } catch (error) {
                setTransferAlert(error.message || 'Erro ao transferir estoque.', 'danger');
            } finally {
                setBusy(
                    transferBtn,
                    false,
                    '<i class="fas fa-spinner fa-spin me-1"></i>A transferir...',
                    '<i class="fas fa-right-left me-1"></i> Transferir agora'
                );
            }
        });
    }
})();
