<x-app-layout>
    <x-slot:title>Acknowledge ticket #{{ $mondayTicketId }}</x-slot:title>

    <div class="max-w-xl mx-auto py-10 px-4 sm:px-6">
        <div class="card bg-base-100 shadow-md border border-base-300/70">
            <div class="card-body gap-4">

                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-full bg-primary/10 text-primary flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <div>
                        <h1 class="text-xl font-semibold tracking-tight">Acknowledge ticket #{{ $mondayTicketId }}</h1>
                        <p class="text-sm text-base-content/70 mt-1">
                            This is the alert email link — confirming here records that you've seen the ticket
                            and lets the customer know you're on it.
                        </p>
                    </div>
                </div>

                @if ($mismatchedUser)
                    <div role="alert" class="alert alert-warning text-sm">
                        <svg class="w-5 h-5 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M8.485 3.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 3.495zM10 6a1 1 0 011 1v3a1 1 0 11-2 0V7a1 1 0 011-1zm0 8a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
                        </svg>
                        <span>
                            This link is addressed to another team member, but you're signed in as
                            <span class="font-mono">{{ $signedInUser->email }}</span>.
                            You can still acknowledge the ticket — it'll be recorded under your account.
                        </span>
                    </div>
                @endif

                @if (! $signedInUser)
                    {{-- Not signed in yet: lead with a login prompt so the
                         link-from-email flow works cleanly on a phone. --}}
                    <div role="alert" class="alert alert-info text-sm">
                        <svg class="w-5 h-5 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                        </svg>
                        <span>
                            Please sign in first, then return to this page to confirm acknowledgement.
                        </span>
                    </div>

                    <div class="flex gap-2">
                        <a href="{{ route('login') }}" class="btn btn-primary btn-sm flex-1">Sign in</a>
                    </div>
                @else
                    @if ($alreadyAcked)
                        <div role="alert" class="alert alert-success text-sm">
                            <svg class="w-5 h-5 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            <span>
                                You've already acknowledged this ticket. You can head straight to the
                                ticket page to claim it or review the details.
                            </span>
                        </div>

                        <div class="flex gap-2">
                            <a href="{{ route('tsp.tickets.show', ['ticket' => $mondayTicketId]) }}" class="btn btn-primary btn-sm flex-1">
                                Go to ticket
                            </a>
                        </div>
                    @else
                        <form method="POST" action="{{ route('tsp.tickets.acknowledge', ['id' => $mondayTicketId]) }}{{ $targetUserId > 0 ? '?user=' . $targetUserId : '' }}">
                            @csrf
                            <p class="text-sm text-base-content/80">
                                You're signed in as <span class="font-semibold">{{ $signedInUser->name }}</span>
                                (<span class="font-mono text-xs">{{ $signedInUser->email }}</span>).
                                Click the button below to confirm.
                            </p>
                            <div class="card-actions justify-end mt-4">
                                <a href="{{ route('tsp.dashboard') }}" class="btn btn-ghost btn-sm">Cancel</a>
                                <button type="submit" class="btn btn-success btn-sm">
                                    Acknowledge
                                </button>
                            </div>
                        </form>
                    @endif
                @endif

                <div class="text-xs text-base-content/50 mt-2 pt-3 border-t border-base-300/70">
                    Acknowledging only records a "I saw it" timestamp. Use the
                    <strong>Claim</strong> button on the ticket page to assign the ticket to yourself.
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
