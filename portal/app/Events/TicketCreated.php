<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a customer creates a new ticket. Broadcast on a
 * region-scoped private channel so TSPs in the right region see the
 * new ticket appear in their "Available" pool without polling.
 *
 * Channel naming: `region.{code}` (e.g. `region.ncr`). A fallback
 * `region.all` channel exists for customers with no resolvable
 * region (their tickets show up for every TSP — degraded UX but
 * better than the ticket going unseen).
 *
 * The payload is the minimum the TSP dashboard needs to:
 *   1. Show a toast / banner ("New ticket in your region")
 *   2. Optimistically prepend the ticket to the available pool
 *   3. Recompute the open / in_progress / total stats
 *
 * The dashboard Livewire component listens for this event and runs
 * `loadLists()` again, which is the safest path: a fresh Monday
 * read keeps status, brand, model, and assigned-TSP all in sync.
 */
class TicketCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  string  $mondayTicketId  The Monday item id of the new ticket
     * @param  string|null  $regionCode  4-broad region code (NCR / NORTH LUZON / VISAYAS / MINDANAO) or null
     * @param  string  $subject         Ticket subject (used in toast text)
     * @param  string|null  $brand       Affected machine brand, if known
     * @param  string|null  $model       Affected machine model, if known
     * @param  string|null  $requestType 'Issue' | 'Request'
     * @param  string|null  $customerName  Name of the customer who opened the ticket (for the TSP alert email)
     * @param  string|null  $customerEmail  Email of the customer (reply-to in the TSP alert email)
     */
    public function __construct(
        public string $mondayTicketId,
        public ?string $regionCode,
        public string $subject,
        public ?string $brand = null,
        public ?string $model = null,
        public ?string $requestType = null,
        public ?string $customerName = null,
        public ?string $customerEmail = null,
    ) {
    }

    public function broadcastOn(): array
    {
        $channels = [
            // Always broadcast on the catch-all so admins / superadmins
            // see every new ticket.
            new PrivateChannel('region.all'),
        ];

        if ($this->regionCode !== null && $this->regionCode !== '') {
            $channels[] = new PrivateChannel('region.' . strtolower($this->regionCode));
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'ticket.created';
    }

    public function broadcastWith(): array
    {
        return [
            'monday_ticket_id' => $this->mondayTicketId,
            'region_code'      => $this->regionCode,
            'subject'          => $this->subject,
            'brand'            => $this->brand,
            'model'            => $this->model,
            'request_type'     => $this->requestType,
            'created_at'       => now()->toIso8601String(),
        ];
    }

    /**
     * Field map consumed by the SendTspAlertForNewTicket listener.
     * Kept separate from broadcastWith() because the email needs the
     * customer identity fields (not useful in the realtime toast)
     * and we don't want to bloat the websocket payload.
     */
    public function alertPayload(): array
    {
        return [
            'monday_ticket_id' => $this->mondayTicketId,
            'region_code'      => $this->regionCode,
            'subject'          => $this->subject,
            'brand'            => $this->brand,
            'model'            => $this->model,
            'request_type'     => $this->requestType,
            'customer_name'    => $this->customerName,
            'customer_email'   => $this->customerEmail,
        ];
    }
}
