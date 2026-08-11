<div wire:poll.20s="pollRefresh" wire:poll.keep-alive>
    {{-- pollRefresh runs every 20s (paused while a claim is in
         flight — see Dashboard::pollRefresh). keep-alive keeps
         the timer running when the tab is backgrounded so a
         returning user sees fresh data without manual reload.
         Cost: one Monday round-trip per poll, ~30/min when
         active. --}}
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <p class="text-xs font-semibold tracking-widest uppercase text-base-content/50 mb-1">
                    @if(auth()->user()->role === 'fse')
                        Field service engineer
                    @elseif(auth()->user()->role === 'its')
                        IT specialist
                    @endif
                </p>
                <h2 class="font-semibold text-2xl text-base-content leading-tight">
                    Welcome back, {{ auth()->user()->name }}
                </h2>
                <p class="text-sm text-base-content/60 mt-1">
                    @if(auth()->user()->team) {{ auth()->user()->team }} @endif
                    @if(auth()->user()->region) &middot; {{ auth()->user()->region }} @endif
                </p>
            </div>
            <div class="flex items-center gap-2 self-start sm:self-auto">
                <button type="button"
                        wire:click="refresh"
                        wire:loading.attr="disabled"
                        wire:target="refresh"
                        class="btn btn-ghost btn-sm gap-1"
                        title="Refresh from Monday">
                    <svg wire:loading.remove wire:target="refresh" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    <svg wire:loading wire:target="refresh" class="w-3.5 h-3.5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                </button>
                <span class="badge badge-primary badge-lg gap-1.5 font-medium">
                    <span class="w-1.5 h-1.5 rounded-full bg-primary-content"></span>
                    {{ strtoupper(auth()->user()->role) }}
                </span>
            </div>
        </div>
    </x-slot>

    <div class="py-2">
        <div class="max-w-4xl mx-auto sm:px-4 lg:px-6 space-y-4"
             @toast.window="$wire.dispatch('toast-shown', { id: $event.detail.id })">

            {{-- ───── Toasts (fired via $dispatch('toast', ...)) ─────
                 Single renderable toast region; the Alpine @toast.window
                 handler shows it for ~3.5s. --}}
            <div x-data="{ toasts: [] }"
                 @toast.window="toasts.push({ id: Date.now() + Math.random(), type: $event.detail.type, title: $event.detail.title, body: $event.detail.body }); setTimeout(() => toasts.shift(), 3500);"
                 class="fixed top-4 right-4 z-50 space-y-2 w-80 max-w-[90vw]">
                <template x-for="t in toasts" :key="t.id">
                    <div x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0 translate-y-2"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         x-transition:leave="transition ease-in duration-150"
                         x-transition:leave-start="opacity-100"
                         x-transition:leave-end="opacity-0"
                         :class="t.type === 'success' ? 'bg-success/15 border-success/40 text-success-content' : 'bg-error/15 border-error/40 text-error-content'"
                         class="rounded-xl border shadow-lg px-4 py-3 flex items-start gap-3 backdrop-blur-sm">
                        <div :class="t.type === 'success' ? 'bg-success/20 text-success' : 'bg-error/20 text-error'"
                             class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0">
                            <svg x-show="t.type === 'success'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <svg x-show="t.type !== 'success'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                        </div>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold" :class="t.type === 'success' ? 'text-success' : 'text-error'" x-text="t.title"></p>
                            <p class="text-xs mt-0.5" :class="t.type === 'success' ? 'text-success-content/80' : 'text-error-content/80'" x-text="t.body"></p>
                        </div>
                    </div>
                </template>
            </div>

            @if($flashStatus)
                <x-ui.toast type="success" title="All set!">
                    {{ $flashStatus }}
                </x-ui.toast>
            @endif

            @if(empty(auth()->user()->monday_id))
                <div role="alert" class="alert alert-warning shadow-sm">
                    <svg class="w-5 h-5 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                    <div>
                        <h3 class="font-semibold">Account not yet linked to Monday</h3>
                        <div class="text-xs mt-0.5">
                            Your account is missing a <code class="px-1 py-0.5 rounded bg-warning/20 font-mono text-[11px]">monday_id</code>.
                            Tickets won't show up until an admin sets it.
                        </div>
                    </div>
                </div>
            @endif

            {{-- ───── Stats cards ─────
                 Top: Total (full-width summary)
                 Bottom: 3 status cards in a single row — Open /
                 In progress / Awaiting / Resolved. The
                 `awaiting_parts` bucket was added so the card
                 numbers match the row badges 1:1 (a ticket in
                 "Awaiting Parts" used to be counted in both
                 `open` AND `in_progress`, making the cards look
                 like they double-counted). With this layout each
                 ticket falls into exactly one bucket and the
                 card totals agree with the row list. --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <x-ui.card padding="p-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-base-200 text-base-content/70 flex items-center justify-center">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold text-base-content/60 uppercase tracking-wider">Total</p>
                            <p class="text-2xl font-extrabold text-base-content leading-none mt-0.5">{{ $stats['total'] }}</p>
                        </div>
                    </div>
                </x-ui.card>

                <x-ui.card padding="p-4" tone="accent">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-info/10 text-info flex items-center justify-center">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold text-info uppercase tracking-wider">Open</p>
                            <p class="text-2xl font-extrabold text-base-content leading-none mt-0.5">{{ $stats['open'] }}</p>
                        </div>
                    </div>
                </x-ui.card>

                <x-ui.card padding="p-4" tone="warning">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-warning/10 text-warning flex items-center justify-center">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold text-warning uppercase tracking-wider">In progress</p>
                            <p class="text-2xl font-extrabold text-base-content leading-none mt-0.5">{{ $stats['in_progress'] }}</p>
                        </div>
                    </div>
                </x-ui.card>

                <x-ui.card padding="p-4" tone="neutral">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-neutral/10 text-neutral flex items-center justify-center">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold text-neutral uppercase tracking-wider">Awaiting</p>
                            <p class="text-2xl font-extrabold text-base-content leading-none mt-0.5">{{ $stats['awaiting_parts'] }}</p>
                        </div>
                    </div>
                </x-ui.card>

                <x-ui.card padding="p-4" tone="success">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-secondary/10 text-secondary flex items-center justify-center">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold text-secondary uppercase tracking-wider">Resolved</p>
                            <p class="text-2xl font-extrabold text-base-content leading-none mt-0.5">{{ $stats['resolved'] }}</p>
                        </div>
                    </div>
                </x-ui.card>
            </div>

            {{-- ───── Sync banners (TSP-only) ─────
                 Two banners can show at the same time:
                 1. "Queued"  (yellow)  — pending+syncing rows; the
                    auto-drainer is on it. No user action needed.
                 2. "Needs attention" (red) — error rows. Each row
                    shows WHY it's stuck and has Retry / Discard
                    actions. The Drainer left these alone because
                    they failed once; manual retry may succeed if
                    the underlying cause (e.g. monday ticket moved
                    to trash) has been resolved.
                 If a row is permanently unrecoverable (e.g. the
                 source ticket is in monday trash), the user clicks
                 Discard to remove it from the count. The row stays
                 in the DB for audit but is excluded from future
                 drainer runs. --}}
            @if($stats['pending_count'] > 0)
                <div class="flex items-center gap-3 px-4 py-3 rounded-xl bg-warning/10 border border-warning/30"
                     data-testid="sync-queued-banner">
                    <div class="w-9 h-9 rounded-full bg-warning/20 text-warning flex items-center justify-center shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold text-base-content">
                            {{ $stats['pending_count'] }} service report{{ $stats['pending_count'] === 1 ? '' : 's' }} queued for sync
                        </p>
                        <p class="text-[11px] text-base-content/70">Mirroring to Monday.com — these go through automatically.</p>
                    </div>
                </div>
            @endif

            @if($stats['error_count'] > 0)
                <div class="px-4 py-3 rounded-xl bg-error/10 border border-error/30"
                     data-testid="sync-needs-attention-banner">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-full bg-error/20 text-error flex items-center justify-center shrink-0">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-base-content">
                                {{ $stats['error_count'] }} service report{{ $stats['error_count'] === 1 ? '' : 's' }} need{{ $stats['error_count'] === 1 ? 's' : '' }} attention
                            </p>
                            <p class="text-[11px] text-base-content/70">
                                The drainer couldn't mirror {{ $stats['error_count'] === 1 ? 'this' : 'these' }} to Monday.com.
                                Retry, or discard if the source ticket is gone.
                            </p>
                        </div>
                        <button type="button"
                                wire:click="retryAll"
                                wire:loading.attr="disabled"
                                wire:target="retryAll"
                                class="btn btn-sm btn-ghost text-error hover:bg-error/20 shrink-0">
                            <span wire:loading.remove wire:target="retryAll">Retry all</span>
                            <span wire:loading wire:target="retryAll" class="loading loading-spinner loading-xs"></span>
                        </button>
                    </div>

                    @if(!empty($errorReports))
                        <ul class="mt-3 space-y-2">
                            @foreach($errorReports as $r)
                                <li class="flex items-start gap-3 px-3 py-2.5 rounded-lg bg-base-100 border border-base-300/60"
                                    data-testid="error-row-{{ $r['id'] }}">
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            @if(!empty($r['ticket']))
                                                <span class="text-[11px] font-mono text-base-content/60">Ticket #{{ $r['ticket'] }}</span>
                                            @endif
                                            <span class="text-[11px] text-base-content/40">·</span>
                                            <span class="text-[11px] text-base-content/50">TSR #{{ $r['id'] }}</span>
                                            @if(!empty($r['created_at']))
                                                <span class="text-[11px] text-base-content/40">·</span>
                                                <span class="text-[11px] text-base-content/50">{{ \Carbon\Carbon::parse($r['created_at'])->diffForHumans() }}</span>
                                            @endif
                                        </div>
                                        @if(!empty($r['error']))
                                            <p class="text-[11px] text-error/90 mt-1 break-words leading-snug" title="{{ $r['error'] }}">
                                                {{ \Illuminate\Support\Str::limit($r['error'], 160) }}
                                            </p>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-1.5 shrink-0">
                                        <button type="button"
                                                wire:click="retrySync({{ $r['id'] }})"
                                                wire:loading.attr="disabled"
                                                wire:target="retrySync({{ $r['id'] }})"
                                                class="btn btn-xs btn-ghost text-base-content/70 hover:bg-base-200">
                                            <span wire:loading.remove wire:target="retrySync({{ $r['id'] }})">Retry</span>
                                            <span wire:loading wire:target="retrySync({{ $r['id'] }})" class="loading loading-spinner loading-xs"></span>
                                        </button>
                                        <button type="button"
                                                wire:click="discardReport({{ $r['id'] }})"
                                                wire:confirm="Discard TSR #{{ $r['id'] }}? The row stays in the database for audit but will be removed from this list and the drainer."
                                                class="btn btn-xs btn-ghost text-base-content/50 hover:bg-base-200">
                                            Discard
                                        </button>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                        @if($stats['error_count'] > count($errorReports))
                            <p class="text-[11px] text-base-content/50 mt-2 px-1">
                                Showing the {{ count($errorReports) }} most recent. {{ $stats['error_count'] - count($errorReports) }} more — use Retry all to clear.
                            </p>
                        @endif
                    @endif
                </div>
            @endif

            {{-- ───── Filter bar ─────
                 Applies to both Available and My tickets via the
                 filteredAvailable / filteredMyTickets computed
                 properties. Every wire:model.live triggers a
                 server round-trip; the search input is debounced
                 by 250ms to avoid excessive requests. --}}
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-2.5 rounded-xl bg-base-200/30 border border-base-300/50">
                {{-- Search --}}
                <div class="relative flex-1 min-w-[160px] max-w-xs">
                    <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-base-content/40 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input type="search" wire:model.live.debounce.250ms="filters.query" placeholder="Search tickets…"
                           class="input input-xs input-bordered w-full pl-7 h-8 text-sm">
                </div>

                {{-- Status multi-select --}}
                <div class="dropdown dropdown-end">
                    <button class="btn btn-xs btn-ghost gap-1" tabindex="0">
                        <span>Status</span>
                        @if(!empty($filters['status']))
                            <span class="badge badge-xs badge-primary">+{{ count($filters['status']) }}</span>
                        @endif
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <ul class="dropdown-content menu menu-xs p-1.5 shadow-lg bg-base-100 rounded-box w-40 z-20 border border-base-300/60">
                        <li><a wire:click="toggleStatusFilter('open')" class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-info"></span>Open</a></li>
                        <li><a wire:click="toggleStatusFilter('in_progress')" class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-warning"></span>In progress</a></li>
                        <li><a wire:click="toggleStatusFilter('awaiting')" class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-accent"></span>Awaiting</a></li>
                        <li><a wire:click="toggleStatusFilter('resolved')" class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-success"></span>Resolved</a></li>
                    </ul>
                </div>

                {{-- Sort --}}
                <div class="join">
                    <button type="button"
                            wire:click="$set('filters.sort', 'newest')"
                            @class(['btn btn-xs join-item gap-1', 'btn-active' => $filters['sort'] === 'newest'])>
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9m-9 4h6m4 0l4-4m0 0l4 4m-4-4v12"/></svg>
                        Newest
                    </button>
                    <button type="button"
                            wire:click="$set('filters.sort', 'oldest')"
                            @class(['btn btn-xs join-item gap-1', 'btn-active' => $filters['sort'] === 'oldest'])>
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9m-9 4h9m4-4v12m0 0l-4-4m4 4l4-4"/></svg>
                        Oldest
                    </button>
                </div>

                {{-- Clear all --}}
                <button wire:click="resetFilters"
                        @disabled(empty($filters['query']) && empty($filters['status']))
                        class="btn btn-xs btn-ghost text-base-content/50 gap-1">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    Clear
                </button>
            </div>

            {{-- ── Active filter badges ── --}}
            @if(!empty($filters['status']) || !empty($filters['query']))
                <div class="flex flex-wrap items-center gap-1.5">
                    @if(!empty($filters['query']))
                        <span class="badge badge-sm badge-ghost gap-1">
                            “{{ $filters['query'] }}”
                            <button wire:click="$set('filters.query', '')" class="hover:text-base-content/70">&times;</button>
                        </span>
                    @endif
                    @foreach($filters['status'] as $s)
                        @php
                            $badgeClass = match($s) {
                                'open' => 'badge-info',
                                'in_progress' => 'badge-warning',
                                'awaiting' => 'badge-accent',
                                default => 'badge-success',
                            };
                        @endphp
                        <button wire:click="toggleStatusFilter('{{ $s }}')"
                                class="badge badge-sm {{ $badgeClass }} gap-1 cursor-pointer hover:opacity-70 transition">
                            <span>{{ str_replace('_', ' ', $s) }}</span>
                            <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    @endforeach
                </div>
            @endif

            {{-- ───── Region warning (when no region resolvable) ─────
                 Shown when the TSP has no region, branch, or address
                 on file — explains the empty "Available" list instead
                 of silently showing nothing. User complained on
                 2026-08-07 that Remial Busa had no claimable tickets
                 despite an open NCR ticket; root cause was the
                 account having region/branch/address all NULL. --}}
            @if($regionWarning)
                <div class="rounded-xl border border-warning/40 bg-warning/10 px-4 py-3 flex items-start gap-3"
                     role="alert"
                     data-testid="region-warning">
                    <span aria-hidden="true" class="w-7 h-7 rounded-lg bg-warning/20 text-warning flex items-center justify-center shrink-0">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-warning-content">No region set on your account</p>
                        <p class="text-xs mt-0.5 text-base-content/70">{{ $regionWarning }}</p>
                    </div>
                </div>
            @endif

            {{-- ───── Available tickets in your region ─────
                 Click Claim to review ticket details in a modal,
                 then confirm to claim it. The ticket disappears
                 from the regional pool instantly. --}}
            @if(!empty($availableTickets))
                <x-ui.card
                    title="Available tickets in your region"
                    subtitle="Click Claim to review details before accepting a ticket into your queue."
                    padding="p-0"
                >
                    <x-slot:icon>
                        <span aria-hidden="true" class="w-7 h-7 rounded-lg bg-warning/10 text-warning flex items-center justify-center shrink-0">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                        </span>
                    </x-slot:icon>

                    @if(empty($this->filteredAvailable))
                        <div class="p-2">
                            <x-ui.empty-state
                                icon="🔍"
                                title="No matching tickets"
                                body="Try adjusting your search or filters."
                            />
                        </div>
                    @else
                    <ul role="list" class="divide-y divide-base-300/70">
                        @foreach($this->filteredAvailable as $t)
                            @php
                                $statusLower = strtolower((string) $t['status_text']);
                                $statusConfig = match(true) {
                                    str_contains($statusLower, 'new') || str_contains($statusLower, 'open')
                                        => ['class' => 'badge-info',    'dot' => 'bg-info'],
                                    str_contains($statusLower, 'progress')
                                        => ['class' => 'badge-warning', 'dot' => 'bg-warning'],
                                    default
                                        => ['class' => 'badge-ghost',   'dot' => 'bg-base-content/40'],
                                };
                                $brand = $t['item']['column_values']['text_mm5apcrc']['text'] ?? null;
                                $model = $t['item']['column_values']['text_mm5am2kf']['text'] ?? null;
                                $accountName = $t['account_name'] ?? null;
                            @endphp
                            <li wire:key="available-{{ $t['id'] }}">
                                <div class="flex items-center gap-3 px-4 py-3.5 hover:bg-base-200/60 transition group">
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 mb-1">
                                            <span class="text-[11px] font-mono text-base-content/50">#{{ $t['id'] }}</span>
                                            <span class="badge {{ $statusConfig['class'] }} badge-sm gap-1 font-medium">
                                                <span class="w-1.5 h-1.5 rounded-full {{ $statusConfig['dot'] }}"></span>
                                                {{ $t['status_text'] ?? '—' }}
                                            </span>
                                            @if(!empty($t['customer_region']))
                                                <span class="badge badge-outline badge-sm text-[10px]">{{ $t['customer_region'] }}</span>
                                            @endif
                                        </div>
                                        <h3 class="text-sm font-semibold text-base-content truncate">
                                            {{ $t['subject_text'] ?: $t['name'] }}
                                        </h3>
                                        <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[11px] text-base-content/60 mt-1">
                                            @if($accountName)
                                                <span class="inline-flex items-center gap-1">
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                                                    {{ $accountName }}
                                                </span>
                                            @endif
                                            @if($brand || $model)
                                                <span class="inline-flex items-center gap-1">
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
                                                    {{ trim(($brand ?? '') . ' ' . (($brand && $model) ? '· ' : '') . ($model ?? '')) }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>

                                    {{-- Single-click Claim button. Opens the
                                         claim confirmation modal so the TSP
                                         can review ticket details before
                                         claiming. --}}
                                    <button type="button"
                                            wire:click="showClaimModal('{{ $t['id'] }}')"
                                            class="btn btn-sm btn-primary gap-1 flex-shrink-0">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                                        <span>Claim</span>
                                    </button>

                                    {{-- Non-JS fallback: standard form POST. Only
                                         rendered when JS is disabled. --}}
                                    <noscript>
                                        <form method="POST" action="{{ route('tsp.tickets.claim', $t['id']) }}" class="ml-1">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-primary">Claim</button>
                                        </form>
                                    </noscript>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    @endif {{-- /empty filteredAvailable --}}
                </x-ui.card>
            @endif

            {{-- ───── Claim confirmation modal ─────
                 Shown when the TSP clicks "Claim" on an available
                 ticket. Displays full ticket details before the
                 TSP confirms the claim. Uses Alpine for keyboard
                 dismiss and a fixed overlay. --}}
            @if($claimingTicket)
                @php
                    $ctBrand = $claimingTicket['item']['column_values']['text_mm5apcrc']['text'] ?? null;
                    $ctModel = $claimingTicket['item']['column_values']['text_mm5am2kf']['text'] ?? null;
                    $ctDesc  = $claimingTicket['item']['column_values']['long_text7']['text'] ?? null;
                @endphp
                <div class="fixed inset-0 z-50 overflow-y-auto"
                     x-data
                     x-init="document.body.classList.add('overflow-y-hidden')"
                     x-on:keydown.escape.window="$wire.cancelClaim()"
                     x-on:close-claim-modal.window="document.body.classList.remove('overflow-y-hidden')">
                    {{-- Backdrop --}}
                    <div class="fixed inset-0 bg-gray-500/75 transition-opacity"
                         wire:click="cancelClaim"></div>

                    {{-- Panel --}}
                    <div class="min-h-full flex items-center justify-center p-4">
                        <div class="relative bg-white rounded-lg shadow-xl max-w-lg w-full">
                            {{-- Header --}}
                            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200">
                                <div>
                                    <h2 class="text-lg font-semibold text-gray-900">Claim ticket</h2>
                                    <p class="text-sm text-gray-500 mt-0.5">Review the ticket details before claiming</p>
                                </div>
                                <button type="button"
                                        wire:click="cancelClaim"
                                        class="text-gray-400 hover:text-gray-600 transition">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>

                            {{-- Body --}}
                            <div class="px-6 py-5 space-y-5">
                                {{-- Ticket ID badge --}}
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-mono font-semibold text-gray-400 uppercase">Ticket</span>
                                    <span class="text-sm font-mono font-bold text-gray-900">#{{ $claimingTicket['id'] }}</span>
                                </div>

                                {{-- Account name --}}
                                @if(!empty($claimingTicket['account_name']))
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Account</label>
                                        <div class="flex items-center gap-2 text-sm font-medium text-gray-900">
                                            <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                                            {{ $claimingTicket['account_name'] }}
                                        </div>
                                    </div>
                                @endif

                                {{-- Brand / Model --}}
                                @if($ctBrand || $ctModel)
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Machine</label>
                                        <div class="flex items-center gap-2 text-sm font-medium text-gray-900">
                                            <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
                                            {{ trim(($ctBrand ?? '') . ' ' . (($ctBrand && $ctModel) ? '· ' : '') . ($ctModel ?? '')) }}
                                        </div>
                                    </div>
                                @endif

                                {{-- Subject --}}
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Subject</label>
                                    <p class="text-sm font-medium text-gray-900 break-words">{{ $claimingTicket['subject_text'] ?: $claimingTicket['name'] }}</p>
                                </div>

                                {{-- Description --}}
                                @if($ctDesc)
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Description</label>
                                        <div class="text-sm text-gray-700 whitespace-pre-wrap bg-gray-50 rounded-lg p-3 border border-gray-200 max-h-40 overflow-y-auto leading-relaxed">{{ $ctDesc }}</div>
                                    </div>
                                @endif
                            </div>

                            {{-- Footer --}}
                            <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-gray-200 bg-gray-50 rounded-b-lg">
                                <button type="button"
                                        wire:click="cancelClaim"
                                        class="btn btn-sm btn-ghost">Cancel</button>
                                <button type="button"
                                        wire:click="confirmClaim"
                                        wire:loading.attr="disabled"
                                        class="btn btn-sm btn-primary gap-1.5">
                                    <span wire:loading.remove wire:target="confirmClaim">
                                        <svg class="w-3.5 h-3.5 inline-block -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        Confirm claim
                                    </span>
                                    <span wire:loading wire:target="confirmClaim" class="loading loading-spinner loading-xs"></span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            {{-- ───── My Tickets card ─────
                 Now annotates the assigned TSP name when the ticket
                 has a People-column value (which is always the current
                 TSP after claim, but a co-owned queue or a future
                 re-assignment will show the actual name). The badge
                 is hidden when the only assignee is the current viewer
                 to avoid visual noise — "you" already know it's you. --}}
            <x-ui.card
                title="My tickets"
                subtitle="From Monday.com · cached 30s"
                padding="p-0"
            >
                <x-slot:icon>
                    <span aria-hidden="true" class="w-7 h-7 rounded-lg bg-primary/10 text-primary flex items-center justify-center shrink-0">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    </span>
                </x-slot:icon>

                @php $hasMyTickets = !empty($myTickets); @endphp
                @if(!$hasMyTickets)
                    <div class="p-2">
                        <x-ui.empty-state
                            icon="📋"
                            title="No tickets claimed yet"
                            body="Check the available tickets pool above to claim one, or wait for new tickets to come in from your region."
                        />
                    </div>
                @elseif($hasMyTickets && empty($this->filteredMyTickets))
                    <div class="p-2">
                        <x-ui.empty-state
                            icon="🔍"
                            title="No matching tickets"
                            body="Try adjusting your search or filters."
                        />
                    </div>
                @else
                    <ul role="list" class="divide-y divide-base-300/70">
                        @foreach($this->filteredMyTickets as $t)
                            @php
                                $statusLower = strtolower((string) $t['status_text']);
                                $statusConfig = match(true) {
                                    str_contains($statusLower, 'new') || str_contains($statusLower, 'open')
                                        => ['class' => 'badge-info',    'dot' => 'bg-info'],
                                    str_contains($statusLower, 'progress')
                                        => ['class' => 'badge-warning', 'dot' => 'bg-warning'],
                                    str_contains($statusLower, 'awaiting')
                                        => ['class' => 'badge-accent',  'dot' => 'bg-accent'],
                                    str_contains($statusLower, 'resolved') || str_contains($statusLower, 'closed') || str_contains($statusLower, 'done') || str_contains($statusLower, 'complete')
                                        => ['class' => 'badge-success', 'dot' => 'bg-success'],
                                    default
                                        => ['class' => 'badge-ghost',   'dot' => 'bg-base-content/40'],
                                };

                                $brand = $t['item']['column_values']['text_mm5apcrc']['text'] ?? null;
                                $model = $t['item']['column_values']['text_mm5am2kf']['text'] ?? null;
                                $accountName = $t['account_name'] ?? null;

                                // Resolve assigned TSP name(s). When the
                                // current viewer is the only assignee, hide
                                // the badge — the row's position in the list
                                // already says "mine". When a different TSP
                                // (e.g. ITS coverage, co-claim) is on the
                                // ticket, show their name.
                                $currentId = (string) (auth()->user()->monday_id ?? '');
                                $tspIds = array_map('strval', $t['tsp_person_ids'] ?? []);
                                $otherTsps = array_values(array_filter(
                                    $tspIds,
                                    static fn ($id) => $id !== '' && $id !== $currentId,
                                ));
                                $showAssignedBadge = ! empty($otherTsps);
                                $assignedNames = array_values(array_filter(
                                    array_map(
                                        static fn ($id) => $this->tspNameMap[$id] ?? null,
                                        $otherTsps,
                                    ),
                                ));
                            @endphp
                            <li wire:key="mine-{{ $t['id'] }}">
                                <a href="{{ route('tsp.tickets.show', $t['id']) }}"
                                   class="block px-4 py-3.5 hover:bg-base-200/60 transition group">
                                    <div class="flex items-center gap-3">
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2 mb-1 flex-wrap">
                                                <span class="text-[11px] font-mono text-base-content/50">#{{ $t['id'] }}</span>
                                                <span class="badge {{ $statusConfig['class'] }} badge-sm gap-1 font-medium">
                                                    <span class="w-1.5 h-1.5 rounded-full {{ $statusConfig['dot'] }}"></span>
                                                    {{ $t['status_text'] ?? '—' }}
                                                </span>
                                                @if($showAssignedBadge)
                                                    <span class="badge badge-outline badge-sm gap-1 text-[10px]" title="Also assigned to {{ implode(', ', $assignedNames) }}">
                                                        <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                                        {{ implode(', ', $assignedNames) }}
                                                    </span>
                                                @endif
                                            </div>
                                            <h3 class="text-sm font-semibold text-base-content truncate group-hover:text-primary transition">
                                                {{ $t['subject_text'] ?: $t['name'] }}
                                            </h3>
                                            <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[11px] text-base-content/60 mt-1">
                                                @if($accountName)
                                                    <span class="inline-flex items-center gap-1">
                                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                                                        {{ $accountName }}
                                                    </span>
                                                @endif
                                                @if($brand || $model)
                                                    <span class="inline-flex items-center gap-1">
                                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
                                                        {{ trim(($brand ?? '') . ' ' . (($brand && $model) ? '· ' : '') . ($model ?? '')) }}
                                                    </span>
                                                @endif
                                                @if(!empty($t['updates_count']))
                                                    <span class="inline-flex items-center gap-1">
                                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                                                        {{ $t['updates_count'] }} update{{ $t['updates_count'] === 1 ? '' : 's' }}
                                                    </span>
                                                @endif
                                            </div>
                                        </div>
                                        <svg class="w-4 h-4 text-base-content/40 group-hover:text-primary group-hover:translate-x-0.5 transition flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                        </svg>
                                    </div>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif {{-- /empty myTickets / filteredMyTickets --}}
            </x-ui.card>
        </div>
    </div>
</div>

{{-- ───── Realtime Pusher subscription ─────
     Boot the region-scoped subscription so the dashboard refreshes
     on `ticket.created` / `ticket.claimed` events without waiting
     for the 20s poll. The `regionCode` is resolved server-side via
     the same RegionResolver the broadcast uses, so the channel
     authorization on routes/channels.php is consistent end-to-end.

     `initRealtimeDashboard` is exported by
     `resources/js/realtime-dashboard.js` (bundled into the main
     `app.js` entry). The `initialized` flag inside the module
     keeps the subscription single-subscription per page even if
     Livewire re-runs this block on a hot-reload. --}}
@once
    @push('scripts')
        <script>
            (function () {
                var regionCode = @json(
                    \App\Support\RegionResolver::resolveForCustomer(auth()->user())
                );
                // The module is loaded as part of the main app.js
                // bundle; we just call its export here.
                var mod = window.__realtimeDashboard
                    || (window.__realtimeDashboard = {
                        // Stub in case the bundle hasn't loaded yet
                        // (e.g. when the test scripts snapshot the
                        // page). The real implementation is in
                        // resources/js/realtime-dashboard.js.
                        init: function () {},
                    });
                if (typeof mod.init === 'function') {
                    mod.init({ regionCode: regionCode });
                }
            })();
        </script>
    @endpush
@endonce
