{{-- =============================================================
     Help & Support — landing-page edition.
     This page must work for guests (pre-login visitors from the
     sign-in top bar) AND signed-in users from the in-app nav,
     so it does NOT use <x-app-layout> (whose signed-in nav
     shows a Dashboard button + bell). Instead it renders its
     own slim top bar: logo left, Sign in / Dashboard right
     depending on auth state — matching the Figma sign-in shell.

     Content stays strictly role-assigned: guests see only the
     pre-login basics; signed-in roles keep their audience map.
     ============================================================= --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full" data-theme="mcbio">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#0A2540">

        <title>Help &amp; Support — Customer Portal</title>

        <link rel="icon" type="image/png" href="{{ asset('images/brand/favicon-mcbio.png') }}">

        <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
        <link rel="preload" href="https://fonts.bunny.net/css?family=inter:400,500,600,700|plus-jakarta-sans:600,700,800&display=swap" as="style" onload="this.rel='stylesheet'">
        <noscript><link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|plus-jakarta-sans:600,700,800&display=swap" rel="stylesheet"></noscript>

        @vite(['resources/css/app.css'])
        <style>[x-cloak] { display: none !important; }</style>
    </head>
    <body class="font-sans antialiased text-base-content bg-[#F5F6F9] min-h-full">

        @php
            $authed = auth()->check();
            $role = auth()->user()->role ?? 'guest';
            // Strict audience map — guests see only pre-login basics.
            $audience = ! $authed
                ? 'guest'
                : match ($role) {
                    'customer' => 'customer',
                    'fse', 'its', 'manager' => 'tsp',
                    default => 'admin',
                };
            $sections = [
                [
                    'title' => 'Before you sign in',
                    'roles' => ['guest'],
                    'faqs' => [
                        ['q' => 'How do I get an account?', 'a' => 'Accounts are provisioned by MC BioTechnical Solutions. If your organization is a customer and you cannot sign in, contact your service coordinator — they will set you up with your work email and a first password.'],
                        ['q' => 'I have a password but cannot sign in.', 'a' => 'Make sure you are using your work email and the password issued to you. If you forgot it, use the Forgot password link on the sign-in page and follow the emailed reset link.'],
                        ['q' => 'What can I do inside the portal?', 'a' => 'Customers open and track service tickets and talk to their assigned engineer. Technicians claim, work, and report on tickets. Everything is mirrored to Monday.com, our system of record.'],
                    ],
                ],
                [
                    'title' => 'Getting started',
                    'roles' => ['customer'],
                    'faqs' => [
                        ['q' => 'How do I submit a service request?', 'a' => 'Use the New Ticket button on your dashboard. Describe the problem, pick the affected machine and brand, and submit — a field engineer in your region will be notified. If you already have an open ticket with the same subject, the form will warn you first so duplicates stay out of the queue.'],
                        ['q' => 'How do I track my ticket?', 'a' => 'Open My Tickets on your dashboard and select the ticket to see its timeline, status changes, assigned technician, and messages. Use the search box, tabs, and filters to find older tickets.'],
                        ['q' => 'How does the search box in the top bar work?', 'a' => 'Type a ticket number to jump straight to it, or type part of a subject to filter your dashboard. If nothing matches, you will see a "no ticket found" message instead of an error page.'],
                    ],
                ],
                [
                    'title' => 'Ticket statuses',
                    'roles' => ['customer', 'tsp'],
                    'faqs' => [
                        ['q' => 'What do the ticket statuses mean?', 'a' => 'Open: received, awaiting a technician. In Progress: work has started. Awaiting Info: the technician needs something from you — reply on the ticket. Resolved: work is complete and verified.'],
                        ['q' => 'Why is my ticket read-only?', 'a' => 'Resolved tickets lock the chat so the record stays final. If the issue is back, submit a new service request referencing the old ticket number.'],
                        ['q' => 'What do the priority labels mean?', 'a' => 'Critical and High jump ahead in the queue. Medium and Low are scheduled in order. The priority is set when the ticket is triaged — contact your service coordinator if you believe it is wrong.'],
                    ],
                ],
                [
                    'title' => 'Claiming & transfers',
                    'roles' => ['tsp'],
                    'faqs' => [
                        ['q' => 'How do I claim a ticket?', 'a' => 'Open the Available tickets panel on your dashboard, review the ticket, and press Claim. The ticket moves into My tickets and the customer is notified. Claiming writes your assignment to Monday.com.'],
                        ['q' => 'No claimable tickets show up. Is something broken?', 'a' => 'Usually not. The pool only shows open, unassigned tickets in your region. If your account has no region set, a warning card on the dashboard says so — ask your manager to set your region. If your account is not linked to Monday.com yet, an admin must set your monday_id first.'],
                        ['q' => 'How do ticket transfers work?', 'a' => 'On one of your tickets, choose Transfer and pick the receiving TSP. Nothing moves until they press Accept on their own dashboard — declining or cancelling keeps the ticket with you. You can transfer only tickets you solely hold.'],
                    ],
                ],
                [
                    'title' => 'Service reports',
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
                    'title' => 'Administration',
                    'roles' => ['admin'],
                    'faqs' => [
                        ['q' => 'Where do I see overall performance?', 'a' => 'The KPI dashboard (your home page) summarizes ticket volumes, response activity, and service-report output across regions.'],
                        ['q' => 'How do account-deletion requests work?', 'a' => 'Customers and technicians file deletion requests from their profile. Superadmins review them in the deletion-requests inbox and approve or reject each one — approvals remove the account, rejections keep it with no change.'],
                        ['q' => 'A TSP cannot claim tickets. What do I check?', 'a' => 'First, their account needs a monday_id linking them to Monday.com — without it, claiming is blocked with an on-screen warning. Second, they need a region set, or the Available pool stays empty for them.'],
                        ['q' => 'A TSR is stuck in sync error. What now?', 'a' => 'Open the report to read the sync error. Transient Monday.com outages clear on Retry. If signatures failed to upload, the monday:reupload-signatures artisan command retries just the files. If the source ticket was deleted on Monday, Discard the row — it stays in the database for audit.'],
                        ['q' => 'How do portal users stay in sync with Monday.com?', 'a' => 'The monday:sync-users artisan command reconciles portal accounts against the Monday.com boards. Run it after personnel changes so new technicians can log in and removed ones lose access.'],
                    ],
                ],
                [
                    'title' => 'Account & password',
                    'roles' => ['customer', 'tsp', 'admin'],
                    'faqs' => [
                        ['q' => 'I forgot my password. What do I do?', 'a' => 'Use the Forgot password link on the sign-in page and follow the emailed reset link.'],
                        ['q' => 'Why does the bell say I use a temporary password?', 'a' => 'Your account still logs in with the default password. Open the notification bell and choose Set password now to pick your own. Set up later hides the reminder until your next session.'],
                        ['q' => 'How do I register my equipment?', 'a' => 'Open Profile, then My machines, and add each machine with its brand, model, and serial number. Registered machines appear as one-tap options on the new-ticket form.'],
                    ],
                ],
                [
                    'title' => 'Notifications',
                    'roles' => ['customer', 'tsp', 'admin'],
                    'faqs' => [
                        ['q' => 'What does the notification bell badge count?', 'a' => 'Everything needing your attention: new claimable tickets (technicians), ticket status changes, failed report syncs, transfer requests, and the temporary-password reminder. Open an item to jump straight to it — opening marks it read.'],
                        ['q' => 'Do notifications arrive instantly?', 'a' => 'When the connection supports it, yes — otherwise the bell refreshes on its own about every minute. Nothing is lost in between; the badge counts unread items stored on the portal.'],
                    ],
                ],
            ];
            $fi = 0;
        @endphp

        {{-- ── Slim top bar (guest-style shell, no Dashboard button) ── --}}
        <header class="bg-base-100 border-b border-base-300/70">
            <div class="max-w-7xl mx-auto px-4 sm:px-8 h-[68px] flex items-center justify-between gap-3">
                <a href="{{ url('/') }}" class="inline-flex items-center shrink-0" aria-label="Home">
                    <img src="{{ asset('images/brand/mcbio-logo.png') }}" alt="MC BioTechnical Solutions Inc." class="h-9 w-auto">
                </a>
                <div class="flex items-center gap-3">
                    <div class="hidden sm:flex flex-col items-end gap-0.5 leading-tight">
                        <span class="text-[11px] text-base-content/50">{{ $authed ? 'Back to your workspace' : 'Ready to get started?' }}</span>
                        @if ($authed)
                            <a href="{{ route('dashboard', absolute: false) }}" class="text-xs font-semibold text-primary hover:underline">
                                Back to dashboard
                            </a>
                        @else
                            <a href="{{ route('login') }}" class="text-xs font-semibold text-primary hover:underline">
                                Sign in to the portal
                            </a>
                        @endif
                    </div>
                    @if ($authed)
                        <a href="{{ route('dashboard', absolute: false) }}" class="btn btn-primary btn-sm h-9 px-4">
                            Dashboard
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="btn btn-primary btn-sm h-9 px-4">
                            Sign in
                        </a>
                    @endif
                </div>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 sm:px-8 py-10">
            {{-- Page header (landing language, not the app-header slot) --}}
            <div class="animate-enter max-w-3xl mx-auto">
                <p class="text-[11px] font-medium uppercase tracking-wider text-base-content/50">
                    Support center
                </p>
                <h1 class="font-bold text-4xl text-base-content leading-tight mt-1">
                    How can we help?
                </h1>
                <p class="text-sm text-base-content/60 mt-2">
                    Guides and troubleshooting for the MC BioTechnical Solutions service portal.
                </p>
            </div>

            <div class="max-w-3xl mx-auto mt-8" x-data="{ q: '' }">

                {{-- Search --}}
                <div class="animate-enter animate-enter-1 relative">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-base-content/40 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input type="search" x-model="q" placeholder="Search help articles…"
                           class="input input-bordered input-sm w-full pl-8 h-9 text-[13px] bg-base-100 rounded-lg"
                           aria-label="Search help articles">
                </div>

                <div class="mt-7 space-y-7">
                    @foreach ($sections as $section)
                        @if (! in_array($audience, $section['roles']))
                            @continue
                        @endif
                        <section class="animate-enter animate-enter-2">
                            <h3 class="text-[15px] font-semibold text-base-content mb-3" x-show="!q">{{ $section['title'] }}</h3>
                            <div class="space-y-3">
                                @foreach ($section['faqs'] as $faq)
                                    @php $fi++; $fid = 'faq-' . $fi; @endphp
                                    <div class="collapse collapse-arrow rounded-2xl bg-base-100 border border-base-300/70 shadow-sm"
                                         x-show="!q || $el.dataset.search.includes(q.toLowerCase())"
                                         data-search="{{ strtolower($faq['q'] . ' ' . $faq['a']) }}">
                                        <input type="checkbox" id="{{ $fid }}" class="peer" />
                                        <label for="{{ $fid }}" class="collapse-title text-sm font-semibold text-base-content cursor-pointer">
                                            {{ $faq['q'] }}
                                        </label>
                                        <div class="collapse-content text-sm text-base-content/70 leading-relaxed">
                                            <p>{{ $faq['a'] }}</p>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endforeach

                    <div x-show="q" x-cloak class="text-sm text-base-content/60">
                        Showing matches for “<span x-text="q"></span>”.
                    </div>
                </div>

                <section class="animate-enter animate-enter-3 mt-8 rounded-2xl bg-[#EEEEFF] p-5 flex items-center gap-4">
                    <span class="w-10 h-10 rounded-xl bg-base-100 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5 text-[#5B5BD6]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    </span>
                    <span>
                        <span class="block text-sm font-semibold text-base-content">Still stuck?</span>
                        <span class="block text-[13px] text-base-content/60 mt-0.5">
                            @if ($authed)
                                Reply on your ticket or contact your service coordinator — they can see the same ticket you see.
                            @else
                                Once you can sign in, reply on your ticket or contact your service coordinator — they can see the same ticket you see.
                            @endif
                        </span>
                    </span>
                </section>
            </div>

            <p class="mt-10 text-center text-xs text-base-content/40">
                &copy; {{ date('Y') }} MC BioTechnical Solutions Inc. &middot; All rights reserved.
            </p>
        </main>
    </body>
</html>