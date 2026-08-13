<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pending TSP-to-TSP ticket transfer request.
 *
 * The handoff is two-step: the current assignee (from_user) requests
 * a transfer to a target TSP (to_user); only the target can accept,
 * and ONLY an accepted transfer writes the new People column on
 * Monday.com (see MondayClient::reassignTicket). Declined and
 * cancelled requests leave the assignment untouched.
 *
 * Status lifecycle: pending → accepted | declined | cancelled.
 */
class TicketTransfer extends Model
{
    use HasFactory;

    public const STATUS_PENDING   = 'pending';
    public const STATUS_ACCEPTED  = 'accepted';
    public const STATUS_DECLINED  = 'declined';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACCEPTED,
        self::STATUS_DECLINED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'monday_ticket_id',
        'from_user_id',
        'to_user_id',
        'status',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
