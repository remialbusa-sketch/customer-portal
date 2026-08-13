<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a transfer request is ACCEPTED and the ticket's People
 * column on Monday.com has been rewritten (old TSP removed, new TSP
 * added).
 *
 * Broadcast on `region.all`. Every TSP dashboard reacts:
 *   - the OLD assignee's dashboard drops the ticket from "My tickets"
 *     on the next refresh;
 *   - the NEW assignee's dashboard picks it up;
 *   - the new assignee gets a toast so they know to act on it.
 *
 * Declines and cancellations are local-only (the assignment never
 * changed on Monday), so they don't broadcast.
 */
class TicketTransferred implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $mondayTicketId,
        public int $fromUserId,
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
        return 'ticket.transferred';
    }

    public function broadcastWith(): array
    {
        return [
            'monday_ticket_id' => $this->mondayTicketId,
            'from_user_id'     => $this->fromUserId,
            'to_user_id'       => $this->toUserId,
            'to_name'          => $this->toName,
            'transferred_at'   => now()->toIso8601String(),
        ];
    }
}
