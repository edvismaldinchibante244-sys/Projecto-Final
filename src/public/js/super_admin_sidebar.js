/*
 * Menu lateral das paginas de super admin.
 *
 * Fonte unica: antes disto cada pagina tinha a sua propria copia desta
 * logica, e elas tinham divergido -- uma fechava ao escolher um item, outra
 * nao, nenhuma fechava com Escape e nenhuma travava a rolagem da pagina.
 *
 * Mantem as classes que o CSS ja usava: sidebar-hidden na barra, show no
 * veu, layout-sidebar-collapsed no body e is-docked no botao.
 */
(function () {
    'use strict';

    var CONSULTA_MOVEL = '(max-width: 991px)';
    var mq = window.matchMedia(CONSULTA_MOVEL);
    var posicaoGuardada = 0;

    function emEcraMovel() {
        return mq.matches;
    }

    function barra() {
        return document.querySelector('.sidebar');
    }

    function veu() {
        return document.getElementById('sidebarBackdrop');
    }

    function botao() {
        return document.getElementById('btnMenuToggle');
    }

    function estaAberta() {
        var el = barra();
        return !!el && !el.classList.contains('sidebar-hidden');
    }

    /*
     * Travar a pagina com position:fixed, e nao so com overflow:hidden: o
     * Safari do iOS ignora o overflow nesta situacao e a pagina continuava a
     * arrastar por tras do menu. O deslocamento negativo guarda a posicao,
     * que e reposta ao fechar.
     */
    function travarPagina(travar) {
        var corpo = document.body;
        if (!corpo) {
            return;
        }

        if (travar) {
            if (corpo.classList.contains('nv-pagina-travada')) {
                return;
            }
            posicaoGuardada = window.pageYOffset || document.documentElement.scrollTop || 0;
            corpo.style.top = (-posicaoGuardada) + 'px';
            corpo.classList.add('nv-pagina-travada');
            return;
        }

        if (!corpo.classList.contains('nv-pagina-travada')) {
            return;
        }
        corpo.classList.remove('nv-pagina-travada');
        corpo.style.top = '';
        window.scrollTo(0, posicaoGuardada);
    }

    function actualizarBotao(aberta) {
        var btn = botao();
        if (!btn) {
            return;
        }

        var rotulo = aberta ? 'Fechar menu' : 'Abrir menu';
        btn.setAttribute('aria-expanded', aberta ? 'true' : 'false');
        btn.setAttribute('aria-label', rotulo);
        btn.setAttribute('title', rotulo);

        var icone = btn.querySelector('i');
        if (icone) {
            icone.classList.remove('fa-bars', 'fa-times');
            icone.classList.add(aberta ? 'fa-times' : 'fa-bars');
        }
    }

    function definirAberta(aberta) {
        var el = barra();
        if (!el) {
            return;
        }

        var movel = emEcraMovel();
        var v = veu();
        var btn = botao();

        el.classList.toggle('sidebar-hidden', !aberta);
        el.setAttribute('aria-hidden', (movel && !aberta) ? 'true' : 'false');

        if (movel) {
            if (v) {
                v.classList.toggle('show', !!aberta);
            }
            document.body.classList.remove('layout-sidebar-collapsed');
            if (btn) {
                btn.classList.remove('is-docked');
            }
            travarPagina(!!aberta);
        } else {
            if (v) {
                v.classList.remove('show');
            }
            document.body.classList.toggle('layout-sidebar-collapsed', !aberta);
            if (btn) {
                btn.classList.toggle('is-docked', !!aberta);
            }
            travarPagina(false);
        }

        actualizarBotao(aberta);
    }

    function alternar() {
        definirAberta(!estaAberta());
    }

    function fechar() {
        definirAberta(false);
    }

    /*
     * Com o menu sobreposto, o Tab nao deve sair para a pagina por tras: sem
     * isto o foco desaparecia para links escondidos e a pessoa deixava de
     * saber onde estava.
     */
    function prenderFoco(evento) {
        if (evento.key !== 'Tab' || !emEcraMovel() || !estaAberta()) {
            return;
        }

        var el = barra();
        if (!el) {
            return;
        }

        var focaveis = el.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        );
        var visiveis = [];
        for (var i = 0; i < focaveis.length; i++) {
            if (focaveis[i].offsetParent !== null) {
                visiveis.push(focaveis[i]);
            }
        }

        var btn = botao();
        if (btn) {
            visiveis.unshift(btn);
        }
        if (!visiveis.length) {
            return;
        }

        var primeiro = visiveis[0];
        var ultimo = visiveis[visiveis.length - 1];

        if (evento.shiftKey && document.activeElement === primeiro) {
            evento.preventDefault();
            ultimo.focus();
        } else if (!evento.shiftKey && document.activeElement === ultimo) {
            evento.preventDefault();
            primeiro.focus();
        }
    }

    function sincronizarComEcra() {
        // No computador a barra fica sempre visivel; no telemovel comeca fechada.
        definirAberta(!emEcraMovel());
    }

    function ligar() {
        var btn = botao();
        if (btn) {
            btn.addEventListener('click', function (evento) {
                evento.preventDefault();
                alternar();
            });
        }

        var v = veu();
        if (v) {
            v.addEventListener('click', fechar);
        }

        // Ao escolher um item a barra fecha antes de a pagina mudar: senao a
        // pessoa chega a pagina nova com o menu aberto por cima.
        var el = barra();
        if (el) {
            var ligacoes = el.querySelectorAll('a[href]');
            for (var i = 0; i < ligacoes.length; i++) {
                ligacoes[i].addEventListener('click', function () {
                    if (emEcraMovel()) {
                        fechar();
                    }
                });
            }
        }

        document.addEventListener('keydown', function (evento) {
            if (evento.key === 'Escape' && emEcraMovel() && estaAberta()) {
                fechar();
                var b = botao();
                if (b) {
                    b.focus();
                }
                return;
            }
            prenderFoco(evento);
        });

        window.addEventListener('resize', sincronizarComEcra);
        sincronizarComEcra();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ligar);
    } else {
        ligar();
    }

    // As paginas antigas chamavam estes nomes directamente.
    window.setSidebarOpen = definirAberta;
    window.isMobileViewport = emEcraMovel;
})();
