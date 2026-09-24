<x-app-layout>
    <x-slot:title>Service Report — {{ $ticketName }}</x-slot:title>

    <x-slot:header>
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <p class="text-xs font-semibold tracking-widest uppercase text-base-content/60">Service Report</p>
                <h2 class="font-semibold text-2xl text-base-content leading-tight mt-0.5">
                    {{ $ticketName }}
                </h2>
                <p class="text-sm text-base-content/60 mt-1">
                    Submitted {{ $report->created_at?->format('M j, Y g:i A') ?? '—' }}
                    @if ($report->client_submitted_at)
                        · from device {{ $report->client_submitted_at->format('M j, Y g:i A') }}
                    @endif
                </p>
            </div>
            <div class="flex items-center gap-2">
                <span class="badge badge-ghost gap-1.5 font-medium">
                    <span class="w-1.5 h-1.5 rounded-full bg-base-content/40"></span>
                    {{ $report->statusLabel() }}
                </span>
                @php
                    $syncTone = match ($report->sync_state?->value ?? '') {
                        'synced'   => ['badge-success', 'Synced to Monday'],
                        'pending'  => ['badge-warning', 'Queued for sync'],
                        'syncing'  => ['badge-info', 'Syncing…'],
                        'error'    => ['badge-error', 'Sync failed'],
                        default    => ['badge-ghost', 'Not synced'],
                    };
                @endphp
                <span class="badge {{ $syncTone[0] }} gap-1.5 font-medium">
                    {{ $syncTone[1] }}
                </span>
                <a href="{{ route('tsp.tickets.show', ['id' => $report->monday_ticket_id]) }}"
                   class="btn btn-ghost btn-sm gap-1.5" wire:navigate>
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    Back to ticket
                </a>
            </div>
        </div>
    </x-slot:header>

    <div class="max-w-5xl mx-auto space-y-4">

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

        {{-- ── Service window + equipment ── --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="card bg-base-100 border border-base-300/70 shadow-soft rounded-2xl p-5">
                <h3 class="text-sm font-semibold text-base-content mb-3 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-md bg-accent/10 text-accent flex items-center justify-center" aria-hidden="true">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </span>
                    Service window
                </div>
                <dl class="space-y-2.5 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-base-content/60">Logged in</dt><dd class="font-medium text-end">{{ $report->login_date?->format('M j, Y') ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-base-content/60">Service start</dt><dd class="font-medium text-end">{{ $report->service_start_at?->format('M j, Y g:i A') ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-base-content/60">Service end</dt><dd class="font-medium text-end">{{ $report->service_end_at?->format('M j, Y g:i A') ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-base-content/60">Logged out</dt><dd class="font-medium text-end">{{ $report->logout_date?->format('M j, Y') ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3 border-t border-base-300/60 pt-2.5">
                        <dt class="text-base-content/60">Total time</dt>
                        <dd class="font-bold">
                            @if ($report->total_minutes)
                                {{ intdiv($report->total_minutes, 60) }}h {{ $report->total_minutes % 60 }}m
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="card bg-base-100 border border-base-300/70 shadow-soft rounded-2xl p-4">
                <div class="flex items-center gap-2 mb-3">
                    <span class="w-6 h-6 rounded-md bg-primary/10 text-primary flex items-center justify-center" aria-hidden="true">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
                    </span>
                    <h3 class="text-sm font-semibold">Equipment</h3>
                </div>
                <dl class="text-sm space-y-2.5">
                    <div class="flex justify-between gap-3"><dt class="text-base-content/60">Engineer</dt><dd class="font-medium text-end">{{ $report->user?->name ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-base-content/60">Serial number</dt><dd class="font-medium text-end font-mono text-xs">{{ $report->serial_number ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-base-content/60">Software version</dt><dd class="font-medium text-end font-mono text-xs">{{ $report->software_version ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-base-content/60">Monday ticket</dt><dd class="font-medium text-end font-mono text-xs">{{ $report->monday_ticket_id }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-base-content/60">Local ID</dt><dd class="text-end font-mono text-[10px] text-base-content/50 break-all max-w-[12rem]">{{ $report->local_id ?? '—' }}</dd></div>
                </dl>
            </div>
        </div>

        {{-- ── Work details ── --}}
        <div class="card bg-base-100 border border-base-300/70 shadow-soft rounded-2xl p-5 space-y-4">
            <h3 class="text-sm font-semibold text-base-content flex items-center gap-2">
                <span class="w-6 h-6 rounded-md bg-secondary/10 text-secondary flex items-center justify-center" aria-hidden="true">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </span>
                Work details
            </h3>

            @foreach ([
                'Problem & concerns' => $report->problem_and_concerns,
                'Job done' => $report->job_done,
                'Parts replaced' => $report->parts_replaced,
                'Recommendation' => $report->recommendation,
                'Remarks' => $report->remarks,
            ] as $label => $value)
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-base-content/50 mb-1">{{ $label }}</p>
                    <p class="text-sm text-base-content leading-relaxed whitespace-pre-wrap">{{ $value ?: '—' }}</p>
                </div>
            @endforeach

            @if ($report->customer_incharge || $report->biomed_incharge)
                <div class="border-t border-base-300/60 pt-3 grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                    @if ($report->customer_incharge)
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-base-content/50 mb-0.5">Customer in charge</p>
                            <p class="font-medium">{{ $report->customer_incharge }}</p>
                            @if ($report->customer_incharge_email)
                                <p class="text-xs text-base-content/60">{{ $report->customer_incharge_email }}</p>
                            @endif
                        </div>
                    @endif
                    @if ($report->biomed_incharge)
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-base-content/50 mb-0.5">Biomed in charge</p>
                            <p class="font-medium">{{ $report->biomed_incharge }}</p>
                            @if ($report->biomed_email)
                                <p class="font-medium">{{ $report->biomed_email }}</p>
                            @endif
                        </div>
                    @endif
                </div>
            @endif
        </div>

        {{-- ── Signatures ── --}}
        <div class="card bg-base-100 border border-base-300/70 shadow-soft rounded-2xl p-5">
            <h3 class="text-sm font-semibold text-base-content flex items-center gap-2 mb-4">
                <span class="w-6 h-6 rounded-md bg-primary/10 text-primary flex items-center justify-center" aria-hidden="true">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                </span>
                Signatures
            </h3>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                @foreach (['tsp' => 'Field service engineer', 'customer' => 'Customer', 'biomed' => 'Biomed'] as $role => $label)
                    <div class="rounded-xl border border-base-300/70 overflow-hidden">
                        <div class="px-3 py-2 bg-base-200/60 flex items-center justify-between">
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-base-content/60">{{ $label }}</span>
                        </div>
                        @if (! empty($signatures[$role]))
                            <img src="{{ $signatures[$role] }}" alt="{{ $label }} signature" class="w-full h-24 object-contain bg-white">
                        @else
                            <div class="px-3 py-6 text-center text-xs text-base-content/40 bg-base-200/40">Not collected</div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>