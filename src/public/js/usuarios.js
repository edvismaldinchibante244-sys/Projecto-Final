/**
 * JavaScript - Gestao de usuarios (Bootstrap 5)
 */

var fetchOptions = {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
    }
};

document.addEventListener('DOMContentLoaded', function () {
    var modalUsuarioEl = document.getElementById('modalUsuario');
    var modalSenhaEl = document.getElementById('modalSenha');

    if (modalUsuarioEl) new bootstrap.Modal(modalUsuarioEl);
    if (modalSenhaEl) new bootstrap.Modal(modalSenhaEl);

    var form = document.getElementById('formUsuario');
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var usuarioId = document.getElementById('usuario_id').value;
            var url = usuarioId ? 'api/usuario_editar.php' : 'api/usuario_cadastrar.php';
            var perfilEl = document.getElementById('perfil');
            var perfilWasDisabled = !!(perfilEl && perfilEl.disabled);
            if (perfilWasDisabled) {
                perfilEl.disabled = false;
            }
            var formData = new FormData(form);
            if (perfilWasDisabled) {
                perfilEl.disabled = true;
            }
            // Conta própria: nunca desactivar
            if (usuarioId && typeof SESSION_USUARIO_ID !== 'undefined' && Number(usuarioId) === Number(SESSION_USUARIO_ID)) {
                formData.set('ativo', 'on');
            }
            formData.append('_csrf', getCsrfToken());

            fetch(url, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: csrfHeaders()
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        showAlert('alertModal', data.message, 'success');
                        setTimeout(function () { location.reload(); }, 1500);
                    } else {
                        showAlert('alertModal', data.message, 'danger');
                    }
                })
                .catch(function (err) {
                    showAlert('alertModal', 'Erro ao processar: ' + err.message, 'danger');
                });
        });
    }

    var formSenha = document.getElementById('formSenha');
    if (formSenha) {
        formSenha.addEventListener('submit', function (e) {
            e.preventDefault();
            var nova = document.getElementById('nova_senha').value;
            var confirma = document.getElementById('confirmar_senha').value;

            if (nova !== confirma) {
                showAlert('alertSenha', 'As senhas nao coincidem!', 'danger');
                return;
            }
            if (nova.length < 6) {
                showAlert('alertSenha', 'Minimo 6 caracteres', 'danger');
                return;
            }

            var formData = new FormData(formSenha);
            formData.append('_csrf', getCsrfToken());
            fetch('api/usuario_senha.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: csrfHeaders()
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        showAlert('alertSenha', data.message, 'success');
                        setTimeout(function () { location.reload(); }, 1500);
                    } else {
                        showAlert('alertSenha', data.message, 'danger');
                    }
                })
                .catch(function (err) {
                    showAlert('alertSenha', 'Erro ao alterar: ' + err.message, 'danger');
                });
        });
    }

    var filtroPerfil = document.getElementById('filtroPerfil');
    ['filtroEstado', 'filtroUnidade'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) { el.addEventListener('change', aplicarFiltroPerfil); }
    });
    var selPerfilNovo = document.getElementById('perfil');
    if (selPerfilNovo) {
        selPerfilNovo.addEventListener('change', atualizarRegraSenha);
        atualizarRegraSenha();
    }
    if (document.getElementById('usrContagem')) { aplicarFiltroPerfil(); }
    if (filtroPerfil) {
        filtroPerfil.addEventListener('change', aplicarFiltroPerfil);
    }

    var chips = document.getElementById('usrEmpChips');
    if (chips) {
        chips.addEventListener('click', function (ev) {
            var btn = ev.target.closest('[data-perfil-filter]');
            if (!btn) return;
            var perfil = btn.getAttribute('data-perfil-filter') || '';
            if (filtroPerfil) {
                filtroPerfil.value = perfil;
            }
            chips.querySelectorAll('.usr-emp-chip').forEach(function (c) {
                c.classList.toggle('is-active', c === btn);
            });
            aplicarFiltroPerfil();
        });
    }

    if (typeof EDIT_ME_ON_LOAD !== 'undefined' && EDIT_ME_ON_LOAD && typeof SESSION_USUARIO_ID !== 'undefined' && SESSION_USUARIO_ID > 0) {
        editarUsuario(SESSION_USUARIO_ID, { meuPerfil: true });
        try {
            var cleanUrl = window.location.pathname + window.location.search.replace(/([?&])edit_me=1&?/, '$1').replace(/[?&]$/, '');
            window.history.replaceState({}, '', cleanUrl || window.location.pathname);
        } catch (e) { /* ignore */ }
    }
});

function setCamposMeuPerfil(isMeuPerfil) {
    var perfilEl = document.getElementById('perfil');
    var ativoEl = document.getElementById('ativo');
    var perfilWrap = perfilEl ? perfilEl.closest('.mb-3') : null;
    var ativoWrap = ativoEl ? ativoEl.closest('.form-check') : null;
    if (perfilEl) {
        perfilEl.disabled = !!isMeuPerfil;
        if (isMeuPerfil) {
            perfilEl.setAttribute('data-locked', '1');
        } else {
            perfilEl.removeAttribute('data-locked');
        }
    }
    if (ativoEl) {
        ativoEl.disabled = !!isMeuPerfil;
        if (isMeuPerfil) {
            ativoEl.checked = true;
        }
    }
    if (perfilWrap) {
        var hint = perfilWrap.querySelector('.meu-perfil-hint');
        if (isMeuPerfil) {
            if (!hint) {
                hint = document.createElement('small');
                hint.className = 'text-muted d-block mt-1 meu-perfil-hint';
                hint.textContent = 'O seu perfil de Administrador não pode ser alterado aqui.';
                perfilWrap.appendChild(hint);
            }
        } else if (hint) {
            hint.remove();
        }
    }
    if (ativoWrap) {
        ativoWrap.style.display = isMeuPerfil ? 'none' : '';
    }
}
function atualizarRegraSenha() {
    if (typeof SENHA_REGRAS === 'undefined') { return; }
    var sel = document.getElementById('perfil');
    var campo = document.getElementById('senha');
    var hint = document.getElementById('senhaHint');
    var regra = SENHA_REGRAS[(sel && sel.value ? sel.value : 'ADMIN').toUpperCase()] || SENHA_REGRAS.ADMIN;
    if (!regra) { return; }
    if (campo) { campo.setAttribute('minlength', regra.min); }
    if (hint) {
        hint.textContent = (regra.texto || ('Mínimo de ' + regra.min + ' caracteres.'))
            + ' Ao editar, deixe em branco para manter a senha actual.';
    }
}

async function reativarUsuario(id) {
    if (!(await nivoraConfirmar({ titulo: 'Reactivar este acesso?', mensagem: 'O utilizador volta a poder entrar no sistema.', confirmar: 'Reactivar' }))) return;
    var formData = new FormData();
    formData.append('id', id);
    formData.append('_csrf', getCsrfToken());
    fetch('api/usuario_reativar.php', { method: 'POST', body: formData, credentials: 'same-origin', headers: csrfHeaders() })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                NivoraFeedback.reloadWithMessage(data.message, 'success');
            } else {
                NivoraFeedback.error(data.message || 'Não foi possível reactivar.');
            }
        })
        .catch(function () { NivoraFeedback.error('Erro de ligação ao reactivar.'); });
}

function verHistoricoUsuario(id) {
    var corpo = document.getElementById('historicoUsuarioCorpo');
    var titulo = document.getElementById('historicoUsuarioNome');
    var modalEl = document.getElementById('historicoUsuarioModal');
    if (!corpo || !modalEl || !window.bootstrap) { return; }
    corpo.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3"><i class="fas fa-spinner fa-spin me-2"></i>A carregar...</td></tr>';
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
    fetch('api/usuario_historico.php?id=' + encodeURIComponent(id), { credentials: 'same-origin', headers: csrfHeaders() })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.success) {
                corpo.innerHTML = '<tr><td colspan="4" class="text-center text-danger py-3">' + escapeHtmlUsr(data.message || 'Erro') + '</td></tr>';
                return;
            }
            if (titulo) { titulo.textContent = data.nome || ''; }
            if (!data.itens || !data.itens.length) {
                corpo.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">Ainda não há alterações registadas.</td></tr>';
                return;
            }
            corpo.innerHTML = data.itens.map(function (it) {
                return '<tr><td>' + escapeHtmlUsr(it.quando) + '</td><td>' + escapeHtmlUsr(it.acao)
                    + '</td><td>' + escapeHtmlUsr(it.autor) + '</td><td class="text-muted">' + escapeHtmlUsr(it.detalhe || '—') + '</td></tr>';
            }).join('');
        })
        .catch(function () {
            corpo.innerHTML = '<tr><td colspan="4" class="text-center text-danger py-3">Erro de ligação.</td></tr>';
        });
}

function escapeHtmlUsr(valor) {
    return String(valor === null || valor === undefined ? '' : valor)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function aplicarFiltroPerfil() {
    var filtroEl = document.getElementById('filtroPerfil');
    if (!filtroEl) return;

    var perfil = (filtroEl.value || '').toUpperCase();
    var estado = (document.getElementById('filtroEstado') || {}).value || '';
    var unidade = (document.getElementById('filtroUnidade') || {}).value || '';
    var rows = document.querySelectorAll('tr.usuario-row');
    var visiveis = 0;
    rows.forEach(function (row) {
        var okPerfil = !perfil || (row.getAttribute('data-perfil') || '').toUpperCase() === perfil;
        var okEstado = !estado || (row.getAttribute('data-estado') || '') === estado;
        var okUnidade = !unidade || (row.getAttribute('data-unidade') || '') === unidade;
        var mostrar = okPerfil && okEstado && okUnidade;
        row.style.display = mostrar ? '' : 'none';
        if (mostrar) { visiveis++; }
    });
    var contador = document.getElementById('usrContagem');
    if (contador) { contador.textContent = visiveis + ' de ' + rows.length; }
    var chips = document.getElementById('usrEmpChips');
    if (chips) {
        chips.querySelectorAll('.usr-emp-chip').forEach(function (c) {
            var chipPerfil = (c.getAttribute('data-perfil-filter') || '').toUpperCase();
            c.classList.toggle('is-active', chipPerfil === perfil);
        });
    }
}

function limparOpcoesPerfilTemporarias() {
    var select = document.getElementById('perfil');
    if (!select) return;
    Array.prototype.slice.call(select.querySelectorAll('option[data-temp-perfil="1"]')).forEach(function (opt) {
        opt.remove();
    });
}

function garantirOpcaoPerfil(valor) {
    var select = document.getElementById('perfil');
    if (!select || !valor) return;
    var perfil = String(valor).toUpperCase();
    var existe = Array.prototype.some.call(select.options, function (opt) {
        return String(opt.value).toUpperCase() === perfil;
    });
    if (existe) return;

    var labels = { COZINHA: 'Cozinha (legado)', BAR: 'Bar (legado)', GERENTE: 'Gerente (legado)' };
    var opt = document.createElement('option');
    opt.value = perfil;
    opt.textContent = labels[perfil] || perfil;
    opt.setAttribute('data-temp-perfil', '1');
    select.appendChild(opt);
}

function abrirModal() {
    document.getElementById('tituloModal').textContent = 'Novo Usuario';
    document.getElementById('formUsuario').reset();
    document.getElementById('usuario_id').value = '';
    document.getElementById('ativo').checked = true;
    document.getElementById('senhaHint').style.display = 'none';
    document.getElementById('senha').required = true;
    document.getElementById('alertModal').style.display = 'none';
    document.getElementById('fotoPreview').src = 'https://ui-avatars.com/api/?name=?&background=FF6B35&color=fff&size=100';
    limparOpcoesPerfilTemporarias();
    setCamposMeuPerfil(false);

    var modalEl = document.getElementById('modalUsuario');
    if (modalEl) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
}

function editarUsuario(id, opts) {
    opts = opts || {};
    if (!id || isNaN(id) || id <= 0) {
        NivoraFeedback.warning('ID do usuario invalido ou nao fornecido. Nao e possivel editar este usuario.');
        return;
    }

    var isMeuPerfil = !!opts.meuPerfil || (typeof SESSION_USUARIO_ID !== 'undefined' && Number(id) === Number(SESSION_USUARIO_ID));

    fetch('api/usuario_buscar.php?id=' + id, {
        credentials: 'same-origin'
    })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.success) {
                NivoraFeedback.error(data.message || 'Erro ao buscar usuario');
                return;
            }

            var u = data.data;
            document.getElementById('tituloModal').textContent = isMeuPerfil ? 'Meu Perfil' : 'Editar Usuario';
            document.getElementById('usuario_id').value = u.id;
            document.getElementById('nome').value = u.nome;
            document.getElementById('email').value = u.email;
            limparOpcoesPerfilTemporarias();
            // Mantém perfil legado só para edição (sem criar novos fora do plano).
            if (!PODE_PERFIS_BAR_COZINHA && (u.perfil === 'COZINHA' || u.perfil === 'BAR')) {
                garantirOpcaoPerfil(u.perfil);
            }
            if (typeof PODE_PERFIL_GERENTE !== 'undefined' && !PODE_PERFIL_GERENTE && u.perfil === 'GERENTE') {
                garantirOpcaoPerfil(u.perfil);
            }
            document.getElementById('perfil').value = u.perfil;
            document.getElementById('ativo').checked = u.ativo == 1;
            document.getElementById('senha').required = false;
            document.getElementById('senhaHint').style.display = 'block';
            document.getElementById('alertModal').style.display = 'none';
            setCamposMeuPerfil(isMeuPerfil);

            if (u.foto && u.foto.trim() !== '') {
                document.getElementById('fotoPreview').src = u.foto.startsWith('http') || u.foto.startsWith('/')
                    ? u.foto
                    : BASE_URL + '/' + u.foto;
            } else {
                var iniciais = u.nome ? u.nome.substring(0, 1).toUpperCase() : '?';
                document.getElementById('fotoPreview').src = avatarIniciaisUsr(iniciais);
            }

            var modalEl = document.getElementById('modalUsuario');
            if (modalEl) {
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
        })
        .catch(function (err) {
            NivoraFeedback.error('Erro ao buscar usuario: ' + err.message);
        });
}

async function deletarUsuario(id) {
    if (!(await nivoraConfirmar({ titulo: 'Excluir este utilizador?', mensagem: 'Esta ação não pode ser desfeita.', confirmar: 'Excluir', perigo: true }))) return;
    var formData = new FormData();
    formData.append('id', id);
    formData.append('_csrf', getCsrfToken());
    fetch('api/usuario_deletar.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        headers: csrfHeaders()
    })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                NivoraFeedback.reloadWithMessage(data.message, 'success');
            } else {
                NivoraFeedback.error(data.message || 'Não foi possível excluir o usuário.');
            }
        });
}

function getCsrfToken() {
    if (typeof CSRF_TOKEN !== 'undefined' && CSRF_TOKEN) return CSRF_TOKEN;

    var hiddenInput = document.getElementById('csrf_token_form');
    if (hiddenInput && hiddenInput.value) return hiddenInput.value;

    var meta = document.querySelector('meta[name=\"csrf-token\"]');
    if (meta) {
        var metaToken = meta.getAttribute('content');
        if (metaToken) return metaToken;
    }

    return '';
}

function csrfHeaders() {
    var token = getCsrfToken();
    return token ? { 'X-CSRF-Token': token } : {};
}

function alterarSenha(id) {
    document.getElementById('formSenha').reset();
    document.getElementById('senha_usuario_id').value = id;
    document.getElementById('alertSenha').style.display = 'none';

    var modalEl = document.getElementById('modalSenha');
    if (modalEl) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
}

function showAlert(id, message, type) {
    var alertDiv = document.getElementById(id);
    if (alertDiv) {
        alertDiv.textContent = message;
        alertDiv.className = 'alert alert-' + type;
        alertDiv.style.display = 'block';

        if (typeof message === 'string' && message.toLowerCase().indexOf('sessao expirada') !== -1) {
            window.setTimeout(function () {
                window.location.reload();
            }, 1200);
            return;
        }

        setTimeout(function () { alertDiv.style.display = 'none'; }, 5000);
    }
}

document.addEventListener('DOMContentLoaded', function () {
    var nome = document.getElementById('nome');
    var perfil = document.getElementById('perfil');
    var previewNome = document.getElementById('userPreviewNome');
    var previewPerfil = document.getElementById('userPreviewPerfil');
    function atualizarPreviewUsuario() {
        if (previewNome) previewNome.textContent = (nome && nome.value.trim()) || 'Nome do colaborador';
        if (previewPerfil && perfil) {
            var opt = perfil.options[perfil.selectedIndex];
            previewPerfil.textContent = (opt && opt.text) ? opt.text : 'Função no salão';
        }
    }
    if (nome) nome.addEventListener('input', atualizarPreviewUsuario);
    if (perfil) perfil.addEventListener('change', atualizarPreviewUsuario);
    atualizarPreviewUsuario();
});


function avatarIniciaisUsr(texto) {
    var ini = String(texto || '?').trim().slice(0, 2).toUpperCase() || '?';
    var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100">'
        + '<rect width="100%" height="100%" rx="50" fill="#FF6B35"/>'
        + '<text x="50%" y="50%" dy=".35em" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="40" font-weight="700" fill="#fff">'
        + escapeHtmlUsr(ini) + '</text></svg>';
    return 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg);
}

// Fotos que não carregam caem para as iniciais locais (sem depender da internet)
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('img[data-fallback-src]').forEach(function (img) {
        img.addEventListener('error', function () {
            var alt = img.getAttribute('data-fallback-src');
            if (alt && img.src !== alt) { img.src = alt; }
        });
    });
});
