<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\NotificationCreated;
use App\Models\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Creates bell notifications and pushes the realtime ping.
 *
 * Single entry point so every producer gets the same behavior:
 *   - dedupe against UNREAD duplicates (same user + type + ticket +
 *     title) so a 5-minute drainer retry cycle can't flood the bell
 *   - broadcast NotificationCreated on the recipient's private
 *     channel inside a try/catch — Pusher being down must never
 *     break the action that produced the notification
 *
 * Polling (45s wire:poll in the bell) is the delivery fallback.
 */
class Notifier
{
    public function send(
        int $userId,
        string $type,
        string $title,
        ?string $body = null,
        ?string $url = null,
        ?string $ticketId = null,
    ): ?Notification {
        // Dedupe: an unread copy of the exact same notification is
        // enough — don't stack identical rows while the condition
        // persists (e.g. a TSR that keeps failing every drain cycle).
        $exists = Notification::query()
            ->where('user_id', $userId)
            ->where('type', $type)
            ->where('ticket_id', $ticketId)
            ->where('title', $title)
            ->unread()
            ->exists();
        if ($exists) {
            return null;
        }

        $notification = Notification::create([
            'user_id'   => $userId,
            'type'      => $type,
            'title'     => $title,
            'body'      => $body,
            'url'       => $url,
            'ticket_id' => $ticketId,
        ]);

        try {
            broadcast(new NotificationCreated($userId));
        } catch (\Throwable $e) {
            Log::warning('Notification broadcast failed (bell falls back to polling)', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }

        return $notification;
    }
}
