<?php

declare(strict_types=1);

namespace App\Actions;

use App\DataTransferObjects\TsrSubmissionDto;
use App\Enums\SyncState;
use App\Models\ServiceReport;
use App\Models\User;
use App\Services\SignatureStorage;
use App\Support\Monday\TsrStatusMapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Single entry point for a TSP submitting a TSR. Idempotent on
 * `local_id` so offline retries never create duplicate rows.
 *
 * Phases:
 *   1. Find-or-create the ServiceReport row by local_id.
 *   2. Persist the three signatures to local storage.
 *   3. Update DB columns from the DTO.
 *   4. Compute the ticket-side status change via TsrStatusMapper.
 *      (Actual monday write is queued; see SyncPendingTsrReports.)
 *   5. Set sync_state = 'pending' so the drainer picks it up.
 *   6. After the local row is committed, drain the just-saved row
 *      to monday.com so the source ticket's status flips in the
 *      same user-facing action (no more "I saved the TSR but the
 *      ticket is still 'Working on it' on Monday" surprise). If the
 *      drain fails the row stays in 'pending'/'error' for the next
 *      drainer cycle (browser `online` event, 5-min cron, manual
 *      "Sync to Monday" button).
 *
 * No monday.com write happens *inside* the transaction. The drainer
 * runs *after* commit so the row is visible to its own query and a
 * failed drain never rolls back the local write — the portal's
 * local DB is the authoritative record at submit time.
 */
class SubmitServiceReport
{
    /**
     * When true, the action will attempt an inline monday.com
     * drain of the just-saved row BEFORE returning. Defaults
     * to false because the inline path takes 1.5–3s (3-6 HTTP
     * calls to api.monday.com) and makes the "TSR saved" toast
     * feel sluggish — the user is staring at a spinner.
     *
     * With the default (false), the row is saved locally in
     * 'pending' state and the next call to
     * `ServiceReportController::status()` — which the form
     * polls every 5s — will opportunistically drain it. The
     * sticky-bar "Sync to Monday" button and the offline-queue
     * `online` event also call the drainer directly, so
     * recovery on flaky networks is automatic.
     *
     * Tests that want the inline path can opt in by setting
     * this to true before calling execute().
     */
    public bool $syncAfterCommit = false;

    public function __construct(
        protected SignatureStorage $signatures,
        protected SyncPendingTsrReports $drainer,
    ) {
    }

    public function execute(User $tsp, TsrSubmissionDto $dto): ServiceReport
    {
        $report = DB::transaction(function () use ($tsp, $dto) {
            // Phase 1: idempotent row
            $report = ServiceReport::firstOrNew(['local_id' => $dto->localId]);

            // First-write-wins: if a synced row already exists for this
            // local_id, return it as-is (no overwrite, no duplicate).
            if ($report->exists && $report->sync_state === SyncState::Synced) {
                return $report;
            }

            // Phase 2: signatures to local storage. As of 2026-07-30
            // signature fields are optional, so a missing/invalid
            // pad is no longer fatal — we just store a null path
            // for that role and let the TSR proceed.
            $tspSigPath  = $this->storeSignatureIfPresent($dto->tspSignature,         $dto->localId, 'tsp');
            $custSigPath = $this->storeSignatureIfPresent($dto->customerInCharge,     $dto->localId, 'customer');
            $biomedPath  = $this->storeSignatureIfPresent($dto->biomedPersonInCharge, $dto->localId, 'biomed');

            // Phase 3: fill the DB columns
            $report->fill([
                'monday_ticket_id'        => $dto->ticketNumber,
                'user_id'                 => $tsp->id,
                'author_role'             => $tsp->role,
                'client_submitted_at'     => $dto->clientSubmittedAt,
                'service_status'          => $dto->serviceStatus->value,
                'problem_and_concerns'    => $dto->problemAndConcerns,
                'job_done'                => $dto->jobDone,
                'parts_replaced'          => $dto->partsReplaced,
                'recommendation'          => $dto->recommendation,
                'remarks'                 => $dto->remarks,
                'login_date'              => $dto->logInDate,
                'service_start_at'        => $dto->serviceStartDateTime,
                'service_end_at'          => $dto->serviceEndDateTime,
                'logout_date'             => $dto->logOutDate,
                'serial_number'           => $dto->machineSystemSerialNumber,
                'software_version'        => $dto->softwareVersionNo,
                'customer_incharge'       => $dto->customerInCharge->name,
                'customer_incharge_email' => $dto->customerInCharge->email,
                'biomed_incharge'         => $dto->biomedPersonInCharge->name,
                'biomed_email'            => $dto->biomedPersonInCharge->email,
                'tsp_workwith_person_ids' => $dto->tspWorkWith,
                'total_minutes'           => $dto->totalMinutes,
                'tsp_signature_path'      => $tspSigPath,
                'customer_signature_path' => $custSigPath,
                'biomed_signature_path'   => $biomedPath,
                'sync_state'              => SyncState::Pending,
                'sync_error'              => null,
            ]);
            $report->save();

            // Phase 4: log the would-be ticket status change for the
            // drainer. We don't write to monday here.
            $patch = TsrStatusMapper::toTicketChange($dto->serviceStatus);
            Log::info('TSR submission queued for sync', [
                'local_id'        => $dto->localId,
                'ticket'          => $dto->ticketNumber,
                'tsr_status'      => $dto->serviceStatus->value,
                'ticket_patch'    => $patch,
            ]);

            return $report;
        });

        // Phase 5 (deferred): the drainer runs on the next status
        // poll (every 5s) or manual "Sync to Monday" click, not
        // inline. The flag is honored for backwards compat with
        // tests that opt in to the inline path. The inline path
        // is still slow but at least it works end-to-end.
        if ($this->syncAfterCommit && $this->drainer !== null) {
            try {
                $stats = $this->drainer->syncOneRow($report);
                if (($stats['succeeded'] ?? 0) > 0) {
                    $report->refresh();
                }
            } catch (Throwable $e) {
                Log::warning('TSR immediate drain failed; will retry on next cycle', [
                    'local_id' => $dto->localId,
                    'ticket'   => $dto->ticketNumber,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return $report;
    }

    /**
     * Persist a signature only if it actually contains a valid
     * pad drawing. Returns the relative storage path, or null
     * if the pad is empty/invalid (which is now a valid state
     * for a partial TSR as of 2026-07-30).
     */
    protected function storeSignatureIfPresent(\App\DataTransferObjects\SignatureBlob $blob, string $localId, string $role): ?string
    {
        // Empty pad = no dataUrl = nothing to store.
        if ($blob->dataUrl === '' || $blob->dataUrl === null) {
            return null;
        }
        if (! $blob->isValid()) {
            // A user-typed dataUrl that didn't decode (or was
            // shorter than 200 bytes of a real pad). Treat as
            // missing rather than throwing — the validator
            // already accepted the field as nullable.
            Log::info('TSR signature pad present but invalid; treating as missing', [
                'local_id' => $localId,
                'role'     => $role,
            ]);
            return null;
        }
        return $this->signatures->store($blob, $localId, $role);
    }
}
