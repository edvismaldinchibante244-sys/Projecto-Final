// Dashboard do administrador: actualiza os números do topo sem recarregar e guarda a meta do dia.
(function () {
    'use strict';

    var kpis = document.getElementById('dashEmpKpis') || document.querySelector('[data-dash-auto]');
    // Mesmo formato do servidor: 4.500,00
    var fmt = function (n) {
        var partes = Number(n || 0).toFixed(2).split('.');
        return partes[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ',' + partes[1];
    };
    var por = function (campo, texto) {
        document.querySelectorAll('[data-dash-campo="' + campo + '"]').forEach(function (el) {
            if (el.textContent !== texto) { el.textContent = texto; }
        });
    };

    function actualizar() {
        if (document.visibilityState === 'hidden') { return; }
        fetch('api/dashboard_resumo.php', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d || !d.success) { return; }
                por('total_hoje', fmt(d.total_hoje) + ' MZN');
                por('total_hoje_num', fmt(d.total_hoje));
                por('resumo_hoje', d.qtd_hoje + ' venda(s) · ticket ' + fmt(d.ticket));
                por('fila_total', String(d.fila_total));
                por('qtd_hoje_txt', d.qtd_hoje + ' vendas');
                por('ticket_txt', 'Ticket médio: MZN ' + fmt(d.ticket));
                document.querySelectorAll('[data-dash-campo="salao_simples"]').forEach(function (el) {
                    var total = el.getAttribute('data-total') || d.mesas_total;
                    el.textContent = Math.min(Number(d.mesas_ocupadas), Number(total)) + '/' + total;
                });
                // O total de mesas vem da própria página (a API não conhece os filtros do salão).
                document.querySelectorAll('[data-dash-campo="salao"]').forEach(function (el) {
                    var total = el.getAttribute('data-total') || d.mesas_total;
                    var ocupadas = Math.min(Number(d.mesas_ocupadas), Number(total));
                    el.textContent = ocupadas + '/' + total + ' · ' + (d.caixa_aberto ? 'Aberto' : 'Fechado');
                });
                var hora = document.getElementById('dashAutoHora');
                if (hora) { hora.textContent = 'actualizado às ' + d.hora; }
            })
            .catch(function () { /* sem ligação: tenta na próxima volta */ });
    }

    if (kpis) {
        setInterval(actualizar, 30000);
        document.addEventListener('visibilitychange', actualizar);
    }

    var form = document.getElementById('formMetaDia');
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var campo = document.getElementById('dashMetaValor');
            var corpo = new FormData();
            corpo.append('meta', (campo && campo.value) ? campo.value : '0');
            corpo.append('_csrf', form.dataset.csrf || '');
            fetch('api/dashboard_resumo.php', {
                method: 'POST',
                body: corpo,
                credentials: 'same-origin',
                headers: { 'X-CSRF-Token': form.dataset.csrf || '', 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.success) {
                        window.location.reload();
                    } else if (window.NivoraFeedback) {
                        NivoraFeedback.error(d.message || 'Não foi possível guardar a meta.');
                    }
                })
                .catch(function () {
                    if (window.NivoraFeedback) { NivoraFeedback.error('Erro de ligação ao guardar a meta.'); }
                });
        });
    }
})();
