<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tsp;

use App\Actions\SubmitServiceReport;
use App\Actions\SyncPendingTsrReports;
use App\Http\Controllers\Concerns\AssertsTicketAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreServiceReportRequest;
use App\Models\ServiceReport;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\MondayClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Service reports (TSR) — Phase 6 (offline-first).
 *
 * The form is a Livewire component on the page; this controller is
 * the JSON endpoint the Livewire submit AND the offline JS queue both
 * post to. It does NOT talk to Monday directly — that is the
 * drainer's job (see SyncPendingTsrReports).
 *
 * The previous synchronous version of this controller has been
 * replaced: the old `MondayClient::createServiceReportItem` call
 * has been moved to the drainer. The drainer runs:
 *   - on the `online` window event (the JS posts to `sync()`)
 *   - on `schedule:run` every 5 minutes
 *   - manually from the TSP's "pending sync" badge
 */
class ServiceReportController extends Controller
{
    use AssertsTicketAccess;

    /**
     * Read-only detail view of a single service report.
     */
    public function show(int $id): View
    {
        $report = ServiceReport::with('user')->findOrFail($id);
        $this->authorizeTicketAccess(auth()->user(), $this->loadMondayTicket($report->monday_ticket_id));

        // Human ticket label for the header (TICKET-00081, with a
        // numeric fallback if Monday can't resolve the name).
        $monday = app(MondayClient::class);
        $ticketName = $monday->ticketName((int) $report->monday_ticket_id)
            ?? ('#' . $report->monday_ticket_id);

        // Signature previews. The PNGs live on the private local
        // disk, so the view fetches them through the same signed,
        // time-limited URL Monday uses (10-min expiry is plenty for
        // a page view; regenerate on each load).
        $signatures = [];
        foreach (['tsp' => $report->tsp_signature_path, 'customer' => $report->customer_signature_path, 'biomed' => $report->biomed_signature_path] as $role => $path) {
            $signatures[$role] = ($path !== null && \Illuminate\Support\Facades\Storage::disk('local')->exists($path))
                ? \Illuminate\Support\Facades\URL::temporarySignedRoute(
                    'signatures.show',
                    now()->addMinutes(60),
                    ['localId' => $report->local_id, 'role' => $role],
                    absolute: false,
                )
                : null;
        }

        return view('tsp.service-report-show', [
            'report'     => $report,
            'user'       => auth()->user(),
            'ticketName' => $ticketName,
            'signatures' => $signatures,
        ]);
    }

    /**
     * Render the TSR create form as a full page (not a modal).
     *
     * The previous implementation embedded the Livewire TSR form in a
     * Bootstrap 5 modal on the ticket detail page; that pattern is
     * fragile (the Bootstrap bundle must load on a page that is
     * otherwise Tailwind/DaisyUI only) and easy to break with a CSP
     * or CDN failure. Rendering the form as a real page is simpler,
     * more accessible, and shares the same view the Livewire modal
     * was wrapping.
     */
    public function create(string $id): View
    {
        $user = auth()->user();
        $item = $this->loadMondayTicket($id);
        $this->authorizeTicketAccess($user, $item);

        return view('tsp.service-report.create', [
            'ticket' => $item,
            'user'   => $user,
        ]);
    }

    /**
     * Persist a new service report. Always returns 200 if the payload
     * is valid (even when monday sync is queued for later) so the
     * offline JS queue can safely retry on 5xx without flooding the
     * log with 4xx.
     */
    public function store(
        StoreServiceReportRequest $request,
        string $id,
        SubmitServiceReport $action,
    ): JsonResponse|RedirectResponse {
        $user = auth()->user();
        $item = $this->loadMondayTicket($id);
        $this->authorizeTicketAccess($user, $item);

        // Auto-aggregate total minutes from time_entries on this ticket
        // by anyone. The local table is the audit log so we sum across
        // all users — even if multiple TSPs worked the ticket.
        $totalSeconds = (int) TimeEntry::where('monday_ticket_id', $id)
            ->where('status', 'closed')
            ->sum('accumulated_seconds');
        $totalMinutes = $totalSeconds > 0 ? (int) round($totalSeconds / 60) : null;

        $dto = $request->toDto();

        try {
            $report = $action->execute($user, $dto);
        } catch (InvalidArgumentException $e) {
            // Signature blob failed isValid() (bad mime, empty pad, or
            // a queued payload from a form-state that lost its canvas
            // data). Treat as a 422 so the offline queue's drain() loop
            // drops the item instead of retrying forever every 60s.
            // LOG_LEVEL is set to "error" in this env, so we use
            // Log::error here even though "warning" would be more
            // semantically correct — the action's own ERROR log is
            // already filtered out for the same reason.
            Log::error('TSR submission rejected — invalid payload', [
                'ticket'     => $id,
                'local_id'   => $dto->localId,
                'reason'     => $e->getMessage(),
            ]);

            if ($request->wantsJson() || $request->isJson()) {
                return response()->json([
                    'ok'       => false,
                    'reason'   => 'invalid_payload',
                    'message'  => $e->getMessage(),
                    'local_id' => $dto->localId,
                ], 422);
            }

            throw ValidationException::withMessages([
                'signatures' => $e->getMessage(),
            ]);
        }

        $report->total_minutes = $totalMinutes;
        $report->save();

        if ($request->wantsJson() || $request->isJson()) {
            return response()->json([
                'ok'          => true,
                'local_id'    => $report->local_id,
                'id'          => (int) $report->id,
                'sync_state'  => $report->sync_state?->value,
                'total_minutes' => $totalMinutes,
                'redirect'    => route('tsp.tickets.show', ['id' => $id]),
            ], 200);
        }

        return redirect()
            ->route('tsp.tickets.show', ['id' => $id])
            ->with('tsr.saved', true);
    }

    /**
     * Offline drainer entrypoint. The JS layer POSTs here on the
     * `online` event. Returns the sync stats so the badge component
     * can re-render its counts.
     */
    public function sync(SyncPendingTsrReports $drain): JsonResponse
    {
        $stats = $drain->execute();

        return response()->json(['ok' => true] + $stats, 200);
    }

    /**
     * Read-only sync state for a single ticket. The form's sticky
     * bar polls this every 5s so the "Queued / Syncing / Synced /
     * Error" pill updates in real time without a full page reload.
     *
     * Returns:
     *   { "pending": N, "syncing": N, "synced": N, "error": N,
     *     "last_error": "...", "last_synced_at": "iso8601" }
     *
     * Opportunistic drain: on each poll, if there's at least one
     * 'pending' row for this ticket we fire a quick drain pass
     * inline (best-effort, errors swallowed). This is the
     * primary sync path now that
     * `SubmitServiceReport::$syncAfterCommit` defaults to false —
     * the user gets an instant "saved" toast and the Monday
     * writes happen within 5s of the next poll, without making
     * the user wait through 3+ API round-trips.
     *
     * Never throws — the bar is purely cosmetic and we don't want
     * a transient 500 to blank the page.
     */
    public function status(
        string $id,
        SyncPendingTsrReports $drain = null,
    ): JsonResponse {
        try {
            $counts = ServiceReport::query()
                ->where('monday_ticket_id', $id)
                ->selectRaw('sync_state, COUNT(*) as c')
                ->groupBy('sync_state')
                ->pluck('c', 'sync_state')
                ->toArray();

            $lastErrored = ServiceReport::query()
                ->where('monday_ticket_id', $id)
                ->where('sync_state', \App\Enums\SyncState::Error->value)
                ->orderByDesc('updated_at')
                ->first(['sync_error', 'updated_at']);

            $lastSynced = ServiceReport::query()
                ->where('monday_ticket_id', $id)
                ->where('sync_state', \App\Enums\SyncState::Synced->value)
                ->orderByDesc('mirrored_to_monday_at')
                ->first(['mirrored_to_monday_at']);

            // Best-effort drain. Bounded to ONE attempt per poll
            // so a flaky Monday can't drag the response out — the
            // 5s poll cadence already gives us a natural retry.
            $drainedNow = 0;
            $pendingForThisTicket = (int) ($counts[\App\Enums\SyncState::Pending->value] ?? 0);
            if ($pendingForThisTicket > 0 && $drain !== null) {
                try {
                    $oldest = ServiceReport::query()
                        ->where('monday_ticket_id', $id)
                        ->where('sync_state', \App\Enums\SyncState::Pending->value)
                        ->orderBy('created_at')
                        ->first();
                    if ($oldest) {
                        $stats = $drain->syncOneRow($oldest);
                        $drainedNow = (int) ($stats['succeeded'] ?? 0);

                        // Refresh counts in the response so the
                        // pill animates ◌ → ✓ on the same poll
                        // that did the work.
                        if ($drainedNow > 0) {
                            $counts = ServiceReport::query()
                                ->where('monday_ticket_id', $id)
                                ->selectRaw('sync_state, COUNT(*) as c')
                                ->groupBy('sync_state')
                                ->pluck('c', 'sync_state')
                                ->toArray();

                            $lastSynced = ServiceReport::query()
                                ->where('monday_ticket_id', $id)
                                ->where('sync_state', \App\Enums\SyncState::Synced->value)
                                ->orderByDesc('mirrored_to_monday_at')
                                ->first(['mirrored_to_monday_at']);
                        }
                    }
                } catch (\Throwable $e) {
                    // Swallow — the status response must always
                    // be fast and always be 200. The row stays
                    // 'pending' and the next poll retries.
                    Log::info('Opportunistic TSR drain on /status failed (will retry)', [
                        'ticket' => $id,
                        'error'  => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'ok'              => true,
                'pending'         => (int) ($counts[\App\Enums\SyncState::Pending->value]  ?? 0),
                'syncing'         => (int) ($counts[\App\Enums\SyncState::Syncing->value]  ?? 0),
                'synced'          => (int) ($counts[\App\Enums\SyncState::Synced->value]   ?? 0),
                'error'           => (int) ($counts[\App\Enums\SyncState::Error->value]    ?? 0),
                'drained_now'     => $drainedNow,
                'last_error'      => $lastErrored?->sync_error,
                'last_synced_at'  => optional($lastSynced?->mirrored_to_monday_at)?->toIso8601String(),
            ], 200);
        } catch (\Throwable $e) {
            Log::warning('TSR status fetch failed', [
                'ticket' => $id,
                'error'  => $e->getMessage(),
            ]);
            return response()->json([
                'ok'      => false,
                'error'   => $e->getMessage(),
            ], 200);
        }
    }
}
