<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a TSP requests to hand a ticket over to another TSP.
 *
 * Broadcast on `region.all` (same catch-all the dashboard already
 * subscribes to). The receiving TSP's dashboard checks
 * `to_user_id === my id` before showing the "incoming transfer
 * request" card — the 20s poll is the fallback when Pusher isn't
 * configured, so no TSP misses a request.
 *
 * This is only the REQUEST — the People column on Monday.com is NOT
 * touched until the target TSP accepts (TicketTransferred).
 */
class TicketTransferRequested implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $mondayTicketId,
        public int $fromUserId,
        public string $fromName,
        public int $toUserId,
        public string $toName,
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('region.all'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ticket.transfer_requested';
    }

    public function broadcastWith(): array
    {
        return [
            'monday_ticket_id' => $this->mondayTicketId,
            'from_user_id'     => $this->fromUserId,
            'from_name'        => $this->fromName,
            'to_user_id'       => $this->toUserId,
            'to_name'          => $this->toName,
            'requested_at'     => now()->toIso8601String(),
        ];
    }
}
