/*
|--------------------------------------------------------------------------
| Realtime dashboard listener
|--------------------------------------------------------------------------
|
| Listens for region-scoped Pusher events (`ticket.created` and
| `ticket.claimed`) and dispatches the matching Livewire events
| (`ticket.created` / `ticket.claimed`) on the document so any
| Livewire component on the page can react. The TSP dashboard
| Livewire component (`App\Livewire\Tsp\Dashboard`) listens for
| these events and runs `loadLists()` to refresh the available
| pool + stats + my-tickets list.
|
| Channel subscription:
|   - `region.<tspRegion>`        → ticket.created events for the
|                                   TSP's region
|   - `region.all`                → ticket.claimed events so the
|                                   pool drops claimed tickets
|                                   immediately, even if the claim
|                                   came from another TSP
|   - The customer's region is
|     resolved server-side        → only relevant TSPs are
|                                   authorized to join the channel
|     by routes/channels.php      (see `Broadcast::channel('region.{region}', ...)`)
|
| The script is included from the TSP dashboard view via
| `@once @push('scripts')` — the view reads the TSP's region from
| the user record and renders the right channel names. This file
| is loaded by `resources/js/app.js` and runs once per page.
|
| Idempotency: the subscription is registered only once per page
| (guarded by a module-scope flag) so a Livewire re-render that
| re-runs the @push block doesn't open duplicate subscriptions.
*/

const TICKET_CREATED = 'ticket.created';
const TICKET_CLAIMED = 'ticket.claimed';
const TRANSFER_REQUESTED = 'transfer.requested';
const TICKET_TRANSFERRED = 'ticket.transferred';

const subscriptionRegistry = window.__realtimeDashboardSubscriptions ??= new Set();
let initialized = false;

function dispatchLivewire(name, payload) {
    // Livewire 3 listens on `window` for dispatched events. The
    // dashboard's #[On(name)] listener will pick this up and
    // run its handler. We also fire a CustomEvent on document
    // for any non-Livewire listener (the test scripts use this
    // to verify the wiring without booting Livewire).
    try {
        window.dispatchEvent(new CustomEvent(name, { detail: payload }));
    } catch (e) {
        // Older browsers / no CustomEvent constructor — fall
        // through to the Livewire path which uses the same
        // window event surface.
    }
    if (window.Livewire && typeof window.Livewire.dispatch === 'function') {
        try {
            window.Livewire.dispatch(name, payload);
        } catch (e) {
            // Livewire might not be loaded yet; the page-load
            // retry below will catch it.
        }
    }
}

function subscribeRegionChannels(echo, regionCode) {
    // Build the list of channels the TSP should join. The
    // `region.all` channel is the catch-all for admins and for
    // TSPs whose region can't be resolved server-side.
    const codes = ['all'];
    if (regionCode) {
        codes.push(String(regionCode).toLowerCase());
    }

    for (const code of codes) {
        const key = `region.${code}`;
        if (subscriptionRegistry.has(key)) {
            continue; // already subscribed
        }
        subscriptionRegistry.add(key);

        try {
            echo
                .private(`region.${code}`)
                .listen('.ticket.created', (e) => {
                    dispatchLivewire(TICKET_CREATED, e);
                })
                .listen('.ticket.claimed', (e) => {
                    dispatchLivewire(TICKET_CLAIMED, e);
                })
                .listen('.ticket.transfer_requested', (e) => {
                    dispatchLivewire(TRANSFER_REQUESTED, e);
                })
                .listen('.ticket.transferred', (e) => {
                    dispatchLivewire(TICKET_TRANSFERRED, e);
                });
        } catch (err) {
            // Pusher / Echo may not be configured in the dev
            // environment. Log and continue — the dashboard
            // still works via the 20s poll fallback.
            // eslint-disable-next-line no-console
            console.warn('[realtime-dashboard] subscribe failed for', key, err);
        }
    }
}

export function initRealtimeDashboard({ regionCode } = {}) {
    if (initialized) {
        // Already initialised on this page — but a Livewire
        // re-render may have changed the region prop, so
        // re-subscribe to the new region (idempotent via
        // subscriptionRegistry).
        initialized = false;
    }
    initialized = true;

    if (typeof window.echo !== 'function') {
        // echo.js not loaded — skip silently. The dashboard
        // will fall back to 20s polling.
        return;
    }
    const echo = window.echo();
    if (!echo) {
        return;
    }

    subscribeRegionChannels(echo, regionCode);
}

// Expose on window for the inline script in the dashboard view
// (resources/views/livewire/tsp/dashboard.blade.php) which calls
// `window.__realtimeDashboard.init({ regionCode })` on page load.
// The export name uses a fixed prefix so other bundles (the test
// scripts) can stub it cleanly via the same surface.
if (typeof window !== 'undefined') {
    window.__realtimeDashboard = {
        init: initRealtimeDashboard,
    };
}
