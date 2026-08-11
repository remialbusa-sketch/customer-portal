<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <p class="text-xs font-semibold tracking-widest uppercase text-base-content/50 mb-1">
                    Super admin
                </p>
                <h2 class="font-semibold text-2xl text-base-content leading-tight">
                    Customer invites
                </h2>
            </div>
            <a href="{{ route('admin.kpi') }}" class="btn btn-ghost btn-sm gap-1 self-start sm:self-auto">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                Back to KPI
            </a>
        </div>
    </x-slot>

    <div class="py-2">
        <div class="max-w-5xl mx-auto sm:px-4 lg:px-6 space-y-6">

            {{-- ───────  Success banner with the issued link  ─────── --}}
            @if (session('invite_url'))
                @php
                    $emailed = session('invite_emailed', false);
                    $bannerClass = $emailed ? 'alert-success' : 'alert-info';
                    $iconPath = $emailed
                        ? 'M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z'
                        : 'M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z';
                @endphp
                <div role="alert" class="alert {{ $bannerClass }} shadow-sm flex-col items-start gap-2 p-4">
                    <div class="flex items-center gap-2">
                        <svg class="w-5 h-5 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="{{ $iconPath }}" clip-rule="evenodd"/></svg>
                        <h3 class="font-semibold">{{ session('status') }}</h3>
                    </div>
                    @if ($emailed)
                        <p class="text-xs text-base-content/70">
                            The customer should receive the email at <span class="font-mono">{{ session('invite_email') }}</span> within a few minutes.
                            If they don't see it, ask them to check spam and to whitelist
                            <span class="font-mono">{{ config('mail.from.address') }}</span>.
                        </p>
                    @else
                        <p class="text-xs text-base-content/70">
                            Email delivery was skipped or failed. Copy the link below and send it to the customer yourself.
                        </p>
                    @endif
                    <p class="text-xs text-base-content/70">
                        Expires {{ session('invite_expires_at') }}. Single use. {{ $emailed ? 'A copy is shown below for your records.' : 'Send this link to the customer:' }}
                    </p>
                    <div class="mt-1 flex items-stretch gap-2 w-full">
                        <input
                            type="text"
                            readonly
                            value="{{ session('invite_url') }}"
                            class="input input-bordered input-sm flex-1 font-mono text-xs focus:outline-none"
                            onclick="this.select()"
                        />
                        <button type="button"
                                onclick="navigator.clipboard.writeText('{{ session('invite_url') }}'); this.textContent='Copied!'; setTimeout(()=>this.textContent='Copy',1500);"
                                class="btn btn-sm btn-success">
                            Copy
                        </button>
                    </div>
                </div>
            @endif

            {{-- ───────  Issue a new invite  ─────── --}}
            <x-ui.card
                title="Issue a new invite"
                subtitle="The customer's email must already exist on the Customer Details board on monday.com. We'll snapshot their account / branch / region / address, and print a one-time link valid for the chosen number of days."
                padding="p-6"
            >
                <x-slot:icon>
                    <span aria-hidden="true" class="w-7 h-7 rounded-lg bg-primary/10 text-primary flex items-center justify-center shrink-0">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    </span>
                </x-slot:icon>

                <form method="POST" action="{{ route('admin.invites.store') }}" class="space-y-5">
                    @csrf

                    <div>
                        <x-input-label for="email" value="Customer email" />
                        <x-text-input
                            id="email"
                            name="email"
                            type="email"
                            required
                            autofocus
                            placeholder="jane@stlukes.com"
                            value="{{ old('email') }}"
                            class="mt-1 block w-full"
                        />
                        <x-input-error :messages="$errors->get('email')" class="mt-2" />
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="ttl" value="Link lifetime (days)" />
                            <x-text-input
                                id="ttl"
                                name="ttl"
                                type="number"
                                min="1"
                                max="365"
                                value="{{ old('ttl', $defaultTtl) }}"
                                class="mt-1 block w-full"
                            />
                            <p class="mt-1 text-xs text-base-content/60">Default {{ $defaultTtl }} days.</p>
                            <x-input-error :messages="$errors->get('ttl')" class="mt-2" />
                        </div>
                        <div class="flex items-end">
                            <label class="label cursor-pointer justify-start gap-2 p-0">
                                <input
                                    type="hidden"
                                    name="invalidate_existing"
                                    value="0"
                                />
                                <input
                                    type="checkbox"
                                    name="invalidate_existing"
                                    value="1"
                                    {{ old('invalidate_existing') ? 'checked' : '' }}
                                    class="checkbox checkbox-secondary checkbox-sm"
                                />
                                <span class="text-sm text-base-content/80">Revoke any prior unused links for this email</span>
                            </label>
                        </div>
                    </div>

                    <div class="rounded-md border border-primary/30 bg-primary/5 p-3">
                        <label class="label cursor-pointer justify-start gap-2 p-0">
                            <input
                                type="hidden"
                                name="send_email"
                                value="0"
                            />
                            <input
                                type="checkbox"
                                name="send_email"
                                value="1"
                                {{ old('send_email', '1') ? 'checked' : '' }}
                                class="checkbox checkbox-primary checkbox-sm"
                            />
                            <span class="text-sm text-base-content/80">
                                Email the invite to <span class="font-mono text-xs">{{ old('email') ?: 'the customer' }}</span>
                            </span>
                        </label>
                        <p class="text-xs text-base-content/60 mt-1 pl-8">
                            Sends the registration link via the configured Gmail SMTP account
                            (<span class="font-mono">{{ config('mail.from.address') }}</span>).
                            If sending fails, the link is still shown on this page so you can copy it manually.
                        </p>
                    </div>

                    <div class="flex items-center gap-3">
                        <x-primary-button>Issue invite</x-primary-button>
                        <span class="text-xs text-base-content/60">
                            Logged in as
                            <span class="font-semibold text-base-content">{{ $user->name }}</span>
                            ·
                            <span class="uppercase tracking-wide">{{ $user->role }}</span>
                        </span>
                    </div>
                </form>
            </x-ui.card>

            {{-- ───────  Recent invites  ─────── --}}
            <x-ui.card
                title="Recent invites"
                subtitle="Last 25 issued, newest first."
                padding="p-0"
            >
                <x-slot:icon>
                    <span aria-hidden="true" class="w-7 h-7 rounded-lg bg-base-200 text-base-content/70 flex items-center justify-center shrink-0">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    </span>
                </x-slot:icon>

                <div class="overflow-x-auto border-t border-base-300/70">
                    <table class="table table-zebra w-full text-sm">
                        <thead>
                            <tr class="text-[11px] uppercase tracking-wider text-base-content/60">
                                <th>Email</th>
                                <th>Account</th>
                                <th>Region / Branch</th>
                                <th>Expires</th>
                                <th>Status</th>
                                <th>Issued by</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($recent as $invite)
                                @php
                                    $status = $invite->isUsed()
                                        ? 'used'
                                        : ($invite->isExpired() ? 'expired' : 'active');
                                    $statusBadge = match ($status) {
                                        'used'    => 'badge-success',
                                        'expired' => 'badge-error',
                                        default   => 'badge-info',
                                    };
                                @endphp
                                <tr>
                                    <td class="font-mono text-xs">{{ $invite->email }}</td>
                                    <td>{{ $invite->account_name ?: '—' }}</td>
                                    <td>
                                        {{ $invite->region ?: '—' }}
                                        @if ($invite->branch)
                                            <span class="text-base-content/30">·</span>
                                            <span class="text-xs text-base-content/60">{{ $invite->branch }}</span>
                                        @endif
                                    </td>
                                    <td class="text-xs text-base-content/60">
                                        {{ optional($invite->expires_at)->toDayDateTimeString() ?? '—' }}
                                    </td>
                                    <td>
                                        <span class="badge {{ $statusBadge }} badge-sm font-medium">
                                            {{ ucfirst($status) }}
                                        </span>
                                    </td>
                                    <td class="text-xs text-base-content/60">
                                        {{ $invite->invitedBy?->name ?? '—' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-sm text-base-content/60 py-6">
                                        No invites issued yet.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        </div>
    </div>
</x-app-layout>
