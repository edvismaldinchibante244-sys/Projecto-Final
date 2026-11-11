// Pedidos - Valores do servidor, estado, utilitarios, sons de pedido pronto e estados/origens.
// Parte de pedidos.php; carregado por ordem com os outros ficheiros de js/pedidos/.

// Valores do servidor: <script type="application/json" id="cfg-pedidos"> na pagina.
var __NIVORA_PEDIDOS = JSON.parse(document.getElementById('cfg-pedidos').textContent);
        var cardapioUrl = __NIVORA_PEDIDOS.v0;
        var todasMesas = __NIVORA_PEDIDOS.v1;
        var mesaQrUrls = __NIVORA_PEDIDOS.v2;
        var initialPedidosData = __NIVORA_PEDIDOS.v3;
        var initialContadores = __NIVORA_PEDIDOS.v4;
        var pedidosFilter = __NIVORA_PEDIDOS.v5;
        var sse = null;
        var pollingTimer = null;
        var currentPedidos = Array.isArray(initialPedidosData) ? initialPedidosData : [];
        var pedidoSelecionadoId = null;
        var canReceivePayment = __NIVORA_PEDIDOS.v6;
        var canManageOrderFlow = __NIVORA_PEDIDOS.v7;
        var canRequestBill = __NIVORA_PEDIDOS.v8;
        var canCancelOrder = __NIVORA_PEDIDOS.v9;
        var userPerfilPedidosJs = __NIVORA_PEDIDOS.v10;
        var CSRF_TOKEN = __NIVORA_PEDIDOS.v11;
        OfflineSync.init({ csrfToken: CSRF_TOKEN, syncIntervalMs: 10000 });
        var isEmpresarialUi = __NIVORA_PEDIDOS.v12;
        var FILA_SLA_MINUTOS = __NIVORA_PEDIDOS.v13;
        var peTabFilter = 'todos';
        var selectedEmpPedidoId = null;
        var PEDIDOS_ONTEM = __NIVORA_PEDIDOS.v14;
        var isProfissionalUi = __NIVORA_PEDIDOS.v15 === true;
        // Atrasos sao assinalados no Profissional e no Empresarial.
        var marcarAtrasos = isEmpresarialUi || isProfissionalUi;

        function getPedidoAgeMinutes(criadoEm) {
            return window.NivoraPedidoTempo.ageMinutes(criadoEm);
        }

        function calcFilaOps(pedidos) {
            var lista = Array.isArray(pedidos) ? pedidos : [];
            var activos = 0;
            var atrasados = 0;
            var esperaMax = 0;
            lista.forEach(function(p) {
                var st = normalizarStatus(p && p.status);
                if (st !== 'NOVO' && st !== 'PREPARANDO' && st !== 'PRONTO') return;
                activos++;
                var mins = getPedidoAgeMinutes(p.criado_em);
                if (mins > esperaMax) esperaMax = mins;
                if (mins >= FILA_SLA_MINUTOS) atrasados++;
            });
            return { activos: activos, atrasados: atrasados, esperaMax: esperaMax };
        }

        function updateFilaOpsUi(pedidos, contadores) {
            var ops = calcFilaOps(pedidos);
            var filaEl = document.getElementById('kpiFilaValue');
            var hintEl = document.getElementById('kpiFilaHint');
            var atrEl = document.getElementById('countAtrasados');
            if (isEmpresarialUi) {
                if (filaEl) filaEl.textContent = ops.activos + ' na fila';
                if (hintEl) {
                    hintEl.textContent = ops.esperaMax > 0
                        ? ('Espera máx. ' + ops.esperaMax + ' min · SLA ' + FILA_SLA_MINUTOS + ' min')
                        : ('SLA ' + FILA_SLA_MINUTOS + ' min · sem espera activa');
                }
                if (atrEl) atrEl.textContent = String(ops.atrasados);
            } else if (filaEl && contadores) {
                var total = 0;
                Object.keys(contadores).forEach(function(k) { total += Number(contadores[k] || 0); });
                filaEl.textContent = total + ' pedidos';
            }
        }

        function gerarQRDataURL(url, size) {
            var qr = qrcode(0, 'M');
            qr.addData(url);
            qr.make();
            var mc = qr.getModuleCount();
            var cell = Math.ceil(size / mc);
            var cv = document.createElement('canvas');
            cv.width = cell * mc;
            cv.height = cell * mc;
            var ctx = cv.getContext('2d');
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, cv.width, cv.height);
            ctx.fillStyle = '#000000';
            for (var r = 0; r < mc; r++) {
                for (var c = 0; c < mc; c++) {
                    if (qr.isDark(r, c)) {
                        ctx.fillRect(c * cell, r * cell, cell, cell);
                    }
                }
            }
            return cv.toDataURL('image/png');
        }

        function escapeHtml(value) {
            return String(value || '').replace(/[&<>'"]/g, function(char) {
                return ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    "'": '&#39;',
                    '"': '&quot;'
                })[char];
            });
        }

        function formatMoney(value) {
            var n = parseFloat(value || 0).toFixed(2).replace('.', ',');
            return isEmpresarialUi ? ('MZN ' + n) : (n + ' MZN');
        }

        let audioCtx = null;
        let soundEnabled = false;
        let previousReadyIds = new Set();

        function getAudioCtx() {
            if (!audioCtx) audioCtx = new(window.AudioContext || window.webkitAudioContext)();
            return audioCtx;
        }

        function playBeep(freq, dur, volume = 0.5) {
            if (!soundEnabled) return;
            try {
                var ctx = getAudioCtx();
                var osc = ctx.createOscillator();
                var gain = ctx.createGain();
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.type = 'sine';
                osc.frequency.setValueAtTime(freq, ctx.currentTime);
                gain.gain.setValueAtTime(volume, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + dur);
                osc.start(ctx.currentTime);
                osc.stop(ctx.currentTime + dur);
            } catch (e) {}
        }

        function playReadySound() {
            playBeep(1046, 0.18, 0.62);
            setTimeout(function() { playBeep(1175, 0.18, 0.65); }, 180);
            setTimeout(function() { playBeep(1318, 0.25, 0.7); }, 360);
            setTimeout(function() { playBeep(1568, 0.3, 0.75); }, 620);
        }

        function updateSoundBanner() {
            var banner = document.getElementById('sound-banner');
            if (banner) banner.classList.toggle('show', !soundEnabled);
        }

        async function enableSoundAlerts() {
            try {
                var ctx = getAudioCtx();
                if (ctx.state === 'suspended') await ctx.resume();
                soundEnabled = true;
                localStorage.setItem('edvis_sound_alerts', '1');
                updateSoundBanner();
                playBeep(880, 0.12, 0.4);
            } catch (e) {}
        }

        function normalizarStatus(status) {
            var s = String(status || '').toUpperCase();
            if (s === 'PENDENTE') return 'NOVO';
            if (s === 'CONFIRMADO') return 'PREPARANDO';
            if (s === 'CONTA_SOLICITADA' || s === 'PEDIR_CONTA') return 'CONTA';
            if (['NOVO', 'PREPARANDO', 'PRONTO', 'ENTREGUE', 'CONTA', 'PAGO', 'CANCELADO'].indexOf(s) >= 0) return s;
            return 'NOVO';
        }

        function podeAvancarStatus(statusAtual, proximo) {
            var st = normalizarStatus(statusAtual);
            var nx = normalizarStatus(proximo);
            var perfil = String(userPerfilPedidosJs || '').toUpperCase();
            if (perfil === 'GARÇOM' || perfil === 'OPERADOR') perfil = 'GARCOM';
            if (perfil === 'ADMIN' || perfil === 'GERENTE') return true;
            if (nx === 'CONTA') return canRequestBill;
            if (st === 'PRONTO' && nx === 'ENTREGUE') {
                return canManageOrderFlow || perfil === 'GARCOM';
            }
            if (st === 'NOVO' || st === 'PREPARANDO') {
                return canManageOrderFlow && perfil !== 'GARCOM';
            }
            return canManageOrderFlow;
        }

        function normalizarOrigemPedido(origem) {
            var valor = String(origem || 'BALCAO').trim().toUpperCase();
            if (['QR', 'GARCOM', 'BALCAO'].indexOf(valor) === -1) {
                return 'BALCAO';
            }
            return valor;
        }

        function getOrigemPedidoMeta(origem) {
            var valor = normalizarOrigemPedido(origem);
            if (valor === 'QR') {
                return {
                    label: 'QR',
                    icon: 'fa-qrcode',
                    className: 'qr'
                };
            }

            if (valor === 'GARCOM') {
                return {
                    label: 'Garçom',
                    icon: 'fa-user-tie',
                    className: 'garcom'
                };
            }

            return {
                label: 'Balcão',
                icon: 'fa-store',
                className: 'balcao'
            };
        }

        function getMesaPedidoLabel(ped, fallback) {
            var raw = ped && ped.mesa_numero !== undefined && ped.mesa_numero !== null ? String(ped.mesa_numero).trim() : '';
            if (raw !== '' && raw !== '0') {
                return 'Mesa ' + raw;
            }
            if (fallback) return fallback;
            var origemMeta = getOrigemPedidoMeta(ped && ped.origem);
            return origemMeta.className === 'balcao' ? 'Take Away' : origemMeta.label;
        }

        // --- Aviso sonoro de pedido novo (Profissional) -------------------------
        // Usa o mesmo interruptor de som dos pedidos prontos (edvis_sound_alerts),
        // para haver uma so preferencia de som nesta pagina.
        var somPedidosIdsVistos = null;

        function pintarBotaoSomPedidos() {
            var btn = document.getElementById('btnSomPedidos');
            if (!btn) return;
            var icone = btn.querySelector('i');
            var texto = btn.querySelector('span');
            if (icone) icone.className = soundEnabled ? 'fas fa-bell me-1' : 'fas fa-bell-slash me-1';
            if (texto) texto.textContent = soundEnabled ? 'Som ligado' : 'Som desligado';
            btn.classList.toggle('btn-outline-secondary', !soundEnabled);
            btn.classList.toggle('btn-success', soundEnabled);
        }

        async function alternarSomPedidos() {
            if (soundEnabled) {
                soundEnabled = false;
                try { localStorage.setItem('edvis_sound_alerts', '0'); } catch (e) {}
            } else {
                await enableSoundAlerts();
            }
            updateSoundBanner();
            pintarBotaoSomPedidos();
        }

        function tocarAvisoPedidoNovo() {
            playBeep(880, 0.16, 0.5);
            setTimeout(function() { playBeep(1180, 0.18, 0.55); }, 170);
        }

        // Toca quando aparece um pedido que ainda nao estava na lista.
        function verificarPedidosNovosParaSom(pedidos) {
            if (!isProfissionalUi) return;
            var lista = Array.isArray(pedidos) ? pedidos : [];
            var ids = {};
            lista.forEach(function(p) { if (p && p.id) ids[String(p.id)] = true; });
            if (somPedidosIdsVistos === null) {
                somPedidosIdsVistos = ids;
                return;
            }
            var temNovo = false;
            Object.keys(ids).forEach(function(id) {
                if (!somPedidosIdsVistos[id]) temNovo = true;
            });
            somPedidosIdsVistos = ids;
            if (temNovo) tocarAvisoPedidoNovo();
        }
