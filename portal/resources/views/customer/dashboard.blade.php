<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <p class="text-xs font-semibold tracking-widest uppercase text-base-content/50 mb-1">
                    {{ $user->account_name ? $user->account_name : 'Customer' }}
                </p>
                <h2 class="font-semibold text-2xl text-base-content leading-tight">
                    Hello, {{ explode(' ', $user->name)[0] }} 👋
                </h2>
                <p class="text-sm text-base-content/60 mt-1">
                    Track your service requests and start a new one when you need us.
                </p>
            </div>
            <a href="{{ route('tickets.create') }}" class="btn btn-primary gap-2 shadow-soft">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New service request
            </a>
        </div>
    </x-slot>

    <div class="py-2">
        <div class="max-w-4xl mx-auto sm:px-4 lg:px-6 space-y-6">

            @if (session('status'))
                <x-ui.toast type="success" title="All set!">
                    {{ session('status') }}
                </x-ui.toast>
            @endif

            {{-- ───── Quick glance (4 stat cards) ───── --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
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

            {{-- ───── Your service requests ─────
                 Alpine-powered filter bar + client-side sorting.
                 The component receives the full ticket list as JSON,
                 then filters/sorts in-browser — zero extra round trips. --}}
            <x-ui.card
                title="Your service requests"
                subtitle="Tap any row to see the full timeline and updates."
                padding="p-0"
            >
                <x-slot:icon>
                    <span aria-hidden="true" class="w-7 h-7 rounded-lg bg-primary/10 text-primary flex items-center justify-center shrink-0">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                    </span>
                </x-slot:icon>
                <x-slot:actions>
                    <a href="{{ route('tickets.create') }}" class="btn btn-ghost btn-sm gap-1">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        New
                    </a>
                </x-slot:actions>

                <div x-data="ticketFilter({ tickets: {{ Js::from($ticketsJson) }} })">
                    {{-- ── Filter bar ── --}}
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-2.5 border-b border-base-300/70 bg-base-100/50">
                        {{-- Search --}}
                        <div class="relative flex-1 min-w-[160px] max-w-xs">
                            <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-base-content/40 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            <input type="search" x-model="query" placeholder="Search tickets…"
                                   class="input input-xs input-bordered w-full pl-7 h-8 text-sm">
                        </div>

                        {{-- Status dropdown --}}
                        <div class="dropdown dropdown-end">
                            <button class="btn btn-xs btn-ghost gap-1" tabindex="0">
                                <span x-text="statusFilter.length ? `Status (${statusFilter.length})` : 'Status'"></span>
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </button>
                            <ul class="dropdown-content menu menu-xs p-1.5 shadow-lg bg-base-100 rounded-box w-40 z-20 border border-base-300/60">
                                <li><a @click="toggleStatus('open')" :class="{ active: statusFilter.includes('open') }" class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-info"></span>Open</a></li>
                                <li><a @click="toggleStatus('in_progress')" :class="{ active: statusFilter.includes('in_progress') }" class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-warning"></span>In progress</a></li>
                                <li><a @click="toggleStatus('awaiting')" :class="{ active: statusFilter.includes('awaiting') }" class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-accent"></span>Awaiting</a></li>
                                <li><a @click="toggleStatus('resolved')" :class="{ active: statusFilter.includes('resolved') }" class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-success"></span>Resolved</a></li>
                                <li><a @click="toggleStatus('uncategorised')" :class="{ active: statusFilter.includes('uncategorised') }" class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-base-content/30"></span>Uncategorised</a></li>
                            </ul>
                        </div>

                        {{-- Sort --}}
                        <div class="join">
                            <button @click="sort = 'newest'" :class="sort === 'newest' ? 'btn-active' : ''" class="btn btn-xs join-item gap-1">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9m-9 4h6m4 0l4-4m0 0l4 4m-4-4v12"/></svg>
                                Newest
                            </button>
                            <button @click="sort = 'oldest'" :class="sort === 'oldest' ? 'btn-active' : ''" class="btn btn-xs join-item gap-1">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9m-9 4h9m4-4v12m0 0l-4-4m4 4l4-4"/></svg>
                                Oldest
                            </button>
                        </div>

                        {{-- Clear filters --}}
                        <button x-show="activeFilterCount > 0"
                                @click="clearFilters()"
                                class="btn btn-xs btn-ghost text-base-content/50 gap-1">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            Clear
                        </button>
                    </div>

                    {{-- ── Active filter badges ── --}}
                    <template x-if="activeFilterCount > 0">
                        <div class="flex flex-wrap items-center gap-1.5 px-4 py-1.5 border-b border-base-300/40 bg-base-100/30">
                            <template x-for="s in statusFilter" :key="s">
                                <button @click="toggleStatus(s)"
                                        class="badge badge-sm gap-1 cursor-pointer hover:opacity-70 transition"
                                        :class="s === 'open' ? 'badge-info' : s === 'in_progress' ? 'badge-warning' : s === 'awaiting' ? 'badge-accent' : s === 'resolved' ? 'badge-success' : 'badge-ghost'">
                                    <span x-text="s.replace('_', ' ')"></span>
                                    <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </template>
                            <span class="text-[10px] text-base-content/40 ml-1" x-text="`· ${filteredTickets.length} of ${tickets.length}`"></span>
                        </div>
                    </template>

                    {{-- ── Empty: no tickets at all ── --}}
                    <template x-if="tickets.length === 0">
                        <div class="p-2">
                            <x-ui.empty-state
                                icon="📋"
                                title="No service requests yet"
                                body="When you submit a service request it will show up here so you can track progress and add updates."
                                cta="Start a service request"
                                :ctaRoute="'tickets.create'"
                            />
                        </div>
                    </template>

                    {{-- ── Empty: filtered out ── --}}
                    <template x-if="tickets.length > 0 && filteredTickets.length === 0">
                        <div class="p-2">
                            <x-ui.empty-state
                                icon="🔍"
                                title="No matching tickets"
                                body="Try adjusting your search or filters."
                            />
                        </div>
                    </template>

                    {{-- ── Ticket list ── --}}
                    <template x-if="filteredTickets.length > 0">
                        <ul role="list" class="divide-y divide-base-300/70">
                            <template x-for="t in filteredTickets" :key="t.id">
                                <li>
                                    <a :href="`/tickets/${t.id}`"
                                       class="block px-4 py-3.5 hover:bg-base-200/60 transition group">
                                        <div class="flex items-center gap-3">
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-center gap-2 mb-1 flex-wrap">
                                                    <span class="text-[11px] font-mono text-base-content/50" x-text="t.name || `#${t.id}`"></span>
                                                    <span :class="`badge ${statusBadge(t.status_text).class} badge-sm gap-1 font-medium`">
                                                        <span :class="`w-1.5 h-1.5 rounded-full ${statusBadge(t.status_text).dot}`"></span>
                                                        <span x-text="t.status_text || '—'"></span>
                                                    </span>
                                                    <template x-if="t.assigned_names && t.assigned_names.length">
                                                        <span class="badge badge-outline badge-sm gap-1 text-[10px]"
                                                              :title="`Assigned technician${t.assigned_names.length > 1 ? 's' : ''}`">
                                                            <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                                            <span x-text="t.assigned_names.join(', ')"></span>
                                                        </span>
                                                    </template>
                                                </div>
                                                <h3 class="text-sm font-semibold text-base-content truncate group-hover:text-primary transition"
                                                    x-text="t.subject_text || t.name">
                                                </h3>
                                                <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[11px] text-base-content/60 mt-1">
                                                    <template x-if="t.request_type_text">
                                                        <span x-text="t.request_type_text"></span>
                                                    </template>
                                                </div>
                                            </div>
                                            <svg class="w-4 h-4 text-base-content/40 group-hover:text-primary group-hover:translate-x-0.5 transition flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                            </svg>
                                        </div>
                                    </a>
                                </li>
                            </template>
                        </ul>
                    </template>
                </div>
            </x-ui.card>
        </div>
    </div>
</x-app-layout>

