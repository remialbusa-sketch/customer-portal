{{-- =============================================================
     Help & Support — static page, no backend. Linked from the
     nav help icon and the customer dashboard Quick Actions.
     Sections are role-aware (customers see customer guides,
     TSPs see technician guides). FAQ search filters client-side.
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

    @php
        $role = auth()->user()->role ?? 'customer';
        $isTsp = in_array($role, ['fse', 'its', 'manager', 'admin'], true);
        $sections = [
            [
                'title' => 'Getting started',
                'roles' => ['all'],
                'faqs' => [
                    ['q' => 'How do I submit a service request?', 'a' => 'Use the New Ticket button on your dashboard. Describe the problem, pick the affected machine and brand, and submit — a field engineer in your region will be notified. If you already have an open ticket with the same subject, the form will warn you first so duplicates stay out of the queue.'],
                    ['q' => 'How do I track my ticket?', 'a' => 'Open My Tickets on your dashboard and select the ticket to see its timeline, status changes, assigned technician, and messages. Use the search box, tabs, and filters to find older tickets.'],
                    ['q' => 'How does the search box in the top bar work?', 'a' => 'Type a ticket number to jump straight to it, or type part of a subject to filter your dashboard. If nothing matches, you will see a "no ticket found" message instead of an error page.'],
                ],
            ],
            [
                'title' => 'Ticket statuses',
                'roles' => ['all'],
                'faqs' => [
                    ['q' => 'What do the ticket statuses mean?', 'a' => 'Open: received, awaiting a technician. In Progress: work has started. Awaiting Info: the technician needs something from you — reply on the ticket. Resolved: work is complete and verified.'],
                    ['q' => 'Why is my ticket read-only?', 'a' => 'Resolved tickets lock the chat so the record stays final. If the issue is back, submit a new service request referencing the old ticket number.'],
                    ['q' => 'What do the priority labels mean?', 'a' => 'Critical and High jump ahead in the queue. Medium and Low are scheduled in order. The priority is set when the ticket is triaged — contact your service coordinator if you believe it is wrong.'],
                ],
            ],
            [
                'title' => 'For technicians — claiming & transfers',
                'roles' => ['tsp'],
                'faqs' => [
                    ['q' => 'How do I claim a ticket?', 'a' => 'Open the Available tickets panel on your dashboard, review the ticket, and press Claim. The ticket moves into My tickets and the customer is notified. Claiming writes your assignment to Monday.com.'],
                    ['q' => 'No claimable tickets show up. Is something broken?', 'a' => 'Usually not. The pool only shows open, unassigned tickets in your region. If your account has no region set, a warning card on the dashboard says so — ask your manager to set your region. If your account is not linked to Monday.com yet, an admin must set your monday_id first.'],
                    ['q' => 'How do ticket transfers work?', 'a' => 'On one of your tickets, choose Transfer and pick the receiving TSP. Nothing moves until they press Accept on their own dashboard — declining or cancelling keeps the ticket with you. You can transfer only tickets you solely hold.'],
                ],
            ],
            [
                'title' => 'For technicians — service reports',
                'roles' => ['tsp'],
                'faqs' => [
                    ['q' => 'How do I file a service report (TSR)?', 'a' => 'Open the ticket and choose Create service report. Work through the three steps — equipment and time, work details, signatures — then submit. The report saves on the portal first and mirrors to Monday.com right after.'],
                    ['q' => 'What if I have no internet on site?', 'a' => 'Fill and submit the report anyway. It is queued safely on the device and syncs automatically once you are back online — the header pill shows Queued until it lands. Keep the signatures on screen until you see Synced.'],
                    ['q' => 'What do the sync states mean?', 'a' => 'Queued: waiting for connection. Syncing: sending now. Synced: the report and its signatures are on Monday.com. Sync failed: something blocked the upload — open the Sync status panel for the reason, then Retry, or Discard if the source ticket is gone.'],
                    ['q' => 'Where do internal notes go?', 'a' => 'Internal notes stay inside the portal and are visible to technicians and admins only — never to the customer. Use the ticket chat for anything the customer should see.'],
                    ['q' => 'How does the time tracker work?', 'a' => 'The tracker mirrors Monday.com\u2019s own time-tracking widget and is read-only here: start, pause, and stop the timer on the Monday ticket and the portal reflects it within about 30 seconds.'],
                ],
            ],
            [
                'title' => 'Account & password',
                'roles' => ['all'],
                'faqs' => [
                    ['q' => 'I forgot my password. What do I do?', 'a' => 'Use the Forgot password link on the login page and follow the emailed reset link.'],
                    ['q' => 'Why does the bell say I use a temporary password?', 'a' => 'Your account still logs in with the default password. Open the notification bell and choose Set password now to pick your own. Set up later hides the reminder until your next session.'],
                    ['q' => 'How do I register my equipment?', 'a' => 'Open Profile, then My machines, and add each machine with its brand, model, and serial number. Registered machines appear as one-tap options on the new-ticket form.'],
                ],
            ],
            [
                'title' => 'Notifications',
                'roles' => ['all'],
                'faqs' => [
                    ['q' => 'What does the notification bell badge count?', 'a' => 'Everything needing your attention: new claimable tickets (technicians), ticket status changes, failed report syncs, transfer requests, and the temporary-password reminder. Open an item to jump straight to it — opening marks it read.'],
                    ['q' => 'Do notifications arrive instantly?', 'a' => 'When the connection supports it, yes — otherwise the bell refreshes on its own about every minute. Nothing is lost in between; the badge counts unread items stored on the portal.'],
                ],
            ],
        ];
    @endphp

    <div class="py-2" x-data="{ q: '' }">
        <div class="max-w-3xl mx-auto sm:px-4 lg:px-6 space-y-7">

            {{-- Search --}}
            <div class="relative">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-base-content/40 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input type="search" x-model="q" placeholder="Search help articles…"
                       class="input input-bordered input-sm w-full pl-8 h-9 text-[13px] bg-base-100 rounded-lg"
                       aria-label="Search help articles">
            </div>

            @foreach ($sections as $section)
                @if (! in_array('all', $section['roles']) && ! ($isTsp && in_array('tsp', $section['roles'])))
                    @continue
                @endif
                <section>
                    <h3 class="text-[15px] font-semibold text-base-content mb-3" x-show="!q">{{ $section['title'] }}</h3>
                    <div class="space-y-3">
                        @foreach ($section['faqs'] as $faq)
                            <div class="collapse collapse-arrow rounded-2xl bg-base-100 border border-base-300/70 shadow-sm"
                                 x-show="!q || $el.dataset.search.includes(q.toLowerCase())"
                                 data-search="{{ strtolower($faq['q'] . ' ' . $faq['a']) }}">
                                <input type="checkbox" class="peer" checked="checked" />
                                <div class="collapse-title text-sm font-semibold text-base-content">
                                    {{ $faq['q'] }}
                                </div>
                                <div class="collapse-content text-sm text-base-content/70 leading-relaxed">
                                    <p>{{ $faq['a'] }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach

            <div x-show="q" x-cloak class="text-sm text-base-content/60">
                Showing matches for “<span x-text="q"></span>” across all sections.
            </div>

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
