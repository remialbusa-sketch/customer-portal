<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs font-semibold tracking-widest uppercase text-base-content/50 mb-1">
                    Service request
                </p>
                <h2 class="font-semibold text-2xl text-base-content leading-tight truncate">
                    {{ $ticket['name'] ?: ('Ticket #' . $ticket['id']) }}@if(!empty($ticket['column_values']['text_mm5c1w5n']['text'])) &mdash; {{ $ticket['column_values']['text_mm5c1w5n']['text'] }}@endif
                </h2>
            </div>
            <a href="{{ route('dashboard') }}" class="btn btn-ghost btn-sm gap-1 self-start sm:self-auto">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                Back to tickets
            </a>
        </div>
    </x-slot>

    @php
        $status   = $ticket['column_values']['status95']['text']                  ?? null;
        $rtype    = $ticket['column_values']['request_type']['text']              ?? null;
        $account  = $ticket['column_values']['lookup_mm4f1f6y']['display_value'] ?? null;
        $branch   = $ticket['column_values']['lookup_mm4fj9gp']['display_value'] ?? null;
        $created  = $ticket['column_values']['date']['text']                      ?? null;
        $desc     = $ticket['column_values']['long_text7']['text']                ?? null;
        $subject  = $ticket['column_values']['text_mm5c1w5n']['text']             ?? null;

        // Brand/model — text columns on the Tickets board. Customers
        // can supply free-text (it's not strictly typed in the catalog)
        // so we render a graceful fallback for legacy tickets where
        // neither column exists.
        $brand    = $ticket['column_values']['text_mm5apcrc']['text'] ?? null;
        $model    = $ticket['column_values']['text_mm5am2kf']['text'] ?? null;

        // Map status text → DaisyUI badge tone (matches the customer
        // dashboard / create form so this view feels consistent).
        $statusLower = strtolower((string) $status);
        $statusBadge = match (true) {
            str_contains($statusLower, 'new') || str_contains($statusLower, 'open')
                => 'badge-info',
            str_contains($statusLower, 'progress')
                => 'badge-warning',
            str_contains($statusLower, 'awaiting')
                => 'badge-accent',
            str_contains($statusLower, 'resolved')
                || str_contains($statusLower, 'closed')
                || str_contains($statusLower, 'done')
                || str_contains($statusLower, 'complete')
                => 'badge-success',
            default
                => 'badge-ghost',
        };

        // `isClosed` is the canonical "this ticket can no longer
        // accept chat messages" boolean. It is consumed by:
        //   1. The chat-bubble component (disables send + input)
        //   2. The `send()` action in Customer/ChatController (server-
        //      side guard, defense in depth)
        //   3. The realtime-status banner below (so the customer
        //      sees "Closed" instead of "Working on it" the moment
        //      the status flips to RESOLVED)
        // Matches the set used everywhere else in the app — adding
        // a new terminal status here means adding it to the Monday
        // status column enum, the TSP dashboard counters, the
        // chat-bubble guard, and any other closed-status consumer.
        $isClosed = str_contains($statusLower, 'resolved')
            || str_contains($statusLower, 'closed')
            || str_contains($statusLower, 'done')
            || str_contains($statusLower, 'complete');

        // Resolve the assigned TSP name(s) from the People column.
        // The People column value is JSON like
        //   {"personsAndTeams":[{"id":12345,"kind":"person"}, ...]}
        // and is parsed into a list of monday person ids by
        // MondayClient::listTickets() — but here we have a raw
        // `getItem()` payload, so we parse it ourselves.
        $tspPersonIds = [];
        $tspColId = config('services.monday.tickets_columns.tsp');
        $tspVal = $tspColId ? ($ticket['column_values'][$tspColId]['value'] ?? null) : null;
        if ($tspVal) {
            $decoded = json_decode($tspVal, true);
            if (is_array($decoded) && isset($decoded['personsAndTeams']) && is_array($decoded['personsAndTeams'])) {
                foreach ($decoded['personsAndTeams'] as $row) {
                    if (isset($row['id']) && ($row['kind'] ?? 'person') === 'person') {
                        $tspPersonIds[] = (int) $row['id'];
                    }
                }
            }
        }
        $tspNameMap = \App\Services\MondayClient::resolveTspNames($tspPersonIds);
        $assignedNames = [];
        foreach ($tspPersonIds as $pid) {
            $name = $tspNameMap[(string) $pid] ?? null;
            if ($name) {
                $assignedNames[] = $name;
            } else {
                // Unknown TSP — fall back to the raw Monday id so
                // the row still has something visible.
                $assignedNames[] = 'TSP #' . $pid;
            }
        }
    @endphp

    <div class="py-2">
        <div class="max-w-3xl mx-auto sm:px-4 lg:px-6 space-y-6">

            {{-- ───── Real-time banner: ticket assignment + closed notice ─────
                 Subscribes to the per-ticket customer channel via the
                 shared `window.echo` instance. When a TSP claims this
                 ticket elsewhere (`ticket.claimed` Pusher event), the
                 banner shows "<name> has been assigned to your ticket".
                 When the ticket status flips to a terminal value
                 (`ticket.status.changed` event), the banner shows a
                 "This ticket is closed" notice that mirrors the
                 chat-bubble's disabled state so the customer isn't
                 confused by a chat input that's locked.

                 Pure client-side; no server round-trip. The server
                 still has the canonical `isClosed` boolean for
                 authz on the chat-send endpoint. --}}
            <div
                id="realtime-ticket-banner"
                x-data="customerTicketBanner({
                    ticketId: @js((string) $ticket['id']),
                    initialIsClosed: @js($isClosed),
                    initialHasTsp: @js(!empty($assignedNames)),
                })"
                x-show="visible"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 -translate-y-1"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-cloak
                :class="tone === 'success' ? 'bg-success/10 border-success/40 text-success-content' : 'bg-warning/10 border-warning/40 text-warning-content'"
                class="rounded-xl border px-4 py-3 flex items-start gap-3"
                role="status"
            >
                <div :class="tone === 'success' ? 'bg-success/20 text-success' : 'bg-warning/20 text-warning'"
                     class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0">
                    <svg x-show="tone === 'success'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    <svg x-show="tone !== 'success'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold" :class="tone === 'success' ? 'text-success' : 'text-warning'" x-text="title"></p>
                    <p class="text-xs mt-0.5" :class="tone === 'success' ? 'text-success-content/80' : 'text-warning-content/80'" x-text="body"></p>
                </div>
            </div>

            {{-- ───────────────── Customer Information (consolidated) ─────────────────
                 One card containing:
                   • Account hero (icon badge + account name + branch chip)
                   • Status / Type horizontal row (DaisyUI badge for status)
                   • Affected Machine callout (amber-tinted, brand · model)
                   • Submitted (single column on customer view — no email row)
                   • Real-time status banner (still embedded here so it
                     stays anchored under the customer's machine info)
                   • Description (full width)
                 The Customer ticket view does NOT show the customer's
                 own email — that lives in the page nav header. --}}
            <x-ui.card padding="p-0">
                <x-slot:icon>
                    <span aria-hidden="true" class="w-7 h-7 rounded-lg bg-primary/10 text-primary flex items-center justify-center shrink-0">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7l9-4 9 4M3 7l9 4 9-4M3 7v10l9 4 9-4V7M12 11v10"/></svg>
                    </span>
                </x-slot:icon>

                <div class="px-5 pb-4 flex items-start gap-3 border-b border-base-300/70">
                    <div class="flex-1 min-w-0">
                        <div class="text-[11px] font-semibold text-base-content/60 uppercase tracking-wider">Account</div>
                        <div class="mt-0.5 text-base font-semibold text-base-content truncate">
                            {{ $account ?? '—' }}
                        </div>
                        @if ($branch)
                            <span class="inline-flex items-center mt-1.5 px-2 py-0.5 rounded text-[11px] font-medium bg-base-200 text-base-content/80">
                                {{ $branch }}
                            </span>
                        @endif
                    </div>
                </div>

                {{-- Status / Type row --}}
                <div class="px-5 py-4 grid grid-cols-2 gap-3 border-b border-base-300/70">
                    <div>
                        <div class="text-[11px] font-semibold text-base-content/60 uppercase tracking-wider">Status</div>
                        @if ($status)
                            <span class="badge {{ $statusBadge }} badge-sm mt-1 gap-1 font-medium">
                                {{ $status }}
                            </span>
                        @else
                            <div class="mt-1 text-sm font-medium text-base-content/60">—</div>
                        @endif
                    </div>
                    <div>
                        <div class="text-[11px] font-semibold text-base-content/60 uppercase tracking-wider">Type</div>
                        <div class="mt-1 text-sm font-medium text-base-content">{{ $rtype ?? '—' }}</div>
                    </div>
                </div>

                {{-- Assigned technician callout (primary-tinted).
                     Hidden when no TSP has been assigned yet — i.e.
                     the ticket is still in the regional pool. Once
                     a TSP claims it, the customer can see their
                     name and reach out via the chat bubble. --}}
                @if (!empty($assignedNames))
                    <div class="px-5 py-4 border-b border-base-300/70">
                        <div class="rounded-lg bg-primary/5 border border-primary/20 px-3 py-2.5 flex items-center gap-3">
                            <div class="flex-shrink-0 w-9 h-9 rounded-md bg-primary/15 text-primary flex items-center justify-center" aria-hidden="true">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="text-[11px] font-semibold text-primary uppercase tracking-wider">Assigned technician</div>
                                <div class="mt-0.5 text-sm font-semibold text-base-content truncate">
                                    {{ implode(', ', $assignedNames) }}
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Affected Machine callout (amber-tinted). Hidden
                     entirely when both brand and model are missing —
                     legacy tickets shouldn't show an empty card. --}}
                @if ($brand || $model)
                    <div class="px-5 py-4 border-b border-base-300/70">
                        <div class="rounded-lg bg-warning/10 border border-warning/30 px-3 py-2.5 flex items-center gap-3">
                            <div class="flex-shrink-0 w-9 h-9 rounded-md bg-warning/20 text-warning flex items-center justify-center" aria-hidden="true">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="text-[11px] font-semibold text-warning uppercase tracking-wider">Affected machine</div>
                                <div class="mt-0.5 text-sm font-semibold text-base-content truncate">
                                    @if ($brand && $model)
                                        {{ $brand }} <span class="text-base-content/30" aria-hidden="true">·</span> {{ $model }}
                                    @elseif ($brand)
                                        {{ $brand }}
                                    @else
                                        {{ $model }}
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Submitted (single column — customer view doesn't show
                     the customer's own email here; that's already in the
                     page header / nav). --}}
                <div class="px-5 py-4 border-b border-base-300/70">
                    <div class="text-[11px] font-semibold text-base-content/60 uppercase tracking-wider">Submitted</div>
                    <div class="mt-0.5 text-sm text-base-content">{{ $created ?? '—' }}</div>
                </div>

                {{-- Subject --}}
                @if ($subject)
                    <div class="px-5 py-4 border-b border-base-300/70">
                        <div class="text-[11px] font-semibold text-base-content/60 uppercase tracking-wider">Subject</div>
                        <div class="mt-0.5 text-sm font-medium text-base-content">{{ $subject }}</div>
                    </div>
                @endif

                {{-- Description --}}
                @if ($desc)
                    <div class="px-5 py-4">
                        <div class="text-[11px] font-semibold text-base-content/60 uppercase tracking-wider">Description</div>
                        <p class="mt-1 text-sm text-base-content whitespace-pre-wrap">{{ $desc }}</p>
                    </div>
                @endif
            </x-ui.card>
        </div>
    </div>

    {{-- Floating chat bubble (replaces the full-width chat panel).
         `:is-closed` is the canonical "ticket is in a terminal state"
         boolean computed above. When true, the chat-bubble disables
         the input + send button and shows a "This ticket is closed"
         notice. The server-side `Customer/ChatController::send()`
         action enforces the same restriction as a defense-in-depth
         check (returns 403 if a stale tab tries to POST). --}}
    <livewire:chat-bubble
        :ticket-id="$ticket['id']"
        :ticket-name="$ticket['name'] ?? null"
        :current-user-name="$user->name"
        :current-user-role="$user->role"
        :messages="$messages"
        :is-closed="$isClosed"
    />
</x-app-layout>

{{-- Boot the customerTicketBanner Alpine factory. The factory
     subscribes to the per-ticket Pusher channel so the customer
     sees the "TSP assigned" toast the moment the claim lands
     elsewhere. We also push to the realtime-banners init that
     the TSP-side dashboard reuses (window.__realtimeDashboard),
     so adding a new event type only needs the channel
     authorization + the listener in one place. --}}
@once
    @push('scripts')
        <script>
            (function () {
                var bannerEl = document.getElementById('realtime-ticket-banner');
                if (!bannerEl || typeof Alpine === 'undefined') return;
                // Alpine picks up the x-data attribute on first
                // scan; the factory is defined inline in
                // customer-ticket-banner.js below. We just make
                // sure the script that defines it loads.
            })();
        </script>
    @endpush
@endonce
