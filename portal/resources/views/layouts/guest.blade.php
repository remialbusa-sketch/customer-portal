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
    <body class="font-sans antialiased text-base-content bg-[#F5F6F9] h-full">

        {{-- Slim top bar (Figma sign-in frame): brand left, help access right. --}}
        <header class="bg-base-100 border-b border-base-300/70">
            <div class="max-w-7xl mx-auto px-4 sm:px-8 h-[68px] flex items-center justify-between gap-3">
                <a href="{{ url('/') }}" class="inline-flex items-center shrink-0" wire:navigate>
                    <img src="{{ asset('images/brand/mcbio-logo.png') }}"
                         alt="MC BioTechnical Solutions Inc."
                         class="h-9 w-auto">
                </a>
                <div class="flex items-center gap-3">
                    <div class="hidden sm:flex flex-col items-end gap-0.5 leading-tight">
                        <span class="text-[11px] text-base-content/50">Need help signing in?</span>
                        <a href="{{ route('help') }}" class="text-xs font-semibold text-primary hover:underline" wire:navigate>
                            Visit Help Center
                        </a>
                    </div>
                    <a href="{{ route('help') }}" title="Help & Support" aria-label="Help & Support"
                       class="w-9 h-9 rounded-lg border border-base-300/70 bg-base-100 text-base-content/60 hover:text-base-content hover:bg-base-200/60 transition inline-flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </a>
                </div>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 sm:px-8 py-10">
            <div class="flex items-center justify-center gap-14">

                {{-- Intro panel (desktop only) --}}
                <aside class="hidden lg:flex flex-col w-full max-w-[584px] rounded-3xl bg-[#EEF0FF] border border-[#E4E6FF] p-10 gap-7 relative overflow-hidden" aria-hidden="false">
                    {{-- Ambient drifting orbs (decorative, motion-safe). --}}
                    <div class="absolute -top-20 -right-20 w-72 h-72 rounded-full bg-[#5B5BD6]/10 blur-3xl animate-drift pointer-events-none" aria-hidden="true"></div>
                    <div class="absolute -bottom-24 -left-16 w-80 h-80 rounded-full bg-[#2E9B3F]/10 blur-3xl animate-drift pointer-events-none" style="animation-delay: -7s;" aria-hidden="true"></div>
                    <div class="relative">
                        <span class="animate-enter inline-flex items-center gap-1.5 rounded-full bg-base-100 px-2.5 py-1.5 text-[10px] font-bold uppercase tracking-wider text-primary">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>
                            Customer support portal
                        </span>
                        <h1 class="animate-enter animate-enter-1 mt-3.5 text-4xl font-bold leading-[1.14] text-base-content">
                            One place for every support request.
                        </h1>
                        <p class="animate-enter animate-enter-2 mt-3 text-sm leading-relaxed text-base-content/60">
                            Track tickets, share updates with your service engineer, and get back to work with confidence.
                        </p>
                    </div>

                    <div class="animate-enter animate-enter-2 flex gap-5 relative">
                        <div class="flex items-center gap-3 flex-1">
                            <span class="w-9 h-9 rounded-lg bg-base-100 flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </span>
                            <span>
                                <span class="block text-[13px] font-semibold text-base-content">Stay informed</span>
                                <span class="block text-[11px] text-base-content/60">Live status and engineer updates</span>
                            </span>
                        </div>
                        <div class="flex items-center gap-3 flex-1">
                            <span class="w-9 h-9 rounded-lg bg-base-100 flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </span>
                            <span>
                                <span class="block text-[13px] font-semibold text-base-content">Stay secure</span>
                                <span class="block text-[11px] text-base-content/60">Protected customer workspace</span>
                            </span>
                        </div>
                    </div>

                    {{-- Decorative product preview (static mock, not live data). --}}
                    <div class="animate-enter animate-enter-3 relative rounded-2xl bg-base-100 border border-base-300/70 shadow-[0_16px_32px_0_rgba(53,53,165,0.10)] overflow-hidden" aria-hidden="true">
                        <div class="flex items-center justify-between px-4 h-12 border-b border-base-300/70">
                            <div class="flex items-center gap-2">
                                <span class="w-6 h-6 rounded-md bg-[#EEEEFF] text-primary flex items-center justify-center">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
                                </span>
                                <span class="text-xs font-semibold text-base-content">Your ticket workspace</span>
                            </div>
                            <span class="flex items-center gap-1.5 text-[10px] text-base-content/60">
                                <span class="relative flex w-[7px] h-[7px]">
                                    <span class="absolute inset-0 rounded-full bg-success/40 animate-ping"></span>
                                    <span class="relative rounded-full w-[7px] h-[7px] bg-success"></span>
                                </span>
                                Support online
                            </span>
                        </div>
                        <div class="p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-bold text-base-content">Welcome back, Customer</p>
                                    <p class="text-[10px] text-base-content/50">Here’s your support overview</p>
                                </div>
                                <span class="h-[30px] px-3 rounded-md bg-primary text-primary-content text-[11px] font-semibold inline-flex items-center">+ New Ticket</span>
                            </div>
                            <div class="flex gap-2 mt-3.5">
                                <div class="flex-1 rounded-lg bg-base-200/60 border border-base-300/60 p-2.5">
                                    <p class="text-[10px] uppercase tracking-wider text-base-content/50">Open</p>
                                    <p class="text-xl font-bold text-info">1</p>
                                </div>
                                <div class="flex-1 rounded-lg bg-base-200/60 border border-base-300/60 p-2.5">
                                    <p class="text-[10px] uppercase tracking-wider text-base-content/50">In progress</p>
                                    <p class="text-xl font-bold text-base-content/60">2</p>
                                </div>
                                <div class="flex-1 rounded-lg bg-base-200/60 border border-base-300/60 p-2.5">
                                    <p class="text-[10px] uppercase tracking-wider text-base-content/50">Resolved</p>
                                    <p class="text-xl font-bold text-success">6</p>
                                </div>
                            </div>
                            <div class="mt-3 rounded-lg border border-base-300/60 divide-y divide-base-300/60">
                                <div class="flex items-center gap-3 px-4 h-[52px]">
                                    <span class="text-[11px] font-semibold text-primary w-[92px] shrink-0">TICKET-0006</span>
                                    <span class="text-xs font-medium text-base-content truncate flex-1">Account access request</span>
                                    <span class="rounded-md px-2 py-[3px] text-[10px] font-semibold bg-[#EBF2FD] text-[#3977E8]">Open</span>
                                </div>
                                <div class="flex items-center gap-3 px-4 h-[52px]">
                                    <span class="text-[11px] font-semibold text-primary w-[92px] shrink-0">TICKET-0005</span>
                                    <span class="text-xs font-medium text-base-content truncate flex-1">API rate limit exceeded</span>
                                    <span class="rounded-md px-2 py-[3px] text-[10px] font-semibold bg-[#E3F7F3] text-[#17847A]">Resolved</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </aside>

                {{-- Form card column --}}
                <div class="w-full max-w-[520px]">
                    {{ $slot ?? '' }}
                    @hasSection('auth-content')
                        @yield('auth-content')
                    @endif
                </div>
            </div>

            <p class="mt-10 text-center text-xs text-base-content/40">
                &copy; {{ date('Y') }} MC BioTechnical Solutions Inc. &middot; All rights reserved.
            </p>
        </main>
    </body>
</html>
