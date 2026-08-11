<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A first-touch acknowledgement by a TSP that they saw a newly-created
 * ticket. Distinct from a "claim" (which writes the TSP's person id to
 * the People column on Monday and flips response status to RESPONDED).
 *
 * The acknowledgement is recorded when a TSP clicks the link in the
 * alert email. The link is signed, so it's safe to expose in email;
 * the controller that handles the click records the row and redirects
 * the TSP to the ticket detail page where the claim button lives.
 *
 * @property int    $id
 * @property string $monday_ticket_id
 * @property int    $user_id
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon $acknowledged_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read User $user
 */
class TicketAcknowledgement extends Model
{
    protected $fillable = [
        'monday_ticket_id',
        'user_id',
        'ip',
        'user_agent',
        'acknowledged_at',
    ];

    protected $casts = [
        'acknowledged_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Has the given user already acknowledged this ticket? Used by the
     * controller to short-circuit a re-click of the email link.
     */
    public static function existsFor(string $mondayTicketId, int $userId): bool
    {
        return static::query()
            ->where('monday_ticket_id', $mondayTicketId)
            ->where('user_id', $userId)
            ->exists();
    }

    /**
     * Record an acknowledgement. Idempotent: if a row already exists
     * for (ticket, user), return the existing row without writing a
     * duplicate. This is what makes the email link safe to click twice.
     */
    public static function recordIfNew(
        string $mondayTicketId,
        int $userId,
        ?string $ip = null,
        ?string $userAgent = null,
    ): self {
        $existing = static::query()
            ->where('monday_ticket_id', $mondayTicketId)
            ->where('user_id', $userId)
            ->first();

        if ($existing) {
            return $existing;
        }

        return static::create([
            'monday_ticket_id' => $mondayTicketId,
            'user_id'          => $userId,
            'ip'               => $ip,
            'user_agent'       => $userAgent !== null
                ? mb_substr($userAgent, 0, 512)
                : null,
            'acknowledged_at'  => now(),
        ]);
    }

    /**
     * Who has acknowledged this ticket? Useful for the customer view:
     * "X TSPs have acknowledged your request" once we expose it on
     * the customer ticket page.
     *
     * @return Builder<TicketAcknowledgement>
     */
    public static function queryForTicket(string $mondayTicketId): Builder
    {
        return static::query()
            ->where('monday_ticket_id', $mondayTicketId)
            ->orderBy('acknowledged_at');
    }
}
