<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-semibold tracking-widest uppercase text-base-content/50 mb-1">
                Access denied
            </p>
            <h2 class="font-semibold text-2xl text-base-content leading-tight">
                403 — Forbidden
            </h2>
        </div>
    </x-slot>

    <div class="py-2">
        <div class="max-w-md mx-auto sm:px-4 lg:px-6">
            <x-ui.card padding="p-6">
                <x-slot:icon>
                    <span aria-hidden="true" class="w-7 h-7 rounded-lg bg-error/10 text-error flex items-center justify-center shrink-0">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.74 4a2 2 0 00-3.48 0l-7.1 13.25A2 2 0 005 19z"/></svg>
                    </span>
                </x-slot:icon>

                <div class="text-center">
                    <h3 class="text-lg font-semibold text-base-content">
                        You don't have access to that resource
                    </h3>
                    <p class="text-sm text-base-content/70 mt-2">
                        {{ $message ?? 'This page is restricted. If you think you should have access, please contact a superadmin.' }}
                    </p>
                    <div class="mt-4">
                        @php
                            // Always escape to the user's role-aware home
                            // page. url()->previous() is NOT safe here:
                            // after a 403 completes, the session's previous
                            // URL is the forbidden page itself, so "go
                            // back" would loop forever on the same 403.
                            $homeUrl = auth()->user()
                                ? route(auth()->user()->homeRoute())
                                : url('/');
                        @endphp
                        <a href="{{ $homeUrl }}" class="btn btn-primary btn-sm gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                            Go to dashboard
                        </a>
                    </div>
                </div>
            </x-ui.card>
        </div>
    </div>
</x-app-layout>
