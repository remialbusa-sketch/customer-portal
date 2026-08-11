<?php

use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component
{
    public int $ticketId;
    public string $currentUserName;
    public string $currentUserRole;

    /**
     * Initial chat history passed in by the parent view as a
     * plain array of associative arrays (see
     * Customer\ChatController::loadMessageHistory). The Alpine
     * wrapper handles new messages from this point on, so the
     * component itself doesn't need to re-query.
     *
     * Typed as `array` (not `Collection`) because Livewire 3 treats
     * `Collection` properties as Eloquent collections, which would
     * fail on items that are plain arrays.
     */
    public array $messages = [];

    // Canonical "this ticket is in a terminal state" boolean.
    // When true, the input is replaced with a "Ticket is closed"
    // notice so the TSP understands why the form is gone. The
    // server-side `Tsp/ChatController::send()` and
    // `Customer/ChatController::send()` actions also refuse
    // messages on closed tickets — this prop is purely a UX
    // affordance for the chat-panel itself.
    public bool $isClosed = false;

    public string $body = '';

    public function mount(
        int $ticketId,
        string $currentUserName,
        string $currentUserRole,
        array $messages = [],
        bool $isClosed = false,
    ): void {
        $this->ticketId        = $ticketId;
        $this->currentUserName = $currentUserName;
        $this->currentUserRole = $currentUserRole;
        $this->messages        = $messages;
        $this->isClosed        = $isClosed;
    }

    public function send(): void
    {
        // Defense in depth — even though the form is replaced with
        // a "Ticket is closed" notice when `$isClosed` is true, a
        // stale tab or hand-crafted POST could still hit this
        // method. Bail out so no message ever lands on a closed
        // ticket.
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

        // Skip Livewire's re-render of this component: the controller
        // already persisted the message and broadcast it on Pusher,
        // and the Alpine `chat-sent-ack` handler clears the input. A
        // re-render would also wipe any Alpine-appended rows from the
        // chat log (because the server-side template re-renders
        // from $this->messages, which doesn't include the just-sent
        // message until next page load).
        $this->skipRender();
        $this->dispatch('chat-sent-ack');
    }
}; ?>

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
    class="bg-base-100 shadow sm:rounded-2xl flex flex-col h-[640px] border border-base-300/70"
>
    <div class="px-6 py-4 border-b border-base-300/70 flex items-center justify-between">
        <h3 class="text-base font-semibold text-base-content">
            Chat with {{ $currentUserRole === 'customer' ? 'our support team' : 'the customer' }}
        </h3>
        <div class="text-xs text-base-content/50 flex items-center gap-1.5">
            <span x-show="connecting" x-cloak>Connecting…</span>
            <span x-show="!connecting && connected" x-cloak class="inline-flex items-center gap-1 text-success">
                <span class="h-2 w-2 rounded-full bg-success"></span>
                Live
            </span>
        </div>
    </div>

    <div
        id="chat-log-{{ $ticketId }}"
        class="flex-1 overflow-y-auto px-6 py-4 space-y-3"
    >
        {{ $slot ?? '' }}
        @isset($messages)
            @forelse ($messages as $msg)
                <div data-server-id="{{ $msg['id'] }}" data-mine="{{ $msg['mine'] ? '1' : '0' }}" class="flex {{ $msg['mine'] ? 'justify-end' : 'justify-start' }}">
                    <div class="max-w-[80%] rounded-lg px-4 py-2 text-sm
                                {{ $msg['mine'] ? 'bg-primary text-primary-content' : 'bg-base-200 text-base-content' }}">
                        @if (! $msg['mine'])
                            <div class="text-xs font-semibold mb-1 opacity-70">
                                {{ $msg['sender_name'] }}
                                <span class="ml-1 px-1.5 py-0.5 rounded bg-white/60 text-[10px] uppercase tracking-wider">
                                    {{ $msg['sender_role'] }}
                                </span>
                            </div>
                        @endif
                        <div class="whitespace-pre-wrap break-words">{{ $msg['body'] }}</div>
                        <div class="text-[10px] mt-1 {{ $msg['mine'] ? 'text-primary-content/80' : 'text-base-content/50' }}">
                            @php
                                $ts = $msg['created_at'] ?? null;
                                echo $ts ? \Carbon\Carbon::parse($ts)->format('M j, g:i A') : '—';
                            @endphp
                        </div>
                    </div>
                </div>
            @empty
                <div class="text-center text-sm text-base-content/50 mt-12">
                    No messages yet — say hello
                </div>
            @endforelse
        @endisset
    </div>

    {{-- Chat input row.
         When the ticket is closed (`$isClosed` is true) the form is
         replaced with a "Ticket is closed" notice so the TSP
         understands why the input is missing. The Livewire
         `send()` method also short-circuits if `isClosed` is true,
         so a stale tab can never bypass the gate. --}}
    @if ($isClosed)
        <div
            role="status"
            data-test="chat-closed-notice"
            class="border-t border-base-300/70 px-4 py-3 flex items-center gap-2 text-sm text-base-content/70 bg-base-200/40"
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
            class="border-t border-base-300/70 px-4 py-3 flex gap-2"
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
