{{-- =============================================================
     Customer dashboard — Figma "Customer portal - All-in-One" frame.
     Layout: welcome header, 5 stat cards, ticket table panel with
     tabs/filters/pagination, right column (recent activity, quick
     actions, help banner). Primary actions use the portal navy.
     Ticket data arrives as JSON; filtering/sorting/pagination are
     client-side (ticketFilter Alpine factory, resources/js).
     ============================================================= --}}
<x-app-layout>
    @php
        $firstName = explode(' ', (string) ($user->name ?? 'there'))[0];
        $account = $user->account_name ?: 'Customer';
    @endphp

    {{-- ── Page header ── --}}
    <x-slot name="header">
        <div class="animate-enter flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div>
                <p class="text-[11px] font-medium uppercase tracking-wider text-base-content/50">
                    {{ $account }}
                </p>
                <h2 class="font-bold text-3xl text-base-content leading-tight mt-1">
                    Welcome back, {{ $firstName }}!
                </h2>
                <p class="text-sm text-base-content/60 mt-1.5">
                    Here's your support ticket overview for {{ $account }}.
                </p>
            </div>
            <a href="{{ route('tickets.create') }}" class="btn btn-primary gap-2 h-11 px-5 shrink-0">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New Ticket
            </a>
        </div>
    </x-slot>

    <div class="py-2">
        <div class="max-w-6xl mx-auto sm:px-4 lg:px-6 space-y-7">

            @if (session('status'))
                <x-ui.toast type="success" title="All set!">
                    {{ session('status') }}
                </x-ui.toast>
            @endif

            {{-- Monday unreachable: render empty + banner, never a 500. --}}
            @if (! empty($mondayUnreachable))
                <div class="alert alert-warning" role="status">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M4.93 19h14.14a2 2 0 001.74-3L13.74 4a2 2 0 00-3.48 0L3.19 16.9a2 2 0 001.74 3z"/></svg>
                    <div class="text-sm">
                        <strong>Can't reach the ticket system right now.</strong>
                        Your service requests aren't shown while we reconnect — please refresh in a few minutes.
                    </div>
                </div>
            @endif

            {{-- ── Status summary (5 stat cards) ── --}}
            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-4">
                @foreach ([
                    ['label' => 'Total',       'value' => $stats['total'],       'hint' => 'All time tickets',   'tone' => 'text-base-content'],
                    ['label' => 'Open',        'value' => $stats['open'],        'hint' => 'Awaiting response',  'tone' => 'text-info'],
                    ['label' => 'In Progress', 'value' => $stats['in_progress'], 'hint' => 'Being worked on',    'tone' => 'text-warning'],
                    ['label' => 'Awaiting Info', 'value' => $stats['awaiting'],  'hint' => 'Pending your reply', 'tone' => 'text-accent'],
                    ['label' => 'Resolved',    'value' => $stats['resolved'],    'hint' => 'Closed tickets',     'tone' => 'text-success'],
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

            <div x-data="ticketFilter({ tickets: {{ Js::from($ticketsJson) }} })">
                <div class="grid grid-cols-1 xl:grid-cols-3 gap-5 items-start">

                    {{-- ── Ticket list panel ── --}}
                    <section class="xl:col-span-2 rounded-2xl bg-base-100 border border-base-300/70 shadow-sm p-6">
                        <div class="flex items-center justify-between">
                            <h3 class="text-[15px] font-semibold text-base-content">My Tickets</h3>
                            <a href="{{ route('tickets.create') }}" class="btn btn-primary btn-sm h-8 px-3.5 text-xs">
                                + New Ticket
                            </a>
                        </div>

                        {{-- Filters row --}}
                        <div class="flex flex-wrap items-center gap-2.5 mt-4">
                            <div class="relative flex-1 min-w-[160px]">
                                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-base-content/40 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                <input type="search" x-model="query" @input="resetPage()" placeholder="Search tickets…"
                                       class="input input-bordered input-sm w-full pl-8 h-9 text-[13px] bg-base-200/50">
                            </div>

                            <div class="dropdown dropdown-end">
                                <button class="btn btn-sm h-9 px-3 gap-1.5 bg-base-100 border-base-300 font-medium text-xs" tabindex="0">
                                    <span x-text="statusFilter.length || (tab !== 'all') ? 'Status •' : 'Status'"></span>
                                    <svg class="w-3 h-3 text-base-content/50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                </button>
                                <ul class="dropdown-content menu menu-xs p-1.5 shadow-lg bg-base-100 rounded-box w-44 z-20 border border-base-300/60">
                                    <li><button type="button" @click="toggleStatus('open')" :class="{ active: statusFilter.includes('open') }" class="w-full text-left">Open</button></li>
                                    <li><button type="button" @click="toggleStatus('in_progress')" :class="{ active: statusFilter.includes('in_progress') }" class="w-full text-left">In Progress</button></li>
                                    <li><button type="button" @click="toggleStatus('awaiting')" :class="{ active: statusFilter.includes('awaiting') }" class="w-full text-left">Awaiting Info</button></li>
                                    <li><button type="button" @click="toggleStatus('resolved')" :class="{ active: statusFilter.includes('resolved') }" class="w-full text-left">Resolved</button></li>
                                    <li><button type="button" @click="toggleStatus('uncategorised')" :class="{ active: statusFilter.includes('uncategorised') }" class="w-full text-left">Uncategorised</button></li>
                                </ul>
                            </div>

                            <div class="dropdown dropdown-end">
                                <button class="btn btn-sm h-9 px-3 gap-1.5 bg-base-100 border-base-300 font-medium text-xs" tabindex="0">
                                    <span x-text="priorityFilter.length ? `Priority (${priorityFilter.length})` : 'Priority'"></span>
                                    <svg class="w-3 h-3 text-base-content/50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                </button>
                                <ul class="dropdown-content menu menu-xs p-1.5 shadow-lg bg-base-100 rounded-box w-40 z-20 border border-base-300/60">
                                    @foreach (['Critical', 'High', 'Medium', 'Low'] as $p)
                                        <li><button type="button" @click="togglePriority('{{ $p }}')" :class="{ active: priorityFilter.includes('{{ $p }}') }" class="w-full text-left">{{ $p }}</button></li>
                                    @endforeach
                                </ul>
                            </div>

                            <div class="join">
                                <button @click="sort = 'newest'; resetPage()" :class="sort === 'newest' ? 'btn-active' : ''" class="btn btn-xs join-item h-9" title="Newest first">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9m-9 4h6m4 0l4-4m0 0l4 4m-4-4v12"/></svg>
                                </button>
                                <button @click="sort = 'oldest'; resetPage()" :class="sort === 'oldest' ? 'btn-active' : ''" class="btn btn-xs join-item h-9" title="Oldest first">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9m-9 4h9m4-4v12m0 0l-4-4m4 4l4-4"/></svg>
                                </button>
                            </div>

                            <button x-show="activeFilterCount > 0" x-cloak
                                    @click="clearFilters()"
                                    class="btn btn-xs btn-ghost text-base-content/50 gap-1 h-9">
                                Clear
                            </button>
                        </div>

                        {{-- Tab bar --}}
                        <div class="flex items-center gap-5 mt-4 border-b border-base-300/70 text-[13px] font-medium" role="tablist">
                            @foreach ([['all', 'All'], ['open', 'Open'], ['in_progress', 'In Progress'], ['awaiting', 'Awaiting Info'], ['resolved', 'Resolved']] as [$bucket, $label])
                                <button type="button" role="tab"
                                        @click="selectTab('{{ $bucket }}')"
                                        :class="tab === '{{ $bucket }}' ? 'text-primary border-b-2 border-primary -mb-px pb-2.5' : 'text-base-content/50 pb-2.5 hover:text-base-content'">
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>

                        {{-- Table header --}}
                        <div class="hidden sm:grid grid-cols-[140px_1fr_100px_80px] gap-2 items-center pt-3 pb-2.5 border-b border-base-300/70 text-[11px] font-semibold uppercase tracking-wider text-base-content/50">
                            <span>Ticket ID</span>
                            <span>Subject</span>
                            <span>Status</span>
                            <span>Priority</span>
                        </div>

                        {{-- Empty: no tickets at all --}}
                        <template x-if="tickets.length === 0">
                            <div class="py-2">
                                <x-ui.empty-state
                                    icon="📋"
                                    title="No service requests yet"
                                    body="When you submit a service request it will show up here so you can track progress and add updates."
                                    cta="Start a service request"
                                    :ctaRoute="'tickets.create'"
                                />
                            </div>
                        </template>

                        {{-- Empty: filtered out --}}
                        <template x-if="tickets.length > 0 && filteredTickets.length === 0">
                            <div class="py-2">
                                <x-ui.empty-state
                                    icon="🔍"
                                    title="No matching tickets"
                                    body="Try adjusting your search or filters."
                                />
                            </div>
                        </template>

                        {{-- Rows --}}
                        <template x-if="pagedTickets.length > 0">
                            <ul role="list" class="divide-y divide-base-300/70">
                                <template x-for="t in pagedTickets" :key="t.id">
                                    <li>
                                        <a :href="`/tickets/${t.id}`"
                                           class="grid grid-cols-[1fr_auto] sm:grid-cols-[140px_1fr_100px_80px] gap-2 items-center py-3.5 hover:bg-base-200/40 transition group rounded-lg px-1">
                                            <span class="text-[13px] font-semibold text-primary" x-text="t.name || `#${t.id}`"></span>
                                            <span class="min-w-0 sm:col-auto">
                                                <span class="block text-[13px] text-base-content truncate" x-text="t.subject_text || t.name"></span>
                                                <span class="block text-[11px] text-base-content/50 truncate mt-0.5">
                                                    <span x-text="t.request_type_text || ''"></span>
                                                    <template x-if="t.assigned_names && t.assigned_names.length">
                                                        <span x-text="(t.request_type_text ? ' · ' : '') + t.assigned_names.join(', ')"></span>
                                                    </template>
                                                </span>
                                            </span>
                                            <span>
                                                <span class="inline-block rounded-md px-2 py-[3px] text-[11px] font-semibold"
                                                      :class="statusPill(t._statusBucket).bg + ' ' + statusPill(t._statusBucket).text"
                                                      x-text="t.status_text || '—'"></span>
                                            </span>
                                            <span>
                                                <template x-if="priorityPill(t.priority_text)">
                                                    <span class="inline-block rounded-md px-2 py-[3px] text-[11px] font-semibold"
                                                          :class="priorityPill(t.priority_text).bg + ' ' + priorityPill(t.priority_text).text"
                                                          x-text="priorityPill(t.priority_text).label"></span>
                                                </template>
                                                <template x-if="! priorityPill(t.priority_text)">
                                                    <span class="text-base-content/30 text-xs">—</span>
                                                </template>
                                            </span>
                                        </a>
                                    </li>
                                </template>
                            </ul>
                        </template>

                        {{-- Pagination --}}
                        <template x-if="filteredTickets.length > 0">
                            <div class="flex items-center justify-between pt-3">
                                <span class="text-xs text-base-content/50">
                                    Showing <span x-text="showingFrom"></span>–<span x-text="showingTo"></span>
                                    of <span x-text="filteredTickets.length"></span> tickets
                                </span>
                                <div class="flex items-center gap-1.5" x-show="pageCount > 1">
                                    <button type="button" @click="page = Math.max(1, page - 1)" :disabled="page <= 1"
                                            class="btn btn-xs h-8 px-2.5 border-base-300 disabled:opacity-40">← Prev</button>
                                    <template x-for="p in pageCount" :key="p">
                                        <button type="button" @click="page = p"
                                                :class="page === p ? 'btn-primary text-primary-content border-primary' : 'border-base-300'"
                                                class="btn btn-xs h-8 min-w-8 px-2 border" x-text="p"></button>
                                    </template>
                                    <button type="button" @click="page = Math.min(pageCount, page + 1)" :disabled="page >= pageCount"
                                            class="btn btn-xs h-8 px-2.5 border-base-300 disabled:opacity-40">Next →</button>
                                </div>
                            </div>
                        </template>
                    </section>

                    {{-- ── Right column ── --}}
                    <div class="space-y-5">
                        {{-- Recent activity (derived from loaded tickets) --}}
                        <section class="rounded-2xl bg-base-100 border border-base-300/70 shadow-sm p-5">
                            <div class="flex items-center justify-between">
                                <h3 class="text-[15px] font-semibold text-base-content">Recent Activity</h3>
                                <span class="text-xs text-primary">Latest</span>
                            </div>
                            @if (count($recentActivity) === 0)
                                <p class="text-xs text-base-content/50 mt-3">New tickets and updates will appear here.</p>
                            @else
                                <ul class="divide-y divide-base-300/70 mt-1">
                                    @foreach ($recentActivity as $i => $a)
                                        @php
                                            $tones = [
                                                ['bg' => 'bg-[#EEF0FD]', 'text' => 'text-[#5B5BD6]'],
                                                ['bg' => 'bg-[#E3F7F3]', 'text' => 'text-[#17847A]'],
                                                ['bg' => 'bg-[#FDEEF0]', 'text' => 'text-[#C1447E]'],
                                                ['bg' => 'bg-[#EBF2FD]', 'text' => 'text-[#3977E8]'],
                                            ];
                                            $tone = $tones[$i % count($tones)];
                                            $initials = implode('', array_map(fn ($w) => mb_substr($w, 0, 1), preg_split('/\s+/', trim((string) ($a['subject_text'] ?? $a['name'])), -1, PREG_SPLIT_NO_EMPTY)));
                                            $initials = mb_substr($initials !== '' ? $initials : $a['name'], 0, 2);
                                        @endphp
                                        <li class="py-3 first:pt-2 last:pb-0">
                                            <a href="{{ route('tickets.show', ['id' => $a['id']]) }}" class="flex items-center gap-2.5 group">
                                                <span class="w-[30px] h-[30px] rounded-full {{ $tone['bg'] }} {{ $tone['text'] }} text-[10px] font-semibold flex items-center justify-center shrink-0">{{ $initials }}</span>
                                                <span class="min-w-0">
                                                    <span class="block text-xs font-semibold text-base-content truncate group-hover:text-primary">{{ $a['name'] }} · {{ $a['status_text'] }}</span>
                                                    <span class="block text-[11px] text-base-content/50 truncate mt-0.5">{{ $a['subject_text'] }}</span>
                                                </span>
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </section>

                        {{-- Quick actions --}}
                        <section class="rounded-2xl bg-base-100 border border-base-300/70 shadow-sm p-5">
                            <h3 class="text-[15px] font-semibold text-base-content">Quick Actions</h3>
                            <div class="space-y-2 mt-3">
                                @foreach ([
                                    ['title' => 'Submit New Ticket', 'hint' => 'Report an issue or request', 'url' => route('tickets.create')],
                                    ['title' => 'Help & Support', 'hint' => 'Guides and troubleshooting', 'url' => route('help')],
                                    ['title' => 'Account Settings', 'hint' => 'Profile, notifications & more', 'url' => route('profile')],
                                ] as $action)
                                    <a href="{{ $action['url'] }}"
                                       class="flex items-center gap-2.5 rounded-[10px] bg-base-200/50 border border-base-300/70 px-3.5 py-2.5 hover:bg-base-200 transition group">
                                        <span class="w-7 h-7 rounded-lg bg-[#EEEEFF] text-[#5B5BD6] flex items-center justify-center shrink-0">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
                                        </span>
                                        <span class="flex-1 min-w-0">
                                            <span class="block text-[13px] font-semibold text-base-content leading-tight">{{ $action['title'] }}</span>
                                            <span class="block text-[11px] text-base-content/50">{{ $action['hint'] }}</span>
                                        </span>
                                        <span class="text-base-content/40 group-hover:text-primary group-hover:translate-x-0.5 transition text-sm" aria-hidden="true">›</span>
                                    </a>
                                @endforeach
                            </div>
                        </section>

                        {{-- Help resources banner (informational, no external link) --}}
                        <section id="help-resources" class="rounded-2xl bg-[#EEEEFF] p-4 flex items-center gap-3.5">
                            <span class="w-[38px] h-[38px] rounded-[10px] bg-base-100 flex items-center justify-center shrink-0">
                                <svg class="w-[17px] h-[17px] text-[#5B5BD6]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                            </span>
                            <span>
                                <span class="block text-[13px] font-semibold text-base-content">MC BioTechnical Solutions Inc.</span>
                                <span class="block text-[11px] text-base-content/60">Product updates, setup guides and more.</span>
                            </span>
                        </section>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
