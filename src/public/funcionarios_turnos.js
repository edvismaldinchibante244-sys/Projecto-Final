let turnosCache = [];
let ativosHojeCache = [];
let auditoriaCache = [];
let editarTurnoModal = null;
let turnosTodos = [];
let encerrarModal = null;
let encerrarResolver = null;

function hojeIso() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function filtrosHistorico() {
    const v = (id) => (document.getElementById(id)?.value || '').trim();
    return { funcionario: v('filtroFuncionario'), perfil: v('filtroPerfil'), de: v('filtroDe'), ate: v('filtroAte') };
}

function aplicarFiltrosHistorico(lista) {
    const f = filtrosHistorico();
    return lista.filter((t) => {
        if (f.funcionario && String(t.usuario_id) !== f.funcionario) { return false; }
        if (f.perfil && String(t.cargo || t.funcionario_perfil || '').toUpperCase() !== f.perfil) { return false; }
        if (f.de && String(t.data || '') < f.de) { return false; }
        if (f.ate && String(t.data || '') > f.ate) { return false; }
        return true;
    });
}

function descricaoFiltros() {
    const partes = [];
    const sel = document.getElementById('filtroData');
    if (sel && sel.value) { partes.push('Período: ' + sel.options[sel.selectedIndex].text); }
    const f = filtrosHistorico();
    const fn = document.getElementById('filtroFuncionario');
    if (f.funcionario && fn) { partes.push('Funcionário: ' + fn.options[fn.selectedIndex].text); }
    const fp = document.getElementById('filtroPerfil');
    if (f.perfil && fp) { partes.push('Perfil: ' + fp.options[fp.selectedIndex].text); }
    if (f.de) { partes.push('De: ' + f.de); }
    if (f.ate) { partes.push('Até: ' + f.ate); }
    return partes.length ? partes.join(' | ') : 'Todos os turnos';
}

function pedirMotivoEncerrar(nome) {
    const el = document.getElementById('encerrarTurnoModal');
    if (!el || !window.bootstrap) {
        return Promise.resolve(null);
    }
    encerrarModal = encerrarModal || new bootstrap.Modal(el);
    const campo = document.getElementById('encerrarTurnoMotivo');
    const quem = document.getElementById('encerrarTurnoNome');
    if (campo) { campo.value = ''; campo.classList.remove('is-invalid'); }
    if (quem) { quem.textContent = nome || 'este funcionário'; }
    return new Promise((resolve) => {
        encerrarResolver = resolve;
        encerrarModal.show();
        setTimeout(() => campo?.focus(), 300);
    });
}

function getCsrfToken() {
    return (typeof CSRF_TOKEN !== 'undefined' && CSRF_TOKEN) ? CSRF_TOKEN : '';
}

function csrfHeaders(extraHeaders = {}) {
    const headers = new Headers(extraHeaders);
    const token = getCsrfToken();
    if (token) {
        headers.set('X-CSRF-Token', token);
    }
    headers.set('X-Requested-With', 'XMLHttpRequest');
    return headers;
}

document.addEventListener('DOMContentLoaded', () => {
    const formNovoTurno = document.getElementById('formNovoTurno');
    const formEditarTurno = document.getElementById('formEditarTurno');
    const filtroData = document.getElementById('filtroData');
    const modalEl = document.getElementById('editarTurnoModal');
    const novoTurnoTipo = document.getElementById('novoTurnoTipo');
    const novoTurnoHoraEntrada = document.getElementById('novoTurnoHoraEntrada');
    const editarTurnoTipo = document.getElementById('editarTurnoTipo');
    const editarTurnoHoraEntrada = document.getElementById('editarTurnoHoraEntrada');

    if (modalEl && window.bootstrap) {
        editarTurnoModal = new bootstrap.Modal(modalEl);
    }

    aplicarHoraPadrao(novoTurnoTipo, novoTurnoHoraEntrada, true);
    aplicarHoraPadrao(editarTurnoTipo, editarTurnoHoraEntrada, false);

    novoTurnoTipo?.addEventListener('change', () => aplicarHoraPadrao(novoTurnoTipo, novoTurnoHoraEntrada, false));
    editarTurnoTipo?.addEventListener('change', () => aplicarHoraPadrao(editarTurnoTipo, editarTurnoHoraEntrada, false));

    formNovoTurno?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btnSubmit = e.target.querySelector('button[type="submit"]');
        const originalHtml = btnSubmit ? btnSubmit.innerHTML : '';
        const formData = new FormData(e.target);

        if (btnSubmit) {
            btnSubmit.disabled = true;
            btnSubmit.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        }

        try {
            if (getCsrfToken()) {
                formData.append('_csrf', getCsrfToken());
            }
            const response = await fetch('api/turno_criar.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: csrfHeaders()
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.message || `Servidor retornou erro ${response.status}`);
            }

            NivoraFeedback.success(data.message || 'Turno criado com sucesso.');
            resetarFormularioNovoTurno();
            await carregarTurnos();
        } catch (err) {
            NivoraFeedback.error('Erro ao criar turno: ' + err.message);
        } finally {
            if (btnSubmit) {
                btnSubmit.disabled = false;
                btnSubmit.innerHTML = originalHtml;
            }
        }
    });

    formEditarTurno?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btnSubmit = document.getElementById('btnSalvarEdicaoTurno');
        const originalHtml = btnSubmit ? btnSubmit.innerHTML : '';
        const formData = new FormData(e.target);

        if (btnSubmit) {
            btnSubmit.disabled = true;
            btnSubmit.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        }

        try {
            if (getCsrfToken()) {
                formData.append('_csrf', getCsrfToken());
            }
            const response = await fetch('api/turno_atualizar.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: csrfHeaders()
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.message || `Servidor retornou erro ${response.status}`);
            }

            if (editarTurnoModal) {
                editarTurnoModal.hide();
            }

            NivoraFeedback.success(data.message || 'Turno atualizado com sucesso.');
            await carregarTurnos();
        } catch (err) {
            NivoraFeedback.error('Erro ao atualizar turno: ' + err.message);
        } finally {
            if (btnSubmit) {
                btnSubmit.disabled = false;
                btnSubmit.innerHTML = originalHtml;
            }
        }
    });

    filtroData?.addEventListener('change', carregarTurnos);
    ['filtroFuncionario', 'filtroPerfil', 'filtroDe', 'filtroAte'].forEach((id) => {
        document.getElementById(id)?.addEventListener('change', () => renderTurnos(turnosTodos));
    });
    document.getElementById('filtroLimpar')?.addEventListener('click', () => {
        ['filtroFuncionario', 'filtroPerfil', 'filtroDe', 'filtroAte'].forEach((id) => {
            const el = document.getElementById(id);
            if (el) { el.value = ''; }
        });
        renderTurnos(turnosTodos);
    });
    const formEncerrar = document.getElementById('formEncerrarTurno');
    formEncerrar?.addEventListener('submit', (e) => {
        e.preventDefault();
        const campo = document.getElementById('encerrarTurnoMotivo');
        const motivo = (campo?.value || '').trim();
        if (motivo.length < 3) {
            campo?.classList.add('is-invalid');
            campo?.focus();
            return;
        }
        campo.classList.remove('is-invalid');
        const resolver = encerrarResolver;
        encerrarResolver = null;
        encerrarModal?.hide();
        if (resolver) { resolver(motivo); }
    });
    document.getElementById('encerrarTurnoModal')?.addEventListener('hidden.bs.modal', () => {
        if (encerrarResolver) { const r = encerrarResolver; encerrarResolver = null; r(null); }
    });
    carregarTurnos();
});

function getHoraPadraoPorTurno(turno) {
    switch ((turno || '').toLowerCase()) {
        case 'noite':
            return '18:00';
        case 'integral':
            return '08:00';
        case 'manha':
        default:
            return '08:00';
    }
}

function aplicarHoraPadrao(selectEl, inputEl, force) {
    if (!selectEl || !inputEl) {
        return;
    }

    if (force || !inputEl.value) {
        inputEl.value = getHoraPadraoPorTurno(selectEl.value);
    }
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function isHoraValida(valor) {
    if (!valor) {
        return false;
    }

    const hora = String(valor).trim();
    return hora !== '00:00' && hora !== '00:00:00';
}

function formatarHora(valor) {
    if (!isHoraValida(valor)) {
        return '';
    }

    const hora = String(valor).trim();
    return hora.length >= 5 ? hora.slice(0, 5) : hora;
}

function isDiaSeguinte(data, dataSaida) {
    if (!data || !dataSaida) {
        return false;
    }

    const entrada = new Date(`${data}T00:00:00`);
    const saida = new Date(`${dataSaida}T00:00:00`);
    return saida.getTime() > entrada.getTime();
}

function formatarEntradaSaida(entrada, saida, data, dataSaida) {
    const horaEntrada = formatarHora(entrada);
    const horaSaida = formatarHora(saida);
    const viraDia = isDiaSeguinte(data, dataSaida);
    const sufixo = viraDia ? ' (+1d)' : '';

    if (horaEntrada && horaSaida) {
        return `${horaEntrada} / ${horaSaida}${sufixo}`;
    }

    if (horaEntrada) {
        return horaEntrada;
    }

    if (horaSaida) {
        return `- / ${horaSaida}${sufixo}`;
    }

    return '-';
}

function getStatusBadgeClass(status) {
    switch ((status || '').toLowerCase()) {
        case 'ativo':
            return 'success';
        case 'finalizado':
            return 'dark';
        case 'ausente':
            return 'danger';
        case 'planejado':
        default:
            return 'secondary';
    }
}

function getStatusLabel(status) {
    const mapa = { planejado: 'Planeado', ativo: 'Ativo', finalizado: 'Encerrado', encerrado: 'Encerrado', ausente: 'Ausente' };
    const k = String(status || 'planejado').toLowerCase();
    return mapa[k] || k;
}

function isTurnosEmpShell() {
    return document.body && document.body.classList.contains('turnos-emp-shell');
}

function renderAtivosHoje(lista) {
    const container = document.getElementById('listaAtivos');
    const countEl = document.getElementById('activeTodayCount');
    ativosHojeCache = Array.isArray(lista) ? lista : [];
    const emp = isTurnosEmpShell();

    if (countEl) {
        countEl.textContent = String(ativosHojeCache.length);
    }

    if (!container) {
        return;
    }

    if (ativosHojeCache.length === 0) {
        container.innerHTML = `
            <div class="text-center p-4 text-muted te-empty">
                <i class="fas fa-user-slash fa-2x mb-2"></i>
                <p class="mb-0">Nenhum funcionário ativo</p>
            </div>
        `;
        return;
    }

    container.innerHTML = ativosHojeCache.map((ativo) => {
        const nome = ativo.funcionario_nome || ativo.nome || 'Sem nome';
        const iniciais = nome.trim().slice(0, 2).toUpperCase();
        const entrada = formatarHora(ativo.hora_entrada) || '--:--';
        const turno = String(ativo.turno || '').toUpperCase();
        const duracao = ativo.duracao_formatada ? String(ativo.duracao_formatada) : '';
        const online = !!ativo.online;

        if (emp) {
            return `
                <div class="te-active-row">
                    <div class="avatar">${escapeHtml(iniciais)}</div>
                    <div class="te-active-meta">
                        <strong>${escapeHtml(nome)}</strong>
                        <span>${escapeHtml(turno)} · entrada ${escapeHtml(entrada)}${duracao ? ` · ${escapeHtml(duracao)}` : ''}</span>
                    </div>
                    <span class="te-pill ${online ? 'is-on' : 'is-off'}">${online ? 'Online' : 'Offline'}</span>
                </div>
            `;
        }

        return `
            <div class="d-flex align-items-center p-3 border-bottom">
                <div class="avatar me-3">${escapeHtml(iniciais)}</div>
                <div class="flex-grow-1">
                    <div class="fw-bold">${escapeHtml(nome)}</div>
                    <small class="text-muted">${escapeHtml(turno)} • ${escapeHtml(entrada)}</small>
                </div>
            </div>
        `;
    }).join('');
}

function renderTurnos(lista) {
    const tbody = document.getElementById('turnosLista');
    if (!tbody) {
        return;
    }

    turnosTodos = Array.isArray(lista) ? lista : [];
    turnosCache = aplicarFiltrosHistorico(turnosTodos);
    const emp = isTurnosEmpShell();
    const hoje = hojeIso();
    const contador = document.getElementById('filtroContagem');
    if (contador) { contador.textContent = `${turnosCache.length} de ${turnosTodos.length}`; }

    tbody.innerHTML = turnosCache.map((turno) => {
        const ativo = String(turno.status || '').toUpperCase() === 'ATIVO';
        const esquecido = ativo && String(turno.data || '') < hoje;
        return `
        <tr class="${esquecido ? 'te-row-esquecido' : ''}">
            <td>${escapeHtml(turno.data || '-')}${esquecido ? '<br><span class="badge bg-danger">Esquecido</span>' : ''}</td>
            <td>${escapeHtml(turno.funcionario_nome || 'Sem nome')}</td>
            <td>${escapeHtml(String(turno.turno || '-').toUpperCase())}</td>
            <td>${escapeHtml(formatarEntradaSaida(turno.hora_entrada, turno.hora_saida, turno.data, turno.data_saida))}</td>
            <td><span class="badge bg-${getStatusBadgeClass(turno.status)}">${escapeHtml(getStatusLabel(turno.status))}</span></td>
            <td class="${emp ? 'te-actions' : 'd-flex gap-2'}">
                <button type="button" class="btn btn-sm btn-info" onclick="editarTurno(${Number(turno.id)})">Editar</button>
                ${ativo
                    ? `<button type="button" class="btn btn-sm ${esquecido ? 'btn-danger' : 'btn-outline-danger'}" onclick="fecharTurnoManualPorId(${Number(turno.usuario_id)}, ${Number(turno.id)})">${esquecido ? 'Encerrar agora' : 'Encerrar'}</button>`
                    : ''}
            </td>
        </tr>
    `;
    }).join('') || '<tr><td colspan="6" class="text-center text-muted">Nenhum turno</td></tr>';
}

function renderMetricas(metricas) {
    if (!metricas || typeof metricas !== 'object') {
        return;
    }

    const ativos = document.getElementById('metricaAtivos');
    const naoEncerrados = document.getElementById('metricaNaoEncerrados');
    const tempo = document.getElementById('metricaTempoTurno');
    const online = document.getElementById('metricaOnline');
    const offline = document.getElementById('metricaOffline');

    if (ativos) ativos.textContent = String(metricas.funcionarios_ativos ?? 0);
    if (naoEncerrados) naoEncerrados.textContent = String(metricas.turnos_nao_encerrados ?? 0);
    if (tempo) tempo.textContent = String(metricas.tempo_turno_formatado ?? '0 min');
    if (online) online.textContent = String(metricas.online ?? 0);
    if (offline) offline.textContent = String(metricas.offline ?? 0);
}

function renderAuditoria(lista) {
    const tbody = document.getElementById('auditoriaLista');
    auditoriaCache = Array.isArray(lista) ? lista : [];
    if (!tbody) {
        return;
    }

    if (auditoriaCache.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">Nenhum log de auditoria</td></tr>';
        return;
    }

    tbody.innerHTML = auditoriaCache.map((item) => `
        <tr>
            <td>${escapeHtml(item.criado_em || '-')}</td>
            <td>${escapeHtml(item.responsavel_nome || '-')}</td>
            <td>${escapeHtml(item.funcionario_nome || '-')}</td>
            <td>${escapeHtml(item.tipo_acao || '-')}</td>
            <td>${escapeHtml(item.motivo || '-')}</td>
        </tr>
    `).join('');
}

function showAdminAlert(message, type) {
    const el = document.getElementById('turnoAdminAlert');
    if (!el) {
        NivoraFeedback.warning(message);
        return;
    }

    el.textContent = message;
    el.className = `alert alert-${type}`;
    el.style.display = 'block';
    setTimeout(() => {
        el.style.display = 'none';
    }, 5000);
}

async function carregarTurnos() {
    const filtro = document.getElementById('filtroData')?.value || '';
    const tbody = document.getElementById('turnosLista');

    if (tbody) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" class="text-center text-muted py-3">
                    <i class="fas fa-spinner fa-spin me-2"></i>Carregando turnos...
                </td>
            </tr>
        `;
    }

    try {
        const response = await fetch(`api/turno_listar.php?data=${encodeURIComponent(filtro)}`, {
            credentials: 'same-origin',
            headers: csrfHeaders()
        });
        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.message || `HTTP ${response.status}`);
        }

        let lista = Array.isArray(data.turnos) ? data.turnos : [];

        if (filtro === 'semana') {
            const hoje = new Date();
            const diaSemana = hoje.getDay();
            const inicioSemana = new Date(hoje);
            inicioSemana.setDate(hoje.getDate() - (diaSemana === 0 ? 6 : diaSemana - 1));
            inicioSemana.setHours(0, 0, 0, 0);

            const fimSemana = new Date(inicioSemana);
            fimSemana.setDate(inicioSemana.getDate() + 6);
            fimSemana.setHours(23, 59, 59, 999);

            lista = lista.filter((turno) => {
                const d = new Date(`${turno.data}T00:00:00`);
                return d >= inicioSemana && d <= fimSemana;
            });
        }

        renderTurnos(lista);
        renderAtivosHoje(Array.isArray(data.ativos_hoje) ? data.ativos_hoje : []);
        renderMetricas(data.metricas || {});
        renderAuditoria(Array.isArray(data.auditoria) ? data.auditoria : []);
    } catch (err) {
        console.error('Erro ao carregar turnos:', err);
        if (tbody) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="6" class="text-center text-danger py-3">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Não foi possível carregar os turnos. (${escapeHtml(err.message)})
                    </td>
                </tr>
            `;
        }
    }
}

function resetarFormularioNovoTurno() {
    const form = document.getElementById('formNovoTurno');
    if (!form) {
        return;
    }

    form.reset();
    form.querySelector('input[name="data"]').value = new Date().toISOString().slice(0, 10);
    form.querySelector('#novoTurnoTipo').value = 'manha';
    form.querySelector('#novoTurnoHoraEntrada').value = getHoraPadraoPorTurno('manha');
}

function editarTurno(id) {
    const turno = turnosCache.find((item) => Number(item.id) === Number(id));
    if (!turno) {
        NivoraFeedback.warning('Turno não encontrado na lista atual.');
        return;
    }

    document.getElementById('editarTurnoId').value = turno.id;
    document.getElementById('editarTurnoData').value = turno.data || '';
    document.getElementById('editarTurnoUsuario').value = turno.usuario_id || '';
    document.getElementById('editarTurnoStatus').value = turno.status || 'planejado';
    document.getElementById('editarTurnoTipo').value = turno.turno || 'manha';
    document.getElementById('editarTurnoHoraEntrada').value = formatarHora(turno.hora_entrada);
    document.getElementById('editarTurnoHoraSaida').value = formatarHora(turno.hora_saida);
    document.getElementById('editarTurnoObservacoes').value = turno.observacoes || '';
    document.getElementById('editarTurnoMotivo').value = turno.motivo_intervencao || '';

    if (editarTurnoModal) {
        editarTurnoModal.show();
    }
}

async function fecharTurnoManualSelecionado() {
    const form = document.getElementById('formNovoTurno');
    if (!form) {
        return;
    }

    const funcionarioId = form.querySelector('select[name="usuario_id"]')?.value;
    if (!funcionarioId) {
        showAdminAlert('Selecione um funcionário para encerrar manualmente o turno.', 'warning');
        return;
    }

    await fecharTurnoManualPorId(funcionarioId);
}

async function fecharTurnoManualPorId(funcionarioId, turnoId) {
    const ref = turnosTodos.find((t) => Number(t.usuario_id) === Number(funcionarioId))
        || ativosHojeCache.find((t) => Number(t.usuario_id) === Number(funcionarioId));
    const motivo = await pedirMotivoEncerrar(ref ? (ref.funcionario_nome || ref.nome) : '');
    if (!motivo) {
        return;
    }

    try {
        const response = await fetch('api/turno_operacao.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: csrfHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify({
                acao: 'fechar_manual',
                funcionario_id: Number(funcionarioId),
                turno_id: turnoId ? Number(turnoId) : undefined,
                motivo
            })
        });
        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.message || `HTTP ${response.status}`);
        }

        showAdminAlert(data.message || 'Turno encerrado manualmente com sucesso.', 'success');
        await carregarTurnos();
    } catch (err) {
        showAdminAlert(`Erro ao encerrar turno manualmente: ${err.message}`, 'danger');
    }
}

function exportarTurnos() {
    if (!Array.isArray(turnosCache) || turnosCache.length === 0) {
        NivoraFeedback.info('Não há turnos para exportar.');
        return;
    }

    const linhas = [
        ['Filtro', descricaoFiltros()],
        ['Exportado em', new Date().toLocaleString('pt-PT')],
        [],
        ['Data', 'Funcionário', 'Perfil', 'Turno', 'Entrada', 'Saída', 'Status', 'Observações']
    ];

    turnosCache.forEach((turno) => {
        linhas.push([
            turno.data || '',
            turno.funcionario_nome || '',
            String(turno.cargo || turno.funcionario_perfil || ''),
            String(turno.turno || '').toUpperCase(),
            formatarHora(turno.hora_entrada),
            formatarHora(turno.hora_saida),
            getStatusLabel(turno.status),
            turno.observacoes || ''
        ]);
    });

    const csv = linhas
        .map((linha) => linha.map((coluna) => `"${String(coluna ?? '').replace(/"/g, '""')}"`).join(';'))
        .join('\r\n');

    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `turnos_${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}
