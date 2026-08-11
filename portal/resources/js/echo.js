import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

/*
|--------------------------------------------------------------------------
| Laravel Echo (Pusher) bootstrap
|--------------------------------------------------------------------------
|
| Echo is built lazily on first call to `window.echo()`. If the page
| was built without a VITE_PUSHER_APP_KEY (the cPanel shared hosting
| case), `getEcho()` returns null and the chat panel falls back to
| the 3-second polling endpoint — see chat-panel.js.
|
| The detection is at the build-time env level: if the key is
| missing at `npm run build`, the value baked into the bundle is
| `undefined`, and `getEcho()` never even attempts to construct a
| Pusher client. This means zero WebSocket connection attempts,
| zero console errors, and zero bandwidth on cPanel deployments
| where Pusher isn't configured.
|
| To enable Pusher, fill in the VITE_PUSHER_* vars in .env and
| rebuild assets with `npm run build`. No code change required.
*/

let echoInstance = null;
let echoBuilt = false;

function readEnv(key) {
    const v = import.meta.env[key];
    return (typeof v === 'string' && v.length > 0) ? v : null;
}

export function getEcho() {
    if (echoBuilt) return echoInstance;

    echoBuilt = true;

    const key     = readEnv('VITE_PUSHER_APP_KEY');
    const cluster = readEnv('VITE_PUSHER_APP_CLUSTER');

    if (! key || ! cluster) {
        // Pusher not configured. Leave echoInstance = null so
        // chat-panel.js's opportunistic subscription is a
        // no-op and polling carries the realtime path.
        return null;
    }

    echoInstance = new Echo({
        broadcaster: 'pusher',
        key,
        cluster,
        forceTLS: true,
        encrypted: true,
        authEndpoint: '/broadcasting/auth',
        auth: {
            headers: {
                'X-CSRF-TOKEN': document
                    .querySelector('meta[name="csrf-token"]')
                    ?.getAttribute('content') ?? '',
                'X-Requested-With': 'XMLHttpRequest',
            },
        },
    });

    return echoInstance;
}
