// =========================================================================
//  Customer Portal service worker
//  -------------------------------------------------------------
//  Serves a fallback page when a navigation fails while offline.
//  POST routes are never cached — those are owned by the offline-tsr.js
//  IndexedDB queue. Authed HTML is NOT served cache-first (stale-data
//  risk); only static, same-origin assets are.
//
//  Versioned cache key: bump CACHE_VERSION on every release to evict
//  stale assets. Must be a plain file at public/sw.js (service workers
//  cannot be Vite-bundled with a hashed filename — the registration
//  path /sw.js is fixed and its scope must stay the site root).
// =========================================================================

const CACHE_VERSION = 'portal-v2';

// Pre-cache at install: everything a device needs to drain a queued
// TSR right after a cold, offline start. Runtime caching (below)
// picks up the hashed Vite build assets as the user visits pages.
// NOTE: '/offline' is a real route (routes/web.php).
const APP_SHELL = [
    '/js/portal/offline-tsr.js',
    '/offline',
];

// Cache-first static prefixes. Hashed /build assets are immutable;
// the offline-tsr.js copy is small and refreshes on the next visit.
const STATIC_PREFIXES = [
    '/build/',
    '/js/portal/',
    '/css/portal/',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_VERSION).then((c) => c.addAll(APP_SHELL))
            .then(() => self.skipWaiting())
            .catch(() => { /* a missing shell entry must not strand an old worker */ })
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => k !== CACHE_VERSION).map((k) => caches.delete(k)))
        ).then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;   // never cache POST/PUT/PATCH/DELETE

    const url = new URL(req.url);
    if (url.origin !== location.origin) return;
    if (url.pathname.startsWith('/livewire/')) return;   // Livewire handles its own transport
    if (url.pathname.startsWith('/_debugbar/')) return;
    if (url.pathname.startsWith('/broadcasting/')) return;
    // Signed, time-limited signature files: never serve them from a
    // long-lived cache — the signed URL must expire with its validity.
    if (url.pathname.startsWith('/signatures/')) return;

    // Navigations (page loads): network first so authed pages are
    // always fresh; fall back to the cached copy, then /offline.
    if (req.mode === 'navigate') {
        event.respondWith(
            fetch(req).then((res) => {
                if (res.ok && url.pathname !== '/offline') {
                    const copy = res.clone();
                    caches.open(CACHE_VERSION).then((c) => c.put(req, copy));
                }
                return res;
            }).catch(() => caches.match(req).then((hit) => hit || caches.match('/offline'))),
        );
        return;
    }

    // Static assets: cache first (immutable hashes), stash on first use.
    if (STATIC_PREFIXES.some((p) => url.pathname.startsWith(p))) {
        event.respondWith(
            caches.match(req).then((hit) => {
                if (hit) return hit;
                return fetch(req).then((res) => {
                    if (res.ok) {
                        const copy = res.clone();
                        caches.open(CACHE_VERSION).then((c) => c.put(req, copy));
                    }
                    return res;
                });
            }),
        );
    }
});
