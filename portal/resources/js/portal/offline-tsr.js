// =========================================================================
//  TSR offline queue (self-contained, IndexedDB-or-localStorage)
//  -------------------------------------------------------------
//  Stores TSR payloads in the browser while the TSP is offline so the
//  Save button never silently drops the report.
//
//  Storage backend is picked at runtime:
//    1. If the browser has IndexedDB we use it directly (no extra
//       dependency — we just wrap the raw IDB API).
//    2. Otherwise we fall back to localStorage so the form still
//       queues while the user is offline on a small-screen device.
//
//  Drain triggers:
//    * the `online` window event (immediate)
//    * a manual call from the Alpine controller (the "Sync to Monday"
//      button on the sticky bar)
//    * an interval poll every 60s
//
//  The drainer always POSTs to the same endpoint the form would have
//  used when online: /tsp/tickets/{ticket}/tsr. The server returns
//  200 with the new sync_state, which the form then surfaces via
//  /tsp/tickets/{ticket}/tsr/status (polled separately by the form).
// =========================================================================

const STORE = 'pending';
const POLL_MS = 60_000;

// -----------------------------------------------------------------------
//  Tiny IndexedDB wrapper. Same shape as Dexie's `db()` so swapping
//  back to Dexie later is a one-line change.
// -----------------------------------------------------------------------
function openIdb() {
    return new Promise((resolve, reject) => {
        if (typeof indexedDB === 'undefined') {
            reject(new Error('IndexedDB unavailable'));
            return;
        }
        const req = indexedDB.open('portal-tsr', 1);
        req.onupgradeneeded = () => {
            const db = req.result;
            if (! db.objectStoreNames.contains(STORE)) {
                db.createObjectStore(STORE, { keyPath: 'local_id' });
            }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror   = () => reject(req.error);
    });
}

// Run a callback with a fresh IDB connection that auto-closes
// when the callback's promise settles. Avoids long-lived
// connections that would block other tabs / Playwright tests.
function withIdb(fn) {
    return openIdb().then((db) => {
        return Promise.resolve(fn(db)).finally(() => {
            try { db.close(); } catch (e) { /* ignore */ }
        });
    });
}

function idbPut(db, value) {
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE, 'readwrite');
        tx.objectStore(STORE).put(value);
        tx.oncomplete = () => resolve();
        tx.onerror    = () => reject(tx.error);
    });
}
function idbGetAll(db) {
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE, 'readonly');
        const req = tx.objectStore(STORE).getAll();
        req.onsuccess = () => resolve(req.result || []);
        req.onerror   = () => reject(req.error);
    });
}
function idbDelete(db, key) {
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE, 'readwrite');
        tx.objectStore(STORE).delete(key);
        tx.oncomplete = () => resolve();
        tx.onerror    = () => reject(tx.error);
    });
}
function idbCount(db) {
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE, 'readonly');
        const req = tx.objectStore(STORE).count();
        req.onsuccess = () => resolve(req.result || 0);
        req.onerror   = () => reject(req.error);
    });
}

// -----------------------------------------------------------------------
//  localStorage fallback (older browsers, very small quota).
// -----------------------------------------------------------------------
const LS_KEY = 'portal-tsr-pending';
function lsGetAll() {
    try { return JSON.parse(localStorage.getItem(LS_KEY) || '[]'); }
    catch (e) { return []; }
}
function lsSetAll(items) {
    try { localStorage.setItem(LS_KEY, JSON.stringify(items)); }
    catch (e) { /* quota or disabled — silently drop */ }
}
function lsCount() { return lsGetAll().length; }

// -----------------------------------------------------------------------
//  Backend selection. We try IDB once and remember the result.
// -----------------------------------------------------------------------
let _backend = null; // 'idb' | 'ls'

async function backend() {
    // Try IDB first, fall back to localStorage. Each call to
    // withIdb() opens and closes its own connection, so we never
    // hold a long-lived reference that would block other tabs
    // or automation tools.
    if (_backend === 'ls') return { kind: 'ls' };
    try {
        await withIdb(() => Promise.resolve()); // smoke-test open
        return { kind: 'idb' };
    } catch (e) {
        _backend = 'ls';
        return { kind: 'ls' };
    }
}

async function put(value) {
    if ((await backend()).kind === 'idb') {
        return withIdb((db) => idbPut(db, value));
    }
    const items = lsGetAll();
    const i = items.findIndex(it => it.local_id === value.local_id);
    if (i >= 0) items[i] = value; else items.push(value);
    lsSetAll(items);
}
async function getAll() {
    if ((await backend()).kind === 'idb') {
        return withIdb((db) => idbGetAll(db));
    }
    return lsGetAll();
}
async function remove(key) {
    if ((await backend()).kind === 'idb') {
        return withIdb((db) => idbDelete(db, key));
    }
    lsSetAll(lsGetAll().filter(it => it.local_id !== key));
}
export async function queueCount() {
    if ((await backend()).kind === 'idb') {
        return withIdb((db) => idbCount(db));
    }
    return lsCount();
}

// -----------------------------------------------------------------------
//  Network
// -----------------------------------------------------------------------
function syncUrlFor(ticket) {
    // The form sets window.__tsrSyncUrl via the route helper. We fall
    // back to building the URL ourselves if the helper wasn't set yet
    // (e.g. when the module is loaded outside the form).
    if (typeof window !== 'undefined' && window.__tsrSyncUrl) {
        return window.__tsrSyncUrl;
    }
    return '/tsp/tickets/' + encodeURIComponent(ticket) + '/tsr/sync';
}

// Endpoint that the SERVER uses to accept a TSR payload and write it
// to the DB. This is the same endpoint Livewire's `submit()` action
// hits server-side, but here we're POSTing from the browser directly
// so the offline-queued payloads can be drained when the connection
// comes back.
function storeUrlFor(ticket) {
    if (typeof window !== 'undefined' && window.__tsrStoreUrl) {
        return window.__tsrStoreUrl;
    }
    return '/tsp/tickets/' + encodeURIComponent(ticket) + '/service-report';
}

// CSRF: read the XSRF-TOKEN cookie and echo it back as
// X-XSRF-TOKEN (Laravel's VerifyCsrfToken middleware expects
// exactly that pairing). Fall back to the meta tag value when
// the cookie isn't set yet.
function csrfToken() {
    if (typeof document === 'undefined') return '';
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta && meta.content) return meta.content;
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    if (m) return decodeURIComponent(m[1]);
    return '';
}

async function postOnce(payload) {
    return fetch(storeUrlFor(payload.ticket_number), {
        method:  'POST',
        headers: {
            'Content-Type':   'application/json',
            'Accept':         'application/json',
            'X-TSR-Local-Id': payload.local_id,
            'X-CSRF-TOKEN':   csrfToken(),
            'X-XSRF-TOKEN':   csrfToken(),
        },
        body: JSON.stringify(payload),
        credentials: 'same-origin',
    });
}

// -----------------------------------------------------------------------
//  Public API
// -----------------------------------------------------------------------

// Treat the browser as offline if the user flipped the form's
// "Go offline" toggle, even if navigator.onLine still says we're
// online. The form keeps this in sync with the Alpine tsrForm
// component state via window.__tsrForceOffline.
function effectivelyOffline() {
    if (typeof navigator !== 'undefined' && ! navigator.onLine) return true;
    if (typeof window !== 'undefined' && window.__tsrForceOffline === true) return true;
    return false;
}

/**
 * Submit a TSR. If we're online we try the server first; on 5xx
 * or network error we queue. If we're offline we queue immediately.
 * Returns the path so the form can show the right success message.
 */
export async function submitTsr(payload) {
    if (! effectivelyOffline()) {
        try {
            const r = await postOnce(payload);
            if (r.ok) return { path: 'live', localId: payload.local_id };
            if (r.status >= 500) throw new Error('server 5xx, queueing');
        } catch (e) {
            // fall through to queue
        }
    }
    await put({ ...payload, queued_at: Date.now() });
    return { path: 'queued', localId: payload.local_id };
}

async function drain() {
    if (effectivelyOffline()) {
        return { drained: 0, skipped: 'offline' };
    }
    const items = await getAll();
    let drained = 0;
    for (const it of items) {
        try {
            const r = await postOnce(it);
            if (r.ok) {
                await remove(it.local_id);
                drained++;
                if (typeof window !== 'undefined') {
                    window.dispatchEvent(new CustomEvent('tsr.synced', {
                        detail: it.local_id,
                    }));
                }
            } else if (r.status >= 400 && r.status < 500) {
                // 422 is the server's "this payload is permanently
                // invalid" (bad signature, etc) — drop it, otherwise
                // we re-POST every drain cycle forever. Other 4xx
                // (auth, CSRF, validation the user can fix) we keep
                // queued so a corrected submit can land later.
                const isPermanent = r.status === 422;
                if (isPermanent) {
                    await remove(it.local_id);
                }
                if (typeof window !== 'undefined') {
                    window.dispatchEvent(new CustomEvent('tsr.sync_failed', {
                        detail: {
                            localId: it.local_id,
                            reason: 'validation',
                            status: r.status,
                            dropped: isPermanent,
                        },
                    }));
                }
            }
        } catch (e) {
            // network blip — leave it queued
        }
    }

    // The server-side drainer handles DB rows. We only invoke it
    // when the *server* has its own pending rows (e.g. the form
    // submit went through Livewire and the row was written but
    // Monday's API was down at the time).
    if (drained === 0 && items.length === 0) {
        const ticket = (typeof window !== 'undefined' && window.__tsrTicketNumber) || '';
        if (ticket) {
            try {
                await fetch(syncUrlFor(ticket), {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        // POST route → VerifyCsrfToken requires the header
                        // pair. A bare POST 419s and the server-side
                        // drainer trigger silently never fires.
                        'X-CSRF-TOKEN': csrfToken(),
                        'X-XSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                });
            } catch (e) { /* silent */ }
        }
    }

    return { drained };
}

// -----------------------------------------------------------------------
//  Wiring
// -----------------------------------------------------------------------

if (typeof window !== 'undefined') {
    window.addEventListener('online',  () => { drain(); });
    window.addEventListener('offline', () => {
        window.dispatchEvent(new CustomEvent('tsr.offline'));
    });
    setInterval(() => drain(), POLL_MS);
    document.addEventListener('DOMContentLoaded', () => drain());

    // Expose the public API on `window` so the Alpine tsrForm
    // component can call submitTsr() / drain() without needing to
    // import the module. The file is loaded as a plain <script>
    // (not a module) so ES `export`s are not visible globally.
    window.submitTsr = submitTsr;
    window.__tsrOfflineDrain = drain;
}
