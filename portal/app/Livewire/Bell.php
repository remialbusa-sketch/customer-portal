<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Notification;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The notification bell in the top navigation.
 *
 * Updates arrive two ways (user picked "Both"):
 *   - Pusher: NotificationCreated broadcasts on `user.{id}` and the
 *     Echo subscription in app.js re-dispatches a `bell-refresh`
 *     window event, which this component listens for via @On.
 *   - wire:poll every 45s as the fallback (cPanel builds without
 *     VITE_PUSHER_* never construct an Echo client).
 */
class Bell extends Component
{
    public bool $open = false;

    /** Refresh the unread count + list (poll + realtime callback). */
    #[On('bell-refresh')]
    public function refreshBell(): void
    {
        // Computed properties re-resolve on the next render; touch
        // the query now so poll()/echo callbacks have fresh data.
        $this->unreadCount;
        $this->items;
        $this->dispatch('$refresh');
    }

    /** Unread count for the badge. */
    public function getUnreadCountProperty(): int
    {
        return Notification::query()
            ->where('user_id', auth()->id())
            ->unread()
            ->count();
    }

    /** The 10 most recent notifications for the dropdown. */
    public function getItemsProperty(): array
    {
        return Notification::query()
            ->where('user_id', auth()->id())
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(function (Notification $n) {
                return [
                    'id'       => $n->id,
                    'type'     => $n->type,
                    'title'    => $n->title,
                    'body'     => $n->body,
                    'url'      => $n->url,
                    'tone'     => $n->tone(),
                    'unread'   => $n->read_at === null,
                    'when'     => optional($n->created_at)->diffForHumans(),
                ];
            })
            ->all();
    }

    /** Mark one notification read and navigate to its deep link. */
    public function openNotification(int $id): void
    {
        Notification::query()
            ->where('id', $id)
            ->where('user_id', auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $url = Notification::query()
            ->where('id', $id)
            ->where('user_id', auth()->id())
            ->value('url');

        $this->open = false;

        if ($url) {
            $this->redirect($url, navigate: true);
        }
    }

    /** Mark every notification for the current user read. */
    public function markAllRead(): void
    {
        Notification::query()
            ->where('user_id', auth()->id())
            ->unread()
            ->update(['read_at' => now()]);
    }

    public function render(): View
    {
        return view('livewire.bell');
    }
}
