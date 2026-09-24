<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single in-app notification row (the bell in the top navigation).
 *
 * Created by App\Services\Notifier::send() from the app's event
 * points (new claimable ticket, ticket status change, TSR sync
 * failure, ticket transfer). One row per recipient — no fan-out
 * table — so the bell's unread count is a cheap indexed query.
 */
class Notification extends Model
{
    public const TYPE_CLAIMABLE   = 'claimable_ticket';
    public const TYPE_STATUS      = 'ticket_status';
    public const TYPE_TSR_ERROR   = 'tsr_sync_error';
    public const TYPE_TRANSFER    = 'ticket_transfer';

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'body',
        'url',
        'ticket_id',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    /**
     * DaisyUI badge tone per type, for the bell list icons.
     */
    public function tone(): string
    {
        return match ($this->type) {
            self::TYPE_CLAIMABLE => 'badge-success',
            self::TYPE_STATUS    => 'badge-info',
            self::TYPE_TSR_ERROR => 'badge-error',
            self::TYPE_TRANSFER  => 'badge-warning',
            default              => 'badge-ghost',
        };
    }
}
