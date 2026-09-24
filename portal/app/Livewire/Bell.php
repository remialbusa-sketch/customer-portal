<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Notification;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The notification bell in the top navigation — the single surface
 * for EVERYTHING the user needs to act on:
 *
 *   - DB notifications (App\Services\Notifier): claimable tickets,
 *     ticket status changes, TSR sync failures, transfers
 *   - the temporary-password notice (pinned, synthesized from the
 *     user's state — not a DB row) so there is ONE notification UI
 *
 * Updates arrive two ways (user picked "Both"):
 *   - Pusher: NotificationCreated broadcasts on `user.{id}` and the
 *     Echo subscription in app.js re-dispatches a `bell-refresh`
 *     window event, which this component listens for via #[On].
 *   - wire:poll every 45s as the fallback (cPanel builds without
 *     VITE_PUSHER_* never construct an Echo client).
 */
class Bell extends Component
{
    public bool $open = false;

    /** Refresh data (poll tick + realtime callback). */
    #[On('bell-refresh')]
    public function refreshBell(): void
    {
        $this->dispatch('$refresh');
    }

    /**
     * The temporary-password notice, synthesized fresh each render
     * (never stored): visible while the account still logs in with
     * the default password and hasn't dismissed it this session.
     */
    public function getPasswordNoticeProperty(): ?array
    {
        $user = auth()->user();
        if (! $user || ! $user->usingDefaultPassword()) {
            return null;
        }
        if (session('passwordChangeDismissed')) {
            return null;
        }

        return [
            'is_notice' => true,
            'id'        => 0,
            'type'      => 'password',
            'title'     => 'Temporary password in use',
            'body'      => 'Your account is still using the temporary password. Set one of your own to keep it secure.',
            'url'       => route('password.change'),
            'unread'    => true,
            'when'      => null,
        ];
    }

    /** Badge count: unread DB notifications + the pinned notice. */
    public function getUnreadCountProperty(): int
    {
        return Notification::query()
            ->where('user_id', auth()->id())
            ->unread()
            ->count()
            + ($this->passwordNotice !== null ? 1 : 0);
    }

    /** The 10 most recent DB notifications (notice is pinned separately). */
    public function getItemsProperty(): array
    {
        return Notification::query()
            ->where('user_id', auth()->id())
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(function (Notification $n) {
                return [
                    'is_notice' => false,
                    'id'        => $n->id,
                    'type'      => $n->type,
                    'title'     => $n->title,
                    'body'      => $n->body,
                    'url'       => $n->url,
                    'unread'    => $n->read_at === null,
                    'when'      => optional($n->created_at)->diffForHumans(),
                ];
            })
            ->all();
    }

    /** Mark one DB notification read and navigate to its deep link. */
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

    /** Mark every DB notification for the current user read. */
    public function markAllRead(): void
    {
        Notification::query()
            ->where('user_id', auth()->id())
            ->unread()
            ->update(['read_at' => now()]);
    }

    /**
     * "Set up later" — hide the temporary-password notice for the
     * rest of this session (same behavior the old floating card had;
     * it returns next session until the password is changed).
     */
    public function dismissPasswordNotice(): void
    {
        session(['passwordChangeDismissed' => true]);
    }

    public function render(): View
    {
        return view('livewire.bell');
    }
}
