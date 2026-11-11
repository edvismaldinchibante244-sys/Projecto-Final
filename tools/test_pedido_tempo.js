'use strict';

const assert = require('node:assert/strict');
const tempo = require('../src/public/js/pedido_tempo.js');

const now = new Date('2026-08-14T11:51:00').getTime();

assert.equal(tempo.serviceMinutes({
    status: 'NOVO',
    criado_em: '2026-08-14 09:41:00'
}, now), 130, 'pedido ativo deve continuar a contar ate agora');

assert.equal(tempo.serviceMinutes({
    status: 'PAGO',
    criado_em: '2026-08-14 09:41:00',
    pago_em: '2026-08-14 09:43:00',
    atualizado_em: '2026-08-14 11:51:00'
}, now), 2, 'pedido pago deve parar em pago_em');

assert.equal(tempo.serviceMinutes({
    status: 'PAGO',
    criado_em: '2026-08-14 09:41:00',
    atualizado_em: '2026-08-14 09:44:00'
}, now), 3, 'pedido antigo deve usar atualizado_em como fallback');

assert.equal(tempo.serviceMinutes({
    status: 'CONTA',
    criado_em: '2026-08-14 09:41:00',
    entregue_em: '2026-08-14 09:47:00',
    conta_em: '2026-08-14 09:50:00'
}, now), 6, 'conta deve preservar a duracao ate a entrega');

assert.equal(tempo.serviceLabel({
    status: 'ENTREGUE',
    criado_em: '2026-08-14 09:41:00',
    entregue_em: '2026-08-14 10:42:00'
}, now), '1h1m');

console.log('OK: tempo de atendimento validado (5 verificacoes).');
