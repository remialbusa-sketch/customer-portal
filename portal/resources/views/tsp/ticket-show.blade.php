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
            <a href="{{ route('tsp.dashboard') }}" class="btn btn-ghost btn-sm gap-1 self-start sm:self-auto">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                Back to dashboard
            </a>
        </div>
    </x-slot>

    @php
        $status   = $ticket['column_values']['status95']['text']                  ?? null;
        $rtype    = $ticket['column_values']['request_type']['text']              ?? null;
        $account  = $ticket['column_values']['lookup_mm4f1f6y']['display_value'] ?? null;
        $branch   = $ticket['column_values']['lookup_mm4fj9gp']['display_value'] ?? null;
        $email    = $ticket['column_values']['email']['text']                     ?? null;
        $created  = $ticket['column_values']['date']['text']                      ?? null;
        $desc     = $ticket['column_values']['long_text7']['text']                ?? null;
        $subject  = $ticket['column_values']['text_mm5c1w5n']['text']             ?? null;

        // Brand/model — text columns on the Tickets board. Free-text
        // values; render a graceful fallback for legacy tickets where
        // neither column exists.
        $brand    = $ticket['column_values']['text_mm5apcrc']['text'] ?? null;
        $model    = $ticket['column_values']['text_mm5am2kf']['text'] ?? null;

        // Status text → DaisyUI badge tone.
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
    @endphp

    <div class="py-2">
        <div class="max-w-7xl mx-auto sm:px-4 lg:px-6 space-y-6">

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {{-- ───────────────── Customer Information (consolidated) ─────────────────
                     One card containing:
                       • Account hero (icon badge + account name + branch chip)
                       • Status / Type horizontal row (DaisyUI badge)
                       • Affected Machine callout (amber-tinted, brand · model)
                       • Customer email + Submitted 2-column grid
                       • Description (full width)
                     Plus a "Create Service Report" button at the bottom of
                     the card that opens the TSR form in a Bootstrap modal. --}}
                <div class="lg:col-span-1">
                    {{-- Alpine `ticketStatusPoller` wrapper. The
                         `pollerXData` string is built in the controller
                         (where @js/route compile normally) and contains
                         a fully-formed JS object literal, so we just
                         echo it unescaped inside `x-data`. The card
                         body lives inside the wrapper so the badge and
                         the "Create service report" button both react
                         to polled status changes. --}}
                    <div x-data="{{ $pollerXData }}" x-init="init()">
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

                        {{-- Status / Type row (Priority removed — mirrors
                             the customer ticket-show card, which dropped
                             Priority when the field was removed from the
                             create form). The Alpine data lives on the
                             card root (above) so the status badge and
                             the Create/View TSR button at the bottom
                             can both react to the same poller state. --}}
                        <div class="px-5 py-4 grid grid-cols-2 gap-3 border-b border-base-300/70">
                            <div>
                                <div class="text-[11px] font-semibold text-base-content/60 uppercase tracking-wider flex items-center gap-1.5">
                                    Status
                                    <span x-show="lastUpdatedAt" x-cloak class="inline-flex items-center gap-1 text-[10px] font-normal text-base-content/50 normal-case tracking-normal">
                                        <span class="relative flex w-1.5 h-1.5">
                                            <span class="absolute inset-0 rounded-full bg-success/40 animate-ping" x-show="connected" x-cloak></span>
                                            <span class="relative rounded-full w-1.5 h-1.5" :class="connected ? 'bg-success' : 'bg-base-content/30'"></span>
                                        </span>
                                        <span x-text="lastUpdatedLabel" x-cloak>just now</span>
                                    </span>
                                </div>
                                @if ($status)
                                    <span class="badge badge-sm mt-1 gap-1 font-medium"
                                          :class="currentBadge"
                                          x-text="currentStatus || @js($status)">@js($status)</span>
                                @else
                                    <div class="mt-1 text-sm font-medium text-base-content/60">—</div>
                                @endif
                            </div>
                            <div>
                                <div class="text-[11px] font-semibold text-base-content/60 uppercase tracking-wider">Type</div>
                                <div class="mt-1 text-sm font-medium text-base-content">{{ $rtype ?? '—' }}</div>
                            </div>
                        </div>

                        {{-- Assigned technician(s) callout (primary-tinted).
                             Hidden when no TSP has been assigned yet — i.e.
                             the ticket is still in the regional pool. Once
                             a TSP claims it, their name shows up here so
                             managers and other TSPs (co-claim scenarios)
                             can see who's responsible. --}}
                        @if (!empty($assignedNames))
                            <div class="px-5 py-4 border-b border-base-300/70">
                                <div class="rounded-lg bg-primary/5 border border-primary/20 px-3 py-2.5 flex items-center gap-3">
                                    <div class="flex-shrink-0 w-9 h-9 rounded-md bg-primary/15 text-primary flex items-center justify-center" aria-hidden="true">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-[11px] font-semibold text-primary uppercase tracking-wider">
                                            Assigned technician{{ count($assignedNames) > 1 ? 's' : '' }}
                                        </div>
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

                        {{-- Customer email + Submitted 2-column grid --}}
                        <div class="px-5 py-4 grid grid-cols-1 sm:grid-cols-2 gap-4 border-b border-base-300/70">
                            <div class="min-w-0">
                                <div class="text-[11px] font-semibold text-base-content/60 uppercase tracking-wider">Customer email</div>
                                <div class="mt-0.5 text-sm text-base-content truncate" title="{{ $email }}">{{ $email ?? '—' }}</div>
                            </div>
                            <div class="min-w-0">
                                <div class="text-[11px] font-semibold text-base-content/60 uppercase tracking-wider">Submitted</div>
                                <div class="mt-0.5 text-sm text-base-content">{{ $created ?? '—' }}</div>
                            </div>
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
                            <div class="px-5 py-4 border-b border-base-300/70">
                                <div class="text-[11px] font-semibold text-base-content/60 uppercase tracking-wider">Description</div>
                                <p class="mt-1 text-sm text-base-content whitespace-pre-wrap">{{ $desc }}</p>
                            </div>
                        @endif

                        {{-- Create Service Report — opens an in-place modal
                             that hosts the TSR Livewire form. The modal
                             pattern is preferred over a full-page route
                             because the form is long enough to be
                             disorienting on its own URL, and the user
                             needs the ticket context (description,
                             affected machine) visible right above
                             while filling it in. The `<x-modal>`
                             component is the Breeze Tailwind/Alpine
                             modal — no Bootstrap JS or CDN is involved,
                             so it can't silently fail to open. --}}
                        <div class="px-5 py-4 bg-base-200/60 border-t border-base-300/70 flex items-center justify-between gap-3">
                            <div class="text-xs text-base-content/60"
                                 x-show="! hasReport" x-cloak>
                                On-site work complete? File the post-service report.
                            </div>
                            <div class="text-xs text-success font-medium inline-flex items-center gap-1.5"
                                 x-show="hasReport" x-cloak>
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                Service report on file
                            </div>

                            {{-- When hasReport is true, the poller swaps
                                 the Create button for a View TSR link.
                                 The href is the route built on the
                                 server when the page first rendered; if
                                 the report was created in another tab
                                 since then, the poller reloads the
                                 page (cheaper than guessing the new id
                                 from the client). --}}
                            <button
                                type="button"
                                class="btn btn-primary btn-sm gap-1.5"
                                x-show="! hasReport"
                                x-on:click="$dispatch('open-modal', 'tsr-create-{{ $ticket['id'] }}')"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                Create service report
                            </button>
                            <template x-if="hasReport && tsrShowUrl">
                                <a :href="tsrShowUrl"
                                   class="btn btn-success btn-sm gap-1.5">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                    View TSR
                                </a>
                            </template>
                            {{-- When hasReport flips true in another tab
                                 but we don't yet know the new report id
                                 (initial page render had no report),
                                 fall back to a soft reload link that
                                 picks up the right route on next page
                                 load. --}}
                            <button
                                type="button"
                                class="btn btn-success btn-sm gap-1.5"
                                x-show="hasReport && ! tsrShowUrl"
                                x-on:click="window.location.reload()"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                Refresh to view TSR
                            </button>
                        </div>
                    </x-ui.card>
                    </div>{{-- /x-data Alpine wrapper --}}

                    {{-- "TSR saved" toast. The CreateServiceReport
                         Livewire component dispatches a window-level
                         `tsr.saved` event (with the new report's
                         localId) when the form successfully saves.
                         We listen for that event here and surface a
                         4s DaisyUI toast at the bottom of the
                         customer-info column so the TSP gets
                         confirmation even though the modal closed.
                         A bit of state on the poller (savedToastVisible)
                         is flipped via a custom event, so the toast
                         and the "View TSR" link stay in sync. --}}
                    <div
                        class="toast toast-end z-50"
                        x-data="tsrSavedToast()"
                        x-init="init()"
                        x-show="visible"
                        x-cloak
                        x-transition.opacity.duration.200ms
                        style="display: none;"
                    >
                        <div class="alert alert-success shadow-lg">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            <div class="text-sm">
                                <strong>TSR saved.</strong>
                                <span class="text-base-content/80">Syncing to Monday.com.</span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Chat panel.
                     `:is-closed` is the canonical "ticket is in a
                     terminal state" boolean. When true, the
                     chat-panel disables the input + send button and
                     shows a "This ticket is closed" notice. The
                     server-side `Tsp/ChatController::send()` action
                     enforces the same restriction as a
                     defense-in-depth check. Computed via the same
                     `match` expression used by the status badge so
                     the two stay consistent. --}}
                @php
                    $ticketStatusLower = strtolower((string) ($ticket['column_values']['status95']['text'] ?? ''));
                    $ticketIsClosed = str_contains($ticketStatusLower, 'resolved')
                        || str_contains($ticketStatusLower, 'closed')
                        || str_contains($ticketStatusLower, 'done')
                        || str_contains($ticketStatusLower, 'complete');
                @endphp
                <div class="lg:col-span-2">
                    <livewire:chat-panel
                        :ticket-id="$ticket['id']"
                        :ticket-name="$ticket['name'] ?? null"
                        :current-user-name="$user->name"
                        :current-user-role="$user->role"
                        :messages="$messages"
                        :is-closed="$ticketIsClosed"
                    />
                </div>
            </div>

            {{-- Time tracker (Phase 5) --}}
            <livewire:time-tracker
                :ticket-id="$ticket['id']"
                :current-user-name="$user->name"
                :current-user-role="$user->role"
                :active="$timeActive"
                :total-seconds="$timeTotal"
            />

            {{-- Internal notes panel (TSP-only) --}}
            <livewire:internal-notes-panel
                :ticket-id="$ticket['id']"
                :current-user-name="$user->name"
                :current-user-role="$user->role"
                :notes="$notes"
            />

            {{-- Existing service report (read-only). Renders a small
                 card showing the latest TSR filed against this ticket,
                 with a link to the full detail page. Only renders when
                 at least one report exists for this ticket. --}}
            @isset($existingReport)
                @if ($existingReport)
                    <x-ui.card
                        title="Service report"
                        subtitle="The latest TSR filed against this ticket."
                        padding="p-0"
                    >
                        <x-slot:icon>
                            <span aria-hidden="true" class="w-7 h-7 rounded-lg bg-secondary/10 text-secondary flex items-center justify-center shrink-0">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            </span>
                        </x-slot:icon>
                        <x-slot:actions>
                            <a href="{{ route('tsp.service-reports.show', ['id' => $existingReport['id']]) }}"
                               class="btn btn-ghost btn-sm gap-1">
                                View full report
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            </a>
                        </x-slot:actions>

                        <div class="px-5 py-4 grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm border-t border-base-300/70">
                            <div>
                                <div class="text-[11px] font-semibold text-base-content/60 uppercase tracking-wider">Status</div>
                                <div class="mt-0.5 text-base-content">{{ $existingReport['service_status_label'] ?? '—' }}</div>
                            </div>
                            <div>
                                <div class="text-[11px] font-semibold text-base-content/60 uppercase tracking-wider">Filed by</div>
                                <div class="mt-0.5 text-base-content">{{ $existingReport['author_name'] ?? '—' }} · <span class="text-base-content/60">{{ $existingReport['author_role'] ?? '' }}</span></div>
                            </div>
                            <div>
                                <div class="text-[11px] font-semibold text-base-content/60 uppercase tracking-wider">Service window</div>
                                <div class="mt-0.5 text-base-content">
                                    {{ $existingReport['service_start_at'] ?? '—' }}
                                    →
                                    {{ $existingReport['service_end_at'] ?? '—' }}
                                </div>
                            </div>
                            <div>
                                <div class="text-[11px] font-semibold text-base-content/60 uppercase tracking-wider">Total minutes</div>
                                <div class="mt-0.5 text-base-content">{{ $existingReport['total_minutes'] ?? '—' }}</div>
                            </div>
                            @if (! empty($existingReport['job_done']))
                                <div class="sm:col-span-2">
                                    <div class="text-[11px] font-semibold text-base-content/60 uppercase tracking-wider">Job done</div>
                                    <p class="mt-0.5 text-base-content whitespace-pre-wrap">{{ $existingReport['job_done'] }}</p>
                                </div>
                            @endif
                        </div>
                    </x-ui.card>
                @endif
            @endisset
        </div>
    </div>

    {{-- ───────────────────── TSR create modal ─────────────────────
         In-place TSR (Technical Service Report) form. Uses the
         Breeze Tailwind/Alpine `<x-modal>` (no Bootstrap CDN needed)
         so opening is reliable — Alpine is always present on the
         app layout. The modal body is a Livewire component
         (<livewire:tsp.tickets.create-service-report>) which owns
         form state, draft autosave, sync state, and submission.

         Sized 4xl on desktop / fullscreen on mobile so the form has
         room to breathe. --}}
    <x-modal name="tsr-create-{{ $ticket['id'] }}" max-width="2xl" focusable>
        <div class="px-6 py-5 max-h-[85vh] overflow-y-auto" x-data x-on:keydown.escape.window="$dispatch('close-modal', 'tsr-create-{{ $ticket['id'] }}')">
            <livewire:tsp.tickets.create-service-report :ticket-number="(string) $ticket['id']" />
        </div>
    </x-modal>

    @once
        @push('scripts')
            <script>
                // ----------------------------------------------------------------
                //  ticketStatusPoller — Alpine component for the ticket-show
                //  page's status badge. Polls /tsp/tickets/{id}/status.json
                //  every 15s and, when the response shows a different
                //  status text than the page was rendered with, replaces
                //  the badge label + DaisyUI tone in place. No page
                //  reload, no Livewire round-trip — just a small fetch.
                //
                //  Also surfaces a tiny "updated Xs ago" indicator + a
                //  green pulse dot so the TSP can see at a glance that
                //  the page is still talking to the server.
                // ----------------------------------------------------------------
                window.ticketStatusPoller = function (opts) {
                    return {
                        url: opts.url,
                        intervalMs: opts.intervalMs || 15000,
                        currentStatus: opts.initialStatus || null,
                        currentBadge:  opts.initialBadge  || 'badge-ghost',
                        // Track whether a TSR exists for this ticket.
                        // The page hydrates with whatever the controller
                        // found at render time; the poller keeps it in
                        // sync so a TSR submitted in another tab turns
                        // the "Create service report" button into a
                        // "View TSR" link without a hard reload.
                        hasReport: !! opts.initialHasReport,
                        tsrShowUrl: opts.tsrShowUrl || null,
                        lastFetchedAt: null,
                        lastUpdatedAt: null,
                        connected: false,
                        _timer: null,
                        // When the report flips to "exists" in a tab that
                        // didn't have a TSR at page render, we don't know
                        // the new id from the client side. We stash a
                        // "we'd need to reload to get the right link"
                        // flag and let the view show a "Refresh to view
                        // TSR" button instead of a broken link.
                        _needsReloadForLink: false,

                        // Map a status text to a DaisyUI badge tone. The
                        // exact same match() expression lives in the
                        // Blade @php block; keep them in sync. Doing
                        // it in JS too avoids round-tripping just to
                        // recolor an existing pill.
                        _badgeFor(text) {
                            const s = (text || '').toLowerCase();
                            if (s.includes('new') || s.includes('open')) return 'badge-info';
                            if (s.includes('progress'))                  return 'badge-warning';
                            if (s.includes('awaiting'))                  return 'badge-accent';
                            if (s.includes('resolved') || s.includes('closed') || s.includes('done') || s.includes('complete')) return 'badge-success';
                            return 'badge-ghost';
                        },

                        get lastUpdatedLabel() {
                            if (! this.lastUpdatedAt) return '';
                            const sec = Math.max(0, Math.floor((Date.now() - this.lastUpdatedAt) / 1000));
                            if (sec < 5)   return 'just now';
                            if (sec < 60)  return sec + 's ago';
                            if (sec < 3600) return Math.floor(sec / 60) + 'm ago';
                            return Math.floor(sec / 3600) + 'h ago';
                        },

                        init() {
                            this.lastUpdatedAt = Date.now();
                            this._tick();
                            // Pause the poller while the tab is hidden,
                            // resume on focus — a backgrounded tab polling
                            // Monday is wasted bandwidth and may even
                            // burn API quota on long sessions.
                            document.addEventListener('visibilitychange', () => {
                                if (document.hidden) {
                                    this._stop();
                                } else {
                                    this._tick();
                                }
                            });
                            // When the CreateServiceReport Livewire
                            // component saves a TSR, it dispatches a
                            // window-level `tsr.saved` event. Flip
                            // hasReport to true so the "Create service
                            // report" button swaps for a "View TSR"
                            // link (or "Refresh to view TSR" if we
                            // didn't have the link rendered), and
                            // kick the next poll so the status
                            // endpoint catches up with reality faster
                            // than the 15s default.
                            window.addEventListener('tsr.saved', () => {
                                if (! this.hasReport) {
                                    this.hasReport = true;
                                    this._needsReloadForLink = true;
                                }
                                this._tick();
                            });
                        },

                        _stop() {
                            if (this._timer) {
                                clearTimeout(this._timer);
                                this._timer = null;
                            }
                        },

                        _tick() {
                            this._stop();
                            this._fetch().finally(() => {
                                // Re-arm only when the tab is still
                                // visible. If the user backgrounded
                                // the tab while the fetch was in
                                // flight, visibilitychange will
                                // re-call _tick() on return.
                                if (! document.hidden) {
                                    this._timer = setTimeout(() => this._tick(), this.intervalMs);
                                }
                            });
                        },

                        async _fetch() {
                            // Bypass HTTP cache so we always see the
                            // latest server state.
                            try {
                                const res = await fetch(this.url, {
                                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                    credentials: 'same-origin',
                                    cache: 'no-store',
                                });
                                if (! res.ok) {
                                    this.connected = false;
                                    return;
                                }
                                const json = await res.json();
                                this.connected = true;
                                this.lastFetchedAt = Date.now();
                                this.lastUpdatedAt = Date.now();

                                const newStatus = json.status_text ?? null;
                                if (newStatus !== this.currentStatus) {
                                    this.currentStatus = newStatus;
                                    this.currentBadge  = this._badgeFor(newStatus);
                                }

                                // has_report boolean from the JSON
                                // endpoint. If it flipped to true and
                                // we don't have a link, we'll show a
                                // "Refresh to view TSR" button.
                                const newHasReport = !! json.has_report;
                                if (newHasReport !== this.hasReport) {
                                    this.hasReport = newHasReport;
                                    if (newHasReport && ! this.tsrShowUrl) {
                                        this._needsReloadForLink = true;
                                    }
                                }
                            } catch (e) {
                                this.connected = false;
                            }
                        },
                    };
                };

                // ----------------------------------------------------------------
                //  tsrSavedToast — tiny Alpine component that listens
                //  for the Livewire CreateServiceReport component's
                //  window-level `tsr.saved` event and shows a DaisyUI
                //  alert for 4 seconds. We keep this as a separate
                //  component (not a flag on ticketStatusPoller) so the
                //  toast can be rendered outside the poller's x-data
                //  scope and so the auto-hide timer doesn't share
                //  state with the polling loop.
                //
                //  Why window-level: Livewire 3's `$this->dispatch()`
                //  on a component fires a CustomEvent on the window
                //  by default, which is exactly what we want — no
                //  manual wiring to a specific DOM node, no Alpine
                //  `$dispatch` adapter required.
                // ----------------------------------------------------------------
                window.tsrSavedToast = function () {
                    return {
                        visible: false,
                        _timer: null,

                        init() {
                            window.addEventListener('tsr.saved', () => this._show());
                        },

                        _show() {
                            this.visible = true;
                            if (this._timer) {
                                clearTimeout(this._timer);
                            }
                            this._timer = setTimeout(() => {
                                this.visible = false;
                                this._timer = null;
                            }, 4000);
                        },
                    };
                };
            </script>
        @endpush
    @endonce

</x-app-layout>
