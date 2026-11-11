/* Nivora Receipt Print - impressao centralizada para POS web/kiosk. */
(function () {
    'use strict';

    const PRINTED_PREFIX = 'nivora_receipt_printed_';
    const FRAME_TIMEOUT_MS = 120000;
    const activePrints = new Set();

    function normalizeSaleId(value) {
        const id = parseInt(value, 10) || 0;
        return id > 0 ? id : 0;
    }

    function printedKey(saleId) {
        return PRINTED_PREFIX + String(saleId);
    }

    function wasPrintedInThisSession(saleId) {
        try {
            return sessionStorage.getItem(printedKey(saleId)) === '1';
        } catch (e) {
            return false;
        }
    }

    function markPrintedInThisSession(saleId) {
        try {
            sessionStorage.setItem(printedKey(saleId), '1');
        } catch (e) {}
    }

    function removeFrame(frame, saleId) {
        activePrints.delete(saleId);
        if (frame && frame.parentNode) frame.parentNode.removeChild(frame);
    }

    function printReceipt(saleId, options = {}) {
        const id = normalizeSaleId(saleId);
        if (!id) return Promise.reject(new Error('Venda invalida para impressao'));

        const force = options.force === true;
        if (!force && (activePrints.has(id) || wasPrintedInThisSession(id))) {
            return Promise.resolve({ printed: false, duplicate: true, venda_id: id });
        }

        activePrints.add(id);
        markPrintedInThisSession(id);

        return new Promise((resolve, reject) => {
            const frame = document.createElement('iframe');
            frame.title = 'Impressao do recibo';
            frame.setAttribute('aria-hidden', 'true');
            frame.style.cssText = 'position:fixed;width:1px;height:1px;right:0;bottom:0;border:0;opacity:0;pointer-events:none;';

            let settled = false;
            const finish = (result, error) => {
                if (settled) return;
                settled = true;
                if (error) {
                    activePrints.delete(id);
                    try { sessionStorage.removeItem(printedKey(id)); } catch (e) {}
                    reject(error);
                    return;
                }
                resolve(result);
            };

            frame.addEventListener('load', () => {
                try {
                    const childWindow = frame.contentWindow;
                    if (childWindow) {
                        childWindow.addEventListener('afterprint', () => {
                            finish({ printed: true, venda_id: id });
                            setTimeout(() => removeFrame(frame, id), 300);
                        }, { once: true });
                    }
                    // O comprovante chama window.print() depois do load.
                    setTimeout(() => finish({ printed: true, venda_id: id }), 800);
                } catch (error) {
                    finish(null, error);
                }
            }, { once: true });

            frame.addEventListener('error', () => {
                finish(null, new Error('Nao foi possivel carregar o recibo para impressao'));
                removeFrame(frame, id);
            }, { once: true });

            const url = new URL('comprovante.php', window.location.href);
            url.searchParams.set('id', String(id));
            url.searchParams.set('auto_print', '1');
            url.searchParams.set('embedded', '1');
            url.searchParams.set('paper', String(options.paper === 58 ? 58 : 80));
            frame.src = url.toString();
            document.body.appendChild(frame);

            setTimeout(() => removeFrame(frame, id), FRAME_TIMEOUT_MS);
        });
    }

    window.addEventListener('offline-sync-operation-done', (event) => {
        const detail = event.detail || {};
        const endpoint = String(detail.endpoint || '');
        const response = detail.response || {};
        if (!endpoint.includes('pedido_pagar.php') || !response.venda_id) return;
        printReceipt(response.venda_id, { reason: 'offline-sync' }).catch((error) => {
            console.error('Falha ao imprimir recibo sincronizado', error);
        });
    });

    window.ReceiptPrint = {
        print: printReceipt,
        reprint: (saleId, options = {}) => printReceipt(saleId, Object.assign({}, options, { force: true })),
    };
})();
