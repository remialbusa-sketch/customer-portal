<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\SyncState;
use App\Models\ServiceReport;
use App\Services\MondayClient;
use App\Support\Monday\MondayColumnIds;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drains the offline queue. Called:
 *   - On the browser's `online` event (the JS layer POSTs to a
 *     dedicated endpoint that triggers this).
 *   - On a cron tick every 5 minutes (catches browser crashes that
 *     prevented the online event from firing).
 *   - Manually by the TSP from the sticky bar's "Sync to Monday"
 *     button on the TSR form.
 *
 * The actual monday.com GraphQL calls live behind `MondayClient` —
 * see `MondayClient::createServiceReportItem`, `attachFile`, and
 * `applyTicketStatusFromServiceStatus` for the mutation bodies.
 */
class SyncPendingTsrReports
{
    public function __construct(
        protected MondayClient $monday,
    ) {
    }

    /**
     * @return array{processed:int, succeeded:int, failed:int}
     */
    public function execute(int $max = 25): array
    {
        $stats = ['processed' => 0, 'succeeded' => 0, 'failed' => 0];

        ServiceReport::query()
            ->whereIn('sync_state', [SyncState::Pending->value, SyncState::Error->value])
            // Partial-success rows: Step 1 (create) finished and
            // the TSR is on Monday, but Step 2 (signatures) failed.
            // Re-running the drainer on these would create a
            // duplicate TSR on Monday. Only Step 2 is retryable,
            // and that's the manual ReuploadSignatures command's
            // job — not the auto-drainer.
            ->where(function ($q) {
                $q->whereNull('monday_tsr_item_id')
                  ->orWhere('monday_tsr_item_id', '');
            })
            ->orderBy('created_at')
            ->limit($max)
            ->get()
            ->each(function (ServiceReport $r) use (&$stats) {
                $stats['processed']++;
                $r->update(['sync_state' => SyncState::Syncing, 'sync_error' => null]);

                try {
                    $this->syncOne($r);
                    $stats['succeeded']++;
                } catch (Throwable $e) {
                    $stats['failed']++;
                    $r->update([
                        'sync_state' => SyncState::Error,
                        'sync_error' => substr($e->getMessage(), 0, 500),
                    ]);
                    Log::error('TSR sync failed', [
                        'local_id' => $r->local_id,
                        'error'    => $e->getMessage(),
                    ]);
                    $this->notifySyncError($r);
                }
            });

        return $stats;
    }

    /**
     * Drain ONE specific row to monday. Used by the immediate-drain
     * path on the Livewire submit so the just-saved TSR (the
     * newest pending row) doesn't get stuck behind an older failed
     * drain in the FIFO queue.
     *
     * The caller is expected to be `SubmitServiceReport::execute()`
     * after the local DB transaction has committed. The row is
     * already in `sync_state='pending'` and `monday_tsr_item_id IS
     * NULL` (fresh row), so this is the safe path — no risk of
     * double-creating the TSR on Monday.
     *
     * Returns the same stats shape as `execute()` but always
     * either 0 or 1 in `succeeded` / `failed` / `processed`.
     *
     * @return array{processed:int, succeeded:int, failed:int}
     */
    public function syncOneRow(ServiceReport $r): array
    {
        $stats = ['processed' => 0, 'succeeded' => 0, 'failed' => 0];

        // Idempotency guard: if a previous attempt already got the
        // TSR onto Monday (monday_tsr_item_id set) we must NOT call
        // createServiceReportItem again. The ReuploadSignatures
        // artisan command is the recovery path for partial-success
        // rows, not this method.
        if (! empty($r->monday_tsr_item_id)) {
            Log::info('syncOneRow: row already has monday_tsr_item_id, skipping', [
                'local_id'          => $r->local_id,
                'monday_tsr_item_id' => $r->monday_tsr_item_id,
            ]);
            return $stats;
        }

        $stats['processed']++;
        $r->update(['sync_state' => SyncState::Syncing, 'sync_error' => null]);

        try {
            $this->syncOne($r);
            $stats['succeeded']++;
        } catch (Throwable $e) {
            $stats['failed']++;
            $r->update([
                'sync_state' => SyncState::Error,
                'sync_error' => substr($e->getMessage(), 0, 500),
            ]);
            Log::error('TSR sync failed (immediate)', [
                'local_id' => $r->local_id,
                'error'    => $e->getMessage(),
            ]);
            $this->notifySyncError($r);
        }

        return $stats;
    }

    /**
     * Bell notification to the owning TSP when a TSR fails to reach
     * Monday. The Notifier dedupes against unread duplicates, so the
     * 5-minute drainer retry cycle can't flood the bell while the
     * row keeps failing.
     */
    protected function notifySyncError(ServiceReport $r): void
    {
        try {
            $label = $this->monday->ticketName((int) $r->monday_ticket_id)
                ?? ('#' . $r->monday_ticket_id);

            app(\App\Services\Notifier::class)->send(
                userId:   (int) $r->user_id,
                type:     \App\Models\Notification::TYPE_TSR_ERROR,
                title:    "TSR sync failed for {$label}",
                body:     substr((string) $r->sync_error, 0, 200),
                url:      '/tsp/service-reports/' . $r->id,
                ticketId: (string) $r->monday_ticket_id,
            );
        } catch (\Throwable $e) {
            // Notification is cosmetic - never mask the sync error.
            Log::warning('TSR sync-error notification failed', [
                'local_id' => $r->local_id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * One row. Order of operations:
     *   1. Create the TSR item on board 5029041107 (BOARD_TSR) with
     *      all the column_values from the DTO. We hand off the whole
     *      payload to MondayClient::createServiceReportItem(), which
     *      does the column-id resolution and date/email/status
     *      shaping for us.
     *   2. Upload the three signature files to Monday.
     *   3. Patch the source ticket's status95 (if the mapper says
     *      there's a change to make). The ticket status flips
     *      automatically based on the TSR's service status.
     *   4. Mark the local row synced.
     */
    protected function syncOne(ServiceReport $r): void
    {
        $status = $r->serviceStatusEnum()
            ?? \App\Enums\ServiceStatus::Open;

        // Step 1: create the TSR item (unless we already created it
        // on a previous run). If the row already has a
        // monday_tsr_item_id, we MUST NOT call createServiceReportItem
        // again — that would create a duplicate TSR on Monday. The
        // re-upload path is the ReuploadSignatures command.
        $tsrItemId = (int) $r->monday_tsr_item_id;
        if ($tsrItemId <= 0) {
            // Item name uses the ticket's NAME (TICKET-00081) rather
            // than the raw Monday item id, so the TSR board reads the
            // same way the portal does. Falls back to the numeric id
            // when the name can't be resolved.
            $ticketLabel = $this->monday->ticketName((int) $r->monday_ticket_id)
                ?? ('#' . $r->monday_ticket_id);

            $result = $this->monday->createServiceReportItem([
                'ticket_item_id'            => (int) $r->monday_ticket_id,
                'item_name'                 => sprintf(
                    'TSR for %s — %s',
                    $ticketLabel,
                    $r->service_start_at?->format('Y-m-d H:i') ?? now()->format('Y-m-d H:i')
                ),
                'service_status'            => $status->value,
                'problem_and_concerns'      => $r->problem_and_concerns,
                'job_done'                  => $r->job_done,
                'parts_replaced'            => $r->parts_replaced,
                'recommendation'            => $r->recommendation,
                'remarks'                   => $r->remarks,
                'serial_number'             => $r->serial_number,
                'software_version'          => $r->software_version,
                'login_date'                => $r->login_date?->toDateString(),
                'service_start'             => $r->service_start_at?->toDateString(),
                'service_end'               => $r->service_end_at?->toDateString(),
                'logout_date'               => $r->logout_date?->toDateString(),
                'tsp_email'                 => $r->user?->email,
                'customer_incharge'         => $r->customer_incharge,
                'customer_incharge_email'   => $r->customer_incharge_email,
                'biomed_incharge'           => $r->biomed_incharge,
                'biomed_email'              => $r->biomed_email,
                'tsp_workwith_person_ids'   => $r->tsp_workwith_person_ids ?? [],
            ]);

            $tsrItemId = (int) ($result['id'] ?? 0);
            if ($tsrItemId <= 0) {
                throw new \RuntimeException('Monday createServiceReportItem did not return an item id');
            }
            $r->monday_tsr_item_id = (string) $tsrItemId;
            $r->save();

            // Safety net: ensure the TSR <-> ticket relation is set. The
            // create_item payload above already includes the relation,
            // but if Monday stripped it during the auto-retry path
            // (itemsNotInConnectedBoards / inactiveItems) the TSR will
            // exist without a back-link. A second chance via
            // change_column_value is cheap and idempotent.
            $this->monday->linkTsrToTicket($tsrItemId, (int) $r->monday_ticket_id);
        }

        // Step 2: upload the three signatures to the TSR item.
        // Each attachFile() reads the local file we stored on submit
        // and POSTs it to Monday's add_file_to_column mutation.
        //
        // We track per-signature results. A partial-success
        // (TSR on Monday, some signatures uploaded, some not) is
        // WORSE than no signatures at all — the user can see the
        // TSR row but the signature columns are inconsistent, and
        // there's no UI affordance to retry. So we throw at the
        // end if any signature failed, and the catch in execute()
        // sets sync_state=error with a clear message. The admin
        // can then run `php artisan monday:reupload-signatures
        // <localId>` to retry just the signatures.
        $cols = config('services.monday.service_report_columns');
        $sigUploads = [
            'tsp'       => $r->tsp_signature_path,
            'customer'  => $r->customer_signature_path,
            'biomed'    => $r->biomed_signature_path,
        ];
        $sigColumns = [
            'tsp'       => $cols['tsp_signature']             ?? MondayColumnIds::TSR_COL_TSP_SIGNATURE,
            'customer'  => $cols['customer_incharge_sig']     ?? MondayColumnIds::TSR_COL_CUSTOMER_INCHARGE_S,
            'biomed'    => $cols['biomed_signature']          ?? MondayColumnIds::TSR_COL_BIOMED_SIGNATURE,
        ];
        $failedSigs = [];
        foreach ($sigUploads as $role => $relPath) {
            if (! $relPath) { continue; }
            $colId = $sigColumns[$role] ?? null;
            if (! $colId) { continue; }
            try {
                $assetId = $this->monday->attachFileWithFallback(
                    itemId:   $tsrItemId,
                    columnId: $colId,
                    path:     $relPath,
                    localId:  $r->local_id,
                    role:     $role,
                );
                if ($assetId === null) {
                    $failedSigs[] = $role;
                    Log::warning('TSR sync: signature upload returned no asset id', [
                        'local_id' => $r->local_id,
                        'role'     => $role,
                        'col'      => $colId,
                    ]);
                }
            } catch (Throwable $e) {
                $failedSigs[] = $role;
                Log::warning('TSR sync: signature upload failed; will mark row as error', [
                    'local_id' => $r->local_id,
                    'role'     => $role,
                    'col'      => $colId,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        if (! empty($failedSigs)) {
            // Mark the row as error and bail before flipping to
            // synced. The TSR is already on Monday (Step 1
            // succeeded) — we just couldn't get all the signatures
            // attached. An admin re-runs ReuploadSignatures once
            // Monday's add_file_to_column API is healthy.
            $errMsg = sprintf(
                'Signature upload failed for: %s. TSR is on Monday (id=%d) but file columns are empty. '
                    . 'Run `php artisan monday:reupload-signatures %s` to retry once the file-upload API is healthy.',
                implode(', ', array_unique($failedSigs)),
                $tsrItemId,
                $r->local_id
            );
            throw new \RuntimeException($errMsg);
        }

        // Step 3: patch the source ticket's status. The mapper is
        // the single source of truth for "TSR X → ticket Y". For an
        // OPEN TSR this is a no-op and the function returns null —
        // we just skip in that case.
        $tsrLabel = strtoupper(str_replace('_', '-', $status->value));
        $appliedLabel = $this->monday->applyTicketStatusFromServiceStatus(
            tsrLabel: $tsrLabel,
            ticketItemId: (int) $r->monday_ticket_id,
        );
        if ($appliedLabel !== null) {
            Log::info('TSR sync: ticket status updated', [
                'local_id'    => $r->local_id,
                'ticket'      => $r->monday_ticket_id,
                'tsr_status'  => $status->value,
                'new_label'   => $appliedLabel,
            ]);

            // Bust the 60s getBoardItems() cache for the tickets
            // board so the customer/TSP dashboard reflects the
            // new ticket status on the NEXT request, not 60s
            // later. Without this the user would submit a TSR,
            // the status flips on Monday, but the dashboard
            // still shows "Working on it" until the cache
            // expires.
            $ticketsBoardId = config('services.monday.tickets_board_id');
            if ($ticketsBoardId) {
                Cache::forget("monday.board.{$ticketsBoardId}.items");
            }
        }

        // Step 4: bookkeeping. We only flip to Synced once every
        // sub-step (create, attach, patch) has returned. If any of
        // them threw we would have skipped past this line, so the
        // row stays in 'error' for the next drain.
        $r->mirrored_to_monday_at = now();
        $r->sync_state = SyncState::Synced;
        $r->sync_error = null;
        $r->save();
    }

    /**
     * Build the GraphQL column_values JSON for the TSR board.
     * Mirrors the DTO fields back to the monday column ids in
     * MondayColumnIds. Retained for callers that want to bypass
     * createServiceReportItem (e.g. unit tests with stubbed client).
     *
     * @return array<string, mixed>
     */
    protected function buildTsrColumnValues(ServiceReport $r): array
    {
        $status = $r->serviceStatusEnum()
            ?? \App\Enums\ServiceStatus::Open;

        return [
            MondayColumnIds::TSR_COL_SERVICE_NUMBER => [
                'item_ids' => [(int) $r->monday_ticket_id],
            ],
            MondayColumnIds::TSR_COL_SERVICE_STATUS => [
                'index' => MondayColumnIds::TSR_STATUS_LABEL_INDEX[
                    strtoupper(str_replace('_', '-', $status->value))
                ] ?? 0,
            ],
            MondayColumnIds::TSR_COL_PROBLEM         => $r->problem_and_concerns,
            MondayColumnIds::TSR_COL_JOB_DONE        => $r->job_done,
            MondayColumnIds::TSR_COL_PARTS_REPLACED  => $r->parts_replaced,
            MondayColumnIds::TSR_COL_RECOMMENDATION  => $r->recommendation,
            MondayColumnIds::TSR_COL_REMARKS         => $r->remarks,
            MondayColumnIds::TSR_COL_LOGIN_DATE      => $r->login_date?->toDateString(),
            MondayColumnIds::TSR_COL_SERVICE_START   => $r->service_start_at?->toIso8601String(),
            MondayColumnIds::TSR_COL_SERVICE_END     => $r->service_end_at?->toIso8601String(),
            MondayColumnIds::TSR_COL_LOGOUT_DATE     => $r->logout_date?->toDateString(),
            MondayColumnIds::TSR_COL_SERIAL          => $r->serial_number,
            MondayColumnIds::TSR_COL_SOFTWARE        => $r->software_version,
            MondayColumnIds::TSR_COL_TSP_WORKWITH    => $r->tsp_workwith_person_ids ?? [],
            MondayColumnIds::TSR_COL_CUSTOMER_INCHARGE   => $r->customer_incharge,
            MondayColumnIds::TSR_COL_CUSTOMER_INCHARGE_E => $r->customer_incharge_email,
            MondayColumnIds::TSR_COL_BIOMED_INCHARGE     => $r->biomed_incharge,
            MondayColumnIds::TSR_COL_BIOMED_EMAIL        => $r->biomed_email,
        ];
    }
}
