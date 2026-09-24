{{-- =============================================================
     Notification bell (top navigation)
     -------------------------------------------------------------
     Updates: Pusher ping (app.js re-dispatches `bell-refresh`) +
     wire:poll 45s fallback. Dropdown lists the 10 most recent
     notifications; unread count is the badge.
     ============================================================= --}}
<div
    class="relative"
    x-data="{ open: @entangle(false) }"
    wire:poll.45s="refreshBell"
    @bell-refresh.window="$wire.refreshBell()"
    @click.outside="open = false"
>
    <button
        type="button"
        class="btn btn-ghost btn-circle btn-sm"
        :aria-expanded="open ? 'true' : 'false'"
        aria-label="Notifications"
        @click="open = ! open"
    >
        <span class="relative inline-flex">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M15 17h5l-1.4-1.4A2 2 0 0118 15.6V11a6 6 0 00-4-5.7V5a2 2 0 10-4 0v.3A6 6 0 006 11v4.6c0 .5-.2 1-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
            </svg>
            @if ($this->unreadCount > 0)
                <span
                    class="absolute -top-1.5 -right-1.5 min-w-4 h-4 px-1 rounded-full bg-error text-error-content text-[10px] font-bold leading-4 text-center"
                    data-unread="{{ $this->unreadCount }}"
                >{{ $this->unreadCount > 9 ? '9+' : $this->unreadCount }}</span>
            @endif
        </span>
    </button>

    <div
        x-show="open"
        x-cloak
        x-transition.opacity.duration.150ms
        class="absolute end-0 mt-2 w-80 max-w-[90vw] rounded-xl border border-base-300/60 bg-base-100 shadow-xl z-50"
    >
        <div class="flex items-center justify-between px-4 py-3 border-b border-base-300/60">
            <span class="text-sm font-semibold">Notifications</span>
            @if ($this->unreadCount > 0)
                <button
                    type="button"
                    wire:click="markAllRead"
                    wire:loading.attr="disabled"
                    class="btn btn-ghost btn-xs text-base-content/60"
                >Mark all read</button>
            @endif
        </div>

        @if (count($this->items) === 0)
            <div class="px-4 py-8 text-center">
                <div class="mx-auto w-12 h-12 rounded-full bg-base-200 flex items-center justify-center text-xl mb-2" aria-hidden="true">🔔</div>
                <p class="text-sm text-base-content/60">You're all caught up.</p>
            </div>
        @else
            <ul class="max-h-80 overflow-y-auto divide-y divide-base-300/50">
                @foreach ($this->items as $item)
                    <li>
                        <button
                            type="button"
                            wire:click="openNotification({{ $item['id'] }})"
                            class="w-full text-left px-4 py-3 hover:bg-base-200/60 transition flex gap-3 {{ $item['unread'] ? 'bg-primary/5' : '' }}"
                        >
                            <span class="mt-0.5 shrink-0">
                                <span class="inline-block w-2 h-2 rounded-full {{ $item['unread'] ? 'bg-primary' : 'bg-base-300' }}"></span>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium text-base-content leading-snug">
                                    {{ $item['title'] }}
                                </span>
                                @if ($item['body'])
                                    <span class="block text-xs text-base-content/60 mt-0.5 line-clamp-2">{{ $item['body'] }}</span>
                                @endif
                                <span class="block text-[10px] text-base-content/40 mt-1">
                                    <span class="badge badge-{{ $item['tone'] }} badge-xs gap-1 mr-1 align-middle"></span>
                                    {{ $item['when'] }}
                                </span>
                            </span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
