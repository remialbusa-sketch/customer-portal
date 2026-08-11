<?php

namespace App\Listeners;

use App\Events\TicketCreated;
use App\Mail\TspTicketAlertMail;
use App\Models\TicketAcknowledgement;
use App\Models\User;
use App\Support\PersonnelDirectory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Sends a one-shot alert email to every active TSP in the same region
 * as the ticket's customer. Each TSP gets:
 *
 *   - a signed "Acknowledge" link (records a row in
 *     `ticket_acknowledgements` when clicked; idempotent)
 *   - a signed "View ticket" link (just goes to the ticket page)
 *
 * If the event carries no region (e.g. customer lookup failed) we
 * skip the broadcast entirely and log a warning — it's better to
 * surface that gap in the log than to email a wrong region.
 *
 * Per-TSP failures are caught and logged so one bad address doesn't
 * poison the rest of the list.
 *
 * NOTE: not `ShouldQueue` — cPanel target has no worker. The number
 * of TSPs per region is small (1-5 in practice), so synchronous
 * send is fine.
 */
class SendTspAlertForNewTicket
{
    /**
     * Create the event listener.
     */
    public function __construct() {}

    public function handle(TicketCreated $event): void
    {
        $regionCode = $event->regionCode ?? null;

        if ($regionCode === null || $regionCode === '') {
            Log::warning('TicketCreated without regionCode; skipping TSP alert.', [
                'monday_ticket_id' => $event->mondayTicketId ?? null,
                'subject'          => $event->subject ?? null,
            ]);
            return;
        }

        try {
            // PersonnelDirectory::forCustomerAssignment() returns a
            // Collection of region groups; each group has a `members`
            // array of [id, name, email, role, region, ...] rows. We
            // pluck the user ids and reload the full User records so
            // we have a real Eloquent model for the mailable (and
            // access to `status` for the active filter).
            $userIds = PersonnelDirectory::forCustomerAssignment($regionCode)
                ->flatMap(fn (array $group) => $group['members'])
                ->pluck('id')
                ->unique()
                ->values();

            if ($userIds->isEmpty()) {
                Log::info('No TSPs in directory for region; nothing to email.', [
                    'monday_ticket_id' => $event->mondayTicketId,
                    'region'           => $regionCode,
                ]);
                return;
            }

            $tsps = User::query()
                ->whereIn('id', $userIds)
                ->where('status', 'active')
                ->get();
        } catch (\Throwable $e) {
            Log::warning('PersonnelDirectory lookup failed for TSP alert.', [
                'region' => $regionCode,
                'error'  => $e->getMessage(),
            ]);
            return;
        }

        if ($tsps->isEmpty()) {
            Log::info('No active TSPs in region for new ticket; nothing to email.', [
                'monday_ticket_id' => $event->mondayTicketId,
                'region'           => $regionCode,
            ]);
            return;
        }

        foreach ($tsps as $tsp) {
            $this->sendToTsp($tsp, $event, $regionCode);
        }
    }

    private function sendToTsp(User $tsp, TicketCreated $event, string $regionCode): void
    {
        try {
            $acknowledgeUrl = URL::temporarySignedRoute(
                'tsp.tickets.acknowledge.show',
                now()->addHours(48),
                ['id' => $event->mondayTicketId, 'user' => $tsp->id],
            );

            $viewUrl = URL::temporarySignedRoute(
                'tsp.tickets.show',
                now()->addHours(48),
                ['id' => $event->mondayTicketId],
            );

            $alreadyAcked = TicketAcknowledgement::existsFor(
                (string) $event->mondayTicketId,
                (int) $tsp->id,
            );

            Mail::to($tsp->email)->send(new TspTicketAlertMail(
                tsp:                 $tsp,
                mondayTicketId:      (string) $event->mondayTicketId,
                ticketSubject:       (string) ($event->subject ?? '(no subject)'),
                brand:               $event->brand,
                model:               $event->model,
                requestType:         $event->requestType,
                regionCode:          $regionCode,
                customerName:        $event->customerName,
                customerEmail:       $event->customerEmail,
                acknowledgeUrl:      $acknowledgeUrl,
                viewUrl:             $viewUrl,
                alreadyAcknowledged: $alreadyAcked,
            ));
        } catch (\Throwable $e) {
            Log::warning('TspTicketAlertMail send failed', [
                'tsp_id'           => $tsp->id,
                'tsp_email'        => $tsp->email,
                'monday_ticket_id' => $event->mondayTicketId,
                'error'            => $e->getMessage(),
            ]);
        }
    }
}
