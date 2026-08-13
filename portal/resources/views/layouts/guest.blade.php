<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full" data-theme="mcbio">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#0A2540">

        <title>{{ config('app.name', 'MCBIO SERVICE PORTAL') }} — @yield('title', 'Customer Portal')</title>

        <link rel="icon" type="image/png" href="{{ asset('images/brand/favicon-mcbio.png') }}">

        {{-- Fonts: Inter (UI) + Plus Jakarta Sans (display) --}}
        <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
        <link rel="preload" href="https://fonts.bunny.net/css?family=inter:400,500,600,700|plus-jakarta-sans:600,700,800&display=swap" as="style" onload="this.rel='stylesheet'">
        <noscript><link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|plus-jakarta-sans:600,700,800&display=swap" rel="stylesheet"></noscript>

        @vite(['resources/css/app.css', 'resources/js/guest.js'])
    </head>
    <body class="font-sans antialiased text-brand-slate bg-brand-cream h-full">

        <div class="min-h-screen grid grid-cols-1 md:grid-cols-5">

            {{-- ──────────────────────────  BRAND PANEL  ────────────────────────── --}}
            <aside class="relative hidden md:flex md:col-span-2 flex-col justify-between overflow-hidden bg-brand-navy text-white">

                <div class="absolute inset-0 -z-0">
                    <img src="{{ asset('images/brand/banner.svg') }}"
                         alt=""
                         class="h-full w-full object-cover opacity-90"
                         aria-hidden="true">
                    <div class="absolute inset-0 bg-gradient-to-br from-brand-navy/85 via-brand-navy/55 to-brand-blue-3/80"></div>
                </div>

                <div class="relative z-10 p-10">
                    <a href="{{ url('/') }}" class="inline-flex items-center gap-3" wire:navigate>
                        <img src="{{ asset('images/brand/mcbio-logo.png') }}"
                             alt="BioTechnical Solutions Inc."
                             class="h-12 w-auto brightness-0 invert">
                    </a>
                </div>

                <div class="relative z-10 px-10 py-12">
                    <p class="text-xs font-semibold uppercase tracking-[0.25em] text-white/70">Service portal</p>
                    <h1 class="mt-3 font-display text-3xl font-bold leading-tight text-white">
                        MC BioTechnical Solutions
                    </h1>
                    <p class="mt-5 max-w-md text-base leading-relaxed text-white/80">
                        The internal portal for our customers, technicians, and managers.
                        Sign in to open a service ticket, follow the technician assigned to you,
                        and read the signed service report when the work is done.
                    </p>

                    <p class="mt-8 text-sm font-display italic text-white/60">
                        &mdash; Providing Services Beyond Expectations
                    </p>
                </div>

                <div class="relative z-10 p-10">
                    {{-- Intentionally empty: the tagline already appears
                         once at the bottom of the centre panel above. --}}
                </div>
            </aside>

            {{-- ──────────────────────────  FORM PANEL  ────────────────────────── --}}
            <main class="col-span-1 md:col-span-3 flex flex-col">

                <header class="flex items-center justify-between px-6 py-5 md:hidden">
                    <a href="{{ url('/') }}" class="inline-flex items-center" wire:navigate>
                        <img src="{{ asset('images/brand/mcbio-logo.png') }}" alt="BioTechnical Solutions Inc." class="h-10 w-auto">
                    </a>
                </header>

                <div class="flex-1 flex flex-col justify-center px-6 py-10 sm:px-12 lg:px-20">
                    <div class="mx-auto w-full max-w-md">

                        @isset($header)
                            <h1 class="hidden md:block font-display text-2xl font-bold text-brand-navy">{{ $header }}</h1>
                        @endisset

                        <div class="rounded-2xl bg-white p-8 shadow-soft ring-1 ring-brand-mist sm:p-10">
                            {{-- Breeze Volt components pass their view as $slot.
                                 Custom auth pages can also use @yield('auth-content'). --}}
                            {{ $slot ?? '' }}
                            @hasSection('auth-content')
                                @yield('auth-content')
                            @endif
                        </div>

                        @hasSection('auth-footer')
                            <p class="mt-6 text-center text-xs text-brand-slate/70">@yield('auth-footer')</p>
                        @endif
                    </div>
                </div>

                <footer class="border-t border-brand-mist/60 px-6 py-4 text-center text-xs text-brand-slate/60">
                    &copy; {{ date('Y') }} BioTechnical Solutions Inc. &middot; All rights reserved.
                </footer>
            </main>
        </div>
    </body>
</html>
