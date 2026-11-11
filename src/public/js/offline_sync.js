/* EDVISTRO Offline Sync Core */
(function () {
    'use strict';

    // Mantem o nome legado para atualizar o schema sem abandonar filas existentes.
    const DB_NAME = 'rezva_offline_db';
    const DB_VERSION = 2;
    const STORE_CACHE = 'api_cache';
    const STORE_QUEUE = 'pending_ops';
    const STORE_DRAFTS = 'drafts';
    const DEFAULT_TTL_MS = 60000;
    const MAX_ATTEMPTS = 12;

    let dbPromise = null;
    let csrfToken = '';
    let deviceId = '';
    let syncTimer = null;
    let syncingPromise = null;

    function makeId(prefix = 'op') {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return prefix + '_' + window.crypto.randomUUID();
        }
        const random = window.crypto && window.crypto.getRandomValues
            ? Array.from(window.crypto.getRandomValues(new Uint32Array(4))).map((v) => v.toString(16)).join('')
            : Math.random().toString(16).slice(2) + Math.random().toString(16).slice(2);
        return prefix + '_' + Date.now() + '_' + random;
    }

    function getDeviceId() {
        if (deviceId) return deviceId;
        try {
            const key = 'edvistro_offline_device_id';
            const existing = localStorage.getItem(key) || localStorage.getItem('rezva_offline_device_id');
            if (existing) {
                deviceId = existing;
            } else {
                deviceId = makeId('dev');
            }
            localStorage.setItem(key, deviceId);
            return deviceId;
        } catch (e) {
            deviceId = makeId('dev_mem');
            return deviceId;
        }
    }

    function upgradeDb(db) {
        if (!db.objectStoreNames.contains(STORE_CACHE)) {
            const cache = db.createObjectStore(STORE_CACHE, { keyPath: 'key' });
            cache.createIndex('updated_at', 'updated_at', { unique: false });
        }
        if (!db.objectStoreNames.contains(STORE_QUEUE)) {
            const queue = db.createObjectStore(STORE_QUEUE, { keyPath: 'id' });
            queue.createIndex('status', 'status', { unique: false });
            queue.createIndex('created_at', 'created_at', { unique: false });
            queue.createIndex('next_attempt_at', 'next_attempt_at', { unique: false });
        } else {
            const queue = eventTargetStore(db, STORE_QUEUE);
            if (queue && !queue.indexNames.contains('next_attempt_at')) {
                queue.createIndex('next_attempt_at', 'next_attempt_at', { unique: false });
            }
        }
        if (!db.objectStoreNames.contains(STORE_DRAFTS)) {
            const drafts = db.createObjectStore(STORE_DRAFTS, { keyPath: 'key' });
            drafts.createIndex('updated_at', 'updated_at', { unique: false });
        }
    }

    let activeUpgradeTransaction = null;
    function eventTargetStore(db, name) {
        try {
            return activeUpgradeTransaction ? activeUpgradeTransaction.objectStore(name) : null;
        } catch (e) {
            return null;
        }
    }

    function openDb() {
        if (dbPromise) return dbPromise;
        dbPromise = new Promise((resolve, reject) => {
            const req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onupgradeneeded = (event) => {
                activeUpgradeTransaction = event.target.transaction;
                upgradeDb(event.target.result);
                activeUpgradeTransaction = null;
            };
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => reject(req.error || new Error('Falha ao abrir IndexedDB'));
            req.onblocked = () => reject(new Error('Atualizacao do armazenamento offline bloqueada por outra aba.'));
        });
        return dbPromise;
    }

    function requestResult(request) {
        return new Promise((resolve, reject) => {
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error || new Error('Falha IndexedDB'));
        });
    }

    async function withStore(store, mode, callback) {
        const db = await openDb();
        return new Promise((resolve, reject) => {
            const transaction = db.transaction(store, mode);
            const objectStore = transaction.objectStore(store);
            let result;
            try {
                result = callback(objectStore);
            } catch (error) {
                reject(error);
                return;
            }
            transaction.oncomplete = () => resolve(result);
            transaction.onerror = () => reject(transaction.error || new Error('Falha na transacao IndexedDB'));
            transaction.onabort = () => reject(transaction.error || new Error('Transacao IndexedDB cancelada'));
        });
    }

    function normalizeUrl(url) {
        const value = String(url || '');
        if (!value) return value;
        try {
            return new URL(value, window.location.href).pathname + new URL(value, window.location.href).search;
        } catch (e) {
            return value;
        }
    }

    function safeJson(text) {
        try { return JSON.parse(text); } catch (e) { return null; }
    }

    async function cacheSet(key, payload) {
        await withStore(STORE_CACHE, 'readwrite', (store) => store.put({ key, payload, updated_at: Date.now() }));
    }

    async function cacheGet(key) {
        const db = await openDb();
        return requestResult(db.transaction(STORE_CACHE, 'readonly').objectStore(STORE_CACHE).get(key));
    }

    async function fetchJSON(url, opts = {}) {
        const method = String(opts.method || 'GET').toUpperCase();
        const ttlMs = Number(opts.ttlMs || DEFAULT_TTL_MS);
        const cacheKey = method + ':' + normalizeUrl(url);
        const fetchOpts = Object.assign({
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        }, opts);

        try {
            const response = await fetch(url, fetchOpts);
            const raw = await response.text();
            const json = safeJson(raw);
            if (!response.ok) throw new Error('HTTP ' + response.status + ': ' + (json?.message || response.statusText || 'Erro'));
            if (!json) throw new Error('Resposta JSON invalida');
            if (method === 'GET') await cacheSet(cacheKey, json);
            return { ok: true, data: json, source: 'network' };
        } catch (networkError) {
            if (method === 'GET') {
                const cached = await cacheGet(cacheKey);
                if (cached && (opts.allowStale === true || Date.now() - Number(cached.updated_at || 0) <= ttlMs)) {
                    return { ok: true, data: cached.payload, source: 'cache', cachedAt: cached.updated_at };
                }
            }
            throw networkError;
        }
    }

    async function saveDraft(key, payload) {
        const row = { key: String(key), payload, updated_at: Date.now() };
        await withStore(STORE_DRAFTS, 'readwrite', (store) => store.put(row));
        return row;
    }

    async function getDraft(key) {
        const db = await openDb();
        return (await requestResult(db.transaction(STORE_DRAFTS, 'readonly').objectStore(STORE_DRAFTS).get(String(key)))) || null;
    }

    async function removeDraft(key) {
        await withStore(STORE_DRAFTS, 'readwrite', (store) => store.delete(String(key)));
    }

    async function queueAction(payload) {
        const id = String(payload.opId || payload.id || makeId('op'));
        const existing = await getQueueItem(id);
        if (existing) return existing;
        const row = {
            id,
            endpoint: String(payload.endpoint || ''),
            method: String(payload.method || 'POST').toUpperCase(),
            body: payload.body || {},
            headers: payload.headers || {},
            label: String(payload.label || 'Operacao'),
            status: 'PENDING',
            created_at: new Date().toISOString(),
            updated_at: new Date().toISOString(),
            next_attempt_at: Date.now(),
            attempts: 0,
            max_attempts: Number(payload.maxAttempts || MAX_ATTEMPTS),
            last_error: '',
            response: null,
        };
        await withStore(STORE_QUEUE, 'readwrite', (store) => store.put(row));
        await requestBackgroundSync();
        reportTelemetry(row);
        emitUpdate();
        return row;
    }

    async function getQueueItem(id) {
        const db = await openDb();
        return (await requestResult(db.transaction(STORE_QUEUE, 'readonly').objectStore(STORE_QUEUE).get(String(id)))) || null;
    }

    async function getQueueRows() {
        const db = await openDb();
        const store = db.transaction(STORE_QUEUE, 'readonly').objectStore(STORE_QUEUE);
        const rows = await requestResult(store.getAll());
        return (rows || []).sort((a, b) => String(a.created_at).localeCompare(String(b.created_at)));
    }

    function isRecoverableCompletedOperation(row) {
        const endpoint = normalizeUrl(row?.endpoint || '').split('?')[0];
        const error = String(row?.last_error || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase();
        return endpoint.endsWith('/api/pedido_pagar.php')
            && error.includes('este pedido ja foi pago');
    }

    function isAuthorizationDenied(row) {
        const error = String(row?.last_error || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase();
        return error.includes('sem permissao');
    }

    async function getPending(limit = 100, includeWaiting = false) {
        const now = Date.now();
        const pending = [];
        for (const row of await getQueueRows()) {
            if (row.status === 'RETRY' && isAuthorizationDenied(row)) {
                row.status = 'FAILED';
                row.next_attempt_at = 0;
                await updateQueueItem(row);
                continue;
            }
            const recoverableCompleted = row.status === 'FAILED' && isRecoverableCompletedOperation(row);
            // Uma falha permanente continua visível para revisão, mas não deve
            // bloquear operações independentes adicionadas depois dela.
            if (row.status === 'FAILED' && !recoverableCompleted) continue;
            const active = row.status === 'PENDING' || row.status === 'RETRY' || recoverableCompleted;
            if (active && (includeWaiting || Number(row.next_attempt_at || 0) <= now)) {
                pending.push(row);
                if (pending.length >= limit) break;
            }
        }
        return pending;
    }

    async function getQueueStats() {
        const rows = await getQueueRows();
        return rows.reduce((stats, row) => {
            if (row.status === 'DONE') stats.done += 1;
            else if (row.status === 'FAILED') stats.failed += 1;
            else stats.pending += 1;
            stats.attempts += Number(row.attempts || 0);
            return stats;
        }, { pending: 0, done: 0, failed: 0, attempts: 0, total: rows.length });
    }

    async function updateQueueItem(row) {
        row.updated_at = new Date().toISOString();
        await withStore(STORE_QUEUE, 'readwrite', (store) => store.put(row));
        emitUpdate();
    }

    function backoffMs(attempts) {
        return Math.min(5 * 60 * 1000, 3000 * Math.pow(2, Math.max(0, attempts - 1)));
    }

    function reportTelemetry(row) {
        const body = {
            op_id: row.id,
            status: row.status,
            endpoint: row.endpoint,
            method: row.method,
            attempts: Number(row.attempts || 0),
            last_error: String(row.last_error || ''),
            device_id: getDeviceId(),
        };
        const payload = JSON.stringify(body);
        try {
            if (navigator.sendBeacon) {
                navigator.sendBeacon('api/offline_sync_event.php', new Blob([payload], { type: 'application/json' }));
                return;
            }
        } catch (e) {}
        fetch('api/offline_sync_event.php', {
            method: 'POST', credentials: 'same-origin',
            headers: Object.assign({ 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, csrfToken ? { 'X-CSRF-Token': csrfToken } : {}),
            body: payload,
        }).catch(() => {});
    }

    async function postJSON(operation) {
        const form = new FormData();
        Object.keys(operation.body || {}).forEach((key) => form.append(key, operation.body[key]));
        form.set('_offline_op_id', operation.id);
        if (csrfToken) form.set('_csrf', csrfToken);
        const headers = Object.assign({
            'X-Requested-With': 'XMLHttpRequest',
            'X-Idempotency-Key': operation.id,
            'X-Offline-Device': getDeviceId(),
        }, operation.headers || {});
        if (csrfToken) headers['X-CSRF-Token'] = csrfToken;

        const response = await fetch(operation.endpoint, {
            method: operation.method,
            body: form,
            credentials: 'same-origin',
            headers,
        });
        const raw = await response.text();
        const json = safeJson(raw);
        if (!response.ok || !json || json.success === false) {
            const error = new Error(String(json?.message || ('Falha ' + operation.method + ' ' + operation.endpoint + ' (' + response.status + ')')));
            error.status = response.status;
            error.permanent = response.ok && !!json && json.success === false;
            if (!error.permanent) {
                error.permanent = response.status >= 400 && response.status < 500 && ![401, 408, 409, 429].includes(response.status);
            }
            throw error;
        }
        return json;
    }

    async function syncOperation(id) {
        const operation = await getQueueItem(id);
        if (!operation || operation.status === 'DONE') return operation?.response || null;
        if (!navigator.onLine) throw new Error('Sem ligacao ao servidor');
        try {
            const response = await postJSON(operation);
            operation.status = 'DONE';
            operation.response = response;
            operation.last_error = '';
            operation.next_attempt_at = 0;
            await updateQueueItem(operation);
            reportTelemetry(operation);
            window.dispatchEvent(new CustomEvent('offline-sync-operation-done', {
                detail: {
                    operation_id: operation.id,
                    endpoint: operation.endpoint,
                    label: operation.label,
                    response,
                },
            }));
            return response;
        } catch (error) {
            if (error.status === 428) {
                // Faltou a autorizacao do gerente e a janela foi fechada: a acao foi
                // abandonada de proposito, por isso sai da fila (nao fica como "falhada").
                await withStore(STORE_QUEUE, 'readwrite', (store) => store.delete(operation.id));
                emitUpdate();
                error.permanent = true;
                throw error;
            }
            operation.attempts = Number(operation.attempts || 0) + 1;
            operation.last_error = String(error.message || error);
            operation.status = error.permanent || operation.attempts >= Number(operation.max_attempts || MAX_ATTEMPTS) ? 'FAILED' : 'RETRY';
            operation.next_attempt_at = Date.now() + backoffMs(operation.attempts);
            await updateQueueItem(operation);
            reportTelemetry(operation);
            throw error;
        }
    }

    async function executeOrQueue(payload) {
        const operation = await queueAction(payload);
        if (!navigator.onLine) return { queued: true, operation };
        try {
            const data = await syncOperation(operation.id);
            return { queued: false, operation: await getQueueItem(operation.id), data };
        } catch (error) {
            if (error.permanent) throw error;
            return { queued: true, operation: await getQueueItem(operation.id), error };
        }
    }

    async function fetchQueued(endpoint, options = {}, label = '') {
        const body = {};
        if (options.body instanceof FormData || options.body instanceof URLSearchParams) {
            options.body.forEach((value, key) => { body[key] = String(value); });
        } else if (typeof options.body === 'string') {
            new URLSearchParams(options.body).forEach((value, key) => { body[key] = value; });
        } else if (options.body && typeof options.body === 'object') {
            Object.assign(body, options.body);
        }
        const result = await executeOrQueue({
            endpoint,
            method: options.method || 'POST',
            body,
            headers: options.headers || {},
            label: label || ('Operacao em ' + endpoint),
        });
        const data = result.queued
            ? { success: true, queued: true, message: 'Operacao pendente de sincronizacao.' }
            : result.data;
        const raw = JSON.stringify(data);
        return {
            ok: true,
            status: result.queued ? 202 : 200,
            json: async () => data,
            text: async () => raw,
        };
    }

    async function syncQueue(options = {}) {
        if (syncingPromise) return syncingPromise;
        syncingPromise = (async () => {
            if (!navigator.onLine) return { synced: 0, pending: (await getPending(100, true)).length };
            const pending = await getPending(100, options.force === true);
            let synced = 0;
            for (const operation of pending) {
                try {
                    await syncOperation(operation.id);
                    synced += 1;
                } catch (error) {
                    // Nao ultrapassa uma operacao anterior: preserva a ordem causal.
                    break;
                }
            }
            return { synced, pending: (await getPending(100, true)).length };
        })();
        try { return await syncingPromise; } finally { syncingPromise = null; }
    }

    async function retryFailed() {
        const failed = (await getQueueRows()).filter((row) => row.status === 'FAILED');
        for (const row of failed) {
            row.status = 'RETRY';
            row.attempts = 0;
            row.next_attempt_at = Date.now();
            await updateQueueItem(row);
        }
        return syncQueue({ force: true });
    }

    function emitUpdate() {
        window.dispatchEvent(new CustomEvent('offline-sync-update'));
        updateIndicator().catch(() => {});
    }

    function ensureIndicator() {
        if (document.getElementById('offline-sync-indicator')) return;
        const element = document.createElement('button');
        element.type = 'button';
        element.id = 'offline-sync-indicator';
        element.title = 'Estado da sincronizacao offline';
        element.style.cssText = 'display:none;position:fixed;right:14px;bottom:14px;z-index:1080;border:1px solid rgba(255,255,255,.25);background:#1f2937;color:#fff;padding:9px 12px;border-radius:6px;font:600 12px/1.2 system-ui;box-shadow:0 4px 16px rgba(0,0,0,.25);cursor:pointer';
        element.addEventListener('click', () => {
            const panel = document.getElementById('offline-sync-panel');
            if (!panel) return;
            panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
            renderQueuePanel().catch(() => {});
        });
        document.body.appendChild(element);

        const panel = document.createElement('aside');
        panel.id = 'offline-sync-panel';
        panel.setAttribute('aria-label', 'Operacoes pendentes de sincronizacao');
        panel.style.cssText = 'display:none;position:fixed;right:14px;bottom:58px;z-index:1081;width:min(360px,calc(100vw - 28px));max-height:55vh;overflow:auto;background:#fff;color:#111827;border:1px solid #d1d5db;border-radius:6px;padding:12px;box-shadow:0 8px 28px rgba(0,0,0,.28);font:13px/1.4 system-ui';
        const title = document.createElement('strong');
        title.textContent = 'Sincronizacao';
        const list = document.createElement('div');
        list.id = 'offline-sync-list';
        list.style.cssText = 'display:grid;gap:8px;margin:10px 0';
        const retry = document.createElement('button');
        retry.type = 'button';
        retry.textContent = 'Tentar falhas novamente';
        retry.style.cssText = 'width:100%;border:0;border-radius:5px;padding:8px;background:#1f2937;color:#fff;font-weight:600;cursor:pointer';
        retry.addEventListener('click', () => retryFailed().then(renderQueuePanel).catch(renderQueuePanel));
        panel.append(title, list, retry);
        document.body.appendChild(panel);
    }

    async function renderQueuePanel() {
        const list = document.getElementById('offline-sync-list');
        if (!list) return;
        const rows = (await getQueueRows()).filter((row) => row.status !== 'DONE').slice(0, 30);
        list.replaceChildren();
        if (!rows.length) {
            const empty = document.createElement('div');
            empty.textContent = 'Sem operações pendentes.';
            empty.style.color = '#4b5563';
            list.appendChild(empty);
            return;
        }
        rows.forEach((row) => {
            const item = document.createElement('div');
            item.style.cssText = 'border-left:3px solid ' + (row.status === 'FAILED' ? '#b91c1c' : '#d97706') + ';padding:6px 8px;background:#f9fafb';
            const label = document.createElement('div');
            label.style.fontWeight = '700';
            label.textContent = row.label;
            const meta = document.createElement('div');
            meta.style.color = '#4b5563';
            meta.textContent = row.status + ' | tentativas: ' + Number(row.attempts || 0);
            item.append(label, meta);
            if (row.last_error) {
                const error = document.createElement('div');
                error.style.cssText = 'color:#991b1b;margin-top:3px;overflow-wrap:anywhere';
                error.textContent = row.last_error;
                item.appendChild(error);
            }
            list.appendChild(item);
        });
    }

    async function updateIndicator() {
        ensureIndicator();
        const element = document.getElementById('offline-sync-indicator');
        if (!element) return;
        const stats = await getQueueStats();
        const panel = document.getElementById('offline-sync-panel');
        if (panel && panel.style.display !== 'none') await renderQueuePanel();
        // No painel Super Admin a fila e das operacoes dos restaurantes neste navegador: nao interessa ali
        // (fica visivel na seccao Seguranca). Mostrar o aviso so confundia.
        if (document.body && document.body.classList.contains('super-admin-ui')) {
            element.style.display = 'none';
            return;
        }
        if (!navigator.onLine || stats.pending > 0 || stats.failed > 0) {
            element.style.display = 'block';
            element.style.background = stats.failed > 0 ? '#991b1b' : (!navigator.onLine ? '#92400e' : '#1f2937');
            element.textContent = !navigator.onLine
                ? 'Sem internet · ' + stats.pending + (stats.pending === 1 ? ' operação por enviar' : ' operações por enviar')
                : (stats.failed > 0
                    ? (stats.failed === 1 ? '1 operação não foi enviada' : stats.failed + ' operações não foram enviadas') + ' · Ver e tentar de novo'
                    : 'A enviar ' + stats.pending + (stats.pending === 1 ? ' operação…' : ' operações…'));
        } else {
            element.style.display = 'none';
        }
    }

    async function requestBackgroundSync() {
        if (!('serviceWorker' in navigator)) return;
        try {
            const registration = await navigator.serviceWorker.ready;
            if (registration.sync) await registration.sync.register('edvistro-sync');
        } catch (e) {}
    }

    function registerServiceWorker() {
        if (!('serviceWorker' in navigator) || !window.isSecureContext) return;
        navigator.serviceWorker.register('service-worker.js', { scope: './' }).catch(() => {});
        navigator.serviceWorker.addEventListener('message', (event) => {
            if (event.data && event.data.type === 'EDVISTRO_SYNC_REQUESTED') {
                syncQueue({ force: true }).catch(() => {});
            }
        });
        if (!document.querySelector('link[rel="manifest"]')) {
            const link = document.createElement('link');
            link.rel = 'manifest';
            link.href = 'manifest.webmanifest';
            document.head.appendChild(link);
        }
    }

    function startAutoSync(intervalMs) {
        if (syncTimer) clearInterval(syncTimer);
        syncTimer = setInterval(() => syncQueue().catch(() => {}), Math.max(5000, intervalMs));
        window.addEventListener('online', () => { updateIndicator(); syncQueue({ force: true }).catch(() => {}); });
        window.addEventListener('offline', updateIndicator);
        window.addEventListener('pageshow', () => syncQueue().catch(() => {}));
    }

    function init(options = {}) {
        csrfToken = String(options.csrfToken || csrfToken || '');
        getDeviceId();
        openDb().then(() => {
            updateIndicator();
            syncQueue().catch(() => {});
        }).catch(() => {});
        registerServiceWorker();
        startAutoSync(Number(options.syncIntervalMs || 15000));
    }

    window.OfflineSync = {
        init,
        fetchJSON,
        queueAction,
        executeOrQueue,
        fetchQueued,
        syncQueue,
        retryFailed,
        getQueueStats,
        saveDraft,
        getDraft,
        removeDraft,
        makeId,
    };
})();
