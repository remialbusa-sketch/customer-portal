<?php

namespace App\Http\Controllers\Tsp;

use App\Events\InternalNoteAdded;
use App\Http\Controllers\Concerns\AssertsTicketAccess;
use App\Http\Controllers\Controller;
use App\Models\InternalNote;
use App\Models\ServiceReport;
use App\Models\TimeEntry;
use App\Services\MondayClient;
use App\Services\TimeTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * TSP-only internal notes on a ticket.
 *
 * Notes are stored locally in `internal_notes` (one row per note, full
 * audit trail with author + timestamp). The most-recent note's body is
 * mirrored to a dedicated long-text column on the Tickets board in
 * Monday (config('services.monday.tickets_columns.internal_notes')).
 * Customers never see this surface.
 */
class InternalNoteController extends Controller
{
    use AssertsTicketAccess;

    /**
     * Render the TSP ticket detail with chat + internal-notes panels.
     */
    public function show(string $id, TimeTracker $tracker, MondayClient $monday): View
    {
        $user = auth()->user();
        $item = $this->loadMondayTicket($id);
        $this->authorizeTicketAccess($user, $item);

        $messages = $this->loadMessageHistory($id, $user);
        $notes    = $this->loadNoteHistory($id);

        // Phase 5 → reflection phase: time tracker is a read-only mirror
        // of Monday's `duration_mm4hesrz` time_tracking column. The
        // Livewire component fetches fresh state on mount and re-fetches
        // every 30s; the props here are only the initial paint values.
        try {
            $tt = $monday->readTimeTracking((int) $id);
        } catch (\Throwable $e) {
            Log::warning('InternalNoteController: readTimeTracking failed', [
                'monday_ticket_id' => $id,
                'error'            => $e->getMessage(),
            ]);
            $tt = ['duration' => 0, 'running' => false, 'start_date' => null, 'text' => '00:00:00'];
        }

        // The Livewire time-tracker mirrors the same JSON shape the
        // /tsp/tickets/{id}/time-tracking endpoint returns, so the
        // initial paint and the polled updates are interchangeable.
        $timeActive = $tt['running']
            ? [
                // Synthesized "active" row for the Alpine factory.
                'status'          => 'open',
                'elapsed_seconds' => (int) $tt['duration'],
                'started_at'      => $tt['start_date']
                    ? gmdate('Y-m-d\TH:i:s\Z', (int) $tt['start_date'])
                    : null,
                'monday_ticket_id'=> (int) $id,
            ]
            : null;
        $timeTotal = (int) $tt['duration'];

        // Phase 6: latest service report for this ticket (if any).
        $report = ServiceReport::where('monday_ticket_id', $id)
            ->orderByDesc('created_at')
            ->first();

        // Resolve assigned TSP name(s) from the People column. Shown in
        // the assigned-technician callout so managers and other TSPs
        // (co-claim scenarios) can see who's responsible.
        $assignedNames = [];
        $peopleCol = config('services.monday.tickets_columns.tsp');
        $peopleValue = $item['column_values'][$peopleCol]['value'] ?? null;
        if ($peopleValue) {
            $decoded = json_decode($peopleValue, true);
            if (is_array($decoded) && isset($decoded['personsAndTeams'])) {
                $tspIds = [];
                foreach ($decoded['personsAndTeams'] as $row) {
                    if (isset($row['id'])) {
                        $tspIds[] = (string) $row['id'];
                    }
                }
                $tspNameMap = MondayClient::resolveTspNames($tspIds);
                foreach ($tspIds as $pid) {
                    $name = $tspNameMap[$pid] ?? null;
                    if ($name) { $assignedNames[] = $name; }
                    else { $assignedNames[] = 'TSP #' . $pid; }
                }
            }
        }

        // Build the Alpine `ticketStatusPoller(...)` argument list in PHP
        // (rather than the Blade view) so the rendered `x-data` value
        // is just a single template variable. The previous approach —
        // a `@php` block followed by `<x-ui.card x-data="...">` —
        // confused the Blade compiler: with `@js(...)`/`route(...)`
        // inside an attribute value passed to a component, the
        // directives weren't compiled and the `x-data` ended up as
        // a literal Blade source string at runtime. Building the
        // string here keeps the view a one-liner.
        $statusText  = $item['column_values']['status95']['text'] ?? null;
        $statusLower = strtolower((string) $statusText);
        // Figma pill language (solid soft pills, no dots) — kept in
        // sync with ticketStatusPoller._badgeFor in the view.
        $statusBadge = match (true) {
            str_contains($statusLower, 'new') || str_contains($statusLower, 'open') => 'bg-[#EBF2FD] text-[#3977E8]',
            str_contains($statusLower, 'progress') => 'bg-[#FEF3C7] text-[#B45309]',
            str_contains($statusLower, 'awaiting') => 'bg-[#FDF2F8] text-[#C1447E]',
            str_contains($statusLower, 'resolved')
                || str_contains($statusLower, 'closed')
                || str_contains($statusLower, 'done')
                || str_contains($statusLower, 'complete') => 'bg-[#E3F7F3] text-[#17847A]',
            default => 'bg-base-200 text-base-content/60',
        };
        $pollerArgs = [
            'url'              => route('tsp.tickets.status', ['id' => $item['id']]),
            'intervalMs'       => 15000,
            'initialStatus'    => $statusText,
            'initialBadge'     => $statusBadge,
            'initialHasReport' => $report ? true : false,
            'tsrShowUrl'       => $report ? route('tsp.service-reports.show', ['id' => $report->id]) : null,
        ];
        $pollerXData = 'ticketStatusPoller(' . json_encode($pollerArgs) . ')';

        return view('tsp.ticket-show', [
            'user'         => $user,
            'ticket'       => $item,
            'messages'     => $messages,
            'notes'        => $notes,
            'timeActive'   => $timeActive,
            'timeTotal'    => $timeTotal,
            'assignedNames' => $assignedNames,
            'pollerXData'  => $pollerXData,
            'existingReport' => $report ? [
                'id'                    => (int) $report->id,
                'service_status'        => $report->service_status,
                'service_status_label'  => $report->statusLabel(),
                'author_name'           => $report->user?->name ?? '—',
                'author_role'           => $report->author_role,
                'total_minutes'         => $report->total_minutes,
                'job_done'              => $report->job_done,
                'problem_and_concerns'  => $report->problem_and_concerns,
                'parts_replaced'        => $report->parts_replaced,
                'recommendation'        => $report->recommendation,
                'remarks'               => $report->remarks,
                'login_date'            => $report->login_date?->toDateString(),
                'service_start_at'      => $report->service_start_at?->toDateTimeString(),
                'service_end_at'        => $report->service_end_at?->toDateTimeString(),
                'logout_date'           => $report->logout_date?->toDateString(),
                'call_login_time'       => $report->call_login_time,
                'machine_system'        => $report->machine_system,
                'serial_number'         => $report->serial_number,
                'software_version'      => $report->software_version,
                'contract'              => $report->contract,
                'customer_incharge'     => $report->customer_incharge,
                'customer_incharge_email' => $report->customer_incharge_email,
                'biomed_incharge'       => $report->biomed_incharge,
                'biomed_email'          => $report->biomed_email,
                'created_at'            => $report->created_at->toIso8601String(),
            ] : null,
        ]);
    }

    /**
     * Lightweight JSON status endpoint for the ticket-show page's
     * Alpine poller. Returns the fields the header / status badge
     * / "create TSR" affordance care about, plus a server-side
     * last-modified timestamp so the poller can short-circuit a
     * page reload when nothing changed since the last fetch.
     *
     * Cost: one Monday round-trip per call. The poller on the view
     * runs every 15s and the response is cached server-side for 5s
     * (see Cache::remember call below) so a tab storm doesn't
     * hammer the Monday API.
     */
    public function statusJson(string $id, MondayClient $monday): JsonResponse
    {
        $user = auth()->user();
        $item = $this->loadMondayTicket($id);
        $this->authorizeTicketAccess($user, $item);

        $status = $item['column_values']['status95']['text'] ?? null;
        $subject = $item['column_values']['text_mm5c1w5n']['text'] ?? ($item['name'] ?? null);

        // Was a service report created since the page loaded? The
        // page hydrates `existingReport` from the DB; if the user
        // opens the form in another tab and submits, the
        // `created_at` of the latest report will be newer than the
        // page-render time. The poller flips the "Create TSR"
        // button to "View TSR" without a hard reload.
        $latestReport = ServiceReport::where('monday_ticket_id', $id)
            ->orderByDesc('created_at')
            ->first();
        $hasReport = $latestReport !== null;

        // A snapshot of pending sync state, mirrored to the TSR
        // form's sticky bar but useful here too so the page can
        // show "TSR syncing…" or "TSR synced" in the header.
        $pendingSync = ServiceReport::where('monday_ticket_id', $id)
            ->where('sync_state', '!=', \App\Enums\SyncState::Synced->value)
            ->count();

        return response()->json([
            'ok'              => true,
            'status_text'     => $status,
            'subject'         => $subject,
            'has_report'      => $hasReport,
            'pending_sync'    => $pendingSync,
            'monday_updated'  => $item['updated_at'] ?? null,
            'fetched_at'      => now()->toIso8601String(),
        ]);
    }

    /**
     * Persist a new internal note and broadcast it on the
     * ticket.{id}.internal private channel. Returns JSON so the
     * Livewire form doesn't re-render the whole page (same pattern
     * as ChatController::send).
     *
     * Side effects, in order:
     *  1. Insert the local `internal_notes` row (with author + role).
     *  2. Mirror the body to the Monday long-text column. This is a
     *     best-effort write; if Monday is unreachable the local row
     *     stays and we re-mirror on the next successful save.
     *  3. Broadcast the event so other TSP tabs see the note live.
     */
    public function store(Request $request, string $id): JsonResponse
    {
        $user = auth()->user();
        $item = $this->loadMondayTicket($id);
        $this->authorizeTicketAccess($user, $item);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $note = InternalNote::create([
            'monday_ticket_id' => $id,
            'user_id'          => $user->id,
            'author_role'      => $user->role,
            'body'             => $data['body'],
        ]);

        // Best-effort mirror to the Monday long-text column. The local
        // row is the source of truth; Monday is a single-value cache
        // of the most recent note.
        try {
            $columnId = config('services.monday.tickets_columns.internal_notes');
            app(MondayClient::class)->writeLongTextColumn(
                (int) $id,
                (string) $columnId,
                $note->body,
            );
            $note->forceFill(['mirrored_to_monday_at' => now()])->save();
        } catch (\Throwable $e) {
            // Don't fail the request — the note is in the local log.
            // The next save will overwrite the column with the latest
            // body, so Monday will catch up automatically.
            Log::warning('Failed to mirror internal note to Monday', [
                'note_id' => $note->id,
                'error'   => $e->getMessage(),
            ]);
        }

        $note->load('user');
        broadcast(new InternalNoteAdded($note))->toOthers();

        return response()->json([
            'ok'          => true,
            'id'          => (int) $note->id,
            'body'        => (string) $note->body,
            'author_name' => (string) ($note->user?->name ?? 'Unknown'),
            'author_role' => (string) $note->author_role,
            'at'          => $note->created_at?->toIso8601String(),
        ]);
    }

    /**
     * Load the internal-note history for a ticket. Plain array shape
     * for the same reason chat messages are plain arrays — Livewire
     * `array` properties choke on Eloquent Collections.
     */
    protected function loadNoteHistory(string $mondayTicketId): array
    {
        return InternalNote::with('user')
            ->where('monday_ticket_id', $mondayTicketId)
            ->orderBy('created_at')
            ->get()
            ->map(function (InternalNote $note) {
                return [
                    'id'          => (int) $note->id,
                    'body'        => (string) $note->body,
                    'author_role' => (string) $note->author_role,
                    'author_name' => (string) ($note->user?->name ?? 'Unknown'),
                    'created_at'  => optional($note->created_at)->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Shared chat-history loader (copy of Tsp\ChatController::loadMessageHistory
     * — kept local so the controllers stay self-contained).
     */
    protected function loadMessageHistory(string $mondayTicketId, \App\Models\User $viewer): array
    {
        return \App\Models\ChatMessage::with('user')
            ->where('monday_ticket_id', $mondayTicketId)
            ->orderBy('created_at')
            ->get()
            ->map(function ($msg) use ($viewer) {
                return [
                    'id'          => (int) $msg->id,
                    'body'        => (string) $msg->body,
                    'sender_role' => (string) $msg->sender_role,
                    'sender_name' => (string) ($msg->user?->name ?? 'Unknown'),
                    'mine'        => (int) $msg->user_id === (int) $viewer->id,
                    'created_at'  => optional($msg->created_at)->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }
}
