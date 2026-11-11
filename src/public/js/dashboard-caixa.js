// Dashboard Caixa JS
// Remove PHP from JS file - load via inline script in dashboard.php
const perfil = 'CAIXA'; // Hardcoded for this dashboard

if (perfil === 'CAIXA') {
    const caixaContainer = document.getElementById('caixa-stats');
    
    const loadCaixaStats = async () => {
        try {
            const [vendasRes, pagamentosRes, turnoRes, pedidosRes] = await Promise.all([
                fetch('/api/caixa_vendas.php'),
                fetch('/api/caixa_pagamentos.php'),
                fetch('/api/caixa_turno.php'),
                fetch('/api/caixa_ultimos_pedidos.php')
            ]);

            const vendas = await vendasRes.json();
            const pagamentos = await pagamentosRes.json();
            const turno = await turnoRes.json();
            const pedidos = await pedidosRes.json();

            // Update stats
            document.querySelector('[data-caixa="vendas-hoje"]').textContent = vendas.vendas_hoje;
            document.querySelector('[data-caixa="ticket-medio"]').textContent = vendas.ticket_medio;
            
            // Pagamentos pie
            const pagamentosCtx = document.getElementById('pagamentosChart').getContext('2d');
            new Chart(pagamentosCtx, {
                type: 'doughnut',
                data: {
                    labels: Object.keys(pagamentos),
                    datasets: [{
                        data: Object.values(pagamentos).map(p => parseFloat(p.total.replace(/,/g, '.'))),
                        backgroundColor: ['#10b981', '#3b82f6', '#f59e0b', '#ef4444', '#8b5cf6']
                    }]
                },
                options: { plugins: { legend: { position: 'bottom' } } }
            });

            // Turno
            document.querySelector('[data-caixa="total-turno"]').textContent = turno.total_turno;
            document.querySelector('[data-caixa="hora-abertura"]').textContent = turno.hora_abertura;
            document.querySelector('[data-caixa="diferenca"]').textContent = turno.diferenca;
            
            if (turno.aberto) {
                document.querySelector('.caixa-status').textContent = 'ABERTO';
                document.querySelector('.caixa-status').className = 'caixa-status badge-success';
            } else {
                document.querySelector('.caixa-status').textContent = 'FECHADO';
                document.querySelector('.caixa-status').className = 'caixa-status badge-danger';
            }

            // Botão fechar caixa
            document.getElementById('btn-fechar-caixa').onclick = async () => {
                if (await nivoraConfirmar('Fechar caixa atual?')) {
                    fetch('/api/caixa_fechar.php', {method: 'POST'})
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) location.reload();
                    });
                }
            };

            // Últimos pedidos table
            const tbody = document.querySelector('#ultimos-pedidos-tbody');
            tbody.innerHTML = pedidos.map(p => `
                <tr>
                    <td>${p.numero_fatura}</td>
                    <td>${p.criado_em}</td>
                    <td class="${!p.mesa_numero ? 'balcao-label' : ''}">${p.mesa_numero || 'Balcão'}</td>
                    <td>${parseFloat(p.total_final).toLocaleString('pt-PT', {minimumFractionDigits:2})}</td>
                    <td><span class="badge-custom badge-${p.forma_pagamento === 'PAGO' ? 'success' : 'warning'}">${p.forma_pagamento}</span></td>
                    <td><span class="badge-custom badge-success">${p.status}</span></td>
                </tr>
            `).join('');

        } catch (e) {
            console.error('Erro loading caixa stats:', e);
        }
    };

    loadCaixaStats();
    setInterval(loadCaixaStats, 15000); // Refresh 15s
}

