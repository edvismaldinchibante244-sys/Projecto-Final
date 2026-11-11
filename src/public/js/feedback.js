(() => {
    'use strict';

    if (window.NivoraFeedback) {
        return;
    }

    const storageKey = 'nivora.feedback.pending';
    const titles = {
        success: 'Concluído',
        error: 'Não foi possível concluir',
        warning: 'Atenção',
        info: 'Informação'
    };
    const durations = {
        success: 5000,
        error: 10000,
        warning: 8000,
        info: 6500
    };

    function normalizarTipo(tipo) {
        return Object.prototype.hasOwnProperty.call(titles, tipo) ? tipo : 'info';
    }

    function criarRegiao() {
        let regiao = document.getElementById('nivora-feedback-region');
        if (!regiao) {
            regiao = document.createElement('div');
            regiao.id = 'nivora-feedback-region';
            regiao.className = 'nivora-feedback-region';
            regiao.setAttribute('role', 'region');
            regiao.setAttribute('aria-label', 'Notificações');
            document.body.appendChild(regiao);
        }
        return regiao;
    }

    function mostrar(mensagem, tipo, opcoes) {
        const texto = String(mensagem == null ? '' : mensagem).trim();
        if (!texto) {
            return null;
        }

        const tipoNormalizado = normalizarTipo(tipo || 'info');
        if (!document.body) {
            document.addEventListener('DOMContentLoaded', () => mostrar(texto, tipoNormalizado, opcoes), { once: true });
            return null;
        }

        const regiao = criarRegiao();
        const notificacao = document.createElement('section');
        notificacao.className = 'nivora-feedback nivora-feedback--' + tipoNormalizado;
        notificacao.setAttribute('role', tipoNormalizado === 'error' || tipoNormalizado === 'warning' ? 'alert' : 'status');
        notificacao.setAttribute('aria-atomic', 'true');

        const conteudo = document.createElement('div');
        conteudo.className = 'nivora-feedback__content';

        const titulo = document.createElement('p');
        titulo.className = 'nivora-feedback__title';
        titulo.textContent = titles[tipoNormalizado];

        const corpo = document.createElement('p');
        corpo.className = 'nivora-feedback__message';
        corpo.textContent = texto;

        const fechar = document.createElement('button');
        fechar.className = 'nivora-feedback__close';
        fechar.type = 'button';
        fechar.textContent = 'Fechar';
        fechar.setAttribute('aria-label', 'Fechar notificação');

        conteudo.append(titulo, corpo);

        // Acao opcional na notificacao, ex.: { action: { label: 'Desfazer', onClick: fn } }.
        const acao = opcoes && opcoes.action;
        if (acao && typeof acao.onClick === 'function') {
            const botaoAcao = document.createElement('button');
            botaoAcao.type = 'button';
            botaoAcao.className = 'nivora-feedback__action';
            botaoAcao.textContent = acao.label || 'Desfazer';
            botaoAcao.addEventListener('click', () => {
                remover();
                acao.onClick();
            });
            conteudo.appendChild(botaoAcao);
        }
        notificacao.append(conteudo, fechar);
        regiao.appendChild(notificacao);

        while (regiao.children.length > 4) {
            regiao.firstElementChild.remove();
        }

        // Com acao (ex.: Desfazer) fica mais tempo, para dar tempo de reagir.
        let tempoRestante = Number(opcoes && opcoes.duration) || (acao && typeof acao.onClick === 'function' ? Math.max(10000, durations[tipoNormalizado]) : durations[tipoNormalizado]);
        let iniciadoEm = Date.now();
        let temporizador = null;
        let removida = false;

        function remover() {
            if (removida) {
                return;
            }
            removida = true;
            window.clearTimeout(temporizador);
            notificacao.classList.add('is-leaving');
            window.setTimeout(() => notificacao.remove(), 180);
        }

        function pausar() {
            if (!temporizador) {
                return;
            }
            window.clearTimeout(temporizador);
            temporizador = null;
            tempoRestante = Math.max(0, tempoRestante - (Date.now() - iniciadoEm));
        }

        function retomar() {
            if (removida || temporizador) {
                return;
            }
            iniciadoEm = Date.now();
            temporizador = window.setTimeout(remover, tempoRestante);
        }

        fechar.addEventListener('click', remover);
        notificacao.addEventListener('mouseenter', pausar);
        notificacao.addEventListener('mouseleave', retomar);
        notificacao.addEventListener('focusin', pausar);
        notificacao.addEventListener('focusout', retomar);
        retomar();

        return notificacao;
    }

    function guardarParaProximaPagina(mensagem, tipo) {
        try {
            window.sessionStorage.setItem(storageKey, JSON.stringify({
                message: String(mensagem == null ? '' : mensagem),
                type: normalizarTipo(tipo || 'success')
            }));
            return true;
        } catch (error) {
            console.error('Não foi possível guardar a notificação para a próxima página.', error);
            return false;
        }
    }

    function mostrarNotificacaoGuardada() {
        let pendente;
        try {
            pendente = window.sessionStorage.getItem(storageKey);
            if (pendente) {
                window.sessionStorage.removeItem(storageKey);
            }
        } catch (error) {
            console.error('Não foi possível recuperar a notificação guardada.', error);
            return;
        }

        if (!pendente) {
            return;
        }

        try {
            const dados = JSON.parse(pendente);
            mostrar(dados.message, dados.type);
        } catch (error) {
            console.error('A notificação guardada está inválida.', error);
        }
    }

    window.NivoraFeedback = Object.freeze({
        show: (mensagem, tipo, opcoes) => mostrar(mensagem, tipo, opcoes),
        success: (mensagem, opcoes) => mostrar(mensagem, 'success', opcoes),
        error: (mensagem, opcoes) => mostrar(mensagem, 'error', opcoes),
        warning: (mensagem, opcoes) => mostrar(mensagem, 'warning', opcoes),
        info: (mensagem, opcoes) => mostrar(mensagem, 'info', opcoes),
        reloadWithMessage: (mensagem, tipo) => {
            if (!guardarParaProximaPagina(mensagem, tipo)) {
                mostrar(mensagem, tipo, { duration: 3500 });
                window.setTimeout(() => window.location.reload(), 3500);
                return true;
            }
            window.location.reload();
            return true;
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mostrarNotificacaoGuardada, { once: true });
    } else {
        mostrarNotificacaoGuardada();
    }
})();
