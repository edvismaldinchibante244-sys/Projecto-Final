/**
 * ============================================
 * JAVASCRIPT - GESTÃO DE MESAS (Bootstrap 5)
 * ============================================
 */

let modalNovaMesaInstance = null;
let modalReservaInstance = null;
let modalReservaQrInstance = null;
let reservaSubmitBusy = false;
let reservaPodeSubmeter = true;
let reservaDisponibilidadeSeq = 0;
let reservaQrState = null;

async function parseJsonResponse(response) {
    const raw = await response.text();
    let data = null;

    try {
        data = raw ? JSON.parse(raw) : null;
    } catch (error) {
        const compact = String(raw || '').replace(/\s+/g, ' ').trim();
        throw new Error(compact || ('Resposta invalida do servidor (HTTP ' + response.status + ').'));
    }

    if (!response.ok && data && data.message) {
        const error = new Error(data.detail ? (data.message + ' Detalhe: ' + data.detail) : data.message);
        error.code = data.code || '';
        error.payload = data;
        throw error;
    }

    if (!response.ok) {
        throw new Error('Falha na requisicao (HTTP ' + response.status + ').');
    }

    return data || {};
}

function reservaQrExpiracaoUtc(expiresAt) {
    const value = String(expiresAt || '').trim();
    if (!value) return null;

    // O backend persiste DATETIME em UTC; o sufixo Z evita o navegador
    // interpretar a data como hora local do aparelho.
    const parsed = new Date(value.replace(' ', 'T') + (/[zZ]|[+-]\d\d:\d\d$/.test(value) ? '' : 'Z'));
    return Number.isNaN(parsed.getTime()) ? null : parsed;
}

function reservaQrEstaExpirado(state = reservaQrState) {
    const expires = reservaQrExpiracaoUtc(state?.expires_at);
    return expires ? expires.getTime() <= Date.now() : false;
}

function reservaQrExpiracaoLocal(expiresAt) {
    const expires = reservaQrExpiracaoUtc(expiresAt);
    if (!expires) return String(expiresAt || '');

    return new Intl.DateTimeFormat('pt-MZ', {
        dateStyle: 'short',
        timeStyle: 'medium'
    }).format(expires);
}

function exigirReservaQrValido() {
    if (!reservaQrState || !reservaQrEstaExpirado(reservaQrState)) {
        return true;
    }

    showFeedback('Este QR Code expirou. Confirme os dados e clique em "Gerar QR" para criar uma nova sessao.', 'warning', 'alertReservaQr');
    preencherResultadoReservaQr(reservaQrState);
    return false;
}

document.addEventListener('DOMContentLoaded', function () {
    const modalMesaEl = document.getElementById('modalNovaMesa');
    if (modalMesaEl) {
        modalNovaMesaInstance = new bootstrap.Modal(modalMesaEl);
    }

    const modalReservaEl = document.getElementById('modalReservaMesa');
    if (modalReservaEl) {
        modalReservaInstance = new bootstrap.Modal(modalReservaEl);
    }

    const modalReservaQrEl = document.getElementById('modalReservaQr');
    if (modalReservaQrEl) {
        modalReservaQrInstance = new bootstrap.Modal(modalReservaQrEl);
    }

    const formMesa = document.getElementById('formNovaMesa');
    if (formMesa) {
        const numeroMesa = document.getElementById('mesa_numero');
        const capacidadeMesa = document.getElementById('mesa_capacidade');

        const atualizarPreviewMesa = function () {
            const previewNumero = document.getElementById('mesaCreatePreviewNumero');
            const previewCapacidade = document.getElementById('mesaCreatePreviewCapacidade');
            const capacidade = Math.max(1, Number(capacidadeMesa?.value || 4));

            if (previewNumero) previewNumero.textContent = numeroMesa?.value?.trim() || 'Identificação no salão';
            if (previewCapacidade) previewCapacidade.textContent = String(capacidade);

            document.querySelectorAll('[data-mesa-capacidade]').forEach(function (chip) {
                chip.classList.toggle('is-active', Number(chip.dataset.mesaCapacidade) === capacidade);
            });
        };

        numeroMesa?.addEventListener('input', atualizarPreviewMesa);
        capacidadeMesa?.addEventListener('input', atualizarPreviewMesa);
        document.querySelectorAll('[data-mesa-capacidade]').forEach(function (chip) {
            chip.addEventListener('click', function () {
                if (capacidadeMesa) {
                    capacidadeMesa.value = chip.dataset.mesaCapacidade || '4';
                    capacidadeMesa.dispatchEvent(new Event('input', { bubbles: true }));
                    capacidadeMesa.focus();
                }
            });
        });

        formMesa.addEventListener('submit', function (e) {
            e.preventDefault();
            const formData = new FormData(formMesa);
            formData.append('_csrf', getReservaToken());
            const submitButton = document.getElementById('btnSalvarNovaMesa');
            const submitButtonDefaultHtml = submitButton?.innerHTML || '<i class="fas fa-plus me-2"></i>Salvar';

            if (submitButton) {
                submitButton.disabled = true;
                submitButton.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>A adicionar...';
            }

            fetch('api/mesa_cadastrar.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': getReservaToken()
                }
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showFeedback(data.message, 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showFeedback(data.message, 'danger');
                    }
                })
                .catch(() => showFeedback('Erro ao cadastrar mesa.', 'danger'))
                .finally(() => {
                    if (submitButton) {
                        submitButton.disabled = false;
                        submitButton.innerHTML = submitButtonDefaultHtml;
                    }
                });
        });
    }

    const formReserva = document.getElementById('formReservaMesa');
    if (formReserva) {
        ['reserva_data', 'reserva_hora', 'reserva_quantidade', 'reserva_mesa_id'].forEach((fieldId) => {
            const field = document.getElementById(fieldId);
            if (!field) {
                return;
            }

            field.addEventListener('input', atualizarDisponibilidadeReserva);
            field.addEventListener('change', atualizarDisponibilidadeReserva);
        });

        refreshReservaSubmitButton();
        atualizarDisponibilidadeReserva();

        formReserva.addEventListener('submit', function (e) {
            e.preventDefault();

            const payload = {
                mesa_atribuida: document.getElementById('reserva_mesa_id').value || null,
                nome_cliente: document.getElementById('reserva_nome_cliente').value.trim(),
                telefone_cliente: document.getElementById('reserva_telefone_cliente').value.trim(),
                email_cliente: document.getElementById('reserva_email_cliente').value.trim(),
                data_reserva: document.getElementById('reserva_data').value,
                hora_reserva: document.getElementById('reserva_hora').value,
                quantidade_pessoas: Number(document.getElementById('reserva_quantidade').value || 0),
                observacoes: document.getElementById('reserva_observacoes').value.trim()
            };

            if (!payload.nome_cliente || !payload.data_reserva || !payload.hora_reserva || payload.quantidade_pessoas <= 0) {
                showFeedback('Preencha cliente, data, hora e quantidade de pessoas.', 'warning', 'alertReserva');
                return;
            }

            if (window.RESERVAS_CONFIG?.exigirEmail && !payload.email_cliente) {
                showFeedback('Informe o email do cliente para enviar a confirmação/cancelamento.', 'warning', 'alertReserva');
                return;
            }

            const capacidadeMaxima = Number(document.getElementById('reserva_quantidade').max || 0);
            if (capacidadeMaxima > 0 && payload.quantidade_pessoas > capacidadeMaxima) {
                showFeedback('A quantidade de pessoas excede a capacidade da mesa selecionada.', 'warning', 'alertReserva');
                return;
            }

            if (!reservaPodeSubmeter) {
                showFeedback('Nao ha disponibilidade para os dados informados.', 'warning', 'alertReserva');
                return;
            }

            setReservaSubmitBusy(true);

            fetch(buildReservaUrl(), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': getReservaToken()
                },
                body: JSON.stringify(payload)
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        if (typeof window.sidebarReservasMarcarVisto === 'function' && data.reserva_id) {
                            window.sidebarReservasMarcarVisto(data.reserva_id);
                        }
                        showFeedback(data.message || 'Reserva criada com sucesso.', 'success', 'alertReserva');
                        setTimeout(() => location.reload(), 1200);
                    } else {
                        showFeedback(data.message || 'Erro ao criar reserva.', 'danger', 'alertReserva');
                    }
                })
                .catch(() => {
                    showFeedback('Erro ao comunicar com a API de reservas.', 'danger', 'alertReserva');
                })
                .finally(() => {
                    setReservaSubmitBusy(false);
                });
        });
    }

    const formReservaQr = document.getElementById('formReservaQrInterna');
    if (formReservaQr) {
        const anexoInput = document.getElementById('qrReservaAnexo');

        if (anexoInput) {
            anexoInput.addEventListener('change', atualizarAnexoReservaQr);
        }

        formReservaQr.addEventListener('submit', async function (e) {
            e.preventDefault();
            const button = document.getElementById('btnGerarReservaQr');
            const buttonDefaultHtml = button?.innerHTML || '<i class="fas fa-bolt me-2"></i>Gerar QR';
            const payload = {
                mesa_id: document.getElementById('qr_mesa_id')?.value || '',
                expira_em_minutos: document.getElementById('qr_expira_em_minutos')?.value || '120',
                nome_atendente: document.getElementById('qr_nome_atendente')?.value?.trim() || '',
                nome_cliente: document.getElementById('qr_nome_cliente')?.value?.trim() || '',
                telefone_cliente: document.getElementById('qr_telefone_cliente')?.value?.trim() || '',
                email_cliente: document.getElementById('qr_email_cliente')?.value?.trim() || '',
                observacoes: document.getElementById('qr_observacoes')?.value?.trim() || '',
                tipo_qr: 'reserva'
            };

            if (!payload.nome_cliente) {
                showFeedback('Informe o nome do cliente para gerar o QR Code.', 'warning', 'alertReservaQr');
                return;
            }

            if (button) {
                button.disabled = true;
                button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>A gerar...';
            }

            try {
                const response = await fetch(window.RESERVAS_CONFIG?.qrEndpoint || 'api/reservas_qr.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': getReservaToken()
                    },
                    body: JSON.stringify(payload)
                });
                const data = await parseJsonResponse(response);

                if (!data.success || !data.id) {
                    showFeedback(
                        data.detail ? ((data.message || 'Nao foi possivel gerar o QR Code.') + ' Detalhe: ' + data.detail) : (data.message || 'Nao foi possivel gerar o QR Code.'),
                        'danger',
                        'alertReservaQr'
                    );
                    return;
                }

                const qrUrl = String(data.url || data.link || '').trim();
                if (!qrUrl || !/[?&]token=/.test(qrUrl)) {
                    showFeedback('Nao foi possivel gerar um QR valido: o link nao contem token da sessao. Gere novamente ou contacte o suporte.', 'danger', 'alertReservaQr');
                    return;
                }

                const mesaSelect = document.getElementById('qr_mesa_id');
                const mesaSelecionada = mesaSelect?.selectedOptions?.[0]?.textContent?.trim() || 'Melhor mesa disponível';
                const qrImageData = gerarQrReservaPngDataUrl(qrUrl, {
                    restaurante_nome: window.RESERVAS_CONFIG?.restauranteNome || 'Restaurante',
                    nome_cliente: payload.nome_cliente || 'Cliente',
                    mesa_label: mesaSelecionada,
                    expires_at: data.expires_at || '',
                    codigo: 'S-' + String(data.id).padStart(6, '0')
                });
                const estado = {
                    success: true,
                    sessao_id: data.id,
                    reserva_id: 0,
                    link: qrUrl,
                    expires_at: data.expires_at || '',
                    codigo: 'S-' + String(data.id).padStart(6, '0'),
                    download_name: 'qr-reserva-sessao-' + data.id + '.png',
                    localImageDataUrl: qrImageData,
                    telefone: payload.telefone_cliente || '',
                    email: payload.email_cliente || '',
                    observacoes: payload.observacoes || '',
                    mesa_label: mesaSelecionada,
                    downloaded: false,
                    selectedAttachmentFile: null,
                    selectedAttachmentName: '',
                    basePayload: {
                        sessao_id: data.id,
                        reserva_id: 0,
                        nome_cliente: payload.nome_cliente || '',
                        telefone_cliente: payload.telefone_cliente || '',
                        email_cliente: payload.email_cliente || '',
                        observacoes: payload.observacoes || ''
                    }
                };

                reservaQrState = estado;
                preencherResultadoReservaQr(estado);
                showFeedback('QR temporario de reserva/check-in gerado. Envie ao cliente ou use no check-in.', 'success', 'alertReservaQr');
            } catch (error) {
                showFeedback(error.message || 'Erro ao gerar a sessao QR.', 'danger', 'alertReservaQr');
            } finally {
                if (button) {
                    button.disabled = false;
                    button.innerHTML = buttonDefaultHtml;
                }
            }
        });
    }
});

async function atualizarMesa(id, novoStatus) {
    const labels = { LIVRE: 'liberar', OCUPADA: 'ocupar', RESERVADA: 'reservar' };
    if (!(await nivoraConfirmar('Deseja ' + (labels[novoStatus] || novoStatus) + ' esta mesa?'))) return;

    const formData = new FormData();
    formData.append('id', id);
    formData.append('status', novoStatus);
    formData.append('_csrf', getReservaToken());

    fetch('api/mesa_atualizar.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': getReservaToken()
        }
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showFeedback(data.message, 'success');
                setTimeout(() => location.reload(), 1200);
            } else {
                showFeedback(data.message, 'danger');
            }
        })
        .catch(() => showFeedback('Erro ao atualizar a mesa.', 'danger'));
}

function abrirModalNovaMesa() {
    const form = document.getElementById('formNovaMesa');
    if (form) form.reset();
    hideFeedback('alertMesa');

    const tipoField = document.getElementById('mesa_tipo');
    const numeroField = document.getElementById('mesa_numero');
    const capacidadeField = document.getElementById('mesa_capacidade');

    if (tipoField) tipoField.value = 'mesa';
    if (numeroField) {
        numeroField.value = '';
        numeroField.readOnly = false;
        numeroField.required = true;
        numeroField.dispatchEvent(new Event('input', { bubbles: true }));
    }
    if (capacidadeField) {
        capacidadeField.value = 4;
        capacidadeField.dispatchEvent(new Event('input', { bubbles: true }));
    }

    if (modalNovaMesaInstance) {
        modalNovaMesaInstance.show();
    } else {
        const modal = document.getElementById('modalNovaMesa');
        if (modal) modal.style.display = 'block';
    }
}

function abrirModalReserva(mesaId = '', mesaNumero = '', capacidade = 0) {
    const form = document.getElementById('formReservaMesa');
    if (form) form.reset();

    hideFeedback('alertReserva');

    const mesaIdField = document.getElementById('reserva_mesa_id');
    const mesaLabelField = document.getElementById('reserva_mesa_label');
    const qtdField = document.getElementById('reserva_quantidade');
    const dataField = document.getElementById('reserva_data');
    const horaField = document.getElementById('reserva_hora');

    if (mesaIdField) mesaIdField.value = mesaId || '';
    if (mesaLabelField) {
        mesaLabelField.value = mesaNumero
            ? 'Mesa ' + mesaNumero + ' • capacidade ' + (capacidade || '?')
            : 'Atribuição automática pela melhor disponibilidade';
    }

    if (qtdField) {
        qtdField.value = capacidade > 0 ? Math.min(2, capacidade) : 2;
        if (capacidade > 0) {
            qtdField.max = String(capacidade);
        } else {
            qtdField.removeAttribute('max');
        }
    }

    if (dataField && !dataField.value && window.RESERVAS_CONFIG?.hoje) {
        dataField.value = window.RESERVAS_CONFIG.hoje;
    }

    if (horaField && !horaField.value) {
        horaField.value = '19:00';
    }

    setReservaPodeSubmeter(true);
    updateReservaAvailabilityHint('Selecione data, hora e quantidade para ver a disponibilidade.', 'muted');
    refreshReservaSubmitButton();
    setTimeout(atualizarDisponibilidadeReserva, 0);

    if (modalReservaInstance) {
        modalReservaInstance.show();
    } else {
        const modal = document.getElementById('modalReservaMesa');
        if (modal) modal.style.display = 'block';
    }
}

function abrirModalReservaQr() {
    const form = document.getElementById('formReservaQrInterna');
    if (form) {
        form.reset();
    }

    hideFeedback('alertReservaQr');
    const resultado = document.getElementById('qrReservaResultado');
    if (resultado) {
        resultado.style.display = 'none';
    }
    reservaQrState = null;

    const linkInput = document.getElementById('qrReservaLink');
    const image = document.getElementById('qrReservaImagem');
    const canais = document.getElementById('qrReservaCanais');
    const expiracao = document.getElementById('qrReservaExpiracao');
    const anexoInput = document.getElementById('qrReservaAnexo');
    const anexoInfo = document.getElementById('qrReservaAnexoInfo');
    if (linkInput) linkInput.value = '';
    if (image) image.removeAttribute('src');
    if (canais) canais.textContent = '';
    if (expiracao) expiracao.textContent = '';
    if (anexoInput) anexoInput.value = '';
    if (anexoInfo) anexoInfo.textContent = 'Baixe o QR Code, selecione a imagem baixada e depois clique em "Enviar pelo WhatsApp".';

    const atendenteField = document.getElementById('qr_nome_atendente');
    if (atendenteField && !atendenteField.value.trim()) {
        atendenteField.value = '';
    }

    if (modalReservaQrInstance) {
        modalReservaQrInstance.show();
    }
}

async function confirmarReserva(reservaId) {
    // A mesa escolhida aqui vai na mensagem ao cliente, por isso mostra-se
    // qual e e deixa-se trocar antes de confirmar, em vez de o sistema
    // escolher sozinho sem ninguem ver.
    let reserva = null;
    let mesas = [];
    try {
        const rResp = await fetch(buildReservaUrl(String(reservaId)), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const rJson = await rResp.json();
        reserva = rJson && (rJson.reserva || rJson.data || rJson);
        if (reserva && reserva.data_reserva) {
            const dUrl = buildReservaUrl('disponibilidade', {
                data: reserva.data_reserva,
                hora: String(reserva.hora_reserva || '').slice(0, 5),
                quantidade: reserva.quantidade_pessoas || 2
            });
            const d = await (await fetch(dUrl, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })).json();
            mesas = (d && d.mesas_disponiveis) || [];
        }
    } catch (e) {
        reserva = null;
    }

    const mesaAtual = Number((reserva && reserva.mesa_atribuida) || 0);
    if (mesaAtual > 0 && !mesas.some(m => Number(m.id) === mesaAtual)) {
        // A mesa ja atribuida continua a ser uma escolha valida.
        mesas.unshift({ id: mesaAtual, numero: (reserva && reserva.mesa_numero) || mesaAtual, capacidade: '' });
    }

    // O conteudo vai como elemento, e nao como texto: o dialogo remove-o do
    // ecra ao fechar, mas a referencia mantem o valor escolhido.
    const zona = document.createElement('div');
    let campo = null;
    if (mesas.length) {
        const rot = document.createElement('label');
        rot.textContent = 'Mesa para esta reserva';
        rot.style.cssText = 'display:block;font-size:13px;font-weight:600;margin-bottom:6px;';
        campo = document.createElement('select');
        campo.className = 'nd-campo';
        campo.style.width = '100%';
        mesas.forEach(m => {
            const op = document.createElement('option');
            op.value = String(Number(m.id));
            op.textContent = 'Mesa ' + String(m.numero) + (m.capacidade ? ' (' + m.capacidade + ' lugares)' : '');
            if (Number(m.id) === mesaAtual) { op.selected = true; }
            campo.appendChild(op);
        });
        rot.setAttribute('for', 'nv-mesa-confirmar');
        campo.id = 'nv-mesa-confirmar';
        const nota = document.createElement('p');
        nota.textContent = 'O cliente recebe este numero na mensagem de confirmacao.';
        nota.style.cssText = 'font-size:12px;color:#64748b;margin:8px 0 0;line-height:1.5;';
        zona.appendChild(rot);
        zona.appendChild(campo);
        zona.appendChild(nota);
    } else {
        const aviso = document.createElement('p');
        aviso.textContent = 'Nao ha mesas disponiveis para esta hora. A confirmacao pode falhar.';
        aviso.style.cssText = 'font-size:13px;color:#b45309;margin:0;';
        zona.appendChild(aviso);
    }

    const ok = await nivoraConfirmar({
        titulo: 'Confirmar reserva',
        mensagem: 'O cliente vai receber a confirmacao com a mesa indicada.',
        conteudo: zona,
        confirmar: 'Confirmar reserva'
    });
    if (!ok) return;

    const mesaId = campo ? Number(campo.value || 0) : 0;
    executarAcaoReserva(reservaId + '/confirmar', mesaId > 0 ? { mesa_id: mesaId } : {}, 'Reserva confirmada com sucesso.');
}

async function cancelarReserva(reservaId) {
    if (!(await nivoraConfirmar({ mensagem: 'Cancelar esta reserva?', confirmar: 'Cancelar reserva', cancelar: 'Voltar', perigo: true }))) return;
    executarAcaoReserva(String(reservaId), {}, 'Reserva cancelada com sucesso.');
}

async function fazerCheckinReserva(reservaId, mesaId) {
    if (!mesaId || Number(mesaId) <= 0) {
        showFloatingNotice('Esta reserva ainda nao possui mesa atribuida.', 'warning');
        return;
    }

    if (!(await nivoraConfirmar({ mensagem: 'Confirmar chegada do cliente?\nA mesa fica ocupada e o pedido abre automaticamente.', confirmar: 'Confirmar chegada' }))) return;
    executarAcaoReserva(
        reservaId + '/checkin',
        { mesa_id: Number(mesaId) },
        'Check-in ok. A abrir o pedido da mesa…',
        {}
    );
}

function executarAcaoReserva(route, payload, successMessage, options) {
    const opts = options || {};
    fetch(buildReservaUrl(route), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': getReservaToken()
        },
        body: JSON.stringify(payload || {})
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showFloatingNotice(data.message || successMessage, 'success');
                const isCheckin = String(route).includes('/checkin');
                const mesaPedido = Number(data.mesa_atribuida || (payload && payload.mesa_id) || 0);
                setTimeout(() => {
                    // Check-in: a mesa fica ocupada e segue para o pedido dessa mesa (como a mensagem indica).
                    if (isCheckin && data.abrir_pedido && Number(data.pedido_id || 0) > 0) {
                        // O cliente ja fez o pedido pelo QR: mostrar esse pedido, nao abrir um novo.
                        window.location.href = 'pedidos.php?pedido=' + encodeURIComponent(Number(data.pedido_id));
                        return;
                    }
                    if (isCheckin && data.abrir_pedido && mesaPedido > 0) {
                        window.location.href = 'novo_pedido.php?mesa_id=' + encodeURIComponent(mesaPedido) + '&origem=reserva_checkin';
                        return;
                    }
                    location.reload();
                }, isCheckin ? 900 : 700);
            } else {
                showFloatingNotice(data.message || 'Nao foi possivel concluir a acao.', 'danger');
            }
        })
        .catch(() => showFloatingNotice('Erro ao comunicar com a API de reservas.', 'danger'));
}

function buildReservaUrl(route = '', query = {}) {
    const endpoint = window.RESERVAS_CONFIG?.endpoint || 'api/reservas.php';
    const params = new URLSearchParams();
    params.set('route', route);

    Object.entries(query || {}).forEach(([key, value]) => {
        if (value === undefined || value === null || value === '') {
            return;
        }

        params.set(key, String(value));
    });

    return endpoint + '?' + params.toString();
}

function getReservaToken() {
    return window.RESERVAS_CONFIG?.token || '';
}

function showFeedback(message, type, targetId = 'alertMesa') {
    const alertDiv = document.getElementById(targetId);
    if (!alertDiv) {
        showFloatingNotice(message, type);
        return;
    }

    alertDiv.textContent = message;
    alertDiv.className = 'alert alert-' + type;
    alertDiv.style.display = 'block';
}

function hideFeedback(targetId) {
    const alertDiv = document.getElementById(targetId);
    if (!alertDiv) return;
    alertDiv.style.display = 'none';
    alertDiv.textContent = '';
}

function showFloatingNotice(message, type) {
    let toast = document.getElementById('mesasFloatingNotice');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'mesasFloatingNotice';
        toast.style.position = 'fixed';
        toast.style.top = '24px';
        toast.style.right = '24px';
        toast.style.zIndex = '2000';
        toast.style.minWidth = '260px';
        document.body.appendChild(toast);
    }

    toast.className = 'alert alert-' + type;
    toast.textContent = message;
    toast.style.display = 'block';

    clearTimeout(toast._hideTimer);
    toast._hideTimer = setTimeout(() => {
        toast.style.display = 'none';
    }, 4000);
}

function atualizarDisponibilidadeReserva() {
    const dataField = document.getElementById('reserva_data');
    const horaField = document.getElementById('reserva_hora');
    const qtdField = document.getElementById('reserva_quantidade');
    const mesaField = document.getElementById('reserva_mesa_id');

    if (!dataField || !horaField || !qtdField || !mesaField) {
        return;
    }

    const data = dataField.value;
    const hora = horaField.value;
    const quantidade = Number(qtdField.value || 0);
    const mesaId = Number(mesaField.value || 0);
    const capacidadeMaxima = Number(qtdField.max || 0);

    if (capacidadeMaxima > 0 && quantidade > capacidadeMaxima) {
        setReservaPodeSubmeter(false);
        updateReservaAvailabilityHint('A quantidade de pessoas excede a capacidade da mesa selecionada.', 'warning');
        return;
    }

    if (!data || !hora || quantidade <= 0) {
        setReservaPodeSubmeter(true);
        updateReservaAvailabilityHint('Selecione data, hora e quantidade para ver a disponibilidade.', 'muted');
        return;
    }

    const requestId = ++reservaDisponibilidadeSeq;
    updateReservaAvailabilityHint('A verificar disponibilidade...', 'info');

    fetch(buildReservaUrl('disponibilidade', {
        data,
        hora,
        quantidade
    }), {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
        .then(response => response.json())
        .then(data => {
            if (requestId !== reservaDisponibilidadeSeq) {
                return;
            }

            const mesas = Array.isArray(data.mesas_disponiveis) ? data.mesas_disponiveis : [];
            if (!data.success) {
                setReservaPodeSubmeter(true);
                updateReservaAvailabilityHint('Nao foi possivel validar a disponibilidade agora.', 'warning');
                return;
            }

            if (mesas.length === 0) {
                setReservaPodeSubmeter(false);
                updateReservaAvailabilityHint('Nenhuma mesa disponivel para este horario e quantidade.', 'danger');
                return;
            }

            if (mesaId > 0) {
                const mesaSelecionada = mesas.find((mesa) => Number(mesa.id) === mesaId);
                if (!mesaSelecionada) {
                    setReservaPodeSubmeter(false);
                    updateReservaAvailabilityHint('A mesa escolhida nao esta disponivel neste horario. Escolha outra ou use atribuicao automatica.', 'warning');
                    return;
                }

                setReservaPodeSubmeter(true);
                updateReservaAvailabilityHint(
                    'Mesa ' + mesaSelecionada.numero + ' disponivel para ' + quantidade + ' pessoa(s).',
                    'success'
                );
                return;
            }

            const melhorMesa = mesas[0];
            setReservaPodeSubmeter(true);
            updateReservaAvailabilityHint(
                mesas.length + ' mesa(s) disponiveis. Melhor encaixe: Mesa ' + melhorMesa.numero + '.',
                'success'
            );
        })
        .catch(() => {
            if (requestId !== reservaDisponibilidadeSeq) {
                return;
            }

            setReservaPodeSubmeter(true);
            updateReservaAvailabilityHint('Nao foi possivel verificar a disponibilidade agora.', 'warning');
        });
}

function getReservaSubmitButton() {
    return document.getElementById('btnSalvarReserva');
}

function setReservaSubmitBusy(flag) {
    reservaSubmitBusy = !!flag;
    refreshReservaSubmitButton();
}

function setReservaPodeSubmeter(flag) {
    reservaPodeSubmeter = !!flag;
    refreshReservaSubmitButton();
}

function refreshReservaSubmitButton() {
    const button = getReservaSubmitButton();
    if (!button) {
        return;
    }

    button.disabled = reservaSubmitBusy || !reservaPodeSubmeter;
    button.innerHTML = reservaSubmitBusy
        ? '<i class="fas fa-spinner fa-spin me-2"></i>A guardar...'
        : '<i class="fas fa-save me-2"></i>Salvar Reserva';
}

function updateReservaAvailabilityHint(message, tone = 'muted') {
    const hint = document.getElementById('reserva_disponibilidade');
    if (!hint) {
        return;
    }

    const toneClass = {
        success: 'text-success',
        warning: 'text-warning',
        danger: 'text-danger',
        info: 'text-info',
        muted: 'text-muted'
    };

    hint.textContent = message;
    hint.className = 'form-text ' + (toneClass[tone] || 'text-muted');
}

function preencherResultadoReservaQr(data) {
    const box = document.getElementById('qrReservaResultado');
    const linkInput = document.getElementById('qrReservaLink');
    const image = document.getElementById('qrReservaImagem');
    const openBtn = document.getElementById('btnAbrirReservaQr');
    const downloadBtn = document.getElementById('btnDownloadReservaQr');
    const btnEmail = document.getElementById('btnEnviarReservaQrEmail');
    const btnWhatsapp = document.getElementById('btnEnviarReservaQrWhatsApp');
    const expiracao = document.getElementById('qrReservaExpiracao');
    const canais = document.getElementById('qrReservaCanais');
    const anexoInfo = document.getElementById('qrReservaAnexoInfo');
    const referencia = data.link || data.codigo || '';
    const imageUrl = data.localImageDataUrl || data.image_url || '';

    if (linkInput) {
        linkInput.value = referencia;
    }

    if (openBtn) {
        openBtn.href = data.link || '#';
        openBtn.style.display = data.link ? '' : 'none';
    }

    if (image && imageUrl) {
        image.src = imageUrl;
    }

    if (downloadBtn) {
        downloadBtn.href = imageUrl || data.download_url || '#';
        downloadBtn.download = data.download_name || 'qr-reserva.png';
        downloadBtn.style.display = imageUrl || data.download_url ? '' : 'none';
    }

    if (btnEmail) {
        btnEmail.style.display = data.email ? '' : 'none';
    }

    if (btnWhatsapp) {
        btnWhatsapp.style.display = data.telefone ? '' : 'none';
        btnWhatsapp.disabled = !(data.downloaded && data.selectedAttachmentFile);
    }

    if (expiracao) {
        const extras = [];
        if (data.expires_at) {
            extras.push(
                reservaQrEstaExpirado(data)
                    ? 'Sessao expirada. Gere um novo QR Code.'
                    : 'Sessao valida ate ' + reservaQrExpiracaoLocal(data.expires_at) + ' (hora local).'
            );
        }
        if (data.data_hora_label && !data.expires_at) {
            extras.push(data.data_hora_label);
        }
        expiracao.textContent = extras.join(' ');
    }

    if (canais) {
        const notices = [];
        if (data.telefone && !data.downloaded) {
            notices.push('Baixe o QR Code antes de preparar o envio pelo WhatsApp.');
        }
        if (data.telefone && data.downloaded && !data.selectedAttachmentFile) {
            notices.push('Selecione no formulario a imagem do QR Code que acabou de baixar.');
        }
        if (!data.telefone) {
            notices.push('Informe um telefone do cliente para habilitar o WhatsApp.');
        }
        if (!data.email) {
            notices.push('Informe um e-mail do cliente para habilitar o envio por e-mail.');
        }
        canais.textContent = notices.join(' ');
    }

    if (anexoInfo) {
        if (data.selectedAttachmentName) {
            anexoInfo.textContent = 'Imagem selecionada: ' + data.selectedAttachmentName + '. Em navegadores compativeis, o compartilhamento inclui essa imagem; no WhatsApp Web, anexe na propria conversa se necessario.';
        } else if (data.downloaded) {
            anexoInfo.textContent = 'Agora selecione no formulario a imagem do QR Code que voce baixou.';
        } else {
            anexoInfo.textContent = 'Baixe o QR Code, selecione a imagem baixada e depois clique em "Enviar pelo WhatsApp".';
        }
    }

    if (box) {
        box.style.display = 'block';
    }
}
function copiarLinkReservaQr() {
    const input = document.getElementById('qrReservaLink');
    if (!input || !input.value) {
        showFloatingNotice('Nenhuma referencia de QR disponivel.', 'warning');
        return;
    }

    navigator.clipboard.writeText(input.value)
        .then(() => showFloatingNotice('Referencia copiada com sucesso.', 'success'))
        .catch(() => showFloatingNotice('Nao foi possivel copiar a referencia.', 'danger'));
}

function gerarQrReservaPngDataUrl(texto, dados = {}) {
    if (typeof qrcode !== 'function') {
        throw new Error('Biblioteca de QR Code indisponivel.');
    }

    const qr = qrcode(0, 'M');
    qr.addData(String(texto || ''));
    qr.make();

    const modules = qr.getModuleCount();
    const margin = 4;
    const totalModules = modules + (margin * 2);
    const qrArea = 560;
    const scale = Math.max(5, Math.floor(qrArea / totalModules));
    const qrSize = totalModules * scale;
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    const width = 1080;
    const height = 1640;
    const cardX = 48;
    const cardY = 48;
    const cardW = width - 96;
    const cardH = height - 96;
    const restauranteNome = String(dados.restaurante_nome || 'Restaurante').trim();
    const nomeCliente = String(dados.nome_cliente || 'Cliente').trim();
    const mesaLabel = String(dados.mesa_label || 'Melhor mesa disponivel').trim();
    const codigo = String(dados.codigo || '').trim();
    const validade = dados.expires_at ? reservaQrExpiracaoLocal(dados.expires_at) : '';

    const textoCurto = function (valor, limite) {
        const normalizado = String(valor || '').replace(/\s+/g, ' ').trim();
        return normalizado.length > limite ? normalizado.slice(0, Math.max(1, limite - 3)) + '...' : normalizado;
    };

    const textoLinhas = function (valor, limitePorLinha, maxLinhas) {
        const palavras = String(valor || '').replace(/\s+/g, ' ').trim().split(' ').filter(Boolean);
        const linhas = [];
        let linha = '';

        palavras.forEach(function (palavra) {
            const tentativa = linha ? linha + ' ' + palavra : palavra;
            if (tentativa.length <= limitePorLinha) {
                linha = tentativa;
                return;
            }
            if (linha) {
                linhas.push(linha);
            }
            linha = palavra;
        });

        if (linha) {
            linhas.push(linha);
        }

        if (linhas.length > maxLinhas) {
            const finais = linhas.slice(0, maxLinhas);
            finais[maxLinhas - 1] = textoCurto(finais[maxLinhas - 1] + ' ' + linhas.slice(maxLinhas).join(' '), limitePorLinha);
            return finais;
        }

        return linhas.length ? linhas : ['-'];
    };

    const roundRect = function (x, y, w, h, r) {
        const radius = Math.min(r, w / 2, h / 2);
        ctx.beginPath();
        ctx.moveTo(x + radius, y);
        ctx.arcTo(x + w, y, x + w, y + h, radius);
        ctx.arcTo(x + w, y + h, x, y + h, radius);
        ctx.arcTo(x, y + h, x, y, radius);
        ctx.arcTo(x, y, x + w, y, radius);
        ctx.closePath();
    };

    const drawCorner = function (x, y, size, dx, dy) {
        ctx.strokeStyle = '#ea580c';
        ctx.lineWidth = 6;
        ctx.beginPath();
        ctx.moveTo(x, y + (dy * size));
        ctx.lineTo(x, y);
        ctx.lineTo(x + (dx * size), y);
        ctx.stroke();
    };

    canvas.width = width;
    canvas.height = height;

    ctx.fillStyle = '#f4efe8';
    ctx.fillRect(0, 0, width, height);

    roundRect(cardX, cardY, cardW, cardH, 28);
    ctx.fillStyle = '#ffffff';
    ctx.fill();
    ctx.strokeStyle = '#e7d5c4';
    ctx.lineWidth = 2;
    ctx.stroke();

    // Cabecalho da marca. O token nunca e escrito como texto na imagem.
    ctx.save();
    roundRect(cardX, cardY, cardW, 228, 28);
    ctx.clip();
    ctx.fillStyle = '#3b2416';
    ctx.fillRect(cardX, cardY, cardW, 228);
    ctx.restore();
    ctx.fillStyle = '#3b2416';
    ctx.fillRect(cardX, cardY + 28, cardW, 200);
    ctx.fillStyle = '#ea580c';
    ctx.fillRect(cardX, cardY + 228, cardW, 8);

    ctx.textAlign = 'center';
    ctx.fillStyle = '#fdba74';
    ctx.font = '700 22px Arial, sans-serif';
    ctx.fillText('NIVORA RESTAURANT', width / 2, cardY + 52);
    ctx.fillStyle = '#ffffff';
    ctx.font = '700 44px Arial, sans-serif';
    ctx.fillText(textoCurto(restauranteNome, 30), width / 2, cardY + 108, cardW - 80);

    roundRect(width / 2 - 196, cardY + 128, 392, 42, 21);
    ctx.fillStyle = '#ea580c';
    ctx.fill();
    ctx.fillStyle = '#ffffff';
    ctx.font = '800 20px Arial, sans-serif';
    ctx.fillText('RESERVA  ·  CHECK-IN', width / 2, cardY + 157);

    ctx.fillStyle = 'rgba(255,255,255,0.72)';
    ctx.font = '600 18px Arial, sans-serif';
    ctx.fillText('Acesso temporario e protegido', width / 2, cardY + 206);

    const qrPad = 36;
    const qrFrame = qrSize + (qrPad * 2);
    const qrFrameX = Math.round((width - qrFrame) / 2);
    const qrFrameY = cardY + 268;
    const qrX = qrFrameX + qrPad;
    const qrY = qrFrameY + qrPad;

    roundRect(qrFrameX, qrFrameY, qrFrame, qrFrame, 22);
    ctx.fillStyle = '#fffaf5';
    ctx.fill();
    ctx.strokeStyle = '#f3d5b5';
    ctx.lineWidth = 2;
    ctx.stroke();

    ctx.fillStyle = '#111111';
    for (let row = 0; row < modules; row += 1) {
        for (let col = 0; col < modules; col += 1) {
            if (!qr.isDark(row, col)) {
                continue;
            }
            ctx.fillRect(qrX + ((col + margin) * scale), qrY + ((row + margin) * scale), scale, scale);
        }
    }

    const corner = 28;
    drawCorner(qrFrameX + 18, qrFrameY + 18, corner, 1, 1);
    drawCorner(qrFrameX + qrFrame - 18, qrFrameY + 18, corner, -1, 1);
    drawCorner(qrFrameX + 18, qrFrameY + qrFrame - 18, corner, 1, -1);
    drawCorner(qrFrameX + qrFrame - 18, qrFrameY + qrFrame - 18, corner, -1, -1);

    const contentX = cardX + 56;
    const contentW = cardW - 112;
    const clienteY = qrFrameY + qrFrame + 54;
    const nomeLinhas = textoLinhas(nomeCliente, 32, 2);

    ctx.textAlign = 'left';
    ctx.fillStyle = '#c2410c';
    ctx.font = '800 16px Arial, sans-serif';
    ctx.fillText('CLIENTE CONFIRMADO', contentX, clienteY);
    ctx.fillStyle = '#1c1917';
    ctx.font = '800 38px Arial, sans-serif';
    nomeLinhas.forEach(function (linha, indice) {
        ctx.fillText(linha, contentX, clienteY + 48 + (indice * 44), contentW);
    });

    const splitY = clienteY + 70 + (nomeLinhas.length * 44);
    const colGap = 18;
    const colW = Math.floor((contentW - colGap) / 2);
    const colH = 118;

    roundRect(contentX, splitY, colW, colH, 18);
    ctx.fillStyle = '#fff7ed';
    ctx.fill();
    ctx.strokeStyle = '#fed7aa';
    ctx.lineWidth = 2;
    ctx.stroke();
    ctx.fillStyle = '#9a3412';
    ctx.font = '800 14px Arial, sans-serif';
    ctx.fillText('MESA', contentX + 24, splitY + 36);
    ctx.fillStyle = '#7c2d12';
    ctx.font = '800 26px Arial, sans-serif';
    ctx.fillText(textoCurto(mesaLabel, 22), contentX + 24, splitY + 78, colW - 48);

    roundRect(contentX + colW + colGap, splitY, colW, colH, 18);
    ctx.fillStyle = '#fff7ed';
    ctx.fill();
    ctx.strokeStyle = '#fed7aa';
    ctx.lineWidth = 2;
    ctx.stroke();
    ctx.fillStyle = '#9a3412';
    ctx.font = '800 14px Arial, sans-serif';
    ctx.fillText('VALIDO ATE', contentX + colW + colGap + 24, splitY + 36);
    ctx.fillStyle = '#7c2d12';
    ctx.font = '800 22px Arial, sans-serif';
    ctx.fillText(textoCurto(validade || 'Indicada no acesso', 24), contentX + colW + colGap + 24, splitY + 78, colW - 48);

    const actionY = splitY + colH + 22;
    roundRect(contentX, actionY, contentW, 86, 18);
    ctx.fillStyle = '#3b2416';
    ctx.fill();
    ctx.fillStyle = '#ffffff';
    ctx.font = '700 22px Arial, sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText('Apresente este QR na recepcao para continuar.', width / 2, actionY + 52, contentW - 40);

    ctx.textAlign = 'left';
    ctx.fillStyle = '#a8a29e';
    ctx.font = '600 17px Arial, sans-serif';
    ctx.fillText('Horario local do restaurante', contentX, actionY + 128, 420);
    if (codigo) {
        ctx.textAlign = 'right';
        ctx.fillText('Referencia ' + codigo, contentX + contentW, actionY + 128, 420);
    }

    ctx.textAlign = 'center';
    ctx.fillStyle = '#c2410c';
    ctx.font = '700 17px Arial, sans-serif';
    ctx.fillText('Nao compartilhe publicamente.', width / 2, actionY + 168, contentW);

    return canvas.toDataURL('image/png');
}
function slugifyArquivo(texto) {
    return String(texto || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-zA-Z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .toLowerCase() || 'mesa';
}

function gerarQrMesaPngDataUrl(link, numeroMesa, size = 512) {
    if (typeof qrcode !== 'function') {
        throw new Error('Biblioteca de QR Code indisponivel.');
    }

    const qr = qrcode(0, 'M');
    qr.addData(String(link || ''));
    qr.make();

    const modules = qr.getModuleCount();
    const margin = 4;
    const totalModules = modules + (margin * 2);
    const scale = Math.max(4, Math.floor(size / totalModules));
    const qrSize = totalModules * scale;
    const header = 72;
    const footer = 88;
    const padX = 36;
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    const restauranteNome = (window.RESERVAS_CONFIG && window.RESERVAS_CONFIG.restauranteNome) || '';
    const mesaLabel = 'Mesa ' + String(numeroMesa || '').trim();

    canvas.width = qrSize + (padX * 2);
    canvas.height = qrSize + header + footer;

    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    ctx.fillStyle = '#111827';
    ctx.font = '700 22px Arial, sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText(mesaLabel, canvas.width / 2, 40, canvas.width - 24);

    if (restauranteNome) {
        ctx.fillStyle = '#6b7280';
        ctx.font = '600 14px Arial, sans-serif';
        ctx.fillText(String(restauranteNome).slice(0, 42), canvas.width / 2, 62, canvas.width - 24);
    }

    ctx.fillStyle = '#111111';
    for (let row = 0; row < modules; row += 1) {
        for (let col = 0; col < modules; col += 1) {
            if (!qr.isDark(row, col)) {
                continue;
            }
            const x = padX + ((col + margin) * scale);
            const y = header + ((row + margin) * scale);
            ctx.fillRect(x, y, scale, scale);
        }
    }

    ctx.fillStyle = '#6b7280';
    ctx.font = '500 13px Arial, sans-serif';
    ctx.fillText('Escaneie para ver o cardápio', canvas.width / 2, header + qrSize + 36, canvas.width - 24);

    return canvas.toDataURL('image/png');
}

function baixarDataUrlComoArquivo(dataUrl, nomeArquivo) {
    const a = document.createElement('a');
    a.href = dataUrl;
    a.download = nomeArquivo;
    document.body.appendChild(a);
    a.click();
    a.remove();
}

function baixarQrMesa(btnOrLink, numeroOpcional) {
    let link = '';
    let numero = '';

    if (btnOrLink && typeof btnOrLink === 'object' && btnOrLink.dataset) {
        link = btnOrLink.dataset.mesaLink || '';
        numero = btnOrLink.dataset.mesaNumero || '';
    } else {
        link = String(btnOrLink || '');
        numero = String(numeroOpcional || '');
    }

    if (!link) {
        showFloatingNotice('Link do QR Code indisponivel.', 'warning');
        return;
    }

    try {
        const dataUrl = gerarQrMesaPngDataUrl(link, numero, 512);
        const nome = 'qr-mesa-' + slugifyArquivo(numero) + '.png';
        baixarDataUrlComoArquivo(dataUrl, nome);
        showFloatingNotice('QR Code da Mesa ' + numero + ' baixado.', 'success');
    } catch (error) {
        showFloatingNotice(error.message || 'Nao foi possivel gerar o QR Code.', 'danger');
    }
}

async function baixarTodosQrMesas() {
    const botoes = Array.from(document.querySelectorAll('.js-baixar-qr-mesa'));
    if (!botoes.length) {
        showFloatingNotice('Nenhuma mesa com QR Code disponivel.', 'warning');
        return;
    }

    showFloatingNotice('A baixar ' + botoes.length + ' QR Codes...', 'success');

    for (let i = 0; i < botoes.length; i += 1) {
        const btn = botoes[i];
        try {
            const link = btn.dataset.mesaLink || '';
            const numero = btn.dataset.mesaNumero || String(i + 1);
            if (!link) {
                continue;
            }
            const dataUrl = gerarQrMesaPngDataUrl(link, numero, 512);
            baixarDataUrlComoArquivo(dataUrl, 'qr-mesa-' + slugifyArquivo(numero) + '.png');
            await new Promise(function (resolve) {
                setTimeout(resolve, 350);
            });
        } catch (error) {
            showFloatingNotice(error.message || 'Falha ao baixar um dos QR Codes.', 'danger');
            return;
        }
    }

    showFloatingNotice('Download dos QR Codes concluido.', 'success');
}

function normalizarTelefoneReservaQr(telefone) {
    let digits = String(telefone || '').replace(/\D+/g, '');

    if (digits.startsWith('00')) {
        digits = digits.slice(2);
    }

    if (digits.length === 9) {
        digits = '258' + digits;
    }

    return digits.length >= 10 ? digits : '';
}

function registrarDownloadReservaQr() {
    if (!reservaQrState) {
        return true;
    }

    reservaQrState.downloaded = true;
    preencherResultadoReservaQr(reservaQrState);
    showFeedback('QR Code baixado. Agora selecione a imagem no formulario e depois envie pelo WhatsApp.', 'success', 'alertReservaQr');
    return true;
}

function atualizarAnexoReservaQr() {
    const input = document.getElementById('qrReservaAnexo');
    if (!input || !reservaQrState) {
        return;
    }

    const file = input.files && input.files[0] ? input.files[0] : null;
    if (!file) {
        reservaQrState.selectedAttachmentFile = null;
        reservaQrState.selectedAttachmentName = '';
        preencherResultadoReservaQr(reservaQrState);
        return;
    }

    const mime = String(file.type || '').toLowerCase();
    const valido = mime === 'image/png' || mime === 'image/jpeg' || /\.(png|jpe?g)$/i.test(file.name || '');
    if (!valido) {
        input.value = '';
        reservaQrState.selectedAttachmentFile = null;
        reservaQrState.selectedAttachmentName = '';
        preencherResultadoReservaQr(reservaQrState);
        showFeedback('Selecione uma imagem PNG ou JPG do QR Code.', 'warning', 'alertReservaQr');
        return;
    }

    reservaQrState.selectedAttachmentFile = file;
    reservaQrState.selectedAttachmentName = file.name || 'qr-code.png';
    preencherResultadoReservaQr(reservaQrState);
    showFeedback('Imagem do QR Code selecionada. Agora clique em "Enviar pelo WhatsApp".', 'success', 'alertReservaQr');
}

async function enviarReservaQrEmail() {
    if (!reservaQrState) {
        showFloatingNotice('Gere o QR Code antes de enviar por e-mail.', 'warning');
        return;
    }

    if (!exigirReservaQrValido()) {
        return;
    }

    const email = String(reservaQrState.email || reservaQrState.basePayload?.email_cliente || '').trim();
    if (!email) {
        showFeedback('Informe o e-mail do cliente para enviar o QR Code.', 'warning', 'alertReservaQr');
        return;
    }

    const payload = {
        sessao_id: reservaQrState.sessao_id || null,
        reserva_id: reservaQrState.reserva_id || null,
        nome_cliente: reservaQrState.basePayload?.nome_cliente || '',
        telefone_cliente: reservaQrState.basePayload?.telefone_cliente || '',
        email_cliente: email,
        observacoes: reservaQrState.basePayload?.observacoes || '',
        link: reservaQrState.link || '',
        token: extrairTokenReservaQr(reservaQrState.link || ''),
        enviar_whatsapp: false,
        enviar_email: true,
        qr_image_data: reservaQrState.localImageDataUrl || '',
        _csrf: getReservaToken()
    };

    try {
        const response = await fetch('api/reservas_qr_enviar.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': getReservaToken()
            },
            body: JSON.stringify(payload)
        });
        const data = await parseJsonResponse(response);
        const emailDelivery = data.delivery && data.delivery.email ? data.delivery.email : null;
        const warningText = Array.isArray(data.warnings) && data.warnings.length
            ? ' Detalhe: ' + data.warnings.join(' ')
            : '';

        if (emailDelivery && emailDelivery.attempted && !emailDelivery.sent) {
            showFeedback(
                (emailDelivery.error || data.message || 'QR Code gerado, mas o e-mail nao foi enviado.') + warningText,
                'warning',
                'alertReservaQr'
            );
            return;
        }

        showFeedback((data.message || 'QR Code enviado por e-mail com sucesso.') + warningText, 'success', 'alertReservaQr');
    } catch (error) {
        if (error.code === 'QR_EXPIRED' || error.code === 'QR_REVOKED') {
            preencherResultadoReservaQr(reservaQrState);
        }
        showFeedback(error.message || 'Falha ao enviar o QR Code por e-mail.', 'danger', 'alertReservaQr');
    }
}

function extrairTokenReservaQr(link) {
    try {
        const url = new URL(String(link || ''), window.location.href);
        return url.searchParams.get('token') || '';
    } catch (error) {
        const match = String(link || '').match(/[?&]token=([^&#]+)/);
        return match ? decodeURIComponent(match[1].replace(/\+/g, ' ')) : '';
    }
}

async function enviarReservaQrWhatsAppManual() {
    if (!reservaQrState) {
        showFloatingNotice('Gere o QR Code antes de enviar.', 'warning');
        return;
    }

    if (!exigirReservaQrValido()) {
        return;
    }

    if (!reservaQrState.downloaded) {
        showFeedback('Baixe o QR Code primeiro para depois enviar pelo WhatsApp.', 'warning', 'alertReservaQr');
        return;
    }

    if (!reservaQrState.selectedAttachmentFile) {
        showFeedback('Selecione no formulario a imagem do QR Code baixada antes de abrir o WhatsApp.', 'warning', 'alertReservaQr');
        return;
    }

    const telefone = normalizarTelefoneReservaQr(reservaQrState.telefone || reservaQrState.basePayload?.telefone_cliente || '');
    if (!telefone) {
        showFeedback('Telefone do cliente invalido para abrir o WhatsApp.', 'warning', 'alertReservaQr');
        return;
    }

    const nomeCliente = reservaQrState.basePayload?.nome_cliente || 'cliente';
    const restauranteNome = window.RESERVAS_CONFIG?.restauranteNome || 'restaurante';
    const mesaLabel = reservaQrState.mesa_label || 'Melhor mesa disponivel';
    const validade = reservaQrState.expires_at ? reservaQrExpiracaoLocal(reservaQrState.expires_at) : '';
    const linhas = [
        'Ola ' + nomeCliente + ',',
        'segue o QR Code da sua reserva no ' + restauranteNome + '.',
        'Mesa: ' + mesaLabel,
        validade ? 'Valido ate: ' + validade + ' (hora local)' : '',
        'Apresente ou escaneie o QR Code para continuar com a reserva/check-in.',
        reservaQrState.link ? 'Link da reserva: ' + reservaQrState.link : '',
        'Estou a enviar a imagem do QR Code da sua reserva.'
    ].filter(Boolean);

    const mensagem = linhas.join('\n');
    const arquivo = reservaQrState.selectedAttachmentFile || null;

    if (arquivo && navigator.share && navigator.canShare) {
        try {
            const shareData = {
                title: 'QR Code da reserva',
                text: mensagem,
                files: [arquivo]
            };

            if (navigator.canShare(shareData)) {
                await navigator.share(shareData);
                showFeedback('Compartilhamento aberto com a imagem do QR Code. Selecione o WhatsApp para concluir o envio.', 'success', 'alertReservaQr');
                return;
            }
        } catch (error) {
            if (error && error.name === 'AbortError') {
                showFeedback('Compartilhamento cancelado.', 'warning', 'alertReservaQr');
                return;
            }
        }
    }

    const url = 'https://wa.me/' + telefone + '?text=' + encodeURIComponent(mensagem);
    window.open(url, '_blank', 'noopener');
    showFeedback('WhatsApp aberto com a mensagem pronta. Neste navegador, a imagem selecionada nao pode ser anexada automaticamente no WhatsApp Web; use o botao de anexo da propria conversa.', 'warning', 'alertReservaQr');
}

function enviarQrReservaWhatsApp(sessaoId, reservaId) {
    abrirModalReservaQr();
    if (sessaoId || reservaId) {
        showFeedback('Gere o QR, baixe a imagem, selecione o arquivo no formulario e depois abra o WhatsApp.', 'warning', 'alertReservaQr');
    }
}

/* Escapa texto vindo da base de dados antes de entrar em HTML.
   Nomes de clientes sao escritos por pessoas e podem conter < ou &. */
function esperaEscapar(valor) {
    return String(valor === null || valor === undefined ? '' : valor)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

/* ===================== Lista de espera =====================
   Quem ficou sem mesa fica aqui. Quando uma reserva e cancelada, o servidor
   marca o primeiro da fila como avisado e diz quem e na resposta. */

function esperaEstadoRotulo(estado) {
    switch (String(estado || '').toUpperCase()) {
        case 'AGUARDA': return { txt: 'Na fila', cls: 'badge-warning' };
        case 'AVISADO': return { txt: 'Avisado', cls: 'badge-info' };
        case 'CONVERTIDA': return { txt: 'Virou reserva', cls: 'badge-success' };
        case 'CANCELADA': return { txt: 'Saiu da fila', cls: 'badge-secondary' };
        default: return { txt: String(estado || '-'), cls: 'badge-secondary' };
    }
}

async function carregarListaEspera() {
    const corpo = document.getElementById('mesas-espera-linhas');
    const resumo = document.getElementById('mesas-espera-resumo');
    if (!corpo) return;

    try {
        const r = await fetch(buildReservaUrl('espera'), {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const d = await r.json();
        const fila = (d && d.fila) || [];

        if (resumo) {
            resumo.textContent = fila.length === 0
                ? 'ninguem a espera'
                : (fila.length === 1 ? '1 pessoa a espera' : fila.length + ' pessoas a espera');
        }

        if (!fila.length) {
            corpo.innerHTML = '<tr><td colspan="5" class="text-muted">Ninguem na lista de espera.</td></tr>';
            return;
        }

        corpo.innerHTML = fila.map(e => {
            const est = esperaEstadoRotulo(e.status);
            const hora = String(e.hora_reserva || '').slice(0, 5);
            const tel = e.telefone_cliente ? esperaEscapar(String(e.telefone_cliente)) : 'Sem telefone';
            return '<tr>'
                + '<td><strong>' + esperaEscapar(String(e.data_reserva || '')) + '</strong><span class="text-muted ms-1">' + esperaEscapar(hora) + '</span></td>'
                + '<td><strong>' + esperaEscapar(String(e.nome_cliente || '')) + '</strong><div class="small text-muted">' + tel + '</div></td>'
                + '<td>' + Number(e.quantidade_pessoas || 0) + '</td>'
                + '<td><span class="badge-custom ' + est.cls + '">' + est.txt + '</span></td>'
                + '<td class="text-end">'
                + '<button class="btn btn-success btn-action btn-sm" onclick="converterEspera(' + Number(e.id) + ')" title="Criar a reserva para este cliente"><i class="fas fa-calendar-check"></i></button> '
                + '<button class="btn btn-outline-danger btn-action btn-sm" onclick="retirarDaEspera(' + Number(e.id) + ')" title="Retirar da fila"><i class="fas fa-xmark"></i></button>'
                + '</td></tr>';
        }).join('');
    } catch (e) {
        corpo.innerHTML = '<tr><td colspan="5" class="text-danger">Nao foi possivel carregar a lista de espera.</td></tr>';
        if (resumo) resumo.textContent = '';
    }
}

async function abrirNovaEspera() {
    const zona = document.createElement('div');
    zona.innerHTML = '<div style="display:grid;gap:10px;">'
        + '<label style="font-size:13px;font-weight:600;">Nome<input id="esp-nome" class="nd-campo" style="width:100%;" maxlength="120"></label>'
        + '<label style="font-size:13px;font-weight:600;">Telefone<input id="esp-tel" class="nd-campo" style="width:100%;" maxlength="40"></label>'
        + '<label style="font-size:13px;font-weight:600;">Email (para o avisarmos se abrir mesa)<input id="esp-email" type="email" class="nd-campo" style="width:100%;" maxlength="180"></label>'
        + '<label style="font-size:13px;font-weight:600;">Dia<input id="esp-data" type="date" class="nd-campo" style="width:100%;"></label>'
        + '<label style="font-size:13px;font-weight:600;">Hora<input id="esp-hora" type="time" class="nd-campo" style="width:100%;"></label>'
        + '<label style="font-size:13px;font-weight:600;">Pessoas<input id="esp-qtd" type="number" min="1" max="50" value="2" class="nd-campo" style="width:100%;"></label>'
        + '</div>';

    const ok = await nivoraConfirmar({
        titulo: 'Juntar a lista de espera',
        mensagem: 'Guardamos o pedido. Se uma reserva for cancelada, este cliente e o proximo a ser avisado.',
        conteudo: zona,
        confirmar: 'Juntar a fila'
    });
    if (!ok) return;

    const val = id => {
        const el = zona.querySelector('#' + id);
        return el ? String(el.value || '').trim() : '';
    };

    const dados = {
        nome_cliente: val('esp-nome'),
        telefone_cliente: val('esp-tel'),
        email_cliente: val('esp-email'),
        data_reserva: val('esp-data'),
        hora_reserva: val('esp-hora'),
        quantidade_pessoas: Number(val('esp-qtd') || 0)
    };

    if (!dados.nome_cliente || !dados.data_reserva || !dados.hora_reserva || !dados.quantidade_pessoas) {
        showFloatingNotice('Preencha nome, dia, hora e numero de pessoas.', 'warning');
        return;
    }

    try {
        const r = await fetch(buildReservaUrl('espera'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': getReservaToken()
            },
            body: JSON.stringify(dados)
        });
        const d = await r.json();
        showFloatingNotice(d.message || 'Pedido registado.', d.success ? 'success' : 'warning');
        if (d.success) carregarListaEspera();
    } catch (e) {
        showFloatingNotice('Nao foi possivel juntar a fila.', 'error');
    }
}

async function converterEspera(esperaId) {
    if (!(await nivoraConfirmar('Criar a reserva para este cliente?'))) return;
    await accaoEspera(esperaId + '/converter', 'Reserva criada a partir da lista de espera.');
}

async function retirarDaEspera(esperaId) {
    if (!(await nivoraConfirmar({ mensagem: 'Retirar este cliente da fila?', confirmar: 'Retirar', perigo: true }))) return;
    await accaoEspera(esperaId + '/cancelar', 'Retirado da lista de espera.');
}

async function accaoEspera(rota, mensagemOk) {
    try {
        const r = await fetch(buildReservaUrl('espera/' + rota), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': getReservaToken()
            },
            body: '{}'
        });
        const d = await r.json();
        showFloatingNotice(d.message || mensagemOk, d.success ? 'success' : 'warning');
        if (d.success) {
            carregarListaEspera();
            if (String(rota).includes('/converter')) {
                setTimeout(() => window.location.reload(), 1200);
            }
        }
    } catch (e) {
        showFloatingNotice('A operacao falhou.', 'error');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('mesas-espera-card')) {
        carregarListaEspera();
    }
});
