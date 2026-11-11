const STATIC_CACHE = 'edvistro-static-v6';
const PAGE_CACHE = 'edvistro-pages-v6';
const STATIC_ASSETS = [
    './offline.html',
    './js/offline_sync.js',
    './js/receipt_print.js',
    './css/style.css',
    './favicon.ico',
    './lib/bootstrap-5.3.2/css/bootstrap.min.css',
    './lib/bootstrap-5.3.2/js/bootstrap.bundle.min.js',
    './lib/fontawesome-6.5.1/css/all.min.css',
    './lib/fontawesome-6.5.1/webfonts/fa-solid-900.woff2',
    './lib/fontawesome-6.5.1/webfonts/fa-regular-400.woff2',
    './lib/fontawesome-6.5.1/webfonts/fa-brands-400.woff2',
    './lib/qrcode-generator-1.4.4/qrcode.js'
];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(STATIC_CACHE).then((cache) => cache.addAll(STATIC_ASSETS)));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(
            keys.filter((key) => ![STATIC_CACHE, PAGE_CACHE].includes(key)).map((key) => caches.delete(key))
        )).then(() => self.clients.claim())
    );
});

async function networkFirstPage(request) {
    const cache = await caches.open(PAGE_CACHE);
    try {
        const response = await fetch(request);
        if (response.ok) await cache.put(request, response.clone());
        return response;
    } catch (error) {
        return (await cache.match(request)) || (await caches.match('./offline.html'));
    }
}

async function staleWhileRevalidate(request) {
    const cache = await caches.open(STATIC_CACHE);
    const cached = await cache.match(request);
    const network = fetch(request).then((response) => {
        if (response.ok) cache.put(request, response.clone());
        return response;
    }).catch(() => cached);
    return cached || network;
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;
    const url = new URL(request.url);

    if (url.pathname.endsWith('/logout.php')) {
        event.respondWith(fetch(request).then(async (response) => {
            await caches.delete(PAGE_CACHE);
            return response;
        }));
        return;
    }

    if (url.pathname.includes('/api/')) return;
    if (request.mode === 'navigate') {
        event.respondWith(networkFirstPage(request));
        return;
    }

    const staticDestination = ['script', 'style', 'font', 'image'].includes(request.destination);
    if (staticDestination) event.respondWith(staleWhileRevalidate(request));
});

self.addEventListener('sync', (event) => {
    if (event.tag === 'edvistro-sync') {
        event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            clients.forEach((client) => client.postMessage({ type: 'EDVISTRO_SYNC_REQUESTED' }));
        }));
    }
});
