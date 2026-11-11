// Pedidos - Atualizacao em tempo real (SSE/polling) e arranque da pagina.
// Parte de pedidos.php; carregado por ordem com os outros ficheiros de js/pedidos/.

        function refreshPedidosData() {
            var url = 'api/pedidos_snapshot.php?ts=' + Date.now();
            if (pedidosFilter) {
                url += '&filter=' + encodeURIComponent(pedidosFilter);
            }
            return fetch(url)
                .then(function(r) {
                    if (!r.ok) {
                        throw new Error('HTTP ' + r.status);
                    }
                    return r.json();
                })
                .then(function(data) {
                    if (data && data.success) {
                        renderPedidos(data);
                        return true;
                    }
                    throw new Error((data && data.message) ? data.message : 'Falha ao atualizar pedidos');
                })
                .catch(function(err) {
                    console.warn('[pedidos] refresh falhou:', err);
                    return false;
                });
        }

        // Verificacao leve: pergunta so "mudou?" e recarrega a lista quando ha novidade
        // (js/nv_operacao.js). Sem nv_operacao.js, volta ao recarregamento a cada 4 s.
        function startPollingFallback() {
            if (pollingTimer) return;
            if (window.NvOperacao) {
                pollingTimer = window.NvOperacao.vigiar({ intervalo: 4000, forcarCada: 60000, aoMudar: function() { refreshPedidosData(); } });
            } else {
                var id = setInterval(function() { refreshPedidosData(); }, 4000);
                pollingTimer = { parar: function() { clearInterval(id); } };
            }
        }

        function stopPollingFallback() {
            if (pollingTimer) {
                pollingTimer.parar();
                pollingTimer = null;
            }
        }

        function reconnectRealtime() {
            if (sse) return;
            setTimeout(function() {
                if (!sse) {
                    setupRealtime();
                }
            }, 1500);
        }

        function pauseRealtime() {
            var paused = false;
            if (sse) {
                try { sse.close(); } catch (e) {}
                sse = null;
                paused = true;
            }
            if (pollingTimer) {
                stopPollingFallback();
                paused = true;
            }
            return paused;
        }

        function resumeRealtime(wasPaused) {
            if (!wasPaused) return;
            // Em localhost o PHP built-in é single-thread: só polling.
            setupRealtime();
        }

        function withRealtimePaused(asyncWork) {
            var paused = pauseRealtime();
            return Promise.resolve()
                .then(asyncWork)
                .finally(function() {
                    resumeRealtime(paused);
                });
        }

        function setupRealtime() {
            var isLocal = /^(localhost|127\.0\.0\.1)$/i.test(location.hostname || '');
            // SSE em PHP ocupa um processo do servidor por cada ecra aberto: com muitos restaurantes
            // esgota o alojamento. Por omissao usa a verificacao leve (+ servidor de tempo real, se
            // existir). O SSE so liga se a pagina pedir explicitamente (window.NV_PEDIDOS_SSE = true).
            if (isLocal || !window.EventSource || window.NV_PEDIDOS_SSE !== true) {
                var badge = document.getElementById('rtBadge');
                if (badge) {
                    badge.textContent = isEmpresarialUi ? 'Em tempo real' : 'Tempo real';
                    badge.className = isEmpresarialUi ? 'pe-live' : 'pedidos-live-chip';
                }
                startPollingFallback();
                return;
            }

            if (sse) return;

            var sseUrl = 'api/pedido_sse.php';
            if (pedidosFilter) {
                sseUrl += '?filter=' + encodeURIComponent(pedidosFilter);
            }
            sse = new EventSource(sseUrl);

            sse.onopen = function() {
                var badge = document.getElementById('rtBadge');
                if (badge) {
                    badge.textContent = isEmpresarialUi ? 'Em tempo real' : 'Tempo real';
                    badge.className = isEmpresarialUi ? 'pe-live' : 'pedidos-live-chip';
                }
                stopPollingFallback();
            };

            sse.addEventListener('pedidos', function(event) {
                try {
                    var data = JSON.parse(event.data);
                    renderPedidos(data);
                } catch (e) {}
            });

            sse.onerror = function() {
                var badge = document.getElementById('rtBadge');
                if (badge) {
                    badge.textContent = 'Reconectando';
                    badge.className = isEmpresarialUi ? 'pe-live' : 'pedidos-live-chip is-reconnect';
                }
                if (sse) {
                    try { sse.close(); } catch (e2) {}
                    sse = null;
                }
                startPollingFallback();
                reconnectRealtime();
            };
        }

        window.addEventListener('beforeunload', function() {
            if (sse) {
                sse.close();
                sse = null;
            }
            if (pollingTimer) {
                pollingTimer.parar();
                pollingTimer = null;
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            // pedidos.php?pedido=N (ex.: check-in de reserva com pre-pedido): abrir logo o detalhe.
            var pedidoUrl = parseInt(new URLSearchParams(window.location.search).get('pedido') || '0', 10);
            if (pedidoUrl > 0) {
                if (isEmpresarialUi) selectedEmpPedidoId = pedidoUrl;
                setTimeout(function() { verItensPedido(pedidoUrl); }, 0);
            }

            soundEnabled = localStorage.getItem('edvis_sound_alerts') === '1';
            updateSoundBanner();
            document.getElementById('sound-enable-btn')?.addEventListener('click', function() {
                enableSoundAlerts().then(pintarBotaoSomPedidos);
            });
            pintarBotaoSomPedidos();

            if (isEmpresarialUi) {
                document.querySelectorAll('.pe-tab').forEach(function(tab) {
                    tab.addEventListener('click', function() {
                        var next = tab.getAttribute('data-pe-tab') || 'todos';
                        if (next === peTabFilter) return;
                        peTabFilter = next;
                        document.querySelectorAll('.pe-tab').forEach(function(t) {
                            t.classList.toggle('is-active', t === tab);
                        });
                        var area = document.getElementById('pedidosArea');
                        if (area) area.innerHTML = buildEmpTableHtml(currentPedidos);
                        var filtered = filterEmpPedidos(currentPedidos);
                        var stillVisible = selectedEmpPedidoId && filtered.some(function(p) {
                            return parseInt(p.id, 10) === parseInt(selectedEmpPedidoId, 10);
                        });
                        if (!stillVisible) {
                            selectedEmpPedidoId = filtered.length ? parseInt(filtered[0].id, 10) : null;
                        }
                        if (selectedEmpPedidoId) {
                            selectEmpPedido(selectedEmpPedidoId);
                        } else {
                            renderEmpDetail(null);
                        }
                    });
                });

                var btnVerTodos = document.getElementById('btnPeVerTodos');
                if (btnVerTodos) {
                    btnVerTodos.addEventListener('click', function(e) {
                        e.preventDefault();
                        peResetFiltroTodos();
                    });
                }
            }

            try {
                var canvas = document.getElementById('qrCodeCanvas');
                if (canvas && cardapioUrl && typeof qrcode === 'function') {
                    var qr = qrcode(0, 'M');
                    qr.addData(cardapioUrl);
                    qr.make();
                    var mc = qr.getModuleCount();
                    var cell = Math.floor(116 / mc);
                    canvas.width = cell * mc;
                    canvas.height = cell * mc;
                    var ctx = canvas.getContext('2d');
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, canvas.width, canvas.height);
                    ctx.fillStyle = '#000000';
                    for (var r = 0; r < mc; r++) {
                        for (var c = 0; c < mc; c++) {
                            if (qr.isDark(r, c)) {
                                ctx.fillRect(c * cell, r * cell, cell, cell);
                            }
                        }
                    }
                }
            } catch (e) {}

            renderPedidos({
                pedidos: initialPedidosData,
                contadores: initialContadores
            });
            detectReadyAlerts(initialPedidosData || []);

            // Em localhost usa só polling (evita SSE bloquear APIs no php -S).
            setupRealtime();
            refreshPedidosData();
        });

        function forcarAtualizacaoPedidos(btn) {
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> A atualizar';
            }

            refreshPedidosData().finally(function() {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-sync-alt me-1"></i> Atualizar';
                }
            });
        }

        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) {
                refreshPedidosData();
            }
        });
