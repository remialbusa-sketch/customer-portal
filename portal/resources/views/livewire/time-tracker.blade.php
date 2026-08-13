<?php

use App\Services\MondayClient;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component
{
    public int $ticketId;
    public string $currentUserName;
    public string $currentUserRole;

    /**
     * Mirror of Monday's time_tracking column, re-shaped to match the
     * old "active" entry so the existing Alpine `timeTracker` factory
     * keeps working without modification.
     *
     * Shape (when running):
     *   [
     *     'status'          => 'open',
     *     'elapsed_seconds' => int, // Monday's `duration` so far
     *     'started_at'      => ?iso, // from Monday's `startDate` unix
     *     'monday_ticket_id'=> int,
     *   ]
     * Null when the timer is stopped on Monday's side.
     */
    public ?array $active = null;

    /**
     * Total accumulated time on the ticket, in seconds. Sourced from
     * Monday's `duration` field, which is the sum of every closed
     * segment plus the in-progress segment (when running).
     */
    public int $totalSeconds = 0;

    /**
     * Last error message from a failed Monday read. Lets the UI show
     * a quiet "couldn't reach Monday" hint without breaking the page.
     */
    public ?string $errorMessage = null;

    public function mount(
        int $ticketId,
        string $currentUserName,
        string $currentUserRole,
        ?array $active = null,
        int $totalSeconds = 0,
    ): void {
        $this->ticketId        = $ticketId;
        $this->currentUserName = $currentUserName;
        $this->currentUserRole = $currentUserRole;
        $this->active          = $active;
        $this->totalSeconds    = $totalSeconds;
    }

    /**
     * Read the current time_tracking value from Monday and update
     * the public props. The Blade template wires `wire:poll.30s` to
     * call this on a 30-second cadence; the Alpine 1Hz ticker fills
     * in the smooth "current session" ticks between polls.
     *
     * Best-effort: a failed Monday read does NOT throw — we just
     * stash the message in `errorMessage` and leave the previous
     * values in place so the user doesn't see a flicker.
     */
    public function refresh(MondayClient $monday): void
    {
        try {
            $tt = $monday->readTimeTracking($this->ticketId);
        } catch (\Throwable $e) {
            $this->errorMessage = 'Could not load time tracking from Monday.';
            return;
        }

        $this->errorMessage = null;
        $this->totalSeconds = (int) $tt['duration'];
        $this->active       = $tt['running']
            ? [
                'status'          => 'open',
                'elapsed_seconds' => (int) $tt['duration'],
                'started_at'      => $tt['start_date']
                    ? gmdate('Y-m-d\TH:i:s\Z', (int) $tt['start_date'])
                    : null,
                'monday_ticket_id'=> $this->ticketId,
            ]
            : null;

        $this->dispatch('time-tracker-state', [
            'active' => $this->active,
            'total'  => $this->totalSeconds,
        ]);
    }
};
?>

<div
    wire:poll.30s="refresh"
    x-data="timeTracker({
        ticketId: @js((int) $ticketId),
        active:   @js($active ?: null),
        total:    @js((int) $totalSeconds),
    })"
    x-init="
        init();
        // Keep Alpine in sync with Livewire's re-rendered values
        $watch('$wire.active', (v) => { active = v; recompute(); });
        $watch('$wire.totalSeconds', (v) => { total = Number(v || 0); recompute(); });
    "
    @time-tracker-state.window="onState($event.detail)"
    class="bg-base-100 shadow sm:rounded-2xl p-6 border border-base-300/70"
>
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-base font-semibold text-base-content">Time tracker</h3>
        <div class="text-xs text-base-content/60 flex items-center gap-2">
            <template x-if="active">
                <span class="inline-flex items-center gap-1 text-warning">
                    <span class="h-2 w-2 rounded-full bg-warning animate-pulse"></span>
                    <span x-text="active.status === 'open' ? 'Running on Monday' : 'Paused on Monday'"></span>
                </span>
            </template>
            <template x-if="!active">
                <span class="text-base-content/50">No active timer</span>
            </template>
        </div>
    </div>

    <div class="rounded-xl bg-base-200/50 p-4 border border-base-300/70">
        <div class="flex items-center justify-between">
            <div class="text-xs uppercase tracking-wider text-base-content/60">Total on this ticket</div>
            <div class="text-xs text-base-content/60" x-text="activeLabel"></div>
        </div>
        <div class="text-2xl font-bold text-base-content mt-1" x-text="formatTotal(total)">—</div>
        <template x-if="active">
            <div class="mt-2 pt-2 border-t border-base-300/50 flex items-baseline justify-between gap-3">
                <span class="text-xs uppercase tracking-wider text-base-content/60">Current session</span>
                <span class="text-sm font-semibold text-base-content" x-text="formatElapsed(elapsedSeconds)">0m 00s</span>
            </div>
        </template>
    </div>

    {{-- The time tracker is now a read-only reflection of Monday's
         `duration_mm4hesrz` column. To start / pause / stop a session,
         use the native time_tracking widget on the Monday ticket —
         the portal mirrors its value automatically on a 30s poll. --}}
    <div class="mt-4 text-xs text-base-content/60 flex flex-wrap items-center gap-x-3 gap-y-1">
        <span class="inline-flex items-center gap-1">
            <svg class="h-3.5 w-3.5 text-base-content/50" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm.75-13a.75.75 0 00-1.5 0v5c0 .2.08.39.22.53l3 3a.75.75 0 101.06-1.06L10.75 9.69V5z" clip-rule="evenodd" />
            </svg>
            Mirrored from Monday · auto-refreshes every 30s
        </span>

        @if ($errorMessage)
            <span class="text-warning inline-flex items-center gap-1">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.74 4a2 2 0 00-3.48 0l-7.1 13.25A2 2 0 005 19z"/></svg>
                {{ $errorMessage }}
            </span>
        @endif
    </div>
</div>
