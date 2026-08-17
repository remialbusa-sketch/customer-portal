@php
    // Route authenticated users to the right dashboard for their role,
    // not a hardcoded /dashboard (which is customer-only and 403s for
    // FSE/ITS/Manager/Admin). See User::homeRoute().
    $dashboardRoute = auth()->user()?->homeRoute() ?? 'login';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#0A2540">
        <title>MCBIO SERVICE PORTAL</title>
        <meta name="description" content="Sign in to the MC BioTechnical Solutions service portal. Open and track service tickets for the equipment we support.">

        <link rel="icon" type="image/png" href="{{ asset('images/brand/favicon-mcbio.png') }}">

        <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
        <link rel="preload" href="https://fonts.bunny.net/css?family=inter:400,500,600,700|plus-jakarta-sans:600,700,800&display=swap" as="style" onload="this.rel='stylesheet'">
        <noscript><link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|plus-jakarta-sans:600,700,800&display=swap" rel="stylesheet"></noscript>

        @vite(['resources/css/app.css', 'resources/js/guest.js'])
    </head>

    <body class="font-sans antialiased text-brand-slate bg-brand-cream min-h-full flex flex-col">

        {{-- ───────────────────────  TOP NAV  ─────────────────────── --}}
        <header class="border-b border-brand-mist/60 bg-white">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
                <a href="/" class="flex items-center" wire:navigate>
                    <img src="{{ asset('images/brand/mcbio-logo.png') }}"
                         alt="MC BioTechnical Solutions Inc."
                         class="h-10 w-auto">
                </a>

                <div class="flex items-center gap-3">
                    @auth
                        <a href="{{ route($dashboardRoute) }}" wire:navigate
                           class="rounded-lg bg-brand-cta px-4 py-2 text-sm font-semibold text-white hover:opacity-95 transition">
                            Open dashboard
                        </a>
                    @else
                        <a href="{{ route('login') }}" wire:navigate
                           class="rounded-lg bg-brand-cta px-4 py-2 text-sm font-semibold text-white hover:opacity-95 transition">
                            Sign in
                        </a>
                    @endauth
                </div>
            </div>
        </header>

        {{-- ───────────────────────  MAIN  ─────────────────────── --}}
        <main class="flex-1 flex items-center">
            <div class="mx-auto grid w-full max-w-6xl items-center gap-12 px-6 py-16 md:py-24 md:grid-cols-2">

                {{-- Left: copy + sign-in CTA --}}
                <div>
                    <h1 class="font-display text-3xl font-bold leading-tight text-brand-navy sm:text-4xl">
                        Customer Portal
                    </h1>
                    <p class="mt-4 max-w-md text-base text-brand-slate">
                        Sign in to open a service ticket, follow the technician assigned to you,
                        and read signed service reports for the equipment we support.
                    </p>

                    <div class="mt-8 flex flex-wrap items-center gap-3">
                        @auth
                            <a href="{{ route($dashboardRoute) }}" wire:navigate
                               class="inline-flex items-center gap-2 rounded-lg bg-brand-cta px-5 py-3 text-base font-semibold text-white hover:opacity-95 transition">
                                Go to your dashboard
                            </a>
                        @else
                            <a href="{{ route('login') }}" wire:navigate
                               class="inline-flex items-center gap-2 rounded-lg bg-brand-cta px-5 py-3 text-base font-semibold text-white hover:opacity-95 transition">
                                Sign in
                            </a>
                        @endauth
                    </div>

                    <p class="mt-6 text-sm text-brand-slate">
                        New here? When your service coordinator adds you on monday.com, you'll
                        receive an email with your temporary sign-in password.
                        <span class="text-brand-navy">Sign in, then set a password of your own.</span>
                    </p>
                </div>

                {{-- Right: quiet info card --}}
                <div>
                    <div class="rounded-2xl bg-white p-6 shadow-soft ring-1 ring-brand-mist/60">
                        <h2 class="font-display text-base font-semibold text-brand-navy">
                            What you can do here
                        </h2>
                        <ul class="mt-4 space-y-3 text-sm text-brand-slate">
                            <li class="flex items-start gap-3">
                                <span class="mt-1 inline-flex h-1.5 w-1.5 shrink-0 rounded-full bg-brand-blue"></span>
                                Open a service ticket for any machine we support
                            </li>
                            <li class="flex items-start gap-3">
                                <span class="mt-1 inline-flex h-1.5 w-1.5 shrink-0 rounded-full bg-brand-blue"></span>
                                Chat in real time with the technician assigned to your ticket
                            </li>
                            <li class="flex items-start gap-3">
                                <span class="mt-1 inline-flex h-1.5 w-1.5 shrink-0 rounded-full bg-brand-blue"></span>
                                Read the signed service report when the work is done
                            </li>
                            <li class="flex items-start gap-3">
                                <span class="mt-1 inline-flex h-1.5 w-1.5 shrink-0 rounded-full bg-brand-blue"></span>
                                Look up past tickets and reports for your equipment
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </main>

        {{-- ───────────────────────  FOOTER  ─────────────────────── --}}
        <footer class="border-t border-brand-mist bg-white">
            <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-3 px-6 py-6 sm:flex-row">
                <p class="text-xs text-brand-slate">
                    &copy; {{ date('Y') }} MC BioTechnical Solutions Inc. &middot; All rights reserved.
                </p>
                <p class="text-xs italic text-brand-slate">
                    Providing Services Beyond Expectations
                </p>
            </div>
        </footer>

    </body>
</html>
