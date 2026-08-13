<x-app-layout>
    <x-slot:header>
        <div class="flex flex-col gap-1">
            <span class="text-xs font-semibold uppercase tracking-wider text-base-content/60">Service Report</span>
            <span class="text-2xl font-semibold text-base-content">{{ $ticket['name'] ?: ('Ticket #' . ($ticket['id'] ?? '—')) }}</span>
            @php $subject = $ticket['column_values']['text_mm5c1w5n']['text'] ?: ($ticket['name'] ?? null); @endphp
            @if (!empty($subject))
                <span class="text-sm text-base-content/70">{{ $subject }}</span>
            @endif
        </div>
    </x-slot:header>

    <div class="max-w-6xl mx-auto">
        <div class="mb-4 flex justify-end">
            <a
                href="{{ route('tsp.tickets.show', ['id' => $ticket['id'] ?? 0]) }}"
                class="btn btn-ghost btn-sm gap-1.5"
                wire:navigate
            >
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                Back to ticket
            </a>
        </div>

        <livewire:tsp.tickets.create-service-report :ticket-number="(string) ($ticket['id'] ?? '')" />
    </div>

    @include('partials.sw-register')
</x-app-layout>
