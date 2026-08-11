/*
 * Alpine.js component for the chat panel. Polls
 * `/tickets/{id}/chat/messages?since=<lastId>` every 3s for new
 * messages, and opportunistically subscribes to Pusher via Laravel
 * Echo when the page is configured with a `VITE_PUSHER_APP_KEY`
 * (see echo.js).
 *
 *   <div x-data="chatPanel({ ticketId, currentUserName, currentUserRole, pollUrl, csrf })"
 *        x-init="init()"> … </div>
 *
 * Design — three layers, any of which can be the source of new
 * messages on a given deployment:
 *
 *   1. **Polling** (always on, the realtime path on cPanel shared
 *      hosting where Pusher is not configured). The browser hits
 *      `/tickets/{id}/chat/messages?since=<lastSeenId>` every 2s
 *      while the tab is visible. Each response is a delta — the
 *      server only returns rows with `id > since`. Cap is 50
 *      messages per response so the payload stays small even on
 *      busy tickets. The poller is also kicked immediately after
 *      the Livewire `send()` ack (window event `chat-sent-ack`)
 *      so the sender's own message lands in their own tab within
 *      a few hundred milliseconds instead of waiting for the next
 *      scheduled tick.
 *   2. **Pusher private channel** (opportunistic, only when Echo
 *      is configured). When the page is hosted somewhere with
 *      Pusher credentials wired, we also listen on
 *      `private-ticket.{id}` for `.message.sent` events. The dedup
 *      set absorbs the case where both Pusher and polling deliver
 *      the same message.
 *   3. **Initial server render** — the page renders the first
 *      50 messages server-side; the seed step below primes the
 *      dedup set with those ids so neither layer replays them.
 *
 * Why polling first (instead of "if Pusher, only Pusher"):
 *   The user's deployment is cPanel shared hosting where neither
 *   a WebSocket service (Laravel Reverb, Soketi) nor Pusher's
 *   paid tier is available. Polling is the deployment-realistic
 *   realtime. Pusher becomes a free upgrade: when the user adds
 *   `PUSHER_APP_*` to `.env` and `VITE_PUSHER_*` to `.env`, the
 *   same client switches on the live path automatically without
 *   any code change.
 *
 * Pause-on-hidden-tab: the page visibility API pauses polling
 * when the tab is backgrounded and catches up on the next
 * visibilitychange. This is the standard "don't waste server
 * cycles on backgrounded tabs" pattern.
 *
 * Init de-dup: Livewire 3 can fire `init()` twice during
 * hydration. The `_initialized` flag plus a module-level
 * `_pollerState` registry guarantee exactly one polling timer per
 * (ticketId, page lifetime).
 */

const POLL_INTERVAL_MS = 2000;

// Module-level state keyed by ticketId. Alpine can re-init the
// data object (Livewire 3 hydration) but the timer + dedup state
// must live at module scope so the second init() finds them and
// doesn't start a second timer.
const _pollerState = new Map();

window.chatPanel = function ({ ticketId, currentUserName, currentUserRole, pollUrl, csrf }) {
    return {
        ticketId,
        currentUserName,
        currentUserRole,
        pollUrl,
        csrf,
        connecting: true,
        connected:  false,

        // Track which message ids we have already rendered so any
        // duplicate (poll-then-pusher, pusher-then-poll, or two
        // timers running for one tab) is dropped instead of
        // appending a second row.
        _seenIds:     new Set(),
        _initialized: false,
        _pollState:   null,

        init() {
            if (this._initialized) return;
            this._initialized = true;

            // Seed the dedup set from BOTH the Livewire `messages` prop
            // (if the parent passed one) AND the server-rendered rows in
            // the DOM. The DOM scan is the authoritative source on the
            // customer side, where the bubble factory does not pass
            // `messages` into chatPanel. Without the DOM scan the first
            // poll would re-append every prior message and the chat log
            // would have duplicates.
            if (Array.isArray(this.messages)) {
                for (const m of this.messages) {
                    if (m && m.id != null) this._seenIds.add(m.id);
                }
            }
            const log = document.getElementById(`chat-log-${this.ticketId}`);
            if (log) {
                for (const row of log.querySelectorAll('[data-server-id]')) {
                    const id = parseInt(row.getAttribute('data-server-id'), 10);
                    if (Number.isFinite(id)) this._seenIds.add(id);
                }
            }

            // Start the polling loop (always on, even if Pusher
            // also connects — it's the only realtime path on
            // cPanel shared hosting where Pusher is not configured).
            this._startPolling();

            // Opportunistic Pusher subscription. If `window.echo()`
            // isn't configured (no Pusher key in .env), `getEcho()`
            // returns null and we fall back to polling alone.
            this._subscribePusherIfAvailable();

            // When the Livewire `send()` finishes, the
            // `chat-sent-ack` window event is dispatched. We use it
            // as a "poll now" trigger so the sender's own message
            // appears in their own tab without waiting for the
            // 2s tick. The deferred poll gives the DB write time
            // to commit (Livewire returns after the controller
            // returns, so the row is already persisted when the
            // event fires; we still wait 300ms to be safe).
            window.addEventListener('chat-sent-ack', () => {
                setTimeout(() => this._tickNow(), 300);
            });
        },

        /**
         * Force an immediate poll cycle. Public so external
         * triggers (the `chat-sent-ack` event above) can kick the
         * poller. Safe to call from anywhere — the `inflight`
         * guard inside `tick()` prevents overlap.
         */
        _tickNow() {
            if (this._pollState && ! this._pollState.inflight) {
                // Call the same logic the setInterval fires by
                // invoking tick() via a synthetic call. We re-read
                // the closure here.
                const state = this._pollState;
                if (state.paused) return;
                state.inflight = true;
                this._runTick(state).finally(() => { state.inflight = false; });
            }
        },

        /**
         * The actual fetch + parse + append logic, factored out
         * so `_tickNow()` can call it without re-entering the
         * setInterval closure.
         */
        async _runTick(state) {
            try {
                const url = new URL(this.pollUrl, window.location.origin);
                url.searchParams.set('since', String(state.lastId));
                const res = await fetch(url.toString(), {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                });
                if (! res.ok) {
                    this.connecting = false;
                    this.connected  = false;
                    return;
                }
                const data = await res.json();
                this.connecting = false;
                this.connected  = true;
                if (Array.isArray(data.messages)) {
                    for (const m of data.messages) {
                        if (m && m.id != null && ! this._seenIds.has(m.id)) {
                            this._seenIds.add(m.id);
                            this.appendMessage({
                                id:          m.id,
                                body:        m.body,
                                sender_role: m.sender_role,
                                sender_name: m.sender_name,
                                created_at:  m.created_at,
                                mine:        m.mine,
                            });
                        }
                        if (m && m.id != null && m.id > state.lastId) {
                            state.lastId = m.id;
                        }
                    }
                }
                if (typeof data.max_id === 'number' && data.max_id > state.lastId) {
                    state.lastId = data.max_id;
                }
            } catch (_) {
                this.connecting = false;
            }
        },

        /**
         * The polling loop. Started once per (ticketId, page
         * lifetime) — see `_pollerState` registry at module top.
         */
        _startPolling() {
            const key = String(ticketId);
            let state = _pollerState.get(key);
            if (state) {
                // Already running for this ticketId (Livewire re-init).
                this._pollState = state;
                return;
            }

            state = {
                lastId: this._maxSeenId(),
                timer:  null,
                inflight: false,
                paused: document.hidden,
            };
            _pollerState.set(key, state);
            this._pollState = state;

            const tick = () => {
                if (state.inflight || state.paused) return;
                state.inflight = true;
                this._runTick(state).finally(() => { state.inflight = false; });
            };

            // First tick immediately (so a message that landed
            // between page load and init() is picked up within ms,
            // not 2s later). Then schedule the steady-state tick.
            tick();
            state.timer = setInterval(tick, POLL_INTERVAL_MS);

            // Pause on tab hide, resume on visible.
            document.addEventListener('visibilitychange', () => {
                state.paused = document.hidden;
                if (! document.hidden) {
                    // Catch up the moment the tab comes back.
                    tick();
                }
            });
        },

        /**
         * Opportunistic Pusher subscription. If `window.echo()` is
         * configured (VITE_PUSHER_APP_KEY was set at build time),
         * wire the same dedup pipeline as the polling loop. If
         * Echo isn't there, this is a no-op and polling is the
         * sole realtime path.
         */
        _subscribePusherIfAvailable() {
            if (typeof window.echo !== 'function') return;
            let echo;
            try {
                echo = window.echo();
            } catch (_) {
                return; // Pusher not configured; polling handles it.
            }
            if (! echo) return;

            const registry = (window.__chatPanelListeners ||= new Map());
            const key = String(ticketId);
            let channel = registry.get(key);
            if (! channel) {
                channel = echo.private(`ticket.${ticketId}`);
                registry.set(key, channel);
            }

            channel.listen('.message.sent', (e) => {
                if (e.id != null && this._seenIds.has(e.id)) return;
                if (e.id != null) this._seenIds.add(e.id);
                this.appendMessage({
                    id:          e.id,
                    body:        e.body,
                    sender_role: e.sender_role,
                    sender_name: e.sender_name,
                    created_at:  e.created_at,
                    mine:        e.sender_name === currentUserName
                                && e.sender_role === currentUserRole,
                });
                if (e.id != null && e.id > this._pollState.lastId) {
                    this._pollState.lastId = e.id;
                }
            });
        },

        /**
         * Largest message id we've already seen. Used to seed the
         * polling cursor on first tick so the server doesn't
         * re-deliver the server-rendered history.
         */
        _maxSeenId() {
            let max = 0;
            for (const id of this._seenIds) {
                if (id > max) max = id;
            }
            return max;
        },

        appendMessage(msg) {
            const log = document.getElementById(`chat-log-${this.ticketId}`);
            if (! log) return;

            // Clear the "no messages yet" placeholder on first message.
            const empty = log.querySelector('.chat-empty');
            if (empty) empty.remove();

            const row = document.createElement('div');
            row.className = `flex ${msg.mine ? 'justify-end' : 'justify-start'}`;
            if (msg.id !== undefined && msg.id !== null) row.dataset.serverId = msg.id;
            // data-mine lets the chat-bubble MutationObserver skip
            // the sender's own messages when bumping the unread
            // counter. Server-rendered rows already have this; we
            // add it on JS-appended rows for consistency.
            row.dataset.mine = msg.mine ? '1' : '0';

            const bubble = document.createElement('div');
            bubble.className = `max-w-[80%] rounded-lg px-4 py-2 text-sm ${msg.mine
                ? 'bg-indigo-600 text-white'
                : 'bg-gray-100 text-gray-900'}`;

            if (! msg.mine) {
                const head = document.createElement('div');
                head.className = 'text-xs font-semibold mb-1 opacity-70';
                head.innerHTML = `${escapeHtml(msg.sender_name)} <span class="ml-1 px-1.5 py-0.5 rounded bg-white/60 text-[10px] uppercase tracking-wider">${escapeHtml(msg.sender_role)}</span>`;
                bubble.appendChild(head);
            }

            const body = document.createElement('div');
            body.className = 'whitespace-pre-wrap break-words chat-msg-body';
            body.textContent = msg.body;
            bubble.appendChild(body);

            const ts = document.createElement('div');
            ts.className = `text-[10px] mt-1 chat-msg-ts ${msg.mine ? 'text-indigo-100' : 'text-gray-400'}`;
            ts.textContent = formatTs(msg.created_at);
            bubble.appendChild(ts);

            row.appendChild(bubble);
            log.appendChild(row);
            this.scrollToBottom();
        },

        scrollToBottom() {
            const log = document.getElementById(`chat-log-${this.ticketId}`);
            if (! log) return;
            requestAnimationFrame(() => {
                log.scrollTop = log.scrollHeight;
            });
        },
    };
};

function escapeHtml(s) {
    return String(s ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function formatTs(iso) {
    try {
        const d  = new Date(iso);
        const m  = d.toLocaleString('en-US', { month: 'short' });
        const dd = d.getDate();
        const t  = d.toLocaleString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
        return `${m} ${dd}, ${t}`;
    } catch (_) {
        return '';
    }
}
