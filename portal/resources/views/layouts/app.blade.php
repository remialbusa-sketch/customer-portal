<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="mcbio">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="auth-user-id" content="{{ auth()->id() }}">

        <title>{{ config('app.name', 'MCBIO SERVICE PORTAL') }}</title>

        <link rel="icon" type="image/png" href="{{ asset('images/brand/favicon-mcbio.png') }}">

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
        {{-- Boot buffer. Full-screen <mc-logo mode="loader"> covering
             startup until the document, webfonts, and Livewire are all
             ready. First child of <body> so it paints before anything
             else. resources/js/boot-loader.js decides when to finish()
             it and hard-hides it at a ceiling so it can never strand
             the user. It removes itself; nothing to clean up here. --}}
        <mc-logo data-boot mode="loader" fullscreen nohelix
                 src="{{ asset('images/brand/mcbio-logo.png') }}"
                 theme="light"
                 role="status"
                 aria-label="Loading the MC BioTechnical Solutions portal"></mc-logo>

        {{-- Global navigation progress (brand gradient slide). Shown
             during Livewire SPA navigations; see script below. --}}
        <div id="portal-progress" aria-hidden="true"></div>
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

        <script>
            // Global navigation progress: slide the brand bar while a
            // Livewire SPA navigation is in flight. Failsafe hides it
            // after 10s so a dropped event can never strand it on.
            (function () {
                // ---- Boot buffer -----------------------------------
                // The full-screen <mc-logo data-boot> in the body. This
                // must be an inline body script (not a module in the
                // head): a Livewire SPA navigation swaps <body> and
                // re-runs inline scripts, but never re-runs an already
                // -evaluated head module. In a module the overlay would
                // appear on every in-app link click with no controller
                // left to remove it.
                (function () {
                    var boot = document.querySelector('mc-logo[data-boot]');
                    if (! boot) return;
                    // Guard THIS element, not a global. A guard on
                    // window.portalBoot breaks the signed-in handoff:
                    // the login page drives its own overlay, then the
                    // wire:navigate redirect to /dashboard swaps in the
                    // app layout with a NEW boot element while
                    // window.portalBoot is still set from before — the
                    // overlay would then never be removed.
                    if (boot.dataset.bootDriven === '1') return;
                    boot.dataset.bootDriven = '1';

                    // A SPA navigation re-runs this script against a
                    // fresh boot element. No full-screen flash for an
                    // in-app link click — remove it and return.
                    if (document.documentElement.dataset.portalNavigated === '1') {
                        boot.remove();
                        return;
                    }

                    // Hard ceiling. This is the guarantee that matters:
                    // whatever the readiness signals do, the overlay is
                    // gone within ceilingMs.
                    var CEILING = 8000;
                    // Never flash: hold briefly even on an instant load.
                    var MIN_VISIBLE = window.matchMedia
                        && window.matchMedia('(prefers-reduced-motion: reduce)').matches
                        ? 160 : 420;

                    var startedAt = performance.now();
                    var settled = false;
                    var timers = [];
                    function after(ms, fn) { timers.push(setTimeout(fn, ms)); }
                    function clearTimers() { timers.forEach(clearTimeout); timers = []; }

                    function complete() {
                        if (settled) return;
                        settled = true;
                        clearTimers();
                        var wait = Math.max(0, MIN_VISIBLE - (performance.now() - startedAt));
                        after(wait, function () {
                            // finish() only exists once the custom element
                            // has been upgraded. If the bundle never
                            // arrived, the pre-upgrade CSS cover is still
                            // on screen — take it down directly.
                            if (typeof boot.finish === 'function') {
                                try { boot.finish(); return; } catch (e) { /* fall through */ }
                            }
                            boot.remove();
                        });
                    }

                    var pending = 0;
                    var expected = 0;
                    function signal() {
                        if (settled) return;
                        pending += 1;
                        if (pending >= expected) complete();
                    }

                    // 1. document + stylesheets applied
                    expected += 1;
                    if (document.readyState === 'complete') signal();
                    else window.addEventListener('load', signal, { once: true });

                    // 2. webfonts settled (Inter / Plus Jakarta come from
                    //    a third-party host and are a visible source of
                    //    first-paint jank)
                    if (document.fonts && document.fonts.ready) {
                        expected += 1;
                        document.fonts.ready.then(signal, signal);
                    }

                    // 3. Livewire booted. Until this fires, buttons and
                    //    forms in this app are inert.
                    expected += 1;
                    if (window.Livewire) signal();
                    else document.addEventListener('livewire:init', signal, { once: true });

                    after(CEILING, function () {
                        if (settled) return;
                        console.warn('[portal-boot] readiness never settled; forcing hide');
                        settled = true;
                        clearTimers();
                        // hide() animates out; if the element never
                        // upgraded, remove() instead.
                        if (typeof boot.hide === 'function') {
                            try { boot.hide(); } catch (e) { boot.remove(); }
                        } else {
                            boot.remove();
                        }
                    });

                    // Last resort if the element's own boot failed and
                    // hide() never completes: remove it outright.
                    after(CEILING + 1500, function () {
                        if (boot.isConnected) boot.remove();
                    });

                    window.portalBoot = { element: boot, finish: complete };
                })();

                // ---- Global navigation progress --------------------
                var bar = document.getElementById('portal-progress');
                if (! bar) return;
                var failsafe = null;
                function show() {
                    bar.classList.add('is-active');
                    if (failsafe) clearTimeout(failsafe);
                    failsafe = setTimeout(hide, 10000);
                }
                function hide() {
                    bar.classList.remove('is-active');
                    if (failsafe) { clearTimeout(failsafe); failsafe = null; }
                }
                window.portalProgress = { show: show, hide: hide };

                function bind() {
                    if (! window.Livewire) {
                        setTimeout(bind, 200);
                        return;
                    }
                    document.addEventListener('livewire:navigating', show);
                    // Marks this document as SPA-continued. Set inside the
                    // listener (not eagerly) so a hard load is not
                    // mistaken for a continuation.
                    document.addEventListener('livewire:navigated', function () {
                        document.documentElement.dataset.portalNavigated = '1';
                    });
                }
                bind();
            })();
        </script>

        @stack('scripts')
    </body>
</html>
