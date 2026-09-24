{{-- =============================================================
     Notification bell (top navigation) — the single notification
     surface. Pinned "Temporary password" notice on top (with its
     own Set password / Set up later actions), then DB notifications
     (claimable tickets, status changes, TSR sync errors, transfers).

     Updates: Pusher ping (app.js re-dispatches `bell-refresh`) +
     wire:poll 45s fallback.
     ============================================================= --}}
<div
    class="relative"
    x-data="{ open: false }"
    wire:poll.45s="refreshBell"
    @bell-refresh.window="$wire.refreshBell()"
    @click.outside="open = false"
>
    <button
        type="button"
        class="btn btn-ghost btn-circle btn-sm relative"
        :aria-expanded="open ? 'true' : 'false'"
        aria-label="Notifications"
        @click="open = ! open"
    >
        <span class="relative inline-flex">
            {{-- Badge reflects the SAME bell icon the old amber
                 notice used, so the visual reminder carries over. --}}
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
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        class="absolute end-0 mt-2 w-[22rem] max-w-[calc(100vw-2rem)] rounded-2xl border border-base-300/60 bg-base-100 shadow-xl z-50 overflow-hidden"
    >
        {{-- ── Header ── --}}
        <div class="flex items-center justify-between px-4 py-3 border-b border-base-300/60 bg-base-200/40">
            <div class="flex items-center gap-2">
                <span class="text-sm font-semibold text-base-content">Notifications</span>
                @if ($this->unreadCount > 0)
                    <span class="badge badge-error badge-sm text-error-content font-bold">{{ $this->unreadCount }}</span>
                @endif
            </div>
            @if ($this->items && collect($this->items)->contains('unread', true))
                <button
                    type="button"
                    wire:click="markAllRead"
                    wire:loading.attr="disabled"
                    class="btn btn-ghost btn-xs text-base-content/60"
                >Mark all read</button>
            @endif
        </div>

        {{-- ── Pinned: temporary-password notice ── --}}
        @if ($this->passwordNotice)
            <div class="px-4 py-3 border-b border-base-300/60 bg-warning/10">
                <div class="flex gap-3">
                    <span class="mt-0.5 shrink-0 inline-flex h-8 w-8 items-center justify-center rounded-full bg-warning/20 text-warning">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m0 0v2m0-2h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-base-content leading-snug">{{ $this->passwordNotice['title'] }}</p>
                        <p class="text-xs text-base-content/70 mt-0.5 leading-relaxed">{{ $this->passwordNotice['body'] }}</p>
                        <div class="mt-2.5 flex items-center gap-2">
                            <a href="{{ $this->passwordNotice['url'] }}" wire:navigate
                               class="btn btn-warning btn-xs font-semibold"
                               @click="open = false">
                                Set password now
                            </a>
                            <button
                                type="button"
                                wire:click="dismissPasswordNotice"
                                class="btn btn-ghost btn-xs text-base-content/60"
                            >Set up later</button>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- ── DB notifications ── --}}
        @if (count($this->items) === 0)
            <div class="px-4 py-8 text-center">
                <div class="mx-auto w-12 h-12 rounded-full bg-base-200 flex items-center justify-center text-xl mb-2" aria-hidden="true">🔔</div>
                <p class="text-sm text-base-content/60">You're all caught up.</p>
            </div>
        @else
            <ul class="max-h-80 overflow-y-auto divide-y divide-base-300/50">
                @foreach ($this->items as $item)
                    @php
                        [$icon, $iconTone] = match ($item['type']) {
                            \App\Models\Notification::TYPE_CLAIMABLE => ['M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4', 'bg-success/15 text-success'],
                            \App\Models\Notification::TYPE_STATUS    => ['M13 10V3L4 14h7v7l9-11h-7z', 'bg-info/15 text-info'],
                            \App\Models\Notification::TYPE_TSR_ERROR => ['M12 9v2m0 4h.01M4.93 19h14.14a2 2 0 001.74-3L13.74 4a2 2 0 00-3.48 0L3.19 16.9a2 2 0 001.74 3z', 'bg-error/15 text-error'],
                            \App\Models\Notification::TYPE_TRANSFER  => ['M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4', 'bg-warning/15 text-warning'],
                            default => ['M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z', 'bg-base-200 text-base-content/60'],
                        };
                    @endphp
                    <li>
                        <button
                            type="button"
                            wire:click="openNotification({{ $item['id'] }})"
                            class="w-full text-left px-4 py-3 hover:bg-base-200/60 transition flex gap-3 {{ $item['unread'] ? 'bg-primary/5 border-s-2 border-s-primary' : '' }}"
                        >
                            <span class="mt-0.5 shrink-0 inline-flex h-8 w-8 items-center justify-center rounded-full {{ $iconTone }}">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icon }}"/>
                                </svg>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="flex items-start justify-between gap-2">
                                    <span class="block text-sm font-medium text-base-content leading-snug">{{ $item['title'] }}</span>
                                    @if ($item['unread'])
                                        <span class="shrink-0 mt-1.5 h-2 w-2 rounded-full bg-primary" title="Unread"></span>
                                    @endif
                                </span>
                                @if ($item['body'])
                                    <span class="block text-xs text-base-content/60 mt-0.5 leading-relaxed">{{ $item['body'] }}</span>
                                @endif
                                <span class="block text-[10px] text-base-content/40 mt-1">{{ $item['when'] }}</span>
                            </span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
