/* Paginas Terminais (admin) e Definir PIN (equipa). */
(function () {
    'use strict';
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var aviso = document.getElementById('termAviso');

    function mostrar(msg, ok) {
        if (!aviso) { return; }
        aviso.className = 'alert ' + (ok ? 'alert-success' : 'alert-danger');
        aviso.textContent = msg;
    }

    function enviar(url, dados) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(dados)
        }).then(function (r) {
            return r.json().catch(function () { return { success: false, message: 'Erro inesperado (' + r.status + ').' }; });
        }).catch(function () {
            return { success: false, message: 'Sem ligação. Tente novamente.' };
        });
    }

    var formRegistar = document.getElementById('formRegistar');
    if (formRegistar) {
        formRegistar.addEventListener('submit', function (e) {
            e.preventDefault();
            enviar('api/terminal.php', { acao: 'registar', nome: document.getElementById('termNome').value }).then(function (d) {
                if (d.success) { window.location.reload(); } else { mostrar(d.message || 'Não foi possível registar.', false); }
            });
        });
    }

    document.querySelectorAll('[data-revogar]').forEach(function (b) {
        b.addEventListener('click', async function () {
            if (!(await nivoraConfirmar({ titulo: 'Revogar o terminal "' + b.dataset.nome + '"?', mensagem: 'Deixa de aceitar PIN de imediato.', confirmar: 'Revogar', perigo: true }))) { return; }
            enviar('api/terminal.php', { acao: 'revogar', terminal_id: parseInt(b.dataset.revogar, 10) }).then(function (d) {
                if (d.success) { window.location.reload(); } else { mostrar(d.message || 'Não foi possível revogar.', false); }
            });
        });
    });

    document.querySelectorAll('[data-limpar-pin]').forEach(function (b) {
        b.addEventListener('click', async function () {
            if (!(await nivoraConfirmar({ titulo: 'Apagar o PIN de ' + b.dataset.nome + '?', mensagem: 'Terá de definir um novo PIN.', confirmar: 'Apagar PIN', perigo: true }))) { return; }
            enviar('api/pin.php', { acao: 'limpar', usuario_id: parseInt(b.dataset.limparPin, 10) }).then(function (d) {
                if (d.success) { window.location.reload(); } else { mostrar(d.message || 'Não foi possível apagar.', false); }
            });
        });
    });

    document.querySelectorAll('[data-desbloquear-pin]').forEach(function (b) {
        b.addEventListener('click', async function () {
            if (!(await nivoraConfirmar({ titulo: 'Desbloquear o PIN de ' + b.dataset.nome + '?', mensagem: 'Mantém o mesmo PIN e pode voltar a entrar já.', confirmar: 'Desbloquear' }))) { return; }
            enviar('api/pin.php', { acao: 'desbloquear', usuario_id: parseInt(b.dataset.desbloquearPin, 10) }).then(function (d) {
                if (d.success) { window.location.reload(); } else { mostrar(d.message || 'Não foi possível desbloquear.', false); }
            });
        });
    });

    function escTerm(valor) {
        var d = document.createElement('div');
        d.textContent = String(valor === null || valor === undefined ? '' : valor);
        return d.innerHTML;
    }

    document.querySelectorAll('[data-acessos]').forEach(function (b) {
        b.addEventListener('click', function () {
            var modalEl = document.getElementById('modalAcessosTerminal');
            var corpo = document.getElementById('acessosTerminalCorpo');
            var titulo = document.getElementById('acessosTerminalNome');
            if (!modalEl || !corpo || !window.bootstrap) { return; }
            if (titulo) { titulo.textContent = b.dataset.nome || ''; }
            corpo.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-3"><i class="fas fa-spinner fa-spin me-2"></i>A carregar...</td></tr>';
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
            fetch('api/terminal_acessos.php?terminal_id=' + encodeURIComponent(b.dataset.acessos), {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d.success) {
                        corpo.innerHTML = '<tr><td colspan="3" class="text-center text-danger py-3">' + escTerm(d.message || 'Erro') + '</td></tr>';
                        return;
                    }
                    if (!d.itens || !d.itens.length) {
                        corpo.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-3">Ainda não há acessos neste terminal.</td></tr>';
                        return;
                    }
                    corpo.innerHTML = d.itens.map(function (it) {
                        return '<tr><td>' + escTerm(it.quando) + '</td><td>' + escTerm(it.usuario) + '</td><td>'
                            + '<span class="badge ' + (it.ok ? 'bg-success' : 'bg-warning text-dark') + '">' + escTerm(it.resultado) + '</span></td></tr>';
                    }).join('');
                })
                .catch(function () {
                    corpo.innerHTML = '<tr><td colspan="3" class="text-center text-danger py-3">Erro de ligação.</td></tr>';
                });
        });
    });

    var formPin = document.getElementById('formPin');
    if (formPin) {
        formPin.addEventListener('submit', function (e) {
            e.preventDefault();
            var botaoPin = formPin.querySelector('button[type="submit"]');
            var conteudoOriginal = botaoPin ? botaoPin.innerHTML : '';
            if (botaoPin) {
                botaoPin.disabled = true;
                botaoPin.setAttribute('aria-busy', 'true');
                botaoPin.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i><span>A guardar PIN...</span>';
            }
            enviar('api/pin.php', {
                acao: 'definir',
                pin: document.getElementById('pinNovo').value,
                pin_confirmar: document.getElementById('pinConfirmar').value,
                senha: document.getElementById('pinSenha').value
            }).then(function (d) {
                mostrar(d.message || (d.success ? 'PIN guardado.' : 'Não foi possível guardar.'), !!d.success);
                if (d.success) { formPin.reset(); }
            }).finally(function () {
                if (botaoPin) {
                    botaoPin.disabled = false;
                    botaoPin.removeAttribute('aria-busy');
                    botaoPin.innerHTML = conteudoOriginal;
                }
            });
        });
    }
})();
