<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <p class="text-xs font-semibold tracking-widest uppercase text-base-content/50 mb-1">
                    Super admin
                </p>
                <h2 class="font-semibold text-2xl text-base-content leading-tight">
                    Account deletion requests
                </h2>
            </div>
            <a href="{{ route('admin.kpi') }}" wire:navigate class="btn btn-ghost btn-sm gap-1 self-start sm:self-auto">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                KPI dashboard
            </a>
        </div>
    </x-slot>

    <div class="py-2">
        <div class="max-w-5xl mx-auto sm:px-4 lg:px-6 space-y-6">

            @if (session('status'))
                <x-ui.toast type="success" title="All set!">
                    {{ session('status') }}
                </x-ui.toast>
            @endif

            @if ($errors->any())
                <div role="alert" class="alert alert-error shadow-sm flex-col items-start gap-1 p-4">
                    <h3 class="font-semibold text-sm">There were some problems with your input</h3>
                    <ul class="list-disc list-inside text-xs space-y-0.5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- ───────  Pending  ─────── --}}
            <x-ui.card
                title="Pending"
                :subtitle="$pending->count() . ' awaiting review'"
                padding="p-0"
            >
                <x-slot:icon>
                    <span aria-hidden="true" class="w-7 h-7 rounded-lg bg-warning/15 text-warning flex items-center justify-center shrink-0">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </span>
                </x-slot:icon>

                @if ($pending->isEmpty())
                    <div class="px-6 py-10 text-center text-sm text-base-content/60">
                        No pending deletion requests.
                    </div>
                @else
                    <ul class="divide-y divide-base-300/70">
                        @foreach ($pending as $req)
                            <li class="px-5 py-4">
                                <div class="flex items-start justify-between gap-4">
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-semibold text-base-content break-words">
                                            {{ $req->name ?? '(no name)' }}
                                            <span class="text-xs text-base-content/60 font-normal">— {{ $req->email }}</span>
                                        </p>
                                        <p class="mt-1 text-xs text-base-content/60">
                                            Role:
                                            <span class="font-medium text-base-content/80">{{ $req->role ?? '—' }}</span>
                                            <span class="mx-2 text-base-content/30">·</span>
                                            Filed {{ $req->created_at->diffForHumans() }}
                                            ({{ $req->created_at->format('M j, Y g:i A') }})
                                        </p>
                                        @if ($req->reason)
                                            <p class="mt-2 text-sm text-base-content bg-base-200/60 border border-base-300/70 rounded px-3 py-2 italic whitespace-pre-wrap">
                                                "{{ $req->reason }}"
                                            </p>
                                        @endif
                                    </div>

                                    <div class="flex flex-col gap-2 shrink-0">
                                        {{-- Approve: actually deletes the user. --}}
                                        <form
                                            method="POST"
                                            action="{{ route('admin.deletion-requests.approve', $req) }}"
                                            onsubmit="return confirm('Approve and PERMANENTLY delete the account for {{ $req->email }}? This cannot be undone.');"
                                        >
                                            @csrf
                                            <button
                                                type="submit"
                                                class="btn btn-error btn-sm gap-1"
                                            >
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg>
                                                Approve & delete
                                            </button>
                                        </form>

                                        {{-- Reject: keeps the user, marks request as rejected. --}}
                                        <form
                                            method="POST"
                                            action="{{ route('admin.deletion-requests.reject', $req) }}"
                                            onsubmit="return confirm('Reject this deletion request?');"
                                        >
                                            @csrf
                                            <input type="hidden" name="decision_note" value="Rejected by {{ $user->name }}">
                                            <button
                                                type="submit"
                                                class="btn btn-ghost btn-sm"
                                            >
                                                Reject
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            {{-- ───────  Recent activity  ─────── --}}
            <x-ui.card
                title="Recent decisions"
                padding="p-0"
            >
                <x-slot:icon>
                    <span aria-hidden="true" class="w-7 h-7 rounded-lg bg-base-200 text-base-content/70 flex items-center justify-center shrink-0">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    </span>
                </x-slot:icon>

                @if ($recent->isEmpty())
                    <div class="px-6 py-10 text-center text-sm text-base-content/60">
                        No decisions yet.
                    </div>
                @else
                    <ul class="divide-y divide-base-300/70">
                        @foreach ($recent as $req)
                            @php
                                $decisionBadge = match ($req->status) {
                                    'approved' => 'badge-success',
                                    'rejected' => 'badge-error',
                                    default    => 'badge-ghost',
                                };
                            @endphp
                            <li class="px-5 py-3 text-sm">
                                <div class="flex items-center justify-between gap-4">
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2">
                                            <span class="font-medium text-base-content">{{ $req->email }}</span>
                                            <span class="badge {{ $decisionBadge }} badge-sm">
                                                {{ ucfirst($req->status) }}
                                            </span>
                                        </div>
                                        <span class="text-xs text-base-content/60 ml-0 mt-0.5 block">
                                            by {{ $req->processor?->name ?? '—' }}
                                            on {{ $req->processed_at?->format('M j, Y g:i A') }}
                                        </span>
                                        @if ($req->decision_note)
                                            <p class="text-xs text-base-content/60 mt-0.5 italic">{{ $req->decision_note }}</p>
                                        @endif
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>
    </div>
</x-app-layout>
