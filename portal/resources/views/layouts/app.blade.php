<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="mcbio">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }} — BioTechnical Solutions</title>

        {{-- Fonts: Inter for UI, Plus Jakarta Sans for display --}}
        <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
        <link rel="preload" href="https://fonts.bunny.net/css?family=inter:400,500,600,700|plus-jakarta-sans:600,700,800&display=swap" as="style" onload="this.rel='stylesheet'">
        <noscript><link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|plus-jakarta-sans:600,700,800&display=swap" rel="stylesheet"></noscript>

        {{-- Bootstrap CDN removed 2026-07-18: DaisyUI is now the single
             component system (see tailwind.config.js daisyui.theme 'mcbio').
             The TSR form view opts in to Bootstrap locally via @push('styles')
             because that view still uses form-control / btn-primary classes
             for the signature canvas controls (and rewriting it is its own
             scheduled task). --}}

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        {{-- Livewire styles (Livewire 3's runtime injects a tiny
             stylesheet for `wire:loading` etc.). Loaded from the
             vendor URL published by `php artisan livewire:publish`
             at /vendor/livewire/livewire.css. --}}
        @livewireStyles
    </head>
    <body class="font-sans antialiased bg-base-200 text-base-content">
        <div class="min-h-screen">
            <livewire:layout.navigation />

            <!-- Page Heading -->
            @if (isset($header))
                <header class="bg-base-100 border-b border-base-300/60">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                    <div class="h-1 bg-gradient-to-r from-primary via-secondary to-accent opacity-80"></div>
                </header>
            @endif

            <!-- Page Content -->
            <main class="py-8">
                {{ $slot }}
            </main>
        </div>

        {{-- Livewire runtime: registers the `Livewire` global,
             hooks the `wire:click` / `wire:submit` / `wire:model`
             directives, and exposes the `/livewire/update`
             endpoint. Without this, all `<livewire:*>` components
             on the page render as static HTML and their handlers
             never fire — chat, time-tracker, internal-notes, the
             TSR wizard, all of it.

             NOTE: must come BEFORE `@stack('scripts')` so any
             per-view `@push('scripts')` block that references
             `Livewire` (e.g. calls `Livewire.dispatch(...)`) runs
             after the runtime is available. --}}
        @livewireScripts

        @stack('scripts')
    </body>
</html>
