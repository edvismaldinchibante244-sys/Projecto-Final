(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.NivoraPedidoTempo = api;
})(typeof window !== 'undefined' ? window : globalThis, function () {
    'use strict';

    function normalizeStatus(value) {
        var status = String(value || '').trim().toUpperCase();
        if (status === 'PENDENTE') return 'NOVO';
        if (status === 'CONFIRMADO') return 'PREPARANDO';
        if (status === 'CONTA_SOLICITADA' || status === 'PEDIR_CONTA') return 'CONTA';
        return status;
    }

    function parseTimestamp(value) {
        if (!value) return null;
        var parsed = new Date(String(value).replace(' ', 'T'));
        return isNaN(parsed.getTime()) ? null : parsed;
    }

    function ageMinutes(createdAt, nowMs) {
        var start = parseTimestamp(createdAt);
        if (!start) return 0;
        var endMs = Number.isFinite(nowMs) ? nowMs : Date.now();
        return Math.max(0, Math.round((endMs - start.getTime()) / 60000));
    }

    function endTimestamp(order) {
        var status = normalizeStatus(order && order.status);
        if (status === 'ENTREGUE') return order.entregue_em || order.atualizado_em || null;
        if (status === 'CONTA') return order.entregue_em || order.conta_em || order.atualizado_em || null;
        if (status === 'PAGO') return order.pago_em || order.atualizado_em || null;
        if (status === 'CANCELADO') return order.cancelado_em || order.atualizado_em || null;
        return null;
    }

    function serviceMinutes(order, nowMs) {
        if (!order) return 0;
        var start = parseTimestamp(order.criado_em);
        if (!start) return 0;
        var terminalEnd = parseTimestamp(endTimestamp(order));
        var endMs = terminalEnd
            ? terminalEnd.getTime()
            : (Number.isFinite(nowMs) ? nowMs : Date.now());
        return Math.max(0, Math.round((endMs - start.getTime()) / 60000));
    }

    function formatMinutes(minutes) {
        var mins = Math.max(0, Math.round(Number(minutes) || 0));
        if (mins < 1) return 'agora';
        if (mins < 60) return mins + ' min';
        var hours = Math.floor(mins / 60);
        var remainder = mins % 60;
        return hours + 'h' + (remainder > 0 ? remainder + 'm' : '');
    }

    function serviceLabel(order, nowMs) {
        return formatMinutes(serviceMinutes(order, nowMs));
    }

    return {
        normalizeStatus: normalizeStatus,
        parseTimestamp: parseTimestamp,
        ageMinutes: ageMinutes,
        endTimestamp: endTimestamp,
        serviceMinutes: serviceMinutes,
        formatMinutes: formatMinutes,
        serviceLabel: serviceLabel
    };
});
