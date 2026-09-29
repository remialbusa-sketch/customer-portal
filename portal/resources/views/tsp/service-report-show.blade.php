{{-- =============================================================
     Service report detail — Figma dashboard language: welcome
     header with pills, divided-row panels, right rail.
     ============================================================= --}}
<x-app-layout>
    <x-slot:title>Service Report — {{ $ticketName }}</x-slot:title>

    @php
        $statusPill = match (true) {
            str_contains(strtolower((string) $report->service_status?->value), 'complete') => ['bg' => 'bg-[#E3F7F3]', 'text' => 'text-[#17847A]'],
            str_contains(strtolower((string) $report->service_status?->value), 'progress') => ['bg' => 'bg-[#FEF3C7]', 'text' => 'text-[#B45309]'],
            str_contains(strtolower((string) $report->service_status?->value), 'escalat') => ['bg' => 'bg-[#FDF2F8]', 'text' => 'text-[#C1447E]'],
            default => ['bg' => 'bg-[#EBF2FD]', 'text' => 'text-[#3977E8]'],
        };
        $syncPill = match ($report->sync_state?->value ?? '') {
            'synced'  => ['bg' => 'bg-[#E3F7F3]', 'text' => 'text-[#17847A]', 'label' => 'Synced to Monday'],
            'pending' => ['bg' => 'bg-[#FEF3C7]', 'text' => 'text-[#B45309]', 'label' => 'Queued for sync'],
            'syncing' => ['bg' => 'bg-[#EBF2FD]', 'text' => 'text-[#3977E8]', 'label' => 'Syncing…'],
            'error'   => ['bg' => 'bg-[#FDEEF0]', 'text' => 'text-[#C1447E]', 'label' => 'Sync failed'],
            default   => ['bg' => 'bg-base-200', 'text' => 'text-base-content/60', 'label' => 'Not synced'],
        };
        $sigCount = collect($signatures)->filter()->count();
    @endphp

    <x-slot:header>
        <div class="animate-enter flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div class="min-w-0">
                <p class="text-[11px] font-medium uppercase tracking-wider text-base-content/50">
                    Service Report
                </p>
                <h2 class="font-bold text-3xl text-base-content leading-tight mt-1 truncate">
                    {{ $ticketName }}
                </h2>
                <p class="text-sm text-base-content/60 mt-1.5">
                    Submitted {{ $report->created_at?->format('M j, Y g:i A') ?? '—' }}
                    @if ($report->client_submitted_at)
                        · from device {{ $report->client_submitted_at->format('M j, Y g:i A') }}
                    @endif
                </p>
            </div>
            <div class="flex items-center gap-2 shrink-0 flex-wrap">
                <span class="inline-block rounded-md px-2 py-[3px] text-[11px] font-semibold {{ $statusPill['bg'] }} {{ $statusPill['text'] }}">
                    {{ $report->statusLabel() }}
                </span>
                <span class="inline-block rounded-md px-2 py-[3px] text-[11px] font-semibold {{ $syncPill['bg'] }} {{ $syncPill['text'] }}">
                    {{ $syncPill['label'] }}
                </span>
                <a href="{{ route('tsp.tickets.show', ['id' => $report->monday_ticket_id]) }}"
                   class="btn btn-ghost btn-sm gap-1.5" wire:navigate>
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    Back to ticket
                </a>
            </div>
        </div>
    </x-slot:header>

    <div class="py-2">
        <div class="max-w-6xl mx-auto sm:px-4 lg:px-6 space-y-5">

            {{-- ── Sync error banner ── --}}
            @if ($report->sync_state?->value === 'error' && $report->sync_error)
                <div class="alert alert-error" role="alert">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M4.93 19h14.14a2 2 0 001.74-3L13.74 4a2 2 0 00-3.48 0L3.19 16.9a2 2 0 001.74 3z"/></svg>
                    <div class="text-sm">
                        <strong>Failed to sync to Monday.com.</strong>
                        <span class="block text-base-content/80 mt-0.5">{{ $report->sync_error }}</span>
                    </div>
                </div>
            @endif

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-5 items-start">
                <div class="xl:col-span-2 space-y-5 min-w-0">

                    {{-- ── Work details ── --}}
                    <section class="rounded-2xl bg-base-100 border border-base-300/70 shadow-sm p-6">
                        <h3 class="text-[15px] font-semibold text-base-content">Work details</h3>
                        <div class="divide-y divide-base-300/70 mt-2">
                            @foreach ([
                                'Problem & concerns' => $report->problem_and_concerns,
                                'Job done' => $report->job_done,
                                'Parts replaced' => $report->parts_replaced,
                                'Recommendation' => $report->recommendation,
                                'Remarks' => $report->remarks,
                            ] as $label => $value)
                                <div class="py-3.5 first:pt-2 last:pb-0">
                                    <p class="text-[11px] font-semibold uppercase tracking-wider text-base-content/50">{{ $label }}</p>
                                    <p class="text-sm text-base-content leading-relaxed whitespace-pre-wrap mt-1">{{ $value ?: '—' }}</p>
                                </div>
                            @endforeach
                        </div>

                        @if ($report->customer_incharge || $report->biomed_incharge)
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-4 pt-4 border-t border-base-300/70">
                                @if ($report->customer_incharge)
                                    <div class="rounded-xl bg-base-200/50 border border-base-300/60 px-3.5 py-2.5">
                                        <p class="text-[11px] font-semibold uppercase tracking-wider text-base-content/50">Customer in charge</p>
                                        <p class="text-sm font-semibold text-base-content mt-0.5">{{ $report->customer_incharge }}</p>
                                        @if ($report->customer_incharge_email)
                                            <p class="text-xs text-base-content/60">{{ $report->customer_incharge_email }}</p>
                                        @endif
                                    </div>
                                @endif
                                @if ($report->biomed_incharge)
                                    <div class="rounded-xl bg-base-200/50 border border-base-300/60 px-3.5 py-2.5">
                                        <p class="text-[11px] font-semibold uppercase tracking-wider text-base-content/50">Biomed in charge</p>
                                        <p class="text-sm font-semibold text-base-content mt-0.5">{{ $report->biomed_incharge }}</p>
                                        @if ($report->biomed_email)
                                            <p class="text-xs text-base-content/60">{{ $report->biomed_email }}</p>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endif
                    </section>
                </div>

                <div class="space-y-5">
                    {{-- ── Service visit ── --}}
                    <section class="rounded-2xl bg-base-100 border border-base-300/70 shadow-sm p-5">
                        <h3 class="text-[15px] font-semibold text-base-content">Service visit</h3>
                        <dl class="mt-3 space-y-2.5 text-sm">
                            <div class="flex justify-between gap-3"><dt class="text-base-content/50">Engineer</dt><dd class="font-medium text-end">{{ $report->user?->name ?? '—' }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-base-content/50">Logged in</dt><dd class="font-medium text-end">{{ $report->login_date?->format('M j, Y') ?? '—' }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-base-content/50">Started</dt><dd class="font-medium text-end">{{ $report->service_start_at?->format('M j, g:i A') ?? '—' }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-base-content/50">Ended</dt><dd class="font-medium text-end">{{ $report->service_end_at?->format('M j, g:i A') ?? '—' }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-base-content/50">Logged out</dt><dd class="font-medium text-end">{{ $report->logout_date?->format('M j, Y') ?? '—' }}</dd></div>
                            <div class="flex justify-between gap-3 border-t border-base-300/60 pt-2.5">
                                <dt class="text-base-content/50">Total time</dt>
                                <dd class="font-bold text-base">
                                    @if ($report->total_minutes)
                                        {{ intdiv($report->total_minutes, 60) }}h {{ $report->total_minutes % 60 }}m
                                    @else
                                        —
                                    @endif
                                </dd>
                            </div>
                        </dl>
                    </section>

                    {{-- ── Equipment ── --}}
                    <section class="rounded-2xl bg-base-100 border border-base-300/70 shadow-sm p-5">
                        <h3 class="text-[15px] font-semibold text-base-content">Equipment</h3>
                        <dl class="mt-3 space-y-2.5 text-sm">
                            <div class="flex justify-between gap-3"><dt class="text-base-content/50">Serial number</dt><dd class="font-medium text-end font-mono text-xs">{{ $report->serial_number ?? '—' }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-base-content/50">Software</dt><dd class="font-medium text-end font-mono text-xs">{{ $report->software_version ?? '—' }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-base-content/50">Ticket</dt><dd class="font-medium text-end font-mono text-xs">{{ $report->monday_ticket_id }}</dd></div>
                        </dl>
                    </section>

                    {{-- ── Signatures ── --}}
                    <section class="rounded-2xl bg-base-100 border border-base-300/70 shadow-sm p-5">
                        <div class="flex items-center justify-between">
                            <h3 class="text-[15px] font-semibold text-base-content">Signatures</h3>
                            <span class="text-xs text-base-content/50">{{ $sigCount }} of 3 collected</span>
                        </div>
                        <div class="space-y-2.5 mt-3">
                            @foreach (['tsp' => 'Field service engineer', 'customer' => 'Customer', 'biomed' => 'Biomed'] as $role => $label)
                                <div class="rounded-xl border border-base-300/70 overflow-hidden">
                                    <div class="px-3 py-1.5 bg-base-200/60 flex items-center justify-between">
                                        <span class="text-[11px] font-semibold uppercase tracking-wider text-base-content/60">{{ $label }}</span>
                                        @if (! empty($signatures[$role]))
                                            <span class="text-[10px] font-semibold text-success">Signed</span>
                                        @endif
                                    </div>
                                    @if (! empty($signatures[$role]))
                                        <img src="{{ $signatures[$role] }}" alt="{{ $label }} signature" class="w-full h-20 object-contain bg-white">
                                    @else
                                        <div class="px-3 py-4 text-center text-xs text-base-content/40 bg-base-200/40">Not collected</div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
