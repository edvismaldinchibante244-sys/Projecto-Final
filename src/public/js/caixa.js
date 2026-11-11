/**
 * ============================================
 * JAVASCRIPT - CAIXA (Bootstrap 5)
 * ============================================
 */

let modalAbrirInstance = null;
let modalFecharInstance = null;

// Inicializar modais quando o DOM estiver pronto
document.addEventListener('DOMContentLoaded', function() {
    // Criar instâncias dos modais Bootstrap
    const modalAbrirEl = document.getElementById('modalAbrir');
    const modalFecharEl = document.getElementById('modalFechar');
    
    if (modalAbrirEl) {
        modalAbrirInstance = new bootstrap.Modal(modalAbrirEl);
    }
    if (modalFecharEl) {
        modalFecharInstance = new bootstrap.Modal(modalFecharEl);
    }
    
    // Form de abertura
    const formAbrir = document.getElementById('formAbrir');
    const previewAbertura = document.getElementById('caixaOpenPreviewValor');
    const inputAbertura = document.getElementById('valor_abertura');

    if (inputAbertura && previewAbertura) {
        const atualizarPreviewAbertura = function () {
            const valor = String(inputAbertura.value || '').trim();
            previewAbertura.textContent = valor !== '' ? (valor + ' MZN') : 'Fundo de gaveta';
        };
        inputAbertura.addEventListener('input', atualizarPreviewAbertura);
        atualizarPreviewAbertura();
    }
    
    if(formAbrir) {
        formAbrir.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const valor = document.getElementById('valor_abertura').value;
            
            if(!valor || parseFloat(valor) < 0) {
                showAlertModal('alertAbrir', 'Digite um valor válido', 'danger');
                return;
            }
            
            const formData = new FormData();
            formData.append('abertura', valor);
            formData.append('_csrf', getCsrfToken());
            
            OfflineSync.fetchQueued('api/caixa_abrir.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: csrfHeaders()
            })
            .then(response => response.json())
            .then(data => {
                if(data.success) {
                    showAlertModal('alertAbrir', data.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlertModal('alertAbrir', data.message, 'danger');
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                showAlertModal('alertAbrir', 'Erro ao abrir caixa', 'danger');
            });
        });
    }
    
    // Form de fechamento
    const formFechar = document.getElementById('formFechar');
    const inputFecho = document.getElementById('valor_fechamento');
    const diffEl = document.getElementById('caixaDiffFecho');
    const btnUsarEsperado = document.getElementById('btnUsarEsperadoGaveta');

    /** Aceita 8983 | 8983.50 | 8.983 | 8.983,00 | 8983,00 (formato MZ). */
    function parseMoneyMz(raw) {
        let s = String(raw == null ? '' : raw).trim().replace(/\s/g, '').replace(/MZN/gi, '');
        if (!s) return NaN;
        s = s.replace(/[^\d.,\-]/g, '');
        if (!s || s === '-' || s === '.' || s === ',') return NaN;

        const neg = s.startsWith('-');
        if (neg) s = s.slice(1);

        let n;
        if (s.includes(',') && s.includes('.')) {
            // 8.983,50 → milhares com ponto, decimal com vírgula
            n = parseFloat(s.replace(/\./g, '').replace(',', '.'));
        } else if (s.includes(',')) {
            // 8983,50
            n = parseFloat(s.replace(/\./g, '').replace(',', '.'));
        } else if (/^\d{1,3}(\.\d{3})+$/.test(s)) {
            // 8.983 ou 1.234.567 → milhares MZ
            n = parseFloat(s.replace(/\./g, ''));
        } else {
            // 8983 ou 8983.50 (decimal com ponto)
            n = parseFloat(s);
        }

        if (!Number.isFinite(n)) return NaN;
        return neg ? -n : n;
    }

    function atualizarDiffGaveta() {
        if (!diffEl || typeof IS_EMPRESARIAL_CAIXA === 'undefined' || !IS_EMPRESARIAL_CAIXA) return;
        if (typeof CAIXA_FECHO_CEGO !== 'undefined' && CAIXA_FECHO_CEGO) return;
        const contado = parseMoneyMz(inputFecho && inputFecho.value !== '' ? inputFecho.value : '0');
        const esperado = typeof CAIXA_ESPERADO_GAVETA !== 'undefined' ? Number(CAIXA_ESPERADO_GAVETA) : 0;
        if (!Number.isFinite(contado)) {
            diffEl.textContent = '—';
            diffEl.style.color = '#64748b';
            return;
        }
        const diff = contado - esperado;
        const abs = Math.abs(diff).toLocaleString('pt-MZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        if (Math.abs(diff) < 0.005) {
            diffEl.textContent = '0,00 MZN';
            diffEl.style.color = '#059669';
        } else if (diff > 0) {
            diffEl.textContent = '+' + abs + ' MZN (sobra)';
            diffEl.style.color = '#0284c7';
        } else {
            diffEl.textContent = '-' + abs + ' MZN (falta)';
            diffEl.style.color = '#dc2626';
        }
    }

    if (btnUsarEsperado && inputFecho && typeof CAIXA_ESPERADO_GAVETA !== 'undefined') {
        btnUsarEsperado.addEventListener('click', function() {
            const esp = Number(CAIXA_ESPERADO_GAVETA) || 0;
            inputFecho.value = esp.toLocaleString('pt-MZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            atualizarDiffGaveta();
            inputFecho.focus();
        });
    }

    if (inputFecho) {
        inputFecho.addEventListener('input', atualizarDiffGaveta);
        atualizarDiffGaveta();
    }
    
    if(formFechar) {
        formFechar.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const valorRaw = document.getElementById('valor_fechamento').value;
            const contado = parseMoneyMz(valorRaw);
            
            if(!Number.isFinite(contado) || contado < 0) {
                showAlertModal('alertFechar', 'Digite um valor válido (ex: 8983 ou 8.983,00)', 'danger');
                return;
            }

            if (typeof CAIXA_FECHO_CEGO !== 'undefined' && CAIXA_FECHO_CEGO) {
                if (!(await nivoraConfirmar({ mensagem: 'Fechar o caixa com ' + contado.toLocaleString('pt-MZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' MZN contados na gaveta?', confirmar: 'Fechar caixa' }))) return;
            } else if (typeof IS_EMPRESARIAL_CAIXA !== 'undefined' && IS_EMPRESARIAL_CAIXA) {
                const esperado = typeof CAIXA_ESPERADO_GAVETA !== 'undefined' ? Number(CAIXA_ESPERADO_GAVETA) : 0;
                const diff = contado - esperado;
                if (Math.abs(diff) >= 0.01) {
                    const msgDiff = (diff > 0 ? 'Sobra' : 'Falta') + ' de ' +
                        Math.abs(diff).toLocaleString('pt-MZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) +
                        ' MZN na gaveta. Fechar mesmo assim?';
                    if (!(await nivoraConfirmar({ titulo: 'O valor contado não bate certo', mensagem: msgDiff, confirmar: 'Fechar mesmo assim', perigo: true }))) return;
                } else if (!(await nivoraConfirmar({ mensagem: 'Fechar o caixa?', confirmar: 'Fechar caixa' }))) {
                    return;
                }
            } else if (!(await nivoraConfirmar({ mensagem: 'Fechar o caixa?', confirmar: 'Fechar caixa' }))) {
                return;
            }
            
            const formData = new FormData();
            formData.append('fechamento', String(contado));
            formData.append('_csrf', getCsrfToken());
            
            OfflineSync.fetchQueued('api/caixa_fechar.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: csrfHeaders()
            })
            .then(response => response.json())
            .then(data => {
                if(data.success) {
                    showAlertModal('alertFechar', data.message, 'success');
                    // Abre o talão de fecho (resumo do turno para imprimir e assinar)
                    setTimeout(() => {
                        if (data.caixa_id) location.href = 'caixa_fecho_imprimir.php?id=' + encodeURIComponent(data.caixa_id);
                        else location.reload();
                    }, 1200);
                } else {
                    showAlertModal('alertFechar', data.message, 'danger');
                }
            })
            .catch(error => {
                if (error && error.status === 428) {
                    showAlertModal('alertFechar', 'O valor contado é diferente do esperado: o fecho precisa da autorização de um gerente.', 'warning');
                    return;
                }
                console.error('Erro:', error);
                showAlertModal('alertFechar', 'Erro ao fechar caixa', 'danger');
            });
        });
    }
});

// Funções para abrir modais (compatível com Bootstrap 5)
function abrirModalAbrir() {
    if (modalAbrirInstance) {
        modalAbrirInstance.show();
    } else {
        // Fallback para Bootstrap 4 ou sem Bootstrap
        const modal = document.getElementById('modalAbrir');
        if (modal) modal.style.display = 'block';
    }
}

function abrirModalFechar() {
    if (modalFecharInstance) {
        modalFecharInstance.show();
    } else {
        // Fallback para Bootstrap 4 ou sem Bootstrap
        const modal = document.getElementById('modalFechar');
        if (modal) modal.style.display = 'block';
    }
}

// Mostrar alerta no modal
function showAlertModal(id, message, type) {
    const alertDiv = document.getElementById(id);
    if (alertDiv) {
        alertDiv.textContent = message;
        alertDiv.className = 'alert alert-' + type;
        alertDiv.style.display = 'block';
        
        // Ocultar após 5 segundos
        setTimeout(() => {
            alertDiv.style.display = 'none';
        }, 5000);
    }
}

function getCsrfToken() {
    return (typeof CSRF_TOKEN !== 'undefined' && CSRF_TOKEN) ? CSRF_TOKEN : '';
}

function csrfHeaders() {
    const token = getCsrfToken();
    return token ? { 'X-CSRF-Token': token, 'X-Requested-With': 'XMLHttpRequest' } : { 'X-Requested-With': 'XMLHttpRequest' };
}
