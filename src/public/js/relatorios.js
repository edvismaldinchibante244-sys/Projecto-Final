// Valores do servidor: <script type="application/json" id="cfg-relatorios"> na pagina.
var __NIVORA_RELATORIOS = JSON.parse(document.getElementById('cfg-relatorios').textContent);
        function construirUrlRelatorio(extraParams) {
            var form = document.getElementById('filtroRelatorio');
            var params = form
                ? new URLSearchParams(new FormData(form))
                : new URLSearchParams(window.location.search);

            Object.keys(extraParams || {}).forEach(function(key) {
                params.set(key, extraParams[key]);
            });

            return 'relatorios.php?' + params.toString();
        }

        const canExportRelatorio = __NIVORA_RELATORIOS.v0;
        const canPrintRelatorio = __NIVORA_RELATORIOS.v1;
        const canExportPdf = __NIVORA_RELATORIOS.v2;

        function exportarRelatorio() {
            if (!canExportRelatorio) {
                NivoraFeedback.warning('Seu plano atual não permite exportação de relatórios.');
                return;
            }
            window.location.href = construirUrlRelatorio({
                export: 'excel'
            });
        }

        function imprimirRelatorio() {
            if (!canPrintRelatorio) {
                NivoraFeedback.warning('Seu plano atual não permite impressão de relatórios.');
                return;
            }
            var tabela = document.getElementById('tabelaRelatorio');
            var card = document.getElementById('relatorioConteudoCard');
            var resumo = document.getElementById('relatorioResumoCards');
            var printWindow = window.open('', '_blank', 'width=1200,height=900');

            if (!printWindow || !tabela || !card) {
                window.print();
                return;
            }

            var titulo = __NIVORA_RELATORIOS.v3;
            var restaurante = __NIVORA_RELATORIOS.v4;
            var periodo = __NIVORA_RELATORIOS.v5;
            var resumoHtml = resumo ? resumo.innerHTML : '';
            var tabelaHtml = card.innerHTML;
            var notasEl = document.getElementById('relEmpNotasPrint');
            var chartEl = document.getElementById('relEmpChartCard');
            var notasHtml = notasEl ? ('<div class="notes-block">' + notasEl.innerHTML + '</div>') : '';
            var chartHtml = chartEl ? ('<div class="chart-block">' + chartEl.innerHTML + '</div>') : '';

            printWindow.document.write('<!DOCTYPE html><html lang="pt"><head><meta charset="UTF-8"><title>' + titulo + '</title><link rel="icon" href="favicon.ico" type="image/x-icon"><style>' +
                'body{font-family:Segoe UI,Arial,sans-serif;padding:32px;color:#0f172a;background:#fff;}' +
                '.header{display:flex;justify-content:space-between;gap:24px;align-items:flex-start;margin-bottom:24px;padding-bottom:18px;border-bottom:2px solid #e2e8f0;}' +
                '.brand{max-width:70%;}.brand h1{margin:0 0 6px;font-size:28px;color:#0f172a;}.brand p{margin:0;color:#475569;font-size:14px;}' +
                '.meta{text-align:right;font-size:13px;color:#475569;}' +
                '.summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin:24px 0;}' +
                '.summary .col-md-3{width:auto;flex:none;}' +
                '.report-stat-card{border:1px solid #e2e8f0;border-radius:18px;padding:16px;background:#fff;box-shadow:none;break-inside:avoid;}' +
                '.report-stat-label{font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#64748b;margin:0 0 8px;}' +
                '.report-stat-value{font-size:28px;font-weight:800;color:#0f172a;margin:0;line-height:1.1;}' +
                '.report-stat-icon{display:none;}' +
                '.notes-block,.chart-block{margin:18px 0 22px;}' +
                '.rel-emp-notes{list-style:none;margin:0;padding:0;}' +
                '.rel-emp-notes li{border:1px solid #fed7aa;border-radius:12px;padding:10px 12px;margin-bottom:8px;background:#fff7ed;}' +
                '.rel-emp-notes strong{display:block;margin-bottom:4px;}' +
                '.card{border:1px solid #e2e8f0;border-radius:18px;overflow:hidden;box-shadow:none;}' +
                '.card-header{padding:18px 22px;border-bottom:1px solid #e2e8f0;background:#f8fafc;}' +
                '.card-title{margin:0;font-size:22px;color:#0f172a;}' +
                '.card-body{padding:0;}' +
                'table{width:100%;border-collapse:collapse;}' +
                'th,td{padding:12px 14px;border-bottom:1px solid #e2e8f0;text-align:left;font-size:13px;}' +
                'th{background:#f8fafc;color:#334155;font-weight:800;text-transform:uppercase;}' +
                '.badge{display:inline-block;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700;}' +
                '.bg-success{background:#dcfce7;color:#166534;}.bg-warning{background:#fef3c7;color:#92400e;}.bg-danger{background:#fee2e2;color:#991b1b;}.bg-info,.bg-primary{background:#dbeafe;color:#1d4ed8;}' +
                '.table-responsive{overflow:visible;}' +
                '@media print{body{padding:18px;}.summary{grid-template-columns:repeat(2,minmax(0,1fr));}}' +
                '</style></head><body>' +
                '<div class="header"><div class="brand"><h1>' + restaurante + '</h1><p>' + titulo + '</p><p>Período: ' + periodo + '</p></div><div class="meta"><div>Gerado em</div><strong>' + new Date().toLocaleString('pt-BR') + '</strong></div></div>' +
                (resumoHtml ? '<div class="summary">' + resumoHtml + '</div>' : '') +
                chartHtml +
                notasHtml +
                '<div class="report-content">' + tabelaHtml + '</div>' +
                '</body></html>');

            printWindow.document.close();
            printWindow.focus();
            setTimeout(function() {
                printWindow.print();
            }, 250);
        }

        function exportarPDF() {
            if (!canExportPdf) {
                NivoraFeedback.warning('Seu plano atual não permite exportação em PDF.');
                return;
            }
            imprimirRelatorio();
        }

        function atualizarResumoTabela() {
            const table = document.getElementById('tabelaRelatorio');
            const card = document.getElementById('relatorioConteudoCard');
            if (!table || !card || !table.classList.contains('table-advanced')) return;
            const tbody = table.querySelector('tbody');
            if (!tbody) return;
            const total = tbody.querySelectorAll('tr').length;
            let footer = card.querySelector('.table-summary');
            if (!footer) {
                footer = document.createElement('div');
                footer.className = 'table-summary';
                card.appendChild(footer);
            }
            footer.textContent = 'Total de registros: ' + total;
        }

        document.addEventListener('DOMContentLoaded', atualizarResumoTabela);
    
