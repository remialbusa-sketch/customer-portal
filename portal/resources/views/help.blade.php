{{-- =============================================================
     Help & Support — static page, no backend. Linked from the
     nav help icon and the customer dashboard Quick Actions.
     ============================================================= --}}
<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-[11px] font-medium uppercase tracking-wider text-base-content/50">
                Support
            </p>
            <h2 class="font-bold text-3xl text-base-content leading-tight mt-1">
                Help &amp; Support
            </h2>
            <p class="text-sm text-base-content/60 mt-1.5">
                Guides and troubleshooting for the service portal.
            </p>
        </div>
    </x-slot>

    <div class="py-2">
        <div class="max-w-3xl mx-auto sm:px-4 lg:px-6 space-y-4">
            @foreach ([
                ['q' => 'How do I submit a service request?', 'a' => 'Use the New Ticket button on your dashboard. Describe the problem, pick the affected machine and brand, and submit — a field engineer in your region will be notified.'],
                ['q' => 'How do I track my ticket?', 'a' => 'Open My Tickets on your dashboard and select the ticket to see its timeline, status changes, and messages from the technician.'],
                ['q' => 'What do the ticket statuses mean?', 'a' => 'Open: received, awaiting a technician. In Progress: work has started. Awaiting Info: the technician needs something from you — reply on the ticket. Resolved: work is complete and verified.'],
                ['q' => 'I forgot my password. What do I do?', 'a' => 'Use the Forgot password link on the login page. If your account still uses the temporary password, open the notification bell and choose Set password now.'],
                ['q' => 'Why do I see a notification bell badge?', 'a' => 'The bell collects everything needing your attention: new claimable tickets (TSPs), status changes, failed report syncs, and transfer requests.'],
            ] as $faq)
                <div class="collapse collapse-arrow rounded-2xl bg-base-100 border border-base-300/70 shadow-sm">
                    <input type="checkbox" class="peer" />
                    <div class="collapse-title text-[15px] font-semibold text-base-content">
                        {{ $faq['q'] }}
                    </div>
                    <div class="collapse-content text-sm text-base-content/70 leading-relaxed">
                        <p>{{ $faq['a'] }}</p>
                    </div>
                </div>
            @endforeach

            <section class="rounded-2xl bg-[#EEEEFF] p-5 flex items-center gap-4">
                <span class="w-10 h-10 rounded-xl bg-base-100 flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5 text-[#5B5BD6]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                </span>
                <span>
                    <span class="block text-sm font-semibold text-base-content">Still stuck?</span>
                    <span class="block text-[13px] text-base-content/60 mt-0.5">Reply on your ticket or contact your service coordinator — they can see the same ticket you see.</span>
                </span>
            </section>
        </div>
    </div>
</x-app-layout>
