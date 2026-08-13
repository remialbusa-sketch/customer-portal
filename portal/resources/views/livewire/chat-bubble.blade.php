<?php

use Livewire\Volt\Component;

new class extends Component
{
    public int $ticketId;
    public string $currentUserName;
    public string $currentUserRole;
    public array  $messages = [];

    // Monday item name (e.g. "TICKET-00079") shown in the header.
    // Falls back to the numeric ticket id when empty.
    public ?string $ticketName = null;

    // Canonical "this ticket is in a terminal state" boolean.
    // When true, the input is disabled, the send button is
    // disabled, and a "Ticket is closed" notice replaces the
    // form. The server-side `Customer/ChatController::send()`
    // and `Tsp/ChatController::send()` actions also refuse
    // messages on closed tickets — this prop is purely a UX
    // affordance for the chat-bubble itself. The Pusher
    // `ticket.status.changed` event flips this client-side via
    // the customer-ticket-banner factory if the status changes
    // mid-session, so the disabled state stays in sync without
    // a page reload.
    public bool $isClosed = false;

    // Body for the send form. Lives on this component (the bubble
    // owns the input), not on the inner `chatPanel` Alpine factory.
    public string $body = '';

    public function mount(
        int $ticketId,
        string $currentUserName,
        string $currentUserRole,
        array $messages = [],
        bool $isClosed = false,
        ?string $ticketName = null,
    ): void {
        $this->ticketId        = $ticketId;
        $this->currentUserName = $currentUserName;
        $this->currentUserRole = $currentUserRole;
        $this->messages        = $messages;
        $this->isClosed        = $isClosed;
        $this->ticketName      = $ticketName;
    }

    public function send(): void
    {
        // Defense in depth — even if the form is rendered with
        // the input/button disabled, a stale tab or a hand-crafted
        // POST could still hit this method. Bail out so the user
        // never sees a successful send on a closed ticket.
        if ($this->isClosed) {
            $this->skipRender();
            return;
        }

        $body = trim($this->body);
        if ($body === '') {
            return;
        }

        $request = request();
        $request->merge(['body' => $body]);

        $controller = match (true) {
            in_array($this->currentUserRole, ['fse', 'its', 'manager', 'admin'], true)
                => app(\App\Http\Controllers\Tsp\ChatController::class),
            default
                => app(\App\Http\Controllers\Customer\ChatController::class),
        };

        // Tsp\ChatController::send() requires the MondayClient
        // dependency as a 3rd parameter (it marks the ticket as
        // RESPONDED on the Monday board). Customer\ChatController
        // does not — it's read-only from Monday's side. Calling
        // Tsp\ChatController without it throws a TypeError which
        // Livewire 3 catches and converts to abort(419).
        if ($controller instanceof \App\Http\Controllers\Tsp\ChatController) {
            $monday = app(\App\Services\MondayClient::class);
            $controller->send($request, (string) $this->ticketId, $monday);
        } else {
            $controller->send($request, (string) $this->ticketId);
        }

        // Don't re-render: the Pusher echo will append the row, and
        // re-rendering would wipe any rows Alpine has appended in the
        // meantime. The `chat-sent-ack` window event clears the input.
        $this->skipRender();
        $this->dispatch('chat-sent-ack');
    }
}; ?>

@once
    @push('scripts')
        <script>
            // Chat bubble toggle: clicking the FAB opens a popover that
            // hosts the same chat panel markup as the legacy full-width
            // view. Alpine handles open/close and unread counts.
            window.chatBubble = function ({ ticketId, initialCount }) {
                return {
                    open: false,
                    unread: 0,
                    ticketId,
                    init() {
                        // Initial unread = server-rendered messages we
                        // didn't send. Live support staff will normally
                        // open the bubble on first load, so we only flag
                        // unread when staff has written since the page
                        // was opened.
                        this.unread = initialCount;

                        // Listen for Pusher echoes coming through the
                        // chatPanel factory: when a non-self message
                        // arrives while the bubble is closed, bump the
                        // unread count and pulse the FAB.
                        const log = document.getElementById(`chat-log-${this.ticketId}`);
                        if (! log) return;

                        const observer = new MutationObserver((muts) => {
                            for (const m of muts) {
                                for (const node of m.addedNodes) {
                                    if (! (node instanceof HTMLElement)) continue;
                                    if (node.dataset?.serverId && node.querySelector('.chat-msg-body')) {
                                        // Only bump the unread badge for
                                        // messages from the other side. A
                                        // sender's own message landing in
                                        // their own tab (via the 2s poll)
                                        // should not count as unread.
                                        if (node.dataset.mine === '1') continue;
                                        if (! this.open) {
                                            this.unread++;
                                            this.pulse();
                                        }
                                    }
                                }
                            }
                        });
                        observer.observe(log, { childList: true });
                    },
                    toggle() {
                        this.open = ! this.open;
                        if (this.open) {
                            this.unread = 0;
                            // Scroll the log to the bottom when the
                            // popover is opened so the latest message
                            // is visible.
                            this.$nextTick(() => {
                                const log = document.getElementById(`chat-log-${this.ticketId}`);
                                if (log) log.scrollTop = log.scrollHeight;
                            });
                        }
                    },
                    pulse: false,
                    pulseToggle() {
                        this.pulse = true;
                        setTimeout(() => { this.pulse = false; }, 1200);
                    },
                };
            };
        </script>
    @endpush
@endonce

<div
    x-data="chatBubble({ ticketId: @js($ticketId), initialCount: 0 })"
    class="fixed bottom-5 right-5 z-50 flex flex-col items-end gap-3"
    style="font-family: inherit;"
>
    {{-- ───── Floating panel (visible when open) ───── --}}
    <div
        x-show="open"
        x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-y-2 scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 scale-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-y-0 scale-100"
        x-transition:leave-end="opacity-0 translate-y-2 scale-95"
        class="w-[360px] max-w-[calc(100vw-2.5rem)] h-[520px] max-h-[calc(100vh-6rem)] bg-base-100 rounded-2xl shadow-2xl border border-base-300/70 flex flex-col overflow-hidden"
    >
        {{-- Panel header --}}
        <div class="px-4 py-3 bg-primary text-primary-content flex items-center justify-between flex-shrink-0">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                </div>
                <div>
                    <div class="text-sm font-semibold leading-tight">Chat with our support team</div>
                    <div class="text-[11px] text-primary-content/80">{{ $ticketName ?: ('Ticket #' . $ticketId) }}</div>
                </div>
            </div>
            <button
                type="button"
                @click="toggle()"
                class="text-primary-content/80 hover:text-primary-content p-1 rounded transition"
                aria-label="Close chat"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        {{-- Reuse the existing chat panel markup via the same
             Alpine factory. We mount it inside this popover so the
             Pusher subscription, the dedup set, and the message
             renderer all behave identically to the legacy full-width
             view. --}}
        <div
            x-data="chatPanel({
                ticketId: @js($ticketId),
                currentUserName: @js($currentUserName),
                currentUserRole: @js($currentUserRole),
                pollUrl: @js(
                    $currentUserRole === 'customer'
                        ? route('tickets.chat.poll', ['id' => $ticketId])
                        : route('tsp.tickets.chat.poll', ['id' => $ticketId])
                ),
                csrf: @js(csrf_token()),
            })"
            x-init="init()"
            class="flex-1 flex flex-col min-h-0"
        >
            <div class="px-3 py-1.5 border-b border-base-300/70 flex items-center justify-between flex-shrink-0">
                <span class="text-[10px] text-base-content/50" x-show="connecting">Connecting…</span>
                <span class="text-[10px] text-success" x-show="!connecting && connected" x-cloak>● Live</span>
            </div>
            <div
                id="chat-log-{{ $ticketId }}"
                class="flex-1 overflow-y-auto px-3 py-3 space-y-2 min-h-0"
            >
                @forelse ($messages as $msg)
                    <div data-server-id="{{ $msg['id'] }}" data-mine="{{ $msg['mine'] ? '1' : '0' }}" class="flex {{ $msg['mine'] ? 'justify-end' : 'justify-start' }}">
                        <div class="max-w-[80%] rounded-lg px-3 py-1.5 text-sm
                                    {{ $msg['mine'] ? 'bg-primary text-primary-content' : 'bg-base-200 text-base-content' }}">
                            @if (! $msg['mine'])
                                <div class="text-[10px] font-semibold mb-0.5 opacity-70">
                                    {{ $msg['sender_name'] }}
                                    <span class="ml-1 px-1 py-0.5 rounded bg-white/60 text-[9px] uppercase tracking-wider">
                                        {{ $msg['sender_role'] }}
                                    </span>
                                </div>
                            @endif
                            <div class="whitespace-pre-wrap break-words chat-msg-body">{{ $msg['body'] }}</div>
                            <div class="text-[9px] mt-0.5 chat-msg-ts {{ $msg['mine'] ? 'text-primary-content/80' : 'text-base-content/50' }}">
                                @php
                                    $ts = $msg['created_at'] ?? null;
                                    echo $ts ? \Carbon\Carbon::parse($ts)->format('M j, g:i A') : '—';
                                @endphp
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="text-center text-xs text-base-content/50 mt-8 chat-empty">
                        No messages yet — say hello
                    </div>
                @endforelse
            </div>
            {{-- Chat input row.
                 When the ticket is closed (`$isClosed` is true) the
                 form is replaced with a "Ticket is closed" notice so
                 the customer understands why the input is missing.
                 The form is also rendered with `wire:submit.prevent`
                 and a `disabled` flag, and the Livewire `send()`
                 method short-circuits if `isClosed` is true. --}}
            @if ($isClosed)
                <div
                    role="status"
                    data-test="chat-closed-notice"
                    class="border-t border-base-300/70 px-3 py-2.5 flex items-center gap-2 text-xs text-base-content/70 bg-base-200/40 flex-shrink-0"
                >
                    <svg class="w-4 h-4 shrink-0 text-base-content/50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                    <span>This ticket is closed. Chat is read-only.</span>
                </div>
            @else
                <form
                    wire:submit.prevent="send"
                    @chat-sent-ack.window="$el.querySelector('input[name=body],input').value = ''"
                    class="border-t border-base-300/70 px-2 py-2 flex gap-1.5 flex-shrink-0"
                >
                    <input
                        type="text"
                        wire:model="body"
                        placeholder="Type a message…"
                        maxlength="2000"
                        autocomplete="off"
                        class="input input-bordered input-sm flex-1 focus:outline-none focus:border-primary"
                    >
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        class="btn btn-primary btn-sm"
                    >
                        Send
                    </button>
                </form>
            @endif
        </div>
    </div>

    {{-- ───── Floating action button (FAB) ───── --}}
    <button
        type="button"
        @click="toggle()"
        :class="pulse ? 'animate-bounce' : ''"
        class="relative w-14 h-14 rounded-full bg-primary text-primary-content shadow-lg hover:opacity-90 focus:outline-none focus:ring-4 focus:ring-primary/30 transition flex items-center justify-center"
        aria-label="Open chat with support"
    >
        {{-- Chat icon when closed --}}
        <svg x-show="!open" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
        </svg>

        {{-- Close icon when open --}}
        <svg x-show="open" x-cloak class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
        </svg>

        {{-- Unread badge --}}
        <span
            x-show="unread > 0"
            x-cloak
            class="absolute -top-1 -right-1 min-w-[20px] h-5 px-1 rounded-full bg-error text-error-content text-[10px] font-bold flex items-center justify-center border-2 border-base-100"
            x-text="unread > 9 ? '9+' : unread"
        ></span>
    </button>
</div>
