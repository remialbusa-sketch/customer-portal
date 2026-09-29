{{-- =============================================================
     TSP dashboard — same Figma language as the customer page
     ("Customer portal - All-in-One" frame): welcome header,
     5 stat cards, table panels with pills, right column.
     Primary actions stay portal navy. All Livewire actions,
     testids, and poll/realtime wiring are preserved.
     ============================================================= --}}
<div wire:poll.20s="pollRefresh" wire:poll.keep-alive
     x-on:close-transfer-modal.window="document.body.classList.remove('overflow-y-hidden')">
    {{-- pollRefresh runs every 20s (paused while a claim is in
         flight — see Dashboard::pollRefresh). keep-alive keeps
         the timer running when the tab is backgrounded. --}}
    @php
        $roleLabel = match(auth()->user()->role) {
            'fse' => 'Field service engineer',
            'its' => 'IT specialist',
            'manager' => 'Manager',
            default => ucfirst((string) auth()->user()->role),
        };
        // Figma pill language (server-side, mirrors the customer
        // ticketFilter.statusPill bucket map).
        $statusPill = function (string $statusText): array {
            $s = strtolower($statusText);
            if (str_contains($s, 'resolved') || str_contains($s, 'closed') || str_contains($s, 'done') || str_contains($s, 'complete'))
                return ['bg' => 'bg-[#E3F7F3]', 'text' => 'text-[#17847A]'];
            if (str_contains($s, 'progress'))
                return ['bg' => 'bg-[#FEF3C7]', 'text' => 'text-[#B45309]'];
            if (str_contains($s, 'awaiting'))
                return ['bg' => 'bg-[#FDF2F8]', 'text' => 'text-[#C1447E]'];
            if (str_contains($s, 'new') || str_contains($s, 'open'))
                return ['bg' => 'bg-[#EBF2FD]', 'text' => 'text-[#3977E8]'];
            return ['bg' => 'bg-base-200', 'text' => 'text-base-content/60'];
        };
        $priorityPill = function (?string $priority): ?array {
            $s = strtolower(trim((string) $priority));
            if ($s === '' || $s === '—' || $s === '-') return null;
            if (str_contains($s, 'critical')) return ['bg' => 'bg-error/10', 'text' => 'text-error'];
            if (str_contains($s, 'high'))     return ['bg' => 'bg-[#FFF4E5]', 'text' => 'text-[#B45309]'];
            if (str_contains($s, 'medium'))   return ['bg' => 'bg-base-200', 'text' => 'text-base-content/60'];
            if (str_contains($s, 'low'))      return ['bg' => 'bg-base-200/60', 'text' => 'text-base-content/50'];
            return ['bg' => 'bg-base-200', 'text' => 'text-base-content/60'];
        };
    @endphp

    <x-slot name="header">
        <div class="animate-enter flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div>
                <p class="text-[11px] font-medium uppercase tracking-wider text-base-content/50">
                    {{ $roleLabel }}
                </p>
                <h2 class="font-bold text-3xl text-base-content leading-tight mt-1">
                    Welcome back, {{ auth()->user()->name }}!
                </h2>
                <p class="text-sm text-base-content/60 mt-1.5">
                    @if(auth()->user()->team) {{ auth()->user()->team }} @endif
                    @if(auth()->user()->region) &middot; {{ auth()->user()->region }} @endif
                    @if(!auth()->user()->team && !auth()->user()->region) Your ticket queue and regional pool. @endif
                </p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <button type="button"
                        wire:click="refresh"
                        wire:loading.attr="disabled"
                        wire:target="refresh"
                        class="btn btn-ghost btn-sm gap-1.5 h-11 px-4"
                        title="Refresh from Monday">
                    <svg wire:loading.remove wire:target="refresh" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    <svg wire:loading wire:target="refresh" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    Refresh
                </button>
                <span class="badge badge-primary badge-lg gap-1.5 font-medium">
                    <span class="w-1.5 h-1.5 rounded-full bg-primary-content"></span>
                    {{ strtoupper(auth()->user()->role) }}
                </span>
            </div>
        </div>
    </x-slot>

    <div class="py-2">
        <div class="max-w-6xl mx-auto sm:px-4 lg:px-6 space-y-7"
             @toast.window="$wire.dispatch('toast-shown', { id: $event.detail.id })">

            {{-- ── Toasts ── --}}
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
                <div role="alert" class="rounded-2xl border border-warning/40 bg-warning/10 px-4 py-3 flex items-start gap-3">
                    <span aria-hidden="true" class="w-7 h-7 rounded-lg bg-warning/20 text-warning flex items-center justify-center shrink-0">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                    </span>
                    <div>
                        <h3 class="text-sm font-semibold text-base-content">Account not yet linked to Monday</h3>
                        <div class="text-xs mt-0.5 text-base-content/70">
                            Your account is missing a <code class="px-1 py-0.5 rounded bg-warning/20 font-mono text-[11px]">monday_id</code>.
                            Tickets won't show up until an admin sets it.
                        </div>
                    </div>
                </div>
            @endif

            {{-- ── Status summary (5 stat cards, Figma language) ── --}}
            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-4">
                @foreach ([
                    ['label' => 'Total',       'value' => $stats['total'],          'hint' => 'Tickets in your queue', 'tone' => 'text-base-content'],
                    ['label' => 'Open',        'value' => $stats['open'],           'hint' => 'Awaiting response',     'tone' => 'text-info'],
                    ['label' => 'In Progress', 'value' => $stats['in_progress'],    'hint' => 'Being worked on',       'tone' => 'text-warning'],
                    ['label' => 'Awaiting',    'value' => $stats['awaiting_parts'], 'hint' => 'Waiting for parts',     'tone' => 'text-accent'],
                    ['label' => 'Resolved',    'value' => $stats['resolved'],       'hint' => 'Closed tickets',        'tone' => 'text-success'],
                ] as $card)
                    <div class="animate-enter rounded-2xl bg-base-100 border border-base-300/70 shadow-sm px-5 py-4" style="animation-delay: {{ 60 + $loop->index * 60 }}ms;">
                        <div class="flex items-center justify-between">
                            <p class="text-[10px] font-medium uppercase tracking-wider text-base-content/50">{{ $card['label'] }}</p>
                            <span class="text-base-content/20 text-sm" aria-hidden="true">›</span>
                        </div>
                        <p class="text-[34px] leading-none font-bold {{ $card['tone'] }} mt-2">{{ $card['value'] }}</p>
                        <p class="text-[11px] text-base-content/50 mt-1.5">{{ $card['hint'] }}</p>
                    </div>
                @endforeach
            </div>

            {{-- ── Incoming transfer requests (conditional) ── --}}
            @if(!empty($incomingTransfers))
                <section class="rounded-2xl bg-base-100 border border-base-300/70 shadow-sm p-6">
                    <div class="flex items-center gap-2.5">
                        <span aria-hidden="true" class="w-7 h-7 rounded-lg bg-accent/10 text-accent flex items-center justify-center shrink-0">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                        </span>
                        <div>
                            <h3 class="text-[15px] font-semibold text-base-content">Incoming transfer requests</h3>
                            <p class="text-xs text-base-content/50">Confirm to take over the assignment</p>
                        </div>
                    </div>
                    <ul role="list" class="divide-y divide-base-300/70 mt-2">
                        @foreach($incomingTransfers as $tr)
                            <li wire:key="transfer-{{ $tr['id'] }}" class="py-3.5">
                                <div class="flex items-center gap-3">
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 mb-1 flex-wrap">
                                            <span class="text-[11px] font-mono text-base-content/50">#{{ $tr['monday_ticket_id'] }}</span>
                                            <span class="inline-block rounded-md px-2 py-[3px] text-[11px] font-semibold bg-[#FDF2F8] text-[#C1447E]">
                                                Awaiting your confirmation
                                            </span>
                                        </div>
                                        <p class="text-sm font-semibold text-base-content truncate">{{ $tr['ticket_label'] }}</p>
                                        <p class="text-[11px] text-base-content/60 mt-0.5">
                                            {{ $tr['from_name'] }}<span class="text-base-content/40">@if(!empty($tr['from_region'])) · {{ $tr['from_region'] }} @endif</span>
                                            <span class="text-base-content/40">· {{ $tr['created_at'] }}</span>
                                        </p>
                                    </div>
                                    <div class="flex items-center gap-1.5 shrink-0">
                                        <button type="button"
                                                wire:click="acceptTransfer({{ $tr['id'] }})"
                                                wire:loading.attr="disabled"
                                                wire:target="acceptTransfer({{ $tr['id'] }})"
                                                wire:confirm="Accept transfer of ticket #{{ $tr['monday_ticket_id'] }}? It will be reassigned to you on Monday.com — the current TSP will no longer hold it."
                                                class="btn btn-sm btn-primary gap-1">
                                            <span wire:loading.remove wire:target="acceptTransfer({{ $tr['id'] }})">Accept</span>
                                            <span wire:loading wire:target="acceptTransfer({{ $tr['id'] }})" class="loading loading-spinner loading-xs"></span>
                                        </button>
                                        <button type="button"
                                                wire:click="declineTransfer({{ $tr['id'] }})"
                                                wire:confirm="Decline this transfer request? The ticket stays with the current TSP."
                                                class="btn btn-sm btn-ghost text-base-content/70 hover:bg-base-200">
                                            Decline
                                        </button>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- ── Filters toolbar (Figra filter-bar language) ── --}}
            <div class="flex flex-wrap items-center gap-2.5">
                <div class="relative flex-1 min-w-[160px] max-w-xs">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-base-content/40 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input type="search" wire:model.live.debounce.250ms="filters.query" placeholder="Search tickets…"
                           class="input input-bordered input-sm w-full pl-8 h-9 text-[13px] bg-base-100">
                </div>

                <div class="dropdown dropdown-end">
                    <button class="btn btn-sm h-9 px-3 gap-1.5 bg-base-100 border-base-300 font-medium text-xs" tabindex="0">
                        <span>Status</span>
                        @if(!empty($filters['status']))
                            <span class="badge badge-xs badge-primary">+{{ count($filters['status']) }}</span>
                        @endif
                        <svg class="w-3 h-3 text-base-content/50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <ul class="dropdown-content menu menu-xs p-1.5 shadow-lg bg-base-100 rounded-box w-40 z-20 border border-base-300/60">
                        <li><button type="button" wire:click="toggleStatusFilter('open')" class="w-full text-left">Open</button></li>
                        <li><button type="button" wire:click="toggleStatusFilter('in_progress')" class="w-full text-left">In progress</button></li>
                        <li><button type="button" wire:click="toggleStatusFilter('awaiting')" class="w-full text-left">Awaiting</button></li>
                        <li><button type="button" wire:click="toggleStatusFilter('resolved')" class="w-full text-left">Resolved</button></li>
                    </ul>
                </div>

                <div class="join">
                    <button type="button"
                            wire:click="$set('filters.sort', 'newest')"
                            @class(['btn btn-xs join-item h-9', 'btn-active' => $filters['sort'] === 'newest'])
                            title="Newest first">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9m-9 4h6m4 0l4-4m0 0l4 4m-4-4v12"/></svg>
                    </button>
                    <button type="button"
                            wire:click="$set('filters.sort', 'oldest')"
                            @class(['btn btn-xs join-item h-9', 'btn-active' => $filters['sort'] === 'oldest'])
                            title="Oldest first">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9m-9 4h9m4-4v12m0 0l-4-4m4 4l4-4"/></svg>
                    </button>
                </div>

                <button wire:click="resetFilters"
                        @disabled(empty($filters['query']) && empty($filters['status']))
                        class="btn btn-xs btn-ghost text-base-content/50 gap-1 h-9">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    Clear
                </button>
            </div>

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

            {{-- ── Region warning ── --}}
            @if($regionWarning)
                <div class="rounded-2xl border border-warning/40 bg-warning/10 px-4 py-3 flex items-start gap-3"
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

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-5 items-start">
                <div class="xl:col-span-2 space-y-5 min-w-0">

                    {{-- ── Available tickets in your region ── --}}
                    @if(!empty($availableTickets))
                        <section class="rounded-2xl bg-base-100 border border-base-300/70 shadow-sm p-6">
                            <h3 class="text-[15px] font-semibold text-base-content">Available tickets in your region</h3>
                            <p class="text-xs text-base-content/50 mt-0.5">Click Claim to review details before accepting a ticket into your queue.</p>

                            @if(empty($this->filteredAvailable))
                                <div class="pt-2">
                                    <x-ui.empty-state
                                        icon="🔍"
                                        title="No matching tickets"
                                        body="Try adjusting your search or filters."
                                    />
                                </div>
                            @else
                                <div class="hidden sm:grid grid-cols-[140px_1fr_100px_80px] gap-2 items-center pt-4 pb-2.5 border-b border-base-300/70 text-[11px] font-semibold uppercase tracking-wider text-base-content/50">
                                    <span>Ticket ID</span>
                                    <span>Subject</span>
                                    <span>Status</span>
                                    <span class="text-right">Action</span>
                                </div>
                                <ul role="list" class="divide-y divide-base-300/70">
                                    @foreach($this->filteredAvailable as $t)
                                        @php
                                            $aPill = $statusPill((string) ($t['status_text'] ?? ''));
                                            $aPrio = $priorityPill($t['priority_text'] ?? null);
                                            $aBrand = $t['item']['column_values']['text_mm5apcrc']['text'] ?? null;
                                            $aModel = $t['item']['column_values']['text_mm5am2kf']['text'] ?? null;
                                            $aAccount = $t['account_name'] ?? null;
                                        @endphp
                                        <li wire:key="available-{{ $t['id'] }}">
                                            <div class="grid grid-cols-[1fr_auto] sm:grid-cols-[140px_1fr_100px_80px] gap-2 items-center py-3.5">
                                                <span class="text-[13px] font-semibold text-primary">{{ $t['name'] ?: ('#' . $t['id']) }}</span>
                                                <span class="min-w-0">
                                                    <span class="block text-[13px] text-base-content truncate">{{ $t['subject_text'] ?: $t['name'] }}</span>
                                                    <span class="flex flex-wrap items-center gap-x-2 text-[11px] text-base-content/50 mt-0.5">
                                                        @if(!empty($t['customer_region']))
                                                            <span class="badge badge-outline badge-sm text-[10px]">{{ $t['customer_region'] }}</span>
                                                        @endif
                                                        @if($aAccount)<span class="truncate">{{ $aAccount }}</span>@endif
                                                        @if($aBrand || $aModel)<span class="truncate">{{ trim(($aBrand ?? '') . ' ' . (($aBrand && $aModel) ? '· ' : '') . ($aModel ?? '')) }}</span>@endif
                                                    </span>
                                                </span>
                                                <span>
                                                    <span class="inline-block rounded-md px-2 py-[3px] text-[11px] font-semibold {{ $aPill['bg'] }} {{ $aPill['text'] }}">
                                                        {{ $t['status_text'] ?? '—' }}
                                                    </span>
                                                </span>
                                                <span class="text-right">
                                                    <button type="button"
                                                            wire:click="showClaimModal('{{ $t['id'] }}')"
                                                            class="btn btn-sm btn-primary gap-1 h-8 px-3.5 text-xs">
                                                        Claim
                                                    </button>
                                                    <noscript>
                                                        <form method="POST" action="{{ route('tsp.tickets.claim', $t['id']) }}" class="ml-1">
                                                            @csrf
                                                            <button type="submit" class="btn btn-sm btn-primary">Claim</button>
                                                        </form>
                                                    </noscript>
                                                </span>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </section>
                    @endif

                    {{-- ── My tickets ── --}}
                    <section class="rounded-2xl bg-base-100 border border-base-300/70 shadow-sm p-6">
                        <h3 class="text-[15px] font-semibold text-base-content">My tickets</h3>
                        <p class="text-xs text-base-content/50 mt-0.5">From Monday.com · cached 30s</p>

                        @php $hasMyTickets = !empty($myTickets); @endphp
                        @if(!$hasMyTickets)
                            <div class="pt-2">
                                <x-ui.empty-state
                                    icon="📋"
                                    title="No tickets claimed yet"
                                    body="Check the available tickets pool above to claim one, or wait for new tickets to come in from your region."
                                />
                            </div>
                        @elseif($hasMyTickets && empty($this->filteredMyTickets))
                            <div class="pt-2">
                                <x-ui.empty-state
                                    icon="🔍"
                                    title="No matching tickets"
                                    body="Try adjusting your search or filters."
                                />
                            </div>
                        @else
                            @php
                                $pendingTransferByTicket = [];
                                foreach ($myPendingTransfers as $pt) {
                                    $pendingTransferByTicket[$pt['monday_ticket_id']] = $pt;
                                }
                            @endphp
                            <div class="hidden sm:grid grid-cols-[140px_1fr_100px_80px] gap-2 items-center pt-4 pb-2.5 border-b border-base-300/70 text-[11px] font-semibold uppercase tracking-wider text-base-content/50">
                                <span>Ticket ID</span>
                                <span>Subject</span>
                                <span>Status</span>
                                <span class="text-right">Action</span>
                            </div>
                            <ul role="list" class="divide-y divide-base-300/70">
                                @foreach($this->filteredMyTickets as $t)
                                    @php
                                        $mPill = $statusPill((string) ($t['status_text'] ?? ''));
                                        $mBrand = $t['item']['column_values']['text_mm5apcrc']['text'] ?? null;
                                        $mModel = $t['item']['column_values']['text_mm5am2kf']['text'] ?? null;
                                        $mAccount = $t['account_name'] ?? null;
                                        $currentId = (string) (auth()->user()->monday_id ?? '');
                                        $tspIds = array_map('strval', $t['tsp_person_ids'] ?? []);
                                        $otherTsps = array_values(array_filter(
                                            $tspIds,
                                            static fn ($id) => $id !== '' && $id !== $currentId,
                                        ));
                                        $assignedNames = array_values(array_filter(
                                            array_map(
                                                static fn ($id) => $this->tspNameMap[$id] ?? null,
                                                $otherTsps,
                                            ),
                                        ));
                                        $statusLower = strtolower((string) ($t['status_text'] ?? ''));
                                        $isResolved = str_contains($statusLower, 'resolved')
                                            || str_contains($statusLower, 'closed')
                                            || str_contains($statusLower, 'done')
                                            || str_contains($statusLower, 'complete');
                                    @endphp
                                    <li wire:key="mine-{{ $t['id'] }}">
                                        <div class="grid grid-cols-[1fr_auto] sm:grid-cols-[140px_1fr_100px_80px] gap-2 items-center py-3.5">
                                            <a href="{{ route('tsp.tickets.show', $t['id']) }}"
                                               class="text-[13px] font-semibold text-primary hover:underline truncate">
                                                {{ $t['name'] ?: ('#' . $t['id']) }}
                                            </a>
                                            <span class="min-w-0">
                                                <a href="{{ route('tsp.tickets.show', $t['id']) }}"
                                                   class="block text-[13px] text-base-content truncate hover:text-primary transition">
                                                    {{ $t['subject_text'] ?: $t['name'] }}
                                                </a>
                                                <span class="flex flex-wrap items-center gap-x-2 text-[11px] text-base-content/50 mt-0.5">
                                                    @if($mAccount)<span class="truncate">{{ $mAccount }}</span>@endif
                                                    @if($mBrand || $mModel)<span class="truncate">{{ trim(($mBrand ?? '') . ' ' . (($mBrand && $mModel) ? '· ' : '') . ($mModel ?? '')) }}</span>@endif
                                                    @if(!empty($assignedNames))<span class="truncate">· {{ implode(', ', $assignedNames) }}</span>@endif
                                                    @if(isset($pendingTransferByTicket[$t['id']]))
                                                        <span class="text-accent">Transfer pending → {{ $pendingTransferByTicket[$t['id']]['to_name'] }}</span>
                                                    @endif
                                                    @if(!empty($t['updates_count']))
                                                        <span>{{ $t['updates_count'] }} update{{ $t['updates_count'] === 1 ? '' : 's' }}</span>
                                                    @endif
                                                </span>
                                            </span>
                                            <span>
                                                <span class="inline-block rounded-md px-2 py-[3px] text-[11px] font-semibold {{ $mPill['bg'] }} {{ $mPill['text'] }}">
                                                    {{ $t['status_text'] ?? '—' }}
                                                </span>
                                            </span>
                                            <span class="flex items-center justify-end gap-1.5">
                                                @if(isset($pendingTransferByTicket[$t['id']]))
                                                    <button type="button"
                                                            wire:click="cancelPendingTransfer({{ $pendingTransferByTicket[$t['id']]['id'] }})"
                                                            wire:confirm="Cancel the transfer request for ticket #{{ $t['id'] }}? It stays assigned to you."
                                                            class="btn btn-xs btn-ghost text-accent hover:bg-base-200">
                                                        Cancel request
                                                    </button>
                                                @elseif(!$isResolved && empty($otherTsps))
                                                    <button type="button"
                                                            wire:click="openTransfer('{{ $t['id'] }}')"
                                                            wire:loading.attr="disabled"
                                                            wire:target="openTransfer('{{ $t['id'] }}')"
                                                            class="btn btn-xs btn-ghost text-base-content/60 hover:text-primary hover:bg-base-200 gap-1"
                                                            title="Transfer this ticket to another TSP">
                                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                                                        <span>Transfer</span>
                                                    </button>
                                                @endif
                                                <a href="{{ route('tsp.tickets.show', $t['id']) }}"
                                                   class="text-base-content/40 hover:text-primary transition p-1"
                                                   title="Open ticket #{{ $t['id'] }}">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                                    </svg>
                                                </a>
                                            </span>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                </div>

                {{-- ── Right column: sync status ── --}}
                <div class="space-y-5">
                    <section class="rounded-2xl bg-base-100 border border-base-300/70 shadow-sm p-5">
                        <h3 class="text-[15px] font-semibold text-base-content">Sync status</h3>
                        <p class="text-xs text-base-content/50 mt-0.5">Service reports mirroring to Monday.com</p>

                        @if($stats['pending_count'] > 0)
                            <div class="flex items-center gap-3 mt-3 px-3.5 py-3 rounded-xl bg-warning/10 border border-warning/30"
                                 data-testid="sync-queued-banner">
                                <div class="w-9 h-9 rounded-full bg-warning/20 text-warning flex items-center justify-center shrink-0">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-base-content">
                                        {{ $stats['pending_count'] }} queued
                                    </p>
                                    <p class="text-[11px] text-base-content/70">Going through automatically.</p>
                                </div>
                            </div>
                        @endif

                        @if($stats['error_count'] > 0)
                            <div class="mt-3 px-3.5 py-3 rounded-xl bg-error/10 border border-error/30"
                                 data-testid="sync-needs-attention-banner">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-full bg-error/20 text-error flex items-center justify-center shrink-0">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-semibold text-base-content">
                                            {{ $stats['error_count'] }} need{{ $stats['error_count'] === 1 ? 's' : '' }} attention
                                        </p>
                                        <p class="text-[11px] text-base-content/70">Retry, or discard if the ticket is gone.</p>
                                    </div>
                                    <button type="button"
                                            wire:click="retryAll"
                                            wire:loading.attr="disabled"
                                            wire:target="retryAll"
                                            class="btn btn-xs btn-ghost text-error hover:bg-error/20 shrink-0">
                                        <span wire:loading.remove wire:target="retryAll">Retry all</span>
                                        <span wire:loading wire:target="retryAll" class="loading loading-spinner loading-xs"></span>
                                    </button>
                                </div>

                                @if(!empty($errorReports))
                                    <ul class="mt-3 space-y-2">
                                        @foreach($errorReports as $r)
                                            <li class="px-3 py-2.5 rounded-lg bg-base-100 border border-base-300/60"
                                                data-testid="error-row-{{ $r['id'] }}">
                                                <div class="flex items-center gap-2 flex-wrap text-[11px]">
                                                    @if(!empty($r['ticket']))
                                                        <span class="font-mono text-base-content/60">Ticket #{{ $r['ticket'] }}</span>
                                                    @endif
                                                    <span class="text-base-content/50">TSR #{{ $r['id'] }}</span>
                                                    @if(!empty($r['created_at']))
                                                        <span class="text-base-content/40">{{ \Carbon\Carbon::parse($r['created_at'])->diffForHumans() }}</span>
                                                    @endif
                                                </div>
                                                @if(!empty($r['error']))
                                                    <p class="text-[11px] text-error/90 mt-1 break-words leading-snug" title="{{ $r['error'] }}">
                                                        {{ \Illuminate\Support\Str::limit($r['error'], 160) }}
                                                    </p>
                                                @endif
                                                <div class="flex items-center gap-1.5 mt-1.5">
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
                                        <p class="text-[11px] text-base-content/50 mt-2">
                                            Showing the {{ count($errorReports) }} most recent. {{ $stats['error_count'] - count($errorReports) }} more — use Retry all to clear.
                                        </p>
                                    @endif
                                @endif
                            </div>
                        @endif

                        @if($stats['pending_count'] === 0 && $stats['error_count'] === 0)
                            <div class="flex items-center gap-3 mt-3 px-3.5 py-3 rounded-xl bg-success/10 border border-success/30">
                                <div class="w-9 h-9 rounded-full bg-success/20 text-success flex items-center justify-center shrink-0">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-base-content">All synced</p>
                                    <p class="text-[11px] text-base-content/70">Every report is on Monday.com.</p>
                                </div>
                            </div>
                        @endif
                    </section>
                </div>
            </div>
        </div>
    </div>

    {{-- ───── Claim confirmation modal (base tokens, Figma radius) ───── --}}
    @if($claimingTicket)
        @php
            $ctBrand = $claimingTicket['item']['column_values']['text_mm5apcrc']['text'] ?? null;
            $ctModel = $claimingTicket['item']['column_values']['text_mm5am2kf']['text'] ?? null;
            $ctDesc  = $claimingTicket['item']['column_values']['long_text7']['text'] ?? null;
        @endphp
        <div class="fixed inset-0 z-50 overflow-y-auto"
             x-data
             x-init="document.body.classList.add('overflow-y-hidden')"
             x-on:keydown.escape.window="$wire.cancelClaim(); document.body.classList.remove('overflow-y-hidden')"
             x-on:close-claim-modal.window="document.body.classList.remove('overflow-y-hidden')">
            <div class="fixed inset-0 bg-black/60 backdrop-blur-sm transition-opacity"
                 wire:click="cancelClaim"
                 x-on:click="document.body.classList.remove('overflow-y-hidden')"></div>

            <div class="min-h-full flex items-center justify-center p-4">
                <div class="relative bg-base-100 rounded-2xl shadow-xl max-w-lg w-full border border-base-300/70">
                    <div class="flex items-center justify-between px-6 py-4 border-b border-base-300/70">
                        <div>
                            <h2 class="text-lg font-semibold text-base-content">Claim ticket</h2>
                            <p class="text-sm text-base-content/60 mt-0.5">Review the ticket details before claiming</p>
                        </div>
                        <button type="button"
                                wire:click="cancelClaim"
                                x-on:click="document.body.classList.remove('overflow-y-hidden')"
                                class="text-base-content/40 hover:text-base-content transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <div class="px-6 py-5 space-y-5">
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-medium uppercase tracking-wider text-base-content/50">Ticket</span>
                            <span class="text-sm font-mono font-bold text-primary">{{ $claimingTicket['name'] ?: ('#' . $claimingTicket['id']) }}</span>
                        </div>

                        @if(!empty($claimingTicket['account_name']))
                            <div>
                                <label class="block text-xs font-semibold text-base-content/50 uppercase tracking-wider mb-1">Account</label>
                                <div class="flex items-center gap-2 text-sm font-medium text-base-content">
                                    <svg class="w-4 h-4 text-base-content/40 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                                    {{ $claimingTicket['account_name'] }}
                                </div>
                            </div>
                        @endif

                        @if($ctBrand || $ctModel)
                            <div>
                                <label class="block text-xs font-semibold text-base-content/50 uppercase tracking-wider mb-1">Machine</label>
                                <div class="flex items-center gap-2 text-sm font-medium text-base-content">
                                    <svg class="w-4 h-4 text-base-content/40 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
                                    {{ trim(($ctBrand ?? '') . ' ' . (($ctBrand && $ctModel) ? '· ' : '') . ($ctModel ?? '')) }}
                                </div>
                            </div>
                        @endif

                        <div>
                            <label class="block text-xs font-semibold text-base-content/50 uppercase tracking-wider mb-1">Subject</label>
                            <p class="text-sm font-medium text-base-content break-words">{{ $claimingTicket['subject_text'] ?: $claimingTicket['name'] }}</p>
                        </div>

                        @if($ctDesc)
                            <div>
                                <label class="block text-xs font-semibold text-base-content/50 uppercase tracking-wider mb-1">Description</label>
                                <div class="text-sm text-base-content/80 whitespace-pre-wrap bg-base-200/50 rounded-xl p-3 border border-base-300/60 max-h-40 overflow-y-auto leading-relaxed">{{ $ctDesc }}</div>
                            </div>
                        @endif
                    </div>

                    <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-base-300/70 bg-base-200/40 rounded-b-2xl">
                        <button type="button"
                                wire:click="cancelClaim"
                                x-on:click="document.body.classList.remove('overflow-y-hidden')"
                                class="btn btn-sm btn-ghost">Cancel</button>
                        <button type="button"
                                wire:click="confirmClaim"
                                x-on:click="document.body.classList.remove('overflow-y-hidden')"
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

    {{-- ───── Transfer-target picker modal (unchanged behavior) ───── --}}
    @if($transferTicketId)
        <div class="fixed inset-0 z-50 overflow-y-auto"
             x-data
             x-init="document.body.classList.add('overflow-y-hidden')"
             x-on:keydown.escape.window="$wire.cancelTransfer(); document.body.classList.remove('overflow-y-hidden')"
             x-on:close-transfer-modal.window="document.body.classList.remove('overflow-y-hidden')">
            <div class="fixed inset-0 bg-black/60 backdrop-blur-sm transition-opacity"
                 wire:click="cancelTransfer"
                 x-on:click="document.body.classList.remove('overflow-y-hidden')"></div>

            <div class="min-h-full flex items-center justify-center p-4">
                <div class="relative bg-base-100 rounded-2xl shadow-xl max-w-lg w-full border border-base-300/70">
                    <div class="flex items-center justify-between px-6 py-4 border-b border-base-300/70">
                        <div>
                            <h2 class="text-lg font-semibold text-base-content">Transfer {{ $transferTicketName ?: ('ticket #' . $transferTicketId) }}</h2>
                            <p class="text-sm text-base-content/60 mt-0.5">
                                Pick a TSP to take over. They'll need to confirm on their dashboard before it moves on Monday.
                            </p>
                        </div>
                        <button type="button"
                                wire:click="cancelTransfer"
                                x-on:click="document.body.classList.remove('overflow-y-hidden')"
                                class="text-base-content/40 hover:text-base-content transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <div class="px-6 py-5 space-y-4">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-xs font-semibold text-base-content/60 uppercase tracking-wider">Branch scope</span>
                            <div class="join">
                                <button type="button"
                                        wire:click="setTransferScope('same')"
                                        class="join-item btn btn-sm {{ $transferScope === 'same' ? 'btn-primary' : 'btn-ghost' }}">
                                    Same branch
                                </button>
                                <button type="button"
                                        wire:click="setTransferScope('all')"
                                        class="join-item btn btn-sm {{ $transferScope === 'all' ? 'btn-primary' : 'btn-ghost' }}">
                                    All branches
                                </button>
                            </div>
                            @if($transferScope === 'all')
                                <span class="badge badge-warning badge-sm gap-1">
                                    <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                                    Cross-branch
                                </span>
                            @endif
                        </div>

                        @if(empty($transferTargets))
                            <div class="text-sm text-base-content/70 flex items-start gap-3 p-4 rounded-xl bg-base-200/50 border border-base-300/60">
                                <svg class="w-5 h-5 text-base-content/40 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                @if($transferScope === 'same')
                                    <span>No other TSP in your branch is available to receive this ticket right now — switch to <strong>All branches</strong> to find a receiver in another region.</span>
                                @else
                                    <span>No TSP across any branch is currently available to receive this ticket.</span>
                                @endif
                            </div>
                        @else
                            <fieldset>
                                <legend class="block text-xs font-semibold text-base-content/60 uppercase tracking-wider mb-2">
                                    Choose the receiving TSP <span class="normal-case font-normal">({{ collect($transferTargets)->where('assignable', true)->count() }} available)</span>
                                </legend>
                                <div class="space-y-2 max-h-72 overflow-y-auto pr-1">
                                    @foreach($transferTargets as $target)
                                        <label class="flex items-start gap-3 p-3 rounded-xl border {{ $target['assignable'] ? 'border-base-300/60 bg-base-200/30 hover:border-primary/50 hover:bg-primary/10 cursor-pointer transition' : 'border-base-300/30 bg-base-200/10 opacity-60 cursor-not-allowed' }}"
                                               wire:key="target-{{ $target['id'] }}"
                                               @if(!$target['assignable']) title="This TSP has no Monday.com person id linked yet — an admin must link it before they can receive transfers." @endif>
                                            <input type="radio"
                                                   name="transferTarget"
                                                   value="{{ $target['id'] }}"
                                                   wire:model.live="transferToUserId"
                                                   class="radio radio-primary radio-sm mt-0.5"
                                                   @if(!$target['assignable']) disabled @endif>
                                            <span class="flex-1 min-w-0">
                                                <span class="block text-sm font-semibold text-base-content">{{ $target['name'] }}</span>
                                                <span class="block text-xs text-base-content/50 truncate">{{ $target['email'] }}</span>
                                                @if(!$target['assignable'])
                                                    <span class="block text-[10px] text-warning mt-0.5">No Monday account linked</span>
                                                @endif
                                            </span>
                                            @if(!empty($target['region']))
                                                <span class="badge badge-ghost badge-sm shrink-0">{{ $target['region'] }}</span>
                                            @endif
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                            <p class="text-[11px] text-base-content/50">
                                The original assignment on Monday.com is only replaced after the receiving TSP confirms.
                            </p>
                        @endif
                    </div>

                    <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-base-300/70 bg-base-200/40 rounded-b-2xl">
                        <button type="button"
                                wire:click="cancelTransfer"
                                x-on:click="document.body.classList.remove('overflow-y-hidden')"
                                class="btn btn-sm btn-ghost">Cancel</button>
                        <button type="button"
                                wire:click="requestTransfer"
                                wire:loading.attr="disabled"
                                wire:target="requestTransfer"
                                @disabled(empty($transferToUserId))
                                class="btn btn-sm btn-primary gap-1"
                                title="{{ empty($transferToUserId) ? 'Select a TSP first' : 'Send the transfer request' }}">
                            <span wire:loading.remove wire:target="requestTransfer">Send request</span>
                            <span wire:loading wire:target="requestTransfer" class="loading loading-spinner loading-xs"></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

{{-- ───── Realtime Pusher subscription (unchanged) ───── --}}
@once
    @push('scripts')
        <script>
            (function () {
                var regionCode = @json(
                    \App\Support\RegionResolver::resolveForCustomer(auth()->user())
                );
                var mod = window.__realtimeDashboard
                    || (window.__realtimeDashboard = {
                        init: function () {},
                    });
                if (typeof mod.init === 'function') {
                    mod.init({ regionCode: regionCode });
                }
            })();
        </script>
    @endpush
@endonce
